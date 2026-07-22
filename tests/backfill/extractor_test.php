<?php
/**
 * extractor_test.php — U-B.2 unit test for scripts/backfill/Extractor.php.
 *
 * Exercises Extractor::extract() against a real scratch DB (inventory tables
 * + server_configurations) and a throwaway ims-data fixture (for the riser
 * component_subtype path), covering every quirk in the pack: cpu/ram quantity
 * expansion + serial resolution, hbacard triple-format + dedup, riser
 * retyping (both uuid-prefix and component_subtype), onboard vs regular nic
 * parenting, sfp assigned/unassigned, storage connection.bay, and the
 * "cannot resolve -> quarantine with a distinct reason" path.
 *
 * Requires GOLDEN_DB_HOST/GOLDEN_DB_NAME/GOLDEN_DB_USER/GOLDEN_DB_PASS env
 * vars pointed at a scratch DB with the inventory tables + server_configurations
 * + config_components/config_events (see migration/00-overview/SESSION_PROTOCOL.md
 * for the scratch DB convention). Exit 0 = all pass; exit 1 = a failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/scripts/backfill/Extractor.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

$pdo = new PDO(
    'mysql:host=' . (getenv('GOLDEN_DB_HOST') ?: '127.0.0.1') . ';dbname=' . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden'),
    getenv('GOLDEN_DB_USER') ?: 'root',
    getenv('GOLDEN_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// -----------------------------------------------------------------------
// Throwaway ims-data fixture (riser component_subtype detection only).
// -----------------------------------------------------------------------
$tmpImsData = sys_get_temp_dir() . '/ims-data-extractor-test-' . getmypid();
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
mkdir("$tmpImsData/pciecard", 0777, true);

$subtypeRiserUuid = 'r1ser000-0000-4000-8000-000000000001'; // detected via component_subtype, no 'riser-' prefix
file_put_contents("$tmpImsData/pciecard/pci-level-3.json", json_encode([
    ['component_subtype' => 'Riser Card', 'brand' => 'Supermicro', 'models' => [['UUID' => $subtypeRiserUuid, 'pcie_slots' => 2, 'slot_type' => 'x16']]],
    ['component_subtype' => 'Standard PCIe Card', 'brand' => 'Intel', 'models' => [['UUID' => 'plain0000-0000-4000-8000-000000000001']]],
]));
putenv("IMS_DATA_PATH=$tmpImsData");

// -----------------------------------------------------------------------
// Seed inventory rows + a config row.
// -----------------------------------------------------------------------
$configUuid = 'TEST-UB2-' . substr(md5(uniqid('', true)), 0, 8);
$mbUuid = 'mb000000-0000-4000-8000-000000000001';
$chassisUuid = 'ch000000-0000-4000-8000-000000000001';
$cpuUuid = 'cpu00000-0000-4000-8000-000000000001';
$ramUuid = 'ram00000-0000-4000-8000-000000000001';
$storageUuid = 'stg00000-0000-4000-8000-000000000001';
$storageUuid2 = 'stg00000-0000-4000-8000-000000000002';
$riserPrefixUuid = 'riser-0000-4000-8000-000000000001';
$plainCardUuid = 'plain000-0000-4000-8000-000000000001';
$hbaUuid = 'hba00000-0000-4000-8000-000000000001';
$nicUuid = 'nic00000-0000-4000-8000-000000000001';
$onboardNicUuid = 'onboard-0000-4000-8000-00000001';
$sfpUuid = 'sfp00000-0000-4000-8000-000000000001';
$configUuid2 = 'TEST-UB2B-' . substr(md5(uniqid('', true)), 0, 8);

function insertInv(PDO $pdo, $table, $uuid, $serial, $serverUuid, $extra = []) {
    $cols = array_merge(['UUID' => $uuid, 'SerialNumber' => $serial, 'Status' => 2, 'ServerUUID' => $serverUuid], $extra);
    $fields = array_keys($cols);
    $pdo->prepare("INSERT INTO `$table` (" . implode(',', $fields) . ') VALUES (' . implode(',', array_map(fn($f) => ":$f", $fields)) . ')')->execute($cols);
    return (int)$pdo->lastInsertId();
}

try {
    insertInv($pdo, 'motherboardinventory', $mbUuid, 'MB-SN-1', $configUuid);
    insertInv($pdo, 'chassisinventory', $chassisUuid, 'CH-SN-1', $configUuid);
    insertInv($pdo, 'cpuinventory', $cpuUuid, 'CPU-SN-1', $configUuid);
    // Second CPU inventory row, same UUID, for the quantity=2 expansion case.
    insertInv($pdo, 'cpuinventory', $cpuUuid, 'CPU-SN-2', $configUuid);
    insertInv($pdo, 'raminventory', $ramUuid, 'RAM-SN-1', $configUuid);
    // A SECOND ram row sharing UUID+ServerUUID -> makes the serial-less ram entry ambiguous.
    insertInv($pdo, 'raminventory', $ramUuid, 'RAM-SN-2', $configUuid);
    insertInv($pdo, 'storageinventory', $storageUuid, 'STG-SN-1', $configUuid);
    insertInv($pdo, 'storageinventory', $storageUuid2, 'STG-SN-2', $configUuid);
    insertInv($pdo, 'pciecardinventory', $riserPrefixUuid, 'RISER-SN-1', $configUuid);
    insertInv($pdo, 'pciecardinventory', $plainCardUuid, 'CARD-SN-1', $configUuid);
    insertInv($pdo, 'hbacardinventory', $hbaUuid, 'HBA-SN-1', $configUuid);
    insertInv($pdo, 'nicinventory', $nicUuid, 'NIC-SN-1', $configUuid);
    insertInv($pdo, 'nicinventory', $onboardNicUuid, 'ONBOARD-SN-1', $configUuid, ['ParentComponentUUID' => $mbUuid, 'SourceType' => 'onboard']);
    insertInv($pdo, 'sfpinventory', $sfpUuid, 'SFP-SN-1', $configUuid);
    insertInv($pdo, 'sfpinventory', 'sfp00000-0000-4000-8000-000000000002', 'SFP-SN-2', $configUuid);

    $configRow = [
        'config_uuid' => $configUuid,
        'motherboard_uuid' => $mbUuid,
        'chassis_uuid' => $chassisUuid,
        'cpu_configuration' => json_encode(['cpus' => [
            ['uuid' => $cpuUuid, 'quantity' => 2], // no serials in JSON -> expand via ServerUUID match
        ]]),
        'ram_configuration' => json_encode([
            ['uuid' => $ramUuid, 'quantity' => 1], // serial-less, 2 candidates -> ambiguous
        ]),
        'storage_configuration' => json_encode([
            ['uuid' => $storageUuid, 'connection' => ['bay' => 'bay_1']],
            ['uuid' => $storageUuid2], // no connection -> slot_ref null, NOT quarantined
        ]),
        'pciecard_configurations' => json_encode([
            ['uuid' => $riserPrefixUuid, 'slot_position' => 'pcie_1_x16'], // riser via uuid prefix
            ['uuid' => $plainCardUuid, 'slot_position' => 'riser_slot_1'], // plain card, riser-looking slot, single riser present -> parents to riser
        ]),
        'hbacard_config' => json_encode([['uuid' => $hbaUuid, 'slot_position' => null, 'serial_number' => null]]),
        'hbacard_uuid' => $hbaUuid, // both populated -> dedup must NOT double-extract
        'nic_config' => json_encode(['nics' => [
            ['uuid' => $nicUuid, 'slot_position' => 'pcie_x8_slot_1'],
            ['uuid' => $onboardNicUuid],
        ]]),
        'sfp_configuration' => json_encode([
            'sfps' => [['uuid' => $sfpUuid, 'parent_nic_uuid' => $nicUuid, 'port_index' => 1]],
            'unassigned_sfps' => [['uuid' => 'sfp00000-0000-4000-8000-000000000002']],
        ]),
        'caddy_configuration' => null,
    ];

    $extractor = new Extractor();
    $result = $extractor->extract($pdo, $configRow);
    $plans = $result['plans'];
    $quarantine = $result['quarantine'];

    $byType = [];
    foreach ($plans as $p) { $byType[$p['component_type']][] = $p; }
    $qByReason = array_count_values(array_column($quarantine, 'reason'));

    check('motherboard resolved', count($byType['motherboard'] ?? []) === 1);
    check('chassis resolved', count($byType['chassis'] ?? []) === 1);

    check('cpu quantity=2 expands to 2 rows', count($byType['cpu'] ?? []) === 2);
    check('cpu rows carry distinct serials', array_unique(array_column($byType['cpu'] ?? [], 'serial_number')) == array_column($byType['cpu'] ?? [], 'serial_number'));
    check('cpu rows parent to motherboard', ($byType['cpu'][0]['parent_ref'] ?? null) === 'motherboard');

    check('ram serial-less ambiguity quarantined', ($qByReason['ambiguous-serial'] ?? 0) >= 1);
    check('ram NOT emitted as a plan', empty($byType['ram']));

    check('storage with connection.bay gets slot_ref', ($byType['storage'][0]['slot_ref'] ?? null) === 'bay_1');
    $storageNoConn = array_values(array_filter($byType['storage'] ?? [], fn($p) => $p['spec_uuid'] === $storageUuid2));
    check('storage without connection resolves with null slot_ref (not quarantined)', count($storageNoConn) === 1 && $storageNoConn[0]['slot_ref'] === null);

    check('riser via uuid prefix retyped to riser', count($byType['riser'] ?? []) === 1 && $byType['riser'][0]['spec_uuid'] === $riserPrefixUuid);
    check('riser parents to motherboard', ($byType['riser'][0]['parent_ref'] ?? null) === 'motherboard');
    check('plain card left as pciecard', count($byType['pciecard'] ?? []) === 1);
    check('plain card w/ riser-looking slot parents to the single riser', ($byType['pciecard'][0]['parent_ref'] ?? null) === 'riser');

    check('hbacard dedup: exactly 1 hbacard plan despite both fields populated', count($byType['hbacard'] ?? []) === 1);
    check('hbacard parents to motherboard (no riser slot)', ($byType['hbacard'][0]['parent_ref'] ?? null) === 'motherboard');

    check('exactly 2 nic plans (regular + onboard)', count($byType['nic'] ?? []) === 2);
    $regularNic = array_values(array_filter($byType['nic'], fn($p) => $p['spec_uuid'] === $nicUuid))[0] ?? null;
    $onboardNic = array_values(array_filter($byType['nic'], fn($p) => $p['spec_uuid'] === $onboardNicUuid))[0] ?? null;
    check('regular nic parents to motherboard (F-5: matches live path + seeder 2026_07_21_002)', $regularNic && $regularNic['parent_ref'] === 'motherboard');
    check('regular nic gets slot_ref from slot_position (fixes slot_report slotless_card gap)', $regularNic && $regularNic['slot_ref'] === 'pcie_x8_slot_1');
    check('onboard nic parents to motherboard', $onboardNic && $onboardNic['parent_ref'] === 'motherboard');
    check('onboard nic has null slot_ref (no discrete slot)', $onboardNic && $onboardNic['slot_ref'] === null);

    check('assigned sfp gets port_ slot_ref + nic parent ref', ($byType['sfp'][0]['slot_ref'] ?? null) === 'port_1'
        && ($byType['sfp'][0]['parent_ref'] ?? null) === ['nic_spec_uuid' => $nicUuid]);
    $unassignedSfp = array_values(array_filter($byType['sfp'] ?? [], fn($p) => $p['spec_uuid'] === 'sfp00000-0000-4000-8000-000000000002'))[0] ?? null;
    check('unassigned sfp has null slot_ref and null parent_ref', $unassignedSfp && $unassignedSfp['slot_ref'] === null && $unassignedSfp['parent_ref'] === null);

    // ---- component_subtype riser detection (separate config, isolated fixture) ----
    insertInv($pdo, 'pciecardinventory', $subtypeRiserUuid, 'SUBRISER-SN-1', $configUuid2);
    $result2 = $extractor->extract($pdo, [
        'config_uuid' => $configUuid2,
        'pciecard_configurations' => json_encode([['uuid' => $subtypeRiserUuid]]),
    ]);
    $byType2 = [];
    foreach ($result2['plans'] as $p) { $byType2[$p['component_type']][] = $p; }
    check('component_subtype=Riser Card retypes to riser (no uuid prefix)', count($byType2['riser'] ?? []) === 1);

    // ---- missing-uuid quarantine ----
    $result3 = $extractor->extract($pdo, ['config_uuid' => 'TEST-UB2C', 'caddy_configuration' => json_encode([['not_uuid' => 'x']])]);
    check('missing uuid quarantined with distinct reason', ($result3['quarantine'][0]['reason'] ?? null) === 'missing-uuid');

    // ---- serial given but not found ----
    $result4 = $extractor->extract($pdo, ['config_uuid' => 'TEST-UB2D', 'ram_configuration' => json_encode([['uuid' => $ramUuid, 'serial_number' => 'NO-SUCH-SERIAL']])]);
    check('given serial not found quarantined distinctly', ($result4['quarantine'][0]['reason'] ?? null) === 'serial-not-found');

} finally {
    foreach (['motherboardinventory', 'chassisinventory', 'cpuinventory', 'raminventory', 'storageinventory',
              'pciecardinventory', 'hbacardinventory', 'nicinventory', 'sfpinventory'] as $t) {
        $pdo->prepare("DELETE FROM `$t` WHERE ServerUUID IN (?, ?)")->execute([$configUuid, $configUuid2]);
    }
    rrmdir($tmpImsData);
}

echo $fails === 0 ? "extractor_test: ALL PASS\n" : "extractor_test: $fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
