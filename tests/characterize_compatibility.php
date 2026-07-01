<?php
/**
 * characterize_compatibility.php — Phase 0 golden-master harness.
 *
 * PURPOSE
 *   Capture the CURRENT, byte-for-byte behaviour of the compatibility engine
 *   (bugs and all) for every real `server_configurations` row in the production
 *   dump, so that every later refactor step in COMPATIBILITY_CONSOLIDATION_PLAN.md
 *   can be proven at parity (or its intended diffs reviewed) against this baseline.
 *
 *   It changes NO production code and writes NOTHING to any database. It only
 *   reads `server_configurations` + inventory tables from an isolated scratch DB
 *   seeded from the production dump, and emits tests/golden/compatibility_baseline.json.
 *
 * WHAT IT CAPTURES, per server_configurations row:
 *   finalize-time:
 *     - ServerBuilder::validateConfiguration($configUuid)
 *     - ServerBuilder::validateConfigurationEnhanced($configUuid)
 *   add-time (replay):
 *     - For each component extracted from the persisted config JSON,
 *       ServerBuilder::validateComponentAddition($configUuid, $type, $uuid,
 *       $compatibility, $configData, $parentNicUuid, $portIndex, $quantity).
 *       NOTE: the replay runs against the PERSISTED configData (the component is
 *       already present). This is deterministic and exercises every authority on
 *       real stored state; it is NOT a from-scratch re-add.
 *
 * BEHAVIOUR FLAGS
 *   Loads ims-ftp/.env so the baseline reflects PRODUCTION validation behaviour
 *   (e.g. PCIE_LANE_CHECK_ENABLED=warn), then forces all dual-run / write flags to
 *   no-op so the harness can never mutate state, and overrides DB_* to the scratch DB.
 *
 * SEED + RUN  (see tests/golden/README.md for the full recipe):
 *   mysql -u root -e "DROP DATABASE IF EXISTS ims_compat_golden; CREATE DATABASE ims_compat_golden CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
 *   mysql -u root ims_compat_golden < imsbdcmsbharatda_Ims_Production.sql
 *   php ims-ftp/tests/characterize_compatibility.php
 *
 * Connection is overridable via GOLDEN_DB_HOST / GOLDEN_DB_NAME / GOLDEN_DB_USER /
 * GOLDEN_DB_PASS (defaults: 127.0.0.1 / ims_compat_golden / root / "").
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');        // keep stdout clean — JSON goes to the file
ini_set('serialize_precision', '-1');  // stable float encoding

$ROOT = dirname(__DIR__);              // ims-ftp/
$REPO = dirname($ROOT);               // repo root

// ---------------------------------------------------------------------------
// 1. Inherit production behaviour flags from ims-ftp/.env (verdict-affecting).
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
        if ((substr($v, 0, 1) === '"' && substr($v, -1) === '"') ||
            (substr($v, 0, 1) === "'" && substr($v, -1) === "'")) {
            $v = substr($v, 1, -1);
        }
        putenv("$k=$v");
        $_ENV[$k] = $v;
    }
}

// ---------------------------------------------------------------------------
// 2. Force read-only: no dual-run logging, no constraint-state writes.
// ---------------------------------------------------------------------------
foreach ([
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
// 3. Connect to the isolated scratch DB (overridable; XAMPP defaults).
// ---------------------------------------------------------------------------
$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
$dbPass = getenv('GOLDEN_DB_PASS');
if ($dbPass === false) {
    $dbPass = '';
}
// Override DB_* so any code path that reads them targets the scratch DB.
putenv("DB_HOST=$dbHost");
putenv("DB_NAME=$dbName");
putenv("DB_USER=$dbUser");
putenv("DB_PASS=$dbPass");

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: cannot connect to scratch DB '$dbName' on $dbHost as '$dbUser': " . $e->getMessage() . "\n");
    fwrite(STDERR, "Seed it first (see tests/golden/README.md).\n");
    exit(2);
}

require_once $ROOT . '/core/models/server/ServerBuilder.php';
require_once $ROOT . '/core/models/compatibility/ComponentCompatibility.php';

$builder = new ServerBuilder($pdo);
$compat  = new ComponentCompatibility($pdo);

/**
 * Invoke a closure and capture its result OR any Throwable, so a single broken
 * config can never abort the whole characterization run. The thrown class+message
 * IS part of the captured behaviour (the engine's fail-open posture is the point).
 */
function capture(callable $fn)
{
    try {
        return ['ok' => true, 'value' => $fn()];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// 4. Walk every configuration in a deterministic order.
// ---------------------------------------------------------------------------
$rows = $pdo->query("SELECT * FROM server_configurations")->fetchAll(PDO::FETCH_ASSOC);

usort($rows, function ($a, $b) {
    $c = strcmp((string)($a['config_uuid'] ?? ''), (string)($b['config_uuid'] ?? ''));
    return $c !== 0 ? $c : ((int)$a['id'] <=> (int)$b['id']);
});

$configurations = [];
$nonFatal       = [];
$replayCount    = 0;

foreach ($rows as $row) {
    $configUuid = (string)($row['config_uuid'] ?? '');

    $entry = [
        'meta' => [
            'id'                   => (int)$row['id'],
            'config_uuid'          => $row['config_uuid'],
            'server_name'          => $row['server_name'],
            'is_virtual'           => (int)$row['is_virtual'],
            'configuration_status' => (int)$row['configuration_status'],
        ],
        'finalize' => [
            'validateConfiguration'         => capture(function () use ($builder, $configUuid) {
                return $builder->validateConfiguration($configUuid);
            }),
            'validateConfigurationEnhanced' => capture(function () use ($builder, $configUuid) {
                return $builder->validateConfigurationEnhanced($configUuid);
            }),
        ],
        'add_time_replay' => [],
    ];

    $components = [];
    try {
        $components = $builder->extractComponentsFromJson($row);
    } catch (\Throwable $e) {
        $nonFatal[] = "extractComponentsFromJson($configUuid): " . $e->getMessage();
    }

    foreach ($components as $c) {
        $type = $c['component_type']  ?? null;
        $uuid = $c['component_uuid']  ?? null;
        $qty  = $c['quantity']        ?? 1;
        $pnu  = $c['parent_nic_uuid'] ?? null;
        $pidx = $c['port_index']      ?? null;

        $result = capture(function () use ($builder, $configUuid, $type, $uuid, $compat, $row, $pnu, $pidx, $qty) {
            return $builder->validateComponentAddition($configUuid, $type, $uuid, $compat, $row, $pnu, $pidx, $qty);
        });

        $entry['add_time_replay'][] = [
            'component_type'  => $type,
            'component_uuid'  => $uuid,
            'quantity'        => $qty,
            'parent_nic_uuid' => $pnu,
            'port_index'      => $pidx,
            'result'          => $result,
        ];
        $replayCount++;
    }

    // Key by config_uuid; fall back to id-keyed slot if a row has no uuid.
    $key = $configUuid !== '' ? $configUuid : ('__no_uuid_id_' . (int)$row['id']);
    $configurations[$key] = $entry;
}

ksort($configurations);

// ---------------------------------------------------------------------------
// 5. Serialize the baseline (stable, diffable — no timestamps in the artifact).
// ---------------------------------------------------------------------------
$out = [
    '_meta' => [
        'description'    => 'Golden-master characterization of the BDC IMS compatibility engine. '
            . 'Captures CURRENT verdicts (bugs included) for every server_configurations row in the '
            . 'production dump. Regenerate with tests/characterize_compatibility.php after re-seeding '
            . 'the scratch DB. See tests/golden/README.md. DO NOT hand-edit.',
        'generator'      => 'ims-ftp/tests/characterize_compatibility.php',
        'source_dump'    => 'imsbdcmsbharatda_Ims_Production.sql',
        'scratch_db'     => $dbName,
        'config_count'   => count($configurations),
        'replay_count'   => $replayCount,
        'behavior_flags' => [
            'PCIE_LANE_CHECK_ENABLED'     => getenv('PCIE_LANE_CHECK_ENABLED') ?: null,
            'COMPATIBILITY_READS'         => getenv('COMPATIBILITY_READS') ?: null,
            'COMPATIBILITY_WRITES'        => getenv('COMPATIBILITY_WRITES') ?: null,
            'COMPATIBILITY_DUALRUN_LOG'   => getenv('COMPATIBILITY_DUALRUN_LOG') ?: null,
            'COMPATIBILITY_DUALRUN_WRITE' => getenv('COMPATIBILITY_DUALRUN_WRITE') ?: null,
        ],
    ],
    'configurations' => $configurations,
];

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, "FATAL: json_encode failed: " . json_last_error_msg() . "\n");
    exit(3);
}

$outDir = __DIR__ . '/golden';
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "FATAL: cannot create $outDir\n");
    exit(4);
}
$outFile = $outDir . '/compatibility_baseline.json';
file_put_contents($outFile, $json . "\n");

// ---------------------------------------------------------------------------
// 6. Human summary to stdout (the artifact itself is the file above).
// ---------------------------------------------------------------------------
fwrite(STDOUT, "Golden master written: $outFile\n");
fwrite(STDOUT, "  configurations : " . count($configurations) . "\n");
fwrite(STDOUT, "  add-time replays: $replayCount\n");
if ($nonFatal) {
    fwrite(STDOUT, "  non-fatal notes : " . count($nonFatal) . "\n");
    foreach ($nonFatal as $n) {
        fwrite(STDOUT, "    - $n\n");
    }
}
exit(0);
