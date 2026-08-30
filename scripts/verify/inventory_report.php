<?php
/**
 * inventory_report.php — 11-verification/README.md #2.
 *
 * Green iff, per {type}inventory table:
 *   - no row with Status=2 (installed) and NULL ServerUUID
 *   - no row referenced by any server_configurations JSON while Status=1 (available)
 *   - no row where status_v2 IS NOT NULL disagrees with Status under
 *     StatusMap::INVENTORY_V2_TO_LEGACY (U-SM.3, once status_v2 exists — a
 *     row with status_v2 not present in that map at all is also a violation:
 *     it means something wrote a value the reverse map doesn't know, which
 *     can only happen via a bug since the ENUM itself constrains the column)
 *   - no row (and no server_configurations row) has status_v2 NULL at all, on a
 *     table that HAS the column — added 2026-07-28 for F-21. This tightens the
 *     report: the previous three checks were all satisfiable by a row that had
 *     never been migrated, which is how 22 inventory units and 8 configurations
 *     stayed invisible while this report was GREEN. Applying seeder
 *     2026_07_28_001 is now a precondition for green.
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
require_once __DIR__ . '/../../core/models/state/StatusMap.php';

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO connection not available after bootstrap.\n");
    exit(2);
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

const COMPONENT_TABLES = [
    'cpu' => 'cpuinventory', 'ram' => 'raminventory', 'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory', 'chassis' => 'chassisinventory', 'nic' => 'nicinventory',
    'caddy' => 'caddyinventory', 'pciecard' => 'pciecardinventory', 'risercard' => 'risercardinventory',
    'hbacard' => 'hbacardinventory',
    'sfp' => 'sfpinventory',
];

// Every table a config_components row may point at. `serverplatforminventory` is not
// in the map above — no component type inside a build is called "serverplatform" —
// but a platform-provisioned server claims its motherboard and chassis there.
const AUDITABLE_TABLES = [
    'cpuinventory', 'raminventory', 'storageinventory', 'motherboardinventory',
    'chassisinventory', 'nicinventory', 'caddyinventory', 'pciecardinventory',
    'risercardinventory', 'hbacardinventory', 'sfpinventory', 'serverplatforminventory',
];

function tableExists(PDO $pdo, string $table): bool {
    // SHOW TABLES isn't preparable under real (non-emulated) prepares — inline the quoted literal.
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return (bool)$stmt->fetch();
}

/**
 * Extract [type, uuid, serial, inventory_id] tuples referenced by a configuration.
 * Mirrors scripts/audit-orphans.php's extractRefs() so both reports agree on "referenced".
 *
 * U-D.3c (2026-08-30): the nine legacy JSON columns are dropped and `config_components`
 * is the only store. That upgrades Check 2 from a sampling heuristic to an exact one —
 * every row names its unit by `inventory_id`, so the model-vs-unit ambiguity documented
 * at the call site (finding F-2, model 4c8f5e1b) no longer has anywhere to occur.
 *
 * This function did NOT start erroring when the columns went: it read them through
 * `?? null`, so it would have returned an empty ref list and kept Check 2 green forever
 * while checking nothing. That is why it had to be repointed rather than left alone.
 */
function extractRefs(PDO $pdo, array $row): array {
    $refs = [];

    $stmt = $pdo->prepare(
        'SELECT component_type, spec_uuid, serial_number, inventory_id, inventory_table
           FROM config_components
          WHERE config_uuid = ? AND removed_at IS NULL
          ORDER BY id'
    );
    $stmt->execute([$row['config_uuid']]);
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $refs[] = ['type' => $r['component_type'], 'uuid' => $r['spec_uuid'],
                   'serial' => $r['serial_number'], 'inventory_id' => (int)$r['inventory_id'],
                   'table' => $r['inventory_table']];
        $seen[$r['component_type'] . '|' . $r['spec_uuid']] = true;
    }

    // The two surviving scalars. They name a MODEL, never a unit, so they keep the
    // old ServerUUID-scoped treatment below; skipped when a row already covers them.
    foreach (['motherboard' => 'motherboard_uuid', 'chassis' => 'chassis_uuid'] as $type => $col) {
        if (empty($row[$col]) || isset($seen[$type . '|' . $row[$col]])) continue;
        $refs[] = ['type' => $type, 'uuid' => $row[$col], 'serial' => null,
                   'inventory_id' => null, 'table' => null];
    }

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
    // Virtual configs are excluded (see migration/PLAN_VERIFICATION_REVIEW.md F-5):
    // they are sandbox data that reference component UUIDs without ever consuming
    // real inventory, by design — equivalence_report.php already excludes them.
    if (tableExists($pdo, 'server_configurations')) {
        $stmt = $pdo->query('SELECT * FROM server_configurations WHERE is_virtual = 0');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $config) {
            foreach (extractRefs($pdo, $config) as $ref) {
                if ($ref['type'] === 'nic' && strpos((string)$ref['uuid'], 'onboard-') === 0) continue;
                // The row names its own table, and it is not always the one the type
                // map predicts: a serverplatform-provisioned build records both its
                // motherboard and its chassis against `serverplatforminventory`, one
                // platform unit supplying both. Whitelisted, not trusted — the value
                // is interpolated into SQL and it comes out of a database column.
                $table = $ref['table'] ?? (COMPONENT_TABLES[$ref['type']] ?? null);
                if ($table === null || !in_array($table, AUDITABLE_TABLES, true)) continue;
                if (!tableExists($pdo, $table)) continue;

                // UUID is the MODEL/spec id — many physical units share it; SerialNumber
                // is the unit. When the config ref carries a serial we can match the unit
                // exactly. When it does not (only CPU refs carry one today), a bare
                // `UUID = ? LIMIT 1` samples an ARBITRARY unit of that model: it reports a
                // violation when some other unit happens to be available even though this
                // config's own unit is correctly installed, and conversely can mask a real
                // violation by sampling an in-use unit. Both directions were observed on
                // model 4c8f5e1b (2026-07-21, finding F-2).
                //
                // The question this check actually asks is "does a physical unit of this
                // model belong to THIS config, and is it marked in-use?" — so scope by
                // ServerUUID rather than sampling.
                //
                // U-D.3c: a config_components ref answers that question outright. It
                // carries the unit's row id, so ask about THAT row and nothing else —
                // no sampling, no ServerUUID proxy, and a mis-set ServerUUID can no
                // longer hide the violation by making the unit look unallocated.
                if ($ref['inventory_id'] !== null) {
                    $check = $pdo->prepare("SELECT Status FROM `$table` WHERE ID = ?");
                    $check->execute([$ref['inventory_id']]);
                    $status = $check->fetchColumn();
                    if ($status === false) {
                        // The row this configuration claims does not exist. That is an
                        // orphan, which audit-orphans.php owns; not this report's check.
                        continue;
                    }
                    $available = ((int)$status === 1);
                    $detail = 'unit #' . $ref['inventory_id'] . ' claimed by config '
                            . $config['config_uuid'] . ' is marked Status=1 (available)';
                } elseif ($ref['serial'] !== null) {
                    $check = $pdo->prepare("SELECT Status FROM `$table` WHERE UUID = ? AND SerialNumber = ? LIMIT 1");
                    $check->execute([$ref['uuid'], $ref['serial']]);
                    $status = $check->fetchColumn();
                    $available = ($status !== false && (int)$status === 1);
                    $detail = 'Status=1 (available) but referenced by config ' . $config['config_uuid'];
                } else {
                    // Any unit of this model actually allocated to this config?
                    $check = $pdo->prepare(
                        "SELECT Status FROM `$table`
                          WHERE UUID = ?
                            AND ServerUUID = ?
                          ORDER BY Status DESC LIMIT 1"
                    );
                    $check->execute([$ref['uuid'], $config['config_uuid']]);
                    $status = $check->fetchColumn();

                    if ($status === false) {
                        // Nothing of this model is allocated to this config at all.
                        $available = true;
                        $detail = 'referenced by config ' . $config['config_uuid']
                                . ' but NO physical unit of this model is allocated to it';
                    } else {
                        $available = ((int)$status === 1);
                        $detail = 'unit allocated to config ' . $config['config_uuid']
                                . ' is marked Status=1 (available)';
                    }
                }

                if ($available) {
                    $violations[] = [
                        'check' => 'referenced_while_available',
                        'type' => $ref['type'], 'table' => $table,
                        'uuid' => $ref['uuid'], 'serial' => $ref['serial'],
                        'config_uuid' => $config['config_uuid'],
                        'detail' => $detail,
                    ];
                }
            }
        }
    }

    // Check 3: status_v2 / Status mapping agreement (U-SM.3), only on tables
    // that already have the column (pre-U-SM.1-apply DBs just skip this).
    foreach (COMPONENT_TABLES as $type => $table) {
        if (!tableExists($pdo, $table) || !columnExists($pdo, $table, 'status_v2')) continue;
        $stmt = $pdo->query("SELECT UUID, SerialNumber, Status, status_v2 FROM `$table` WHERE status_v2 IS NOT NULL");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!array_key_exists($row['status_v2'], StatusMap::INVENTORY_V2_TO_LEGACY)) {
                $violations[] = [
                    'check' => 'status_v2_illegal_value',
                    'type' => $type, 'table' => $table,
                    'uuid' => $row['UUID'], 'serial' => $row['SerialNumber'],
                    'detail' => "status_v2='{$row['status_v2']}' has no entry in StatusMap::INVENTORY_V2_TO_LEGACY",
                ];
                continue;
            }
            $expectedLegacy = StatusMap::INVENTORY_V2_TO_LEGACY[$row['status_v2']];
            if ((int)$row['Status'] !== $expectedLegacy) {
                $violations[] = [
                    'check' => 'status_v2_legacy_mismatch',
                    'type' => $type, 'table' => $table,
                    'uuid' => $row['UUID'], 'serial' => $row['SerialNumber'],
                    'detail' => "status_v2='{$row['status_v2']}' expects Status=$expectedLegacy but found Status={$row['Status']}",
                ];
            }
        }
    }

    // Check 4: status_v2 populated at all. [F-21]
    //
    // Check 3 above inspects only rows WHERE status_v2 IS NOT NULL -- written that
    // way so a DB predating seeder 2026_07_10_001 would skip the column rather than
    // report every row as broken. But the three INSERT paths that create rows never
    // named status_v2, so rows kept being BORN NULL long after that seeder, and this
    // report excused every one of them: production held 21 cpu + 1 pciecard units and
    // 8 of 12 configurations with status_v2 NULL on 2026-07-27 while this report was
    // GREEN. NULL is not a member of StatusMap::INVENTORY_V2_TO_LEGACY, so
    // StateMachine::assert*Transition() fails closed on such a row -- every physical
    // configuration in the fleet was untransitionable and no gate said so.
    //
    // The skip condition is therefore per-COLUMN (does status_v2 exist here?), never
    // per-row. A table that has the column must have it populated on every row.
    // Seeder 2026_07_28_001 backfills the existing rows; the code fixes stop new ones.
    foreach (COMPONENT_TABLES as $type => $table) {
        if (!tableExists($pdo, $table) || !columnExists($pdo, $table, 'status_v2')) continue;
        $stmt = $pdo->query("SELECT UUID, SerialNumber, Status FROM `$table` WHERE status_v2 IS NULL");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $violations[] = [
                'check' => 'status_v2_missing',
                'type' => $type, 'table' => $table,
                'uuid' => $row['UUID'], 'serial' => $row['SerialNumber'],
                'detail' => "status_v2 IS NULL (legacy Status={$row['Status']}); the state machine cannot read this unit's state",
            ];
        }
    }

    if (tableExists($pdo, 'server_configurations') && columnExists($pdo, 'server_configurations', 'status_v2')) {
        $stmt = $pdo->query(
            'SELECT config_uuid, configuration_status, is_virtual FROM server_configurations WHERE status_v2 IS NULL'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $violations[] = [
                'check' => 'status_v2_missing',
                'type' => 'configuration', 'table' => 'server_configurations',
                'uuid' => $row['config_uuid'], 'serial' => null,
                'detail' => "status_v2 IS NULL (configuration_status={$row['configuration_status']}, "
                    . "is_virtual={$row['is_virtual']}); assertConfigTransition() refuses this config",
            ];
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

        // Check 2 fixture (added U-D.3c). The old self-test exercised Check 1 only,
        // which is exactly how Check 2 could have been repointed at a store it never
        // read and still reported PASS. This one seeds an available unit CLAIMED by a
        // live config_components row — the shape Check 2 exists to refuse — so the
        // self-test now fails if the reference walk goes blind again.
        $ref2Caught = null;
        if (tableExists($pdo, 'server_configurations') && tableExists($pdo, 'config_components')) {
            $ref2Uuid   = 'SELFTEST-REF-' . substr(md5(uniqid()), 0, 10);
            $ref2Config = 'selftest-' . substr(md5(uniqid()), 0, 24);
            $pdo->prepare("INSERT INTO `$table` (UUID, SerialNumber, Status, ServerUUID, Flag) VALUES (?, 'SELFTEST-REF', 1, NULL, 'TEMP-SELFTEST-INV')")
                ->execute([$ref2Uuid]);
            $ref2InvId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO server_configurations (config_uuid, server_name, is_virtual, configuration_status) VALUES (?, ?, 0, 0)')
                ->execute([$ref2Config, 'SELFTEST inventory_report']);
            $pdo->prepare("INSERT INTO config_components (config_uuid, component_type, inventory_table, inventory_id, spec_uuid, serial_number)
                           VALUES (?, 'ram', ?, ?, ?, 'SELFTEST-REF')")
                ->execute([$ref2Config, $table, $ref2InvId, $ref2Uuid]);
            $ref2Caught = false;
        }

        $result = runChecks($pdo);
        $caught = false;
        foreach ($result['violations'] as $v) {
            if (($v['check'] ?? null) === 'installed_without_server' && $v['uuid'] === $fixtureUuid) {
                $caught = true;
            }
            if ($ref2Caught !== null && ($v['check'] ?? null) === 'referenced_while_available'
                && ($v['config_uuid'] ?? null) === $ref2Config) {
                $ref2Caught = true;
            }
        }
        writeReport($result, true);

        $pdo->rollback();

        if ($ref2Caught === false) {
            echo "inventory_report --self-test: FAIL (Check 2 did not flag an available unit claimed by a live config_components row)\n";
            exit(0);
        }
        if ($caught) {
            echo "inventory_report --self-test: PASS (defect fixture correctly flagged"
               . ($ref2Caught === null ? '' : '; Check 2 fixture flagged too') . ")\n";
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
