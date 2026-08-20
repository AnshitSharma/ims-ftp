<?php
/**
 * soak_status.php — read the archived battery runs and certify a soak criterion.
 *
 * The cutover's remaining gates are stated as durations and streaks:
 *   - U-X.2 step 3   : "Day 14 all green => open P8 gate, fill signoff"
 *   - U-D.3          : P8 signoff >= 30 days old AND 30 consecutive archived GREEN runs
 * Nothing in the repo counted those, so they were a manual tally over a directory
 * listing -- which is exactly the class of criterion this migration has been bitten
 * by three times (F-8 duplicate rows inflating a denominator, F-10 reports exiting 0
 * having run nothing, F-27 an absent log satisfying "no divergences"). This script
 * makes the count mechanical and makes EMPTY FAIL.
 *
 * Reads reports/archive/battery-*.json (written by scripts/ci/nightly.sh).
 *
 * Usage:
 *   php scripts/verify/soak_status.php                        # human summary
 *   php scripts/verify/soak_status.php --criterion p8-day14   # U-X.2 step 3
 *   php scripts/verify/soak_status.php --criterion ud3-30day  # U-D.3 streak half
 *   php scripts/verify/soak_status.php --json
 *
 * Exit: 0 = criterion met (or, with no --criterion, at least one run was found).
 *       1 = criterion NOT met.
 *       2 = usage/setup error, including an empty or unreadable archive.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
$ARCHIVE = $ROOT . '/reports/archive';

$argvLocal = $argv;
$asJson = in_array('--json', $argvLocal, true);

$criterion = null;
$cIdx = array_search('--criterion', $argvLocal, true);
if ($cIdx !== false) {
    $criterion = $argvLocal[$cIdx + 1] ?? null;
}

const CRITERIA = [
    // name => [required consecutive GREEN days, required observed window in days, description]
    'p8-day14'  => [14, 14, 'U-X.2 step 3: 14 consecutive GREEN battery days at READ_FROM_ROWS=on'],
    'ud3-30day' => [30, 30, 'U-D.3: 30 consecutive GREEN archived battery runs'],
];

if ($criterion !== null && !isset(CRITERIA[$criterion])) {
    fwrite(STDERR, "Unknown criterion '$criterion'. Known: " . implode(', ', array_keys(CRITERIA)) . "\n");
    exit(2);
}

if (!is_dir($ARCHIVE)) {
    fwrite(STDERR, "No archive directory at $ARCHIVE.\n");
    fwrite(STDERR, "Nothing has been archived yet -- install the cron first:\n");
    fwrite(STDERR, "  sh scripts/ci/nightly.sh\n");
    exit(2);
}

$files = glob($ARCHIVE . '/battery-*.json') ?: [];
if ($files === []) {
    // An empty archive is a FAILURE, never a pass. See the F-27 note above.
    fwrite(STDERR, "Archive at $ARCHIVE is EMPTY -- no battery has ever been archived.\n");
    fwrite(STDERR, "This is a failure, not a clean slate. Install the cron: sh scripts/ci/nightly.sh\n");
    exit(2);
}

$runs = [];
$malformed = [];
foreach ($files as $f) {
    $raw = @file_get_contents($f);
    $rec = $raw === false ? null : json_decode($raw, true);
    if (!is_array($rec) || !isset($rec['day'], $rec['status'])) {
        $malformed[] = basename($f);
        continue;
    }
    // Key by day so a duplicated record cannot count twice.
    $runs[(string)$rec['day']] = $rec;
}

if ($runs === []) {
    fwrite(STDERR, "Archive contains no readable run records (" . count($malformed) . " malformed).\n");
    exit(2);
}

ksort($runs);
$days = array_keys($runs);

$toTs = static function (string $day): ?int {
    $ts = strtotime(substr($day, 0, 4) . '-' . substr($day, 4, 2) . '-' . substr($day, 6, 2) . ' 00:00:00 UTC');
    return $ts === false ? null : $ts;
};

// Consecutive GREEN run of CALENDAR days ending at the most recent archived day.
// A missing day breaks the streak: "30 consecutive runs" means the battery
// actually ran on 30 consecutive days, not that the last 30 records were green.
$streak = 0;
$streakBrokenBy = null;
$expected = $toTs((string)end($days));
foreach (array_reverse($days) as $day) {
    $ts = $toTs((string)$day);
    if ($ts === null) { $streakBrokenBy = "unparseable day $day"; break; }
    if ($ts !== $expected) {
        $streakBrokenBy = 'gap: no run archived for ' . gmdate('Y-m-d', (int)$expected);
        break;
    }
    if (($runs[$day]['status'] ?? '') !== 'GREEN') {
        $streakBrokenBy = $day . ' was ' . ($runs[$day]['status'] ?? 'UNKNOWN');
        break;
    }
    $streak++;
    $expected -= 86400;
}

$firstTs = $toTs((string)reset($days));
$lastTs  = $toTs((string)end($days));
$windowDays = ($firstTs !== null && $lastTs !== null) ? (int)floor(($lastTs - $firstTs) / 86400) + 1 : 0;

$greenCount = 0;
foreach ($runs as $r) { if (($r['status'] ?? '') === 'GREEN') { $greenCount++; } }

$summary = [
    'archive' => $ARCHIVE,
    'runs_archived' => count($runs),
    'green' => $greenCount,
    'not_green' => count($runs) - $greenCount,
    'malformed_files' => $malformed,
    'first_day' => (string)reset($days),
    'last_day' => (string)end($days),
    'observed_window_days' => $windowDays,
    'current_green_streak_days' => $streak,
    'streak_broken_by' => $streakBrokenBy,
];

$exit = 0;

if ($criterion !== null) {
    [$needStreak, $needWindow, $desc] = CRITERIA[$criterion];
    $met = ($streak >= $needStreak) && ($windowDays >= $needWindow);
    $summary['criterion'] = $criterion;
    $summary['criterion_description'] = $desc;
    $summary['required_streak_days'] = $needStreak;
    $summary['required_window_days'] = $needWindow;
    $summary['met'] = $met;
    if (!$met) {
        $summary['days_remaining'] = max(0, $needStreak - $streak);
        $exit = 1;
    }
}

if ($asJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($exit);
}

echo "Battery archive: {$summary['archive']}\n";
echo "  runs archived      : {$summary['runs_archived']} ({$summary['green']} GREEN, {$summary['not_green']} not)\n";
echo "  window             : {$summary['first_day']} -> {$summary['last_day']} ({$summary['observed_window_days']} day(s))\n";
echo "  current GREEN streak: {$summary['current_green_streak_days']} day(s)\n";
if ($streakBrokenBy !== null) {
    echo "  streak broken by   : {$streakBrokenBy}\n";
}
if ($malformed !== []) {
    echo "  WARNING malformed  : " . implode(', ', $malformed) . "\n";
}

if ($criterion !== null) {
    [$needStreak, $needWindow, $desc] = CRITERIA[$criterion];
    echo "\n$desc\n";
    echo "  needs {$needStreak} consecutive GREEN day(s) over a >= {$needWindow}-day window\n";
    if ($summary['met']) {
        echo "  RESULT: MET\n";
    } else {
        echo "  RESULT: NOT MET -- {$summary['days_remaining']} more consecutive GREEN day(s) needed\n";
    }
}

exit($exit);
