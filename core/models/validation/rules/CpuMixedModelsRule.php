<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../../shared/DataNormalizationUtils.php';

/**
 * RULE_MAP.md: cpu.mixed_models (W). Legacy:
 * ComponentValidator::validateMixedCPUCompatibility (line 377) — ORPHANED,
 * never called from anywhere in the legacy add/validate paths. Registering
 * it here means it fires for the first time; RULE_MAP flags this as an
 * EXPECTED new-firing diff class, not a regression (cite in
 * expected_diffs.json: any config with mismatched CPU sockets that legacy
 * silently allowed will now surface a WARNING under VALIDATE).
 */
final class CpuMixedModelsRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'cpu.mixed_models';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function triggers(): array
    {
        return [Trigger::VALIDATE];
    }

    public function scope(): string
    {
        return self::SCOPE_PAIR;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        $cpus = $state->byType('cpu');
        $sockets = [];
        foreach ($cpus as $cpu) {
            $spec = $this->dataUtils->getCPUByUUID($cpu['spec_uuid']);
            $socket = is_array($spec) ? ($spec['socket'] ?? null) : null;
            if ($socket) {
                $sockets[] = DataNormalizationUtils::normalizeSocketType($socket);
            }
        }

        $distinct = array_unique($sockets);
        if (count($distinct) > 1) {
            return new RuleResult($this->id(), $this->severity(), false,
                'Mixed CPU socket types not allowed. Sockets found: ' . implode(', ', $distinct),
                ['sockets' => array_values($distinct)]);
        }

        return new RuleResult($this->id(), $this->severity(), true, 'No mixed CPU socket types');
    }
}
