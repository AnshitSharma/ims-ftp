<?php

require_once __DIR__ . '/BaseCommand.php';
require_once __DIR__ . '/../config/ConfigComponentRepository.php';
require_once __DIR__ . '/../validation/SlotPlanner.php';
require_once __DIR__ . '/../validation/Trigger.php';
require_once __DIR__ . '/../server/ServerBuilder.php';
require_once __DIR__ . '/../shared/DataExtractionUtilities.php';

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

    public function __construct(PDO $pdo, string $configUuid, string $componentType, string $oldComponentUuid, ?string $oldSerialNumber, string $newComponentUuid, array $options = [], $actor = 0, ?int $expectedRevision = null)
    {
        parent::__construct($pdo, $configUuid, $actor, $expectedRevision);
        $this->componentType = $componentType;
        $this->oldComponentUuid = $oldComponentUuid;
        $this->oldSerialNumber = $oldSerialNumber;
        $this->newComponentUuid = $newComponentUuid;
        $this->options = $options;
    }

    protected function trigger(): string
    {
        return Trigger::REPLACE;
    }

    protected function buildTarget(TargetState $current, array $lockedRow): TargetState
    {
        $this->oldRow = null;
        foreach ($current->byType($this->componentType) as $row) {
            if ($row['spec_uuid'] !== $this->oldComponentUuid) {
                continue;
            }
            if ($this->oldSerialNumber !== null && $row['serial_number'] !== $this->oldSerialNumber) {
                continue;
            }
            $this->oldRow = $row;
            break;
        }
        if ($this->oldRow === null) {
            $serialInfo = $this->oldSerialNumber ? " with SerialNumber '{$this->oldSerialNumber}'" : '';
            throw new CommandFailed('component_not_found', "Component to replace not found in configuration$serialInfo", 404);
        }

        $this->newInventoryRow = $this->lockAndCheckComponent();
        if ($this->newInventoryRow === null) {
            throw new CommandFailed('component_not_found', "Replacement component {$this->newComponentUuid} not found in inventory", 404);
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

        $legacyOptions = $this->options;
        if ($this->newSlotRef !== null) {
            $legacyOptions['slot_position'] = $this->newSlotRef;
        }
        $sb->updateServerConfigurationTable($this->configUuid, $this->componentType, $this->oldComponentUuid, 1, 'remove', $oldSerial);
        $sb->updateServerConfigurationTable($this->configUuid, $this->componentType, $this->newComponentUuid, 1, 'add', $newSerial, $legacyOptions);

        $sb->updateComponentStatusAndServerUuid($this->componentType, $this->oldComponentUuid, 1, null, 'Replaced via command layer (U-C.4)', null, null, $oldSerial);
        $sb->updateComponentStatusAndServerUuid($this->componentType, $this->newComponentUuid, 2, $this->configUuid, 'Replaced via command layer (U-C.4)', null, null, $newSerial);

        $sb->recalculateFormFactorLock($this->configUuid);
    }

    /** Own copy of the inventory lock helper (matches AddComponentCommand's own, per-unit — commands must not share state via ServerBuilder). */
    private function lockAndCheckComponent(): ?array
    {
        $sb = new ServerBuilder($this->pdo);
        if (!$sb->isValidComponentType($this->componentType)) {
            throw new CommandFailed('invalid_component_type', "Invalid component type: {$this->componentType}", 400);
        }
        $table = $sb->getComponentInventoryTable($this->componentType);

        $stmt = $this->pdo->prepare("
            SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition
            FROM `$table` WHERE UUID = ? ORDER BY Status ASC FOR UPDATE
        ");
        $stmt->execute([$this->newComponentUuid]);
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
            default:
                return null;
        }
        if (!is_array($spec)) {
            return null;
        }

        $isRiser = ($spec['component_subtype'] ?? null) === 'Riser Card';
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
