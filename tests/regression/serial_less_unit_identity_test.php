<?php
/**
 * Regression test — Phase 3 of the AssetTag work (tasks/asset-tag-unit-identity.md).
 *
 * WHAT IT PROVES
 * --------------
 * updateComponentStatusAndServerUuid() must be able to address a physical unit that
 * has NO manufacturer serial (SerialNumber NULL), when several units share the model
 * UUID. Since seeder 2026_07_22_003 that is real production stock: 3 x Kingston KC600
 * and 9 x Quad M.2 adapter, all serial-less, all sharing one model UUID each.
 *
 * Before the fix, the only unit identifiers were UUID and SerialNumber. A NULL serial
 * cannot be matched with `= ?`, so the WHERE collapsed to `UUID = ?` — the MODEL — and
 * the ambiguity guard (added for F-1) refused the write. Net effect: serial-less units
 * could be added to a config but never released, and removeComponent() rolls the whole
 * removal back on a failed release, so the drive became unremovable.
 *
 * HOW TO READ A RESULT
 * --------------------
 * Run against UNPATCHED code first. Tests 2/3/4 MUST fail there — a regression test
 * that only ever passes proves nothing (the lesson from F-1's own writeup). Then run
 * against patched code: everything must pass.
 *
 * USAGE
 *   "C:\xampp\php\php.exe" tests/regression/serial_less_unit_identity_test.php
 *
 * Requires GOLDEN_DB_* env vars pointing at a SCRATCH database — see the scratch-DB
 * notes. It creates its own throwaway table and drops it; it must NEVER be pointed at
 * production, and it refuses to run against a database whose name lacks 'scratch',
 * 'golden' or 'test'.
 */

// SB_PATH lets the runner point this test at an unpatched copy of ServerBuilder,
// so the same assertions can be executed against before-fix and after-fix code.
require_once (getenv('SB_PATH') ?: __DIR__ . '/../../core/models/server/ServerBuilder.php');

// ---------------------------------------------------------------------------
// Connection — scratch only, fail closed.
// ---------------------------------------------------------------------------
$host = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$name = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$user = getenv('GOLDEN_DB_USER') ?: 'root';

// Read the credential from a FILE by preference, so it never has to be typed on a
// command line or into a shell history. GOLDEN_DB_PASS still works if already set
// in the environment.
$pass = getenv('GOLDEN_DB_PASS');
if ($pass === false || $pass === '') {
    $passFile = getenv('GOLDEN_DB_PASS_FILE');
    if ($passFile !== false && $passFile !== '' && is_readable($passFile)) {
        $pass = trim(file_get_contents($passFile));
    }
}

if ($pass === false || $pass === '') {
    fwrite(STDERR, "No scratch credential. Set GOLDEN_DB_PASS_FILE to a file containing it,\n"
                 . "or GOLDEN_DB_PASS in the environment. Refusing to connect passwordless.\n");
    exit(2);
}
if (!preg_match('/scratch|golden|test/i', $name)) {
    fwrite(STDERR, "Database '$name' does not look like a scratch DB. Refusing to run.\n");
    exit(2);
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to scratch DB: " . $e->getMessage() . "\n");
    exit(2);
}

$passed = 0;
$failed = 0;

function check($label, $condition, $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
        $failed++;
    }
}

// ---------------------------------------------------------------------------
// Fixture: one model, three physical units, none with a serial. Mirrors the
// KC600 situation exactly.
// ---------------------------------------------------------------------------
const MODEL_UUID = 'test-model-0000-0000-000000000001';
const CFG_A      = 'test-config-aaaa-0000-000000000001';
const CFG_B      = 'test-config-bbbb-0000-000000000002';

$pdo->exec("DROP TABLE IF EXISTS `storageinventory_sltest`");
$pdo->exec("
    CREATE TABLE `storageinventory_sltest` (
      `ID` int(11) NOT NULL AUTO_INCREMENT,
      `UUID` varchar(50) NOT NULL,
      `AssetTag` varchar(20) DEFAULT NULL,
      `SerialNumber` varchar(50) DEFAULT NULL,
      `Status` tinyint(1) NOT NULL DEFAULT 1,
      `status_v2` varchar(20) DEFAULT NULL,
      `ServerUUID` varchar(36) DEFAULT NULL,
      `Location` varchar(100) DEFAULT NULL,
      `RackPosition` varchar(20) DEFAULT NULL,
      `InstallationDate` date DEFAULT NULL,
      `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`ID`),
      UNIQUE KEY `uq_asset_tag` (`AssetTag`),
      UNIQUE KEY `idx_serial_number` (`SerialNumber`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Three serial-less units of one model. Unit 1 and 2 are installed in different
// configs; unit 3 is free stock.
$ins = $pdo->prepare(
    "INSERT INTO `storageinventory_sltest` (UUID, AssetTag, SerialNumber, Status, ServerUUID)
     VALUES (?, ?, NULL, ?, ?)"
);
$ins->execute([MODEL_UUID, 'BDC-STO-900001', 2, CFG_A]);
$unit1 = (int)$pdo->lastInsertId();
$ins->execute([MODEL_UUID, 'BDC-STO-900002', 2, CFG_B]);
$unit2 = (int)$pdo->lastInsertId();
$ins->execute([MODEL_UUID, 'BDC-STO-900003', 1, null]);
$unit3 = (int)$pdo->lastInsertId();

echo "Fixture: 3 serial-less units of one model — #$unit1 (in CFG_A), #$unit2 (in CFG_B), #$unit3 (free)\n\n";

// Point ServerBuilder's table map at the throwaway table.
$sb = new ServerBuilder($pdo);
$ref = new ReflectionObject($sb);
$prop = $ref->getProperty('componentTables');
$prop->setAccessible(true);
$prop->setValue($sb, ['storage' => 'storageinventory_sltest']);

function unitRow(PDO $pdo, $id) {
    $s = $pdo->prepare("SELECT * FROM `storageinventory_sltest` WHERE ID = ?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------------
echo "TEST 1 — the ambiguity guard still refuses a model-wide write (F-1 must stay fixed)\n";
// ---------------------------------------------------------------------------
$r = $sb->updateComponentStatusAndServerUuid('storage', MODEL_UUID, 1, null, 'no identifier at all');
check('returns false when neither serial nor inventoryId is given', $r === false);
check('unit #1 was NOT collaterally released', (int)unitRow($pdo, $unit1)['Status'] === 2,
      'F-1 regression: a model-wide UPDATE freed another config\'s unit');
check('unit #2 was NOT collaterally released', (int)unitRow($pdo, $unit2)['Status'] === 2);

// ---------------------------------------------------------------------------
echo "\nTEST 2 — a serial-less unit CAN be released by inventory ID\n";
// ---------------------------------------------------------------------------
$r = $sb->updateComponentStatusAndServerUuid('storage', MODEL_UUID, 1, null, 'release by id', null, null, null, $unit1);
check('returns true', $r === true, 'unpatched code refuses this — the bug being fixed');
$u1 = unitRow($pdo, $unit1);
check('unit #1 is now available', (int)$u1['Status'] === 1, 'Status=' . $u1['Status']);
check('unit #1 ServerUUID cleared', $u1['ServerUUID'] === null);

// ---------------------------------------------------------------------------
echo "\nTEST 3 — releasing unit #1 left the OTHER config's unit untouched\n";
// ---------------------------------------------------------------------------
$u2 = unitRow($pdo, $unit2);
check('unit #2 still Status=2', (int)$u2['Status'] === 2, 'Status=' . $u2['Status']);
check('unit #2 still bound to CFG_B', $u2['ServerUUID'] === CFG_B, 'ServerUUID=' . var_export($u2['ServerUUID'], true));
$u3 = unitRow($pdo, $unit3);
check('free unit #3 untouched', (int)$u3['Status'] === 1 && $u3['ServerUUID'] === null);

// ---------------------------------------------------------------------------
echo "\nTEST 4 — a serial-less unit CAN be installed by inventory ID\n";
// ---------------------------------------------------------------------------
$r = $sb->updateComponentStatusAndServerUuid('storage', MODEL_UUID, 2, CFG_A, 'install by id', 'Noida', '42u', null, $unit3);
check('returns true', $r === true, 'unpatched code refuses this too');
$u3 = unitRow($pdo, $unit3);
check('unit #3 is now in use', (int)$u3['Status'] === 2, 'Status=' . $u3['Status']);
check('unit #3 bound to CFG_A', $u3['ServerUUID'] === CFG_A);
check('unit #3 location applied', $u3['Location'] === 'Noida');
check('unit #2 STILL untouched after install', (int)unitRow($pdo, $unit2)['Status'] === 2);

// ---------------------------------------------------------------------------
echo "\nTEST 5 — the serial path still works for units that DO have one\n";
// ---------------------------------------------------------------------------
$pdo->prepare("UPDATE `storageinventory_sltest` SET SerialNumber = 'REALSERIAL123' WHERE ID = ?")
    ->execute([$unit2]);
$r = $sb->updateComponentStatusAndServerUuid('storage', MODEL_UUID, 1, null, 'release by serial', null, null, 'REALSERIAL123');
check('returns true', $r === true);
check('unit #2 released', (int)unitRow($pdo, $unit2)['Status'] === 1);
check('unit #3 not disturbed', (int)unitRow($pdo, $unit3)['Status'] === 2);

// ---------------------------------------------------------------------------
echo "\nTEST 6 — a bad inventory ID fails rather than falling back to the model\n";
// ---------------------------------------------------------------------------
$r = $sb->updateComponentStatusAndServerUuid('storage', MODEL_UUID, 1, null, 'bogus id', null, null, null, 99999999);
check('returns false for a non-existent row', $r === false);
check('no unit was released as a side effect',
      (int)unitRow($pdo, $unit3)['Status'] === 2,
      'a miss must not degrade into a model-wide UPDATE');

// ---------------------------------------------------------------------------
$pdo->exec("DROP TABLE IF EXISTS `storageinventory_sltest`");

echo "\n" . str_repeat('=', 62) . "\n";
echo "passed: $passed   failed: $failed\n";
if ($failed > 0) {
    echo "RESULT: FAIL\n";
    echo "(Expected against UNPATCHED code — tests 2, 4 and 6 depend on the fix.)\n";
    exit(1);
}
echo "RESULT: PASS\n";
exit(0);
