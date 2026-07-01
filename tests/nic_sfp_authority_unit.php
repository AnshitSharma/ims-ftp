<?php
/**
 * nic_sfp_authority_unit.php — focused unit test for the Phase-3 single
 * SFP-matrix authority (NICPortTracker::getCompatiblePortTypesForSFP, the
 * reverse map derived from the one canonical forward matrix).
 *
 * The golden-master corpus contains no 1G-SFP configuration, so the enforce
 * path never flips a verdict there. This test drives the reverse-map authority
 * directly to prove three things:
 *   1. For every key the legacy literal map defined, the authority returns the
 *      IDENTICAL array (so enforce introduces no unintended drift).
 *   2. The 1G "SFP"/"SFP DAC" modules — which the legacy map omitted (returned
 *      []) — now resolve to their SFP+/SFP28 cages (the reverse-direction H5 fix).
 *   3. Forward/reverse round-trip consistency: port P accepts SFP S  <=>  S lists
 *      P as a compatible port. This is the proof that there is now exactly ONE
 *      source of truth, not two maps that can drift.
 *
 * Pure logic; no DB needed. Exit 0 = all pass; exit 1 = a failure (prints which).
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/core/models/compatibility/NICPortTracker.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

// The legacy literal reverse map (copied verbatim from the pre-consolidation
// SFPCompatibilityResolver) — the authority must reproduce each of these keys.
$legacy = [
    'SFP+'     => ['SFP+', 'SFP28'],
    'SFP+ DAC' => ['SFP+', 'SFP28'],
    'SFP28'    => ['SFP28'],
    'QSFP+'    => ['QSFP+', 'QSFP28', 'QSFP56', 'OSFP'],
    'QSFP28'   => ['QSFP28', 'QSFP56', 'OSFP'],
    'QSFP56'   => ['QSFP56', 'OSFP'],
    'OSFP'     => ['OSFP'],
];

// 1. Byte-identical at every key the legacy map defined.
foreach ($legacy as $sfp => $expected) {
    $got = NICPortTracker::getCompatiblePortTypesForSFP($sfp);
    check("authority reproduces legacy key '$sfp' (" . json_encode($expected) . ")", $got === $expected);
}

// 2. The H5 reverse-direction fix: 1G SFP / SFP DAC now find their cages.
$g1 = NICPortTracker::getCompatiblePortTypesForSFP('SFP');
check("1G 'SFP' resolves to ['SFP+','SFP28'] (legacy returned [])", $g1 === ['SFP+', 'SFP28']);
$g2 = NICPortTracker::getCompatiblePortTypesForSFP('SFP DAC');
check("1G 'SFP DAC' resolves to ['SFP+','SFP28'] (legacy returned [])", $g2 === ['SFP+', 'SFP28']);

// 3. Forward/reverse round-trip consistency over the full cage + module space.
$portTypes = ['SFP+', 'SFP28', 'QSFP+', 'QSFP28', 'QSFP56', 'OSFP'];
$sfpTypes  = ['SFP', 'SFP DAC', 'SFP+', 'SFP+ DAC', 'SFP28',
              'QSFP+', 'QSFP28', 'QSFP56', 'OSFP'];
$mismatch = 0;
foreach ($portTypes as $port) {
    $forwardAccepts = array_map('strtoupper', NICPortTracker::getCompatibleSfpTypes($port));
    foreach ($sfpTypes as $sfp) {
        $sfpU = strtoupper($sfp);
        $inForward = in_array($sfpU, $forwardAccepts, true);
        $inReverse = in_array($port, NICPortTracker::getCompatiblePortTypesForSFP($sfp), true);
        if ($inForward !== $inReverse) {
            echo "    round-trip mismatch: port=$port sfp=$sfp forward=" .
                var_export($inForward, true) . " reverse=" . var_export($inReverse, true) . "\n";
            $mismatch++;
        }
    }
}
check("forward/reverse round-trip consistent across all cage x module pairs", $mismatch === 0);

// 4. Unknown module → empty (data-gated, no fabricated compatibility).
check("unknown SFP type -> [] (no fabricated compatibility)",
    NICPortTracker::getCompatiblePortTypesForSFP('NOTASFP') === []);

echo "\n";
if ($fails === 0) {
    echo "OK: NICPortTracker::getCompatiblePortTypesForSFP — single SFP-matrix authority verified.\n";
    exit(0);
}
echo "FAILED: $fails assertion(s).\n";
exit(1);
