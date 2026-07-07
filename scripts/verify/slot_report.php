<?php
/**
 * slot_report.php — 11-verification/README.md #4.
 *
 * Green iff, per non-virtual config:
 *   1. No duplicate slot_ref among live config_components rows (defensive
 *      double-check of uq_slot_occupancy — the DB unique key already blocks
 *      this at write time; this only catches a future write path that
 *      bypasses it).
 *   2. No duplicate (resource, slot_ref) among config_resources rows
 *      (defensive double-check of uq_discrete, same reasoning).
 *   3. Every discrete-resource consumption row (consumer_id IS NOT NULL AND
 *      slot_ref IS NOT NULL) has a LIVE provider — provider_id resolves to a
 *      config_components row that exists and is not tombstoned. provider_id
 *      is a hard FK (fk_cr_provider), so a row referencing a component that
 *      does not exist AT ALL is impossible by construction; the reachable
 *      defect is a provider tombstoned by a write path that (unlike
 *      ConfigComponentWriter::cleanupLedgerForRemove()) forgets to also
 *      clean up config_resources — see migration/handoffs/UL2-VERIFY-20260707.md's
 *      RV-3. Per migration/handoffs/U-L.2-20260707.md (RV-1/RV-2), no
 *      discrete consumer row can be produced via ConfigComponentWriter
 *      today at all — this check exists for when a follow-up unit
 *      implements discrete slot-consumer linking (and must keep RV-3 in
 *      mind when it does).
 *   4. No live config_components row of type nic/pciecard/hbacard has a NULL
 *      slot_ref (the "slotless card" class, audit A-8) — excluding onboard
 *      NICs (spec_uuid prefix "onboard-"), which legitimately have no
 *      discrete PCIe slot (mirrors equivalence_report.php's TODO_UB2 stance).
 *
 * Usage:
 *   php scripts/verify/slot_report.php                # scan all non-virtual configs
 *   php scripts/verify/slot_report.php --self-test     # seeds a discrete consumer
 *                                                        row, then tombstones its
 *                                                        provider component WITHOUT
 *                                                        running the normal cleanup
 *                                                        (raw SQL — provider_id's FK
 *                                                        makes a nonexistent provider
 *                                                        impossible by construction),
 *                                                        proves check 3 catches the
 *                                                        resulting dangling reference
 *
 * Exit: 0 = green, 1 = red (violations found, or self-test failed to detect
 * its induced defect), 2 = usage/setup error.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);

$bootstrap = $ROOT . '/core/config/app.php';
if (!file_exists($bootstrap)) {
    fwrite(STDERR, "Cannot locate core/config/app.php from " . __DIR__ . "\n");
    exit(2);
}
require_once $bootstrap;
require_once $ROOT . '/core/models/config/ConfigComponentRepository.php';

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO connection not available after bootstrap.\n");
    exit(2);
}

function isOnboardNic(string $type, ?string $specUuid): bool
{
    return $type === 'nic' && $specUuid !== null && strpos($specUuid, 'onboard-') === 0;
}

/**
 * @return array[] violations for a single config (empty if clean)
 */
function checkConfigSlots(PDO $pdo, string $configUuid): array
{
    $violations = [];

    $stmt = $pdo->prepare(
        'SELECT slot_ref, COUNT(*) c FROM config_components
         WHERE config_uuid = ? AND removed_at IS NULL AND slot_ref IS NOT NULL
         GROUP BY slot_ref HAVING c > 1'
    );
    $stmt->execute([$configUuid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $violations[] = ['type' => 'duplicate_component_slot_ref', 'slot_ref' => $row['slot_ref'], 'count' => (int)$row['c']];
    }

    $stmt = $pdo->prepare(
        'SELECT resource, slot_ref, COUNT(*) c FROM config_resources
         WHERE config_uuid = ? AND slot_ref IS NOT NULL
         GROUP BY resource, slot_ref HAVING c > 1'
    );
    $stmt->execute([$configUuid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $violations[] = ['type' => 'duplicate_resource_slot_ref', 'resource' => $row['resource'], 'slot_ref' => $row['slot_ref'], 'count' => (int)$row['c']];
    }

    $stmt = $pdo->prepare(
        'SELECT cr.id, cr.resource, cr.slot_ref, cr.provider_id, cr.consumer_id
         FROM config_resources cr
         LEFT JOIN config_components pc ON pc.id = cr.provider_id AND pc.removed_at IS NULL
         WHERE cr.config_uuid = ? AND cr.consumer_id IS NOT NULL AND cr.slot_ref IS NOT NULL AND pc.id IS NULL'
    );
    $stmt->execute([$configUuid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $violations[] = [
            'type' => 'consumer_missing_provider',
            'resource' => $row['resource'], 'slot_ref' => $row['slot_ref'],
            'provider_id' => (int)$row['provider_id'], 'consumer_id' => (int)$row['consumer_id'],
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT id, component_type, spec_uuid FROM config_components
         WHERE config_uuid = ? AND removed_at IS NULL AND slot_ref IS NULL
         AND component_type IN ('nic', 'pciecard', 'hbacard')"
    );
    $stmt->execute([$configUuid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isOnboardNic($row['component_type'], $row['spec_uuid'])) {
            continue;
        }
        $violations[] = ['type' => 'slotless_card', 'component_id' => (int)$row['id'], 'component_type' => $row['component_type']];
    }

    return $violations;
}

function writeReport(array $violations, int $configsScanned, string $mode): string
{
    $reportsDir = __DIR__ . '/../../reports';
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0755, true);
    }
    $file = $reportsDir . '/slot-' . date('Ymd-His') . '.json';
    file_put_contents($file, json_encode([
        'report'           => 'slot_report',
        'generated_at'     => date('c'),
        'mode'             => $mode,
        'configs_scanned'  => $configsScanned,
        'violation_count'  => count($violations),
        'violations'       => $violations,
        'status'           => empty($violations) ? 'GREEN' : 'RED',
    ], JSON_PRETTY_PRINT));
    return $file;
}

// -----------------------------------------------------------------------
// Self-test: seed a discrete consumer row pointing at a nonexistent
// provider, prove check 3 catches it. NOTE: exit() inside a try{} does not
// run finally{} in PHP (bug class already hit in equivalence_report.php),
// so cleanup + exit-code selection stay OUTSIDE the try/finally below.
// -----------------------------------------------------------------------
if (in_array('--self-test', $argv, true)) {
    $repo = new ConfigComponentRepository($pdo);
    $configUuid = 'SELFTEST-SLOT-' . substr(md5(uniqid()), 0, 8);
    $providerInventoryId = random_int(100000, 999999);
    $consumerInventoryId = random_int(100000, 999999);

    $caught = false;
    try {
        $pdo->prepare('INSERT INTO server_configurations (config_uuid, server_name, is_virtual, configuration_status) VALUES (?, ?, 0, 1)')
            ->execute([$configUuid, 'SLOT SELFTEST']);

        $pdo->beginTransaction();
        $providerId = $repo->insert($configUuid, [
            'component_type'  => 'motherboard',
            'inventory_table' => 'motherboardinventory',
            'inventory_id'    => $providerInventoryId,
            'spec_uuid'       => 'selftest-motherboard-uuid',
        ], 1);
        $consumerId = $repo->insert($configUuid, [
            'component_type'  => 'pciecard',
            'inventory_table' => 'pciecardinventory',
            'inventory_id'    => $consumerInventoryId,
            'spec_uuid'       => 'selftest-pciecard-uuid',
            'slot_ref'        => 'pcie_1_x16',
        ], 1);
        $pdo->commit();

        // ConfigComponentWriter never creates this shape itself (RV-2) — raw
        // SQL is the only way to induce a discrete link at all.
        $pdo->prepare(
            'INSERT INTO config_resources (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$configUuid, 'pcie_slot', $providerId, 'pcie_1_x16', 1, $consumerId]);

        // Tombstone the provider WITHOUT the normal cleanupLedgerForRemove()
        // pass, to induce the "provider no longer live" defect that a buggy
        // future write path (RV-3) could otherwise leave behind.
        $pdo->prepare('UPDATE config_components SET removed_at = NOW() WHERE id = ?')->execute([$providerId]);

        $violations = checkConfigSlots($pdo, $configUuid);
        $caught = (bool)array_filter($violations, fn($v) => $v['type'] === 'consumer_missing_provider');
        writeReport($caught ? $violations : [], 1, 'self-test');
    } finally {
        $pdo->exec("DELETE FROM config_resources WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid));
    }

    if ($caught) {
        echo "slot_report --self-test: PASS (induced defect correctly detected)\n";
        exit(1); // intentional: proves detection
    }
    echo "slot_report --self-test: FAIL (induced defect NOT detected — checker is broken)\n";
    exit(0);
}

// -----------------------------------------------------------------------
// Default: keyset-paginated scan of all non-virtual configs (F-5: virtual
// configs are never dual-written, mirrors equivalence_report.php).
// -----------------------------------------------------------------------
const BATCH_SIZE = 1000;
$cursor = '';
$allViolations = [];
$scanned = 0;

while (true) {
    $stmt = $pdo->prepare(
        'SELECT config_uuid FROM server_configurations
         WHERE is_virtual = 0 AND config_uuid > ?
         ORDER BY config_uuid
         LIMIT ' . BATCH_SIZE
    );
    $stmt->execute([$cursor]);
    $batch = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($batch)) {
        break;
    }

    foreach ($batch as $configUuid) {
        $scanned++;
        foreach (checkConfigSlots($pdo, $configUuid) as $v) {
            $v['config_uuid'] = $configUuid;
            $allViolations[] = $v;
        }
    }

    $cursor = end($batch);
    if (count($batch) < BATCH_SIZE) {
        break;
    }
}

$file = writeReport($allViolations, $scanned, 'all');
$status = empty($allViolations) ? 'GREEN' : 'RED';
echo "slot_report: $status $file\n";
exit(empty($allViolations) ? 0 : 1);
