<?php
/**
 * backfill.php — 07-component-migration/execution-packs/U-B.1.md + U-B.2.md.
 *
 * Safe bulk-migration machinery (state tracking, dry-run, resume, quarantine)
 * plus the real Extractor (Extractor.php, U-B.2): turns each legacy JSON
 * component entry into a config_components row, resolved to its one physical
 * inventory row. Anything the Extractor cannot confidently resolve is
 * quarantined with a distinct reason instead of guessed.
 *
 * Usage:
 *   php scripts/backfill/backfill.php [--config <uuid>]
 *       Dry-run (default): writes reports/backfill-plan-<ts>.json only.
 *       Zero DB writes — no transaction is even opened.
 *   php scripts/backfill/backfill.php --execute [--run-id <id>] [--config <uuid>]
 *       Real run: per config, locks the config row, migrates every resolvable
 *       legacy component entry (config_components + config_events event=
 *       'backfill', carrying run_id) and quarantines the rest, commits.
 *       Prints the run-id (auto-generated if not given) so it can be resumed.
 *   php scripts/backfill/backfill.php --resume --run-id <id> [--config <uuid>]
 *       Continues configs still 'pending'/'error' for that run. Configs
 *       already 'done' are re-verified via equivalence_report.php --config
 *       instead of being reprocessed (idempotence); 'quarantined' configs
 *       are left untouched (a config with any quarantined entry needs human
 *       review before this run revisits it).
 *   php scripts/backfill/backfill.php --rollback-run <id>
 *       Deletes every row this run produced: config_components/config_resources
 *       rows referenced by its config_events(event='backfill') entries, those
 *       config_events rows themselves, its backfill_quarantine rows, and its
 *       migration_backfill_state rows.
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
require_once $ROOT . '/core/models/config/ConfigComponentRepository.php';
require_once $ROOT . '/core/models/config/ResourceCatalog.php';
require_once __DIR__ . '/Extractor.php';

// ResourceCatalog::provides()/consumes() now implement all 10 real component
// types (cpu -> U-L.4, nic/hbacard/pciecard -> U-L.5) — see ResourceCatalog.php's
// own class docblock. No skip list is needed any more: backfillLedgerForConfig()
// always normalizes 'riser' rows to $physicalType = 'pciecard' before this check
// runs (a riser's spec has no interface/pcie_lanes field, so it naturally
// consumes 0 lanes via consumesPcieLanes() -- consistent with
// PcieLaneBudgetValidator::computeLanesUsed(), which walks riser entries the
// same un-excluded way).
const LEDGER_SKIP_PROVIDES = [];
const LEDGER_SKIP_CONSUMES = [];

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
 * Insert every plan in order, resolving each plan's 'parent_ref' marker
 * ('motherboard' / 'riser' / ['nic_spec_uuid' => uuid]) against ids assigned
 * to earlier plans in this same config (Extractor::extract() guarantees
 * parent-before-child ordering). A ref with nothing to resolve against
 * (e.g. the extractor decided against linking an ambiguous riser) silently
 * yields parent_id = NULL — parent_id has always been best-effort here (RV-1/RV-2).
 */
/**
 * @return array[] one row per inserted plan: {id, component_type, spec_uuid, slot_ref}
 *         (input for backfillLedgerForConfig()'s second pass).
 */
function persistPlans(PDO $pdo, ConfigComponentRepository $repo, Extractor $extractor, string $configUuid, array $plans, string $runId, $actor): array
{
    $motherboardId = null;
    $riserId = null;
    $nicIdsBySpec = [];
    $insertedRows = [];

    foreach ($plans as $plan) {
        $ref = $plan['parent_ref'];
        if ($ref === 'motherboard') {
            $parentId = $motherboardId;
        } elseif ($ref === 'riser') {
            $parentId = $riserId;
        } elseif (is_array($ref) && isset($ref['nic_spec_uuid'])) {
            $parentId = $nicIdsBySpec[$ref['nic_spec_uuid']] ?? null;
        } else {
            $parentId = null;
        }

        $id = $extractor->persistPlan($pdo, $repo, $configUuid, $plan, $parentId, $runId, $actor);
        $insertedRows[] = [
            'id' => $id, 'component_type' => $plan['component_type'],
            'spec_uuid' => $plan['spec_uuid'], 'slot_ref' => $plan['slot_ref'],
        ];

        if ($plan['component_type'] === 'motherboard') {
            $motherboardId = $id;
        } elseif ($plan['component_type'] === 'riser') {
            $riserId = $id;
        } elseif ($plan['component_type'] === 'nic') {
            $nicIdsBySpec[$plan['spec_uuid']] = $id;
        }
    }

    return $insertedRows;
}

/**
 * Second pass, same transaction as persistPlans(): ledger rows for the
 * config's now-inserted components. Providers via ResourceCatalog::provides()
 * for every non-skipped type ('riser' rows are queried as 'pciecard' —
 * ResourceCatalog doesn't know the config_components-level relabeling,
 * providesPciecard() itself detects Riser Card via component_subtype).
 * Discrete slot consumer-linking is a plain slot_ref string match — per RV-2
 * (ConfigComponentWriter.php docblock), ResourceCatalog's slot_ref naming
 * rarely if ever matches the legacy slot-assignment system's, so this mostly
 * links nothing today; the mechanism exists for whenever a future unit
 * reconciles the two naming schemes. Scalar lane consumption via
 * ResourceCatalog::consumes() (storage only, today).
 *
 * A CatalogException here (malformed spec) propagates to the caller's
 * existing per-config try/catch, which marks the config 'error' (resumable)
 * rather than quarantined: per the pack, this class of failure is
 * spec/catalog-fixable, not a shape problem. Provider ABSENCE, however, is
 * no longer an error (F-PSU fix, 2026-07-15): a mid-build config with no
 * chassis has psu_watt consumers and nothing to attach them to — those
 * consumption rows are skipped with a log line and the live dual-writer
 * retro-attaches them when a provider is eventually added.
 */
function backfillLedgerForConfig(PDO $pdo, string $configUuid, array $insertedRows): void
{
    $catalog = new ResourceCatalog();

    foreach ($insertedRows as $row) {
        $physicalType = $row['component_type'] === 'riser' ? 'pciecard' : $row['component_type'];

        if (!in_array($physicalType, LEDGER_SKIP_PROVIDES, true)) {
            $providerStmt = $pdo->prepare(
                'INSERT INTO config_resources (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id)
                 VALUES (?, ?, ?, ?, ?, NULL)'
            );
            foreach ($catalog->provides($physicalType, $row['spec_uuid']) as $p) {
                $providerStmt->execute([$configUuid, $p['resource'], $row['id'], $p['slot_ref'], $p['capacity']]);
            }
        }

        if ($row['slot_ref'] !== null) {
            $pdo->prepare(
                'UPDATE config_resources SET consumer_id = ?
                 WHERE config_uuid = ? AND slot_ref = ? AND consumer_id IS NULL
                 ORDER BY id LIMIT 1'
            )->execute([$row['id'], $configUuid, $row['slot_ref']]);
        }

        if (!in_array($physicalType, LEDGER_SKIP_CONSUMES, true)) {
            foreach ($catalog->consumes($physicalType, $row['spec_uuid']) as $consumed) {
                $findProvider = $pdo->prepare(
                    'SELECT provider_id FROM config_resources
                     WHERE config_uuid = ? AND resource = ? AND consumer_id IS NULL LIMIT 1'
                );
                $findProvider->execute([$configUuid, $consumed['resource']]);
                $providerId = $findProvider->fetchColumn();
                if ($providerId === false) {
                    // F-PSU fix (2026-07-15): provider absence is a deferred
                    // state, not an error — legacy imposes no build order, so a
                    // chassis-less (mid-build) config legitimately has psu_watt
                    // consumers and no provider. Skip the row; the live dual-
                    // writer retro-attaches it when a provider is added (see
                    // ConfigComponentWriter's DEFERRED CONSUMPTION docblock).
                    // Malformed-spec CatalogExceptions still propagate.
                    fwrite(STDERR,
                        "ledger: deferred consumption of '{$consumed['resource']}' by component id {$row['id']} " .
                        "in config $configUuid — no provider present (mid-build config)\n"
                    );
                    continue;
                }
                $pdo->prepare(
                    'INSERT INTO config_resources (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id)
                     VALUES (?, ?, ?, NULL, ?, ?)'
                )->execute([$configUuid, $consumed['resource'], $providerId, $consumed['amount'], $row['id']]);
            }
        }
    }
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

        // component_id ordered by revision DESC: persistPlans() always inserts
        // a parent (motherboard/riser/nic) before anything that references it
        // via parent_id, bumping revision each time — so the highest-revision
        // rows in this run are always children (if any) of a lower-revision
        // row also in this run. Deleting in that order (children first) keeps
        // fk_cc_parent (RESTRICT, no ON DELETE clause) satisfied; a single
        // unordered `DELETE ... WHERE id IN (...)` can hit "parent row" FK
        // violations depending on MySQL's internal row order (hit this exact
        // error deleting a real Extractor's output — U-B.1's rollback path was
        // only exercised against zero rows, since its stub never inserted any).
        $stmt = $pdo->prepare(
            "SELECT component_id FROM config_events
             WHERE event = 'backfill' AND component_id IS NOT NULL
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.run_id')) = ?
             ORDER BY revision DESC"
        );
        $stmt->execute([$runId]);
        $componentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (!empty($componentIds)) {
            $placeholders = implode(',', array_fill(0, count($componentIds), '?'));
            $pdo->prepare("DELETE FROM config_resources WHERE provider_id IN ($placeholders) OR consumer_id IN ($placeholders)")
                ->execute(array_merge($componentIds, $componentIds));

            $deleteOne = $pdo->prepare('DELETE FROM config_components WHERE id = ?');
            foreach ($componentIds as $id) {
                $deleteOne->execute([$id]);
            }
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
        $result = $row ? (new Extractor())->extract($pdo, $row) : ['plans' => [], 'quarantine' => []];
        $planEntries[] = [
            'config_uuid'      => $configUuid,
            'would_migrate'    => count($result['plans']),
            'would_quarantine' => count($result['quarantine']),
            'reasons'          => array_values(array_unique(array_column($result['quarantine'], 'reason'))),
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

        $extractor = new Extractor();
        $result = $extractor->extract($pdo, $row);
        $repo = new ConfigComponentRepository($pdo);

        if (!empty($result['plans'])) {
            $insertedRows = persistPlans($pdo, $repo, $extractor, $configUuid, $result['plans'], $runId, 0);
            backfillLedgerForConfig($pdo, $configUuid, $insertedRows);
        }

        if (!empty($result['quarantine'])) {
            $quarantineStmt = $pdo->prepare(
                'INSERT INTO backfill_quarantine (run_id, config_uuid, component_json, reason) VALUES (?, ?, ?, ?)'
            );
            foreach ($result['quarantine'] as $q) {
                $quarantineStmt->execute([$runId, $configUuid, json_encode(['type' => $q['type'], 'entry' => $q['entry']]), $q['reason']]);
            }
            upsertState($pdo, $runId, $configUuid, 'quarantined', null);
            $quarantinedCount++;
        } else {
            upsertState($pdo, $runId, $configUuid, 'done', null);
            $doneCount++;
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
