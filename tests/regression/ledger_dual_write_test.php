<?php
/**
 * ledger_dual_write_test.php — U-L.2 regression test (config_resources ledger).
 *
 * Proves ConfigComponentWriter's ledger hooks (wired into afterLegacyAdd/afterLegacyRemove):
 *   - flag off: zero config_resources writes.
 *   - add motherboard/chassis (flag on): ResourceCatalog::provides() rows land in the SAME
 *     transaction as the component row, provider_id = the new component, consumer_id NULL.
 *   - scalar consumption (pcie_lane): adding NVMe storage against a pre-seeded CPU-provided
 *     lane budget inserts a consumption row (provider_id = the CPU's row, consumer_id = the
 *     storage's row); removing that storage deletes the consumption row.
 *   - removing a PROVIDER (motherboard) explicitly deletes its own provider rows, since
 *     ON DELETE CASCADE never fires on a soft tombstone.
 *   - an induced catalog failure (nic with an UNRESOLVABLE spec UUID: provides() throws
 *     "spec not found", fail-closed per INV-5) rolls back the legacy write, the
 *     config_components row, AND any ledger rows together — nothing partial survives. This
 *     scenario's failure mode changed after U-L.5 (previously nic always threw regardless of
 *     UUID, since provides('nic', ...) had no confirmed fields at all; now it only throws when
 *     the spec genuinely can't be resolved — still correctly fail-closed, just for a different,
 *     narrower reason). See Scenarios F-I below for the U-L.4/U-L.5 positive-path proof: real,
 *     resolvable cpu/nic/hbacard/pciecard specs now add cleanly through this exact live path.
 *
 * NOTE on scope: the pack's example scenario is "add nic with slot->consumer link + lane
 * consumption". This unit does NOT implement discrete slot->consumer linking (RV-2, see
 * ConfigComponentWriter's class docblock: ResourceCatalog's slot_ref naming has no
 * relationship to the legacy slot-assignment system's slot IDs). The positive-path
 * scalar-consumption scenario below therefore uses 'storage' (NVMe) instead, which IS fully
 * implemented via a pre-seeded provider row.
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

require_once $ROOT . '/core/models/config/ConfigComponentWriter.php';
require_once $ROOT . '/core/models/config/ConfigComponentRepository.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

function makeConfig(PDO $pdo, $configUuid) {
    $pdo->prepare('INSERT INTO server_configurations (config_uuid, server_name, is_virtual, configuration_status) VALUES (?, ?, 0, 0)')
        ->execute([$configUuid, 'LEDGER DW TEST']);
}

function cleanupConfig(PDO $pdo, $configUuid) {
    $pdo->exec("DELETE FROM config_resources WHERE config_uuid = " . $pdo->quote($configUuid));
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configUuid));
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configUuid));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid));
}

// -----------------------------------------------------------------------
// Throwaway ims-data fixture (chassis + motherboard + storage), same shapes
// as U-L.1's resource_catalog_test.php.
// -----------------------------------------------------------------------
$tmpImsData = sys_get_temp_dir() . '/ims-data-ledger-dw-' . getmypid();
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
mkdir("$tmpImsData/chassis", 0777, true);
mkdir("$tmpImsData/motherboard", 0777, true);
mkdir("$tmpImsData/storage", 0777, true);
mkdir("$tmpImsData/cpu", 0777, true);
mkdir("$tmpImsData/nic", 0777, true);
mkdir("$tmpImsData/hbacard", 0777, true);
mkdir("$tmpImsData/pciecard", 0777, true);

$chassisUuid = 'c1a2b3c4-1111-4000-8000-000000000001';
$mbUuid = 'm1a2b3c4-1111-4000-8000-000000000001';
$nvmeStorageUuid = 's1a2b3c4-1111-4000-8000-000000000001';
$m2StorageUuid = 's1a2b3c4-1111-4000-8000-000000000002';
$cpuUuid = 'a1a2b3c4-1111-4000-8000-000000000001';
$nicUuid = 'n1a2b3c4-1111-4000-8000-000000000001';
$hbaUuid = 'h1a2b3c4-1111-4000-8000-000000000001';
$pciecardUuid = 'p1a2b3c4-1111-4000-8000-000000000001';

file_put_contents("$tmpImsData/chassis/chasis-level-3.json", json_encode([
    'chassis_specifications' => ['manufacturers' => [[
        'manufacturer' => 'Dell', 'series' => [[
            'series_name' => 'PowerEdge', 'models' => [
                ['uuid' => $chassisUuid, 'power_supply' => ['wattage' => 800]],
            ],
        ]],
    ]]],
]));

file_put_contents("$tmpImsData/motherboard/motherboard-level-3.json", json_encode([
    ['brand' => 'Supermicro', 'models' => [
        ['uuid' => $mbUuid, 'expansion_slots' => [
            'pcie_slots' => [['type' => 'PCIe 4.0 x16', 'count' => 2]],
            'riser_slots' => [['type' => 'PCIe x16 Riser', 'count' => 1]],
        ], 'storage' => ['nvme' => ['m2_slots' => [['count' => 2]]]]],
    ]],
]));

file_put_contents("$tmpImsData/storage/storage-level-3.json", json_encode([
    ['brand' => 'Samsung', 'models' => [
        // U.2, not M.2, so this scenario keeps proving the general consumption mechanism
        // (RV-4 fix: M.2 NVMe consumes 0 pcie_lane -- see the dedicated M.2 scenario below).
        ['uuid' => $nvmeStorageUuid, 'interface' => 'PCIe Gen4 x4 NVMe', 'form_factor' => 'U.2'],
        ['uuid' => $m2StorageUuid, 'interface' => 'PCIe Gen4 x4 NVMe', 'form_factor' => 'M.2 2280'],
    ]],
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
file_put_contents("$tmpImsData/pciecard/pci-level-3.json", json_encode([
    ['component_subtype' => 'Standard PCIe Card', 'brand' => 'Intel', 'models' => [['UUID' => $pciecardUuid, 'interface' => 'PCIe 4.0 x4']]],
]));

putenv("IMS_DATA_PATH=$tmpImsData");

// -----------------------------------------------------------------------
// A. Flag off: no config_resources writes at all
// -----------------------------------------------------------------------
$configA = 'TEST-LDW-OFF-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED'); // unset -> off
    makeConfig($pdo, $configA);

    $pdo->beginTransaction();
    ConfigComponentWriter::afterLegacyAdd($pdo, $configA, 'motherboard', $mbUuid, null, null, 'motherboardinventory', 1001, 1);
    $pdo->commit();
    $count = (int)$pdo->query("SELECT COUNT(*) FROM config_resources WHERE config_uuid = " . $pdo->quote($configA))->fetchColumn();
    check('flag off: no config_resources rows written', $count === 0);
    $componentCount = (int)$pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = " . $pdo->quote($configA))->fetchColumn();
    check('flag off: no config_components row written either (whole hook is a no-op)', $componentCount === 0);
} finally {
    cleanupConfig($pdo, $configA);
}

// -----------------------------------------------------------------------
// B. Flag on: motherboard add -> provider rows (pcie_slot, m2_slot, riser_slot)
// -----------------------------------------------------------------------
$configB = 'TEST-LDW-MB-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    makeConfig($pdo, $configB);

    $pdo->beginTransaction();
    ConfigComponentWriter::afterLegacyAdd($pdo, $configB, 'motherboard', $mbUuid, null, null, 'motherboardinventory', 1001, 1);
    $pdo->commit();

    $mbComponentId = (int)$pdo->query("SELECT id FROM config_components WHERE config_uuid = " . $pdo->quote($configB) . " AND component_type = 'motherboard'")->fetchColumn();
    check('motherboard add: config_components row created', $mbComponentId > 0);

    $rows = $pdo->query("SELECT * FROM config_resources WHERE config_uuid = " . $pdo->quote($configB) . " ORDER BY resource, slot_ref")->fetchAll();
    $byResource = [];
    foreach ($rows as $r) { $byResource[$r['resource']][] = $r; }

    check('motherboard: 2 pcie_slot provider rows', count($byResource['pcie_slot'] ?? []) === 2);
    check('motherboard: 1 riser_slot provider row', count($byResource['riser_slot'] ?? []) === 1);
    check('motherboard: 1 m2_slot provider row (pooled, capacity 2)', count($byResource['m2_slot'] ?? []) === 1 && (int)$byResource['m2_slot'][0]['capacity'] === 2);
    check('motherboard: every provider row has consumer_id NULL', array_reduce($rows, fn($carry, $r) => $carry && $r['consumer_id'] === null, true));
    check('motherboard: every provider row has provider_id = the motherboard component', array_reduce($rows, fn($carry, $r) => $carry && (int)$r['provider_id'] === $mbComponentId, true));

    // ---- remove motherboard -> its provider rows are explicitly cleaned up ----
    $pdo->beginTransaction();
    ConfigComponentWriter::afterLegacyRemove($pdo, $configB, 'motherboard', $mbUuid, null, 1);
    $pdo->commit();
    $remaining = (int)$pdo->query("SELECT COUNT(*) FROM config_resources WHERE config_uuid = " . $pdo->quote($configB))->fetchColumn();
    check('motherboard remove: all its provider rows deleted (no CASCADE on soft tombstone)', $remaining === 0);
} finally {
    cleanupConfig($pdo, $configB);
}

// -----------------------------------------------------------------------
// C. Flag on: chassis add -> psu_watt provider row
// -----------------------------------------------------------------------
$configC = 'TEST-LDW-CH-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    makeConfig($pdo, $configC);

    $pdo->beginTransaction();
    ConfigComponentWriter::afterLegacyAdd($pdo, $configC, 'chassis', $chassisUuid, null, null, 'chassisinventory', 2001, 1);
    $pdo->commit();

    $rows = $pdo->query("SELECT * FROM config_resources WHERE config_uuid = " . $pdo->quote($configC))->fetchAll();
    check('chassis: exactly 1 provider row (psu_watt)', count($rows) === 1);
    check('chassis: resource=psu_watt, capacity=800, consumer_id NULL', ($rows[0]['resource'] ?? null) === 'psu_watt' && (int)($rows[0]['capacity'] ?? 0) === 800 && $rows[0]['consumer_id'] === null);
} finally {
    cleanupConfig($pdo, $configC);
}

// -----------------------------------------------------------------------
// D. Scalar consumption: NVMe storage consumes lanes from a pre-seeded
// CPU-provided pcie_lane budget (CPU itself can't provide yet, per U-L.1 —
// seeded directly to isolate and prove the CONSUMPTION mechanism).
// -----------------------------------------------------------------------
$configD = 'TEST-LDW-LANE-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    makeConfig($pdo, $configD);

    $repo = new ConfigComponentRepository($pdo);
    $pdo->beginTransaction();
    $cpuComponentId = $repo->insert($configD, [
        'component_type' => 'cpu', 'inventory_table' => 'cpuinventory', 'inventory_id' => 3001,
        'spec_uuid' => 'fake-cpu-uuid-for-ledger-seed',
    ], 1);
    $pdo->commit();
    $pdo->prepare('INSERT INTO config_resources (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id) VALUES (?, ?, ?, NULL, ?, NULL)')
        ->execute([$configD, 'pcie_lane', $cpuComponentId, 64]);

    $pdo->beginTransaction();
    ConfigComponentWriter::afterLegacyAdd($pdo, $configD, 'storage', $nvmeStorageUuid, 'STG-1', null, 'storageinventory', 4001, 1);
    $pdo->commit();
    $storageComponentId = (int)$pdo->query("SELECT id FROM config_components WHERE config_uuid = " . $pdo->quote($configD) . " AND component_type = 'storage'")->fetchColumn();

    $laneRows = $pdo->query("SELECT * FROM config_resources WHERE config_uuid = " . $pdo->quote($configD) . " AND resource = 'pcie_lane' ORDER BY consumer_id IS NULL DESC")->fetchAll();
    check('lane consumption: 2 pcie_lane rows (1 provider + 1 consumption)', count($laneRows) === 2);
    $providerRow = $laneRows[0];
    $consumptionRow = $laneRows[1] ?? null;
    check('lane consumption: provider row unchanged (capacity 64, consumer_id NULL)', (int)$providerRow['capacity'] === 64 && $providerRow['consumer_id'] === null);
    check('lane consumption: consumption row has provider_id = CPU, consumer_id = storage, capacity = 4', $consumptionRow
        && (int)$consumptionRow['provider_id'] === $cpuComponentId
        && (int)$consumptionRow['consumer_id'] === $storageComponentId
        && (int)$consumptionRow['capacity'] === 4);

    // ---- remove the storage -> its consumption row is deleted, provider untouched ----
    $pdo->beginTransaction();
    ConfigComponentWriter::afterLegacyRemove($pdo, $configD, 'storage', $nvmeStorageUuid, 'STG-1', 1);
    $pdo->commit();
    $laneRowsAfter = $pdo->query("SELECT * FROM config_resources WHERE config_uuid = " . $pdo->quote($configD) . " AND resource = 'pcie_lane'")->fetchAll();
    check('storage remove: exactly 1 pcie_lane row remains (the provider)', count($laneRowsAfter) === 1);
    check('storage remove: remaining row is the untouched provider', $laneRowsAfter[0]['consumer_id'] === null && (int)$laneRowsAfter[0]['capacity'] === 64);
} finally {
    cleanupConfig($pdo, $configD);
}

// -----------------------------------------------------------------------
// D2. RV-4 fix: M.2 NVMe storage against the SAME pre-seeded CPU-provided
// pcie_lane budget writes NO consumption row -- it rides dedicated chipset
// lanes, not the expansion budget (mirrors PcieLaneBudgetValidator.php:212-213).
// -----------------------------------------------------------------------
$configD2 = 'TEST-LDW-LANE-M2-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    makeConfig($pdo, $configD2);

    $repo = new ConfigComponentRepository($pdo);
    $pdo->beginTransaction();
    $cpuComponentId = $repo->insert($configD2, [
        'component_type' => 'cpu', 'inventory_table' => 'cpuinventory', 'inventory_id' => 3002,
        'spec_uuid' => 'fake-cpu-uuid-for-ledger-seed-m2',
    ], 1);
    $pdo->commit();
    $pdo->prepare('INSERT INTO config_resources (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id) VALUES (?, ?, ?, NULL, ?, NULL)')
        ->execute([$configD2, 'pcie_lane', $cpuComponentId, 64]);

    $pdo->beginTransaction();
    ConfigComponentWriter::afterLegacyAdd($pdo, $configD2, 'storage', $m2StorageUuid, 'STG-1', null, 'storageinventory', 4002, 1);
    $pdo->commit();

    $laneRows = $pdo->query("SELECT * FROM config_resources WHERE config_uuid = " . $pdo->quote($configD2) . " AND resource = 'pcie_lane'")->fetchAll();
    check('M.2 storage add: exactly 1 pcie_lane row remains (the untouched CPU provider, no consumption row)', count($laneRows) === 1);
    check('M.2 storage add: remaining row has consumer_id NULL, capacity 64 (provider unchanged)', $laneRows[0]['consumer_id'] === null && (int)$laneRows[0]['capacity'] === 64);
} finally {
    cleanupConfig($pdo, $configD2);
}

// -----------------------------------------------------------------------
// E. Induced catalog failure (nic: provides() AND consumes() both throw)
// rolls back the legacy write, the component row, and any ledger rows.
// -----------------------------------------------------------------------
$configE = 'TEST-LDW-FAIL-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    makeConfig($pdo, $configE);

    $pdo->beginTransaction();
    // Legacy write analog (what updateServerConfigurationTable would have done).
    $pdo->prepare('UPDATE server_configurations SET nic_config = ? WHERE config_uuid = ?')
        ->execute([json_encode(['should-not-persist' => true]), $configE]);

    $threw = false;
    try {
        ConfigComponentWriter::afterLegacyAdd($pdo, $configE, 'nic', 'some-nic-uuid', 'NIC-1', null, 'nicinventory', 5001, 1);
    } catch (\Throwable $e) {
        $threw = true;
    }
    check('induced failure: afterLegacyAdd throws for nic (provides() has no confirmed fields)', $threw);

    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }

    $legacyAfter = $pdo->query("SELECT nic_config FROM server_configurations WHERE config_uuid = " . $pdo->quote($configE))->fetchColumn();
    check('induced failure: legacy write rolled back', $legacyAfter === null);
    $componentCount = (int)$pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = " . $pdo->quote($configE))->fetchColumn();
    check('induced failure: no config_components row leaked', $componentCount === 0);
    $resourceCount = (int)$pdo->query("SELECT COUNT(*) FROM config_resources WHERE config_uuid = " . $pdo->quote($configE))->fetchColumn();
    check('induced failure: no config_resources row leaked', $resourceCount === 0);
} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    cleanupConfig($pdo, $configE);
}

// -----------------------------------------------------------------------
// F. U-L.4/U-L.5 proof: real, resolvable cpu/nic/hbacard/pciecard specs now
// add cleanly through ConfigComponentWriter::afterLegacyAdd() — the exact
// live dual-write path that would have broken every such add under
// DUAL_WRITE_ENABLED=on before these units (no skip list on this path,
// unlike backfill.php). This is the direct answer to "does add-component
// still error for cpu/nic/hbacard/pciecard" that Scenario E (fake UUID)
// cannot provide.
// -----------------------------------------------------------------------
$configF = 'TEST-LDW-CPU-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    makeConfig($pdo, $configF);

    $pdo->beginTransaction();
    $threw = false;
    try {
        ConfigComponentWriter::afterLegacyAdd($pdo, $configF, 'cpu', $cpuUuid, 'CPU-1', null, 'cpuinventory', 6001, 1);
    } catch (\Throwable $e) {
        $threw = true;
    }
    if ($pdo->inTransaction()) { $pdo->commit(); }
    check('cpu add (real spec): does NOT throw (U-L.4)', !$threw);

    $rows = $pdo->query("SELECT * FROM config_resources WHERE config_uuid = " . $pdo->quote($configF))->fetchAll();
    check('cpu add: exactly 1 provider row (pcie_lane, capacity 64)', count($rows) === 1 && ($rows[0]['resource'] ?? null) === 'pcie_lane' && (int)($rows[0]['capacity'] ?? 0) === 64);
} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    cleanupConfig($pdo, $configF);
}

$configG = 'TEST-LDW-NIC-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    makeConfig($pdo, $configG);

    $pdo->beginTransaction();
    $threw = false;
    try {
        ConfigComponentWriter::afterLegacyAdd($pdo, $configG, 'nic', $nicUuid, 'NIC-1', null, 'nicinventory', 7001, 1);
    } catch (\Throwable $e) {
        $threw = true;
    }
    if ($pdo->inTransaction()) { $pdo->commit(); }
    check('nic add (real spec): does NOT throw (U-L.5)', !$threw);

    $rows = $pdo->query("SELECT * FROM config_resources WHERE config_uuid = " . $pdo->quote($configG))->fetchAll();
    check('nic add: exactly 1 provider row (sfp_port, capacity 4)', count($rows) === 1 && ($rows[0]['resource'] ?? null) === 'sfp_port' && (int)($rows[0]['capacity'] ?? 0) === 4);
} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    cleanupConfig($pdo, $configG);
}

// hbacard/pciecard CONSUME pcie_lane — pre-seed a CPU-provided lane budget
// (same pattern as Scenario D) so the consumption side has a provider to
// attach to; adding the CPU itself is proven separately in Scenario F.
$configH = 'TEST-LDW-HBA-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    makeConfig($pdo, $configH);

    $repo = new ConfigComponentRepository($pdo);
    $pdo->beginTransaction();
    $cpuComponentId = $repo->insert($configH, [
        'component_type' => 'cpu', 'inventory_table' => 'cpuinventory', 'inventory_id' => 8001,
        'spec_uuid' => 'fake-cpu-uuid-for-ledger-seed',
    ], 1);
    $pdo->commit();
    $pdo->prepare('INSERT INTO config_resources (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id) VALUES (?, ?, ?, NULL, ?, NULL)')
        ->execute([$configH, 'pcie_lane', $cpuComponentId, 64]);

    $pdo->beginTransaction();
    $threw = false;
    try {
        ConfigComponentWriter::afterLegacyAdd($pdo, $configH, 'hbacard', $hbaUuid, 'HBA-1', null, 'hbacardinventory', 8002, 1);
    } catch (\Throwable $e) {
        $threw = true;
    }
    if ($pdo->inTransaction()) { $pdo->commit(); }
    check('hbacard add (real spec, interface="PCIe 4.0 x8"): does NOT throw (U-L.5)', !$threw);

    $hbaComponentId = (int)$pdo->query("SELECT id FROM config_components WHERE config_uuid = " . $pdo->quote($configH) . " AND component_type = 'hbacard'")->fetchColumn();
    $consumeRow = $pdo->query("SELECT capacity FROM config_resources WHERE config_uuid = " . $pdo->quote($configH) . " AND resource = 'pcie_lane' AND consumer_id = $hbaComponentId")->fetch();
    check('hbacard add: pcie_lane consumption row present (capacity 8)', $consumeRow && (int)$consumeRow['capacity'] === 8);
} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    cleanupConfig($pdo, $configH);
}

$configI = 'TEST-LDW-PCIE-' . substr(md5(uniqid()), 0, 8);
try {
    putenv('DUAL_WRITE_ENABLED=on');
    makeConfig($pdo, $configI);

    $repo = new ConfigComponentRepository($pdo);
    $pdo->beginTransaction();
    $cpuComponentId = $repo->insert($configI, [
        'component_type' => 'cpu', 'inventory_table' => 'cpuinventory', 'inventory_id' => 9001,
        'spec_uuid' => 'fake-cpu-uuid-for-ledger-seed',
    ], 1);
    $pdo->commit();
    $pdo->prepare('INSERT INTO config_resources (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id) VALUES (?, ?, ?, NULL, ?, NULL)')
        ->execute([$configI, 'pcie_lane', $cpuComponentId, 64]);

    $pdo->beginTransaction();
    $threw = false;
    try {
        ConfigComponentWriter::afterLegacyAdd($pdo, $configI, 'pciecard', $pciecardUuid, 'PCIE-1', null, 'pciecardinventory', 9002, 1);
    } catch (\Throwable $e) {
        $threw = true;
    }
    if ($pdo->inTransaction()) { $pdo->commit(); }
    check('pciecard add (real spec, interface="PCIe 4.0 x4"): does NOT throw (U-L.5)', !$threw);

    $cardComponentId = (int)$pdo->query("SELECT id FROM config_components WHERE config_uuid = " . $pdo->quote($configI) . " AND component_type = 'pciecard'")->fetchColumn();
    $consumeRow = $pdo->query("SELECT capacity FROM config_resources WHERE config_uuid = " . $pdo->quote($configI) . " AND resource = 'pcie_lane' AND consumer_id = $cardComponentId")->fetch();
    check('pciecard add: pcie_lane consumption row present (capacity 4)', $consumeRow && (int)$consumeRow['capacity'] === 4);
} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    cleanupConfig($pdo, $configI);
}

rrmdir($tmpImsData);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
