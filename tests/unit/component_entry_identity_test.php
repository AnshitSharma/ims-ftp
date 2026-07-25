<?php
/**
 * Unit test — physical-unit identity in component JSON entries (audit A-L3/A-L4/A-L5/A-L8).
 *
 * DB-free: exercises the pure helpers on ServerBuilder via reflection. The class is
 * constructed with a stub PDO because none of the helpers under test touch the
 * connection.
 *
 * What this pins down, per the audit findings:
 *   A-L4  removal must drop exactly ONE entry, never every entry of the model
 *   A-L5  inventory_id distinguishes units whose SerialNumber is NULL
 *   A-L3  reserved_units records every unit a quantity>1 entry claims
 *   A-L8  capacity budgets sum each entry's quantity, they do not count entries
 *
 * Run: php tests/unit/component_entry_identity_test.php
 */

require_once __DIR__ . '/../../core/models/server/ServerBuilder.php';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  PASS  $label\n";
    } else {
        $failures++;
        echo "  FAIL  $label\n";
    }
}

/** Minimal stand-in: the helpers under test never call the connection. */
class StubPdoForIdentityTest extends PDO {
    public function __construct() {} // deliberately does not call parent::__construct
}

$builder = new ServerBuilder(new StubPdoForIdentityTest());
$ref = new ReflectionClass(ServerBuilder::class);

$call = function (string $method, array $args) use ($ref, $builder) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($builder, $args);
};

echo "\n-- A-L4: removal drops exactly ONE entry --\n";

// Three serial-less units of one model. The pre-fix filter returned
// `$e['uuid'] !== $uuid`, wiping all three while only one inventory row was released.
$serialLess = [
    ['uuid' => 'RAM-X', 'quantity' => 1, 'inventory_id' => 10],
    ['uuid' => 'RAM-X', 'quantity' => 1, 'inventory_id' => 11],
    ['uuid' => 'RAM-X', 'quantity' => 1, 'inventory_id' => 12],
];
$after = $call('removeOneComponentEntry', [$serialLess, 'RAM-X', null, 11]);
check('3 serial-less units, remove id=11 -> 2 remain', count($after) === 2);
check('  the removed entry is exactly id=11',
    array_column($after, 'inventory_id') === [10, 12]);

// Mixed set: one entry with a serial, one without. The pre-fix code hit the
// uuid-only fallback for the serial-less entry and removed it too.
$mixed = [
    ['uuid' => 'RAM-X', 'quantity' => 1, 'serial_number' => 'S1'],
    ['uuid' => 'RAM-X', 'quantity' => 1],
];
$after = $call('removeOneComponentEntry', [$mixed, 'RAM-X', 'S1', null]);
check('mixed serial/serial-less, remove S1 -> 1 remains', count($after) === 1);
check('  the surviving entry is the serial-less one',
    !isset($after[0]['serial_number']));

// No identity on either side: still only one entry may go.
$bare = [
    ['uuid' => 'CADDY-Y', 'quantity' => 1],
    ['uuid' => 'CADDY-Y', 'quantity' => 1],
];
$after = $call('removeOneComponentEntry', [$bare, 'CADDY-Y', null, null]);
check('two indistinguishable entries -> exactly one removed', count($after) === 1);

// A different model must be untouched.
$twoModels = [
    ['uuid' => 'RAM-X', 'quantity' => 1, 'inventory_id' => 10],
    ['uuid' => 'RAM-Z', 'quantity' => 1, 'inventory_id' => 20],
];
$after = $call('removeOneComponentEntry', [$twoModels, 'RAM-X', null, 10]);
check('other models untouched', count($after) === 1 && $after[0]['uuid'] === 'RAM-Z');

// Removing something absent is a no-op, not a wipe.
$after = $call('removeOneComponentEntry', [$twoModels, 'RAM-ABSENT', null, 99]);
check('absent target -> nothing removed', count($after) === 2);

echo "\n-- A-L5: inventory_id separates units with NULL serials --\n";

$entryA = ['uuid' => 'SSD-K', 'inventory_id' => 41];
$entryB = ['uuid' => 'SSD-K', 'inventory_id' => 42];
check('same model, same (null) serial, different id -> NOT the same unit',
    $call('componentEntryMatches', [$entryA, 'SSD-K', null, 42]) === false);
check('matching id -> same unit',
    $call('componentEntryMatches', [$entryB, 'SSD-K', null, 42]) === true);
check('different model never matches',
    $call('componentEntryMatches', [$entryA, 'SSD-OTHER', null, 41]) === false);

// Legacy entries carry a serial but no inventory_id.
$legacy = ['uuid' => 'SSD-K', 'serial_number' => 'SN-7'];
check('legacy entry matches on serial',
    $call('componentEntryMatches', [$legacy, 'SSD-K', 'SN-7', null]) === true);
check('legacy entry rejects a different serial',
    $call('componentEntryMatches', [$legacy, 'SSD-K', 'SN-9', null]) === false);

echo "\n-- A-L3: identity fields written onto new entries --\n";

$identity = $call('componentEntryIdentity', ['SN-1', [
    'inventory_id'   => 7,
    'reserved_units' => [['ID' => 7], ['ID' => 8], ['ID' => 9]],
]]);
check('serial_number recorded', ($identity['serial_number'] ?? null) === 'SN-1');
check('inventory_id recorded', ($identity['inventory_id'] ?? null) === 7);
check('reserved_units records all 3 claimed units',
    ($identity['reserved_units'] ?? null) === [7, 8, 9]);

// A single-unit add needs no reserved_units list; inventory_id already names it.
$identity = $call('componentEntryIdentity', [null, [
    'inventory_id'   => 7,
    'reserved_units' => [['ID' => 7]],
]]);
check('single unit -> no redundant reserved_units key',
    !array_key_exists('reserved_units', $identity));
check('null serial -> no serial_number key (not a null value)',
    !array_key_exists('serial_number', $identity));

echo "\n-- A-L8: capacity budgets sum quantities, not entry count --\n";

// The pre-fix code used count(), so this 3-entry list read as 3 slots, not 9.
$entries = [
    ['uuid' => 'A', 'quantity' => 4],
    ['uuid' => 'B', 'quantity' => 4],
    ['uuid' => 'C', 'quantity' => 1],
];
check('4 + 4 + 1 = 9 (count() would have said 3)',
    $call('sumEntryQuantities', [$entries]) === 9);
check('missing quantity counts as 1',
    $call('sumEntryQuantities', [[['uuid' => 'A']]]) === 1);
check('zero/negative quantity floors at 1',
    $call('sumEntryQuantities', [[['uuid' => 'A', 'quantity' => 0]]]) === 1);
check('empty list -> 0', $call('sumEntryQuantities', [[]]) === 0);
check('non-array input -> 0', $call('sumEntryQuantities', [null]) === 0);
check('non-array members skipped',
    $call('sumEntryQuantities', [[['quantity' => 2], 'garbage', null]]) === 2);

echo "\n";
if ($failures > 0) {
    echo "$failures of $checks CHECKS FAILED\n";
    exit(1);
}
echo "ALL $checks CHECKS PASS\n";
exit(0);
