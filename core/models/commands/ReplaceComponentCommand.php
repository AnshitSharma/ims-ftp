<?php

require_once __DIR__ . '/BaseCommand.php';
require_once __DIR__ . '/../config/ConfigComponentRepository.php';
require_once __DIR__ . '/../validation/SlotPlanner.php';
require_once __DIR__ . '/../validation/Trigger.php';
require_once __DIR__ . '/../server/ServerBuilder.php';
require_once __DIR__ . '/../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../location/LocationResolver.php';

/**
 * ReplaceComponentCommand — a NEW capability (RULE_MAP.md: no legacy
 * counterpart, so shadow parity is zero-diffs by construction; this unit
 * ships the command + tests only, reachable from no API action yet — U-A.2
 * exposes it). Composes AddComponentCommand's/RemoveComponentCommand's own
 * apply() helpers (same library calls) rather than duplicating them.
 *
 * buildTarget() produces ONE resulting TargetState — old absent, new present,
 * children re-anchored — in a single pass, so RP-1's "intermediate state a
 * rule could observe" never exists (matches the pack's "single tx, single
 * verdict" requirement) and the SAME state both evaluate() and apply() work
 * from:
 *   1. remove old (cascade=false -- a replace is not a cascade-remove)
 *   2. add new (slot inheritance: reuse old's own slot_ref when SlotPlanner
 *      confirms the new spec's width still fits that exact slot -- it just
 *      became free in step 1's TargetState, so this is a plain width check,
 *      not new planner logic; otherwise fall back to a fresh SlotPlanner::plan())
 *   3. re-anchor: any row whose parent_id pointed at old's row id is
 *      rewritten to new's (synthetic, pre-apply) row id -- e.g. a NIC's SFPs
 *      survive a NIC A->B replace instead of becoming dependency.blocked_removal
 *      dependents.
 *
 * A board A->B replace that is itself incompatible blocks with the OLD board
 * still in place (evaluate() runs against the single post-replace state
 * before apply() ever touches the DB) -- the audit's "stranding" scenario
 * (remove-then-blocked-add leaving a config boardless) is structurally
 * impossible here, since remove+add is one state, one verdict, one commit.
 */
final class ReplaceComponentCommand extends BaseCommand
{
    /** @var string */
    private $componentType;
    /** @var string */
    private $oldComponentUuid;
    /** @var string|null */
    private $oldSerialNumber;
    /** @var int|null the exact inventory row coming out, when the caller knows it */
    private $oldInventoryId;
    /** @var string */
    private $newComponentUuid;
    /** @var array */
    private $options;

    /** @var array|null resolved by buildTarget() */
    private $oldRow;
    /** @var array|null resolved by buildTarget() */
    private $newInventoryRow;
    /** @var int|string|null the synthetic id buildTarget() assigned the new row */
    private $newRowId;
    /** @var string|null */
    private $newSlotRef;

    public function __construct(PDO $pdo, string $configUuid, string $componentType, string $oldComponentUuid, ?string $oldSerialNumber, string $newComponentUuid, array $options = [], $actor = 0, ?int $expectedRevision = null, ?int $oldInventoryId = null)
    {
        parent::__construct($pdo, $configUuid, $actor, $expectedRevision);
        $this->componentType = $componentType;
        $this->oldComponentUuid = $oldComponentUuid;
        $this->oldSerialNumber = $oldSerialNumber;
        $this->newComponentUuid = $newComponentUuid;
        $this->options = $options;
        $this->oldInventoryId = $oldInventoryId;
    }

    protected function trigger(): string
    {
        return Trigger::REPLACE;
    }

    protected function buildTarget(TargetState $current, array $lockedRow): TargetState
    {
        $candidates = $current->byType($this->componentType);

        // WHICH unit is coming out. spec_uuid names a MODEL, so on a build with
        // four identical DIMMs and no serial numbers it names all four equally,
        // and the first match wins arbitrarily. inventory_id is the only thing
        // that separates them, so it is preferred where the caller supplied it
        // AND the state actually carries ids.
        //
        // THAT SECOND CONDITION IS NOT DEFENSIVE PADDING. TargetStateBuilder's
        // JSON-fallback path sets inventory_id to NULL on every row (the legacy
        // columns have nowhere to keep it), so a config still being read from
        // JSON would match nothing and a replace that works today would start
        // failing 404. Falling back to the model+serial match there keeps that
        // case byte-identical to its previous behaviour; the id only ever makes
        // the choice MORE precise, never less possible.
        $byId = null;
        if ($this->oldInventoryId !== null) {
            $stateHasIds = false;
            foreach ($candidates as $row) {
                if (($row['inventory_id'] ?? null) !== null) {
                    $stateHasIds = true;
                    break;
                }
            }
            if ($stateHasIds) {
                $byId = $this->oldInventoryId;
            }
        }

        $this->oldRow = null;
        foreach ($candidates as $row) {
            // The model is checked in BOTH modes. An inventory_id paired with the
            // wrong old_component_uuid is a malformed request, and honouring the
            // id alone would quietly remove a part nobody named.
            if ($row['spec_uuid'] !== $this->oldComponentUuid) {
                continue;
            }
            if ($byId !== null) {
                if ((int)($row['inventory_id'] ?? 0) !== $byId) {
                    continue;
                }
            } elseif ($this->oldSerialNumber !== null && $row['serial_number'] !== $this->oldSerialNumber) {
                continue;
            }
            $this->oldRow = $row;
            break;
        }
        if ($this->oldRow === null) {
            if ($byId !== null) {
                $which = " (inventory row #{$byId})";
            } else {
                $which = $this->oldSerialNumber ? " with SerialNumber '{$this->oldSerialNumber}'" : '';
            }
            throw new CommandFailed('component_not_found', "Component to replace not found in configuration$which", 404);
        }

        $this->newInventoryRow = $this->lockAndCheckComponent();
        if ($this->newInventoryRow === null) {
            // 'inventory_component_not_found' — the REPLACEMENT has no unit in
            // stock, which a request can fix by raising an
            // inventory.component.add prerequisite. The miss above (the part
            // being taken OUT is not in this configuration) keeps
            // 'component_not_found': no amount of stock makes that true.
            throw new CommandFailed('inventory_component_not_found', "Replacement component {$this->newComponentUuid} not found in inventory", 404);
        }
        // Finding A (verify record 2026-07-12): legacy's post-lock availability
        // gate + override protocol, ported into BaseCommand. The U-A.2
        // quantity>1 add loop inherits this via AddComponentCommand.
        $this->assertInventoryAvailability($this->newInventoryRow['data'], $lockedRow, $this->options);

        $withoutOld = TargetStateBuilder::withRemove($current, $this->oldRow['id'], false);

        $this->newSlotRef = null;
        if (in_array($this->componentType, ['nic', 'pciecard', 'hbacard'], true)
            && strpos($this->newComponentUuid, 'onboard-') !== 0
        ) {
            $this->newSlotRef = $this->planSlot($withoutOld);
        }

        $parentId = null;
        if ($this->componentType === 'sfp' && !empty($this->options['parent_nic_uuid'])) {
            foreach ($withoutOld->byType('nic') as $nic) {
                if ($nic['spec_uuid'] === $this->options['parent_nic_uuid']) {
                    $parentId = $nic['id'];
                    break;
                }
            }
        } elseif ($this->oldRow['parent_id'] !== null) {
            // preserve the old row's own parent link by default (e.g. replacing
            // an sfp itself keeps it under the same nic) unless the caller gave
            // an explicit new parent above.
            $parentId = $withoutOld->find($this->oldRow['parent_id']) !== null ? $this->oldRow['parent_id'] : null;
        }

        $newRow = [
            'component_type' => $this->componentType,
            'spec_uuid' => $this->newComponentUuid,
            'inventory_table' => $this->newInventoryRow['table'],
            'inventory_id' => (int)$this->newInventoryRow['data']['ID'],
            'serial_number' => $this->newInventoryRow['data']['SerialNumber'] ?? null,
            'parent_id' => $parentId,
            'slot_ref' => $this->newSlotRef,
        ];
        $replaced = TargetStateBuilder::withAdd($withoutOld, $newRow);

        $replacedComponents = $replaced->components();
        $addedRow = end($replacedComponents);
        $this->newRowId = $addedRow['id'];

        // Re-anchor: any live row whose parent_id pointed at the OLD row now
        // points at the NEW row instead of going dangling (which would
        // otherwise trip dependency.blocked_removal, U-R.8, for every replace
        // that has children -- exactly the case this unit exists to handle).
        $reanchored = array_map(function ($c) {
            if ($c['parent_id'] === $this->oldRow['id']) {
                $c['parent_id'] = $this->newRowId;
            }
            return $c;
        }, $replaced->components());

        return new TargetState($reanchored);
    }

    protected function apply(PDO $pdo, TargetState $target): void
    {
        $repo = new ConfigComponentRepository($pdo);
        $sb = new ServerBuilder($pdo);

        $oldSerial = $this->oldRow['serial_number'];
        $newInventoryData = $this->newInventoryRow['data'];
        $newSerial = $newInventoryData['SerialNumber'] ?? null;

        if (is_int($this->oldRow['id']) && $this->oldRow['id'] > 0) {
            $repo->tombstone($this->oldRow['id'], $this->actor);
        } else {
            $repo->bumpRevision($this->configUuid, 'remove', ['component_type' => $this->componentType], $this->actor);
        }

        $newRowId = $repo->insert($this->configUuid, [
            'component_type' => $this->componentType,
            'inventory_table' => $this->newInventoryRow['table'],
            'inventory_id' => (int)$newInventoryData['ID'],
            'spec_uuid' => $this->newComponentUuid,
            'serial_number' => $newSerial,
            'parent_id' => null, // re-anchor pass below resolves real DB parent_ids after the new row exists
            'slot_ref' => $this->newSlotRef,
        ], $this->actor);

        // Re-anchor children in config_components: any live row whose
        // parent_id was the OLD row's id now points at the NEW row's real id.
        if (is_int($this->oldRow['id']) && $this->oldRow['id'] > 0) {
            $stmt = $pdo->prepare('UPDATE config_components SET parent_id = ? WHERE parent_id = ? AND removed_at IS NULL');
            $stmt->execute([$newRowId, $this->oldRow['id']]);
        }

        // Both sides are identified by inventory row ID -- the outgoing unit from its
        // config_components row, the incoming one from the row this command locked.
        // Serial alone cannot address serial-less stock (SerialNumber NULL). The JSON
        // writes below need the same identity for the same reason: without it they
        // match on the model UUID and can hit the wrong unit's entry.
        $oldUnitId = isset($this->oldRow['inventory_id']) && $this->oldRow['inventory_id'] !== null
            ? (int)$this->oldRow['inventory_id']
            : null;

        $legacyOptions = $this->options;
        if ($this->newSlotRef !== null) {
            $legacyOptions['slot_position'] = $this->newSlotRef;
        }
        $legacyOptions['inventory_id'] = (int)$newInventoryData['ID'];
        $sb->updateServerConfigurationTable($this->configUuid, $this->componentType, $this->oldComponentUuid, 1, 'remove', $oldSerial, ['inventory_id' => $oldUnitId]);
        $sb->updateServerConfigurationTable($this->configUuid, $this->componentType, $this->newComponentUuid, 1, 'add', $newSerial, $legacyOptions);
        $sb->updateComponentStatusAndServerUuid($this->componentType, $this->oldComponentUuid, 1, null, 'Replaced via command layer (U-C.4)', null, null, $oldSerial, $oldUnitId);
        $sb->updateComponentStatusAndServerUuid($this->componentType, $this->newComponentUuid, 2, $this->configUuid, 'Replaced via command layer (U-C.4)', null, null, $newSerial, (int)$newInventoryData['ID']);

        // ROOT-CAUSE FIX (2026-08-26): both calls above pass null, null for
        // $serverLocation / $serverRackPosition, which the setter writes
        // unconditionally onto the unit going IN -- erasing its address rather
        // than stamping it, and never writing location_uuid. syncConfig()
        // re-derives the whole config's address from its real placement, in this
        // command's open transaction. The unit coming OUT keeps its Location and
        // loses its RackPosition, which is already correct for loose stock.
        LocationResolver::syncConfig($this->pdo, $this->configUuid);

        $sb->recalculateFormFactorLock($this->configUuid);

        // Swapping to a taller chassis has to grow the rack placement (or refuse) —
        // same rule the legacy add path applies.
        if ($this->componentType === 'chassis') {
            require_once __DIR__ . '/../rack/RackPlacement.php';
            $placementSync = RackPlacement::syncHeightFromChassis($pdo, $this->configUuid);
            if (!$placementSync['success']) {
                throw new CommandFailed('rack_placement_conflict', $placementSync['message'], 409);
            }
        }
    }

    /** Own copy of the inventory lock helper (matches AddComponentCommand's own, per-unit — commands must not share state via ServerBuilder). */
    private function lockAndCheckComponent(): ?array
    {
        $sb = new ServerBuilder($this->pdo);
        if (!$sb->isValidComponentType($this->componentType)) {
            throw new CommandFailed('invalid_component_type', "Invalid component type: {$this->componentType}", 400);
        }
        $table = $sb->getComponentInventoryTable($this->componentType);

        // BUGFIX (A-L1, matching ServerBuilder::lockAndCheckComponent()): `ORDER BY
        // Status ASC` put FAILED units (Status=0) first, so a replacement picked a
        // defective unit ahead of an available one. LIMIT 1 bounds the lock scope.
        // LOCATION PREFERENCE (2026-08-26): see AddComponentCommand's own copy.
        // Null when the location columns or the server's location are unknown,
        // and the SQL is then byte-identical to what it was.
        $preferLocation = LocationResolver::preferredUnitLocation($this->pdo, $table, $this->configUuid);
        $locationOrder  = $preferLocation !== null ? '(location_uuid = ?) DESC, ' : '';
        $params         = $preferLocation !== null
            ? [$this->newComponentUuid, $preferLocation]
            : [$this->newComponentUuid];

        $stmt = $this->pdo->prepare("
            SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition
            FROM `$table` WHERE UUID = ?
            ORDER BY (Status = 1) DESC, (Status = 2) DESC, {$locationOrder}ID ASC
            LIMIT 1 FOR UPDATE
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['table' => $table, 'data' => $row] : null;
    }

    private function planSlot(TargetState $withoutOld): ?string
    {
        $dataUtils = new DataExtractionUtilities();
        switch ($this->componentType) {
            case 'nic':
                $spec = $dataUtils->getNICByUUID($this->newComponentUuid);
                break;
            case 'hbacard':
                $spec = $dataUtils->getHBACardByUUID($this->newComponentUuid);
                break;
            case 'pciecard':
                $spec = $dataUtils->getPCIeCardByUUID($this->newComponentUuid);
                break;
            case 'risercard':
                $spec = $dataUtils->getRiserCardByUUID($this->newComponentUuid);
                break;
            default:
                return null;
        }
        if (!is_array($spec)) {
            return null;
        }

        // Type is the riser test since the 2026-08-14 split; the subtype test stays
        // as a fallback for any pciecard row still labelled 'Riser Card'.
        $isRiser = $this->componentType === 'risercard'
            || ($spec['component_subtype'] ?? null) === 'Riser Card';
        $resource = $isRiser ? 'riser_slot' : 'pcie_slot';
        $width = SlotPlanner::extractCardWidth($spec);

        // Slot inheritance: the old row's slot_ref is free again in $withoutOld
        // (old was already removed) -- prefer it if the new card's width still
        // fits that exact slot, matching the pack's "new component takes old's
        // slot_ref when SlotPlanner validates width" instruction.
        if ($this->oldRow['slot_ref'] !== null) {
            $plan = SlotPlanner::plan($withoutOld, $resource, $width, $this->oldRow['slot_ref']);
            if ($plan['ok']) {
                return $plan['slot_ref'];
            }
        }

        $plan = SlotPlanner::plan($withoutOld, $resource, $width, null);
        return $plan['ok'] ? $plan['slot_ref'] : null;
    }
}
