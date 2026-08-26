<?php
/**
 * request_transition_action_test.php — the 2026-08-26 regression.
 *
 * A Request carrying server.config.transition could neither be RAISED nor
 * APPROVED. Both ends ran the edge's ACL check against someone who was never
 * going to hold it: PipelineManager's submit-time preflight builds commands
 * with actor 0 (nobody), and applyStageEffect executes them as the REQUESTER —
 * who by design never gains the permission, because the approval does the work.
 * Every requester, super admins included, got:
 *     Action 1: missing permission 'server.edit'
 * ACL::hasPermission has no admin bypass, which is why role could not save it.
 *
 * The fix is a system-authorized mode that skips the ACL half of
 * assertConfigTransition and NOTHING else. This suite pins both halves: the
 * skip works, and legality/permission still bite everywhere they did before.
 *
 * No MySQL: StateMachine's transition reads are plain SELECTs, so an in-memory
 * SQLite fixture exercises the real method. (The ACL tables are deliberately
 * absent — hasPermission() fails closed to "denied", which is the state a
 * requester without server.edit is in anyway.)
 * Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/state/StateMachine.php';
require_once $ROOT . '/core/models/validation/Trigger.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . ($detail !== '' ? " [$detail]" : '') . "\n";
    if (!$cond) { $fails++; }
}

// ---------------------------------------------------------------------------
echo "-- StateMachine: legality and permission are two separate questions --\n";

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE server_configurations (config_uuid TEXT PRIMARY KEY, status_v2 TEXT)');
$pdo->exec('CREATE TABLE config_status_transitions (from_status TEXT, to_status TEXT, required_permission TEXT, requires_validation TEXT)');
$pdo->exec("INSERT INTO config_status_transitions VALUES
    ('draft','building','server.edit','none'),
    ('validated','finalized','server.finalize','full'),
    ('validating','validated','SYSTEM','full')");
$cfg = 'cfg-request-transition-0001';
$pdo->exec("INSERT INTO server_configurations VALUES ('$cfg', 'draft')");

$asPerson = StateMachine::assertConfigTransition($pdo, $cfg, 'building', 77);
check('a person without server.edit is refused draft->building (unchanged)',
    !$asPerson['allowed'] && strpos($asPerson['reason'], "missing permission 'server.edit'") !== false,
    $asPerson['reason']);

$asSystem = StateMachine::assertConfigTransition($pdo, $cfg, 'building', 77, true);
check('the same edge is allowed when the engine is the actor (an approved Request)',
    $asSystem['allowed'], $asSystem['reason']);

$asNobody = StateMachine::assertConfigTransition($pdo, $cfg, 'building', 0, true);
check('...including actor 0, which is what submit-time preflight passes',
    $asNobody['allowed'], $asNobody['reason']);

$illegal = StateMachine::assertConfigTransition($pdo, $cfg, 'retired', 77, true);
check('LEGALITY still bites: no draft->retired edge, engine or not',
    !$illegal['allowed'] && strpos($illegal['reason'], 'no such transition') !== false,
    $illegal['reason']);

$pdo->exec("UPDATE server_configurations SET status_v2 = NULL WHERE config_uuid = '$cfg'");
$unpopulated = StateMachine::assertConfigTransition($pdo, $cfg, 'building', 77, true);
check('F-21 still bites: a config with no status_v2 is refused, engine or not', !$unpopulated['allowed'], $unpopulated['reason']);

$missing = StateMachine::assertConfigTransition($pdo, 'no-such-config', 'building', 77, true);
check('an unknown config is refused, engine or not', !$missing['allowed'], $missing['reason']);

$pdo->exec("UPDATE server_configurations SET status_v2 = 'validated' WHERE config_uuid = '$cfg'");
$full = StateMachine::assertConfigTransition($pdo, $cfg, 'finalized', 77, true);
check('requires_validation is still reported to the caller (finalize edge = full)',
    $full['allowed'] && $full['requires_validation'] === true);

// ---------------------------------------------------------------------------
echo "-- the default is unchanged for every human caller --\n";

$sm = file_get_contents($ROOT . '/core/models/state/StateMachine.php');
check('assertConfigTransition defaults $systemAuthorized to false',
    strpos($sm, 'int $userId, bool $systemAuthorized = false') !== false);

foreach ([
    'core/models/server/ServerBuilder.php'        => 'ServerBuilder finalize',
    'api/handlers/server/server_api.php'          => 'the transition/finalize endpoints',
] as $file => $label) {
    $src = file_get_contents("$ROOT/$file");
    check("$label never asks for system authorization",
        strpos($src, 'systemAuthorized') === false && !preg_match('/assertConfigTransition\([^)]*,\s*true\s*\)/', $src));
}

$api = file_get_contents($ROOT . '/api/handlers/server/server_api.php');
check('server-transition-status still gates on the caller before building the command',
    strpos($api, "userCanActOnConfig(\$pdo, \$config, \$user['id'], 'server.edit_all')") !== false);

// ---------------------------------------------------------------------------
echo "-- RequestActionExecutor asks for it, on BOTH paths --\n";

$exec = file_get_contents($ROOT . '/core/models/pipelines/RequestActionExecutor.php');
// One buildCommand() serves preflight (actor 0) and runCommand (the requester),
// so the flag being on that single construction is what covers both.
check('the transition command is constructed system-authorized',
    (bool)preg_match('/new TransitionStatusCommand\((?:[^;]*?)\n\s*null,\n\s*true\n\s*\);/s', $exec));
check('buildCommand() is the only place it constructs one (so preflight and execute cannot diverge)',
    substr_count($exec, 'new TransitionStatusCommand(') === 1);
check('preflight still dry-runs through the real command',
    strpos($exec, '$verdict = $command->dryRun();') !== false);

$cmd = file_get_contents($ROOT . '/core/models/commands/TransitionStatusCommand.php');
check('the flag reaches assertConfigTransition and nothing else',
    substr_count($cmd, '$this->systemAuthorized') === 2);   // assignment + the one use

// ---------------------------------------------------------------------------
echo "-- a lighter edge must not run the deployability suite --\n";

// FINALIZE subsumes every VALIDATE rule, so a hardcoded FINALIZE meant
// draft->building was assessed for missing CPU/RAM: a draft refused entry to
// the state it gets built in.
require_once $ROOT . '/core/models/validation/ValidationEngine.php';
$rulesUnder = function ($trigger) {
    $n = 0;
    foreach (ValidationEngine::RULES as $ruleClass) {
        $rule = new $ruleClass();
        $t = $rule->triggers();
        if (in_array($trigger, $t, true) || ($trigger === Trigger::FINALIZE && in_array(Trigger::VALIDATE, $t, true))) {
            $n++;
        }
    }
    return $n;
};
check('no rule claims TRANSITION, so a "none" edge evaluates nothing', $rulesUnder(Trigger::TRANSITION) === 0);
check('FINALIZE still picks up the whole suite', $rulesUnder(Trigger::FINALIZE) > 10, $rulesUnder(Trigger::FINALIZE) . ' rules');
check('TRANSITION is a real member of the vocabulary', in_array(Trigger::TRANSITION, Trigger::all(), true));

// ---------------------------------------------------------------------------
echo "-- apply() side effects belong to finalize only --\n";
check('allocated->installed promotion is gated on finalizing',
    strpos($cmd, "if (\$this->toStatus !== 'finalized') {") !== false);
check('an empty note no longer overwrites the config\'s notes column',
    strpos($cmd, "if (\$this->notes !== '') {") !== false);
check('the executor passes the requester\'s own note, not a house string',
    strpos($exec, "'Applied by an approved Request'") === false);

echo "\n" . ($fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n");
exit($fails === 0 ? 0 : 1);
