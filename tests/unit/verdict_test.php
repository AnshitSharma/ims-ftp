<?php
/**
 * verdict_test.php — U-V.1 unit test for the value objects + RuleInterface.
 * Pure PHP, no DB. Exit 0 = all pass; exit 1 = a failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/validation/Severity.php';
require_once $ROOT . '/core/models/validation/Trigger.php';
require_once $ROOT . '/core/models/validation/RuleResult.php';
require_once $ROOT . '/core/models/validation/Verdict.php';
require_once $ROOT . '/core/models/validation/RuleInterface.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

function result($severity, $passed) {
    return new RuleResult('test.rule', $severity, $passed, 'msg');
}

// -----------------------------------------------------------------------
echo "-- blocking matrix: ERROR blocks every trigger --\n";
foreach (Trigger::all() as $trigger) {
    $v = new Verdict([result(Severity::ERROR, false)], $trigger);
    check("ERROR failure blocks under $trigger", $v->blocking() === true);
}

echo "-- blocking matrix: VALIDATION_FAILURE blocks only VALIDATE/FINALIZE --\n";
foreach (Trigger::all() as $trigger) {
    $v = new Verdict([result(Severity::VALIDATION_FAILURE, false)], $trigger);
    $expected = in_array($trigger, [Trigger::VALIDATE, Trigger::FINALIZE], true);
    check("VF failure under $trigger blocks=" . ($expected ? 'true' : 'false'), $v->blocking() === $expected);
}

echo "-- blocking matrix: WARNING never blocks --\n";
foreach (Trigger::all() as $trigger) {
    $v = new Verdict([result(Severity::WARNING, false)], $trigger);
    check("WARNING failure under $trigger never blocks", $v->blocking() === false);
}

echo "-- passed results never block regardless of severity --\n";
foreach (Severity::all() as $severity) {
    $v = new Verdict([result($severity, true)], Trigger::FINALIZE);
    check("passed $severity under FINALIZE does not block", $v->blocking() === false);
}

echo "-- mixed results: one blocking failure among passes still blocks --\n";
$v = new Verdict([result(Severity::WARNING, false), result(Severity::ERROR, false), result(Severity::VALIDATION_FAILURE, true)], Trigger::ADD);
check('mixed set with one ERROR failure blocks', $v->blocking() === true);

echo "-- failures() returns only failed results --\n";
$v = new Verdict([result(Severity::WARNING, false), result(Severity::ERROR, true)], Trigger::ADD);
check('failures() excludes passed results', count($v->failures()) === 1 && $v->failures()[0]->severity() === Severity::WARNING);

echo "-- RuleResult immutability (no setters exist; getters echo constructor args) --\n";
$r = new RuleResult('cpu.socket_match', Severity::ERROR, false, 'socket mismatch', ['expected' => 'LGA1700']);
check('ruleId()', $r->ruleId() === 'cpu.socket_match');
check('severity()', $r->severity() === Severity::ERROR);
check('passed()', $r->passed() === false);
check('message()', $r->message() === 'socket mismatch');
check('details()', $r->details() === ['expected' => 'LGA1700']);
check('no public properties (immutability via private+getters)', (new ReflectionClass('RuleResult'))->getProperties(ReflectionProperty::IS_PUBLIC) === []);

echo "-- RuleInterface scope constants exist --\n";
check('SCOPE_PAIR', RuleInterface::SCOPE_PAIR === 'PAIR');
check('SCOPE_RESOURCE', RuleInterface::SCOPE_RESOURCE === 'RESOURCE');
check('SCOPE_CONFIG', RuleInterface::SCOPE_CONFIG === 'CONFIG');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
