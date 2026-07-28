<?php
/**
 * read_report.php — the gating report over reports/shadow/read-*.jsonl
 * (READ_FROM_ROWS=sample evidence). Created 2026-07-29.
 *
 * WHY THIS EXISTS (F-27): nothing consumed read-*.jsonl. Worse, nothing COULD,
 * because until 2026-07-29 ConfigReadRouter logged divergences only — so the
 * artifact produced by "every read agreed" and the artifact produced by "no read
 * ever reached the router" were byte-identical: an absent file.
 *
 * U-X.2's acceptance criterion was written against exactly that artifact:
 *
 *     "READ_FROM_ROWS=sample, >=72h, divergence log must stay empty
 *      (any row => fix unit, restart clock)."
 *
 * A router that never executes satisfies it perfectly. That is the same
 * fail-open shape as F-10 (gate reports exiting 0 having run nothing) and
 * F-8/F-23 (a ratio whose denominator was never established), and it is the
 * third time this migration has shipped a criterion that silence can pass.
 *
 * The router now records every outcome with a 'kind', so this report can ask the
 * question the criterion meant to ask: did production actually exercise the read
 * path, for long enough, without disagreeing?
 *
 * ROW CLASSES
 *   compared        — both sides ran and agreed. THE DENOMINATOR. Nothing else
 *                     in this file is evidence that the router ran at all.
 *   divergence      — the two stores disagree about WHO is in the configuration.
 *                     Always gate-RED; there is deliberately no expected-diffs
 *                     file for this stream (see NO EXEMPTIONS below).
 *   skipped_virtual — a virtual config, which legitimately has no rows and is
 *                     skipped by both dual-write hooks. Recorded so a window of
 *                     nothing but virtual reads cannot be mistaken for a clean
 *                     comparison; counted apart, never as a comparison.
 *   (missing kind)  — written before 2026-07-29, when the only row that existed
 *                     was a divergence. Treated as a divergence, never as a
 *                     comparison: a historical row must not manufacture a
 *                     denominator it never measured.
 *   (unknown kind)  — a kind this report does not recognise, i.e. a writer newer
 *                     than this reader. Gate-RED rather than ignored, so the two
 *                     cannot drift apart silently.
 *
 * NO EXEMPTIONS. parity_report and command_parity_report both read an
 * expected_*.json because legacy and the new engine are entitled to disagree
 * about a VERDICT where an audit finding says legacy is wrong. Two stores
 * disagreeing about which components a configuration contains is never that: one
 * of them is simply wrong about the hardware. The three shape differences =on
 * genuinely cannot reproduce (storage 'connection', scalar-column 'added_at',
 * aggregated 'quantity') are excluded at the source — canonicalTuple() ignores
 * all three — which is what makes a clean window meaningful about IDENTITY and
 * silent about shape. Adding an exemption file here would only ever excuse a real
 * data divergence.
 *
 * F-23 (sapi): cli rows are local harness output and are excluded. Not
 * hypothetical for this stream — on 2026-07-29 the production copy of
 * read-20260728.jsonl held 6 rows, every one sapi=cli and stamped +02:00 while
 * production runs UTC. They were written by a local tree and carried up by SFTP,
 * because reports/ is not in the deploy ignore list. Under U-X.2's original
 * wording those rows alone would have restarted the 72h clock.
 *
 * Usage:
 *   php scripts/verify/read_report.php [--file <path>]...   # default: all reports/shadow/read-*.jsonl
 *   php scripts/verify/read_report.php --since <YYYY-MM-DD[THH:MM[:SS]]>
 *   php scripts/verify/read_report.php --min-hours <N>      # default 72, U-X.2's soak length
 *   php scripts/verify/read_report.php --self-test
 *
 * Exit: 0 = green, 1 = red. Green requires ALL of:
 *   - 0 divergences
 *   - 0 unknown row kinds
 *   - at least one production comparison
 *   - an observed window of at least --min-hours
 * The last two are the point of the file. Dropping either restores a criterion
 * that silence can pass.
 */

declare(strict_types=1);

const KIND_COMPARED        = 'compared';
const KIND_DIVERGENCE      = 'divergence';
const KIND_SKIPPED_VIRTUAL = 'skipped_virtual';

/** U-X.2 checklist item 1. Overridable per-run via --min-hours for ad-hoc reads. */
const MIN_SOAK_HOURS = 72;

/**
 * Reads rows, de-duplicating input files by CONTENT HASH — browser/SFTP
 * re-downloads land as "read-20260728 (1).jsonl" and the default glob matches
 * every copy. Identical logic and identical reason as command_parity_report.php;
 * 11 such duplicate files were found on disk on 2026-07-27.
 *
 * UNDECODABLE LINES ARE COUNTED, not silently dropped (2026-07-29). Found the
 * hard way: a copy of the production log pulled through PowerShell 5.1 picked up
 * a UTF-8 BOM, json_decode returned null on line 1, and every reader skipped it
 * without a word — the row count quietly went from 6 to 5 and nothing said so.
 * A gate whose input can lose rows in silence is not a gate.
 *
 * @return array{rows: array[], duplicates: string[], undecodable: int}
 */
function readReadRows(array $files, ?string $sinceCutoff = null): array {
    $seen = [];
    $duplicates = [];
    $rows = [];
    $undecodable = 0;
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $hash = md5_file($file);
        if (isset($seen[$hash])) {
            $duplicates[] = basename($file) . ' (identical to ' . basename($seen[$hash]) . ')';
            continue;
        }
        $seen[$hash] = $file;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            // A leading BOM is the common cause and is recoverable; anything else
            // is counted and surfaced.
            $decoded = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $line), true);
            if (!is_array($decoded)) { $undecodable++; continue; }
            if ($sinceCutoff !== null && (!isset($decoded['ts']) || substr($decoded['ts'], 0, strlen($sinceCutoff)) < $sinceCutoff)) {
                continue;
            }
            $rows[] = $decoded;
        }
    }
    return ['rows' => $rows, 'duplicates' => $duplicates, 'undecodable' => $undecodable];
}

/**
 * @return array{comparisons:int, divergences:array, virtual_skipped:int, unknown_kind:array,
 *               local_excluded:int, unlabeled_env:int, configs_compared:array,
 *               first_ts:?string, last_ts:?string, window_hours:?float}
 */
function analyzeReadRows(array $rows): array {
    $comparisons = 0;
    $divergences = [];
    $virtualSkipped = 0;
    $unknownKind = [];
    $localExcluded = 0;
    $unlabeledEnv = 0;
    $configsCompared = [];
    $firstEpoch = null;
    $lastEpoch = null;

    foreach ($rows as $row) {
        // F-23: cli rows are local harness output, not production traffic.
        $sapi = $row['sapi'] ?? null;
        if ($sapi === 'cli') {
            $localExcluded++;
            continue;
        }
        if ($sapi === null) {
            $unlabeledEnv++;
        }

        // A row written before 'kind' existed is a divergence — that was the only
        // thing the writer emitted. Defaulting the other way would let history
        // supply a denominator nobody measured.
        $kind = $row['kind'] ?? KIND_DIVERGENCE;

        // The window is measured over every production row, comparisons and
        // skips alike: they all prove the router was reached at that instant.
        // strtotime() compares INSTANTS, which matters because ts carries a UTC
        // offset and the offset is not always +00:00 (see the sapi note above).
        // The --since filter above is a lexicographic string compare and is only
        // sound because production rows are uniformly UTC; this is not.
        if (isset($row['ts'])) {
            $epoch = strtotime((string)$row['ts']);
            if ($epoch !== false) {
                $firstEpoch = $firstEpoch === null ? $epoch : min($firstEpoch, $epoch);
                $lastEpoch  = $lastEpoch === null ? $epoch : max($lastEpoch, $epoch);
            }
        }

        switch ($kind) {
            case KIND_COMPARED:
                $comparisons++;
                if (isset($row['config_uuid'])) {
                    $configsCompared[(string)$row['config_uuid']] = true;
                }
                break;
            case KIND_DIVERGENCE:
                $divergences[] = $row;
                break;
            case KIND_SKIPPED_VIRTUAL:
                $virtualSkipped++;
                break;
            default:
                // A writer this reader does not understand. Counting it as
                // anything would be a guess; RED is the only honest answer.
                $unknownKind[] = $row;
                break;
        }
    }

    $windowHours = ($firstEpoch === null || $lastEpoch === null)
        ? null
        : round(($lastEpoch - $firstEpoch) / 3600, 2);

    return [
        'comparisons'      => $comparisons,
        'divergences'      => $divergences,
        'virtual_skipped'  => $virtualSkipped,
        'unknown_kind'     => $unknownKind,
        'local_excluded'   => $localExcluded,
        'unlabeled_env'    => $unlabeledEnv,
        'configs_compared' => array_keys($configsCompared),
        'first_ts'         => $firstEpoch === null ? null : date('c', $firstEpoch),
        'last_ts'          => $lastEpoch === null ? null : date('c', $lastEpoch),
        'window_hours'     => $windowHours,
    ];
}

/**
 * ONE definition of green, called by both the file writer and the exit code —
 * F-10's lesson (two independently computed statuses drift, and the one that
 * gates is never the one you read).
 */
function readReportGreen(array $analysis, int $minHours): bool {
    return count($analysis['divergences']) === 0
        && count($analysis['unknown_kind']) === 0
        && $analysis['comparisons'] > 0
        && $analysis['window_hours'] !== null
        && $analysis['window_hours'] >= $minHours;
}

function writeReadReport(array $analysis, int $minHours, bool $selfTest): string {
    $reportsDir = __DIR__ . '/../../reports';
    if (!is_dir($reportsDir)) { mkdir($reportsDir, 0755, true); }
    $file = $reportsDir . '/read-' . date('Ymd-His') . ($selfTest ? '-selftest' : '') . '.json';

    $green = readReportGreen($analysis, $minHours);

    file_put_contents($file, json_encode([
        'report'             => 'read_report',
        'generated_at'       => date('c'),
        'self_test'          => $selfTest,
        'comparisons'        => $analysis['comparisons'],
        'divergences'        => count($analysis['divergences']),
        'unknown_kind_rows'  => count($analysis['unknown_kind']),
        'virtual_skipped'    => $analysis['virtual_skipped'],
        'configs_compared'   => $analysis['configs_compared'],
        'local_rows_excluded' => $analysis['local_excluded'],
        'unlabeled_env_rows' => $analysis['unlabeled_env'],
        'window_hours'       => $analysis['window_hours'],
        'min_soak_hours'     => $minHours,
        'first_ts'           => $analysis['first_ts'],
        'last_ts'            => $analysis['last_ts'],
        'divergence_rows'    => $analysis['divergences'],
        'unknown_kind_examples' => array_slice($analysis['unknown_kind'], 0, 10),
        'status'             => $green ? 'GREEN' : 'RED',
    ], JSON_PRETTY_PRINT));
    return $file;
}

// -----------------------------------------------------------------------
// --self-test: synthetic fixture exercising every row class -> must exit 1.
// -----------------------------------------------------------------------
if (in_array('--self-test', $argv, true)) {
    $tmpFile = sys_get_temp_dir() . '/read_report_selftest_' . uniqid() . '.jsonl';
    $base = strtotime('2026-07-01T00:00:00+00:00');
    $at = function (int $hours) use ($base): string { return date('c', $base + $hours * 3600); };

    $syntheticRows = [
        // Production comparisons across a 100h window, two distinct configs.
        ['ts' => $at(0),   'sapi' => 'litespeed', 'kind' => 'compared', 'config_uuid' => 'ST-A', 'legacy_count' => 5, 'rows_count' => 5],
        ['ts' => $at(50),  'sapi' => 'litespeed', 'kind' => 'compared', 'config_uuid' => 'ST-B', 'legacy_count' => 3, 'rows_count' => 3],
        ['ts' => $at(100), 'sapi' => 'litespeed', 'kind' => 'compared', 'config_uuid' => 'ST-A', 'legacy_count' => 5, 'rows_count' => 5],
        // A virtual skip: proves the router ran, is NOT a comparison.
        ['ts' => $at(10),  'sapi' => 'litespeed', 'kind' => 'skipped_virtual', 'config_uuid' => 'ST-V'],
        // A real divergence.
        ['ts' => $at(20),  'sapi' => 'litespeed', 'kind' => 'divergence', 'config_uuid' => 'ST-C',
            'rows_side_empty' => false, 'legacy_count' => 13, 'rows_count' => 12,
            'only_in_json' => [['motherboard', 'mb-uuid', null, null]], 'only_in_rows' => []],
        // Pre-2026-07-29 shape: NO 'kind'. Must count as a divergence.
        ['ts' => $at(21),  'sapi' => 'litespeed', 'config_uuid' => 'ST-D',
            'rows_side_empty' => true, 'legacy_count' => 4, 'rows_count' => 0,
            'only_in_json' => [['cpu', 'cpu-uuid', null, null]], 'only_in_rows' => []],
        // A kind from a newer writer: must be RED, not ignored.
        ['ts' => $at(22),  'sapi' => 'litespeed', 'kind' => 'some_future_kind', 'config_uuid' => 'ST-E'],
        // Local harness rows: excluded whatever they say. THIS is the shape found
        // on production on 2026-07-29 (cli, non-UTC offset, a real divergence).
        ['ts' => '2026-07-28T10:15:10+02:00', 'sapi' => 'cli', 'kind' => 'divergence', 'config_uuid' => 'ST-LOCAL',
            'rows_side_empty' => false, 'legacy_count' => 13, 'rows_count' => 12,
            'only_in_json' => [['motherboard', 'mb-uuid', null, null]], 'only_in_rows' => []],
        ['ts' => '2026-07-28T10:16:13+02:00', 'sapi' => 'cli', 'kind' => 'compared', 'config_uuid' => 'ST-LOCAL'],
        // Predates the sapi field: provenance unknown, counted and warned about.
        ['ts' => $at(30),  'kind' => 'compared', 'config_uuid' => 'ST-F'],
    ];
    file_put_contents($tmpFile, implode("\n", array_map('json_encode', $syntheticRows)) . "\n");

    $read = readReadRows([$tmpFile]);
    $analysis = analyzeReadRows($read['rows']);
    writeReadReport($analysis, MIN_SOAK_HOURS, true);
    @unlink($tmpFile);

    $divergedIds = array_map(function ($r) { return $r['config_uuid'] ?? null; }, $analysis['divergences']);

    $checks = [
        'production comparisons counted'              => $analysis['comparisons'] === 4,
        'distinct configs compared tracked'           => count($analysis['configs_compared']) === 3,
        'an explicit divergence is caught'            => in_array('ST-C', $divergedIds, true),
        'a row with NO kind counts as a divergence, never as a comparison'
                                                      => in_array('ST-D', $divergedIds, true),
        'an unrecognised kind is RED, not ignored'    => count($analysis['unknown_kind']) === 1,
        'a virtual skip is not a comparison'          => $analysis['virtual_skipped'] === 1,
        'cli rows excluded whatever they claim'       => $analysis['local_excluded'] === 2
                                                         && !in_array('ST-LOCAL', $divergedIds, true),
        'rows predating sapi are flagged'             => $analysis['unlabeled_env'] === 1,
        'window measured as INSTANTS across the production rows'
                                                      => $analysis['window_hours'] === 100.0,
        // The three ways silence used to pass, each pinned.
        'a window with zero comparisons is RED'       => readReportGreen(
            ['divergences' => [], 'unknown_kind' => [], 'comparisons' => 0, 'window_hours' => 999.0], 72) === false,
        'a clean but too-short window is RED'         => readReportGreen(
            ['divergences' => [], 'unknown_kind' => [], 'comparisons' => 50, 'window_hours' => 71.9], 72) === false,
        'an empty log is RED (window_hours null)'     => readReportGreen(
            ['divergences' => [], 'unknown_kind' => [], 'comparisons' => 0, 'window_hours' => null], 72) === false,
        'a long, exercised, clean window is GREEN'    => readReportGreen(
            ['divergences' => [], 'unknown_kind' => [], 'comparisons' => 50, 'window_hours' => 72.0], 72) === true,
    ];
    $failed = array_keys(array_filter($checks, function ($ok) { return !$ok; }));
    if ($failed === []) {
        echo "read_report --self-test: PASS (all " . count($checks) . " checks; every row class classified correctly)\n";
        exit(1); // intentional, mirrors the other two parity reports: proves detection works
    }
    echo "read_report --self-test: FAIL -- " . implode('; ', $failed) . "\n";
    exit(0);
}

// -----------------------------------------------------------------------
// Normal mode
// -----------------------------------------------------------------------
$fileArgs = [];
$sinceCutoff = null;
$minHours = MIN_SOAK_HOURS;
foreach ($argv as $i => $arg) {
    if ($arg === '--file' && isset($argv[$i + 1])) {
        $fileArgs[] = $argv[$i + 1];
    }
    if ($arg === '--since' && isset($argv[$i + 1])) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/', $argv[$i + 1]) !== 1) {
            fwrite(STDERR, "read_report: --since expects YYYY-MM-DD or YYYY-MM-DDTHH:MM[:SS], got '{$argv[$i + 1]}'\n");
            exit(1);
        }
        $sinceCutoff = str_replace(' ', 'T', $argv[$i + 1]);
    }
    if ($arg === '--min-hours' && isset($argv[$i + 1])) {
        if (preg_match('/^\d+$/', $argv[$i + 1]) !== 1) {
            fwrite(STDERR, "read_report: --min-hours expects a whole number of hours, got '{$argv[$i + 1]}'\n");
            exit(1);
        }
        $minHours = (int)$argv[$i + 1];
    }
}
$files = $fileArgs ?: glob(__DIR__ . '/../../reports/shadow/read-*.jsonl') ?: [];

$read = readReadRows($files, $sinceCutoff);
$analysis = analyzeReadRows($read['rows']);
$file = writeReadReport($analysis, $minHours, false);

$green = readReportGreen($analysis, $minHours);
$status = $green ? 'GREEN' : 'RED';

if ($read['duplicates'] !== []) {
    echo "read_report: " . count($read['duplicates']) . " duplicate input file(s) skipped: " . implode('; ', $read['duplicates']) . "\n";
}
if ($read['undecodable'] > 0) {
    echo "read_report: WARNING {$read['undecodable']} input line(s) could not be decoded and were dropped -- "
        . "the window below is measured over FEWER rows than the log contains\n";
}
if ($analysis['local_excluded'] > 0) {
    echo "read_report: {$analysis['local_excluded']} local cli row(s) excluded (F-23: harness output, not production traffic)\n";
}
if ($analysis['unlabeled_env'] > 0) {
    echo "read_report: WARNING {$analysis['unlabeled_env']} row(s) predate the sapi field (F-23) -- provenance unknown\n";
}
if ($analysis['virtual_skipped'] > 0) {
    echo "read_report: {$analysis['virtual_skipped']} virtual config read(s) skipped -- the router ran, but no comparison was performed\n";
}
if ($analysis['comparisons'] === 0) {
    echo "read_report: RED 0 production comparisons -- READ_FROM_ROWS=sample has not been exercised by real traffic "
        . "in this window, so an absence of divergences says NOTHING about the read path (F-27)\n";
} else {
    echo "read_report: {$analysis['comparisons']} comparison(s) across "
        . count($analysis['configs_compared']) . " config(s); window "
        . ($analysis['window_hours'] ?? 0) . "h of {$minHours}h required\n";
    if (count($analysis['configs_compared']) === 1) {
        echo "read_report: WARNING every comparison came from ONE configuration -- weak coverage for a fleet-wide flag\n";
    }
}
if ($analysis['window_hours'] !== null && $analysis['window_hours'] < $minHours) {
    echo "read_report: RED observed window {$analysis['window_hours']}h is shorter than the {$minHours}h soak "
        . "(U-X.2 checklist item 1); use --min-hours for an ad-hoc read\n";
}
if (count($analysis['unknown_kind']) > 0) {
    echo "read_report: RED " . count($analysis['unknown_kind']) . " row(s) carry a kind this report does not "
        . "recognise -- the writer is newer than the reader, do not interpret this window\n";
}
if (count($analysis['divergences']) > 0) {
    echo "read_report: RED " . count($analysis['divergences']) . " divergence(s) -- the JSON and rows stores "
        . "disagree about configuration membership; per U-X.2 item 1 this restarts the soak clock\n";
    foreach (array_slice($analysis['divergences'], 0, 5) as $row) {
        echo "  - " . ($row['config_uuid'] ?? '?') . " legacy=" . ($row['legacy_count'] ?? '?')
            . " rows=" . ($row['rows_count'] ?? '?')
            . ($row['rows_side_empty'] ?? false ? ' [ROWS SIDE EMPTY -- never dual-written or backfilled]' : '') . "\n";
    }
}
if ($sinceCutoff !== null) {
    echo "read_report: rows before $sinceCutoff excluded\n";
}

echo "read_report: $status $file\n";
exit($green ? 0 : 1);
