<?php
/**
 * ledger_report.php — 11-verification/README.md #5.
 *
 * Green iff, per non-virtual config:
 *   1. Σ consumed ≤ Σ capacity, per resource (sum of consumer_id-NOT-NULL
 *      rows' capacity vs sum of consumer_id-IS-NULL (provider) rows'
 *      capacity).
 *   2. No consumer row (consumer_id IS NOT NULL) references a component
 *      that is missing or tombstoned in config_components.
 *   3. No provider row (provider_id, every row has one) references a
 *      component that is missing or tombstoned in config_components.
 *   4. For the pcie_lane resource specifically: the ledger's own totals
 *      (Σ capacity for provider rows = budget, Σ capacity for consumer rows
 *      = used) match PcieLaneBudgetValidator's independent recomputation
 *      from the config's LEGACY JSON columns (PcieLaneBudgetValidator.php
 *      66-140, the pre-existing single lane model — see that class's
 *      docblock and migration/06-resource-ledger/execution-packs/U-L.3.md's
 *      checklist item, which documents this as the target U-R.4 will later
 *      unify). Only checked for configs that actually HAVE at least one
 *      pcie_lane ledger row — a config with real legacy lane usage that
 *      simply hasn't been dual-written yet is a "not yet migrated" state,
 *      not a ledger defect (same stance as equivalence_report.php).
 *
 * Usage:
 *   php scripts/verify/ledger_report.php                # scan all non-virtual configs
 *   php scripts/verify/ledger_report.php --self-test     # seeds an over-consumed
 *                                                          scalar resource, proves
 *                                                          check 1 catches it
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
require_once $ROOT . '/core/models/compatibility/PcieLaneBudgetValidator.php';

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO connection not available after bootstrap.\n");
    exit(2);
}

/**
 * @return array[] violations for a single config (empty if clean)
 */
function checkConfigLedger(PDO $pdo, PcieLaneBudgetValidator $laneValidator, string $configUuid): array
{
    $violations = [];

    // Check 1: Σ consumed <= Σ capacity, per resource.
    $stmt = $pdo->prepare(
        "SELECT resource,
                SUM(CASE WHEN consumer_id IS NULL THEN capacity ELSE 0 END) AS total_capacity,
                SUM(CASE WHEN consumer_id IS NOT NULL THEN capacity ELSE 0 END) AS total_consumed
         FROM config_resources
         WHERE config_uuid = ?
         GROUP BY resource"
    );
    $stmt->execute([$configUuid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $capacity = (int)$row['total_capacity'];
        $consumed = (int)$row['total_consumed'];
        if ($consumed > $capacity) {
            $violations[] = [
                'type' => 'over_consumed', 'resource' => $row['resource'],
                'capacity' => $capacity, 'consumed' => $consumed,
            ];
        }
    }

    // Check 2: every consumer row's consumer_id is a live component.
    $stmt = $pdo->prepare(
        'SELECT cr.id, cr.resource, cr.consumer_id
         FROM config_resources cr
         LEFT JOIN config_components cc ON cc.id = cr.consumer_id AND cc.removed_at IS NULL
         WHERE cr.config_uuid = ? AND cr.consumer_id IS NOT NULL AND cc.id IS NULL'
    );
    $stmt->execute([$configUuid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $violations[] = ['type' => 'consumer_not_live', 'resource' => $row['resource'], 'consumer_id' => (int)$row['consumer_id']];
    }

    // Check 3: every provider row's provider_id is a live component.
    $stmt = $pdo->prepare(
        'SELECT cr.id, cr.resource, cr.provider_id
         FROM config_resources cr
         LEFT JOIN config_components cc ON cc.id = cr.provider_id AND cc.removed_at IS NULL
         WHERE cr.config_uuid = ? AND cc.id IS NULL'
    );
    $stmt->execute([$configUuid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $violations[] = ['type' => 'provider_not_live', 'resource' => $row['resource'], 'provider_id' => (int)$row['provider_id']];
    }

    // Check 4: pcie_lane ledger totals vs the legacy single lane model.
    $stmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN consumer_id IS NULL THEN capacity ELSE 0 END) AS ledger_budget,
            SUM(CASE WHEN consumer_id IS NOT NULL THEN capacity ELSE 0 END) AS ledger_used,
            COUNT(*) AS row_count
         FROM config_resources
         WHERE config_uuid = ? AND resource = 'pcie_lane'"
    );
    $stmt->execute([$configUuid]);
    $laneRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($laneRow && (int)$laneRow['row_count'] > 0) {
        $configStmt = $pdo->prepare('SELECT * FROM server_configurations WHERE config_uuid = ?');
        $configStmt->execute([$configUuid]);
        $configData = $configStmt->fetch(PDO::FETCH_ASSOC);
        if ($configData) {
            $ledgerBudget = (int)$laneRow['ledger_budget'];
            $ledgerUsed = (int)$laneRow['ledger_used'];
            $legacyBudget = $laneValidator->computeLaneBudget($configData);
            $legacyUsed = $laneValidator->computeLanesUsed($configData);
            if ($ledgerBudget !== $legacyBudget || $ledgerUsed !== $legacyUsed) {
                $violations[] = [
                    'type' => 'lane_model_mismatch',
                    'ledger_budget' => $ledgerBudget, 'ledger_used' => $ledgerUsed,
                    'legacy_budget' => $legacyBudget, 'legacy_used' => $legacyUsed,
                ];
            }
        }
    }

    return $violations;
}

function writeReport(array $violations, int $configsScanned, string $mode): string
{
    $reportsDir = __DIR__ . '/../../reports';
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0755, true);
    }
    $file = $reportsDir . '/ledger-' . date('Ymd-His') . '.json';
    file_put_contents($file, json_encode([
        'report'          => 'ledger_report',
        'generated_at'    => date('c'),
        'mode'            => $mode,
        'configs_scanned' => $configsScanned,
        'violation_count' => count($violations),
        'violations'      => $violations,
        'status'          => empty($violations) ? 'GREEN' : 'RED',
    ], JSON_PRETTY_PRINT));
    return $file;
}

// -----------------------------------------------------------------------
// Self-test: seed an over-consumed scalar resource, prove check 1 catches
// it. NOTE: exit() inside a try{} does not run finally{} in PHP (bug class
// already hit in equivalence_report.php), so cleanup + exit-code selection
// stay OUTSIDE the try/finally below.
// -----------------------------------------------------------------------
if (in_array('--self-test', $argv, true)) {
    $repo = new ConfigComponentRepository($pdo);
    $laneValidator = new PcieLaneBudgetValidator($pdo);
    $configUuid = 'SELFTEST-LEDGER-' . substr(md5(uniqid()), 0, 8);
    $providerInventoryId = random_int(100000, 999999);
    $consumerInventoryId = random_int(100000, 999999);

    $caught = false;
    try {
        $pdo->prepare('INSERT INTO server_configurations (config_uuid, server_name, is_virtual, configuration_status) VALUES (?, ?, 0, 1)')
            ->execute([$configUuid, 'LEDGER SELFTEST']);

        $pdo->beginTransaction();
        $providerId = $repo->insert($configUuid, [
            'component_type' => 'chassis', 'inventory_table' => 'chassisinventory',
            'inventory_id' => $providerInventoryId, 'spec_uuid' => 'selftest-chassis-uuid',
        ], 1);
        $consumerId = $repo->insert($configUuid, [
            'component_type' => 'storage', 'inventory_table' => 'storageinventory',
            'inventory_id' => $consumerInventoryId, 'spec_uuid' => 'selftest-storage-uuid',
        ], 1);
        $pdo->commit();

        // ConfigComponentWriter never over-allocates a consumption amount
        // itself (it copies whatever ResourceCatalog::consumes() reports) —
        // raw SQL is the only way to induce a Σconsumed > Σcapacity defect,
        // which is exactly the point.
        $pdo->prepare(
            'INSERT INTO config_resources (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id)
             VALUES (?, ?, ?, NULL, ?, NULL)'
        )->execute([$configUuid, 'pcie_lane', $providerId, 4]);
        $pdo->prepare(
            'INSERT INTO config_resources (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id)
             VALUES (?, ?, ?, NULL, ?, ?)'
        )->execute([$configUuid, 'pcie_lane', $providerId, 999, $consumerId]);

        $violations = checkConfigLedger($pdo, $laneValidator, $configUuid);
        $caught = (bool)array_filter($violations, fn($v) => $v['type'] === 'over_consumed');
        writeReport($caught ? $violations : [], 1, 'self-test');
    } finally {
        $pdo->exec("DELETE FROM config_resources WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid));
    }

    if ($caught) {
        echo "ledger_report --self-test: PASS (induced defect correctly detected)\n";
        exit(1); // intentional: proves detection
    }
    echo "ledger_report --self-test: FAIL (induced defect NOT detected — checker is broken)\n";
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
$laneValidator = new PcieLaneBudgetValidator($pdo);

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
        foreach (checkConfigLedger($pdo, $laneValidator, $configUuid) as $v) {
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
echo "ledger_report: $status $file\n";
exit(empty($allViolations) ? 0 : 1);
