<?php
/**
 * audit-orphans.php — scan every server_configurations row, extract every
 * component UUID / serial_number from its JSON columns, and verify each one
 * still exists in the matching {type}inventory table with a non-zero Status.
 *
 * Orphaned references (inventory row missing or Status=0) are reported.
 *
 * Usage:
 *   php scripts/audit-orphans.php           # dry run, prints report only
 *   php scripts/audit-orphans.php --fix     # also removes orphaned entries
 *                                           # from config JSON via ServerBuilder
 *                                           # (inventory rows are never touched)
 *
 * Exit code: 0 if no orphans (or all fixed), 1 if orphans found in dry-run.
 */

declare(strict_types=1);

// Resolve boot path regardless of where the script is invoked from.
$bootstrap = __DIR__ . '/../core/config/app.php';
if (!file_exists($bootstrap)) {
    fwrite(STDERR, "Cannot locate core/config/app.php from " . __DIR__ . "\n");
    exit(2);
}
require_once $bootstrap;

require_once __DIR__ . '/../core/models/server/ServerBuilder.php';

$fix = in_array('--fix', $argv, true);

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO connection not available after bootstrap.\n");
    exit(2);
}

$componentTables = [
    'cpu'         => 'cpuinventory',
    'ram'         => 'raminventory',
    'storage'     => 'storageinventory',
    'motherboard' => 'motherboardinventory',
    'chassis'     => 'chassisinventory',
    'nic'         => 'nicinventory',
    'caddy'       => 'caddyinventory',
    'pciecard'    => 'pciecardinventory',
    'hbacard'     => 'hbacardinventory',
    'sfp'         => 'sfpinventory',
];

/**
 * Extract [type, uuid, serial_number|null] tuples from a config row.
 * Mirrors the shape produced by ServerBuilder::extractComponentsFromJson()
 * but is standalone so this script can be run without pulling the full
 * compatibility stack.
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
            $refs[] = [
                'type'   => $type,
                'uuid'   => $entry['uuid'],
                'serial' => $entry['serial_number'] ?? null,
            ];
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

    if (!empty($row['motherboard_uuid'])) {
        $refs[] = ['type' => 'motherboard', 'uuid' => $row['motherboard_uuid'], 'serial' => null];
    }
    if (!empty($row['chassis_uuid'])) {
        $refs[] = ['type' => 'chassis', 'uuid' => $row['chassis_uuid'], 'serial' => null];
    }
    if (!empty($row['hbacard_uuid'])) {
        $refs[] = ['type' => 'hbacard', 'uuid' => $row['hbacard_uuid'], 'serial' => null];
    }

    return $refs;
}

$stmt = $pdo->query("SELECT * FROM server_configurations");
$configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRefs = 0;
$orphans = [];

foreach ($configs as $config) {
    // Skip synthetic/onboard NIC UUIDs — they don't exist in inventory by design.
    $refs = extractRefs($config);
    foreach ($refs as $ref) {
        $totalRefs++;

        if ($ref['type'] === 'nic' && strpos($ref['uuid'], 'onboard-') === 0) {
            continue;
        }

        $table = $componentTables[$ref['type']] ?? null;
        if ($table === null) continue;

        $where = "UUID = ?";
        $params = [$ref['uuid']];
        if ($ref['serial'] !== null) {
            $where .= " AND SerialNumber = ?";
            $params[] = $ref['serial'];
        }

        $check = $pdo->prepare("SELECT Status FROM `$table` WHERE $where LIMIT 1");
        $check->execute($params);
        $status = $check->fetchColumn();

        if ($status === false) {
            $orphans[] = [
                'config_uuid'  => $config['config_uuid'],
                'server_name'  => $config['server_name'],
                'type'         => $ref['type'],
                'uuid'         => $ref['uuid'],
                'serial'       => $ref['serial'],
                'reason'       => 'inventory_missing',
            ];
        } elseif ((int)$status === 0) {
            $orphans[] = [
                'config_uuid'  => $config['config_uuid'],
                'server_name'  => $config['server_name'],
                'type'         => $ref['type'],
                'uuid'         => $ref['uuid'],
                'serial'       => $ref['serial'],
                'reason'       => 'inventory_status_failed',
            ];
        }
    }
}

echo "Scanned " . count($configs) . " configurations, $totalRefs component references.\n";
echo "Orphans found: " . count($orphans) . "\n\n";

if (!empty($orphans)) {
    printf("%-38s %-30s %-12s %-38s %-20s %s\n",
        'CONFIG_UUID', 'SERVER_NAME', 'TYPE', 'UUID', 'SERIAL', 'REASON');
    echo str_repeat('-', 160) . "\n";
    foreach ($orphans as $o) {
        printf("%-38s %-30s %-12s %-38s %-20s %s\n",
            $o['config_uuid'],
            substr((string)$o['server_name'], 0, 30),
            $o['type'],
            $o['uuid'],
            (string)($o['serial'] ?? ''),
            $o['reason']
        );
    }
    echo "\n";
}

if (!$fix) {
    if (!empty($orphans)) {
        echo "Dry-run. Re-run with --fix to remove orphaned entries from config JSON.\n";
        exit(1);
    }
    exit(0);
}

// --fix mode: cascade-remove each orphan via ServerBuilder::removeComponent(),
// which holds the Phase 1 FOR UPDATE lock while mutating the JSON.
$sb = new ServerBuilder($pdo);
$removed = 0;
$failed = 0;
foreach ($orphans as $o) {
    try {
        $result = $sb->removeComponent($o['config_uuid'], $o['type'], $o['uuid'], $o['serial']);
        if (!empty($result['success'])) {
            $removed++;
            echo "FIXED: {$o['config_uuid']} / {$o['type']} / {$o['uuid']}\n";
        } else {
            $failed++;
            echo "SKIP:  {$o['config_uuid']} / {$o['type']} / {$o['uuid']} — " . ($result['message'] ?? 'unknown error') . "\n";
        }
    } catch (Throwable $e) {
        $failed++;
        echo "ERROR: {$o['config_uuid']} / {$o['type']} / {$o['uuid']} — " . $e->getMessage() . "\n";
    }
}

echo "\nRemoved: $removed  Failed/Skipped: $failed\n";
exit($failed > 0 ? 1 : 0);
