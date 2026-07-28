<?php
/**
 * repair-onboard-nic-rows.php — F-13 data repair.
 *
 * WHY THIS EXISTS
 *   Until 2026-07-27 the dual-write had a blind spot: ServerBuilder mirrored the
 *   MOTHERBOARD it was adding, then OnboardNICHandler::autoAddOnboardNICs()
 *   created nicinventory rows and rewrote the legacy nic_config JSON without ever
 *   telling the rows store. Result: config_components held ZERO nic rows while the
 *   legacy blob listed them, and equivalence_report was RED on every config with
 *   an onboard NIC. The live path is fixed, but configs built BEFORE the fix still
 *   have the missing rows -- that is what this repairs.
 *
 * WHY NOT A .sql SEEDER
 *   A config_components row is not the whole story: ResourceCatalog also emits
 *   config_resources provider/consumption rows for a nic. Hand-writing that in SQL
 *   would duplicate ConfigComponentWriter's logic, which is precisely the class of
 *   drift this migration exists to remove. So this calls the ONE writer instead --
 *   correct by construction, and it stays correct if the writer changes.
 *
 * WHY NOT scripts/backfill/backfill.php
 *   That tool's idempotency is per-run-state, not per-row: it does not check for an
 *   existing live row, so re-running it over a config that already has
 *   dual-written rows would DUPLICATE them. This script checks findLive() per unit
 *   and only writes what is genuinely absent.
 *
 * Usage:
 *   php scripts/backfill/repair-onboard-nic-rows.php                 # dry run
 *   php scripts/backfill/repair-onboard-nic-rows.php --execute
 *   php scripts/backfill/repair-onboard-nic-rows.php --config <uuid> [--execute]
 *
 * Requires DUAL_WRITE_ENABLED=on (the writer is a documented no-op otherwise --
 * this script refuses to run rather than silently repairing nothing).
 * Idempotent: a second --execute run finds nothing to do.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/config/app.php';
require_once $ROOT . '/core/models/config/ConfigComponentWriter.php';
require_once $ROOT . '/core/models/config/ConfigComponentRepository.php';

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "No PDO from bootstrap\n");
    exit(1);
}

$execute = in_array('--execute', $argv, true);
$onlyConfig = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--config' && isset($argv[$i + 1])) {
        $onlyConfig = $argv[$i + 1];
    }
}

if (ConfigComponentWriter::mode() !== 'on') {
    fwrite(STDERR, "repair-onboard-nic-rows: DUAL_WRITE_ENABLED is not 'on' -- the writer would\n"
                 . "no-op and this script would report success having done nothing. Refusing.\n");
    exit(1);
}

// Onboard NIC units currently attached to a config, per the inventory table.
$sql = "SELECT ID, UUID, SerialNumber, ServerUUID
        FROM nicinventory
        WHERE SourceType = 'onboard'
          AND ServerUUID IS NOT NULL
          AND ServerUUID <> ''
          AND Status = 2";
$params = [];
if ($onlyConfig !== null) {
    $sql .= " AND ServerUUID = ?";
    $params[] = $onlyConfig;
}
$sql .= " ORDER BY ServerUUID, OnboardNICIndex";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attached = $stmt->fetchAll(PDO::FETCH_ASSOC);

$repo = new ConfigComponentRepository($pdo);

$missing = [];
foreach ($attached as $nic) {
    // Skip configs that do not exist any more (deleted config, stale ServerUUID).
    $cfgStmt = $pdo->prepare('SELECT is_virtual FROM server_configurations WHERE config_uuid = ?');
    $cfgStmt->execute([$nic['ServerUUID']]);
    $cfg = $cfgStmt->fetch(PDO::FETCH_ASSOC);
    if ($cfg === false) {
        echo "  SKIP {$nic['UUID']}: config {$nic['ServerUUID']} no longer exists\n";
        continue;
    }
    if ((bool)$cfg['is_virtual']) {
        echo "  SKIP {$nic['UUID']}: config is virtual (never dual-written)\n";
        continue;
    }

    $live = $repo->findLive($nic['ServerUUID'], 'nic', $nic['UUID'], $nic['SerialNumber']);
    if ($live === null) {
        $missing[] = $nic;
    }
}

echo "onboard NIC units attached:      " . count($attached) . "\n";
echo "missing a config_components row: " . count($missing) . "\n";
foreach ($missing as $nic) {
    echo "  - config " . substr($nic['ServerUUID'], 0, 8) . "  {$nic['UUID']}  (nicinventory ID {$nic['ID']})\n";
}

if (empty($missing)) {
    echo "nothing to repair\n";
    exit(0);
}

if (!$execute) {
    echo "\nDRY RUN -- no rows written. Re-run with --execute to apply.\n";
    exit(0);
}

// One transaction per unit: a single bad unit must not strand the rest, and the
// writer requires an active transaction to keep component+ledger rows atomic.
$written = 0;
$failed = 0;
foreach ($missing as $nic) {
    try {
        $pdo->beginTransaction();
        ConfigComponentWriter::afterLegacyAdd(
            $pdo,
            $nic['ServerUUID'],
            'nic',
            $nic['UUID'],
            $nic['SerialNumber'],
            null,            // onboard ports occupy no PCIe slot
            'nicinventory',
            (int)$nic['ID'],
            0,               // actor: repair script, same convention as ServerBuilder
            null             // parent resolves via server_configurations.motherboard_uuid
        );
        $pdo->commit();
        $written++;
        echo "  WROTE {$nic['UUID']}\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failed++;
        echo "  FAILED {$nic['UUID']}: " . $e->getMessage() . "\n";
    }
}

echo "\nwritten: $written   failed: $failed\n";
echo "Now re-run: php scripts/verify/run_all.php --gate P2\n";
exit($failed === 0 ? 0 : 1);
