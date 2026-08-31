<?php
/**
 * caddy_finalize_parity_test.php — F-19: the add gate and the finalize gate must
 * not impose mutually unsatisfiable caddy requirements on the same drive.
 *
 * The bug: ServerBuilder::validateStorageConnections() (the finalize gate) demanded
 * a caddy for EVERY drive whose primary path was chassis_bay, sized that caddy
 * against the DRIVE's form factor, and BLOCKED when it could not find one. The
 * add gate — StorageConnectionValidator::checkBayAvailability/checkCaddyRequirement,
 * which is the authority that actually admits the component — requires a caddy only
 * for the adapter case (a 2.5" drive seated in a 3.5" bay), sizes it to the BAY
 * (the caddy is what slots into the bay), and raises it as a WARNING.
 *
 * Consequence: a routine 2.5"-in-3.5" build could never be finalized. A 3.5" caddy
 * cleared the add gate and failed finalize; a 2.5" caddy did the reverse. The
 * dead-end was reachable through the normal UI.
 *
 * These are source-level assertions on purpose. The behavioural suites for this
 * path (storage_bay_placement_test, storage_rules_test) need `ims-data/` and a
 * provisioned scratch DB; neither exists in a bare checkout, so they exit without
 * executing a check and are NOT evidence. A static check costs nothing and holds
 * in any environment.
 *
 * ============================================================================
 * DISABLED 2026-08-31 — its subject no longer exists. P2 cleanup deleted
 * ServerBuilder::validateStorageConnections() as unreachable legacy code:
 * nothing called it (the live finalize/validate path runs through
 * ValidationEngine / StorageCaddyPairingRule instead, which P9 had already
 * moved production onto). Every assertion below isolates that method's source
 * text and cannot resolve. Kept verbatim as the written record of the F-19
 * bug this test suite pinned; do not re-enable against a method that is gone.
 * ============================================================================
 *
 * Exit 0 = every invariant holds.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

fwrite(STDERR,
    "caddy_finalize_parity_test.php: DISABLED -- its subject no longer exists.\n" .
    "  * ServerBuilder::validateStorageConnections() was deleted 2026-08-31 as\n" .
    "    unreachable legacy code (P2 cleanup); nothing called it at runtime.\n" .
    "  * The F-19 parity it pinned is now the sole job of ValidationEngine's\n" .
    "    StorageCaddyPairingRule. See this file's header.\n");
exit(2);

$ROOT = dirname(__DIR__, 2);
$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    echo($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$ok) { $failures++; }
}

$builderPath = $ROOT . '/core/models/server/ServerBuilder.php';
$validatorPath = $ROOT . '/core/models/compatibility/StorageConnectionValidator.php';

if (!is_file($builderPath) || !is_file($validatorPath)) {
    fwrite(STDERR, "FATAL: expected source files not found\n");
    exit(1);
}

$builder = file_get_contents($builderPath);
$validator = file_get_contents($validatorPath);

// Isolate validateStorageConnections() — the finalize-side caddy gate.
$start = strpos($builder, 'private function validateStorageConnections(');
check('validateStorageConnections() exists in ServerBuilder', $start !== false);
if ($start === false) { exit(1); }

// Bound the slice at the next method declaration so assertions cannot accidentally
// match unrelated code further down this very large class.
$rest = substr($builder, $start + 10);
$next = preg_match('/\n    (?:private|public|protected) function /', $rest, $m, PREG_OFFSET_CAPTURE)
    ? $start + 10 + $m[0][1]
    : strlen($builder);
$fn = substr($builder, $start, $next - $start);

echo "-- F-19.1: finalize must not BLOCK on a missing caddy --\n";

// The add gate admits the drive with a warning; the finalize gate must agree.
check(
    'missing_caddy is raised into $caddyWarnings, not $caddyErrors',
    preg_match('/\$caddyWarnings\[\]\s*=\s*\[\s*\'type\'\s*=>\s*\'missing_caddy\'/', $fn) === 1
);
check(
    'no $caddyErrors accumulator survives in validateStorageConnections()',
    strpos($fn, '$caddyErrors') === false
);
// Scoped to the caddy section of the method -- from the "Validate caddy
// availability" header to the end of the method -- and asserted with NO distance
// budget. A budget on a NEGATIVE check is the worst case of all: it passes by
// STOPPING to look. This form forbids the write anywhere in the caddy section,
// including before missing_caddy is raised, which the ordered match could not
// see. (The one legitimate $result['valid'] = false in this method belongs to
// the storage-connection error path ABOVE this section, and stays outside it.)
$caddySectionAt = strpos($fn, '// Validate caddy availability');
$caddySection   = $caddySectionAt === false ? '' : substr($fn, $caddySectionAt);
check(
    'the caddy branch never sets $result[\'valid\'] = false',
    $caddySection !== ''
    && preg_match('/\$result\[.valid.\]\s*=\s*false/', $caddySection) !== 1
);

echo "\n-- F-19.2: the caddy is sized to the BAY, not to the drive --\n";

check(
    'caddiesNeeded entries carry an explicit required_caddy_size',
    preg_match('/\'required_caddy_size\'\s*=>/', $fn) === 1
);
check(
    'matching consumes required_caddy_size rather than re-deriving from the drive form factor',
    preg_match('/\$requiredCaddySize\s*=\s*\$need\[\'required_caddy_size\'\]/', $fn) === 1
);
// The old code inferred the caddy size from the drive's own form factor string.
// That inference is precisely the half of the bug that made 2.5"-in-3.5" unsatisfiable.
check(
    'the drive form factor is no longer used to derive the required caddy size',
    preg_match('/\$requiredCaddySize\s*=\s*\'2\.5\'/', $fn) !== 1
);

echo "\n-- F-19.3: a caddy is required only for the adapter case --\n";

// A drive sitting in a natively-matching bay needs no caddy at either gate.
// Scoped to the detection loop -- `$needsAdapterCaddy = false;` up to the
// `if ($needsAdapterCaddy)` that consumes it -- so the 400-byte window's other
// possible partner, the explanatory comment 500 bytes earlier that also says
// "caddy_recommended", is out of scope rather than merely out of reach.
$detectStart = strpos($fn, '$needsAdapterCaddy = false;');
$detectEnd   = $detectStart !== false ? strpos($fn, 'if ($needsAdapterCaddy) {', $detectStart) : false;
$detectLoop  = ($detectStart !== false && $detectEnd !== false && $detectEnd > $detectStart)
    ? substr($fn, $detectStart, $detectEnd - $detectStart)
    : '';
check(
    'the requirement is driven by the add gate\'s own caddy_recommended signal',
    $detectLoop !== ''
    && strpos($detectLoop, "'caddy_recommended'") !== false
    && preg_match('/\$needsAdapterCaddy\s*=\s*true/', $detectLoop) === 1
);
// Restated positionally, without a budget. The old form was a negative bounded by
// 300 bytes: it passed because the two tokens are 1499 bytes apart TODAY, and a
// chassis_bay-driven increment introduced anywhere closer than 300 bytes was all
// it could ever have caught. What actually has to hold is that the ONE increment
// in this method is the one guarded by $needsAdapterCaddy -- so: exactly one
// increment, sandwiched inside the adapter branch, with no other condition
// interposed between the guard and it.
$gateAt      = strpos($fn, 'if ($needsAdapterCaddy) {');
$incrementAt = strpos($fn, '$caddyRequired++');
$neededAt    = strpos($fn, '$caddiesNeeded[] = [');
$guardToInc  = ($gateAt !== false && $incrementAt !== false && $incrementAt > $gateAt)
    ? substr($fn, $gateAt, $incrementAt - $gateAt)
    : '';
check(
    'a chassis_bay primary path alone no longer manufactures a caddy requirement',
    substr_count($fn, '$caddyRequired++') === 1
    && $guardToInc !== ''
    && strpos($guardToInc, 'if (', 4) === false
    && $neededAt !== false && $incrementAt < $neededAt
);
check(
    'the retired chassis_backplane caddy reason is gone',
    strpos($fn, "'connection_type' => 'chassis_backplane'") === false
);

echo "\n-- F-19.4: one caddy per adapted drive (pair check) --\n";

// The add gate stops at the first caddy it finds and lets it satisfy every drive.
// Finalize consumes one per drive; non-blocking, so tightening cannot strand a build.
check(
    'a matched caddy is consumed so it cannot satisfy a second drive',
    preg_match('/unset\(\$availableCaddies\[\$idx\]\)/', $fn) === 1
);

echo "\n-- F-19.5: the add-side authority still only warns --\n";

// Isolate the two methods under test the same way $fn is isolated above, so none
// of these three assertions depends on a byte distance. The boundary regex is
// tolerant of the modifier and of indentation drift (this file mixes 4- and
// 5-space indents), and every slice is fail-closed.
$sliceMethod = function (string $src, string $signature): string {
    $s = strpos($src, $signature);
    if ($s === false) { return ''; }
    $rest = substr($src, $s + strlen($signature));
    return preg_match('/\n\s*(?:public|private|protected)[^\n]*function\s/', $rest, $m, PREG_OFFSET_CAPTURE)
        ? substr($src, $s, strlen($signature) + $m[0][1])
        : substr($src, $s);
};
$checkCaddyFn = $sliceMethod($validator, 'private function checkCaddyRequirement(');
$addGateFn    = $sliceMethod($validator, 'public function validate($configUuid, $storageUuid, $existingComponents)');

check(
    'checkCaddyRequirement returns a warning, never an error',
    // The "never an error" half was never asserted before -- only that a
    // 'warning' key appeared within 900 bytes of the signature.
    $checkCaddyFn !== ''
    && preg_match('/\'warning\'\s*=>/', $checkCaddyFn) === 1
    && strpos($checkCaddyFn, "'error'") === false
);
check(
    'checkCaddyRequirement raises caddy_recommended (the signal finalize keys off)',
    $checkCaddyFn !== '' && strpos($checkCaddyFn, "'caddy_recommended'") !== false
);
check(
    'the add path files that warning into $warnings, not $errors',
    // Scoped to validate(), the caller: the call must precede the $warnings
    // append, and nothing in that method may file this check into $errors.
    $addGateFn !== ''
    && ($callAt = strpos($addGateFn, '$caddyCheck = $this->checkCaddyRequirement(')) !== false
    && ($fileAt = strpos($addGateFn, "\$warnings[] = \$caddyCheck['warning'];")) !== false
    && $callAt < $fileAt
    && strpos($addGateFn, '$errors[] = $caddyCheck') === false
);

echo "\n";
if ($failures > 0) {
    echo "FAILED — $failures check(s) failed.\n";
    exit(1);
}
echo "ALL CHECKS PASS\n";
exit(0);
