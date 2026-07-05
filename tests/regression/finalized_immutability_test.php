<?php
/**
 * finalized_immutability_test.php — U-0.2 regression test (INV-4).
 *
 * Proves a finalized (configuration_status=3) config can't be mutated via
 * ServerBuilder::addComponent() or ::removeComponent(): both must reject
 * inside the row lock with error_type=config_finalized and change nothing
 * (config JSON columns + inventory Status byte-identical before/after).
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

putenv("DB_HOST=$dbHost"); putenv("DB_NAME=$dbName");
putenv("DB_USER=$dbUser"); putenv("DB_PASS=$dbPass");

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once $ROOT . '/core/models/server/ServerBuilder.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

$ramUuid = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c'; // DDR4 RDIMM, ims-data/ram/ram_detail.json
$configUuid = null;

try {
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-PROBE'");
    $n = $pdo->prepare("SELECT COUNT(*) c FROM raminventory WHERE UUID = ?");
    $n->execute([$ramUuid]);
    $insertedTempInventory = false;
    if ((int)$n->fetch()['c'] === 0) {
        $pdo->prepare("INSERT INTO raminventory (UUID, SerialNumber, Status, Flag) VALUES (?, 'TEMP-FIN', 1, 'TEMP-PROBE')")
            ->execute([$ramUuid]);
        $insertedTempInventory = true;
    }

    $configUuid = 'TEST-FINALIZED-' . substr(md5(uniqid()), 0, 8);
    $ramConfigJson = json_encode([['uuid' => $ramUuid, 'quantity' => 1, 'serial_number' => 'TEMP-FIN']]);
    $cols = [
        'config_uuid' => $configUuid, 'server_name' => 'TEST FINALIZED', 'is_virtual' => 0,
        'configuration_status' => 3, 'motherboard_uuid' => null, 'chassis_uuid' => null,
        'cpu_configuration' => null, 'ram_configuration' => $ramConfigJson, 'storage_configuration' => null,
        'caddy_configuration' => null, 'nic_config' => null, 'sfp_configuration' => null,
        'hbacard_config' => null, 'pciecard_configurations' => null,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')
        ->execute($cols);

    $builder = new ServerBuilder($pdo);

    // ---- addComponent on a finalized config ----------------------------
    $before = $pdo->query("SELECT ram_configuration FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid))->fetch();
    $beforeStatus = $pdo->query("SELECT Status FROM raminventory WHERE UUID = " . $pdo->quote($ramUuid) . " AND Flag = 'TEMP-PROBE'")->fetch();

    $addResult = $builder->addComponent($configUuid, 'ram', $ramUuid, ['quantity' => 1, 'serial_number' => 'TEMP-FIN-2']);
    check('addComponent rejects finalized config', ($addResult['success'] ?? true) === false);
    check('addComponent error_type=config_finalized', ($addResult['error_type'] ?? null) === 'config_finalized');
    check('addComponent: no PDO transaction left open', !$pdo->inTransaction());

    $after = $pdo->query("SELECT ram_configuration FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid))->fetch();
    check('addComponent: no config JSON mutation', $after['ram_configuration'] === $before['ram_configuration']);

    $afterStatus = $pdo->query("SELECT Status FROM raminventory WHERE UUID = " . $pdo->quote($ramUuid) . " AND Flag = 'TEMP-PROBE'")->fetch();
    if ($insertedTempInventory) {
        check('addComponent: inventory Status unchanged', $afterStatus && $afterStatus['Status'] == $beforeStatus['Status']);
    }

    // ---- removeComponent on a finalized config --------------------------
    $before2 = $pdo->query("SELECT ram_configuration FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid))->fetch();

    $removeResult = $builder->removeComponent($configUuid, 'ram', $ramUuid, 'TEMP-FIN');
    check('removeComponent rejects finalized config', ($removeResult['success'] ?? true) === false);
    check('removeComponent error_type=config_finalized', ($removeResult['error_type'] ?? null) === 'config_finalized');
    check('removeComponent: no PDO transaction left open', !$pdo->inTransaction());

    $after2 = $pdo->query("SELECT ram_configuration FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid))->fetch();
    check('removeComponent: no config JSON mutation', $after2['ram_configuration'] === $before2['ram_configuration']);

} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    if ($configUuid) {
        $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid));
    }
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-PROBE'");
}

// ---- static check: TEMP-GUARD marker present at both sites -------------
$src = file_get_contents($ROOT . '/core/models/server/ServerBuilder.php');
check('TEMP-GUARD(U-0.2) marker present (x2)', substr_count($src, 'TEMP-GUARD(U-0.2)') === 2);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
