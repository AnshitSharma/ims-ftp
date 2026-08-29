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
 * SCOPE AFTER P9 (2026-08-30). The add-time entry point (validateAddition()) and
 * the PCIE_LANE_CHECK_ENABLED flag that gated it are gone — PcieLaneBudgetRule in
 * the ValidationEngine registry owns add-time lane budgeting now. What survives is
 * evaluateAssembledStorageLaneBudget(), still called live from
 * StorageConnectionValidator::checkPCIeLaneBudget(), plus the lane arithmetic
 * scripts/verify/ledger_report.php recomputes against.
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
        foreach (['hbacard' => 'hbacard', 'pciecard' => 'pciecard', 'risercard' => 'risercard'] as $key => $type) {
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
        // U-D.3b: the CPU list comes from config_components rows via ServerState's typed
        // accessor. Per-entry quantity is 1 on the rows side (one row per physical unit)
        // where legacy could carry a single quantity=N entry, so the multiply below is
        // now usually x1 -- the TOTAL is the same either way, which is all this sums.
        $state = ServerState::fromConfigData($configData, $this->pdo);

        // CPUs
        foreach ($state->getCpus() as $cpu) {
            if (empty($cpu['component_uuid'])) continue;
            $specs = $this->dataUtils->getCPUByUUID($cpu['component_uuid']);
            if ($specs && isset($specs['pcie_lanes'])) {
                $qty = max(1, (int)($cpu['quantity'] ?? 1));
                $total += (int)$specs['pcie_lanes'] * $qty;
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
        // U-D.3b: every list below comes from config_components rows via ServerState's
        // typed accessors, replacing four per-column json_decodes.
        $state = ServerState::fromConfigData($configData, $this->pdo);

        $walkType = function (array $entries, $type) use (&$used) {
            foreach ($entries as $entry) {
                if (empty($entry['component_uuid'])) continue;
                $specs = $this->componentDataService->getComponentSpecifications($type, $entry['component_uuid']);
                $qty = max(1, (int)($entry['quantity'] ?? 1));
                $used += $this->extractLaneCount($specs ?? []) * $qty;
            }
        };

        foreach ($state->getNics() as $nic) {
            if (empty($nic['component_uuid'])) continue;
            // Onboard NICs share motherboard lanes; not counted against expansion budget.
            // The rows side marks them via ConfigReadRouter's source_type; the belt-and-
            // braces prefix test is the same one line 101 above already applies.
            if (($nic['source_type'] ?? '') === 'onboard'
                || strpos((string)$nic['component_uuid'], 'onboard-') === 0) continue;
            // The inline 'specifications' blob the legacy nic_config carried is gone --
            // rows store identity, not specs. ComponentDataService is the only spec
            // authority anyway, and this was already the fallback for every entry that
            // lacked the blob.
            $specs = $this->componentDataService->getComponentSpecifications('nic', $nic['component_uuid']);
            $qty = max(1, (int)($nic['quantity'] ?? 1));
            $used += $this->extractLaneCount($specs ?? []) * $qty;
        }

        $walkType($state->getHbas(), 'hbacard');
        $walkType($state->getPcieCards(), 'pciecard');

        // Storage: only count NVMe (PCIe) storage. SAS/SATA don't consume PCIe lanes directly.
        {
            foreach ($state->getStorage() as $entry) {
                if (empty($entry['component_uuid'])) continue;
                $specs = $this->componentDataService->getComponentSpecifications('storage', $entry['component_uuid']);
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
