<?php
/**
 * lane_authority_unit.php — focused unit test for the Phase-3 single lane model
 * (PcieLaneBudgetValidator::evaluateAssembledStorageLaneBudget).
 *
 * The golden-master corpus contains no storage-lane-oversubscribed config, so the
 * enforce path never flips a verdict there. This test drives the method directly
 * with a real CPU UUID + synthetic candidate specs to prove the budget/used/
 * requested/sufficient math (incl. the insufficient branch, M.2 exemption, and
 * quantity scaling) is correct. Read-only; uses the same scratch DB as the harness.
 *
 * Exit 0 = all assertions pass; exit 1 = a failure (prints which).
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

require_once $ROOT . '/core/models/compatibility/PcieLaneBudgetValidator.php';
require_once $ROOT . '/core/models/shared/DataExtractionUtilities.php';

$validator = new PcieLaneBudgetValidator($pdo);
$dataUtils = new DataExtractionUtilities($pdo);

// Real CPU from the dump; read its actual pcie_lanes so assertions track real data.
$CPU_UUID = '545e143b-57b3-419e-86e5-1df6f7aa8fd3';
$cpuSpecs = $dataUtils->getCPUByUUID($CPU_UUID);
if (!$cpuSpecs || !isset($cpuSpecs['pcie_lanes'])) {
    fwrite(STDERR, "FATAL: CPU $CPU_UUID has no pcie_lanes in scratch DB; cannot run.\n");
    exit(2);
}
$cpuLanes = (int)$cpuSpecs['pcie_lanes'];

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

$baseExisting = ['cpu' => [['component_uuid' => $CPU_UUID, 'quantity' => 1]]];

// 1. Empty system + x4 candidate → budget=cpuLanes, used=0, requested=4, sufficient.
$r = $validator->evaluateAssembledStorageLaneBudget(
    $baseExisting, ['interface' => 'PCIe 4.0 x4', 'form_factor' => 'U.2'], 1
);
check("budget == single CPU lanes ($cpuLanes)", $r['budget'] === $cpuLanes);
check("used == 0 (no cards installed)", $r['used'] === 0);
check("requested == 4 (parsed x4, not hardcoded)", $r['requested'] === 4);
check("sufficient when 4 <= $cpuLanes", $r['sufficient'] === true);

// 2. Two CPUs (qty=2) → budget doubles (proves all-CPU × qty, not first-CPU-only).
$r2 = $validator->evaluateAssembledStorageLaneBudget(
    ['cpu' => [['component_uuid' => $CPU_UUID, 'quantity' => 2]]],
    ['interface' => 'PCIe 4.0 x4', 'form_factor' => 'U.2'], 1
);
check("budget scales with CPU quantity (== " . ($cpuLanes * 2) . ")", $r2['budget'] === $cpuLanes * 2);

// 3. Candidate width exceeds budget → insufficient + correct message numbers.
$huge = $cpuLanes + 8; // unsatisfiable
$r3 = $validator->evaluateAssembledStorageLaneBudget(
    $baseExisting, ['interface' => "PCIe 5.0 x{$huge}", 'form_factor' => 'U.2'], 1
);
check("requested == $huge (parsed wide candidate)", $r3['requested'] === $huge);
check("insufficient when requested > budget", $r3['sufficient'] === false);
check("available_lanes == budget - used ($cpuLanes)", $r3['available_lanes'] === $cpuLanes);

// 4. M.2 candidate → zero expansion cost regardless of interface (TP-1C).
$r4 = $validator->evaluateAssembledStorageLaneBudget(
    $baseExisting, ['interface' => 'PCIe 4.0 x4', 'form_factor' => 'M.2 2280'], 1
);
check("M.2 candidate requested == 0 (dedicated chipset lanes)", $r4['requested'] === 0);
check("M.2 candidate always sufficient", $r4['sufficient'] === true);

// 5. Quantity scaling on the candidate: 3 x4 drives → requested 12.
$r5 = $validator->evaluateAssembledStorageLaneBudget(
    $baseExisting, ['interface' => 'PCIe 4.0 x4', 'form_factor' => 'U.2'], 3
);
check("candidate quantity scales requested (3 x4 == 12)", $r5['requested'] === 12);

// 6. No-CPU system → budget 0; a real x4 candidate cannot fit.
$r6 = $validator->evaluateAssembledStorageLaneBudget(
    [], ['interface' => 'PCIe 4.0 x4', 'form_factor' => 'U.2'], 1
);
check("no-CPU budget == 0", $r6['budget'] === 0);
check("no-CPU x4 candidate insufficient", $r6['sufficient'] === false);

// 7. Candidate with no parseable width → requested 0 (data-gated, never fabricated).
$r7 = $validator->evaluateAssembledStorageLaneBudget(
    $baseExisting, ['interface' => 'NVMe', 'form_factor' => 'U.2'], 1
);
check("unparseable width → requested 0 (no fabricated lanes)", $r7['requested'] === 0);
check("unparseable width → sufficient (no constraint)", $r7['sufficient'] === true);

echo "\n";
if ($fails === 0) {
    echo "OK: LaneAuthority::evaluateAssembledStorageLaneBudget — all assertions pass (CPU lanes=$cpuLanes).\n";
    exit(0);
}
echo "FAILED: $fails assertion(s).\n";
exit(1);
