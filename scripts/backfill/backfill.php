<?php
/**
 * backfill.php — U-B.1 skeleton (07-component-migration/execution-packs/U-B.1.md).
 *
 * Safe bulk-migration machinery: state tracking, dry-run, resume, quarantine.
 * The Extractor itself (turning a legacy JSON entry into real
 * config_components/config_resources rows) is NOT implemented yet — U-B.2
 * ships it. This unit's Extractor stub quarantines EVERY legacy component
 * entry it finds, reason 'extractor-not-implemented', so the machinery
 * below can be proven correct without depending on unwritten extraction logic.
 *
 * extractLegacyEntries() mirrors scripts/audit-orphans.php::extractRefs()
 * exactly (the only file this unit's pack authorized reading for this
 * pattern) — it does NOT include equivalence_report.php's hbacard_uuid /
 * hbacard_config dedup refinement (that file was out of this unit's read
 * scope), so a config with BOTH populated could theoretically quarantine
 * hbacard twice. Low-stakes here (everything is quarantined either way,
 * once each duplicate) — U-B.2's real Extractor must get this right.
 *
 * Usage:
 *   php scripts/backfill/backfill.php [--config <uuid>]
 *       Dry-run (default): writes reports/backfill-plan-<ts>.json only.
 *       Zero DB writes — no transaction is even opened.
 *   php scripts/backfill/backfill.php --execute [--run-id <id>] [--config <uuid>]
 *       Real run: per config, locks the config row, quarantines every legacy
 *       component entry found (or marks 'done' if none), commits. Prints the
 *       run-id (auto-generated if not given) so it can be resumed later.
 *   php scripts/backfill/backfill.php --resume --run-id <id> [--config <uuid>]
 *       Continues configs still 'pending'/'error' for that run. Configs
 *       already 'done' are re-verified via equivalence_report.php --config
 *       instead of being reprocessed (idempotence); 'quarantined' configs
 *       are left untouched (terminal for this stub).
 *   php scripts/backfill/backfill.php --rollback-run <id>
 *       Deletes every row this run produced: config_resources/config_components
 *       rows referenced by its config_events(event='backfill') entries (none
 *       exist yet under this unit's stub — forward-compatible for U-B.2),
 *       those config_events rows themselves, its backfill_quarantine rows,
 *       and its migration_backfill_state rows.
 *
 * Exit: 0 = clean run (or clean dry-run/rollback), 1 = one or more configs
 * ended in 'error', 2 = usage/setup error.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);

$bootstrap = $ROOT . '/core/config/app.php';
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

function argAfter(array $argv, string $flag): ?string
{
    $i = array_search($flag, $argv, true);
    return $i !== false ? ($argv[$i + 1] ?? null) : null;
}

/**
 * Mirrors scripts/audit-orphans.php::extractRefs() exactly, but keeps the
 * FULL raw entry (not just uuid/serial) so a quarantine row has enough
 * information for an operator to inspect or hand-migrate. See file docblock
 * for the one known deviation (no hbacard dedup).
 *
 * @return array<int, array{type:string, entry:array}>
 */
function extractLegacyEntries(array $configRow): array
{
    $entries = [];

    $pushJson = function ($type, $json, $key = null) use (&$entries) {
        if (empty($json)) {
            return;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return;
        }
        $list = $key ? ($decoded[$key] ?? []) : $decoded;
        if (!is_array($list)) {
            return;
        }
        foreach ($list as $entry) {
            if (!is_array($entry) || empty($entry['uuid'])) {
                continue;
            }
            $entries[] = ['type' => $type, 'entry' => $entry];
        }
    };

    $pushJson('cpu',      $configRow['cpu_configuration']       ?? null, 'cpus');
    $pushJson('ram',      $configRow['ram_configuration']       ?? null);
    $pushJson('storage',  $configRow['storage_configuration']   ?? null);
    $pushJson('caddy',    $configRow['caddy_configuration']     ?? null);
    $pushJson('pciecard', $configRow['pciecard_configurations'] ?? null);
    $pushJson('hbacard',  $configRow['hbacard_config']          ?? null);
    $pushJson('sfp',      $configRow['sfp_configuration']       ?? null);
    $pushJson('nic',      $configRow['nic_config']              ?? null, 'nics');

    if (!empty($configRow['motherboard_uuid'])) {
        $entries[] = ['type' => 'motherboard', 'entry' => ['uuid' => $configRow['motherboard_uuid']]];
    }
    if (!empty($configRow['chassis_uuid'])) {
        $entries[] = ['type' => 'chassis', 'entry' => ['uuid' => $configRow['chassis_uuid']]];
    }
    if (!empty($configRow['hbacard_uuid'])) {
        $entries[] = ['type' => 'hbacard', 'entry' => ['uuid' => $configRow['hbacard_uuid']]];
    }

    return $entries;
}

function upsertState(PDO $pdo, string $runId, string $configUuid, string $status, ?string $lastError): void
{
    $attemptsIncrement = ($status === 'error') ? 1 : 0;
    $pdo->prepare(
        'INSERT INTO migration_backfill_state (run_id, config_uuid, status, attempts, last_error, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
             status = VALUES(status), attempts = attempts + VALUES(attempts),
             last_error = VALUES(last_error), updated_at = NOW()'
    )->execute([$runId, $configUuid, $status, $attemptsIncrement, $lastError]);
}

function writePlan(string $rootDir, string $runId, array $planEntries): string
{
    $dir = $rootDir . '/reports';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $file = $dir . '/backfill-plan-' . date('Ymd-His') . '.json';
    file_put_contents($file, json_encode([
        'report'       => 'backfill_plan',
        'run_id'       => $runId,
        'generated_at' => date('c'),
        'config_count' => count($planEntries),
        'configs'      => $planEntries,
    ], JSON_PRETTY_PRINT));
    return $file;
}

/**
 * A config already marked 'done' for this run is NOT reprocessed on
 * --resume — its rows already exist. Instead, re-verify it still matches
 * via the existing equivalence checker (a separate process/connection, run
 * outside any transaction).
 */
function reverifyDoneConfig(string $rootDir, string $configUuid): bool
{
    $script = $rootDir . '/scripts/verify/equivalence_report.php';
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['php', $script, '--config', $configUuid], $descriptors, $pipes, $rootDir);
    if (!is_resource($process)) {
        return false;
    }
    // Must drain both pipes before closing them — closing an unread pipe can
    // SIGPIPE the child while it's still writing, producing a false failure
    // exit code unrelated to the check's actual result (hit this exact race
    // during development; orphan_report.php/equivalence_report.php avoid it
    // the same way).
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process) === 0;
}

function rollbackRun(PDO $pdo, string $rootDir, string $runId): int
{
    try {
        $pdo->beginTransaction();

        // Forward-compatible with U-B.2: under THIS unit's stub extractor no
        // config_components row is ever inserted (everything is quarantined),
        // so this always finds zero ids today — kept correct for when a real
        // Extractor starts writing rows via ConfigComponentRepository::insert().
        $stmt = $pdo->prepare(
            "SELECT component_id FROM config_events
             WHERE event = 'backfill' AND component_id IS NOT NULL
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.run_id')) = ?"
        );
        $stmt->execute([$runId]);
        $componentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (!empty($componentIds)) {
            $placeholders = implode(',', array_fill(0, count($componentIds), '?'));
            $pdo->prepare("DELETE FROM config_resources WHERE provider_id IN ($placeholders) OR consumer_id IN ($placeholders)")
                ->execute(array_merge($componentIds, $componentIds));
            $pdo->prepare("DELETE FROM config_components WHERE id IN ($placeholders)")
                ->execute($componentIds);
        }

        $pdo->prepare(
            "DELETE FROM config_events WHERE event = 'backfill'
             AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.run_id')) = ?"
        )->execute([$runId]);
        $pdo->prepare('DELETE FROM backfill_quarantine WHERE run_id = ?')->execute([$runId]);
        $pdo->prepare('DELETE FROM migration_backfill_state WHERE run_id = ?')->execute([$runId]);

        $pdo->commit();
        echo "backfill --rollback-run $runId: cleared " . count($componentIds) . " component row(s), quarantine + state rows.\n";
        return 0;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "rollback-run failed: " . $ex->getMessage() . "\n");
        return 1;
    }
}

// -----------------------------------------------------------------------
// CLI dispatch
// -----------------------------------------------------------------------
$rollbackRunId = argAfter($argv, '--rollback-run');
if ($rollbackRunId !== null) {
    exit(rollbackRun($pdo, $ROOT, $rollbackRunId));
}

$configFilter = argAfter($argv, '--config');
$runId = argAfter($argv, '--run-id');
$resume = in_array('--resume', $argv, true);
$execute = $resume || in_array('--execute', $argv, true);

if ($resume && $runId === null) {
    fwrite(STDERR, "--resume requires --run-id <id>\n");
    exit(2);
}
if ($runId === null) {
    $runId = 'run-' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6);
}

// -----------------------------------------------------------------------
// Gather target configs (non-virtual only — assumption A-3,
// PLAN_VERIFICATION_REVIEW.md: virtual/test configs are excluded from
// backfill, equivalence, and all row-side machinery).
// -----------------------------------------------------------------------
$configUuids = [];
if ($configFilter !== null) {
    $stmt = $pdo->prepare('SELECT config_uuid FROM server_configurations WHERE config_uuid = ? AND is_virtual = 0');
    $stmt->execute([$configFilter]);
    if (!$stmt->fetchColumn()) {
        fwrite(STDERR, "Config not found (or is virtual): $configFilter\n");
        exit(2);
    }
    $configUuids = [$configFilter];
} else {
    $cursor = '';
    while (true) {
        $stmt = $pdo->prepare(
            'SELECT config_uuid FROM server_configurations
             WHERE is_virtual = 0 AND config_uuid > ? ORDER BY config_uuid LIMIT 1000'
        );
        $stmt->execute([$cursor]);
        $batch = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($batch)) {
            break;
        }
        foreach ($batch as $u) {
            $configUuids[] = $u;
        }
        $cursor = end($batch);
        if (count($batch) < 1000) {
            break;
        }
    }
}

$existingState = [];
if ($resume) {
    $stmt = $pdo->prepare('SELECT config_uuid, status FROM migration_backfill_state WHERE run_id = ?');
    $stmt->execute([$runId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existingState[$row['config_uuid']] = $row['status'];
    }
    if (empty($existingState)) {
        fwrite(STDERR, "No existing state for run-id $runId — nothing to resume.\n");
        exit(2);
    }
    if ($configFilter === null) {
        $configUuids = array_keys($existingState);
    }
}

// -----------------------------------------------------------------------
// Main loop
// -----------------------------------------------------------------------
$planEntries = [];
$doneCount = 0;
$quarantinedCount = 0;
$errorCount = 0;
$verifiedCount = 0;

foreach ($configUuids as $configUuid) {
    $priorStatus = $existingState[$configUuid] ?? null;

    if ($resume && $priorStatus === 'done') {
        if (reverifyDoneConfig($ROOT, $configUuid)) {
            $verifiedCount++;
        } else {
            $errorCount++;
            upsertState($pdo, $runId, $configUuid, 'error', 'post-resume equivalence check failed');
        }
        continue;
    }
    if ($resume && $priorStatus === 'quarantined') {
        // Terminal for this stub extractor — nothing to re-verify yet.
        continue;
    }

    if (!$execute) {
        $stmt = $pdo->prepare('SELECT * FROM server_configurations WHERE config_uuid = ?');
        $stmt->execute([$configUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $entries = $row ? extractLegacyEntries($row) : [];
        $planEntries[] = [
            'config_uuid'      => $configUuid,
            'would_quarantine' => count($entries),
            'reason'           => empty($entries) ? null : 'extractor-not-implemented',
        ];
        continue;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM server_configurations WHERE config_uuid = ? FOR UPDATE');
        $stmt->execute([$configUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('config row disappeared mid-run');
        }

        $entries = extractLegacyEntries($row);
        if (empty($entries)) {
            upsertState($pdo, $runId, $configUuid, 'done', null);
            $doneCount++;
        } else {
            $quarantineStmt = $pdo->prepare(
                'INSERT INTO backfill_quarantine (run_id, config_uuid, component_json, reason) VALUES (?, ?, ?, ?)'
            );
            foreach ($entries as $entry) {
                $quarantineStmt->execute([$runId, $configUuid, json_encode($entry), 'extractor-not-implemented']);
            }
            upsertState($pdo, $runId, $configUuid, 'quarantined', null);
            $quarantinedCount++;
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        upsertState($pdo, $runId, $configUuid, 'error', $ex->getMessage());
        $errorCount++;
    }
}

if (!$execute) {
    $file = writePlan($ROOT, $runId, $planEntries);
    echo "backfill: DRY-RUN plan written to $file (" . count($planEntries) . " configs; DB untouched)\n";
    exit(0);
}

echo "backfill: run-id=$runId done=$doneCount quarantined=$quarantinedCount errors=$errorCount" .
    ($resume ? " verified=$verifiedCount" : "") . "\n";
exit($errorCount > 0 ? 1 : 0);
