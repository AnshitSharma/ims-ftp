<?php
/**
 * deadcode_scan.php — the scan half of deadcode_report.php, with no I/O and no exit().
 *
 * Extracted so two callers can share ONE implementation of the rules:
 *   - scripts/verify/deadcode_report.php  (CLI: adds the tree lint + report file)
 *   - server-debug-deadcode               (API: role-gated, scan only)
 *
 * The API caller exists because this host gives no shell, so the CLI report cannot
 * actually be run here — and a scan that never runs is not evidence. Read the header
 * of deadcode_report.php for the contract, why same-file callers are counted, and the
 * receiver-resolution limitation. Do not fork the rules: change them here.
 *
 * Returns pure arrays. Never echoes, never exits, never writes a file.
 *
 * -----------------------------------------------------------------------------
 * SOLE-WRITER DETECTION (added 2026-08-24 — see
 * migration/10-cleanup/FINDING-20260824-replaceOnboardNIC-not-superseded.md)
 * -----------------------------------------------------------------------------
 * Until today this scan answered exactly ONE question: "does any file name this
 * symbol?" That is necessary and it is not sufficient, and the gap has a name.
 *
 * replaceOnboardNIC() scored GREEN — zero blocking callers, zero internal
 * callers, confirmed independently by tree-wide grep AND by the deployed scan.
 * It is also the ONLY code in the codebase that writes `Flag = 'replaced'`
 * (OnboardNICHandler.php:530), and three surviving branches READ that value
 * (:108, :420, :421) and exist only to honour it. Delete the writer and those
 * three readers become permanently unreachable while staying syntactically
 * live — so the next run of this scan would rate THEM green too, on the same
 * reasoning, and a production invariant would be dismantled in individually-
 * justified pieces. Same fail-open family as the rest of this harness: a check
 * that returns a verdict because it cannot see the thing that matters.
 *
 * So: a symbol that is the SOLE WRITER of a persisted literal value that other
 * code READS is not deletable on a name-reference count, and this scan now says
 * so. The rule is evidence-based over the actual source — there is deliberately
 * NO allowlist of symbol names, because an allowlist would only ever know about
 * the one instance somebody already found by hand.
 *
 * HOW A WRITE IS RECOGNISED. Inside the candidate's own body (span resolved with
 * token_get_all(), not brace-counting — SQL heredocs and strings make raw brace
 * counting unsafe), a line is a write of (column, value) when:
 *   - it holds `column = 'value'` with a non-numeric, short, word-shaped value;
 *   - the nearest preceding SQL statement keyword within SQL_LOOKBACK lines is
 *     UPDATE / INSERT INTO / REPLACE INTO (not SELECT / DELETE / DDL); and
 *   - nothing on the line BEFORE the match reads as a comparison context
 *     (WHEN/WHERE/AND/OR/CASE/THEN/ELSE/...).
 * That last clause is what keeps `Status = CASE WHEN Flag = 'replaced' THEN ...`
 * (OnboardNICHandler.php:420) classified as a READ of Flag='replaced' rather
 * than a second writer of it — without it, the sole-writer test would have
 * silently answered "no" for the one symbol it was built for.
 *
 * HOW A READ IS RECOGNISED. Any non-comment line, anywhere in the scan corpus,
 * that contains the quoted literal AND names the column as a word, and is not
 * itself a write. That covers both the SQL form (`CASE WHEN Flag = 'replaced'`)
 * and the PHP form (`$existingNIC['Flag'] === 'replaced'`).
 *
 * VERDICT. sole writer (zero writes outside the body) AND at least one read
 * outside the body  ->  status RETAIN_SOLE_WRITER, with every reader location
 * named. It counts toward symbols_red — i.e. the gate refuses to certify the
 * symbol deletable — UNLESS the manifest entry declares `retain: true`, which
 * is how an owner records "known, reviewed, staying" without the gate nagging
 * forever. A declared retain still never reads as deletable.
 *
 * FAIL-SAFE. If a GREEN candidate's body cannot be located or its file cannot be
 * tokenised, the analysis did not run, and a check that could not run must not
 * print a pass: the symbol becomes RETAIN_UNVERIFIED and counts red. The ONLY
 * skip that is not red is ALREADY_GONE — a definition that no longer exists has
 * no body to be the sole writer of.
 *
 * SCOPE, stated honestly. This detects literal-valued persisted vocabulary
 * (enum/flag/status strings) in inline SQL. It does NOT detect a sole writer of
 * a NUMERIC status (`Status = 0` is written in dozens of places and carries no
 * distinguishing vocabulary), a value assembled from a variable, or one written
 * through a query builder. It is a tripwire for the class of invariant that
 * actually bit us, not a proof of deletability.
 */

declare(strict_types=1);

if (!function_exists('deadcodeCollectPhpFiles')) {

    /**
     * Every .php file under the scan roots, minus the excluded dirs.
     *
     * @return string[] repo-relative paths
     */
    function deadcodeCollectPhpFiles(string $root, array $scanRoots, array $excludedDirs): array
    {
        $files = [];
        foreach ($scanRoots as $sub) {
            $base = $root . DIRECTORY_SEPARATOR . $sub;
            if (!is_dir($base)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $entry) {
                /** @var SplFileInfo $entry */
                $rel = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
                foreach (explode('/', $rel) as $seg) {
                    if (in_array($seg, $excludedDirs, true)) {
                        continue 2;
                    }
                }
                if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                    $files[] = $rel;
                }
            }
        }
        sort($files);
        return $files;
    }

    /** A comment-only line carries no call site. */
    function deadcodeIsCommentLine(string $line): bool
    {
        $t = ltrim($line);
        return $t === ''
            || strpos($t, '//') === 0
            || strpos($t, '*') === 0
            || strpos($t, '/*') === 0
            || strpos($t, '#') === 0;
    }

    /** The definition itself is not a call site. */
    function deadcodeIsDefinitionLine(string $line, string $symbol, string $kind): bool
    {
        if ($kind === 'class') {
            return (bool)preg_match('/\b(class|interface|trait)\s+' . preg_quote($symbol, '/') . '\b/', $line);
        }
        return (bool)preg_match('/\bfunction\s+' . preg_quote($symbol, '/') . '\s*\(/', $line);
    }

    /** Call/reference syntax for the symbol, deliberately narrower than a bare name match. */
    function deadcodeReferencePattern(string $symbol, string $kind): string
    {
        $q = preg_quote($symbol, '/');
        if ($kind === 'class') {
            // new X, X::, extends/implements/use X, X $typed
            return '/(new\s+' . $q . '\b|\b' . $q . '\s*::|\b(extends|implements|use)\s+[\\\\\w]*' . $q . '\b|\b' . $q . '\s+\$)/';
        }
        // ->m(, ::m(, m( — the "function m(" case is filtered by deadcodeIsDefinitionLine().
        return '/(->\s*' . $q . '\s*\(|::\s*' . $q . '\s*\(|\b' . $q . '\s*\()/';
    }

    // -----------------------------------------------------------------------
    // Sole-writer detection helpers. See the file docblock for the reasoning.
    // -----------------------------------------------------------------------

    /**
     * How far back a line looks for the SQL statement keyword that governs it.
     * define() rather than const because this whole block is inside an
     * `if (!function_exists(...))` guard, and `const` is a compile-time
     * declaration that PHP does not allow in a conditional block.
     */
    if (!defined('DEADCODE_SQL_LOOKBACK')) {
        define('DEADCODE_SQL_LOOKBACK', 25);
    }

    /**
     * Line span [firstLine, lastLine] of the symbol's body, via the PHP tokeniser.
     *
     * Brace counting over raw text is not safe here: this codebase embeds
     * multi-line SQL and CONCAT() expressions in string literals, and one stray
     * brace inside a string would silently mis-span a body — which for a
     * fail-safe check means either a missed hazard or a bogus one. token_get_all()
     * cannot make that mistake. Returns null when the definition is not found,
     * the file cannot be tokenised, or the declaration has no body at all
     * (abstract/interface): every one of those is treated as "did not run".
     *
     * @return array{0:int,1:int}|null
     */
    function deadcodeBodySpan(string $absPath, string $symbol, string $kind): ?array
    {
        // Tokenised once per FILE, not once per symbol: ServerBuilder.php is ~9k
        // lines and holds 11 of the current candidates, and this function also runs
        // inside the role-gated API request, where re-tokenising it eleven times is
        // a real cost for no information.
        static $cache = [];
        if (!array_key_exists($absPath, $cache)) {
            $src = @file_get_contents($absPath);
            if ($src === false || $src === '') {
                $cache[$absPath] = null;
            } else {
                try {
                    $cache[$absPath] = @token_get_all($src) ?: null;
                } catch (\Throwable $e) {
                    $cache[$absPath] = null;
                }
            }
        }
        $tokens = $cache[$absPath];
        if (!$tokens) {
            return null;
        }
        $want = $kind === 'class' ? [T_CLASS, T_INTERFACE, T_TRAIT] : [T_FUNCTION];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], $want, true)) {
                continue;
            }
            // The name is the next meaningful token (skip whitespace/comments and
            // the by-reference `&` of `function &foo()`).
            $j = $i + 1;
            for (; $j < $n; $j++) {
                $s = $tokens[$j];
                if (is_array($s) && in_array($s[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($s === '&') {
                    continue;
                }
                break;
            }
            if (!isset($tokens[$j]) || !is_array($tokens[$j])
                || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $symbol) {
                continue;
            }
            $depth = 0;
            $startLine = null;
            for ($k = $j; $k < $n; $k++) {
                $tk = $tokens[$k];
                if ($tk === ';' && $depth === 0) {
                    return null;   // declaration without a body
                }
                if ($tk === '{') {
                    if ($depth === 0) {
                        $startLine = deadcodeTokenLine($tokens, $k);
                    }
                    $depth++;
                } elseif ($tk === '}') {
                    $depth--;
                    if ($depth === 0 && $startLine !== null) {
                        return [$startLine, deadcodeTokenLine($tokens, $k)];
                    }
                }
            }
            return null;
        }
        return null;
    }

    /** Source line of token $idx (literal tokens carry no line, so walk back). */
    function deadcodeTokenLine(array $tokens, int $idx): int
    {
        for ($i = $idx; $i >= 0; $i--) {
            if (is_array($tokens[$i])) {
                return $tokens[$i][2] + substr_count($tokens[$i][1], "\n");
            }
        }
        return 1;
    }

    /**
     * Nearest preceding SQL statement keyword governing $idx: 'write', 'read', or null.
     *
     * 'write' only for UPDATE / INSERT INTO / REPLACE INTO. SELECT, DELETE and DDL
     * return 'read' so a literal inside a SELECT's WHERE can never be mistaken for
     * persisted vocabulary being SET.
     */
    function deadcodeSqlContext(array $lines, int $idx): ?string
    {
        $stop = max(0, $idx - DEADCODE_SQL_LOOKBACK);
        for ($i = $idx; $i >= $stop; $i--) {
            $l = $lines[$i] ?? '';
            if (preg_match('/\b(UPDATE|INSERT\s+INTO|REPLACE\s+INTO)\b/i', $l)) {
                return 'write';
            }
            if (preg_match('/\b(SELECT|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE)\b/i', $l)) {
                return 'read';
            }
        }
        return null;
    }

    /**
     * Every (column, value) pair this line WRITES. Empty for a comparison,
     * a comment, or a line not governed by an UPDATE/INSERT.
     *
     * @return array<int,array{0:string,1:string}>
     */
    function deadcodeWriteMatches(array $lines, int $idx): array
    {
        $line = $lines[$idx] ?? '';
        if ($line === '' || deadcodeIsCommentLine($line)) {
            return [];
        }
        if (deadcodeSqlContext($lines, $idx) !== 'write') {
            return [];
        }
        $out = [];
        if (!preg_match_all("/([A-Za-z_][A-Za-z0-9_]*)\\s*=\\s*'([^']*)'/", $line, $m, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($m[0] as $x => $whole) {
            // Anything comparison-shaped ahead of the match on this line means we
            // are reading the value, not assigning it. This is the clause that
            // keeps `... = CASE WHEN Flag = 'replaced' THEN ...` out of the write set.
            $prefix = substr($line, 0, (int)$whole[1]);
            if (preg_match('/\b(WHEN|WHERE|AND|OR|CASE|IF|IFNULL|ON|HAVING|ELSE|THEN|NOT|IN|LIKE|COALESCE|NULLIF)\b/i', $prefix)) {
                continue;
            }
            $col = $m[1][$x][0];
            $val = $m[2][$x][0];
            // Persisted VOCABULARY only: a short word-shaped literal. Numbers,
            // empty strings, SQL fragments and sentences are out — they carry no
            // distinguishing identity and would generate noise, and a noisy
            // fail-safe check gets switched off, which is the real failure mode.
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_\- ]{0,31}$/', $val)) {
                continue;
            }
            $out[] = [$col, $val];
        }
        return $out;
    }

    /** Is this line a write of exactly (column, value)? */
    function deadcodeLineWrites(array $lines, int $idx, string $col, string $val): bool
    {
        foreach (deadcodeWriteMatches($lines, $idx) as [$c, $v]) {
            if ($c === $col && $v === $val) {
                return true;
            }
        }
        return false;
    }

    /**
     * Corpus-wide write and read sites for one (column, value) pair.
     *
     * A read is any non-comment line holding the quoted literal AND naming the
     * column as a word, that is not itself a write — which catches the SQL form
     * (`CASE WHEN Flag = 'replaced'`) and the PHP form
     * (`$row['Flag'] === 'replaced'`) with one rule.
     *
     * @param array<string,string[]> $contents rel path => lines
     * @return array{writes:array,reads:array}
     */
    function deadcodePersistedValueSites(array $contents, string $col, string $val): array
    {
        $needle = "'" . $val . "'";
        $colWord = '/\b' . preg_quote($col, '/') . '\b/';
        $writes = [];
        $reads = [];
        foreach ($contents as $rel => $lines) {
            foreach ($lines as $i => $line) {
                if (strpos($line, $needle) === false) {
                    continue;
                }
                $hit = ['file' => $rel, 'line' => $i + 1, 'text' => trim($line)];
                if (deadcodeLineWrites($lines, $i, $col, $val)) {
                    $writes[] = $hit;
                    continue;
                }
                if (deadcodeIsCommentLine($line)) {
                    continue;
                }
                if (!preg_match($colWord, $line)) {
                    continue;
                }
                $reads[] = $hit;
            }
        }
        return ['writes' => $writes, 'reads' => $reads];
    }

    /**
     * Sole-writer analysis for one candidate symbol.
     *
     * @param array<string,string[]> $contents
     * @return array{evaluated:bool,reason:?string,body_span:?array,findings:array}
     */
    function deadcodeSoleWriterAnalysis(
        string $root,
        array $contents,
        string $symbol,
        string $kind,
        string $definedIn
    ): array {
        $out = ['evaluated' => false, 'reason' => null, 'body_span' => null, 'findings' => []];

        if ($definedIn === '' || !isset($contents[$definedIn])) {
            $out['reason'] = $definedIn === ''
                ? 'manifest entry has no defined_in'
                : "defined_in ($definedIn) is outside the scan corpus";
            return $out;
        }
        $span = deadcodeBodySpan($root . '/' . $definedIn, $symbol, $kind);
        if ($span === null) {
            $out['reason'] = "could not resolve the body span of $symbol in $definedIn "
                . '(definition not found, file not tokenisable, or no body)';
            return $out;
        }

        $out['evaluated'] = true;
        $out['body_span'] = $span;
        $lines = $contents[$definedIn];

        // (column, value) pairs this body writes, in body order, de-duplicated.
        $pairs = [];
        for ($i = $span[0] - 1; $i <= $span[1] - 1; $i++) {
            if (!isset($lines[$i])) {
                break;
            }
            foreach (deadcodeWriteMatches($lines, $i) as [$col, $val]) {
                $key = $col . "\0" . $val;
                if (!isset($pairs[$key])) {
                    $pairs[$key] = ['column' => $col, 'value' => $val, 'write_lines' => []];
                }
                $pairs[$key]['write_lines'][] = $i + 1;
            }
        }

        $inBody = function (array $hit) use ($definedIn, $span): bool {
            return $hit['file'] === $definedIn && $hit['line'] >= $span[0] && $hit['line'] <= $span[1];
        };

        foreach ($pairs as $p) {
            $sites = deadcodePersistedValueSites($contents, $p['column'], $p['value']);
            $writesOutside = array_values(array_filter($sites['writes'], fn($h) => !$inBody($h)));
            $readsOutside = array_values(array_filter($sites['reads'], fn($h) => !$inBody($h)));
            if ($writesOutside || !$readsOutside) {
                continue;   // not sole, or nothing depends on it — no hazard
            }
            $out['findings'][] = [
                'column' => $p['column'],
                'value' => $p['value'],
                'write_lines_in_symbol' => $p['write_lines'],
                'other_writers' => 0,
                'reader_count' => count($readsOutside),
                'readers' => $readsOutside,
            ];
        }

        return $out;
    }

    /**
     * Scan the tree for every manifest symbol's call sites.
     *
     * @param string      $root       ims-ftp/
     * @param array       $manifest   decoded deadcode_manifest.json
     * @param string|null $onlyUnit   restrict to one unit (e.g. 'U-D.1')
     * @param string|null $onlySymbol restrict to one symbol
     * @return array{error:?string,php_files_scanned:int,symbols_selected:int,symbols_red:int,scan:array,results:array}
     */
    function deadcodeScan(string $root, array $manifest, ?string $onlyUnit = null, ?string $onlySymbol = null): array
    {
        $scanRoots = $manifest['_scan_roots'] ?? ['api', 'core', 'scripts', 'includes', 'cli'];
        $excludedDirs = $manifest['_excluded_dirs'] ?? ['tests', 'migration', 'docs', 'reports', 'database', 'vendor', 'node_modules', '.git'];

        $empty = [
            'php_files_scanned' => 0,
            'symbols_selected' => 0,
            'symbols_red' => 0,
            'symbols_retain' => 0,
            'scan' => ['roots' => $scanRoots, 'excluded_dirs' => $excludedDirs],
            'results' => [],
        ];

        $files = deadcodeCollectPhpFiles($root, $scanRoots, $excludedDirs);
        if (!$files) {
            return array_merge($empty, ['error' => 'Scanned zero PHP files — check _scan_roots in the manifest']);
        }

        // Read every file once; the manifest is small but the tree is not.
        $contents = [];
        foreach ($files as $rel) {
            $contents[$rel] = file($root . '/' . $rel, FILE_IGNORE_NEW_LINES) ?: [];
        }

        $results = [];
        $selected = 0;
        $redCount = 0;
        $retainCount = 0;

        foreach ($manifest['symbols'] as $entry) {
            $symbol = $entry['symbol'] ?? null;
            if ($symbol === null) {
                continue;
            }
            if ($onlyUnit !== null && ($entry['unit'] ?? null) !== $onlyUnit) {
                continue;
            }
            if ($onlySymbol !== null && $symbol !== $onlySymbol) {
                continue;
            }
            $selected++;

            $kind = $entry['kind'] ?? 'method';
            $definedIn = str_replace('\\', '/', (string)($entry['defined_in'] ?? ''));
            $allowed = $entry['allowed_callers'] ?? [];
            $pattern = deadcodeReferencePattern($symbol, $kind);

            $blocking = [];
            $allowedHits = [];
            $internalHits = [];
            $definitionSeen = false;
            $internalDeclaredDead = (bool)($entry['internal_callers_also_deleted'] ?? false);

            foreach ($contents as $rel => $lines) {
                $isOwnFile = ($rel === $definedIn);
                foreach ($lines as $i => $line) {
                    if (strpos($line, $symbol) === false) {
                        continue;
                    }
                    if (deadcodeIsDefinitionLine($line, $symbol, $kind)) {
                        if ($isOwnFile) {
                            $definitionSeen = true;
                        }
                        continue;
                    }
                    if (deadcodeIsCommentLine($line)) {
                        continue;
                    }
                    if (!preg_match($pattern, $line)) {
                        continue;
                    }

                    $hit = ['file' => $rel, 'line' => $i + 1, 'text' => trim($line)];
                    if ($isOwnFile) {
                        // Same-file caller. Counted, not discarded — see deadcode_report.php's header.
                        $internalHits[] = $hit;
                        continue;
                    }
                    $isAllowed = false;
                    foreach ($allowed as $prefix) {
                        if (strpos($rel, str_replace('\\', '/', $prefix)) === 0) {
                            $isAllowed = true;
                            break;
                        }
                    }
                    if ($isAllowed) {
                        $allowedHits[] = $hit;
                    } else {
                        $blocking[] = $hit;
                    }
                }
            }

            // A symbol whose definition is gone is already deleted — green, and say so.
            $definitionExists = $definedIn !== '' && is_file($root . '/' . $definedIn) && $definitionSeen;
            $internalBlocks = $internalHits && !$internalDeclaredDead;
            if (!$definitionExists && !$blocking && !$internalHits) {
                $status = 'ALREADY_GONE';
            } elseif ($blocking) {
                $status = 'RED';
            } elseif ($internalBlocks) {
                $status = 'RED_INTERNAL';
            } else {
                $status = 'GREEN';
            }

            // ---- sole-writer gate (2026-08-24) ------------------------------
            // Runs ONLY on a candidate the reference count would have cleared,
            // because that is exactly where the old rule was unsafe. A symbol
            // already RED needs no second reason.
            //
            // ALREADY_GONE is skipped and stays green: there is no body left to
            // be the sole writer of. Every OTHER unevaluable case is RED — a
            // check that could not run must never print a pass (the fail-open
            // family this harness keeps finding).
            $soleWriter = ['evaluated' => false, 'reason' => 'not applicable (symbol is not otherwise deletable)', 'body_span' => null, 'findings' => []];
            if ($status === 'GREEN') {
                $soleWriter = deadcodeSoleWriterAnalysis($root, $contents, $symbol, $kind, $definedIn);
                if (!$soleWriter['evaluated']) {
                    $status = 'RETAIN_UNVERIFIED';
                } elseif ($soleWriter['findings']) {
                    $status = 'RETAIN_SOLE_WRITER';
                }
            } elseif ($status === 'ALREADY_GONE') {
                $soleWriter['reason'] = 'not applicable (definition is already gone — no body to own a persisted value)';
            }

            // A declared retain is an owner decision already on record; it is
            // still NOT deletable, it just stops counting against the gate.
            $retain = (bool)($entry['retain'] ?? false);
            if ($status === 'RED' || $status === 'RED_INTERNAL'
                || (($status === 'RETAIN_SOLE_WRITER' || $status === 'RETAIN_UNVERIFIED') && !$retain)) {
                $redCount++;
            }

            if ($status === 'RETAIN_SOLE_WRITER' || $status === 'RETAIN_UNVERIFIED') {
                $retainCount++;
            }

            $results[] = [
                'symbol' => $symbol,
                'kind' => $kind,
                'unit' => $entry['unit'] ?? null,
                'defined_in' => $definedIn,
                'definition_present' => $definitionExists,
                'retain' => $retain,
                'status' => $status,
                // Additive (2026-08-24). deletable is the question callers
                // actually ask; status alone now has two non-deletable greens'
                // worth of nuance in it.
                'deletable' => $status === 'GREEN' || $status === 'ALREADY_GONE',
                'sole_writer' => $soleWriter,
                'blocking_call_sites' => $blocking,
                'blocking_count' => count($blocking),
                'internal_call_sites' => $internalHits,
                'internal_count' => count($internalHits),
                'internal_callers_also_deleted' => $internalDeclaredDead,
                'allowed_call_sites' => $allowedHits,
                'allowed_count' => count($allowedHits),
                'note' => $entry['note'] ?? null,
            ];
        }

        if ($selected === 0) {
            return array_merge($empty, [
                'error' => 'No manifest symbol matched the given filters',
                'php_files_scanned' => count($files),
            ]);
        }

        return [
            'error' => null,
            'php_files_scanned' => count($files),
            'symbols_selected' => $selected,
            'symbols_red' => $redCount,
            // Additive (2026-08-24): symbols the reference count cleared but the
            // sole-writer rule refuses to call deletable.
            'symbols_retain' => $retainCount,
            'scan' => ['roots' => $scanRoots, 'excluded_dirs' => $excludedDirs],
            'results' => $results,
        ];
    }
}
