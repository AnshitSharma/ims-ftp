<?php
/**
 * serverstate_equivalence.php — Phase 1 equivalence proof for ServerState.
 *
 * COMPATIBILITY_CONSOLIDATION_PLAN.md Phase 1 requires the new read-model to be provably
 * equivalent to the existing reads before any validator is cut onto it (Phase 2). This
 * harness asserts, for every server_configurations row in the scratch DB, that
 *
 *     ServerState::getComponents()  ≡  ServerBuilder::extractComponentsFromJson()
 *
 * on the IDENTITY fields (type, uuid, quantity, and for SFP parent_nic_uuid/port_index)
 * — added_at is intentionally excluded (the legacy extractor stamps wall-clock for missing
 * values; ServerState is deterministic). It also checks the typed accessors partition the
 * same set. Read-only; seed the scratch DB exactly like the golden master
 * (see tests/golden/README.md).
 *
 *   php ims-ftp/tests/serverstate_equivalence.php   → exit 0 = zero divergence.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

$ROOT = dirname(__DIR__);

if (!getenv('JWT_SECRET')) {
    putenv('JWT_SECRET=serverstate-equivalence-harness');
}

$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
$dbPass = getenv('GOLDEN_DB_PASS');
if ($dbPass === false) { $dbPass = ''; }

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: cannot connect to scratch DB '$dbName': " . $e->getMessage() . "\n");
    exit(2);
}

require_once $ROOT . '/core/models/server/ServerBuilder.php';
require_once $ROOT . '/core/models/compatibility/ServerState.php';

$builder = new ServerBuilder($pdo);

/** Reduce a component list to comparable identity tuples (order preserved). */
$identity = function (array $components): array {
    $out = [];
    foreach ($components as $c) {
        $out[] = [
            'type'   => $c['component_type']  ?? null,
            'uuid'   => $c['component_uuid']  ?? null,
            'qty'    => $c['quantity']        ?? 1,
            'parent' => $c['parent_nic_uuid'] ?? null,
            'port'   => $c['port_index']      ?? null,
        ];
    }
    return $out;
};

$rows = $pdo->query("SELECT * FROM server_configurations ORDER BY config_uuid, id")->fetchAll(PDO::FETCH_ASSOC);

$failures = [];
$checked  = 0;
$compared = 0;

foreach ($rows as $row) {
    $configUuid = (string)($row['config_uuid'] ?? ('id:' . ($row['id'] ?? '?')));
    $checked++;

    $expected = $identity($builder->extractComponentsFromJson($row));
    $state    = ServerState::fromConfigData($row);
    $actual   = $identity($state->getComponents());
    $compared += count($expected);

    if ($expected !== $actual) {
        $failures[] = "[$configUuid] getComponents divergence:\n"
            . "    expected: " . json_encode($expected, JSON_UNESCAPED_SLASHES) . "\n"
            . "    actual  : " . json_encode($actual, JSON_UNESCAPED_SLASHES);
        continue;
    }

    // Typed accessors must partition the same multiset by type.
    $byTypeExpected = [];
    foreach ($expected as $e) { $byTypeExpected[$e['type']][] = $e; }

    $accessorMap = [
        'cpu'        => $state->getCpus(),
        'ram'        => $state->getRam(),
        'storage'    => $state->getStorage(),
        'caddy'      => $state->getCaddies(),
        'nic'        => $state->getNics(),
        'hbacard'    => $state->getHbas(),
        'pciecard'   => $state->getPcieCards(),
        'sfp'        => $state->getSfps(),
        'motherboard'=> array_filter([$state->getMotherboard()]),
        'chassis'    => array_filter([$state->getChassis()]),
    ];
    foreach ($accessorMap as $type => $list) {
        $expectCount = count($byTypeExpected[$type] ?? []);
        $gotCount    = count($list);
        if ($expectCount !== $gotCount) {
            $failures[] = "[$configUuid] accessor count mismatch for '$type': expected $expectCount, got $gotCount";
        }
    }

    // Scalar identity sanity.
    $mbExpected = !empty($row['motherboard_uuid']) ? $row['motherboard_uuid'] : null;
    if ($state->getMotherboardUuid() !== $mbExpected) {
        $failures[] = "[$configUuid] motherboard uuid mismatch: expected " . var_export($mbExpected, true)
            . ", got " . var_export($state->getMotherboardUuid(), true);
    }
}

// withCandidate sanity: adding a candidate appends exactly one component of that type.
if (!empty($rows)) {
    $sample = ServerState::fromConfigData($rows[0]);
    $before = count($sample->getComponents());
    $after  = $sample->withCandidate('nic', 'candidate-nic-uuid', 1);
    if (count($after->getComponents()) !== $before + 1) {
        $failures[] = "withCandidate: expected " . ($before + 1) . " components, got " . count($after->getComponents());
    }
    if (count($sample->getComponents()) !== $before) {
        $failures[] = "withCandidate mutated the original state (immutability violated)";
    }
}

if (empty($failures)) {
    fwrite(STDOUT, "OK: ServerState ≡ extractComponentsFromJson across $checked configs ($compared components compared); typed accessors + withCandidate consistent.\n");
    exit(0);
}

fwrite(STDOUT, "FAILED: " . count($failures) . " divergence(s):\n");
foreach ($failures as $f) {
    fwrite(STDOUT, "  - $f\n");
}
exit(1);
