<?php
/**
 * state_guard_test.php — U-SM.4 regression test, rewritten 2026-08-30 for U-D.4.
 *
 * The original suite spent two thirds of its assertions on STATE_MACHINE_ENABLED's
 * off and shadow modes — the flag, the divergence JSONL, and the legacy TEMP-GUARD
 * the shadow verdict was compared against. U-D.4 deleted all three, so those
 * assertions could only ever fail from here on.
 *
 * What replaces them is strictly TIGHTER, per this migration's standing rule that
 * a repaired assertion must never end up looser than the one it replaces:
 *
 *   - The old suite sampled THREE status_v2 values (maintenance, deployed, and a
 *     NULL fallback pair). This one enumerates ALL EIGHT values of the column's
 *     enum and pins the verdict for every one, so a status added to the enum
 *     without a decision here shows up as a failure rather than as silence.
 *   - The old suite asserted the enforce-mode verdict. There is no mode any more,
 *     so the same assertions are now unconditional — a strictly stronger claim
 *     than "when the flag says enforce".
 *   - The end-to-end half moves off ServerBuilder::addComponent()/removeComponent()
 *     (deleted by U-D.2) and onto AddComponentCommand / RemoveComponentCommand,
 *     which are the real mutation paths in production. That closes a gap the old
 *     suite had: it proved the guard through a code path that production had
 *     already stopped using.
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
// honours GOLDEN_DB_PASS *and* GOLDEN_DB_PASS_FILE. See _scratch_db.php.
require_once __DIR__ . '/_scratch_db.php';
$dbPass = scratch_db_password();

putenv("DB_HOST=$dbHost"); putenv("DB_NAME=$dbName");
putenv("DB_USER=$dbUser"); putenv("DB_PASS=$dbPass");

require_once $ROOT . '/core/models/state/StateGuard.php';
require_once $ROOT . '/core/models/server/ServerBuilder.php';
require_once $ROOT . '/core/models/commands/BaseCommand.php';
require_once $ROOT . '/core/models/commands/AddComponentCommand.php';
require_once $ROOT . '/core/models/commands/RemoveComponentCommand.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

echo "state_guard_test (U-SM.4, post-U-D.4)\n";

$pdo = null;
try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "  SKIP  no scratch DB reachable: " . $e->getMessage() . "\n";
    exit(0);
}

$ramUuid = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c'; // DDR4 RDIMM, ims-data/ram/ram_detail.json
$configUuids = [];

/**
 * U-D.3c: the nine legacy JSON columns are dropped, so a fixture that names them dies
 * with "Unknown column" the moment the drop runs. $ramConfigJson is kept in the
 * signature and ignored — every caller passes it, and StateGuard's subject is
 * status_v2, not what the configuration contains.
 */
function makeConfig(PDO $pdo, $statusV2, $legacyInt, $ramConfigJson = null) {
    global $configUuids;
    $configUuid = 'TEST-SG-' . substr(md5(uniqid('', true)), 0, 8);
    $configUuids[] = $configUuid;
    $cols = [
        'config_uuid' => $configUuid, 'server_name' => 'TEST STATEGUARD', 'is_virtual' => 0,
        'configuration_status' => $legacyInt, 'status_v2' => $statusV2,
        'motherboard_uuid' => null, 'chassis_uuid' => null,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')
        ->execute($cols);
    return $configUuid;
}

/** Run a command and reduce it to an error_type (or null on success). */
function errorTypeOf(callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (CommandFailed $e) {
        return $e->errorType;
    }
}

try {
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-PROBE-SG'");
    $pdo->prepare("INSERT INTO raminventory (UUID, SerialNumber, Status, Flag) VALUES (?, 'TEMP-SG', 1, 'TEMP-PROBE-SG')")
        ->execute([$ramUuid]);

    $ramConfigJson = json_encode([['uuid' => $ramUuid, 'quantity' => 1, 'serial_number' => 'TEMP-SG']]);

    // ---- 1. checkMutation() over EVERY value of the status_v2 enum ----------
    // The column is enum('draft','building','validating','validated','finalized',
    // 'deployed','maintenance','retired'). Three of those permit mutation; the
    // other five must all refuse with the SAME error_type. Enumerated rather than
    // sampled so that adding a ninth status without deciding its verdict fails here.
    $expected = [
        'draft'       => null,
        'building'    => null,
        'maintenance' => null,
        'validating'  => 'config_immutable',
        'validated'   => 'config_immutable',
        'finalized'   => 'config_immutable',
        'deployed'    => 'config_immutable',
        'retired'     => 'config_immutable',
    ];
    foreach ($expected as $status => $wantType) {
        // legacy int deliberately set to 3 ("would block") for the ALLOWED statuses
        // too, so a pass can only come from status_v2 winning, never from the
        // fallback happening to agree.
        $verdict = StateGuard::checkMutation($pdo, ['status_v2' => $status, 'configuration_status' => 3]);
        $gotType = $verdict === null ? null : ($verdict['error_type'] ?? '(no error_type)');
        check(
            "status_v2=$status -> " . ($wantType === null ? 'mutation ALLOWED' : "blocked ($wantType)")
                . ($wantType === null ? ' even with legacy int=3' : ''),
            $gotType === $wantType
        );
    }

    // Every status the enum defines is decided above — pin the count so the
    // table cannot silently fall behind the schema.
    // SHOW COLUMNS, not information_schema: the app DB user is denied the latter
    // (see database/seeders — a guard built on it fails open and reports success
    // while checking nothing), and this suite should behave the same under either user.
    $enumRow = null;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM server_configurations LIKE 'status_v2'")->fetch(PDO::FETCH_ASSOC);
        $enumRow = $col['Type'] ?? null;
    } catch (Throwable $e) {
        $enumRow = null;
    }
    if (is_string($enumRow) && preg_match_all("/'([^']+)'/", $enumRow, $m)) {
        $schemaStatuses = $m[1];
        sort($schemaStatuses);
        $decided = array_keys($expected);
        sort($decided);
        check(
            'every status_v2 the schema defines has a decided verdict above (' . count($schemaStatuses) . ' values)',
            $schemaStatuses === $decided
        );
    } else {
        check('status_v2 enum readable from the schema', false);
    }

    // ---- 2. NULL status_v2 falls back to the legacy int rule ----------------
    check(
        'status_v2=NULL + legacy int=3 blocks as config_finalized (fallback)',
        (StateGuard::checkMutation($pdo, ['status_v2' => null, 'configuration_status' => 3])['error_type'] ?? null) === 'config_finalized'
    );
    foreach ([0, 1, 2] as $legacyInt) {
        check(
            "status_v2=NULL + legacy int=$legacyInt allows (fallback)",
            StateGuard::checkMutation($pdo, ['status_v2' => null, 'configuration_status' => $legacyInt]) === null
        );
    }
    check(
        'status_v2 absent entirely behaves as NULL, not as an allow-by-default',
        (StateGuard::checkMutation($pdo, ['configuration_status' => 3])['error_type'] ?? null) === 'config_finalized'
    );

    // ---- 3. end to end through the REAL mutation paths ----------------------
    // AddComponentCommand / RemoveComponentCommand are what production runs.
    $deployedCfg = makeConfig($pdo, 'deployed', 3, $ramConfigJson);
    check(
        'AddComponentCommand refused on a deployed config (config_immutable)',
        errorTypeOf(fn() => (new AddComponentCommand(
            $pdo, $deployedCfg, 'ram', $ramUuid, ['quantity' => 1, 'serial_number' => 'TEMP-SG-2']
        ))->execute()) === 'config_immutable'
    );
    check('no PDO transaction left open after the refused add', !$pdo->inTransaction());

    check(
        'RemoveComponentCommand refused on a deployed config (config_immutable)',
        errorTypeOf(fn() => (new RemoveComponentCommand(
            $pdo, $deployedCfg, 'ram', $ramUuid, 'TEMP-SG'
        ))->execute()) === 'config_immutable'
    );
    check('no PDO transaction left open after the refused remove', !$pdo->inTransaction());

    // The refusal must be a refusal, not a partial write.
    $rev = $pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($deployedCfg))->fetchColumn();
    check('a refused mutation bumps no revision', (int)$rev === 0);
    $rows = $pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = " . $pdo->quote($deployedCfg))->fetchColumn();
    check('a refused mutation writes no config_components row', (int)$rows === 0);

    // A maintenance config is mutable — the case that motivated StateGuard in the
    // first place, since its legacy int mapping is 3 and the old rule blocked it.
    $maintCfg = makeConfig($pdo, 'maintenance', 3, null);
    $maintErr = errorTypeOf(fn() => (new AddComponentCommand(
        $pdo, $maintCfg, 'ram', $ramUuid, ['quantity' => 1, 'serial_number' => 'TEMP-SG']
    ))->execute());
    check(
        'AddComponentCommand is NOT blocked by state on a maintenance config (legacy int=3 does not win)',
        $maintErr !== 'config_immutable' && $maintErr !== 'config_finalized'
    );
    check('no PDO transaction left open after the maintenance add', !$pdo->inTransaction());

} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    foreach ($configUuids as $cfg) {
        $q = $pdo->quote($cfg);
        $pdo->exec("DELETE FROM config_components WHERE config_uuid = $q");
        $pdo->exec("DELETE FROM config_events WHERE config_uuid = $q");
        $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = $q");
    }
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-PROBE-SG'");
}

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
