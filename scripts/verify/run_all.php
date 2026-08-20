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
 *
 * The three shadow-log reports (`parity`, `command_parity`, `read`) are always invoked
 * here with `--since PARITY_SINCE_DEFAULT` (below;
 * overridable via the PARITY_SINCE_CUTOFF env var) -- owner-adopted standing gate invocation,
 * 2026-07-13 (see migration/11-verification/README.md #6 and the tenth-session handoff record).
 * This does NOT change parity_report.php's own default behavior: running it directly with no
 * arguments still scans every row in every shadow-log file, unfiltered, exactly as before.
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
    // command_parity (2026-07-27): gates the COMMAND_LAYER shadow stream
    // (reports/shadow/command-*.jsonl). Until it existed nothing consumed that
    // log at all, so COMMAND_LAYER's soak was unverified even with the flag at
    // shadow in production -- and U-C.6's enforce soak is downstream of it.
    // Takes --since like parity, for the same append-only-log reason.
    'command_parity' => ['script' => __DIR__ . '/command_parity_report.php', 'available' => true, 'lands_in' => null],
    // read (2026-07-29): gates the READ_FROM_ROWS shadow stream
    // (reports/shadow/read-*.jsonl). Same gap command_parity closed two days ago
    // -- nothing consumed that log, and until F-27 nothing COULD, because the
    // router recorded divergences only: "every read agreed" and "no read ever
    // reached the router" produced the identical empty artifact. U-X.2's gate
    // criterion was written against that artifact. Takes --since like the other
    // two, for the same append-only-log reason.
    'read'        => ['script' => __DIR__ . '/read_report.php',        'available' => true,  'lands_in' => null],
    // deadcode (2026-08-20): landed with U-D.1's "Files Created (1)". Gates the P9
    // deletions -- it is expected to be RED until those units actually run, since
    // every scheduled symbol still has its callers. It is NOT in QUICK_SET or P8,
    // so the daily nightly.sh battery is unaffected.
    'deadcode'    => ['script' => __DIR__ . '/deadcode_report.php',    'available' => true,  'lands_in' => null],
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
    // P6 gains 'command_parity' (2026-07-27): U-C.6 is P6's enforce-soak unit and
    // its evidence lives in the command stream, which no gate report read before.
    // Mirrored into migration/phase-status.json's P6 gate_reports.
    'P6'  => ['parity', 'command_parity', 'equivalence', 'regression', 'performance'],
    'P7'  => ['regression', 'parity'],
    // P8 gains 'read' (2026-07-29): U-X.2 is P8's read-cutover unit and its
    // checklist item 1 is a >=72h zero-divergence sample soak. That evidence lives
    // in the read stream, which no gate report read before -- so the criterion was
    // being checked by eye against a log whose emptiness meant nothing.
    // Mirrored into migration/phase-status.json's P8 gate_reports.
    'P8'  => ['equivalence', 'orphan', 'slot', 'ledger', 'inventory', 'performance', 'read'],
    'P9'  => ['deadcode', 'equivalence', 'regression'],
    'P10' => ['all'],
];

const QUICK_SET = ['schema', 'inventory', 'orphan', 'equivalence'];

/**
 * Owner-adopted (tenth session, 2026-07-13) standing invocation for the
 * parity gate report: the shadow log is append-only and never rotated, so
 * without a cutoff, rows logged before a fix landed (rule changes, the ghost-
 * config cleanup, etc.) keep tripping the gate forever even though today's
 * live behavior has moved on (ninth-session verify finding). Overridable via
 * PARITY_SINCE_CUTOFF so this doesn't need a code change every time the
 * "known-good since" date moves forward; defaults to the date the shadow log
 * was last known clean of pre-fix rows in this environment. This constant is
 * run_all.php-only -- a bare `php scripts/verify/parity_report.php` (no
 * --since) is completely unaffected and keeps scanning every row, unchanged.
 */
/**
 * 2026-07-29: moved 2026-07-13 -> 2026-07-29. Three changes landed during the
 * 2026-07-28 evening session and EVERY one of them alters what a shadow row
 * means: F-26 (FINALIZE now subsumes VALIDATE, 4 rules -> 21),
 * SERVER_BUILD_GUIDE Bug 2 (the raw memory-type compare that rejected every
 * finalize in the fleet), and the finalize hook's own two corrections
 * (legacy_blocked now reflects the REAL finalize outcome; dry_run_error
 * separates a deliberate refusal from a crash).
 *
 * The cutoff is the whole day, not the last fix's timestamp, DELIBERATELY. Rows
 * from that evening are development churn -- a mixture of pre- and post-fix
 * behaviour under three different instrumentation contracts -- and picking a
 * time that happens to leave only the agreeing rows would be choosing the
 * answer. Excluding the day excludes the favourable rows too.
 *
 * Consequence, stated plainly: the command gate reads RED with "0 finalize
 * operations" until real post-fix traffic arrives. That is the correct reading.
 * The soak starts from the code as it now stands, not from evidence about code
 * that no longer exists.
 *
 * (The reports accept a time part -- YYYY-MM-DDTHH:MM -- which is what made
 * choosing between a day and a moment a real decision rather than a limitation.)
 */
const PARITY_SINCE_DEFAULT = '2026-07-29';

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

    $cmd = ['php', $entry['script']];
    // 'read' takes the same standing cutoff (2026-07-29): F-27 changed what a row
    // in that stream MEANS -- before it, agreement was unrecorded -- so pre-cutoff
    // rows cannot contribute a denominator and must not contribute a numerator.
    if ($name === 'parity' || $name === 'command_parity' || $name === 'read') {
        $since = getenv('PARITY_SINCE_CUTOFF') ?: PARITY_SINCE_DEFAULT;
        $cmd[] = '--since';
        $cmd[] = $since;
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes);
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
    // Path capture uses .+ (not \S+): this repo's own working-directory path contains a
    // space ("Github IMS"), which \S+ can't span — found live 2026-07-13 running run_all.php
    // directly against the real tree (every prior session ran from C:\tmp, no space, so this
    // never surfaced). Exit-code-based gating above is unaffected either way.
    if (preg_match('/:\s*(GREEN|RED)\s+(.+)$/', $lastLine, $m)) {
        echo "$name: {$m[1]} {$m[2]}\n";
    } else {
        echo "$name: $status (no report line found in child output)\n";
    }

    if ($exitCode !== 0) {
        $overallExit = 1;
    }
}

exit($overallExit);
