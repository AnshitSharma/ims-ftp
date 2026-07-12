<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';

/**
 * RULE_MAP.md: cpu.socket_count (E). Legacy:
 * ServerBuilder::validateCPUAddition, entry-count check (was lines
 * 3915-3940) — counted `cpu_configuration` JSON array entries and compared
 * to `limits['cpu']['max_sockets']`. Intentional diff (A-2): counts
 * TargetState ROWS (one row = one physical unit, always — see
 * TargetStateBuilder), never a per-call count field, closing the bypass
 * where a single add call requesting several units at once could exceed
 * max_sockets undetected.
 *
 * Capacity comes from ResourceCatalog's motherboard cpu_socket provider
 * (added this unit — see ResourceCatalog::motherboardCpuSocketRows()).
 * Passes with no violation when no motherboard is present (defers to
 * CpuRequiresBoardRule, which owns that case).
 */
final class CpuSocketCountRule implements RuleInterface
{
    public function id(): string
    {
        return 'cpu.socket_count';
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
            return new RuleResult($this->id(), $this->severity(), true, 'No motherboard to check against');
        }

        $count = count($state->byType('cpu'));
        $capacity = 0;
        foreach ($state->byResource('cpu_socket') as $row) {
            $capacity += (int)$row['capacity'];
        }

        if ($count > $capacity) {
            return new RuleResult($this->id(), $this->severity(), false,
                "CPU limit exceeded: motherboard supports maximum {$capacity} CPUs, currently has {$count} CPUs",
                ['count' => $count, 'capacity' => $capacity]);
        }

        return new RuleResult($this->id(), $this->severity(), true, "CPU count $count within capacity $capacity");
    }
}
