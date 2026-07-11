<?php

/**
 * StateGuard — the U-SM.4 replacement for TEMP-GUARD(U-0.2), gated behind
 * STATE_MACHINE_ENABLED (off -> shadow -> enforce, see FLAGS.md).
 *
 * Rule: a config whose status_v2 is one of {draft, building, maintenance} may
 * be mutated (add/remove component); any other status_v2 blocks mutation.
 * NULL status_v2 (not yet backfilled) falls back to the legacy rule: blocked
 * only when configuration_status (legacy int) === 3 (finalized) — this is
 * also exactly what TEMP-GUARD(U-0.2) itself checks, so it doubles as the
 * "legacy verdict" shadow mode diffs against.
 *
 * off:     checkMutation() is a no-op, returns null immediately. Zero overhead,
 *          zero behavior change — callers still run their own TEMP-GUARD block.
 * shadow:  evaluates the new rule, compares it against the legacy rule, logs
 *          only the cases where they DISAGREE to reports/shadow/state-guard.jsonl,
 *          then always returns null (never blocks; legacy TEMP-GUARD stays live
 *          and is the sole enforcement in this mode).
 * enforce: returns the new rule's verdict authoritatively. Callers must skip
 *          their TEMP-GUARD block in this mode (wrap it in
 *          `if (StateGuard::mode() !== 'enforce')`) rather than deleting it —
 *          physical deletion of TEMP-GUARD happens in U-D.4.
 */
class StateGuard
{
    private const ALLOWED_STATUS_V2 = ['draft', 'building', 'maintenance'];
    private const LOG_PATH = __DIR__ . '/../../../reports/shadow/state-guard.jsonl';

    /**
     * @return string one of "off", "shadow", "enforce"
     */
    public static function mode(): string
    {
        $mode = getenv('STATE_MACHINE_ENABLED');
        if (!is_string($mode) || $mode === '') {
            $mode = $_ENV['STATE_MACHINE_ENABLED'] ?? 'off';
        }
        $mode = strtolower(trim((string)$mode));
        if (!in_array($mode, ['off', 'shadow', 'enforce'], true)) {
            return 'off';
        }
        return $mode;
    }

    /**
     * @param array $lockedRow the already row-locked server_configurations row
     *              (caller must hold the FOR UPDATE lock already; this method
     *              never locks or queries anything itself)
     * @return array|null null = mutation allowed; array = failure payload
     *              (['success'=>false,'error_type'=>...,'message'=>...])
     */
    public static function checkMutation(PDO $pdo, array $lockedRow): ?array
    {
        $mode = self::mode();
        if ($mode === 'off') {
            return null;
        }

        $verdict = self::evaluate($lockedRow);

        if ($mode === 'shadow') {
            $legacyVerdict = self::legacyVerdict($lockedRow);
            if (($verdict === null) !== ($legacyVerdict === null)) {
                self::logDivergence($lockedRow, $verdict, $legacyVerdict);
            }
            return null;
        }

        // enforce
        return $verdict;
    }

    private static function evaluate(array $lockedRow): ?array
    {
        $statusV2 = $lockedRow['status_v2'] ?? null;

        if ($statusV2 !== null) {
            if (in_array($statusV2, self::ALLOWED_STATUS_V2, true)) {
                return null;
            }
            return [
                'success' => false,
                'error_type' => 'config_immutable',
                'message' => "Configuration status '$statusV2' does not allow mutation. " .
                    "Move it to draft, building, or maintenance first.",
            ];
        }

        // status_v2 not yet populated for this row -> legacy int rule
        return self::legacyVerdict($lockedRow);
    }

    private static function legacyVerdict(array $lockedRow): ?array
    {
        if ((int)($lockedRow['configuration_status'] ?? 0) === 3) {
            return [
                'success' => false,
                'error_type' => 'config_finalized',
                'message' => 'Configuration is finalized and immutable. Move it to maintenance ' .
                    '(not yet available) or unfinalize via an administrator.',
            ];
        }
        return null;
    }

    private static function logDivergence(array $lockedRow, ?array $newVerdict, ?array $legacyVerdict): void
    {
        $dir = dirname(self::LOG_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $entry = [
            'timestamp' => date('c'),
            'config_uuid' => $lockedRow['config_uuid'] ?? null,
            'status_v2' => $lockedRow['status_v2'] ?? null,
            'configuration_status' => $lockedRow['configuration_status'] ?? null,
            'new_verdict_blocks' => $newVerdict !== null,
            'new_reason' => $newVerdict['message'] ?? null,
            'legacy_verdict_blocks' => $legacyVerdict !== null,
        ];
        @file_put_contents(self::LOG_PATH, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }
}
