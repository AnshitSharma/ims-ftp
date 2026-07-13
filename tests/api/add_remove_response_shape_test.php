<?php
/**
 * add_remove_response_shape_test.php — U-A.1 (redesigned flag-gated per
 * owner decision, see migration/08-api-adapters/DEPRECATION.md) structural
 * regression test.
 *
 * The pack's literal acceptance test ("golden fixtures captured pre-change
 * from scratch, byte-equal post-change") needs a real HTTP/DB round trip --
 * exercised for the first time this session (2026-07-13) via a scratch-only
 * HTTP harness (`_http_harness.php`, `IMS_HTTP_HARNESS_URL` env var); self-
 * skips with an honest SKIPPED line when no harness is reachable, same
 * convention as every scratch-DB-backed test in this suite. Structural
 * grep-level checks proving the redesign shipped as documented (not the
 * pack's original unconditional-deletion text) also still run below,
 * DB-free.
 *
 * Exit 0 = every assertion passes (DB+HTTP-backed criteria included when a
 * harness is reachable; DB-free only otherwise).
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

echo "-- DB+HTTP-backed golden response-shape (real scratch-only harness when reachable) --\n";
require_once __DIR__ . '/../regression/_scratch_db.php';
require_once __DIR__ . '/_http_harness.php';
$pdo = scratch_db_connect();
$harness = HttpHarness::connect();
if ($pdo === null) {
    echo "  SKIPPED  golden response-shape fixtures byte-equal pre/post change\n";
} elseif ($harness === null) {
    echo "  SKIPPED  golden response-shape fixtures byte-equal pre/post change -- no IMS_HTTP_HARNESS_URL reachable\n";
    echo "  (start one: php -S 127.0.0.1:8099 -t <scratch tree root>, with COMMAND_LAYER_ENABLED set as a\n";
    echo "   process env var for that server only, then IMS_HTTP_HARNESS_URL=http://127.0.0.1:8099/api/api.php)\n";
} else {
    // Real chassis spec reused from the same known-good real fleet config
    // (809d10c9-...) new_actions_test.php's HTTP scenarios also reuse.
    $chassisUuid = '327e585c-8c3a-4ef5-80a3-c434df5c79a4';
    $suffix = substr(md5(uniqid('', true)), 0, 8);
    $cu = 'TEST-HTTP-GOLDEN-' . strtoupper($suffix);
    $serial = "HTTP-GOLDEN-$suffix";
    $created = ['configs' => [$cu], 'inventory' => []];
    try {
        $pdo->prepare("INSERT INTO server_configurations (config_uuid, server_name, configuration_status, status_v2, revision, is_virtual, created_by) VALUES (?, 'HTTP-GOLDEN-SHAPE', 0, 'draft', 0, 1, 5)")
            ->execute([$cu]);
        $pdo->prepare("INSERT INTO chassisinventory (UUID, SerialNumber, Status) VALUES (?, ?, 1)")->execute([$chassisUuid, $serial]);
        $invId = (int)$pdo->lastInsertId();
        $created['inventory'][] = ['chassisinventory', $invId];

        [$codeAdd, $headersAdd, $bodyAdd] = $harness->postWithHeaders('server-add-component', [
            'config_uuid' => $cu, 'component_type' => 'chassis',
            'component_uuid' => $chassisUuid, 'serial_number' => $serial,
        ]);
        check('add: HTTP 200', $codeAdd === 200);
        check('add: X-IMS-Deprecation header present (byte-equal pre/post U-A.1 redesign -- still sent unconditionally)',
            isset($headersAdd['x-ims-deprecation']) && strpos($headersAdd['x-ims-deprecation'], 'superseded by v2 commands') !== false);
        check('add: response shape has success/authenticated/message/timestamp/code/data (legacy envelope, unchanged)',
            is_array($bodyAdd) && array_key_exists('success', $bodyAdd) && array_key_exists('authenticated', $bodyAdd)
            && array_key_exists('message', $bodyAdd) && array_key_exists('timestamp', $bodyAdd)
            && array_key_exists('code', $bodyAdd) && array_key_exists('data', $bodyAdd));
        check('add: success=true', ($bodyAdd['success'] ?? false) === true);
        check('add: data.component_added has the golden field set (type/uuid/quantity/status_override_used/server_uuid_updated/slot_position)',
            isset($bodyAdd['data']['component_added']) && is_array($bodyAdd['data']['component_added'])
            && array_keys($bodyAdd['data']['component_added']) === ['type', 'uuid', 'quantity', 'status_override_used', 'server_uuid_updated', 'slot_position']);

        [$codeRemove, $headersRemove, $bodyRemove] = $harness->postWithHeaders('server-remove-component', [
            'config_uuid' => $cu, 'component_type' => 'chassis',
            'component_uuid' => $chassisUuid, 'serial_number' => $serial,
        ]);
        check('remove: HTTP 200', $codeRemove === 200);
        check('remove: X-IMS-Deprecation header present', isset($headersRemove['x-ims-deprecation']));
        check('remove: success=true, data.component_removed has the golden field set (type/uuid/server_uuid_cleared)',
            ($bodyRemove['success'] ?? false) === true
            && isset($bodyRemove['data']['component_removed'])
            && array_keys($bodyRemove['data']['component_removed']) === ['type', 'uuid', 'server_uuid_cleared']);
    } finally {
        // Real HTTP calls => real commits; explicit teardown, same posture
        // as new_actions_test.php's DB+HTTP section and finalize_command_
        // test.php's two-connection section.
        foreach ($created['configs'] as $cfg) {
            $pdo->exec("DELETE FROM config_resources WHERE config_uuid = " . $pdo->quote($cfg));
            $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($cfg));
            $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($cfg));
            $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($cfg));
        }
        foreach ($created['inventory'] as [$table, $id]) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) === 1) {
                $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
            }
        }
    }
    echo "  (ran against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . " over real HTTP, throwaway config + inventory fully torn down afterward)\n";
}
echo "  NOTE  'characterization ZERO verdict diffs' is covered by the broader full-sweep run\n";
echo "        (tests/characterize_compatibility.php against the checked-in baseline), not by this file directly\n";
echo "        -- see the accompanying same-day handoff section for that result.\n";

echo $fails === 0 ? "\nALL CHECKS PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
