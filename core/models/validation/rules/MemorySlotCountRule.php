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
 *
 * CPU-OWNED SLOTS (QA-01/QA-02, 2026-10-10). On a multi-socket board each DIMM bank
 * hangs off one CPU, and a bank whose CPU is not installed is dead: an R630 with
 * one processor uses A1-A12 only. The catalog's memory.slots is the whole-board
 * total, split evenly across sockets on every multi-socket board it holds, so with
 * N of S CPUs installed the usable count is slots * N / S. With no CPU at all the
 * physical total still applies -- a draft can hold memory before its processors.
 *
 * ONLY A RAM ADD OR FINALIZE BLOCKS ON THE PER-CPU LIMIT (2026-10-26). A draft holding
 * 16 DIMMs and no CPU (DL360 Gen9 SGH724SP8J, config f093deb0) could never take its
 * first CPU: 1 of 2 sockets makes 12 usable, so every CPU add failed with 16/12 even
 * though a CPU only revives dead banks. With a subject that is not RAM the add did not
 * cause the excess, so it is a WARNING; a RAM add or a subject-less VALIDATE/FINALIZE
 * still blocks -- the same split StorageInterfacePathRule makes (F-24).
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

        $sockets = 0;
        foreach ($state->byResource('cpu_socket') as $row) {
            $sockets += (int)$row['capacity'];
        }
        $cpus = count($state->byType('cpu'));
        if ($cpus >= 1 && $cpus < $sockets) {
            $usable = intdiv($capacity * $cpus, $sockets);
            if ($count > $usable) {
                // The rule's ERROR: swept across all 80 live builds on 2026-10-10 first,
                // none exceeds its CPUs' slots, so none freezes.
                $subject = $state->subject();
                $severity = ($subject !== null && $subject['component_type'] !== 'ram')
                    ? Severity::WARNING
                    : $this->severity();
                return new RuleResult($this->id(), $severity, false,
                    "Memory slot limit reached ($count/$usable): with $cpus of $sockets CPUs installed, only $usable of the board's $capacity DIMM slots are usable",
                    ['count' => $count, 'capacity' => $usable, 'board_capacity' => $capacity,
                        'cpus' => $cpus, 'sockets' => $sockets,
                        'recommendation' => 'Install another CPU, or remove memory down to ' . $usable . ' modules.']);
            }
            return new RuleResult($this->id(), $this->severity(), true,
                "RAM count $count within the $usable slots usable with $cpus of $sockets CPUs");
        }

        return new RuleResult($this->id(), $this->severity(), true, "RAM count $count within slot capacity $capacity");
    }
}
