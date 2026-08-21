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
            if ($status === 'RED' || $status === 'RED_INTERNAL') {
                $redCount++;
            }

            $results[] = [
                'symbol' => $symbol,
                'kind' => $kind,
                'unit' => $entry['unit'] ?? null,
                'defined_in' => $definedIn,
                'definition_present' => $definitionExists,
                'retain' => (bool)($entry['retain'] ?? false),
                'status' => $status,
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
            'scan' => ['roots' => $scanRoots, 'excluded_dirs' => $excludedDirs],
            'results' => $results,
        ];
    }
}
