<?php
/**
 * Unit test — slot-ID namespace discrimination (audit D / D2).
 *
 * DB-free: the three discriminators are pure static functions over a string,
 * exercised via reflection. No PDO, no ims-data, no config rows.
 *
 * The defect being pinned down: three slot-ID namespaces exist and two share a
 * `riser_` prefix, so bare strpos() tests conflated them.
 *
 *   1. Motherboard PCIe slot   pcie_x16_slot_1
 *   2. Motherboard riser BAY   riser_x16_slot_1
 *   3. Riser-PROVIDED PCIe     riser_{uuid}_pcie_x16_slot_1
 *
 * Consequences this test locks out:
 *   D   getUsedPCIeSlots() missed namespace 3  -> riser slots always read as free
 *       getUsedRiserSlots() caught namespace 3 -> same card double-counted against a bay
 *   D2  validateRiserSlotIntegrity() ran its namespace-3 regex against namespace 2,
 *       reporting "invalid riser slot format" for correctly-seated riser cards
 *
 * Run: php tests/unit/slot_namespace_test.php
 */

require_once __DIR__ . '/../../core/models/compatibility/UnifiedSlotTracker.php';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  PASS  $label\n";
    } else {
        $failures++;
        echo "  FAIL  $label\n";
    }
}

$ref = new ReflectionClass(UnifiedSlotTracker::class);
// $slotId is deliberately untyped: the production helpers take an untyped
// argument and cast internally, so null/non-string input must reach them
// unchanged for the degenerate-input checks below to mean anything.
$fn = function (string $method, $slotId) use ($ref) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invoke(null, $slotId); // static
};

$isProvided = function ($s) use ($fn) { return $fn('isRiserProvidedPcieSlot', $s); };
$isPcie     = function ($s) use ($fn) { return $fn('isPcieSlotPosition', $s); };
$isBay      = function ($s) use ($fn) { return $fn('isRiserBaySlot', $s); };

// Real IDs as minted by the code, with the minting site for each.
$mbPcie    = 'pcie_x16_slot_1';                          // loadMotherboardPCIeSlots
$mbBay     = 'riser_x16_slot_1';                         // loadMotherboardRiserSlots
$mbBay8    = 'riser_x8_slot_3';                          // ditto, sized variant
$provided  = 'riser_a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d_pcie_x16_slot_1'; // loadRiserCardProvidedPCIeSlots

echo "\n-- namespace 1: motherboard PCIe slot --\n";
check('counts as a PCIe slot',        $isPcie($mbPcie) === true);
check('is not a riser bay',           $isBay($mbPcie) === false);
check('is not riser-provided',        $isProvided($mbPcie) === false);

echo "\n-- namespace 2: motherboard riser bay --\n";
check('is a riser bay',               $isBay($mbBay) === true);
// The D miscount: a bay must never be counted as PCIe capacity.
check('does NOT count as a PCIe slot', $isPcie($mbBay) === false);
check('is not riser-provided',        $isProvided($mbBay) === false);
check('sized variant is a riser bay', $isBay($mbBay8) === true);

echo "\n-- namespace 3: riser-provided PCIe slot --\n";
// The core of D: this must be visible to getUsedPCIeSlots()...
check('counts as a PCIe slot',        $isPcie($provided) === true);
check('is riser-provided',            $isProvided($provided) === true);
// ...and invisible to getUsedRiserSlots().
check('does NOT count as a riser bay', $isBay($provided) === false);

echo "\n-- the namespaces are mutually exclusive --\n";
foreach ([$mbPcie, $mbBay, $provided] as $slotId) {
    $hits = (int)$isPcie($slotId) + (int)$isBay($slotId);
    check("exactly one of {pcie, bay} matches '$slotId'", $hits === 1);
}

echo "\n-- D2: bay ids must survive the namespace-3 regex gate --\n";
// validateRiserSlotIntegrity() only runs the "references non-existent riser"
// regex when isRiserProvidedPcieSlot() is true. If a bay leaked in, the regex
// would fail and raise a false "invalid riser slot format".
check('bay is not routed to the riser-reference check', $isProvided($mbBay) === false);
check('provided slot IS routed to it',                  $isProvided($provided) === true);
// And the bay-format check used by the elseif must accept real bay ids.
check('real bay id matches the bay-format pattern',
    preg_match('/^riser_x\d+_slot_\d+$/i', $mbBay) === 1);
check('sized bay id matches the bay-format pattern',
    preg_match('/^riser_x\d+_slot_\d+$/i', $mbBay8) === 1);
check('genuinely malformed riser id is still rejected',
    preg_match('/^riser_x\d+_slot_\d+$/i', 'riser_garbage') === 0);

echo "\n-- uuid shapes cannot break the discriminator --\n";
// UUIDs are hex+hyphen, so they never contain the `_` that separates segments.
check('uppercase uuid still detected',
    $isProvided('riser_A1B2C3D4-E5F6-4A5B-8C9D-0E1F2A3B4C5D_pcie_x8_slot_2') === true);
check('x1 provided slot detected',
    $isProvided('riser_abc-123_pcie_x1_slot_1') === true);
check('riser-ish prefix without _pcie_ is a bay, not provided',
    $isProvided('riser_x16_slot_10') === false);

echo "\n-- degenerate inputs do not throw or misclassify --\n";
check('empty string is no namespace',
    $isPcie('') === false && $isBay('') === false && $isProvided('') === false);
check('null is no namespace',
    $isPcie(null) === false && $isBay(null) === false && $isProvided(null) === false);
check('unrelated id is no namespace',
    $isPcie('m2_slot_1') === false && $isBay('m2_slot_1') === false);
// Substring, not prefix — must not match.
check('embedded prefix does not match',
    $isPcie('x_pcie_x16_slot_1') === false);

echo "\n";
if ($failures > 0) {
    echo "$failures of $checks CHECKS FAILED\n";
    exit(1);
}
echo "ALL $checks CHECKS PASS\n";
exit(0);
