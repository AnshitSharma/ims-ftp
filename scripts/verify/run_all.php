<?php
/**
 * run_all.php — 11-verification/README.md §run_all.
 *
 * Orchestrates the reports registry below. A report not yet implemented is marked
 * "available": false here and prints `<name>: SKIPPED (lands in <unit>)` — it never
 * runs, never writes a report file, and never affects the exit code. This is
 * deliberate: a SKIPPED report cannot green-wash a gate that still requires it.
 *
 * Usage:
 *   php scripts/verify/run_all.php --quick        # schema + inventory + orphan + equivalence
 *   php scripts/verify/run_all.php --gate P0      # exactly the reports listed for that phase
 *                                                  # in migration/phase-status.json
 *
 * Exit: 0 iff every AVAILABLE selected report is GREEN. 1 if any is RED. 2 on usage/setup error.
 */

declare(strict_types=1);

// -----------------------------------------------------------------------
// Report registry. "gates" is informational only (which gate lists mention
// this report) — actual gate selection reads migration/phase-status.json
// directly (GATE_REPORTS below), copied from there per the pack.
// -----------------------------------------------------------------------
const REGISTRY = [
    'inventory'   => ['script' => __DIR__ . '/inventory_report.php',   'available' => true,  'lands_in' => null],
    'orphan'      => ['script' => __DIR__ . '/orphan_report.php',      'available' => true,  'lands_in' => null],
    'performance' => ['script' => __DIR__ . '/performance_report.php', 'available' => true,  'lands_in' => null],
    'schema'      => ['script' => __DIR__ . '/schema_report.php',      'available' => true,  'lands_in' => null],
    'ledger'      => ['script' => __DIR__ . '/ledger_report.php',      'available' => true,  'lands_in' => null],
    'slot'        => ['script' => __DIR__ . '/slot_report.php',        'available' => true,  'lands_in' => null],
    'equivalence' => ['script' => __DIR__ . '/equivalence_report.php', 'available' => true,  'lands_in' => null],
    'parity'      => ['script' => __DIR__ . '/parity_report.php',      'available' => true,  'lands_in' => null],
    'deadcode'    => ['script' => __DIR__ . '/deadcode_report.php',    'available' => false, 'lands_in' => 'U-D.1'],
    'baseline'    => ['script' => null, 'available' => false, 'lands_in' => 'tests/characterize_compatibility.php (no dedicated report script planned)'],
    'regression'  => ['script' => null, 'available' => false, 'lands_in' => 'tests/regression/*.php (no dedicated report script planned)'],
];

// Copied verbatim from each phase's "gate_reports" in migration/phase-status.json.
const GATE_REPORTS = [
    'P0'  => ['baseline', 'orphan', 'regression'],
    'P1'  => ['schema', 'regression'],
    'PL'  => ['schema', 'ledger', 'regression'],
    'P2'  => ['equivalence', 'orphan', 'ledger', 'inventory'],
    'P3'  => ['schema', 'inventory', 'regression'],
    'P4'  => ['parity', 'regression'],
    'P5'  => ['parity', 'regression'],
    'P6'  => ['parity', 'equivalence', 'regression', 'performance'],
    'P7'  => ['regression', 'parity'],
    'P8'  => ['equivalence', 'orphan', 'slot', 'ledger', 'inventory', 'performance'],
    'P9'  => ['deadcode', 'equivalence', 'regression'],
    'P10' => ['all'],
];

const QUICK_SET = ['schema', 'inventory', 'orphan', 'equivalence'];

function resolveSelection(array $argv): array {
    if (in_array('--quick', $argv, true)) {
        return QUICK_SET;
    }
    $gateIdx = array_search('--gate', $argv, true);
    if ($gateIdx !== false) {
        $gate = $argv[$gateIdx + 1] ?? null;
        if ($gate === null || !isset(GATE_REPORTS[$gate])) {
            fwrite(STDERR, "Unknown or missing gate after --gate. Known gates: " . implode(', ', array_keys(GATE_REPORTS)) . "\n");
            exit(2);
        }
        $reports = GATE_REPORTS[$gate];
        if (in_array('all', $reports, true)) {
            return array_keys(REGISTRY);
        }
        return $reports;
    }
    fwrite(STDERR, "Usage: php scripts/verify/run_all.php [--quick] [--gate P<N>]\n");
    exit(2);
}

$selection = resolveSelection($argv);

$overallExit = 0;
foreach ($selection as $name) {
    if (!isset(REGISTRY[$name])) {
        echo "$name: SKIPPED (unknown report name)\n";
        continue;
    }
    $entry = REGISTRY[$name];

    if (!$entry['available']) {
        echo "$name: SKIPPED (lands in {$entry['lands_in']})\n";
        continue;
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['php', $entry['script']], $descriptors, $pipes);
    if (!is_resource($process)) {
        echo "$name: RED (failed to launch {$entry['script']})\n";
        $overallExit = 1;
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    // Each report script prints "<report_name>: GREEN|RED <path>" as its own last line
    // (report_name is its own name, e.g. "inventory_report", not necessarily this
    // registry's short gate name) — pull the path out of it and reprint under our name.
    $lastLine = trim(strrchr(trim($stdout), "\n") ?: $stdout);
    $status = $exitCode === 0 ? 'GREEN' : 'RED';
    if (preg_match('/:\s*(GREEN|RED)\s+(\S+)\s*$/', $lastLine, $m)) {
        echo "$name: {$m[1]} {$m[2]}\n";
    } else {
        echo "$name: $status (no report line found in child output)\n";
    }

    if ($exitCode !== 0) {
        $overallExit = 1;
    }
}

exit($overallExit);
