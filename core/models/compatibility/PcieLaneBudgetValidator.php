<?php

require_once __DIR__ . '/../components/ComponentDataService.php';
require_once __DIR__ . '/../shared/DataExtractionUtilities.php';

/**
 * PcieLaneBudgetValidator
 *
 * Total system-wide PCIe lane budget enforcement.
 *
 * Budget = sum of CPU pcie_lanes (+ motherboard chipset_pcie_lanes if present).
 * Used   = sum of lanes consumed by all installed NIC / HBA / PCIe card /
 *          NVMe-storage components, derived from their `interface` string
 *          (regex /x(\d+)/i over e.g. "PCIe 4.0 x8").
 *
 * A candidate addition is rejected if Used + Requested > Budget.
 *
 * Per-socket tracking is deferred (the motherboard JSON spec does not yet
 * carry a per-slot cpu_socket affinity field). The total-budget check is
 * strictly more conservative than the previous zero check — the number of
 * legitimate configurations it rejects is exactly the set of configurations
 * that were silently over-subscribed.
 *
 * Rollout flag: PCIE_LANE_CHECK_ENABLED (env / .env)
 *   - "enforce"  : reject over-subscribed additions (HTTP 4xx).
 *   - "warn"     : allow the addition, log a warning (default during rollout).
 *   - "off"      : skip entirely (legacy behaviour).
 *   - unset      : defaults to "warn" on first deploy.
 */
class PcieLaneBudgetValidator
{
    /** @var PDO */
    private $pdo;

    /** @var DataExtractionUtilities */
    private $dataUtils;

    /** @var ComponentDataService */
    private $componentDataService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->dataUtils = new DataExtractionUtilities($pdo);
        $this->componentDataService = ComponentDataService::getInstance();
    }

    /**
     * Current rollout mode. Reads env; falls back to "warn" so a fresh deploy
     * does not silently bypass the check.
     *
     * @return string one of "enforce", "warn", "off"
     */
    public static function currentMode(): string
    {
        $mode = getenv('PCIE_LANE_CHECK_ENABLED');
        if (!is_string($mode) || $mode === '') {
            $mode = $_ENV['PCIE_LANE_CHECK_ENABLED'] ?? 'warn';
        }
        $mode = strtolower(trim((string)$mode));
        if (!in_array($mode, ['enforce', 'warn', 'off'], true)) {
            return 'warn';
        }
        return $mode;
    }

    /**
     * Validate a proposed component addition against the total PCIe lane
     * budget of the existing configuration.
     *
     * @param array  $configData     Row from server_configurations (the locked snapshot)
     * @param string $componentType  'nic' | 'hbacard' | 'pciecard' | 'storage'
     * @param string $componentUuid  UUID of the component being added
     * @param array|null $componentSpec Optional pre-loaded spec to avoid a second disk read
     * @return array {
     *   ok:        bool,   // true if the check executed cleanly (independent of allowed)
     *   allowed:   bool,   // true if the addition fits within the budget
     *   mode:      string, // "enforce" | "warn" | "off"
     *   budget:    int,    // total lanes available
     *   used:      int,    // lanes already consumed
     *   requested: int,    // lanes this new component would consume
     *   message:   string, // human-readable explanation
     * }
     */
    public function validateAddition(array $configData, string $componentType, string $componentUuid, ?array $componentSpec = null): array
    {
        $mode = self::currentMode();

        if ($mode === 'off' || !in_array($componentType, ['nic', 'hbacard', 'pciecard', 'storage'], true)) {
            return [
                'ok' => true, 'allowed' => true, 'mode' => $mode,
                'budget' => 0, 'used' => 0, 'requested' => 0,
                'message' => 'PCIe lane check skipped'
            ];
        }

        try {
            $budget = $this->computeLaneBudget($configData);
            $used = $this->computeLanesUsed($configData);

            if ($componentSpec === null) {
                $componentSpec = $this->componentDataService->getComponentSpecifications($componentType, $componentUuid) ?: [];
            }
            $requested = $this->extractLaneCount($componentSpec);

            $allowed = ($requested === 0) || (($used + $requested) <= $budget);

            $message = $allowed
                ? "PCIe lane budget OK: $used + $requested ≤ $budget"
                : "PCIe lane budget exceeded: $budget total, $used already allocated, this " .
                  ($requested > 0 ? "x$requested" : "card") . " needs $requested more lanes";

            return [
                'ok'        => true,
                'allowed'   => $allowed,
                'mode'      => $mode,
                'budget'    => $budget,
                'used'      => $used,
                'requested' => $requested,
                'message'   => $message,
            ];
        } catch (Throwable $e) {
            error_log("PcieLaneBudgetValidator error: " . $e->getMessage());
            // Never block on validator bugs — fail-open.
            return [
                'ok' => false, 'allowed' => true, 'mode' => $mode,
                'budget' => 0, 'used' => 0, 'requested' => 0,
                'message' => 'PCIe lane check errored (fail-open): ' . $e->getMessage()
            ];
        }
    }

    /**
     * Total PCIe lane budget of the server.
     * Sum of each CPU's pcie_lanes, plus motherboard chipset_pcie_lanes if any.
     */
    public function computeLaneBudget(array $configData): int
    {
        $total = 0;

        // CPUs
        $cpuJson = $configData['cpu_configuration'] ?? null;
        if (!empty($cpuJson)) {
            $cpus = json_decode($cpuJson, true);
            if (isset($cpus['cpus']) && is_array($cpus['cpus'])) {
                foreach ($cpus['cpus'] as $cpu) {
                    if (empty($cpu['uuid'])) continue;
                    $specs = $this->dataUtils->getCPUByUUID($cpu['uuid']);
                    if ($specs && isset($specs['pcie_lanes'])) {
                        $total += (int)$specs['pcie_lanes'];
                    }
                }
            }
        }

        // Motherboard chipset lanes (optional field, not always populated)
        $mbUuid = $configData['motherboard_uuid'] ?? null;
        if (!empty($mbUuid)) {
            $mbSpecs = $this->componentDataService->getComponentSpecifications('motherboard', $mbUuid);
            if ($mbSpecs && isset($mbSpecs['chipset_pcie_lanes'])) {
                $total += (int)$mbSpecs['chipset_pcie_lanes'];
            }
        }

        return $total;
    }

    /**
     * Lanes already consumed by installed NIC / HBA / PCIe card / storage.
     */
    public function computeLanesUsed(array $configData): int
    {
        $used = 0;

        $walkFlatJson = function ($json, $type) use (&$used) {
            if (empty($json)) return;
            $arr = json_decode($json, true);
            if (!is_array($arr)) return;
            foreach ($arr as $entry) {
                if (!is_array($entry) || empty($entry['uuid'])) continue;
                $specs = $this->componentDataService->getComponentSpecifications($type, $entry['uuid']);
                $used += $this->extractLaneCount($specs ?? []);
            }
        };

        // NIC lives under nic_config.nics
        $nicJson = $configData['nic_config'] ?? null;
        if (!empty($nicJson)) {
            $nicData = json_decode($nicJson, true);
            if (isset($nicData['nics']) && is_array($nicData['nics'])) {
                foreach ($nicData['nics'] as $nic) {
                    if (empty($nic['uuid'])) continue;
                    // Onboard NICs share motherboard lanes; not counted against expansion budget.
                    if (($nic['source_type'] ?? '') === 'onboard') continue;
                    $specs = $nic['specifications'] ?? null;
                    if (!$specs) {
                        $specs = $this->componentDataService->getComponentSpecifications('nic', $nic['uuid']);
                    }
                    $used += $this->extractLaneCount($specs ?? []);
                }
            }
        }

        $walkFlatJson($configData['hbacard_config']          ?? null, 'hbacard');
        $walkFlatJson($configData['pciecard_configurations'] ?? null, 'pciecard');

        // Storage: only count NVMe (PCIe) storage. SAS/SATA don't consume PCIe lanes directly.
        $storageJson = $configData['storage_configuration'] ?? null;
        if (!empty($storageJson)) {
            $arr = json_decode($storageJson, true);
            if (is_array($arr)) {
                foreach ($arr as $entry) {
                    if (!is_array($entry) || empty($entry['uuid'])) continue;
                    $specs = $this->componentDataService->getComponentSpecifications('storage', $entry['uuid']);
                    if (!$specs) continue;
                    $interface = (string)($specs['interface'] ?? '');
                    if (stripos($interface, 'pcie') === false && stripos($interface, 'nvme') === false) continue;
                    $used += $this->extractLaneCount($specs);
                }
            }
        }

        return $used;
    }

    /**
     * Parse a component spec's `interface` string (e.g. "PCIe 4.0 x8") and
     * return the lane count as an int. Returns 0 when the string has no
     * parseable lane width — caller treats 0-lane cards as "no budget cost".
     */
    private function extractLaneCount(array $spec): int
    {
        $candidate = $spec['interface'] ?? $spec['pcie_interface'] ?? $spec['bus_interface'] ?? '';
        if (!is_string($candidate) || $candidate === '') return 0;
        if (preg_match('/x(\d+)/i', $candidate, $m)) {
            return (int)$m[1];
        }
        // Some specs carry a dedicated numeric field
        if (isset($spec['pcie_lanes']) && is_numeric($spec['pcie_lanes'])) {
            return (int)$spec['pcie_lanes'];
        }
        return 0;
    }
}
