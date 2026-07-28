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
 * SCOPE — add, remove, and (since 2026-07-29) finalize. replace-component and
 * the standalone server-transition-status action remain v2-only with no legacy
 * counterpart (08-api-adapters/DEPRECATION.md), so they have no comparable
 * verdict and are correctly absent from the log; a row for either is reported
 * as out-of-scope rather than silently counted.
 *
 * FINALIZE is comparable even though transition-status in general is not, and
 * it is the ONLY op that covers the four Trigger::FINALIZE rules
 * (system.singleton, system.inventory_state, system.psu_capacity,
 * system.required_set). Its legacy counterpart is the API-layer
 * validateConfigurationComprehensive() pre-check in handleFinalizeConfiguration()
 * — precisely the check COMMAND_LAYER=enforce DELETES in the command's favour.
 * Before the U-A.2 hook (2026-07-29) those four rules had never executed in
 * production and no volume of traffic could have changed that, so `rule_coverage`
 * showing zero of them is the signal that the soak is not yet evidence.
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
 *   - dry_run_failed  — the command's dryRun() CRASHED (dry_run_error 'exception',
 *                       or absent on a row predating the field). No verdict in any
 *                       sense; gate-RED, mirroring parity_report.php's treatment of
 *                       engine.build_exception. NOTE (2026-07-29): a dryRun() that
 *                       threw CommandFailed is NOT this class -- transition_denied /
 *                       config_immutable / revision_mismatch / config_not_found are
 *                       the answer the caller gets at enforce, so those rows are
 *                       compared as command_blocked=true. Folding them in here made
 *                       every legitimate refusal permanently gate-RED, so the gate
 *                       could never go green on real traffic however well the two
 *                       sides agreed -- and it HID the dangerous case where the
 *                       command refuses something legacy allows.
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
const IN_SCOPE_OPS = ['add', 'remove', 'finalize'];

/**
 * The Trigger::FINALIZE rule registry. Coverage of these is reported explicitly:
 * a GREEN command-parity run that has never fired a single one of them is not
 * evidence that enforce is safe, it is evidence the path was never walked — the
 * golden-master trap that produced F-19.
 */
const FINALIZE_RULES = [
    'system.singleton',
    'system.inventory_state',
    'system.psu_capacity',
    'system.required_set',
];

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
        // rule_id may be null ONLY when legacy_error_type names the diff instead
        // (see matchesExpectedCommand). An entry naming neither would match every
        // row in its direction -- a blanket exemption, which is the one thing this
        // file must never contain.
        $namesRule = $entry['rule_id'] !== null && $entry['rule_id'] !== '';
        $namesLegacyType = ($entry['legacy_error_type'] ?? null) !== null && $entry['legacy_error_type'] !== '';
        if (!$namesRule && !$namesLegacyType) {
            throw new \RuntimeException("expected_command_diffs.json entries[$i] must name either a rule_id or a legacy_error_type");
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
 * UNDECODABLE LINES ARE COUNTED (2026-07-29): a BOM-prefixed first line made
 * json_decode return null and this loop dropped the row without a word. Same
 * change in parity_report.php and read_report.php -- see read_report.php's note.
 *
 * @return array{rows: array[], duplicates: string[], undecodable: int}
 */
function readCommandRows(array $files, ?string $sinceCutoff = null): array {
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
    // As in parity_report.php: an exemption must NAME the thing that earned it,
    // otherwise one entry silently absolves every divergence in its direction.
    //
    // Which identifier is nameable depends on the DIRECTION of the diff:
    //   command blocked, legacy did not -> cite the command rule that fired.
    //   legacy blocked, command did not -> the command failed NOTHING, so there
    //      is no rule id to cite. Cite legacy's `type` slug instead
    //      (legacy_error_types, logged by the finalize hook). Requiring rule_id
    //      here would make this direction permanently inexplicable, and the
    //      tempting workaround -- citing an unrelated non-blocking WARNING that
    //      happens to appear in command_failures -- would be a false exemption.
    if (array_key_exists('legacy_error_type', $entry) && $entry['legacy_error_type'] !== null) {
        return in_array($entry['legacy_error_type'], $row['legacy_error_types'] ?? [], true);
    }
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

        // A thrown dry run is not one thing (2026-07-29). dryRun() raises
        // CommandFailed for transition_denied / config_immutable / revision_mismatch
        // / config_not_found -- at enforce those ARE the answer the caller gets, so
        // the command's verdict is "blocked", delivered as an exception instead of a
        // Verdict. Discarding those rows made every legitimate refusal permanently
        // gate-RED, which is why the command gate could not go green on real traffic
        // no matter how well the two sides agreed.
        //
        // A genuine crash is still uncomparable and STILL gate-RED. The writer
        // distinguishes them (dry_run_error: 'command_failed:<type>' vs 'exception');
        // a row from before that field existed carries neither, and is treated as a
        // crash -- unknown provenance must not be promoted into evidence.
        $dryRunError = $row['dry_run_error'] ?? null;
        $refusedByCommand = is_string($dryRunError) && strpos($dryRunError, 'command_failed') === 0;
        $commandBlocked = $row['command_blocked'] ?? null;
        if ($commandBlocked === null && $refusedByCommand) {
            $commandBlocked = true;
        }
        if ($commandBlocked === null) {
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
        if ($row['legacy_blocked'] === $commandBlocked) {
            $identical++;
            continue;
        }

        // Expected-diff matching reads command_blocked off the row, so a row whose
        // verdict came from a refusal must carry it explicitly rather than as null.
        $row['command_blocked'] = $commandBlocked;

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

    // Which of the four FINALIZE-trigger rules this window actually exercised.
    // A rule only appears in command_failures[] when it FAILED, so absence is
    // ambiguous on its own -- hence finalize_ops (the denominator) is reported
    // alongside it. Zero finalize ops means none of the four ran, full stop.
    $finalizeCoverage = [];
    foreach (FINALIZE_RULES as $ruleId) {
        $finalizeCoverage[$ruleId] = $ruleCoverage[$ruleId] ?? 0;
    }

    return [
        'operations_compared' => $compared,
        'finalize_ops' => $opsSeen['finalize'] ?? 0,
        'finalize_rule_coverage' => $finalizeCoverage,
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

/**
 * ONE definition of green, called by both the file writer and the exit code —
 * F-10's lesson (two independently computed statuses drift, and the one that
 * gates is never the one you read).
 *
 * finalize_ops === 0 is RED, not a warning. COMMAND_LAYER=enforce deletes the
 * legacy finalize pre-check and hands finalize to the four Trigger::FINALIZE
 * rules; a window containing zero finalize operations has produced exactly zero
 * evidence about that swap, and passing it would be the same zero-sample
 * green-wash this report was built to prevent.
 */
function commandParityGreen(array $analysis): bool {
    return count($analysis['unexplained']) === 0
        && $analysis['dry_run_failed'] === 0
        && $analysis['finalize_ops'] > 0;
}

function writeCommandReport(array $analysis, bool $selfTest): string {
    $reportsDir = __DIR__ . '/../../reports';
    if (!is_dir($reportsDir)) { mkdir($reportsDir, 0755, true); }
    $file = $reportsDir . '/command-parity-' . date('Ymd-His') . ($selfTest ? '-selftest' : '') . '.json';

    $green = commandParityGreen($analysis);

    file_put_contents($file, json_encode([
        'report' => 'command_parity_report',
        'generated_at' => date('c'),
        'self_test' => $selfTest,
        'operations_compared' => $analysis['operations_compared'],
        'finalize_ops' => $analysis['finalize_ops'],
        'finalize_rule_coverage' => $analysis['finalize_rule_coverage'],
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
        // dry_run_failed with NO dry_run_error -- a crash, or a row predating the
        // field: uncomparable, gate-RED.
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-6', 'op' => 'add', 'component_type' => 'ram',
            'legacy_blocked' => false, 'command_blocked' => null, 'command_failures' => [], 'dry_run_failed' => true],
        // dry_run_failed with dry_run_error='exception' -- an explicit crash: still RED.
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-11', 'op' => 'finalize', 'component_type' => null,
            'legacy_blocked' => true, 'command_blocked' => null, 'command_failures' => [],
            'dry_run_failed' => true, 'dry_run_error' => 'exception'],
        // dry_run_failed with dry_run_error='command_failed:*' -- a DECISION. Both
        // sides refused, so this is an AGREEMENT, not a discarded row.
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-12', 'op' => 'finalize', 'component_type' => null,
            'legacy_blocked' => true, 'command_blocked' => null, 'command_failures' => [],
            'dry_run_failed' => true, 'dry_run_error' => 'command_failed:transition_denied'],
        // Same, but legacy ALLOWED: the command refuses what legacy permits -- the
        // dangerous direction, and it must surface as an unexplained DIFF (it used to
        // vanish into the dry-run bucket).
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-13', 'op' => 'finalize', 'component_type' => null,
            'legacy_blocked' => false, 'command_blocked' => null, 'command_failures' => [],
            'dry_run_failed' => true, 'dry_run_error' => 'command_failed:config_immutable'],
        // out_of_scope -- v2-only op with no legacy counterpart
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-7', 'op' => 'replace', 'component_type' => 'cpu',
            'legacy_blocked' => null, 'command_blocked' => false, 'command_failures' => []],
        // finalize agreement -- in scope since 2026-07-29, and the only op that
        // can put a Trigger::FINALIZE rule into finalize_rule_coverage.
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-8', 'op' => 'finalize', 'component_type' => null,
            'legacy_blocked' => true, 'command_blocked' => true, 'command_failures' => ['system.psu_capacity'], 'dry_run_failed' => false],
        // EXPECTED diff in the legacy-blocked/command-allowed direction, where the
        // command failed nothing and only legacy_error_types can name it.
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-9', 'op' => 'finalize', 'component_type' => null,
            'legacy_blocked' => true, 'command_blocked' => false, 'command_failures' => ['memory.downclock'],
            'legacy_error_types' => ['selftest_legacy_bug'], 'dry_run_failed' => false],
        // UNEXPLAINED diff in that SAME direction: identical shape, different legacy
        // type. Proves the legacy_error_type exemption is specific and not a blanket.
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-10', 'op' => 'finalize', 'component_type' => null,
            'legacy_blocked' => true, 'command_blocked' => false, 'command_failures' => ['memory.downclock'],
            'legacy_error_types' => ['some_other_type'], 'dry_run_failed' => false],
    ];
    file_put_contents($tmpFile, implode("\n", array_map('json_encode', $syntheticRows)) . "\n");

    $syntheticExpected = [
        ['rule_id' => 'selftest.expected_rule', 'audit_finding' => 'SELFTEST-FINDING',
            'legacy_blocked' => false, 'command_blocked' => true, 'op' => 'remove'],
        ['rule_id' => null, 'legacy_error_type' => 'selftest_legacy_bug', 'audit_finding' => 'SELFTEST-LEGACY-BUG',
            'legacy_blocked' => true, 'command_blocked' => false, 'op' => 'finalize'],
    ];

    $read = readCommandRows([$tmpFile]);
    $analysis = analyzeCommandRows($read['rows'], $syntheticExpected);
    writeCommandReport($analysis, true);
    @unlink($tmpFile);

    $unexplainedIds = array_map(function ($r) { return $r['config_uuid'] ?? null; }, $analysis['unexplained']);
    $checks = [
        'three unexplained diffs, correctly identified' => count($analysis['unexplained']) === 3
            && in_array('SELFTEST-4', $unexplainedIds, true)
            && in_array('SELFTEST-10', $unexplainedIds, true)
            && in_array('SELFTEST-13', $unexplainedIds, true),
        'two expected diffs matched'                 => count($analysis['expected']) === 2,
        'a legacy_error_type exemption is SPECIFIC (SELFTEST-10 not absolved by SELFTEST-9\'s entry)'
                                                     => in_array('SELFTEST-10', $unexplainedIds, true),
        'a command REFUSAL both sides agree on counts as agreement, not a discarded row'
                                                     => $analysis['identical'] === 4,
        'a command refusal legacy did NOT share surfaces as a diff, not as a dry-run failure'
                                                     => in_array('SELFTEST-13', $unexplainedIds, true),
        'an explicit crash stays uncomparable, and so does a row with no dry_run_error'
                                                     => $analysis['dry_run_failed'] === 2,
        'legacy_unknown row excluded'                => $analysis['legacy_unknown'] === 1,
        'out-of-scope op excluded'                   => $analysis['out_of_scope'] === 1,
        'operations_compared excludes non-evidence'  => $analysis['operations_compared'] === 9,
        'finalize op is in scope and counted'        => $analysis['finalize_ops'] === 6,
        'FINALIZE-rule coverage attributed'          => $analysis['finalize_rule_coverage']['system.psu_capacity'] === 1
            && $analysis['finalize_rule_coverage']['system.required_set'] === 0,
        'zero-finalize windows are RED'              => commandParityGreen(
            ['unexplained' => [], 'dry_run_failed' => 0, 'finalize_ops' => 0]) === false,
    ];
    $failed = array_keys(array_filter($checks, function ($ok) { return !$ok; }));
    if ($failed === []) {
        echo "command_parity_report --self-test: PASS (all 13 row classes classified correctly)\n";
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
        // Optional time part — see parity_report.php's identical note: a mid-day
        // fix leaves pre- and post-fix rows in the same file, and a date-only
        // cutoff cannot separate them.
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/', $argv[$i + 1]) !== 1) {
            fwrite(STDERR, "command_parity_report: --since expects YYYY-MM-DD or YYYY-MM-DDTHH:MM[:SS], got '{$argv[$i + 1]}'\n");
            exit(1);
        }
        $sinceCutoff = str_replace(' ', 'T', $argv[$i + 1]);
    }
}
$files = $fileArgs ?: glob(__DIR__ . '/../../reports/shadow/command-*.jsonl') ?: [];

$expectedDiffs = loadExpectedCommandDiffs();
$read = readCommandRows($files, $sinceCutoff);
$analysis = analyzeCommandRows($read['rows'], $expectedDiffs);
$file = writeCommandReport($analysis, false);

$green = commandParityGreen($analysis);
$status = $green ? 'GREEN' : 'RED';

if ($read['duplicates'] !== []) {
    echo "command_parity_report: " . count($read['duplicates']) . " duplicate input file(s) skipped: " . implode('; ', $read['duplicates']) . "\n";
}
if ($read['undecodable'] > 0) {
    echo "command_parity_report: WARNING {$read['undecodable']} input line(s) could not be decoded and were dropped -- "
        . "this window is measured over FEWER rows than the log contains\n";
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
if ($analysis['finalize_ops'] === 0) {
    echo "command_parity_report: RED 0 finalize operations -- the four Trigger::FINALIZE rules ("
        . implode(', ', FINALIZE_RULES) . ") have not run, so this window says NOTHING about "
        . "COMMAND_LAYER=enforce dropping the legacy finalize pre-check\n";
} else {
    $fired = [];
    foreach ($analysis['finalize_rule_coverage'] as $ruleId => $count) {
        $fired[] = "$ruleId=$count";
    }
    echo "command_parity_report: {$analysis['finalize_ops']} finalize op(s); FINALIZE-rule blocks: "
        . implode(' ', $fired) . "\n";
}
if ($sinceCutoff !== null) {
    echo "command_parity_report: --since $sinceCutoff applied\n";
}
echo "command_parity_report: $status $file\n";
exit($green ? 0 : 1);
