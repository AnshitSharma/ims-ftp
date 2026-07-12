<?php
/**
 * new_actions_test.php — U-A.2 regression test for the new additive actions
 * (server-replace-component, server-transition-status), serial+revision
 * params, and the quantity<1 rejection.
 *
 * FULL acceptance criteria per the execution pack (replace happy/blocked,
 * transition legal/illegal, 409 path, qty=0 -> 400, serial-targeted remove)
 * need a real MySQL scratch DB with a live HTTP-shaped call into api.php.
 * This session has no reachable local MySQL (see the P6 handoff) -- those
 * are marked SKIPPED, not silently omitted. What CAN be verified without a
 * DB (structural/contract-level: the actions are wired, the flag gate
 * exists, permission_map + seeder entries exist) runs below.
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

echo "-- action dispatch wiring (no DB needed) --\n";
check("switch has 'server-replace-component' -> handleReplaceComponent", strpos($src, "case 'server-replace-component':") !== false);
check("switch has 'server-transition-status' -> handleTransitionStatus", strpos($src, "case 'server-transition-status':") !== false);
check('handleReplaceComponent function exists', strpos($src, 'function handleReplaceComponent(') !== false);
check('handleTransitionStatus function exists', strpos($src, 'function handleTransitionStatus(') !== false);

echo "-- flag-gated deviation (both new actions require COMMAND_LAYER_ENABLED != off) --\n";
check("handleReplaceComponent rejects when CommandLayer::mode() === 'off'",
    preg_match("/function handleReplaceComponent[\\s\\S]{0,400}CommandLayer::mode\\(\\) === 'off'/", $src) === 1);
check("handleTransitionStatus rejects when CommandLayer::mode() === 'off'",
    preg_match("/function handleTransitionStatus[\\s\\S]{0,400}CommandLayer::mode\\(\\) === 'off'/", $src) === 1);

echo "-- expected_revision -> 409 with current_revision (add/remove/replace/transition) --\n";
check('handleAddComponent maps revision_mismatch to 409 with current_revision',
    preg_match("/errorType === 'revision_mismatch'[\\s\\S]{0,300}409[\\s\\S]{0,200}current_revision/", $src) === 1);
check('all four mutation handlers read $_POST[\'expected_revision\']', substr_count($src, "\$_POST['expected_revision']") >= 4);

echo "-- quantity handling --\n";
check('quantity < 1 rejected with 400', strpos($src, 'quantity must be at least 1') !== false);
check('quantity > 1 maps to N sequential command dispatches in one outer tx (enforce mode)', strpos($src, 'quantity > 1') !== false && strpos($src, 'ownTx') !== false);

echo "-- permission_map.php / seeder --\n";
$permMap = require "$ROOT/api/permission_map.php";
check("permission_map['server']['replace-component'] === 'server.replace'", ($permMap['server']['replace-component'] ?? null) === 'server.replace');
check("permission_map['server']['transition-status'] === 'server.transition'", ($permMap['server']['transition-status'] ?? null) === 'server.transition');
check('a seeder for server.replace/server.transition exists (not run -- shown to owner per project rules)',
    count(glob("$ROOT/database/seeders/*add-server-replace-transition-permissions.sql")) === 1);

echo "-- DB-backed acceptance criteria (NOT run this session -- no reachable local MySQL) --\n";
echo "  SKIPPED  replace happy path (ram A->B) + replace blocked (incompatible board)\n";
echo "  SKIPPED  transition legal (validated->finalized) + illegal (draft->finalized, Finding 2 territory)\n";
echo "  SKIPPED  409 path returns the REAL current revision after a concurrent mutation\n";
echo "  SKIPPED  serial-targeted remove (R-3) removes the exact physical unit, not just any matching UUID\n";

echo $fails === 0 ? "\nALL DB-FREE CHECKS PASS (DB-backed criteria not run -- see above)\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
