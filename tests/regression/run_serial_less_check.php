<?php
/**
 * Runner for serial_less_unit_identity_test.php.
 *
 * Executes the SAME assertions twice:
 *   1. against an UNPATCHED copy of ServerBuilder (the ID branch mechanically
 *      removed) -- this MUST fail, or the test proves nothing;
 *   2. against the real, patched ServerBuilder -- this MUST pass.
 *
 * That two-sided result is the whole point. A regression test that only ever
 * passes cannot distinguish a fix from a no-op -- the lesson recorded in F-1's
 * writeup (tasks/p2-verification-findings-20260721.md).
 *
 * USAGE (no credential on the command line):
 *   1. Put the scratch DB password in a file, e.g.
 *        C:\xampp\tmp\ims_scratch_pw.txt
 *   2. Run:
 *        set GOLDEN_DB_PASS_FILE=C:\xampp\tmp\ims_scratch_pw.txt
 *        "C:\xampp\php\php.exe" tests\regression\run_serial_less_check.php
 *
 * The unpatched copy is written next to ServerBuilder.php (so its __DIR__
 * relative requires still resolve) and is deleted again on the way out, including
 * on failure.
 */

$serverDir  = __DIR__ . '/../../core/models/server';
$realPath   = $serverDir . '/ServerBuilder.php';
$probePath  = $serverDir . '/_ServerBuilder_unpatched_probe.php';
$testPath   = __DIR__ . '/serial_less_unit_identity_test.php';
$php        = PHP_BINARY;

if (!is_file($realPath)) {
    fwrite(STDERR, "Cannot find ServerBuilder.php at $realPath\n");
    exit(2);
}

// Normalise line endings for matching. ServerBuilder.php is CRLF while files
// written by tooling here tend to be LF, so an exact multi-line string compare
// silently misses. PHP does not care which the probe copy uses.
$src = str_replace("\r\n", "\n", file_get_contents($realPath));

// --- Mechanically revert the fix in the copy ------------------------------
// Neutralise the ID branch condition so the method always takes the pre-fix
// UUID/serial path. Anchored with a whitespace-tolerant pattern; if it ever
// stops matching, the runner fails loudly rather than silently testing patched
// code against itself and reporting a meaningless PASS.
$pattern = '/if\s*\(\s*\$inventoryId\s*!==\s*null\s*\)\s*\{/';

if (!preg_match($pattern, $src)) {
    fwrite(STDERR, "ABORT: could not locate the patched WHERE block in ServerBuilder.php.\n"
                 . "The runner would otherwise compare patched code against itself and\n"
                 . "report a meaningless PASS. Update the anchor in this runner.\n");
    exit(2);
}

// Pre-fix behaviour: ignore $inventoryId entirely, always take the UUID branch.
$unpatched = preg_replace($pattern, 'if (false) {', $src, 1, $count);
if ($count !== 1) {
    fwrite(STDERR, "ABORT: expected exactly 1 replacement, made $count.\n");
    exit(2);
}
file_put_contents($probePath, $unpatched);

register_shutdown_function(function () use ($probePath) {
    if (is_file($probePath)) {
        @unlink($probePath);
    }
});

// --- Run both -------------------------------------------------------------
function runTest($php, $testPath, $sbPath, $label) {
    $env = $_ENV + $_SERVER;
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($testPath);

    $envVars = getenv();
    $envVars['SB_PATH'] = $sbPath;

    $proc = proc_open($cmd, $descriptor, $pipes, dirname($testPath), $envVars);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Could not start PHP for $label\n");
        exit(2);
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    echo str_repeat('=', 66) . "\n";
    echo "  $label\n";
    echo str_repeat('=', 66) . "\n";
    echo $out;
    if (trim($err) !== '') {
        echo "--- stderr ---\n$err";
    }
    echo "\n";
    return $code;
}

$unpatchedCode = runTest($php, $testPath, $probePath, 'RUN 1 of 2 — UNPATCHED code (this MUST fail)');
$patchedCode   = runTest($php, $testPath, $realPath, 'RUN 2 of 2 — PATCHED code (this MUST pass)');

@unlink($probePath);

// --- Verdict --------------------------------------------------------------
echo str_repeat('#', 66) . "\n";
if ($unpatchedCode === 2 || $patchedCode === 2) {
    echo "INCONCLUSIVE — the test could not run (DB/credential problem above).\n";
    exit(2);
}
if ($unpatchedCode === 0) {
    echo "INVALID TEST — it passed against UNPATCHED code, so it does not actually\n"
       . "exercise the bug. Do not trust the fix on this evidence.\n";
    exit(1);
}
if ($patchedCode !== 0) {
    echo "FIX INCOMPLETE — the test still fails against the patched code.\n";
    exit(1);
}
echo "VERDICT: PASS\n";
echo "  unpatched -> failed (bug reproduced)\n";
echo "  patched   -> passed (bug fixed)\n";
exit(0);
