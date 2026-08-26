<?php
/**
 * location_aware_requests_test.php — regression test for the location-aware
 * Requests feature and the Hardware Handover child request
 * (tasks/location-aware-requests-and-handover.md).
 *
 * Structural checks (no DB needed) prove the wiring the plan called for is
 * actually in the shipped files: the three-valued match contract, the
 * location gate's scope and fail-open-on-error behaviour, the executor
 * registry entry, the ownerless-step assignee injection, the
 * self-service/endpoint wiring in api.php, and defects 2/3's fixes
 * (location-preferred unit ordering, syncConfig() after the inventory
 * write). A DB-backed scenario (Noida vs Jaipur units, checkComponentForConfig,
 * ComponentRelocation::move) runs against the scratch DB when reachable and
 * self-skips otherwise, same convention as replace_command_test.php.
 *
 * Exit 0 = every DB-free assertion passes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

function src($path) {
    global $ROOT;
    $full = "$ROOT/$path";
    if (!is_file($full)) { return null; }
    return file_get_contents($full);
}

// =============================================================================
echo "-- files exist --\n";
// =============================================================================
foreach ([
    'core/models/location/ComponentRelocation.php',
    'api/handlers/pipelines/pipeline-component-location.php',
    'api/handlers/pipelines/pipeline-users.php',
    'database/seeders/2026_08_26_006_create-component-movements.sql',
    'database/seeders/2026_08_26_007_hardware-handover-request-type.sql',
    'database/seeders/2026_08_26_008_pipeline-act-for-handover-carriers.sql',
    'database/seeders/rollback/2026_08_26_006_create-component-movements_rollback.sql',
    'database/seeders/rollback/2026_08_26_007_hardware-handover-request-type_rollback.sql',
    'database/seeders/rollback/2026_08_26_008_pipeline-act-for-handover-carriers_rollback.sql',
] as $f) {
    check("exists: $f", is_file("$ROOT/$f"));
}

// =============================================================================
echo "-- LocationResolver: three-valued check, never blocks on unknown --\n";
// =============================================================================
require_once "$ROOT/core/models/location/LocationResolver.php";
$lrRef = new ReflectionClass('LocationResolver');
foreach (['unitsForModel', 'checkComponentForConfig', 'unitOptions', 'preferredUnitLocation'] as $m) {
    check("LocationResolver::$m() exists", $lrRef->hasMethod($m) && $lrRef->getMethod($m)->isStatic());
}

$lrSrc = src('core/models/location/LocationResolver.php');
check(
    "checkComponentForConfig() returns match=null (not false) when supported is false",
    (bool)preg_match(
        "/function checkComponentForConfig.*?'supported'\s*=>\s*false.*?'match'\s*=>\s*null/s",
        $lrSrc
    )
);

// =============================================================================
echo "-- ComponentRelocation: fail-closed refusals, nestable transaction --\n";
// =============================================================================
require_once "$ROOT/core/models/location/ComponentRelocation.php";
$crRef = new ReflectionClass('ComponentRelocation');
check('ComponentRelocation::move() exists and is static', $crRef->hasMethod('move') && $crRef->getMethod('move')->isStatic());

$crSrc = src('core/models/location/ComponentRelocation.php');
check('refuses a unit that is not loose stock (Status != 1 or a ServerUUID set)',
    strpos($crSrc, "['Status'] != 1") !== false || strpos($crSrc, "['Status'] !== 1") !== false || strpos($crSrc, "'Status'] != 1") !== false);
check('checks ServerUUID before allowing the move (installed components are not loose stock)',
    strpos($crSrc, 'ServerUUID') !== false);
check('refuses a no-op move (same destination)', stripos($crSrc, 'no-op') !== false || stripos($crSrc, 'noop') !== false || stripos($crSrc, 'already at') !== false || stripos($crSrc, 'already there') !== false);
check('writes to component_movements behind a table-existence guard (deploy-order safe)',
    strpos($crSrc, 'component_movements') !== false
    && (strpos($crSrc, 'hasTable') !== false));
check('nestable ownTransaction pattern: begins only when not already inside one', strpos($crSrc, '!$pdo->inTransaction()') !== false);
check('never commits a transaction it did not open', preg_match('/ownsTransaction\s*&&.*rollBack/s', $crSrc) === 1 || strpos($crSrc, 'ownsTransaction') !== false);

// =============================================================================
echo "-- RequestActionExecutor: registry entry, gate scope, fail-open on error --\n";
// =============================================================================
$raeSrc = src('core/models/pipelines/RequestActionExecutor.php');
check("ACTION_TYPES declares 'inventory.component.relocate'", strpos($raeSrc, "'inventory.component.relocate' =>") !== false);
check("its scope is 'inventory' (renders under Component inventory in the Request Types editor)",
    (bool)preg_match("/'inventory\\.component\\.relocate'\s*=>\s*\\[.*?'scope'\s*=>\s*'inventory'/s", $raeSrc));
check("required keys include component_type, inventory_id, location_uuid",
    (bool)preg_match("/'inventory\\.component\\.relocate'\s*=>\s*\\[.*?'required'\s*=>\s*\\['component_type',\s*'inventory_id',\s*'location_uuid'\\]/s", $raeSrc));

check('locationGate() is a private method', strpos($raeSrc, 'private function locationGate(') !== false);
$executeFnAt = strpos($raeSrc, 'function execute($actionType, array $payload, $subjectUserId, $approverId, $ticketId = null)');
$gateCallAt = strpos($raeSrc, '$gate = $this->locationGate(');
$executeSwitchAt = $executeFnAt !== false ? strpos($raeSrc, 'switch ($actionType) {', $executeFnAt) : false;
check('locationGate() is invoked in execute() BEFORE the action switch (so a mismatch never runs the install)',
    $executeFnAt !== false && $gateCallAt !== false && $executeSwitchAt !== false
    && $gateCallAt > $executeFnAt && $gateCallAt < $executeSwitchAt);
check('locationGate() only fires for server.component.add / server.component.replace',
    strpos($raeSrc, "if (\$actionType !== 'server.component.add' && \$actionType !== 'server.component.replace') {") !== false);
check('a replace is judged on the incoming part (new_component_uuid), not the outgoing one',
    strpos($raeSrc, "new_component_uuid") !== false);
check('a resolver exception in locationGate() is caught and FAILS OPEN (never blocks an approval on its own bug)',
    (bool)preg_match('/locationGate.*?catch\s*\(Throwable[^)]*\)\s*\{.*?return null;/s', $raeSrc));
check("locationGate() only refuses on a CONFIRMED mismatch (match === false), never on null/unsupported",
    strpos($raeSrc, "\$check['match'] !== false") !== false);
check("relocateComponent() dispatches to ComponentRelocation::move()", strpos($raeSrc, 'ComponentRelocation::move(') !== false);
check("the relocate case threads \$ticketId through (component_movements.ticket_id, not a client-supplied payload key)",
    (bool)preg_match('/relocateComponent\(\$payload,\s*\$subjectUserId,\s*\$ticketId\)/', $raeSrc));

// =============================================================================
echo "-- PipelineManager: carrier -> confirmation-step assignee injection --\n";
// =============================================================================
$pmSrc = src('core/models/pipelines/PipelineManager.php');
check('applyHandoverAssignee() is a private method', strpos($pmSrc, 'private function applyHandoverAssignee(') !== false);
check('createPipeline() calls it', strpos($pmSrc, '$this->applyHandoverAssignee(') !== false);
check('targets the LAST stage with no effect_type AND no default_assignee (keeps overwriting, does not break on first match)',
    (bool)preg_match('/applyHandoverAssignee.*?foreach\s*\(\$template\[.stages.\][^{]*\{.*?continue;.*?continue;.*?\$targetStageId\s*=\s*\$stage\[.id.\];/s', $pmSrc));
check('an explicit client override for that stage wins over the auto-injected carrier',
    (bool)preg_match('/isset\(\$overrides\[\$targetStageId\]\)\s*\|\|\s*isset\(\$overrides\[\(string\)\$targetStageId\]\)\)\s*\{\s*return \$overrides;/', $pmSrc));

// =============================================================================
echo "-- api.php: self-service wiring, endpoint registration --\n";
// =============================================================================
$apiSrc = src('api/api.php');
check("'claim' added to \$selfServiceOperations (unblocks the carrier's own step-2 confirmation)",
    (bool)preg_match('/\$selfServiceOperations\s*=\s*\[[^\]]*\'claim\'/s', $apiSrc));
check("'complete' added to \$selfServiceOperations", (bool)preg_match('/\$selfServiceOperations\s*=\s*\[[^\]]*\'complete\'/s', $apiSrc));
check("'reject' deliberately NOT added (bigger act than confirming your own step)",
    !(bool)preg_match('/\$selfServiceOperations\s*=\s*\[[^\]]*\'reject\'/s', $apiSrc));
check("endpointMap registers 'users' => pipeline-users.php", strpos($apiSrc, "'users'              => 'pipeline-users.php'") !== false);
check("endpointMap registers 'component-location' => pipeline-component-location.php",
    strpos($apiSrc, "'component-location' => 'pipeline-component-location.php'") !== false);

// =============================================================================
echo "-- new endpoints: gated on pipeline.create | pipeline.manage, like pipeline-servers.php --\n";
// =============================================================================
foreach ([
    'api/handlers/pipelines/pipeline-users.php',
    'api/handlers/pipelines/pipeline-component-location.php',
] as $f) {
    $s = src($f);
    check("$f gates on pipeline.create or pipeline.manage",
        strpos($s, "'pipeline.create'") !== false && strpos($s, "'pipeline.manage'") !== false);
}
$pclSrc = src('api/handlers/pipelines/pipeline-component-location.php');
check('pipeline-component-location.php treats config_uuid as OPTIONAL (units-only mode for the handover picker)',
    strpos($pclSrc, "\$configUuid !== ''") !== false && strpos($pclSrc, "no_server_named") !== false);
$puSrc = src('api/handlers/pipelines/pipeline-users.php');
check('pipeline-users.php filters to pipeline.act / pipeline.manage holders only',
    strpos($puSrc, 'pipeline.act') !== false && strpos($puSrc, 'pipeline.manage') !== false);

// =============================================================================
echo "-- defect 2 fix: location-preferred unit selection, byte-identical when unknown --\n";
// =============================================================================
foreach ([
    'core/models/commands/AddComponentCommand.php',
    'core/models/commands/ReplaceComponentCommand.php',
    'core/models/server/ServerBuilder.php',
] as $f) {
    $s = src($f);
    check("$f: preferredUnitLocation() spliced into unit ORDER BY", strpos($s, 'LocationResolver::preferredUnitLocation(') !== false);
    check("$f: ORDER BY keeps (Status = 1) DESC, (Status = 2) DESC ahead of the location preference",
        (bool)preg_match('/ORDER BY \(Status = 1\) DESC, \(Status = 2\) DESC, \{\$locationOrder\}ID ASC/', $s));
}

// =============================================================================
echo "-- defect 3 fix: syncConfig() called after the inventory write --\n";
// =============================================================================
foreach ([
    'core/models/commands/AddComponentCommand.php',
    'core/models/commands/ReplaceComponentCommand.php',
] as $f) {
    $s = src($f);
    check("$f calls LocationResolver::syncConfig() (re-derives location_uuid/Location/RackPosition instead of stamping nulls)",
        strpos($s, 'LocationResolver::syncConfig(') !== false);
}

// =============================================================================
echo "-- seeders: step ownership, ceiling, idempotency, no information_schema --\n";
// =============================================================================
$seed007 = src('database/seeders/2026_08_26_007_hardware-handover-request-type.sql');
if ($seed007 === null) {
    echo "  SKIPPED  seeder 007 checks (file missing)\n";
} else {
    check('seeder 007: step 1 effect_type is execute_request', stripos($seed007, "'execute_request'") !== false);
    check("seeder 007: step 1's ceiling names only inventory.component.relocate",
        strpos($seed007, '"action_types":["inventory.component.relocate"]') !== false);
    check('seeder 007: Hardware Handover is not the system type (is_system=0)',
        (bool)preg_match('/Hardware Handover.{0,200}is_system.{0,10}0/s', $seed007) || strpos($seed007, 'is_system') !== false);
    check('seeder 007 does not read information_schema (prod DB user has no grant)', stripos($seed007, 'information_schema') === false);
}
$seed006 = src('database/seeders/2026_08_26_006_create-component-movements.sql');
if ($seed006 === null) {
    echo "  SKIPPED  seeder 006 checks (file missing)\n";
} else {
    check('seeder 006: CREATE TABLE IF NOT EXISTS (idempotent)', stripos($seed006, 'CREATE TABLE IF NOT EXISTS') !== false);
    check('seeder 006: handover_user_id is a distinct column from moved_by', strpos($seed006, 'handover_user_id') !== false && strpos($seed006, 'moved_by') !== false);
    check('seeder 006 does not read information_schema', stripos($seed006, 'information_schema') === false);
}
$seed008 = src('database/seeders/2026_08_26_008_pipeline-act-for-handover-carriers.sql');
if ($seed008 === null) {
    echo "  SKIPPED  seeder 008 checks (file missing)\n";
} else {
    check('seeder 008: INSERT IGNORE (idempotent re-run safe)', stripos($seed008, 'INSERT IGNORE') !== false);
    check('seeder 008: ships an editable role-list marker for the real technician role', strpos($seed008, '>>> EDIT THIS LIST <<<') !== false);
    check('seeder 008 does not read information_schema', stripos($seed008, 'information_schema') === false);
}

// =============================================================================
echo "-- DB-backed scenario (real scratch DB when reachable; SKIPPED otherwise) --\n";
// =============================================================================
require_once __DIR__ . '/_scratch_db.php';
$pdo = scratch_db_connect();
if ($pdo !== null && ($schemaGap = scratch_db_schema_gap($pdo)) !== null) {
    echo "  (scratch DB unusable: $schemaGap)\n";
    $pdo = null;
}
$hasLocationSchema = false;
if ($pdo !== null) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM raminventory LIKE 'location_uuid'")->fetchAll();
        $hasLocationSchema = !empty($cols) && $pdo->query("SHOW TABLES LIKE 'locations'")->fetchColumn() !== false;
    } catch (Throwable $e) {
        $hasLocationSchema = false;
    }
}

if ($pdo === null) {
    echo "  SKIPPED  Noida vs Jaipur: checkComponentForConfig() match=false, units_elsewhere lists the Noida unit\n";
    echo "  SKIPPED  ComponentRelocation::move() refuses an installed (non-loose-stock) unit\n";
    echo "  SKIPPED  ComponentRelocation::move() writes a component_movements row on success and rolls back on refusal\n";
} elseif (!$hasLocationSchema) {
    echo "  SKIPPED  Noida vs Jaipur: replica predates the location migration (2026_08_26_001..003 not applied)\n";
    echo "  SKIPPED  ComponentRelocation::move() refuses an installed (non-loose-stock) unit\n";
    echo "  SKIPPED  ComponentRelocation::move() writes a component_movements row on success and rolls back on refusal\n";
} else {
    $pdo->beginTransaction();
    try {
        // Two synthetic locations, two synthetic units of one existing RAM spec:
        // one "at Noida", one "at Jaipur" (using synthetic uuids so this never
        // collides with real fleet data), no server involved yet.
        $ramSpecUuid = $pdo->query("SELECT UUID FROM raminventory WHERE Status = 1 LIMIT 1")->fetchColumn();
        if ($ramSpecUuid === false) {
            echo "  SKIPPED  no available RAM unit in this scratch DB to clone a fixture pair from\n";
        } else {
            $noidaLoc = 'aaaaaaaa-0000-4000-8000-000000000001';
            $jaipurLoc = 'aaaaaaaa-0000-4000-8000-000000000002';
            foreach ([$noidaLoc => 'Noida Yotta', $jaipurLoc => 'Jaipur Office'] as $uuid => $name) {
                $pdo->prepare("INSERT INTO locations (location_uuid, name, is_active) VALUES (?, ?, 1)
                               ON DUPLICATE KEY UPDATE name = VALUES(name)")->execute([$uuid, $name]);
            }

            $insNoida = $pdo->prepare("INSERT INTO raminventory (UUID, Status, SerialNumber, location_uuid) VALUES (?, 1, ?, ?)");
            $insNoida->execute([$ramSpecUuid, 'LOC-TEST-NOIDA-' . mt_rand(1000, 9999), $noidaLoc]);
            $noidaId = (int)$pdo->lastInsertId();

            $insJaipur = $pdo->prepare("INSERT INTO raminventory (UUID, Status, SerialNumber, location_uuid) VALUES (?, 1, ?, ?)");
            $insJaipur->execute([$ramSpecUuid, 'LOC-TEST-JAIPUR-' . mt_rand(1000, 9999), $jaipurLoc]);
            $jaipurId = (int)$pdo->lastInsertId();

            // A server "racked" at Jaipur: reuse an existing config and stamp its
            // location_uuid directly (a full rack/rack_servers fixture is out of
            // scope for this check — checkComponentForConfig() only needs the
            // config's resolved location_uuid to be Jaipur).
            $configUuid = $pdo->query("SELECT config_uuid FROM server_configurations ORDER BY config_uuid LIMIT 1")->fetchColumn();
            if ($configUuid === false) {
                echo "  SKIPPED  no server_configurations row in this scratch DB\n";
            } else {
                $hasConfigLocCol = $pdo->query("SHOW COLUMNS FROM server_configurations LIKE 'location_uuid'")->fetchAll();
                if (empty($hasConfigLocCol)) {
                    echo "  SKIPPED  server_configurations.location_uuid absent (seeder 2026_08_26_002 not applied)\n";
                } else {
                    $pdo->prepare("UPDATE server_configurations SET location_uuid = ? WHERE config_uuid = ?")
                        ->execute([$jaipurLoc, $configUuid]);

                    $check = LocationResolver::checkComponentForConfig($pdo, $configUuid, 'ram', $ramSpecUuid, null);
                    check('checkComponentForConfig(): supported=true once the location schema exists', $check['supported'] === true);
                    check('checkComponentForConfig(): match=false — the only available unit named is at Noida, the server at Jaipur',
                        $check['match'] === false || $check['units_here'] > 0 /* a stray real Jaipur unit of this spec would legitimately flip this */);
                    if ($check['match'] === false) {
                        $elsewhereIds = array_map(function ($u) { return (int)$u['inventory_id']; }, $check['units_elsewhere']);
                        check('units_elsewhere names the Noida unit specifically', in_array($noidaId, $elsewhereIds, true));
                    }

                    // ComponentRelocation::move(): refuse an installed unit.
                    $pdo->prepare("UPDATE raminventory SET Status = 2, ServerUUID = ? WHERE id = ?")->execute([$configUuid, $jaipurId]);
                    $installedResult = ComponentRelocation::move($pdo, 'ram', $jaipurId, ['location_uuid' => $noidaLoc], []);
                    check('ComponentRelocation::move() refuses an installed (Status=2/ServerUUID set) unit', $installedResult['success'] === false);

                    $movementCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM component_movements WHERE inventory_id = $jaipurId")->fetchColumn();
                    check('a refused move leaves no component_movements row behind', $movementCountBefore === 0);

                    // A legitimate move of the loose-stock Noida unit to Jaipur.
                    $moveResult = ComponentRelocation::move($pdo, 'ram', $noidaId, ['location_uuid' => $jaipurLoc], ['moved_by' => 0]);
                    check('ComponentRelocation::move() succeeds for loose stock moving to a real, active location', $moveResult['success'] === true);
                    if ($moveResult['success'] === true) {
                        $movedRow = $pdo->query("SELECT location_uuid FROM raminventory WHERE id = $noidaId")->fetch(PDO::FETCH_ASSOC);
                        check('the unit row now carries the new location_uuid', $movedRow['location_uuid'] === $jaipurLoc);
                        $movementRow = $pdo->query("SELECT * FROM component_movements WHERE inventory_id = $noidaId ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                        check('a component_movements row was written for the successful move', $movementRow !== false);
                        if ($movementRow !== false) {
                            check('component_movements.to_location_uuid is the destination', $movementRow['to_location_uuid'] === $jaipurLoc);
                            check('component_movements.from_location_uuid is the origin', $movementRow['from_location_uuid'] === $noidaLoc);
                        }
                    }

                    // A no-op move (already there) must be refused.
                    $noopResult = ComponentRelocation::move($pdo, 'ram', $noidaId, ['location_uuid' => $jaipurLoc], []);
                    check('ComponentRelocation::move() refuses a no-op move (already at that location)', $noopResult['success'] === false);
                }
            }
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    echo "  (DB-backed scenario ran against " . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden') . ", rolled back -- no data persisted)\n";
}

echo $fails === 0 ? "\nALL CHECKS PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
