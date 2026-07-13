<?php
/**
 * parity_report.php — 11-verification/README.md #6.
 *
 * Compares shadow-engine verdicts vs legacy verdicts recorded by
 * ShadowRunner::record() (core/models/validation/ShadowRunner.php, U-V.3)
 * in reports/shadow/engine-*.jsonl. Each row already carries both sides
 * canonicalized to {blocked, error_class} (see ShadowRunner::record()) —
 * this report does no further canonicalization, only comparison.
 *
 * A diff = legacy.blocked !== engine.blocked (a same-direction block with a
 * different error_class is NOT a diff — message-text/rule-identity
 * differences never count, per the 04-validation-engine/README.md risk
 * note). A diff is EXPECTED iff it matches an entry in the checked-in
 * scripts/verify/expected_diffs.json; every entry there must cite an audit
 * finding id. Any diff that matches no entry is UNEXPLAINED and keeps the
 * gate closed.
 *
 * Usage:
 *   php scripts/verify/parity_report.php [--file <path-to-jsonl>]...   # default: all reports/shadow/engine-*.jsonl
 *   php scripts/verify/parity_report.php --since <YYYY-MM-DD>          # optional: drop rows with ts before this date (inclusive of the date itself)
 *   php scripts/verify/parity_report.php --self-test                  # synthetic JSONL, one unexplained diff -> exit 1
 *
 * --since exists because the shadow log is append-only and never rotated:
 * rows logged before a fix landed (e.g. a rule change, a ghost-config
 * cleanup) stay in the file and keep tripping the parity gate forever even
 * though today's live behavior has moved on. Omitting --since preserves
 * this report's ORIGINAL behavior exactly (scans every row in every matched
 * file, no filtering) -- it is opt-in, not a new default, so any existing
 * caller/gate-report invocation is unaffected until it explicitly asks for a
 * cutoff.
 *
 * Exit: 0 = green (0 unexplained diffs AND 0 engine exceptions; an
 *       empty window is also green, but prints a loud WARNING line since a
 *       zero-sample green proves nothing was exercised),
 *       1 = red (self-test's synthetic fixture correctly detected also exits 1
 *       — see the --self-test block).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../core/models/validation/Severity.php';

const EXPECTED_DIFFS_FILE = __DIR__ . '/expected_diffs.json';

/** @return array[] loaded expected_diffs.json entries */
function loadExpectedDiffs(): array {
    if (!file_exists(EXPECTED_DIFFS_FILE)) {
        return [];
    }
    $decoded = json_decode(file_get_contents(EXPECTED_DIFFS_FILE), true);
    if (!is_array($decoded) || !isset($decoded['entries']) || !is_array($decoded['entries'])) {
        throw new \RuntimeException('expected_diffs.json is malformed (expected {"entries": [...]})');
    }
    foreach ($decoded['entries'] as $i => $entry) {
        foreach (['rule_id', 'audit_finding', 'legacy_blocked', 'engine_blocked'] as $required) {
            if (!array_key_exists($required, $entry)) {
                throw new \RuntimeException("expected_diffs.json entries[$i] missing required field '$required'");
            }
        }
    }
    return $decoded['entries'];
}

/**
 * @return array[] every {ts, config_uuid, op, trigger, legacy, engine, results} row
 *         across the given files, dropped to rows with ts >= $sinceCutoff
 *         when a cutoff is given (null = no filtering, the original behavior).
 */
function readShadowRows(array $files, ?string $sinceCutoff = null): array {
    $rows = [];
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                if ($sinceCutoff !== null && (!isset($decoded['ts']) || substr($decoded['ts'], 0, 10) < $sinceCutoff)) {
                    continue;
                }
                $rows[] = $decoded;
            }
        }
    }
    return $rows;
}

function matchesExpected(array $entry, array $row): bool {
    if ($entry['legacy_blocked'] !== $row['legacy']['blocked']) return false;
    if ($entry['engine_blocked'] !== $row['engine']['blocked']) return false;
    if (array_key_exists('engine_error_class', $entry) && $entry['engine_error_class'] !== null) {
        return $entry['engine_error_class'] === ($row['engine']['error_class'] ?? null);
    }
    return true;
}

/**
 * @return array{operations_compared:int, identical:int, expected:array, unexplained:array, exceptions:int, rule_coverage:array}
 */
function analyze(array $rows, array $expectedDiffs): array {
    $identical = 0;
    $expected = [];
    $unexplained = [];
    $exceptions = 0;
    $ruleCoverage = []; // rule_id => fired count

    foreach ($rows as $row) {
        foreach ($row['results'] ?? [] as $r) {
            $ruleId = $r['rule_id'] ?? 'unknown';
            if ($ruleId === 'engine.rule_exception' || $ruleId === 'engine.build_exception') {
                $exceptions++;
            }
            if (empty($r['passed'])) {
                $ruleCoverage[$ruleId] = ($ruleCoverage[$ruleId] ?? 0) + 1;
            }
        }

        $legacyBlocked = $row['legacy']['blocked'] ?? null;
        $engineBlocked = $row['engine']['blocked'] ?? null;
        if ($legacyBlocked === null || $engineBlocked === null) {
            continue; // malformed row, not countable either way
        }

        if ($legacyBlocked === $engineBlocked) {
            $identical++;
            continue;
        }

        $matchedEntry = null;
        foreach ($expectedDiffs as $entry) {
            if (matchesExpected($entry, $row)) {
                $matchedEntry = $entry;
                break;
            }
        }
        if ($matchedEntry !== null) {
            $expected[] = ['row' => $row, 'entry' => $matchedEntry];
        } else {
            $unexplained[] = $row;
        }
    }

    return [
        'operations_compared' => count($rows),
        'identical' => $identical,
        'expected' => $expected,
        'unexplained' => $unexplained,
        'exceptions' => $exceptions,
        'rule_coverage' => $ruleCoverage,
    ];
}

function writeReport(array $analysis, bool $selfTest): string {
    $reportsDir = __DIR__ . '/../../reports';
    if (!is_dir($reportsDir)) { mkdir($reportsDir, 0755, true); }
    $file = $reportsDir . '/parity-' . date('Ymd-His') . ($selfTest ? '-selftest' : '') . '.json';

    $green = count($analysis['unexplained']) === 0 && $analysis['exceptions'] === 0;

    file_put_contents($file, json_encode([
        'report' => 'parity_report',
        'generated_at' => date('c'),
        'self_test' => $selfTest,
        'operations_compared' => $analysis['operations_compared'],
        'identical_verdicts' => $analysis['identical'],
        'diffs_expected' => count($analysis['expected']),
        'diffs_unexplained' => count($analysis['unexplained']),
        'engine_exceptions' => $analysis['exceptions'],
        'rule_coverage' => $analysis['rule_coverage'],
        'unexplained_rows' => $analysis['unexplained'],
        'expected_rows' => $analysis['expected'],
        'status' => $green ? 'GREEN' : 'RED',
    ], JSON_PRETTY_PRINT));
    return $file;
}

// -----------------------------------------------------------------------
// --self-test: synthetic JSONL with one unexplained diff -> must exit 1.
// -----------------------------------------------------------------------
if (in_array('--self-test', $argv, true)) {
    $tmpFile = sys_get_temp_dir() . '/parity_report_selftest_' . uniqid() . '.jsonl';
    $syntheticRows = [
        // identical (both allow) -- not a diff
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-1', 'op' => 'add', 'trigger' => 'ADD',
            'legacy' => ['blocked' => false, 'error_class' => 'none'], 'engine' => ['blocked' => false, 'error_class' => 'none'], 'results' => []],
        // expected diff -- matches a seeded expected_diffs entry below
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-2', 'op' => 'add', 'trigger' => 'ADD',
            'legacy' => ['blocked' => false, 'error_class' => 'none'], 'engine' => ['blocked' => true, 'error_class' => 'selftest.expected_rule'],
            'results' => [['rule_id' => 'selftest.expected_rule', 'severity' => Severity::ERROR, 'passed' => false]]],
        // UNEXPLAINED diff -- matches nothing
        ['ts' => date('c'), 'config_uuid' => 'SELFTEST-3', 'op' => 'add', 'trigger' => 'ADD',
            'legacy' => ['blocked' => true, 'error_class' => 'legacy: some message'], 'engine' => ['blocked' => false, 'error_class' => 'none'], 'results' => []],
    ];
    file_put_contents($tmpFile, implode("\n", array_map('json_encode', $syntheticRows)) . "\n");

    $syntheticExpectedDiffs = [
        ['rule_id' => 'selftest.expected_rule', 'audit_finding' => 'SELFTEST-FINDING', 'legacy_blocked' => false, 'engine_blocked' => true, 'engine_error_class' => 'selftest.expected_rule'],
    ];

    $rows = readShadowRows([$tmpFile]);
    $analysis = analyze($rows, $syntheticExpectedDiffs);
    writeReport($analysis, true);
    @unlink($tmpFile);

    $caught = count($analysis['unexplained']) === 1 && $analysis['unexplained'][0]['config_uuid'] === 'SELFTEST-3';
    $expectedCaught = count($analysis['expected']) === 1;
    if ($caught && $expectedCaught) {
        echo "parity_report --self-test: PASS (unexplained diff correctly flagged, expected diff correctly matched)\n";
        exit(1); // intentional: proves detection, matches pack's acceptance test
    }
    echo "parity_report --self-test: FAIL (checker did not classify the synthetic fixture correctly)\n";
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
            fwrite(STDERR, "parity_report: --since expects YYYY-MM-DD, got '{$argv[$i + 1]}'\n");
            exit(1);
        }
        $sinceCutoff = $argv[$i + 1];
    }
}
$files = $fileArgs ?: glob(__DIR__ . '/../../reports/shadow/engine-*.jsonl') ?: [];

$expectedDiffs = loadExpectedDiffs();
$rows = readShadowRows($files, $sinceCutoff);
$analysis = analyze($rows, $expectedDiffs);
$file = writeReport($analysis, false);

$green = count($analysis['unexplained']) === 0 && $analysis['exceptions'] === 0;
$status = $green ? 'GREEN' : 'RED';

if ($analysis['operations_compared'] === 0) {
    echo "parity_report: WARNING operations compared: 0 -- a zero-sample GREEN proves nothing was exercised\n";
}
if ($sinceCutoff !== null) {
    echo "parity_report: --since $sinceCutoff applied (rows before this date excluded)\n";
}
echo "parity_report: $status $file\n";
exit($green ? 0 : 1);
