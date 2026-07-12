<?php
/**
 * slot_rules_test.php — U-R.3 unit test for SlotPlanner + pcie.slot_placement.
 * Pure PHP + real ims-data fixtures (no DB). Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/SlotPlanner.php';
require_once $ROOT . '/core/models/validation/rules/PcieSlotPlacementRule.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Real fixture: motherboard with DIRECT pcie_slots (6x x16, 4x x8, 2x x4) --
// found by scanning ims-data/motherboard/motherboard-level-3.json for a
// spec with expansion_slots.pcie_slots (MB_3647 from fixture_scenarios_real.php
// only has riser_slots, no direct pcie_slots, so it can't exercise this path).
const MB_DIRECT_SLOTS = '8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c';
const NIC_X8 = 'da6c533b-7475-4364-989c-f6c7dd442efa'; // interface 'PCIe 3.0 x8' -> width x8

function mbRow($id, $uuid) { return ['id' => $id, 'component_type' => 'motherboard', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }
function nicRow($id, $uuid, $slotRef = null) { return ['id' => $id, 'component_type' => 'nic', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => $slotRef, 'source' => 'rows']; }

// -----------------------------------------------------------------------
echo "-- SlotPlanner::extractCardWidth (ported from ServerBuilder::extractPCIeSlotSize) --\n";
check("interface 'PCIe 3.0 x8' -> x8", SlotPlanner::extractCardWidth(['interface' => 'PCIe 3.0 x8']) === 'x8');
check("slot_type fallback", SlotPlanner::extractCardWidth(['slot_type' => 'x4']) === 'x4');
check('unparseable -> null', SlotPlanner::extractCardWidth(['interface' => 'USB']) === null);

// -----------------------------------------------------------------------
echo "-- SlotPlanner::plan -- auto assignment, smallest-sufficient-width --\n";
$state = new TargetState([mbRow(1, MB_DIRECT_SLOTS)]);
$plan = SlotPlanner::plan($state, 'pcie_slot', 'x4');
check('x4 card auto-assigned to an x4 slot (smallest sufficient)', $plan['ok'] && strpos($plan['slot_ref'], '_x4') !== false);

$plan16 = SlotPlanner::plan($state, 'pcie_slot', 'x16');
check('x16 card auto-assigned to an x16 slot', $plan16['ok'] && strpos($plan16['slot_ref'], '_x16') !== false);

// -----------------------------------------------------------------------
echo "-- SlotPlanner::plan -- manual slot honored (A-7) --\n";
$manualPlan = SlotPlanner::plan($state, 'pcie_slot', 'x8', $plan16['slot_ref']); // request the SAME x16 slot chosen above, with an x8 card
check('manual-honored: request an existing free x16 slot for an x8 card (x8 fits in x16)', $manualPlan['ok'] && $manualPlan['slot_ref'] === $plan16['slot_ref']);

echo "-- SlotPlanner::plan -- manual-occupied blocked --\n";
$occupied = new TargetState([mbRow(1, MB_DIRECT_SLOTS), nicRow(2, NIC_X8, $plan16['slot_ref'])]); // occupy that slot
$occupiedPlan = SlotPlanner::plan($occupied, 'pcie_slot', 'x8', $plan16['slot_ref']);
check('manual-occupied: requesting an already-occupied slot fails', $occupiedPlan['ok'] === false && $occupiedPlan['error_code'] === 'slot_occupied');

echo "-- SlotPlanner::plan -- unknown-width blocked (A-8) --\n";
$unknownPlan = SlotPlanner::plan($state, 'pcie_slot', null);
check('unknown card width fails (A-8 -- legacy fails open, engine fails closed)', $unknownPlan['ok'] === false && $unknownPlan['error_code'] === 'unknown_width');

echo "-- SlotPlanner::plan -- no free slots of any compatible width --\n";
$noPcieSlots = new TargetState([mbRow(1, '00000000-0000-0000-0000-000000000000')]); // motherboard with no matching spec -> no pcie_slot rows
// use a motherboard row whose spec resolves but has zero pcie_slot capacity instead, to avoid a CatalogException:
$mbNoDirectSlots = new TargetState([mbRow(1, 'd8e9f0a1-b2c3-4d4e-bf6a-7b8c9d0e1f2a')]); // MB_3647: riser_slots only, no direct pcie_slot
$noneFound = SlotPlanner::plan($mbNoDirectSlots, 'pcie_slot', 'x8');
check('no matching-resource slots (board needs a riser first): fails', $noneFound['ok'] === false && $noneFound['error_code'] === 'no_slots_available');

// -----------------------------------------------------------------------
echo "-- pcie.slot_placement rule --\n";
$feasible = new TargetState([mbRow(1, MB_DIRECT_SLOTS), nicRow(2, NIC_X8, null)]); // unplaced NIC
$r = (new PcieSlotPlacementRule())->evaluate($feasible);
check('unplaced x8 NIC on a board with free slots: passes', $r->passed() === true);
check('slot_placement severity is ERROR', $r->severity() === Severity::ERROR);

$alreadyPlaced = new TargetState([mbRow(1, MB_DIRECT_SLOTS), nicRow(2, NIC_X8, 'pcie_1_x16')]); // already has a slot_ref
check('already-placed NIC (slot_ref set): not re-planned, passes trivially', (new PcieSlotPlacementRule())->evaluate($alreadyPlaced)->passed() === true);

$onboardNic = new TargetState([mbRow(1, MB_DIRECT_SLOTS), nicRow(2, 'onboard-abc123', null)]);
check('onboard NIC (no real spec, slot_ref null): excluded from placement, passes', (new PcieSlotPlacementRule())->evaluate($onboardNic)->passed() === true);

// Fill all x8-compatible slots (4 x8 + 6 x16 = 10 slots that fit an x8 card) with placed NICs, then one more unplaced x8 NIC should fail.
$fillRows = [mbRow(1, MB_DIRECT_SLOTS)];
$state2 = new TargetState($fillRows);
$id = 2;
foreach (['x8', 'x8', 'x8', 'x8', 'x16', 'x16', 'x16', 'x16', 'x16', 'x16'] as $w) {
    $p = SlotPlanner::plan($state2, 'pcie_slot', $w);
    $fillRows[] = nicRow($id, NIC_X8, $p['slot_ref']);
    $state2 = new TargetState($fillRows);
    $id++;
}
$fillRows[] = nicRow($id, NIC_X8, null); // one more, unplaced -- no free x8-or-wider slot left
$fullState = new TargetState($fillRows);
$rFull = (new PcieSlotPlacementRule())->evaluate($fullState);
check('all compatible slots occupied: new unplaced x8 NIC fails', $rFull->passed() === false);

foreach (['SlotPlanner', 'PcieSlotPlacementRule'] as $class) {
    $path = is_file("$ROOT/core/models/validation/$class.php") ? "$ROOT/core/models/validation/$class.php" : "$ROOT/core/models/validation/rules/$class.php";
    $src = file_get_contents($path);
    check("$class.php contains no 'quantity' token (INV-1)", stripos($src, 'quantity') === false);
    check("$class.php touches no PDO (planner purity)", !preg_match('/\bPDO\s*\$|\\\\?PDO::/', $src));
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
