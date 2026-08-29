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
        $dsn,
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (\Throwable $e) {
    // Reported by scratch_db_or_skip() below, uniformly with a stale-schema replica.
}
$pdo = scratch_db_or_skip($pdo, 'nested-transaction savepoint behaviour');

require_once $ROOT . '/core/models/server/ServerBuilder.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

$ramUuid = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c';
$builder = new ServerBuilder($pdo);

/**
 * U-D.3b: build one installed-RAM fixture.
 *
 * These fixtures used to persist the installed unit ONLY as a ram_configuration JSON
 * blob. That column is being retired and every reader now answers from
 * config_components, so a JSON-only fixture describes a configuration the code can no
 * longer see -- removeComponent() found nothing to remove and reported failure, and
 * deleteConfiguration()'s installed-components guard had nothing to refuse over.
 *
 * The fixture now writes what production writes: an inventory unit claimed by the
 * config (Status=2 + ServerUUID) and its config_components row. That is a STRICTER
 * setup than the blob it replaces -- it exercises the same store the live add path
 * fills, so these transaction-ownership assertions now run against a configuration
 * that is real to every reader rather than to one decoder.
 *
 * @return int the raminventory row id
 */
function nest_fixture(PDO $pdo, string $configUuid, string $ramUuid, string $serial, string $flag): int
{
    $pdo->exec("DELETE FROM raminventory WHERE Flag = " . $pdo->quote($flag));
    $pdo->prepare("INSERT INTO raminventory (UUID, SerialNumber, Status, ServerUUID, Flag) VALUES (?, ?, 2, ?, ?)")
        ->execute([$ramUuid, $serial, $configUuid, $flag]);
    $inventoryId = (int)$pdo->lastInsertId();

    $cols = [
        'config_uuid' => $configUuid, 'server_name' => 'TEST NEST', 'is_virtual' => 0,
        'configuration_status' => 1,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES ('
        . implode(',', array_map(fn($x) => ":$x", $f)) . ')')->execute($cols);

    $pdo->prepare("INSERT INTO config_components
            (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, serial_number)
            VALUES (?, 'ram', 'raminventory', ?, ?, ?)")
        ->execute([$configUuid, $inventoryId, $ramUuid, $serial]);

    return $inventoryId;
}

/** Live config_components rows for a config — the state the assertions care about. */
function nest_live_rows(PDO $pdo, string $configUuid): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM config_components WHERE config_uuid = ? AND removed_at IS NULL");
    $st->execute([$configUuid]);
    return (int)$st->fetchColumn();
}

// =============================================================================
// 1. removeComponent() nested inside an already-open outer transaction
// =============================================================================
$configUuid1 = 'TEST-NEST-RM-' . substr(md5(uniqid()), 0, 8);
try {
    nest_fixture($pdo, $configUuid1, $ramUuid, 'TEMP-NEST', 'TEMP-NEST-PROBE');

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

    // U-D.3b: assert against config_components, the store the removal actually
    // tombstones. Reading back the JSON column proved the rollback undid a write to a
    // column nothing reads; this proves it undid the one that decides what is installed.
    check('removeComponent nested: outer rollback restored the component row',
        nest_live_rows($pdo, $configUuid1) === 1);

} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configUuid1));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid1));
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-NEST-PROBE'");
}

// =============================================================================
// 2. deleteConfiguration() nested inside an already-open outer transaction
// =============================================================================
$configUuid2 = 'TEST-NEST-DEL-' . substr(md5(uniqid()), 0, 8);
try {
    nest_fixture($pdo, $configUuid2, $ramUuid, 'TEMP-NEST-2', 'TEMP-NEST-PROBE2');

    $pdo->beginTransaction(); // outer transaction, owned by the test

    // This fixture still HAS its RAM installed, so the 2026-07-21 guard refuses
    // an unforced delete. Assert that first, then force past it — this test is
    // about transaction ownership, and skipping the guard would mean the nested
    // path below never actually runs the delete.
    $guarded = $builder->deleteConfiguration($configUuid2);
    check('deleteConfiguration: refuses while components are installed',
        ($guarded['success'] ?? true) === false && ($guarded['reason'] ?? null) === 'components_installed');
    check('deleteConfiguration: refusal left the outer transaction alone', $pdo->inTransaction());

    $threw = false;
    $result = null;
    try {
        $result = $builder->deleteConfiguration($configUuid2, true);
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
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configUuid2));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid2));
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-NEST-PROBE2'");
}

// =============================================================================
// 3. Standalone behaviour unchanged: both methods still own + commit their own
//    transaction when called with no outer transaction open.
// =============================================================================
$configUuid3 = 'TEST-NEST-STANDALONE-' . substr(md5(uniqid()), 0, 8);
try {
    nest_fixture($pdo, $configUuid3, $ramUuid, 'TEMP-NEST-3', 'TEMP-NEST-PROBE3');

    check('standalone: no transaction open before call', !$pdo->inTransaction());
    $result = $builder->removeComponent($configUuid3, 'ram', $ramUuid, 'TEMP-NEST-3');
    check('standalone removeComponent: reports success', ($result['success'] ?? false) === true);
    check('standalone removeComponent: commits its own transaction (none left open)', !$pdo->inTransaction());

    $result2 = $builder->deleteConfiguration($configUuid3);
    check('standalone deleteConfiguration: reports success', ($result2['success'] ?? false) === true);
    check('standalone deleteConfiguration: commits its own transaction (none left open)', !$pdo->inTransaction());

} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configUuid3));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid3));
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-NEST-PROBE3'");
}

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
