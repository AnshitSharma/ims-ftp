<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';

/**
 * RULE_MAP.md: cpu.requires_board (VF). Legacy:
 * ServerBuilder::validateCPUAddition (was 3902-3960, now inside
 * legacyValidateComponentAddition per U-V.3's hook rename) hard-blocks any
 * CPU add with no motherboard present ("No motherboard found in
 * configuration - add motherboard first").
 *
 * Intentional diff (A-12, per RULE_MAP): legacy is a hard block (E-equivalent)
 * at every trigger; here it's VALIDATION_FAILURE, so it blocks ADD/VALIDATE
 * but NOT REPLACE (a same-socket CPU swap on an already-invalid draft
 * shouldn't be blocked by a board-less state it didn't create) — closes the
 * "adds allowed in draft" gap the pack calls out.
 */
final class CpuRequiresBoardRule implements RuleInterface
{
    public function id(): string
    {
        return 'cpu.requires_board';
    }

    public function severity(): string
    {
        return Severity::VALIDATION_FAILURE;
    }

    public function triggers(): array
    {
        return [Trigger::ADD, Trigger::VALIDATE];
    }

    public function scope(): string
    {
        return self::SCOPE_CONFIG;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        $cpus = $state->byType('cpu');
        $motherboards = $state->byType('motherboard');

        if (!empty($cpus) && empty($motherboards)) {
            return new RuleResult($this->id(), $this->severity(), false,
                'No motherboard found in configuration - add motherboard first',
                ['cpu_count' => count($cpus)]);
        }

        return new RuleResult($this->id(), $this->severity(), true, 'Motherboard present or no CPUs to check');
    }
}
