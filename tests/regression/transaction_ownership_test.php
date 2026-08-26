<?php
/**
 * transaction_ownership_test.php — U-C.6 (transaction ownership consolidation)
 * regression coverage.
 *
 * PURPOSE. U-C.6's checklist asks for two things a grep alone cannot give:
 *   (a) "INV-3 CHECK command green" — INV-3's literal grep ("beginTransaction
 *       may appear only in BaseCommand, the backfill script and test
 *       bootstrap") has never been runnable as written: core/auth/ACL.php,
 *       core/helpers/BaseFunctions.php, the pipeline managers and four API
 *       handlers all own transactions and are entirely outside this
 *       migration's scope. The pack itself says the check passes "with the
 *       documented allowlist" — no such allowlist existed in the repo. This
 *       file IS that allowlist, made mechanical: the set of transaction-owning
 *       files is pinned, so a NEW transaction owner introduced anywhere under
 *       core/ or api/ fails this suite, in either direction (added or removed).
 *   (b) proof that the ownTransaction pattern actually behaves as claimed at
 *       runtime — nested calls join, failures roll back only what the failing
 *       frame owns, and no command failure can leave a committed partial write.
 *
 * NOT a test of a consolidation that has not happened. As of 2026-08-24 the
 * ServerBuilder CommandGate guard U-C.6 prescribes is NOT implemented (see the
 * session report: deleteConfiguration has no command replacement, and
 * finalizeConfiguration's public entry IS the enforce delegation shim, so the
 * guard as written is not behaviour-preserving). This file pins the CURRENT
 * contract so whoever implements the guard has a red/green signal, and so the
 * ownership map cannot drift underneath it in the meantime.
 *
 * Exit 0 = all pass; exit 1 = a failure.
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

/** Every .php under a directory, recursively. */
function php_files(string $dir): array {
    if (!is_dir($dir)) { return []; }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() === 'php') { $out[] = $f->getPathname(); }
    }
    sort($out);
    return $out;
}

/** Lines that actually CALL beginTransaction (not the ones that merely name it in prose). */
function begin_call_lines(string $path): array {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $hits = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/->\s*beginTransaction\s*\(/', $line)) { $hits[] = $i; }
    }
    return $hits;
}

// =============================================================================
echo "-- INV-3 ownership map (no DB needed) --\n";
// =============================================================================

/**
 * THE ALLOWLIST. Every entry is a file that legitimately owns a transaction
 * today, with the reason it is not INV-3's problem. Anything else that begins
 * a transaction under core/ or api/ is a new transaction owner and a violation.
 */
$allowed = [
    // --- the migration's target owner -------------------------------------
    'core/models/commands/BaseCommand.php'
        => 'INV-3 target state: the ONE transaction owner for every mutation this migration introduces',

    // --- legacy mutation paths still in the box (U-C.6 scope) -------------
    'core/models/server/ServerBuilder.php'
        => 'addComponent/removeComponent/finalizeConfiguration/deleteConfiguration — nestable ownTransaction, U-C.6 pending',
    'core/models/compatibility/OnboardNICHandler.php'
        => 'autoAddOnboardNICs/replaceOnboardNIC — begin-guarded (A-L7), joins a command transaction',
    'core/models/server/ServerConfiguration.php'
        => 'configuration persistence helper — legacy, outside the command layer',
    'core/models/rack/ServerRelocation.php'
        => 'move()/swap() — nestable ownTransaction (same guard as ServerBuilder), joins a command/request transaction when one is open; predates this allowlist (2026-08-26 location migration), added 2026-08-26',
    'core/models/location/ComponentRelocation.php'
        => 'move() — nestable ownTransaction, same pattern as ServerRelocation.php; backs inventory.component.relocate (Hardware Handover), 2026-08-26',

    // --- outside this migration entirely ----------------------------------
    'core/auth/ACL.php'                                 => 'ACL/role writes — not a configuration mutation',
    'core/helpers/BaseFunctions.php'                    => 'component inventory CRUD — not a configuration mutation',
    'core/models/pipelines/PipelineManager.php'         => 'Requests engine — separate subsystem',
    'core/models/pipelines/PipelineTemplateManager.php' => 'Request Types engine — separate subsystem',
    'api/handlers/auth/auth_api.php'                    => 'auth/session writes',
    'api/handlers/vendors/vendor_api.php'               => 'vendor CRUD',
    'api/handlers/server/compatibility_api.php'         => 'compatibility bench build lifecycle',
    'api/handlers/server/server_api.php'                => 'handler-owned OUTER transaction for quantity>1 add (commands nest into it — PLAN_VERIFICATION_REVIEW:65)',
];

$found = [];
foreach (['core', 'api'] as $top) {
    foreach (php_files($ROOT . '/' . $top) as $abs) {
        if (begin_call_lines($abs) !== []) {
            $found[] = str_replace('\\', '/', substr($abs, strlen($ROOT) + 1));
        }
    }
}
sort($found);
$expected = array_keys($allowed);
sort($expected);

$unexpected = array_values(array_diff($found, $expected));
$vanished   = array_values(array_diff($expected, $found));

check('no UNDOCUMENTED transaction owner under core/ or api/ (INV-3 allowlist)', $unexpected === []);
if ($unexpected !== []) { echo "        new owner(s): " . implode(', ', $unexpected) . "\n"; }
check('every allowlisted transaction owner still exists (allowlist not stale)', $vanished === []);
if ($vanished !== []) { echo "        gone / no longer begins a transaction: " . implode(', ', $vanished) . "\n"; }

// --- commands: BaseCommand is the only one ----------------------------------
$commandOffenders = [];
foreach (php_files($ROOT . '/core/models/commands') as $abs) {
    if (basename($abs) === 'BaseCommand.php') { continue; }
    if (stripos((string)file_get_contents($abs), 'beginTransaction') !== false) {
        $commandOffenders[] = basename($abs);
    }
}
check('BaseCommand.php is the only file under core/models/commands/ that mentions beginTransaction',
    $commandOffenders === []);
if ($commandOffenders !== []) { echo "        offender(s): " . implode(', ', $commandOffenders) . "\n"; }

// --- the row-writing layer owns NO transaction ------------------------------
foreach (['core/models/config/ConfigComponentRepository.php', 'core/models/config/ConfigComponentWriter.php'] as $rel) {
    $src = (string)file_get_contents($ROOT . '/' . $rel);
    check(basename($rel) . ': never begins, commits or rolls back a transaction',
        !preg_match('/->\s*(beginTransaction|commit|rollBack|rollback)\s*\(/', $src));
}
$repoSrc = (string)file_get_contents($ROOT . '/core/models/config/ConfigComponentRepository.php');
check('ConfigComponentRepository REFUSES to write outside an active transaction (fail-closed)',
    strpos($repoSrc, 'requires an active transaction') !== false
    && strpos($repoSrc, '!$this->pdo->inTransaction()') !== false);

// --- every legacy begin/commit/rollback is ownership-guarded ----------------
foreach ([
    'core/models/server/ServerBuilder.php',
    'core/models/compatibility/OnboardNICHandler.php',
] as $rel) {
    $lines = file($ROOT . '/' . $rel, FILE_IGNORE_NEW_LINES);
    $base  = basename($rel);

    $unguardedBegin = [];
    foreach (begin_call_lines($ROOT . '/' . $rel) as $i) {
        $window = implode("\n", array_slice($lines, max(0, $i - 3), 4));
        if (strpos($window, 'ownTransaction') === false) { $unguardedBegin[] = $i + 1; }
    }
    check("$base: every beginTransaction() is guarded by the ownTransaction pattern (nestable)",
        $unguardedBegin === []);
    if ($unguardedBegin !== []) { echo "        unguarded at line(s): " . implode(', ', $unguardedBegin) . "\n"; }

    $unguardedCommit = [];
    foreach ($lines as $i => $line) {
        if (!preg_match('/->\s*commit\s*\(/', $line)) { continue; }
        $window = implode("\n", array_slice($lines, max(0, $i - 3), 4));
        if (strpos($window, 'ownTransaction') === false) { $unguardedCommit[] = $i + 1; }
    }
    check("$base: every commit() is guarded by ownTransaction — never commits a caller's transaction",
        $unguardedCommit === []);
    if ($unguardedCommit !== []) { echo "        unguarded at line(s): " . implode(', ', $unguardedCommit) . "\n"; }

    $unguardedRollback = [];
    foreach ($lines as $i => $line) {
        if (!preg_match('/->\s*(rollBack|rollback)\s*\(/', $line)) { continue; }
        $window = implode("\n", array_slice($lines, max(0, $i - 3), 4));
        if (strpos($window, 'ownTransaction') === false) { $unguardedRollback[] = $i + 1; }
    }
    check("$base: every rollback() is guarded by ownTransaction — never destroys a caller's transaction",
        $unguardedRollback === []);
    if ($unguardedRollback !== []) { echo "        unguarded at line(s): " . implode(', ', $unguardedRollback) . "\n"; }
}

// --- BaseCommand's own ownership contract -----------------------------------
$baseSrc = (string)file_get_contents($ROOT . '/core/models/commands/BaseCommand.php');
check('BaseCommand only begins when it owns the frame ($ownTransaction = !inTransaction())',
    strpos($baseSrc, '$ownTransaction = !$this->pdo->inTransaction();') !== false);
check('BaseCommand::execute() commits only its own transaction',
    preg_match('/if \(\$ownTransaction\) \{\s*\$this->pdo->commit\(\);/', $baseSrc) === 1);
check('BaseCommand rolls back only its own transaction on failure',
    substr_count($baseSrc, 'if ($ownTransaction && $this->pdo->inTransaction()) {') >= 2);
check('BaseCommand::dryRun() ALWAYS releases what it took (finally + rollback, INV-8)',
    preg_match('/\} finally \{\s*if \(\$ownTransaction && \$this->pdo->inTransaction\(\)\) \{\s*\$this->pdo->rollBack\(\);/', $baseSrc) === 1);
check('afterCommit() hooks run only AFTER the commit (never inside the transaction)',
    strpos($baseSrc, '$this->afterCommit();') > strpos($baseSrc, '$this->pdo->commit();'));

// =============================================================================
echo "-- runtime ownership (real scratch DB when reachable; SKIPPED otherwise) --\n";
// =============================================================================
require_once __DIR__ . '/_scratch_db.php';
require_once $ROOT . '/core/models/commands/BaseCommand.php';
require_once $ROOT . '/core/models/commands/AddComponentCommand.php';

$pdo = scratch_db_connect();
if ($pdo !== null) {
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if (($schemaGap = scratch_db_schema_gap($pdo)) !== null) {
        echo "  (scratch DB unusable: $schemaGap)\n";
        $pdo = null;
    }
}

if ($pdo === null) {
    echo "  SKIPPED  standalone command failure leaves no transaction open\n";
    echo "  SKIPPED  nested command failure leaves the caller's transaction intact\n";
    echo "  SKIPPED  nested command success is not committed; caller's rollback erases it whole\n";
} else {
    // -------------------------------------------------------------------
    // S1. A command that owns its frame and fails must roll ITSELF back and
    //     leave nothing open. Nothing here is ever committed: the failure is
    //     raised before apply() is ever reached.
    // -------------------------------------------------------------------
    check('S1 precondition: no transaction open', !$pdo->inTransaction());
    $caught = null;
    try {
        (new AddComponentCommand($pdo, 'NO-SUCH-CONFIG-' . substr(md5(uniqid()), 0, 8), 'ram', '00000000-0000-0000-0000-000000000000', [], 0))->execute();
    } catch (CommandFailed $e) {
        $caught = $e;
    }
    check('S1 standalone failure raises CommandFailed', $caught !== null);
    check('S1 standalone failure is config_not_found (failed before apply())',
        $caught !== null && $caught->errorType === 'config_not_found');
    check('S1 standalone failure rolled back its OWN transaction (none left open)', !$pdo->inTransaction());

    // -------------------------------------------------------------------
    // S2. The same failure NESTED inside a caller's transaction must not
    //     touch that transaction — this is the "rolled back something the
    //     caller still believes is live" hazard, asserted directly.
    // -------------------------------------------------------------------
    $probe = 'TXOWN-PROBE-' . substr(md5(uniqid()), 0, 8);
    $pdo->beginTransaction();
    try {
        // Caller's own uncommitted work, done BEFORE the nested command.
        $pdo->prepare('INSERT INTO server_configurations (config_uuid, server_name, is_virtual, configuration_status) VALUES (?, ?, 0, 1)')
            ->execute([$probe, 'TX OWNERSHIP PROBE']);

        $caught2 = null;
        try {
            (new AddComponentCommand($pdo, 'NO-SUCH-CONFIG-' . substr(md5(uniqid()), 0, 8), 'ram', '00000000-0000-0000-0000-000000000000', [], 0))->execute();
        } catch (CommandFailed $e) {
            $caught2 = $e;
        }
        check('S2 nested failure still raises CommandFailed', $caught2 !== null);
        check("S2 nested failure left the caller's transaction OPEN", $pdo->inTransaction());

        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM server_configurations WHERE config_uuid = ?');
        $stmt->execute([$probe]);
        check("S2 nested failure did NOT discard the caller's uncommitted work",
            $pdo->inTransaction() && (int)$stmt->fetch()['c'] === 1);
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM server_configurations WHERE config_uuid = ?');
    $stmt->execute([$probe]);
    check("S2 cleanup: caller's rollback removed the probe row (nothing committed)", (int)$stmt->fetch()['c'] === 0);

    // -------------------------------------------------------------------
    // S3. A SUCCESSFUL nested command must not commit. The caller's rollback
    //     must erase the whole operation — the config_components row AND the
    //     inventory unit it claimed. This is the "committed partial write"
    //     assertion: if the command had committed anything of its own, the
    //     rollback below would leave a fragment behind.
    //
    //     Fixture pairs are dryRun()-pre-checked (add_command_test's Finding B
    //     convention): an arbitrary (config, RAM) pair legitimately BLOCKS on
    //     real fleet data, which is not a fixture.
    // -------------------------------------------------------------------
    $pdo->beginTransaction();
    try {
        $configs = $pdo->query("SELECT config_uuid FROM server_configurations WHERE configuration_status < 3 ORDER BY config_uuid LIMIT 8")->fetchAll(PDO::FETCH_COLUMN);
        $rams    = $pdo->query("SELECT DISTINCT UUID FROM raminventory WHERE Status = 1 ORDER BY UUID LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);

        $green = null;
        foreach ($configs as $cu) {
            foreach ($rams as $ru) {
                try {
                    $v = (new AddComponentCommand($pdo, $cu, 'ram', $ru, [], 0))->dryRun();
                } catch (CommandFailed $e) {
                    continue;
                }
                if (!$v->blocking()) { $green = [$cu, $ru]; break 2; }
            }
        }

        if ($green === null) {
            echo "  SKIPPED  nested command success is not committed; caller's rollback erases it whole"
               . " — no (open config, available RAM) pair pre-checks green in this scratch DB\n";
        } else {
            list($cu, $ru) = $green;
            $revBefore   = (int)$pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($cu))->fetchColumn();
            $liveBefore  = (int)$pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = " . $pdo->quote($cu) . " AND removed_at IS NULL")->fetchColumn();
            $availBefore = (int)$pdo->query("SELECT COUNT(*) FROM raminventory WHERE UUID = " . $pdo->quote($ru) . " AND Status = 1")->fetchColumn();

            $result = (new AddComponentCommand($pdo, $cu, 'ram', $ru, [], 0))->execute();

            check('S3 nested success returns a CommandResult', $result instanceof CommandResult);
            check("S3 nested success did NOT commit — caller's transaction still open", $pdo->inTransaction());

            $revAfter  = (int)$pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($cu))->fetchColumn();
            $liveAfter = (int)$pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = " . $pdo->quote($cu) . " AND removed_at IS NULL")->fetchColumn();
            check("S3 the write IS visible inside the caller's transaction (revision bumped)", $revAfter > $revBefore);
            check("S3 the write IS visible inside the caller's transaction (one more live row)", $liveAfter === $liveBefore + 1);

            $pdo->rollBack();

            $revRolled   = (int)$pdo->query("SELECT revision FROM server_configurations WHERE config_uuid = " . $pdo->quote($cu))->fetchColumn();
            $liveRolled  = (int)$pdo->query("SELECT COUNT(*) FROM config_components WHERE config_uuid = " . $pdo->quote($cu) . " AND removed_at IS NULL")->fetchColumn();
            $availRolled = (int)$pdo->query("SELECT COUNT(*) FROM raminventory WHERE UUID = " . $pdo->quote($ru) . " AND Status = 1")->fetchColumn();

            check('S3 caller rollback undid the revision bump (no committed partial write)', $revRolled === $revBefore);
            check('S3 caller rollback undid the config_components row', $liveRolled === $liveBefore);
            check('S3 caller rollback released the inventory unit the command claimed', $availRolled === $availBefore);
        }
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
    }
}

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
