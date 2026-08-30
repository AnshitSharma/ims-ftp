<?php
/**
 * component_delete_guard_test.php — BACKLOG §B-16 regression test.
 *
 * WHAT WENT WRONG. deleteComponent() was a bare `DELETE FROM {type}inventory
 * WHERE id = ?` with no in-use check. Both `{type}-delete` and
 * `{type}-bulk-delete` route through it, so deleting a unit that a live server
 * still used destroyed the inventory row and left the config_components row
 * claiming it behind. That is how configuration 1f61541b came to display an SFP
 * (sfpinventory ID 99) that no longer exists — found by scripts/audit-orphans.php
 * on its first run after U-D.3c repointed it at config_components.
 *
 * Since U-D.3 dropped the nine legacy JSON columns, config_components is the
 * ONLY record of what a configuration contains. That row IS the dependency.
 *
 * WHAT THIS PINS. Four behaviours, none of which needs a database — the fakes
 * below record what deleteComponent() would have sent:
 *   1. a live claim refuses the delete, names the configuration, and never
 *      issues the DELETE at all;
 *   2. no claim still deletes, against the right table and id;
 *   3. fail-closed (INV-5) — if the claim query itself throws, the delete is
 *      REFUSED, not attempted. A unit that might be in use is never destroyed
 *      on the strength of a query that did not answer;
 *   4. the claim is matched on inventory_table, never on component_type: one
 *      serverplatform unit is claimed by BOTH a motherboard row and a chassis
 *      row, so only the table identifies the physical unit.
 *
 * Plus a static check that both API handlers still surface the refusal instead
 * of flattening it into a generic 500 — the guard is worth little if the
 * operator cannot see which configuration is holding the unit.
 *
 * Run: php tests/regression/component_delete_guard_test.php   (no database required)
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// BaseFunctions needs a JWT secret at load time; no DB connection is made.
putenv('JWT_SECRET=test-secret-for-delete-guard-0123456789abcdef');
require_once __DIR__ . '/../../core/helpers/BaseFunctions.php';

$ROOT = dirname(__DIR__, 2);

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// --- Recording fakes ---------------------------------------------------------

class RecordingStatement
{
    private $sql;
    private $log;
    private $claims;

    public function __construct($sql, &$log, array $claims)
    {
        $this->sql = $sql;
        $this->log =& $log;
        $this->claims = $claims;
    }

    public function execute($params = null)
    {
        $this->log[] = ['sql' => $this->sql, 'params' => $params];
        return true;
    }

    #[\ReturnTypeWillChange]
    public function fetchAll($mode = null, $arg = null)
    {
        return $this->claims;
    }
}

/** Answers the claim query with $claims; records every statement executed. */
class RecordingPDO extends PDO
{
    public $log = [];
    private $claims;

    public function __construct(array $claims = [])
    {
        $this->claims = $claims;
    }

    #[\ReturnTypeWillChange]
    public function prepare($sql, $options = [])
    {
        return new RecordingStatement($sql, $this->log, $this->claims);
    }
}

/** The claim query cannot be answered at all. */
class UnreadablePDO extends PDO
{
    public $log = [];

    public function __construct()
    {
    }

    #[\ReturnTypeWillChange]
    public function prepare($sql, $options = [])
    {
        $this->log[] = ['sql' => $sql, 'params' => null];
        throw new PDOException('simulated database outage');
    }
}

function issuedDeletes(array $log)
{
    return array_values(array_filter($log, function ($e) {
        return stripos($e['sql'], 'DELETE FROM') !== false;
    }));
}

// --- 1. A live claim refuses the delete --------------------------------------

echo "1. a live config_components claim refuses the delete\n";
$pdo = new RecordingPDO(['33ff4c41-16aa-4706-97d1-52f50680b870']);
$refused = null;
try {
    deleteComponent($pdo, 'ram', 243, 1);
} catch (ComponentInUseException $e) {
    $refused = $e;
}
check('throws ComponentInUseException', $refused !== null);
check('names the configuration holding the unit',
    $refused !== null && strpos($refused->getMessage(), '33ff4c41-16aa-4706-97d1-52f50680b870') !== false);
check('issues NO DELETE', count(issuedDeletes($pdo->log)) === 0);

// --- 2. No claim: the unit still deletes -------------------------------------

echo "\n2. an unclaimed unit still deletes\n";
$pdo = new RecordingPDO([]);
$ok = false;
$threw = null;
try {
    $ok = deleteComponent($pdo, 'ram', 243, 1);
} catch (Exception $e) {
    $threw = $e;
}
check('does not throw', $threw === null);
check('returns true', $ok === true);
$deletes = issuedDeletes($pdo->log);
check('issues exactly one DELETE', count($deletes) === 1);
check('against raminventory',
    count($deletes) === 1 && stripos($deletes[0]['sql'], 'raminventory') !== false);
check('bound to the id given', count($deletes) === 1 && $deletes[0]['params'] === [243]);

// --- 3. Fail-closed: an unanswerable claim query refuses ---------------------

echo "\n3. fail-closed — an unreadable claim query refuses (INV-5)\n";
$pdo = new UnreadablePDO();
$refused = null;
try {
    deleteComponent($pdo, 'ram', 243, 1);
} catch (ComponentInUseException $e) {
    $refused = $e;
}
check('throws ComponentInUseException', $refused !== null);
check('issues NO DELETE', count(issuedDeletes($pdo->log)) === 0);
check('leaks no driver text to the caller',
    $refused !== null && stripos($refused->getMessage(), 'simulated database outage') === false);
// PDOException also extends RuntimeException. If the guard threw a bare
// RuntimeException, a handler catching that to surface the message would leak
// driver text on an unrelated DB error, so the dedicated subclass is load-bearing.
check('ComponentInUseException is not a PDOException',
    !is_subclass_of('ComponentInUseException', 'PDOException'));

// --- 4. Matched on the inventory table, not the component type ---------------

echo "\n4. the claim is matched on inventory_table, not component_type\n";
$pdo = new RecordingPDO([]);
deleteComponent($pdo, 'serverplatform', 7, 1);
$claimQ = null;
foreach ($pdo->log as $entry) {
    if (stripos($entry['sql'], 'config_components') !== false) {
        $claimQ = $entry;
        break;
    }
}
check('a claim query ran before the delete', $claimQ !== null);
check('filters on inventory_table',
    $claimQ !== null && stripos($claimQ['sql'], 'inventory_table') !== false);
check('bound to serverplatforminventory, not a component_type',
    $claimQ !== null && $claimQ['params'] === ['serverplatforminventory', 7]);
check('only live rows count as a claim',
    $claimQ !== null && stripos($claimQ['sql'], 'removed_at IS NULL') !== false);

// --- 5. Static: the handlers surface the refusal -----------------------------

echo "\n5. both delete handlers surface the refusal\n";
$crud = file_get_contents($ROOT . '/api/handlers/components/component_crud_api.php');
check('component_crud_api.php catches ComponentInUseException twice (delete + bulk-delete)',
    substr_count($crud, 'catch (ComponentInUseException') === 2);
check('the single delete answers 409, not a generic 500',
    preg_match('/catch \(ComponentInUseException \$e\) \{.*?send_json_response\(0, 1, 409, \$e->getMessage\(\)\)/s', $crud) === 1);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
