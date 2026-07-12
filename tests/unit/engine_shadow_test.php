<?php
/**
 * engine_shadow_test.php — U-V.3 unit test for ValidationEngine/ShadowRunner
 * and the ServerBuilder::validateComponentAddition hook.
 *
 * Requires GOLDEN_DB_HOST/GOLDEN_DB_NAME/GOLDEN_DB_USER/GOLDEN_DB_PASS env
 * vars (see tests/backfill/extractor_test.php for the convention). Inserts
 * one throwaway server_configurations row inside a transaction that is
 * rolled back at the end, regardless of pass/fail. Never touches
 * ENGINE_MODE in a live process -- sets it via putenv() in THIS CLI process
 * only, which has no effect on the web server.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/server/ServerBuilder.php';
require_once $ROOT . '/core/models/compatibility/ComponentCompatibility.php';
require_once $ROOT . '/core/models/validation/RuleInterface.php';
require_once $ROOT . '/core/models/validation/RuleResult.php';
require_once $ROOT . '/core/models/validation/Severity.php';
require_once $ROOT . '/core/models/validation/Trigger.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

$pdo = new PDO(
    'mysql:host=' . (getenv('GOLDEN_DB_HOST') ?: '127.0.0.1') . ';dbname=' . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden'),
    getenv('GOLDEN_DB_USER') ?: 'root',
    getenv('GOLDEN_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$shadowDir = $ROOT . '/reports/shadow';
$shadowFile = $shadowDir . '/engine-' . date('Ymd') . '.jsonl';
$linesBefore = is_file($shadowFile) ? count(file($shadowFile)) : 0;

$pdo->beginTransaction();
try {
    $configUuid = 'ENGINE-SHADOW-TEST-' . bin2hex(random_bytes(4));
    $cols = ['config_uuid', 'server_name', 'is_virtual', 'configuration_status'];
    $vals = [$configUuid, 'engine shadow test', 0, 0];
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
    $rowStmt = $pdo->prepare('SELECT * FROM server_configurations WHERE config_uuid = ?');
    $rowStmt->execute([$configUuid]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

    $builder = new ServerBuilder($pdo);
    $compat = new ComponentCompatibility($pdo);

    // A REAL motherboard spec UUID is required here (not a bogus placeholder):
    // TargetState::resources() (U-V.2) resolves ims-data specs for every live
    // component eagerly, including the one being added, and fails closed
    // (CatalogException -> synthesized engine.rule_exception) on an
    // unresolvable UUID -- which is exactly correct engine behavior, but
    // would make THIS test's "engine does not block" assertion meaningless.
    // Production never has this problem: validateComponentUuid() guarantees
    // every real component_uuid resolves in ims-data before insertion.
    $realMotherboardUuid = 'd8e9f0a1-b2c3-4d4e-bf6a-7b8c9d0e1f2a'; // MB_3647, see tests/fixture_scenarios_real.php

    echo "-- ENGINE_MODE=off: passthrough, byte-identical to pre-U-V.3 behavior --\n";
    putenv('ENGINE_MODE=off');
    $offResult = $builder->validateComponentAddition($configUuid, 'motherboard', $realMotherboardUuid, $compat, $row);
    check('off mode returns a legacy-shaped result (success key present)', array_key_exists('success', $offResult));
    $linesAfterOff = is_file($shadowFile) ? count(file($shadowFile)) : 0;
    check('off mode writes NO shadow row', $linesAfterOff === $linesBefore);

    echo "-- ENGINE_MODE=shadow: same legacy result returned, shadow row appended --\n";
    putenv('ENGINE_MODE=shadow');
    $shadowResult = $builder->validateComponentAddition($configUuid, 'motherboard', $realMotherboardUuid, $compat, $row);
    check('shadow mode returns the SAME legacy result as off mode', $shadowResult === $offResult);
    $linesAfterShadow = is_file($shadowFile) ? count(file($shadowFile)) : 0;
    check('shadow mode appended exactly one shadow row', $linesAfterShadow === $linesBefore + 1);

    $lastLine = null;
    if (is_file($shadowFile)) {
        $all = file($shadowFile);
        $lastLine = json_decode(end($all), true);
    }
    check('shadow row parses as JSON', is_array($lastLine));
    check('shadow row config_uuid matches', $lastLine && $lastLine['config_uuid'] === $configUuid);
    check('shadow row trigger is ADD', $lastLine && $lastLine['trigger'] === Trigger::ADD);
    check('shadow row engine.blocked is false (a lone valid motherboard trips no registered rule)', $lastLine && $lastLine['engine']['blocked'] === false);
    check('shadow row legacy.blocked matches actual legacy result', $lastLine && $lastLine['legacy']['blocked'] === empty($shadowResult['success']));

    echo "-- induced rule exception: ValidationEngine::evaluate() fails closed --\n";
    /** A throwaway rule fixture that always throws, registered via a test subclass (RULES is a class const, so a subclass is the only way to swap the registry without touching production ValidationEngine::RULES). */
    eval('
        final class ThrowingTestRule implements RuleInterface {
            public function id(): string { return "test.throws"; }
            public function severity(): string { return Severity::ERROR; }
            public function triggers(): array { return [Trigger::ADD]; }
            public function scope(): string { return self::SCOPE_CONFIG; }
            public function evaluate(TargetState $state): RuleResult { throw new \RuntimeException("induced failure"); }
        }
        final class TestEngineWithThrowingRule extends ValidationEngine {
            const RULES = [ThrowingTestRule::class];
        }
    ');
    require_once $ROOT . '/core/models/validation/TargetState.php';
    $engine = new TestEngineWithThrowingRule();
    $verdict = $engine->evaluate(new TargetState([]), Trigger::ADD);
    check('exception synthesized into a failed RuleResult, not swallowed', count($verdict->failures()) === 1 && $verdict->failures()[0]->ruleId() === 'engine.rule_exception');
    check('synthesized failure is ERROR severity (fail-closed)', $verdict->failures()[0]->severity() === Severity::ERROR);
    check('verdict blocks under ADD', $verdict->blocking() === true);
} finally {
    $pdo->rollBack();
    putenv('ENGINE_MODE'); // unset for this CLI process
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
