<?php
/**
 * onboard_nic_engine_test.php — onboard-NIC engine handling unit test
 * (fleet-parity Finding C, 2026-07-12: synthetic onboard-{mb}-{n} spec_uuids
 * made every catalog-backed rule throw CatalogException and fail closed).
 *
 * Proves, DB-free, through the REAL DataExtractionUtilities spec-loading path
 * against a throwaway ims-data fixture (same technique as
 * resource_catalog_test.php):
 *   - ResourceCatalog::provides()/consumes() return [] (never throw) for
 *     synthetic onboard NIC uuids, in BOTH synthetic formats
 *     ("onboard-{mb8}-{n}" and "onboard-nic-{mb24}-{n}");
 *   - providesOnboardNic() resolves sfp_port capacity via the parent board's
 *     networking.onboard_nics (mirror of NICPortTracker::resolveOnboardNicSpecs),
 *     failing OPEN ([]) on any unresolvable step, exactly like legacy;
 *   - TargetState::resources()/poolBalance()/freeSlots() no longer throw on a
 *     state containing an onboard NIC, resolve its ports via parent_id
 *     (rows path) or uuid-prefix board match (json fallback path), and
 *     TargetStateBuilder::dependentsOf() survives an onboard root.
 *
 * Exit 0 = all pass; exit 1 = a failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once $ROOT . '/core/models/validation/TargetState.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// -----------------------------------------------------------------------
// Throwaway ims-data fixture with one board carrying two onboard NICs.
// -----------------------------------------------------------------------
$tmpImsData = sys_get_temp_dir() . '/ims-data-onboard-nic-' . getmypid();
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
mkdir("$tmpImsData/nic", 0777, true);

$mbUuid = '4f8e6c3d-0000-4000-8000-000000000001';
file_put_contents("$tmpImsData/motherboard/motherboard-level-3.json", json_encode([
    [
        'brand' => 'Supermicro',
        'models' => [
            [
                'uuid' => $mbUuid,
                'expansion_slots' => ['pcie_slots' => [['type' => 'PCIe 4.0 x16', 'count' => 1]]],
                'networking' => [
                    'onboard_nics' => [
                        ['controller' => 'Broadcom BCM57414', 'ports' => 2, 'speed' => '10GbE', 'connector' => 'SFP+'],
                        ['controller' => 'Intel i350', 'ports' => 4, 'speed' => '1GbE', 'connector' => 'RJ45'],
                    ],
                ],
            ],
        ],
    ],
]));
file_put_contents("$tmpImsData/nic/nic-level-3.json", json_encode([]));

putenv("IMS_DATA_PATH=$tmpImsData");

$catalog = new ResourceCatalog();
$onboard1 = 'onboard-4f8e6c3d-1';           // LEGACY model-scoped format (8-char prefix)
$onboard2 = 'onboard-nic-' . substr($mbUuid, 0, 24) . '-2'; // LEGACY ServerBuilder format (24-char prefix)
$onboard3 = 'onboard-4f8e6c3d-55-1';        // CURRENT unit-scoped format (board inventory id 55)

// -----------------------------------------------------------------------
// 1. uuid recognition / parsing
// -----------------------------------------------------------------------
echo "uuid recognition:\n";
check('isOnboardNicUuid true for onboard- prefix', ResourceCatalog::isOnboardNicUuid($onboard1));
check('isOnboardNicUuid true for onboard-nic- prefix', ResourceCatalog::isOnboardNicUuid($onboard2));
check('isOnboardNicUuid true for unit-scoped format', ResourceCatalog::isOnboardNicUuid($onboard3));
check('isOnboardNicUuid false for a real uuid', !ResourceCatalog::isOnboardNicUuid($mbUuid));
$p1 = ResourceCatalog::parseOnboardNicUuid($onboard1);
$p2 = ResourceCatalog::parseOnboardNicUuid($onboard2);
$p3 = ResourceCatalog::parseOnboardNicUuid($onboard3);
check('parse 8-char legacy: prefix + index, no inventory id',
    $p1 === ['board_prefix' => '4f8e6c3d', 'inventory_id' => null, 'index' => 1]);
check('parse 24-char legacy: prefix + index, no inventory id',
    $p2 === ['board_prefix' => substr($mbUuid, 0, 24), 'inventory_id' => null, 'index' => 2]);
check('parse unit-scoped: prefix + inventory id + index',
    $p3 === ['board_prefix' => '4f8e6c3d', 'inventory_id' => 55, 'index' => 1]);
check('parse rejects malformed uuid', ResourceCatalog::parseOnboardNicUuid('onboard-') === null);

// board_prefix must stay the motherboard SPEC-uuid prefix across BOTH formats,
// otherwise TargetState::onboardParentBoardSpecUuid()'s json-fallback prefix
// match silently stops resolving the parent board (= lost sfp_port capacity).
check('unit-scoped board_prefix still prefixes the board spec uuid',
    strpos('4f8e6c3d-1111-2222-3333-444455556666', $p3['board_prefix']) === 0);

// REGRESSION (2026-07-20): two physical boards of ONE model must mint two
// distinct identities. Under the old model-scoped scheme both produced
// "onboard-4f8e6c3d-1" and the 2nd board's INSERT died on the SerialNumber
// UNIQUE key, silently leaving that server with no onboard NICs.
$board49 = 'onboard-4f8e6c3d-49-1';
$board55 = 'onboard-4f8e6c3d-55-1';
check('two boards, one model -> distinct uuids', $board49 !== $board55);
check('board 49 parses to inventory id 49',
    ResourceCatalog::parseOnboardNicUuid($board49)['inventory_id'] === 49);
check('board 55 parses to inventory id 55',
    ResourceCatalog::parseOnboardNicUuid($board55)['inventory_id'] === 55);
check('both resolve to the same parent board spec (same model)',
    ResourceCatalog::parseOnboardNicUuid($board49)['board_prefix']
    === ResourceCatalog::parseOnboardNicUuid($board55)['board_prefix']);

// -----------------------------------------------------------------------
// 2. catalog never throws on onboard uuids
// -----------------------------------------------------------------------
echo "catalog skip behavior:\n";
try {
    check('provides(nic, onboard) === []', $catalog->provides('nic', $onboard1) === []);
    check('consumes(nic, onboard) === [] (legacy lane-budget skip)', $catalog->consumes('nic', $onboard1) === []);
    check('provides(nic, onboard-nic-…) === []', $catalog->provides('nic', $onboard2) === []);
} catch (\Throwable $e) {
    check('catalog threw on onboard uuid: ' . $e->getMessage(), false);
}

// -----------------------------------------------------------------------
// 3. providesOnboardNic resolution via parent board (fail-open on failure)
// -----------------------------------------------------------------------
echo "providesOnboardNic:\n";
$rows = $catalog->providesOnboardNic($onboard1, $mbUuid);
check('resolves nic #1 -> 2 sfp ports', $rows === [['resource' => 'sfp_port', 'slot_ref' => null, 'capacity' => 2]]);
$rows = $catalog->providesOnboardNic($onboard2, $mbUuid);
check('resolves nic #2 -> 4 sfp ports', $rows === [['resource' => 'sfp_port', 'slot_ref' => null, 'capacity' => 4]]);
check('index out of range -> [] (fail-open)', $catalog->providesOnboardNic('onboard-4f8e6c3d-9', $mbUuid) === []);
check('null board -> [] (fail-open)', $catalog->providesOnboardNic($onboard1, null) === []);
check('unknown board -> [] (fail-open)', $catalog->providesOnboardNic($onboard1, 'no-such-board') === []);
check('malformed uuid -> [] (fail-open)', $catalog->providesOnboardNic('onboard-', $mbUuid) === []);

// -----------------------------------------------------------------------
// 4. TargetState with an onboard NIC — rows path (parent_id linkage)
// -----------------------------------------------------------------------
echo "TargetState rows path:\n";
function tuple($id, $type, $spec, $parent = null, $slotRef = null) {
    return [
        'id' => $id, 'component_type' => $type, 'spec_uuid' => $spec,
        'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null,
        'parent_id' => $parent, 'slot_ref' => $slotRef, 'source' => 'rows', 'status_v2' => null,
    ];
}
$state = new TargetState([
    tuple(1, 'motherboard', $mbUuid),
    tuple(2, 'nic', $onboard1, 1),
]);
try {
    $resources = $state->resources();
    $sfpPorts = array_values(array_filter($resources, function ($r) { return $r['resource'] === 'sfp_port'; }));
    check('resources() does not throw', true);
    check('onboard nic contributes 2 expanded sfp_port rows', count($sfpPorts) === 2);
    check('sfp_port rows owned by the onboard nic row', $sfpPorts && $sfpPorts[0]['owner_component_id'] === 2);
    check('freeSlots(sfp_port) sees both ports free', count($state->freeSlots('sfp_port')) === 2);
    check('poolBalance(pcie_lane) does not count onboard nic', $state->poolBalance('pcie_lane') === 0);
} catch (\Throwable $e) {
    check('TargetState threw on onboard nic (rows path): ' . get_class($e) . ': ' . $e->getMessage(), false);
}

// -----------------------------------------------------------------------
// 5. TargetState json fallback path (no parent_id -> uuid-prefix board match)
// -----------------------------------------------------------------------
echo "TargetState json fallback path:\n";
$stateJson = new TargetState([
    tuple(-1, 'motherboard', $mbUuid),
    tuple(-2, 'nic', $onboard1, null),
]);
try {
    $sfpPorts = array_values(array_filter($stateJson->resources(), function ($r) { return $r['resource'] === 'sfp_port'; }));
    check('prefix-matched board resolves ports without parent_id', count($sfpPorts) === 2);
} catch (\Throwable $e) {
    check('TargetState threw on onboard nic (json path): ' . get_class($e) . ': ' . $e->getMessage(), false);
}
$stateOrphan = new TargetState([tuple(-2, 'nic', $onboard1, null)]);
try {
    check('orphan onboard nic (no board in state) -> no ports, no throw',
        $stateOrphan->resources() === [] && $stateOrphan->poolBalance('pcie_lane') === 0);
} catch (\Throwable $e) {
    check('TargetState threw on orphan onboard nic: ' . get_class($e) . ': ' . $e->getMessage(), false);
}

// -----------------------------------------------------------------------
// 6. dependentsOf survives an onboard-NIC node
// -----------------------------------------------------------------------
echo "TargetStateBuilder::dependentsOf:\n";
require_once $ROOT . '/core/models/validation/TargetStateBuilder.php';
try {
    $deps = TargetStateBuilder::dependentsOf($state, 1); // board -> onboard nic is parent-linked
    check('dependentsOf(board) includes the onboard nic without throwing',
        count($deps) === 1 && $deps[0]['id'] === 2);
} catch (\Throwable $e) {
    check('dependentsOf threw: ' . get_class($e) . ': ' . $e->getMessage(), false);
}

rrmdir($tmpImsData);
echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
