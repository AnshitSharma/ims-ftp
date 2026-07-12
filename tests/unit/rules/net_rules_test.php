<?php
/**
 * net_rules_test.php — U-R.6 unit test for the net.* rule family.
 * Pure PHP + real ims-data fixtures (no DB). Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/rules/NetSfpPortRule.php';
require_once $ROOT . '/core/models/validation/rules/NetNicRequirementsRule.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Real fixtures (tests/fixture_scenarios_real.php's R6/R7/R8 scenarios).
const NIC_SFPP = 'da6c533b-7475-4364-989c-f6c7dd442efa'; // port_type SFP+, ports 2
const SFP_10G = '32bc2712-98a6-421f-85f5-4efb68e4ee00';  // type SFP+ -- exact match
const SFP_25G = '0035b99b-6a00-4a80-afad-134a0393601f';  // type SFP28 -- incompatible with SFP+ port
const SFP_1G = '4c2f2f42-aa7b-4d8d-848b-103d8e37fd1d';   // type SFP -- backward-compatible (H5)

function nicRow($id, $uuid) { return ['id' => $id, 'component_type' => 'nic', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }
function sfpRow($id, $uuid, $parentId, $port) { return ['id' => $id, 'component_type' => 'sfp', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => $parentId, 'slot_ref' => $port, 'source' => 'rows']; }

// -----------------------------------------------------------------------
echo "-- net.sfp_port (E) -- R6/R7/R8 fixture scenarios ported --\n";
$r6 = new TargetState([nicRow(1, NIC_SFPP), sfpRow(2, SFP_10G, 1, 'port_1')]);
check('R6 sfp-cage-match: SFP+ into SFP+ cage passes', (new NetSfpPortRule())->evaluate($r6)->passed() === true);

$r7 = new TargetState([nicRow(1, NIC_SFPP), sfpRow(2, SFP_25G, 1, 'port_1')]);
$r = (new NetSfpPortRule())->evaluate($r7);
check('R7 sfp-cage-mismatch: SFP28 into SFP+ cage fails', $r->passed() === false);
check('sfp_port severity is ERROR', $r->severity() === Severity::ERROR);

$r8 = new TargetState([nicRow(1, NIC_SFPP), sfpRow(2, SFP_1G, 1, 'port_1')]);
check('R8 sfp-1g-into-sfpplus (H5): 1G SFP into SFP+ cage passes (backward-compat)', (new NetSfpPortRule())->evaluate($r8)->passed() === true);

echo "-- net.sfp_port -- staged/unassigned SFP allowed (TP-4A) --\n";
$staged = new TargetState([sfpRow(1, SFP_10G, null, null)]);
check('SFP with no parent_id at all: passes (staged workflow)', (new NetSfpPortRule())->evaluate($staged)->passed() === true);

echo "-- net.sfp_port -- parent_id referencing a nonexistent NIC blocks --\n";
$danglingParent = new TargetState([sfpRow(1, SFP_10G, 999, 'port_1')]); // parent_id 999 doesn't exist in state
$rDangling = (new NetSfpPortRule())->evaluate($danglingParent);
check('SFP with a parent_id that resolves to nothing: fails', $rDangling->passed() === false);

echo "-- net.sfp_port -- port collision (two SFPs claiming the same port on the same NIC) --\n";
$collision = new TargetState([nicRow(1, NIC_SFPP), sfpRow(2, SFP_10G, 1, 'port_1'), sfpRow(3, SFP_1G, 1, 'port_1')]);
check('two SFPs on the same NIC port: fails', (new NetSfpPortRule())->evaluate($collision)->passed() === false);

$noCollision = new TargetState([nicRow(1, NIC_SFPP), sfpRow(2, SFP_10G, 1, 'port_1'), sfpRow(3, SFP_1G, 1, 'port_2')]);
check('two SFPs on different ports of the same NIC: passes', (new NetSfpPortRule())->evaluate($noCollision)->passed() === true);

// -----------------------------------------------------------------------
echo "-- net.nic_requirements (W) -- honest-gap placeholder (SR-IOV note has no legacy source) --\n";
check('NIC with declared port_type: no warning', (new NetNicRequirementsRule())->evaluate(new TargetState([nicRow(1, NIC_SFPP)]))->passed() === true);
check('nic_requirements severity is WARNING', (new NetNicRequirementsRule())->severity() === Severity::WARNING);
check('onboard NIC (no real spec_uuid): excluded, passes', (new NetNicRequirementsRule())->evaluate(new TargetState([nicRow(1, 'onboard-xyz')]))->passed() === true);

foreach (['NetSfpPortRule', 'NetNicRequirementsRule'] as $class) {
    $src = file_get_contents("$ROOT/core/models/validation/rules/$class.php");
    check("$class.php contains no 'quantity' token (INV-1)", stripos($src, 'quantity') === false);
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
