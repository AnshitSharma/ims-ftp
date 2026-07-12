<?php
/**
 * verdict_shim_test.php — U-A.3 unit test for VerdictShim (DB-free: builds
 * Verdict/RuleResult objects directly, no ValidationEngine/PDO needed).
 *
 * Exit 0 = every assertion passes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/validation/Severity.php';
require_once $ROOT . '/core/models/validation/Trigger.php';
require_once $ROOT . '/core/models/validation/RuleResult.php';
require_once $ROOT . '/core/models/validation/Verdict.php';
require_once $ROOT . '/api/handlers/server/VerdictShim.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// =========================================================================
echo "-- non-blocking verdict --\n";
$passVerdict = new Verdict([new RuleResult('cpu.socket_match', Severity::ERROR, true, 'ok')], Trigger::ADD);
$shim = VerdictShim::fromVerdict($passVerdict);
check('success=true, error_type=null on a non-blocking verdict', $shim['success'] === true && $shim['error_type'] === null);

// =========================================================================
echo "-- mapped rule_id -> legacy error_type --\n";
foreach ([
    'cpu.socket_match' => 'socket_mismatch',
    'cpu.socket_count' => 'cpu_limit_exceeded',
    'system.singleton' => 'duplicate_component',
    'cpu.requires_board' => 'motherboard_required',
    'pcie.slot_placement' => 'no_pcie_slots_available',
    'dependency.blocked_removal' => 'dependency_blocked',
] as $ruleId => $expectedType) {
    $v = new Verdict([new RuleResult($ruleId, Severity::ERROR, false, "blocked by $ruleId")], Trigger::ADD);
    $s = VerdictShim::fromVerdict($v);
    check("$ruleId -> error_type '$expectedType'", $s['success'] === false && $s['error_type'] === $expectedType);
}

// =========================================================================
echo "-- unknown rule id falls back to compatibility_failure (never a 500) --\n";
$unknown = new Verdict([new RuleResult('some.brand_new_rule', Severity::ERROR, false, 'blocked')], Trigger::ADD);
$s = VerdictShim::fromVerdict($unknown);
check("unmapped rule id -> 'compatibility_failure'", $s['error_type'] === 'compatibility_failure');

// =========================================================================
echo "-- WARNING never blocks, but still surfaces in warnings[] --\n";
$warnOnly = new Verdict([new RuleResult('cpu.mixed_models', Severity::WARNING, false, 'mixed CPU models present')], Trigger::ADD);
$s = VerdictShim::fromVerdict($warnOnly);
check('a WARNING-only verdict is success=true', $s['success'] === true);
check('the warning message is surfaced in warnings[]', in_array('mixed CPU models present', $s['warnings'], true));

// =========================================================================
echo "-- VALIDATION_FAILURE only blocks under VALIDATE/FINALIZE, matching Verdict::blocking() --\n";
$vfUnderAdd = new Verdict([new RuleResult('storage.caddy_pairing', Severity::VALIDATION_FAILURE, false, 'vf under add')], Trigger::ADD);
check('a VF under ADD does not block (matches Verdict::blocking())', VerdictShim::fromVerdict($vfUnderAdd)['success'] === true);
$vfUnderFinalize = new Verdict([new RuleResult('storage.caddy_pairing', Severity::VALIDATION_FAILURE, false, 'vf under finalize')], Trigger::FINALIZE);
check('the SAME rule under FINALIZE blocks', VerdictShim::fromVerdict($vfUnderFinalize)['success'] === false);

// =========================================================================
echo "-- RAM enrichment from MemoryDownclockRule details --\n";
$withDownclock = new Verdict([
    new RuleResult('cpu.socket_match', Severity::ERROR, false, 'blocked'), // the actual blocking reason
    new RuleResult('memory.downclock', Severity::WARNING, false, 'RAM will run at reduced speed', ['downclock_mhz' => 2400]),
], Trigger::ADD);
$s = VerdictShim::fromVerdict($withDownclock);
check('memory.downclock details surface in recommendations[] alongside the real blocking reason', count($s['recommendations']) === 1 && $s['recommendations'][0]['downclock_mhz'] === 2400);
check('the PRIMARY error_type is still the actual ERROR (cpu.socket_match), not the WARNING', $s['error_type'] === 'socket_mismatch');

echo $fails === 0 ? "\nALL CHECKS PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
