<?php
/**
 * performance_report.php — 11-verification/README.md #7.
 *
 * Replays the real-component scenarios from tests/fixture_scenarios_real.php (same shapes,
 * same operations: validateComponentAddition for adds, validateConfiguration +
 * validateConfigurationEnhanced for finalize), records wall-time p50/p95 per operation
 * ("add" vs "finalize"), and compares against reports/perf-baseline.json.
 *
 * Unlike tests/fixture_scenarios_real.php (which hardcodes the production DB name), this
 * script honors GOLDEN_DB_HOST / GOLDEN_DB_NAME / GOLDEN_DB_USER / GOLDEN_DB_PASS so it can
 * run against any scratch DB seeded with the real component catalog.
 *
 * Usage:
 *   php scripts/verify/performance_report.php                    # compare vs baseline
 *   php scripts/verify/performance_report.php --capture-baseline  # (re)write reports/perf-baseline.json
 *                                                                  # (single pass, R1-R10 as-is: 8 add / 2
 *                                                                  # finalize samples -- fine for a first
 *                                                                  # capture, too small to re-bless a
 *                                                                  # baseline other numbers get compared
 *                                                                  # against later, see --rebless below)
 *   php scripts/verify/performance_report.php --rebless --confirm  # RE-BLESS: replays R1-R10 enough times
 *                                                                  # to reach >=50 add / >=20 finalize
 *                                                                  # samples, then overwrites
 *                                                                  # reports/perf-baseline.json. Requires
 *                                                                  # BOTH flags -- `--rebless` alone
 *                                                                  # refuses (exit 2, baseline untouched)
 *                                                                  # so this can never fire by accident
 *                                                                  # (e.g. a copy-pasted `--rebless`
 *                                                                  # without reading what it does).
 *
 * Green iff p95 delta <= +20% for every operation. Exit: 0 = green, 1 = red.
 * --capture-baseline / --rebless always exit 0 on success (they define the baseline, nothing to
 * compare against yet); --rebless without --confirm exits 2 and writes nothing.
 *
 * Re-blessing changes what every future run of this report is graded against -- it is a deliberate,
 * reviewed action, not something a verify/implementer session runs on itself. See
 * migration/05-command-layer/PERF-BASELINE-REBLESS.md for the quiet-machine capture procedure this
 * flag assumes (machine state affects wall-clock timings; a rebless captured on a noisy machine
 * produces a baseline every future run will unfairly beat or fail against).
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
$dbPass = getenv('GOLDEN_DB_PASS');
if ($dbPass === false) { $dbPass = ''; }
$dbSocket = getenv('GOLDEN_DB_SOCKET') ?: null;

$dsn = $dbSocket
    ? "mysql:unix_socket=$dbSocket;dbname=$dbName;charset=utf8mb4"
    : "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

putenv("DB_HOST=$dbHost"); putenv("DB_NAME=$dbName"); putenv("DB_USER=$dbUser"); putenv("DB_PASS=$dbPass");

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: cannot connect to scratch DB '$dbName' on $dbHost: " . $e->getMessage() . "\n");
    exit(2);
}

require_once $ROOT . '/core/models/server/ServerBuilder.php';
require_once $ROOT . '/core/models/compatibility/ComponentCompatibility.php';

$builder = new ServerBuilder($pdo);
$compat = new ComponentCompatibility($pdo);

// Same real-catalog UUIDs as tests/fixture_scenarios_real.php.
$C = [
    'MB_3647'   => ['motherboard', 'd8e9f0a1-b2c3-4d4e-bf6a-7b8c9d0e1f2a'],
    'CPU_3647'  => ['cpu', '980bd035-0b5c-40aa-9329-d5088a036ae0'],
    'CPU_4189'  => ['cpu', '3001f095-9a50-44e5-92c5-b46310160e90'],
    'RAM_D4_RD' => ['ram', 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c'],
    'RAM_D5_RD' => ['ram', 'a1b2c3d4-e5f6-7890-1234-567890abcdef'],
    'RAM_D4_UD' => ['ram', 'debda7e8-b44a-4633-97d2-38ad264dec7b'],
    'NIC_SFPP'  => ['nic', 'da6c533b-7475-4364-989c-f6c7dd442efa'],
    'SFP_10G'   => ['sfp', '32bc2712-98a6-421f-85f5-4efb68e4ee00'],
    'SFP_25G'   => ['sfp', '0035b99b-6a00-4a80-afad-134a0393601f'],
    'SFP_1G'    => ['sfp', '4c2f2f42-aa7b-4d8d-848b-103d8e37fd1d'],
    'CHS_2BAY'  => ['chassis', 'a8f3b25d-4f1c-4b95-a3b0-fc30f5b12da8'],
    'CHS_BIG'   => ['chassis', 'b8106f02-636e-40cc-ba7f-baa5e23ecb53'],
    'ST_SSD25'  => ['storage', 'a3b4c5d6-e7f8-a9b0-c1d2-e3f4a5b6c7d8'],
];
$tables = ['cpu' => 'cpuinventory', 'motherboard' => 'motherboardinventory', 'ram' => 'raminventory',
    'storage' => 'storageinventory', 'nic' => 'nicinventory', 'sfp' => 'sfpinventory', 'chassis' => 'chassisinventory'];
function tableExists(PDO $pdo, string $table): bool {
    // SHOW TABLES isn't preparable under real (non-emulated) prepares — inline the quoted literal.
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return (bool)$stmt->fetch();
}

function jcpus($u) { return json_encode(['cpus' => [['uuid' => $u, 'quantity' => 1, 'serial_number' => 'TMP']]]); }
function jarr($us) { $a = []; foreach ($us as $u) { $a[] = ['uuid' => $u, 'quantity' => 1]; } return json_encode($a); }
function jnics($u) { return json_encode(['nics' => [['uuid' => $u, 'source_type' => 'component', 'status' => 'in_use', 'specifications' => ['ports' => 2, 'port_type' => 'SFP+']]]]); }

function insertRow(PDO $pdo, string $cfg, array $cols): array {
    $base = ['config_uuid' => $cfg, 'server_name' => 'PERF ' . $cfg, 'is_virtual' => 0, 'configuration_status' => 0,
        'motherboard_uuid' => null, 'chassis_uuid' => null, 'cpu_configuration' => null, 'ram_configuration' => null,
        'storage_configuration' => null, 'caddy_configuration' => null, 'nic_config' => null, 'sfp_configuration' => null,
        'hbacard_config' => null, 'pciecard_configurations' => null];
    $row = array_merge($base, $cols);
    $f = array_keys($row);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')->execute($row);
    $s = $pdo->prepare('SELECT * FROM server_configurations WHERE config_uuid = ?');
    $s->execute([$cfg]);
    return $s->fetch();
}

// Same 10 real scenarios as tests/fixture_scenarios_real.php (R1-R10): [id, setup, action, expected].
$SCENARIOS = [
    ['R1 cpu-socket-match', fn($U) => ['motherboard_uuid' => $U['MB_3647']], ['cpu', fn($U) => $U['CPU_3647']]],
    ['R2 cpu-socket-mismatch', fn($U) => ['motherboard_uuid' => $U['MB_3647']], ['cpu', fn($U) => $U['CPU_4189']]],
    ['R3 ram-type-match', fn($U) => ['motherboard_uuid' => $U['MB_3647'], 'cpu_configuration' => jcpus($U['CPU_3647'])], ['ram', fn($U) => $U['RAM_D4_RD']]],
    ['R4 ram-ddr-mismatch', fn($U) => ['motherboard_uuid' => $U['MB_3647']], ['ram', fn($U) => $U['RAM_D5_RD']]],
    ['R5 ram-module-mix', fn($U) => ['motherboard_uuid' => $U['MB_3647'], 'ram_configuration' => jarr([$U['RAM_D4_RD']])], ['ram', fn($U) => $U['RAM_D4_UD']]],
    ['R6 sfp-cage-match', fn($U) => ['motherboard_uuid' => $U['MB_3647'], 'nic_config' => jnics($U['NIC_SFPP'])], ['sfp', fn($U) => $U['SFP_10G'], fn($U) => $U['NIC_SFPP'], 1]],
    ['R7 sfp-cage-mismatch', fn($U) => ['motherboard_uuid' => $U['MB_3647'], 'nic_config' => jnics($U['NIC_SFPP'])], ['sfp', fn($U) => $U['SFP_25G'], fn($U) => $U['NIC_SFPP'], 1]],
    ['R8 sfp-1g-into-sfpplus', fn($U) => ['motherboard_uuid' => $U['MB_3647'], 'nic_config' => jnics($U['NIC_SFPP'])], ['sfp', fn($U) => $U['SFP_1G'], fn($U) => $U['NIC_SFPP'], 1]],
    ['R9 bay-overflow(final)', fn($U) => ['motherboard_uuid' => $U['MB_3647'], 'chassis_uuid' => $U['CHS_2BAY'], 'cpu_configuration' => jcpus($U['CPU_3647']), 'storage_configuration' => jarr([$U['ST_SSD25'], $U['ST_SSD25'], $U['ST_SSD25']])], 'finalize'],
    ['R10 full-build(final)', fn($U) => ['motherboard_uuid' => $U['MB_3647'], 'chassis_uuid' => $U['CHS_BIG'], 'cpu_configuration' => jcpus($U['CPU_3647']), 'ram_configuration' => jarr([$U['RAM_D4_RD'], $U['RAM_D4_RD']]), 'storage_configuration' => jarr([$U['ST_SSD25']]), 'nic_config' => jnics($U['NIC_SFPP'])], 'finalize'],
];

function percentile(array $sorted, float $p): float {
    if (empty($sorted)) return 0.0;
    $idx = (int)ceil($p * count($sorted)) - 1;
    $idx = max(0, min(count($sorted) - 1, $idx));
    return $sorted[$idx];
}

/** @return array{timings: array<string,float[]>, errors: int} */
function replayScenarios(PDO $pdo, ServerBuilder $builder, ComponentCompatibility $compat, array $scenarios, array $C, array $tables): array {
    $U = [];
    foreach ($C as $k => $v) { $U[$k] = $v[1]; }

    // Seed a temp inventory row for every real UUID this replay needs, mirroring
    // tests/fixture_scenarios_real.php (only if no row for that UUID exists yet).
    foreach ($tables as $t) {
        if (tableExists($pdo, $t)) { $pdo->exec("DELETE FROM `$t` WHERE Flag = 'TEMP-PERF-PROBE'"); }
    }
    foreach ($C as $k => [$type, $uuid]) {
        $tbl = $tables[$type] ?? null;
        if ($tbl === null || !tableExists($pdo, $tbl)) { continue; }
        $n = $pdo->prepare("SELECT COUNT(*) c FROM `$tbl` WHERE UUID = ?");
        $n->execute([$uuid]);
        if ((int)$n->fetch()['c'] === 0) {
            $extra = $type === 'nic' ? ', `SourceType`' : '';
            $extraV = $type === 'nic' ? ", 'component'" : '';
            $pdo->prepare("INSERT INTO `$tbl` (`UUID`, `SerialNumber`, `Status`, `Flag`$extra) VALUES (?, ?, 1, 'TEMP-PERF-PROBE'$extraV)")
                ->execute([$uuid, 'TEMP-' . $k]);
        }
    }

    $timings = ['add' => [], 'finalize' => []];
    $errors = 0;

    if (tableExists($pdo, 'server_configurations')) {
        $pdo->exec("DELETE FROM server_configurations WHERE config_uuid LIKE 'TESTPERF-%'");
    }

    foreach ($scenarios as $i => $sc) {
        [$id, $colsFn, $action] = $sc;
        $cfg = 'TESTPERF-R' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);

        try {
            $row = insertRow($pdo, $cfg, $colsFn($U));
            $start = hrtime(true);
            if ($action === 'finalize') {
                $r1 = $builder->validateConfiguration($cfg);
                $r2 = $builder->validateConfigurationEnhanced($cfg);
                $op = 'finalize';
            } else {
                [$t, $uF, $pF, $pi] = array_pad($action, 4, null);
                $parent = $pF ? $pF($U) : null;
                $r = $builder->validateComponentAddition($cfg, $t, $uF($U), $compat, $row, $parent, $pi, 1);
                $op = 'add';
            }
            $elapsedMs = (hrtime(true) - $start) / 1e6;
            $timings[$op][] = $elapsedMs;
        } catch (\Throwable $e) {
            $errors++;
        } finally {
            if (tableExists($pdo, 'server_configurations')) {
                $pdo->exec('DELETE FROM server_configurations WHERE config_uuid = ' . $pdo->quote($cfg));
            }
        }
    }

    return ['timings' => $timings, 'errors' => $errors];
}

$captureBaseline = in_array('--capture-baseline', $argv, true);
$rebless = in_array('--rebless', $argv, true);
$confirmed = in_array('--confirm', $argv, true);
$reportsDir = $ROOT . '/reports';
if (!is_dir($reportsDir)) { mkdir($reportsDir, 0755, true); }
$baselineFile = $reportsDir . '/perf-baseline.json';

if ($rebless && !$confirmed) {
    fwrite(STDERR, "performance_report: --rebless refuses to run without --confirm (this overwrites reports/perf-baseline.json, which every future performance_report.php run is graded against).\n");
    fwrite(STDERR, "Read migration/05-command-layer/PERF-BASELINE-REBLESS.md first (quiet-machine capture procedure), then re-run with: php scripts/verify/performance_report.php --rebless --confirm\n");
    exit(2);
}

const REBLESS_MIN_ADD_SAMPLES = 50;
const REBLESS_MIN_FINALIZE_SAMPLES = 20;

if ($rebless) {
    $addPerPass = count(array_filter($SCENARIOS, fn($s) => is_array($s[2])));
    $finalizePerPass = count($SCENARIOS) - $addPerPass;
    $iterations = max(
        (int)ceil(REBLESS_MIN_ADD_SAMPLES / max($addPerPass, 1)),
        (int)ceil(REBLESS_MIN_FINALIZE_SAMPLES / max($finalizePerPass, 1))
    );
    $timings = ['add' => [], 'finalize' => []];
    $errors = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $pass = replayScenarios($pdo, $builder, $compat, $SCENARIOS, $C, $tables);
        foreach ($pass['timings'] as $op => $samples) { $timings[$op] = array_merge($timings[$op], $samples); }
        $errors += $pass['errors'];
    }
    foreach ($tables as $t) {
        if (tableExists($pdo, $t)) { $pdo->exec("DELETE FROM `$t` WHERE Flag = 'TEMP-PERF-PROBE'"); }
    }
    $replay = ['timings' => $timings, 'errors' => $errors];
} else {
    $replay = replayScenarios($pdo, $builder, $compat, $SCENARIOS, $C, $tables);
    foreach ($tables as $t) {
        if (tableExists($pdo, $t)) { $pdo->exec("DELETE FROM `$t` WHERE Flag = 'TEMP-PERF-PROBE'"); }
    }
}

$stats = [];
foreach ($replay['timings'] as $op => $samples) {
    sort($samples);
    $stats[$op] = [
        'samples' => count($samples),
        'p50_ms' => round(percentile($samples, 0.50), 3),
        'p95_ms' => round(percentile($samples, 0.95), 3),
    ];
}

if ($captureBaseline || $rebless) {
    file_put_contents($baselineFile, json_encode([
        'captured_at' => date('c'),
        'note' => $rebless
            ? 'p95/p50 wall-time per operation, RE-BLESSED via --rebless --confirm: R1-R10 replayed ' . $iterations . 'x to reach >=' . REBLESS_MIN_ADD_SAMPLES . ' add / >=' . REBLESS_MIN_FINALIZE_SAMPLES . ' finalize samples (see migration/05-command-layer/PERF-BASELINE-REBLESS.md for the capture procedure this run assumed)'
            : 'p95/p50 wall-time per operation, replaying the R1-R10 real-component scenarios from tests/fixture_scenarios_real.php (single pass — small sample count, fine for an initial capture only)',
        'errors' => $replay['errors'],
        'operations' => $stats,
    ], JSON_PRETTY_PRINT));
    echo ($rebless ? "performance_report: BASELINE RE-BLESSED ($iterations passes) " : "performance_report: BASELINE CAPTURED ") . "$baselineFile\n";
    foreach ($stats as $op => $s) {
        echo "  $op: p50={$s['p50_ms']}ms p95={$s['p95_ms']}ms (n={$s['samples']})\n";
    }
    if ($replay['errors'] > 0) {
        echo "  ({$replay['errors']} scenario(s) errored — timed anyway, see errors count)\n";
    }
    exit(0);
}

if (!is_file($baselineFile)) {
    fwrite(STDERR, "No baseline at $baselineFile — run with --capture-baseline first.\n");
    exit(2);
}
$baseline = json_decode(file_get_contents($baselineFile), true);

$violations = [];
foreach ($stats as $op => $s) {
    $basePs95 = $baseline['operations'][$op]['p95_ms'] ?? null;
    if ($basePs95 === null || $basePs95 <= 0) { continue; }
    $deltaPct = (($s['p95_ms'] - $basePs95) / $basePs95) * 100;
    if ($deltaPct > 20.0) {
        $violations[] = ['operation' => $op, 'baseline_p95_ms' => $basePs95, 'current_p95_ms' => $s['p95_ms'], 'delta_pct' => round($deltaPct, 1)];
    }
}

$reportFile = $reportsDir . '/performance-' . date('Ymd-His') . '.json';
file_put_contents($reportFile, json_encode([
    'report' => 'performance_report',
    'generated_at' => date('c'),
    'baseline_file' => 'reports/perf-baseline.json',
    'current' => $stats,
    'baseline' => $baseline['operations'] ?? null,
    'errors' => $replay['errors'],
    'violations' => $violations,
    'status' => empty($violations) ? 'GREEN' : 'RED',
], JSON_PRETTY_PRINT));

$status = empty($violations) ? 'GREEN' : 'RED';
echo "performance_report: $status $reportFile\n";
exit(empty($violations) ? 0 : 1);
