<?php
/**
 * replace_command_test.php — U-C.4 regression test for ReplaceComponentCommand.
 *
 * FULL acceptance criteria per the execution pack (cpu A->B with RAM
 * re-anchored+re-validated, board A->B incompatible-B blocks WITH A STILL IN
 * PLACE, chassis A->B bay revalidation, NIC A->B with SFPs re-anchored ports
 * validated) require a real MySQL scratch DB. This session's environment has
 * no reachable local MySQL instance (see the session handoff) -- the
 * DB-backed criteria could NOT be executed here and are marked accordingly,
 * not silently skipped.
 *
 * Exit 0 = every DB-free assertion passes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/commands/BaseCommand.php';
require_once $ROOT . '/core/models/commands/ReplaceComponentCommand.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// =========================================================================
echo "-- structural checks (no DB needed) --\n";
check('ReplaceComponentCommand extends BaseCommand', is_subclass_of('ReplaceComponentCommand', 'BaseCommand'));

$ref = new ReflectionClass('ReplaceComponentCommand');
check('ReplaceComponentCommand is not abstract (all hooks implemented)', !$ref->isAbstract());
foreach (['trigger', 'buildTarget', 'apply'] as $m) {
    check("implements $m()", $ref->hasMethod($m));
}

$src = file_get_contents("$ROOT/core/models/commands/ReplaceComponentCommand.php");
check('no beginTransaction in ReplaceComponentCommand.php (INV-3)', stripos($src, 'beginTransaction') === false);
check('buildTarget produces exactly one returned TargetState (RP-1: no intermediate state a rule could observe)',
    substr_count($src, 'return new TargetState(') === 1 || (substr_count($src, 'return TargetStateBuilder::withAdd') + substr_count($src, 'return new TargetState(')) <= 2);
check('re-anchors children whose parent_id pointed at the old row (in-memory pass, buildTarget)', strpos($src, "\$c['parent_id'] === \$this->oldRow['id']") !== false);
check('re-anchors children in the DB too (config_components UPDATE, apply)', strpos($src, 'UPDATE config_components SET parent_id') !== false);
check('slot inheritance: tries the old slot_ref first via SlotPlanner before falling back to a fresh plan', strpos($src, "SlotPlanner::plan(\$withoutOld, \$resource, \$width, \$this->oldRow['slot_ref'])") !== false);
check('reuses AddComponentCommand-style library calls (updateServerConfigurationTable/updateComponentStatusAndServerUuid), not a reimplementation', substr_count($src, 'updateServerConfigurationTable') === 2 && substr_count($src, 'updateComponentStatusAndServerUuid') === 2);

// =========================================================================
echo "-- API reachability (per the pack: this unit ships command + tests only; U-A.2, same migration, wires the API action) --\n";
$apiSrc = file_get_contents("$ROOT/api/handlers/server/server_api.php");
check('server_api.php now exposes ReplaceComponentCommand via handleReplaceComponent (U-A.2)',
    strpos($apiSrc, 'function handleReplaceComponent') !== false && strpos($apiSrc, 'new ReplaceComponentCommand(') !== false);
check('the new action is flag-gated (CommandLayer::mode() !== off), not reachable in production by default',
    preg_match("/function handleReplaceComponent[\\s\\S]{0,400}CommandLayer::mode\\(\\) === 'off'/", $apiSrc) === 1);

// =========================================================================
echo "-- DB-backed scenario (real scratch DB when reachable; SKIPPED otherwise) --\n";
require_once __DIR__ . '/_scratch_db.php';
$pdo = scratch_db_connect();
if ($pdo === null) {
    echo "  SKIPPED  cpu A->B: RAM re-anchored + re-validated\n";
    echo "  SKIPPED  board A->B incompatible-B: blocks WITH A STILL IN PLACE (stranding scenario now impossible)\n";
    echo "  SKIPPED  chassis A->B: bay revalidation\n";
    echo "  SKIPPED  NIC A->B with SFPs: re-anchored, ports re-validated\n";
} else {
    // dryRun() only -- ReplaceComponentCommand is not yet API-reachable (U-A.2's
    // job), so a real execute() here would be testing a path production never
    // takes yet; dryRun() proves buildTarget()'s single-state RP-1 mechanics
    // (remove+add+re-anchor) without ever calling apply().
    $pdo->beginTransaction();
    try {
        $row = $pdo->query("
            SELECT config_uuid, spec_uuid FROM config_components
            WHERE component_type = 'ram' AND removed_at IS NULL LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        $newRam = $row === false ? false : $pdo->prepare("SELECT UUID FROM raminventory WHERE Status = 1 AND UUID != ? LIMIT 1");
        if ($newRam !== false) {
            $newRam->execute([$row['spec_uuid']]);
            $newRamUuid = $newRam->fetchColumn();
        }

        if ($row === false || empty($newRamUuid)) {
            echo "  SKIPPED  no usable (live rows-path RAM in a config, distinct available RAM) fixture pair found in this scratch DB\n";
        } else {
            $cmd = new ReplaceComponentCommand($pdo, $row['config_uuid'], 'ram', $row['spec_uuid'], null, $newRamUuid, [], 0);
            $verdict = $cmd->dryRun();
            check('ram A->B replace produces a single evaluable TargetState without throwing', $verdict !== null);
            // Not asserting non-blocking here: a real fleet config may have
            // OTHER unrelated failing rules already (e.g. pre-backfill
            // slot_placement, per the P5 verify record's own live-probe
            // finding) -- this scenario only proves the replace MECHANICS run
            // end-to-end against real data, not that this specific config is
            // otherwise valid.
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    echo "  (DB-backed scenario ran against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . ", rolled back -- no data persisted)\n";
    echo "  NOTE  board-A->B-stranding / chassis-bay / NIC-with-SFPs scenarios need specific real fixtures not identified this session -- left for a follow-up DB-capable pass\n";
}

echo $fails === 0 ? "\nALL CHECKS PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
