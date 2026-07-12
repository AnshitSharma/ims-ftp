<?php
/**
 * add_command_test.php — U-C.2 regression test for AddComponentCommand.
 *
 * FULL acceptance criteria per the execution pack (shadow: characterization
 * ZERO diffs + shadow log rows; enforce on scratch: regression PASS,
 * equivalence --config green post-op, performance_report within budget)
 * require a real MySQL scratch DB (GOLDEN_DB_* / ims_compat_golden) with
 * config_components + server_configurations + {type}inventory rows. This
 * session's environment has no reachable local MySQL instance (see the
 * session handoff for detail) -- these DB-backed assertions could NOT be
 * executed here and are marked accordingly below, not silently skipped.
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
    echo "  SKIPPED  enforce: add an available component, verify one revision bump + a config_components row\n";
    echo "  SKIPPED  enforce: verdict parity with legacy on a fixture (blocked add stays blocked)\n";
    echo "  SKIPPED  characterization ZERO diffs / equivalence / performance_report -- these need the full harness, not this file\n";
} else {
    // Everything below runs inside ONE transaction this test owns and always
    // rolls back at the end -- execute() sees $pdo->inTransaction() === true
    // and therefore joins rather than commits (BaseCommand's own
    // ownTransaction rule), so nothing this scenario does is ever persisted.
    $pdo->beginTransaction();
    try {
        // Pick any config not yet finalized/immutable and any available RAM
        // module not already attached anywhere -- RAM has no parent_id/slot_ref
        // dependencies, so this is the simplest real add path to exercise.
        $config = $pdo->query("SELECT config_uuid FROM server_configurations WHERE configuration_status < 3 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $ram = $pdo->query("SELECT UUID FROM raminventory WHERE Status = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        if ($config === false || $ram === false) {
            echo "  SKIPPED  no usable (open config, available RAM) fixture pair found in this scratch DB\n";
        } else {
            $revBefore = (int)$pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($config['config_uuid']))->fetchColumn();

            $cmd = new AddComponentCommand($pdo, $config['config_uuid'], 'ram', $ram['UUID'], [], 0);
            $result = $cmd->execute();

            check('execute() returns a CommandResult with revision > previous', $result->revision > $revBefore);
            check('execute() verdict is non-blocking (add succeeded)', !$result->verdict->blocking());

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM config_components WHERE config_uuid = ? AND spec_uuid = ? AND removed_at IS NULL");
            $stmt->execute([$config['config_uuid'], $ram['UUID']]);
            $rowCount = (int)$stmt->fetchColumn();
            // A pre-U-B.4-backfill config has no rows-path row at all -- both
            // 0 (json-fallback-only) and 1 (rows-path present) are legitimate,
            // 2+ would mean a duplicate insert bug.
            check('config_components has at most one live row for this add (no duplicate insert)', $rowCount <= 1);
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
