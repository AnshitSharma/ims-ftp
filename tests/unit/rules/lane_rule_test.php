<?php
/**
 * lane_rule_test.php — U-R.4 unit test for pcie.lane_budget.
 * Pure PHP + real ims-data fixtures (no DB), porting lane_authority_unit.php's
 * cases (empty system, quantity scaling, over-budget) onto TargetState.
 * Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/rules/PcieLaneBudgetRule.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Real fixtures (same CPU lane_authority_unit.php uses; NIC from fixture_scenarios_real.php).
const CPU_UUID = '545e143b-57b3-419e-86e5-1df6f7aa8fd3'; // pcie_lanes=80
const NIC_X8 = 'da6c533b-7475-4364-989c-f6c7dd442efa';   // interface 'PCIe 3.0 x8' -> 8 lanes

require_once $ROOT . '/core/models/shared/DataExtractionUtilities.php';
$cpuLanes = (int)(new DataExtractionUtilities())->getCPUByUUID(CPU_UUID)['pcie_lanes'];

function cpuRow($id, $uuid) { return ['id' => $id, 'component_type' => 'cpu', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }
function nicRow($id, $uuid) { return ['id' => $id, 'component_type' => 'nic', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }

// -----------------------------------------------------------------------
echo "-- pcie.lane_budget -- empty system + one NIC within budget --\n";
$oneCpuOneNic = new TargetState([cpuRow(1, CPU_UUID), nicRow(2, NIC_X8)]);
$r = (new PcieLaneBudgetRule())->evaluate($oneCpuOneNic);
check("1 CPU ($cpuLanes lanes) + 1 x8 NIC (8 lanes): within budget, passes", $r->passed() === true);
check('lane_budget severity is ERROR', $r->severity() === Severity::ERROR);

echo "-- pcie.lane_budget -- two CPUs doubles the budget (all-CPU x qty, not first-CPU-only) --\n";
$twoCpu = new TargetState([cpuRow(1, CPU_UUID), cpuRow(2, CPU_UUID)]);
check('2 CPUs, no cards: still passes (budget doubled, nothing consumed)', (new PcieLaneBudgetRule())->evaluate($twoCpu)->passed() === true);

echo "-- pcie.lane_budget -- over budget (A-9: legacy warn-default never blocked this) --\n";
$nicsNeeded = intdiv($cpuLanes, 8) + 2; // enough x8 NICs to exceed a single CPU's budget
$overRows = [cpuRow(1, CPU_UUID)];
for ($i = 2; $i <= 1 + $nicsNeeded; $i++) { $overRows[] = nicRow($i, NIC_X8); }
$overBudget = new TargetState($overRows);
$rOver = (new PcieLaneBudgetRule())->evaluate($overBudget);
check("$nicsNeeded x8 NICs on a single $cpuLanes-lane CPU: over budget, fails", $rOver->passed() === false);
check('over-budget details carry budget/used/over_by', isset($rOver->details()['budget'], $rOver->details()['used'], $rOver->details()['over_by']));

echo "-- pcie.lane_budget -- no CPU: budget 0, any lane-consuming card fails --\n";
$noCpu = new TargetState([nicRow(1, NIC_X8)]);
check('no CPU + an x8 NIC: budget is 0, fails', (new PcieLaneBudgetRule())->evaluate($noCpu)->passed() === false);

echo "-- pcie.lane_budget -- no CPU, no cards: nothing to consume, passes --\n";
check('empty state: passes trivially', (new PcieLaneBudgetRule())->evaluate(new TargetState([]))->passed() === true);

$src = file_get_contents("$ROOT/core/models/validation/rules/PcieLaneBudgetRule.php");
check("PcieLaneBudgetRule.php contains no 'quantity' token (INV-1)", stripos($src, 'quantity') === false);
check('PcieLaneBudgetRule.php reads no env var (INV-7 -- ENGINE_MODE governs rollout globally)', !preg_match('/getenv|\$_ENV/', $src));

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
