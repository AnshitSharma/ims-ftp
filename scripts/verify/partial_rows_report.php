<?php
/**
 * partial_rows_report.php — 11-verification/README.md #12 (added 2026-08-24).
 *
 * Gates the ONE input TargetStateBuilder::fromCurrent() cannot check for itself:
 * whether the rows store is fully populated for a config, or only partly.
 *
 * fromCurrent() chooses its source with `if (!empty($rows))` — a NON-EMPTY test,
 * not a COMPLETE one. So for a config whose config_components rows cover only
 * some of what its legacy JSON columns describe, the rows path is taken and the
 * unmirrored components are simply ABSENT from the TargetState every rule then
 * evaluates. There is no diagnostic: a validation engine handed a server with
 * half its hardware missing reports confidently about the half it can see.
 *
 * At COMMAND_LAYER=off/shadow that costs a wrong line in a shadow log. At
 * COMMAND_LAYER=enforce (today's production setting) it is a user-visible 404 on
 * remove — the command layer looks for a component row that the TargetState says
 * does not exist, and refuses. That is why this report is a gate and not a note.
 *
 * The defect is deliberately NOT fixed by this unit: changing fromCurrent()'s
 * source selection mid-cutover would change which store every rule reads from,
 * for every config, in one step. This report exists so the cutover can proceed
 * knowing whether any such config exists at all — detection first, then a
 * control-flow change with evidence behind it.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS MEASURED, per row of server_configurations
 * ---------------------------------------------------------------------------
 * JSON side: TargetStateBuilder::jsonFallbackRows() — the report calls the
 *   application's own private method by reflection rather than re-reading the
 *   legacy columns itself. This is deliberate and is the single most important
 *   property of this file. jsonFallbackRows() is EXACTLY the fallback
 *   fromCurrent() would have taken, quirks included (quantity expansion,
 *   empty-UUID skipping, the sfp->nic parent walk, and everything
 *   ServerBuilder::extractComponentsFromJson() does or fails to do underneath
 *   it). A hand-written column reader would be a SECOND opinion about the legacy
 *   columns, and the first time the two disagreed the report would be measuring
 *   its own reimplementation instead of the behaviour it is supposed to gate. It
 *   is private and static, so reflection is the only way to reuse it; the
 *   alternative — copying ~60 lines of decode logic that must then be kept in
 *   step by hand forever — is the failure mode, not the safe option. The
 *   reflection handle is resolved ONCE at startup and a failure exits 2 (setup
 *   error), so a rename can never silently degrade this into a green-printing
 *   no-op.
 *
 * Rows side: ConfigComponentRepository::liveRows() — the same call fromCurrent()
 *   makes, so "the rows store is empty" means precisely what the `!empty($rows)`
 *   branch means, tombstones (removed_at) excluded identically.
 *
 * Comparison key: spec_uuid MULTISET, not (component_type, spec_uuid).
 *   A component's spec_uuid is its identity in ims-data and cannot belong to two
 *   types, while component_type legitimately DIFFERS between the two stores for
 *   one class of component: the 2026-08-14 riser split means a riser is
 *   `pciecard` on the JSON side (jsonFallbackRows() emits the extractor's type
 *   verbatim — it does NOT apply ConfigReadRouter::isRiserPciecard()) and
 *   `risercard` row-side. Keying on type would report every riser-bearing config
 *   as PARTIAL. Keying on spec_uuid asks the question this report actually cares
 *   about — "is this physical component INVISIBLE to the engine" — and a riser
 *   recorded under a different type is not invisible. Types are still carried
 *   into the report file and the printed detail.
 *
 * ---------------------------------------------------------------------------
 * CLASSIFICATION (one per config, first match wins)
 * ---------------------------------------------------------------------------
 *   UNPARSEABLE — at least one legacy JSON column cannot be decoded. RED.
 *       ServerBuilder::safeJsonDecode() degrades a malformed PERSISTED column to
 *       [] on read paths (A-E2), so the extractor reports such a column as ZERO
 *       components — indistinguishable from an empty one. This report therefore
 *       probes each column's decodability itself BEFORE trusting the extracted
 *       set, and refuses to classify the config at all. It is RED because a
 *       config whose JSON-implied set cannot be determined cannot be certified
 *       as not-PARTIAL: "unmeasurable" is not "clean".
 *   EXCLUDED    — is_virtual=1 (which includes every is_sandbox=1 config, since
 *       createConfiguration() forces virtual for a sandbox). Never RED, never
 *       counted as measured. Virtual configs are deliberately outside dual-write:
 *       ServerBuilder's add path gates the ConfigComponentWriter call behind
 *       `if (!$isVirtual)` because a virtual add reserves no inventory row, and
 *       finalizeConfiguration()'s isSandboxConfig() guard refuses a bench build
 *       outright. An empty rows store on such a config is the DESIGN, so calling
 *       it PARTIAL would be reporting the guard as a bug. Sandbox and
 *       virtual-only are labelled separately in the output.
 *   EMPTY       — no rows and no JSON-implied components. Not a defect
 *       (fromCurrent() returns an empty TargetState from either branch) but not
 *       evidence either, so it is reported and NOT counted as measured.
 *   JSON_ONLY   — rows store empty, JSON non-empty. NOT a defect: this is the
 *       `!empty($rows)` test working as intended — the fallback is taken and the
 *       whole config is visible. Today's pre-backfill norm.
 *   PARTIAL     — rows store non-empty AND missing at least one JSON-implied
 *       component. RED. These are exactly the configs fromCurrent() silently
 *       under-reports.
 *   ROWS_ONLY   — rows hold components the JSON side does not imply (either the
 *       JSON side is empty entirely, or rows carry extras). Expected once the
 *       U-D.3 column drop lands; reported, never RED.
 *   COMPLETE    — rows present and accounting for every JSON-implied component.
 *
 * KNOWN ASYMMETRY, deliberately NOT patched: when hbacard_config holds the
 * literal string '[]', extractComponentsFromJson()'s
 * `elseif (!empty($configData['hbacard_uuid']))` never fires ('[]' is not
 * empty()), so a scalar hbacard_uuid is dropped from the JSON side.
 * equivalence_report.php and ConfigReadRouter both re-add it; this report does
 * NOT, because it must mirror what fromCurrent() would see, not what the config
 * really contains. The effect is confined to ROWS_ONLY (an extra row with no
 * JSON counterpart) and can never manufacture a PARTIAL.
 *
 * A ZERO-CONFIG RUN IS RED. This report scans a table that is non-empty in every
 * real environment; measuring nothing means it was pointed somewhere it should
 * not have been, and an empty result set must never print as green (F-10).
 *
 * Usage:
 *   php scripts/verify/partial_rows_report.php                  # same as --all
 *   php scripts/verify/partial_rows_report.php --all
 *   php scripts/verify/partial_rows_report.php --config <uuid>
 *   php scripts/verify/partial_rows_report.php --self-test       # injects a throwaway
 *                                                                  config whose rows
 *                                                                  store covers only
 *                                                                  PART of its JSON,
 *                                                                  proves PARTIAL is
 *                                                                  detected
 *
 * Exit: 0 = green (zero PARTIAL, zero UNPARSEABLE, at least one config measured),
 * 1 = red (or self-test succeeded in detecting its induced defect, mirroring
 * slot_report.php/equivalence_report.php), 2 = usage/setup error.
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
require_once $ROOT . '/core/models/validation/TargetStateBuilder.php';

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO connection not available after bootstrap.\n");
    exit(2);
}

/**
 * Legacy JSON columns, exactly as extractComponentsFromJson() reads them. The
 * naming is irregular on purpose (nic_config and hbacard_config singular,
 * pciecard_configurations plural) — these are the real column names; never
 * "regularise" them here.
 */
const JSON_COLUMNS = [
    'cpu_configuration',
    'ram_configuration',
    'storage_configuration',
    'caddy_configuration',
    'nic_config',
    'hbacard_config',
    'pciecard_configurations',
    'sfp_configuration',
];

/** Scalar legacy columns — single UUIDs, nothing to decode. */
const SCALAR_COLUMNS = ['motherboard_uuid', 'chassis_uuid', 'hbacard_uuid'];

const BATCH_SIZE = 1000;

// -----------------------------------------------------------------------
// Setup guards. Both fail with exit 2 rather than degrading: a report that
// cannot reach the application's own extractor, or that is missing a legacy
// column it claims to cover, must not print a verdict at all.
// -----------------------------------------------------------------------

/**
 * Reflection handle on TargetStateBuilder::jsonFallbackRows(), resolved once.
 * See the file docblock for why this is reflection and not a reimplementation.
 */
function jsonFallbackReflector(): ReflectionMethod
{
    static $method = null;
    if ($method === null) {
        try {
            $method = new ReflectionMethod('TargetStateBuilder', 'jsonFallbackRows');
        } catch (ReflectionException $e) {
            fwrite(STDERR, "partial_rows_report: TargetStateBuilder::jsonFallbackRows() is gone or renamed — "
                . "this report cannot measure the json fallback path without it, and will not guess. "
                . "Re-point it at the method that replaced it. (" . $e->getMessage() . ")\n");
            exit(2);
        }
        if ($method->getNumberOfParameters() !== 2) {
            fwrite(STDERR, "partial_rows_report: TargetStateBuilder::jsonFallbackRows() signature changed "
                . "(expected 2 parameters, found " . $method->getNumberOfParameters()
                . ") — refusing to invoke it blind.\n");
            exit(2);
        }
        $method->setAccessible(true);
    }
    return $method;
}

function assertLegacyColumnsPresent(PDO $pdo): void
{
    $present = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `server_configurations`');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $present[$row['Field']] = true;
        }
    } catch (Exception $e) {
        fwrite(STDERR, "partial_rows_report: cannot read server_configurations columns: " . $e->getMessage() . "\n");
        exit(2);
    }
    $missing = [];
    foreach (array_merge(JSON_COLUMNS, SCALAR_COLUMNS) as $col) {
        if (!isset($present[$col])) {
            $missing[] = $col;
        }
    }
    if ($missing !== []) {
        fwrite(STDERR, "partial_rows_report: server_configurations is missing legacy column(s) "
            . implode(', ', $missing) . " — the JSON-implied side cannot be computed, so no verdict is printed. "
            . "(If U-D.3 has dropped these, this report has outlived the gap it gates and should be retired.)\n");
        exit(2);
    }
}

// -----------------------------------------------------------------------
// Measurement
// -----------------------------------------------------------------------

/**
 * @return array[] the rows fromCurrent() WOULD produce on its json fallback path
 */
function jsonImpliedRows(PDO $pdo, array $configRow): array
{
    return jsonFallbackReflector()->invoke(null, $pdo, $configRow);
}

/**
 * Columns that cannot be decoded, mirroring extractComponentsFromJson()'s own
 * `!empty()` entry guard so a column the extractor never looks at is never
 * blamed. Reported loudly instead of silently becoming "0 components".
 *
 * @return array[] {column, reason} for each undecodable column
 */
function undecodableColumns(array $configRow): array
{
    $bad = [];
    foreach (JSON_COLUMNS as $col) {
        $raw = $configRow[$col] ?? null;
        if (empty($raw)) {
            continue; // exactly the extractor's guard — not read, not blamed
        }
        $decoded = json_decode((string)$raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $bad[] = ['column' => $col, 'reason' => 'malformed: ' . json_last_error_msg()];
            continue;
        }
        if ($decoded === null && trim((string)$raw) !== 'null') {
            $bad[] = ['column' => $col, 'reason' => 'decoded to null'];
            continue;
        }
        if (!is_array($decoded)) {
            $bad[] = ['column' => $col, 'reason' => 'decoded to ' . gettype($decoded) . ', not an array'];
        }
    }
    return $bad;
}

/**
 * spec_uuid multiset plus the type each spec_uuid was first seen under.
 *
 * @return array{counts: array<string,int>, types: array<string,string>}
 */
function specMultiset(array $rows, string $uuidKey, string $typeKey): array
{
    $counts = [];
    $types = [];
    foreach ($rows as $row) {
        $uuid = $row[$uuidKey] ?? null;
        if ($uuid === null || $uuid === '') {
            continue;
        }
        $uuid = (string)$uuid;
        $counts[$uuid] = ($counts[$uuid] ?? 0) + 1;
        if (!isset($types[$uuid])) {
            $types[$uuid] = (string)($row[$typeKey] ?? '?');
        }
    }
    return ['counts' => $counts, 'types' => $types];
}

/**
 * @return array the per-config record written to the report file
 */
function classifyConfig(PDO $pdo, ConfigComponentRepository $repo, array $configRow): array
{
    $configUuid = (string)$configRow['config_uuid'];
    $isVirtual = (int)($configRow['is_virtual'] ?? 0) === 1;
    // No is_sandbox column means seeder 2026_08_18_003 has not been applied here,
    // which means no sandbox has ever been created — "not a sandbox" is then the
    // accurate answer, not a guess (same reasoning as ServerBuilder::isSandboxConfig()).
    $isSandbox = ServerBuilder::sandboxColumnExists($pdo)
        && (int)($configRow['is_sandbox'] ?? 0) === 1;

    $record = [
        'config_uuid'         => $configUuid,
        'config_id'           => isset($configRow['id']) ? (int)$configRow['id'] : null,
        'server_name'         => $configRow['server_name'] ?? null,
        'is_virtual'          => $isVirtual,
        'is_sandbox'          => $isSandbox,
        'status'              => null,
        'json_count'          => null,
        'rows_count'          => null,
        'missing_from_rows'   => [],
        'only_in_rows'        => [],
        'undecodable_columns' => [],
    ];

    $undecodable = undecodableColumns($configRow);
    if ($undecodable !== []) {
        $record['status'] = 'UNPARSEABLE';
        $record['undecodable_columns'] = $undecodable;
        // liveRows() is still recorded — whether a rows store exists at all is the
        // first thing anyone investigating a corrupt column will want to know.
        $record['rows_count'] = count($repo->liveRows($configUuid));
        return $record;
    }

    $jsonRows = jsonImpliedRows($pdo, $configRow);
    $liveRows = $repo->liveRows($configUuid);
    $record['json_count'] = count($jsonRows);
    $record['rows_count'] = count($liveRows);

    $json = specMultiset($jsonRows, 'spec_uuid', 'component_type');
    $rows = specMultiset($liveRows, 'spec_uuid', 'component_type');

    foreach ($json['counts'] as $uuid => $wanted) {
        $have = $rows['counts'][$uuid] ?? 0;
        if ($wanted > $have) {
            $record['missing_from_rows'][] = [
                'spec_uuid'      => $uuid,
                'component_type' => $json['types'][$uuid],
                'json_units'     => $wanted,
                'row_units'      => $have,
            ];
        }
    }
    foreach ($rows['counts'] as $uuid => $have) {
        $wanted = $json['counts'][$uuid] ?? 0;
        if ($have > $wanted) {
            $record['only_in_rows'][] = [
                'spec_uuid'      => $uuid,
                'component_type' => $rows['types'][$uuid],
                'json_units'     => $wanted,
                'row_units'      => $have,
            ];
        }
    }

    if ($isVirtual || $isSandbox) {
        $record['status'] = 'EXCLUDED';
        return $record;
    }
    if ($liveRows === [] && $json['counts'] === []) {
        $record['status'] = 'EMPTY';
    } elseif ($liveRows === []) {
        $record['status'] = 'JSON_ONLY';
    } elseif ($record['missing_from_rows'] !== []) {
        $record['status'] = 'PARTIAL';
    } elseif ($record['only_in_rows'] !== [] || $json['counts'] === []) {
        $record['status'] = 'ROWS_ONLY';
    } else {
        $record['status'] = 'COMPLETE';
    }
    return $record;
}

/** @return array{green: bool, measured: int, by_status: array<string,int>} */
function summarize(array $records): array
{
    $byStatus = [];
    foreach ($records as $r) {
        $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
    }
    $measured = ($byStatus['COMPLETE'] ?? 0) + ($byStatus['JSON_ONLY'] ?? 0)
        + ($byStatus['PARTIAL'] ?? 0) + ($byStatus['ROWS_ONLY'] ?? 0);
    return [
        'green'     => ($byStatus['PARTIAL'] ?? 0) === 0
            && ($byStatus['UNPARSEABLE'] ?? 0) === 0
            && $measured > 0,
        'measured'  => $measured,
        'by_status' => $byStatus,
    ];
}

function writeReport(array $records, string $mode): string
{
    $summary = summarize($records);
    $reportsDir = __DIR__ . '/../../reports';
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0755, true);
    }
    $file = $reportsDir . '/partial-rows-' . date('Ymd-His') . '.json';
    file_put_contents($file, json_encode([
        'report'            => 'partial_rows_report',
        'generated_at'      => date('c'),
        'mode'              => $mode,
        'configs_seen'      => count($records),
        'configs_measured'  => $summary['measured'],
        'counts_by_status'  => $summary['by_status'],
        'partial_count'     => $summary['by_status']['PARTIAL'] ?? 0,
        'unparseable_count' => $summary['by_status']['UNPARSEABLE'] ?? 0,
        'configs'           => $records,
        'status'            => $summary['green'] ? 'GREEN' : 'RED',
    ], JSON_PRETTY_PRINT));
    return $file;
}

function describeUnits(array $entries): string
{
    $parts = [];
    foreach ($entries as $e) {
        $parts[] = "{$e['component_type']}/{$e['spec_uuid']} (json {$e['json_units']} vs rows {$e['row_units']})";
    }
    return implode(', ', $parts);
}

function printDetail(array $r): void
{
    $label = $r['status'];
    if ($label === 'EXCLUDED') {
        $label .= $r['is_sandbox'] ? ' (sandbox)' : ' (virtual)';
    }
    $id = $r['config_id'] !== null ? "id {$r['config_id']} " : '';
    echo "partial_rows_report: {$label} {$id}{$r['config_uuid']}"
        . " json=" . ($r['json_count'] ?? '?') . " rows=" . ($r['rows_count'] ?? '?') . "\n";
    foreach ($r['undecodable_columns'] as $c) {
        echo "    UNDECODABLE COLUMN {$c['column']}: {$c['reason']}\n";
    }
    // The alarming label is printed for PARTIAL only. A JSON_ONLY or EXCLUDED
    // config also has a full missing_from_rows list (its rows store is empty), but
    // there NOTHING is invisible: fromCurrent() takes the json fallback and sees
    // every component. Saying "invisible" about those would be the report crying
    // wolf about the one branch that works. The list is still written to the
    // report file for both.
    if ($r['missing_from_rows'] !== [] && $r['status'] === 'PARTIAL') {
        echo "    INVISIBLE TO THE ENGINE: " . describeUnits($r['missing_from_rows']) . "\n";
    }
    if ($r['only_in_rows'] !== []) {
        echo "    only in rows: " . describeUnits($r['only_in_rows']) . "\n";
    }
}

assertLegacyColumnsPresent($pdo);
jsonFallbackReflector();

// -----------------------------------------------------------------------
// Self-test: build a throwaway config whose rows store covers only PART of
// its legacy JSON, and prove PARTIAL is detected. NOTE: exit() inside a try{}
// does NOT run finally{} in PHP (bug class already hit in
// equivalence_report.php), so cleanup + exit-code selection stay OUTSIDE the
// try/finally below.
// -----------------------------------------------------------------------
if (in_array('--self-test', $argv, true)) {
    $repo = new ConfigComponentRepository($pdo);
    $configUuid = 'SELFTEST-PARTIAL-' . substr(md5(uniqid()), 0, 8);
    $cpuUuid = '561bff6c-3431-4295-8678-1653ad00cd53';
    $ramUuid = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c';

    $record = null;
    try {
        $cols = [
            'config_uuid'          => $configUuid,
            'server_name'          => 'PARTIAL SELFTEST',
            'is_virtual'           => 0,
            'configuration_status' => 1,
            // Two JSON-implied components...
            'cpu_configuration'    => json_encode(['cpus' => [['uuid' => $cpuUuid, 'quantity' => 1]]]),
            'ram_configuration'    => json_encode([['uuid' => $ramUuid, 'quantity' => 1]]),
        ];
        $fields = array_keys($cols);
        $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $fields) . ') VALUES ('
            . implode(',', array_map(fn($x) => ":$x", $fields)) . ')')->execute($cols);

        // ...but only ONE of them mirrored into the rows store. That is the exact
        // shape fromCurrent()'s `!empty($rows)` test cannot tell apart from a
        // fully populated config: it takes the rows path and the RAM disappears.
        $pdo->beginTransaction();
        $repo->insert($configUuid, [
            'component_type'  => 'cpu',
            'inventory_table' => 'cpuinventory',
            'inventory_id'    => random_int(100000, 999999),
            'spec_uuid'       => $cpuUuid,
        ], 1);
        $pdo->commit();

        $stmt = $pdo->prepare('SELECT * FROM server_configurations WHERE config_uuid = ?');
        $stmt->execute([$configUuid]);
        $record = classifyConfig($pdo, $repo, $stmt->fetch(PDO::FETCH_ASSOC));
        writeReport([$record], 'self-test');
    } finally {
        $pdo->exec("DELETE FROM config_resources WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($configUuid));
        $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($configUuid));
    }

    $caught = $record !== null
        && $record['status'] === 'PARTIAL'
        && count($record['missing_from_rows']) === 1
        && $record['missing_from_rows'][0]['spec_uuid'] === $ramUuid;
    if ($caught) {
        printDetail($record);
        echo "partial_rows_report --self-test: PASS (induced partial rows store correctly detected)\n";
        exit(1); // intentional: proves detection
    }
    echo "partial_rows_report --self-test: FAIL (induced partial rows store NOT detected — checker is broken; got "
        . ($record['status'] ?? 'no record') . ")\n";
    exit(0);
}

// -----------------------------------------------------------------------
// Default: --all, or one config via --config <uuid>.
// -----------------------------------------------------------------------
$repo = new ConfigComponentRepository($pdo);
$records = [];
$mode = 'all';

$configIdx = array_search('--config', $argv, true);
if ($configIdx !== false) {
    $target = $argv[$configIdx + 1] ?? null;
    if ($target === null || $target === '') {
        fwrite(STDERR, "Usage: php scripts/verify/partial_rows_report.php [--all] [--config <uuid>] [--self-test]\n");
        exit(2);
    }
    $mode = 'config:' . $target;
    $stmt = $pdo->prepare('SELECT * FROM server_configurations WHERE config_uuid = ?');
    $stmt->execute([$target]);
    $configRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$configRow) {
        fwrite(STDERR, "partial_rows_report: no server_configurations row for config_uuid '$target' — "
            . "nothing was measured, so no verdict is printed.\n");
        exit(2);
    }
    $records[] = classifyConfig($pdo, $repo, $configRow);
} else {
    // Keyset-paginated sweep of EVERY config, virtual ones included: unlike
    // slot_report/equivalence_report (which scan is_virtual = 0 because a virtual
    // config has nothing to compare), this report's whole job is to tell an
    // intentionally-empty rows store apart from an accidentally-partial one, and
    // it can only do that by classifying the excluded configs explicitly rather
    // than dropping them from the SELECT.
    $cursor = '';
    while (true) {
        $stmt = $pdo->prepare(
            'SELECT * FROM server_configurations WHERE config_uuid > ? ORDER BY config_uuid LIMIT ' . BATCH_SIZE
        );
        $stmt->execute([$cursor]);
        $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($batch)) {
            break;
        }
        foreach ($batch as $configRow) {
            $records[] = classifyConfig($pdo, $repo, $configRow);
        }
        $last = end($batch);
        $cursor = (string)$last['config_uuid'];
        if (count($batch) < BATCH_SIZE) {
            break;
        }
    }
}

$file = writeReport($records, $mode);
$summary = summarize($records);
$by = $summary['by_status'];

foreach ($records as $r) {
    if ($r['status'] !== 'COMPLETE') {
        printDetail($r);
    }
}

$parts = [];
foreach (['COMPLETE', 'JSON_ONLY', 'PARTIAL', 'ROWS_ONLY', 'EMPTY', 'EXCLUDED', 'UNPARSEABLE'] as $s) {
    $parts[] = "$s=" . ($by[$s] ?? 0);
}
echo "partial_rows_report: " . count($records) . " config(s) seen, {$summary['measured']} measured; "
    . implode(' ', $parts) . "\n";

if ($summary['measured'] === 0) {
    echo "partial_rows_report: RED 0 configurations measured -- every row was excluded (virtual/sandbox), empty, "
        . "or unparseable, so this run says NOTHING about the fromCurrent() rows/json fallback gap. A zero-sample "
        . "GREEN would be a lie about a table that is never empty in a real environment.\n";
}
if (($by['UNPARSEABLE'] ?? 0) > 0) {
    echo "partial_rows_report: RED " . $by['UNPARSEABLE'] . " config(s) carry an undecodable legacy JSON column -- "
        . "safeJsonDecode() reports such a column as ZERO components on read paths (A-E2), so their JSON-implied "
        . "set is unknowable and they cannot be certified as not-PARTIAL. Fix the column, then re-run.\n";
}
if (($by['PARTIAL'] ?? 0) > 0) {
    echo "partial_rows_report: RED " . $by['PARTIAL'] . " config(s) have a PARTIALLY populated rows store -- "
        . "TargetStateBuilder::fromCurrent() takes the rows path on !empty(\$rows) and the components listed above "
        . "are absent from every TargetState the validation engine builds. At COMMAND_LAYER=enforce that is a live "
        . "404 on remove, not a shadow-log diff.\n";
}
if (($by['ROWS_ONLY'] ?? 0) > 0) {
    echo "partial_rows_report: " . $by['ROWS_ONLY'] . " config(s) hold rows with no JSON counterpart -- expected "
        . "post-migration (and see the hbacard_config '[]' asymmetry in this file's docblock); not a gate failure.\n";
}
if (($by['EXCLUDED'] ?? 0) > 0) {
    $sandbox = count(array_filter($records, fn($r) => $r['status'] === 'EXCLUDED' && $r['is_sandbox']));
    echo "partial_rows_report: " . $by['EXCLUDED'] . " config(s) excluded from dual-write by design "
        . "($sandbox sandbox, " . ($by['EXCLUDED'] - $sandbox) . " virtual-only) -- an empty rows store there is the "
        . "ServerBuilder `if (!\$isVirtual)` guard working, not a defect.\n";
}
if (($by['EMPTY'] ?? 0) > 0) {
    echo "partial_rows_report: " . $by['EMPTY'] . " config(s) have neither rows nor JSON components -- fromCurrent() "
        . "returns an empty TargetState either way, so they are reported but prove nothing and are NOT counted as "
        . "measured.\n";
}

$status = $summary['green'] ? 'GREEN' : 'RED';
echo "partial_rows_report: $status $file\n";
exit($summary['green'] ? 0 : 1);
