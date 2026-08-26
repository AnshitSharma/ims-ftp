<?php
/**
 * platform_spec_resolution_test.php
 *
 * A board or chassis that ships INSIDE a server compute platform is described in
 * ims-data/serverplatform/server-platform-level-3.json, not in motherboard-level-3.json
 * or chasis-level-3.json. Four independent spec resolvers exist in this codebase, and
 * historically only some of them knew that -- which is how config 831f1be8 (Dell R740xd,
 * platform-imported) ended up reporting drive_bays.total = 0 and calling EVERY drive in
 * the inventory incompatible: ComponentDataLoader and ChassisManager returned null for
 * the platform chassis, so $storageRequirements['chassis_bays'] was empty and
 * ComponentValidator::validateChassisBayStorage() rejected every candidate.
 *
 * This test pins all four resolvers to the same answer. Real ims-data fixtures, no DB.
 * Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/components/ComponentSpecPaths.php';
require_once $ROOT . '/core/models/components/PlatformSpecIndex.php';
require_once $ROOT . '/core/models/components/ComponentDataExtractor.php';
require_once $ROOT . '/core/models/components/ComponentDataLoader.php';
require_once $ROOT . '/core/models/chassis/ChassisManager.php';
require_once $ROOT . '/core/models/shared/DataExtractionUtilities.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// The exact production fixtures from config 831f1be8 (Dell PowerEdge R740xd).
const PLATFORM_CHASSIS = 'd0986f76-0666-500d-a538-d3b3b635f753'; // 16 bays: 12x 3.5" + 4x 2.5"
const PLATFORM_BOARD   = '9325d0ba-7e58-5f87-8bde-b7dd79a21602'; // 24 DIMM slots
const EXPECTED_BAYS    = 16;
const EXPECTED_SLOTS   = 24;

echo "\n-- Fixture guard (an ims-data edit must fail here, not silently pass below) --\n";

$chassisSpec = PlatformSpecIndex::find('chassis', PLATFORM_CHASSIS);
$boardSpec   = PlatformSpecIndex::find('motherboard', PLATFORM_BOARD);

check('platform chassis ' . substr(PLATFORM_CHASSIS, 0, 8) . ' is in the platform catalog', is_array($chassisSpec));
check('platform board '   . substr(PLATFORM_BOARD, 0, 8)   . ' is in the platform catalog', is_array($boardSpec));
check('fixture chassis still has ' . EXPECTED_BAYS . ' total bays',
    ($chassisSpec['drive_bays']['total_bays'] ?? null) === EXPECTED_BAYS);
check('fixture board still has ' . EXPECTED_SLOTS . ' DIMM slots',
    ($boardSpec['memory']['slots'] ?? null) === EXPECTED_SLOTS);

// The whole point of the platform catalog: these UUIDs are NOT in the ordinary catalogs.
$chassisCatalog = json_decode(file_get_contents(ComponentSpecPaths::getPath('chassis')), true);
$inOwnCatalog = false;
foreach ($chassisCatalog['chassis_specifications']['manufacturers'] ?? [] as $mfr) {
    foreach ($mfr['series'] ?? [] as $series) {
        foreach ($series['models'] ?? [] as $model) {
            if (($model['uuid'] ?? '') === PLATFORM_CHASSIS) { $inOwnCatalog = true; }
        }
    }
}
check('platform chassis is absent from chasis-level-3.json (so a blind resolver WOULD fail)', !$inOwnCatalog);

if ($fails > 0) {
    echo "\nFIXTURE GUARD FAILED -- ims-data no longer matches this test's assumptions.\n";
    exit(1);
}

echo "\n-- Resolver 1: DataExtractionUtilities (every ValidationEngine rule reaches specs here) --\n";
$dataUtils = new DataExtractionUtilities();
// findComponentByUuid() is private -- it is the single seam every rule reaches specs
// through, so it is what must be pinned, not a public convenience wrapper.
$findByUuid = new ReflectionMethod('DataExtractionUtilities', 'findComponentByUuid');
$findByUuid->setAccessible(true);
$duChassis = $findByUuid->invoke($dataUtils, 'chassis', PLATFORM_CHASSIS);
$duBoard   = $dataUtils->getMotherboardByUUID(PLATFORM_BOARD);
check('resolves the platform chassis', is_array($duChassis));
check('reports ' . EXPECTED_BAYS . ' bays', ($duChassis['drive_bays']['total_bays'] ?? null) === EXPECTED_BAYS);
check('resolves the platform board', is_array($duBoard));
check('reports ' . EXPECTED_SLOTS . ' DIMM slots', ($duBoard['memory']['slots'] ?? null) === EXPECTED_SLOTS);

echo "\n-- Resolver 2: ComponentDataLoader (feeds the legacy ComponentCompatibility universe) --\n";
$loader = new ComponentDataLoader(null, new ComponentDataExtractor());

$cdlChassisRaw = $loader->loadJSONData('chassis', PLATFORM_CHASSIS);
check('loadJSONData() resolves the platform chassis', is_array($cdlChassisRaw));
check('loadJSONData() reports ' . EXPECTED_BAYS . ' bays',
    ($cdlChassisRaw['drive_bays']['total_bays'] ?? null) === EXPECTED_BAYS);

$cdlFromJson = $loader->loadComponentFromJSON('chassis', PLATFORM_CHASSIS);
check('loadComponentFromJSON() finds the platform chassis', !empty($cdlFromJson['found']));

check('validateComponentExistsInJSON() accepts the platform chassis',
    $loader->validateComponentExistsInJSON('chassis', PLATFORM_CHASSIS) === true);
check('validateComponentExistsInJSON() accepts the platform board',
    $loader->validateComponentExistsInJSON('motherboard', PLATFORM_BOARD) === true);

// Extractor parity: these two go through extract*Specifications(), same as catalog parts,
// so a caller cannot tell a platform-owned spec from a loose spare.
$cdlChassis = $loader->loadChassisSpecs(PLATFORM_CHASSIS);
check('loadChassisSpecs() returns EXTRACTED specs (not the raw model)',
    is_array($cdlChassis) && array_key_exists('form_factor', $cdlChassis) && array_key_exists('backplane', $cdlChassis));
check('loadChassisSpecs() reports ' . EXPECTED_BAYS . ' bays',
    ($cdlChassis['drive_bays']['total_bays'] ?? null) === EXPECTED_BAYS);

$cdlBoard = $loader->loadMotherboardSpecs(PLATFORM_BOARD);
check('loadMotherboardSpecs() resolves the platform board', is_array($cdlBoard));

$cdlChassisData = $loader->getChassisData(PLATFORM_CHASSIS);
check('getChassisData() resolves the platform chassis',
    is_array($cdlChassisData) && ($cdlChassisData['drive_bays']['total_bays'] ?? null) === EXPECTED_BAYS);

echo "\n-- Resolver 3: ChassisManager (getStorageConnectivity -> drive_bays.total) --\n";
$chassisMgr = new ChassisManager();
$cmResult = $chassisMgr->loadChassisSpecsByUUID(PLATFORM_CHASSIS);
check('loadChassisSpecsByUUID() finds the platform chassis', !empty($cmResult['found']));
// This is the exact expression ServerBuilder::getStorageConnectivity() evaluates.
check('drive_bays.total_bays reads ' . EXPECTED_BAYS . ', not 0 (the reported symptom)',
    ($cmResult['specifications']['drive_bays']['total_bays'] ?? 0) === EXPECTED_BAYS);

echo "\n-- Cross-resolver agreement (the property that actually matters) --\n";
$bayCounts = [
    'PlatformSpecIndex'       => $chassisSpec['drive_bays']['total_bays'] ?? null,
    'DataExtractionUtilities' => $duChassis['drive_bays']['total_bays'] ?? null,
    'ComponentDataLoader'     => $cdlChassis['drive_bays']['total_bays'] ?? null,
    'ChassisManager'          => $cmResult['specifications']['drive_bays']['total_bays'] ?? null,
];
check('all four resolvers agree on the bay count: ' . json_encode($bayCounts),
    count(array_unique($bayCounts, SORT_REGULAR)) === 1 && reset($bayCounts) === EXPECTED_BAYS);

echo "\n-- Loose spares still resolve through their own catalogs (no regression) --\n";
$looseChassis = 'df5c3bf7-9886-4f15-a337-49071c947a98'; // ordinary catalog chassis
check('PlatformSpecIndex correctly claims nothing for a catalog chassis',
    PlatformSpecIndex::find('chassis', $looseChassis) === null);
$looseResult = $chassisMgr->loadChassisSpecsByUUID($looseChassis);
check('ChassisManager still resolves a catalog chassis', !empty($looseResult['found']));
check('ComponentDataLoader still resolves a catalog chassis',
    is_array($loader->loadChassisSpecs($looseChassis)));
check('an unknown UUID is still not found',
    empty($chassisMgr->loadChassisSpecsByUUID('00000000-0000-0000-0000-000000000000')['found']));

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
