<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../../compatibility/CpuIdentityMatcher.php';

/**
 * RULE_MAP.md: cpu.mixed_models. Legacy:
 * ComponentValidator::validateMixedCPUCompatibility (line 377) — ORPHANED,
 * never called from anywhere in the legacy add/validate paths. Registering
 * it here means it fires for the first time; RULE_MAP flags this as an
 * EXPECTED new-firing diff class, not a regression.
 *
 * 2026-08-14: this rule used to compare socket types only, which passed any two
 * CPUs sharing a socket — Xeon Gold 6338 and 6342 are both LGA4189 Ice Lake-SP and
 * cannot actually run together. Socket agreement is already covered by
 * cpu.socket_match against the board; what this rule owns is CPU-to-CPU pairing.
 * It now delegates to CpuIdentityMatcher, the same authority the live legacy path
 * (ServerBuilder::validateCPUAddition) calls, so the two engines cannot disagree
 * about what "pairable" means.
 *
 * Two severities are emitted from one rule (RuleResult carries its own severity,
 * Verdict::blocking() reads that rather than severity()):
 *   MISMATCH -> ERROR   (blocks under every trigger, matching the legacy hard block)
 *   VARIANT  -> WARNING (never blocks; surfaced to the operator)
 */
final class CpuMixedModelsRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    /** @var CpuIdentityMatcher */
    private $matcher;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
        $this->matcher = new CpuIdentityMatcher($this->dataUtils);
    }

    public function id(): string
    {
        return 'cpu.mixed_models';
    }

    /** The strictest severity this rule can emit; per-result severity is set in evaluate(). */
    public function severity(): string
    {
        return Severity::ERROR;
    }

    /**
     * ADD as well as VALIDATE: the legacy path rejects an unpairable CPU at add-time,
     * so the engine must be able to block there too or the two would diverge.
     */
    public function triggers(): array
    {
        return [Trigger::ADD, Trigger::VALIDATE];
    }

    public function scope(): string
    {
        return self::SCOPE_PAIR;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        $cpus = $state->byType('cpu');

        // Resolve each distinct spec once. How MANY of a model are installed is
        // irrelevant to pairing -- only the set of distinct models matters (INV-1).
        $specs = [];
        foreach ($cpus as $cpu) {
            $uuid = $cpu['spec_uuid'] ?? null;
            if (!$uuid || isset($specs[$uuid])) {
                continue;
            }
            $spec = $this->dataUtils->getCPUByUUID($uuid);
            if (is_array($spec)) {
                $specs[$uuid] = $spec;
            }
        }

        if (count($specs) < 2) {
            return new RuleResult($this->id(), Severity::WARNING, true,
                'Single CPU model in configuration — no pairing constraint');
        }

        $specList = array_values($specs);
        $errors = [];
        $warnings = [];
        $pairs = [];

        for ($i = 0; $i < count($specList); $i++) {
            for ($j = $i + 1; $j < count($specList); $j++) {
                $result = $this->matcher->compare($specList[$i], $specList[$j]);
                $pairs[] = [
                    'existing' => $specList[$i]['model'] ?? '',
                    'incoming' => $specList[$j]['model'] ?? '',
                    'verdict'  => $result['verdict'],
                ];
                if (!$result['compatible']) {
                    $errors[] = $result['error'];
                } elseif (!empty($result['warning'])) {
                    $warnings[] = $result['warning'];
                }
            }
        }

        if (!empty($errors)) {
            return new RuleResult($this->id(), Severity::ERROR, false,
                implode(' ', array_unique($errors)), ['pairs' => $pairs]);
        }

        if (!empty($warnings)) {
            return new RuleResult($this->id(), Severity::WARNING, false,
                implode(' ', array_unique($warnings)), ['pairs' => $pairs]);
        }

        return new RuleResult($this->id(), Severity::WARNING, true,
            'All installed CPUs are the same model', ['pairs' => $pairs]);
    }
}
