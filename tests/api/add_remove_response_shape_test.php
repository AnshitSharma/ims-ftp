<?php
/**
 * add_remove_response_shape_test.php — U-A.1 (redesigned flag-gated per
 * owner decision, see migration/08-api-adapters/DEPRECATION.md) structural
 * regression test.
 *
 * The pack's literal acceptance test ("golden fixtures captured pre-change
 * from scratch, byte-equal post-change") needs a real HTTP/DB round trip.
 * This session has no reachable local MySQL (see the P6 handoff) -- that
 * criterion is not run here. What CAN be verified without a DB (structural
 * grep-level checks proving the redesign shipped as documented, not the
 * pack's original unconditional-deletion text) runs below.
 *
 * Exit 0 = every DB-free assertion passes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
$src = file_get_contents("$ROOT/api/handlers/server/server_api.php");

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

echo "-- structural checks (no DB needed) --\n";

check('handleAddComponent sends the X-IMS-Deprecation header',
    preg_match('/function handleAddComponent[\s\S]{0,300}X-IMS-Deprecation/', $src) === 1);
check('handleRemoveComponent sends the X-IMS-Deprecation header',
    preg_match('/function handleRemoveComponent[\s\S]{0,300}X-IMS-Deprecation/', $src) === 1);

check('the advisory pre-check block is skipped ONLY at CommandLayer::mode() === enforce (not deleted unconditionally, per the U-A.1 deviation)',
    strpos($src, "if (CommandLayer::mode() !== 'enforce') {") !== false);
check('validateComponentAddition (the advisory pre-check) is still reachable at off/shadow',
    preg_match("/CommandLayer::mode\\(\\) !== 'enforce'\\) \\{[\\s\\S]{0,1500}validateComponentAddition/", $src) === 1);

check('the handler-level SFP auto-assignment block is still present (NOT deleted -- documented gap, see DEPRECATION.md: AddComponentCommand has no equivalent yet)',
    strpos($src, 'AUTO-ASSIGNMENT TRIGGER') !== false && strpos($src, 'autoAssignSFPsToNIC') !== false);

check('DEPRECATION.md exists and documents the flag-gated deviation', (function () use ($ROOT) {
    $path = "$ROOT/migration/08-api-adapters/DEPRECATION.md";
    return is_file($path) && strpos(file_get_contents($path), 'flag-gated') !== false;
})());

echo "-- DB-backed acceptance criteria (NOT run this session -- no reachable local MySQL) --\n";
echo "  SKIPPED  golden response-shape fixtures byte-equal pre/post change\n";
echo "  SKIPPED  characterization ZERO verdict diffs\n";

echo $fails === 0 ? "\nALL DB-FREE CHECKS PASS (DB-backed criteria not run -- see above)\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
