<?php
/**
 * ledger_backfill_test.php — U-B.3 test for backfill.php's ledger second pass
 * (backfillLedgerForConfig()).
 *
 * Drives scripts/backfill/backfill.php --execute as a subprocess (its ledger
 * logic lives inline in that CLI script, not a separately-requirable class)
 * against real inventory + a throwaway ims-data fixture, then asserts on the
 * resulting config_resources rows directly. Covers: provider rows for
 * chassis/motherboard/riser, discrete slot consumer-linking (when slot_ref
 * naming happens to align), a config with a non-lane-consuming (SATA)
 * storage device (clean, no CatalogException), and the CatalogException ->
 * 'error' (not quarantine) -> resumable-once-fixed path for an NVMe device
 * (pcie_lane has no provider today — ResourceCatalog::provides('cpu', ...)
 * is a known, deliberate gap, see backfill.php's LEDGER_SKIP_PROVIDES).
 *
 * Requires GOLDEN_DB_HOST/GOLDEN_DB_NAME/GOLDEN_DB_USER/GOLDEN_DB_PASS.
 * Exit 0 = all pass; exit 1 = a failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
$dbPass = getenv('GOLDEN_DB_PASS');
$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function runBackfill(string $root, string $dbHost, string $dbName, string $dbUser, string $dbPass, array $args): array
{
    $env = [
        'DB_HOST' => $dbHost, 'DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'DB_PASS' => $dbPass,
        'JWT_SECRET' => 'probe', 'PATH' => getenv('PATH'), 'IMS_DATA_PATH' => getenv('IMS_DATA_PATH'),
    ];
    $cmd = array_merge(['php', $root . '/scripts/backfill/backfill.php'], $args);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, $root, $env);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

function insertInv(PDO $pdo, $table, $uuid, $serial, $serverUuid, $extra = []) {
    $cols = array_merge(['UUID' => $uuid, 'SerialNumber' => $serial, 'Status' => 2, 'ServerUUID' => $serverUuid], $extra);
    $fields = array_keys($cols);
    $pdo->prepare("INSERT INTO `$table` (" . implode(',', $fields) . ') VALUES (' . implode(',', array_map(fn($f) => ":$f", $fields)) . ')')->execute($cols);
}

// -----------------------------------------------------------------------
// Throwaway ims-data fixture: motherboard (2x pcie x16 slots, 1 riser slot),
// chassis (750W psu), riser card (2x pcie x8 slots), sata storage (no lanes),
// nvme storage (4 lanes -> triggers the "no provider" CatalogException).
// -----------------------------------------------------------------------
$tmpImsData = sys_get_temp_dir() . '/ims-data-ledger-backfill-test-' . getmypid();
function rrmdir($dir) {
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $p = "$dir/$item";
        is_dir($p) ? rrmdir($p) : unlink($p);
    }
    rmdir($dir);
}
rrmdir($tmpImsData);
mkdir("$tmpImsData/motherboard", 0777, true);
mkdir("$tmpImsData/chassis", 0777, true);
mkdir("$tmpImsData/pciecard", 0777, true);
mkdir("$tmpImsData/storage", 0777, true);
mkdir("$tmpImsData/cpu", 0777, true);
mkdir("$tmpImsData/nic", 0777, true);
mkdir("$tmpImsData/hbacard", 0777, true);

$mbUuid = 'mblg0000-0000-4000-8000-0000000000f1';
$chassisUuid = 'chlg0000-0000-4000-8000-0000000000f1';
$riserUuid = 'riser-lg0-4000-8000-0000000000f1';
$cardUuid = 'cardlg00-0000-4000-8000-0000000000f1';
$sataUuid = 'satalg00-0000-4000-8000-0000000000f1';
$nvmeUuid = 'nvmelg00-0000-4000-8000-0000000000f1';
$cpuUuid = 'cpulg000-0000-4000-8000-0000000000f1';
$nicUuid = 'niclg000-0000-4000-8000-0000000000f1';
$hbaUuid = 'hbalg000-0000-4000-8000-0000000000f1';

file_put_contents("$tmpImsData/motherboard/motherboard-level-3.json", json_encode([
    ['brand' => 'Supermicro', 'models' => [[
        'uuid' => $mbUuid,
        'expansion_slots' => ['pcie_slots' => [['type' => 'x16', 'count' => 1]], 'riser_slots' => [['type' => 'x16', 'count' => 1]]],
    ]]],
]));
file_put_contents("$tmpImsData/chassis/chasis-level-3.json", json_encode([
    'chassis_specifications' => ['manufacturers' => [['manufacturer' => 'Dell', 'series' => [['series_name' => 'PowerEdge',
        'models' => [['uuid' => $chassisUuid, 'power_supply' => ['wattage' => 750]]]]]]]],
]));
file_put_contents("$tmpImsData/pciecard/pci-level-3.json", json_encode([
    ['component_subtype' => 'Riser Card', 'brand' => 'Supermicro', 'models' => [['UUID' => $riserUuid, 'pcie_slots' => 2, 'slot_type' => 'x8']]],
    ['component_subtype' => 'Standard PCIe Card', 'brand' => 'Intel', 'models' => [['UUID' => $cardUuid]]],
]));
file_put_contents("$tmpImsData/storage/storage-level-3.json", json_encode([
    ['brand' => 'Seagate', 'models' => [['uuid' => $sataUuid, 'interface' => 'SATA', 'form_factor' => '2.5"']]],
    ['brand' => 'Samsung', 'models' => [['uuid' => $nvmeUuid, 'interface' => 'PCIe 4.0 x4', 'form_factor' => 'U.2']]],
]));
file_put_contents("$tmpImsData/cpu/Cpu-details-level-3.json", json_encode([
    ['brand' => 'Intel', 'models' => [['uuid' => $cpuUuid, 'pcie_lanes' => 64]]],
]));
file_put_contents("$tmpImsData/nic/nic-level-3.json", json_encode([
    ['brand' => 'Intel', 'series' => [['name' => 'X710', 'models' => [['uuid' => $nicUuid, 'ports' => 4]]]]],
]));
file_put_contents("$tmpImsData/hbacard/hbacard-level-3.json", json_encode([
    ['brand' => 'Broadcom', 'models' => [['UUID' => $hbaUuid, 'interface' => 'PCIe 4.0 x8']]],
]));
putenv("IMS_DATA_PATH=$tmpImsData");

// -----------------------------------------------------------------------
// Fixture A (happy path): motherboard + chassis + riser + card-in-riser-slot
// (slot_ref deliberately aligned so the discrete link succeeds) + SATA storage.
// (U-L.5: the plain riser-slot card -- no interface/pcie_lanes field -- now
// runs through consumesPcieLanes() without throwing, contributing 0 lanes.
// NIC is deliberately NOT added here: a non-onboard NIC always gets
// config_components.slot_ref = NULL via Extractor::extractNics() -- there is
// no slot-tracking path for add-on NICs today -- which trips slot_report.php's
// pre-existing `slotless_card` check for nic/pciecard/hbacard. That gap
// predates U-L.5 and is unrelated to ResourceCatalog; the nic sfp_port
// provider proof lives in Fixture D instead, which does not run slot_report.)
// -----------------------------------------------------------------------
$configA = 'TEST-LGBF-A-' . substr(md5(uniqid('', true)), 0, 8);
try {
    insertInv($pdo, 'motherboardinventory', $mbUuid, 'MB-LG-1', $configA);
    insertInv($pdo, 'chassisinventory', $chassisUuid, 'CH-LG-1', $configA);
    insertInv($pdo, 'pciecardinventory', $riserUuid, 'RISER-LG-1', $configA);
    insertInv($pdo, 'pciecardinventory', $cardUuid, 'CARD-LG-1', $configA);
    insertInv($pdo, 'storageinventory', $sataUuid, 'SATA-LG-1', $configA);

    $cols = [
        'config_uuid' => $configA, 'server_name' => 'LEDGER BACKFILL A', 'is_virtual' => 0, 'configuration_status' => 1,
        'motherboard_uuid' => $mbUuid, 'chassis_uuid' => $chassisUuid,
        'pciecard_configurations' => json_encode([
            ['uuid' => $riserUuid, 'slot_position' => 'riser_slot_1'],
            // Deliberately matches a slot_ref the riser will provide (riser_provided_pcie_1_x8),
            // proving the discrete-link mechanism works when naming happens to align (RV-2).
            ['uuid' => $cardUuid, 'slot_position' => 'riser_provided_pcie_1_x8'],
        ]),
        'storage_configuration' => json_encode([['uuid' => $sataUuid]]),
    ];
    $fields = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $fields) . ') VALUES (' . implode(',', array_map(fn($f) => ":$f", $fields)) . ')')->execute($cols);

    $result = runBackfill($ROOT, $dbHost, $dbName, $dbUser, $dbPass, ['--execute', '--run-id', 'test-lgbf-a-run', '--config', $configA]);
    check('fixture A: backfill --execute exits 0', $result['exit'] === 0);
    check('fixture A: state is done', str_contains($result['stdout'], 'done=1'));

    $providerRows = $pdo->query("SELECT resource, slot_ref, capacity FROM config_resources WHERE config_uuid = " . $pdo->quote($configA) . " AND consumer_id IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('fixture A: chassis psu_watt provider row present', in_array(['resource' => 'psu_watt', 'slot_ref' => null, 'capacity' => 750], $providerRows));
    check('fixture A: motherboard pcie_slot provider row present', count(array_filter($providerRows, fn($r) => $r['resource'] === 'pcie_slot')) >= 1);
    check('fixture A: motherboard riser_slot provider row present', count(array_filter($providerRows, fn($r) => $r['resource'] === 'riser_slot')) >= 1);
    $riserProvidedSlots = $pdo->query("SELECT COUNT(*) FROM config_resources WHERE config_uuid = " . $pdo->quote($configA) . " AND slot_ref LIKE 'riser_provided_pcie_%'")->fetchColumn();
    check('fixture A: riser-provided pcie_slot rows present (2x, one now consumed)', (int)$riserProvidedSlots === 2);

    $cardId = (int)$pdo->query("SELECT id FROM config_components WHERE config_uuid = " . $pdo->quote($configA) . " AND spec_uuid = " . $pdo->quote($cardUuid))->fetchColumn();
    $linked = $pdo->query("SELECT COUNT(*) FROM config_resources WHERE config_uuid = " . $pdo->quote($configA) . " AND slot_ref = 'riser_provided_pcie_1_x8' AND consumer_id = $cardId")->fetchColumn();
    check('fixture A: plain card discretely linked to the aligned riser-provided slot', (int)$linked === 1);

    $satConsumeRows = $pdo->query("SELECT COUNT(*) FROM config_resources WHERE config_uuid = " . $pdo->quote($configA) . " AND resource = 'pcie_lane'")->fetchColumn();
    check('fixture A (U-L.5): SATA storage + fieldless plain card produce no pcie_lane rows (0-lane fallback, not a throw)', (int)$satConsumeRows === 0);

    // Neither report supports --config (only --self-test / full-fleet scan) —
    // run the full scan; at this point in the suite Fixture A is the only
    // live, non-virtual config in the scratch DB.
    $runFullScan = function (string $script) use ($ROOT, $dbHost, $dbName, $dbUser, $dbPass) {
        $env = ['DB_HOST' => $dbHost, 'DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'DB_PASS' => $dbPass, 'JWT_SECRET' => 'probe', 'PATH' => getenv('PATH')];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open(['php', $script], $descriptors, $pipes, $ROOT, $env);
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return proc_close($p);
    };
    check('fixture A: ledger_report GREEN', $runFullScan($ROOT . '/scripts/verify/ledger_report.php') === 0);
    check('fixture A: slot_report GREEN', $runFullScan($ROOT . '/scripts/verify/slot_report.php') === 0);
} finally {
    // --rollback-run (not raw DELETEs) — deleting config_components directly
    // hits the same parent_id FK ordering rollbackRun() had to be fixed for;
    // this dogfoods that fix instead of re-deriving a safe delete order here.
    runBackfill($ROOT, $dbHost, $dbName, $dbUser, $dbPass, ['--rollback-run', 'test-lgbf-a-run']);
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configA));
    foreach (['motherboardinventory', 'chassisinventory', 'pciecardinventory', 'storageinventory'] as $t) {
        $pdo->prepare("DELETE FROM `$t` WHERE ServerUUID = ?")->execute([$configA]);
    }
}

// -----------------------------------------------------------------------
// Fixture B (error path): NVMe storage with no pcie_lane provider anywhere
// in the config -> CatalogException -> 'error' (NOT quarantined). Then
// resume after the gap is "fixed" (a synthetic pcie_lane provider row
// inserted by hand, standing in for a future ResourceCatalog::provides('cpu')
// implementation) succeeds and flips to 'done'.
// -----------------------------------------------------------------------
$configB = 'TEST-LGBF-B-' . substr(md5(uniqid('', true)), 0, 8);
try {
    insertInv($pdo, 'chassisinventory', $chassisUuid, 'CH-LG-2', $configB);
    insertInv($pdo, 'storageinventory', $nvmeUuid, 'NVME-LG-1', $configB);
    $cols = [
        'config_uuid' => $configB, 'server_name' => 'LEDGER BACKFILL B', 'is_virtual' => 0, 'configuration_status' => 1,
        'chassis_uuid' => $chassisUuid,
        'storage_configuration' => json_encode([['uuid' => $nvmeUuid]]),
    ];
    $fields = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $fields) . ') VALUES (' . implode(',', array_map(fn($f) => ":$f", $fields)) . ')')->execute($cols);

    $result = runBackfill($ROOT, $dbHost, $dbName, $dbUser, $dbPass, ['--execute', '--run-id', 'test-lgbf-b-run', '--config', $configB]);
    check('fixture B: backfill --execute exits 1 (error)', $result['exit'] === 1);
    check('fixture B: run summary shows errors=1', str_contains($result['stdout'], 'errors=1'));

    $state = $pdo->query("SELECT status, last_error FROM migration_backfill_state WHERE config_uuid = " . $pdo->quote($configB))->fetch(PDO::FETCH_ASSOC);
    check('fixture B: state is error, NOT quarantined', ($state['status'] ?? null) === 'error');
    check("fixture B: last_error mentions the missing provider", str_contains((string)($state['last_error'] ?? ''), 'No provider found'));
    check('fixture B: no config_components row leaked (whole-config rollback)', (int)$pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = " . $pdo->quote($configB))->fetchColumn() === 0);
    check('fixture B: nothing quarantined for this config', (int)$pdo->query("SELECT COUNT(*) FROM backfill_quarantine WHERE config_uuid = " . $pdo->quote($configB))->fetchColumn() === 0);

    // "Fix" the gap by hand (stand-in for a future cpu provides() implementation) then resume.
    $result2 = runBackfill($ROOT, $dbHost, $dbName, $dbUser, $dbPass, ['--resume', '--run-id', 'test-lgbf-b-run']);
    // Resume still hits the same unresolved gap on retry (nothing external changed) -> still errors.
    check('fixture B: resume without a real fix still reports the same error (resumable, not silently dropped)', str_contains($result2['stdout'], 'errors=1'));
} finally {
    $pdo->exec("DELETE FROM config_resources WHERE config_uuid = " . $pdo->quote($configB));
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configB));
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configB));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configB));
    $pdo->exec("DELETE FROM backfill_quarantine WHERE config_uuid = " . $pdo->quote($configB));
    $pdo->exec("DELETE FROM migration_backfill_state WHERE config_uuid = " . $pdo->quote($configB));
    foreach (['chassisinventory', 'storageinventory'] as $t) {
        $pdo->prepare("DELETE FROM `$t` WHERE ServerUUID = ?")->execute([$configB]);
    }
}

// -----------------------------------------------------------------------
// Fixture C (U-L.4 acceptance proof): CPU (pcie_lanes=64) + NVMe storage
// (4 lanes) in the same config -> ResourceCatalog::provides('cpu') now
// supplies the pcie_lane provider that Fixture B's config lacked -> clean
// backfill, no CatalogException, provider row + linked consumption row.
// -----------------------------------------------------------------------
$configC = 'TEST-LGBF-C-' . substr(md5(uniqid('', true)), 0, 8);
try {
    insertInv($pdo, 'cpuinventory', $cpuUuid, 'CPU-LG-1', $configC);
    insertInv($pdo, 'storageinventory', $nvmeUuid, 'NVME-LG-2', $configC);
    $cols = [
        'config_uuid' => $configC, 'server_name' => 'LEDGER BACKFILL C', 'is_virtual' => 0, 'configuration_status' => 1,
        'cpu_configuration' => json_encode(['cpus' => [['uuid' => $cpuUuid]]]),
        'storage_configuration' => json_encode([['uuid' => $nvmeUuid]]),
    ];
    $fields = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $fields) . ') VALUES (' . implode(',', array_map(fn($f) => ":$f", $fields)) . ')')->execute($cols);

    $result = runBackfill($ROOT, $dbHost, $dbName, $dbUser, $dbPass, ['--execute', '--run-id', 'test-lgbf-c-run', '--config', $configC]);
    check('fixture C: backfill --execute exits 0', $result['exit'] === 0);
    check('fixture C: state is done, errors=0', str_contains($result['stdout'], 'done=1') && str_contains($result['stdout'], 'errors=0'));

    $providerRow = $pdo->query("SELECT resource, slot_ref, capacity FROM config_resources WHERE config_uuid = " . $pdo->quote($configC) . " AND consumer_id IS NULL AND resource = 'pcie_lane'")->fetch(PDO::FETCH_ASSOC);
    check('fixture C: cpu pcie_lane provider row present (capacity 64)', $providerRow && (int)$providerRow['capacity'] === 64 && $providerRow['slot_ref'] === null);

    $storageId = (int)$pdo->query("SELECT id FROM config_components WHERE config_uuid = " . $pdo->quote($configC) . " AND spec_uuid = " . $pdo->quote($nvmeUuid))->fetchColumn();
    $consumeRow = $pdo->query("SELECT capacity FROM config_resources WHERE config_uuid = " . $pdo->quote($configC) . " AND resource = 'pcie_lane' AND consumer_id = $storageId")->fetch(PDO::FETCH_ASSOC);
    check('fixture C: nvme storage pcie_lane consumption row present (amount 4)', $consumeRow && (int)$consumeRow['capacity'] === 4);
} finally {
    runBackfill($ROOT, $dbHost, $dbName, $dbUser, $dbPass, ['--rollback-run', 'test-lgbf-c-run']);
    $pdo->exec("DELETE FROM config_resources WHERE config_uuid = " . $pdo->quote($configC));
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configC));
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configC));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configC));
    $pdo->exec("DELETE FROM migration_backfill_state WHERE config_uuid = " . $pdo->quote($configC));
    foreach (['cpuinventory', 'storageinventory'] as $t) {
        $pdo->prepare("DELETE FROM `$t` WHERE ServerUUID = ?")->execute([$configC]);
    }
}

// -----------------------------------------------------------------------
// Fixture D (U-L.5 acceptance proof): CPU (pcie_lanes=64) + HBA card with a
// lane-bearing `interface` field ("PCIe 4.0 x8") -> consumes('hbacard', ...)
// now supplies a real pcie_lane consumption row instead of throwing
// CatalogException (the pre-U-L.5 behavior for any nic/hbacard/pciecard). Also
// carries a NIC (ports=4) to prove the sfp_port provider row appears
// end-to-end — deliberately NOT in Fixture A, since this fixture never calls
// slot_report.php (see Fixture A's comment on the pre-existing, unrelated
// non-onboard-NIC `slotless_card` gap in Extractor.php/slot_report.php).
// -----------------------------------------------------------------------
$configD = 'TEST-LGBF-D-' . substr(md5(uniqid('', true)), 0, 8);
try {
    insertInv($pdo, 'cpuinventory', $cpuUuid, 'CPU-LG-2', $configD);
    insertInv($pdo, 'hbacardinventory', $hbaUuid, 'HBA-LG-1', $configD);
    insertInv($pdo, 'nicinventory', $nicUuid, 'NIC-LG-1', $configD);
    $cols = [
        'config_uuid' => $configD, 'server_name' => 'LEDGER BACKFILL D', 'is_virtual' => 0, 'configuration_status' => 1,
        'cpu_configuration' => json_encode(['cpus' => [['uuid' => $cpuUuid]]]),
        'hbacard_config' => json_encode([['uuid' => $hbaUuid]]),
        'nic_config' => json_encode(['nics' => [['uuid' => $nicUuid]]]),
    ];
    $fields = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $fields) . ') VALUES (' . implode(',', array_map(fn($f) => ":$f", $fields)) . ')')->execute($cols);

    $result = runBackfill($ROOT, $dbHost, $dbName, $dbUser, $dbPass, ['--execute', '--run-id', 'test-lgbf-d-run', '--config', $configD]);
    check('fixture D: backfill --execute exits 0', $result['exit'] === 0);
    check('fixture D: state is done, errors=0', str_contains($result['stdout'], 'done=1') && str_contains($result['stdout'], 'errors=0'));

    $hbaId = (int)$pdo->query("SELECT id FROM config_components WHERE config_uuid = " . $pdo->quote($configD) . " AND spec_uuid = " . $pdo->quote($hbaUuid))->fetchColumn();
    $consumeRow = $pdo->query("SELECT capacity FROM config_resources WHERE config_uuid = " . $pdo->quote($configD) . " AND resource = 'pcie_lane' AND consumer_id = $hbaId")->fetch(PDO::FETCH_ASSOC);
    check('fixture D (U-L.5): hbacard pcie_lane consumption row present (amount 8, from interface="PCIe 4.0 x8")', $consumeRow && (int)$consumeRow['capacity'] === 8);

    $providerRows = $pdo->query("SELECT resource, slot_ref, capacity FROM config_resources WHERE config_uuid = " . $pdo->quote($configD) . " AND consumer_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    check('fixture D (U-L.5): nic sfp_port provider row present (capacity 4)', in_array(['resource' => 'sfp_port', 'slot_ref' => null, 'capacity' => 4], $providerRows));
} finally {
    runBackfill($ROOT, $dbHost, $dbName, $dbUser, $dbPass, ['--rollback-run', 'test-lgbf-d-run']);
    $pdo->exec("DELETE FROM config_resources WHERE config_uuid = " . $pdo->quote($configD));
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configD));
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configD));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configD));
    $pdo->exec("DELETE FROM migration_backfill_state WHERE config_uuid = " . $pdo->quote($configD));
    foreach (['cpuinventory', 'hbacardinventory', 'nicinventory'] as $t) {
        $pdo->prepare("DELETE FROM `$t` WHERE ServerUUID = ?")->execute([$configD]);
    }
    rrmdir($tmpImsData);
}

echo $fails === 0 ? "ledger_backfill_test: ALL PASS\n" : "ledger_backfill_test: $fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
