<?php

/**
 * StateGuard — the mutation gate. U-D.4 removed STATE_MACHINE_ENABLED and with
 * it the off/shadow modes and the TEMP-GUARD(U-0.2) blocks callers used to
 * carry alongside it; checkMutation() is now unconditionally authoritative.
 *
 * Rule: a config whose status_v2 is one of {draft, building, maintenance} may
 * be mutated (add/remove component); any other status_v2 blocks mutation.
 * NULL status_v2 (not yet backfilled) falls back to the legacy rule: blocked
 * only when configuration_status (legacy int) === 3 (finalized) — which is
 * exactly what TEMP-GUARD checked, so nothing is lost by its deletion.
 */
class StateGuard
{
    private const ALLOWED_STATUS_V2 = ['draft', 'building', 'maintenance'];

    /**
     * @param array $lockedRow the already row-locked server_configurations row
     *              (caller must hold the FOR UPDATE lock already; this method
     *              never locks or queries anything itself)
     * @return array|null null = mutation allowed; array = failure payload
     *              (['success'=>false,'error_type'=>...,'message'=>...])
     */
    public static function checkMutation(PDO $pdo, array $lockedRow): ?array
    {
        return self::evaluate($lockedRow);
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

}
