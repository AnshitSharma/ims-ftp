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
// ---- scope the handler's own dispatch chain, once ------------------------
// Every assertion in this section is bound to a strpos-delimited slice of
// handleAddComponent instead of a character budget over the whole file. A byte
// window rots on the next legitimate insertion -- the catch (\Throwable
// $shadowFailure) guard (added so a shadow-only fault stops 500-ing real
// add-component requests) already pushed the legacy add out of a 900-char one --
// and an unanchored haystack can be satisfied by a sibling handler:
// handleRemoveComponent has a shadow/enforce chain of its own that these checks
// do not speak for. Every slice is fail-closed: if an anchor moves, the slice is
// '' and the check goes RED rather than quietly matching something else.
$addFnStart = strpos($apiSrc, 'function handleAddComponent(');
$addFnNext  = $addFnStart !== false ? strpos($apiSrc, "\nfunction ", $addFnStart + 1) : false;
$addFnBody  = $addFnStart === false
    ? ''
    : ($addFnNext === false ? substr($apiSrc, $addFnStart) : substr($apiSrc, $addFnStart, $addFnNext - $addFnStart));

// REWRITTEN 2026-08-30 (P9/U-D.4). These assertions used to pin the SHAPE of the
// COMMAND_LAYER_ENABLED dispatch chain: the flag hoisted once, then a shadow
// branch calling dryRun() alongside the real legacy add, then an enforce branch
// calling execute(). The flag, both branches and the legacy add are all deleted,
// so what replaces them is the stronger claim the deletion actually bought:
// the command is not one of several possible paths, it is the ONLY path.
check('handleAddComponent dispatches to AddComponentCommand',
    $addFnBody !== '' && strpos($addFnBody, 'new AddComponentCommand(') !== false);
check('handleAddComponent calls execute(), unconditionally -- no mode, no branch',
    $addFnBody !== '' && strpos($addFnBody, '$addCommand->execute()') !== false);
// The dispatch must not be reachable-only-sometimes. A reintroduced flag read of
// ANY name inside this handler fails here, not just the deleted CommandLayer one.
check('no rollout-flag read gates the dispatch (no getenv/mode() inside the handler)',
    $addFnBody !== ''
    && strpos($addFnBody, 'getenv(') === false
    && preg_match('/\b(CommandLayer|StateGuard|ValidationEngine|ConfigReadRouter)::mode\(\)/', $addFnBody) !== 1);
check('the deleted legacy add path is not called from this handler',
    $addFnBody !== '' && strpos($addFnBody, '$serverBuilder->addComponent(') === false);
check('dryRun() is not called on the add path (dryRun existed for the shadow comparison, which is gone)',
    $addFnBody !== '' && strpos($addFnBody, '->dryRun()') === false);

// Tree-wide, not just this handler: CommandLayer was deleted outright, so a
// surviving CALL is a dangling reference that would fatal on the first request.
// Comment lines are excluded deliberately -- server_api.php:3574 and
// BaseCommand.php:10 both record what the flag used to do, and that history is
// worth keeping. Anything OUTSIDE a comment is a live call and must be gone.
$liveApiSrc = implode("\n", array_filter(
    explode("\n", $apiSrc),
    function ($l) {
        $t = ltrim($l);
        return $t !== '' && strpos($t, '//') !== 0 && strpos($t, '*') !== 0 && strpos($t, '/*') !== 0 && strpos($t, '#') !== 0;
    }
));
check('no live CommandLayer reference survives in server_api.php (the class is deleted)',
    strpos($liveApiSrc, 'CommandLayer') === false);
check('the CommandLayer class file itself is gone',
    !is_file("$ROOT/core/models/commands/CommandLayer.php"));
$sbSrc = file_get_contents("$ROOT/core/models/server/ServerBuilder.php");
check('ServerBuilder no longer defines addComponent() (U-D.2 deleted the legacy chain)',
    preg_match('/function\s+addComponent\s*\(/', $sbSrc) !== 1);

// The refusal envelope the command layer is responsible for. Previously only the
// happy path was pinned structurally; these two are what callers actually see.
check('a revision_mismatch is answered 409 with the current revision',
    $addFnBody !== ''
    && strpos($addFnBody, "\$commandFailure->errorType === 'revision_mismatch'") !== false
    && strpos($addFnBody, 'current_revision') !== false);
check('a blocking Verdict is translated through VerdictShim, not raw',
    $addFnBody !== ''
    && strpos($addFnBody, "\$commandFailure->errorType === 'validation_blocked'") !== false
    && strpos($addFnBody, 'VerdictShim::fromVerdict(') !== false);
check('quantity > 1 dispatches N commands inside ONE transaction (all-or-nothing)',
    $addFnBody !== ''
    && preg_match('/if\s*\(\$quantity\s*>\s*1\)/', $addFnBody) === 1
    && strpos($addFnBody, 'beginTransaction()') !== false
    && strpos($addFnBody, 'rollBack()') !== false);

// =========================================================================
echo "-- DB-backed scenario (real scratch DB when reachable; SKIPPED otherwise) --\n";
require_once __DIR__ . '/_scratch_db.php';
$pdo = scratch_db_connect();
// A REACHABLE replica that predates P2 used to sail past the null check and then
// crash mid-fixture with an uncaught PDOException (exit 255, all prior results
// lost). Downgrade it to the same honest skip an unreachable DB gets, naming the
// reason so "cannot run" is never mistaken for "ran and agreed".
if ($pdo !== null && ($schemaGap = scratch_db_schema_gap($pdo)) !== null) {
    echo "  (scratch DB unusable: $schemaGap)\n";
    $pdo = null;
}
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
