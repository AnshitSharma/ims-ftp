<?php
/**
 * state_machine_unit.php — focused unit test for StateMachine (U-SM.3).
 * Standalone: builds its own throwaway scratch DB (not the golden compat DB
 * — this is about the transition substrate, needs none of ims-data).
 * Covers: assertConfigTransition (allowed/denied-by-permission/no-such-edge),
 * applyConfigTransition (status_v2+legacy+revision+event, bad-value throws,
 * no-transaction throws), assertInventoryTransition (allowed + failed->available
 * denied), applyInventoryTransition (status_v2+legacy Status together).
 * Exit 0 = all pass; exit 1 = a failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT   = dirname(__DIR__);
$dbHost = getenv('SM_TEST_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('SM_TEST_DB_NAME') ?: 'ims_scratch_smunit';
$dbUser = getenv('SM_TEST_DB_USER') ?: 'root';
// Credential resolution is shared, not local (2026-08-24, part two).
// test_db_password() applies ONE documented precedence -- {PREFIX}_PASS, then
// {PREFIX}_PASS_FILE, then '' -- to every env-var family in this tree. This file
// previously read SM_TEST_DB_PASS and nothing else, so the project's own
// "password in a file" fixture convention handed it an empty string here while
// working correctly for the GOLDEN_DB_* suites next door. Note the prefix stays
// SM_TEST_DB: this suite must never point at ims_compat_golden (see the header
// and tests/MANIFEST.md 3).
require_once __DIR__ . '/regression/_scratch_db.php';
$dbPass = test_db_password('SM_TEST_DB');

// Guarded: an unreachable/unauthorised server is an environment fact, not a
// StateMachine defect. Say it once, in the marker line run_tests.php
// understands, and exit 0 having claimed nothing.
try {
    $bootstrapPdo = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $bootstrapPdo->exec("DROP DATABASE IF EXISTS `$dbName`");
    $bootstrapPdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (\Throwable $e) {
    test_skip_suite(
        'StateMachine transition substrate (U-SM.3)',
        "cannot provision the throwaway fixture DB '$dbName' on '$dbHost' ("
            . $e->getMessage() . ') -- set SM_TEST_DB_HOST/_NAME/_USER/_PASS or SM_TEST_DB_PASS_FILE'
    );
}

require_once $ROOT . '/core/models/state/StatusMap.php';
require_once $ROOT . '/core/models/state/StateMachine.php';
require_once $ROOT . '/core/models/config/ConfigComponentRepository.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// ---------------------------------------------------------------------------
// Schema: minimal but real column names/types, matching the actual U-SM.1/
// U-SM.2/U-1.3 seeders closely enough for StateMachine to operate correctly.
// ---------------------------------------------------------------------------
$pdo->exec("
    CREATE TABLE server_configurations (
        config_uuid CHAR(36) NOT NULL PRIMARY KEY,
        configuration_status INT NOT NULL DEFAULT 0,
        status_v2 ENUM('draft','building','validating','validated','finalized','deployed','maintenance','retired') NULL,
        revision INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NULL
    )
");
$pdo->exec("
    CREATE TABLE config_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        config_uuid CHAR(36) NOT NULL,
        revision INT UNSIGNED NOT NULL,
        event ENUM('add','remove','replace','transition','backfill','delete') NOT NULL,
        component_type VARCHAR(16) DEFAULT NULL,
        component_id BIGINT UNSIGNED DEFAULT NULL,
        actor INT NOT NULL DEFAULT 0,
        payload JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_config_rev (config_uuid, revision)
    )
");
$pdo->exec("
    CREATE TABLE config_status_transitions (
        from_status ENUM('draft','building','validating','validated','finalized','deployed','maintenance','retired') NOT NULL,
        to_status   ENUM('draft','building','validating','validated','finalized','deployed','maintenance','retired') NOT NULL,
        required_permission VARCHAR(64) NOT NULL,
        requires_validation ENUM('none','full') NOT NULL,
        PRIMARY KEY (from_status, to_status)
    )
");
$pdo->exec("INSERT INTO config_status_transitions VALUES
    ('draft','building','server.edit','none'),
    ('building','validating','server.edit','none'),
    ('validating','validated','SYSTEM','full')
");

$pdo->exec("
    CREATE TABLE cpuinventory (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        UUID CHAR(36), SerialNumber VARCHAR(64) NULL, ServerUUID CHAR(36) NULL,
        Status INT NOT NULL DEFAULT 1,
        status_v2 ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL
    )
");
$pdo->exec("
    CREATE TABLE inventory_status_transitions (
        from_status ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NOT NULL,
        to_status   ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NOT NULL,
        PRIMARY KEY (from_status, to_status)
    )
");
$pdo->exec("INSERT INTO inventory_status_transitions VALUES ('available','reserved'), ('maintenance','available')");
// Deliberately NOT inserting ('failed','available') — that's the whole point of test 8.

// Minimal ACL schema (mirrors ACL::loadUserPermissions's JOIN exactly).
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY)");
$pdo->exec("CREATE TABLE roles (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50))");
$pdo->exec("CREATE TABLE user_roles (user_id INT, role_id INT)");
$pdo->exec("CREATE TABLE permissions (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100))");
$pdo->exec("CREATE TABLE role_permissions (role_id INT, permission_id INT, granted TINYINT(1) DEFAULT 1)");
$pdo->exec("INSERT INTO users (id) VALUES (1), (2)"); // user 1 gets server.edit; user 2 gets nothing
$pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'editor')");
$pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES (1, 1)");
$pdo->exec("INSERT INTO permissions (id, name) VALUES (1, 'server.edit')");
$pdo->exec("INSERT INTO role_permissions (role_id, permission_id, granted) VALUES (1, 1, 1)");

require_once $ROOT . '/core/auth/ACL.php';

// ---------------------------------------------------------------------------
$configUuid = 'cfg-0000-0000-0000-000000000001';
$pdo->prepare("INSERT INTO server_configurations (config_uuid, configuration_status, status_v2, revision) VALUES (?, 2, 'building', 0)")
    ->execute([$configUuid]);

echo "--- assertConfigTransition ---\n";
$r = StateMachine::assertConfigTransition($pdo, $configUuid, 'validating', 1);
check("1. building->validating, user WITH server.edit -> allowed", $r['allowed'] === true && $r['requires_validation'] === false);

$r = StateMachine::assertConfigTransition($pdo, $configUuid, 'validating', 2);
check("2. building->validating, user WITHOUT server.edit -> denied", $r['allowed'] === false);

$r = StateMachine::assertConfigTransition($pdo, $configUuid, 'finalized', 1);
check("3. building->finalized (no such edge) -> denied", $r['allowed'] === false && strpos($r['reason'], 'no such transition') !== false);

echo "--- applyConfigTransition ---\n";
$pdo->beginTransaction();
$newRevision = StateMachine::applyConfigTransition($pdo, $configUuid, 'validating', 7);
$pdo->commit();

$row = $pdo->prepare("SELECT status_v2, configuration_status, revision FROM server_configurations WHERE config_uuid = ?");
$row->execute([$configUuid]);
$row = $row->fetch();
check("4a. status_v2 written", $row['status_v2'] === 'validating');
check("4b. legacy configuration_status mapped correctly (validating->2)", (int)$row['configuration_status'] === StatusMap::CONFIG_V2_TO_LEGACY['validating']);
check("4c. revision bumped to 1", (int)$row['revision'] === 1 && $newRevision === 1);

$events = $pdo->prepare("SELECT event, actor, revision FROM config_events WHERE config_uuid = ?");
$events->execute([$configUuid]);
$events = $events->fetchAll();
check("4d. exactly one config_events row appended", count($events) === 1);
check("4e. event is 'transition' with the right actor/revision", $events[0]['event'] === 'transition' && (int)$events[0]['actor'] === 7 && (int)$events[0]['revision'] === 1);

echo "--- error paths ---\n";
$threw = false;
$pdo->beginTransaction();
try { StateMachine::applyConfigTransition($pdo, $configUuid, 'not_a_real_status'); }
catch (InvalidArgumentException $e) { $threw = true; }
$pdo->rollback();
check("5. unknown status_v2 value -> InvalidArgumentException", $threw);

$threw = false;
try { StateMachine::applyConfigTransition($pdo, $configUuid, 'validated'); } // no active transaction here
catch (RuntimeException $e) { $threw = true; }
check("6. no active transaction -> RuntimeException", $threw);

// ---------------------------------------------------------------------------
echo "--- assertInventoryTransition / applyInventoryTransition ---\n";
$table = 'cpuinventory';
$availUuid  = 'cpu-0000-0000-0000-000000000001';
$failedUuid = 'cpu-0000-0000-0000-000000000002';
$pdo->prepare("INSERT INTO `$table` (UUID, Status, status_v2) VALUES (?, 1, 'available')")->execute([$availUuid]);
$pdo->prepare("INSERT INTO `$table` (UUID, Status, status_v2) VALUES (?, 0, 'failed')")->execute([$failedUuid]);

$r = StateMachine::assertInventoryTransition($pdo, $table, $availUuid, 'reserved');
check("7. available->reserved (seeded edge) -> allowed", $r['allowed'] === true);

$r = StateMachine::assertInventoryTransition($pdo, $table, $failedUuid, 'available');
check("8. failed->available (illegal resurrection, no edge seeded) -> denied", $r['allowed'] === false);

$pdo->beginTransaction();
StateMachine::applyInventoryTransition($pdo, $table, $availUuid, 'reserved');
$pdo->commit();
$row = $pdo->prepare("SELECT Status, status_v2 FROM `$table` WHERE UUID = ?");
$row->execute([$availUuid]);
$row = $row->fetch();
check("9a. status_v2 written", $row['status_v2'] === 'reserved');
check("9b. legacy Status mapped correctly (reserved->2)", (int)$row['Status'] === StatusMap::INVENTORY_V2_TO_LEGACY['reserved']);

// ---------------------------------------------------------------------------
// F-22: a component UUID names a MODEL. These two methods used to accept one
// alone and address every unit sharing it -- assert answered about whichever
// sibling LIMIT 1 returned, and apply's UPDATE (no LIMIT) rewrote them all.
// Reachable through TransitionStatusCommand for any serial-less unit, of which
// production held 83 under 15 model UUIDs.
echo "--- F-22: model UUID must never stand in for a unit ---\n";
$sharedUuid = 'cpu-0000-0000-0000-000000000003';
$pdo->prepare("INSERT INTO cpuinventory (UUID, SerialNumber, Status, status_v2) VALUES (?, NULL, 1, 'available')")->execute([$sharedUuid]);
$firstId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO cpuinventory (UUID, SerialNumber, Status, status_v2) VALUES (?, NULL, 1, 'available')")->execute([$sharedUuid]);
$pdo->prepare("INSERT INTO cpuinventory (UUID, SerialNumber, Status, status_v2) VALUES (?, NULL, 1, 'available')")->execute([$sharedUuid]);

$r = StateMachine::assertInventoryTransition($pdo, $table, $sharedUuid, 'reserved');
check("10a. 3 serial-less siblings, UUID only -> refused", $r['allowed'] === false);
check("10b. ...and the refusal says why", strpos($r['reason'], 'refusing to transition a model') !== false);

$r = StateMachine::assertInventoryTransition($pdo, $table, $sharedUuid, 'reserved', null, $firstId);
check("10c. same model, addressed by inventory id -> allowed", $r['allowed'] === true);

$threw = false;
$pdo->beginTransaction();
try { StateMachine::applyInventoryTransition($pdo, $table, $sharedUuid, 'reserved'); }
catch (RuntimeException $e) { $threw = true; }
$pdo->rollback();
check("11a. apply with UUID only -> RuntimeException, not a model-wide write", $threw);
$swept = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE UUID = ? AND status_v2 <> 'available'");
$swept->execute([$sharedUuid]);
check("11b. ...and no sibling was touched", (int)$swept->fetchColumn() === 0);

$pdo->beginTransaction();
StateMachine::applyInventoryTransition($pdo, $table, $sharedUuid, 'reserved', null, $firstId);
$pdo->commit();
$moved = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE UUID = ? AND status_v2 = 'reserved'");
$moved->execute([$sharedUuid]);
check("11c. apply by inventory id moved exactly one of the three units", (int)$moved->fetchColumn() === 1);

$r = StateMachine::assertInventoryTransition($pdo, $table, $sharedUuid, 'reserved', null, 999999);
check("12. inventory id that does not exist -> refused, not silently widened", $r['allowed'] === false);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
