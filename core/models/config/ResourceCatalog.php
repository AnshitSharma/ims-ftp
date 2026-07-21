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
 *   - motherboard -> cpu_socket, dimm_slot (added U-R.1/U-R.2, migration/04-validation-engine):
 *     `socket.count` / `memory.slots` — confirmed field paths via
 *     ComponentValidator::parseMotherboardSpecifications() (core/models/components/ComponentValidator.php:123-128),
 *     which U-L.1 was not authorized to read. Defaults (1 / 4) mirror that method's own `?? 1`/`?? 4`
 *     fallbacks exactly. This closes the gap the U-L.1 docblock originally left open below.
 *   - chassis -> drive_bay_2_5, drive_bay_3_5 (added U-R.5, migration/04-validation-engine):
 *     `drive_bays.bay_configuration` (confirmed via
 *     ComponentCompatibility::checkChassisDecentralizedCompatibility(), which U-L.1 was not
 *     authorized to read). u2 bays remain unimplemented — see chassisDriveBayRows() docblock
 *   - cpu/storage/nic/hbacard/pciecard CONSUME psu_watt (added U-R.7, migration/04-validation-engine):
 *     each type's OWN structured ims-data power field (cpu.tdp_W, storage.power_consumption_W.active,
 *     nic.power (a "<N>W" string, numeric part parsed), hbacard/pciecard.power_consumption.typical_W).
 *     DOCUMENTED DEVIATION from legacy: ServerBuilder::checkPowerCompatibilityDetailed()'s own power
 *     estimate (was ~5698-5730) reads free-text per-PHYSICAL-UNIT `Notes` via regex (2.5W/core for
 *     cpu, 1W/4GB for ram, flat 8W/12W SSD/HDD for storage) — instance-level data ResourceCatalog
 *     cannot see (it resolves by spec_uuid only, per INV-1's row-per-physical-unit design; Notes is
 *     never part of the TargetState component tuple). ims-data has NO structured power field for ram
 *     at all (confirmed by reading ram_detail.json in full) — ram consumes 0 psu_watt here, same
 *     "no confirmed structured field" posture as U-L.1's original cpu_socket/dimm_slot gap. This
 *     rule's power math is therefore NOT expected to numerically equal legacy's Notes-regex estimate
 *     on the same fixture; it is a real, catalog-native, arguably more accurate substitute using the
 *     SAME 85%-continuous-ceiling threshold formula. Flagged for human review (see U-R.7 handoff).
 *     for why there is no legacy u2-bay-capacity behavior to mirror.
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
     * Synthetic onboard-NIC rows (spec_uuid "onboard-{mb8}-{inventoryId}-{n}"
     * from OnboardNICHandler::autoAddOnboardNICs(), plus the two legacy formats
     * listed on parseOnboardNicUuid()) are runtime-materialized from the
     * parent motherboard's networking.onboard_nics and never exist in the
     * nic ims-data JSON — every spec lookup on them is guaranteed to fail.
     * provides()/consumes() therefore return [] for them instead of throwing
     * (they consume no PCIe lanes — legacy skips them in the lane budget,
     * PcieLaneBudgetValidator.php:186 — and have no structured power field).
     * Their real sfp_port provision is resolved via the parent board by
     * providesOnboardNic(), called from TargetState::resources(), which is
     * the only caller that can see the board in the same state.
     */
    public static function isOnboardNicUuid(string $specUuid): bool
    {
        return strpos($specUuid, 'onboard-') === 0;
    }

    /**
     * Three synthetic formats exist and all must parse:
     *   onboard-{mb8}-{inventoryId}-{n}  current, scoped to the PHYSICAL board
     *   onboard-{mb8}-{n}                legacy, scoped to the motherboard model
     *   onboard-nic-{mb24}-{n}           legacy, ServerBuilder's dead generator
     *
     * board_prefix is the parent motherboard's SPEC-uuid prefix in every case,
     * so prefix-matching callers stay correct across the format change; the
     * physical board is exposed separately as inventory_id (null on legacy
     * rows, which encoded no such thing — that was the collision bug).
     *
     * @return array{board_prefix:string, inventory_id:int|null, index:int}|null
     */
    public static function parseOnboardNicUuid(string $specUuid): ?array
    {
        // Unit-scoped: {mb8} is exactly 8 hex chars, then two numeric segments.
        // A legacy "onboard-{mb8}-{n}" has only ONE trailing numeric segment and
        // so cannot match here; "onboard-nic-..." fails the hex test on "nic-".
        if (preg_match('/^onboard-([0-9a-fA-F]{8})-(\d+)-(\d+)$/', $specUuid, $m)) {
            return ['board_prefix' => $m[1], 'inventory_id' => (int)$m[2], 'index' => (int)$m[3]];
        }
        if (preg_match('/^onboard-(?:nic-)?(.+)-(\d+)$/', $specUuid, $m)) {
            return ['board_prefix' => $m[1], 'inventory_id' => null, 'index' => (int)$m[2]];
        }
        return null;
    }

    /**
     * sfp_port provision for a synthetic onboard NIC, resolved via its parent
     * motherboard's networking.onboard_nics[index-1].ports — mirrors
     * NICPortTracker::resolveOnboardNicSpecs()'s resolution (and its
     * fail-open null-on-failure posture: any unresolvable step returns [],
     * never throws, matching legacy's error_log-and-continue behavior).
     *
     * @return array<int, array{resource:string, slot_ref:?string, capacity:int}>
     */
    public function providesOnboardNic(string $onboardUuid, ?string $boardSpecUuid): array
    {
        $parsed = self::parseOnboardNicUuid($onboardUuid);
        if ($parsed === null || $boardSpecUuid === null) {
            return [];
        }
        $spec = $this->dataUtils->getMotherboardByUUID($boardSpecUuid);
        $nics = is_array($spec) ? ($spec['networking']['onboard_nics'] ?? null) : null;
        $entry = is_array($nics) ? ($nics[$parsed['index'] - 1] ?? null) : null;
        $ports = is_array($entry) ? ($entry['ports'] ?? null) : null;
        if (!is_numeric($ports) || (int)$ports <= 0) {
            return [];
        }
        return [['resource' => 'sfp_port', 'slot_ref' => null, 'capacity' => (int)$ports]];
    }

    /**
     * @return array<int, array{resource:string, slot_ref:?string, capacity:int}>
     */
    public function provides(string $type, string $specUuid): array
    {
        if ($type === 'nic' && self::isOnboardNicUuid($specUuid)) {
            return []; // see isOnboardNicUuid() docblock — resolved via providesOnboardNic() instead
        }
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
        if ($type === 'nic' && self::isOnboardNicUuid($specUuid)) {
            return []; // legacy skips onboard NICs in the lane budget (PcieLaneBudgetValidator.php:186); no structured power field either
        }
        switch ($type) {
            case 'storage':
                return array_merge($this->consumesStorage($specUuid), $this->consumesPsuWatt($type, $specUuid));
            case 'nic':
            case 'hbacard':
            case 'pciecard':
                return array_merge($this->consumesPcieLanes($type, $specUuid), $this->consumesPsuWatt($type, $specUuid));
            case 'cpu':
                return $this->consumesPsuWatt($type, $specUuid);
            case 'ram':
            case 'motherboard':
            case 'chassis':
            case 'caddy':
            case 'sfp':
                return $this->consumesPsuWatt($type, $specUuid); // ram/motherboard/chassis/caddy/sfp: no structured power field today -> []
            default:
                throw new CatalogException("ResourceCatalog::consumes(): unknown component_type '$type'");
        }
    }

    /**
     * See class docblock "cpu/storage/nic/hbacard/pciecard CONSUME psu_watt" note
     * for the documented deviation from legacy's Notes-regex power estimate.
     * Returns [] (never throws) when the type has no structured power field —
     * matches this file's established "absent field -> no row" posture, not
     * this file's default fail-closed posture, because an absent field here
     * is a genuine, confirmed data gap (ram), not an unparseable value.
     */
    private function consumesPsuWatt(string $type, string $specUuid): array
    {
        switch ($type) {
            case 'cpu':
                $spec = $this->dataUtils->getCPUByUUID($specUuid);
                $watts = is_array($spec) ? ($spec['tdp_W'] ?? null) : null;
                break;
            case 'storage':
                $spec = $this->dataUtils->getStorageByUUID($specUuid);
                $watts = is_array($spec) ? ($spec['power_consumption_W']['active'] ?? null) : null;
                break;
            case 'nic':
                $spec = $this->dataUtils->getNICByUUID($specUuid);
                $raw = is_array($spec) ? ($spec['power'] ?? null) : null;
                $watts = null;
                if (is_string($raw) && preg_match('/(\d+(\.\d+)?)/', $raw, $m)) {
                    $watts = (float)$m[1];
                }
                break;
            case 'hbacard':
                $spec = $this->dataUtils->getHBACardByUUID($specUuid);
                $watts = is_array($spec) ? ($spec['power_consumption']['typical_W'] ?? null) : null;
                break;
            case 'pciecard':
                $spec = $this->dataUtils->getPCIeCardByUUID($specUuid);
                $watts = is_array($spec) ? ($spec['power_consumption']['typical_W'] ?? null) : null;
                break;
            default:
                return []; // ram/motherboard/chassis/caddy/sfp: no structured power field in ims-data today
        }
        if ($watts === null) {
            return [];
        }
        if (!is_numeric($watts)) {
            throw new CatalogException(ucfirst($type) . " $specUuid power field is not numeric");
        }
        return [['resource' => 'psu_watt', 'amount' => (int)ceil((float)$watts)]];
    }

    /**
     * Mirrors PcieLaneBudgetValidator::computeLanesUsed()'s storage branch
     * exactly (PcieLaneBudgetValidator.php:315-334, the check-4 "one lane
     * model"): only NVMe/PCIe-interface storage consumes lanes; M.2 is
     * excluded (RV-4 fix — dedicated chipset lanes, PcieLaneBudgetValidator
     * .php:325-331); and the WIDTH comes from extractLaneCount()'s explicit
     * x<N>/pcie_lanes parse — an NVMe spec with no parseable width consumes
     * 0 lanes, same as legacy. Previously this used DataExtractionUtilities
     * ::extractStoragePCIeLanes(), whose `?? 4` NVMe default counted 4 lanes
     * legacy never counts (surfaced as ledger_report check-4
     * lane_model_mismatch on the real fleet, U-B.4 triage 2026-07-15).
     * extractStoragePCIeLanes() itself is left untouched — legacy callers
     * depend on its current behavior.
     */
    private function consumesStorage(string $specUuid): array
    {
        $spec = $this->dataUtils->getStorageByUUID($specUuid);
        if (!is_array($spec)) {
            return []; // no spec -> no lanes, mirrors computeLanesUsed's `if (!$specs) continue;`
        }
        $interface = (string)($spec['interface'] ?? '');
        if (stripos($interface, 'pcie') === false && stripos($interface, 'nvme') === false) {
            return []; // SATA/SAS don't consume PCIe lanes
        }
        $ff = strtolower((string)($spec['form_factor'] ?? ''));
        if (strpos($ff, 'm.2') !== false || strpos($ff, 'm2') !== false) {
            return [];
        }
        $lanes = $this->extractLaneCount($spec);
        if ($lanes <= 0) {
            return [];
        }
        return [['resource' => 'pcie_lane', 'amount' => $lanes]];
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
        $rows = array_merge($rows, $this->chassisDriveBayRows($specUuid, $spec));
        // u2 bays: NOT implemented -- StorageConnectionValidator's own bay logic
        // (calculateRequiredBays(), ComponentCompatibility.php:5286) only ever
        // tallies 2.5"/3.5" traditional bays; M.2/U.2 storage bypasses bay
        // validation entirely in that same method (both confirmed by reading it
        // for U-R.5), so there is no legacy u2-bay-capacity behavior to mirror.

        return $rows;
    }

    /**
     * Mirrors ComponentDataExtractor::extractChassisBayConfiguration() (raw
     * field `drive_bays.bay_configuration`: [{bay_type, count}, ...], summed
     * per type) -- confirmed via ComponentCompatibility::checkChassisDecentralizedCompatibility()
     * (was lines 3146-3243), read for U-R.5. Bay type spelling is inconsistent
     * in real chassis JSON (both "2.5_inch" and "2.5-inch" appear — see
     * ComponentCompatibility.php:3194 STRICT matching comment); both spellings
     * normalize to the same resource name here so capacity isn't silently lost
     * to a spelling variant. Only 2.5"/3.5" bay types are recognized (matches
     * legacy's own strict scope — other bay_type values are not summed).
     */
    private function chassisDriveBayRows(string $specUuid, array $spec): array
    {
        $bayConfig = $spec['drive_bays']['bay_configuration'] ?? [];
        if (!is_array($bayConfig)) {
            throw new CatalogException("Chassis $specUuid drive_bays.bay_configuration is not an array");
        }

        $capacity = ['drive_bay_2_5' => 0, 'drive_bay_3_5' => 0];
        foreach ($bayConfig as $bay) {
            $bayType = strtolower((string)($bay['bay_type'] ?? ''));
            $count = $bay['count'] ?? 0;
            if (!is_numeric($count)) {
                throw new CatalogException("Chassis $specUuid has a non-numeric drive bay count");
            }
            if (strpos($bayType, '2.5') !== false) {
                $capacity['drive_bay_2_5'] += (int)$count;
            } elseif (strpos($bayType, '3.5') !== false) {
                $capacity['drive_bay_3_5'] += (int)$count;
            }
        }

        $rows = [];
        foreach ($capacity as $resource => $count) {
            if ($count > 0) {
                $rows[] = ['resource' => $resource, 'slot_ref' => null, 'capacity' => $count];
            }
        }
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
        $rows = array_merge($rows, $this->motherboardCpuSocketRows($specUuid, $spec));
        $rows = array_merge($rows, $this->motherboardDimmSlotRows($specUuid, $spec));

        return $rows;
    }

    /**
     * Mirrors ComponentValidator::parseMotherboardSpecifications()'s
     * `socket.type`/`socket.count` read (core/models/components/ComponentValidator.php:123-126,
     * confirmed field paths — the U-L.1 docblock's "NOT implemented, no confirmed structured
     * field found" note predates this file being in scope; U-R.1 (04-validation-engine) added
     * read access to it). One pooled row per motherboard: capacity = socket.count, default 1
     * (matches ComponentCompatibility::getMotherboardLimits()'s `?? 1` fallback exactly).
     */
    private function motherboardCpuSocketRows(string $specUuid, array $spec): array
    {
        $count = $spec['socket']['count'] ?? 1;
        if (!is_numeric($count)) {
            throw new CatalogException("Motherboard $specUuid socket.count is not numeric");
        }
        $count = (int)$count;
        if ($count <= 0) {
            return [];
        }
        return [['resource' => 'cpu_socket', 'slot_ref' => null, 'capacity' => $count]];
    }

    /**
     * Mirrors ComponentValidator::parseMotherboardSpecifications()'s
     * `memory.slots` read (ComponentValidator.php:128, default 4 matching
     * that file's own `?? 4` fallback exactly).
     */
    private function motherboardDimmSlotRows(string $specUuid, array $spec): array
    {
        $slots = $spec['memory']['slots'] ?? 4;
        if (!is_numeric($slots)) {
            throw new CatalogException("Motherboard $specUuid memory.slots is not numeric");
        }
        $slots = (int)$slots;
        if ($slots <= 0) {
            return [];
        }
        return [['resource' => 'dimm_slot', 'slot_ref' => null, 'capacity' => $slots]];
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
