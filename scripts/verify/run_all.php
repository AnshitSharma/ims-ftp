<?php
/**
 * run_all.php — 11-verification/README.md §run_all.
 *
 * Orchestrates the reports registry below. A report not yet implemented is marked
 * "available": false here and prints `<name>: SKIPPED (lands in <unit>)` — it never
 * runs, never writes a report file, and never affects the exit code.
 *
 * 2026-08-24 correction: the sentence that used to stand here — "This is
 * deliberate: a SKIPPED report cannot green-wash a gate that still requires it"
 * — was simply wrong, and wrong in the direction that matters. A SKIPPED report
 * green-washes its gate exactly: the loop below `continue`s past it without
 * touching $overallExit, so a gate whose every unavailable report is skipped
 * exits 0. `regression` sat unavailable in nine gate lists on the strength of
 * that sentence. An entry is only safe to leave unavailable when there is
 * genuinely nothing that could be invoked (see `baseline`) — never because this
 * runner is assumed to fail closed. It does not.
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
    // baseline: STILL 'available' => false, deliberately, and this is not an
    // oversight -- checked 2026-08-24. characterize_compatibility.php is a
    // CAPTURE tool, not a comparison: it rewrites tests/golden/
    // compatibility_baseline.json in place and exits 0 unconditionally (its only
    // non-zero exits are 2/3/4 for "cannot connect", "json_encode failed",
    // "cannot mkdir"). Wiring it here would be a worse fail-open than SKIPPED:
    // the gate would overwrite the very baseline it is supposed to be checked
    // against and then always report GREEN. It needs a --diff/--check mode that
    // compares against a pinned baseline and exits non-zero on drift before it
    // can gate anything. Until then the honest reading is SKIPPED.
    // partial_rows (2026-08-24): gates the fallback gap in
    // TargetStateBuilder::fromCurrent(). Its source selection is `!empty($rows)` --
    // a NON-EMPTY test, not a COMPLETE one -- so a config whose config_components
    // rows cover only PART of its legacy JSON takes the rows path anyway, and the
    // unmirrored components are silently absent from every TargetState the rules
    // evaluate. Nothing else catches this: equivalence_report treats an empty rows
    // store as a diff, so it cannot tell 'not yet backfilled' from 'half
    // backfilled', and the shadow streams only see configs live traffic touches.
    // At COMMAND_LAYER=enforce -- production today -- an under-reported TargetState
    // is a user-visible 404 on remove, not a logged shadow diff. Detection first;
    // the control-flow fix comes later, with evidence behind it.
    // NOT in QUICK_SET (full-table scan, P9-scoped), so nightly.sh is unaffected.
    'partial_rows' => ['script' => __DIR__ . '/partial_rows_report.php', 'available' => true, 'lands_in' => null],
    // deploy_skew (2026-08-24): gates the dead-code gate's own CORPUS. The
    // authoritative run of the deletion authority is the DEPLOYED one (no shell
    // on that host, so server-debug-deadcode is the only way it runs against
    // production), and the SFTP deployment uploads on save but NEVER deletes --
    // measured the same day, production scans 162 PHP files under the same
    // _scan_roots minus _excluded_dirs where local has 146. Those 16 orphans sit
    // INSIDE the gate's corpus. Checked by hand and benign today (none of the 23
    // deployed symbols' verdicts cites a production-only file), but nothing
    // detected it, so the next skew could poison a verdict silently: an orphan
    // that cites a symbol is a permanent unexplained RED, and a stale COPY of a
    // file we later removed a caller from keeps the gate RED after the real fix
    // landed. RED only when a production-only file is actually CITED by a symbol
    // verdict; a bare count delta is a WARN, because a count delta is the normal
    // state of this deployment and a check that screams every run gets ignored.
    // NOT RUNNABLE (no production snapshot) reads RED, deliberately -- see
    // reports/deploy-skew-20260824.md and the file's own header.
    // NOT in QUICK_SET (P9-scoped, needs a captured snapshot), so nightly.sh is
    // unaffected.
    'deploy_skew' => ['script' => __DIR__ . '/deploy_skew_report.php', 'available' => true, 'lands_in' => null],
    // invariants (2026-08-26): U-P.1. Runs every CHECK block in
    // migration/ARCHITECTURAL_INVARIANTS.md, extracted VERBATIM at run time by
    // scripts/ci/inv_extract.php -- no check text lives in either script, so
    // editing the document changes what this gate enforces with no code change.
    //
    // WHY IT IS HERE AND NOT ONLY IN scripts/ci/invariants.sh: this registry
    // launches its children as `php <script>`, so a POSIX sh entry point cannot
    // be a registry entry at all. Until this landed, the invariants were
    // enforced by whoever remembered to call invariants.sh and by NO gate --
    // including P10, whose entire stated objective (migration/12-post-cutover/
    // README.md) is "make the invariants permanent (CI)" and whose gate reads
    // ['all']. The phase that exists to make the rules permanent was not
    // checking them.
    //
    // NOT A DUPLICATE RUN: invariants_report.php is section 1 of invariants.sh
    // only (the CHECK blocks). Sections 2 and 3 of that script are `--quick` and
    // tests/run_tests.php, which are QUICK_SET and the `regression` entry here,
    // so nothing runs twice inside one gate -- and registering the .sh would
    // have made the gate call the battery that calls the gate.
    //
    // Expected RED today, for reasons already filed rather than unknown ones:
    // INV-1/1 (three legacy-compatibility quantity surfaces, BACKLOG.md B-1),
    // INV-8/1 (one equivalence diff, B-8) and INV-9/1 (unpaired seeder
    // rollbacks, B-2). It is registered RED deliberately: those are real,
    // and a gate suppressed until it is convenient is not a gate.
    //
    // Fail-closed: an unreachable DB, a missing POSIX shell or an unparseable
    // invariants document exits 2 -- never 0. NOT in QUICK_SET (it shells out
    // per check and INV-8 runs the full equivalence sweep), so nightly.sh's
    // --quick leg is unaffected; it reaches nightly through --gate P10.
    'invariants'  => ['script' => __DIR__ . '/invariants_report.php', 'available' => true, 'lands_in' => null],
    'baseline'    => ['script' => null, 'available' => false, 'lands_in' => 'tests/characterize_compatibility.php (capture-only: no compare mode, always exits 0 -- cannot gate)'],
    // regression (2026-08-24): now wired to tests/run_tests.php, the discovery-
    // based local suite runner. It was 'available' => false since this registry
    // was written -- on the assumption that "no dedicated report script" meant
    // "nothing to invoke" -- while GATE_REPORTS lists `regression` for P0, P1,
    // PL, P3, P4, P5, P6, P7 and P9. Nine gates therefore printed
    // "regression: SKIPPED" and could still exit 0: the single most-gated
    // report in this file had never once been evaluated. Same shape as F-10,
    // F-11, F-18 and F-21 (a check that reports green because it stopped
    // looking), committed in the gate runner itself.
    //
    // run_tests.php already has exactly the exit contract this registry wants:
    // 0 iff every discovered suite ran and passed, 1 if any suite failed, and
    // (as of the same date) 3 for "exited 0 without executing a check" -- so a
    // sweep that could not reach a scratch DB reads RED here rather than green.
    // Environment reaches it by inheritance: the proc_open() call below passes
    // no $env, so the child gets this process's environment verbatim, which is
    // how GOLDEN_DB_* / IMS_DATA_PATH get through to the DB-backed suites.
    'regression'  => ['script' => __DIR__ . '/../../tests/run_tests.php', 'available' => true, 'lands_in' => null],
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
    // P9 gains 'deploy_skew' (2026-08-24): P9 is the phase that DELETES, and
    // 'deadcode' is trusted to authorise those deletions. deploy_skew is the check
    // that the corpus deadcode scanned is the source of truth. Mirrored into
    // migration/phase-status.json's P9 gate_reports.
    'P9'  => ['deadcode', 'partial_rows', 'deploy_skew', 'equivalence', 'regression'],
    // P10 reads ['all'], which expands to array_keys(REGISTRY) -- so the
    // 'invariants' entry added 2026-08-26 reaches P10's gate automatically, with
    // no edit here and no mirroring needed in phase-status.json's gate_reports.
    // That is the point: P10's objective is "make the invariants permanent", and
    // an invariant enforced by a list someone has to remember to extend is not
    // permanent.
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

    // 2026-08-24: child stderr goes to a temp FILE, not a second pipe. It used to
    // be a pipe that was opened, never read, and closed after the child had already
    // been drained on stdout -- so any report writing more than one pipe buffer
    // (~4KB) to stderr blocked forever on its own write, and run_all.php hung with
    // no output at all rather than failing. deadcode_report.php emits ~3.9KB of
    // stderr today and `--gate P9` deadlocked on it live. stream_select() is not a
    // fix here: on Windows it does not work on proc_open pipes. Nothing read this
    // stderr before and nothing reads it now -- the only change is that the child
    // can always finish writing it.
    $errFile = tempnam(sys_get_temp_dir(), 'runall_');
    $descriptors = [1 => ['pipe', 'w'], 2 => ['file', $errFile ?: (DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null'), 'w']];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        echo "$name: RED (failed to launch {$entry['script']})\n";
        if ($errFile) { @unlink($errFile); }
        $overallExit = 1;
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    if ($errFile) { @unlink($errFile); }

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
