<?php
/**
 * storage_rules_test.php — U-R.5 unit test for the storage.* rule family.
 * Pure PHP + real ims-data fixtures (no DB). Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/TargetStateBuilder.php';
require_once $ROOT . '/core/models/validation/rules/StorageInterfacePathRule.php';
require_once $ROOT . '/core/models/validation/rules/StorageBayCapacityRule.php';
require_once $ROOT . '/core/models/validation/rules/StorageM2CapacityRule.php';
require_once $ROOT . '/core/models/validation/rules/StorageCaddyPairingRule.php';
require_once $ROOT . '/core/models/validation/Verdict.php';
require_once $ROOT . '/core/models/validation/Trigger.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

/**
 * "Real, but advisory" — the 2026-09-01 posture for the three storage rules that
 * used to report their findings as PASSES and were therefore invisible to
 * ValidateConfigService::warnings() (which lists Verdict::failures()).
 *
 * A result must now FAIL at Severity::WARNING, and must still block nothing under
 * any trigger. Asserting both halves is the point: the first half is what makes the
 * check real, the second is the legacy-parity guarantee the old assertions were
 * really protecting.
 */
function checkAdvisoryFailure($label, RuleResult $r) {
    check("$label: reports a failure (not a silent pass)", $r->passed() === false);
    check("$label: severity is WARNING", $r->severity() === Severity::WARNING);
    $blocksSomewhere = false;
    foreach (Trigger::all() as $trigger) {
        if ((new Verdict([$r], $trigger))->blocking()) { $blocksSomewhere = true; }
    }
    check("$label: blocks under NO trigger", $blocksSomewhere === false);
}

// Real fixtures.
const CHS_2BAY = 'a8f3b25d-4f1c-4b95-a3b0-fc30f5b12da8'; // 2x 2.5" bays
const CHS_35ONLY = 'fd6bc35a-70ab-43fc-af25-ffe8dcb810d9'; // SC113TQ-R700CB, 4x 3.5" bays, no 2.5"
const ST_SSD25 = 'a3b4c5d6-e7f8-a9b0-c1d2-e3f4a5b6c7d8'; // 2.5" SATA
const ST_HDD35 = 'e1a2b3c4-d5e6-f7a8-b9c0-d1e2f3a4b5c6'; // 3.5" HDD
const ST_M2 = 'b4c5d6e7-f8a9-b0c1-d2e3-f4a5b6c7d8e9';    // M.2 NVMe
const MB_M2_4SLOTS = '8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c'; // 4x M.2 slots
const CADDY_25 = '4a8a2c05-e993-4b00-acae-9f036617091c';
// F-11 fixtures -- the real components from the 2026-07-26 production shadow run.
const ST_SAS25 = '138e1181-f1bb-4c2e-a487-15afbe7098d6';    // 10E2400, "SAS 12Gb/s", 2.5-inch
const CHS_SAS_BP = '4981e5a2-74b5-46ed-ac9d-7f9bbfdbc6d5';  // Hitachi DS220, backplane SAS3, supports_sas
const CHS_NO_SAS_BP = 'abaa2c58-c08c-46f0-abcf-2242400e907c'; // Generic 4U, backplane SATA3, no SAS
const HBA_SAS = 'd4b7202e-0c59-4557-a964-7c38c4b2ef32';     // LSI 9500-16i, "SAS/SATA/NVMe Tri-Mode"

function chsRow($id, $uuid) { return ['id' => $id, 'component_type' => 'chassis', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }
function mbRow($id, $uuid) { return ['id' => $id, 'component_type' => 'motherboard', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }
function stRow($id, $uuid) { return ['id' => $id, 'component_type' => 'storage', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }
function caddyRow($id, $uuid) { return ['id' => $id, 'component_type' => 'caddy', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }
function hbaRow($id, $uuid) { return ['id' => $id, 'component_type' => 'hbacard', 'spec_uuid' => $uuid, 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows']; }

// -----------------------------------------------------------------------
echo "-- storage.bay_capacity (E) -- bay-TYPE availability, legacy-faithful --\n";
$oneDrive = new TargetState([chsRow(1, CHS_2BAY), stRow(2, ST_SSD25)]);
check('1x 2.5" drive in a 2-bay 2.5" chassis: passes', (new StorageBayCapacityRule())->evaluate($oneDrive)->passed() === true);

// Corrected 2026-07-25: legacy's count/capacity branch (ComponentCompatibility.php:4696-4715)
// is dead code -- $usedBays is never populated -- so legacy never blocks on overflow.
// Blocking here diverged from production on 7 shadow-parity rows.
$threeDrives = new TargetState([chsRow(1, CHS_2BAY), stRow(2, ST_SSD25), stRow(3, ST_SSD25), stRow(4, ST_SSD25)]);
$r = (new StorageBayCapacityRule())->evaluate($threeDrives);
checkAdvisoryFailure('3x 2.5" drives in a 2-bay chassis: oversubscription', $r);
check('...and the overflow is still reported in details for the tightening pass',
    isset($r->details()['overflow'][0]['capacity']) && $r->details()['overflow'][0]['capacity'] === 2);

// ComponentValidator::validateChassisBayStorage():1024-1029 accepts a 2.5" drive
// in a 3.5" bay via a caddy adapter and records it as a WARNING, not an issue.
$caddyFallback = new TargetState([chsRow(1, CHS_35ONLY), stRow(2, ST_SSD25)]);
check('2.5" drive in a 3.5"-only chassis: passes (caddy adapter fallback)',
    (new StorageBayCapacityRule())->evaluate($caddyFallback)->passed() === true);

// No equivalent fallback exists in legacy for the reverse direction.
$noFallback = new TargetState([chsRow(1, CHS_2BAY), stRow(2, ST_HDD35)]);
$rNo = (new StorageBayCapacityRule())->evaluate($noFallback);
check('3.5" drive in a 2.5"-only chassis: fails (no reverse fallback)', $rNo->passed() === false);
check('bay_capacity severity is ERROR', $rNo->severity() === Severity::ERROR);

$m2InBayChassis = new TargetState([chsRow(1, CHS_2BAY), stRow(2, ST_M2), stRow(3, ST_M2), stRow(4, ST_M2)]);
check('M.2 storage bypasses bay validation entirely (3x M.2 in a 2-bay chassis still passes)', (new StorageBayCapacityRule())->evaluate($m2InBayChassis)->passed() === true);

check('no chassis: bay check does not apply', (new StorageBayCapacityRule())->evaluate(new TargetState([stRow(1, ST_SSD25)]))->passed() === true);

// -----------------------------------------------------------------------
echo "-- storage.m2_capacity (E) -- A-10: read-time W promoted to blocking E --\n";
$withinM2 = new TargetState(array_merge([mbRow(1, MB_M2_4SLOTS)], array_map(function ($i) { return stRow($i, ST_M2); }, range(2, 5))));
check('4x M.2 on a 4-slot board: passes', (new StorageM2CapacityRule())->evaluate($withinM2)->passed() === true);

$overM2 = new TargetState(array_merge([mbRow(1, MB_M2_4SLOTS)], array_map(function ($i) { return stRow($i, ST_M2); }, range(2, 6))));
$rM2 = (new StorageM2CapacityRule())->evaluate($overM2);
check('5x M.2 on a 4-slot board: fails (A-10)', $rM2->passed() === false);
check('m2_capacity severity is ERROR', $rM2->severity() === Severity::ERROR);

// -----------------------------------------------------------------------
echo "-- storage.caddy_pairing (VF) -- bay-sized, non-blocking (F-29) --\n";
// REWRITTEN for F-29. The previous block asserted "2 drives, 0 caddies: fails" and
// paired caddies against the DRIVE form factor. Both encoded the defect F-29 recorded:
// the add gate sizes a caddy to the BAY it slots into and requires one only for the
// adapter case (smaller drive, larger bay), so pairing by drive size demanded the
// opposite part and made a routine 2.5"-in-3.5" build unfinishable. Legacy
// (ServerBuilder::validateStorageConnections) was corrected in the same unit; these
// assertions are the engine half of that correction, not a test relaxed to fit code.

// No chassis => no bays => nothing seats in a bay => no adapter tray implied.
$noChassis = new TargetState([stRow(1, ST_SSD25), stRow(2, ST_SSD25)]);
check('no chassis: passes -- caddy pairing does not apply without bays',
    (new StorageCaddyPairingRule())->evaluate($noChassis)->passed() === true);

// Native fit: a 2.5" drive in a 2.5" bay needs no adapter, with or without a caddy.
$native = new TargetState([chsRow(1, CHS_2BAY), stRow(2, ST_SSD25)]);
check('2.5" drive in a 2.5" bay: passes, and no caddy is demanded',
    (new StorageCaddyPairingRule())->evaluate($native)->passed() === true);

// The F-29 case itself: 2.5" drive, 3.5"-only chassis, no 3.5" caddy present.
// Must NOT block -- legacy raises missing_caddy as a warning and admits the build.
$adapted = new TargetState([chsRow(1, CHS_35ONLY), stRow(2, ST_SSD25)]);
$rCaddy = (new StorageCaddyPairingRule())->evaluate($adapted);
checkAdvisoryFailure('2.5" drive into a 3.5"-only chassis with no caddy: shortage', $rCaddy);
check('...and the shortage is reported in details for the tightening pass',
    ($rCaddy->details()['missing'] ?? null) === 1);
check('...sized to the BAY (3.5"), not to the drive (2.5") -- the F-29 correction',
    ($rCaddy->details()['required_caddy_size'] ?? null) === '3.5');
check('caddy_pairing DECLARES VALIDATION_FAILURE (the shortage result overrides it to WARNING)',
    (new StorageCaddyPairingRule())->severity() === Severity::VALIDATION_FAILURE);

// A 2.5" caddy cannot carry a drive into a 3.5" bay -- it is the wrong body size.
// This is the exact part the OLD rule demanded, so it is the sharpest regression guard.
$wrongSizeCaddy = new TargetState([chsRow(1, CHS_35ONLY), stRow(2, ST_SSD25), caddyRow(3, CADDY_25)]);
$rWrong = (new StorageCaddyPairingRule())->evaluate($wrongSizeCaddy);
check('a 2.5" caddy does not satisfy a 3.5" bay adapter need', ($rWrong->details()['missing'] ?? null) === 1);

// COVERAGE GAP, stated rather than faked: the positive adapter path (enough 3.5"
// caddies => no shortage details) needs a real 3.5" caddy UUID from ims-data. No such
// fixture constant exists in this file and ims-data is not present in a bare checkout,
// so inventing a UUID would assert nothing. Add it when a 3.5" caddy fixture is picked.

$excess = new TargetState([chsRow(1, CHS_2BAY), stRow(2, ST_SSD25), caddyRow(3, CADDY_25), caddyRow(4, CADDY_25)]);
check('caddy excess: still passes -- excess is informational only, never blocks', (new StorageCaddyPairingRule())->evaluate($excess)->passed() === true);

// -----------------------------------------------------------------------
echo "-- storage.interface_path (E) -- SAS needs a SAS HBA *or* a SAS backplane --\n";
$ifPath = new StorageInterfacePathRule();

check('SATA storage, no HBA: passes (SATA never hard-blocks on path)',
    $ifPath->evaluate(new TargetState([stRow(1, ST_SSD25)]))->passed() === true);

// F-11 regression (2026-07-27). This is the exact production shadow case from
// 2026-07-26 21:59:16Z: config e7e50504 added SAS drive 138e1181 to chassis
// 4981e5a2 (backplane.supports_sas = true) with NO hbacard present. Legacy
// allowed it; the engine blocked it. If this assertion ever fails again, the
// chassis-backplane path has been dropped from the rule.
$sasOnSasBackplane = new TargetState([chsRow(1, CHS_SAS_BP), stRow(2, ST_SAS25)]);
check('F-11: SAS drive + chassis with SAS backplane, no HBA: PASSES (legacy-faithful)',
    $ifPath->evaluate($sasOnSasBackplane)->passed() === true);

// The cascade half of F-11: because this rule is config-scoped, the stale
// violation above also blocked every LATER add to the same config (the real
// traffic saw caddy and pciecard adds blocked by it). Adding an unrelated
// component to a legitimate config must stay clean.
$sasPlusUnrelated = new TargetState([chsRow(1, CHS_SAS_BP), stRow(2, ST_SAS25), caddyRow(3, CADDY_25)]);
check('F-11 cascade: unrelated caddy add to that same config is not blocked',
    $ifPath->evaluate($sasPlusUnrelated)->passed() === true);

// The blocking condition legacy really has, still intact in both directions.
check('SAS drive alone (no chassis, no HBA): fails',
    $ifPath->evaluate(new TargetState([stRow(1, ST_SAS25)]))->passed() === false);

$sasOnSataOnlyChassis = new TargetState([chsRow(1, CHS_NO_SAS_BP), stRow(2, ST_SAS25)]);
check('SAS drive + SATA3-only backplane, no HBA: still fails (no SAS path)',
    $ifPath->evaluate($sasOnSataOnlyChassis)->passed() === false);
check('interface_path severity is ERROR',
    $ifPath->evaluate($sasOnSataOnlyChassis)->severity() === Severity::ERROR);

check('SAS drive + SAS-capable HBA, no chassis: passes (HBA path)',
    $ifPath->evaluate(new TargetState([hbaRow(1, HBA_SAS), stRow(2, ST_SAS25)]))->passed() === true);
check('SAS drive + SATA-only chassis + SAS HBA: passes (HBA satisfies it)',
    $ifPath->evaluate(new TargetState([chsRow(1, CHS_NO_SAS_BP), hbaRow(2, HBA_SAS), stRow(3, ST_SAS25)]))->passed() === true);

// --------------------------------------------------------------------------
// F-24 (2026-07-28): this rule blocks at its LEGACY MOMENT only -- a snapshot
// or a finalize-time VALIDATE (no TargetState::subject()). On an ADD/REPLACE it
// passes and reports, because legacy's add path never reaches
// StorageConnectionValidator::validate() below
// STORAGE_BAY_AUTHORITY_ENABLED=enforce. Before this, ONE unpathable drive
// failed EVERY add to its config: 9 of 9 unexplained fleet-parity diffs, all
// config 05bcb95b, adds of ram/storage/nic alike -- and under
// ENGINE_MODE=enforce that config could not have been edited at all.
echo "-- storage.interface_path F-24: add-time vs validate-time --\n";

// The control that keeps the rest of this section honest: with NO subject the
// blocking condition is unchanged. (Asserted twice on purpose -- if the
// subject-aware branch below ever swallows this one, the rule is vacuous.)
check('F-24 control: no subject (VALIDATE/finalize) still BLOCKS an unpathable SAS drive',
    $ifPath->evaluate($sasOnSataOnlyChassis)->passed() === false);

$addRam = TargetStateBuilder::withAdd($sasOnSataOnlyChassis, [
    'component_type' => 'ram', 'spec_uuid' => 'ram-fixture-not-read-by-this-rule', 'source' => 'pending',
]);
$ramResult = $ifPath->evaluate($addRam);
checkAdvisoryFailure('F-24: adding RAM to a config holding an unpathable drive', $ramResult);
check('F-24: ...and the deferred condition is reported, not forgotten',
    ($ramResult->details()['deferred_unpathed_storage'] ?? 0) === 1);
check('F-24: ...and details name what the operation was about',
    ($ramResult->details()['subject_type'] ?? null) === 'ram');

// The drive itself: legacy accepts the add and reports not_connected, then
// surfaces the missing HBA at validate-config. Observed in production
// 2026-07-27 on config cbd00521 before F-20 landed.
$addSasDrive = TargetStateBuilder::withAdd(new TargetState([chsRow(1, CHS_NO_SAS_BP)]), [
    'component_type' => 'storage', 'spec_uuid' => ST_SAS25, 'source' => 'pending',
]);
checkAdvisoryFailure('F-24: adding the unpathable SAS drive itself, at add time',
    $ifPath->evaluate($addSasDrive));
check('F-24: ...but the same components with no subject still FAIL at validate time',
    $ifPath->evaluate(new TargetState($addSasDrive->components()))->passed() === false);

$addToCleanConfig = TargetStateBuilder::withAdd($sasOnSasBackplane, [
    'component_type' => 'ram', 'spec_uuid' => 'ram-fixture-not-read-by-this-rule', 'source' => 'pending',
]);
check('F-24: a config with no unpathable drive reports nothing deferred',
    ($addToCleanConfig !== null)
        && $ifPath->evaluate($addToCleanConfig)->details() === []);

// Guards the fixtures themselves: if ims-data ever changes underneath, the
// assertions above would silently stop testing what they claim to test.
$du = new DataExtractionUtilities();
check('fixture guard: ST_SAS25 spec really is a SAS 2.5" drive',
    stripos((string)($du->getStorageByUUID(ST_SAS25)['interface'] ?? ''), 'sas') !== false);
check('fixture guard: CHS_SAS_BP really declares backplane.supports_sas',
    !empty($du->getChassisSpecifications(CHS_SAS_BP)['backplane']['supports_sas']));
check('fixture guard: CHS_NO_SAS_BP really does NOT declare backplane.supports_sas',
    empty($du->getChassisSpecifications(CHS_NO_SAS_BP)['backplane']['supports_sas']));
check('fixture guard: HBA_SAS spec protocol mentions SAS',
    stripos((string)($du->getHBACardByUUID(HBA_SAS)['protocol'] ?? ''), 'sas') !== false);

foreach (['StorageInterfacePathRule', 'StorageBayCapacityRule', 'StorageM2CapacityRule', 'StorageCaddyPairingRule'] as $class) {
    $src = file_get_contents("$ROOT/core/models/validation/rules/$class.php");
    check("$class.php contains no 'quantity' token (INV-1)", stripos($src, 'quantity') === false);
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
