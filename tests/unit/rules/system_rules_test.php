<?php
/**
 * system_rules_test.php — U-R.7 unit test for the system.* rule family.
 * Pure PHP + real ims-data fixtures (no DB), same UUIDs
 * tests/fixture_scenarios_real.php / lane_rule_test.php use where they overlap.
 * Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/rules/SystemRequiredSetRule.php';
require_once $ROOT . '/core/models/validation/rules/SystemSingletonRule.php';
require_once $ROOT . '/core/models/validation/rules/SystemPsuCapacityRule.php';
require_once $ROOT . '/core/models/validation/rules/SystemInventoryStateRule.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Real fixture UUIDs (same ones tests/fixture_scenarios_real.php / lane_rule_test.php use,
// plus CHS_BIG (power_supply.wattage=800) and one hbacard/pciecard/storage each with a
// confirmed structured power_consumption field).
const MB_3647 = 'd8e9f0a1-b2c3-4d4e-bf6a-7b8c9d0e1f2a';
const CPU_UUID = '545e143b-57b3-419e-86e5-1df6f7aa8fd3'; // tdp_W=350
const CHS_BIG = 'b8106f02-636e-40cc-ba7f-baa5e23ecb53';  // power_supply.wattage=800
const HBA_UUID = '19b8d97b-9634-4c77-b274-179e29abbb6c'; // power_consumption.typical_W=14.8
const NIC_UUID = 'da6c533b-7475-4364-989c-f6c7dd442efa'; // power="15W"
const RAM_UUID = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c'; // no structured power field -> 0W

function row($id, $type, $uuid, $extra = []) {
    return array_merge([
        'id' => $id, 'component_type' => $type, 'spec_uuid' => $uuid,
        'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null,
        'parent_id' => null, 'slot_ref' => null, 'source' => 'rows', 'status_v2' => null,
    ], $extra);
}

// =========================================================================
echo "-- system.required_set (VF) --\n";
$reqRule = new SystemRequiredSetRule();
$full = new TargetState([
    row(1, 'chassis', CHS_BIG), row(2, 'motherboard', MB_3647), row(3, 'cpu', CPU_UUID),
    row(4, 'ram', RAM_UUID), row(5, 'storage', 'e1a2b3c4-d5e6-f7a8-b9c0-d1e2f3a4b5c6'), row(6, 'nic', NIC_UUID),
]);
$r = $reqRule->evaluate($full);
check('all six required types present: passes', $r->passed() === true);
check('required_set severity is VALIDATION_FAILURE', $r->severity() === Severity::VALIDATION_FAILURE);

$missingNic = new TargetState([
    row(1, 'chassis', CHS_BIG), row(2, 'motherboard', MB_3647), row(3, 'cpu', CPU_UUID),
    row(4, 'ram', RAM_UUID), row(5, 'storage', 'e1a2b3c4-d5e6-f7a8-b9c0-d1e2f3a4b5c6'),
]);
$rMissing = $reqRule->evaluate($missingNic);
check('missing nic: fails', $rMissing->passed() === false);
check('missing-nic details list "nic"', in_array('nic', $rMissing->details()['missing'] ?? []));

$onlyStorageRecommendedLegacy = new TargetState([row(1, 'cpu', CPU_UUID), row(2, 'motherboard', MB_3647), row(3, 'ram', RAM_UUID)]);
check('cpu+mb+ram only (legacy validateConfiguration\'s old list): still fails -- one list now (chassis/storage/nic also required)',
    $reqRule->evaluate($onlyStorageRecommendedLegacy)->passed() === false);

check('required_set triggers are VALIDATE+FINALIZE only', $reqRule->triggers() === [Trigger::VALIDATE, Trigger::FINALIZE]);

// =========================================================================
echo "-- system.singleton (E) --\n";
$singRule = new SystemSingletonRule();
check('one motherboard, one chassis: passes', $singRule->evaluate(new TargetState([
    row(1, 'motherboard', MB_3647), row(2, 'chassis', CHS_BIG),
]))->passed() === true);

$twoMb = new TargetState([row(1, 'motherboard', MB_3647), row(2, 'motherboard', MB_3647)]);
$rTwoMb = $singRule->evaluate($twoMb);
check('two motherboards: fails', $rTwoMb->passed() === false);
check('singleton severity is ERROR', $rTwoMb->severity() === Severity::ERROR);

check('two chassis: fails', $singRule->evaluate(new TargetState([
    row(1, 'chassis', CHS_BIG), row(2, 'chassis', CHS_BIG),
]))->passed() === false);

check('multiple HBA cards allowed (H8 bugfix, not a regression)', $singRule->evaluate(new TargetState([
    row(1, 'hbacard', HBA_UUID), row(2, 'hbacard', HBA_UUID), row(3, 'hbacard', HBA_UUID),
]))->passed() === true);

check('singleton triggers include ADD+REPLACE (unlike required_set)', $singRule->triggers() === [Trigger::ADD, Trigger::REPLACE, Trigger::VALIDATE, Trigger::FINALIZE]);

// =========================================================================
echo "-- system.psu_capacity (E) -- V-4: legacy scoring became a hard block --\n";
$psuRule = new SystemPsuCapacityRule();

check('no chassis: nothing to budget against, passes', $psuRule->evaluate(new TargetState([row(1, 'cpu', CPU_UUID)]))->passed() === true);

$chassisOnly = new TargetState([row(1, 'chassis', CHS_BIG)]);
$rEmpty = $psuRule->evaluate($chassisOnly);
check('chassis alone (800W rated, 680W usable), no consumers: passes, consumed=0', $rEmpty->passed() === true && $rEmpty->details()['consumed_watts'] === 0);

$oneCpu = new TargetState([row(1, 'chassis', CHS_BIG), row(2, 'cpu', CPU_UUID)]);
$rOneCpu = $psuRule->evaluate($oneCpu);
check('chassis + 1 CPU (350W tdp): within 680W usable, passes', $rOneCpu->passed() === true && $rOneCpu->details()['consumed_watts'] === 350);

$twoCpus = new TargetState([row(1, 'chassis', CHS_BIG), row(2, 'cpu', CPU_UUID), row(3, 'cpu', CPU_UUID)]);
$rTwoCpus = $psuRule->evaluate($twoCpus);
check('chassis + 2 CPUs (700W): exceeds 680W usable of 800W rated, fails', $rTwoCpus->passed() === false);
check('over-budget details carry rated/usable/consumed/over_by', isset($rTwoCpus->details()['rated_watts'], $rTwoCpus->details()['usable_watts'], $rTwoCpus->details()['consumed_watts'], $rTwoCpus->details()['over_by']));

$mixedConsumers = new TargetState([
    row(1, 'chassis', CHS_BIG), row(2, 'cpu', CPU_UUID), row(3, 'hbacard', HBA_UUID),
    row(4, 'nic', NIC_UUID), row(5, 'ram', RAM_UUID),
]);
$rMixed = $psuRule->evaluate($mixedConsumers);
// 350 (cpu) + 15 (hba, ceil(14.8)) + 15 (nic, "15W") + 0 (ram, no structured field) = 380
check('mixed consumers (cpu+hba+nic+ram): consumed=380W (ram contributes 0, no structured field)', $rMixed->details()['consumed_watts'] === 380);
check('mixed consumers within 680W usable: passes', $rMixed->passed() === true);

check('psu_capacity triggers are VALIDATE+FINALIZE only', $psuRule->triggers() === [Trigger::VALIDATE, Trigger::FINALIZE]);

// =========================================================================
echo "-- system.inventory_state (E) -- V-2: non-blocking issue became a hard block --\n";
$invRule = new SystemInventoryStateRule();

check('all available: passes', $invRule->evaluate(new TargetState([
    row(1, 'storage', 'e1a2b3c4-d5e6-f7a8-b9c0-d1e2f3a4b5c6', ['status_v2' => 'available']),
]))->passed() === true);

$oneFailed = new TargetState([
    row(1, 'storage', 'e1a2b3c4-d5e6-f7a8-b9c0-d1e2f3a4b5c6', ['status_v2' => 'failed']),
]);
$rFailed = $invRule->evaluate($oneFailed);
check('a failed drive: fails (closes V-2)', $rFailed->passed() === false);
check('inventory_state severity is ERROR', $rFailed->severity() === Severity::ERROR);
check('offenders detail lists the failed component', count($rFailed->details()['offenders'] ?? []) === 1);

check('retired blocks', $invRule->evaluate(new TargetState([
    row(1, 'cpu', CPU_UUID, ['status_v2' => 'retired']),
]))->passed() === false);
check('maintenance blocks', $invRule->evaluate(new TargetState([
    row(1, 'cpu', CPU_UUID, ['status_v2' => 'maintenance']),
]))->passed() === false);
check('installed does not block', $invRule->evaluate(new TargetState([
    row(1, 'cpu', CPU_UUID, ['status_v2' => 'installed']),
]))->passed() === true);

check('null status_v2 (json-fallback / unresolved rows-path row): unknown, passes -- never fabricated', $invRule->evaluate(new TargetState([
    row(1, 'cpu', CPU_UUID, ['status_v2' => null]),
]))->passed() === true);

check('inventory_state triggers are VALIDATE+FINALIZE only', $invRule->triggers() === [Trigger::VALIDATE, Trigger::FINALIZE]);

// =========================================================================
echo "-- INV-1 / INV-7 grep checks --\n";
foreach (['SystemRequiredSetRule', 'SystemSingletonRule', 'SystemPsuCapacityRule', 'SystemInventoryStateRule'] as $cls) {
    $src = file_get_contents("$ROOT/core/models/validation/rules/$cls.php");
    check("$cls.php contains no 'quantity' token (INV-1)", stripos($src, 'quantity') === false);
    check("$cls.php reads no env var (INV-7)", !preg_match('/getenv|\$_ENV/', $src));
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
