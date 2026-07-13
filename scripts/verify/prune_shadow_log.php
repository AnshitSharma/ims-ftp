<?php
/**
 * prune_shadow_log.php — owner-authorized maintenance for the RED
 * parity_report.php finding (2026-07-13, eighth-session verify record):
 * reports/shadow/engine-*.jsonl is append-only and never rotated, so rows
 * logged before a fix landed (a rule change, a ghost-config cleanup, ...)
 * keep tripping the parity gate forever even though today's live replay
 * evidence is clean. This is the file-log equivalent of a DB seeder for that
 * problem — same posture as this project's database/seeders convention:
 * WRITTEN, SHOWN, NEVER RUN by this session. It is not wired into any test
 * or gate report; nothing calls it automatically.
 *
 * What it does when actually executed (never done by this session): removes
 * every line from reports/shadow/engine-*.jsonl whose `ts` date is strictly
 * before the given cutoff, rewriting each file in place (files that would
 * become empty are left as empty files, not deleted, so a future run's
 * glob() still finds them and an empty file parses to zero rows harmlessly).
 *
 * This script is intentionally inert by default:
 *   php scripts/verify/prune_shadow_log.php --since 2026-07-13            # dry run: prints what WOULD be removed, touches nothing
 *   php scripts/verify/prune_shadow_log.php --since 2026-07-13 --execute  # the only mode that writes -- requires the owner to type --execute explicitly
 *
 * The parity_report.php --since flag (added alongside this script, same
 * finding) already makes the gate report itself go GREEN without requiring
 * this script to ever run — --since is the durable, reversible fix; this
 * script is a separate, optional disk-cleanup convenience the owner can run
 * whenever, or never.
 */

declare(strict_types=1);

$sinceCutoff = null;
$execute = false;
foreach ($argv as $i => $arg) {
    if ($arg === '--since' && isset($argv[$i + 1])) {
        $sinceCutoff = $argv[$i + 1];
    }
    if ($arg === '--execute') {
        $execute = true;
    }
}

if ($sinceCutoff === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $sinceCutoff) !== 1) {
    fwrite(STDERR, "Usage: php prune_shadow_log.php --since YYYY-MM-DD [--execute]\n");
    exit(1);
}

$files = glob(__DIR__ . '/../../reports/shadow/engine-*.jsonl') ?: [];
$totalKept = 0;
$totalPruned = 0;

foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $kept = [];
    $prunedHere = 0;
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        $ts = is_array($decoded) ? ($decoded['ts'] ?? null) : null;
        if ($ts !== null && substr($ts, 0, 10) < $sinceCutoff) {
            $prunedHere++;
            continue;
        }
        $kept[] = $line;
    }
    $totalKept += count($kept);
    $totalPruned += $prunedHere;
    echo basename($file) . ": " . count($lines) . " rows -> keep " . count($kept) . ", prune $prunedHere\n";

    if ($execute && $prunedHere > 0) {
        file_put_contents($file, $kept ? implode("\n", $kept) . "\n" : '');
    }
}

echo ($execute ? "EXECUTED" : "DRY RUN") . ": kept=$totalKept pruned=$totalPruned across " . count($files) . " file(s)\n";
if (!$execute && $totalPruned > 0) {
    echo "Re-run with --execute to actually rewrite the files above.\n";
}
