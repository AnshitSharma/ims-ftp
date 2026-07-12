<?php

require_once __DIR__ . '/BaseCommand.php';
require_once __DIR__ . '/../config/ConfigComponentRepository.php';
require_once __DIR__ . '/../validation/Trigger.php';
require_once __DIR__ . '/../server/ServerBuilder.php';

/**
 * RemoveComponentCommand — the command-layer strangler over
 * ServerBuilder::removeComponent() (was 986-1094+), with genuine cascade
 * support DependencyBlockedRemovalRule (U-R.8) now enforces uniformly
 * instead of legacy's one hard-coded nic->sfp special case.
 *
 * buildTarget() finds the live row (by type+spec_uuid, narrowed by serial
 * when given) and calls TargetStateBuilder::withRemove($current, $id, $cascade).
 * Without cascade, dependency.blocked_removal (U-R.8) blocks via its
 * dangling-parent_id / structural-orphan mechanisms exactly as documented in
 * that rule's own docblock. With cascade, the builder already removed the
 * parent_id subtree before evaluate() runs, so the rule passes for that
 * mechanism (by design) and every OTHER rule evaluates the post-cascade
 * state, per the pack: "the CASCADED state is what all other rules evaluate."
 *
 * apply() tombstones the target row (+ its full parent_id/resource closure
 * when cascade=true, computed via TargetStateBuilder::dependentsOf() against
 * the PRE-removal state so the closure sees the rows that are about to
 * disappear), mirrors each removed row to the legacy JSON columns via
 * ServerBuilder::updateServerConfigurationTable('remove') as a library call,
 * transitions each removed unit's inventory installed(2)->available(1) via
 * updateComponentStatusAndServerUuid(), then recalculates the chassis/storage
 * form-factor lock once for the whole operation.
 *
 * Storage-path recompute: NONE needed here (per the pack) -- storage
 * connection paths are derived post-U-R.5 from resource/consumer data, not
 * stored state this command would need to invalidate.
 */
final class RemoveComponentCommand extends BaseCommand
{
    /** @var string */
    private $componentType;
    /** @var string */
    private $componentUuid;
    /** @var string|null */
    private $serialNumber;
    /** @var bool */
    private $cascade;
    /** @var array|null the live row being removed, resolved by buildTarget() */
    private $targetRow;
    /** @var array[] pre-removal dependents (cascade subtree), resolved by buildTarget() */
    private $cascadeRows = [];

    public function __construct(PDO $pdo, string $configUuid, string $componentType, string $componentUuid, ?string $serialNumber = null, bool $cascade = false, $actor = 0, ?int $expectedRevision = null)
    {
        parent::__construct($pdo, $configUuid, $actor, $expectedRevision);
        $this->componentType = $componentType;
        $this->componentUuid = $componentUuid;
        $this->serialNumber = $serialNumber;
        $this->cascade = $cascade;
    }

    protected function trigger(): string
    {
        return Trigger::REMOVE;
    }

    protected function buildTarget(TargetState $current, array $lockedRow): TargetState
    {
        $this->targetRow = null;
        foreach ($current->byType($this->componentType) as $row) {
            if ($row['spec_uuid'] !== $this->componentUuid) {
                continue;
            }
            if ($this->serialNumber !== null && $row['serial_number'] !== $this->serialNumber) {
                continue;
            }
            $this->targetRow = $row;
            break;
        }
        if ($this->targetRow === null) {
            $serialInfo = $this->serialNumber ? " with SerialNumber '{$this->serialNumber}'" : '';
            throw new CommandFailed('component_not_found', "Component not found in configuration$serialInfo", 404);
        }

        $this->cascadeRows = $this->cascade
            ? TargetStateBuilder::dependentsOf($current, $this->targetRow['id'])
            : [];

        return TargetStateBuilder::withRemove($current, $this->targetRow['id'], $this->cascade);
    }

    protected function apply(PDO $pdo, TargetState $target): void
    {
        $repo = new ConfigComponentRepository($pdo);
        $sb = new ServerBuilder($pdo);

        $rowsToRemove = array_merge([$this->targetRow], $this->cascadeRows);
        foreach ($rowsToRemove as $row) {
            if (is_int($row['id']) && $row['id'] > 0) {
                // only rows-path rows (positive real ids) have a config_components
                // row to tombstone -- json-fallback rows use negative synthetic ids
                // (TargetStateBuilder's own convention) and exist only in the
                // legacy JSON columns, which the library call below still updates.
                // tombstone() bumps revision+event internally (INV-6).
                $repo->tombstone($row['id'], $this->actor);
            } else {
                // No rows-path row to tombstone (pre-backfill / json-fallback-only
                // config) -- still bump revision+event ourselves so this mutation
                // is never invisible to INV-6's mechanical check.
                $repo->bumpRevision($this->configUuid, 'remove', [
                    'component_type' => $row['component_type'],
                ], $this->actor);
            }

            $sb->updateServerConfigurationTable(
                $this->configUuid, $row['component_type'], $row['spec_uuid'], 1, 'remove', $row['serial_number']
            );

            if ($row['inventory_table'] !== null) {
                $sb->updateComponentStatusAndServerUuid(
                    $row['component_type'], $row['spec_uuid'], 1, null, 'Removed via command layer (U-C.3)', null, null, $row['serial_number']
                );
            }
        }

        $sb->recalculateFormFactorLock($this->configUuid);
    }
}
