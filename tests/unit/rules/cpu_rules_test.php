<?php
/**
 * cpu_rules_test.php — U-R.1 unit test for the cpu.* rule family.
 * Pure PHP + real ims-data fixtures (no DB): TargetState is built by hand
 * from the same real UUIDs tests/fixture_scenarios_real.php uses, so the
 * rules resolve real specs via DataExtractionUtilities. Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/rules/CpuSocketMatchRule.php';
require_once $ROOT . '/core/models/validation/rules/CpuSocketCountRule.php';
require_once $ROOT . '/core/models/validation/rules/CpuMixedModelsRule.php';
require_once $ROOT . '/core/models/validation/rules/CpuRequiresBoardRule.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Real fixture UUIDs (same ones tests/fixture_scenarios_real.php uses).
const MB_3647 = 'd8e9f0a1-b2c3-4d4e-bf6a-7b8c9d0e1f2a';   // LGA3647, socket.count=2
const CPU_3647 = '980bd035-0b5c-40aa-9329-d5088a036ae0';  // LGA3647 -- matches MB_3647
const CPU_4189 = '3001f095-9a50-44e5-92c5-b46310160e90';  // LGA4189 -- mismatches MB_3647

function mbRow($id, $uuid) {
    return ['id' => $id, 'component_type' => 'motherboard', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows'];
}
function cpuRow($id, $uuid) {
    return ['id' => $id, 'component_type' => 'cpu', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows'];
}

// -----------------------------------------------------------------------
echo "-- cpu.requires_board (VF) --\n";
$noBoardOneCpu = new TargetState([cpuRow(1, CPU_3647)]);
$r = (new CpuRequiresBoardRule())->evaluate($noBoardOneCpu);
check('CPU with no motherboard fails', $r->passed() === false);
check('severity is VALIDATION_FAILURE (E->VF, A-12)', $r->severity() === Severity::VALIDATION_FAILURE);

$withBoard = new TargetState([mbRow(1, MB_3647), cpuRow(2, CPU_3647)]);
check('CPU with motherboard passes', (new CpuRequiresBoardRule())->evaluate($withBoard)->passed() === true);
check('no CPUs, no board: passes (nothing to require)', (new CpuRequiresBoardRule())->evaluate(new TargetState([]))->passed() === true);

// -----------------------------------------------------------------------
echo "-- cpu.socket_match (E) -- R1/R2 fixture scenarios ported --\n";
$matchState = new TargetState([mbRow(1, MB_3647), cpuRow(2, CPU_3647)]);
check('R1 cpu-socket-match: matching socket passes', (new CpuSocketMatchRule())->evaluate($matchState)->passed() === true);

$mismatchState = new TargetState([mbRow(1, MB_3647), cpuRow(2, CPU_4189)]);
$r = (new CpuSocketMatchRule())->evaluate($mismatchState);
check('R2 cpu-socket-mismatch: mismatched socket fails', $r->passed() === false);
check('mismatch severity is ERROR', $r->severity() === Severity::ERROR);

check('no motherboard: socket_match passes (defers to requires_board)', (new CpuSocketMatchRule())->evaluate($noBoardOneCpu)->passed() === true);

// -----------------------------------------------------------------------
echo "-- cpu.socket_count (E) -- quantity-bypass fixture (A-2) --\n";
// MB_3647 has socket.count=2 -- 2 CPUs should pass, 3 should fail regardless
// of how many "add" calls produced them (row-count based, not a quantity field).
$twoCpus = new TargetState([mbRow(1, MB_3647), cpuRow(2, CPU_3647), cpuRow(3, CPU_3647)]);
check('2 CPUs on a 2-socket board: passes', (new CpuSocketCountRule())->evaluate($twoCpus)->passed() === true);

$threeCpus = new TargetState([mbRow(1, MB_3647), cpuRow(2, CPU_3647), cpuRow(3, CPU_3647), cpuRow(4, CPU_3647)]);
$r = (new CpuSocketCountRule())->evaluate($threeCpus);
check('3 CPUs on a 2-socket board: fails (A-2 -- row count, not quantity field)', $r->passed() === false);
check('socket_count severity is ERROR', $r->severity() === Severity::ERROR);

check('no motherboard: socket_count passes (defers to requires_board)', (new CpuSocketCountRule())->evaluate($noBoardOneCpu)->passed() === true);

// INV-1 grep: no 'quantity' token anywhere in the rule files.
foreach (['CpuSocketMatchRule', 'CpuSocketCountRule', 'CpuMixedModelsRule', 'CpuRequiresBoardRule'] as $class) {
    $src = file_get_contents($ROOT . "/core/models/validation/rules/$class.php");
    check("$class.php contains no 'quantity' token (INV-1)", stripos($src, 'quantity') === false);
}

// -----------------------------------------------------------------------
echo "-- cpu.mixed_models (W, VALIDATE only, previously orphaned) --\n";
$sameModel = new TargetState([cpuRow(1, CPU_3647), cpuRow(2, CPU_3647)]);
check('same-socket CPUs: no mixed-model warning', (new CpuMixedModelsRule())->evaluate($sameModel)->passed() === true);

$mixedModel = new TargetState([cpuRow(1, CPU_3647), cpuRow(2, CPU_4189)]);
$r = (new CpuMixedModelsRule())->evaluate($mixedModel);
check('mixed-socket CPUs: fires (new firing vs. orphaned legacy -- expected diff)', $r->passed() === false);
check('mixed_models severity is WARNING (never blocks)', $r->severity() === Severity::WARNING);
check('mixed_models triggers only VALIDATE', (new CpuMixedModelsRule())->triggers() === [Trigger::VALIDATE]);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
