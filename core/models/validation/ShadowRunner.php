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
    /**
     * $subject (optional, added 2026-07-25) identifies the component the
     * operation was about: ['component_type' => ..., 'component_uuid' => ...].
     * Without it a divergent row records only that legacy and the engine
     * disagreed on some config, with no way to reproduce which add caused it —
     * this cost a full parity investigation on row a84cc492/2026-07-23. Kept
     * optional so this file can deploy ahead of its caller without a window
     * where the old call signature fatals.
     *
     * $phase (optional, added 2026-07-26 — closes finding F-8) says WHICH of
     * the two per-request evaluations produced this row. One add-component
     * request evaluates validateComponentAddition() TWICE by design:
     *   1. 'advisory'      — api/handlers/server/server_api.php's pre-transaction
     *                        pre-check, against an UNLOCKED snapshot (its own
     *                        comment calls the verdict advisory only).
     *   2. 'authoritative' — re-invoked inside ServerBuilder::addComponent()
     *                        AFTER lockAndLoadConfigRow() takes SELECT ... FOR
     *                        UPDATE. This is the verdict that actually decides
     *                        the operation, and the only one enforce would act on.
     * Both are real evaluations and both are worth keeping (a difference between
     * them IS the TOCTOU drift the lock exists to catch), but before this field
     * existed they serialized byte-identically, so parity_report.php counted one
     * operation as two and inflated operations_compared ~2x. Proven by
     * cross-correlating reports/shadow/command-20260723.jsonl (written ONCE per
     * request) against engine-20260723.jsonl: every command row's timestamp maps
     * to exactly one byte-identical engine PAIR.
     *
     * Defaults to 'authoritative' so that this file may deploy ahead of its
     * callers: the pre-existing 6-argument call site is the authoritative one.
     */
    public static function record(string $configUuid, string $op, bool $legacyBlocked, string $legacyClass, Verdict $verdict, array $subject = [], string $phase = 'authoritative'): void
    {
        $dir = self::reportsDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $file = $dir . '/engine-' . date('Ymd') . '.jsonl';

        $row = [
            'ts' => date('c'),
            // Which SAPI produced this row. [F-23]
            //
            // These files accumulate rows from two completely different sources:
            // real production requests (litespeed) and local harness replays --
            // fleet_parity_sweep, characterize_compatibility, before/after probes --
            // which run under cli. Nothing distinguished them, so a default
            // parity_report run analysed both as production traffic: on 2026-07-27
            // the shadow directory held ~432 local rows against ~132 production ones,
            // i.e. a "GREEN over N operations" claim in which most of N was the test
            // suite talking to itself. Same family as F-8 (double-counted rows) and
            // the duplicate-input-file inflation: the verdicts were never wrong, the
            // denominator was.
            //
            // PHP_SAPI is used rather than APP_ENV because it cannot be forgotten or
            // misconfigured -- production serves under litespeed and never cli, and a
            // harness run is always cli.
            'sapi' => PHP_SAPI,
            'config_uuid' => $configUuid,
            'op' => $op,
            'phase' => $phase,
            'subject' => [
                'component_type' => $subject['component_type'] ?? null,
                'component_uuid' => $subject['component_uuid'] ?? null,
            ],
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
