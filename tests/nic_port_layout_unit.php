<?php
/**
 * NICPortTracker::portLayout() / sfpPortIndexes() -- per-port cages for cards
 * with mixed ports (rNDC: 2x SFP+ + 2x RJ45), and the rules that consume them.
 * No database needed. Run: php tests/nic_port_layout_unit.php
 */

require_once __DIR__ . '/../core/models/compatibility/NICPortTracker.php';

$failed = 0;
function check($label, $actual, $expected) {
    global $failed;
    if ($actual === $expected) {
        echo "ok   $label\n";
        return;
    }
    $failed++;
    echo "FAIL $label\n  expected " . json_encode($expected) . "\n  actual   " . json_encode($actual) . "\n";
}

$rndc = [
    'ports' => 4,
    'port_type' => 'SFP+ / RJ45',
    'port_groups' => [['type' => 'SFP+', 'count' => 2], ['type' => 'RJ45', 'count' => 2]],
];

// port_groups: ports numbered through the groups in order.
check('rNDC layout', NICPortTracker::portLayout($rndc), [1 => 'SFP+', 2 => 'SFP+', 3 => 'RJ45', 4 => 'RJ45']);
check('rNDC sfp ports', NICPortTracker::sfpPortIndexes($rndc), [1, 2]);

// Without port_groups the combo string is not a cage: today's behaviour, unchanged.
$noGroups = ['ports' => 4, 'port_type' => 'SFP+ / RJ45'];
check('combo without groups has no SFP port', NICPortTracker::sfpPortIndexes($noGroups), []);

// Plain specs: every port takes the card's one type.
check('all-SFP+ card', NICPortTracker::sfpPortIndexes(['ports' => 2, 'port_type' => 'SFP+']), [1, 2]);
check('all-RJ45 card', NICPortTracker::sfpPortIndexes(['ports' => 4, 'port_type' => 'RJ45']), []);
check('onboard connector fallback', NICPortTracker::sfpPortIndexes(['ports' => 2, 'connector' => 'SFP28']), [1, 2]);
check('lower-case type', NICPortTracker::sfpPortIndexes(['ports' => 2, 'port_type' => 'sfp+']), [1, 2]);

// Bad or missing data fails closed.
$badSum = ['ports' => 4, 'port_groups' => [['type' => 'SFP+', 'count' => 2]]];
check('groups not adding to ports', NICPortTracker::sfpPortIndexes($badSum), []);
check('no ports field', NICPortTracker::portLayout(['port_type' => 'SFP+']), []);
check('zero ports', NICPortTracker::portLayout(['ports' => 0, 'port_type' => 'SFP+']), []);
check('KR group is not an SFP port', NICPortTracker::sfpPortIndexes(
    ['ports' => 2, 'port_groups' => [['type' => 'KR', 'count' => 2]]]), []);

// The engine's own verdicts per port of the rNDC.
$layout = NICPortTracker::portLayout($rndc);
check('SFP+ module in port 1', NICPortTracker::isCompatible($layout[1], 'SFP+'), true);
check('SFP+ module in port 3 (RJ45)', NICPortTracker::isCompatible($layout[3], 'SFP+'), false);
check('QSFP+ module in an SFP+ port', NICPortTracker::isCompatible($layout[1], 'QSFP+'), false);

echo $failed === 0 ? "\nall passed\n" : "\n$failed failed\n";
exit($failed === 0 ? 0 : 1);
