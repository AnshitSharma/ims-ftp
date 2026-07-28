<?php
/**
 * storage_bay_placement_test.php — regression test for F-19.
 *
 * Reported from production: four drives installed in config 05bcb95b, DRIVE BAYS
 * showing 0/4 with every bay Empty. The bay grid only draws a cell when a drive's
 * connection_type is 'chassis_bay', and computeStorageConnectionPath() degrades to
 * 'not_connected' whenever StorageConnectionValidator::validate() says valid=false.
 * Three defects made that happen:
 *
 *   A. normalizeFormFactor() is a literal string munge ("2.5-inch U.2" ->
 *      "2.5-inch-u.2") but is used for PHYSICAL SIZE comparisons, so no compound
 *      form factor could ever match a bay type or the 2.5"-in-3.5" caddy rule.
 *   B. validateFormFactorConsistency() rejected a 2.5" drive in a 3.5"-bay chassis
 *      outright, contradicting checkBayAvailability() in the same class, which
 *      implements the caddy allowance.
 *   C. Describe-time callers pass the drive under examination inside
 *      $existingComponents, so checkBayAvailability() counts it twice and an
 *      exactly-full chassis reports "N in use, cannot add 1 more".
 *
 * Real ims-data fixtures, no DB. Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/components/ComponentSpecPaths.php';
require_once $ROOT . '/core/models/compatibility/StorageConnectionValidator.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// The exact production fixtures from the report.
const CH_R180_F34   = 'df5c3bf7-9886-4f15-a337-49071c947a98'; // 4x 3.5" bays, SATA-only backplane
const ST_U2_NVME    = 'f54497fd-5cd3-4b5a-8cd2-276a68af11ac'; // "2.5-inch U.2", NVMe PCIe 4.0
const ST_SATA_25    = 'a82df310-cb82-4f9e-bd48-2f1d171cf9a9'; // "2.5-inch", SATA III
const ST_SAS_25_A   = '138e1181-f1bb-4c2e-a487-15afbe7098d6'; // "2.5-inch", SAS 12Gb/s
const ST_SAS_25_B   = '7a8b9c0d-1e2f-4a3b-8c4d-5e6f7a8b9c0d'; // "2.5-inch", SAS 12Gb/s

// Neither method under test touches PDO; inject only the spec loaders.
$refl = new ReflectionClass('StorageConnectionValidator');
$v = $refl->newInstanceWithoutConstructor();
foreach (['dataUtils' => 'DataExtractionUtilities', 'componentDataService' => null] as $prop => $cls) {
    $p = $refl->getProperty($prop);
    $p->setAccessible(true);
    $p->setValue($v, $cls ? new $cls() : ComponentDataService::getInstance());
}
$call = function ($method, array $args) use ($refl, $v) {
    $m = $refl->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($v, $args);
};
$specs = function ($uuid) use ($refl, $v) {
    $m = $refl->getMethod('getStorageSpecs');
    $m->setAccessible(true);
    return $m->invoke($v, $uuid);
};

function config(array $storageUuids) {
    $storage = [];
    foreach ($storageUuids as $u) {
        $storage[] = ['component_uuid' => $u, 'quantity' => 1];
    }
    return [
        'chassis' => ['component_uuid' => CH_R180_F34],
        'motherboard' => null,
        'cpu' => [], 'ram' => [], 'storage' => $storage,
        'nic' => [], 'pciecard' => [], 'hbacard' => [], 'caddy' => []
    ];
}

$ALL_FOUR = [ST_U2_NVME, ST_SATA_25, ST_SAS_25_A, ST_SAS_25_B];

// ---------------------------------------------------------------------------
echo "\nFIXTURE GUARDS (fail here => ims-data changed, not the code)\n";
// ---------------------------------------------------------------------------
$chassis = $call('getChassisSpecs', [CH_R180_F34]);
check('R180-F34 resolves', is_array($chassis));
check('  still 4 total bays', (int)($chassis['drive_bays']['total_bays'] ?? 0) === 4);
check('  bays are still 3.5_inch ONLY',
    array_values(array_unique(array_column($chassis['drive_bays']['bay_configuration'] ?? [], 'bay_type'))) === ['3.5_inch']);
check('  backplane still SATA-only (no SAS, no NVMe)',
    ($chassis['backplane']['supports_sata'] ?? null) === true
    && ($chassis['backplane']['supports_sas'] ?? null) === false
    && ($chassis['backplane']['supports_nvme'] ?? null) === false);
check('SATA drive is still form_factor "2.5-inch"', ($specs(ST_SATA_25)['form_factor'] ?? '') === '2.5-inch');
check('U.2 drive is still form_factor "2.5-inch U.2"', ($specs(ST_U2_NVME)['form_factor'] ?? '') === '2.5-inch U.2');

// ---------------------------------------------------------------------------
echo "\nDEFECT B — a 2.5\" drive DOES mount in a 3.5\" bay (with a caddy)\n";
// ---------------------------------------------------------------------------
$r = $call('validateFormFactorConsistency', ['2.5-inch', config([])]);
check('2.5-inch accepted by a 3.5"-bay chassis', ($r['valid'] ?? null) === true);
check('  ... and it says a caddy is needed rather than staying silent',
    stripos(json_encode($r['info'] ?? []), 'caddy') !== false);

// The reverse is physically impossible and must STILL be rejected.
$r = $call('validateFormFactorConsistency', ['3.5-inch', config([])]);
check('3.5-inch in a 3.5" bay accepted (direct match)', ($r['valid'] ?? null) === true);

// ---------------------------------------------------------------------------
echo "\nDEFECT A — compound form factors must compare by PHYSICAL SIZE\n";
// ---------------------------------------------------------------------------
$r = $call('validateFormFactorConsistency', ['2.5-inch U.2', config([])]);
check('"2.5-inch U.2" accepted by a 3.5"-bay chassis', ($r['valid'] ?? null) === true);

// checkBayAvailability is where the string munge actually blocked the bay match.
$r = $call('checkBayAvailability', ['2.5-inch U.2', config([]), $specs(ST_U2_NVME)]);
check('"2.5-inch U.2" matches a 3.5" bay type', ($r['available'] ?? null) === true);
check('  ... and is flagged as needing a caddy', ($r['caddy_required'] ?? null) === true);

$r = $call('checkBayAvailability', ['2.5-inch', config([]), $specs(ST_SATA_25)]);
check('"2.5-inch" matches a 3.5" bay type', ($r['available'] ?? null) === true);
check('  ... and is flagged as needing a caddy', ($r['caddy_required'] ?? null) === true);

// A form factor that genuinely cannot be seated in a bay must still be refused.
$r = $call('checkBayAvailability', ['M.2 2280', config([]), ['form_factor' => 'M.2 2280']]);
check('M.2 is STILL refused a 3.5" chassis bay', ($r['available'] ?? null) === false);
check('  ... as a form-factor mismatch', ($r['error']['type'] ?? '') === 'form_factor_incompatible');

// Mixed 2.5"/2.5"-U.2 drives are the same physical size — no lock violation.
$r = $call('checkBayAvailability', ['2.5-inch', config([ST_U2_NVME]), $specs(ST_SATA_25)]);
check('mixing "2.5-inch" and "2.5-inch U.2" is not a form-factor lock violation',
    ($r['error']['type'] ?? '') !== 'form_factor_lock_violation');

// A real size clash must still lock out.
$r = $call('checkBayAvailability', ['3.5-inch', config([ST_SATA_25]), ['form_factor' => '3.5-inch']]);
check('a 3.5" drive joining 2.5" drives IS still a lock violation',
    ($r['error']['type'] ?? '') === 'form_factor_lock_violation');

// ---------------------------------------------------------------------------
echo "\nDEFECT C — an exactly-full chassis must not report itself overfull\n";
// ---------------------------------------------------------------------------
// Describe-time semantics: the drive under examination is excluded from the config.
$r = $call('checkBayAvailability', ['2.5-inch', config([ST_U2_NVME, ST_SAS_25_A, ST_SAS_25_B]), $specs(ST_SATA_25)]);
check('4th drive fits 4 bays when the other 3 are installed', ($r['available'] ?? null) === true);
check('  ... leaving 0 bays free', (int)($r['available_bays'] ?? -1) === 0);

// Genuine overflow must still be caught.
$r = $call('checkBayAvailability', ['2.5-inch', config($ALL_FOUR), $specs(ST_SATA_25)]);
check('a 5th drive into 4 full bays IS still refused', ($r['available'] ?? null) === false);
check('  ... as a bay limit overflow', ($r['error']['type'] ?? '') === 'bay_limit_exceeded');

// ---------------------------------------------------------------------------
echo "\nSTILL HONEST — no data path is still no data path\n";
// ---------------------------------------------------------------------------
// The SATA-only backplane genuinely cannot carry SAS or NVMe. These must NOT be
// relaxed into a chassis_bay path just because the drive physically fits a bay.
$r = $call('checkChassisBackplaneCapability',
    ['SAS 12Gb/s', '2.5-inch', config([]), $specs(ST_SAS_25_A)]);
check('SAS drive gets NO chassis path on a SATA-only backplane', ($r['available'] ?? null) === false);
$r = $call('checkChassisBackplaneCapability',
    ['NVMe PCIe 4.0', '2.5-inch U.2', config([]), $specs(ST_U2_NVME)]);
check('NVMe drive gets NO chassis path without a Tri-Mode HBA', ($r['available'] ?? null) === false);
$r = $call('checkChassisBackplaneCapability',
    ['SATA III', '2.5-inch', config([]), $specs(ST_SATA_25)]);
check('SATA drive DOES get a chassis path on a SATA backplane', ($r['available'] ?? null) === true);
check('  ... typed as chassis_bay (this is what the UI draws)', ($r['type'] ?? '') === 'chassis_bay');

// ---------------------------------------------------------------------------
echo "\nF-20 — a SAS backplane IS a SAS path; an HBA is not mandatory\n";
// ---------------------------------------------------------------------------
// Observed in situ on production while generating the migration's SAS traffic:
// checkHBACardRequirement() declared an HBA mandatory for every SAS drive without
// ever consulting the chassis, so a SAS drive in a SAS-backplane chassis was
// hard-errored even though CHECK 1 had already granted it a chassis_bay path.
$sasChassis = function (array $storageUuids) {
    $c = config($storageUuids);
    $c['chassis'] = ['component_uuid' => 'd4e5f6a7-b8c9-4d0e-1f2a-3b4c5d6e7f8a']; // DL360 Gen9: 8x 2.5", SAS
    return $c;
};
$sasChassisSpecs = $call('getChassisSpecs', ['d4e5f6a7-b8c9-4d0e-1f2a-3b4c5d6e7f8a']);
check('DL360 Gen9 backplane still declares supports_sas (fixture guard)',
    ($sasChassisSpecs['backplane']['supports_sas'] ?? null) === true);

$r = $call('checkHBACardRequirement', ['SAS 12Gb/s', $sasChassis([]), $specs(ST_SAS_25_A)]);
check('SAS drive + SAS backplane + no HBA is NOT a mandatory HBA error',
    ($r['mandatory'] ?? null) === false);
check('  ... and no error is raised at all', !isset($r['error']));
$r = $call('checkChassisBackplaneCapability',
    ['SAS 12Gb/s', '2.5-inch', $sasChassis([]), $specs(ST_SAS_25_A)]);
check('  ... because the chassis itself supplies the chassis_bay path',
    ($r['available'] ?? null) === true && ($r['type'] ?? '') === 'chassis_bay');

// Without a SAS backplane the HBA really is mandatory — that must not be relaxed.
$r = $call('checkHBACardRequirement', ['SAS 12Gb/s', config([]), $specs(ST_SAS_25_A)]);
check('SAS drive + SATA-only backplane + no HBA IS still a mandatory error',
    ($r['mandatory'] ?? null) === true && ($r['error']['type'] ?? '') === 'hba_required');
$noChassis = config([]);
$noChassis['chassis'] = null;
$r = $call('checkHBACardRequirement', ['SAS 12Gb/s', $noChassis, $specs(ST_SAS_25_A)]);
check('SAS drive + no chassis at all IS still a mandatory error',
    ($r['mandatory'] ?? null) === true);

// ---------------------------------------------------------------------------
echo "\nDEFECT C — describe-time callers must exclude the drive under examination\n";
// ---------------------------------------------------------------------------
require_once $ROOT . '/core/models/server/ServerBuilder.php';
$sbRefl = new ReflectionClass('ServerBuilder');
$sb = $sbRefl->newInstanceWithoutConstructor(); // helper is pure; no PDO needed
$exclude = $sbRefl->getMethod('existingComponentsExcludingStorage');
$exclude->setAccessible(true);
$ids = function ($result) {
    $out = [];
    foreach ($result['storage'] as $e) { $out[] = $e['component_uuid'] . ':' . $e['quantity']; }
    return implode(',', $out);
};

$installed = ['storage' => [
    ['component_uuid' => 'A', 'quantity' => 1],
    ['component_uuid' => 'B', 'quantity' => 2],
    ['component_uuid' => 'A', 'quantity' => 1],
]];
check('removes exactly ONE entry, leaving the duplicate installed',
    $ids($exclude->invoke($sb, $installed, 'A')) === 'B:2,A:1');
check('decrements a quantity-N entry rather than dropping all N',
    $ids($exclude->invoke($sb, $installed, 'B')) === 'A:1,B:1,A:1');
check('a drive that is not installed changes nothing',
    $ids($exclude->invoke($sb, $installed, 'C')) === 'A:1,B:2,A:1');
check('empty storage list is handled',
    $exclude->invoke($sb, ['storage' => []], 'A') === ['storage' => []]);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
