<?php
/**
 * motherboard_storage_gate_test.php — regression test for the legacy
 * motherboard<->storage add-time gates in ComponentCompatibility.
 *
 * Guards the 2026-07-27 fix: extractDriveBays() and
 * extractMotherboardStorageInterfaces() derive their lists ENTIRELY from a board's
 * `storage` block in ims-data. Three boards there have no such block, so both
 * gates saw an empty list and hard-blocked EVERY drive of EVERY type -- reported
 * from production as "Storage form factor 2.5-inch U.2 not compatible with
 * motherboard bays" on S5B-MB 2U. An empty list means "no data", not "incompatible".
 *
 * Pure PHP + real ims-data fixtures (no DB). Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/components/ComponentSpecPaths.php';
// ComponentCompatibility pulls in DataNormalizationUtils and ComponentDataExtractor itself.
require_once $ROOT . '/core/models/compatibility/ComponentCompatibility.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Real fixtures -- the exact production pair from the 2026-07-27 report, plus a
// board that DOES declare storage so we can prove the fix is not a blanket pass.
const MB_NO_STORAGE_2U = 'c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f'; // S5B-MB 2U (LBG-4), no `storage` block
const MB_NO_STORAGE_1U = 'd2e3f4a5-b6c7-4d8e-9f0a-1b2c3d4e5f6a'; // S5B-MB 1U (LBG-4), no `storage` block
const MB_WITH_STORAGE  = '8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c'; // X13DRG-H: 8 SATA, M.2, 2x U.2
const ST_U2_25         = 'f54497fd-5cd3-4b5a-8cd2-276a68af11ac'; // MPSSDAI1001TB, "2.5-inch U.2", NVMe PCIe 4.0

// ---------------------------------------------------------------------------
// Load real specs straight from ims-data, through the same extractor the
// production loader uses -- so the test breaks if the extractor changes shape.
// ---------------------------------------------------------------------------
$extractor = new ComponentDataExtractor();

$mbJson = json_decode(file_get_contents(ComponentSpecPaths::getPath('motherboard')), true);
$boards = [];
foreach ($mbJson as $brand) {
    foreach ($brand['models'] ?? [] as $model) {
        if (isset($model['uuid'])) { $boards[strtolower($model['uuid'])] = $model; }
    }
}
function boardSpecs($uuid) {
    global $extractor, $boards;
    $raw = $boards[strtolower($uuid)] ?? null;
    if ($raw === null) { return null; }
    return $extractor->extractMotherboardSpecifications($raw);
}

$stJson = json_decode(file_get_contents(ComponentSpecPaths::getPath('storage')), true);
$drive = null;
$findDrive = function ($node) use (&$findDrive, &$drive) {
    if (!is_array($node)) { return; }
    foreach ($node as $v) {
        if (!is_array($v)) { continue; }
        if (isset($v['uuid']) && strtolower($v['uuid']) === ST_U2_25) { $drive = $v; return; }
        $findDrive($v);
        if ($drive !== null) { return; }
    }
};
$findDrive($stJson);

// The gates are private; the fix lives in them, so drive them directly. No PDO is
// touched by either method, so a constructor-less instance is sufficient.
$refl = new ReflectionClass('ComponentCompatibility');
$compat = $refl->newInstanceWithoutConstructor();
$ffGate = $refl->getMethod('checkFormFactorCompatibility');
$ffGate->setAccessible(true);
$ifGate = $refl->getMethod('checkStorageInterfaceCompatibility');
$ifGate->setAccessible(true);
$formFactor = function ($storage, $board) use ($ffGate, $compat) {
    return $ffGate->invoke($compat, $storage, $board);
};
$iface = function ($storage, $board) use ($ifGate, $compat) {
    return $ifGate->invoke($compat, $storage, $board);
};

// ---------------------------------------------------------------------------
echo "\nFIXTURE GUARDS (fail here => ims-data changed, not the code)\n";
// ---------------------------------------------------------------------------
check('drive f54497fd exists in ims-data', is_array($drive));
check('drive form_factor is still "2.5-inch U.2"', ($drive['form_factor'] ?? '') === '2.5-inch U.2');
check('drive interface is still NVMe PCIe', stripos($drive['interface'] ?? '', 'nvme') !== false);

$noStorage2U = boardSpecs(MB_NO_STORAGE_2U);
$noStorage1U = boardSpecs(MB_NO_STORAGE_1U);
$withStorage = boardSpecs(MB_WITH_STORAGE);
check('S5B-MB 2U resolves', is_array($noStorage2U));
check('S5B-MB 2U still derives an EMPTY supported_form_factors list',
    empty($noStorage2U['drive_bays']['supported_form_factors']));
check('S5B-MB 2U still derives an EMPTY storage_interfaces list',
    empty($noStorage2U['storage_interfaces']));
check('X13DRG-H DOES derive form factors (control board)',
    !empty($withStorage['drive_bays']['supported_form_factors']));
check('X13DRG-H DOES derive storage interfaces (control board)',
    !empty($withStorage['storage_interfaces']));

// ---------------------------------------------------------------------------
echo "\nTHE REPORTED PRODUCTION FAILURE\n";
// ---------------------------------------------------------------------------
$r = $formFactor($drive, $noStorage2U);
check('2.5-inch U.2 drive is NOT blocked by S5B-MB 2U bays', $r['compatible'] === true);
check('  ... and the verdict says it deferred, not that it matched natively',
    stripos($r['message'], 'defer') !== false);

$r = $iface($drive, $noStorage2U);
check('NVMe interface is NOT blocked by S5B-MB 2U interface list', $r['compatible'] === true);

// The sibling board fails identically, so it must be fixed identically.
check('2.5-inch U.2 drive is NOT blocked by S5B-MB 1U bays',
    $formFactor($drive, $noStorage1U)['compatible'] === true);
check('NVMe interface is NOT blocked by S5B-MB 1U interface list',
    $iface($drive, $noStorage1U)['compatible'] === true);

// Every drive of every type was blocked, not just this one -- prove the class.
echo "\nEVERY form factor was blocked on these boards, not just 2.5-inch U.2\n";
foreach (['3.5-inch', '2.5-inch', 'M.2 2280', 'U.3'] as $ff) {
    $synthetic = ['form_factor' => $ff, 'interface' => 'SATA III'];
    check("form factor '$ff' is not blocked by a board with no storage block",
        $formFactor($synthetic, $noStorage2U)['compatible'] === true);
    check("interface 'SATA III' is not blocked for '$ff' either",
        $iface($synthetic, $noStorage2U)['compatible'] === true);
}

// ---------------------------------------------------------------------------
echo "\nNOT A BLANKET PASS -- boards that DO declare capability still gate\n";
// ---------------------------------------------------------------------------
$r = $formFactor($drive, $withStorage);
check('2.5-inch U.2 still natively supported on X13DRG-H', $r['compatible'] === true);
check('  ... and via native support, NOT the deferral branch',
    stripos($r['message'], 'defer') === false);

// A form factor the control board genuinely cannot mount must still be rejected,
// otherwise the fix would have neutered the gate everywhere.
$bogus = ['form_factor' => 'CFexpress Type B', 'interface' => 'SATA III'];
$r = $formFactor($bogus, $withStorage);
check('unsupported form factor is STILL blocked on a board with real bay data',
    $r['compatible'] === false);
check('  ... and still names what the board does support',
    strpos($r['recommendation'], '2.5-inch') !== false);

$r = $iface(['form_factor' => '2.5-inch', 'interface' => 'Fibre Channel 32G'], $withStorage);
check('unsupported interface is STILL blocked on a board with real interface data',
    $r['compatible'] === false);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
