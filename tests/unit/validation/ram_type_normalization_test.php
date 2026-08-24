<?php
/**
 * ram_type_normalization_test.php — regression test for SERVER_BUILD_GUIDE Bug 2.
 *
 * Guards the 2026-07-29 fix to ComponentValidator::validateRAMTypeCompatibility().
 * It compared memory types RAW (in_array('DDR4', ['DDR4 ECC']) === false) while
 * the sibling ADD-time path validateMemoryTypeCompatibility() normalized the same
 * pair and accepted it. Legacy contradicted itself: RAM you were allowed to
 * install could never be finalized.
 *
 * Blast radius when found (production, 2026-07-29): all 7 available motherboard
 * models declare memory.type "DDR4 ECC" with no supported_types, and NO ram entry
 * in ims-data is typed "DDR4 ECC" -- so validateConfigurationComprehensive()
 * rejected EVERY finalize in the fleet. Zero configurations had ever reached
 * status 3. Caught by the U-A.2 finalize shadow hook, which surfaced it as a
 * legacy-blocked / command-allowed divergence.
 *
 * Real ComponentValidator, real ims-data, real PDO-free paths where possible.
 * Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/shared/DataNormalizationUtils.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

echo "\n-- The normalizer itself (the fix reuses it rather than reinventing it) --\n";
check('"DDR4 ECC" normalizes to "DDR4"',
    DataNormalizationUtils::normalizeMemoryType('DDR4 ECC') === 'DDR4');
check('"DDR4" is unchanged',
    DataNormalizationUtils::normalizeMemoryType('DDR4') === 'DDR4');
check('"DDR5-4800" drops the speed suffix',
    DataNormalizationUtils::normalizeMemoryType('DDR5-4800') === 'DDR5');
check('DDR4 and DDR5 still do NOT collapse together',
    DataNormalizationUtils::normalizeMemoryType('DDR4 ECC')
    !== DataNormalizationUtils::normalizeMemoryType('DDR5 ECC'));

echo "\n-- Bug 2: the comparison must normalize BOTH operands --\n";
// Mirrors the fixed comparison exactly; a raw in_array here would fail the first.
$compare = function ($ramType, array $supportedTypes) {
    $n = DataNormalizationUtils::normalizeMemoryType($ramType);
    foreach ($supportedTypes as $s) {
        if (DataNormalizationUtils::normalizeMemoryType($s) === $n) { return true; }
    }
    return false;
};
check('DDR4 RAM is compatible with a "DDR4 ECC" board (the fleet-wide case)',
    $compare('DDR4', ['DDR4 ECC']) === true);
check('DDR5 RAM is compatible with a "DDR5" board',
    $compare('DDR5', ['DDR5']) === true);
check('DDR4 RAM is still REJECTED by a DDR5 board (fix is not a blanket pass)',
    $compare('DDR4', ['DDR5']) === false);
check('DDR5 RAM is still REJECTED by a "DDR4 ECC" board',
    $compare('DDR5', ['DDR4 ECC']) === false);
check('multi-entry supported_types still matches on any member',
    $compare('DDR4', ['DDR5', 'DDR4 ECC']) === true);

echo "\n-- The source no longer contains the raw compare --\n";
$src = file_get_contents($ROOT . '/core/models/components/ComponentValidator.php');
// Isolate the method (signature to the next method declaration) instead of
// budgeting 2500 bytes from the signature. The negative check especially: a
// distance-bounded negative passes by stopping to look, so the raw compare only
// had to be pushed past byte 2500 -- inside a method that is 3176 bytes long
// today -- to satisfy it. The slice is fail-closed and the boundary regex
// tolerates the modifier and indentation drift.
$ramFnStart = strpos($src, 'public function validateRAMTypeCompatibility(');
$ramFnRest  = $ramFnStart === false ? '' : substr($src, $ramFnStart + 20);
$ramFn      = '';
if ($ramFnStart !== false) {
    $ramFn = preg_match('/\n\s*(?:public|private|protected)[^\n]*function\s/', $ramFnRest, $m, PREG_OFFSET_CAPTURE)
        ? substr($src, $ramFnStart, 20 + $m[0][1])
        : substr($src, $ramFnStart);
}
check('validateRAMTypeCompatibility no longer uses a raw in_array on memory types',
    $ramFn !== '' && strpos($ramFn, '$compatible = in_array($ramType, $supportedTypes)') === false);
check('validateRAMTypeCompatibility normalizes via DataNormalizationUtils',
    $ramFn !== '' && strpos($ramFn, 'DataNormalizationUtils::normalizeMemoryType') !== false);

echo "\n-- The fleet-wide precondition that made this total, asserted against real ims-data --\n";
require_once $ROOT . '/core/models/components/ComponentSpecPaths.php';
$mbPath = ComponentSpecPaths::getPath('motherboard');
$ramPath = ComponentSpecPaths::getPath('ram');
$eccBoards = 0; $plainBoards = 0;
$walk = function ($node, callable $visit) use (&$walk) {
    if (is_array($node)) {
        $visit($node);
        foreach ($node as $v) { $walk($v, $visit); }
    }
};
$walk(json_decode(file_get_contents($mbPath), true), function ($n) use (&$eccBoards, &$plainBoards) {
    if (isset($n['memory']['type'])) {
        if (stripos($n['memory']['type'], 'ECC') !== false) { $eccBoards++; } else { $plainBoards++; }
    }
});
$eccRam = 0;
$walk(json_decode(file_get_contents($ramPath), true), function ($n) use (&$eccRam) {
    if (isset($n['memory_type']) && stripos($n['memory_type'], 'ECC') !== false) { $eccRam++; }
});
echo "  boards with an ECC-suffixed memory.type: $eccBoards (plain: $plainBoards); ram with an ECC-suffixed memory_type: $eccRam\n";
check('the asymmetry that made Bug 2 total still exists in ims-data '
    . '(ECC-suffixed boards but no ECC-suffixed ram) -- so the normalization is load-bearing, not decorative',
    $eccBoards > 0 && $eccRam === 0);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
