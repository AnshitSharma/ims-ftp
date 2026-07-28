<?php
/**
 * m2_capacity_rule_test.php — regression test for StorageM2CapacityRule (F-25).
 *
 * Guards the 2026-07-28 fix. The rule was ported from legacy's READ-TIME warning
 * (ServerBuilder::getConfigurationWarnings(), guarded by $m2TotalSlots > 0) instead
 * of the ADD-TIME gate that actually blocks
 * (ComponentValidator::validateMotherboardM2Storage(): `if ($m2Slots <= 0)`).
 * It therefore carried a `&& $capacity > 0` guard that made a board with ZERO M.2
 * slots accept unlimited M.2 drives — the exact inversion of legacy.
 *
 * Caught in production shadow: 2026-07-28T15:10:15Z, config 2fcea743, HP ProLiant
 * DL360 Gen9 (storage.nvme.m2_slots: []). Legacy blocked, engine passed all 16 rules.
 *
 * Real ims-data fixtures, real ResourceCatalog, no DB. Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/components/ComponentSpecPaths.php';
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/rules/StorageM2CapacityRule.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Real fixtures.
const MB_ZERO_M2 = '4c8f5e1b-2b4a-4c8d-b9e7-f6d2a3c1e9b8'; // DL360 Gen9 — m2_slots: [] (the F-25 board)
const MB_FOUR_M2 = '8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c'; // X13DRG-H — m2_slots: [{count: 4}]
const ST_M2      = 'b4c5d6e7-f8a9-b0c1-d2e3-f4a5b6c7d8e9'; // M.2 2280, NVMe PCIe 4.0
const ST_25_SATA = 'f54497fd-5cd3-4b5a-8cd2-276a68af11ac'; // 2.5-inch U.2 — NOT an M.2 drive

$nextId = 1;
function comp($type, $specUuid) {
    global $nextId;
    return [
        'id' => $nextId++,
        'component_type' => $type,
        'spec_uuid' => $specUuid,
        'inventory_table' => $type . 'inventory',
        'inventory_id' => 1000 + $nextId,
        'serial_number' => null,
        'parent_id' => null,
        'slot_ref' => null,
        'source' => 'rows',
        'status_v2' => 'installed',
    ];
}

function verdictFor(array $components) {
    $rule = new StorageM2CapacityRule();
    return $rule->evaluate(new TargetState($components));
}

echo "\n-- Fixture sanity (fails loudly if ims-data shifts under the test) --\n";
$catalog = new ResourceCatalog();
$zeroRows = $catalog->provides('motherboard', MB_ZERO_M2);
$fourRows = $catalog->provides('motherboard', MB_FOUR_M2);
$capOf = function ($rows) {
    $t = 0;
    foreach ($rows as $r) { if ($r['resource'] === 'm2_slot') { $t += (int)$r['capacity']; } }
    return $t;
};
check('DL360 Gen9 still derives m2_slot capacity 0', $capOf($zeroRows) === 0);
check('X13DRG-H still derives m2_slot capacity 4', $capOf($fourRows) === 4);

echo "\n-- F-25: zero M.2 slots must BLOCK an M.2 drive (was failing open) --\n";
$v = verdictFor([comp('motherboard', MB_ZERO_M2), comp('storage', ST_M2)]);
check('blocks M.2 drive on a board with no M.2 slots', $v->passed() === false);
check('severity is ERROR (blocks at ADD, not deferred)', $v->severity() === Severity::ERROR);
check('message matches legacy ComponentValidator wording',
    strpos($v->message(), 'No M.2 slots available') === 0);

echo "\n-- Must NOT become a blanket block --\n";
$v = verdictFor([comp('motherboard', MB_ZERO_M2)]);
check('board with no M.2 slots and no storage passes', $v->passed() === true);

$v = verdictFor([comp('motherboard', MB_ZERO_M2), comp('storage', ST_25_SATA)]);
check('board with no M.2 slots + a NON-M.2 drive passes', $v->passed() === true);

$v = verdictFor([comp('motherboard', MB_FOUR_M2), comp('storage', ST_M2)]);
check('1 M.2 drive into 4 slots passes', $v->passed() === true);

$v = verdictFor([
    comp('motherboard', MB_FOUR_M2),
    comp('storage', ST_M2), comp('storage', ST_M2),
    comp('storage', ST_M2), comp('storage', ST_M2),
]);
check('4 M.2 drives into 4 slots passes (boundary)', $v->passed() === true);

echo "\n-- A-10 intentional diff preserved (over-subscription still blocks) --\n";
$v = verdictFor([
    comp('motherboard', MB_FOUR_M2),
    comp('storage', ST_M2), comp('storage', ST_M2),
    comp('storage', ST_M2), comp('storage', ST_M2), comp('storage', ST_M2),
]);
check('5 M.2 drives into 4 slots blocks', $v->passed() === false);
check('over-subscription keeps its own wording',
    strpos($v->message(), 'M.2 slots exceeded') === 0);

echo "\n-- Guard intact: no motherboard, no opinion --\n";
$v = verdictFor([comp('storage', ST_M2)]);
check('no motherboard => rule does not apply', $v->passed() === true);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
