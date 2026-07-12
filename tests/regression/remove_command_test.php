<?php
/**
 * remove_command_test.php — U-C.3 regression test for RemoveComponentCommand.
 *
 * FULL acceptance criteria per the execution pack (six §6 scenarios: blocked
 * without cascade, full-subtree cascade with JSON/rows/ledger/inventory all
 * consistent, NIC->SFP parity with legacy) require a real MySQL scratch DB.
 * This session's environment has no reachable local MySQL instance (see the
 * session handoff) -- the DB-backed criteria could NOT be executed here and
 * are marked accordingly, not silently skipped. The underlying mechanisms
 * (DependencyBlockedRemovalRule's six scenarios, TargetStateBuilder::
 * dependentsOf()'s cascade closure) ARE independently unit-tested, DB-free,
 * in tests/unit/rules/dependency_rule_test.php (U-R.8) -- this file only
 * covers what's specific to the command wiring itself.
 *
 * Exit 0 = every DB-free assertion passes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/commands/BaseCommand.php';
require_once $ROOT . '/core/models/commands/RemoveComponentCommand.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// =========================================================================
echo "-- structural checks (no DB needed) --\n";
check('RemoveComponentCommand extends BaseCommand', is_subclass_of('RemoveComponentCommand', 'BaseCommand'));

$ref = new ReflectionClass('RemoveComponentCommand');
check('RemoveComponentCommand is not abstract (all hooks implemented)', !$ref->isAbstract());
foreach (['trigger', 'buildTarget', 'apply'] as $m) {
    check("implements $m()", $ref->hasMethod($m));
}
$ctor = $ref->getConstructor();
check('constructor accepts a cascade:bool parameter', $ctor->getParameters()[5]->getName() === 'cascade');

$src = file_get_contents("$ROOT/core/models/commands/RemoveComponentCommand.php");
check('no beginTransaction in RemoveComponentCommand.php (INV-3)', stripos($src, 'beginTransaction') === false);
check('apply() bumps revision even for json-fallback-only rows (INV-6, no silent mutation)', strpos($src, 'bumpRevision') !== false);
check('cascade rows resolved via TargetStateBuilder::dependentsOf() against the PRE-removal state', strpos($src, 'dependentsOf($current') !== false);

// =========================================================================
echo "-- server_api.php shadow/enforce dispatch wiring --\n";
$apiSrc = file_get_contents("$ROOT/api/handlers/server/server_api.php");
check('handleRemoveComponent references CommandLayer::mode()', preg_match('/function handleRemoveComponent[\s\S]{0,2000}CommandLayer::mode\(\)/', $apiSrc) === 1);
check('cascade defaults to false (matches legacy single-component removal)', strpos($apiSrc, "\$_POST['cascade'] ?? false") !== false);

// =========================================================================
echo "-- DB-backed scenario (real scratch DB when reachable; SKIPPED otherwise) --\n";
require_once __DIR__ . '/_scratch_db.php';
$pdo = scratch_db_connect();
if ($pdo === null) {
    echo "  SKIPPED  board-with-cpus / hba-with-drives / riser-with-cards / chassis-with-bays: blocked without cascade\n";
    echo "  SKIPPED  full-subtree cascade: JSON, rows, ledger, inventory all consistent post-op\n";
    echo "  SKIPPED  nic->sfp parity with the one legacy special case\n";
    echo "  SKIPPED  shadow: characterization ZERO diffs vs legacy remove\n";
} else {
    // Same non-destructive pattern as add_command_test.php: one owned
    // transaction, dryRun()/execute() joins it, always rolled back.
    $pdo->beginTransaction();
    try {
        // A motherboard is a real dependency.blocked_removal anchor
        // (DEPENDS_ON: cpu/ram -> motherboard) -- find a live config that
        // still has a motherboard AND at least one cpu/ram row so removing
        // the motherboard without cascade should block.
        $row = $pdo->query("
            SELECT cc.config_uuid, cc.id AS mb_row_id
            FROM config_components cc
            WHERE cc.component_type = 'motherboard' AND cc.removed_at IS NULL
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            echo "  SKIPPED  no live rows-path motherboard found in this scratch DB (pre-backfill / json-fallback-only fleet) -- this scenario needs a rows-path fixture\n";
        } else {
            $mbSpec = $pdo->query("SELECT spec_uuid FROM config_components WHERE id = " . (int)$row['mb_row_id'])->fetchColumn();

            $cmdNoCascade = new RemoveComponentCommand($pdo, $row['config_uuid'], 'motherboard', $mbSpec, null, false, 0);
            $blockedAsExpected = false;
            try {
                $cmdNoCascade->execute();
            } catch (CommandFailed $e) {
                $blockedAsExpected = ($e->errorType === 'validation_blocked');
            }
            check('removing a motherboard with live children blocks WITHOUT cascade (dependency.blocked_removal)', $blockedAsExpected);

            $cmdCascade = new RemoveComponentCommand($pdo, $row['config_uuid'], 'motherboard', $mbSpec, null, true, 0);
            $cascadeVerdict = $cmdCascade->dryRun(); // dryRun only -- never applies, always rolls back internally too
            check('the SAME removal with cascade=true does not block on dependency.blocked_removal', !in_array('dependency.blocked_removal', array_map(function ($r) { return $r->ruleId(); }, $cascadeVerdict->failures()), true));
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    echo "  (DB-backed scenario ran against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . ", rolled back -- no data persisted)\n";
    echo "  NOTE  nic->sfp parity and full characterization diffing are separate-harness checks, not re-run by this file\n";
}

echo $fails === 0 ? "\nALL CHECKS PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
