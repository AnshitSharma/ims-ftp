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
 *   php tests/run_tests.php            # regression + unit
 *   php tests/run_tests.php --verbose  # also echo each suite's own output
 *
 * Exit: 0 iff every discovered suite exited 0.
 */

declare(strict_types=1);

const SUITE_DIRS = [
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
exit($failed === 0 ? 0 : 1);
