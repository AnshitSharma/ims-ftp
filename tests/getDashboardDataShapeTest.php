<?php
/**
 * Regression test for the getDashboardData() response shape.
 *
 * Pins two things:
 *   1. The dashboard payload covers ALL 10 component types plus the 'servers'
 *      block, each with the expected count keys. (A dead duplicate of this
 *      function once covered only 6 types — this test makes that regression
 *      loud if it ever comes back.)
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
}

// --- Assertions --------------------------------------------------------------

$failures = [];

function check($condition, $message)
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$data = getDashboardData(new FakePDO(), ['id' => 1]);

check(isset($data['component_counts']), "response has 'component_counts'");
check(array_key_exists('total_components', $data), "response has 'total_components'");
check(array_key_exists('recent_activity', $data), "response has 'recent_activity'");
check(!isset($data['error']), "success response must not carry an 'error' key");

$expectedTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'chassis', 'pciecard', 'hbacard', 'sfp'];
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

// 10 types x 5 components + 4 servers
check($data['total_components'] === 54, "total_components sums components + servers (expected 54, got " . var_export($data['total_components'], true) . ")");

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
