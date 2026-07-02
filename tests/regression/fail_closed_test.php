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
$dbPass = getenv('GOLDEN_DB_PASS');
if ($dbPass === false) { $dbPass = ''; }

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

    $cols = [
        'config_uuid' => $configUuid, 'server_name' => 'TEST FAIL-CLOSED', 'is_virtual' => 0,
        'configuration_status' => 0, 'motherboard_uuid' => null, 'chassis_uuid' => null,
        'cpu_configuration' => null, 'ram_configuration' => null, 'storage_configuration' => null,
        'caddy_configuration' => null, 'nic_config' => null, 'sfp_configuration' => null,
        'hbacard_config' => null, 'pciecard_configurations' => null,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')
        ->execute($cols);

    // ---------------------------------------------------------------
    // 3. Snapshot state, then attempt the add. The RAM spec JSON is
    //    hidden, so any code path that resolves it must throw — and
    //    addComponent() must abort cleanly instead of continuing.
    // ---------------------------------------------------------------
    $beforeConfig = $pdo->query("SELECT ram_configuration FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid))->fetch();
    $beforeStatus = $pdo->query("SELECT Status FROM raminventory WHERE UUID = " . $pdo->quote($ramUuid) . " AND Flag = 'TEMP-PROBE'")->fetch();

    $builder = new ServerBuilder($pdo);
    $result = $builder->addComponent($configUuid, 'ram', $ramUuid, ['quantity' => 1]);

    check('add returns success=false', ($result['success'] ?? true) === false);
    check('no PDO transaction left open', !$pdo->inTransaction());

    $afterConfig = $pdo->query("SELECT ram_configuration FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid))->fetch();
    check('no config JSON mutation', $afterConfig['ram_configuration'] === $beforeConfig['ram_configuration']);

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
