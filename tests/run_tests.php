<?php
/**
 * run_tests.php — runs the local test suites by DISCOVERY, not by list.
 *
 * WHY (2026-07-29): every prior session's "all N tests pass" was measured against
 * a list typed by hand into the shell. On 2026-07-29 that list was found to
 * contain two files that do not exist and to omit six that do — and all six of
 * the omitted ones were exiting 255 with an uncaught PDOException in every
 * environment. The suite had been red for an unknown length of time while the
 * sweep reported green, because the sweep and the suite were different sets.
 *
 * That is the same wrong-denominator shape as F-8, F-23 and F-27, committed in
 * the verification step itself. A glob cannot drift from the directory it globs.
 *
 * A suite that exits 0 having run NOTHING (no reachable/provisioned scratch DB,
 * no HTTP harness, a missing PHP extension) is counted and reported separately
 * from one that ran and passed. Those are not the same result and this runner
 * will not print them as one.
 *
 * That verdict rests on TWO independent signals, and needs only one of them:
 *   (1) the suite prints the "SKIPPED: 0 check(s) run" marker itself, and
 *   (2) the runner counts ZERO per-check "PASS"/"FAIL" lines in the suite's
 *       own output.
 * (1) alone made the mechanism opt-in and therefore forgettable -- see the
 * 2026-08-24 note at the ranNothing assignment for the suite that forgot.
 * (2) is the runner measuring for itself, which is the same reason discovery is
 * a glob and not a typed list.
 *
 * Usage:
 *   php tests/run_tests.php            # every directory in SUITE_DIRS
 *   php tests/run_tests.php --verbose  # also echo each suite's own output
 *
 * Exit: 0 iff every discovered suite exited 0 AND at least one check ran in
 * every one of them. 1 if any suite failed. 3 if none failed but one or more
 * exited 0 without executing a single check (see the 2026-08-24 note below).
 * 2 on setup error (nothing discovered).
 */

declare(strict_types=1);

/**
 * 2026-08-24: `api` and `backfill` added. They were missing since this file was
 * written on 2026-07-29, which made the runner commit the exact drift its own
 * header calls out: tests/api/add_remove_response_shape_test.php is unit
 * U-A.1's stated acceptance artifact and was never discovered at all, and
 * tests/backfill/* likewise. A glob cannot drift from the directory it globs --
 * but it can drift from the directories it was never pointed at.
 */
const SUITE_DIRS = [
    'api'        => __DIR__ . '/api',
    'backfill'   => __DIR__ . '/backfill',
    'regression' => __DIR__ . '/regression',
    'unit'       => __DIR__ . '/unit',
];

/**
 * Not suites: shared helpers (_*.php), single-purpose probes, and (2026-08-30)
 * this file itself plus two tools that live in tests/ alongside real suites --
 * see the root-level scan below.
 */
const NOT_A_SUITE = [
    'run_serial_less_check.php',
    'run_tests.php',
    // A golden-master CAPTURE tool, not a suite: running it would overwrite
    // tests/golden/compatibility_baseline.json on every suite run. See BACKLOG
    // B-4 (closed SUPERSEDED) -- permanently unusable as a parity gate, not just
    // currently: P9/U-D.3a deleted the methods it characterises. B-18 logs the
    // rewrite against ValidationEngine as separate, still-open work.
    'characterize_compatibility.php',
    // Deliberately exits 2 (not a pass/fail suite) -- both its subjects are
    // gone: P9 deleted the validate* methods it drives, and U-D.3c dropped
    // the columns its fixtures insert. See its own header.
    'fixture_scenarios_real.php',
    // Deliberately exits 2 (not a pass/fail suite) -- its subject,
    // ServerBuilder::validateStorageConnections(), was deleted 2026-08-31 as
    // unreachable legacy code (P2 cleanup). See its own header.
    'caddy_finalize_parity_test.php',
];

$verbose = in_array('--verbose', $argv, true);
$php = PHP_BINARY;

$files = [];
foreach (SUITE_DIRS as $label => $dir) {
    if (!is_dir($dir)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') { continue; }
        $base = $f->getBasename();
        if ($base[0] === '_' || in_array($base, NOT_A_SUITE, true)) { continue; }
        $files[] = [$label, $f->getPathname(), $base];
    }
}

/**
 * 2026-08-30: tests/ itself holds real suites SUITE_DIRS never looked at --
 * lane_authority_unit.php and its siblings. Same wrong-denominator shape this
 * file's own header describes, one directory up: they had been running green
 * or red with nobody watching, because the sweep and the suite were different
 * sets. See BACKLOG B-17.
 *
 * Scanned NON-recursively and separately from the loop above: SUITE_DIRS
 * already recurses into api/, backfill/, regression/ and unit/, so a
 * recursive scan rooted at __DIR__ would run every one of those suites a
 * second time.
 */
foreach (glob(__DIR__ . '/*.php') as $path) {
    $base = basename($path);
    if ($base[0] === '_' || in_array($base, NOT_A_SUITE, true)) { continue; }
    $files[] = ['root', $path, $base];
}
usort($files, function ($a, $b) { return [$a[0], $a[2]] <=> [$b[0], $b[2]]; });

if ($files === []) {
    fwrite(STDERR, "run_tests: discovered NO suites — check SUITE_DIRS\n");
    exit(2);
}

$passed = $failed = $provedNothing = 0;
$failedNames = [];

foreach ($files as [$label, $path, $base]) {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$php, $path], $descriptors, $pipes);
    if (!is_resource($proc)) {
        printf("  %-42s %-10s FAILED TO LAUNCH\n", $base, $label);
        $failed++;
        $failedNames[] = $base;
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    // "Ran nothing" is a distinct outcome, printed as such. See the header.
    //
    // TWO independent signals, deliberately (2026-08-24, part two). Until today
    // this was the marker grep ALONE, which made the whole ran-nothing mechanism
    // OPT-IN: a suite was counted as "ran nothing" only if the suite itself
    // remembered to volunteer the marker string. Any suite that exited 0 having
    // executed no assertion and simply said nothing about it was counted as a
    // `pass` -- which is precisely how tests/unit/rate_limiter_concurrency_test
    // .php (one `SKIP: pcntl not available` line, zero of its assertions, exit 0)
    // read as green on every Windows box this project is developed on.
    //
    // Signal 2 is the runner's OWN measurement: count the per-check result lines
    // in the suite's output. Every suite in this tree reports each check as a
    // line beginning "PASS" / "FAIL" (the `check()` helper they all share by
    // copy), so zero such lines means zero checks executed, whatever the suite
    // claims. A runner that measures for itself cannot be lied to by silence --
    // the same reasoning that made discovery a glob instead of a typed list.
    //
    // The marker is KEPT and still honoured: a suite can execute some real
    // checks and still declare that its actual acceptance criteria never ran
    // (tests/api/*), which no count of output lines could infer.
    $checksRun = preg_match_all('/^\s*(?:PASS|FAIL)\b/m', $stdout);
    $ranNothing = strpos($stdout, 'SKIPPED: 0 check(s) run') !== false || $checksRun === 0;

    if ($code !== 0) {
        $status = 'FAIL';
        $failed++;
        $failedNames[] = $base;
    } elseif ($ranNothing) {
        // Say WHICH signal fired, so "the suite told us" and "the suite said
        // nothing and we counted zero" are never confused for each other.
        $status = $checksRun === 0 ? 'RAN NOTHING (0 checks)' : 'RAN NOTHING (declared)';
        $provedNothing++;
    } else {
        $status = 'pass';
        $passed++;
    }

    printf("  %-42s %-10s %s\n", $base, $label, $status);
    if ($verbose || $code !== 0) {
        foreach (explode("\n", rtrim($stdout . $stderr)) as $line) {
            echo "        $line\n";
        }
    }
}

$total = count($files);
echo "\n";
echo "run_tests: $total suite(s) discovered — $passed passed, $failed failed, $provedNothing ran nothing\n";
if ($provedNothing > 0) {
    echo "run_tests: WARNING $provedNothing suite(s) exited 0 without executing a single check "
       . "(no provisioned scratch DB, no harness, or a missing PHP extension). They are NOT evidence.\n";
}
if ($failed > 0) {
    echo "run_tests: FAILED — " . implode(', ', $failedNames) . "\n";
}

/**
 * 2026-08-24: "ran nothing" is now a NON-ZERO exit (3), distinct from a real
 * failure (1). Until today this runner printed the ran-nothing warning and then
 * exited 0 anyway, so the one consumer that only reads the exit code -- and as
 * of today scripts/verify/run_all.php's `regression` gate report IS that
 * consumer -- read "no scratch DB was reachable, nothing was proved" as GREEN.
 * The header has always insisted those are not the same result; the exit code
 * now agrees with the header.
 *
 * The final line is emitted in the `<name>: GREEN|RED <detail>` shape that
 * run_all.php's report-line regex expects, so the gate reprints a real verdict
 * instead of "(no report line found in child output)".
 */
$verdict = ($failed === 0 && $provedNothing === 0) ? 'GREEN' : 'RED';
echo "run_tests: $verdict $total discovered / $passed passed / $failed failed / $provedNothing ran nothing\n";

if ($failed > 0) { exit(1); }
if ($provedNothing > 0) { exit(3); }
exit(0);
