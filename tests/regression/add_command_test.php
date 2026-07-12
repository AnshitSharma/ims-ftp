<?php
/**
 * add_command_test.php — U-C.2 regression test for AddComponentCommand.
 *
 * FULL acceptance criteria per the execution pack (shadow: characterization
 * ZERO diffs + shadow log rows; enforce on scratch: regression PASS,
 * equivalence --config green post-op, performance_report within budget)
 * require a real MySQL scratch DB (GOLDEN_DB_* / ims_compat_golden) with
 * config_components + server_configurations + {type}inventory rows. The
 * DB-backed section below self-skips with honest SKIPPED lines when no
 * scratch DB is reachable. Finding B (2026-07-12 verify record) fix: the
 * scenario now dryRun()-pre-checks fixture pairs and treats a blocking
 * verdict (validation_blocked) as a legitimate, asserted-on outcome instead
 * of crashing uncaught. Also carries the Finding A availability-gate
 * scenarios (failed/in-use unit rejected, override_used bypass).
 *
 * What CAN be verified without a DB (structural/contract-level) runs now.
 * Exit 0 = every DB-free assertion passes. Re-run with GOLDEN_DB_* set
 * against a real scratch DB to also exercise the marked-skipped section.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/commands/BaseCommand.php';
require_once $ROOT . '/core/models/commands/AddComponentCommand.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// =========================================================================
echo "-- structural checks (no DB needed) --\n";
check('AddComponentCommand extends BaseCommand', is_subclass_of('AddComponentCommand', 'BaseCommand'));

$ref = new ReflectionClass('AddComponentCommand');
check('AddComponentCommand is not abstract (all hooks implemented)', !$ref->isAbstract());
foreach (['trigger', 'buildTarget', 'apply'] as $m) {
    check("implements $m()", $ref->hasMethod($m));
}

$src = file_get_contents("$ROOT/core/models/commands/AddComponentCommand.php");
check('no beginTransaction in AddComponentCommand.php (INV-3 -- BaseCommand is the only transaction owner)', stripos($src, 'beginTransaction') === false);
check('re-derives the slot plan in apply() rather than trusting a stashed value from elsewhere (SlotPlanner is pure over $target)', strpos($src, 'planSlot') !== false);

// =========================================================================
echo "-- server_api.php shadow/enforce dispatch wiring --\n";
$apiSrc = file_get_contents("$ROOT/api/handlers/server/server_api.php");
check('handleAddComponent references CommandLayer::mode()', strpos($apiSrc, 'CommandLayer::mode()') !== false);
check('shadow path calls dryRun(), not execute()', preg_match('/commandLayerMode === .shadow.[\s\S]{0,200}dryRun\(\)/', $apiSrc) === 1);
check('enforce path calls execute()', preg_match('/commandLayerMode === .enforce.[\s\S]{0,200}execute\(\)/', $apiSrc) === 1);
check('shadow path still calls the real legacy add ($serverBuilder->addComponent) and sets $result from it -- INV-8, mirrors handleRemoveComponent (U-C.3)',
    preg_match('/commandLayerMode === .shadow.[\s\S]{0,900}\$result = \$serverBuilder->addComponent\(/', $apiSrc) === 1);
check('shadow diff comparison uses the legacy call\'s REAL outcome (!$result[\'success\']), not a hardcoded false',
    strpos($apiSrc, '$legacyBlocked = !$result[\'success\']') !== false
    && preg_match('/\$legacyPrecheckBlocked\s*=\s*false;/', $apiSrc) !== 1);

// =========================================================================
echo "-- shadow-fidelity fix (P4 verify record) --\n";
$sbSrc = file_get_contents("$ROOT/core/models/server/ServerBuilder.php");
check('validateComponentAddition\'s shadow hook resolves parent_id from parent_nic_uuid (no longer hardcoded null)',
    strpos($sbSrc, 'resolvedParentId') !== false && strpos($sbSrc, "'parent_id' => \$resolvedParentId") !== false);
check('validateComponentAddition\'s shadow hook resolves slot_ref from port_index for sfp',
    strpos($sbSrc, 'resolvedSlotRef') !== false);

// =========================================================================
echo "-- DB-backed scenario (real scratch DB when reachable; SKIPPED otherwise) --\n";
require_once __DIR__ . '/_scratch_db.php';
$pdo = scratch_db_connect();
if ($pdo === null) {
    echo "  SKIPPED  enforce: add a compatibility-pre-checked component, verify one revision bump + a config_components row\n";
    echo "  SKIPPED  enforce: blocked add raises CommandFailed(validation_blocked) pre-apply (no row, no revision bump)\n";
    echo "  SKIPPED  Finding A: failed unit rejected (component_unavailable), in-use-elsewhere rejected, override_used bypasses\n";
    echo "  SKIPPED  characterization ZERO diffs / equivalence / performance_report -- these need the full harness, not this file\n";
} else {
    // Everything below runs inside ONE transaction this test owns and always
    // rolls back at the end -- execute()/dryRun() see $pdo->inTransaction()
    // === true and therefore join rather than commit (BaseCommand's own
    // ownTransaction rule), so nothing this scenario does is ever persisted.
    $pdo->beginTransaction();
    try {
        // Finding B fix: an arbitrary (config, RAM) pair regularly BLOCKS on
        // real fleet data (e.g. a config whose existing CPU/RAM already
        // mismatch its board) -- that is a legitimate enforce-path outcome,
        // not a fixture. So: dryRun() candidate pairs first, keep one that
        // pre-checks GREEN for the happy path and one that pre-checks BLOCKED
        // for the failure-path assertions.
        $configs = $pdo->query("SELECT config_uuid FROM server_configurations WHERE configuration_status < 3 ORDER BY config_uuid LIMIT 8")->fetchAll(PDO::FETCH_COLUMN);
        $rams = $pdo->query("SELECT DISTINCT UUID FROM raminventory WHERE Status = 1 ORDER BY UUID LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);

        $greenPair = null;
        $blockedPair = null;
        foreach ($configs as $cu) {
            foreach ($rams as $ru) {
                try {
                    $v = (new AddComponentCommand($pdo, $cu, 'ram', $ru, [], 0))->dryRun();
                } catch (CommandFailed $e) {
                    continue; // immutable config / guard block etc. -- not a usable fixture
                }
                if (!$v->blocking() && $greenPair === null) {
                    $greenPair = [$cu, $ru];
                } elseif ($v->blocking() && $blockedPair === null) {
                    $blockedPair = [$cu, $ru];
                }
                if ($greenPair !== null && $blockedPair !== null) {
                    break 2;
                }
            }
        }

        // --- blocked path FIRST: validation_blocked is a LEGITIMATE outcome.
        //     (Runs before the happy path on purpose: the green execute()
        //     claims its inventory unit inside this same transaction, and if
        //     the blocked pair shares that unit the availability gate --
        //     Finding A, tested separately below -- would fire before the
        //     validation verdict this section is asserting on.) ---
        if ($blockedPair === null) {
            echo "  SKIPPED  no (open config, available RAM) pair pre-checks blocked in this scratch DB\n";
        } else {
            list($cu, $ru) = $blockedPair;
            $revBefore = (int)$pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($cu))->fetchColumn();
            $caught = null;
            try {
                (new AddComponentCommand($pdo, $cu, 'ram', $ru, [], 0))->execute();
            } catch (CommandFailed $e) {
                $caught = $e;
            }
            check('blocked add raises CommandFailed', $caught !== null);
            if ($caught !== null && $caught->errorType !== 'validation_blocked') {
                echo "        (actual errorType={$caught->errorType}: " . substr($caught->getMessage(), 0, 160) . ")\n";
            }
            check('blocked add errorType is validation_blocked with a verdict attached', $caught !== null && $caught->errorType === 'validation_blocked' && $caught->verdict !== null);
            $revAfter = (int)$pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($cu))->fetchColumn();
            check('blocked add never reached apply(): revision unchanged', $revAfter === $revBefore);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM config_components WHERE config_uuid = ? AND spec_uuid = ? AND removed_at IS NULL AND added_at > NOW() - INTERVAL 1 MINUTE");
            $stmt->execute([$cu, $ru]);
            check('blocked add inserted no config_components row', (int)$stmt->fetchColumn() === 0);
        }

        // --- happy path: compatibility-pre-checked fixture ---
        if ($greenPair === null) {
            echo "  SKIPPED  no (open config, available RAM) pair pre-checks green in this scratch DB\n";
        } else {
            list($cu, $ru) = $greenPair;
            $revBefore = (int)$pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($cu))->fetchColumn();
            $result = (new AddComponentCommand($pdo, $cu, 'ram', $ru, [], 0))->execute();
            check('execute() returns a CommandResult with revision > previous', $result->revision > $revBefore);
            check('execute() verdict is non-blocking (add succeeded)', !$result->verdict->blocking());
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM config_components WHERE config_uuid = ? AND spec_uuid = ? AND removed_at IS NULL");
            $stmt->execute([$cu, $ru]);
            // A pre-U-B.4-backfill config has no rows-path row at all -- both
            // 0 (json-fallback-only) and 1 (rows-path present) are legitimate,
            // 2+ would mean a duplicate insert bug.
            check('config_components has at most one live row for this add (no duplicate insert)', (int)$stmt->fetchColumn() <= 1);
        }

        // --- Finding A: post-lock availability gate (runs LAST -- it mutates
        //     inventory status inside this rolled-back transaction) ---
        $cuA = $configs ? $configs[0] : null;
        $ruA = $rams ? $rams[count($rams) - 1] : null;
        if ($cuA === null || $ruA === null) {
            echo "  SKIPPED  Finding A scenarios: no config/RAM fixture available\n";
        } else {
            $forceStatus = $pdo->prepare("UPDATE raminventory SET Status = ?, ServerUUID = ? WHERE UUID = ?");

            $forceStatus->execute([0, null, $ruA]); // all units of this spec: failed
            $caught = null;
            try { (new AddComponentCommand($pdo, $cuA, 'ram', $ruA, [], 0))->execute(); } catch (CommandFailed $e) { $caught = $e; }
            check('failed unit (Status=0) is rejected with component_unavailable', $caught !== null && $caught->errorType === 'component_unavailable');
            check('failed-unit message mirrors legacy', $caught !== null && strpos($caught->getMessage(), 'Failed/Defective') !== false);

            $forceStatus->execute([2, 'some-other-config-uuid', $ruA]); // in use elsewhere
            $caught = null;
            try { (new AddComponentCommand($pdo, $cuA, 'ram', $ruA, [], 0))->execute(); } catch (CommandFailed $e) { $caught = $e; }
            check('in-use-in-another-config unit is rejected with component_unavailable', $caught !== null && $caught->errorType === 'component_unavailable');
            check('in-use message names the holding configuration', $caught !== null && strpos($caught->getMessage(), 'some-other-config-uuid') !== false);

            $caught = null;
            try { (new AddComponentCommand($pdo, $cuA, 'ram', $ruA, ['override_used' => true], 0))->execute(); } catch (CommandFailed $e) { $caught = $e; }
            check('override_used bypasses the availability gate (legacy ServerBuilder.php:745 protocol)', $caught === null || $caught->errorType !== 'component_unavailable');
        }
    } finally {
        // Never commit -- this is a read/verify scenario, not a real mutation.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    echo "  (DB-backed scenario ran against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . ", rolled back -- no data persisted)\n";
    echo "  NOTE  characterization ZERO diffs / equivalence --config / performance_report are full-harness checks (separate scripts), not re-run by this file\n";
}

echo $fails === 0 ? "\nALL CHECKS PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
