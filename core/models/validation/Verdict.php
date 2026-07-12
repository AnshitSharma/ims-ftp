<?php

/**
 * Aggregate outcome of an Engine::evaluate() call: every RuleResult plus the
 * blocking decision for the trigger the results were computed under.
 *
 * Blocking matrix (RULE_MAP.md legend, U-V.1 pack): a failed ERROR result
 * blocks under every trigger; a failed VALIDATION_FAILURE result blocks only
 * under VALIDATE/FINALIZE (drafts may carry them); WARNING never blocks.
 */
final class Verdict
{
    /** @var RuleResult[] */
    private $results;
    /** @var string one of Trigger::* */
    private $trigger;

    public function __construct(array $results, string $trigger)
    {
        $this->results = $results;
        $this->trigger = $trigger;
    }

    /** @return RuleResult[] */
    public function results(): array
    {
        return $this->results;
    }

    public function trigger(): string
    {
        return $this->trigger;
    }

    public function blocking(): bool
    {
        foreach ($this->results as $result) {
            if ($result->passed()) {
                continue;
            }
            if ($result->severity() === Severity::ERROR) {
                return true;
            }
            if ($result->severity() === Severity::VALIDATION_FAILURE
                && in_array($this->trigger, [Trigger::VALIDATE, Trigger::FINALIZE], true)
            ) {
                return true;
            }
            // WARNING (or a non-blocking VF under a non-VALIDATE/FINALIZE trigger) never blocks.
        }
        return false;
    }

    /** @return RuleResult[] only the failed results (any severity) */
    public function failures(): array
    {
        return array_values(array_filter($this->results, function (RuleResult $r) {
            return !$r->passed();
        }));
    }
}
