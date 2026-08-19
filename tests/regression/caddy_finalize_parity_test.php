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
 * Exit 0 = every invariant holds.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

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
check(
    'the caddy branch never sets $result[\'valid\'] = false',
    preg_match('/missing_caddy[\s\S]{0,800}?\$result\[.valid.\]\s*=\s*false/', $fn) !== 1
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
check(
    'the requirement is driven by the add gate\'s own caddy_recommended signal',
    preg_match('/caddy_recommended[\s\S]{0,400}?\$needsAdapterCaddy\s*=\s*true/', $fn) === 1
);
check(
    'a chassis_bay primary path alone no longer manufactures a caddy requirement',
    preg_match('/chassis_bay\'[\s\S]{0,300}?\$caddyRequired\+\+/', $fn) !== 1
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

check(
    'checkCaddyRequirement returns a warning, never an error',
    preg_match('/function checkCaddyRequirement[\s\S]{0,900}?\'warning\'\s*=>/', $validator) === 1
);
check(
    'checkCaddyRequirement raises caddy_recommended (the signal finalize keys off)',
    preg_match('/function checkCaddyRequirement[\s\S]{0,900}?\'caddy_recommended\'/', $validator) === 1
);
check(
    'the add path files that warning into $warnings, not $errors',
    preg_match('/\$caddyCheck\s*=\s*\$this->checkCaddyRequirement\([\s\S]{0,200}?\$warnings\[\]\s*=\s*\$caddyCheck\[\'warning\'\]/', $validator) === 1
);

echo "\n";
if ($failures > 0) {
    echo "FAILED — $failures check(s) failed.\n";
    exit(1);
}
echo "ALL CHECKS PASS\n";
exit(0);
