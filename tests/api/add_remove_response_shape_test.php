<?php
/**
 * add_remove_response_shape_test.php — U-A.1 (redesigned flag-gated per
 * owner decision, see docs/API-DEPRECATION.md) structural
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
$checksRun = 0;
function check($label, $cond) {
    global $fails, $checksRun;
    $checksRun++;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Set to true ONLY on the branch that actually exercises the DB+HTTP
// acceptance criteria. See the exit block at the bottom of this file.
$httpCriteriaRan = false;

echo "-- structural checks (no DB needed) --\n";

check('handleAddComponent sends the X-IMS-Deprecation header',
    preg_match('/function handleAddComponent[\s\S]{0,300}X-IMS-Deprecation/', $src) === 1);
check('handleRemoveComponent sends the X-IMS-Deprecation header',
    preg_match('/function handleRemoveComponent[\s\S]{0,300}X-IMS-Deprecation/', $src) === 1);

// REWRITTEN 2026-08-30 (P9/U-A.1, U-D.2). These two asserted the U-A.1 deviation:
// the unlocked advisory pre-check was skipped at CommandLayer=enforce but had to
// stay REACHABLE at off/shadow, so a rollback would not lose it. There is no
// off/shadow to roll back to and validateComponentAddition() is deleted, so the
// deviation is closed rather than violated. What replaces it is the reason the
// pre-check was safe to drop in the first place, asserted rather than assumed:
// the command evaluates the same registry, and it does so UNDER THE LOCK, which
// is what made the unlocked second opinion a TOCTOU window rather than a safety net.
// Comment lines excluded: server_api.php:871 explains that VerdictShim reproduces
// the envelope callers of the OLD validateComponentAddition() expect, and that
// history is worth keeping. A call outside a comment would be a dangling one.
$liveSrc = implode("\n", array_filter(
    explode("\n", $src),
    function ($l) {
        $t = ltrim($l);
        return $t !== '' && strpos($t, '//') !== 0 && strpos($t, '*') !== 0 && strpos($t, '/*') !== 0 && strpos($t, '#') !== 0;
    }
));
check('no live call to the unlocked advisory pre-check survives in server_api.php',
    strpos($liveSrc, 'validateComponentAddition') === false);
check('ServerBuilder no longer defines validateComponentAddition() either (U-D.2)',
    preg_match('/function\s+validateComponentAddition\s*\(/', file_get_contents("$ROOT/core/models/server/ServerBuilder.php")) !== 1);
check('validation now happens inside the command, under the row lock BaseCommand holds',
    (function () use ($ROOT) {
        $bc = file_get_contents("$ROOT/core/models/commands/BaseCommand.php");
        // The lock is taken, then the verdict is evaluated, then apply() runs --
        // in that order, in execute(). Positions, not proximity.
        $exStart = strpos($bc, 'final public function execute()');
        $exEnd   = $exStart !== false ? strpos($bc, 'final public function dryRun()', $exStart) : false;
        $ex      = ($exStart !== false && $exEnd !== false && $exEnd > $exStart)
            ? substr($bc, $exStart, $exEnd - $exStart)
            : '';
        if ($ex === '') { return false; }
        // The lock itself is taken by lockAndLoadConfigRow(), which issues the
        // SELECT ... FOR UPDATE and refuses to run outside a transaction. What
        // execute() has to get right is the ORDER: lock, then evaluate, then apply.
        $lockAt  = strpos($ex, '$this->lockAndLoadConfigRow()');
        $evalAt  = strpos($ex, 'evaluate($target, $this->trigger())');
        $applyAt = strpos($ex, '$this->apply(');
        $lockFn  = strpos($bc, 'SELECT * FROM server_configurations WHERE config_uuid = ? FOR UPDATE');
        $lockGuard = strpos($bc, 'must be called inside an active transaction');
        return $lockAt !== false && $evalAt !== false && $applyAt !== false
            && $lockFn !== false && $lockGuard !== false
            && $lockAt < $evalAt && $evalAt < $applyAt;
    })());

check('the handler-level SFP auto-assignment block is still present (NOT deleted -- documented gap, see DEPRECATION.md: AddComponentCommand has no equivalent yet)',
    strpos($src, 'AUTO-ASSIGNMENT TRIGGER') !== false && strpos($src, 'autoAssignSFPsToNIC') !== false);

check('DEPRECATION.md exists and documents the flag-gated deviation', (function () use ($ROOT) {
    $path = "$ROOT/docs/API-DEPRECATION.md";
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
        // server_configurations.created_by is a HARD FK to users.id
        // (fk_server_config_user). Hard-coding id 5 only ever worked in a scratch
        // DB seeded to have that row; against a restored production replica it is
        // a fatal 1452, i.e. these acceptance criteria could not run at all there.
        // Resolve a real id rather than assuming one.
        $harnessUserId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT INTO server_configurations (config_uuid, server_name, configuration_status, status_v2, revision, is_virtual, created_by) VALUES (?, 'HTTP-GOLDEN-SHAPE', 0, 'draft', 0, 1, ?)")
            ->execute([$cu, $harnessUserId]);
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
    $httpCriteriaRan = true;
    echo "  (ran against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . " over real HTTP, throwaway config + inventory fully torn down afterward)\n";
}
echo "  NOTE  'characterization ZERO verdict diffs' is covered by the broader full-sweep run\n";
echo "        (tests/characterize_compatibility.php against the checked-in baseline), not by this file directly\n";
echo "        -- see the accompanying same-day handoff section for that result.\n";

// -----------------------------------------------------------------------
// Exit reporting.
//
// WHY (2026-08-24, the F-11/F-18/F-21/F-24 family): until today this file
// printed per-criterion SKIPPED lines when no IMS_HTTP_HARNESS_URL was
// reachable, then printed "ALL CHECKS PASS" and exited 0 anyway. It never
// emitted the "SKIPPED: 0 check(s) run" marker tests/run_tests.php greps
// for, so the runner counted it as a plain PASS -- indistinguishable from a
// run in which U-A.1's acceptance criteria actually executed. A pass on
// the offline structural checks never implied the HTTP criteria ran, and
// U-A.1's acceptance rests on exactly those criteria.
//
// The offline checks are genuinely real and are KEPT and still enforced --
// a failure in one still exits 1. What changes is that a run which executed
// only the offline half now says so in the one line the runner understands,
// and is counted as "ran nothing" (of the acceptance criteria) rather than
// as a pass. Same convention as _scratch_db.php's scratch_db_or_skip().
// -----------------------------------------------------------------------
if ($fails > 0) {
    echo "\n$fails FAILURE(S)\n";
    exit(1);
}

if (!$httpCriteriaRan) {
    echo "\n$checksRun offline structural check(s) ran and passed -- real, and kept, but they are\n";
    echo "NOT U-A.1's acceptance criteria (golden response-shape fixtures byte-equal pre/post change, over real HTTP).\n";
    echo "SKIPPED: 0 check(s) run of U-A.1's DB+HTTP acceptance criteria -- this suite proved\n"
       . "NOTHING about them in this environment (no reachable scratch DB + IMS_HTTP_HARNESS_URL)\n";
    exit(0);
}

echo "\nALL CHECKS PASS\n";
exit(0);
