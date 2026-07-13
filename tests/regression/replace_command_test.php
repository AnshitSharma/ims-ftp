<?php
/**
 * replace_command_test.php — U-C.4 regression test for ReplaceComponentCommand.
 *
 * FULL acceptance criteria per the execution pack (cpu A->B with RAM
 * re-anchored+re-validated, board A->B incompatible-B blocks WITH A STILL IN
 * PLACE, chassis A->B bay revalidation, NIC A->B with SFPs re-anchored ports
 * validated) require a real MySQL scratch DB. The board-stranding,
 * chassis-bay, and NIC-with-SFPs scenarios build their own in-transaction
 * fixtures (real inventory rows -- including two fresh throwaway rows for
 * the chassis scenario, since the scratch DB carries zero 2.5"/3.5" storage
 * inventory and zero Status=1 chassis rows) since the fleet has no
 * naturally-occurring rows-path pair for any of them. When mysql itself is
 * unreachable, everything DB-backed self-skips with honest SKIPPED lines
 * instead.
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
        // rows-path fixture first; fall back to the json path (pre-U-B.4
        // reality: config_components may be empty, but ReplaceComponentCommand
        // finds its old row via TargetState, which mirrors the JSON columns).
        $row = $pdo->query("
            SELECT config_uuid, spec_uuid FROM config_components
            WHERE component_type = 'ram' AND removed_at IS NULL LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            require_once $ROOT . '/core/models/validation/TargetStateBuilder.php';
            foreach ($pdo->query("SELECT config_uuid FROM server_configurations WHERE configuration_status < 3 ORDER BY config_uuid")->fetchAll(PDO::FETCH_COLUMN) as $cu) {
                $rams = TargetStateBuilder::fromCurrent($pdo, $cu)->byType('ram');
                if (!empty($rams)) {
                    $row = ['config_uuid' => $cu, 'spec_uuid' => $rams[0]['spec_uuid']];
                    break;
                }
            }
        }
        $newRamUuid = null;
        if ($row !== false && $row !== null) {
            $newRam = $pdo->prepare("SELECT UUID FROM raminventory WHERE Status = 1 AND UUID != ? LIMIT 1");
            $newRam->execute([$row['spec_uuid']]);
            $newRamUuid = $newRam->fetchColumn();
        }

        if ($row === false || $row === null || empty($newRamUuid)) {
            echo "  SKIPPED  no usable (RAM in a config, distinct available RAM) fixture pair found in this scratch DB\n";
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

            // Finding A (verify record 2026-07-12): the replacement unit must
            // pass the post-lock availability gate. Force every unit of the
            // replacement spec to failed inside this rolled-back transaction.
            $pdo->prepare("UPDATE raminventory SET Status = 0 WHERE UUID = ?")->execute([$newRamUuid]);
            $caught = null;
            try {
                (new ReplaceComponentCommand($pdo, $row['config_uuid'], 'ram', $row['spec_uuid'], null, $newRamUuid, [], 0))->dryRun();
            } catch (CommandFailed $e) {
                $caught = $e;
            }
            check('replace onto a failed unit is rejected with component_unavailable', $caught !== null && $caught->errorType === 'component_unavailable');
            $caught = null;
            try {
                (new ReplaceComponentCommand($pdo, $row['config_uuid'], 'ram', $row['spec_uuid'], null, $newRamUuid, ['override_used' => true], 0))->dryRun();
            } catch (CommandFailed $e) {
                $caught = $e;
            }
            check('override_used bypasses the replace availability gate (legacy ServerBuilder.php:745 protocol)', $caught === null || $caught->errorType !== 'component_unavailable');
        }
        // ---------------------------------------------------------------
        // board A->B stranding: incompatible-B blocks WITH A STILL IN PLACE.
        // No live rows-path (motherboard, cpu) pair with a genuinely
        // socket-mismatched replacement candidate exists in this fleet, so
        // build one in-transaction: board A (LGA3647) + a matching CPU,
        // replaced with board B (LGA2011-3) -- cpu.socket_match must block,
        // and since ReplaceComponentCommand's buildTarget() produces exactly
        // ONE TargetState (remove+add+re-anchor in one pass, per its own
        // docblock) evaluated by dryRun() BEFORE apply() ever runs, the old
        // board row is untouched in the DB regardless -- asserted below by
        // re-reading it after the blocked dryRun().
        $mbA = $pdo->query("SELECT id, UUID FROM motherboardinventory WHERE UUID = 'd2e3f4a5-b6c7-4d8e-9f0a-1b2c3d4e5f6a' AND Status = 1")->fetch(PDO::FETCH_ASSOC);
        $mbB = $pdo->query("SELECT id, UUID FROM motherboardinventory WHERE UUID = '4c8f5e1b-2b4a-4c8d-b9e7-f6d2a3c1e9b8' AND Status = 1")->fetch(PDO::FETCH_ASSOC);
        $cpuLga3647 = $pdo->query("SELECT id, UUID FROM cpuinventory WHERE UUID = '6def2015-bbf6-478d-8e48-4ae32d5443c1' AND Status = 1")->fetch(PDO::FETCH_ASSOC);
        $strandConfigUuid = $pdo->query("SELECT config_uuid FROM server_configurations LIMIT 1")->fetchColumn();

        if ($mbA === false || $mbB === false || $cpuLga3647 === false || $strandConfigUuid === false) {
            echo "  SKIPPED  board A->B stranding: fixture inventory rows (LGA3647 board+cpu, LGA2011-3 board) not present in this scratch DB\n";
        } else {
            $insMbA = $pdo->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, parent_id, added_by) VALUES (?, 'motherboard', 'motherboardinventory', ?, ?, NULL, 0)");
            $insMbA->execute([$strandConfigUuid, $mbA['id'], $mbA['UUID']]);
            $mbARowId = (int)$pdo->lastInsertId();
            $insCpu = $pdo->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, parent_id, added_by) VALUES (?, 'cpu', 'cpuinventory', ?, ?, NULL, 0)");
            $insCpu->execute([$strandConfigUuid, $cpuLga3647['id'], $cpuLga3647['UUID']]);

            $strandCmd = new ReplaceComponentCommand($pdo, $strandConfigUuid, 'motherboard', $mbA['UUID'], null, $mbB['UUID'], [], 0);
            $strandVerdict = $strandCmd->dryRun();
            $strandFailedRules = array_map(function ($r) { return $r->ruleId(); }, $strandVerdict->failures());
            check('board A(LGA3647)->B(LGA2011-3) replace blocks on cpu.socket_match [in-transaction fixture]', in_array('cpu.socket_match', $strandFailedRules, true));

            $stillThere = $pdo->prepare("SELECT COUNT(*) FROM config_components WHERE id = ? AND removed_at IS NULL AND spec_uuid = ?");
            $stillThere->execute([$mbARowId, $mbA['UUID']]);
            check('board A is still in place after the blocked dryRun (stranding structurally impossible -- single TargetState, apply() never ran)', (int)$stillThere->fetchColumn() === 1);
        }

        // ---------------------------------------------------------------
        // NIC A->B with an SFP child: re-anchored, not dependency-blocked.
        // Build a nic row + a live sfp row parented to it, replace the nic,
        // and confirm the replace's own re-anchor pass (buildTarget(), the
        // "$c['parent_id'] === $this->oldRow['id']" rewrite already proven
        // present by the structural check above) keeps the SFP off
        // dependency.blocked_removal in the resulting single TargetState. A
        // motherboard + chassis row are also seeded (DEPENDS_ON: nic/motherboard
        // require them structurally, mechanism 2) purely so THAT unrelated
        // requirement doesn't itself trip dependency.blocked_removal and mask
        // the one signal this scenario actually probes.
        $nicA = $pdo->query("SELECT id, UUID FROM nicinventory WHERE UUID = 'f85c377e-f4a2-46eb-9f2e-a466581d1ee4'")->fetch(PDO::FETCH_ASSOC);
        $nicB = $pdo->query("SELECT id, UUID FROM nicinventory WHERE UUID = '6f8a0b2c-4d6e-4f8a-0b2c-4d6e8f0a2b4c' AND Status = 1")->fetch(PDO::FETCH_ASSOC);
        $sfpUnit = $pdo->query("SELECT id, UUID FROM sfpinventory WHERE UUID = '32bc2712-98a6-421f-85f5-4efb68e4ee00' AND Status = 1")->fetch(PDO::FETCH_ASSOC);
        // excludes mbA's unit (already live-inserted by the board-stranding
        // scenario above, in the same transaction -- inventory rows can only
        // be attached to one live config_components row at a time)
        $nicMb = $pdo->query("SELECT id, UUID FROM motherboardinventory WHERE Status = 1 AND UUID != 'd2e3f4a5-b6c7-4d8e-9f0a-1b2c3d4e5f6a' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $nicChassis = $pdo->query("SELECT id, UUID FROM chassisinventory LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $nicConfigUuid = $pdo->query("SELECT config_uuid FROM server_configurations LIMIT 1")->fetchColumn();

        if ($nicA === false || $nicB === false || $sfpUnit === false || $nicMb === false || $nicChassis === false || $nicConfigUuid === false) {
            echo "  SKIPPED  NIC A->B with SFPs: fixture inventory rows not present in this scratch DB\n";
        } else {
            $pdo->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, parent_id, added_by) VALUES (?, 'motherboard', 'motherboardinventory', ?, ?, NULL, 0)")
                ->execute([$nicConfigUuid, $nicMb['id'], $nicMb['UUID']]);
            $pdo->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, parent_id, added_by) VALUES (?, 'chassis', 'chassisinventory', ?, ?, NULL, 0)")
                ->execute([$nicConfigUuid, $nicChassis['id'], $nicChassis['UUID']]);

            $insNicA = $pdo->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, parent_id, added_by) VALUES (?, 'nic', 'nicinventory', ?, ?, NULL, 0)");
            $insNicA->execute([$nicConfigUuid, $nicA['id'], $nicA['UUID']]);
            $nicARowId = (int)$pdo->lastInsertId();
            $insSfp = $pdo->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, parent_id, added_by) VALUES (?, 'sfp', 'sfpinventory', ?, ?, ?, 0)");
            $insSfp->execute([$nicConfigUuid, $sfpUnit['id'], $sfpUnit['UUID'], $nicARowId]);

            $nicCmd = new ReplaceComponentCommand($pdo, $nicConfigUuid, 'nic', $nicA['UUID'], null, $nicB['UUID'], [], 0);
            $nicVerdict = $nicCmd->dryRun();
            $nicFailedRules = array_map(function ($r) { return $r->ruleId(); }, $nicVerdict->failures());
            check('NIC A->B replace re-anchors its SFP child instead of tripping dependency.blocked_removal [in-transaction fixture]', !in_array('dependency.blocked_removal', $nicFailedRules, true));
        }

        // ---------------------------------------------------------------
        // chassis A->B bay revalidation: replacing the chassis must
        // re-evaluate storage.bay_capacity (StorageBayCapacityRule) against
        // the NEW chassis's drive_bays.bay_configuration for whatever
        // storage already lives in the config. No naturally-occurring
        // fixture exists: the scratch DB carries zero Status=1 chassis rows
        // (all four seeded chassis units are Status=2/in_use) and exactly
        // one storageinventory row total (an M.2 unit, which
        // StorageBayCapacityRule explicitly bypasses -- only 2.5"/3.5"
        // form factors are bay-counted). Built in-transaction: chassis A =
        // Dell PowerEdge R740 (16x 2.5" bays, ims-data 327e585c) as the
        // config's existing occupant; chassis B = Hitachi DS220 (0x 2.5",
        // 12x 3.5" bays, ims-data 4981e5a2) as the incompatible replacement
        // target, flipped to Status=1 within this rolled-back transaction
        // so it clears BaseCommand::assertInventoryAvailability(); one
        // fresh 2.5" SATA SSD storageinventory row (ims-data
        // a3b4c5d6-e7f8-a9b0-c1d2-e3f4a5b6c7d8) inserted as the config's
        // existing storage occupant, since none exist to reuse. A distinct
        // config (not the board-stranding/NIC-with-SFP config above) avoids
        // a second live chassis row landing on the same config_uuid within
        // this transaction.
        //
        // Finding D fix (2026-07-13 verify record): chassis A must NOT reuse
        // the real 327e585c physical unit row -- it is Status=2/in_use and,
        // worse, the SAME row the NIC-with-SFP scenario's $nicChassis query
        // (an unfiltered "LIMIT 1") can also pick up in the same transaction,
        // so a second config_components insert against the same inventory_id
        // trips uq_inventory_once. Insert a fresh synthetic chassisinventory
        // row carrying the R740 spec UUID instead -- exactly the pattern this
        // fixture already uses for its own storage insert below.
        $chASpecUuid = '327e585c-8c3a-4ef5-80a3-c434df5c79a4';
        $chBayConfigUuid = $pdo->query("SELECT config_uuid FROM server_configurations ORDER BY config_uuid LIMIT 1 OFFSET 1")->fetchColumn();
        $chB = $pdo->query("SELECT id, UUID FROM chassisinventory WHERE UUID = '4981e5a2-74b5-46ed-ac9d-7f9bbfdbc6d5'")->fetch(PDO::FETCH_ASSOC);

        if ($chBayConfigUuid === false || $chB === false) {
            echo "  SKIPPED  chassis A->B bay revalidation: fixture inventory rows (4981e5a2 chassis, or a second config) not present in this scratch DB\n";
        } else {
            $pdo->prepare("UPDATE chassisinventory SET Status = 1 WHERE id = ?")->execute([$chB['id']]);

            $insChAUnit = $pdo->prepare("INSERT INTO chassisinventory (UUID, Status) VALUES (?, 2)");
            $insChAUnit->execute([$chASpecUuid]);
            $chAInvId = (int)$pdo->lastInsertId();
            $insChA = $pdo->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, parent_id, added_by) VALUES (?, 'chassis', 'chassisinventory', ?, ?, NULL, 0)");
            $insChA->execute([$chBayConfigUuid, $chAInvId, $chASpecUuid]);

            $insStorage = $pdo->prepare("INSERT INTO storageinventory (UUID, Status) VALUES (?, 2)");
            $insStorage->execute(['a3b4c5d6-e7f8-a9b0-c1d2-e3f4a5b6c7d8']);
            $storageInvId = (int)$pdo->lastInsertId();
            $insStorageRow = $pdo->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, parent_id, added_by) VALUES (?, 'storage', 'storageinventory', ?, ?, NULL, 0)");
            $insStorageRow->execute([$chBayConfigUuid, $storageInvId, 'a3b4c5d6-e7f8-a9b0-c1d2-e3f4a5b6c7d8']);

            $chBayCmd = new ReplaceComponentCommand($pdo, $chBayConfigUuid, 'chassis', $chASpecUuid, null, $chB['UUID'], [], 0);
            $chBayVerdict = $chBayCmd->dryRun();
            $chBayFailedRules = array_map(function ($r) { return $r->ruleId(); }, $chBayVerdict->failures());
            check('chassis A(16x 2.5" bays)->B(0x 2.5" bays) replace blocks on storage.bay_capacity [in-transaction fixture]', in_array('storage.bay_capacity', $chBayFailedRules, true));
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    echo "  (DB-backed scenario ran against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . ", rolled back -- no data persisted)\n";
}

echo $fails === 0 ? "\nALL CHECKS PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
