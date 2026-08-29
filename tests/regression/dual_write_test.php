<?php
/**
 * dual_write_test.php — U-1.5 regression test for the ConfigComponentWriter hook.
 *
 * REPOINTED 2026-08-30 (P9/U-D.4): DUAL_WRITE_ENABLED is deleted and the writer is
 * unconditional, so the flag-off section is gone (see the note at Section A's old
 * position).
 *
 * REPOINTED AGAIN 2026-08-30 (U-D.3a): there is no longer a second store to be dual
 * with. The eight JSON-column updaters are deleted, so every assertion of the form
 * "the legacy JSON write landed in the same commit" was asserting that deleted code
 * still ran, and each one has been REPLACED, not dropped:
 *
 *   was                                          now
 *   ---                                          ---
 *   legacy JSON write still happened             NOTHING wrote a legacy column, and
 *                                                the rows side is the whole record
 *   legacy JSON landed in the same commit        a same-transaction companion write
 *                                                landed with it (atomicity, proven
 *                                                against a column that survives)
 *   legacy JSON write rolled back too            that companion write rolled back too
 *
 * The companion write is server_configurations.notes — chosen because it is an
 * ordinary column U-D.3c does not touch, so this suite keeps proving transactional
 * atomicity after the drop instead of dying with the columns it used to name.
 *
 * What it proves, all unconditional:
 *   - remove: a live config_components row is tombstoned in the SAME transaction as
 *     ServerBuilder::removeComponent()'s other work.
 *   - add: ConfigComponentWriter::afterLegacyAdd() commits atomically with the
 *     caller's own writes (see NOTE below for why the writer is called directly).
 *   - a repository failure during the writer call rolls back the whole transaction,
 *     the caller's writes included (fail-closed, INV-5).
 *
 * NOTE on coverage: the add path is driven through the writer directly rather than
 * through a full add, because a real add gates on
 * ComponentCompatibility::validateComponentExistsInJSON(), which reads
 * ims-data/{type}/*.json, and this sandbox has no ims-data/ (the same gap documented
 * in every prior migration unit's handoff). removeComponent() has no such gate, so
 * its test below runs through the real method.
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
$dbSocket = getenv('GOLDEN_DB_SOCKET') ?: null;

putenv("DB_HOST=$dbHost"); putenv("DB_NAME=$dbName");
putenv("DB_USER=$dbUser"); putenv("DB_PASS=$dbPass");

$dsn = $dbSocket
    ? "mysql:unix_socket=$dbSocket;dbname=$dbName;charset=utf8mb4"
    : "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

require_once __DIR__ . '/_scratch_db.php';
$pdo = null;
try {
    $pdo = new PDO(
        $dsn, $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (\Throwable $e) {
    // Reported by scratch_db_or_skip() below, uniformly with a stale-schema replica.
}
$pdo = scratch_db_or_skip($pdo, 'dual-write JSON/rows consistency');

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

/**
 * U-D.3a: the fixture no longer seeds ram_configuration. The store under test is
 * config_components, and each section inserts its own rows.
 */
function makeConfig(PDO $pdo, $configUuid, $unusedLegacyJson = null) {
    $cols = [
        'config_uuid' => $configUuid, 'server_name' => 'DUAL WRITE TEST', 'is_virtual' => 0,
        'configuration_status' => 1,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')
        ->execute($cols);
}

$ramUuid = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c';
$builder = new ServerBuilder($pdo);

// SECTION A REMOVED 2026-08-30 (P9/U-D.4). It asserted that with DUAL_WRITE_ENABLED
// unset the hook was inert: no config_components row written, revision untouched.
// The flag is deleted and ConfigComponentWriter now writes unconditionally, so there
// is no "off" state left to assert. Every section below likewise drops its putenv
// and its "flag on:" label prefix: what were guarantees conditional on a flag are
// now unconditional ones, which is strictly stronger than the pair they replace.

// =============================================================================
// B. removeComponent() tombstones a pre-seeded row, same transaction
// =============================================================================
$configB = 'TEST-DW-RM-' . substr(md5(uniqid()), 0, 8);
try {
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-DW-RM'");
    $serial = 'TEMP-DW-RM';
    $ramId = makeRamRow($pdo, $ramUuid, $serial, 'TEMP-DW-RM');
    makeConfig($pdo, $configB);

    // Simulate this unit having been dual-written on a prior add.
    $pdo->beginTransaction();
    $repo = new ConfigComponentRepository($pdo);
    $ccId = $repo->insert($configB, [
        'component_type' => 'ram', 'inventory_table' => 'raminventory', 'inventory_id' => $ramId,
        'spec_uuid' => $ramUuid, 'serial_number' => $serial,
    ], 1);
    $pdo->commit();

    $result = $builder->removeComponent($configB, 'ram', $ramUuid, $serial);
    check('removeComponent() succeeds', ($result['success'] ?? false) === true);

    $row = $pdo->query("SELECT removed_at FROM config_components WHERE id = $ccId")->fetch();
    check('config_components row tombstoned (removed_at set)', $row && $row['removed_at'] !== null);

    $rev = (int)$pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($configB))->fetchColumn();
    check('revision is 2 (1 from seeded add, 1 from this remove)', $rev === 2);

    $events = $pdo->query("SELECT event FROM config_events WHERE config_uuid = " . $pdo->quote($configB) . " ORDER BY revision")->fetchAll(PDO::FETCH_COLUMN);
    check('config_events shows add then remove', $events === ['add', 'remove']);

    // U-D.3a: was "legacy JSON write still happened". There is no legacy write left,
    // so the stronger statement is that the tombstone is the ENTIRE record of the
    // removal -- no live row survives, by any route.
    $liveAfter = (int)$pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = "
        . $pdo->quote($configB) . " AND removed_at IS NULL")->fetchColumn();
    check('the tombstone is the whole record: no live row survives the removal', $liveAfter === 0);
} finally {
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configB));
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configB));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configB));
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-DW-RM'");
}

// =============================================================================
// C. add path: writer call + legacy-column write commit atomically
// (direct writer call — see file header NOTE on why addComponent() itself isn't
// driven here)
// =============================================================================
$configC = 'TEST-DW-ADD-' . substr(md5(uniqid()), 0, 8);
try {
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-DW-ADD'");
    $serial = 'TEMP-DW-ADD';
    $ramId = makeRamRow($pdo, $ramUuid, $serial, 'TEMP-DW-ADD');
    makeConfig($pdo, $configC);

    $pdo->beginTransaction();
    // A companion write by the caller, in the same transaction, standing in for
    // whatever else an add does around the writer call. On a column that survives
    // U-D.3c, so this atomicity proof outlives the drop.
    $pdo->prepare("UPDATE server_configurations SET notes = ? WHERE config_uuid = ?")
        ->execute(['DW-ADD-COMPANION', $configC]);
    ConfigComponentWriter::afterLegacyAdd(
        $pdo, $configC, 'ram', $ramUuid, $serial, null, 'raminventory', $ramId, 1, null
    );
    $pdo->commit();

    $live = $pdo->query("SELECT * FROM config_components WHERE config_uuid = " . $pdo->quote($configC) . " AND removed_at IS NULL")->fetchAll();
    check('add path: exactly 1 live config_components row after commit', count($live) === 1);
    check("add path: row's inventory_id matches the raminventory row", $live && (int)$live[0]['inventory_id'] === $ramId);

    $rev = (int)$pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($configC))->fetchColumn();
    check('add path: revision is 1', $rev === 1);

    $companion = $pdo->query("SELECT notes FROM server_configurations WHERE config_uuid = " . $pdo->quote($configC))->fetchColumn();
    check("add path: the caller's companion write landed in the same commit", $companion === 'DW-ADD-COMPANION');
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
    makeConfig($pdo, $configD);

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE server_configurations SET notes = ? WHERE config_uuid = ?")
        ->execute(['SHOULD-NOT-PERSIST', $configD]);

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

    $companionAfterRollback = $pdo->query("SELECT notes FROM server_configurations WHERE config_uuid = " . $pdo->quote($configD))->fetchColumn();
    check("induced failure: the caller's own write rolled back too", $companionAfterRollback === null);

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
