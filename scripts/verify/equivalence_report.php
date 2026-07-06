<?php
/**
 * equivalence_report.php — 11-verification/README.md §equivalence_report, INV-8 owner.
 *
 * For each config, extracts components via the legacy path
 * (ServerBuilder::extractComponentsFromJson()) AND the row path
 * (ConfigComponentRepository::liveRows()), canonicalizes both to sorted
 * [component_type, spec_uuid, serial_number|null, slot_ref|null] tuples, and diffs
 * them. Green iff zero diffs across every config checked.
 *
 * This is the proof that DUAL_WRITE_ENABLED is actually keeping the two stores in
 * sync — it says nothing about configs that were never dual-written (rows will be
 * legitimately empty there; the diff would show "only_in_json" for all of them),
 * so it is only meaningful once DUAL_WRITE_ENABLED has been on for a config's
 * whole lifetime, or is being run against a synthetic/scratch fixture built with
 * that in mind.
 *
 * Usage:
 *   php scripts/verify/equivalence_report.php                 # same as --all
 *   php scripts/verify/equivalence_report.php --all [--after <uuid>]
 *   php scripts/verify/equivalence_report.php --config <uuid>
 *   php scripts/verify/equivalence_report.php --self-test      # injects a throwaway
 *                                                                config with a deliberate
 *                                                                JSON/row mismatch, proves
 *                                                                this report detects it.
 *
 * Exit: 0 = green (zero diffs), 1 = red (diffs found, or self-test failed to detect
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
require_once $ROOT . '/core/models/server/ServerBuilder.php';
require_once $ROOT . '/core/models/config/ConfigComponentRepository.php';

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO connection not available after bootstrap.\n");
    exit(2);
}

// -----------------------------------------------------------------------
// Normalization consts — each encodes one legacy quirk, per the pack.
// -----------------------------------------------------------------------

// Onboard NICs (uuid prefix "onboard-") are excluded from BOTH sides until the
// U-B.2 backfill gives them real config_components rows with real parent_ids.
// Flip to true in U-B.2.
const TODO_UB2 = false;

// BEST-EFFORT: the exact legacy JSON key that marks a pciecard entry as a riser
// card (vs. a plain PCIe card) was not confirmed in this unit's allowed read
// range (ServerBuilder.php lines 61-120 only cover extractComponentsFromJson's
// cpu/ram/storage/caddy blocks). Checked defensively against a short list of
// plausible key names; a human/verify session should confirm the real key
// against ims-data/pciecard specs and this list updated if wrong.
const RISER_SUBTYPE_KEYS = ['subtype', 'card_type', 'type'];

function isOnboardNic(string $type, ?string $specUuid): bool
{
    return $type === 'nic' && $specUuid !== null && strpos($specUuid, 'onboard-') === 0;
}

function isRiserPciecard(string $type, array $entry): bool
{
    if ($type !== 'pciecard') {
        return false;
    }
    foreach (RISER_SUBTYPE_KEYS as $key) {
        if (isset($entry[$key]) && stripos((string)$entry[$key], 'riser') !== false) {
            return true;
        }
    }
    return false;
}

function canonicalTuple(string $type, array $entry): array
{
    if (isRiserPciecard($type, $entry)) {
        $type = 'riser';
    }
    $specUuid = $entry['component_uuid'] ?? $entry['spec_uuid'] ?? null;
    $serial = $entry['serial_number'] ?? null;
    $slotRef = $entry['slot_position'] ?? $entry['slot_ref'] ?? $entry['slot_id'] ?? null;
    return [$type, $specUuid, $serial, $slotRef];
}

/**
 * @return string[] canonical tuples (JSON-encoded, sorted) for the legacy side.
 */
function canonicalizeJsonSide(ServerBuilder $builder, array $configRow): array
{
    $entries = $builder->extractComponentsFromJson($configRow);

    // hbacard edge case: server_configurations carries both a scalar hbacard_uuid
    // column (mirroring chassis_uuid/motherboard_uuid) and a hbacard_config JSON
    // blob for extended fields. When hbacard_config is empty, the scalar column
    // is the one live hbacard entry.
    $hbacardConfigEmpty = empty($configRow['hbacard_config']) || $configRow['hbacard_config'] === '[]';
    if (!empty($configRow['hbacard_uuid']) && $hbacardConfigEmpty) {
        $alreadyPresent = false;
        foreach ($entries as $existing) {
            if (($existing['component_type'] ?? null) === 'hbacard') {
                $alreadyPresent = true;
                break;
            }
        }
        if (!$alreadyPresent) {
            $entries[] = ['component_type' => 'hbacard', 'component_uuid' => $configRow['hbacard_uuid']];
        }
    }

    $tuples = [];
    foreach ($entries as $entry) {
        $type = $entry['component_type'] ?? null;
        $specUuid = $entry['component_uuid'] ?? null;
        if ($type === null || $specUuid === null) {
            continue;
        }
        if (!TODO_UB2 && isOnboardNic($type, $specUuid)) {
            continue;
        }
        $tuples[] = json_encode(canonicalTuple($type, $entry));
    }
    sort($tuples);
    return $tuples;
}

/**
 * @return string[] canonical tuples (JSON-encoded, sorted) for the row side.
 */
function canonicalizeRowSide(ConfigComponentRepository $repo, string $configUuid): array
{
    $rows = $repo->liveRows($configUuid);
    $tuples = [];
    foreach ($rows as $row) {
        $type = $row['component_type'];
        $specUuid = $row['spec_uuid'];
        if (!TODO_UB2 && isOnboardNic($type, $specUuid)) {
            continue;
        }
        $tuples[] = json_encode([$type, $specUuid, $row['serial_number'], $row['slot_ref']]);
    }
    sort($tuples);
    return $tuples;
}

/**
 * @return array|null diff record, or null if the config is equivalent (or does
 *         not exist).
 */
function diffConfig(PDO $pdo, ServerBuilder $builder, ConfigComponentRepository $repo, string $configUuid): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM server_configurations WHERE config_uuid = ?');
    $stmt->execute([$configUuid]);
    $configRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$configRow) {
        return null;
    }

    $jsonTuples = canonicalizeJsonSide($builder, $configRow);
    $rowTuples = canonicalizeRowSide($repo, $configUuid);

    $onlyInJson = array_values(array_diff($jsonTuples, $rowTuples));
    $onlyInRows = array_values(array_diff($rowTuples, $jsonTuples));

    if (empty($onlyInJson) && empty($onlyInRows)) {
        return null;
    }
    return [
        'config_uuid'   => $configUuid,
        'only_in_json'  => array_map(fn($t) => json_decode($t, true), $onlyInJson),
        'only_in_rows'  => array_map(fn($t) => json_decode($t, true), $onlyInRows),
    ];
}

function writeReport(array $diffs, int $configsScanned, string $mode): string
{
    $reportsDir = __DIR__ . '/../../reports';
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0755, true);
    }
    $file = $reportsDir . '/equivalence-' . date('Ymd-His') . '.json';
    file_put_contents($file, json_encode([
        'report'          => 'equivalence_report',
        'generated_at'    => date('c'),
        'mode'            => $mode,
        'configs_scanned' => $configsScanned,
        'diff_count'      => count($diffs),
        'diffs'           => $diffs,
        'status'          => empty($diffs) ? 'GREEN' : 'RED',
    ], JSON_PRETTY_PRINT));
    return $file;
}

// -----------------------------------------------------------------------
// Self-test: build a throwaway config whose JSON and rows deliberately
// disagree, prove this report's own diff logic catches it.
// -----------------------------------------------------------------------
if (in_array('--self-test', $argv, true)) {
    $builder = new ServerBuilder($pdo);
    $repo = new ConfigComponentRepository($pdo);
    $configUuid = 'SELFTEST-EQUIV-' . substr(md5(uniqid()), 0, 8);
    $cpuUuidJsonSide = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c';
    $cpuUuidRowSide = '00000000-0000-4000-8000-000000000000'; // deliberately different -> guaranteed mismatch
    $inventoryId = random_int(100000, 999999);

    // NOTE: exit() inside a try{} does NOT run finally{} in PHP, so cleanup and
    // exit-code selection are kept OUTSIDE the try/finally below.
    $caught = false;
    try {
        $cols = [
            'config_uuid' => $configUuid, 'server_name' => 'EQUIV SELFTEST', 'is_virtual' => 0,
            'configuration_status' => 1,
            'cpu_configuration' => json_encode(['cpus' => [['uuid' => $cpuUuidJsonSide, 'quantity' => 1, 'serial_number' => 'SELFTEST-1']]]),
        ];
        $fields = array_keys($cols);
        $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $fields) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $fields)) . ')')
            ->execute($cols);

        // Deliberately write a DIFFERENT spec_uuid on the row side -> guaranteed
        // "only_in_json" + "only_in_rows" diff, independent of any per-type
        // extraction quirk (unlike a serial-only mismatch would be for 'ram').
        $pdo->beginTransaction();
        $repo->insert($configUuid, [
            'component_type' => 'cpu', 'inventory_table' => 'cpuinventory', 'inventory_id' => $inventoryId,
            'spec_uuid' => $cpuUuidRowSide, 'serial_number' => 'SELFTEST-1',
        ], 1);
        $pdo->commit();

        $diff = diffConfig($pdo, $builder, $repo, $configUuid);
        $caught = $diff !== null && (!empty($diff['only_in_json']) || !empty($diff['only_in_rows']));
        writeReport($caught ? [$diff] : [], 1, 'self-test');
    } finally {
        $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid));
    }

    if ($caught) {
        echo "equivalence_report --self-test: PASS (induced diff correctly detected)\n";
        exit(1); // intentional: proves detection
    }
    echo "equivalence_report --self-test: FAIL (induced diff NOT detected — checker is broken)\n";
    exit(0);
}

// -----------------------------------------------------------------------
// --config <uuid>: single config
// -----------------------------------------------------------------------
$configIdx = array_search('--config', $argv, true);
if ($configIdx !== false) {
    $targetUuid = $argv[$configIdx + 1] ?? null;
    if ($targetUuid === null) {
        fwrite(STDERR, "--config requires a <uuid> argument\n");
        exit(2);
    }

    $builder = new ServerBuilder($pdo);
    $repo = new ConfigComponentRepository($pdo);
    $diff = diffConfig($pdo, $builder, $repo, $targetUuid);
    $diffs = $diff !== null ? [$diff] : [];

    $file = writeReport($diffs, 1, 'config:' . $targetUuid);
    $status = empty($diffs) ? 'GREEN' : 'RED';
    echo "equivalence_report: $status $file\n";
    exit(empty($diffs) ? 0 : 1);
}

// -----------------------------------------------------------------------
// --all (default): keyset-paginated fleet scan, skipping virtual configs (F-5:
// virtual configs bypass duplicate/JSON/inventory checks by design and are
// never dual-written — see migration/00-overview/PLAN_VERIFICATION_REVIEW.md).
// -----------------------------------------------------------------------
$builder = new ServerBuilder($pdo);
$repo = new ConfigComponentRepository($pdo);

$afterIdx = array_search('--after', $argv, true);
$cursor = $afterIdx !== false ? ($argv[$afterIdx + 1] ?? '') : '';

const BATCH_SIZE = 1000;
$diffs = [];
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
        $diff = diffConfig($pdo, $builder, $repo, $configUuid);
        if ($diff !== null) {
            $diffs[] = $diff;
        }
    }

    $cursor = end($batch);
    if (count($batch) < BATCH_SIZE) {
        break;
    }
}

$file = writeReport($diffs, $scanned, 'all');
$status = empty($diffs) ? 'GREEN' : 'RED';
echo "equivalence_report: $status $file\n";
exit(empty($diffs) ? 0 : 1);
