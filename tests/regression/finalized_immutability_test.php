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
// Credential resolution is shared, not copy-pasted: scratch_db_password()
// honours GOLDEN_DB_PASS *and* GOLDEN_DB_PASS_FILE. The local copy this
// replaced honoured only the former, so the documented pass-file fixture
// silently reduced this suite to a self-skip. See _scratch_db.php.
require_once __DIR__ . '/_scratch_db.php';
$dbPass = scratch_db_password();

putenv("DB_HOST=$dbHost"); putenv("DB_NAME=$dbName");
putenv("DB_USER=$dbUser"); putenv("DB_PASS=$dbPass");

require_once __DIR__ . '/_scratch_db.php';
$pdo = null;
try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (\Throwable $e) {
    // Reported by scratch_db_or_skip() below, uniformly with a stale-schema replica.
}
$pdo = scratch_db_or_skip($pdo, 'finalized-config immutability');

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

    // U-D.3c: the installed RAM is seeded as a config_components ROW, not as a
    // ram_configuration blob. The nine JSON columns are dropped, so naming them here
    // would kill this fixture; and the row is what the code under test actually reads,
    // so this is the stricter fixture as well as the surviving one.
    $configUuid = 'TEST-FINALIZED-' . substr(md5(uniqid()), 0, 8);
    $cols = [
        'config_uuid' => $configUuid, 'server_name' => 'TEST FINALIZED', 'is_virtual' => 0,
        'configuration_status' => 3, 'motherboard_uuid' => null, 'chassis_uuid' => null,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')
        ->execute($cols);

    $ramInvId = (int)$pdo->query("SELECT ID FROM raminventory WHERE UUID = " . $pdo->quote($ramUuid)
        . " AND SerialNumber = 'TEMP-FIN' LIMIT 1")->fetchColumn();
    if ($ramInvId) {
        $pdo->prepare("INSERT INTO config_components
                (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, serial_number)
                VALUES (?, 'ram', 'raminventory', ?, ?, 'TEMP-FIN')")
            ->execute([$configUuid, $ramInvId, $ramUuid]);
    }

    $builder = new ServerBuilder($pdo);

    // ---- addComponent on a finalized config ----------------------------
    // U-D.3c: immutability is now asserted against config_components, the store a
    // refused add or remove would actually have written. Watching ram_configuration was
    // a proxy for that, and since U-D.3a nothing writes it at all — so that check would
    // now pass however badly the command behaved. A live-row count cannot go vacuous.
    $liveRows = function () use ($pdo, $configUuid) {
        return (int)$pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = "
            . $pdo->quote($configUuid) . " AND removed_at IS NULL")->fetchColumn();
    };
    $before = $liveRows();
    $beforeStatus = $pdo->query("SELECT Status FROM raminventory WHERE UUID = " . $pdo->quote($ramUuid) . " AND Flag = 'TEMP-PROBE'")->fetch();

    // REPOINTED 2026-08-30 (P9/U-D.2): ServerBuilder::addComponent() is deleted.
    // AddComponentCommand is the add path in production and always was the one
    // this assertion should have been made through — the old seam had stopped
    // being the code that runs. It refuses by throwing CommandFailed rather than
    // by returning a {success:false} array, so the shape of the check changes
    // while the guarantee does not.
    require_once $ROOT . '/core/models/commands/BaseCommand.php';
    require_once $ROOT . '/core/models/commands/AddComponentCommand.php';
    $addErrorType = null;
    $addThrew = false;
    try {
        (new AddComponentCommand($pdo, $configUuid, 'ram', $ramUuid, ['quantity' => 1, 'serial_number' => 'TEMP-FIN-2']))->execute();
    } catch (CommandFailed $e) {
        $addThrew = true;
        $addErrorType = $e->errorType;
    }
    check('AddComponentCommand rejects finalized config', $addThrew);
    check('add error_type=config_finalized (NULL status_v2 falls back to the legacy int rule)', $addErrorType === 'config_finalized');
    check('add: no PDO transaction left open', !$pdo->inTransaction());

    check('add: no component row written', $liveRows() === $before);

    $afterStatus = $pdo->query("SELECT Status FROM raminventory WHERE UUID = " . $pdo->quote($ramUuid) . " AND Flag = 'TEMP-PROBE'")->fetch();
    if ($insertedTempInventory) {
        check('add: inventory Status unchanged', $afterStatus && $afterStatus['Status'] == $beforeStatus['Status']);
    }

    // ---- removeComponent on a finalized config --------------------------
    $before2 = $liveRows();

    $removeResult = $builder->removeComponent($configUuid, 'ram', $ramUuid, 'TEMP-FIN');
    check('removeComponent rejects finalized config', ($removeResult['success'] ?? true) === false);
    check('removeComponent error_type=config_finalized', ($removeResult['error_type'] ?? null) === 'config_finalized');
    check('removeComponent: no PDO transaction left open', !$pdo->inTransaction());

    check('removeComponent: no component row tombstoned', $liveRows() === $before2);

} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    if (isset($configUuid)) {
        $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configUuid));
    }
    if ($configUuid) {
        $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid));
    }
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-PROBE'");
}

// ---- static check: the immutability gate has ONE implementation --------
// REPLACED 2026-08-30 (P9/U-D.4). This used to count two TEMP-GUARD(U-0.2)
// markers in ServerBuilder — the hand-written pre-StateGuard guard that sat at
// the add and remove sites while STATE_MACHINE_ENABLED was still being rolled
// out. U-D.4 deleted both along with the flag, leaving StateGuard as the sole
// gate. Counting markers that no longer exist proves nothing, so the assertion
// now pins the property that replaced them, which is the stronger one: there is
// exactly one implementation of the rule, and the duplicate is really gone.
$src = file_get_contents($ROOT . '/core/models/server/ServerBuilder.php');
// Comment lines are excluded: ServerBuilder.php:628 records where the guard used
// to sit and why it went, which is worth keeping. Anything outside a comment
// would be a surviving second implementation of the rule.
$liveSrc = implode("\n", array_filter(
    explode("\n", $src),
    function ($l) {
        $t = ltrim($l);
        return $t !== '' && strpos($t, '//') !== 0 && strpos($t, '*') !== 0 && strpos($t, '/*') !== 0 && strpos($t, '#') !== 0;
    }
));
check('no live TEMP-GUARD(U-0.2) immutability check survives in ServerBuilder',
    strpos($liveSrc, 'TEMP-GUARD') === false);
check('ServerBuilder defers to StateGuard rather than re-implementing the rule',
    strpos($liveSrc, 'StateGuard::checkMutation(') !== false);
$guardSrc = file_get_contents($ROOT . '/core/models/state/StateGuard.php');
check('StateGuard::checkMutation() is the single gate, and reads no rollout flag',
    strpos($guardSrc, 'function checkMutation(') !== false
    && strpos($guardSrc, 'getenv(') === false
    && strpos($guardSrc, 'function mode(') === false);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
