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
 *   php scripts/verify/run_all.php --quick        # schema + inventory + orphan
 *   php scripts/verify/run_all.php --gate P0      # exactly the reports listed for that phase
 *                                                  # in GATE_REPORTS below
 *
 * Exit: 0 iff every AVAILABLE selected report is GREEN. 1 if any is RED. 2 on usage/setup error.
 */

declare(strict_types=1);

// -----------------------------------------------------------------------
// Report registry. Gate selection reads GATE_REPORTS below. Those lists were
// originally copied from migration/phase-status.json; that file was deleted
// 2026-08-31 with the migration scaffolding, so GATE_REPORTS is now the sole
// definition rather than a mirror of one.
// -----------------------------------------------------------------------
const REGISTRY = [
    'inventory'   => ['script' => __DIR__ . '/inventory_report.php',   'available' => true,  'lands_in' => null],
    'orphan'      => ['script' => __DIR__ . '/orphan_report.php',      'available' => true,  'lands_in' => null],
    'performance' => ['script' => __DIR__ . '/performance_report.php', 'available' => true,  'lands_in' => null],
    'schema'      => ['script' => __DIR__ . '/schema_report.php',      'available' => true,  'lands_in' => null],
    'ledger'      => ['script' => __DIR__ . '/ledger_report.php',      'available' => true,  'lands_in' => null],
    'slot'        => ['script' => __DIR__ . '/slot_report.php',        'available' => true,  'lands_in' => null],
    // equivalence: RETIRED 2026-08-30 by U-D.3c, along with INV-8, which it was the
    // check for. It compared the legacy JSON columns against config_components; the
    // columns are dropped, so there is no second store left to fork from and the
    // invariant is now structural rather than something a nightly run can restate.
    // Deliberately absent rather than 'available' => false: an entry here would
    // report SKIPPED forever and read as a gate someone still has to satisfy.
    // The rows-vs-inventory half of its job lives on in inventory_report.php's
    // Check 2, which U-D.3c repointed at config_components and made exact.
    // parity, command_parity and read: RETIRED 2026-08-31. All three read only
    // reports/shadow/*.jsonl, and nothing has written that directory since P9
    // deleted ShadowRunner and U-D.4 deleted READ_FROM_ROWS -- so each of them
    // could only ever re-measure a frozen log whose subject no longer exists.
    // Same treatment as equivalence above: absent, not 'available' => false, so
    // no gate prints SKIPPED for a criterion nobody has to satisfy any more.
    // deadcode: RETIRED 2026-08-31 with the deletions it authorised. It was
    // U-D.1's deletion precondition; P9 discharged it, and its authoritative run
    // was always the DEPLOYED one via server-debug-deadcode, an endpoint removed
    // the same day. deadcode_report.php, deadcode_scan.php and
    // deadcode_manifest.json are deleted.
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
    // partial_rows: RETIRED 2026-08-30 by U-D.3c. It gated the fallback gap in
    // TargetStateBuilder::fromCurrent(), whose source selection was `!empty($rows)`
    // -- a NON-EMPTY test, not a COMPLETE one -- so a config whose config_components
    // rows covered only PART of its legacy JSON took the rows path anyway and the
    // unmirrored components were silently absent from every TargetState the rules
    // evaluated. "Partly mirrored" is not a state that can exist any more: there is
    // no JSON side to be half of, fromCurrent() returns an empty TargetState rather
    // than falling back to a decoder that no longer exists, and a component absent
    // from config_components is absent from the configuration, full stop.
    // deploy_skew: RETIRED 2026-08-31, and this one is a deliberate loss worth
    // stating plainly. It existed to check that the corpus the dead-code gate
    // scanned was the DEPLOYED tree, and it did that by require_once'ing
    // deadcode_scan.php and reading deadcode_manifest.json against a snapshot
    // that only server-debug-deadcode could produce. Both of those are gone, so
    // it is unrunnable by construction rather than merely unrun. The underlying
    // risk it measured is NOT gone: the SFTP deployment uploads on save and
    // NEVER deletes, so production still carries files this repo does not, and
    // as of 2026-08-24 that gap was 16 PHP files. That residual is now accepted
    // and unmeasured -- see BACKLOG.md B-3.
    // invariants (2026-08-26): U-P.1. Runs every CHECK block in
    // docs/ARCHITECTURAL_INVARIANTS.md, extracted VERBATIM at run time by
    // scripts/ci/inv_extract.php -- no check text lives in either script, so
    // editing the document changes what this gate enforces with no code change.
    //
    // WHY IT IS HERE AND NOT ONLY IN scripts/ci/invariants.sh: this registry
    // launches its children as `php <script>`, so a POSIX sh entry point cannot
    // be a registry entry at all. Until this landed, the invariants were
    // enforced by whoever remembered to call invariants.sh and by NO gate --
    // including P10, whose entire stated objective was "make the invariants
    // permanent (CI)" and whose gate reads
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

// Originally copied verbatim from each phase's "gate_reports" in
// migration/phase-status.json. That file is deleted; this is now the original.
const GATE_REPORTS = [
    'P0'  => ['baseline', 'orphan', 'regression'],
    'P1'  => ['schema', 'regression'],
    'PL'  => ['schema', 'ledger', 'regression'],
    // 'equivalence' left P2, P6, P8 and P9 on 2026-08-30 with U-D.3c, and 'partial_rows'
    // left P9 with it. On 2026-08-31 'parity' left P4/P5/P6/P7, 'command_parity' left P6,
    // 'read' left P8, and 'deadcode' + 'deploy_skew' left P9 -- see the RETIRED notes in
    // REGISTRY above for why each one can no longer measure anything. Every one of those
    // reports is deleted; an unknown name here does not fail, it prints SKIPPED, which is
    // the shape of a gate nobody notices went missing. These are the lists that RUN a gate
    // today -- what each phase was actually shown at the time is in its signoff file, and is
    // not edited by this.
    //
    // P4, P5, P7 and P9 are now ['regression'] alone. That is a thinner gate than it was,
    // and honestly so: their other criteria were shadow-log soaks for flags that no longer
    // exist, so re-running them would have measured a frozen file, not the system.
    'P2'  => ['orphan', 'ledger', 'inventory'],
    'P3'  => ['schema', 'inventory', 'regression'],
    'P4'  => ['regression'],
    'P5'  => ['regression'],
    'P6'  => ['regression', 'performance'],
    'P7'  => ['regression'],
    'P8'  => ['orphan', 'slot', 'ledger', 'inventory', 'performance'],
    'P9'  => ['regression'],
    // P10 reads ['all'], which expands to array_keys(REGISTRY) -- so the
    // 'invariants' entry added 2026-08-26 reaches P10's gate automatically, with
    // no edit here and no mirroring needed in phase-status.json's gate_reports.
    // That is the point: P10's objective is "make the invariants permanent", and
    // an invariant enforced by a list someone has to remember to extend is not
    // permanent.
    'P10' => ['all'],
];

const QUICK_SET = ['schema', 'inventory', 'orphan'];

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

    // 2026-08-24: child stderr goes to a temp FILE, not a second pipe. It used to
    // be a pipe that was opened, never read, and closed after the child had already
    // been drained on stdout -- so any report writing more than one pipe buffer
    // (~4KB) to stderr blocked forever on its own write, and run_all.php hung with
    // no output at all rather than failing. The report that exposed it,
    // deadcode_report.php (~3.9KB of stderr, deadlocked `--gate P9` live), has
    // since been deleted -- the fix stays, because the bug was in this runner. stream_select() is not a
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
