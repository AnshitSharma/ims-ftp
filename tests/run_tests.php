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
 * A suite that exits 0 having run NOTHING (no reachable/provisioned scratch DB)
 * is counted and reported separately from one that ran and passed. Those are not
 * the same result and this runner will not print them as one.
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

/** Not suites: shared helpers (_*.php) and single-purpose probes. */
const NOT_A_SUITE = ['run_serial_less_check.php'];

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
    $ranNothing = strpos($stdout, 'SKIPPED: 0 check(s) run') !== false;

    if ($code !== 0) {
        $status = 'FAIL';
        $failed++;
        $failedNames[] = $base;
    } elseif ($ranNothing) {
        $status = 'RAN NOTHING';
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
       . "(no provisioned scratch DB). They are NOT evidence.\n";
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
