<?php

require_once __DIR__ . '/../shared/DataExtractionUtilities.php';

/**
 * Thrown by ResourceCatalog on any spec lookup/shape it cannot confidently
 * resolve. Never silently return a partial or guessed result — the caller
 * (a dual-write hook, per U-L.2) decides what a catalog failure means for
 * its own transaction; this class only decides "I don't know" vs "here it is".
 */
class CatalogException extends \RuntimeException
{
}

/**
 * ResourceCatalog — the single owner of "what resources does this component
 * PROVIDE" (config_resources rows: resource, slot_ref, capacity). Isolates
 * ALL ims-data spec parsing here so later units (ledger dual-writer, ledger
 * report) never touch raw JSON shapes directly (migration/06-resource-ledger/README.md).
 *
 * COVERAGE NOTE (read before extending): this unit (U-L.1) was scoped to a
 * fixed set of "Files To Read" (see migration/06-resource-ledger/execution-packs/U-L.1.md)
 * and explicitly forbidden from inventing field names for anything those
 * files didn't show. What's implemented below is exactly what those files
 * (plus one direct, one-hop follow into DataExtractionUtilities.php, referenced
 * directly from ServerBuilder::getChassisPsuWattage()) confirm:
 *   - chassis  -> psu_watt          (power_supply.wattage)
 *   - motherboard -> pcie_slot, m2_slot, riser_slot
 *     (expansion_slots.pcie_slots / expansion_slots.riser_slots|riser_compatibility.max_risers /
 *      storage.nvme.m2_slots, summed across ALL entries per the P3.1 lesson)
 *   - pciecard (component_subtype === 'Riser Card') -> pcie_slot rows the riser itself provides
 *     (pcie_slots count + slot_type, mirroring UnifiedSlotTracker::loadRiserCardProvidedPCIeSlots())
 *   - cpu -> pcie_lane (spec's `pcie_lanes` field, one row per physical CPU; mirrors
 *     PcieLaneBudgetValidator::evaluateAssembledStorageLaneBudget()'s field read — see U-L.4)
 *   - nic -> sfp_port (spec's `ports` field, mirrors NICPortTracker::getPortAssignmentInfo() — U-L.5)
 *   - nic/hbacard/pciecard CONSUME pcie_lane (mirrors PcieLaneBudgetValidator::extractLaneCount()'s
 *     `interface`/`pcie_interface`/`bus_interface` regex, falling back to a numeric `pcie_lanes`
 *     field — U-L.5). NOTE: this is the ONE deliberate exception to this file's fail-closed posture:
 *     an absent/unparseable width returns 0 lanes (empty row set), NOT a CatalogException, because
 *     that is legacy's own fail-open behavior for this exact field and mirroring anything stricter
 *     would invent behavior the codebase has never had. Do not "fix" this to throw.
 * NOT implemented (throws CatalogException, does not guess):
 *   - motherboard cpu_socket count, dimm_slot count/refs — no confirmed structured field found;
 *     the existing legacy code (ServerBuilder::estimateMemorySlots()) resorts to a free-text
 *     regex over the inventory row's Notes field for DIMM count, which is itself evidence there
 *     may be no reliable structured spec field for this today.
 *   - chassis drive_bay_2_5/drive_bay_3_5/u2 bays — not found anywhere in this unit's permitted
 *     read scope (likely lives in StorageConnectionValidator.php, which this unit was not
 *     authorized to read).
 * A follow-up unit/session with real ims-data (or authorization to read those files) should fill
 * these in — see migration/handoffs/U-L.1-<date>.md.
 */
class ResourceCatalog
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    /**
     * @return array<int, array{resource:string, slot_ref:?string, capacity:int}>
     */
    public function provides(string $type, string $specUuid): array
    {
        switch ($type) {
            case 'chassis':
                return $this->providesChassis($specUuid);
            case 'motherboard':
                return $this->providesMotherboard($specUuid);
            case 'pciecard':
                return $this->providesPciecard($specUuid);
            case 'cpu':
                return $this->providesCpu($specUuid);
            case 'nic':
                return $this->providesNic($specUuid);
            case 'ram':
            case 'storage':
            case 'caddy':
            case 'hbacard':
            case 'sfp':
                return []; // confirmed: these types provide no resources today
            default:
                throw new CatalogException("ResourceCatalog::provides(): unknown component_type '$type'");
        }
    }

    /**
     * What resources a component CONSUMES from a scalar/pooled provider (e.g.
     * a CPU's total pcie_lane budget). Discrete resources (pcie_slot,
     * riser_slot) are NOT covered here — see U-L.2's handoff for why slot-level
     * consumer linking is deferred (RV-2: ResourceCatalog's slot_ref naming
     * does not match the legacy slot-assignment system's slot IDs).
     *
     * @return array<int, array{resource:string, amount:int}>
     */
    public function consumes(string $type, string $specUuid): array
    {
        switch ($type) {
            case 'storage':
                return $this->consumesStorage($specUuid);
            case 'nic':
            case 'hbacard':
            case 'pciecard':
                return $this->consumesPcieLanes($type, $specUuid);
            case 'cpu':
            case 'ram':
            case 'motherboard':
            case 'chassis':
            case 'caddy':
            case 'sfp':
                return []; // confirmed: these types consume no scalar resources today
            default:
                throw new CatalogException("ResourceCatalog::consumes(): unknown component_type '$type'");
        }
    }

    /**
     * Mirrors DataExtractionUtilities::extractStoragePCIeLanes(): NVMe/PCIe
     * storage consumes lanes from the CPU's budget; SATA/SAS do not.
     */
    private function consumesStorage(string $specUuid): array
    {
        $lanes = $this->dataUtils->extractStoragePCIeLanes($specUuid);
        if ($lanes === null) {
            return [];
        }
        if (!is_numeric($lanes)) {
            throw new CatalogException("Storage $specUuid pcie lane count is not numeric");
        }
        return [['resource' => 'pcie_lane', 'amount' => (int)$lanes]];
    }

    /**
     * Mirrors PcieLaneBudgetValidator::evaluateAssembledStorageLaneBudget()'s
     * `pcie_lanes` field read. One physical CPU = one provider row (no
     * quantity summing here, unlike the legacy budget check — INV-1).
     */
    private function providesCpu(string $specUuid): array
    {
        $spec = $this->dataUtils->getCPUByUUID($specUuid);
        if (!is_array($spec)) {
            throw new CatalogException("CPU spec not found for UUID $specUuid");
        }

        if (!isset($spec['pcie_lanes'])) {
            return []; // no lane field on this spec — not an error, matches legacy's isset() guard
        }
        if (!is_numeric($spec['pcie_lanes'])) {
            throw new CatalogException("CPU $specUuid pcie_lanes is not numeric");
        }

        return [['resource' => 'pcie_lane', 'slot_ref' => null, 'capacity' => (int)$spec['pcie_lanes']]];
    }

    /**
     * Mirrors NICPortTracker::getPortAssignmentInfo()'s `ports` field read.
     */
    private function providesNic(string $specUuid): array
    {
        $spec = $this->dataUtils->getNICByUUID($specUuid);
        if (!is_array($spec)) {
            throw new CatalogException("NIC spec not found for UUID $specUuid");
        }

        if (!isset($spec['ports'])) {
            return []; // no port field on this spec — not an error, matches providesCpu()'s posture
        }
        if (!is_numeric($spec['ports'])) {
            throw new CatalogException("NIC $specUuid ports is not numeric");
        }

        return [['resource' => 'sfp_port', 'slot_ref' => null, 'capacity' => (int)$spec['ports']]];
    }

    /**
     * Mirrors PcieLaneBudgetValidator::extractLaneCount(): interface string
     * regex (`interface`/`pcie_interface`/`bus_interface`, /x(\d+)/i), falling
     * back to a numeric `pcie_lanes` field, else 0 lanes. Deliberate exception
     * to this file's fail-closed posture — see class docblock.
     */
    private function consumesPcieLanes(string $type, string $specUuid): array
    {
        switch ($type) {
            case 'nic':
                $spec = $this->dataUtils->getNICByUUID($specUuid);
                break;
            case 'hbacard':
                $spec = $this->dataUtils->getHBACardByUUID($specUuid);
                break;
            case 'pciecard':
                $spec = $this->dataUtils->getPCIeCardByUUID($specUuid);
                break;
            default:
                throw new CatalogException("consumesPcieLanes(): unsupported type '$type'");
        }
        if (!is_array($spec)) {
            throw new CatalogException(ucfirst($type) . " spec not found for UUID $specUuid");
        }

        $lanes = $this->extractLaneCount($spec);
        if ($lanes <= 0) {
            return [];
        }
        return [['resource' => 'pcie_lane', 'amount' => $lanes]];
    }

    /**
     * Mirrors PcieLaneBudgetValidator::extractLaneCount() exactly
     * (core/models/compatibility/PcieLaneBudgetValidator.php:346-358): an
     * absent/empty interface candidate returns 0 immediately — the
     * pcie_lanes fallback is reachable only via a NON-empty candidate string
     * that fails the /x(\d+)/i regex. Do not "improve" this to fall back on
     * an absent candidate; that would count lanes the legacy budget check
     * doesn't, which is exactly the equivalence drift this mirror exists to
     * prevent (fail-open, matches legacy, not a CatalogException).
     */
    private function extractLaneCount(array $spec): int
    {
        $candidate = $spec['interface'] ?? $spec['pcie_interface'] ?? $spec['bus_interface'] ?? '';
        if (!is_string($candidate) || $candidate === '') {
            return 0;
        }
        if (preg_match('/x(\d+)/i', $candidate, $m)) {
            return (int)$m[1];
        }
        if (isset($spec['pcie_lanes']) && is_numeric($spec['pcie_lanes'])) {
            return (int)$spec['pcie_lanes'];
        }
        return 0;
    }

    private function providesChassis(string $specUuid): array
    {
        $spec = $this->dataUtils->getChassisSpecifications($specUuid);
        if (!is_array($spec)) {
            throw new CatalogException("Chassis spec not found for UUID $specUuid");
        }

        $rows = [];
        if (isset($spec['power_supply']['wattage'])) {
            $wattage = $spec['power_supply']['wattage'];
            if (!is_numeric($wattage)) {
                throw new CatalogException("Chassis $specUuid power_supply.wattage is not numeric");
            }
            $rows[] = ['resource' => 'psu_watt', 'slot_ref' => null, 'capacity' => (int)$wattage];
        }
        // drive_bay_2_5 / drive_bay_3_5 / u2 bays: NOT implemented, see class docblock.

        return $rows;
    }

    private function providesMotherboard(string $specUuid): array
    {
        $spec = $this->dataUtils->getMotherboardByUUID($specUuid);
        if (!is_array($spec)) {
            throw new CatalogException("Motherboard spec not found for UUID $specUuid");
        }

        $rows = [];
        $rows = array_merge($rows, $this->motherboardPcieSlotRows($specUuid, $spec));
        $rows = array_merge($rows, $this->motherboardM2SlotRows($specUuid, $spec));
        $rows = array_merge($rows, $this->motherboardRiserSlotRows($specUuid, $spec));
        // cpu_socket / dimm_slot: NOT implemented, see class docblock.

        return $rows;
    }

    /**
     * Mirrors UnifiedSlotTracker::loadMotherboardPCIeSlots()'s field access
     * (expansion_slots.pcie_slots: [{type, count, cpu_socket?}, ...]).
     */
    private function motherboardPcieSlotRows(string $specUuid, array $spec): array
    {
        $pcieSlots = $spec['expansion_slots']['pcie_slots'] ?? [];
        if (!is_array($pcieSlots)) {
            throw new CatalogException("Motherboard $specUuid expansion_slots.pcie_slots is not an array");
        }

        $rows = [];
        $index = 1;
        foreach ($pcieSlots as $slotConfig) {
            $slotType = $slotConfig['type'] ?? '';
            $count = $slotConfig['count'] ?? 0;
            if (!is_numeric($count)) {
                throw new CatalogException("Motherboard $specUuid has a non-numeric pcie_slots count");
            }
            if (!preg_match('/x(\d+)/i', (string)$slotType, $m)) {
                throw new CatalogException("Motherboard $specUuid has a pcie_slots entry with an unrecognized type '$slotType'");
            }
            $width = 'x' . $m[1];
            for ($i = 0; $i < (int)$count; $i++) {
                $rows[] = ['resource' => 'pcie_slot', 'slot_ref' => "pcie_{$index}_{$width}", 'capacity' => 1];
                $index++;
            }
        }
        return $rows;
    }

    /**
     * Mirrors UnifiedSlotTracker::loadMotherboardM2Slots(): SUMS every entry's
     * count (the P3.1 lesson — do not take just the first entry).
     */
    private function motherboardM2SlotRows(string $specUuid, array $spec): array
    {
        $m2Slots = $spec['storage']['nvme']['m2_slots'] ?? null;
        if ($m2Slots === null) {
            return [];
        }
        if (!is_array($m2Slots)) {
            throw new CatalogException("Motherboard $specUuid storage.nvme.m2_slots is not an array");
        }

        $total = 0;
        foreach ($m2Slots as $slotConfig) {
            $count = $slotConfig['count'] ?? 0;
            if (!is_numeric($count)) {
                throw new CatalogException("Motherboard $specUuid has a non-numeric m2_slots count");
            }
            $total += (int)$count;
        }
        if ($total <= 0) {
            return [];
        }
        return [['resource' => 'm2_slot', 'slot_ref' => null, 'capacity' => $total]];
    }

    /**
     * Mirrors UnifiedSlotTracker::loadMotherboardRiserSlots(): prefers
     * expansion_slots.riser_slots, falls back to the legacy
     * expansion_slots.riser_compatibility.max_risers (assumed x16).
     */
    private function motherboardRiserSlotRows(string $specUuid, array $spec): array
    {
        $riserSlots = $spec['expansion_slots']['riser_slots'] ?? null;

        if ($riserSlots === null) {
            $maxRisers = $spec['expansion_slots']['riser_compatibility']['max_risers'] ?? 0;
            if (!is_numeric($maxRisers) || $maxRisers <= 0) {
                return [];
            }
            $rows = [];
            for ($i = 1; $i <= (int)$maxRisers; $i++) {
                $rows[] = ['resource' => 'riser_slot', 'slot_ref' => "riser_{$i}_x16", 'capacity' => 1];
            }
            return $rows;
        }

        if (!is_array($riserSlots)) {
            throw new CatalogException("Motherboard $specUuid expansion_slots.riser_slots is not an array");
        }

        $rows = [];
        $index = 1;
        foreach ($riserSlots as $slotConfig) {
            $count = $slotConfig['count'] ?? 1;
            if (!is_numeric($count)) {
                throw new CatalogException("Motherboard $specUuid has a non-numeric riser_slots count");
            }
            $slotType = $slotConfig['type'] ?? 'PCIe x16 Riser';
            $width = preg_match('/x(\d+)/i', (string)$slotType, $m) ? 'x' . $m[1] : 'x16';
            for ($i = 0; $i < (int)$count; $i++) {
                $rows[] = ['resource' => 'riser_slot', 'slot_ref' => "riser_{$index}_{$width}", 'capacity' => 1];
                $index++;
            }
        }
        return $rows;
    }

    /**
     * A riser card (a pciecard whose component_subtype is 'Riser Card', per
     * UnifiedSlotTracker::loadRiserCardProvidedPCIeSlots()) provides the PCIe
     * slots downstream of it. A plain (non-riser) pciecard provides nothing.
     */
    private function providesPciecard(string $specUuid): array
    {
        $spec = $this->dataUtils->getPCIeCardByUUID($specUuid);
        if (!is_array($spec)) {
            throw new CatalogException("PCIe card spec not found for UUID $specUuid");
        }

        if (($spec['component_subtype'] ?? '') !== 'Riser Card') {
            return [];
        }

        $pcieSlots = $spec['pcie_slots'] ?? 0;
        if (!is_numeric($pcieSlots)) {
            throw new CatalogException("Riser card $specUuid has a non-numeric pcie_slots field");
        }
        $pcieSlots = (int)$pcieSlots;
        if ($pcieSlots <= 0) {
            return [];
        }

        $slotType = $spec['slot_type'] ?? 'x16';
        $width = preg_match('/x(\d+)/i', (string)$slotType, $m) ? 'x' . $m[1] : 'x16';

        $rows = [];
        for ($i = 1; $i <= $pcieSlots; $i++) {
            $rows[] = ['resource' => 'pcie_slot', 'slot_ref' => "riser_provided_pcie_{$i}_{$width}", 'capacity' => 1];
        }
        return $rows;
    }
}
