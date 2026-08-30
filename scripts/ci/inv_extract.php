<?php
/**
 * inv_extract.php — turn docs/ARCHITECTURAL_INVARIANTS.md into an
 * executable manifest, WITHOUT retyping a single check.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * U-P.1's only checklist item is: "Every INV check runs verbatim from the
 * invariants file (no paraphrase drift)." The obvious implementation — copy the
 * grep lines into invariants.sh — fails that item on the day someone edits the
 * document, and fails it SILENTLY: the CI stays green while it is checking a
 * rule that no longer exists. That is the same shape as F-10/F-18/F-21 (a check
 * that reports green because it stopped looking), and run_all.php's own header
 * already carries the lesson.
 *
 * So the command text is never stored here. This file only PARSES, and it parses
 * structurally — there is no per-invariant special case, no allow-list of IDs,
 * and no knowledge of what any invariant says. Add an INV-13 tomorrow in the
 * existing shape and it runs tomorrow with no change to this file.
 *
 * WHAT SHAPES THE DOCUMENT ACTUALLY USES (surveyed 2026-08-24, INV-1..INV-12)
 * --------------------------------------------------------------------------
 * Three, not one:
 *
 *   A. `CHECK:` on its own line, followed by a ``` fence. Each non-blank line in
 *      the fence is one check. Two dialects inside the fence:
 *        - a line starting `mysql:`  -> SQL, run against the scratch DB; the
 *          statement may span lines and ends at the first `;`
 *        - anything else            -> a POSIX sh command line
 *      (INV-1, INV-2, INV-3, INV-5, INV-6, INV-7, INV-9)
 *
 *   B. `CHECK: ... `cmd` ... Must pass / exits 0` on one prose line, or a
 *      continuation line beginning `Plus `. The backticked token is the command.
 *      (INV-4, INV-5's second half, INV-8)
 *
 *   C. No CHECK at all — the invariant is a human rule.
 *      (INV-10, INV-11, INV-12)
 *
 * HOW AN ASSERTION IS DERIVED (again: from the document, never from this file)
 * ---------------------------------------------------------------------------
 * Shape A carries its expectation in a `#` comment, either trailing the command
 * or standing alone on the next line inside the fence. The document's vocabulary
 * is consistent:
 *      "must return nothing" | "must print nothing" | "must return 0 rows"
 * -> assert `empty`: the command must produce no output on stdout.
 * A comment with none of those phrases yields assert `none`, and the check is
 * reported UNENFORCED rather than quietly passed.
 *
 * Shape B's expectation is "Must pass" / "exits 0" -> assert `exit0`.
 *
 * PHASE-CONDITIONAL CHECKS
 * ------------------------
 * Several comments are conditioned on a unit ("must return nothing after U-0.1",
 * "must return 0 rows (after U-1.3)", "After U-C.6: must return nothing").
 * Running an "after U-C.6" assertion while U-C.6 is still in_progress would
 * report a violation that the document does not yet claim. So the named unit is
 * resolved against the UNITS_NOT_VERIFIED map below:
 *      not listed (the migration is done) -> the check GATES
 *      listed                             -> the check still RUNS and its output
 *                                            is shown, but it is INFORMATIONAL
 * The pending state is printed loudly; it is not a way to hide a red check.
 *
 * THE ONE INTERPOLATION, STATED PLAINLY
 * -------------------------------------
 * A shape-B token that is a bare `*.php` path (INV-4's
 * `tests/regression/finalized_immutability_test.php`) is prefixed with the PHP
 * binary, because the document names the test, not the way to launch it. No
 * other text is added to, removed from, or reordered within any command.
 * Likewise a `mysql:` statement is handed to a client this script did not
 * choose — the connection is ours, the SQL is the document's.
 *
 * Usage:
 *   php scripts/ci/inv_extract.php --outdir DIR   # write manifest.tsv + *.cmd
 *   php scripts/ci/inv_extract.php --list         # human-readable, runs nothing
 *
 * manifest.tsv columns (0x1F separated -- see the note at the emit step below --
 * one check per line):
 *   inv_id  seq  kind(sh|sql|exit0|none)  assert(empty|exit0|none)
 *   gating(gate|info|manual)  note  cmdfile
 *
 * Exit: 0 on a successful parse, 2 if the document could not be parsed at all
 * (no invariants found, or no executable check found in any of them) — because
 * "the parser broke" and "everything passed" must never look the same.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);                     // ims-ftp/
$DOC  = $ROOT . '/docs/ARCHITECTURAL_INVARIANTS.md';

$outdir = null;
$list   = false;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--outdir' && isset($argv[$i + 1])) { $outdir = $argv[++$i]; }
    elseif ($argv[$i] === '--list') { $list = true; }
    elseif ($argv[$i] === '--doc' && isset($argv[$i + 1])) { $DOC = $argv[++$i]; }
}
if ($outdir === null && !$list) {
    fwrite(STDERR, "Usage: php scripts/ci/inv_extract.php --outdir DIR | --list [--doc FILE]\n");
    exit(2);
}

if (!is_file($DOC)) {
    fwrite(STDERR, "inv_extract: invariants document not found: $DOC\n");
    exit(2);
}
$lines = preg_split('/\R/', (string)file_get_contents($DOC));

// ---------------------------------------------------------------------------
// Unit statuses for the phase-conditional rule above.
//
// This used to be read from migration/phase-status.json (250KB of migration
// bookkeeping), deleted 2026-08-31 with the rest of the migration scaffolding.
// The migration is complete, so the honest default flipped: a unit named by a
// check is ASSUMED VERIFIED unless it appears below. That is deliberately
// fail-CLOSED -- the old code defaulted an unresolvable unit to 'unknown' and
// downgraded its check from gate to informational, so losing the status file
// would silently have stopped INV-5/2 and INV-6/1 from gating while still
// printing them as checks. A gate that quietly becomes a comment is the exact
// failure this repo keeps re-finding; it does not get to happen by accident.
//
// Only units that did NOT complete belong here. Removing a line makes its
// checks gate, which is the direction that can only ever surface a problem.
const UNITS_NOT_VERIFIED = [
    // U-C.6 (transaction-ownership consolidation) was never completed -- it is
    // BLOCKED and its scope is wrong as written (BACKLOG.md C-1). INV-3's check
    // asserts commands are the only transaction owners, which is the state
    // U-C.6 would have produced, so it stays informational.
    'U-C.6' => 'in_progress',
];
$unitStatus = UNITS_NOT_VERIFIED;

// ---------------------------------------------------------------------------
// Parse.
// ---------------------------------------------------------------------------
/** @var array<int,array{id:string,title:string,checks:array}> */
$invariants = [];
$cur = null;
$inFence = false;
$sawCheckMarker = false;
$pendingFenceLines = [];

$flushSection = function () use (&$cur, &$invariants) {
    if ($cur !== null) { $invariants[] = $cur; $cur = null; }
};

foreach ($lines as $raw) {
    $line = rtrim($raw);

    if (preg_match('/^##\s+(INV-\d+)\s*\x{2014}?\s*(.*)$/u', $line, $m)) {
        $flushSection();
        $cur = ['id' => $m[1], 'title' => trim($m[2]), 'checks' => [], 'fence' => []];
        $inFence = false;
        $sawCheckMarker = false;
        continue;
    }
    if ($cur === null) { continue; }

    if (preg_match('/^```/', $line)) {
        $inFence = !$inFence;
        continue;
    }
    if ($inFence) {
        if ($sawCheckMarker) { $cur['fence'][] = $line; }
        continue;
    }

    // Shape A marker.
    if (preg_match('/^CHECK:\s*$/', $line)) { $sawCheckMarker = true; continue; }

    // Shape B: a CHECK: or Plus line carrying a backticked command plus an
    // "exits 0" / "must pass" expectation.
    if (preg_match('/^(CHECK:|Plus\b)/', $line)
        && preg_match('/`([^`]+)`/', $line, $bm)
        && preg_match('/exits?\s+0|must pass/i', $line)) {
        $cur['checks'][] = ['kind' => 'exit0', 'cmd' => trim($bm[1]), 'comment' => trim($line)];
        $sawCheckMarker = true;
        continue;
    }
}
$flushSection();

if ($invariants === []) {
    fwrite(STDERR, "inv_extract: parsed 0 invariants from $DOC — the document's heading shape changed.\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Turn each fence into checks. A `mysql:` statement may span lines (ends at `;`).
// A `#` comment either trails a command or stands alone directly after it.
// ---------------------------------------------------------------------------
foreach ($invariants as &$inv) {
    $buf = null;                 // accumulating multi-line SQL
    $lastIdx = null;             // index of the check a standalone comment attaches to
    foreach ($inv['fence'] as $fl) {
        $t = trim($fl);
        if ($t === '') { continue; }

        // Standalone comment line -> assertion for the previous check.
        if ($t[0] === '#' && $buf === null) {
            if ($lastIdx !== null && ($inv['checks'][$lastIdx]['comment'] ?? '') === '') {
                $inv['checks'][$lastIdx]['comment'] = $t;
            }
            continue;
        }

        if ($buf !== null) {                    // continuing a SQL statement
            $buf .= "\n" . $t;
            if (strpos($t, ';') !== false) {
                [$sql, $comment] = splitComment($buf);
                $inv['checks'][] = ['kind' => 'sql', 'cmd' => trim($sql), 'comment' => $comment];
                $lastIdx = array_key_last($inv['checks']);
                $buf = null;
            }
            continue;
        }

        if (stripos($t, 'mysql:') === 0) {
            $rest = trim(substr($t, strlen('mysql:')));
            if (strpos($rest, ';') === false) { $buf = $rest; continue; }
            [$sql, $comment] = splitComment($rest);
            $inv['checks'][] = ['kind' => 'sql', 'cmd' => trim($sql), 'comment' => $comment];
            $lastIdx = array_key_last($inv['checks']);
            continue;
        }

        [$cmd, $comment] = splitComment($t);
        $cmd = trim($cmd);
        if ($cmd === '') { continue; }
        $inv['checks'][] = ['kind' => 'sh', 'cmd' => $cmd, 'comment' => $comment];
        $lastIdx = array_key_last($inv['checks']);
    }
    if ($buf !== null) {                        // unterminated statement — do not guess
        [$sql, $comment] = splitComment($buf);
        $inv['checks'][] = ['kind' => 'sql', 'cmd' => trim($sql), 'comment' => $comment];
    }
    unset($inv['fence']);
}
unset($inv);

/**
 * Split a trailing ` # ...` comment off a command. Deliberately requires
 * whitespace before the `#` so a `#` inside a pattern or string is not eaten.
 * @return array{0:string,1:string}
 */
function splitComment(string $s): array
{
    if (preg_match('/^(.*?)\s+(#\s.*)$/s', $s, $m)) { return [$m[1], trim($m[2])]; }
    return [$s, ''];
}

// ---------------------------------------------------------------------------
// Classify: assertion, placeholders, phase condition.
// ---------------------------------------------------------------------------
$records = [];
foreach ($invariants as $inv) {
    if ($inv['checks'] === []) {
        $records[] = [
            'inv' => $inv['id'], 'seq' => 0, 'kind' => 'none', 'assert' => 'none',
            'gating' => 'manual', 'note' => 'no CHECK block in the document — human rule',
            'cmd' => '',
        ];
        continue;
    }

    $seq = 0;
    foreach ($inv['checks'] as $c) {
        $seq++;
        $comment = $c['comment'];
        $note    = '';
        $gating  = 'gate';

        if ($c['kind'] === 'exit0') {
            $assert = 'exit0';
        } elseif (preg_match('/must (?:return|print) nothing/i', $comment)) {
            $assert = 'empty';
        } elseif (preg_match('/must return 0 rows/i', $comment)) {
            $assert = 'empty';
        } else {
            $assert = 'none';
            $gating = 'manual';
            $note   = $comment === ''
                ? 'no assertion comment — cannot be judged mechanically'
                : 'no recognised assertion directive in: ' . $comment;
        }

        // An unresolved <placeholder> cannot be run verbatim by anyone.
        if (preg_match('/<[A-Za-z_][A-Za-z0-9_ -]*>/', $c['cmd'], $pm)) {
            $assert = 'none';
            $gating = 'manual';
            $note   = 'unresolved placeholder ' . $pm[0] . ' — not runnable verbatim';
        }

        // Phase-conditional assertion.
        if ($gating === 'gate' && preg_match('/\bafter\s+(U-[A-Za-z0-9.]+)/i', $comment, $um)) {
            $unit = rtrim($um[1], '.:,)');
            $st   = $unitStatus[$unit] ?? 'verified';
            if ($st !== 'verified') {
                $gating = 'info';
                $note   = "conditioned on $unit, whose status is '$st' — informational until it is verified";
            } else {
                $note = "conditioned on $unit (verified)";
            }
        }

        $records[] = [
            'inv' => $inv['id'], 'seq' => $seq, 'kind' => $c['kind'],
            'assert' => $assert, 'gating' => $gating, 'note' => $note, 'cmd' => $c['cmd'],
        ];
    }
}

$executable = 0;
foreach ($records as $r) { if ($r['kind'] !== 'none' && $r['gating'] !== 'manual') { $executable++; } }
if ($executable === 0) {
    fwrite(STDERR, "inv_extract: parsed " . count($invariants) . " invariants but found 0 executable checks —\n");
    fwrite(STDERR, "             the document's CHECK shape changed. Refusing to report a vacuous pass.\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Emit.
// ---------------------------------------------------------------------------
if ($list) {
    printf("%-7s %-4s %-6s %-7s %-7s %s\n", 'INV', 'SEQ', 'KIND', 'ASSERT', 'GATING', 'COMMAND / NOTE');
    foreach ($records as $r) {
        printf("%-7s %-4d %-6s %-7s %-7s %s\n", $r['inv'], $r['seq'], $r['kind'], $r['assert'], $r['gating'],
            $r['cmd'] !== '' ? str_replace("\n", ' ', $r['cmd']) : $r['note']);
        if ($r['cmd'] !== '' && $r['note'] !== '') { printf("%-34s%s\n", '', '-> ' . $r['note']); }
    }
    printf("\n%d invariant(s), %d check(s), %d executable and gating-or-informational.\n",
        count($invariants), count($records), $executable);
    exit(0);
}

if (!is_dir($outdir) && !@mkdir($outdir, 0777, true)) {
    fwrite(STDERR, "inv_extract: cannot create outdir $outdir\n");
    exit(2);
}
$manifest = '';
foreach ($records as $r) {
    $cmdfile = '';
    if ($r['cmd'] !== '') {
        $cmdfile = rtrim($outdir, "/\\") . '/' . $r['inv'] . '.' . $r['seq'] . '.cmd';
        file_put_contents($cmdfile, $r['cmd'] . "\n");
    }
    $note = str_replace(["\x1f", "\t", "\n"], ' ', $r['note']);
    // Field separator is US (0x1F), not TAB, DELIBERATELY. TAB is an IFS
    // whitespace character, so POSIX `read` collapses a run of them into a
    // single delimiter and an EMPTY field in the middle of a record silently
    // shifts every later field left. `note` is empty for most checks, and that
    // shift handed the reader a blank command path — the runner then `cat`ed
    // nothing and would have reported the check as producing no output, i.e.
    // PASSING. A separator choice that turns a broken read into a green check
    // is exactly the failure mode this whole unit exists to prevent.
    // 0x1F is non-whitespace, so empty fields survive, and it cannot occur in a
    // shell command or in a note.
    $manifest .= implode("\x1f", [$r['inv'], (string)$r['seq'], $r['kind'], $r['assert'], $r['gating'], $note, $cmdfile]) . "\n";
}
file_put_contents(rtrim($outdir, "/\\") . '/manifest.tsv', $manifest);
fwrite(STDERR, sprintf("inv_extract: %d invariant(s), %d check(s) extracted verbatim from %s\n",
    count($invariants), count($records), basename($DOC)));
exit(0);
