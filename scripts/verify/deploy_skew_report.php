<?php
/**
 * deploy_skew_report.php — 11-verification/README.md #13 (added 2026-08-24).
 *
 * Gates the ONE input the dead-code gate cannot check for itself: whether the
 * corpus it scanned is the same set of files as the source of truth.
 *
 * WHY THIS EXISTS (see reports/deploy-skew-20260824.md for the full write-up)
 * -------------------------------------------------------------------------
 * The SFTP deployment uploads on save and NEVER deletes. Every PHP file ever
 * deployed and later removed from the working tree is still sitting on the
 * server. Measured 2026-08-24: production scans 162 PHP files under the same
 * _scan_roots minus _excluded_dirs where the local tree has 146. Sixteen
 * orphans, none of them recoverable by diff — this monorepo has no version
 * control.
 *
 * That matters because the dead-code gate is a DELETION AUTHORITY, and the
 * authoritative run of it is the DEPLOYED one (this host has no shell, so
 * server-debug-deadcode is the only way the scan runs against production).
 * Its corpus is therefore a superset of the source of truth, and the failure
 * mode is silent in both directions:
 *
 *   - an orphan that cites a manifest symbol => a permanent, unexplained RED
 *     that no local grep can reproduce and nobody can trace to its cause;
 *   - worse, an orphan that is a stale COPY of a file we later removed a caller
 *     from => the gate keeps seeing the old caller and stays RED after the real
 *     fix landed.
 *
 * The 2026-08-24 delta was checked by hand and found benign: across all 23
 * symbols in the deployed manifest, the union of blocking / internal / allowed
 * call sites and defined_in cites 8 distinct files, all 8 present locally.
 * Production-only files cited: zero. But benign-today is not benign-by-
 * construction, and nothing detected it. This report is the detector.
 *
 * WHAT IS RED AND WHAT IS ONLY A WARNING
 * --------------------------------------
 * RED is deliberately narrow, because a count delta is the NORMAL state of this
 * deployment and a check that screams every run gets ignored:
 *
 *   RED   a production-only file (present in the deployed corpus, absent from
 *         the local tree) is CITED by a production symbol verdict — in any of
 *         blocking_sites, internal_sites, allowed call sites, or defined_in.
 *         That is the moment an orphan started deciding a deletion verdict.
 *   RED   the mirror case, when a full production listing is available: a file
 *         cited by the LOCAL verdicts is absent from production. Then the
 *         DEPLOYED gate is blind to a caller the source of truth has, and a
 *         deployed GREEN is fail-open.
 *   RED   the roots/exclusions the two sides scanned differ. Two different
 *         questions were asked; the counts are not comparable at all.
 *   RED   NOT RUNNABLE (exit 2) — no snapshot, an unreadable or unrecognised
 *         snapshot, or a snapshot carrying no production symbol verdicts, so the
 *         citation test could not be evaluated. A check that could not run must
 *         never print a pass. This is the fail-open family the rest of this
 *         harness keeps finding, and it is not being repeated here.
 *   WARN  the file counts (or the file sets) differ, but no cited file falls on
 *         the wrong side. Green: today's known, reviewed, benign skew.
 *   WARN  the two sides selected a different number of manifest symbols — the
 *         deployed manifest is stale (23 vs 26 on 2026-08-24). Reported loudly
 *         because it means production verdicts cover a different symbol set,
 *         but it does not invalidate the citation test for the symbols both have.
 *
 * THIS REPORT NEVER CHANGES ANOTHER CHECK'S VERDICT. It reads a snapshot and the
 * local filesystem, computes set arithmetic, and writes its own report file.
 * It does not touch the manifest, the scan rules, or any other report.
 *
 * THE LOCAL INVENTORY IS NOT REIMPLEMENTED HERE. It comes from
 * deadcodeCollectPhpFiles() with the roots and exclusions read out of
 * deadcode_manifest.json — literally the same function and the same inputs the
 * dead-code gate uses. A second opinion about "which files count" is precisely
 * the drift this report exists to detect, so writing one here would be the bug.
 *
 * HOW TO PRODUCE THE PRODUCTION SNAPSHOT
 * --------------------------------------
 * The deployed scan is reachable only through the role-gated API action, so:
 *
 *   curl -s -X POST https://<host>/Ims_backend/api/api.php \
 *        -H "Authorization: Bearer $TOKEN" \
 *        -d "action=server-debug-deadcode" \
 *        -o ims-ftp/reports/production-inventory.json
 *
 * Save the response verbatim — envelope and all. That response carries
 * php_files_scanned and every symbol's cited sites, which is enough for the
 * citation test (it is exactly what the 2026-08-24 check was done by hand on).
 *
 * It does NOT carry the production file LIST, so the set delta and the mirror
 * test stay unevaluated in that mode, and this report says so instead of
 * implying it checked. To get those too, add a real listing — an FTP/shell
 * listing of api/, core/, scripts/ — as a `files` array, either in a wrapper:
 *
 *   { "files": ["api/api.php", ...], "deadcode": <the API response above> }
 *
 * or as a plain text file, one repo-relative path per line (`--production
 * listing.txt`), which is inventory-only and therefore not sufficient on its own.
 *
 * Usage:
 *   php scripts/verify/deploy_skew_report.php
 *   php scripts/verify/deploy_skew_report.php --production <path>
 *   php scripts/verify/deploy_skew_report.php --self-test
 *
 * Snapshot resolution order: --production <path>, then the
 * DEPLOY_SKEW_SNAPSHOT env var, then the newest
 * reports/production-inventory*.{json,txt}.
 *
 * Exit: 0 = green (possibly with warnings), 1 = red finding, 2 = not runnable /
 * usage error. Both 1 and 2 read RED in run_all.php, which is the point.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "deploy_skew_report.php is a CLI tool.\n";
    exit;
}

$ROOT = dirname(__DIR__, 2);                 // ims-ftp/
require_once __DIR__ . '/deadcode_scan.php';

const SNAPSHOT_GLOBS = ['/reports/production-inventory*.json', '/reports/production-inventory*.txt'];

// -----------------------------------------------------------------------
// args
// -----------------------------------------------------------------------
$snapshotPath = null;
$selfTest = false;
$argvRest = array_slice($argv, 1);
for ($i = 0; $i < count($argvRest); $i++) {
    $a = $argvRest[$i];
    if ($a === '--production') {
        $snapshotPath = $argvRest[++$i] ?? null;
        if ($snapshotPath === null) {
            fwrite(STDERR, "--production needs a path\n");
            exit(2);
        }
    } elseif (strpos($a, '--production=') === 0) {
        $snapshotPath = substr($a, 13);
    } elseif ($a === '--self-test') {
        $selfTest = true;
    } else {
        fwrite(STDERR, "Unknown argument: $a\n"
            . "Usage: php scripts/verify/deploy_skew_report.php [--production <path>] [--self-test]\n");
        exit(2);
    }
}

// -----------------------------------------------------------------------
// The local side, from the dead-code gate's own collector and manifest.
// -----------------------------------------------------------------------
$manifestPath = __DIR__ . '/deadcode_manifest.json';
if (!is_file($manifestPath)) {
    fwrite(STDERR, "deploy_skew_report: cannot locate deadcode_manifest.json — the local inventory is defined by its "
        . "_scan_roots/_excluded_dirs and this report will not guess them.\n");
    exit(2);
}
$manifest = json_decode((string)file_get_contents($manifestPath), true);
if (!is_array($manifest) || empty($manifest['symbols'])) {
    fwrite(STDERR, "deploy_skew_report: deadcode_manifest.json is missing or malformed.\n");
    exit(2);
}

$localRoots = $manifest['_scan_roots'] ?? ['api', 'core', 'scripts', 'includes', 'cli'];
$localExcluded = $manifest['_excluded_dirs'] ?? ['tests', 'migration', 'docs', 'reports', 'database', 'vendor', 'node_modules', '.git'];
$localFiles = deadcodeCollectPhpFiles($ROOT, $localRoots, $localExcluded);
if (!$localFiles) {
    fwrite(STDERR, "deploy_skew_report: the local scan found zero PHP files under " . implode(', ', $localRoots)
        . " — refusing to compare a deployed corpus against nothing.\n");
    exit(2);
}

/** Local symbol verdicts, so the mirror test knows which local files are load-bearing. */
$localScan = deadcodeScan($ROOT, $manifest);
if (!empty($localScan['error'])) {
    fwrite(STDERR, "deploy_skew_report: the local dead-code scan failed (" . $localScan['error']
        . ") — its cited-file set is half of this comparison.\n");
    exit(2);
}

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

/** Normalise any path shape to a repo-relative forward-slash path. */
function normalisePath(string $p): string
{
    $p = trim(str_replace('\\', '/', $p));
    $p = preg_replace('#^\./#', '', $p) ?? $p;
    return ltrim($p, '/');
}

/** "core/x.php:123" -> "core/x.php". A bare path passes through unchanged. */
function pathOfSite(string $site): string
{
    $site = normalisePath($site);
    return preg_replace('/:\d+$/', '', $site) ?? $site;
}

/**
 * Every file a set of symbol verdicts cites, from whichever shape the verdicts
 * arrive in: the API's flattened "path:line" strings, or the CLI report's
 * nested {file,line} hits. defined_in counts too — a symbol DEFINED in an
 * orphan is the starkest version of the problem.
 *
 * @return array<string,string[]> file => list of citation labels
 */
function citedFiles(array $symbols): array
{
    $out = [];
    $add = function (string $file, string $label) use (&$out) {
        $file = pathOfSite($file);
        if ($file === '') {
            return;
        }
        $out[$file][] = $label;
    };
    foreach ($symbols as $s) {
        if (!is_array($s)) {
            continue;
        }
        $name = (string)($s['symbol'] ?? '?');
        if (!empty($s['defined_in']) && is_string($s['defined_in'])) {
            $add($s['defined_in'], "$name/defined_in");
        }
        $groups = [
            'blocking_sites' => 'blocking',
            'internal_sites' => 'internal',
            'allowed_sites' => 'allowed',
            'blocking_call_sites' => 'blocking',
            'internal_call_sites' => 'internal',
            'allowed_call_sites' => 'allowed',
        ];
        foreach ($groups as $key => $label) {
            if (empty($s[$key]) || !is_array($s[$key])) {
                continue;
            }
            foreach ($s[$key] as $hit) {
                if (is_string($hit)) {
                    $add($hit, "$name/$label");
                } elseif (is_array($hit) && !empty($hit['file']) && is_string($hit['file'])) {
                    $add($hit['file'], "$name/$label");
                }
            }
        }
    }
    foreach ($out as $f => $labels) {
        $out[$f] = array_values(array_unique($labels));
    }
    ksort($out);
    return $out;
}

/**
 * Pull the production side out of whatever the owner saved.
 *
 * Recognised shapes, in order: a wrapper {files:[], deadcode:{}}, the
 * server-debug-deadcode API envelope {data:{...}}, a bare scan payload, a
 * deadcode_report.php report file, or a plain text listing.
 *
 * @return array{ok:bool,reason:?string,shape:?string,files:?array,count:?int,symbols:?array,roots:?array,excluded:?array,symbols_selected:?int}
 */
function parseSnapshot(string $path): array
{
    $fail = fn(string $why) => ['ok' => false, 'reason' => $why, 'shape' => null, 'files' => null,
        'count' => null, 'symbols' => null, 'roots' => null, 'excluded' => null, 'symbols_selected' => null];

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $fail("snapshot $path is unreadable or empty");
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        // Plain listing: one path per line. Inventory only — no verdicts, so the
        // citation test cannot run and the caller will report that, not a pass.
        $lines = preg_split('/\R/', $raw) ?: [];
        $files = [];
        foreach ($lines as $l) {
            $l = normalisePath($l);
            if ($l === '' || strpos($l, '#') === 0) {
                continue;
            }
            if (substr(strtolower($l), -4) !== '.php') {
                continue;
            }
            $files[] = $l;
        }
        if (!$files) {
            return $fail("snapshot $path is neither valid JSON nor a listing containing any .php path");
        }
        sort($files);
        $files = array_values(array_unique($files));
        return ['ok' => true, 'reason' => null, 'shape' => 'text-listing', 'files' => $files,
            'count' => count($files), 'symbols' => null, 'roots' => null, 'excluded' => null,
            'symbols_selected' => null];
    }

    $files = null;
    foreach (['files', 'php_files', 'inventory'] as $k) {
        if (!empty($json[$k]) && is_array($json[$k])) {
            $files = array_values(array_unique(array_map('normalisePath', array_filter($json[$k], 'is_string'))));
            sort($files);
            break;
        }
    }

    // Locate the scan payload: a wrapper's `deadcode`, the API envelope's
    // `data`, or the object itself.
    $payload = null;
    $shape = null;
    foreach ([['deadcode', 'wrapper'], ['data', 'api-envelope']] as [$k, $label]) {
        if (isset($json[$k]) && is_array($json[$k])) {
            $payload = $json[$k];
            $shape = $label;
            // The API envelope nests once; a wrapper may hold the whole envelope.
            if (isset($payload['data']) && is_array($payload['data'])) {
                $payload = $payload['data'];
            }
            break;
        }
    }
    if ($payload === null && (isset($json['php_files_scanned']) || isset($json['symbols']) || isset($json['results']))) {
        $payload = $json;
        $shape = isset($json['report']) ? 'cli-report' : 'bare-scan';
    }
    if ($payload === null && $files === null) {
        return $fail("snapshot $path is JSON but carries neither a files[] inventory nor a dead-code scan payload "
            . "(expected php_files_scanned / symbols / results, or a {files:[],deadcode:{}} wrapper)");
    }

    $symbols = null;
    $count = null;
    $roots = null;
    $excluded = null;
    $selected = null;
    if ($payload !== null) {
        if (!empty($payload['symbols']) && is_array($payload['symbols'])) {
            $symbols = $payload['symbols'];
        } elseif (!empty($payload['results']) && is_array($payload['results'])) {
            $symbols = $payload['results'];
        }
        if (isset($payload['php_files_scanned'])) {
            $count = (int)$payload['php_files_scanned'];
        } elseif (isset($payload['scan']['php_files_scanned'])) {
            $count = (int)$payload['scan']['php_files_scanned'];
        }
        if (isset($payload['scan']['roots']) && is_array($payload['scan']['roots'])) {
            $roots = $payload['scan']['roots'];
        }
        if (isset($payload['scan']['excluded_dirs']) && is_array($payload['scan']['excluded_dirs'])) {
            $excluded = $payload['scan']['excluded_dirs'];
        }
        if (isset($payload['symbols_selected'])) {
            $selected = (int)$payload['symbols_selected'];
        }
    }
    if ($files !== null && $count === null) {
        $count = count($files);
    }
    if ($count === null) {
        return $fail("snapshot $path carries a scan payload with no php_files_scanned and no files[] — there is "
            . "nothing to compare the local inventory against");
    }

    return ['ok' => true, 'reason' => null, 'shape' => $shape ?? 'files-only', 'files' => $files,
        'count' => $count, 'symbols' => $symbols, 'roots' => $roots, 'excluded' => $excluded,
        'symbols_selected' => $selected];
}

/**
 * The whole comparison, as a pure function so --self-test can drive it with
 * synthetic input and prove the RED actually fires.
 *
 * @return array the report record
 */
function compare(array $snapshot, array $localFiles, array $localSymbols, array $localRoots, array $localExcluded, int $localSelected): array
{
    $localSet = array_fill_keys($localFiles, true);
    $prodFiles = $snapshot['files'];
    $prodCount = (int)$snapshot['count'];

    $rec = [
        'snapshot_shape' => $snapshot['shape'],
        'local_file_count' => count($localFiles),
        'production_file_count' => $prodCount,
        'count_delta' => $prodCount - count($localFiles),
        'roots' => ['local' => $localRoots, 'production' => $snapshot['roots']],
        'excluded_dirs' => ['local' => $localExcluded, 'production' => $snapshot['excluded']],
        'tests' => [],
        'production_only_files' => null,
        'local_only_files' => null,
        'cited_production_only' => [],
        'cited_local_only' => [],
        'warnings' => [],
        'red' => [],
    ];

    // ---- scan-parameter agreement -------------------------------------------
    $normList = function (?array $a): ?array {
        if ($a === null) {
            return null;
        }
        $a = array_map('strval', $a);
        sort($a);
        return $a;
    };
    $lr = $normList($localRoots);
    $pr = $normList($snapshot['roots']);
    $le = $normList($localExcluded);
    $pe = $normList($snapshot['excluded']);
    if ($pr === null && $pe === null) {
        $rec['tests']['scan_parameters'] = 'NOT_EVALUATED';
        $rec['warnings'][] = 'The snapshot does not state which roots/exclusions production scanned '
            . '(server-debug-deadcode does not return them), so "under the same roots" is asserted, not verified. '
            . 'A deployed manifest with different _scan_roots would make the two counts incomparable and this '
            . 'report could not tell.';
    } else {
        $rec['tests']['scan_parameters'] = 'EVALUATED';
        if ($pr !== null && $pr !== $lr) {
            $rec['red'][] = 'roots differ: local [' . implode(',', $lr) . '] vs production [' . implode(',', $pr)
                . ']. The two sides scanned different questions; the counts are not comparable.';
        }
        if ($pe !== null && $pe !== $le) {
            $rec['red'][] = 'excluded_dirs differ: local [' . implode(',', $le) . '] vs production ['
                . implode(',', $pe) . ']. Same problem.';
        }
    }

    // ---- manifest agreement (informational) ---------------------------------
    if ($snapshot['symbols_selected'] !== null && $snapshot['symbols_selected'] !== $localSelected) {
        $rec['warnings'][] = 'Manifest skew: production selected ' . $snapshot['symbols_selected']
            . ' symbol(s), local selects ' . $localSelected . '. The deployed manifest is not the local one, so '
            . 'production verdicts cover a different symbol set. The citation test below still holds for the '
            . 'symbols production did evaluate.';
    }

    // ---- the citation test (the point of this report) -----------------------
    if ($snapshot['symbols'] === null) {
        $rec['tests']['production_citation'] = 'NOT_EVALUATED';
        $rec['red'][] = 'NOT RUNNABLE: the snapshot carries no production symbol verdicts, so the one test this '
            . 'report exists for — is any production-only file CITED by a deployed verdict — could not be '
            . 'evaluated. Capture a server-debug-deadcode response (see this file\'s header). A count delta '
            . 'alone is not the finding and this report will not pass on it.';
    } else {
        $rec['tests']['production_citation'] = 'EVALUATED';
        $cited = citedFiles($snapshot['symbols']);
        $rec['production_cited_files'] = array_keys($cited);
        foreach ($cited as $file => $labels) {
            if (isset($localSet[$file])) {
                continue;
            }
            $rec['cited_production_only'][] = ['file' => $file, 'cited_by' => $labels];
        }
        if ($rec['cited_production_only']) {
            $rec['red'][] = count($rec['cited_production_only']) . ' file(s) cited by a PRODUCTION symbol verdict do '
                . 'not exist in the local tree. The deployed dead-code gate is deciding deletion verdicts on code '
                . 'that is not in the source of truth — exactly the failure reports/deploy-skew-20260824.md '
                . 'predicted. No deletion may proceed on those verdicts until the orphans are removed from the '
                . 'server and the deployed scan re-run.';
        }
    }

    // ---- set delta + the mirror test (needs a real listing) -----------------
    if ($prodFiles === null) {
        $rec['tests']['set_delta'] = 'NOT_EVALUATED';
        $rec['tests']['local_citation_mirror'] = 'NOT_EVALUATED';
        $rec['warnings'][] = 'The snapshot carries a file COUNT but no file LIST, so the production-only and '
            . 'local-only file sets are unknown, and the mirror test (a locally-cited file MISSING from '
            . 'production, which would make a deployed GREEN fail-open) could not run. Add a `files` array to '
            . 'evaluate both. This limitation is stated rather than papered over: the count delta below is the '
            . 'only inventory evidence in this run.';
    } else {
        $rec['tests']['set_delta'] = 'EVALUATED';
        $prodSet = array_fill_keys($prodFiles, true);
        $rec['production_only_files'] = array_values(array_diff($prodFiles, $localFiles));
        $rec['local_only_files'] = array_values(array_diff($localFiles, $prodFiles));

        $rec['tests']['local_citation_mirror'] = 'EVALUATED';
        foreach (citedFiles($localSymbols) as $file => $labels) {
            if (isset($prodSet[$file])) {
                continue;
            }
            $rec['cited_local_only'][] = ['file' => $file, 'cited_by' => $labels];
        }
        if ($rec['cited_local_only']) {
            $rec['red'][] = count($rec['cited_local_only']) . ' file(s) cited by a LOCAL symbol verdict are absent '
                . 'from the production corpus. The deployed gate cannot see a caller the source of truth has, so a '
                . 'deployed GREEN for those symbols is fail-open. Deploy the missing files, then re-run the '
                . 'deployed scan.';
        }
    }

    // ---- counts -------------------------------------------------------------
    if ($rec['count_delta'] !== 0) {
        $rec['warnings'][] = 'Inventory delta: production scans ' . $prodCount . ' PHP file(s), local has '
            . count($localFiles) . ' (' . ($rec['count_delta'] > 0 ? '+' : '') . $rec['count_delta']
            . ' on production). FTP uploads on save and never deletes, so a positive delta is orphaned deployed '
            . 'code; a negative one is code that has not shipped. Not a gate failure on its own — it becomes one '
            . 'the moment one of those files is cited above.';
    }

    $rec['green'] = empty($rec['red']);
    return $rec;
}

// -----------------------------------------------------------------------
// Output
// -----------------------------------------------------------------------
function writeReport(string $root, array $rec, string $mode): string
{
    $dir = $root . '/reports';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $file = $dir . '/deploy-skew-' . date('Ymd-His') . ($mode === 'self-test' ? '-selftest' : '') . '.json';
    file_put_contents($file, json_encode(array_merge([
        'report' => 'deploy_skew_report',
        'generated_at' => date('c'),
        'mode' => $mode,
    ], $rec), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $file;
}

function printRecord(array $rec): void
{
    echo "deploy_skew_report: local=" . $rec['local_file_count'] . " production=" . $rec['production_file_count']
        . " delta=" . ($rec['count_delta'] > 0 ? '+' : '') . $rec['count_delta']
        . " snapshot=" . $rec['snapshot_shape'] . "\n";
    foreach ($rec['tests'] as $name => $state) {
        echo "deploy_skew_report:   test $name: $state\n";
    }
    foreach ($rec['cited_production_only'] as $c) {
        echo "deploy_skew_report:   PRODUCTION-ONLY FILE CITED: " . $c['file'] . "  <- " . implode(', ', $c['cited_by']) . "\n";
    }
    foreach ($rec['cited_local_only'] as $c) {
        echo "deploy_skew_report:   LOCAL-ONLY FILE CITED (deployed gate is blind to it): " . $c['file']
            . "  <- " . implode(', ', $c['cited_by']) . "\n";
    }
    if (!empty($rec['production_only_files'])) {
        echo "deploy_skew_report:   " . count($rec['production_only_files']) . " production-only file(s): "
            . implode(', ', array_slice($rec['production_only_files'], 0, 20))
            . (count($rec['production_only_files']) > 20 ? ', ...' : '') . "\n";
    }
    if (!empty($rec['local_only_files'])) {
        echo "deploy_skew_report:   " . count($rec['local_only_files']) . " local-only (undeployed) file(s): "
            . implode(', ', array_slice($rec['local_only_files'], 0, 20))
            . (count($rec['local_only_files']) > 20 ? ', ...' : '') . "\n";
    }
    foreach ($rec['warnings'] as $w) {
        echo "deploy_skew_report:   WARN $w\n";
    }
    foreach ($rec['red'] as $r) {
        echo "deploy_skew_report:   RED $r\n";
    }
}

$localSymbolResults = $localScan['results'];
$localSelected = (int)$localScan['symbols_selected'];

// -----------------------------------------------------------------------
// --self-test: prove the RED fires, and prove the benign case does not.
//
// Deliberate deviation from partial_rows_report.php, which exits 0 when its
// self-test FAILS to detect. That prints green for a broken checker. Here a
// successful detection exits 1 (so a self-test run can never be mistaken for a
// green gate run) and a failed detection exits 2 (the checker itself is broken —
// an infrastructure failure, which is what exit 2 means everywhere else).
// -----------------------------------------------------------------------
if ($selfTest) {
    $victim = $localFiles[0];
    $orphan = 'core/models/__deploy_skew_selftest_orphan.php';

    // Case A — hostile: production has an extra file AND cites it.
    $hostile = [
        'ok' => true, 'reason' => null, 'shape' => 'self-test', 'files' => null,
        'count' => count($localFiles) + 1,
        'symbols' => [[
            'symbol' => 'selfTestSymbol',
            'defined_in' => $victim,
            'blocking_sites' => [$orphan . ':42'],
            'internal_sites' => [],
        ]],
        'roots' => null, 'excluded' => null, 'symbols_selected' => null,
    ];
    $a = compare($hostile, $localFiles, $localSymbolResults, $localRoots, $localExcluded, $localSelected);

    // Case B — benign: same count delta, nothing production-only is cited.
    $benign = $hostile;
    $benign['symbols'] = [[
        'symbol' => 'selfTestSymbol',
        'defined_in' => $victim,
        'blocking_sites' => [$victim . ':42'],
        'internal_sites' => [],
    ]];
    $b = compare($benign, $localFiles, $localSymbolResults, $localRoots, $localExcluded, $localSelected);

    // Case C — not runnable: a count with no verdicts must not pass.
    $blind = $hostile;
    $blind['symbols'] = null;
    $c = compare($blind, $localFiles, $localSymbolResults, $localRoots, $localExcluded, $localSelected);

    $caughtHostile = !$a['green']
        && count($a['cited_production_only']) === 1
        && $a['cited_production_only'][0]['file'] === $orphan;
    $benignPassed = $b['green'] && $b['warnings'];
    $blindRed = !$c['green'] && $c['tests']['production_citation'] === 'NOT_EVALUATED';

    $file = writeReport($ROOT, ['self_test' => [
        'hostile_detected' => $caughtHostile,
        'benign_stayed_green' => $benignPassed,
        'no_verdicts_reads_red' => $blindRed,
    ]] + $a, 'self-test');

    printRecord($a);
    echo "deploy_skew_report --self-test: hostile(cited production-only file) detected=" . ($caughtHostile ? 'yes' : 'NO')
        . "; benign(count delta only) stayed green=" . ($benignPassed ? 'yes' : 'NO')
        . "; snapshot-without-verdicts reads red=" . ($blindRed ? 'yes' : 'NO') . "\n";

    if ($caughtHostile && $benignPassed && $blindRed) {
        echo "deploy_skew_report --self-test: PASS (all three induced cases classified correctly)\n";
        echo "deploy_skew_report: RED $file\n";
        exit(1);   // intentional: proves detection, never mistakable for a green gate run
    }
    echo "deploy_skew_report --self-test: FAIL — the checker is broken, not the tree.\n";
    echo "deploy_skew_report: RED $file\n";
    exit(2);
}

// -----------------------------------------------------------------------
// Resolve the snapshot.
// -----------------------------------------------------------------------
if ($snapshotPath === null) {
    $snapshotPath = getenv('DEPLOY_SKEW_SNAPSHOT') ?: null;
}
if ($snapshotPath === null) {
    $candidates = [];
    foreach (SNAPSHOT_GLOBS as $g) {
        foreach (glob($ROOT . $g) ?: [] as $hit) {
            $candidates[$hit] = filemtime($hit) ?: 0;
        }
    }
    if ($candidates) {
        arsort($candidates);
        $snapshotPath = (string)array_key_first($candidates);
    }
}

if ($snapshotPath === null || !is_file($snapshotPath)) {
    fwrite(STDERR, "deploy_skew_report: NOT RUNNABLE — no production inventory snapshot.\n");
    fwrite(STDERR, "  Looked at: --production <path>, \$DEPLOY_SKEW_SNAPSHOT, then "
        . implode(' and ', SNAPSHOT_GLOBS) . " under " . $ROOT . "\n");
    fwrite(STDERR, "  The deployed tree is the corpus the dead-code gate actually decides deletions on, and this\n"
        . "  host has no shell, so the only way to see it is the role-gated API action. Capture one with:\n"
        . "    curl -s -X POST <api endpoint> -H \"Authorization: Bearer \$TOKEN\" \\\n"
        . "         -d \"action=server-debug-deadcode\" -o reports/production-inventory.json\n"
        . "  then re-run. Reporting RED rather than skipping: an unmeasured corpus is not a matching corpus,\n"
        . "  and this harness has already been bitten four times by checks that passed because they stopped\n"
        . "  looking (F-10, F-11, F-18, F-21).\n");
    echo "deploy_skew_report: RED (not runnable: no production inventory snapshot)\n";
    exit(2);
}

$snapshot = parseSnapshot($snapshotPath);
if (!$snapshot['ok']) {
    fwrite(STDERR, "deploy_skew_report: NOT RUNNABLE — " . $snapshot['reason'] . "\n");
    echo "deploy_skew_report: RED (not runnable: unusable snapshot $snapshotPath)\n";
    exit(2);
}

$rec = compare($snapshot, $localFiles, $localSymbolResults, $localRoots, $localExcluded, $localSelected);
$rec['snapshot_path'] = normalisePath(str_replace($ROOT, '', $snapshotPath));
$file = writeReport($ROOT, $rec, 'compare');

printRecord($rec);
$status = $rec['green'] ? 'GREEN' : 'RED';
echo "deploy_skew_report: $status $file\n";
exit($rec['green'] ? 0 : 1);
