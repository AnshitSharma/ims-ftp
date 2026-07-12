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
 * TargetStateBuilder's class docblock). status_v2 === null (json-fallback
 * rows, or a rows-path row whose inventory_table/inventory_id could not be
 * resolved) is treated as "unknown, cannot judge" and passes — never
 * fabricating a bad state legacy itself has no way to see either pre-backfill.
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
            if ($status !== null && in_array($status, self::BLOCKING_STATUSES, true)) {
                $offenders[] = ['id' => $c['id'], 'component_type' => $c['component_type'], 'status_v2' => $status];
            }
        }

        if (!empty($offenders)) {
            $summary = implode(', ', array_map(function ($o) {
                return "{$o['component_type']}#{$o['id']} ({$o['status_v2']})";
            }, $offenders));
            return new RuleResult($this->id(), $this->severity(), false,
                "Configuration contains component(s) in a non-deployable inventory state: $summary",
                ['offenders' => $offenders]);
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All components are in a deployable inventory state');
    }
}
