<?php
/**
 * audit-orphans.php — scan every server_configurations row, take every component
 * it claims from `config_components`, and verify each one still exists in the
 * matching {type}inventory table with a non-zero Status.
 *
 * Orphaned references (inventory row missing or Status=0) are reported.
 *
 * Reads rows since U-D.3c dropped the legacy JSON columns (2026-08-30); see
 * extractRefs() for why that makes this audit stricter rather than weaker.
 *
 * Usage:
 *   php scripts/audit-orphans.php           # dry run, prints report only
 *   php scripts/audit-orphans.php --fix     # also removes orphaned entries
 *                                           # from the configuration via ServerBuilder
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
    'risercard'   => 'risercardinventory',
    'hbacard'     => 'hbacardinventory',
    'sfp'         => 'sfpinventory',
];

// Every table a config_components row is allowed to point at. `serverplatforminventory`
// is not in the type map above — no component type is called "serverplatform" inside a
// build — but a platform-provisioned server records its motherboard and chassis there,
// because one platform unit supplies both.
$auditableTables = array_merge(array_values($componentTables), ['serverplatforminventory']);

/**
 * Extract [type, uuid, serial_number|null, inventory_id|null] tuples for a config.
 *
 * U-D.3c (2026-08-30): this used to decode the nine legacy JSON columns. They are
 * dropped. The source is now `config_components`, one live row per physical unit —
 * which is a STRICTER audit, not a weaker one: each row carries the `inventory_id`
 * of the exact unit it claims, so a reference resolves to ONE inventory row instead
 * of "some row of this model". Under the old shape a serial-less entry matched
 * `UUID = ? LIMIT 1`, which reported green whenever any other unit of that model
 * happened to be healthy.
 *
 * Note the failure mode this replaced: `SELECT *` plus `?? null` meant the dropped
 * columns produced an EMPTY ref list rather than an error, so this audit would have
 * reported "0 orphans" forever without ever looking at anything.
 *
 * `motherboard_uuid` / `chassis_uuid` survive as scalars on server_configurations
 * and are still checked, by UUID only — they name a model, never a unit. They are
 * normally also present as config_components rows; the dedupe below keeps the
 * unit-level row, which is the more precise of the two.
 */
function extractRefs(PDO $pdo, array $row): array {
    $refs = [];

    $stmt = $pdo->prepare(
        "SELECT component_type, spec_uuid, serial_number, inventory_id, inventory_table
           FROM config_components
          WHERE config_uuid = ? AND removed_at IS NULL
          ORDER BY id"
    );
    $stmt->execute([$row['config_uuid']]);
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $refs[] = [
            'type'         => $r['component_type'],
            'uuid'         => $r['spec_uuid'],
            'serial'       => $r['serial_number'],
            'inventory_id' => (int)$r['inventory_id'],
            'table'        => $r['inventory_table'],
        ];
        $seen[$r['component_type'] . '|' . $r['spec_uuid']] = true;
    }

    foreach (['motherboard' => 'motherboard_uuid', 'chassis' => 'chassis_uuid'] as $type => $col) {
        if (empty($row[$col])) continue;
        if (isset($seen[$type . '|' . $row[$col]])) continue;
        $refs[] = ['type' => $type, 'uuid' => $row[$col], 'serial' => null,
                   'inventory_id' => null, 'table' => null];
    }

    return $refs;
}

// Virtual configs are sandbox data that intentionally reference component UUIDs
// without ever consuming real inventory (see migration/PLAN_VERIFICATION_REVIEW.md
// finding F-5) — equivalence_report.php already excludes them; this script and
// inventory_report.php's Check 2 did not, which produced false-positive orphans.
$stmt = $pdo->query("SELECT * FROM server_configurations WHERE is_virtual = 0");
$configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRefs = 0;
$orphans = [];

foreach ($configs as $config) {
    // Skip synthetic/onboard NIC UUIDs — they don't exist in inventory by design.
    $refs = extractRefs($pdo, $config);
    foreach ($refs as $ref) {
        $totalRefs++;

        if ($ref['type'] === 'nic' && strpos($ref['uuid'], 'onboard-') === 0) {
            continue;
        }

        // A config_components row names BOTH its table and its row id, so audit
        // exactly that row. Taking the table from the row rather than from the
        // type map matters: a serverplatform-provisioned build records its
        // motherboard and chassis against `serverplatforminventory` (the platform
        // unit IS both), which the type map has no entry for and the old
        // JSON-based walk could not see at all.
        //
        // The name is interpolated into SQL, so it is whitelisted rather than
        // trusted: it comes from a database column, and "our own code wrote it"
        // is not a property this script can verify.
        $table = $ref['table'] ?? ($componentTables[$ref['type']] ?? null);
        if ($table === null || !in_array($table, $auditableTables, true)) continue;

        if ($ref['inventory_id'] !== null) {
            $where  = "ID = ?";
            $params = [$ref['inventory_id']];
        } else {
            $where  = "UUID = ?";
            $params = [$ref['uuid']];
            if ($ref['serial'] !== null) {
                $where .= " AND SerialNumber = ?";
                $params[] = $ref['serial'];
            }
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
        echo "Dry-run. Re-run with --fix to remove orphaned entries from the configuration.\n";
        exit(1);
    }
    exit(0);
}

// --fix mode: cascade-remove each orphan via ServerBuilder::removeComponent(),
// which holds the Phase 1 FOR UPDATE lock on server_configurations while it
// tombstones the config_components row and releases the inventory unit.
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
