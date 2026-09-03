<?php

require_once __DIR__ . '/BaseCommand.php';
require_once __DIR__ . '/../config/ConfigComponentRepository.php';
require_once __DIR__ . '/../validation/SlotPlanner.php';
require_once __DIR__ . '/../validation/Trigger.php';
require_once __DIR__ . '/../server/ServerBuilder.php';
require_once __DIR__ . '/../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../location/LocationResolver.php';

/**
 * AddComponentCommand — the command-layer strangler over
 * ServerBuilder::addComponent(). PD-5 (documented interpretation): the
 * legacy method is ~450 lines interleaving duplicate-detection, the OLD
 * compatibility precheck, slot auto-assignment, and persistence. This
 * command reuses ONLY the persistence half as a library
 * (ServerBuilder::updateServerConfigurationTable() /
 * updateComponentStatusAndServerUuid(), both made public this unit for
 * exactly this reuse — INV-2/INV-11 zero-behavior-change visibility
 * changes, not new logic) — the compatibility/slot-feasibility half is
 * superseded by ValidationEngine + SlotPlanner, which is the entire point
 * of this migration (INV-2: validation has exactly one owner).
 *
 * OPTIONS vocabulary (per the pack's skim map of ServerBuilder 440-930):
 * serial_number, slot_position, parent_nic_uuid, port_index, override_used,
 * notes.
 *
 * buildTarget() plans the slot (SlotPlanner) and resolves sfp->nic parent_id
 * BEFORE evaluate() runs, matching PcieSlotPlacementRule's own documented
 * design ("already-placed rows are not re-planned" — U-R.3): a still-null
 * slot_ref at evaluate() time means "could not be planned", which the rule
 * then judges as infeasible. apply() re-derives the identical plan (SlotPlanner
 * is a pure function of the same $target the rule already evaluated) purely
 * to know what to persist — it does not re-decide feasibility.
 */
final class AddComponentCommand extends BaseCommand
{
    /** @var string */
    private $componentType;
    /** @var string */
    private $componentUuid;
    /** @var array */
    private $options;
    /** @var array|null set by buildTarget(), read by apply(). NULL for a virtual config — see $isVirtual. */
    private $resolvedInventoryRow;
    /** @var string|null set by buildTarget() when a slot was planned */
    private $plannedSlotRef;
    /**
     * @var int|null config_components.id of this unit's parent row (an SFP's NIC),
     * resolved in buildTarget() and PERSISTED by apply(). It used to be resolved for
     * evaluate()'s benefit and then thrown away at the insert, which is what left every
     * SFP row parentless -- see apply().
     */
    private $resolvedParentId;
    /** @var bool the config is a sandbox/what-if build that must reserve no stock */
    private $isVirtual = false;

    public function __construct(PDO $pdo, string $configUuid, string $componentType, string $componentUuid, array $options = [], $actor = 0, ?int $expectedRevision = null)
    {
        parent::__construct($pdo, $configUuid, $actor, $expectedRevision);
        $this->componentType = $componentType;
        $this->componentUuid = $componentUuid;
        $this->options = $options;
    }

    protected function trigger(): string
    {
        return Trigger::ADD;
    }

    protected function buildTarget(TargetState $current, array $lockedRow): TargetState
    {
        $sb = new ServerBuilder($this->pdo);
        if (!$sb->isValidComponentType($this->componentType)) {
            throw new CommandFailed('invalid_component_type', "Invalid component type: {$this->componentType}", 400);
        }

        $this->isVirtual = !empty($lockedRow['is_virtual']);
        if ($this->isVirtual) {
            $this->resolveVirtualComponent();
        } else {
            $this->resolveRealComponent($lockedRow);
        }

        $this->resolvedParentId = $this->resolveParentId($current);
        $slotRef = $this->resolveSlotRef($current);
        $this->plannedSlotRef = $slotRef;

        // no 'id' key: TargetStateBuilder::withAdd() assigns a synthetic id for evaluate()'s own purposes.
        $row = [
            'component_type' => $this->componentType,
            'spec_uuid' => $this->componentUuid,
            'inventory_table' => $this->resolvedInventoryRow['table'] ?? null,
            'inventory_id' => isset($this->resolvedInventoryRow['data']['ID'])
                ? (int)$this->resolvedInventoryRow['data']['ID']
                : null,
            'serial_number' => $this->resolvedInventoryRow['data']['SerialNumber'] ?? null,
            'parent_id' => $this->resolvedParentId,
            'slot_ref' => $slotRef,
        ];

        return TargetStateBuilder::withAdd($current, $row);
    }

    /**
     * A VIRTUAL config is a what-if build and must reserve nothing: no inventory
     * row is locked, no unit is claimed, and the config_components row it produces
     * carries inventory_table/inventory_id NULL.
     *
     * This restores the guard migration P9 deleted along with
     * ServerBuilder::addComponent(). Between P9 and this change, a sandbox add
     * wrote the real unit's identity into config_components and flipped that unit
     * to Status=2 -- and because uq_inventory_once is keyed on the physical unit,
     * ConfigComponentRepository::insert()'s ON DUPLICATE KEY UPDATE MOVED the row
     * out of whatever real server was holding it.
     *
     * Refuses (503) rather than falling back to the stock-claiming path while the
     * columns are still NOT NULL, because the fallback is exactly the theft this
     * exists to stop. Seeder 2026_09_01_001 relaxes them.
     */
    private function resolveVirtualComponent(): void
    {
        if (!self::unitlessPlacementSupported($this->pdo)) {
            throw new CommandFailed(
                'virtual_placement_unsupported',
                'Sandbox builds cannot hold components until seeder '
                . '2026_09_01_001_nullable-config-components-inventory.sql has been run.',
                503
            );
        }

        // Rule 1 still holds for a sandbox: the model must exist in ims-data. What a
        // virtual build drops is the requirement that we OWN one, which is what made
        // the Compatibility Bench unable to test hardware not already in stock.
        require_once __DIR__ . '/../components/ComponentDataService.php';
        if (!ComponentDataService::getInstance()->validateComponentUuid($this->componentType, $this->componentUuid)) {
            throw new CommandFailed(
                'component_not_found',
                "Component {$this->componentUuid} is not a known {$this->componentType} specification",
                404
            );
        }

        $this->resolvedInventoryRow = null;
    }

    /** The real (stock-claiming) path: lock a physical unit and gate on its availability. */
    private function resolveRealComponent(array $lockedRow): void
    {
        $this->resolvedInventoryRow = $this->lockAndCheckComponent();
        if ($this->resolvedInventoryRow === null) {
            // 'inventory_component_not_found', NOT 'component_not_found': the
            // model is in the ims-data catalogue but NO UNIT of it exists in
            // stock, which is a different fact from "this configuration does not
            // hold that component" (RemoveComponentCommand /
            // ReplaceComponentCommand's old-row miss, which keeps the old type).
            // Only this one is a "not yet" that a request can fix by raising an
            // inventory.component.add prerequisite -- RequestActionExecutor::preflight()
            // defers on exactly this errorType and on nothing else.
            throw new CommandFailed('inventory_component_not_found', "Component {$this->componentUuid} not found in inventory", 404);
        }
        // Finding A (verify record 2026-07-12): legacy's post-lock availability
        // gate + override protocol, ported into BaseCommand.
        $this->assertInventoryAvailability($this->resolvedInventoryRow['data'], $lockedRow, $this->options);
        $this->assertNotAlreadyPlaced();
    }

    /**
     * Refuse to "add" a physical unit that is ALREADY live in a configuration.
     *
     * Observed live 2026-09-01: a model with exactly one unit in stock accepted three
     * consecutive server-add-component calls, each answering 200 "Component added
     * successfully", and produced ONE row. Two of those three successes were fiction.
     *
     * The mechanism is a gap between two guards that each looked complete:
     *   * assertInventoryAvailability() passes a Status=2 unit whose ServerUUID is
     *     THIS config -- an exemption meant for re-adding a unit already bound here;
     *   * lockAndCheckComponent() with no serial orders available units first but
     *     falls back to that same in-use unit when the model has no other;
     *   * ConfigComponentRepository::insert() then takes the ON DUPLICATE KEY branch
     *     on uq_inventory_once and UPDATEs the row it already wrote.
     * Nothing in that chain is wrong on its own, and nothing in it says no.
     *
     * The live row IS the authority on "already installed", which is why this checks
     * config_components rather than {type}inventory.Status -- Status and ServerUUID
     * drift (BACKLOG B-9), a live row does not. A tombstoned row (removed_at set) is
     * not live, so remove-then-re-add still works. The lookup carries component_type
     * because one serverplatform unit legitimately backs both a motherboard row and a
     * chassis row (seeder 2026_08_25_005).
     */
    private function assertNotAlreadyPlaced(): void
    {
        $table = $this->resolvedInventoryRow['table'] ?? null;
        $unitId = isset($this->resolvedInventoryRow['data']['ID'])
            ? (int)$this->resolvedInventoryRow['data']['ID']
            : null;
        if ($table === null || $unitId === null) {
            return; // virtual placement: no physical unit to double-book
        }

        $stmt = $this->pdo->prepare(
            'SELECT config_uuid FROM config_components
              WHERE inventory_table = ? AND inventory_id = ? AND component_type = ?
                AND removed_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$table, $unitId, $this->componentType]);
        $holder = $stmt->fetchColumn();
        if ($holder === false) {
            return;
        }

        if ($holder === $this->configUuid) {
            throw new CommandFailed(
                'component_already_installed',
                "This {$this->componentType} unit is already installed in this configuration. "
                . 'Add a different unit, or take delivery of more stock.',
                409
            );
        }

        throw new CommandFailed(
            'component_unavailable',
            "This {$this->componentType} unit is installed in configuration $holder.",
            409
        );
    }

    /**
     * config_components.id of the row this unit hangs off, or null.
     *
     * Today only an SFP has one: its parent NIC. The resolved value is now carried
     * through to apply() and PERSISTED. It previously existed only for evaluate()'s
     * benefit and was dropped at the insert (a hardcoded 'parent_id' => null), which
     * meant NetSfpPortRule saw every SFP as unparented and took its
     * "staged/unassigned -- allowed" branch, so SFP-to-NIC cage compatibility was
     * never checked on add and the module read back as status 'unassigned'.
     */
    private function resolveParentId(TargetState $current): ?int
    {
        if ($this->componentType !== 'sfp' || empty($this->options['parent_nic_uuid'])) {
            return null;
        }
        foreach ($current->byType('nic') as $nic) {
            if ($nic['spec_uuid'] === $this->options['parent_nic_uuid']) {
                // Synthetic ids from TargetStateBuilder are negative; only a real
                // config_components row can be a persisted FK target.
                return is_int($nic['id']) && $nic['id'] > 0 ? $nic['id'] : null;
            }
        }
        return null;
    }

    /**
     * The slot this unit occupies, in the LEDGER namespace (pcie_1_x16 / riser_1_x16
     * / port_3) that TargetState::freeSlots() matches providers against.
     *
     * risercard is in the slotted list: it was missing, so every riser was persisted
     * with slot_ref NULL and a board with one riser bay accepted unlimited risers.
     * ServerBuilder::evaluateOneCandidate() (the get-compatible listing) has always
     * planned one, so the listing and the add path disagreed.
     *
     * An SFP's slot IS its NIC port, written in the canonical "port_N" shape seeder
     * 2026_08_22_001 established and ConfigReadRouter::portIndexFromSlotRef() reads
     * back.
     */
    private function resolveSlotRef(TargetState $current): ?string
    {
        if ($this->componentType === 'sfp') {
            $portIndex = isset($this->options['port_index']) ? (int)$this->options['port_index'] : 0;
            return $portIndex > 0 ? 'port_' . $portIndex : null;
        }

        if (!in_array($this->componentType, ['nic', 'pciecard', 'hbacard', 'risercard'], true)
            || strpos($this->componentUuid, 'onboard-') === 0
        ) {
            return null;
        }

        $plan = $this->planSlot($current);
        // A plan failure (no free slot / unknown width) leaves slot_ref null;
        // PcieSlotPlacementRule (U-R.3) judges that as infeasible and blocks
        // the trigger via the SAME registry evaluate() every rule runs
        // through — this command does not duplicate that judgment.
        return $plan['ok'] ? $plan['slot_ref'] : null;
    }

    /**
     * Does config_components accept a row with no physical unit behind it?
     *
     * Code reaches production ~20s after a save; seeders are run by hand afterwards,
     * so every reference to a newly-relaxed column has to tolerate the old schema.
     * Same probe-and-degrade shape as platformRowsSupported() in server_api.php.
     * Never queries the catalog schema — the app DB user is denied it and the guard
     * would fail open.
     */
    private static function unitlessPlacementSupported(PDO $pdo): bool
    {
        static $supported = null;
        if ($supported !== null) {
            return $supported;
        }
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM config_components LIKE 'inventory_table'");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $supported = is_array($row) && strtoupper((string)($row['Null'] ?? 'NO')) === 'YES';
        } catch (\Throwable $e) {
            error_log('AddComponentCommand: inventory_table nullability probe failed, assuming NOT NULL: ' . $e->getMessage());
            $supported = false;
        }
        if (!$supported) {
            error_log('AddComponentCommand: config_components.inventory_table is still NOT NULL -- '
                . 'run seeder 2026_09_01_001. Sandbox builds are refused until then, because the '
                . 'only alternative is claiming real stock for a what-if build.');
        }
        return $supported;
    }

    protected function apply(PDO $pdo, TargetState $target): void
    {
        // NULL for a virtual config: a what-if build names a MODEL, never a unit.
        // Every reader is already written for this shape (RemoveComponentCommand
        // guards on inventory_table !== null, TargetStateBuilder::fetchStatusV2()
        // skips null pairs, ConfigReadRouter omits the inventory_id key).
        $inventoryData = $this->resolvedInventoryRow['data'] ?? null;
        $table = $this->resolvedInventoryRow['table'] ?? null;
        $inventoryId = isset($inventoryData['ID']) ? (int)$inventoryData['ID'] : null;
        $serialNumber = $this->isVirtual
            ? null
            : ($inventoryData['SerialNumber'] ?? ($this->options['serial_number'] ?? null));

        $repo = new ConfigComponentRepository($pdo);
        $repo->insert($this->configUuid, [
            'component_type' => $this->componentType,
            'inventory_table' => $table,
            'inventory_id' => $inventoryId,
            'spec_uuid' => $this->componentUuid,
            'serial_number' => $serialNumber,
            'parent_id' => $this->resolvedParentId,
            'slot_ref' => $this->plannedSlotRef,
        ], $this->actor);

        $sb = new ServerBuilder($pdo);
        // Only stamps server_configurations.motherboard_uuid / chassis_uuid; it reads
        // no options. Correct for a virtual config too — that is the config's own row.
        $sb->updateServerConfigurationTable(
            $this->configUuid, $this->componentType, $this->componentUuid, 1, 'add', $serialNumber
        );

        // Mirrors the legacy add path: a racked server's placement was snapshotted at
        // the 1U default before it had a chassis, so re-derive it here. A collision is
        // a real decision (both engines refuse), not a crash.
        if ($this->componentType === 'chassis') {
            require_once __DIR__ . '/../rack/RackPlacement.php';
            require_once __DIR__ . '/../rack/RackEnclosure.php';

            // An enclosure is not a chassis a server is built IN — it is the box
            // that HOLDS servers (an FX2s holds four FC630 sleds, each its own
            // configuration). Building a server on one would give it the
            // enclosure's 2U and four phantom bays, and leave nothing to slot
            // the real sleds into. Refused here rather than only filtered out of
            // the picker, because the picker is not the only way in.
            if (RackEnclosure::isEnclosureChassis($this->componentUuid)) {
                throw new CommandFailed(
                    'chassis_is_enclosure',
                    'That model is a blade enclosure, not a server chassis. Add it to a rack in Rack View, '
                        . 'then install servers into its bays.',
                    400
                );
            }

            $placementSync = RackPlacement::syncHeightFromChassis($pdo, $this->configUuid);
            if (!$placementSync['success']) {
                throw new CommandFailed('rack_placement_conflict', $placementSync['message'], 409);
            }
        }

        // A-11's first half: onboard NICs. This used to be delegated to
        // updateServerConfigurationTable() on the belief that it materialized
        // them internally via createOnboardNICsFromMotherboard() — that method
        // inserted into five columns nicinventory does not have (OnboardIndex,
        // Controller, Ports, Speed, Connector), so it threw on every call and
        // swallowed it. It never once succeeded, and has been deleted; onboard
        // NICs only ever appeared because the LEGACY add path called
        // OnboardNICHandler separately. At COMMAND_LAYER_ENABLED=enforce that
        // legacy path is gone, so this command must call the handler itself.
        // Runs pre-commit inside this same transaction (the handler joins an
        // open transaction rather than opening its own).
        //
        // Skipped for a virtual config: autoAddOnboardNICs() MATERIALIZES rows in
        // nicinventory, which is real stock. A what-if build must not create parts.
        // The trade-off is deliberate and visible: a sandbox board reports no onboard
        // ports rather than manufacturing inventory rows nobody can account for.
        if ($this->componentType === 'motherboard' && !$this->isVirtual) {
            require_once __DIR__ . '/../compatibility/OnboardNICHandler.php';
            $onboard = (new OnboardNICHandler($pdo))->autoAddOnboardNICs(
                $this->configUuid, $this->componentUuid, (int)$inventoryData['ID']
            );
            if (isset($onboard['error'])) {
                throw new CommandFailed(
                    'onboard_nic_failed',
                    'Motherboard added but onboard NICs could not be attached: ' . $onboard['error'],
                    500
                );
            }

            // The handler writes nicinventory and the legacy nic_config blob, but says
            // nothing to the rows store -- the F-13 mirror that fixes that lives in
            // ServerBuilder::addComponent()'s motherboard branch, which this command
            // replaces at COMMAND_LAYER_ENABLED=enforce. Without this loop a loose
            // board's onboard NIC is reserved in inventory yet absent from
            // config_components, so at READ_FROM_ROWS=on every read reports zero NICs.
            // Same fail-closed posture as the error branch above: a writer failure
            // propagates and rolls the whole motherboard add back.
            //
            // parent_id is left to resolve via server_configurations.motherboard_uuid,
            // already stamped by updateServerConfigurationTable() further up.
            require_once __DIR__ . '/../config/ConfigComponentWriter.php';
            foreach (($onboard['nics'] ?? []) as $onboardNic) {
                if (empty($onboardNic['inventory_id'])) {
                    // A 'replaced' port is skipped by the handler and carries no
                    // identity; there is nothing to mirror.
                    continue;
                }
                ConfigComponentWriter::afterLegacyAdd(
                    $pdo,
                    $this->configUuid,
                    'nic',
                    $onboardNic['uuid'],
                    $onboardNic['serial_number'] ?? null,
                    null, // onboard ports occupy no PCIe slot
                    $onboardNic['inventory_table'] ?? 'nicinventory',
                    $onboardNic['inventory_id'],
                    $this->actor,
                    null
                );
            }
        }

        // Everything below claims and re-addresses a PHYSICAL unit. A virtual config
        // has none, so it stops here -- this is what "reserves nothing" now means,
        // and it is the guard P9 deleted with ServerBuilder::addComponent().
        if ($this->isVirtual) {
            return;
        }

        // Identify the unit by the inventory row this command already locked, not by
        // serial: serial-less stock (SerialNumber NULL, addressed by AssetTag) cannot be
        // matched by serial and would otherwise fall through to the model-wide WHERE and
        // be refused by the ambiguity guard.
        $sb->updateComponentStatusAndServerUuid(
            $this->componentType, $this->componentUuid, 2, $this->configUuid, 'Added via command layer (U-C.2)', null, null, $serialNumber, $inventoryId
        );

        // ROOT-CAUSE FIX (2026-08-26): the call above passes null, null for
        // $serverLocation / $serverRackPosition, and updateComponentStatusAndServerUuid()
        // writes those nulls unconditionally when a unit goes in_use -- so a
        // command-layer install ERASED the part's address instead of stamping
        // it, and never wrote location_uuid at all. Rather than hand-computing
        // an address here (a second implementation that would drift), re-derive
        // the whole config's address from its real placement: syncConfig()
        // re-stamps Location, RackPosition AND location_uuid for every unit in
        // the config, and joins this command's open transaction.
        LocationResolver::syncConfig($this->pdo, $this->configUuid);
    }

    /**
     * Own copy of ServerBuilder::lockAndCheckComponent()'s SELECT ... FOR
     * UPDATE semantics (was lines 5463-5523) — commands must not depend on
     * ServerBuilder for locking, per U-C.1's own precedent for
     * lockAndLoadConfigRow(). Table-name lookup uses ServerBuilder's own
     * public getComponentInventoryTable() (a static {type}inventory naming
     * convention, not per-call state) rather than duplicating that map.
     *
     * @return array{table:string, data:array}|null
     */
    private function lockAndCheckComponent(): ?array
    {
        // Type validity is asserted in buildTarget() before either resolution path.
        $table = (new ServerBuilder($this->pdo))->getComponentInventoryTable($this->componentType);

        $serialNumber = $this->options['serial_number'] ?? null;
        if ($serialNumber !== null) {
            $stmt = $this->pdo->prepare("
                SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition
                FROM `$table` WHERE UUID = ? AND SerialNumber = ? FOR UPDATE
            ");
            $stmt->execute([$this->componentUuid, $serialNumber]);
        } else {
            // BUGFIX (A-L1, matching ServerBuilder::lockAndCheckComponent()): the
            // ordering was `ORDER BY Status ASC` with no LIMIT. Status is
            // 0=failed / 1=available / 2=in_use, so ASC returned the FAILED unit first
            // and any add without an explicit serial was rejected as defective while
            // good stock sat available. LIMIT 1 also stops FOR UPDATE locking every
            // unit of the model for the transaction's lifetime.
            // LOCATION PREFERENCE (2026-08-26): with two units of one model at
            // two sites, prefer the one that is already where this server is.
            // Returns null -- and the SQL below is then byte-identical to what
            // it was -- whenever the location columns or the server's own
            // location are unknown. A preference, never a filter: the only free
            // unit still wins even when it is at the wrong site.
            $preferLocation = LocationResolver::preferredUnitLocation($this->pdo, $table, $this->configUuid);
            $locationOrder  = $preferLocation !== null ? '(location_uuid = ?) DESC, ' : '';
            $params         = $preferLocation !== null
                ? [$this->componentUuid, $preferLocation]
                : [$this->componentUuid];

            $stmt = $this->pdo->prepare("
                SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition
                FROM `$table` WHERE UUID = ?
                ORDER BY (Status = 1) DESC, (Status = 2) DESC, {$locationOrder}ID ASC
                LIMIT 1 FOR UPDATE
            ");
            $stmt->execute($params);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['table' => $table, 'data' => $row] : null;
    }

    /** @return array{ok:bool, slot_ref:?string, error:?string, error_code:?string} */
    private function planSlot(TargetState $current): array
    {
        $dataUtils = new DataExtractionUtilities();
        switch ($this->componentType) {
            case 'nic':
                $spec = $dataUtils->getNICByUUID($this->componentUuid);
                break;
            case 'hbacard':
                $spec = $dataUtils->getHBACardByUUID($this->componentUuid);
                break;
            case 'pciecard':
                $spec = $dataUtils->getPCIeCardByUUID($this->componentUuid);
                break;
            case 'risercard':
                $spec = $dataUtils->getRiserCardByUUID($this->componentUuid);
                break;
            default:
                return ['ok' => false, 'slot_ref' => null, 'error' => 'not a slotted type', 'error_code' => 'not_slotted'];
        }
        if (!is_array($spec)) {
            return ['ok' => false, 'slot_ref' => null, 'error' => 'spec not found', 'error_code' => 'spec_not_found'];
        }

        // Type is the riser test since the 2026-08-14 split; the subtype test stays
        // as a fallback for any pciecard row still labelled 'Riser Card'.
        $isRiser = $this->componentType === 'risercard'
            || ($spec['component_subtype'] ?? null) === 'Riser Card';
        $resource = $isRiser ? 'riser_slot' : 'pcie_slot';
        $width = SlotPlanner::extractCardWidth($spec);

        // ROOT CAUSE of "no card is ever slotted" (2026-09-01). The frontend sends
        // slot_position unconditionally, defaulting to '' (server-api.js
        // addComponentToServer, component-installer.js), and handleAddComponent()
        // passes it straight through. An empty string is not a manual slot request --
        // but `?? null` only catches a MISSING key, so '' went to planManual(), which
        // answered "Slot  does not exist". The plan failed, slot_ref was persisted
        // NULL, and PcieSlotPlacementRule then re-planned the card and passed it.
        // Result: every PCIe card in production carries slot_ref NULL, the slot
        // occupancy key never fires, and slot capacity is not enforced at all.
        $manual = $this->options['slot_position'] ?? null;
        if (is_string($manual)) {
            $manual = trim($manual);
        }
        if ($manual === '' || $manual === false) {
            $manual = null;
        }

        return SlotPlanner::plan($current, $resource, $width, $manual);
    }
}
