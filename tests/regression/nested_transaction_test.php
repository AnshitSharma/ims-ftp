<?php
/**
 * nested_transaction_test.php — U-0.3 regression test (transaction ownership symmetry).
 *
 * Proves ServerBuilder::removeComponent() and ::deleteConfiguration() are safe to call
 * inside an already-open PDO transaction: they must not throw "There is already an
 * active transaction" and must not commit/rollback a transaction they don't own — only
 * the outer caller decides the final outcome. Before this unit, both methods called
 * beginTransaction()/rollback() unconditionally (audit R-4 prereq for ReplaceComponentCommand).
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
$dbSocket = getenv('GOLDEN_DB_SOCKET') ?: null;

putenv("DB_HOST=$dbHost"); putenv("DB_NAME=$dbName");
putenv("DB_USER=$dbUser"); putenv("DB_PASS=$dbPass");

$dsn = $dbSocket
    ? "mysql:unix_socket=$dbSocket;dbname=$dbName;charset=utf8mb4"
    : "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

$pdo = new PDO(
    $dsn,
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

$ramUuid = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c';
$builder = new ServerBuilder($pdo);

// =============================================================================
// 1. removeComponent() nested inside an already-open outer transaction
// =============================================================================
$configUuid1 = 'TEST-NEST-RM-' . substr(md5(uniqid()), 0, 8);
try {
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-NEST-PROBE'");
    $pdo->prepare("INSERT INTO raminventory (UUID, SerialNumber, Status, Flag) VALUES (?, 'TEMP-NEST', 2, 'TEMP-NEST-PROBE')")
        ->execute([$ramUuid]);

    $ramConfigJson = json_encode([['uuid' => $ramUuid, 'quantity' => 1, 'serial_number' => 'TEMP-NEST']]);
    $cols = [
        'config_uuid' => $configUuid1, 'server_name' => 'TEST NEST RM', 'is_virtual' => 0,
        'configuration_status' => 1, 'ram_configuration' => $ramConfigJson,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')
        ->execute($cols);

    $pdo->beginTransaction(); // outer transaction, owned by the test
    $threw = false;
    $result = null;
    try {
        $result = $builder->removeComponent($configUuid1, 'ram', $ramUuid, 'TEMP-NEST');
    } catch (\Throwable $e) {
        $threw = true;
        check('removeComponent nested: no exception', false);
        echo "    " . $e->getMessage() . "\n";
    }

    if (!$threw) {
        check('removeComponent nested: no "already an active transaction" exception', true);
        check('removeComponent nested: reports success', ($result['success'] ?? false) === true);
        check('removeComponent nested: outer transaction still open (inner did not commit)', $pdo->inTransaction());
    }

    // Roll the OUTER transaction back — proves the inner call respected outer ownership.
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }

    $after = $pdo->query("SELECT ram_configuration FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid1))->fetch();
    check('removeComponent nested: outer rollback restored config JSON', $after && json_decode($after['ram_configuration'], true) !== []);

} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid1));
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-NEST-PROBE'");
}

// =============================================================================
// 2. deleteConfiguration() nested inside an already-open outer transaction
// =============================================================================
$configUuid2 = 'TEST-NEST-DEL-' . substr(md5(uniqid()), 0, 8);
try {
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-NEST-PROBE2'");
    $pdo->prepare("INSERT INTO raminventory (UUID, SerialNumber, Status, Flag) VALUES (?, 'TEMP-NEST-2', 2, 'TEMP-NEST-PROBE2')")
        ->execute([$ramUuid]);

    $ramConfigJson = json_encode([['uuid' => $ramUuid, 'quantity' => 1, 'serial_number' => 'TEMP-NEST-2']]);
    $cols = [
        'config_uuid' => $configUuid2, 'server_name' => 'TEST NEST DEL', 'is_virtual' => 0,
        'configuration_status' => 1, 'ram_configuration' => $ramConfigJson,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')
        ->execute($cols);

    $pdo->beginTransaction(); // outer transaction, owned by the test
    $threw = false;
    $result = null;
    try {
        $result = $builder->deleteConfiguration($configUuid2);
    } catch (\Throwable $e) {
        $threw = true;
        check('deleteConfiguration nested: no exception', false);
        echo "    " . $e->getMessage() . "\n";
    }

    if (!$threw) {
        check('deleteConfiguration nested: no "already an active transaction" exception', true);
        check('deleteConfiguration nested: reports success', ($result['success'] ?? false) === true);
        check('deleteConfiguration nested: outer transaction still open (inner did not commit)', $pdo->inTransaction());
    }

    // Roll the OUTER transaction back — the config row must still exist afterwards.
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }

    $after = $pdo->query("SELECT config_uuid FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid2))->fetch();
    check('deleteConfiguration nested: outer rollback restored the config row', $after !== false);

} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid2));
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-NEST-PROBE2'");
}

// =============================================================================
// 3. Standalone behaviour unchanged: both methods still own + commit their own
//    transaction when called with no outer transaction open.
// =============================================================================
$configUuid3 = 'TEST-NEST-STANDALONE-' . substr(md5(uniqid()), 0, 8);
try {
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-NEST-PROBE3'");
    $pdo->prepare("INSERT INTO raminventory (UUID, SerialNumber, Status, Flag) VALUES (?, 'TEMP-NEST-3', 2, 'TEMP-NEST-PROBE3')")
        ->execute([$ramUuid]);

    $ramConfigJson = json_encode([['uuid' => $ramUuid, 'quantity' => 1, 'serial_number' => 'TEMP-NEST-3']]);
    $cols = [
        'config_uuid' => $configUuid3, 'server_name' => 'TEST NEST STANDALONE', 'is_virtual' => 0,
        'configuration_status' => 1, 'ram_configuration' => $ramConfigJson,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')
        ->execute($cols);

    check('standalone: no transaction open before call', !$pdo->inTransaction());
    $result = $builder->removeComponent($configUuid3, 'ram', $ramUuid, 'TEMP-NEST-3');
    check('standalone removeComponent: reports success', ($result['success'] ?? false) === true);
    check('standalone removeComponent: commits its own transaction (none left open)', !$pdo->inTransaction());

    $result2 = $builder->deleteConfiguration($configUuid3);
    check('standalone deleteConfiguration: reports success', ($result2['success'] ?? false) === true);
    check('standalone deleteConfiguration: commits its own transaction (none left open)', !$pdo->inTransaction());

} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid3));
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-NEST-PROBE3'");
}

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
