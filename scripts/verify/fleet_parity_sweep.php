<?php
/**
 * fleet_parity_sweep.php — offline, fleet-wide engine-vs-legacy parity sweep.
 *
 * PURPOSE
 *   parity_report.php only ever analyzes whatever shadow rows a LIVE
 *   ENGINE_MODE=shadow production hook happened to log (reports/shadow/
 *   engine-*.jsonl) -- today, zero rows, since ENGINE_MODE stays off in
 *   production per this migration's own rules. This script instead REPLAYS
 *   every persisted component of every server_configurations row in the
 *   scratch DB through ServerBuilder::validateComponentAddition() with
 *   ENGINE_MODE forced to 'shadow' for the duration of this process only
 *   (never touching production .env) -- reusing tests/characterize_
 *   compatibility.php's own extraction/replay loop verbatim. Each replay
 *   already runs BOTH the legacy check and the engine evaluation and logs a
 *   real comparison row via ShadowRunner::record() (the exact mechanism
 *   ServerBuilder's own ENGINE_MODE=shadow hook uses in production) -- this
 *   script does not reimplement that comparison, it just triggers it at
 *   fleet scale and then triages the result.
 *
 * IMPORTANT: this generates NO expected_diffs.json entries itself. Per this
 * session's explicit instruction, only a diff traceable to a specific
 * RULE_MAP.md intentional-diff row belongs in that file, and that judgment
 * call is made by the U-R.* unit that introduced the rule (already recorded
 * there), not by a sweep script. Every UNEXPLAINED diff this sweep finds is
 * printed for human triage -- never auto-classified as expected.
 *
 * Usage:
 *   php scripts/verify/fleet_parity_sweep.php
 * Connection: same GOLDEN_DB_HOST/NAME/USER/PASS override convention as
 * tests/characterize_compatibility.php (defaults 127.0.0.1/ims_compat_golden/root/"").
 *
 * Exit: 0 = every diff found is mapped to an existing expected_diffs.json
 *       entry (or there were no diffs); 1 = at least one UNEXPLAINED diff,
 *       or the scratch DB was unreachable (nothing was swept).
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

$ROOT = dirname(__DIR__, 2); // ims-ftp/
$REPO = dirname($ROOT);

// ---------------------------------------------------------------------------
// 1. Inherit production behaviour flags from ims-ftp/.env (same as
//    characterize_compatibility.php), then FORCE ENGINE_MODE=shadow for this
//    process only -- production's own .env is never written to.
// ---------------------------------------------------------------------------
$envPath = $ROOT . '/.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ((substr($v, 0, 1) === '"' && substr($v, -1) === '"') || (substr($v, 0, 1) === "'" && substr($v, -1) === "'")) {
            $v = substr($v, 1, -1);
        }
        putenv("$k=$v");
        $_ENV[$k] = $v;
    }
}
foreach ([
    'ENGINE_MODE'                 => 'shadow', // the whole point of this sweep
    'COMPATIBILITY_DUALRUN_LOG'   => 'false',
    'COMPATIBILITY_DUALRUN_WRITE' => 'false',
    'COMPATIBILITY_WRITES'        => 'legacy',
] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
}
if (!getenv('JWT_SECRET')) {
    putenv('JWT_SECRET=golden-master-harness');
}

// ---------------------------------------------------------------------------
// 2. Connect to the isolated scratch DB.
// ---------------------------------------------------------------------------
$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
$dbPass = getenv('GOLDEN_DB_PASS');
if ($dbPass === false) { $dbPass = ''; }
putenv("DB_HOST=$dbHost");
putenv("DB_NAME=$dbName");
putenv("DB_USER=$dbUser");
putenv("DB_PASS=$dbPass");

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "FLEET SWEEP: cannot connect to scratch DB '$dbName' on $dbHost as '$dbUser': " . $e->getMessage() . "\n");
    fwrite(STDERR, "Not swept this run -- no reports/shadow rows were generated, no diffs analyzed.\n");
    exit(1);
}

require_once $ROOT . '/core/models/server/ServerBuilder.php';
require_once $ROOT . '/core/models/compatibility/ComponentCompatibility.php';
require_once $ROOT . '/core/models/validation/Severity.php';

$builder = new ServerBuilder($pdo);
$compat  = new ComponentCompatibility($pdo);

function capture(callable $fn) {
    try {
        return ['ok' => true, 'value' => $fn()];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// 3. Note the shadow log's current size so we only analyze rows THIS sweep
//    appends (never conflating with any pre-existing shadow data for today).
// ---------------------------------------------------------------------------
$shadowFile = $ROOT . '/reports/shadow/engine-' . date('Ymd') . '.jsonl';
$startOffset = is_file($shadowFile) ? filesize($shadowFile) : 0;

// ---------------------------------------------------------------------------
// 4. Walk every configuration, replaying every persisted component's add --
//    identical extraction/replay mechanics to characterize_compatibility.php.
// ---------------------------------------------------------------------------
$rows = $pdo->query("SELECT * FROM server_configurations")->fetchAll(PDO::FETCH_ASSOC);
usort($rows, function ($a, $b) {
    $c = strcmp((string)($a['config_uuid'] ?? ''), (string)($b['config_uuid'] ?? ''));
    return $c !== 0 ? $c : ((int)$a['id'] <=> (int)$b['id']);
});

$replayCount = 0;
$exceptionCount = 0;
foreach ($rows as $row) {
    $configUuid = (string)($row['config_uuid'] ?? '');
    if ($configUuid === '') { continue; }

    $components = [];
    try {
        $components = $builder->extractComponentsFromJson($row);
    } catch (\Throwable $e) {
        continue; // same non-fatal posture as characterize_compatibility.php
    }

    foreach ($components as $c) {
        $type = $c['component_type']  ?? null;
        $uuid = $c['component_uuid']  ?? null;
        $qty  = $c['quantity']        ?? 1;
        $pnu  = $c['parent_nic_uuid'] ?? null;
        $pidx = $c['port_index']      ?? null;
        if ($type === null || $uuid === null) { continue; }

        $result = capture(function () use ($builder, $configUuid, $type, $uuid, $compat, $row, $pnu, $pidx, $qty) {
            // ENGINE_MODE=shadow: this call runs legacy AND the engine, logs
            // ShadowRunner::record(), and returns the legacy result (shadow
            // never changes what's returned to the caller).
            return $builder->validateComponentAddition($configUuid, $type, $uuid, $compat, $row, $pnu, $pidx, $qty);
        });
        if (!$result['ok']) {
            $exceptionCount++;
        }
        $replayCount++;
    }
}

fwrite(STDOUT, "fleet_parity_sweep: $replayCount replays across " . count($rows) . " configs (" . $exceptionCount . " threw)\n");

// ---------------------------------------------------------------------------
// 5. Analyze ONLY the rows this sweep appended (byte offset from step 3),
//    reusing parity_report.php's own comparison semantics (a diff = legacy
//    .blocked !== engine.blocked; expected iff it matches expected_diffs.json).
// ---------------------------------------------------------------------------
$newRows = [];
if (is_file($shadowFile)) {
    $fh = fopen($shadowFile, 'rb');
    fseek($fh, $startOffset);
    while (($line = fgets($fh)) !== false) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) { $newRows[] = $decoded; }
    }
    fclose($fh);
}

$expectedDiffsPath = __DIR__ . '/expected_diffs.json';
$expectedDiffs = [];
if (file_exists($expectedDiffsPath)) {
    $decoded = json_decode(file_get_contents($expectedDiffsPath), true);
    $expectedDiffs = is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [];
}

function fleetMatchesExpected(array $entry, array $row): bool {
    if ($entry['legacy_blocked'] !== $row['legacy']['blocked']) return false;
    if ($entry['engine_blocked'] !== $row['engine']['blocked']) return false;
    if (array_key_exists('engine_error_class', $entry) && $entry['engine_error_class'] !== null) {
        return $entry['engine_error_class'] === ($row['engine']['error_class'] ?? null);
    }
    return true;
}

$identical = 0;
$expected = [];
$unexplained = [];
foreach ($newRows as $row) {
    $legacyBlocked = $row['legacy']['blocked'] ?? null;
    $engineBlocked = $row['engine']['blocked'] ?? null;
    if ($legacyBlocked === null || $engineBlocked === null) { continue; }
    if ($legacyBlocked === $engineBlocked) { $identical++; continue; }

    $matched = null;
    foreach ($expectedDiffs as $entry) {
        if (fleetMatchesExpected($entry, $row)) { $matched = $entry; break; }
    }
    if ($matched !== null) {
        $expected[] = ['row' => $row, 'entry' => $matched];
    } else {
        $unexplained[] = $row;
    }
}

// ---------------------------------------------------------------------------
// 6. Triage report (checked-in reports/ dir, timestamped, never overwritten).
// ---------------------------------------------------------------------------
$reportsDir = $ROOT . '/reports';
if (!is_dir($reportsDir)) { mkdir($reportsDir, 0755, true); }
$reportFile = $reportsDir . '/fleet-parity-sweep-' . date('Ymd-His') . '.json';

$unexplainedByRule = [];
foreach ($unexplained as $row) {
    $rule = $row['engine']['blocked'] ? ($row['engine']['error_class'] ?? 'unknown') : ('legacy:' . ($row['legacy']['error_class'] ?? 'unknown'));
    $unexplainedByRule[$rule] = ($unexplainedByRule[$rule] ?? 0) + 1;
}

$green = count($unexplained) === 0;
file_put_contents($reportFile, json_encode([
    'report' => 'fleet_parity_sweep',
    'generated_at' => date('c'),
    'configs_walked' => count($rows),
    'replays' => $replayCount,
    'replay_exceptions' => $exceptionCount,
    'identical_verdicts' => $identical,
    'diffs_expected' => count($expected),
    'diffs_unexplained' => count($unexplained),
    'unexplained_by_rule' => $unexplainedByRule,
    'unexplained_rows' => $unexplained,
    'expected_rows' => $expected,
    'status' => $green ? 'GREEN' : 'RED',
    'note' => 'unexplained diffs are NOT auto-added to expected_diffs.json -- each one needs to be traced to a specific RULE_MAP.md intentional-diff row by a human/unit before it belongs there.',
], JSON_PRETTY_PRINT));

fwrite(STDOUT, "fleet_parity_sweep: identical=$identical expected=" . count($expected) . " unexplained=" . count($unexplained) . "\n");
if (!empty($unexplainedByRule)) {
    fwrite(STDOUT, "  unexplained by rule/class:\n");
    foreach ($unexplainedByRule as $rule => $n) {
        fwrite(STDOUT, "    $rule: $n\n");
    }
}
fwrite(STDOUT, "fleet_parity_sweep: " . ($green ? 'GREEN' : 'RED') . " $reportFile\n");
exit($green ? 0 : 1);
