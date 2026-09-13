<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../../shared/DataNormalizationUtils.php';

/**
 * RULE_MAP.md: memory.downclock (W). Legacy:
 * ComponentCompatibility::analyzeMemoryFrequency (was lines 1494-1609).
 * Ported the full 4-branch decision (no-MB-no-CPU / CPU-only / full-with-MB,
 * each producing status optimal/limited/suboptimal/error) rather than
 * simplifying it, since RULE_MAP lists no intentional diff for this row and
 * the pack calls out preserving the response-enrichment detail (effective
 * frequency, limiting component) in RuleResult::details() for the future
 * API shim (U-A.3) to surface.
 *
 * 2026-09-13: three corrections.
 *  - CPU speeds were read from `compatibility.memory_types`, a key no ims-data
 *    CPU record has. Every CPU limit was silently absent. Now read through
 *    DataExtractionUtilities::getCpuMemoryTypes().
 *  - The CPU limit was a single minimum taken across ALL declared types, so a
 *    CPU offering DDR4-3200 and DDR5-4800 capped DDR5 modules at 3200. Limits
 *    are now tracked per memory generation and matched to the module's own.
 *  - `?? 3200` defaults invented a rated speed whenever a spec was unreadable.
 *    Unknown is now reported as unknown; only published numbers constrain.
 *
 * Speeds are MT/s. ims-data labels the DDR field `frequency_MHz` (and carries
 * the correctly named `speed_MTs` alongside it); the stored value is MT/s in
 * both, so `speed_MTs` is preferred and user-facing text says MT/s. Detail
 * keys keep their existing names for API consumers.
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

        // Per-generation CPU speed limits: 'DDR5' => ['speed' => 4800, 'cpu' => 'Platinum 8480+'].
        // Slowest CPU wins within a generation; generations never constrain each other.
        $cpuLimits = [];
        foreach ($cpus as $cpu) {
            $cpuSpec = $this->dataUtils->getCPUByUUID($cpu['spec_uuid']);
            $cpuModel = is_array($cpuSpec) ? ($cpuSpec['model'] ?? 'Unknown CPU') : 'Unknown CPU';
            foreach ($this->dataUtils->getCpuMemoryTypes($cpuSpec) as $memType) {
                if (!preg_match('/DDR\d+-(\d+)/', (string)$memType, $m)) {
                    continue; // generation with no published speed -- cannot constrain
                }
                $generation = DataNormalizationUtils::normalizeMemoryType($memType);
                if ($generation === null) {
                    continue;
                }
                $speed = (int)$m[1];
                if (!isset($cpuLimits[$generation]) || $speed < $cpuLimits[$generation]['speed']) {
                    $cpuLimits[$generation] = ['speed' => $speed, 'cpu' => $cpuModel];
                }
            }
        }

        foreach ($state->byType('ram') as $ram) {
            $ramSpec = $this->dataUtils->getRAMByUUID($ram['spec_uuid']);
            $ramSpeed = $this->readRamSpeed($ramSpec);
            if ($ramSpeed === null) {
                continue; // no published module speed -- nothing to compare against
            }

            $generation = is_array($ramSpec)
                ? DataNormalizationUtils::normalizeMemoryType($ramSpec['memory_type'] ?? null)
                : null;
            $cpuLimit = ($generation !== null && isset($cpuLimits[$generation])) ? $cpuLimits[$generation] : null;

            $analysis = $this->analyze(
                $ramSpeed,
                $motherboards,
                $cpuLimit === null ? null : $cpuLimit['speed'],
                $cpuLimit === null ? null : $cpuLimit['cpu']
            );

            if ($analysis['status'] !== 'optimal') {
                return new RuleResult($this->id(), $this->severity(), false, $analysis['message'],
                    ['ram_id' => $ram['id']] + $analysis);
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'No memory downclock');
    }

    /**
     * Rated module speed in MT/s, or null when the module publishes none.
     * `speed_MTs` is the correctly named field; `frequency_MHz` holds the same
     * MT/s value under a legacy label and is the fallback.
     */
    private function readRamSpeed($ramSpec): ?int
    {
        if (!is_array($ramSpec)) {
            return null;
        }
        $raw = $ramSpec['speed_MTs'] ?? ($ramSpec['frequency_MHz'] ?? null);
        if ($raw === null || $raw === '' || (int)$raw <= 0) {
            return null;
        }
        return (int)$raw;
    }

    private function analyze(int $ramSpeed, array $motherboards, ?int $cpuMaxSpeed, ?string $limitingCpu): array
    {
        $mbMaxSpeed = null;
        if (!empty($motherboards)) {
            $mbSpec = $this->dataUtils->getMotherboardByUUID($motherboards[0]['spec_uuid']);
            $rawMbSpeed = is_array($mbSpec) ? ($mbSpec['memory']['max_frequency_MHz'] ?? null) : null;
            if ($rawMbSpeed !== null && $rawMbSpeed !== '' && (int)$rawMbSpeed > 0) {
                $mbMaxSpeed = (int)$rawMbSpeed;
            }
        }

        // Nothing published to compare against. Say so rather than assuming a
        // speed -- the old `?? 3200` turned missing data into a confident
        // number and produced downclock warnings nobody could trace.
        if ($mbMaxSpeed === null && $cpuMaxSpeed === null) {
            $message = empty($motherboards)
                ? "RAM speed {$ramSpeed}MT/s accepted (no constraints)"
                : "RAM speed {$ramSpeed}MT/s accepted - no rated memory speed published for the installed motherboard or CPU";
            return ['status' => 'optimal', 'ram_frequency' => $ramSpeed, 'system_max_frequency' => null,
                'effective_frequency' => $ramSpeed, 'limiting_component' => null, 'message' => $message];
        }

        $systemMaxSpeed = $mbMaxSpeed;
        $limitingComponent = 'motherboard';
        if ($cpuMaxSpeed !== null && ($systemMaxSpeed === null || $cpuMaxSpeed < $systemMaxSpeed)) {
            $systemMaxSpeed = $cpuMaxSpeed;
            $limitingComponent = $limitingCpu ?? 'CPU';
        }

        if ($ramSpeed <= $systemMaxSpeed) {
            $status = 'optimal';
            $effectiveSpeed = $ramSpeed;
            $message = "RAM will operate at full rated speed of {$ramSpeed}MT/s";
        } else {
            $status = 'limited';
            $effectiveSpeed = $systemMaxSpeed;
            $message = "RAM will operate at {$systemMaxSpeed}MT/s (limited by $limitingComponent) instead of rated {$ramSpeed}MT/s";
        }

        // Legacy parity: the headroom warning only applies once a board is installed.
        if ($mbMaxSpeed !== null && $ramSpeed < ($systemMaxSpeed * 0.8)) {
            $status = 'suboptimal';
            $message = "RAM speed may impact performance - consider faster memory";
        }

        return ['status' => $status, 'ram_frequency' => $ramSpeed, 'system_max_frequency' => $systemMaxSpeed,
            'effective_frequency' => $effectiveSpeed, 'limiting_component' => $limitingComponent, 'message' => $message];
    }
}
