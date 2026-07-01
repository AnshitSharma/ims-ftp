<?php
/**
 * storage_bay_authority_unit.php — focused unit test for the Phase-3 single
 * chassis-bay model (StorageConnectionValidator::evaluateBayUsage).
 *
 * The golden-master corpus does not exercise an oversubscribed mixed-form-factor
 * bay scenario, so this test drives the model directly with synthetic candidate
 * specs to prove the combined rule: quantity-aware AND bay-form-factor-only
 * (2.5"/3.5" occupy bays — incl. U.2/U.3; M.2 and PCIe AIC do not), with unknown
 * specs counted conservatively. Read-only; uses the same scratch DB as the harness
 * only to construct the validator.
 *
 * Exit 0 = all pass; exit 1 = a failure (prints which).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
$dbPass = getenv('GOLDEN_DB_PASS');
if ($dbPass === false) { $dbPass = ''; }
putenv("DB_HOST=$dbHost"); putenv("DB_NAME=$dbName");
putenv("DB_USER=$dbUser"); putenv("DB_PASS=$dbPass");

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once $ROOT . '/core/models/compatibility/StorageConnectionValidator.php';
$v = new StorageConnectionValidator($pdo);

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

$TOTAL = 8; // pretend the chassis has 8 bays

// --- Candidate form-factor classification (the bay-consuming rule) ---
$r = $v->evaluateBayUsage([], ['form_factor' => '2.5-inch SATA'], 1, $TOTAL);
check("2.5\" candidate consumes a bay (requested=1)", $r['requested'] === 1);

$r = $v->evaluateBayUsage([], ['form_factor' => '3.5-inch SAS'], 1, $TOTAL);
check("3.5\" candidate consumes a bay (requested=1)", $r['requested'] === 1);

$r = $v->evaluateBayUsage([], ['form_factor' => '2.5-inch U.2'], 1, $TOTAL);
check("U.2 (2.5\") candidate consumes a bay (requested=1)", $r['requested'] === 1);

$r = $v->evaluateBayUsage([], ['form_factor' => 'M.2 2280'], 1, $TOTAL);
check("M.2 candidate consumes NO bay (requested=0)", $r['requested'] === 0);

$r = $v->evaluateBayUsage([], ['form_factor' => 'Add-in Card (HHHL)'], 1, $TOTAL);
check("PCIe AIC candidate consumes NO bay (requested=0)", $r['requested'] === 0);

// --- Quantity awareness on the candidate ---
$r = $v->evaluateBayUsage([], ['form_factor' => '2.5-inch'], 4, $TOTAL);
check("candidate quantity scales requested (4)", $r['requested'] === 4);

$r = $v->evaluateBayUsage([], ['form_factor' => 'M.2'], 4, $TOTAL);
check("M.2 candidate quantity stays 0", $r['requested'] === 0);

// --- Existing-storage conservative counting (no resolvable spec) ---
$existing = [
    ['component_uuid' => '', 'quantity' => 2],                 // no uuid → count 2
    ['component_uuid' => 'not-a-real-uuid-xyz', 'quantity' => 3], // unknown spec → count 3
];
$r = $v->evaluateBayUsage($existing, null, 0, $TOTAL);
check("existing unknown/no-uuid drives counted by quantity (used=5)", $r['used'] === 5);
check("null candidate → requested 0", $r['requested'] === 0);
check("available == total - used (3)", $r['available'] === 3);

// --- Sufficiency threshold ---
$r = $v->evaluateBayUsage($existing, ['form_factor' => '2.5-inch'], 3, $TOTAL); // 5 used + 3 = 8 == total
check("used+requested == total is sufficient (boundary)", $r['sufficient'] === true);
$r = $v->evaluateBayUsage($existing, ['form_factor' => '2.5-inch'], 4, $TOTAL); // 5 + 4 = 9 > 8
check("used+requested > total is insufficient", $r['sufficient'] === false);
$r = $v->evaluateBayUsage($existing, ['form_factor' => 'M.2'], 4, $TOTAL); // M.2 requested 0 → 5 <= 8
check("M.2 add never overflows bays (sufficient)", $r['sufficient'] === true);

echo "\n";
if ($fails === 0) {
    echo "OK: StorageConnectionValidator::evaluateBayUsage — single bay model verified.\n";
    exit(0);
}
echo "FAILED: $fails assertion(s).\n";
exit(1);
