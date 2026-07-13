<?php
/**
 * resource_catalog_test.php — U-L.1 unit test.
 *
 * Exercises ResourceCatalog::provides() through the REAL DataExtractionUtilities
 * spec-loading path (not a mock), against throwaway fixture JSON files whose
 * shapes are copied verbatim from the extraction code this unit was scoped to
 * read (UnifiedSlotTracker::loadMotherboardPCIeSlots/loadMotherboardM2Slots/
 * loadMotherboardRiserSlots/loadRiserCardProvidedPCIeSlots,
 * ServerBuilder::getChassisPsuWattage, DataExtractionUtilities::findChassisInData/
 * findInBrandModels/findInCategoryModels) — never invented field names.
 *
 * Exit 0 = all pass; exit 1 = a failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// -----------------------------------------------------------------------
// Build a throwaway ims-data fixture tree and point IMS_DATA_PATH at it.
// -----------------------------------------------------------------------
$tmpImsData = sys_get_temp_dir() . '/ims-data-resource-catalog-' . getmypid();
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

$chassisUuid = 'c1a2b3c4-0000-4000-8000-000000000001';
$mbUuidNormal = 'm1a2b3c4-0000-4000-8000-000000000001';
$mbUuidLegacyRiser = 'm1a2b3c4-0000-4000-8000-000000000002';
$mbUuidMalformed = 'm1a2b3c4-0000-4000-8000-000000000003';
$mbUuidNoExpansion = 'm1a2b3c4-0000-4000-8000-000000000004';
$riserUuid = 'p1a2b3c4-0000-4000-8000-000000000001';
$plainPciecardUuid = 'p1a2b3c4-0000-4000-8000-000000000002';
$cpuUuidWithLanes = 'a1a2b3c4-0000-4000-8000-000000000001';
$cpuUuidNoLanes = 'a1a2b3c4-0000-4000-8000-000000000002';
$cpuUuidBadLanes = 'a1a2b3c4-0000-4000-8000-000000000003';
$nicUuidWithPorts = 'n1a2b3c4-0000-4000-8000-000000000001';
$nicUuidNoPorts = 'n1a2b3c4-0000-4000-8000-000000000002';
$nicUuidBadPorts = 'n1a2b3c4-0000-4000-8000-000000000003';
$hbaUuidWithInterface = 'h1a2b3c4-0000-4000-8000-000000000001';
$hbaUuidNoLaneFields = 'h1a2b3c4-0000-4000-8000-000000000002';
$pciecardUuidLaneFallback = 'p1a2b3c4-0000-4000-8000-000000000003';
$pciecardUuidUnparseableInterfaceFallback = 'p1a2b3c4-0000-4000-8000-000000000004';
// RV-4 fixtures: M.2 NVMe must consume 0 pcie_lane; U.2/AIC NVMe still consumes lanes.
$storageUuidM2Nvme = 's1a2b3c4-0000-4000-8000-000000000001';
$storageUuidU2Nvme = 's1a2b3c4-0000-4000-8000-000000000002';
$storageUuidSata = 's1a2b3c4-0000-4000-8000-000000000003';

mkdir("$tmpImsData/chassis", 0777, true);
mkdir("$tmpImsData/motherboard", 0777, true);
mkdir("$tmpImsData/pciecard", 0777, true);
mkdir("$tmpImsData/cpu", 0777, true);
mkdir("$tmpImsData/nic", 0777, true);
mkdir("$tmpImsData/hbacard", 0777, true);
mkdir("$tmpImsData/storage", 0777, true);

file_put_contents("$tmpImsData/chassis/chasis-level-3.json", json_encode([
    'chassis_specifications' => [
        'manufacturers' => [
            [
                'manufacturer' => 'Dell',
                'series' => [
                    [
                        'series_name' => 'PowerEdge',
                        'models' => [
                            ['uuid' => $chassisUuid, 'power_supply' => ['wattage' => 750]],
                        ],
                    ],
                ],
            ],
        ],
    ],
]));

file_put_contents("$tmpImsData/motherboard/motherboard-level-3.json", json_encode([
    [
        'brand' => 'Supermicro',
        'models' => [
            [
                'uuid' => $mbUuidNormal,
                'expansion_slots' => [
                    'pcie_slots' => [
                        ['type' => 'PCIe 4.0 x16', 'count' => 2],
                        ['type' => 'PCIe 4.0 x8', 'count' => 1],
                    ],
                    'riser_slots' => [
                        ['type' => 'PCIe x16 Riser', 'count' => 1],
                    ],
                ],
                'storage' => [
                    'nvme' => [
                        // P3.1 lesson: two entries, must SUM (2 + 2 = 4), not take the first (2).
                        'm2_slots' => [
                            ['count' => 2, 'type' => 'M.2 NVMe', 'form_factor' => '2280'],
                            ['count' => 2, 'type' => 'M.2 NVMe', 'form_factor' => '2242'],
                        ],
                    ],
                ],
            ],
            [
                'uuid' => $mbUuidLegacyRiser,
                'expansion_slots' => [
                    'pcie_slots' => [['type' => 'PCIe 3.0 x16', 'count' => 1]],
                    'riser_compatibility' => ['max_risers' => 2],
                ],
            ],
            [
                'uuid' => $mbUuidMalformed,
                'expansion_slots' => [
                    'pcie_slots' => [['type' => 'PCIe 4.0 x16', 'count' => 'not-a-number']],
                ],
            ],
            [
                'uuid' => $mbUuidNoExpansion,
                'expansion_slots' => ['pcie_slots' => []],
            ],
        ],
    ],
]));

file_put_contents("$tmpImsData/cpu/Cpu-details-level-3.json", json_encode([
    [
        'brand' => 'Intel',
        'models' => [
            ['uuid' => $cpuUuidWithLanes, 'pcie_lanes' => 64],
            ['uuid' => $cpuUuidNoLanes],
            ['uuid' => $cpuUuidBadLanes, 'pcie_lanes' => 'not-a-number'],
        ],
    ],
]));

file_put_contents("$tmpImsData/pciecard/pci-level-3.json", json_encode([
    [
        'component_subtype' => 'Riser Card',
        'brand' => 'Supermicro',
        'models' => [
            ['UUID' => $riserUuid, 'pcie_slots' => 2, 'slot_type' => 'x16'],
        ],
    ],
    [
        'component_subtype' => 'Standard PCIe Card',
        'brand' => 'Intel',
        'models' => [
            ['UUID' => $plainPciecardUuid],
            ['UUID' => $pciecardUuidLaneFallback, 'pcie_lanes' => 8],
            ['UUID' => $pciecardUuidUnparseableInterfaceFallback, 'interface' => 'PCIe Gen4', 'pcie_lanes' => 8],
        ],
    ],
]));

file_put_contents("$tmpImsData/nic/nic-level-3.json", json_encode([
    [
        'brand' => 'Intel',
        'series' => [
            [
                'name' => 'X710',
                'models' => [
                    ['uuid' => $nicUuidWithPorts, 'ports' => 4],
                    ['uuid' => $nicUuidNoPorts],
                    ['uuid' => $nicUuidBadPorts, 'ports' => 'not-a-number'],
                ],
            ],
        ],
    ],
]));

file_put_contents("$tmpImsData/hbacard/hbacard-level-3.json", json_encode([
    [
        'brand' => 'Broadcom',
        'models' => [
            ['UUID' => $hbaUuidWithInterface, 'interface' => 'PCIe 4.0 x8'],
            ['UUID' => $hbaUuidNoLaneFields],
        ],
    ],
]));

file_put_contents("$tmpImsData/storage/storage-level-3.json", json_encode([
    [
        'brand' => 'Samsung',
        'models' => [
            ['uuid' => $storageUuidM2Nvme, 'interface' => 'PCIe 4.0 NVMe', 'form_factor' => 'M.2 2280'],
            ['uuid' => $storageUuidU2Nvme, 'interface' => 'PCIe 4.0 NVMe', 'form_factor' => 'U.2'],
            ['uuid' => $storageUuidSata, 'interface' => 'SATA III', 'form_factor' => '2.5"'],
        ],
    ],
]));

putenv("IMS_DATA_PATH=$tmpImsData");

try {
    $catalog = new ResourceCatalog();

    // ---- chassis: psu_watt ---------------------------------------------
    $rows = $catalog->provides('chassis', $chassisUuid);
    check('chassis: exactly 1 row (psu_watt)', count($rows) === 1);
    check('chassis: resource=psu_watt', ($rows[0]['resource'] ?? null) === 'psu_watt');
    check('chassis: slot_ref is null (pooled capacity)', array_key_exists('slot_ref', $rows[0]) && $rows[0]['slot_ref'] === null);
    check('chassis: capacity=750', ($rows[0]['capacity'] ?? null) === 750);

    // ---- motherboard: pcie_slot + m2_slot (summed) + riser_slot --------
    $rows = $catalog->provides('motherboard', $mbUuidNormal);
    $pcieRows = array_values(array_filter($rows, fn($r) => $r['resource'] === 'pcie_slot'));
    $m2Rows = array_values(array_filter($rows, fn($r) => $r['resource'] === 'm2_slot'));
    $riserRows = array_values(array_filter($rows, fn($r) => $r['resource'] === 'riser_slot'));

    check('motherboard: 3 pcie_slot rows (2x16 + 1x8)', count($pcieRows) === 3);
    check('motherboard: pcie slot_refs are pcie_1_x16, pcie_2_x16, pcie_3_x8', array_column($pcieRows, 'slot_ref') === ['pcie_1_x16', 'pcie_2_x16', 'pcie_3_x8']);
    check('motherboard: each pcie_slot row has capacity 1', array_unique(array_column($pcieRows, 'capacity')) === [1]);

    check('motherboard: exactly 1 m2_slot row (pooled)', count($m2Rows) === 1);
    check('motherboard: m2_slot capacity is SUMMED (2+2=4), not first entry only (2)', ($m2Rows[0]['capacity'] ?? null) === 4);
    check('motherboard: m2_slot slot_ref is null (pooled)', array_key_exists('slot_ref', $m2Rows[0]) && $m2Rows[0]['slot_ref'] === null);

    check('motherboard: 1 riser_slot row from riser_slots array', count($riserRows) === 1);
    check('motherboard: riser slot_ref is riser_1_x16', ($riserRows[0]['slot_ref'] ?? null) === 'riser_1_x16');

    // ---- motherboard: legacy riser_compatibility.max_risers fallback ----
    $rows = $catalog->provides('motherboard', $mbUuidLegacyRiser);
    $riserRows = array_values(array_filter($rows, fn($r) => $r['resource'] === 'riser_slot'));
    check('motherboard (legacy riser fallback): 2 riser_slot rows from max_risers=2', count($riserRows) === 2);
    check('motherboard (legacy riser fallback): slot_refs are riser_1_x16, riser_2_x16', array_column($riserRows, 'slot_ref') === ['riser_1_x16', 'riser_2_x16']);

    // ---- motherboard: no expansion data -> only default cpu_socket/dimm_slot rows, no throw ----
    $rows = $catalog->provides('motherboard', $mbUuidNoExpansion);
    $pcieRows = array_values(array_filter($rows, fn($r) => $r['resource'] === 'pcie_slot'));
    $m2Rows = array_values(array_filter($rows, fn($r) => $r['resource'] === 'm2_slot'));
    $riserRows = array_values(array_filter($rows, fn($r) => $r['resource'] === 'riser_slot'));
    $cpuSocketRows = array_values(array_filter($rows, fn($r) => $r['resource'] === 'cpu_socket'));
    $dimmSlotRows = array_values(array_filter($rows, fn($r) => $r['resource'] === 'dimm_slot'));
    check('motherboard (empty expansion_slots): no pcie/m2/riser rows', $pcieRows === [] && $m2Rows === [] && $riserRows === []);
    check('motherboard (empty expansion_slots): exactly 1 cpu_socket row, default capacity 1', count($cpuSocketRows) === 1 && $cpuSocketRows[0]['capacity'] === 1);
    check('motherboard (empty expansion_slots): exactly 1 dimm_slot row, default capacity 4', count($dimmSlotRows) === 1 && $dimmSlotRows[0]['capacity'] === 4);
    check('motherboard (empty expansion_slots): exactly 2 rows total (cpu_socket + dimm_slot only)', count($rows) === 2);

    // ---- motherboard: malformed count throws (fail-closed) --------------
    $threw = false;
    try {
        $catalog->provides('motherboard', $mbUuidMalformed);
    } catch (CatalogException $e) {
        $threw = true;
    }
    check('motherboard (non-numeric pcie_slots count): throws CatalogException', $threw);

    // ---- pciecard: riser provides pcie_slot rows ------------------------
    $rows = $catalog->provides('pciecard', $riserUuid);
    check('riser pciecard: 2 pcie_slot rows', count($rows) === 2);
    check('riser pciecard: slot_refs are riser_provided_pcie_1_x16, riser_provided_pcie_2_x16', array_column($rows, 'slot_ref') === ['riser_provided_pcie_1_x16', 'riser_provided_pcie_2_x16']);

    // ---- pciecard: plain card provides nothing --------------------------
    $rows = $catalog->provides('pciecard', $plainPciecardUuid);
    check('plain pciecard: provides no resources', $rows === []);

    // ---- cpu: pcie_lane provider (U-L.4) ---------------------------------
    $rows = $catalog->provides('cpu', $cpuUuidWithLanes);
    check('cpu (pcie_lanes=64): exactly 1 row', count($rows) === 1);
    check('cpu: resource=pcie_lane', ($rows[0]['resource'] ?? null) === 'pcie_lane');
    check('cpu: slot_ref is null (pooled capacity)', array_key_exists('slot_ref', $rows[0]) && $rows[0]['slot_ref'] === null);
    check('cpu: capacity=64', ($rows[0]['capacity'] ?? null) === 64);

    check('cpu (no pcie_lanes field): returns [] without throwing', $catalog->provides('cpu', $cpuUuidNoLanes) === []);

    $threw = false;
    try {
        $catalog->provides('cpu', $cpuUuidBadLanes);
    } catch (CatalogException $e) {
        $threw = true;
    }
    check('cpu (non-numeric pcie_lanes): throws CatalogException', $threw);

    // ---- nic: sfp_port provider (U-L.5) ----------------------------------
    $rows = $catalog->provides('nic', $nicUuidWithPorts);
    check('nic (ports=4): exactly 1 row', count($rows) === 1);
    check('nic: resource=sfp_port', ($rows[0]['resource'] ?? null) === 'sfp_port');
    check('nic: slot_ref is null (pooled capacity)', array_key_exists('slot_ref', $rows[0]) && $rows[0]['slot_ref'] === null);
    check('nic: capacity=4', ($rows[0]['capacity'] ?? null) === 4);

    check('nic (no ports field): returns [] without throwing', $catalog->provides('nic', $nicUuidNoPorts) === []);

    $threw = false;
    try {
        $catalog->provides('nic', $nicUuidBadPorts);
    } catch (CatalogException $e) {
        $threw = true;
    }
    check('nic (non-numeric ports): throws CatalogException', $threw);

    // ---- nic/hbacard/pciecard: pcie_lane consumption (U-L.5) -------------
    $rows = $catalog->consumes('hbacard', $hbaUuidWithInterface);
    check('hbacard (interface="PCIe 4.0 x8"): exactly 1 pcie_lane row', count($rows) === 1);
    check('hbacard: resource=pcie_lane', ($rows[0]['resource'] ?? null) === 'pcie_lane');
    check('hbacard: amount=8', ($rows[0]['amount'] ?? null) === 8);

    check('hbacard (no interface/pcie_lanes field): returns [] (0 lanes, not a throw)', $catalog->consumes('hbacard', $hbaUuidNoLaneFields) === []);

    // U-L.6 fix: an ABSENT interface candidate returns 0 immediately and never
    // reaches the pcie_lanes fallback -- this now mirrors
    // PcieLaneBudgetValidator::extractLaneCount() exactly (was a documented
    // latent divergence from the U-L.4/U-L.5 verify pass; see
    // migration/handoffs/U-L.6-20260712.md).
    check('pciecard (no interface field, numeric pcie_lanes=8): returns [] (absent candidate short-circuits before the fallback, matches legacy)', $catalog->consumes('pciecard', $pciecardUuidLaneFallback) === []);

    // The pcie_lanes fallback IS reachable, but only via a NON-empty interface
    // string that fails the /x(\d+)/i regex -- this is the real legacy path.
    $rows = $catalog->consumes('pciecard', $pciecardUuidUnparseableInterfaceFallback);
    check('pciecard (interface="PCIe Gen4" unparseable, numeric pcie_lanes=8 fallback): exactly 1 pcie_lane row', count($rows) === 1);
    check('pciecard: amount=8 (from pcie_lanes fallback via unparseable interface string)', ($rows[0]['amount'] ?? null) === 8);

    check('pciecard (no interface, no pcie_lanes): returns [] (0 lanes, not a throw)', $catalog->consumes('pciecard', $plainPciecardUuid) === []);

    $threw = false;
    try {
        $catalog->consumes('nic', 'no-such-nic-uuid');
    } catch (CatalogException $e) {
        $threw = true;
    }
    check('nic consumption: unknown UUID still throws CatalogException (spec not found, not 0-lane)', $threw);

    // ---- storage: pcie_lane consumption, M.2 exclusion (RV-4 fix) --------
    // Mirrors PcieLaneBudgetValidator.php:212-213 -- M.2 NVMe rides dedicated
    // chipset lanes, not the expansion budget, so it must consume 0 pcie_lane.
    check('storage (M.2 NVMe): consumes() returns [] (0 pcie_lane, dedicated chipset lanes)', $catalog->consumes('storage', $storageUuidM2Nvme) === []);

    $rows = $catalog->consumes('storage', $storageUuidU2Nvme);
    check('storage (U.2 NVMe): exactly 1 pcie_lane row', count($rows) === 1);
    check('storage (U.2 NVMe): resource=pcie_lane', ($rows[0]['resource'] ?? null) === 'pcie_lane');
    check('storage (U.2 NVMe): amount=4 (default NVMe lane count)', ($rows[0]['amount'] ?? null) === 4);

    check('storage (SATA): consumes() returns [] (SATA does not use PCIe lanes)', $catalog->consumes('storage', $storageUuidSata) === []);

    // ---- types confirmed to provide nothing ------------------------------
    foreach (['ram', 'storage', 'caddy', 'hbacard', 'sfp'] as $type) {
        check("$type: provides() returns [] (confirmed no resources)", $catalog->provides($type, 'any-uuid') === []);
    }

    // ---- unknown component_type ------------------------------------------
    $threw = false;
    try {
        $catalog->provides('not-a-real-type', 'any-uuid');
    } catch (CatalogException $e) {
        $threw = true;
    }
    check('unknown component_type: throws CatalogException', $threw);

    // ---- spec not found ----------------------------------------------------
    $threw = false;
    try {
        $catalog->provides('chassis', 'no-such-uuid');
    } catch (CatalogException $e) {
        $threw = true;
    }
    check('chassis: unknown UUID throws CatalogException (spec not found)', $threw);

} finally {
    rrmdir($tmpImsData);
}

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
