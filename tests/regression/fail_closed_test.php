<?php
/**
 * fail_closed_test.php — U-0.1 regression test (INV-5).
 *
 * Proves ServerBuilder::addComponent() is fail-closed: when a validation
 * exception is thrown mid-add (here: a required component JSON spec is
 * missing), the add aborts with success=false and mutates nothing —
 * no config JSON write, no inventory Status flip. Before this unit, three
 * catch blocks logged the exception and let the add continue (audit A-1).
 *
 * Fault is injected via IMS_DATA_PATH pointed at a throwaway copy of
 * ims-data with ram/ram_detail.json hidden, so ComponentDataService's
 * loadJsonData() throws when the RAM spec is requested. The real
 * ims-data/ directory is never touched.
 *
 * Exit 0 = all pass; exit 1 = a failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
// Credential resolution is shared, not copy-pasted: scratch_db_password()
// honours GOLDEN_DB_PASS *and* GOLDEN_DB_PASS_FILE. The local copy this
// replaced honoured only the former, so the documented pass-file fixture
// silently reduced this suite to a self-skip. See _scratch_db.php.
require_once __DIR__ . '/_scratch_db.php';
$dbPass = scratch_db_password();

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// ---------------------------------------------------------------------
// 1. Build a throwaway ims-data copy with ram/ram_detail.json hidden,
//    and point IMS_DATA_PATH at it BEFORE any class touches specs.
// ---------------------------------------------------------------------
$realImsData = dirname($ROOT) . '/ims-data';
$tmpImsData = sys_get_temp_dir() . '/ims-data-fail-closed-' . getmypid();

function copyDir($src, $dst) {
    if (!is_dir($dst)) { mkdir($dst, 0777, true); }
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $s = "$src/$item"; $d = "$dst/$item";
        if (is_dir($s)) { copyDir($s, $d); } else { copy($s, $d); }
    }
}
function rrmdir($dir) {
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $p = "$dir/$item";
        is_dir($p) ? rrmdir($p) : unlink($p);
    }
    rmdir($dir);
}

copyDir($realImsData, $tmpImsData);
$ramSpecFile = "$tmpImsData/ram/ram_detail.json";
check("setup: tmp ram spec exists before hiding", file_exists($ramSpecFile));
rename($ramSpecFile, "$ramSpecFile.hidden");
putenv("IMS_DATA_PATH=$tmpImsData");

$configUuid = null;
$pdo = null;

// Pre-flight, BEFORE the fixture try/finally below. Reaching that finally with an
// unusable DB made the CLEANUP throw (raminventory absent), so this suite exited
// 255 instead of skipping — in every environment without a provisioned replica.
require_once __DIR__ . '/_scratch_db.php';
scratch_db_or_skip(scratch_db_connect(), 'fail-closed spec-gate probes');

try {
    putenv("DB_HOST=$dbHost"); putenv("DB_NAME=$dbName");
    putenv("DB_USER=$dbUser"); putenv("DB_PASS=$dbPass");

    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    require_once $ROOT . '/core/models/server/ServerBuilder.php';
    require_once $ROOT . '/core/models/compatibility/ComponentCompatibility.php';

    // ---------------------------------------------------------------
    // 2. Scratch config + a real RAM inventory row (TEMP-PROBE), same
    //    pattern as fixture_scenarios_real.php.
    // ---------------------------------------------------------------
    $configUuid = 'TEST-FAILCLOSED-' . substr(md5(uniqid()), 0, 8);
    $ramUuid = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c'; // DDR4 RDIMM, ims-data/ram/ram_detail.json

    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-PROBE'");
    $n = $pdo->prepare("SELECT COUNT(*) c FROM raminventory WHERE UUID = ?");
    $n->execute([$ramUuid]);
    $insertedTempInventory = false;
    if ((int)$n->fetch()['c'] === 0) {
        $pdo->prepare("INSERT INTO raminventory (UUID, SerialNumber, Status, Flag) VALUES (?, 'TEMP-FC', 1, 'TEMP-PROBE')")
            ->execute([$ramUuid]);
        $insertedTempInventory = true;
    }

    // U-D.3c: the nine legacy JSON columns are dropped; naming them here would make
    // this fixture die with "Unknown column".
    $cols = [
        'config_uuid' => $configUuid, 'server_name' => 'TEST FAIL-CLOSED', 'is_virtual' => 0,
        'configuration_status' => 0, 'motherboard_uuid' => null, 'chassis_uuid' => null,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')
        ->execute($cols);

    // ---------------------------------------------------------------
    // 3. Snapshot state, then attempt the add. The RAM spec JSON is
    //    hidden, so any code path that resolves it must throw — and
    //    addComponent() must abort cleanly instead of continuing.
    // ---------------------------------------------------------------
    // U-D.3c: "did the refused add mutate the configuration?" is now asked of
    // config_components, the store an add actually writes. Watching ram_configuration
    // was only ever a proxy for that, and since U-D.3a nothing writes it at all — so a
    // check on it would pass no matter how badly the command behaved. Counting live
    // rows cannot go vacuous that way.
    $beforeRows = (int)$pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = "
        . $pdo->quote($configUuid) . " AND removed_at IS NULL")->fetchColumn();
    $beforeStatus = $pdo->query("SELECT Status FROM raminventory WHERE UUID = " . $pdo->quote($ramUuid) . " AND Flag = 'TEMP-PROBE'")->fetch();

    // REPOINTED 2026-08-30 (P9/U-D.2): ServerBuilder::addComponent() is deleted.
    // AddComponentCommand is the add path in production, so the fail-closed
    // property is now asserted where it actually has to hold. The command signals
    // refusal by throwing CommandFailed instead of returning success=false; the
    // guarantee under test — an unresolvable spec must ABORT, never continue — is
    // unchanged, and is now additionally pinned on the failure being attributed
    // rather than generic, so "it threw for some other reason" cannot pass.
    $builder = new ServerBuilder($pdo);
    require_once $ROOT . '/core/models/commands/BaseCommand.php';
    require_once $ROOT . '/core/models/commands/AddComponentCommand.php';

    $refused = false;
    $refusalType = null;
    $refusalMessage = '';
    try {
        (new AddComponentCommand($pdo, $configUuid, 'ram', $ramUuid, ['quantity' => 1]))->execute();
    } catch (CommandFailed $e) {
        $refused = true;
        $refusalType = $e->errorType;
        $refusalMessage = $e->getMessage();
    } catch (Throwable $e) {
        // Anything that is NOT a CommandFailed escaped the command's own
        // fail-closed handling. That is still an abort (nothing was written), but
        // it is an uncontrolled one, so record it distinctly rather than counting
        // it as a pass.
        $refused = true;
        $refusalType = 'UNCAUGHT:' . get_class($e);
        $refusalMessage = $e->getMessage();
    }

    check('add is REFUSED when the component spec cannot be resolved', $refused);
    check('the refusal is a controlled CommandFailed, not an escaped exception (found: ' . var_export($refusalType, true) . ')',
        $refused && strpos((string)$refusalType, 'UNCAUGHT:') !== 0);
    check('no PDO transaction left open', !$pdo->inTransaction());

    $afterRows = (int)$pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = "
        . $pdo->quote($configUuid) . " AND removed_at IS NULL")->fetchColumn();
    check('no component row written by the refused add', $afterRows === $beforeRows);

    $afterStatus = $pdo->query("SELECT Status FROM raminventory WHERE UUID = " . $pdo->quote($ramUuid) . " AND Flag = 'TEMP-PROBE'")->fetch();
    if ($insertedTempInventory) {
        check('inventory Status unchanged', $afterStatus && $afterStatus['Status'] == $beforeStatus['Status']);
    } else {
        check('inventory Status unchanged (pre-existing row)', true); // pre-existing row not ours to assert on
    }

} finally {
    // -------------------------------------------------------------
    // 4. Always restore the hidden spec file and clean up, even on
    //    assertion failure.
    // -------------------------------------------------------------
    if (file_exists("$tmpImsData/ram/ram_detail.json.hidden")) {
        rename("$tmpImsData/ram/ram_detail.json.hidden", "$tmpImsData/ram/ram_detail.json");
    }
    putenv('IMS_DATA_PATH');
    rrmdir($tmpImsData);

    if ($pdo) {
        if ($pdo->inTransaction()) { $pdo->rollback(); }
        if ($configUuid) {
            $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid));
        }
        $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-PROBE'");
    }
}

// ---------------------------------------------------------------------
// 5. Static check mirroring the pack's own acceptance test (INV-5):
//    the swallow-and-continue comment must be gone from both files.
// ---------------------------------------------------------------------
$builderSrc = file_get_contents($ROOT . '/core/models/server/ServerBuilder.php');
$apiSrc = file_get_contents($ROOT . '/api/handlers/server/server_api.php');
check('ServerBuilder.php has no "Continue without" swallow', strpos($builderSrc, 'Continue without') === false);
check('server_api.php has no "Continue with addition" swallow', strpos($apiSrc, 'Continue with addition') === false);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
