<?php
/**
 * shadow_dry_run_error_test.php -- regression test for F-30 (2026-07-30).
 *
 * command_parity_report has distinguished two meanings of a thrown dryRun()
 * since 2026-07-29:
 *   - 'command_failed:<type>' -- a REFUSAL. component_unavailable /
 *     component_not_found / inventory_component_not_found / transition_denied /
 *     config_immutable / revision_mismatch / config_not_found are the answer the
 *     caller actually receives at COMMAND_LAYER=enforce, so the row IS
 *     comparable and counts as command_blocked=true.
 *   - 'exception' (or the field absent) -- a CRASH. Uncomparable, gate-RED.
 *
 * Only the finalize hook ever wrote that field. handleAddComponent and
 * handleRemoveComponent recorded a bare dry_run_failed=true, so every
 * legitimate refusal landed in the crash bucket and hard-RED the command gate
 * -- even when legacy refused too, i.e. perfect agreement. Observed live
 * 2026-07-29: 7 rows, all adds of a unit another draft already held,
 * legacy_blocked=true / command_blocked=null / no dry_run_error.
 *
 * Same shape as F-10/F-27: the READER was upgraded and the WRITERS were not, so
 * the gate could not go green on real traffic however well the two sides agreed.
 *
 * Structural pins over the real source -- the hooks need a live PDO, a
 * persisted config and COMMAND_LAYER=shadow, so a behavioural test here could
 * only SKIP, which is the F-27 anti-pattern. Exit 0 = pass.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

/**
 * The `{ ... }` block introduced by $opener, delimited by BRACE MATCHING.
 *
 * Used instead of the byte windows this file used to carry. A window is wrong in
 * both directions: it under-reaches the moment a line is inserted (the guarantee
 * still holds, the test goes red) and it over-reaches into whatever follows the
 * block (an assertion about a catch body gets satisfied by code outside it).
 * A matched block is exactly the construct under test, whatever its size.
 *
 * Returns '' when the opener is absent or the braces do not balance, so every
 * caller fails CLOSED. (Braces inside string literals would fool the counter;
 * none of the blocks asserted on below contain any.)
 */
function blockSlice(string $src, string $opener): string {
    $at = strpos($src, $opener);
    if ($at === false) { return ''; }
    $open = strpos($src, '{', $at);
    if ($open === false) { return ''; }
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) { return substr($src, $at, $i - $at + 1); }
        }
    }
    return '';
}

/** The argument list of the first $callee call, delimited by its own parens. */
function callArgSlice(string $src, string $callee): string {
    $at = strpos($src, $callee);
    if ($at === false || substr($callee, -1) !== '(') { return ''; }
    $depth = 0;
    for ($i = $at + strlen($callee) - 1, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '(') {
            $depth++;
        } elseif ($src[$i] === ')') {
            $depth--;
            if ($depth === 0) { return substr($src, $at, $i - $at + 1); }
        }
    }
    return '';
}

echo "shadow_dry_run_error_test (F-30)\n";

$apiPath = $ROOT . '/api/handlers/server/server_api.php';
check('server_api.php is readable', is_file($apiPath));
$src = is_file($apiPath) ? file_get_contents($apiPath) : '';

// ------------------------------------------------------- shared classifier --
echo "\n[1] one classifier, and it demotes a wrapped crash\n";

check('shadowDryRunErrorLabel() exists',
    strpos($src, 'function shadowDryRunErrorLabel(') !== false);

// BaseCommand wraps a genuine Throwable as CommandFailed('command_exception').
// Labelling that 'command_failed:command_exception' would satisfy the report's
// strpos(..., 'command_failed') === 0 test and promote a CRASH into a verdict.
// The function's own braces, not a 400-byte prefix: the whole function is 284
// bytes today, so the window was silently reaching 116 bytes past its closing
// brace into handleAddComponent's docblock -- text moved OUT of the classifier
// but left sitting above the next function could still satisfy these checks.
// (Caught by the negative control: a fn-signature-to-next-fn slice had the same
// hole, a brace-matched one does not.)
$labelBody = blockSlice($src, 'function shadowDryRunErrorLabel(');
check("maps 'command_exception' to 'exception', not to a command_failed: label",
    preg_match("/command_exception'\s*\?\s*'exception'/", $labelBody) === 1);
check("labels every other type 'command_failed:<type>'",
    strpos($labelBody, "'command_failed:' . \$failure->errorType") !== false);

// 'command_exception' must really be the wrapped-crash type this guards against.
$baseSrc = @file_get_contents($ROOT . '/core/models/commands/BaseCommand.php');
// Asserted INSIDE the catch block (brace-matched) rather than within 300 bytes
// of it: the wrap must be what that catch does, not something that merely
// follows it closely enough today.
$baseCatch = is_string($baseSrc) ? blockSlice($baseSrc, 'catch (\Throwable') : '';
check("BaseCommand still wraps a Throwable as CommandFailed('command_exception')",
    $baseCatch !== ''
    && strpos($baseCatch, "throw new CommandFailed('command_exception'") !== false);

// ---------------------------------------------------------- the two hooks --
echo "\n[2] add and remove hooks record WHY, and survive a crash\n";

foreach (['handleAddComponent' => 'addComponent', 'handleRemoveComponent' => 'removeComponent'] as $fn => $_) {
    $start = strpos($src, "function $fn(");
    // Bound the slice to THIS function -- up to the next top-level `function` --
    // so a match in a sibling hook cannot satisfy these checks. A fixed byte
    // window silently under-reaches: handleAddComponent's shadow block sits some
    // 200 lines into the function.
    $next = $start === false ? false : strpos($src, "\nfunction ", $start + 1);
    $body = $start === false
        ? ''
        : ($next === false ? substr($src, $start) : substr($src, $start, $next - $start));
    check("$fn() found", $start !== false);
    check("  $fn() labels a CommandFailed via the shared classifier",
        strpos($body, '$shadowDryRunError = shadowDryRunErrorLabel($shadowFailure);') !== false);
    // Without this catch, a fault in SHADOW-only code escapes into a real
    // mutating request and 500s it -- shadow forking behaviour, INV-8.
    // Both of these are now bound to the construct they describe -- the catch
    // BLOCK and the record() CALL -- instead of 900/600-byte windows after a
    // token. Inserting a line inside either construct can no longer break them,
    // and code outside the construct can no longer satisfy them.
    $rawCatch = blockSlice($body, 'catch (\Throwable $shadowFailure)');
    check("  $fn() also catches a raw Throwable and calls it 'exception' (INV-8)",
        $rawCatch !== '' && strpos($rawCatch, "\$shadowDryRunError = 'exception';") !== false);
    $recordCall = callArgSlice($body, 'CommandShadowLog::record(');
    check("  $fn() passes dry_run_error through to CommandShadowLog",
        $recordCall !== '' && strpos($recordCall, "'dry_run_error' => \$shadowDryRunError,") !== false);
    // A verdict object alongside dryRunFailed=true would be contradictory.
    check("  $fn() sends no verdict when the dry run threw",
        strpos($body, '$shadowDryRunFailed ? null : $commandVerdict') !== false);
}

// -------------------------------------------------------- finalize parity --
echo "\n[3] the finalize hook uses the same classifier\n";

check('finalize hook no longer hand-builds the command_failed: label',
    strpos($src, "'command_failed:' . \$finalizeFailure->errorType") === false);
check('finalize hook calls shadowDryRunErrorLabel()',
    strpos($src, '$finalizeDryRunError = shadowDryRunErrorLabel($finalizeFailure);') !== false);
check("finalize hook still labels a true crash 'exception'",
    strpos($src, "\$finalizeDryRunError = 'exception';") !== false);

// ------------------------------------------------------- reader agreement --
echo "\n[4] the report still reads the contract these hooks write\n";

$repSrc = @file_get_contents($ROOT . '/scripts/verify/command_parity_report.php');
check('report treats a command_failed: prefix as a real verdict',
    is_string($repSrc) && strpos($repSrc, "strpos(\$dryRunError, 'command_failed') === 0") !== false);
check('report still counts a verdict-less row as a dry-run failure (gate-RED)',
    is_string($repSrc) && preg_match('/\$commandBlocked === null\)\s*\{\s*\$dryRunFailed\+\+;/', $repSrc) === 1);
// Scoped to commandParityGreen()'s own body (signature to the next top-level
// function): the green definition must contain the term, not merely sit within
// 300 bytes of somewhere that mentions it.
$greenStart = is_string($repSrc) ? strpos($repSrc, 'function commandParityGreen') : false;
$greenNext  = $greenStart !== false ? strpos($repSrc, "\nfunction ", $greenStart + 1) : false;
$greenFn    = $greenStart === false
    ? ''
    : ($greenNext === false ? substr($repSrc, $greenStart) : substr($repSrc, $greenStart, $greenNext - $greenStart));
check('dry_run_failed is still part of the green definition',
    $greenFn !== '' && preg_match("/\\\$analysis\['dry_run_failed'\]\s*===\s*0/", $greenFn) === 1);

// ---------------------------------------------------------------------------
echo "\n";
if ($fails === 0) {
    echo "ALL CHECKS PASS\n";
    exit(0);
}
echo "$fails CHECK(S) FAILED\n";
exit(1);
