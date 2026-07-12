<?php

require_once __DIR__ . '/BaseCommand.php';
require_once __DIR__ . '/../config/ConfigComponentRepository.php';
require_once __DIR__ . '/../validation/SlotPlanner.php';
require_once __DIR__ . '/../validation/Trigger.php';
require_once __DIR__ . '/../server/ServerBuilder.php';
require_once __DIR__ . '/../shared/DataExtractionUtilities.php';

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
    /** @var array|null set by buildTarget(), read by apply() */
    private $resolvedInventoryRow;
    /** @var string|null set by buildTarget() when a slot was planned */
    private $plannedSlotRef;

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
        $this->resolvedInventoryRow = $this->lockAndCheckComponent();
        if ($this->resolvedInventoryRow === null) {
            throw new CommandFailed('component_not_found', "Component {$this->componentUuid} not found in inventory", 404);
        }
        // Finding A (verify record 2026-07-12): legacy's post-lock availability
        // gate + override protocol, ported into BaseCommand.
        $this->assertInventoryAvailability($this->resolvedInventoryRow['data'], $lockedRow, $this->options);

        $parentId = null;
        if ($this->componentType === 'sfp' && !empty($this->options['parent_nic_uuid'])) {
            foreach ($current->byType('nic') as $nic) {
                if ($nic['spec_uuid'] === $this->options['parent_nic_uuid']) {
                    $parentId = $nic['id'];
                    break;
                }
            }
        }

        $slotRef = null;
        if (in_array($this->componentType, ['nic', 'pciecard', 'hbacard'], true)
            && strpos($this->componentUuid, 'onboard-') !== 0
        ) {
            $plan = $this->planSlot($current);
            if ($plan['ok']) {
                $slotRef = $plan['slot_ref'];
            }
            // A plan failure (no free slot / unknown width) leaves slot_ref null;
            // PcieSlotPlacementRule (U-R.3) judges that as infeasible and blocks
            // the trigger via the SAME registry evaluate() every rule runs
            // through — this command does not duplicate that judgment.
        }
        $this->plannedSlotRef = $slotRef;

        // no 'id' key: TargetStateBuilder::withAdd() assigns a synthetic id for evaluate()'s own purposes.
        $row = [
            'component_type' => $this->componentType,
            'spec_uuid' => $this->componentUuid,
            'inventory_table' => $this->resolvedInventoryRow['table'],
            'inventory_id' => (int)$this->resolvedInventoryRow['data']['ID'],
            'serial_number' => $this->resolvedInventoryRow['data']['SerialNumber'] ?? null,
            'parent_id' => $parentId,
            'slot_ref' => $slotRef,
        ];

        return TargetStateBuilder::withAdd($current, $row);
    }

    protected function apply(PDO $pdo, TargetState $target): void
    {
        $inventoryData = $this->resolvedInventoryRow['data'];
        $table = $this->resolvedInventoryRow['table'];
        $serialNumber = $inventoryData['SerialNumber'] ?? ($this->options['serial_number'] ?? null);

        $repo = new ConfigComponentRepository($pdo);
        $repo->insert($this->configUuid, [
            'component_type' => $this->componentType,
            'inventory_table' => $table,
            'inventory_id' => (int)$inventoryData['ID'],
            'spec_uuid' => $this->componentUuid,
            'serial_number' => $serialNumber,
            'parent_id' => null, // config_components' parent_id is a row-id FK; re-anchored below once the sfp's own row exists is out of this unit's box (single-insert path only, matches legacy's single-component addComponent())
            'slot_ref' => $this->plannedSlotRef,
        ], $this->actor);

        $sb = new ServerBuilder($pdo);
        $legacyOptions = $this->options;
        if ($this->plannedSlotRef !== null) {
            $legacyOptions['slot_position'] = $this->plannedSlotRef;
        }
        // updateServerConfigurationTable() also materializes onboard NICs for a
        // motherboard add (createOnboardNICsFromMotherboard, called internally) —
        // running here, pre-commit, inside this same transaction closes A-11's
        // first half without this command needing its own OnboardNICHandler call.
        $sb->updateServerConfigurationTable(
            $this->configUuid, $this->componentType, $this->componentUuid, 1, 'add', $serialNumber, $legacyOptions
        );

        $sb->updateComponentStatusAndServerUuid(
            $this->componentType, $this->componentUuid, 2, $this->configUuid, 'Added via command layer (U-C.2)', null, null, $serialNumber
        );
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
        $sb = new ServerBuilder($this->pdo);
        if (!$sb->isValidComponentType($this->componentType)) {
            throw new CommandFailed('invalid_component_type', "Invalid component type: {$this->componentType}", 400);
        }
        $table = $sb->getComponentInventoryTable($this->componentType);

        $serialNumber = $this->options['serial_number'] ?? null;
        if ($serialNumber !== null) {
            $stmt = $this->pdo->prepare("
                SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition
                FROM `$table` WHERE UUID = ? AND SerialNumber = ? FOR UPDATE
            ");
            $stmt->execute([$this->componentUuid, $serialNumber]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition
                FROM `$table` WHERE UUID = ? ORDER BY Status ASC FOR UPDATE
            ");
            $stmt->execute([$this->componentUuid]);
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
            default:
                return ['ok' => false, 'slot_ref' => null, 'error' => 'not a slotted type', 'error_code' => 'not_slotted'];
        }
        if (!is_array($spec)) {
            return ['ok' => false, 'slot_ref' => null, 'error' => 'spec not found', 'error_code' => 'spec_not_found'];
        }

        $isRiser = ($spec['component_subtype'] ?? null) === 'Riser Card';
        $resource = $isRiser ? 'riser_slot' : 'pcie_slot';
        $width = SlotPlanner::extractCardWidth($spec);
        $manual = $this->options['slot_position'] ?? null;

        return SlotPlanner::plan($current, $resource, $width, $manual);
    }
}
