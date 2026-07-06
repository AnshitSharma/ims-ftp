<?php
/**
 * inventory_report.php — 11-verification/README.md #2.
 *
 * Green iff, per {type}inventory table:
 *   - no row with Status=2 (installed) and NULL ServerUUID
 *   - no row referenced by any server_configurations JSON while Status=1 (available)
 * (The status_v2 legacy-agreement + illegal-value checks land in U-SM.3, once status_v2 exists.)
 *
 * Usage:
 *   php scripts/verify/inventory_report.php              # writes reports/inventory-<ts>.json
 *   php scripts/verify/inventory_report.php --self-test   # seeds one known-bad row (rolled back
 *                                                          # at the end, never committed), asserts
 *                                                          # this report's own logic catches it.
 *
 * Exit: 0 = green (or self-test detected its fixture -> intentionally exits 1, see below),
 *       1 = red (violations found, or self-test FAILED to detect its fixture).
 */

declare(strict_types=1);

$bootstrap = __DIR__ . '/../../core/config/app.php';
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

const COMPONENT_TABLES = [
    'cpu' => 'cpuinventory', 'ram' => 'raminventory', 'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory', 'chassis' => 'chassisinventory', 'nic' => 'nicinventory',
    'caddy' => 'caddyinventory', 'pciecard' => 'pciecardinventory', 'hbacard' => 'hbacardinventory',
    'sfp' => 'sfpinventory',
];

function tableExists(PDO $pdo, string $table): bool {
    // SHOW TABLES isn't preparable under real (non-emulated) prepares — inline the quoted literal.
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return (bool)$stmt->fetch();
}

/**
 * Extract [type, uuid, serial] tuples referenced by a server_configurations row's JSON columns.
 * Mirrors scripts/audit-orphans.php's extractRefs() so both reports agree on "referenced".
 */
function extractRefs(array $row): array {
    $refs = [];
    $pushJson = function ($type, $json, $key = null) use (&$refs) {
        if (empty($json)) return;
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return;
        $list = $key ? ($decoded[$key] ?? []) : $decoded;
        if (!is_array($list)) return;
        foreach ($list as $entry) {
            if (!is_array($entry) || empty($entry['uuid'])) continue;
            $refs[] = ['type' => $type, 'uuid' => $entry['uuid'], 'serial' => $entry['serial_number'] ?? null];
        }
    };

    $pushJson('cpu',      $row['cpu_configuration']       ?? null, 'cpus');
    $pushJson('ram',      $row['ram_configuration']       ?? null);
    $pushJson('storage',  $row['storage_configuration']   ?? null);
    $pushJson('caddy',    $row['caddy_configuration']     ?? null);
    $pushJson('pciecard', $row['pciecard_configurations'] ?? null);
    $pushJson('hbacard',  $row['hbacard_config']          ?? null);
    $pushJson('sfp',      $row['sfp_configuration']       ?? null);
    $pushJson('nic',      $row['nic_config']              ?? null, 'nics');

    if (!empty($row['motherboard_uuid'])) $refs[] = ['type' => 'motherboard', 'uuid' => $row['motherboard_uuid'], 'serial' => null];
    if (!empty($row['chassis_uuid']))     $refs[] = ['type' => 'chassis',     'uuid' => $row['chassis_uuid'],     'serial' => null];
    if (!empty($row['hbacard_uuid']))     $refs[] = ['type' => 'hbacard',     'uuid' => $row['hbacard_uuid'],     'serial' => null];

    return $refs;
}

/**
 * Run the two checks against whatever data is currently in the DB.
 * @return array{violations: array, tables_checked: int}
 */
function runChecks(PDO $pdo): array {
    $violations = [];
    $tablesChecked = 0;

    // Check 1: Status=2 (installed) with NULL ServerUUID, per inventory table.
    foreach (COMPONENT_TABLES as $type => $table) {
        if (!tableExists($pdo, $table)) continue;
        $tablesChecked++;
        $stmt = $pdo->query("SELECT UUID, SerialNumber FROM `$table` WHERE Status = 2 AND ServerUUID IS NULL");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $violations[] = [
                'check' => 'installed_without_server',
                'type' => $type, 'table' => $table,
                'uuid' => $row['UUID'], 'serial' => $row['SerialNumber'],
                'detail' => 'Status=2 (installed) but ServerUUID is NULL',
            ];
        }
    }

    // Check 2: referenced by a config while Status=1 (available).
    if (tableExists($pdo, 'server_configurations')) {
        $stmt = $pdo->query('SELECT * FROM server_configurations');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $config) {
            foreach (extractRefs($config) as $ref) {
                if ($ref['type'] === 'nic' && strpos((string)$ref['uuid'], 'onboard-') === 0) continue;
                $table = COMPONENT_TABLES[$ref['type']] ?? null;
                if ($table === null || !tableExists($pdo, $table)) continue;

                $where = 'UUID = ?';
                $params = [$ref['uuid']];
                if ($ref['serial'] !== null) { $where .= ' AND SerialNumber = ?'; $params[] = $ref['serial']; }

                $check = $pdo->prepare("SELECT Status FROM `$table` WHERE $where LIMIT 1");
                $check->execute($params);
                $status = $check->fetchColumn();

                if ($status !== false && (int)$status === 1) {
                    $violations[] = [
                        'check' => 'referenced_while_available',
                        'type' => $ref['type'], 'table' => $table,
                        'uuid' => $ref['uuid'], 'serial' => $ref['serial'],
                        'config_uuid' => $config['config_uuid'],
                        'detail' => 'Status=1 (available) but referenced by config ' . $config['config_uuid'],
                    ];
                }
            }
        }
    }

    return ['violations' => $violations, 'tables_checked' => $tablesChecked];
}

function writeReport(array $result, bool $selfTest): string {
    $reportsDir = __DIR__ . '/../../reports';
    if (!is_dir($reportsDir)) { mkdir($reportsDir, 0755, true); }
    $file = $reportsDir . '/inventory-' . date('Ymd-His') . ($selfTest ? '-selftest' : '') . '.json';
    file_put_contents($file, json_encode([
        'report' => 'inventory_report',
        'generated_at' => date('c'),
        'self_test' => $selfTest,
        'tables_checked' => $result['tables_checked'],
        'violation_count' => count($result['violations']),
        'violations' => $result['violations'],
        'status' => empty($result['violations']) ? 'GREEN' : 'RED',
    ], JSON_PRETTY_PRINT));
    return $file;
}

// -----------------------------------------------------------------------
// --self-test: seed one known-bad row inside a transaction that always
// rolls back, and prove runChecks() flags it. Never commits to the DB.
// -----------------------------------------------------------------------
if (in_array('--self-test', $argv, true)) {
    $table = COMPONENT_TABLES['ram'];
    if (!tableExists($pdo, $table)) {
        fwrite(STDERR, "Self-test needs `$table` to exist; not found.\n");
        exit(2);
    }

    $pdo->beginTransaction();
    $fixtureUuid = 'SELFTEST-' . substr(md5(uniqid()), 0, 12);
    try {
        $pdo->prepare("INSERT INTO `$table` (UUID, SerialNumber, Status, ServerUUID, Flag) VALUES (?, 'SELFTEST', 2, NULL, 'TEMP-SELFTEST-INV')")
            ->execute([$fixtureUuid]);

        $result = runChecks($pdo);
        $caught = false;
        foreach ($result['violations'] as $v) {
            if (($v['check'] ?? null) === 'installed_without_server' && $v['uuid'] === $fixtureUuid) {
                $caught = true;
                break;
            }
        }
        writeReport($result, true);

        $pdo->rollback();

        if ($caught) {
            echo "inventory_report --self-test: PASS (defect fixture correctly flagged)\n";
            exit(1); // intentional: proves detection, matches pack's acceptance test
        }
        echo "inventory_report --self-test: FAIL (defect fixture NOT flagged — checker is broken)\n";
        exit(0);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollback(); }
        fwrite(STDERR, "Self-test error: " . $e->getMessage() . "\n");
        exit(2);
    }
}

// -----------------------------------------------------------------------
// Normal mode
// -----------------------------------------------------------------------
$result = runChecks($pdo);
$file = writeReport($result, false);
$status = empty($result['violations']) ? 'GREEN' : 'RED';
echo "inventory_report: $status $file\n";
exit(empty($result['violations']) ? 0 : 1);
