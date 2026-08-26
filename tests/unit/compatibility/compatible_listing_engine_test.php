<?php
/**
 * compatible_listing_engine_test.php
 *
 * server-get-compatible used to answer "can this be added?" with the legacy
 * ComponentCompatibility universe while the add button answered it with ValidationEngine
 * (production runs ENGINE_MODE=enforce). Two implementations of one question, free to
 * drift -- and they had: platform-imported config 831f1be8 listed ZERO compatible drives
 * while its own component_options said storage could be added.
 *
 * The listing now asks the engine. This test pins the three properties that makes it
 * correct, against a hand-built TargetState mirroring 831f1be8 (platform board + chassis,
 * 16 bays: 12x 3.5" + 4x 2.5"). No PDO -- TargetState is constructed directly and
 * ServerBuilder's verdict-mapping helper is invoked through reflection.
 *
 * Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/components/ComponentSpecPaths.php';
require_once $ROOT . '/core/models/components/PlatformSpecIndex.php';
require_once $ROOT . '/core/models/validation/ValidationEngine.php';
require_once $ROOT . '/core/models/validation/TargetStateBuilder.php';
require_once $ROOT . '/core/models/validation/Trigger.php';
require_once $ROOT . '/core/models/server/ServerBuilder.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// Fixtures from config 831f1be8 / ims-data.
const PLATFORM_CHASSIS = 'd0986f76-0666-500d-a538-d3b3b635f753'; // 12x 3.5" + 4x 2.5" = 16
const PLATFORM_BOARD   = '9325d0ba-7e58-5f87-8bde-b7dd79a21602';
const DRIVE_35_SATA    = 'e1a2b3c4-d5e6-f7a8-b9c0-d1e2f3a4b5c6'; // 3.5-inch, SATA III
const DRIVE_25_SAS     = '138e1181-f1bb-4c2e-a487-15afbe7098d6'; // 2.5-inch, SAS 12Gb/s
const DRIVE_M2_NVME    = 'b4c5d6e7-f8a9-b0c1-d2e3-f4a5b6c7d8e9'; // M.2 2280 NVMe -- this board has no M.2 slot
const TOTAL_BAYS       = 16;

echo "\n-- Fixture guard --\n";
$chassisSpec = PlatformSpecIndex::find('chassis', PLATFORM_CHASSIS);
check('platform chassis resolves', is_array($chassisSpec));
check('platform chassis still has ' . TOTAL_BAYS . ' bays',
    ($chassisSpec['drive_bays']['total_bays'] ?? null) === TOTAL_BAYS);
check('platform board resolves', is_array(PlatformSpecIndex::find('motherboard', PLATFORM_BOARD)));
if ($fails > 0) {
    echo "\nFIXTURE GUARD FAILED -- ims-data no longer matches this test's assumptions.\n";
    exit(1);
}

/** A rows-shaped component tuple, as TargetStateBuilder::normalizeRows() produces. */
function row($id, $type, $specUuid, $extra = []) {
    return array_merge([
        'id' => $id,
        'component_type' => $type,
        'spec_uuid' => $specUuid,
        'inventory_table' => $type === 'motherboard' || $type === 'chassis'
            ? 'serverplatforminventory'   // the platform box is the stocked unit
            : $type . 'inventory',
        'inventory_id' => 1000 + $id,
        'serial_number' => 'TEST-' . $id,
        'parent_id' => null,
        'slot_ref' => null,
        'source' => 'rows',
        'status_v2' => null,
    ], $extra);
}

$engine = new ValidationEngine();

/** The listing's verdict mapping, exercised exactly as getCompatibleComponents() does. */
$sbClass = new ReflectionClass('ServerBuilder');
$mapper = $sbClass->getMethod('verdictToListingResult');
$mapper->setAccessible(true);
$sb = $sbClass->newInstanceWithoutConstructor();

/**
 * @return array{compatible:bool,reason:string,warnings:string[]}
 */
function listingResult($engine, $mapper, $sb, TargetState $current, array $baselineFailed, array $candidateRow) {
    $verdict = $engine->evaluate(TargetStateBuilder::withAdd($current, $candidateRow), Trigger::ADD);
    return $mapper->invoke($sb, $verdict, $baselineFailed);
}

function baselineOf($engine, TargetState $state) {
    $failed = [];
    foreach ($engine->evaluate($state, Trigger::ADD)->failures() as $f) {
        $failed[$f->ruleId()] = true;
    }
    return $failed;
}

echo "\n-- Case 1: an empty platform build offers its drives --\n";
$current = new TargetState([
    row(1, 'motherboard', PLATFORM_BOARD),
    row(2, 'chassis', PLATFORM_CHASSIS),
]);
$baseline = baselineOf($engine, $current);
echo "  (baseline pre-existing failures: " . (empty($baseline) ? 'none' : implode(', ', array_keys($baseline))) . ")\n";

$r35 = listingResult($engine, $mapper, $sb, $current, $baseline,
    ['component_type' => 'storage', 'spec_uuid' => DRIVE_35_SATA]);
check('a 3.5" SATA drive is COMPATIBLE with the 12x 3.5" platform chassis (the reported bug)',
    $r35['compatible'] === true);
echo "    reason: {$r35['reason']}\n";
if (!empty($r35['warnings'])) {
    echo "    warnings: " . implode(' | ', $r35['warnings']) . "\n";
}

$r25 = listingResult($engine, $mapper, $sb, $current, $baseline,
    ['component_type' => 'storage', 'spec_uuid' => DRIVE_25_SAS]);
check('a 2.5" SAS drive is COMPATIBLE with the 4x 2.5" rear bays', $r25['compatible'] === true);

echo "\n-- Case 2: a genuinely impossible candidate is still refused --\n";
// The R740xd board exposes no M.2 slot, so StorageM2CapacityRule (ERROR) blocks any M.2
// drive at ADD. This is the half that proves the listing is not simply saying yes.
$rM2 = listingResult($engine, $mapper, $sb, $current, $baseline,
    ['component_type' => 'storage', 'spec_uuid' => DRIVE_M2_NVME]);
check('an M.2 drive is INCOMPATIBLE with a board that has no M.2 slot', $rM2['compatible'] === false);
echo "    reason: {$rM2['reason']}\n";

echo "\n-- Case 2b: bay OVERFLOW is deliberately not a block at ADD --\n";
// Documented in StorageBayCapacityRule (corrected 2026-07-25 for shadow parity): legacy
// never blocked on overflow, so the rule reports it as a passing result carrying details.
// Asserted here so the listing's permissiveness is a RECORDED decision, not a surprise --
// and so a future tightening of that rule fails here rather than in production.
$full = [row(1, 'motherboard', PLATFORM_BOARD), row(2, 'chassis', PLATFORM_CHASSIS)];
for ($i = 0; $i < TOTAL_BAYS; $i++) {
    $full[] = row(100 + $i, 'storage', $i < 12 ? DRIVE_35_SATA : DRIVE_25_SAS);
}
$fullState = new TargetState($full);
$fullBaseline = baselineOf($engine, $fullState);
$rOverflow = listingResult($engine, $mapper, $sb, $fullState, $fullBaseline,
    ['component_type' => 'storage', 'spec_uuid' => DRIVE_35_SATA]);
check('the 17th drive into a 16-bay chassis is still offered -- same answer the add path gives',
    $rOverflow['compatible'] === true);

echo "\n-- Case 3: a PRE-EXISTING failure must not blank the list --\n";
// Without the baseline diff, any rule already failing on the current state would be
// attributed to every candidate and the whole listing would come back empty.
$brokenBaseline = $baseline;
$brokenBaseline['storage.bay_capacity'] = true;
$brokenBaseline['storage.interface_path'] = true;
$brokenBaseline['storage.caddy_pairing'] = true;
$rDespite = listingResult($engine, $mapper, $sb, $current, $brokenBaseline,
    ['component_type' => 'storage', 'spec_uuid' => DRIVE_35_SATA]);
check('a candidate stays compatible when the failing rules were ALREADY failing',
    $rDespite['compatible'] === true);

// The converse, both directions: with storage.m2_capacity already failing the M.2
// candidate is no longer blamed for it...
$m2Baseline = $baseline;
$m2Baseline['storage.m2_capacity'] = true;
$rM2Suppressed = listingResult($engine, $mapper, $sb, $current, $m2Baseline,
    ['component_type' => 'storage', 'spec_uuid' => DRIVE_M2_NVME]);
check('a pre-existing m2_capacity failure is not attributed to the candidate',
    $rM2Suppressed['compatible'] === true);
// ...but against a clean baseline it is.
$rM2Clean = listingResult($engine, $mapper, $sb, $current, [],
    ['component_type' => 'storage', 'spec_uuid' => DRIVE_M2_NVME]);
check('the diff does not suppress a real NEW failure', $rM2Clean['compatible'] === false);

echo "\n-- Case 4: warnings are surfaced, not treated as blocks --\n";
// Verdict::blocking() semantics at ADD: only ERROR blocks. A VALIDATION_FAILURE such as
// storage.caddy_pairing is reported as a warning here -- which is exactly what the add
// button already accepts, with the block landing at finalize.
check('a compatible result carries a warnings array (possibly empty)',
    is_array($r35['warnings']));
check('an incompatible result names the blocking rule in its reason',
    is_string($rM2['reason']) && $rM2['reason'] !== '');

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
