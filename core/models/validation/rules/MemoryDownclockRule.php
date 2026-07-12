<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * RULE_MAP.md: memory.downclock (W). Legacy:
 * ComponentCompatibility::analyzeMemoryFrequency (was lines 1494-1609).
 * Ported the full 4-branch decision (no-MB-no-CPU / CPU-only / full-with-MB,
 * each producing status optimal/limited/suboptimal/error) rather than
 * simplifying it, since RULE_MAP lists no intentional diff for this row and
 * the pack calls out preserving the response-enrichment detail (effective
 * frequency, limiting component) in RuleResult::details() for the future
 * API shim (U-A.3) to surface.
 */
final class MemoryDownclockRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'memory.downclock';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function triggers(): array
    {
        return [Trigger::ADD, Trigger::REPLACE, Trigger::VALIDATE];
    }

    public function scope(): string
    {
        return self::SCOPE_PAIR;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        $motherboards = $state->byType('motherboard');
        $cpus = $state->byType('cpu');

        $cpuMaxFrequency = null;
        $limitingCpu = null;
        foreach ($cpus as $cpu) {
            $cpuSpec = $this->dataUtils->getCPUByUUID($cpu['spec_uuid']);
            $cpuMemoryTypes = is_array($cpuSpec) ? ($cpuSpec['compatibility']['memory_types'] ?? []) : [];
            foreach ((array)$cpuMemoryTypes as $memType) {
                if (preg_match('/DDR\d+-(\d+)/', (string)$memType, $m)) {
                    $freq = (int)$m[1];
                    if ($cpuMaxFrequency === null || $freq < $cpuMaxFrequency) {
                        $cpuMaxFrequency = $freq;
                        $limitingCpu = $cpuSpec['basic_info']['model'] ?? 'Unknown CPU';
                    }
                }
            }
        }

        foreach ($state->byType('ram') as $ram) {
            $ramSpec = $this->dataUtils->getRAMByUUID($ram['spec_uuid']);
            $ramFrequency = is_array($ramSpec) ? (int)($ramSpec['frequency_MHz'] ?? 3200) : 3200;

            $analysis = $this->analyze($ramFrequency, $motherboards, $cpuMaxFrequency, $limitingCpu);

            if ($analysis['status'] !== 'optimal') {
                return new RuleResult($this->id(), $this->severity(), false, $analysis['message'],
                    ['ram_id' => $ram['id']] + $analysis);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'No memory downclock');
    }

    private function analyze(int $ramFrequency, array $motherboards, ?int $cpuMaxFrequency, ?string $limitingCpu): array
    {
        if (empty($motherboards) && $cpuMaxFrequency !== null) {
            if ($ramFrequency <= $cpuMaxFrequency) {
                return ['status' => 'optimal', 'ram_frequency' => $ramFrequency, 'system_max_frequency' => $cpuMaxFrequency,
                    'effective_frequency' => $ramFrequency, 'limiting_component' => $limitingCpu,
                    'message' => "RAM will operate at full rated speed of {$ramFrequency}MHz with CPU"];
            }
            return ['status' => 'limited', 'ram_frequency' => $ramFrequency, 'system_max_frequency' => $cpuMaxFrequency,
                'effective_frequency' => $cpuMaxFrequency, 'limiting_component' => $limitingCpu,
                'message' => "RAM will operate at {$cpuMaxFrequency}MHz (limited by CPU) instead of rated {$ramFrequency}MHz"];
        }

        if (empty($motherboards) && $cpuMaxFrequency === null) {
            return ['status' => 'optimal', 'ram_frequency' => $ramFrequency, 'system_max_frequency' => $ramFrequency,
                'effective_frequency' => $ramFrequency, 'limiting_component' => null,
                'message' => "RAM frequency {$ramFrequency}MHz accepted (no constraints)"];
        }

        $mbSpec = $this->dataUtils->getMotherboardByUUID($motherboards[0]['spec_uuid']);
        $motherboardMaxFrequency = is_array($mbSpec) ? (int)($mbSpec['memory']['max_frequency_MHz'] ?? 3200) : 3200;

        $systemMaxFrequency = $motherboardMaxFrequency;
        $limitingComponent = 'motherboard';
        if ($cpuMaxFrequency !== null && $cpuMaxFrequency < $systemMaxFrequency) {
            $systemMaxFrequency = $cpuMaxFrequency;
            $limitingComponent = $limitingCpu;
        }

        if ($ramFrequency <= $systemMaxFrequency) {
            $status = 'optimal';
            $effectiveFrequency = $ramFrequency;
            $message = "RAM will operate at full rated speed of {$ramFrequency}MHz";
        } else {
            $status = 'limited';
            $effectiveFrequency = $systemMaxFrequency;
            $message = "RAM will operate at {$systemMaxFrequency}MHz (limited by $limitingComponent) instead of rated {$ramFrequency}MHz";
        }

        if ($ramFrequency < ($systemMaxFrequency * 0.8)) {
            $status = 'suboptimal';
            $message = "RAM frequency may impact performance - consider higher frequency memory";
        }

        return ['status' => $status, 'ram_frequency' => $ramFrequency, 'system_max_frequency' => $systemMaxFrequency,
            'effective_frequency' => $effectiveFrequency, 'limiting_component' => $limitingComponent, 'message' => $message];
    }
}
