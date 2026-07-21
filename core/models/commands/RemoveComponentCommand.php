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
 * Unit release is FAIL-CLOSED (port of legacy removeComponent()'s 2026-07-21
 * hardening): when a row carries no serial (motherboard/chassis/hbacard live
 * in scalar UUID columns that never stored one), the serial is resolved from
 * the inventory row whose ServerUUID binds it to this config — the
 * authoritative record of which physical unit is installed here. Zero bound
 * units is a stale config entry (F-1 class): nothing to release, the removal
 * is exactly the cleanup needed, so it proceeds. A release that still fails
 * (updateComponentStatusAndServerUuid() returns false, e.g. its ambiguity
 * refusal) throws CommandFailed so the whole command rolls back — leaving the
 * component in the config is recoverable, silently leaking a physical unit
 * (tombstoned config row, inventory still Status=2 with a stale ServerUUID)
 * is not. That leak was observed live 2026-07-21 (motherboardinventory #45)
 * on the legacy path; this keeps the command layer from reintroducing it.
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

    /**
     * Rows removed BECAUSE of the cascade (the parent_id/resource closure),
     * excluding the target row itself. Valid after execute()/dryRun() has run
     * buildTarget(); empty when cascade=false. Lets the API adapter tell the
     * caller what else the cascade took — the target alone is otherwise the
     * only thing the response names.
     *
     * @return array[] each ['component_type'=>…, 'spec_uuid'=>…, 'serial_number'=>…]
     */
    public function cascadeRemovedRows(): array
    {
        return array_map(function ($row) {
            return [
                'component_type' => $row['component_type'],
                'spec_uuid' => $row['spec_uuid'],
                'serial_number' => $row['serial_number'],
            ];
        }, $this->cascadeRows);
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
                $serial = $row['serial_number'];
                $releasable = true;
                if ($serial === null) {
                    // Serial-less row (scalar-column types): resolve the physical
                    // unit from its ServerUUID binding — see class docblock.
                    $unitStmt = $pdo->prepare(
                        "SELECT SerialNumber FROM `{$row['inventory_table']}` WHERE UUID = ? AND ServerUUID = ?"
                    );
                    $unitStmt->execute([$row['spec_uuid'], $this->configUuid]);
                    $boundUnits = $unitStmt->fetchAll(PDO::FETCH_COLUMN);
                    if (count($boundUnits) === 1) {
                        $serial = $boundUnits[0];
                    } elseif (count($boundUnits) === 0) {
                        // Stale config entry — no unit bound, nothing to release.
                        $releasable = false;
                        error_log(
                            "Removed {$row['component_type']} {$row['spec_uuid']} from config {$this->configUuid} "
                            . "with no inventory row bound to it (stale config entry — nothing to release)"
                        );
                    }
                    // >1 bound units with no serial: leave $serial null so the
                    // release below hits the ambiguity refusal and this command
                    // fails closed instead of guessing a unit.
                }

                if ($releasable) {
                    $released = $sb->updateComponentStatusAndServerUuid(
                        $row['component_type'], $row['spec_uuid'], 1, null, 'Removed via command layer (U-C.3)', null, null, $serial
                    );
                    if (!$released) {
                        throw new CommandFailed(
                            'unit_release_failed',
                            "Could not identify which physical {$row['component_type']} unit to release from this server. "
                            . 'Nothing was removed.',
                            409
                        );
                    }
                }
            }
        }

        $sb->recalculateFormFactorLock($this->configUuid);
    }
}
