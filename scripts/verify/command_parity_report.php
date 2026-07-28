<?php
/**
 * command_parity_report.php — the gating report over reports/shadow/command-*.jsonl
 * (COMMAND_LAYER_ENABLED=shadow evidence). Created 2026-07-27.
 *
 * WHY THIS EXISTS: until now nothing consumed command-*.jsonl at all, so
 * COMMAND_LAYER's shadow soak was entirely unverified even while the flag sat at
 * shadow in production — U-C.6's enforce soak is strictly downstream of evidence
 * this report is supposed to produce.
 *
 * Sibling of parity_report.php but NOT a copy, because the two logs differ in
 * kind:
 *
 *   - engine-*.jsonl rows carry a full per-rule `results[]` array, so
 *     expected_diffs.json can require that a named rule actually fired on the
 *     row it excuses (parity_report.php's ruleFailedOnRow()). command-*.jsonl
 *     carries only `command_failures[]` — the rule ids that FAILED — so the
 *     equivalent check is "is the cited rule among this row's failures".
 *   - command rows have no advisory/authoritative split (F-8): the writer runs
 *     once per request, which is exactly what made it the independent counter
 *     that proved F-8. No phase filtering is needed here.
 *
 * SCOPE — add and remove only. replace-component and transition-status are
 * v2-only actions with no legacy counterpart (08-api-adapters/DEPRECATION.md),
 * so they have no comparable verdict and are correctly absent from the log; a
 * row for either is reported as out-of-scope rather than silently counted.
 *
 * A diff = legacy_blocked !== command_blocked. Same convention as
 * parity_report.php: a same-direction block with different rule identity is NOT
 * a diff. A diff is EXPECTED iff it matches an entry in
 * scripts/verify/expected_command_diffs.json citing an audit finding; anything
 * else is UNEXPLAINED and keeps the gate closed.
 *
 * THREE ROW CLASSES THAT ARE NOT PARITY EVIDENCE, counted and reported apart so
 * they can never green-wash the gate:
 *   - legacy_unknown  — `legacy_blocked` null/absent. Every pre-2026-07-27
 *                       remove row is this: it was written before legacy ran, so
 *                       agreement and divergence are indistinguishable in it.
 *   - dry_run_failed  — the command's dryRun() threw, so there is no command
 *                       verdict to compare. Counted as an EXCEPTION (gate-RED),
 *                       mirroring parity_report.php's treatment of
 *                       engine.build_exception.
 *   - out_of_scope    — an op other than add/remove.
 *
 * Usage:
 *   php scripts/verify/command_parity_report.php [--file <path>]...   # default: all reports/shadow/command-*.jsonl
 *   php scripts/verify/command_parity_report.php --since <YYYY-MM-DD>
 *   php scripts/verify/command_parity_report.php --self-test
 *
 * Exit: 0 = green (0 unexplained diffs AND 0 dry-run failures), 1 = red.
 * An empty window is green but prints a loud WARNING — a zero-sample green
 * proves nothing was exercised, which for this log was the status quo for the
 * entire soak to date.
 */

declare(strict_types=1);

const EXPECTED_COMMAND_DIFFS_FILE = __DIR__ . '/expected_command_diffs.json';
const IN_SCOPE_OPS = ['add', 'remove'];

/** @return array[] entries from expected_command_diffs.json */
function loadExpectedCommandDiffs(): array {
    if (!file_exists(EXPECTED_COMMAND_DIFFS_FILE)) {
        return [];
    }
    $decoded = json_decode(file_get_contents(EXPECTED_COMMAND_DIFFS_FILE), true);
    if (!is_array($decoded) || !isset($decoded['entries']) || !is_array($decoded['entries'])) {
        throw new \RuntimeException('expected_command_diffs.json is malformed (expected {"entries": [...]})');
    }
    foreach ($decoded['entries'] as $i => $entry) {
        foreach (['rule_id', 'audit_finding', 'legacy_blocked', 'command_blocked'] as $required) {
            if (!array_key_exists($required, $entry)) {
                throw new \RuntimeException("expected_command_diffs.json entries[$i] missing required field '$required'");
            }
        }
    }
    return $decoded['entries'];
}

/**
 * Reads rows, de-duplicating input files by CONTENT HASH first — browser/SFTP
 * re-downloads land as "command-20260723 (1).jsonl" and the default glob matches
 * every copy, which silently multiplied the row count in the engine log's case
 * (11 duplicate files found on disk 2026-07-27). Name is not parsed: two files
 * collapse iff they are byte-identical.
 *
 * @return array{rows: array[], duplicates: string[]}
 */
function readCommandRows(array $files, ?string $sinceCutoff = null): array {
    $seen = [];
    $duplicates = [];
    $rows = [];
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $hash = md5_file($file);
        if (isset($seen[$hash])) {
            $duplicates[] = basename($file) . ' (identical to ' . basename($seen[$hash]) . ')';
            continue;
        }
        $seen[$hash] = $file;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) continue;
            if ($sinceCutoff !== null && (!isset($decoded['ts']) || substr($decoded['ts'], 0, 10) < $sinceCutoff)) {
                continue;
            }
            $rows[] = $decoded;
        }
    }
    return ['rows' => $rows, 'duplicates' => $duplicates];
}

/** True iff $ruleId is among the rule ids the command reported as failing. */
function ruleAmongFailures(string $ruleId, array $row): bool {
    return in_array($ruleId, $row['command_failures'] ?? [], true);
}

function matchesExpectedCommand(array $entry, array $row): bool {
    if ($entry['legacy_blocked'] !== $row['legacy_blocked']) return false;
    if ($entry['command_blocked'] !== $row['command_blocked']) return false;
    if (array_key_exists('op', $entry) && $entry['op'] !== null && $entry['op'] !== ($row['op'] ?? null)) {
        return false;
    }
    // As in parity_report.php: an exemption must NAME the rule that earned it,
    // otherwise one entry silently absolves every divergence in its direction.
    return ruleAmongFailures($entry['rule_id'], $row);
}

/**
 * @return array{operations_compared:int, identical:int, expected:array, unexplained:array,
 *               dry_run_failed:int, legacy_unknown:int, out_of_scope:int, rule_coverage:array,
 *               ops_seen:array}
 */
function analyzeCommandRows(array $rows, array $expectedDiffs): array {
    $identical = 0;
    $expected = [];
    $unexplained = [];
    $dryRunFailed = 0;
    $legacyUnknown = 0;
    $outOfScope = 0;
    $localExcluded = 0;
    $unlabeledEnv = 0;
    $ruleCoverage = [];
    $opsSeen = [];
    $compared = 0;

    foreach ($rows as $row) {
        // F-23: cli rows are local harness output, not production operations.
        // See ShadowRunner::record()'s 'sapi' note.
        $sapi = $row['sapi'] ?? null;
        if ($sapi === 'cli') {
            $localExcluded++;
            continue;
        }
        if ($sapi === null) {
            $unlabeledEnv++;
        }

        $op = $row['op'] ?? null;
        if ($op === null || !in_array($op, IN_SCOPE_OPS, true)) {
            $outOfScope++;
            continue;
        }
        $opsSeen[$op] = ($opsSeen[$op] ?? 0) + 1;

        foreach ($row['command_failures'] ?? [] as $ruleId) {
            $ruleCoverage[$ruleId] = ($ruleCoverage[$ruleId] ?? 0) + 1;
        }

        // No command verdict exists -> nothing to compare, and a thrown dry run is
        // itself a defect signal, so it is gate-RED rather than merely skipped.
        if (!empty($row['dry_run_failed']) || !array_key_exists('command_blocked', $row) || $row['command_blocked'] === null) {
            $dryRunFailed++;
            continue;
        }

        // Pre-2026-07-27 remove rows: written before legacy ran, so agreement and
        // divergence are indistinguishable. Not evidence in either direction.
        if (!array_key_exists('legacy_blocked', $row) || $row['legacy_blocked'] === null) {
            $legacyUnknown++;
            continue;
        }

        $compared++;
        if ($row['legacy_blocked'] === $row['command_blocked']) {
            $identical++;
            continue;
        }

        $matched = null;
        foreach ($expectedDiffs as $entry) {
            if (matchesExpectedCommand($entry, $row)) {
                $matched = $entry;
                break;
            }
        }
        if ($matched !== null) {
            $expected[] = ['row' => $row, 'entry' => $matched];
        } else {
            $unexplained[] = $row;
        }
    }

    return [
        'operations_compared' => $compared,
        'identical' => $identical,
        'expected' => $expected,
        'unexplained' => $unexplained,
        'dry_run_failed' => $dryRunFailed,
        'legacy_unknown' => $legacyUnknown,
        'out_of_scope' => $outOfScope,
        'local_excluded' => $localExcluded,
        'unlabeled_env' => $unlabeledEnv,
        'rule_coverage' => $ruleCoverage,
        'ops_seen' => $opsSeen,
    ];
}

function writeCommandReport(array $analysis, bool $selfTest): string {
    $reportsDir = __DIR__ . '/../../reports';
    if (!is_dir($reportsDir)) { mkdir($reportsDir, 0755, true); }
    $file = $reportsDir . '/command-parity-' . date('Ymd-His') . ($selfTest ? '-selftest' : '') . '.json';

    $green = count($analysis['unexplained']) === 0 && $analysis['dry_run_failed'] === 0;

    file_put_contents($file, json_encode([
        'report' => 'command_parity_report',
        'generated_at' => date('c'),
        'self_test' => $selfTest,
        'operations_compared' => $analysis['operations_compared'],
        'identical_verdicts' => $analysis['identical'],
        'diffs_expected' => count($analysis['expected']),
        'diffs_unexplained' => count($analysis['unexplained']),
        'dry_run_failures' => $analysis['dry_run_failed'],
        'legacy_unknown_rows' => $analysis['legacy_unknown'],
        'out_of_scope_rows' => $analysis['out_of_scope'],
        'local_rows_excluded' => $analysis['local_excluded'],
        'unlabeled_env_rows' => $analysis['unlabeled_env'],
        'ops_seen' => $analysis['ops_seen'],
        'rule_coverage' => $analysis['rule_coverage'],
        'unexplained_rows' => $analysis['unexplained'],
        'expected_rows' => $analysis['expected'],
        'status' => $green ? 'GREEN' : 'RED',
    ], JSON_PRETTY_PRINT));
    return $file;
}

// -----------------------------------------------------------------------
// --self-test: synthetic fixture exercising every row class -> must exit 1.
// -----------------------------------------------------------------------
if (in_array('--self-test', $argv, true)) {
    $tmpFile = sys_get_temp_dir() . '/command_parity_selftest_' . uniqid() . '.jsonl';
    $syntheticRows = [
        // agreement -- both allow
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-1', 'op' => 'add', 'component_type' => 'cpu',
            'legacy_blocked' => false, 'command_blocked' => false, 'command_failures' => [], 'dry_run_failed' => false],
        // agreement -- both block
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-2', 'op' => 'remove', 'component_type' => 'nic',
            'legacy_blocked' => true, 'command_blocked' => true, 'command_failures' => ['dependency.blocked_removal'], 'dry_run_failed' => false],
        // EXPECTED diff -- matches the seeded entry below
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-3', 'op' => 'remove', 'component_type' => 'chassis',
            'legacy_blocked' => false, 'command_blocked' => true, 'command_failures' => ['selftest.expected_rule'], 'dry_run_failed' => false],
        // UNEXPLAINED diff -- matches nothing
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-4', 'op' => 'add', 'component_type' => 'storage',
            'legacy_blocked' => false, 'command_blocked' => true, 'command_failures' => ['selftest.unknown_rule'], 'dry_run_failed' => false],
        // legacy_unknown -- the pre-2026-07-27 remove shape
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-5', 'op' => 'remove', 'component_type' => 'chassis',
            'command_blocked' => true, 'command_failures' => ['dependency.blocked_removal']],
        // dry_run_failed -- no command verdict
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-6', 'op' => 'add', 'component_type' => 'ram',
            'legacy_blocked' => false, 'command_blocked' => null, 'command_failures' => [], 'dry_run_failed' => true],
        // out_of_scope -- v2-only op with no legacy counterpart
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-7', 'op' => 'replace', 'component_type' => 'cpu',
            'legacy_blocked' => null, 'command_blocked' => false, 'command_failures' => []],
    ];
    file_put_contents($tmpFile, implode("\n", array_map('json_encode', $syntheticRows)) . "\n");

    $syntheticExpected = [
        ['rule_id' => 'selftest.expected_rule', 'audit_finding' => 'SELFTEST-FINDING',
            'legacy_blocked' => false, 'command_blocked' => true, 'op' => 'remove'],
    ];

    $read = readCommandRows([$tmpFile]);
    $analysis = analyzeCommandRows($read['rows'], $syntheticExpected);
    writeCommandReport($analysis, true);
    @unlink($tmpFile);

    $checks = [
        'one unexplained diff, correctly identified' => count($analysis['unexplained']) === 1
            && ($analysis['unexplained'][0]['config_uuid'] ?? null) === 'SELFTEST-4',
        'one expected diff matched'                  => count($analysis['expected']) === 1,
        'two agreements counted'                     => $analysis['identical'] === 2,
        'legacy_unknown row excluded'                => $analysis['legacy_unknown'] === 1,
        'dry-run failure counted'                    => $analysis['dry_run_failed'] === 1,
        'out-of-scope op excluded'                   => $analysis['out_of_scope'] === 1,
        'operations_compared excludes non-evidence'  => $analysis['operations_compared'] === 4,
    ];
    $failed = array_keys(array_filter($checks, function ($ok) { return !$ok; }));
    if ($failed === []) {
        echo "command_parity_report --self-test: PASS (all 7 row classes classified correctly)\n";
        exit(1); // intentional, mirrors parity_report.php: proves detection works
    }
    echo "command_parity_report --self-test: FAIL -- " . implode('; ', $failed) . "\n";
    exit(0);
}

// -----------------------------------------------------------------------
// Normal mode
// -----------------------------------------------------------------------
$fileArgs = [];
$sinceCutoff = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--file' && isset($argv[$i + 1])) {
        $fileArgs[] = $argv[$i + 1];
    }
    if ($arg === '--since' && isset($argv[$i + 1])) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[$i + 1]) !== 1) {
            fwrite(STDERR, "command_parity_report: --since expects YYYY-MM-DD, got '{$argv[$i + 1]}'\n");
            exit(1);
        }
        $sinceCutoff = $argv[$i + 1];
    }
}
$files = $fileArgs ?: glob(__DIR__ . '/../../reports/shadow/command-*.jsonl') ?: [];

$expectedDiffs = loadExpectedCommandDiffs();
$read = readCommandRows($files, $sinceCutoff);
$analysis = analyzeCommandRows($read['rows'], $expectedDiffs);
$file = writeCommandReport($analysis, false);

$green = count($analysis['unexplained']) === 0 && $analysis['dry_run_failed'] === 0;
$status = $green ? 'GREEN' : 'RED';

if ($read['duplicates'] !== []) {
    echo "command_parity_report: " . count($read['duplicates']) . " duplicate input file(s) skipped: " . implode('; ', $read['duplicates']) . "\n";
}
if ($analysis['operations_compared'] === 0) {
    echo "command_parity_report: WARNING operations compared: 0 -- a zero-sample GREEN proves nothing was exercised\n";
}
if ($analysis['legacy_unknown'] > 0) {
    echo "command_parity_report: WARNING {$analysis['legacy_unknown']} row(s) carry no legacy verdict (pre-2026-07-27 remove shape) -- not parity evidence, excluded\n";
}
if ($analysis['local_excluded'] > 0) {
    echo "command_parity_report: {$analysis['local_excluded']} local cli row(s) excluded (F-23: harness output, not production traffic)\n";
}
if ($analysis['unlabeled_env'] > 0) {
    echo "command_parity_report: WARNING {$analysis['unlabeled_env']} row(s) predate the sapi field (F-23) -- provenance unknown\n";
}
if ($analysis['out_of_scope'] > 0) {
    echo "command_parity_report: {$analysis['out_of_scope']} out-of-scope row(s) excluded (ops other than " . implode('/', IN_SCOPE_OPS) . ")\n";
}
if ($analysis['dry_run_failed'] > 0) {
    echo "command_parity_report: {$analysis['dry_run_failed']} dry-run failure(s) -- no command verdict to compare, gate RED\n";
}
if ($sinceCutoff !== null) {
    echo "command_parity_report: --since $sinceCutoff applied\n";
}
echo "command_parity_report: $status $file\n";
exit($green ? 0 : 1);
