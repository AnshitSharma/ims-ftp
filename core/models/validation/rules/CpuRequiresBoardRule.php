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
 * at every trigger; here it's VALIDATION_FAILURE, so it blocks VALIDATE/FINALIZE
 * but NOT REPLACE (a same-socket CPU swap on an already-invalid draft
 * shouldn't be blocked by a board-less state it didn't create).
 *
 * DOCBLOCK CORRECTED 2026-09-01, no behaviour change. This used to claim it
 * "blocks ADD/VALIDATE". It does not, and never did: Verdict::blocking() counts a
 * VALIDATION_FAILURE only under Trigger::VALIDATE and Trigger::FINALIZE, so listing
 * ADD in triggers() makes the rule EVALUATE on an add but never REFUSE one. A CPU
 * can be added to a board-less config today, and CpuSocketMatchRule then passes
 * vacuously ("No motherboard to check against").
 *
 * That is deliberately left as the behaviour, because it is the right one: build
 * order is the operator's business, and a draft that is temporarily incomplete is
 * not an error -- it is a draft. What was wrong was the comment claiming otherwise,
 * which is how a reader concludes the add path is guarded when it is not. The state
 * IS caught, at the moment it matters: validate and finalize both refuse it.
 * ADD stays in triggers() so the finding still surfaces in the add response as a
 * non-blocking failure, telling the operator what is still missing.
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
