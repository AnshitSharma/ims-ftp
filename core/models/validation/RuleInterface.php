<?php

/**
 * One business rule in the new validation engine. Stateless: evaluate()
 * takes a TargetState (U-V.2) and returns a single RuleResult — no PDO, no
 * env reads inside a rule (INV-7: rollout is governed globally by
 * ENGINE_MODE, never re-checked per-rule).
 */
interface RuleInterface
{
    const SCOPE_PAIR = 'PAIR';
    const SCOPE_RESOURCE = 'RESOURCE';
    const SCOPE_CONFIG = 'CONFIG';

    public function id(): string;

    /** @return string one of Severity::* */
    public function severity(): string;

    /** @return string[] Trigger::* values this rule evaluates under */
    public function triggers(): array;

    /** @return string one of self::SCOPE_* */
    public function scope(): string;

    public function evaluate(TargetState $state): RuleResult;
}
