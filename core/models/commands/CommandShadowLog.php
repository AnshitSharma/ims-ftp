<?php

/**
 * CommandShadowLog — the single writer for reports/shadow/command-*.jsonl
 * (COMMAND_LAYER_ENABLED=shadow evidence), consumed by
 * scripts/verify/command_parity_report.php.
 *
 * Created 2026-07-27 to close two structural gaps that made COMMAND_LAYER's
 * shadow soak unverifiable rather than merely unverified:
 *
 *   1. NO DENOMINATOR. Both inline writers logged only the interesting case —
 *      add-component when the two sides disagreed, remove-component when the
 *      command blocked — so the log could not distinguish "the command layer
 *      agreed with legacy on 200 operations" from "nothing was exercised at
 *      all". A soak cannot be certified from a log that records only failures.
 *      Every shadow evaluation is now recorded, agreement included.
 *
 *   2. REMOVE ROWS CARRIED NO LEGACY SIDE. remove-component logged from inside
 *      the `if ($commandVerdict->blocking())` branch, BEFORE
 *      ServerBuilder::removeComponent() had run, so `legacy_blocked` was not
 *      merely absent — it was unknowable at write time. A row where legacy
 *      blocked too (agreement) was therefore indistinguishable from a genuine
 *      divergence. The call site now dry-runs, runs legacy, and records both.
 *
 * SCOPE — add and remove only, deliberately. replace-component and
 * transition-status are v2-only actions with NO legacy counterpart
 * (08-api-adapters/DEPRECATION.md; RULE_MAP.md documents replace as
 * zero-diffs-by-construction), so there is no legacy verdict to compare them
 * against and a parity row for them would be meaningless. Their absence from
 * this log is correct, not a gap.
 *
 * ROW SHAPE is an additive EXTENSION of what the two inline writers already
 * emitted — `legacy_blocked` / `command_blocked` / `command_failures` keep
 * their original flat names and meanings — so the 11 pre-existing rows from the
 * 2026-07-22/23 burst stay readable by the report without normalisation. New
 * keys: `component_uuid`, `dry_run_failed`, and `legacy_blocked: null` for the
 * legacy-unknowable case.
 */
final class CommandShadowLog
{
    private static function reportsDir(): string
    {
        return __DIR__ . '/../../../reports/shadow';
    }

    /**
     * Append one command-layer shadow row.
     *
     * @param string      $op            'add' | 'remove'
     * @param array       $subject       ['component_type' => ?string, 'component_uuid' => ?string]
     * @param bool|null   $legacyBlocked legacy path's real outcome, or null when
     *                                   it genuinely could not be known at write
     *                                   time (such a row is NOT parity evidence
     *                                   and the report counts it separately).
     * @param object|null $verdict       the command's dry-run Verdict; null iff
     *                                   the dry run itself failed.
     * @param array       $extra         op-specific context, e.g. ['cascade' => bool]
     * @param bool        $dryRunFailed  true iff dryRun() threw CommandFailed
     */
    public static function record(
        string $op,
        string $configUuid,
        array $subject,
        ?bool $legacyBlocked,
        $verdict,
        array $extra = [],
        bool $dryRunFailed = false
    ): void {
        $dir = self::reportsDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $commandBlocked = null;
        $failures = [];
        if (!$dryRunFailed && $verdict !== null) {
            $commandBlocked = $verdict->blocking();
            foreach ($verdict->failures() as $failure) {
                $failures[] = $failure->ruleId();
            }
        }

        $row = [
            'ts' => date('c'),
            // Provenance of this row; see ShadowRunner::record()'s 'sapi' note. [F-23]
            'sapi' => PHP_SAPI,
            'config_uuid' => $configUuid,
            'op' => $op,
            'component_type' => $subject['component_type'] ?? null,
            'component_uuid' => $subject['component_uuid'] ?? null,
            'legacy_blocked' => $legacyBlocked,
            'command_blocked' => $commandBlocked,
            'command_failures' => $failures,
            'dry_run_failed' => $dryRunFailed,
        ];
        foreach ($extra as $key => $value) {
            // Never let op-specific context shadow a canonical comparison key.
            if (!array_key_exists($key, $row)) {
                $row[$key] = $value;
            }
        }

        @file_put_contents(
            $dir . '/command-' . date('Ymd') . '.jsonl',
            json_encode($row) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
