<?php
/**
 * dual_write_soak_monitor.php — read-only monitor for the U-B.4 precondition:
 * "DUAL_WRITE_ENABLED=on in production for >=24h before the backfill live
 * run" (migration/07-component-migration/README.md:4-5,
 * execution-packs/U-B.4.md checklist item 1).
 *
 * Runs the SAME reports P2's gate already requires (equivalence, orphan,
 * ledger, inventory — migration/phase-status.json's P2 gate_reports) via the
 * standard app.php bootstrap, i.e. against whatever DB the current .env
 * points at — production, once actually deployed there. NEVER writes
 * anything; every report this shells out to is itself read-only (confirmed:
 * none of orphan_report.php/ledger_report.php/inventory_report.php/
 * equivalence_report.php issue an INSERT/UPDATE/DELETE). Also runs one
 * additional check this script owns directly: has DUAL_WRITE_ENABLED
 * actually been self-materializing NEW config_components rows during the
 * window (catches "flag says on but nothing is landing" silently, which
 * none of the four report scripts above would otherwise notice on their
 * own since they only look at CURRENT structural integrity, not recent
 * write activity).
 *
 * fleet_parity_sweep.php is deliberately NOT run here — it is a scratch-DB
 * replay tool (GOLDEN_DB_* env vars, needs a full ims-data mirror on disk)
 * and was never designed to run against a live database, production
 * included (confirmed: it forces ENGINE_MODE=shadow, expects
 * imsbdcmsbharatda_Ims_Production.sql's shape, and its own docblock frames
 * it as an offline sweep). See "Optional secondary check" below for how to
 * use it safely during the soak anyway.
 *
 * Usage:
 *   php scripts/verify/dual_write_soak_monitor.php [--since-hours N]
 *     --since-hours N   New-row staleness check window (default 4).
 *
 * Exit: 0 = every check GREEN. 1 = at least one RED (see "Alert conditions"
 * in migration/07-component-migration/DUAL-WRITE-SOAK-MONITORING.md for what
 * to do about each one). 2 = usage/setup error.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
$bootstrap = $ROOT . '/core/config/app.php';
if (!file_exists($bootstrap)) {
    fwrite(STDERR, "Cannot locate core/config/app.php from " . __DIR__ . "\n");
    exit(2);
}
require_once $bootstrap;

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO connection not available after bootstrap.\n");
    exit(2);
}

$sinceHours = 4;
$idx = array_search('--since-hours', $argv, true);
if ($idx !== false && isset($argv[$idx + 1]) && is_numeric($argv[$idx + 1])) {
    $sinceHours = (int)$argv[$idx + 1];
}

$overallExit = 0;
$summary = [];

// ---------------------------------------------------------------------
// 1. DUAL_WRITE_ENABLED sanity — what mode is THIS process actually
//    reading? (Same getenv/$_ENV precedence every flag in this migration
//    uses — see FLAGS.md.) Informational only: a soak by definition means
//    this should read "on"; if it doesn't, everything below is moot.
// ---------------------------------------------------------------------
$mode = getenv('DUAL_WRITE_ENABLED');
if (!is_string($mode) || $mode === '') {
    $mode = $_ENV['DUAL_WRITE_ENABLED'] ?? 'off';
}
$mode = strtolower(trim((string)$mode));
echo "DUAL_WRITE_ENABLED (as read by this process) = $mode\n";
if ($mode !== 'on') {
    echo "  NOTE: not 'on' — this is not currently inside a soak window from this process's own vantage point.\n";
    echo "  (If this ran against production and you expected 'on', check the deploy, not this script.)\n";
}
echo "\n";

// ---------------------------------------------------------------------
// 2. P2 gate reports (equivalence, orphan, ledger, inventory) — shell out,
//    same subprocess pattern run_all.php already uses.
// ---------------------------------------------------------------------
$reports = [
    'equivalence' => __DIR__ . '/equivalence_report.php',
    'orphan'      => __DIR__ . '/orphan_report.php',
    'ledger'      => __DIR__ . '/ledger_report.php',
    'inventory'   => __DIR__ . '/inventory_report.php',
];
foreach ($reports as $name => $script) {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['php', $script], $descriptors, $pipes);
    if (!is_resource($process)) {
        echo "$name: RED (failed to launch $script)\n";
        $summary[$name] = 'RED';
        $overallExit = 1;
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $lastLine = trim(strrchr(trim($stdout), "\n") ?: $stdout);
    $status = $exitCode === 0 ? 'GREEN' : 'RED';
    echo "$name: $status" . ($status === 'RED' ? " -- $lastLine" : "") . "\n";
    $summary[$name] = $status;
    if ($exitCode !== 0) {
        $overallExit = 1;
    }
}
echo "\n";

// ---------------------------------------------------------------------
// 3. New-write staleness check — is dual-write actually landing rows?
//    Compares config_components rows added in the last $sinceHours against
//    config_events rows in the same window (every real mutation bumps
//    revision via a config_events row per INV-6, regardless of component
//    add/remove/replace/transition) -- if events exist but zero matching
//    component rows do, DUAL_WRITE_ENABLED is not doing anything despite
//    reading 'on'.
// ---------------------------------------------------------------------
$since = date('Y-m-d H:i:s', time() - $sinceHours * 3600);
$eventsStmt = $pdo->prepare('SELECT COUNT(*) FROM config_events WHERE created_at >= ?');
$eventsStmt->execute([$since]);
$eventsSince = (int)$eventsStmt->fetchColumn();

$componentsStmt = $pdo->prepare('SELECT COUNT(*) FROM config_components WHERE added_at >= ?');
$componentsStmt->execute([$since]);
$componentsSince = (int)$componentsStmt->fetchColumn();

echo "Activity in the last {$sinceHours}h: config_events=$eventsSince, config_components added=$componentsSince\n";
if ($eventsSince > 0 && $componentsSince === 0) {
    echo "RED: mutations are happening (config_events > 0) but NO new config_components rows landed --\n";
    echo "     dual-write does not appear to be self-materializing despite DUAL_WRITE_ENABLED reading 'on'.\n";
    $summary['staleness'] = 'RED';
    $overallExit = 1;
} elseif ($eventsSince === 0) {
    echo "  (no mutation activity in this window at all -- staleness check inconclusive, not a failure)\n";
    $summary['staleness'] = 'INCONCLUSIVE';
} else {
    echo "GREEN: new component rows are landing alongside new events.\n";
    $summary['staleness'] = 'GREEN';
}
echo "\n";

echo "dual_write_soak_monitor: " . ($overallExit === 0 ? 'GREEN' : 'RED') . " " . json_encode($summary) . "\n";
exit($overallExit);
