<?php

/**
 * Immutable outcome of one rule evaluation. No setters; all state is passed
 * to the constructor. See Severity.php for the PHP 7.4-compatibility note
 * (this class emulates `readonly` with private properties + getters instead
 * of PHP 8.1 readonly properties).
 */
final class RuleResult
{
    /** @var string */
    private $ruleId;
    /** @var string one of Severity::* */
    private $severity;
    /** @var bool true = rule satisfied, false = rule fired/failed */
    private $passed;
    /** @var string */
    private $message;
    /** @var array */
    private $details;

    public function __construct(string $ruleId, string $severity, bool $passed, string $message, array $details = [])
    {
        $this->ruleId = $ruleId;
        $this->severity = $severity;
        $this->passed = $passed;
        $this->message = $message;
        $this->details = $details;
    }

    public function ruleId(): string
    {
        return $this->ruleId;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function details(): array
    {
        return $this->details;
    }
}
