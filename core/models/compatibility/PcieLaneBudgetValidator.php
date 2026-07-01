<?php

require_once __DIR__ . '/../components/ComponentDataService.php';
require_once __DIR__ . '/../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/ServerState.php';

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
    public function validateAddition(array $configData, string $componentType, string $componentUuid, ?array $componentSpec = null, int $quantity = 1): array
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
            $qty = max(1, $quantity);
            $perCard = $this->extractLaneCount($componentSpec);
            $requested = $perCard * $qty;

            $allowed = ($requested === 0) || (($used + $requested) <= $budget);

            $message = $allowed
                ? "PCIe lane budget OK: $used + $requested ≤ $budget"
                : "PCIe lane budget exceeded: $budget total, $used already allocated, " .
                  ($qty > 1 ? "$qty x{$perCard} cards" : "this x{$perCard} card") .
                  " needs $requested more lanes";

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
     * Single-authority lane evaluation over an ALREADY-ASSEMBLED component map
     * (the `$existing` shape used by StorageConnectionValidator: keyed by type,
     * each entry carrying `component_uuid` / `quantity` / `source_type`).
     *
     * This is the Phase-3 (M11) "one lane model" entry point: it lets the storage
     * connection path delegate its PCIe-lane question to the SAME model used at
     * add-/finalize-time, instead of its own divergent `checkPCIeLaneBudget`.
     * It reuses {@see extractLaneCount} and the exact inclusion rules of
     * computeLaneBudget/computeLanesUsed (all CPUs × qty for budget; non-onboard
     * NIC + HBA + pciecard + non-M.2 NVMe storage for used), so a card's width is
     * derived identically everywhere. Absent/unparseable width = 0 lanes (data-gated,
     * never fabricated) — same posture as the authoritative model.
     *
     * @param array $existing            Assembled component map ('cpu','motherboard','nic','hbacard','pciecard','storage')
     * @param array $candidateStorageSpec Spec of the NVMe/PCIe storage device being added
     * @param int   $qty                 Quantity of the candidate device
     * @return array{sufficient:bool,budget:int,used:int,requested:int,available_lanes:int}
     */
    public function evaluateAssembledStorageLaneBudget(array $existing, array $candidateStorageSpec, int $qty = 1): array
    {
        // Budget: every CPU's pcie_lanes × quantity, plus motherboard chipset lanes
        // (absent from all specs today → contributes 0; forward-compatible guard).
        $budget = 0;
        if (!empty($existing['cpu']) && is_array($existing['cpu'])) {
            foreach ($existing['cpu'] as $cpu) {
                $uuid = $cpu['component_uuid'] ?? '';
                if ($uuid === '') continue;
                $specs = $this->dataUtils->getCPUByUUID($uuid);
                if ($specs && isset($specs['pcie_lanes'])) {
                    $q = max(1, (int)($cpu['quantity'] ?? 1));
                    $budget += (int)$specs['pcie_lanes'] * $q;
                }
            }
        }
        if (!empty($existing['motherboard']['component_uuid'])) {
            $mbSpecs = $this->componentDataService->getComponentSpecifications('motherboard', $existing['motherboard']['component_uuid']);
            if ($mbSpecs && isset($mbSpecs['chipset_pcie_lanes'])) {
                $budget += (int)$mbSpecs['chipset_pcie_lanes'];
            }
        }

        // Used: non-onboard NIC + HBA + pciecard + non-M.2 NVMe storage, each via the
        // single extractLaneCount() parser × quantity.
        $used = 0;
        if (!empty($existing['nic']) && is_array($existing['nic'])) {
            foreach ($existing['nic'] as $nic) {
                $src = strtolower((string)($nic['source_type'] ?? $nic['SourceType'] ?? 'component'));
                $uuid = $nic['component_uuid'] ?? '';
                if ($src === 'onboard' || strpos((string)$uuid, 'onboard-') === 0) continue;
                $specs = $this->componentDataService->getComponentSpecifications('nic', $uuid) ?: [];
                $q = max(1, (int)($nic['quantity'] ?? 1));
                $used += $this->extractLaneCount($specs) * $q;
            }
        }
        foreach (['hbacard' => 'hbacard', 'pciecard' => 'pciecard'] as $key => $type) {
            if (!empty($existing[$key]) && is_array($existing[$key])) {
                foreach ($existing[$key] as $card) {
                    $uuid = $card['component_uuid'] ?? '';
                    if ($uuid === '') continue;
                    $specs = $this->componentDataService->getComponentSpecifications($type, $uuid) ?: [];
                    $q = max(1, (int)($card['quantity'] ?? 1));
                    $used += $this->extractLaneCount($specs) * $q;
                }
            }
        }
        if (!empty($existing['storage']) && is_array($existing['storage'])) {
            foreach ($existing['storage'] as $storage) {
                $uuid = $storage['component_uuid'] ?? '';
                if ($uuid === '') continue;
                $specs = $this->componentDataService->getComponentSpecifications('storage', $uuid);
                if (!$specs) continue;
                $interface = (string)($specs['interface'] ?? '');
                if (stripos($interface, 'pcie') === false && stripos($interface, 'nvme') === false) continue;
                // M.2 NVMe uses dedicated chipset lanes, not the expansion budget (TP-1C).
                $ff = strtolower((string)($specs['form_factor'] ?? ''));
                if (strpos($ff, 'm.2') !== false || strpos($ff, 'm2') !== false) continue;
                $q = max(1, (int)($storage['quantity'] ?? 1));
                $used += $this->extractLaneCount($specs) * $q;
            }
        }

        // Candidate demand: the new device's OWN parsed width × qty (not a hardcoded
        // x4). M.2 candidates ride dedicated chipset lanes → zero expansion cost.
        $candFf = strtolower((string)($candidateStorageSpec['form_factor'] ?? ''));
        $isM2 = (strpos($candFf, 'm.2') !== false || strpos($candFf, 'm2') !== false);
        $requested = $isM2 ? 0 : ($this->extractLaneCount($candidateStorageSpec) * max(1, $qty));

        $available = $budget - $used;
        $sufficient = ($requested === 0) || ($requested <= $available);

        return [
            'sufficient'      => $sufficient,
            'budget'          => $budget,
            'used'            => $used,
            'requested'       => $requested,
            'available_lanes' => $available,
        ];
    }

    /**
     * Total PCIe lane budget of the server.
     * Sum of each CPU's pcie_lanes, plus motherboard chipset_pcie_lanes if any.
     */
    public function computeLaneBudget(array $configData): int
    {
        $total = 0;
        // P2 (M11): decode config columns through the single guarded read-model (TP-5B)
        // instead of raw json_decode. Behaviour-preserving: empty/malformed → [].
        $state = ServerState::fromConfigData($configData);

        // CPUs
        $cpus = $state->getDecodedColumn('cpu_configuration');
        if (isset($cpus['cpus']) && is_array($cpus['cpus'])) {
            foreach ($cpus['cpus'] as $cpu) {
                if (empty($cpu['uuid'])) continue;
                $specs = $this->dataUtils->getCPUByUUID($cpu['uuid']);
                if ($specs && isset($specs['pcie_lanes'])) {
                    $qty = max(1, (int)($cpu['quantity'] ?? 1));
                    $total += (int)$specs['pcie_lanes'] * $qty;
                }
            }
        }

        // Motherboard chipset lanes: the chipset_pcie_lanes key is absent from every
        // ims-data motherboard spec, so this currently adds nothing. The guard keeps
        // it forward-compatible if data is added, but a correct model must treat
        // chipset lanes as sharing a narrow CPU/DMI uplink rather than pooling them
        // into this single fungible budget (tracked under H4 / TP-1B). [TP-1A]
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
        // P2 (M11): decode config columns through the single guarded read-model (TP-5B)
        // instead of raw json_decode. Behaviour-preserving: empty/malformed → [].
        $state = ServerState::fromConfigData($configData);

        $walkFlatJson = function ($column, $type) use (&$used, $state) {
            foreach ($state->getDecodedColumn($column) as $entry) {
                if (!is_array($entry) || empty($entry['uuid'])) continue;
                $specs = $this->componentDataService->getComponentSpecifications($type, $entry['uuid']);
                $qty = max(1, (int)($entry['quantity'] ?? 1));
                $used += $this->extractLaneCount($specs ?? []) * $qty;
            }
        };

        // NIC lives under nic_config.nics
        $nicData = $state->getDecodedColumn('nic_config');
        if (isset($nicData['nics']) && is_array($nicData['nics'])) {
            foreach ($nicData['nics'] as $nic) {
                if (empty($nic['uuid'])) continue;
                // Onboard NICs share motherboard lanes; not counted against expansion budget.
                if (($nic['source_type'] ?? '') === 'onboard') continue;
                $specs = $nic['specifications'] ?? null;
                if (!$specs) {
                    $specs = $this->componentDataService->getComponentSpecifications('nic', $nic['uuid']);
                }
                $qty = max(1, (int)($nic['quantity'] ?? 1));
                $used += $this->extractLaneCount($specs ?? []) * $qty;
            }
        }

        $walkFlatJson('hbacard_config', 'hbacard');
        $walkFlatJson('pciecard_configurations', 'pciecard');

        // Storage: only count NVMe (PCIe) storage. SAS/SATA don't consume PCIe lanes directly.
        {
            $arr = $state->getDecodedColumn('storage_configuration');
            if (is_array($arr)) {
                foreach ($arr as $entry) {
                    if (!is_array($entry) || empty($entry['uuid'])) continue;
                    $specs = $this->componentDataService->getComponentSpecifications('storage', $entry['uuid']);
                    if (!$specs) continue;
                    $interface = (string)($specs['interface'] ?? '');
                    if (stripos($interface, 'pcie') === false && stripos($interface, 'nvme') === false) continue;
                    // BUGFIX (TP-1C): M.2 NVMe drives use dedicated motherboard M.2 slots
                    // (with their own chipset lanes), NOT the shared PCIe expansion-lane
                    // budget. StorageConnectionValidator excludes them; this validator
                    // must too, otherwise an M.2 add inflates the system budget here and
                    // causes a false "lane budget exceeded".
                    $formFactor = strtolower((string)($specs['form_factor'] ?? ''));
                    if (strpos($formFactor, 'm.2') !== false || strpos($formFactor, 'm2') !== false) continue;
                    $qty = max(1, (int)($entry['quantity'] ?? 1));
                    $used += $this->extractLaneCount($specs) * $qty;
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
