<?php
/**
 * Concurrency test — RateLimiter atomicity (audit I).
 *
 * DB-free, but NOT single-process: it forks real concurrent workers, because the
 * defect only appears under genuine parallelism. A sequential test passes against
 * the broken implementation too, which is why the bug survived.
 *
 * The defect: attempt() was load -> count-check -> append -> save with no lock held
 * across the sequence. Concurrent callers all read the same pre-increment array,
 * all passed the limit check, and the last writer clobbered the rest. Separately,
 * file_put_contents(..., LOCK_EX) opens with 'w' and truncates BEFORE taking the
 * lock, so an unsynchronised reader could observe an empty file, fail json_decode,
 * and silently reset the counter to zero.
 *
 * Requires pcntl (CLI). Skips cleanly if unavailable.
 *
 * Run: php tests/unit/rate_limiter_concurrency_test.php
 */

require_once __DIR__ . '/../../core/helpers/RateLimiter.php';

// The ran-nothing marker, not a bare SKIP line (2026-08-24).
//
// WHY: pcntl does not exist on Windows, so on every Windows dev box -- which is
// where this project is developed -- this file printed one `SKIP:` line and
// exited 0, having executed ZERO of its assertions. run_tests.php's ran-nothing
// detection keys off the "SKIPPED: 0 check(s) run" marker, which this file never
// emitted, so it was counted as a plain `pass`: a suite whose entire subject is
// concurrency safety reported green on a platform where it cannot fork. Exactly
// the fail-open class the api/* suites were fixed for earlier the same day, in a
// suite nobody had looked at because it was never DB-backed.
//
// test_skip_suite() is the shared emitter in tests/regression/_scratch_db.php --
// one marker string, one place, whatever the reason for not running.
require_once __DIR__ . '/../regression/_scratch_db.php';

if (!function_exists('pcntl_fork')) {
    test_skip_suite(
        'RateLimiter atomicity under real concurrency (audit I)',
        'pcntl not available on this platform (needed to fork real concurrent workers)'
    );
}

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  PASS  $label" . ($detail ? "  ($detail)" : "") . "\n";
    } else {
        $failures++;
        echo "  FAIL  $label" . ($detail ? "  ($detail)" : "") . "\n";
    }
}

/**
 * Fork $workers processes that each call attempt() once against the same key.
 * Returns how many were granted. Each child reports via its exit code.
 */
function raceForAttempts(string $key, int $maxAttempts, int $window, int $workers): int {
    $limiter = new RateLimiter();
    $limiter->clear($key);

    $pids = [];
    for ($i = 0; $i < $workers; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "fork failed\n");
            exit(1);
        }
        if ($pid === 0) {
            // Child: a fresh limiter, and a tiny stagger so the workers actually
            // interleave inside the critical section rather than lining up.
            usleep(random_int(0, 15000));
            $child = new RateLimiter();
            $granted = $child->attempt($key, $maxAttempts, $window);
            exit($granted ? 0 : 1); // 0 = allowed
        }
        $pids[] = $pid;
    }

    $allowed = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
            $allowed++;
        }
    }
    return $allowed;
}

$limiter = new RateLimiter();

echo "\n-- the limit holds under concurrency (audit I) --\n";

// 40 simultaneous requests against a limit of 5. The pre-fix implementation
// routinely admitted far more than 5 here.
$key = 'test:concurrency:' . getmypid();
$allowed = raceForAttempts($key, 5, 60, 40);
check('40 concurrent requests, limit 5 -> at most 5 admitted',
    $allowed <= 5, "admitted=$allowed");
check('  and the limit is actually reachable (not zero)',
    $allowed === 5, "admitted=$allowed");
$limiter->clear($key);

// A tighter limit, more contention.
$key2 = 'test:concurrency2:' . getmypid();
$allowed2 = raceForAttempts($key2, 1, 60, 25);
check('25 concurrent requests, limit 1 -> exactly 1 admitted',
    $allowed2 === 1, "admitted=$allowed2");
$limiter->clear($key2);

echo "\n-- concurrent hit() loses no increments --\n";

// hit() has the same read-modify-write shape; every failed login must be recorded.
$key3 = 'test:hits:' . getmypid();
$limiter->clear($key3);
$workers = 30;
$pids = [];
for ($i = 0; $i < $workers; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        usleep(random_int(0, 15000));
        (new RateLimiter())->hit($key3, 60);
        exit(0);
    }
    $pids[] = $pid;
}
foreach ($pids as $pid) { pcntl_waitpid($pid, $status); }

// remaining() = max - recorded, so recorded = max - remaining.
$recorded = 1000 - $limiter->remaining($key3, 1000, 60);
check("$workers concurrent hit() calls all recorded",
    $recorded === $workers, "recorded=$recorded");
$limiter->clear($key3);

echo "\n-- a concurrent reader never resets the counter --\n";

// Interleave readers with writers. Under the old file_put_contents(LOCK_EX)
// write path a reader could catch the truncated file and read [] -- which the
// caller would treat as "no attempts yet".
$key4 = 'test:readrace:' . getmypid();
$limiter->clear($key4);
for ($i = 0; $i < 5; $i++) { $limiter->hit($key4, 60); }

$pids = [];
for ($i = 0; $i < 8; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $child = new RateLimiter();
        $sawReset = false;
        for ($j = 0; $j < 60; $j++) {
            $child->hit($key4, 60);
            // Never fewer than the 5 seeded hits.
            if ((1000 - $child->remaining($key4, 1000, 60)) < 5) {
                $sawReset = true;
            }
        }
        exit($sawReset ? 1 : 0);
    }
    $pids[] = $pid;
}
$resetSeen = false;
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
    if (pcntl_wexitstatus($status) !== 0) { $resetSeen = true; }
}
check('no worker ever observed a truncated/reset counter', $resetSeen === false);

$finalCount = 1000 - $limiter->remaining($key4, 1000, 60);
check('every increment survived the read/write race',
    $finalCount === 5 + (8 * 60), "expected=" . (5 + 8 * 60) . " actual=$finalCount");
$limiter->clear($key4);

echo "\n-- window pruning and basic contract still hold --\n";

$key5 = 'test:window:' . getmypid();
$limiter->clear($key5);
check('fresh key allows the first attempt', $limiter->attempt($key5, 2, 60) === true);
check('second attempt still allowed',       $limiter->attempt($key5, 2, 60) === true);
check('third attempt is refused',           $limiter->attempt($key5, 2, 60) === false);
check('tooManyAttempts agrees',             $limiter->tooManyAttempts($key5, 2, 60) === true);
check('clear() resets',                     ($limiter->clear($key5) ?? true) === true
                                            && $limiter->attempt($key5, 2, 60) === true);
$limiter->clear($key5);

// Expired entries must age out rather than accumulate forever.
$key6 = 'test:expiry:' . getmypid();
$limiter->clear($key6);
$limiter->hit($key6, 1);
sleep(2);
check('entries outside the window are pruned',
    $limiter->tooManyAttempts($key6, 1, 1) === false);
$limiter->clear($key6);

echo "\n";
if ($failures > 0) {
    echo "$failures of $checks CHECKS FAILED\n";
    exit(1);
}
echo "ALL $checks CHECKS PASS\n";
exit(0);
