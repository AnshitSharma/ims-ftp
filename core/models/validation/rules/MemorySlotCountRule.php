<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';

/**
 * RULE_MAP.md: memory.slot_count (E). Legacy had THREE implementations
 * (ComponentValidator::validateMemorySlotAvailability:938, a per-call-count
 * check in ServerBuilder:7935, and MemoryAuthority) — unified into one,
 * row-count based (D4 in RULE_MAP). Capacity comes from
 * ResourceCatalog's motherboard dimm_slot provider (added this unit — see
 * ResourceCatalog::motherboardDimmSlotRows()).
 */
final class MemorySlotCountRule implements RuleInterface
{
    public function id(): string
    {
        return 'memory.slot_count';
    }

    public function severity(): string
    {
        return Severity::ERROR;
    }

    public function triggers(): array
    {
        return [Trigger::ADD, Trigger::REPLACE, Trigger::VALIDATE];
    }

    public function scope(): string
    {
        return self::SCOPE_RESOURCE;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        if (empty($state->byType('motherboard'))) {
            return new RuleResult($this->id(), $this->severity(), true, 'No motherboard -- slot count check does not apply');
        }

        $count = count($state->byType('ram'));
        $capacity = 0;
        foreach ($state->byResource('dimm_slot') as $row) {
            $capacity += (int)$row['capacity'];
        }

        if ($count > $capacity) {
            return new RuleResult($this->id(), $this->severity(), false,
                "Memory slot limit reached ($count/$capacity)",
                ['count' => $count, 'capacity' => $capacity]);
        }

        return new RuleResult($this->id(), $this->severity(), true, "RAM count $count within slot capacity $capacity");
    }
}
