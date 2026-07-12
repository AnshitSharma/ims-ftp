<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';

/**
 * RULE_MAP.md: system.required_set (VF). Legacy had THREE divergent
 * required-component lists (audit V-3): validateConfiguration() (~3282:
 * cpu/motherboard/ram, storage merely "recommended"), validateRequiredComponents()
 * (~6760: chassis/motherboard/cpu/ram/storage/nic — the comprehensive-validator
 * list), getConfigurationWarnings() (its own informal set). Per RULE_MAP's
 * "one list = comprehensive's (chassis..nic)": this rule ports
 * validateRequiredComponents()'s six-type list verbatim and retires the other
 * two as this rule's sole owner (INV-2).
 *
 * Severity is VALIDATION_FAILURE, not ERROR: a draft config missing e.g. nic
 * should block VALIDATE/FINALIZE (the "is this deployable" gate) but not an
 * ADD of some other component to that same draft — same reasoning as
 * cpu.requires_board (A-12).
 */
final class SystemRequiredSetRule implements RuleInterface
{
    /** The comprehensive validator's six required types, in that method's order. */
    const REQUIRED_TYPES = ['chassis', 'motherboard', 'cpu', 'ram', 'storage', 'nic'];

    public function id(): string
    {
        return 'system.required_set';
    }

    public function severity(): string
    {
        return Severity::VALIDATION_FAILURE;
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
        $missing = [];
        foreach (self::REQUIRED_TYPES as $type) {
            if (empty($state->byType($type))) {
                $missing[] = $type;
            }
        }

        if (!empty($missing)) {
            return new RuleResult($this->id(), $this->severity(), false,
                'Missing required component(s): ' . implode(', ', $missing),
                ['missing' => $missing]);
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All required components present');
    }
}
