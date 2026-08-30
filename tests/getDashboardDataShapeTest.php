<?php
/**
 * Regression test for the getDashboardData() response shape.
 *
 * Pins two things:
 *   1. The dashboard payload covers every type in VALID_COMPONENT_TYPES plus
 *      the 'servers' block, each with the expected count keys. (A dead
 *      duplicate of this function once covered only 6 types — this test
 *      makes that regression loud if it ever comes back.)
 *   2. A database failure THROWS instead of returning a sentinel array —
 *      callers must turn it into a 5xx, never a 200.
 *
 * Run: php tests/getDashboardDataShapeTest.php   (no database required)
 */

// BaseFunctions needs a JWT secret at load time; no DB connection is made.
putenv('JWT_SECRET=test-secret-for-shape-test-0123456789abcdef');
require_once __DIR__ . '/../core/helpers/BaseFunctions.php';

// --- Minimal PDO fakes -------------------------------------------------------

class FakeStatement
{
    private $sql;

    public function __construct($sql)
    {
        $this->sql = $sql;
    }

    public function execute($params = null)
    {
        return true;
    }

    public function fetch($mode = null)
    {
        if (strpos($this->sql, 'server_configurations') !== false) {
            return ['total' => 4, 'draft' => 1, 'validated' => 1, 'built' => 1, 'finalized' => 1];
        }
        // Component count query
        return ['total' => 5, 'available' => 3, 'in_use' => 1, 'failed' => 1];
    }

    // inventoryTableExists() runs $pdo->query(...INFORMATION_SCHEMA.TABLES...) once
    // (not prepare()) and reads it back with fetchAll(PDO::FETCH_COLUMN) -- every
    // type's table must be listed here or getDashboardData silently zeroes it out.
    public function fetchAll($mode = null)
    {
        return array_map(function ($type) { return $type . 'inventory'; }, VALID_COMPONENT_TYPES);
    }
}

class FakePDO extends PDO
{
    public function __construct()
    {
        // No real connection.
    }

    #[\ReturnTypeWillChange]
    public function prepare($sql, $options = [])
    {
        return new FakeStatement($sql);
    }

    #[\ReturnTypeWillChange]
    public function query($sql, ...$args)
    {
        return new FakeStatement($sql);
    }
}

class BrokenPDO extends PDO
{
    public function __construct()
    {
    }

    #[\ReturnTypeWillChange]
    public function prepare($sql, $options = [])
    {
        throw new RuntimeException('simulated database outage');
    }

    #[\ReturnTypeWillChange]
    public function query($sql, ...$args)
    {
        throw new RuntimeException('simulated database outage');
    }
}

// --- Assertions --------------------------------------------------------------

$failures = [];

// run_tests.php counts checks by grepping for its own "PASS"/"FAIL" line
// convention rather than trusting a suite's silence -- this suite used to
// print neither, which read as "ran nothing" (0 checks) the moment it joined
// the discovered set, indistinguishable from a suite that never executed.
function check($condition, $message)
{
    global $failures;
    if ($condition) {
        echo "  PASS  $message\n";
    } else {
        echo "  FAIL  $message\n";
        $failures[] = $message;
    }
}

$data = getDashboardData(new FakePDO(), ['id' => 1]);

check(isset($data['component_counts']), "response has 'component_counts'");
check(array_key_exists('total_components', $data), "response has 'total_components'");
check(array_key_exists('recent_activity', $data), "response has 'recent_activity'");
check(!isset($data['error']), "success response must not carry an 'error' key");

// Drawn from the live constant, not a copied-and-forgotten list -- risercard
// (2026-08-14) and serverplatform (2026-08-25) were both added to
// VALID_COMPONENT_TYPES after this test's list was last hand-typed, and
// neither was ever added here.
$expectedTypes = VALID_COMPONENT_TYPES;
foreach ($expectedTypes as $type) {
    check(isset($data['component_counts'][$type]), "component_counts covers '$type'");
    foreach (['total', 'available', 'in_use', 'failed'] as $key) {
        check(isset($data['component_counts'][$type][$key]), "component_counts.$type has '$key'");
    }
}

check(isset($data['component_counts']['servers']), "component_counts covers 'servers'");
foreach (['total', 'draft', 'validated', 'built', 'finalized'] as $key) {
    check(isset($data['component_counts']['servers'][$key]), "component_counts.servers has '$key'");
}

// N types x 5 (each type's fake 'total') + 4 (servers' fake 'total')
$expectedTotal = count($expectedTypes) * 5 + 4;
check($data['total_components'] === $expectedTotal, "total_components sums components + servers (expected $expectedTotal, got " . var_export($data['total_components'], true) . ")");

// DB failure must throw, not return a sentinel payload.
$threw = false;
try {
    getDashboardData(new BrokenPDO(), ['id' => 1]);
} catch (Exception $e) {
    $threw = true;
}
check($threw, "getDashboardData throws on database failure (fail-loud)");

// --- Report ------------------------------------------------------------------

if (empty($failures)) {
    echo "OK: getDashboardData shape test passed (" . (count($expectedTypes) * 5 + 12) . " assertions)\n";
    exit(0);
}

echo "FAILED:\n";
foreach ($failures as $failure) {
    echo "  - $failure\n";
}
exit(1);
