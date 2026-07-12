<?php

require_once __DIR__ . '/Verdict.php';
require_once __DIR__ . '/RuleResult.php';

/**
 * Shadow-mode logging + legacy-result mapping for ValidationEngine (U-V.3).
 * Shadow writes ONLY its own JSONL file — never user-visible state
 * (00-overview/FLAGS.md's shadow-mode contract).
 */
final class ShadowRunner
{
    private static function reportsDir(): string
    {
        return __DIR__ . '/../../../reports/shadow';
    }

    /**
     * Append one comparison row. $legacyBlocked/$legacyClass are the
     * canonicalized legacy-side outcome for the SAME operation the engine
     * just evaluated (see ServerBuilder::validateComponentAddition, which
     * calls this after running both sides so the row always carries a real
     * comparison, never a one-sided placeholder).
     */
    public static function record(string $configUuid, string $op, bool $legacyBlocked, string $legacyClass, Verdict $verdict): void
    {
        $dir = self::reportsDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $file = $dir . '/engine-' . date('Ymd') . '.jsonl';

        $row = [
            'ts' => date('c'),
            'config_uuid' => $configUuid,
            'op' => $op,
            'trigger' => $verdict->trigger(),
            'legacy' => [
                'blocked' => $legacyBlocked,
                'error_class' => $legacyClass,
            ],
            'engine' => [
                'blocked' => $verdict->blocking(),
                'error_class' => self::engineErrorClass($verdict),
            ],
            'results' => array_map(function (RuleResult $r) {
                return [
                    'rule_id' => $r->ruleId(),
                    'severity' => $r->severity(),
                    'passed' => $r->passed(),
                ];
            }, $verdict->results()),
        ];

        @file_put_contents($file, json_encode($row) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Canonical engine-side error_class: the ruleId of the first failing
     * result that actually contributes to blocking(), else 'none'.
     */
    public static function engineErrorClass(Verdict $verdict): string
    {
        if (!$verdict->blocking()) {
            return 'none';
        }
        foreach ($verdict->failures() as $r) {
            if ($r->severity() === Severity::ERROR) {
                return $r->ruleId();
            }
            if ($r->severity() === Severity::VALIDATION_FAILURE
                && in_array($verdict->trigger(), [Trigger::VALIDATE, Trigger::FINALIZE], true)
            ) {
                return $r->ruleId();
            }
        }
        return 'none'; // unreachable if blocking() is true, kept for fail-closed symmetry
    }

    /**
     * Map an ENGINE verdict onto the legacy result shape
     * ({success, message, details}), for ENGINE_MODE=enforce. Only reachable
     * once ENGINE_MODE is flipped off 'off' in production (a human decision
     * gated behind P4's soak) — never exercised while the flag stays off.
     */
    public static function mapVerdictToLegacyResult(Verdict $verdict): array
    {
        if (!$verdict->blocking()) {
            return [
                'success' => true,
                'message' => 'Component validation passed',
                'warnings' => array_map(function (RuleResult $r) {
                    return $r->message();
                }, array_filter($verdict->failures(), function (RuleResult $r) {
                    return $r->severity() === Severity::WARNING;
                })),
            ];
        }

        $blocking = array_values(array_filter($verdict->failures(), function (RuleResult $r) use ($verdict) {
            return $r->severity() === Severity::ERROR
                || ($r->severity() === Severity::VALIDATION_FAILURE
                    && in_array($verdict->trigger(), [Trigger::VALIDATE, Trigger::FINALIZE], true));
        }));
        $first = $blocking[0] ?? null;

        return [
            'success' => false,
            'message' => $first ? $first->message() : 'Component validation failed',
            'details' => array_map(function (RuleResult $r) {
                return ['rule_id' => $r->ruleId(), 'severity' => $r->severity(), 'message' => $r->message()] + $r->details();
            }, $blocking),
        ];
    }
}
