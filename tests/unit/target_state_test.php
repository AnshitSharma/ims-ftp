<?php
/**
 * target_state_test.php — U-V.2 unit test for TargetState/TargetStateBuilder.
 *
 * Requires GOLDEN_DB_HOST/GOLDEN_DB_NAME/GOLDEN_DB_USER/GOLDEN_DB_PASS env
 * vars pointed at a scratch DB (see tests/backfill/extractor_test.php for
 * the convention). All DB-touching assertions run inside a transaction that
 * is rolled back at the end, regardless of pass/fail.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/validation/TargetState.php';
require_once $ROOT . '/core/models/validation/TargetStateBuilder.php';
require_once $ROOT . '/core/models/config/ConfigComponentRepository.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

$pdo = new PDO(
    'mysql:host=' . (getenv('GOLDEN_DB_HOST') ?: '127.0.0.1') . ';dbname=' . (getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden'),
    getenv('GOLDEN_DB_USER') ?: 'root',
    getenv('GOLDEN_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// -----------------------------------------------------------------------
echo "-- fromCurrent(): JSON fallback path (config_components empty, real fixture) --\n";
$configUuid = '06ea5abb-ddb0-4945-ba88-7eba61ba3905';
$row = $pdo->prepare('SELECT COUNT(*) c FROM server_configurations WHERE config_uuid = ?');
$row->execute([$configUuid]);
if ((int)$row->fetch()['c'] === 0) {
    echo "  SKIP fixture config $configUuid not present in this scratch DB -- fromCurrent tests skipped\n";
} else {
    $state = TargetStateBuilder::fromCurrent($pdo, $configUuid);
    check('non-empty component set from JSON fallback', count($state->components()) > 0);
    foreach ($state->components() as $c) {
        check("component {$c['component_type']}/{$c['id']} tagged source=json", $c['source'] === 'json');
    }
    $nics = $state->byType('nic');
    check('byType(nic) returns at least one nic', count($nics) >= 1);

    // -------------------------------------------------------------------
    echo "-- fromCurrent(): rows path == JSON fallback path (tuple-equal on type+spec_uuid multiset) --\n";
    $pdo->beginTransaction();
    try {
        $repo = new ConfigComponentRepository($pdo);
        foreach ($state->components() as $c) {
            $repo->insert($configUuid, [
                'component_type' => $c['component_type'],
                'inventory_table' => $c['component_type'] . 'inventory',
                'inventory_id' => abs($c['id']), // synthetic but unique/stable within this rolled-back tx
                'spec_uuid' => $c['spec_uuid'],
                'serial_number' => $c['serial_number'],
                'parent_id' => null, // parent linkage not required for this multiset comparison
                'slot_ref' => $c['slot_ref'],
            ], 0);
        }

        $rowsState = TargetStateBuilder::fromCurrent($pdo, $configUuid);
        check('rows path returns same component count as JSON fallback', count($rowsState->components()) === count($state->components()));
        foreach ($rowsState->components() as $c) {
            check("component {$c['component_type']}/{$c['id']} tagged source=rows", $c['source'] === 'rows');
        }

        $tuple = function ($rows) {
            $t = array_map(function ($c) { return $c['component_type'] . ':' . $c['spec_uuid']; }, $rows);
            sort($t);
            return $t;
        };
        check('rows path and JSON fallback path are tuple-equal (type:spec_uuid multiset)', $tuple($rowsState->components()) === $tuple($state->components()));
    } finally {
        $pdo->rollBack();
    }
}

// -----------------------------------------------------------------------
echo "-- withAdd / withRemove / withReplace: pure array math, no DB --\n";
$base = new TargetState([
    ['id' => 1, 'component_type' => 'chassis', 'spec_uuid' => 'chassis-a', 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows'],
    ['id' => 2, 'component_type' => 'nic', 'spec_uuid' => 'nic-a', 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => null, 'slot_ref' => null, 'source' => 'rows'],
    ['id' => 3, 'component_type' => 'sfp', 'spec_uuid' => 'sfp-a', 'inventory_table' => null, 'inventory_id' => null, 'serial_number' => null, 'parent_id' => 2, 'slot_ref' => 'port_1', 'source' => 'rows'],
]);

$added = TargetStateBuilder::withAdd($base, ['component_type' => 'cpu', 'spec_uuid' => 'cpu-a']);
check('withAdd: base unchanged (immutability)', count($base->components()) === 3);
check('withAdd: new state has one more component', count($added->components()) === 4);
check('withAdd: appended row has assigned id', $added->components()[3]['id'] !== null);

$removed = TargetStateBuilder::withRemove($base, 2, false);
check('withRemove (no cascade): base unchanged', count($base->components()) === 3);
check('withRemove (no cascade): nic gone, sfp child still present (not cascaded)', $removed->find(2) === null && $removed->find(3) !== null);

$cascaded = TargetStateBuilder::withRemove($base, 2, true);
check('withRemove (cascade): parent_id subtree pulled -- both nic and its sfp child gone', $cascaded->find(2) === null && $cascaded->find(3) === null);
check('withRemove (cascade): unrelated sibling (chassis) untouched', $cascaded->find(1) !== null);

$replaced = TargetStateBuilder::withReplace($base, 2, ['component_type' => 'nic', 'spec_uuid' => 'nic-b']);
check('withReplace: old id absent', $replaced->find(2) === null);
check('withReplace: new row present with new spec_uuid', count(array_filter($replaced->components(), function ($c) { return $c['spec_uuid'] === 'nic-b'; })) === 1);
check('withReplace: total count is old count (old removed, new added -- net zero for a 1:1 swap)', count($replaced->components()) === count($base->components()));
check('withReplace: base still has original nic (immutability)', $base->find(2)['spec_uuid'] === 'nic-a');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
