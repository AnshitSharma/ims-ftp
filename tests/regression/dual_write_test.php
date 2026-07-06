<?php
/**
 * dual_write_test.php — U-1.5 regression test (DUAL_WRITE_ENABLED hook).
 *
 * Proves the ConfigComponentWriter hook wired into ServerBuilder::addComponent()/
 * removeComponent():
 *   - flag off (default): completely inert, zero config_components/config_events/
 *     revision writes, even when the legacy call site executes normally.
 *   - flag on, remove: a live config_components row (pre-seeded to simulate a prior
 *     dual-written add) is tombstoned in the SAME transaction as the real
 *     ServerBuilder::removeComponent() legacy JSON write.
 *   - flag on, add: same-transaction proof done directly against
 *     ConfigComponentWriter::afterLegacyAdd() (see NOTE below for why).
 *   - a repository failure during the writer call rolls back BOTH the legacy write
 *     and the new-schema write together (fail-closed, INV-5).
 *
 * NOTE on coverage: ServerBuilder::addComponent() gates every real (non-virtual)
 * component on ComponentCompatibility::validateComponentExistsInJSON(), which reads
 * ims-data/{type}/*.json (CLAUDE.md: canonical specs live outside the DB). This
 * sandbox has no ims-data/ (same gap documented in every prior migration unit's
 * handoff), so addComponent() cannot be driven end-to-end here. removeComponent()
 * has no such gate, so its test below runs through the real method. The add-path
 * same-transaction/rollback proofs instead call the writer directly, wrapped in a
 * transaction that also performs the identical legacy-column write
 * ServerBuilder::addComponent() would have made immediately before it — this
 * exercises the exact hook code and calling contract that is actually wired into
 * ServerBuilder.php, just without routing through the (unrelated) ims-data gate.
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
    $dsn, $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once $ROOT . '/core/models/server/ServerBuilder.php';
require_once $ROOT . '/core/models/config/ConfigComponentWriter.php';
require_once $ROOT . '/core/models/config/ConfigComponentRepository.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

function makeRamRow(PDO $pdo, $uuid, $serial, $flag) {
    $pdo->prepare("INSERT INTO raminventory (UUID, SerialNumber, Status, Flag) VALUES (?, ?, 2, ?)")
        ->execute([$uuid, $serial, $flag]);
    return (int)$pdo->lastInsertId();
}

function makeConfig(PDO $pdo, $configUuid, $ramConfigJson) {
    $cols = [
        'config_uuid' => $configUuid, 'server_name' => 'DUAL WRITE TEST', 'is_virtual' => 0,
        'configuration_status' => 1, 'ram_configuration' => $ramConfigJson,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')
        ->execute($cols);
}

$ramUuid = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c';
$builder = new ServerBuilder($pdo);

// =============================================================================
// A. Flag OFF (default): removeComponent() through the real hook site is inert
// =============================================================================
$configA = 'TEST-DW-OFF-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED'); // unset -> mode() falls back to 'off'
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-DW-OFF'");
    $serial = 'TEMP-DW-OFF';
    makeRamRow($pdo, $ramUuid, $serial, 'TEMP-DW-OFF');
    makeConfig($pdo, $configA, json_encode([['uuid' => $ramUuid, 'quantity' => 1, 'serial_number' => $serial]]));

    check("mode() defaults to 'off' with no env set", ConfigComponentWriter::mode() === 'off');

    $result = $builder->removeComponent($configA, 'ram', $ramUuid, $serial);
    check('flag off: removeComponent() still succeeds', ($result['success'] ?? false) === true);

    $rows = $pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = " . $pdo->quote($configA))->fetchColumn();
    check('flag off: no config_components row written', (int)$rows === 0);

    $rev = $pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($configA))->fetchColumn();
    check('flag off: revision untouched (still 0)', (int)$rev === 0);
} finally {
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configA));
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configA));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configA));
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-DW-OFF'");
}

// =============================================================================
// B. Flag ON: removeComponent() tombstones a pre-seeded row, same transaction
// =============================================================================
$configB = 'TEST-DW-RM-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-DW-RM'");
    $serial = 'TEMP-DW-RM';
    $ramId = makeRamRow($pdo, $ramUuid, $serial, 'TEMP-DW-RM');
    makeConfig($pdo, $configB, json_encode([['uuid' => $ramUuid, 'quantity' => 1, 'serial_number' => $serial]]));

    // Simulate this unit having been dual-written on a prior add.
    $pdo->beginTransaction();
    $repo = new ConfigComponentRepository($pdo);
    $ccId = $repo->insert($configB, [
        'component_type' => 'ram', 'inventory_table' => 'raminventory', 'inventory_id' => $ramId,
        'spec_uuid' => $ramUuid, 'serial_number' => $serial,
    ], 1);
    $pdo->commit();

    $result = $builder->removeComponent($configB, 'ram', $ramUuid, $serial);
    check('flag on: removeComponent() succeeds', ($result['success'] ?? false) === true);

    $row = $pdo->query("SELECT removed_at FROM config_components WHERE id = $ccId")->fetch();
    check('flag on: config_components row tombstoned (removed_at set)', $row && $row['removed_at'] !== null);

    $rev = (int)$pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($configB))->fetchColumn();
    check('flag on: revision is 2 (1 from seeded add, 1 from this remove)', $rev === 2);

    $events = $pdo->query("SELECT event FROM config_events WHERE config_uuid = " . $pdo->quote($configB) . " ORDER BY revision")->fetchAll(PDO::FETCH_COLUMN);
    check('flag on: config_events shows add then remove', $events === ['add', 'remove']);

    $legacy = $pdo->query("SELECT ram_configuration FROM server_configurations WHERE config_uuid = " . $pdo->quote($configB))->fetchColumn();
    check('flag on: legacy JSON write still happened (component removed from JSON)', json_decode($legacy, true) === []);
} finally {
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configB));
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configB));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configB));
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-DW-RM'");
}

// =============================================================================
// C. Flag ON, add path: writer call + legacy-column write commit atomically
// (direct writer call — see file header NOTE on why addComponent() itself isn't
// driven here)
// =============================================================================
$configC = 'TEST-DW-ADD-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-DW-ADD'");
    $serial = 'TEMP-DW-ADD';
    $ramId = makeRamRow($pdo, $ramUuid, $serial, 'TEMP-DW-ADD');
    makeConfig($pdo, $configC, json_encode([]));

    $pdo->beginTransaction();
    // Legacy write analog: what updateServerConfigurationTable(...,'add',...) does.
    $pdo->prepare("UPDATE server_configurations SET ram_configuration = ? WHERE config_uuid = ?")
        ->execute([json_encode([['uuid' => $ramUuid, 'quantity' => 1, 'serial_number' => $serial]]), $configC]);
    ConfigComponentWriter::afterLegacyAdd(
        $pdo, $configC, 'ram', $ramUuid, $serial, null, 'raminventory', $ramId, 1, null
    );
    $pdo->commit();

    $live = $pdo->query("SELECT * FROM config_components WHERE config_uuid = " . $pdo->quote($configC) . " AND removed_at IS NULL")->fetchAll();
    check('add path: exactly 1 live config_components row after commit', count($live) === 1);
    check("add path: row's inventory_id matches the raminventory row", $live && (int)$live[0]['inventory_id'] === $ramId);

    $rev = (int)$pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($configC))->fetchColumn();
    check('add path: revision is 1', $rev === 1);

    $legacy = $pdo->query("SELECT ram_configuration FROM server_configurations WHERE config_uuid = " . $pdo->quote($configC))->fetchColumn();
    $legacyDecoded = json_decode($legacy, true);
    check('add path: legacy JSON write landed in the same commit', count($legacyDecoded) === 1 && $legacyDecoded[0]['uuid'] === $ramUuid);
} finally {
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configC));
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configC));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configC));
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-DW-ADD'");
}

// =============================================================================
// D. Induced repository failure rolls back BOTH the legacy write and the new row
// =============================================================================
$configD = 'TEST-DW-FAIL-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    makeConfig($pdo, $configD, json_encode([]));

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE server_configurations SET ram_configuration = ? WHERE config_uuid = ?")
        ->execute([json_encode([['uuid' => 'should-not-persist']]), $configD]);

    $threw = false;
    try {
        // inventory_table/inventory_id null -> ConfigComponentWriter's own fail-closed guard throws.
        ConfigComponentWriter::afterLegacyAdd(
            $pdo, $configD, 'ram', $ramUuid, 'X', null, null, null, 1, null
        );
    } catch (\Throwable $e) {
        $threw = true;
    }
    check('induced failure: writer throws', $threw);

    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }

    $legacyAfterRollback = $pdo->query("SELECT ram_configuration FROM server_configurations WHERE config_uuid = " . $pdo->quote($configD))->fetchColumn();
    check('induced failure: legacy JSON write rolled back too', json_decode($legacyAfterRollback, true) === []);

    $rows = (int)$pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = " . $pdo->quote($configD))->fetchColumn();
    check('induced failure: no config_components row leaked', $rows === 0);
} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configD));
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configD));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configD));
}

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
