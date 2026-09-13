<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';

/**
 * RULE_MAP.md: system.inventory_state (E). Legacy audit V-2:
 * ServerBuilder::validateConfiguration() (~3329) appends a "marked as
 * failed/defective" issue to $validation['issues'] on Status===0 WITHOUT
 * setting is_valid=false — a non-blocking issue on the finalize gate. This
 * rule closes V-2: any live component whose {inventory_table}.status_v2 is
 * failed/retired/maintenance blocks VALIDATE/FINALIZE.
 *
 * Reads TargetState's 'status_v2' field (added this unit — see
 * TargetStateBuilder's class docblock).
 *
 * status_v2 === null used to mean one thing to this rule — "unknown, cannot
 * judge" — and pass. It is actually two things, and only one of them is
 * unknowable. [M-13, 2026-09-13] A row with no inventory_table/inventory_id
 * claims no hardware (virtual build, embedded component) and still passes. A row
 * that names a table and an ID whose status cannot be read is an ORPHAN CLAIM,
 * and it now blocks: the build says a unit is installed and the inventory has no
 * such unit to show for it.
 */
final class SystemInventoryStateRule implements RuleInterface
{
    /** {inventory_table}.status_v2 values that block VALIDATE/FINALIZE. */
    const BLOCKING_STATUSES = ['failed', 'retired', 'maintenance'];

    public function id(): string
    {
        return 'system.inventory_state';
    }

    public function severity(): string
    {
        return Severity::ERROR;
    }

    public function triggers(): array
    {
        return [Trigger::VALIDATE, Trigger::FINALIZE];
    }

    public function scope(): string
    {
        return self::SCOPE_CONFIG;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        $offenders = [];
        foreach ($state->components() as $c) {
            $status = $c['status_v2'] ?? null;

            // "No unit" and "a unit we cannot read" are not the same fact. [M-13]
            //
            // A row with no inventory_table/inventory_id is a row that never
            // claimed hardware — a virtual build's component, or an embedded one
            // that exists as part of its parent. Nothing to verify, so nothing to
            // fail: it passes, as it always did.
            //
            // A row that DOES name a table and an ID and still has no status is a
            // different animal: it says "unit 412 of cpuinventory is installed
            // here" and the inventory has no such row, or has one whose state is
            // unreadable. That is an orphan claim — from a delete that raced the
            // claim check (M-04), a partial migration, or an older inconsistency —
            // and the rule used to hand it the same pass a virtual row gets, so a
            // build resting on hardware that is not there validated as deployable.
            $physical = ($c['inventory_table'] ?? null) !== null && ($c['inventory_id'] ?? null) !== null;
            if ($physical && $status === null) {
                $offenders[] = [
                    'id'             => $c['id'],
                    'component_type' => $c['component_type'],
                    'status_v2'      => null,
                    'reason'         => 'physical inventory state unavailable',
                ];
                continue;
            }

            if ($status !== null && in_array($status, self::BLOCKING_STATUSES, true)) {
                $offenders[] = ['id' => $c['id'], 'component_type' => $c['component_type'], 'status_v2' => $status];
            }
        }

        if (!empty($offenders)) {
            $summary = implode(', ', array_map(function ($o) {
                $what = $o['status_v2'] === null
                    ? 'no readable inventory row'
                    : $o['status_v2'];
                return "{$o['component_type']}#{$o['id']} ({$what})";
            }, $offenders));
            return new RuleResult($this->id(), $this->severity(), false,
                "Configuration contains component(s) in a non-deployable inventory state: $summary",
                ['offenders' => $offenders]);
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All components are in a deployable inventory state');
    }
}
