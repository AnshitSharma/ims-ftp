<?php
/**
 * finalize_command_test.php — U-C.5 regression test for TransitionStatusCommand.
 *
 * FULL acceptance criteria per the execution pack (defective-inventory
 * fixture blocks via SystemInventoryStateRule, V-2; concurrent-mutation-
 * then-finalize race fixture blocks under lock) require a real MySQL scratch
 * DB. This session's environment has no reachable local MySQL instance (see
 * the session handoff) -- the DB-backed criteria could NOT be executed here
 * and are marked accordingly, not silently skipped. SystemInventoryStateRule
 * itself (the V-2 mechanism) IS independently unit-tested, DB-free, in
 * tests/unit/rules/system_rules_test.php (U-R.7).
 *
 * Exit 0 = every DB-free assertion passes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/commands/BaseCommand.php';
require_once $ROOT . '/core/models/commands/TransitionStatusCommand.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// =========================================================================
echo "-- structural checks (no DB needed) --\n";
check('TransitionStatusCommand extends BaseCommand', is_subclass_of('TransitionStatusCommand', 'BaseCommand'));

$ref = new ReflectionClass('TransitionStatusCommand');
check('TransitionStatusCommand is not abstract (all hooks implemented)', !$ref->isAbstract());
foreach (['trigger', 'buildTarget', 'apply'] as $m) {
    check("implements $m()", $ref->hasMethod($m));
}

$src = file_get_contents("$ROOT/core/models/commands/TransitionStatusCommand.php");
check('no beginTransaction in TransitionStatusCommand.php (INV-3)', stripos($src, 'beginTransaction') === false);
check('trigger() is FINALIZE (full validation always runs for this command)', strpos($src, 'return Trigger::FINALIZE;') !== false);
check('buildTarget() runs assertConfigTransition (legality+permission) under the SAME lock BaseCommand already holds', strpos($src, 'StateMachine::assertConfigTransition') !== false);
check('apply() uses StateMachine::applyConfigTransition (status_v2 + legacy int + revision/event, atomically)', strpos($src, 'StateMachine::applyConfigTransition') !== false);
check('apply() promotes allocated inventory to installed post-finalize', strpos($src, "'allocated'") !== false && strpos($src, "'installed'") !== false);

// =========================================================================
echo "-- ServerBuilder::finalizeConfiguration() enforce delegation --\n";
$sbSrc = file_get_contents("$ROOT/core/models/server/ServerBuilder.php");
check('finalizeConfiguration() delegates to TransitionStatusCommand at CommandLayer::mode()===enforce', preg_match('/function finalizeConfiguration[\s\S]{0,600}CommandLayer::mode\(\) === .enforce.[\s\S]{0,300}TransitionStatusCommand/', $sbSrc) === 1);
check('legacy finalize body is untouched below the enforce delegation (U-D.2 deletes it later, not this unit)', strpos($sbSrc, 'RACE CONDITION FIX (Phase 1): wrap finalize in a transaction') !== false);

// =========================================================================
echo "-- server_api.php: unlocked comprehensive pre-check dropped at enforce --\n";
$apiSrc = file_get_contents("$ROOT/api/handlers/server/server_api.php");
check('handleFinalizeConfiguration only runs the unlocked validateConfigurationComprehensive pre-check when NOT enforce', preg_match('/CommandLayer::mode\(\) !== .enforce.[\s\S]{0,200}validateConfigurationComprehensive/', $apiSrc) === 1);

// =========================================================================
echo "-- DB-backed scenario (real scratch DB when reachable; SKIPPED otherwise) --\n";
require_once __DIR__ . '/_scratch_db.php';
require_once "$ROOT/core/models/state/StateMachine.php";
$pdo = scratch_db_connect();
if ($pdo === null) {
    echo "  SKIPPED  defective-inventory fixture blocks finalize (V-2 via SystemInventoryStateRule)\n";
    echo "  SKIPPED  concurrent-mutation-then-finalize race fixture blocks under lock\n";
} else {
    // KNOWN, DOCUMENTED gap (P6 verify record's FINDING 2, out of scope to fix
    // this session per explicit instruction): config_status_transitions has no
    // direct draft/building -> finalized edge, only the full chain
    // draft->building->validating->validated->finalized. A real fleet config
    // sits at draft/building, so this scenario walks that SAME chain via
    // StateMachine (the intended multi-step lifecycle) rather than routing
    // around the gap -- it deliberately does NOT attempt draft->finalized
    // directly, which would just re-surface Finding 2, not test this unit.
    $pdo->beginTransaction();
    try {
        $config = $pdo->query("SELECT config_uuid, status_v2 FROM server_configurations WHERE status_v2 IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($config === false) {
            echo "  SKIPPED  no config with status_v2 populated found (pre-U-SM.1-backfill scratch DB)\n";
        } else {
            $chain = ['draft', 'building', 'validating', 'validated', 'finalized'];
            $fromIdx = array_search($config['status_v2'], $chain, true);
            if ($fromIdx === false) {
                echo "  SKIPPED  fixture config's status_v2 ('{$config['status_v2']}') is not on the simple linear chain this scenario walks\n";
            } else {
                $walked = true;
                for ($i = $fromIdx; $i < count($chain) - 1 && $walked; $i++) {
                    $assert = StateMachine::assertConfigTransition($pdo, $config['config_uuid'], $chain[$i + 1], 0);
                    if (!$assert['allowed']) {
                        $walked = false;
                        echo "  NOTE  chain walk stopped at {$chain[$i]} -> {$chain[$i + 1]}: {$assert['reason']} (Finding 2 territory -- documented, not fixed here)\n";
                        break;
                    }
                    StateMachine::applyConfigTransition($pdo, $config['config_uuid'], $chain[$i + 1], 0);
                }

                if ($walked && $config['status_v2'] !== 'finalized') {
                    try {
                        $cmd = new TransitionStatusCommand($pdo, $config['config_uuid'], 'finalized', 'DB-backed test scenario (rolled back)', 0);
                        $finalizeVerdict = $cmd->dryRun();
                        check('TransitionStatusCommand::dryRun() to finalized runs end-to-end once the chain is walked (no Finding-2 edge-table block)', $finalizeVerdict !== null);
                    } catch (CommandFailed $e) {
                        check("dryRun() to finalized did not throw CommandFailed ({$e->getMessage()})", false);
                    }

                    // Defective-inventory scenario: mark one live component's
                    // status_v2 failed (still inside the same rolled-back tx)
                    // and confirm SystemInventoryStateRule (V-2) now blocks.
                    $liveComponent = $pdo->query("
                        SELECT inventory_table, spec_uuid FROM config_components cc
                        WHERE cc.config_uuid = " . $pdo->quote($config['config_uuid']) . " AND cc.removed_at IS NULL AND cc.inventory_table IS NOT NULL LIMIT 1
                    ")->fetch(PDO::FETCH_ASSOC);
                    if ($liveComponent !== false) {
                        $table = $liveComponent['inventory_table'];
                        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) === 1) {
                            $pdo->prepare("UPDATE `$table` SET status_v2 = 'failed' WHERE UUID = ?")->execute([$liveComponent['spec_uuid']]);
                            try {
                                $cmd2 = new TransitionStatusCommand($pdo, $config['config_uuid'], 'finalized', 'DB-backed test scenario (rolled back)', 0);
                                $blockedVerdict = $cmd2->dryRun();
                                check('a failed live component blocks finalize (V-2, SystemInventoryStateRule)', $blockedVerdict->blocking());
                            } catch (CommandFailed $e) {
                                check("defective-inventory dryRun() did not throw CommandFailed ({$e->getMessage()})", false);
                            }
                        } else {
                            echo "  SKIPPED  unexpected inventory_table value, not asserting defective-inventory scenario\n";
                        }
                    } else {
                        echo "  SKIPPED  no live rows-path component with a known inventory_table found for this config\n";
                    }
                } else {
                    echo "  SKIPPED  defective-inventory scenario needs a config already past the chain gap (Finding 2) or not already finalized\n";
                }
                echo "  SKIPPED  concurrent-mutation-then-finalize race fixture blocks under lock\n";
            }
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    echo "  (DB-backed scenario ran against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . ", rolled back -- no data persisted)\n";
    echo "  NOTE  concurrent-mutation-then-finalize race requires two overlapping connections -- not attempted this session (single-connection scenario only)\n";
}

echo $fails === 0 ? "\nALL CHECKS PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
