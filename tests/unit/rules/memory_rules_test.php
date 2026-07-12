<?php
/**
 * memory_rules_test.php — U-R.2 unit test for the memory.* rule family.
 * Pure PHP + real ims-data fixtures (no DB), same convention as
 * tests/unit/rules/cpu_rules_test.php. Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/rules/MemoryTypeRule.php';
require_once $ROOT . '/core/models/validation/rules/MemoryFormFactorRule.php';
require_once $ROOT . '/core/models/validation/rules/MemorySlotCountRule.php';
require_once $ROOT . '/core/models/validation/rules/MemoryEccRule.php';
require_once $ROOT . '/core/models/validation/rules/MemoryDownclockRule.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Real fixture UUIDs (tests/fixture_scenarios_real.php).
const MB_3647 = 'd8e9f0a1-b2c3-4d4e-bf6a-7b8c9d0e1f2a';   // DDR4 ECC, socket.count=2, memory.slots=24
const RAM_D4_RD = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c'; // DDR4 RDIMM, ECC, 2666MHz
const RAM_D5_RD = 'a1b2c3d4-e5f6-7890-1234-567890abcdef'; // DDR5 RDIMM, ECC, 6000MHz

function mbRow($id, $uuid) { return ['id' => $id, 'component_type' => 'motherboard', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }
function ramRow($id, $uuid) { return ['id' => $id, 'component_type' => 'ram', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }

// -----------------------------------------------------------------------
echo "-- memory.type (E) -- R3/R4 fixture scenarios ported --\n";
$typeMatch = new TargetState([mbRow(1, MB_3647), ramRow(2, RAM_D4_RD)]);
check('R3 ram-type-match: DDR4 on DDR4(ECC) board passes', (new MemoryTypeRule())->evaluate($typeMatch)->passed() === true);

$typeMismatch = new TargetState([mbRow(1, MB_3647), ramRow(2, RAM_D5_RD)]);
$r = (new MemoryTypeRule())->evaluate($typeMismatch);
check('R4 ram-ddr-mismatch: DDR5 on DDR4 board fails', $r->passed() === false);
check('memory.type severity is ERROR', $r->severity() === Severity::ERROR);

check('no motherboard, no CPU: any RAM type passes', (new MemoryTypeRule())->evaluate(new TargetState([ramRow(1, RAM_D5_RD)]))->passed() === true);

// -----------------------------------------------------------------------
echo "-- memory.form_factor (E) --\n";
// both real RAM fixtures are 'DIMM (288-pin)' -> normalizes to DIMM, matches
// the rule's hardcoded motherboard default -- passes.
check('DIMM RAM on a motherboard: form factor passes', (new MemoryFormFactorRule())->evaluate($typeMatch)->passed() === true);
check('no motherboard: form factor check does not apply', (new MemoryFormFactorRule())->evaluate(new TargetState([ramRow(1, RAM_D4_RD)]))->passed() === true);

// -----------------------------------------------------------------------
echo "-- memory.slot_count (E) -- unified row-count based (D4) --\n";
// MB_3647 has memory.slots=24.
$manyRam = [mbRow(1, MB_3647)];
for ($i = 2; $i <= 25; $i++) { $manyRam[] = ramRow($i, RAM_D4_RD); } // 24 RAM rows
$atCapacity = new TargetState($manyRam);
check('24 RAM on a 24-slot board: passes', (new MemorySlotCountRule())->evaluate($atCapacity)->passed() === true);

$manyRam[] = ramRow(26, RAM_D4_RD); // 25th
$overCapacity = new TargetState($manyRam);
$r = (new MemorySlotCountRule())->evaluate($overCapacity);
check('25 RAM on a 24-slot board: fails', $r->passed() === false);
check('slot_count severity is ERROR', $r->severity() === Severity::ERROR);

foreach (['MemoryTypeRule', 'MemoryFormFactorRule', 'MemorySlotCountRule', 'MemoryEccRule', 'MemoryDownclockRule'] as $class) {
    $src = file_get_contents($ROOT . "/core/models/validation/rules/$class.php");
    check("$class.php contains no 'quantity' token (INV-1)", stripos($src, 'quantity') === false);
}

// -----------------------------------------------------------------------
echo "-- memory.ecc (W) --\n";
// RAM_D4_RD is ECC, MB_3647 is ECC-capable -- matched, no warning.
check('ECC RAM on ECC-capable board: no warning', (new MemoryEccRule())->evaluate($typeMatch)->passed() === true);
check('memory.ecc severity is WARNING', (new MemoryEccRule())->severity() === Severity::WARNING);

// -----------------------------------------------------------------------
echo "-- memory.downclock (W) -- effective-frequency detail preserved --\n";
// RAM_D4_RD is 2666MHz, MB_3647 max_frequency_MHz=2933 -> optimal (2666 <= 2933).
$r = (new MemoryDownclockRule())->evaluate($typeMatch);
check('RAM at or below motherboard max frequency: no downclock warning', $r->passed() === true);
check('memory.downclock severity is WARNING', $r->severity() === Severity::WARNING);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
