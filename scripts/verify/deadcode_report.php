<?php
/**
 * deadcode_report.php — 11-verification/README.md §deadcode. Created U-D.1.
 *
 * For every symbol scheduled for deletion by P9 (10-cleanup), prove it has ZERO
 * blocking call sites before anyone deletes it. Contract, verbatim from
 * 11-verification: "For each symbol scheduled for deletion: grep -rn zero call
 * sites outside tests + the symbol's own file; PHP lint of full tree after
 * deletion; characterization suite green." This script owns the first two; the
 * characterization suite is the regression report's job, not this one's.
 *
 * A match is NOT blocking when it is:
 *   - under an excluded dir (tests/, migration/, docs/, reports/, database/, vendor/),
 *   - in a path listed in that symbol's allowed_callers (see the manifest's
 *     checkComponentPairCompatibility entry for why that exists),
 *   - a comment line, or a bare string occurrence with no call/reference syntax.
 *
 * GREEN for a symbol means SAFE TO DELETE. It is a precondition of the deletion,
 * never a record that the deletion happened — a symbol that no longer exists at
 * all is reported ALREADY_GONE and counts as green.
 *
 * SAME-FILE CALLERS ARE COUNTED, and this is a deliberate departure from the letter
 * of the 11-verification wording ("outside tests + the symbol's own file"). That
 * wording is safe for a small class and actively dangerous for this codebase:
 * ServerBuilder.php is ~6k lines, so a private method's real callers are almost always
 * in its OWN file, and excluding them reports a live method as deletable. That is not
 * hypothetical — validateComponentCompatibility() is called from addComponent() 5.2k
 * lines away in the same file, and addComponent() was still reachable at
 * COMMAND_LAYER=enforce through handleImportVirtual(), which dispatched to the legacy
 * builder with no flag check until that was fixed on 2026-08-22. Deleting on a
 * file-excluded GREEN would have dropped pairwise compatibility validation from the
 * virtual-import path. Same-file hits are therefore reported as internal_call_sites and
 * DO make a symbol RED, unless the manifest entry sets internal_callers_also_deleted:true
 * to declare that those callers are going in the same commit.
 *
 * KNOWN LIMITATION (deliberate, and why allowed_callers exists at all): matching is
 * by NAME, not by resolved receiver. `$this->extractPCIeSlotSize(...)` inside
 * ComponentCompatibility refers to ComponentCompatibility's own copy, not to
 * ServerBuilder's — but this scan cannot tell them apart, because doing so needs a
 * type resolver this codebase has no toolchain for. Where two classes define the same
 * method name, allow-list the other definers in the manifest WITH a note, so the
 * exemption is visible and reviewable instead of a silent miscount. Never allow-list a
 * path to make a symbol go green without checking why it matched.
 *
 * The scan rules themselves live in deadcode_scan.php, shared with the role-gated
 * server-debug-deadcode action — this host has no shell, so the API caller is the only
 * way the scan actually runs against production. Change the rules THERE, not here.
 *
 * CLI ONLY — defence in depth, not a fix for a known hole. Measured 2026-08-22: this
 * host already returns 403 for every .php under scripts/ (only api/api.php is
 * reachable), so this script was never publicly executable. The guard costs nothing and
 * removes the dependency on that server rule staying in place. What IS publicly
 * readable here is the .json beside it (deadcode_manifest.json, expected_diffs.json);
 * see scripts/.htaccess.
 *
 * Usage:
 *   php scripts/verify/deadcode_report.php                 # every symbol in the manifest
 *   php scripts/verify/deadcode_report.php --unit=U-D.1    # one unit's symbols
 *   php scripts/verify/deadcode_report.php --symbol=assignComponentSlot
 *   php scripts/verify/deadcode_report.php --no-lint       # skip the tree lint
 * Exit: 0 = green (every selected symbol clear), 1 = red, 2 = infrastructure failure.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "deadcode_report.php is a CLI tool. Use the server-debug-deadcode API action.\n";
    exit;
}

$root = dirname(__DIR__, 2);                 // ims-ftp/
$manifestPath = __DIR__ . '/deadcode_manifest.json';

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Cannot locate deadcode_manifest.json\n");
    exit(2);
}
$manifest = json_decode((string)file_get_contents($manifestPath), true);
if (!is_array($manifest) || empty($manifest['symbols'])) {
    fwrite(STDERR, "deadcode_manifest.json is missing or malformed\n");
    exit(2);
}

// ---- args -------------------------------------------------------------------
$onlyUnit = null;
$onlySymbol = null;
$runLint = true;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--unit=') === 0) {
        $onlyUnit = substr($arg, 7);
    } elseif (strpos($arg, '--symbol=') === 0) {
        $onlySymbol = substr($arg, 9);
    } elseif ($arg === '--no-lint') {
        $runLint = false;
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(2);
    }
}

// ---- scan (shared rules) ----------------------------------------------------
require_once __DIR__ . '/deadcode_scan.php';

$scan = deadcodeScan($root, $manifest, $onlyUnit, $onlySymbol);
if (!empty($scan['error'])) {
    fwrite(STDERR, $scan['error'] . "\n");
    exit(2);
}

$results = $scan['results'];
$selected = $scan['symbols_selected'];
$redCount = $scan['symbols_red'];
$files = deadcodeCollectPhpFiles($root, $scan['scan']['roots'], $scan['scan']['excluded_dirs']);

// ---- tree lint (contract: "PHP lint of full tree") --------------------------
$lint = ['ran' => false, 'files_checked' => 0, 'failures' => []];
if ($runLint) {
    $lint['ran'] = true;
    foreach ($files as $rel) {
        $out = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $rel) . ' 2>&1', $out, $code);
        $lint['files_checked']++;
        if ($code !== 0) {
            $lint['failures'][] = ['file' => $rel, 'output' => trim(implode("\n", $out))];
        }
    }
}
$lintGreen = !$lint['ran'] || empty($lint['failures']);

// ---- write the report -------------------------------------------------------
$green = ($redCount === 0) && $lintGreen;
$reportsDir = $root . '/reports';
if (!is_dir($reportsDir)) {
    mkdir($reportsDir, 0755, true);
}
$file = $reportsDir . '/deadcode-' . date('Ymd-His') . '.json';

file_put_contents($file, json_encode([
    'report' => 'deadcode_report',
    'generated_at' => date('c'),
    'manifest' => 'scripts/verify/deadcode_manifest.json',
    'filters' => ['unit' => $onlyUnit, 'symbol' => $onlySymbol],
    'scan' => [
        'roots' => $scan['scan']['roots'],
        'excluded_dirs' => $scan['scan']['excluded_dirs'],
        'php_files_scanned' => $scan['php_files_scanned'],
    ],
    'symbols_selected' => $selected,
    'symbols_red' => $redCount,
    'lint' => $lint,
    'results' => $results,
    'status' => $green ? 'GREEN' : 'RED',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

foreach ($results as $r) {
    if ($r['status'] === 'RED') {
        fwrite(STDERR, sprintf("  RED  %-38s %d blocking call site(s)\n", $r['symbol'], $r['blocking_count']));
        foreach (array_slice($r['blocking_call_sites'], 0, 5) as $h) {
            fwrite(STDERR, sprintf("         %s:%d  %s\n", $h['file'], $h['line'], $h['text']));
        }
    } elseif ($r['status'] === 'RED_INTERNAL') {
        fwrite(STDERR, sprintf(
            "  RED  %-38s no external callers, but %d SAME-FILE caller(s) still live\n",
            $r['symbol'],
            $r['internal_count']
        ));
        foreach (array_slice($r['internal_call_sites'], 0, 5) as $h) {
            fwrite(STDERR, sprintf("         %s:%d  %s\n", $h['file'], $h['line'], $h['text']));
        }
    }
}
foreach ($lint['failures'] as $f) {
    fwrite(STDERR, sprintf("  LINT %s: %s\n", $f['file'], $f['output']));
}

echo 'deadcode_report: ' . ($green ? 'GREEN' : 'RED') . " $file\n";
exit($green ? 0 : 1);
