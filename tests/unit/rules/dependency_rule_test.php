<?php
/**
 * dependency_rule_test.php — U-R.8 unit test for dependency.blocked_removal
 * and TargetStateBuilder::dependentsOf(). Pure PHP + real ims-data fixtures
 * (no DB). Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/TargetStateBuilder.php';
require_once $ROOT . '/core/models/validation/rules/DependencyBlockedRemovalRule.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Real fixture UUIDs (reused from lane_rule_test.php / system_rules_test.php / fixture_scenarios_real.php).
const MB_3647 = 'd8e9f0a1-b2c3-4d4e-bf6a-7b8c9d0e1f2a';
const CPU_UUID = '545e143b-57b3-419e-86e5-1df6f7aa8fd3';
const RAM_UUID = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c';
const CHS_BIG = 'b8106f02-636e-40cc-ba7f-baa5e23ecb53';
const HBA_UUID = '19b8d97b-9634-4c77-b274-179e29abbb6c';
const NIC_UUID = 'da6c533b-7475-4364-989c-f6c7dd442efa';
const STORAGE_UUID = 'e1a2b3c4-d5e6-f7a8-b9c0-d1e2f3a4b5c6';
const SFP_UUID = '32bc2712-98a6-421f-85f5-4efb68e4ee00'; // SFP-10G-SR (SFP+)
const CADDY_UUID = '4a8a2c05-e993-4b00-acae-9f036617091c';

require_once $ROOT . '/core/models/shared/DataExtractionUtilities.php';
$dataUtils = new DataExtractionUtilities();
$riserUuid = null;
$imsDataPath = getenv('IMS_DATA_PATH') ?: ($ROOT . '/../ims-data');
$pciSpecs = json_decode(file_get_contents($imsDataPath . '/pciecard/pci-level-3.json'), true);
foreach ($pciSpecs as $family) {
    if (($family['component_subtype'] ?? '') !== 'Riser Card') { continue; }
    foreach ($family['models'] as $m) { $riserUuid = $m['UUID']; break 2; }
}
if ($riserUuid === null) { echo "FATAL: no real Riser Card fixture found in ims-data\n"; exit(1); }

function row($id, $type, $uuid, $extra = []) {
    return array_merge([
        'id' => $id, 'component_type' => $type, 'spec_uuid' => $uuid,
        'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null,
        'parent_id' => null, 'slot_ref' => null, 'source' => 'rows', 'status_v2' => null,
    ], $extra);
}

$rule = new DependencyBlockedRemovalRule();

// =========================================================================
echo "-- board-with-cpus(+ram) -- remove the only motherboard while cpu+ram present --\n";
// TargetStateBuilder::withRemove(cascade=false) already removed the mb row before the rule runs.
$boardRemoved = new TargetState([row(2, 'cpu', CPU_UUID), row(3, 'ram', RAM_UUID)]);
$r1 = $rule->evaluate($boardRemoved);
check('cpu+ram orphaned when motherboard removed: fails', $r1->passed() === false);
check('dependency severity is ERROR', $r1->severity() === Severity::ERROR);
$ids1 = array_column($r1->details()['dependents'], 'component_type');
check('dependents list BOTH cpu and ram (not just one)', in_array('cpu', $ids1) && in_array('ram', $ids1));

echo "-- board-with-cpus -- but a riser still present: cpu/ram still orphaned (they need motherboard specifically, not riser) --\n";
$boardRemovedRiserPresent = new TargetState([row(2, 'cpu', CPU_UUID), row(4, 'pciecard', $riserUuid)]);
check('cpu still orphaned even with a riser present (riser is not in cpu\'s DEPENDS_ON)', $rule->evaluate($boardRemovedRiserPresent)->passed() === false);

// =========================================================================
echo "-- riser-with-cards -- remove the only riser while a card is plugged into it, no motherboard in this config --\n";
$riserRemoved = new TargetState([row(5, 'nic', NIC_UUID)]); // riser gone, no motherboard either
$r2 = $rule->evaluate($riserRemoved);
check('nic orphaned when its only riser is removed (no motherboard either): fails', $r2->passed() === false);

echo "-- riser-with-cards -- a motherboard also present: card is NOT orphaned (motherboard|riser is OR) --\n";
// chassis included too: motherboard itself depends on chassis (DEPENDS_ON['motherboard']) -- not the thing under test here.
$riserRemovedButMbPresent = new TargetState([row(0, 'chassis', CHS_BIG), row(1, 'motherboard', MB_3647), row(5, 'nic', NIC_UUID)]);
check('nic satisfied by motherboard alone: passes', $rule->evaluate($riserRemovedButMbPresent)->passed() === true);

// =========================================================================
echo "-- hba-with-drives -- remove the only hbacard while storage present, no chassis/motherboard in this config --\n";
$hbaRemoved = new TargetState([row(6, 'storage', STORAGE_UUID)]);
$r3 = $rule->evaluate($hbaRemoved);
check('storage orphaned when hbacard removed with no chassis/motherboard route: fails', $r3->passed() === false);

// =========================================================================
echo "-- chassis-with-bays -- remove the only chassis while a caddy present --\n";
$chassisRemoved = new TargetState([row(7, 'caddy', CADDY_UUID)]);
$r4 = $rule->evaluate($chassisRemoved);
check('caddy orphaned when chassis removed: fails', $r4->passed() === false);

// =========================================================================
echo "-- nic-with-sfps -- parity with the ONE legacy check (removeComponent's nic->sfp special case) --\n";
$nicWithDanglingSfp = new TargetState([
    row(8, 'sfp', SFP_UUID, ['parent_id' => 9]), // parent (nic id 9) was just removed
]);
$r5 = $rule->evaluate($nicWithDanglingSfp);
check('sfp with a dangling parent_id (its nic was removed): fails', $r5->passed() === false);
check('dependents detail includes the sfp row', $r5->details()['dependents'][0]['component_type'] === 'sfp');

echo "-- nic-with-sfps -- sfp still resolves to a live nic: passes --\n";
// chassis+motherboard included too: the nic itself depends on motherboard|riser (DEPENDS_ON['nic']) -- not the thing under test here.
$nicStillPresent = new TargetState([
    row(0, 'chassis', CHS_BIG), row(1, 'motherboard', MB_3647),
    row(9, 'nic', NIC_UUID), row(8, 'sfp', SFP_UUID, ['parent_id' => 9]),
]);
check('sfp with a live parent nic: passes', $rule->evaluate($nicStillPresent)->passed() === true);

// =========================================================================
echo "-- cascade=true: builder already removed the whole parent_id subtree, rule passes for that mechanism --\n";
$preRemove = new TargetState([row(1, 'nic', NIC_UUID), row(2, 'sfp', SFP_UUID, ['parent_id' => 1])]);
$postCascade = TargetStateBuilder::withRemove($preRemove, 1, true);
check('cascade removed both nic and sfp -- nothing left to be dangling', count($postCascade->components()) === 0);
check('rule passes on the fully-cascaded state', $rule->evaluate($postCascade)->passed() === true);

echo "-- empty state: trivially passes --\n";
check('empty state passes', $rule->evaluate(new TargetState([]))->passed() === true);

echo "-- triggers are REMOVE+REPLACE only --\n";
check('triggers', $rule->triggers() === [Trigger::REMOVE, Trigger::REPLACE]);

// =========================================================================
echo "-- TargetStateBuilder::dependentsOf() -- pure closure over parent_id + resource-slot links --\n";
$nicSfpState = new TargetState([row(1, 'nic', NIC_UUID), row(2, 'sfp', SFP_UUID, ['parent_id' => 1])]);
$deps = TargetStateBuilder::dependentsOf($nicSfpState, 1);
check('dependentsOf(nic) finds its child sfp', count($deps) === 1 && $deps[0]['id'] === 2);

check('dependentsOf() on an id not in the state returns []', TargetStateBuilder::dependentsOf(new TargetState([]), 999) === []);

$riserWithCard = new TargetState([
    row(1, 'pciecard', $riserUuid),
    row(2, 'pciecard', 'c8384a51-5630-4ecf-9ecc-15bc660a4b17', ['slot_ref' => 'riser_provided_pcie_1_x8']),
]);
$slotDeps = TargetStateBuilder::dependentsOf($riserWithCard, 1);
check('dependentsOf(riser) finds the card occupying its provided slot_ref (resource-link, no parent_id needed)', count($slotDeps) === 1 && $slotDeps[0]['id'] === 2);

// =========================================================================
echo "-- INV-1 / INV-7 grep checks --\n";
$src = file_get_contents("$ROOT/core/models/validation/rules/DependencyBlockedRemovalRule.php");
check("DependencyBlockedRemovalRule.php contains no 'quantity' token (INV-1)", stripos($src, 'quantity') === false);
check("DependencyBlockedRemovalRule.php reads no env var (INV-7)", !preg_match('/getenv|\$_ENV/', $src));

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
