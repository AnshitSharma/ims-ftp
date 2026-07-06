<?php
/**
 * config_component_repository_test.php — U-1.4 unit test.
 *
 * Proves ConfigComponentRepository (no callers yet; this test is its only exerciser):
 *   - insert() -> liveRows() -> tombstone() -> liveRows() lifecycle
 *   - revision increments 1, then 2 (one bump per write)
 *   - one config_events row per write, with the right event/component_type/component_id
 *   - insert() reactivates a previously-tombstoned physical unit rather than erroring
 *     (the ON DUPLICATE KEY UPDATE path — see the class docblock)
 *   - every write method throws RuntimeException when called outside a transaction
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

$dsn = $dbSocket
    ? "mysql:unix_socket=$dbSocket;dbname=$dbName;charset=utf8mb4"
    : "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

$pdo = new PDO(
    $dsn, $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once $ROOT . '/core/models/config/ConfigComponentRepository.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

$repo = new ConfigComponentRepository($pdo);
$configUuid = 'TEST-CCR-' . substr(md5(uniqid()), 0, 8);
$inventoryTable = 'raminventory';
$inventoryId = random_int(100000, 999999); // synthetic; no real inventory row needed, FK is soft

try {
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configUuid));
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configUuid));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid));
    $pdo->prepare('INSERT INTO server_configurations (config_uuid, server_name, is_virtual, configuration_status) VALUES (?, ?, 0, 0)')
        ->execute([$configUuid, 'CCR TEST']);

    // ---- writers throw outside a transaction --------------------------------
    $threwInsert = false;
    try {
        $repo->insert($configUuid, ['component_type' => 'ram', 'inventory_table' => $inventoryTable, 'inventory_id' => $inventoryId, 'spec_uuid' => 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c'], 1);
    } catch (RuntimeException $e) {
        $threwInsert = true;
    }
    check('insert() throws RuntimeException outside a transaction', $threwInsert);

    $threwBump = false;
    try {
        $repo->bumpRevision($configUuid, 'transition', null, 1);
    } catch (RuntimeException $e) {
        $threwBump = true;
    }
    check('bumpRevision() throws RuntimeException outside a transaction', $threwBump);

    // ---- insert -> liveRows -> tombstone -> liveRows lifecycle ---------------
    $pdo->beginTransaction();
    $id = $repo->insert($configUuid, [
        'component_type' => 'ram', 'inventory_table' => $inventoryTable, 'inventory_id' => $inventoryId,
        'spec_uuid' => 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c', 'serial_number' => 'CCR-TEST-1',
    ], 7);
    $pdo->commit();
    check('insert() returns a positive id', $id > 0);

    $live = $repo->liveRows($configUuid);
    check('liveRows() shows exactly 1 row after insert', count($live) === 1);
    check('live row has removed_at IS NULL', $live[0]['removed_at'] === null);
    check('live row inventory_id matches', (int)$live[0]['inventory_id'] === $inventoryId);

    $revAfterInsert = (int)$pdo->query('SELECT revision FROM server_configurations WHERE config_uuid = ' . $pdo->quote($configUuid))->fetchColumn();
    check('revision is 1 after insert', $revAfterInsert === 1);

    $events = $pdo->query('SELECT * FROM config_events WHERE config_uuid = ' . $pdo->quote($configUuid) . ' ORDER BY revision')->fetchAll(PDO::FETCH_ASSOC);
    check('exactly 1 config_events row after insert', count($events) === 1);
    check("event row's event = 'add'", ($events[0]['event'] ?? null) === 'add');
    check("event row's component_type = 'ram'", ($events[0]['component_type'] ?? null) === 'ram');
    check('event row revision matches server_configurations.revision', (int)$events[0]['revision'] === 1);

    $found = $repo->findLive($configUuid, 'ram', 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c', 'CCR-TEST-1');
    check('findLive() finds the row by (config, type, spec, serial)', $found !== null && (int)$found['id'] === $id);

    $threwTombstone = false;
    try {
        $repo->tombstone($id, 7);
    } catch (RuntimeException $e) {
        $threwTombstone = true;
    }
    check('tombstone() throws RuntimeException outside a transaction', $threwTombstone);

    $pdo->beginTransaction();
    $repo->tombstone($id, 7);
    $pdo->commit();

    $liveAfterRemove = $repo->liveRows($configUuid);
    check('liveRows() shows 0 rows after tombstone', count($liveAfterRemove) === 0);

    $revAfterRemove = (int)$pdo->query('SELECT revision FROM server_configurations WHERE config_uuid = ' . $pdo->quote($configUuid))->fetchColumn();
    check('revision is 2 after tombstone', $revAfterRemove === 2);

    $eventsAfterRemove = $pdo->query('SELECT * FROM config_events WHERE config_uuid = ' . $pdo->quote($configUuid) . ' ORDER BY revision')->fetchAll(PDO::FETCH_ASSOC);
    check('exactly 2 config_events rows after tombstone', count($eventsAfterRemove) === 2);
    check("second event row's event = 'remove'", ($eventsAfterRemove[1]['event'] ?? null) === 'remove');

    // ---- re-add after tombstone: insert() must reactivate, not error ---------
    $pdo->beginTransaction();
    $reactivatedId = $repo->insert($configUuid, [
        'component_type' => 'ram', 'inventory_table' => $inventoryTable, 'inventory_id' => $inventoryId,
        'spec_uuid' => 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c', 'serial_number' => 'CCR-TEST-1',
    ], 7);
    $pdo->commit();

    check('insert() after tombstone reuses the SAME row id (uq_inventory_once)', $reactivatedId === $id);
    $liveAfterReactivate = $repo->liveRows($configUuid);
    check('liveRows() shows 1 row again after reactivation', count($liveAfterReactivate) === 1);

    $revAfterReactivate = (int)$pdo->query('SELECT revision FROM server_configurations WHERE config_uuid = ' . $pdo->quote($configUuid))->fetchColumn();
    check('revision is 3 after reactivation', $revAfterReactivate === 3);

} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configUuid));
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configUuid));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid));
}

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
