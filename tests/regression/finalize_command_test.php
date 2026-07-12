<?php
/**
 * finalize_command_test.php — U-C.5 regression test for TransitionStatusCommand.
 *
 * FULL acceptance criteria per the execution pack (defective-inventory
 * fixture blocks via SystemInventoryStateRule, V-2; concurrent-mutation-
 * then-finalize race fixture blocks under lock) require a real MySQL scratch
 * DB. The two-connection section below builds its own throwaway configs
 * (real commits, fully torn down afterward) since these scenarios need two
 * genuinely separate connections racing -- something the single
 * owned-transaction-then-rollback pattern the rest of this file uses cannot
 * express. When mysql itself is unreachable, everything DB-backed self-skips
 * with honest SKIPPED lines instead. SystemInventoryStateRule itself (the
 * V-2 mechanism) IS independently unit-tested, DB-free, in
 * tests/unit/rules/system_rules_test.php (U-R.7).
 *
 * Exit 0 = every DB-free assertion passes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/commands/BaseCommand.php';
require_once $ROOT . '/core/models/commands/TransitionStatusCommand.php';
require_once $ROOT . '/core/models/commands/RemoveComponentCommand.php';

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
            }
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    echo "  (DB-backed scenario ran against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . ", rolled back -- no data persisted)\n";
}

// =========================================================================
// Two-connection concurrency scenarios (real commits against the scratch DB,
// on throwaway configs this section creates and tears down itself -- these
// genuinely need two separate connections racing, which the single owned
// transaction + rollback pattern every other scenario in this file uses
// cannot express: a second connection can't observe a mutation the first
// hasn't committed, and can't be blocked by a lock the first hasn't taken).
echo "-- two-connection concurrency scenarios (real scratch DB, SKIPPED otherwise) --\n";
$conn1 = scratch_db_connect();
$conn2 = scratch_db_connect();
if ($conn1 === null || $conn2 === null) {
    echo "  SKIPPED  409 real-revision after concurrent mutation\n";
    echo "  SKIPPED  finalize race: blocks under lock while another connection holds it\n";
} else {
    function uuidv4(): string {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    // --- Scenario 1: 409 real-revision after concurrent mutation ---------
    $cuA = uuidv4();
    $ramUnit = $conn1->query("SELECT id, UUID FROM raminventory WHERE Status = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($ramUnit === false) {
        echo "  SKIPPED  409 real-revision after concurrent mutation: no available RAM unit in this scratch DB\n";
    } else {
        try {
            $conn1->prepare("INSERT INTO server_configurations (config_uuid, server_name, configuration_status, status_v2, revision, is_virtual, created_by) VALUES (?, 'CONCURRENCY-TEST (409-revision)', 1, 'building', 0, 1, 5)")
                ->execute([$cuA]);
            $conn1->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, added_by) VALUES (?, 'ram', 'raminventory', ?, ?, 0)")
                ->execute([$cuA, $ramUnit['id'], $ramUnit['UUID']]);

            // conn2 captures the revision BEFORE conn1's concurrent mutation --
            // this is the stale value a real client would have read before a
            // second actor mutated the config out from under it.
            $revSeenByConn2 = (int)$conn2->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $conn2->quote($cuA))->fetchColumn();
            check('conn2 observes the fresh config at revision 0', $revSeenByConn2 === 0);

            // conn1: a REAL committed mutation (its own owned transaction,
            // commits inside execute()) -- bumps revision 0 -> 1.
            (new RemoveComponentCommand($conn1, $cuA, 'ram', $ramUnit['UUID'], null, false, 0))->execute();
            $revAfterConn1 = (int)$conn1->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $conn1->quote($cuA))->fetchColumn();
            check('conn1\'s remove really committed and bumped revision to 1', $revAfterConn1 === 1);

            // conn2: still holding the STALE revision it read above, tries to
            // transition the config -- must be rejected with revision_mismatch
            // (409) rather than silently overwriting conn1's concurrent change.
            $caught = null;
            try {
                (new TransitionStatusCommand($conn2, $cuA, 'validated', 'concurrency test (should not apply)', 0, $revSeenByConn2))->execute();
            } catch (CommandFailed $e) {
                $caught = $e;
            }
            check('conn2 with a stale expectedRevision gets CommandFailed', $caught !== null);
            check('conn2\'s failure is revision_mismatch with HTTP 409', $caught !== null && $caught->errorType === 'revision_mismatch' && $caught->httpStatus === 409);
        } finally {
            // Real commits above -> real, explicit teardown (not a rollback).
            $conn1->prepare("DELETE FROM config_events WHERE config_uuid = ?")->execute([$cuA]);
            $conn1->prepare("DELETE FROM config_components WHERE config_uuid = ?")->execute([$cuA]);
            $conn1->prepare("DELETE FROM server_configurations WHERE config_uuid = ?")->execute([$cuA]);
            $conn1->prepare("UPDATE raminventory SET Status = 1, ServerUUID = NULL WHERE UUID = ?")->execute([$ramUnit['UUID']]);
        }
    }

    // --- Scenario 2: finalize race -- blocks under lock -------------------
    $cuB = uuidv4();
    try {
        $conn1->prepare("INSERT INTO server_configurations (config_uuid, server_name, configuration_status, status_v2, revision, is_virtual, created_by) VALUES (?, 'CONCURRENCY-TEST (lock-race)', 1, 'building', 0, 1, 5)")
            ->execute([$cuB]);

        // conn1 simulates a command mid-flight: holds the SAME row-level lock
        // BaseCommand::lockAndLoadConfigRow() takes (FOR UPDATE), inside an
        // uncommitted transaction -- exactly the state a real concurrent
        // command would be in between its own beginTransaction() and commit().
        $conn1->beginTransaction();
        $conn1->prepare('SELECT * FROM server_configurations WHERE config_uuid = ? FOR UPDATE')->execute([$cuB]);

        // conn2 attempts a second command against the SAME config while
        // conn1 still holds the lock uncommitted. A short lock-wait timeout
        // turns the resulting MySQL wait into a fast, deterministic failure
        // instead of a real multi-second block, without changing what's being
        // proven: the second command cannot proceed until the first releases.
        $conn2->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $caught = null;
        try {
            (new TransitionStatusCommand($conn2, $cuB, 'validated', 'concurrency test (should be blocked)', 0))->execute();
        } catch (CommandFailed $e) {
            $caught = $e;
        }
        check('a second command against the SAME config is blocked while conn1 still holds the row lock uncommitted', $caught !== null);
        if ($caught !== null && $caught->errorType !== 'command_exception') {
            echo "        (actual errorType={$caught->errorType}: " . substr($caught->getMessage(), 0, 160) . ")\n";
        }
        check('the block surfaces as a lock-wait failure (command_exception), not a silent pass-through', $caught !== null && $caught->errorType === 'command_exception');

        $conn1->rollBack(); // release the lock

        // Now that conn1 released it, the SAME command on conn2 should be
        // able to acquire the lock and proceed past it (still fails later,
        // on transition legality -- building has no direct edge to validated
        // per Finding 2 -- but that is a DIFFERENT errorType, proving the lock
        // itself is no longer what's blocking).
        $caught2 = null;
        try {
            (new TransitionStatusCommand($conn2, $cuB, 'validated', 'concurrency test (post-release)', 0))->execute();
        } catch (CommandFailed $e) {
            $caught2 = $e;
        }
        check('once conn1 releases the lock, conn2 acquires it and proceeds past the lock wait (fails on transition legality instead, or succeeds)', $caught2 === null || $caught2->errorType !== 'command_exception');
    } finally {
        if ($conn1->inTransaction()) {
            $conn1->rollBack();
        }
        $conn1->prepare("DELETE FROM config_events WHERE config_uuid = ?")->execute([$cuB]);
        $conn1->prepare("DELETE FROM config_components WHERE config_uuid = ?")->execute([$cuB]);
        $conn1->prepare("DELETE FROM server_configurations WHERE config_uuid = ?")->execute([$cuB]);
    }

    echo "  (concurrency scenarios ran with two live connections against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . "; throwaway configs created + fully cleaned up, no real fleet data touched)\n";
}

echo $fails === 0 ? "\nALL CHECKS PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
