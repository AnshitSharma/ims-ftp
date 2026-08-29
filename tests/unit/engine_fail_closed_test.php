<?php
/**
 * engine_fail_closed_test.php — INV-5: ValidationEngine never swallows a rule
 * exception.
 *
 * REPLACES engine_shadow_test.php (2026-08-30). That suite spent nine of its
 * twelve assertions on ENGINE_MODE=off/shadow and the JSONL row ShadowRunner
 * appended — the flag, the runner and the log are all deleted by U-D.4, and
 * `ServerBuilder::validateComponentAddition()`, the hook it drove them through,
 * went with the legacy validation chain in U-D.2. Only its last three
 * assertions had a subject that still exists, and they are the important ones:
 * a rule that throws must fail CLOSED.
 *
 * What survives is kept and widened rather than merely transplanted:
 *
 *   - the old suite induced an exception under ONE trigger (ADD). This one
 *     asserts fail-closed under EVERY trigger the engine dispatches;
 *   - it adds the case the old one could not see: a throwing rule must not
 *     prevent its SIBLING rules from being evaluated, or one broken rule would
 *     silently disable the rest of the registry — a fail-open that looks like a
 *     single failure;
 *   - it pins the diagnostic payload (which rule, which exception class),
 *     because 'engine.rule_exception' with no attribution is not actionable;
 *   - it pins that the real production registry is non-empty, so this file can
 *     never pass against an engine that has no rules to break.
 *
 * No database: the engine evaluates a TargetState, and every assertion here is
 * about dispatch, not about data.
 *
 * Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/validation/RuleInterface.php';
require_once $ROOT . '/core/models/validation/RuleResult.php';
require_once $ROOT . '/core/models/validation/Severity.php';
require_once $ROOT . '/core/models/validation/Trigger.php';
require_once $ROOT . '/core/models/config/ResourceCatalog.php';   // TargetState resolves resources through it
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/ValidationEngine.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

echo "engine_fail_closed_test (INV-5)\n";

/**
 * Fixtures: one rule that always throws, one that always passes. Both declare
 * every trigger, so trigger filtering can never be the reason an assertion holds.
 */
final class AlwaysThrowsRule implements RuleInterface
{
    public function id(): string { return 'test.throws'; }
    public function severity(): string { return Severity::ERROR; }
    public function triggers(): array {
        return [Trigger::ADD, Trigger::REMOVE, Trigger::REPLACE, Trigger::VALIDATE, Trigger::FINALIZE];
    }
    public function scope(): string { return self::SCOPE_CONFIG; }
    public function evaluate(TargetState $state): RuleResult {
        throw new \RuntimeException('induced failure');
    }
}

final class AlwaysPassesRule implements RuleInterface
{
    public function id(): string { return 'test.passes'; }
    public function severity(): string { return Severity::ERROR; }
    public function triggers(): array {
        return [Trigger::ADD, Trigger::REMOVE, Trigger::REPLACE, Trigger::VALIDATE, Trigger::FINALIZE];
    }
    public function scope(): string { return self::SCOPE_CONFIG; }
    public function evaluate(TargetState $state): RuleResult {
        return new RuleResult($this->id(), Severity::ERROR, true, 'ok');
    }
}

// RULES is a class constant, so a subclass is the only way to swap the registry
// without editing production ValidationEngine.
final class EngineWithThrowingRule extends ValidationEngine
{
    const RULES = [AlwaysThrowsRule::class];
}

// Deliberately ordered throw-first: if the loop aborted on the exception, the
// passing sibling after it would go missing, which is what the sibling
// assertion below detects.
final class EngineWithThrowingAndPassingRule extends ValidationEngine
{
    const RULES = [AlwaysThrowsRule::class, AlwaysPassesRule::class];
}

$state = new TargetState([]);

// ---- 1. fail-closed under EVERY trigger, not just ADD ------------------------
$blockingTriggers = [Trigger::ADD, Trigger::REMOVE, Trigger::REPLACE, Trigger::VALIDATE, Trigger::FINALIZE];
$engine = new EngineWithThrowingRule();

foreach ($blockingTriggers as $trigger) {
    $verdict = $engine->evaluate($state, $trigger);
    $failures = $verdict->failures();

    check(
        "$trigger: the exception becomes exactly one failed RuleResult, not a swallowed pass",
        count($failures) === 1 && $failures[0]->ruleId() === 'engine.rule_exception'
    );
    check(
        "$trigger: the synthesized failure is ERROR severity",
        count($failures) === 1 && $failures[0]->severity() === Severity::ERROR
    );
    check(
        "$trigger: the verdict BLOCKS",
        $verdict->blocking() === true
    );
}

// ---- 2. the payload names the culprit ---------------------------------------
// 'a rule threw' without saying which one cannot be acted on in production.
$verdict = $engine->evaluate($state, Trigger::ADD);
$details = $verdict->failures()[0]->details();
check("the failure names the offending rule id ('test.throws')", ($details['rule_id'] ?? null) === 'test.throws');
check('the failure names the exception class', ($details['exception_class'] ?? null) === 'RuntimeException');
check(
    'the failure message carries the original exception text',
    strpos($verdict->failures()[0]->message(), 'induced failure') !== false
);
check('the synthesized result is marked not-passed', $verdict->failures()[0]->passed() === false);

// ---- 3. one broken rule must not disable the rest of the registry ------------
// This is the fail-open the single-rule test above cannot see: if the evaluate
// loop aborted on the throw, the registry would silently shrink to whatever ran
// before the exception, and a config would pass on rules that never executed.
$mixed = (new EngineWithThrowingAndPassingRule())->evaluate($state, Trigger::ADD);
$ids = array_map(fn($r) => $r->ruleId(), $mixed->results());
check(
    'a rule that throws does not stop the rules after it from being evaluated',
    in_array('test.passes', $ids, true)
);
check(
    'both the synthesized failure and the sibling result are present (2 results)',
    count($mixed->results()) === 2
);
check('the mixed verdict still blocks (one failure is enough)', $mixed->blocking() === true);

// ---- 4. this file must not be able to pass against an empty engine ----------
check(
    'the production registry is non-empty, so INV-5 has something to protect',
    count(ValidationEngine::RULES) > 0
);
check(
    'every production rule class actually exists (registry has no dangling entry)',
    count(array_filter(ValidationEngine::RULES, fn($c) => !class_exists($c))) === 0
);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
