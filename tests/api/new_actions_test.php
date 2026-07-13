<?php
/**
 * new_actions_test.php — U-A.2 regression test for the new additive actions
 * (server-replace-component, server-transition-status), serial+revision
 * params, and the quantity<1 rejection.
 *
 * FULL acceptance criteria per the execution pack (replace happy/blocked,
 * transition legal/illegal, 409 path, qty=0 -> 400, serial-targeted remove)
 * need a real MySQL scratch DB with a live HTTP-shaped call into api.php --
 * exercised for the first time this session (2026-07-13) via a scratch-only
 * HTTP harness (`_http_harness.php`, `IMS_HTTP_HARNESS_URL` env var); self-
 * skips with honest SKIPPED lines when no harness is reachable. What CAN be
 * verified without a DB (structural/contract-level: the actions are wired,
 * the flag gate exists, permission_map + seeder entries exist) also still
 * runs below, DB-free.
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

echo "-- DB+HTTP-backed acceptance criteria (real scratch-only harness when reachable) --\n";
require_once __DIR__ . '/../regression/_scratch_db.php';
require_once __DIR__ . '/_http_harness.php';
$pdo = scratch_db_connect();
$harness = HttpHarness::connect();
if ($pdo === null) {
    echo "  SKIPPED  replace happy path (storage A->B) + replace blocked (chassis bay-capacity)\n";
    echo "  SKIPPED  transition legal (draft->building->validating->validated) + illegal (empty config draft->building, blocked)\n";
    echo "  SKIPPED  409 path returns the REAL current revision after a concurrent mutation\n";
    echo "  SKIPPED  serial-targeted remove (R-3) removes the exact physical unit, not just any matching UUID\n";
} elseif ($harness === null) {
    echo "  SKIPPED  replace happy path (storage A->B) + replace blocked (chassis bay-capacity) -- no IMS_HTTP_HARNESS_URL reachable\n";
    echo "  SKIPPED  transition legal (draft->building->validating->validated) + illegal (empty config draft->building, blocked) -- no harness\n";
    echo "  SKIPPED  409 path returns the REAL current revision after a concurrent mutation -- no harness\n";
    echo "  SKIPPED  serial-targeted remove (R-3) removes the exact physical unit, not just any matching UUID -- no harness\n";
    echo "  (start one: php -S 127.0.0.1:8099 -t <scratch tree root>, with COMMAND_LAYER_ENABLED set as a\n";
    echo "   process env var for that server only, then IMS_HTTP_HARNESS_URL=http://127.0.0.1:8099/api/api.php)\n";
} else {
    // Real spec UUIDs reused from an ALREADY-VALID real fleet config
    // (809d10c9-cff2-4e49-88a5-83dccab09f8f) so the whole set is known
    // mutually compatible without hand-picking specs: chassis 327e585c
    // (Dell R740, 16x 2.5in bays), motherboard f4a5b6c7, cpu 3e31758b,
    // ram 9e1a4532, storage f54497fd + a82df310 (both 2.5in, co-resident
    // on that real config already). Chassis 4981e5a2 (12x 3.5in bays ONLY,
    // 0x 2.5in) is the SAME incompatible-bay pairing replace_command_test.php
    // already proves in-process -- reused here over real HTTP instead.
    $specs = [
        'chassisA' => '327e585c-8c3a-4ef5-80a3-c434df5c79a4',
        'chassisB' => '4981e5a2-74b5-46ed-ac9d-7f9bbfdbc6d5',
        'motherboard' => 'f4a5b6c7-d8e9-4f0a-b12c-3d4e5f6a7b8c',
        'cpu' => '3e31758b-04d4-495b-9b46-fc79102c16ff',
        'ram' => '9e1a4532-b693-4a59-b832-d8690bdb62fa',
        'storageA' => 'f54497fd-5cd3-4b5a-8cd2-276a68af11ac',
        'storageB' => 'a82df310-cb82-4f9e-bd48-2f1d171cf9a9',
    ];

    $suffix = substr(md5(uniqid('', true)), 0, 8);
    $created = ['configs' => [], 'inventory' => []]; // [table, id] pairs for teardown

    function h_insertInv(PDO $pdo, array &$created, string $table, string $uuid, string $serial): int {
        $pdo->prepare("INSERT INTO `$table` (UUID, SerialNumber, Status) VALUES (?, ?, 1)")->execute([$uuid, $serial]);
        $id = (int)$pdo->lastInsertId();
        $created['inventory'][] = [$table, $id];
        return $id;
    }
    function h_attachComponent(PDO $pdo, string $configUuid, string $type, string $table, int $invId, string $specUuid, string $serial): int {
        $pdo->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, serial_number, added_by) VALUES (?, ?, ?, ?, ?, ?, 5)")
            ->execute([$configUuid, $type, $table, $invId, $specUuid, $serial]);
        // Capture lastInsertId() BEFORE the UPDATE below -- some driver/server
        // combinations don't reliably preserve it across a subsequent statement.
        $newId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE `$table` SET Status = 2, ServerUUID = ? WHERE id = ?")->execute([$configUuid, $invId]);
        return $newId;
    }
    // Onboard NICs DO get a real nicinventory row in this system (confirmed:
    // production already carries synthetic rows shaped exactly like this --
    // UUID='onboard-{8-char board spec prefix}-{n}', SerialNumber=
    // 'ONBOARD-{same}', e.g. id 230/232/233 in the scratch DB -- config_
    // components.inventory_table/inventory_id are NOT NULL in schema, so
    // there is no NULL-inventory representation to use instead). Insert a
    // FRESH synthetic nicinventory row (UUID is non-unique in this table --
    // multiple physical units of the same onboard-NIC "spec" can coexist,
    // confirmed via SHOW INDEX) so this fixture doesn't steal the real
    // fleet's already-in-use row for the same board spec, then attach it
    // like any other component, parented to the motherboard's row so
    // TargetState::onboardParentBoardSpecUuid() (U-R.9) can resolve it.
    function h_attachOnboardNic(PDO $pdo, array &$created, string $configUuid, int $motherboardComponentId, string $motherboardUuid, string $suffix): void {
        $specUuid = 'onboard-' . substr($motherboardUuid, 0, 8) . '-1';
        $invId = h_insertInv($pdo, $created, 'nicinventory', $specUuid, "ONBOARD-HTTP-$suffix");
        $pdo->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, serial_number, parent_id, added_by) VALUES (?, 'nic', 'nicinventory', ?, ?, ?, ?, 5)")
            ->execute([$configUuid, $invId, $specUuid, "ONBOARD-HTTP-$suffix", $motherboardComponentId]);
        $pdo->prepare("UPDATE nicinventory SET Status = 2, ServerUUID = ? WHERE id = ?")->execute([$configUuid, $invId]);
    }
    function h_makeConfig(PDO $pdo, array &$created, string $name, string $status = 'draft'): string {
        $cu = 'TEST-HTTP-' . strtoupper(substr(md5(uniqid('', true)), 0, 10));
        $pdo->prepare("INSERT INTO server_configurations (config_uuid, server_name, configuration_status, status_v2, revision, is_virtual, created_by) VALUES (?, ?, 0, ?, 0, 1, 5)")
            ->execute([$cu, $name, $status]);
        $created['configs'][] = $cu;
        return $cu;
    }

    try {
        // ---- CFG1: fully-equipped config for replace happy/blocked + transition legal walk ----
        $cfg1 = h_makeConfig($pdo, $created, "HTTP-HARNESS-CFG1-$suffix");
        $invChA = h_insertInv($pdo, $created, 'chassisinventory', $specs['chassisA'], "HTTP-$suffix-CH-A");
        $invMb = h_insertInv($pdo, $created, 'motherboardinventory', $specs['motherboard'], "HTTP-$suffix-MB");
        $invCpu = h_insertInv($pdo, $created, 'cpuinventory', $specs['cpu'], "HTTP-$suffix-CPU");
        $invRam = h_insertInv($pdo, $created, 'raminventory', $specs['ram'], "HTTP-$suffix-RAM");
        $invStA = h_insertInv($pdo, $created, 'storageinventory', $specs['storageA'], "HTTP-$suffix-ST-A");
        h_attachComponent($pdo, $cfg1, 'chassis', 'chassisinventory', $invChA, $specs['chassisA'], "HTTP-$suffix-CH-A");
        $mbComponentId = h_attachComponent($pdo, $cfg1, 'motherboard', 'motherboardinventory', $invMb, $specs['motherboard'], "HTTP-$suffix-MB");
        h_attachComponent($pdo, $cfg1, 'cpu', 'cpuinventory', $invCpu, $specs['cpu'], "HTTP-$suffix-CPU");
        h_attachComponent($pdo, $cfg1, 'ram', 'raminventory', $invRam, $specs['ram'], "HTTP-$suffix-RAM");
        h_attachComponent($pdo, $cfg1, 'storage', 'storageinventory', $invStA, $specs['storageA'], "HTTP-$suffix-ST-A");
        h_attachOnboardNic($pdo, $created, $cfg1, $mbComponentId, $specs['motherboard'], $suffix);

        // -- Replace happy: storage A -> storage B (both 2.5in, real co-resident specs) --
        $invStB = h_insertInv($pdo, $created, 'storageinventory', $specs['storageB'], "HTTP-$suffix-ST-B");
        [$code, $body] = $harness->post('server-replace-component', [
            'config_uuid' => $cfg1, 'component_type' => 'storage',
            'old_component_uuid' => $specs['storageA'], 'old_serial' => "HTTP-$suffix-ST-A",
            'new_component_uuid' => $specs['storageB'],
        ]);
        check('replace happy path (storage A->B): HTTP 200, success=true', $code === 200 && ($body['success'] ?? false) === true);

        // -- Replace blocked: chassis A -> chassis B (2.5in bays -> 0x 2.5in bays, storage B still occupying) --
        [$code, $body] = $harness->post('server-replace-component', [
            'config_uuid' => $cfg1, 'component_type' => 'chassis',
            'old_component_uuid' => $specs['chassisA'], 'old_serial' => "HTTP-$suffix-CH-A",
            'new_component_uuid' => $specs['chassisB'],
        ]);
        check('replace blocked (chassis bay-capacity, real incompatible pair): HTTP failure, success=false', ($body['success'] ?? true) === false);
        check('replace blocked: NOT a 2xx (a real validation/command rejection, not silently accepted)', $code >= 400);

        // -- Transition legal walk: draft->building->validating->validated (fully-equipped config, all real edges) --
        [$code1, $body1] = $harness->post('server-transition-status', ['config_uuid' => $cfg1, 'to_status' => 'building']);
        [$code2, $body2] = $harness->post('server-transition-status', ['config_uuid' => $cfg1, 'to_status' => 'validating']);
        [$code3, $body3] = $harness->post('server-transition-status', ['config_uuid' => $cfg1, 'to_status' => 'validated']);
        check('transition legal: draft->building succeeds (fully-equipped config passes system.required_set)', $code1 === 200 && ($body1['success'] ?? false) === true);
        check('transition legal: building->validating succeeds', $code2 === 200 && ($body2['success'] ?? false) === true);
        check('transition legal: validating->validated succeeds (SYSTEM permission edge)', $code3 === 200 && ($body3['success'] ?? false) === true);
        if ($code1 !== 200) { echo "        (draft->building: HTTP $code1, " . ($body1['message'] ?? '?') . ")\n"; }
        if ($code2 !== 200) { echo "        (building->validating: HTTP $code2, " . ($body2['message'] ?? '?') . ")\n"; }
        if ($code3 !== 200) { echo "        (validating->validated: HTTP $code3, " . ($body3['message'] ?? '?') . ")\n"; }

        // ---- CFG2: empty config -- transition illegal (blocked by validation, not by a missing edge) ----
        $cfg2 = h_makeConfig($pdo, $created, "HTTP-HARNESS-CFG2-EMPTY-$suffix");
        [$code, $body] = $harness->post('server-transition-status', ['config_uuid' => $cfg2, 'to_status' => 'building']);
        check('transition illegal: empty config draft->building is blocked (422, system.required_set)', $code === 422 && ($body['success'] ?? true) === false);

        // ---- CFG3: 409 real-revision-after-concurrent-mutation over real HTTP ----
        $cfg3 = h_makeConfig($pdo, $created, "HTTP-HARNESS-CFG3-409-$suffix");
        $invCh3 = h_insertInv($pdo, $created, 'chassisinventory', $specs['chassisA'], "HTTP-$suffix-409-CH");
        [$code, $body] = $harness->post('server-add-component', [
            'config_uuid' => $cfg3, 'component_type' => 'chassis',
            'component_uuid' => $specs['chassisA'], 'serial_number' => "HTTP-$suffix-409-CH",
            'expected_revision' => 0,
        ]);
        check('409 setup: first mutation with correct expected_revision=0 succeeds', $code === 200 && ($body['success'] ?? false) === true);
        // Second call reuses the SAME now-stale expected_revision=0.
        [$code, $body] = $harness->post('server-remove-component', [
            'config_uuid' => $cfg3, 'component_type' => 'chassis',
            'component_uuid' => $specs['chassisA'], 'serial_number' => "HTTP-$suffix-409-CH",
            'expected_revision' => 0,
        ]);
        check('409: stale expected_revision after a real committed mutation returns HTTP 409', $code === 409);
        check('409: response carries the REAL current_revision (not the stale one)', ($body['data']['current_revision'] ?? null) === 1);

        // ---- CFG4: serial-targeted remove (R-3) -- 2 units, SAME spec UUID, different serials ----
        $cfg4 = h_makeConfig($pdo, $created, "HTTP-HARNESS-CFG4-SERIAL-$suffix");
        $invS1 = h_insertInv($pdo, $created, 'chassisinventory', $specs['chassisA'], "HTTP-$suffix-SER-1");
        $invS2 = h_insertInv($pdo, $created, 'chassisinventory', $specs['chassisA'], "HTTP-$suffix-SER-2");
        h_attachComponent($pdo, $cfg4, 'chassis', 'chassisinventory', $invS1, $specs['chassisA'], "HTTP-$suffix-SER-1");
        h_attachComponent($pdo, $cfg4, 'chassis', 'chassisinventory', $invS2, $specs['chassisA'], "HTTP-$suffix-SER-2");
        [$code, $body] = $harness->post('server-remove-component', [
            'config_uuid' => $cfg4, 'component_type' => 'chassis',
            'component_uuid' => $specs['chassisA'], 'serial_number' => "HTTP-$suffix-SER-1",
        ]);
        check('serial-targeted remove: HTTP 200, success=true', $code === 200 && ($body['success'] ?? false) === true);
        $remaining = $pdo->query("SELECT serial_number FROM config_components WHERE config_uuid = " . $pdo->quote($cfg4) . " AND removed_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        check('serial-targeted remove: the OTHER serial (-SER-2) is still attached, untouched', in_array("HTTP-$suffix-SER-2", $remaining, true));
        check('serial-targeted remove: the targeted serial (-SER-1) is gone from the live set', !in_array("HTTP-$suffix-SER-1", $remaining, true));
    } finally {
        // Real HTTP calls => real commits; explicit teardown (not a rollback),
        // same posture as finalize_command_test.php's two-connection section.
        foreach ($created['configs'] as $cu) {
            $pdo->exec("DELETE FROM config_resources WHERE config_uuid = " . $pdo->quote($cu));
            $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($cu));
            // Children (e.g. the onboard-nic row, parent_id -> the motherboard
            // row) first -- config_components has a self-referencing FK, and a
            // single DELETE over the whole config_uuid set doesn't guarantee
            // child-before-parent ordering.
            $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($cu) . " AND parent_id IS NOT NULL");
            $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($cu));
            $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($cu));
        }
        foreach ($created['inventory'] as [$table, $id]) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) === 1) {
                $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
            }
        }
    }
    echo "  (all scenarios ran against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . " over real HTTP, throwaway configs + inventory fully torn down afterward)\n";
}

echo $fails === 0 ? "\nALL CHECKS PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
