<?php
/**
 * base_command_test.php — U-C.1 unit test for BaseCommand's generic
 * transaction/guard/evaluate/apply skeleton, using a FAKE command (per the
 * pack). No real MySQL needed: a schema-less SQLite in-memory PDO connection
 * supplies real beginTransaction()/commit()/rollBack()/inTransaction()
 * semantics (BaseCommand's own concern), while the two real-SQL touchpoints
 * (lockAndLoadConfigRow / currentRevision) are overridden by the fake to
 * avoid depending on a real server_configurations schema.
 * Exit 0 = all pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/commands/BaseCommand.php';
require_once $ROOT . '/core/models/validation/Trigger.php';
require_once $ROOT . '/core/models/validation/Severity.php';
require_once $ROOT . '/core/models/validation/RuleResult.php';
require_once $ROOT . '/core/models/validation/Verdict.php';
require_once $ROOT . '/core/models/validation/TargetState.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

/**
 * FakeCommand — records what happened at each hook so the test can assert
 * ordering/rollback without touching a real config_components schema.
 */
class FakeCommand extends BaseCommand
{
    public $log = [];
    public $lockedRowToReturn = ['config_uuid' => 'fake-uuid', 'revision' => 5, 'status_v2' => 'draft', 'configuration_status' => 0];
    public $throwInApply = null;
    public $revisionAfterApply = 6;

    protected function lockAndLoadConfigRow(): ?array
    {
        $this->log[] = 'lock';
        return $this->lockedRowToReturn;
    }

    protected function currentRevision(): int
    {
        return $this->revisionAfterApply;
    }

    protected function trigger(): string { return Trigger::ADD; }

    protected function buildTarget(TargetState $current, array $lockedRow): TargetState
    {
        $this->log[] = 'buildTarget';
        return $current;
    }

    protected function apply(PDO $pdo, TargetState $target): void
    {
        $this->log[] = 'apply';
        if ($this->throwInApply !== null) {
            throw $this->throwInApply;
        }
    }

    protected function afterCommit(): void
    {
        $this->log[] = 'afterCommit';
    }
}

/** Swaps ValidationEngine for BlockingEngine so execute()'s own `new ValidationEngine()` call blocks. */
class FakeCommandBlocking extends FakeCommand
{
    protected function buildTarget(TargetState $current, array $lockedRow): TargetState
    {
        $this->log[] = 'buildTarget';
        // execute() always evaluates via the real `new ValidationEngine()` (not
        // swappable per-command), so this fixture proves blocking via an already-
        // registered rule that needs zero ims-data fixtures to fire:
        // dependency.blocked_removal's dangling-parent-id mechanism (U-R.8) --
        // an sfp whose parent_id points at a nonexistent row always fails closed.
        $blocked = new TargetState([
            ['id' => 1, 'component_type' => 'sfp', 'spec_uuid' => 'does-not-matter',
             'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null,
             'parent_id' => 999, 'slot_ref' => null, 'source' => 'rows', 'status_v2' => null],
        ]);
        return $blocked;
    }

    protected function trigger(): string { return Trigger::REMOVE; }
}

/**
 * Schema-less-ish SQLite in-memory connection: real transaction semantics
 * (BaseCommand's own concern) plus the two tables TargetStateBuilder::
 * fromCurrent() unconditionally queries (config_components / server_configurations),
 * empty/minimal so it resolves to an empty TargetState via the JSON-fallback
 * path (FakeCommand's buildTarget just returns $current as-is -- it never
 * needs real component rows). FakeCommand's own lockAndLoadConfigRow()/
 * currentRevision() overrides mean this connection is never used for the
 * config-row lock/revision-read BaseCommand would otherwise need.
 */
function pdo() {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE config_components (id INTEGER PRIMARY KEY, config_uuid TEXT, removed_at TEXT)');
    $pdo->exec('CREATE TABLE server_configurations (config_uuid TEXT PRIMARY KEY)');
    $pdo->exec("INSERT INTO server_configurations (config_uuid) VALUES ('fake-uuid')");
    return $pdo;
}

// =========================================================================
echo "-- happy path: writes in order lock -> buildTarget -> apply -> afterCommit, commits --\n";
$cmd = new FakeCommand(pdo(), 'fake-uuid', 1, null);
$result = $cmd->execute();
check('log order is lock,buildTarget,apply,afterCommit', $cmd->log === ['lock', 'buildTarget', 'apply', 'afterCommit']);
check('CommandResult carries the post-apply revision', $result->revision === 6);
check('CommandResult carries a non-blocking Verdict', $result->verdict->blocking() === false);

// =========================================================================
echo "-- config not found: CommandFailed, no apply/afterCommit --\n";
$cmd2 = new FakeCommand(pdo(), 'fake-uuid');
$cmd2->lockedRowToReturn = null;
try {
    $cmd2->execute();
    check('threw CommandFailed(config_not_found)', false);
} catch (CommandFailed $e) {
    check('threw CommandFailed(config_not_found)', $e->errorType === 'config_not_found' && $e->httpStatus === 404);
    check('apply/afterCommit never ran', !in_array('apply', $cmd2->log) && !in_array('afterCommit', $cmd2->log));
}

// =========================================================================
echo "-- StateGuard blocks (finalized config, STATE_MACHINE_ENABLED default off -- verifies TEMP-GUARD path is bypassed by design, guard itself only fires when enforce) --\n";
putenv('STATE_MACHINE_ENABLED=enforce');
$cmd3 = new FakeCommand(pdo(), 'fake-uuid');
$cmd3->lockedRowToReturn = ['config_uuid' => 'fake-uuid', 'revision' => 1, 'status_v2' => 'finalized', 'configuration_status' => 3];
try {
    $cmd3->execute();
    check('StateGuard enforce blocks a finalized config', false);
} catch (CommandFailed $e) {
    check('StateGuard enforce blocks a finalized config', $e->errorType === 'config_immutable' && $e->httpStatus === 409);
    check('apply never ran', !in_array('apply', $cmd3->log));
}
putenv('STATE_MACHINE_ENABLED'); // restore off (unset)

// =========================================================================
echo "-- revision mismatch: 409-class failure, apply never runs --\n";
$cmd4 = new FakeCommand(pdo(), 'fake-uuid', 1, 999);
try {
    $cmd4->execute();
    check('revision mismatch throws CommandFailed', false);
} catch (CommandFailed $e) {
    check('revision mismatch throws CommandFailed', $e->errorType === 'revision_mismatch' && $e->httpStatus === 409);
    check('apply never ran on revision mismatch', !in_array('apply', $cmd4->log));
}

// =========================================================================
echo "-- blocking verdict: apply never runs, CommandFailed carries the Verdict --\n";
$cmd5 = new FakeCommandBlocking(pdo(), 'fake-uuid');
try {
    $cmd5->execute();
    check('blocking verdict throws CommandFailed', false);
} catch (CommandFailed $e) {
    check('blocking verdict throws CommandFailed', $e->errorType === 'validation_blocked' && $e->httpStatus === 422);
    check('apply never ran when verdict blocks', !in_array('apply', $cmd5->log));
    check('CommandFailed carries the blocking Verdict', $e->verdict !== null && $e->verdict->blocking() === true);
}

// =========================================================================
echo "-- exception inside apply(): rolled back, wrapped as CommandFailed(command_exception) --\n";
$cmd6 = new FakeCommand(pdo(), 'fake-uuid');
$cmd6->throwInApply = new \RuntimeException('boom');
try {
    $cmd6->execute();
    check('exception in apply() rolls back and rethrows as CommandFailed', false);
} catch (CommandFailed $e) {
    check('exception in apply() rolls back and rethrows as CommandFailed', $e->errorType === 'command_exception' && $e->httpStatus === 500);
    check('afterCommit never ran after an apply() exception', !in_array('afterCommit', $cmd6->log));
    check('original exception preserved as ->getPrevious()', $e->getPrevious() instanceof \RuntimeException && $e->getPrevious()->getMessage() === 'boom');
}

// =========================================================================
echo "-- nested transaction: joins caller's tx, does not commit/rollback it itself --\n";
$sharedPdo = pdo();
$sharedPdo->beginTransaction();
$cmd7 = new FakeCommand($sharedPdo, 'fake-uuid');
$cmd7->execute();
check('command did not commit the caller-owned transaction', $sharedPdo->inTransaction() === true);
$sharedPdo->rollBack();

// =========================================================================
echo "-- INV-3 pre-state: only BaseCommand.php touches beginTransaction under core/models/commands/ --\n";
$commandsDir = "$ROOT/core/models/commands";
$offenders = [];
foreach (glob("$commandsDir/*.php") as $f) {
    if (basename($f) === 'BaseCommand.php') { continue; }
    if (stripos(file_get_contents($f), 'beginTransaction') !== false) { $offenders[] = basename($f); }
}
check('no other file under core/models/commands/ calls beginTransaction', empty($offenders));

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
