<?php
/**
 * deadcode_report.php — the gating report for the P9 cleanup units (U-D.1/2/4).
 * Contract: migration/11-verification/README.md #deadcode_report.php --
 *   "For each symbol scheduled for deletion: grep -rn zero call sites outside
 *    tests + the symbol's own file; PHP lint of full tree after deletion;
 *    characterization suite green."
 * This script owns the first clause. The lint and characterization clauses are
 * post-delete checks and stay in each unit's own test block.
 *
 * WHY THIS SHAPE (read before trusting a GREEN)
 * ---------------------------------------------
 * "Zero call sites" is a criterion that SILENCE PASSES. A typo'd pattern, a
 * renamed symbol, a scan root that matched no files, an empty manifest -- every
 * one of those produces zero hits and, in the naive implementation, a GREEN that
 * authorises deleting live code. That is precisely the fail-open class this
 * migration has already been bitten by four times (F-11, F-18, F-21, F-27: an
 * EMPTY derived artifact read as agreement) and the deletions this report gates
 * are the least reversible step in the whole program.
 *
 * So every zero here must be a MEASURED zero, and the report refuses to emit one
 * it cannot back:
 *
 *   1. ANCHOR CHECK. Before counting callers, the symbol's own declaration must
 *      be found in its declaring file. If the anchor is missing, the search
 *      itself is broken (renamed, moved, already deleted, bad pattern) and the
 *      target is reported BROKEN -- red -- never dead. A symbol that cannot be
 *      found is not a symbol that is unused.
 *   2. NON-EMPTY CORPUS. If the scan roots yield no PHP files, exit 2. A zero
 *      over nothing is not evidence.
 *   3. NON-EMPTY MANIFEST. An absent or empty target list exits 2, not 0.
 *   4. SELF-TEST (--self-test), as 11-verification requires of every report:
 *      it plants a symbol that is provably live and a symbol that does not
 *      exist, and FAILS unless the report calls them LIVE and BROKEN. A report
 *      that cannot detect its own defect class is not evidence either.
 *
 * DELIBERATELY CONSERVATIVE
 * -------------------------
 * This is a grep, not a resolver. PHP method calls are dynamically dispatched,
 * so `$x->extractPCIeSlotSize()` cannot be attributed to a class by text alone,
 * and three classes declare that name. The count is therefore an OVER-count:
 * callers belonging to a same-named method elsewhere are still counted here.
 * Over-counting fails CLOSED -- it blocks a deletion that might have been safe,
 * which is the correct direction to be wrong in. Never "resolve" an ambiguity by
 * narrowing the pattern until the number reaches zero.
 *
 * Usage:
 *   php scripts/verify/deadcode_report.php                 # every target
 *   php scripts/verify/deadcode_report.php --unit U-D.1    # one cleanup unit
 *   php scripts/verify/deadcode_report.php --symbol NAME   # one symbol
 *   php scripts/verify/deadcode_report.php --json
 *   php scripts/verify/deadcode_report.php --self-test     # prove it can fail
 *
 * Exit: 0 iff every selected target is DEAD (and at least one was examined).
 *       1 if any target is LIVE or BROKEN.
 *       2 on usage/setup error, including an empty manifest or an empty corpus.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
$MANIFEST = __DIR__ . '/deadcode_targets.json';
$REPORT = $ROOT . '/reports/deadcode-report.json';

// Production code only. tests/ is excluded by the contract ("outside tests"),
// and migration/ docs/ reports/ are prose about the symbols, not callers of them.
const SCAN_ROOTS = ['api', 'core', 'scripts'];

$argvLocal = $argv;
$asJson = in_array('--json', $argvLocal, true);
$selfTest = in_array('--self-test', $argvLocal, true);

$argValue = static function (string $flag) use ($argvLocal): ?string {
    $i = array_search($flag, $argvLocal, true);
    return $i === false ? null : ($argvLocal[$i + 1] ?? null);
};
$unitFilter = $argValue('--unit');
$symbolFilter = $argValue('--symbol');

// ---------------------------------------------------------------- corpus

/** @return string[] absolute paths of every PHP file in the scan roots */
function collectCorpus(string $root): array
{
    $files = [];
    foreach (SCAN_ROOTS as $rel) {
        $dir = $root . '/' . $rel;
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $files[] = $f->getPathname();
            }
        }
    }
    foreach (glob($root . '/*.php') ?: [] as $f) {
        $files[] = $f;
    }
    sort($files);
    return $files;
}

/**
 * Patterns that constitute a USE of a target, by kind. Kept broad on purpose:
 * see "DELIBERATELY CONSERVATIVE" above.
 *
 * @return string[] regexes
 */
function usePatterns(string $kind, string $name): array
{
    $q = preg_quote($name, '/');
    switch ($kind) {
        case 'method':
            return [
                '/->\s*' . $q . '\s*\(/',      // $obj->name(
                '/::\s*' . $q . '\s*\(/',      // Class::name(
                '/[\'"]' . $q . '[\'"]/',      // callable string / array callable
            ];
        case 'function':
            // A plain (non-method) function: called by bare name. Broader, and
            // therefore more over-counting, than the method patterns -- which
            // fails closed, per the note above.
            return [
                '/(?<![>:$\\w])' . $q . '\s*\(/',
                '/[\'"]' . $q . '[\'"]/',
            ];
        case 'class':
            return [
                '/\bnew\s+' . $q . '\b/',
                '/\b' . $q . '\s*::/',
                '/\binstanceof\s+' . $q . '\b/',
                '/\b' . $q . '\s+\$/',         // type-hinted parameter
                '/\?' . $q . '\s+\$/',         // nullable type hint
                '/[\'"][^\'"]*' . $q . '\.php[\'"]/', // require/include of the file
            ];
        case 'marker':
        default:
            return ['/' . $q . '/'];
    }
}

/** The declaration that proves the search is looking at a real symbol. */
function anchorPattern(string $kind, string $name): ?string
{
    $q = preg_quote($name, '/');
    if ($kind === 'method' || $kind === 'function') {
        return '/function\s+' . $q . '\s*\(/';
    }
    if ($kind === 'class') {
        return '/\b(class|interface|trait)\s+' . $q . '\b/';
    }
    return null; // markers have no declaration site
}

/**
 * Strip the comment portion of a line so a MENTION can be told from a CALL.
 *
 * A comment cannot call anything, so counting docblock references as call sites
 * makes genuinely dead symbols look live -- which blocks a safe deletion. That
 * error is the safe direction, but it is still an error, and at the scale of
 * U-D.2 it would bury the real callers in prose.
 *
 * This is a heuristic, and the failure mode it could produce IS fail-open (a
 * real call site mistakenly stripped and downgraded to a mention). Two things
 * bound that risk: the raw pre-strip count is kept in the JSON payload under
 * `mention_count` for every target, and a target with zero code call sites but
 * surviving mentions is reported STALE_REFS -- never silently DEAD -- so a human
 * reads the lines before anything is deleted.
 */
function stripComments(string $line): string
{
    $t = ltrim($line);
    // Whole-line comment / docblock body.
    if ($t === '' || strpos($t, '*') === 0 || strpos($t, '//') === 0
        || strpos($t, '#') === 0 || strpos($t, '/*') === 0) {
        return '';
    }
    // Trailing comment. '//' inside a string (a URL) can truncate early; that is
    // why the raw count is preserved rather than discarded.
    foreach (['//', '/*', '#'] as $marker) {
        $pos = strpos($line, $marker);
        if ($pos !== false) {
            $line = substr($line, 0, $pos);
        }
    }
    return $line;
}

/**
 * @param string[] $corpus
 * @return array{callers:array,other_declarations:array,mentions:array}
 */
function scanTarget(array $target, array $corpus, string $root, string $selfPath): array
{
    $kind = $target['kind'];
    $name = $target['name'];
    $ownFile = isset($target['file']) ? $root . '/' . $target['file'] : null;
    $patterns = usePatterns($kind, $name);
    $declPattern = anchorPattern($kind, $name);

    $callers = [];
    $mentions = [];
    $otherDecls = [];

    foreach ($corpus as $path) {
        // The symbol's own file is excluded by the contract, and this report
        // names every target in its own manifest -- neither is a call site.
        if ($ownFile !== null && $path === $ownFile) {
            continue;
        }
        if ($path === $selfPath) {
            continue;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $n => $line) {
            if ($declPattern !== null && preg_match($declPattern, $line)) {
                // A same-named declaration in another file: not a call site, but
                // it is why this target's caller count may be an over-count.
                $otherDecls[] = [
                    'file' => ltrim(str_replace($root, '', $path), '/'),
                    'line' => $n + 1,
                    'text' => trim($line),
                ];
                continue;
            }
            foreach ($patterns as $p) {
                if (!preg_match($p, $line)) {
                    continue;
                }
                $hit = [
                    'file' => ltrim(str_replace($root, '', $path), '/'),
                    'line' => $n + 1,
                    'text' => trim($line),
                ];
                // Only a match that survives comment-stripping is a CALL.
                if (preg_match($p, stripComments($line))) {
                    $callers[] = $hit;
                } else {
                    $mentions[] = $hit;
                }
                break;
            }
        }
    }

    return ['callers' => $callers, 'mentions' => $mentions, 'other_declarations' => $otherDecls];
}

/** Anchor: is the symbol actually where the manifest says it is? */
function anchorFound(array $target, string $root): ?bool
{
    $declPattern = anchorPattern($target['kind'], $target['name']);
    if ($declPattern === null) {
        return null; // not applicable to markers
    }
    if (!isset($target['file'])) {
        return false;
    }
    $path = $root . '/' . $target['file'];
    $src = @file_get_contents($path);
    if ($src === false) {
        return false;
    }
    return (bool)preg_match($declPattern, $src);
}

/**
 * @param array[] $targets
 * @return array evaluated results
 */
function evaluateTargets(array $targets, array $corpus, string $root, string $selfPath): array
{
    $results = [];
    foreach ($targets as $t) {
        $anchor = anchorFound($t, $root);
        $scan = scanTarget($t, $corpus, $root, $selfPath);

        $isMarker = $t['kind'] === 'marker';
        // For a marker, U-D.4's own criterion is "the grep comes back empty" --
        // a flag name surviving in a comment IS the residue that unit deletes.
        // So markers count mentions as hits; symbols do not.
        $hits = $isMarker
            ? count($scan['callers']) + count($scan['mentions'])
            : count($scan['callers']);

        if ($anchor === false) {
            // NEVER dead. A symbol we cannot find is a broken search, and a
            // broken search returns zero callers for every symbol on earth.
            $status = 'BROKEN';
            $why = "declaration not found in " . ($t['file'] ?? '(no file given)')
                 . " -- renamed, moved, or already deleted; this search proves nothing";
        } elseif ($hits > 0) {
            $status = 'LIVE';
            $why = $isMarker
                ? $hits . ' surviving occurrence(s) -- U-D.4 requires the grep to come back empty'
                : count($scan['callers']) . ' call site(s) outside tests and its own file';
        } elseif (count($scan['mentions']) > 0) {
            // No code path reaches it, but comments still name it. Safe to
            // delete; the comments must be corrected in the same commit, and a
            // human reads them first in case the stripper mis-classified a call.
            $status = 'STALE_REFS';
            $why = 'no code call sites, but ' . count($scan['mentions'])
                 . ' comment/docblock mention(s) still name it';
        } else {
            $status = 'DEAD';
            $why = 'no call sites in ' . count($corpus) . ' scanned production file(s)';
        }

        $results[] = [
            'unit' => $t['unit'],
            'kind' => $t['kind'],
            'name' => $t['name'],
            'file' => $t['file'] ?? null,
            'status' => $status,
            'why' => $why,
            'anchor_found' => $anchor,
            'caller_count' => count($scan['callers']),
            'mention_count' => count($scan['mentions']),
            'callers' => $scan['callers'],
            'mentions' => $scan['mentions'],
            'other_declarations' => $scan['other_declarations'],
            'conditional' => $t['conditional'] ?? null,
            'note' => $t['note'] ?? null,
        ];
    }
    return $results;
}

// ---------------------------------------------------------------- self-test

if ($selfTest) {
    $corpus = collectCorpus($ROOT);
    if ($corpus === []) {
        fwrite(STDERR, "deadcode_report self-test: RED empty corpus\n");
        exit(2);
    }
    $fixtures = [
        // Provably LIVE: send_json_response is called from essentially every
        // handler. If the report calls this DEAD, its caller scan is broken.
        ['unit' => 'SELFTEST', 'kind' => 'function', 'name' => 'send_json_response',
         'file' => 'core/helpers/BaseFunctions.php', 'expect' => 'LIVE'],
        // Provably absent: no such symbol. If the report calls this DEAD, it
        // would authorise deleting code it never located.
        ['unit' => 'SELFTEST', 'kind' => 'method', 'name' => 'zzzNoSuchMethodEverDeclared',
         'file' => 'core/models/server/ServerBuilder.php', 'expect' => 'BROKEN'],
        // Provably LIVE class.
        ['unit' => 'SELFTEST', 'kind' => 'class', 'name' => 'ServerBuilder',
         'file' => 'core/models/server/ServerBuilder.php', 'expect' => 'LIVE'],
    ];
    $ok = true;
    foreach (evaluateTargets($fixtures, $corpus, $ROOT, __FILE__) as $i => $r) {
        $expect = $fixtures[$i]['expect'];
        $pass = $r['status'] === $expect;
        $ok = $ok && $pass;
        printf("  [%s] %-32s expected %-6s got %-6s\n",
            $pass ? 'PASS' : 'FAIL', $r['name'], $expect, $r['status']);
    }
    if (!$ok) {
        echo "deadcode_report self-test: RED -- the report CANNOT detect its own defect class.\n";
        echo "Do not use its GREEN to authorise any deletion.\n";
        exit(1);
    }
    echo "deadcode_report self-test: GREEN -- live symbols detected as LIVE, missing symbols as BROKEN.\n";
    exit(0);
}

// ---------------------------------------------------------------- main

$raw = @file_get_contents($MANIFEST);
$manifest = $raw === false ? null : json_decode($raw, true);
if (!is_array($manifest) || !isset($manifest['targets']) || !is_array($manifest['targets'])) {
    fwrite(STDERR, "deadcode_report: RED cannot read target manifest at $MANIFEST\n");
    exit(2);
}

$targets = $manifest['targets'];
if ($unitFilter !== null) {
    $targets = array_values(array_filter($targets, static fn($t) => ($t['unit'] ?? '') === $unitFilter));
}
if ($symbolFilter !== null) {
    $targets = array_values(array_filter($targets, static fn($t) => ($t['name'] ?? '') === $symbolFilter));
}

if ($targets === []) {
    // An empty selection is a setup error, not a clean bill of health.
    fwrite(STDERR, "deadcode_report: RED no targets selected"
        . ($unitFilter !== null ? " for unit '$unitFilter'" : '')
        . ($symbolFilter !== null ? " for symbol '$symbolFilter'" : '')
        . " -- an empty target set is a setup error, never a pass\n");
    exit(2);
}

$corpus = collectCorpus($ROOT);
if ($corpus === []) {
    fwrite(STDERR, "deadcode_report: RED scan roots (" . implode(', ', SCAN_ROOTS)
        . ") contain no PHP files -- a zero over nothing is not evidence\n");
    exit(2);
}

$results = evaluateTargets($targets, $corpus, $ROOT, __FILE__);

$counts = ['DEAD' => 0, 'STALE_REFS' => 0, 'LIVE' => 0, 'BROKEN' => 0];
foreach ($results as $r) {
    $counts[$r['status']]++;
}
// STALE_REFS is green-eligible: a comment is not a caller. It is surfaced
// separately so the deleting commit also fixes the prose that names the symbol.
$green = ($counts['LIVE'] === 0 && $counts['BROKEN'] === 0);

$byUnit = [];
foreach ($results as $r) {
    $byUnit[$r['unit']][$r['status']] = ($byUnit[$r['unit']][$r['status']] ?? 0) + 1;
}

$payload = [
    'report' => 'deadcode',
    'generated_at' => gmdate('c'),
    'status' => $green ? 'GREEN' : 'RED',
    'scan_roots' => SCAN_ROOTS,
    'files_scanned' => count($corpus),
    'targets_examined' => count($results),
    'counts' => $counts,
    'by_unit' => $byUnit,
    'targets' => $results,
];

@mkdir(dirname($REPORT), 0775, true);
@file_put_contents($REPORT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

if ($asJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($green ? 0 : 1);
}

echo "deadcode_report: {$counts['DEAD']} DEAD, {$counts['STALE_REFS']} STALE_REFS, "
    . "{$counts['LIVE']} LIVE, {$counts['BROKEN']} BROKEN"
    . " over " . count($corpus) . " production file(s)\n";

foreach (['BROKEN', 'LIVE', 'STALE_REFS', 'DEAD'] as $group) {
    $rows = array_values(array_filter($results, static fn($r) => $r['status'] === $group));
    if ($rows === []) {
        continue;
    }
    echo "\n-- $group --\n";
    foreach ($rows as $r) {
        printf("  %-8s %-38s %s\n", $r['unit'], $r['name'], $r['why']);
        if ($r['conditional'] !== null) {
            echo "           CONDITIONAL: {$r['conditional']}\n";
        }
        foreach (array_slice($r['callers'], 0, 6) as $c) {
            echo "           - {$c['file']}:{$c['line']}  {$c['text']}\n";
        }
        if (count($r['callers']) > 6) {
            echo "           - ... " . (count($r['callers']) - 6) . " more\n";
        }
        if ($r['status'] === 'STALE_REFS') {
            foreach (array_slice($r['mentions'], 0, 6) as $m) {
                echo "           ~ {$m['file']}:{$m['line']}  {$m['text']}\n";
            }
            if (count($r['mentions']) > 6) {
                echo "           ~ ... " . (count($r['mentions']) - 6) . " more mention(s)\n";
            }
        }
        if ($r['other_declarations'] !== []) {
            echo "           NOTE " . count($r['other_declarations'])
                . " same-named declaration(s) elsewhere -- caller count is an OVER-count\n";
        }
    }
}

if (!$green) {
    echo "\ndeadcode_report: RED -- the listed symbols are NOT safe to delete yet.\n";
    echo "A LIVE target means a caller survives; a BROKEN target means the search failed\n";
    echo "and its zero proves nothing. Neither authorises a deletion.\n";
}

echo "\ndeadcode_report: " . ($green ? 'GREEN' : 'RED') . " reports/deadcode-report.json\n";
exit($green ? 0 : 1);
