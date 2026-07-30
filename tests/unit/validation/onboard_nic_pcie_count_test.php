<?php
/**
 * onboard_nic_pcie_count_test.php — regression test for UnifiedSlotTracker (F-28).
 *
 * Guards the 2026-07-29 fix. UnifiedSlotTracker::validateAllSlots() counted every
 * `nic` row toward $pcieCount, including the synthetic onboard rows materialized
 * from the board's networking.onboard_nics. Onboard NICs sit on the embedded LOM
 * connector and occupy no discrete PCIe slot — legacy's own PcieLaneBudgetValidator
 * (:186) and the engine's PcieSlotPlacementRule (:71) both already skip them on the
 * "onboard-" spec-uuid prefix. This one count did not.
 *
 * Effect was a FINALIZE OUTAGE, not a cosmetic miscount: on a riser-only board
 * (every DL360 Gen9 PCIe slot is riser_card_required, so an unrisered board reports
 * zero available slots — correctly), a config whose only "PCIe" component was its
 * auto-attached onboard NIC failed comprehensive validation with
 *   "Configuration has 1 PCIe components but motherboard has no PCIe slots"
 * while carrying no expansion card at all. Observed on config d0538f58
 * (cmd-layer-traffic-2, onboard-4c8f5e1b-53-1) 2026-07-29.
 *
 * validateAllSlots() needs a PDO and a persisted configuration, and the scratch DB
 * predates P2, so a behavioural test here could only SKIP — which would prove
 * nothing (the exact failure mode F-27 catalogued). These are structural pins over
 * the real source plus a direct check of the prefix predicate against every onboard
 * uuid format ResourceCatalog::parseOnboardNicUuid() accepts. No DB. Exit 0 = pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

echo "onboard_nic_pcie_count_test (F-28)\n";

// ---------------------------------------------------------------- predicate --
// The prefix test the fix uses, applied to all three synthetic formats. If a new
// format is ever introduced that does not start with "onboard-", both this and the
// two sibling validators silently start counting it again.
echo "\n[1] 'onboard-' prefix covers every synthetic onboard-NIC format\n";

$onboardUuids = [
    'onboard-4c8f5e1b-53-1'                    => 'unit-scoped (current) — the live d0538f58 row',
    'onboard-4c8f5e1b-1'                       => 'legacy, model-scoped',
    'onboard-nic-4c8f5e1b2b4a4c8db9e7f6d2-1'   => 'legacy, ServerBuilder dead generator',
];
foreach ($onboardUuids as $uuid => $why) {
    check("excluded: $uuid  ($why)", strpos($uuid, 'onboard-') === 0);
    // The canonical helper must agree with the inline prefix test the tracker uses;
    // if these ever diverge, one layer counts a slot the other does not.
    check("  ResourceCatalog::isOnboardNicUuid agrees", ResourceCatalog::isOnboardNicUuid($uuid) === true);
}

$discreteUuids = [
    '019eca1d-0000-4000-8000-000000000001' => 'discrete NIC',
    'a1b2c3d4-e5f6-4718-9a0b-1c2d3e4f5a6b' => 'pciecard',
];
foreach ($discreteUuids as $uuid => $why) {
    check("still counted: $uuid ($why)", strpos($uuid, 'onboard-') !== 0);
    check("  ResourceCatalog::isOnboardNicUuid agrees", ResourceCatalog::isOnboardNicUuid($uuid) === false);
}

// ------------------------------------------------------------- source pins --
// validateAllSlots() is DB-bound, so pin the fix at the source instead of pretending
// to exercise it. A pin that reads the file it guards cannot pass vacuously.
echo "\n[2] UnifiedSlotTracker::validateAllSlots still excludes onboard NICs\n";

$trackerPath = $ROOT . '/core/models/compatibility/UnifiedSlotTracker.php';
check('UnifiedSlotTracker.php is readable', is_file($trackerPath));
$src = is_file($trackerPath) ? file_get_contents($trackerPath) : '';

// Isolate validateAllSlots() so a match anywhere else in the file cannot satisfy this.
$start = strpos($src, 'function validateAllSlots');
$body  = $start === false ? '' : substr($src, $start, 4000);

check('validateAllSlots() found', $start !== false);
check('validateAllSlots() still builds $pcieCount from nic/pciecard/hbacard',
    strpos($body, "'pciecard', 'hbacard', 'nic'") !== false);
check('validateAllSlots() excludes the "onboard-" prefix from $pcieCount',
    strpos($body, "'onboard-'") !== false);
check('the exclusion is attached to the $pcieCount++ guard, not merely mentioned',
    preg_match('/in_array\(\s*\$comp\[.component_type.\][^)]*\)\s*\n?\s*&&\s*strpos\(\s*\(string\)\$comp\[.component_uuid.\],\s*.onboard-.\s*\)\s*!==\s*0/', $body) === 1);

// ------------------------------------------------------- sibling agreement --
// The bug was an inconsistency, not an isolated typo: two other layers were already
// right. If either stops excluding onboard NICs, the fleet diverges again.
echo "\n[3] the two layers that were already correct still are\n";

$laneSrc = @file_get_contents($ROOT . '/core/models/compatibility/PcieLaneBudgetValidator.php');
check('PcieLaneBudgetValidator still skips onboard NICs',
    is_string($laneSrc) && strpos($laneSrc, "'onboard-'") !== false);

$ruleSrc = @file_get_contents($ROOT . '/core/models/validation/rules/PcieSlotPlacementRule.php');
check('PcieSlotPlacementRule still skips onboard NICs',
    is_string($ruleSrc) && strpos($ruleSrc, "'onboard-'") !== false);

// ---------------------------------------------------------------------------
echo "\n";
if ($fails === 0) {
    echo "ALL CHECKS PASS\n";
    exit(0);
}
echo "$fails CHECK(S) FAILED\n";
exit(1);
