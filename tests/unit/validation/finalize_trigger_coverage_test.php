<?php
/**
 * finalize_trigger_coverage_test.php — regression test for F-26.
 *
 * Guards the 2026-07-29 fix. Only 4 of 22 rules declare Trigger::FINALIZE, but
 * legacy's finalize gate is validateConfigurationComprehensive() (the whole
 * compatibility suite) and COMMAND_LAYER_ENABLED=enforce DELETES that gate in
 * TransitionStatusCommand's favour. Under strict trigger membership the swap
 * silently dropped 18 rules from finalize.
 *
 * Caught in production shadow on the first complete build the new finalize hook
 * ever saw: 2026-07-28T20:03:44Z, config a3177ce9 — legacy blocked on
 * missing_caddy + ram_type_incompatible, the command layer allowed it with ZERO
 * failures.
 *
 * The fix is structural (ValidationEngine::rulesFor(): FINALIZE subsumes
 * VALIDATE), so the test is structural too — it asserts the RELATIONSHIP over
 * the whole registry rather than listing today's rules, which is what makes it
 * catch rule #23 as well.
 *
 * No DB, no ims-data. Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/validation/ValidationEngine.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

/** rulesFor() is private by design; the invariant it encodes is still public behaviour. */
$applies = function (RuleInterface $rule, string $trigger): bool {
    $m = new ReflectionMethod('ValidationEngine', 'rulesFor');
    $m->setAccessible(true);
    return $m->invoke(null, $rule, $trigger);
};

$rules = [];
foreach (ValidationEngine::RULES as $cls) {
    $rules[] = new $cls();
}
echo "\n-- Registry loaded: " . count($rules) . " rules --\n";
check('registry is non-empty (a zero-rule engine would pass every check below)', count($rules) > 0);

echo "\n-- F-26: every VALIDATE rule must also run at FINALIZE --\n";
$missing = [];
foreach ($rules as $rule) {
    if (in_array(Trigger::VALIDATE, $rule->triggers(), true)
        && !$applies($rule, Trigger::FINALIZE)) {
        $missing[] = $rule->id();
    }
}
check('no VALIDATE rule is skipped at FINALIZE (' . count($missing) . ' missing: '
    . (implode(', ', $missing) ?: 'none') . ')', $missing === []);

echo "\n-- The two rules the production divergence turned on --\n";
$byId = [];
foreach ($rules as $rule) { $byId[$rule->id()] = $rule; }

check('storage.caddy_pairing runs at FINALIZE (legacy: missing_caddy)',
    isset($byId['storage.caddy_pairing']) && $applies($byId['storage.caddy_pairing'], Trigger::FINALIZE));
check('memory.type runs at FINALIZE (legacy: ram_type_incompatible)',
    isset($byId['memory.type']) && $applies($byId['memory.type'], Trigger::FINALIZE));

echo "\n-- Severity semantics unchanged: caddy_pairing still must not block an ADD --\n";
check('storage.caddy_pairing is VALIDATION_FAILURE, not ERROR',
    $byId['storage.caddy_pairing']->severity() === Severity::VALIDATION_FAILURE);
$v = new Verdict([new RuleResult('storage.caddy_pairing', Severity::VALIDATION_FAILURE, false, 'x')], Trigger::ADD);
check('a failed VALIDATION_FAILURE does NOT block at ADD', $v->blocking() === false);
$v = new Verdict([new RuleResult('storage.caddy_pairing', Severity::VALIDATION_FAILURE, false, 'x')], Trigger::FINALIZE);
check('the same failure DOES block at FINALIZE', $v->blocking() === true);

echo "\n-- NOT symmetric: per-operation triggers stay strict (A-12) --\n";
check('system.required_set does not leak into ADD (would block editing a draft)',
    $applies($byId['system.required_set'], Trigger::ADD) === false);
check('system.psu_capacity does not leak into ADD',
    $applies($byId['system.psu_capacity'], Trigger::ADD) === false);
check('dependency.blocked_removal does not leak into FINALIZE (REMOVE/REPLACE only)',
    $applies($byId['dependency.blocked_removal'], Trigger::FINALIZE) === false);
check('a VALIDATE-only rule still does not run at REMOVE',
    $applies($byId['cpu.mixed_models'], Trigger::REMOVE) === false);

echo "\n-- FINALIZE is now a superset of VALIDATE, strictly --\n";
$validateCount = 0; $finalizeCount = 0;
foreach ($rules as $rule) {
    if ($applies($rule, Trigger::VALIDATE)) { $validateCount++; }
    if ($applies($rule, Trigger::FINALIZE)) { $finalizeCount++; }
}
echo "  VALIDATE: $validateCount rules   FINALIZE: $finalizeCount rules\n";
check('FINALIZE covers at least as many rules as VALIDATE', $finalizeCount >= $validateCount);
check('FINALIZE covers far more than the original 4', $finalizeCount > 4);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
