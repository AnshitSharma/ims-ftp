<?php

require_once __DIR__ . '/../../../core/models/validation/Verdict.php';
require_once __DIR__ . '/../../../core/models/validation/RuleResult.php';
require_once __DIR__ . '/../../../core/models/validation/Severity.php';
require_once __DIR__ . '/../../../core/models/validation/Trigger.php';

/**
 * VerdictShim — U-A.3. Maps a ValidationEngine Verdict onto the legacy
 * response envelope ({success, message, error_type, warnings, details,
 * recommendations}) callers/consumers of the OLD validateComponentAddition()
 * result shape already expect, so a mutation handler that now gets its
 * blocking decision from a Verdict (via CommandFailed::$verdict) doesn't
 * have to hand-roll that mapping inline, and every caller keeps seeing one
 * of the same ~10 legacy error_type strings it always has.
 *
 * RULE_TO_LEGACY_TYPE is intentionally small and explicit (per the pack:
 * "rule->error_type map is a const with per-row comment") — every rule_id
 * NOT in this map falls back to 'compatibility_failure' (never a 500;
 * fail-closed on the CLASSIFICATION, not on the response itself).
 */
final class VerdictShim
{
    /**
     * rule_id => legacy error_type. Each row cites the legacy check it
     * replaces (see RULE_MAP.md for the full rule-by-rule audit trail).
     */
    const RULE_TO_LEGACY_TYPE = [
        'cpu.socket_match' => 'socket_mismatch',           // legacy: CPU socket type doesn't match motherboard
        'cpu.socket_count' => 'cpu_limit_exceeded',         // legacy: too many CPUs for this motherboard's socket count
        'system.singleton' => 'duplicate_component',        // legacy: "Configuration already has a motherboard/chassis"
        'cpu.requires_board' => 'motherboard_required',      // legacy: CPU/RAM add requires a motherboard present first
        'pcie.slot_placement' => 'no_pcie_slots_available',   // legacy: no free PCIe slot for this card
        'dependency.blocked_removal' => 'dependency_blocked', // NEW class (U-R.8) -- no legacy predecessor, audit R-1
        'engine.build_exception' => 'validation_exception',  // TargetState build/evaluate threw (fail-closed path)
        'engine.rule_exception' => 'validation_exception',   // an individual rule threw during evaluate()
    ];

    /** Unknown rule ids classify as this rather than ever risking a 500. */
    const FALLBACK_TYPE = 'compatibility_failure';

    /**
     * @return array{success:bool, message:string, error_type:?string,
     *               warnings:string[], details:array[], recommendations:array}
     */
    public static function fromVerdict(Verdict $verdict): array
    {
        $warnings = array_values(array_map(function (RuleResult $r) {
            return $r->message();
        }, array_filter($verdict->results(), function (RuleResult $r) {
            return !$r->passed() && $r->severity() === Severity::WARNING;
        })));

        if (!$verdict->blocking()) {
            return [
                'success' => true,
                'message' => 'Component validation passed',
                'error_type' => null,
                'warnings' => $warnings,
                'details' => [],
                'recommendations' => [],
            ];
        }

        $blocking = $verdict->failures();
        // failures() includes WARNINGs too (any non-passed result); blocking()
        // being true here means at least one ERROR (or VF under VALIDATE/
        // FINALIZE) is among them -- pick the FIRST one that actually
        // contributes to blocking() for the primary message/error_type,
        // matching legacy's own "first failure wins" response shape.
        $primary = null;
        foreach ($blocking as $r) {
            if ($r->severity() === Severity::ERROR
                || ($r->severity() === Severity::VALIDATION_FAILURE
                    && in_array($verdict->trigger(), [Trigger::VALIDATE, Trigger::FINALIZE], true))
            ) {
                $primary = $r;
                break;
            }
        }

        $errorType = $primary !== null
            ? (self::RULE_TO_LEGACY_TYPE[$primary->ruleId()] ?? self::FALLBACK_TYPE)
            : self::FALLBACK_TYPE;

        $details = array_map(function (RuleResult $r) {
            return ['rule_id' => $r->ruleId(), 'severity' => $r->severity(), 'message' => $r->message()] + $r->details();
        }, $blocking);

        // RAM enrichment (per the pack: "RAM enrichment mapped from
        // MemoryDownclockRule details") -- surfaced alongside details rather
        // than replacing them, since downclock is a WARNING (never the
        // blocking reason itself) but legacy callers expect this specific key.
        $recommendations = [];
        foreach ($verdict->results() as $r) {
            if ($r->ruleId() === 'memory.downclock' && !$r->passed()) {
                $recommendations[] = $r->details();
            }
        }

        return [
            'success' => false,
            'message' => $primary !== null ? $primary->message() : 'Component validation failed',
            'error_type' => $errorType,
            'warnings' => $warnings,
            'details' => $details,
            'recommendations' => $recommendations,
        ];
    }
}
