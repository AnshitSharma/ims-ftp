<?php
/**
 * storage_rules_test.php — U-R.5 unit test for the storage.* rule family.
 * Pure PHP + real ims-data fixtures (no DB). Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/rules/StorageInterfacePathRule.php';
require_once $ROOT . '/core/models/validation/rules/StorageBayCapacityRule.php';
require_once $ROOT . '/core/models/validation/rules/StorageM2CapacityRule.php';
require_once $ROOT . '/core/models/validation/rules/StorageCaddyPairingRule.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Real fixtures.
const CHS_2BAY = 'a8f3b25d-4f1c-4b95-a3b0-fc30f5b12da8'; // 2x 2.5" bays
const ST_SSD25 = 'a3b4c5d6-e7f8-a9b0-c1d2-e3f4a5b6c7d8'; // 2.5" SATA
const ST_M2 = 'b4c5d6e7-f8a9-b0c1-d2e3-f4a5b6c7d8e9';    // M.2 NVMe
const MB_M2_4SLOTS = '8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c'; // 4x M.2 slots
const CADDY_25 = '4a8a2c05-e993-4b00-acae-9f036617091c';

function chsRow($id, $uuid) { return ['id' => $id, 'component_type' => 'chassis', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }
function mbRow($id, $uuid) { return ['id' => $id, 'component_type' => 'motherboard', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }
function stRow($id, $uuid) { return ['id' => $id, 'component_type' => 'storage', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }
function caddyRow($id, $uuid) { return ['id' => $id, 'component_type' => 'caddy', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }

// -----------------------------------------------------------------------
echo "-- storage.bay_capacity (E) -- 2.5/3.5 strict matching --\n";
$oneDrive = new TargetState([chsRow(1, CHS_2BAY), stRow(2, ST_SSD25)]);
check('1x 2.5" drive in a 2-bay 2.5" chassis: passes', (new StorageBayCapacityRule())->evaluate($oneDrive)->passed() === true);

$threeDrives = new TargetState([chsRow(1, CHS_2BAY), stRow(2, ST_SSD25), stRow(3, ST_SSD25), stRow(4, ST_SSD25)]);
$r = (new StorageBayCapacityRule())->evaluate($threeDrives);
check('3x 2.5" drives in a 2-bay chassis: fails', $r->passed() === false);
check('bay_capacity severity is ERROR', $r->severity() === Severity::ERROR);

$m2InBayChassis = new TargetState([chsRow(1, CHS_2BAY), stRow(2, ST_M2), stRow(3, ST_M2), stRow(4, ST_M2)]);
check('M.2 storage bypasses bay validation entirely (3x M.2 in a 2-bay chassis still passes)', (new StorageBayCapacityRule())->evaluate($m2InBayChassis)->passed() === true);

check('no chassis: bay check does not apply', (new StorageBayCapacityRule())->evaluate(new TargetState([stRow(1, ST_SSD25)]))->passed() === true);

// -----------------------------------------------------------------------
echo "-- storage.m2_capacity (E) -- A-10: read-time W promoted to blocking E --\n";
$withinM2 = new TargetState(array_merge([mbRow(1, MB_M2_4SLOTS)], array_map(function ($i) { return stRow($i, ST_M2); }, range(2, 5))));
check('4x M.2 on a 4-slot board: passes', (new StorageM2CapacityRule())->evaluate($withinM2)->passed() === true);

$overM2 = new TargetState(array_merge([mbRow(1, MB_M2_4SLOTS)], array_map(function ($i) { return stRow($i, ST_M2); }, range(2, 6))));
$rM2 = (new StorageM2CapacityRule())->evaluate($overM2);
check('5x M.2 on a 4-slot board: fails (A-10)', $rM2->passed() === false);
check('m2_capacity severity is ERROR', $rM2->severity() === Severity::ERROR);

// -----------------------------------------------------------------------
echo "-- storage.caddy_pairing (VF) -- read-time W promoted to VF --\n";
$paired = new TargetState([stRow(1, ST_SSD25), caddyRow(2, CADDY_25)]);
check('1 drive, 1 matching caddy: passes', (new StorageCaddyPairingRule())->evaluate($paired)->passed() === true);

$shortage = new TargetState([stRow(1, ST_SSD25), stRow(2, ST_SSD25)]); // 2 drives, 0 caddies
$rCaddy = (new StorageCaddyPairingRule())->evaluate($shortage);
check('2 drives, 0 caddies: fails (shortage)', $rCaddy->passed() === false);
check('caddy_pairing severity is VALIDATION_FAILURE', $rCaddy->severity() === Severity::VALIDATION_FAILURE);

$excess = new TargetState([stRow(1, ST_SSD25), caddyRow(2, CADDY_25), caddyRow(3, CADDY_25)]);
check('1 drive, 2 caddies (excess): still passes -- excess is informational only, never blocks', (new StorageCaddyPairingRule())->evaluate($excess)->passed() === true);

// -----------------------------------------------------------------------
echo "-- storage.interface_path (E) -- simplified SAS-without-HBA block --\n";
check('SATA storage, no HBA: passes (SATA never hard-blocks on path)', (new StorageInterfacePathRule())->evaluate(new TargetState([stRow(1, ST_SSD25)])) !== null && (new StorageInterfacePathRule())->evaluate(new TargetState([stRow(1, ST_SSD25)]))->passed() === true);

foreach (['StorageInterfacePathRule', 'StorageBayCapacityRule', 'StorageM2CapacityRule', 'StorageCaddyPairingRule'] as $class) {
    $src = file_get_contents("$ROOT/core/models/validation/rules/$class.php");
    check("$class.php contains no 'quantity' token (INV-1)", stripos($src, 'quantity') === false);
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
