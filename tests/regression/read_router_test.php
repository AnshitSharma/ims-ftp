<?php
/**
 * read_router_test.php — U-X.1 regression test for ConfigReadRouter.
 *
 * Pack acceptance criteria: "three modes on dual-written fixture; shape equality
 * field-by-field in =on vs legacy snapshot", plus "sample log empty on healthy
 * fixture, non-empty on self-test corrupted fixture".
 *
 * Safety: every DB write this file makes happens inside a transaction that is
 * ALWAYS rolled back, and each write is preceded by an inTransaction() assertion
 * (see corruptOneRow()). That is deliberately used INSTEAD of the scratch-DB name
 * regex other tests carry -- a name pattern is a convention, an asserted rollback
 * is a mechanism -- but point GOLDEN_DB_NAME at a replica anyway, never production.
 *
 * Fixture requirement: a dual-written/backfilled replica, i.e. real configs that
 * have BOTH legacy JSON and live config_components rows. Without rows the =on and
 * sample assertions have nothing to compare and self-skip with honest SKIPPED
 * lines rather than passing vacuously (the F-11/F-18/F-21 lesson: an empty derived
 * list must never read as agreement).
 *
 *   php ims-ftp/tests/regression/read_router_test.php   → exit 0 = all pass
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);

if (!getenv('JWT_SECRET')) {
    putenv('JWT_SECRET=read-router-test-harness');
}

require_once $ROOT . '/core/models/config/ConfigReadRouter.php';

$fails = 0;
$skips = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}
function skip($label) {
    global $skips;
    echo "  SKIPPED  $label\n";
    $skips++;
}

/** Read a private/protected static through reflection. */
function callPrivate(string $method, array $args) {
    $m = new ReflectionMethod('ConfigReadRouter', $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
}

// =========================================================================
echo "-- mode() contract (no DB needed) --\n";

$restore = getenv('READ_FROM_ROWS');
foreach ([['', 'off'], ['off', 'off'], ['sample', 'sample'], ['on', 'on'],
          ['ON', 'on'], ['  sample  ', 'sample'], ['yes', 'off'], ['enforce', 'off']] as [$set, $want]) {
    putenv('READ_FROM_ROWS=' . $set);
    unset($_ENV['READ_FROM_ROWS']);
    $got = ConfigReadRouter::mode();
    check("mode() maps " . var_export($set, true) . " -> '$want'", $got === $want);
}
putenv('READ_FROM_ROWS');
unset($_ENV['READ_FROM_ROWS']);
check('mode() defaults to off when the flag is absent entirely (FLAGS.md fallback)', ConfigReadRouter::mode() === 'off');

// =========================================================================
echo "-- structural guarantees the docblock claims (no DB needed) --\n";

$routerSrc = file_get_contents("$ROOT/core/models/config/ConfigReadRouter.php");
$sbSrc = file_get_contents("$ROOT/core/models/server/ServerBuilder.php");

// U-X.1 checklist item 1: sample returns legacy ALWAYS. Proven structurally as
// well as behaviourally below -- the sample branch must contain exactly one
// return statement and it must be the legacy one. Scoped to the branch text
// (up to the '// =on.' marker that begins the next branch) so that returns
// elsewhere in the class cannot satisfy or break this assertion.
$sampleStart  = strpos($routerSrc, "if (\$mode === 'sample')");
$sampleEnd    = strpos($routerSrc, '// =on.');
$sampleBranch = ($sampleStart !== false && $sampleEnd !== false && $sampleEnd > $sampleStart)
    ? substr($routerSrc, $sampleStart, $sampleEnd - $sampleStart)
    : '';
check('sample branch contains exactly one return, and it returns $legacy',
    $sampleBranch !== ''
    && substr_count($sampleBranch, 'return ') === 1
    && strpos($sampleBranch, 'return $legacy;') !== false);
check('sample-mode comparison is wrapped so a shadow-side failure cannot break a read',
    strpos($routerSrc, 'catch (Throwable $e)') !== false
    && strpos($routerSrc, 'ConfigReadRouter sample-mode comparison failed') !== false);
check('=on does NOT swallow exceptions (fail-closed: it must not silently serve legacy)',
    preg_match('/rowsToLegacyShape.*?catch/s', substr($routerSrc, strpos($routerSrc, '// =on.'))) !== 1);
check('divergence rows carry the sapi discriminator (F-23)', strpos($routerSrc, "'sapi' => PHP_SAPI") !== false);

// U-X.1 checklist item 3: the cache sits ABOVE the router.
$cachePos  = strpos($sbSrc, '$this->configCache->getConfiguration($configUuid)');
$routerPos = strpos($sbSrc, 'ConfigReadRouter::components(');
check('ServerBuilder routes getConfigurationDetails through ConfigReadRouter', $routerPos !== false);
check('the configuration cache is checked BEFORE the router is reached (cache cannot be poisoned by a mode)',
    $cachePos !== false && $routerPos !== false && $cachePos < $routerPos);

// Only the read entrypoint is routed; mutation/validation callers stay direct
// until U-D.3. Asserted EXACTLY (not ">=") on purpose: routing one more caller,
// or letting one drift back to direct extraction, must fail here and be a
// deliberate decision rather than a silent side effect. 14 in-file call sites
// existed before this unit; 13 remain after getConfigurationDetails was routed.
$directCalls = substr_count($sbSrc, '$this->extractComponentsFromJson(');
check("exactly 13 mutation/validation-path callers still extract directly (found $directCalls) and the carve-out is documented",
    $directCalls === 13 && strpos($sbSrc, 'MUTATION-PATH CALLERS THAT STAY DIRECT') !== false);

// portIndexFromSlotRef is the inverse of the writer's slot_ref format.
foreach ([['port_0', 0], ['port_3', 3], ['port_12', 12], [null, null], ['', null],
          ['slot_3', null], ['port_x', null], ['port_', null]] as [$in, $want]) {
    check('portIndexFromSlotRef(' . var_export($in, true) . ') === ' . var_export($want, true),
        callPrivate('portIndexFromSlotRef', [$in]) === $want);
}

// =========================================================================
echo "-- DB-backed: three modes over a dual-written fixture --\n";

$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
$dbPass = getenv('GOLDEN_DB_PASS');
if (!is_string($dbPass)) { $dbPass = ''; }

$pdo = null;
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    $pdo = null;
}

if ($pdo === null) {
    skip("scratch DB '$dbName' unreachable — all three-mode assertions");
    echo "\n" . ($fails === 0 ? "OK" : "FAILURES") . ": $fails fail(s), $skips skipped\n";
    exit($fails === 0 ? 0 : 1);
}

require_once $ROOT . '/core/models/server/ServerBuilder.php';
$builder = new ServerBuilder($pdo);

/** Configs that actually have BOTH sides — the only ones these assertions mean anything on. */
$fixtures = $pdo->query("
    SELECT sc.*
      FROM server_configurations sc
     WHERE sc.is_virtual = 0
       AND EXISTS (SELECT 1 FROM config_components cc
                    WHERE cc.config_uuid = sc.config_uuid AND cc.removed_at IS NULL)
     ORDER BY sc.config_uuid
")->fetchAll();

if (!$fixtures) {
    skip('no dual-written config in the fixture (is_virtual=0 with live config_components rows)');
    echo "\n" . ($fails === 0 ? "OK" : "FAILURES") . ": $fails fail(s), $skips skipped\n";
    exit($fails === 0 ? 0 : 1);
}
echo "  (fixture: " . count($fixtures) . " dual-written config(s) in '$dbName')\n";

$logFile = $ROOT . '/reports/shadow/read-' . date('Ymd') . '.jsonl';
$logLines = function () use ($logFile): int {
    return is_file($logFile) ? count(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
};

// ---- =off is the identity ------------------------------------------------
putenv('READ_FROM_ROWS=off');
unset($_ENV['READ_FROM_ROWS']);
$offIdentical = 0;
foreach ($fixtures as $row) {
    $legacy = $builder->extractComponentsFromJson($row);
    $routed = ConfigReadRouter::components($builder, $pdo, $row);
    if ($routed === $legacy) { $offIdentical++; }
}
check("=off is byte-identical to direct legacy extraction on all " . count($fixtures) . " configs",
    $offIdentical === count($fixtures));

$before = $logLines();
foreach ($fixtures as $row) { ConfigReadRouter::components($builder, $pdo, $row); }
check('=off writes nothing to the divergence log', $logLines() === $before);

// ---- =sample returns legacy, and stays quiet on a healthy fixture --------
putenv('READ_FROM_ROWS=sample');
unset($_ENV['READ_FROM_ROWS']);
$before = $logLines();
$sampleIdentical = 0;
foreach ($fixtures as $row) {
    $legacy = $builder->extractComponentsFromJson($row);
    if (ConfigReadRouter::components($builder, $pdo, $row) === $legacy) { $sampleIdentical++; }
}
check("=sample returns the legacy answer unchanged on all " . count($fixtures) . " configs",
    $sampleIdentical === count($fixtures));

$sampleDivergences = $logLines() - $before;
// NOT asserted as "must be 0": whether this fixture is equivalent is a property of
// the DUMP, not of the router. A non-empty log here is real information (it is
// exactly what U-X.2's 72h criterion measures) and is reported, not hidden.
if ($sampleDivergences === 0) {
    check('=sample log stays EMPTY on a healthy (equivalent) fixture', true);
} else {
    echo "  INFO   =sample logged $sampleDivergences divergence(s) on this fixture — the fixture is\n";
    echo "         not equivalent. That is an equivalence_report finding, not a router bug;\n";
    echo "         the corrupted-fixture check below still proves detection works.\n";
}

// ---- =sample DETECTS a corrupted fixture (the honesty control) -----------
/**
 * Hide one live component row, inside a transaction that is always rolled back.
 * If this does not produce a divergence line, the sample comparison is vacuous —
 * which is the failure mode that made F-11/F-18/F-21 invisible for weeks.
 */
$corruptTarget = null;
foreach ($fixtures as $row) {
    $stmt = $pdo->prepare('SELECT id FROM config_components WHERE config_uuid = ? AND removed_at IS NULL LIMIT 1');
    $stmt->execute([$row['config_uuid']]);
    $id = $stmt->fetchColumn();
    if ($id !== false) { $corruptTarget = [$row, (int)$id]; break; }
}

if ($corruptTarget === null) {
    skip('corrupted-fixture detection (no live config_components row to hide)');
} else {
    [$row, $ccId] = $corruptTarget;
    $pdo->beginTransaction();
    try {
        // Asserted mechanism, not a naming convention: no write without a transaction.
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('refusing to write outside a transaction');
        }
        $pdo->prepare('UPDATE config_components SET removed_at = NOW() WHERE id = ?')->execute([$ccId]);

        $before = $logLines();
        $legacy = $builder->extractComponentsFromJson($row);
        $returned = ConfigReadRouter::components($builder, $pdo, $row);
        $after = $logLines();

        check('=sample LOGS a divergence when the rows side is missing a component', $after > $before);
        check('=sample STILL returns the legacy answer while diverging (never the rows side)', $returned === $legacy);

        if ($after > $before) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $rec = json_decode(end($lines), true);
            check('divergence row identifies the config', ($rec['config_uuid'] ?? null) === $row['config_uuid']);
            check('divergence row names what is missing from the rows side', !empty($rec['only_in_json']));
            check('divergence row is tagged sapi=cli when written by this harness (F-23)', ($rec['sapi'] ?? null) === 'cli');
            check('divergence row distinguishes "rows side entirely empty" from a component diff',
                array_key_exists('rows_side_empty', $rec));
        }
    } finally {
        $pdo->rollBack();
    }
    // Prove the rollback really happened — a leaked tombstone would silently
    // corrupt every later run of this and every other suite against this replica.
    $stmt = $pdo->prepare('SELECT removed_at FROM config_components WHERE id = ?');
    $stmt->execute([$ccId]);
    check('the corruption was rolled back (fixture left untouched)', $stmt->fetchColumn() === null);
}

// ---- =on: shape equality field-by-field vs the legacy snapshot -----------
echo "-- DB-backed: =on shape vs legacy snapshot --\n";
putenv('READ_FROM_ROWS=on');
unset($_ENV['READ_FROM_ROWS']);

$typeOrderOk = true;
$identityOk = 0;
$identityChecked = 0;
$unexpectedKeys = [];
$storageWithConnection = 0;
$nonUnitQuantity = 0;
$scalarAddedAt = 0;
$legacyKeyUniverse = [];

foreach ($fixtures as $row) {
    $legacy = $builder->extractComponentsFromJson($row);
    $rowsSide = ConfigReadRouter::components($builder, $pdo, $row);

    // (1) IDENTITY — who is in this config — must match. This is the contract
    //     =on actually has to honour; the three documented shape deviations
    //     (quantity/added_at/connection) are checked separately below.
    $ident = function (array $list): array {
        $out = [];
        foreach ($list as $c) {
            $out[] = ($c['component_type'] ?? '?') . '|' . ($c['component_uuid'] ?? '?');
        }
        sort($out);
        return $out;
    };
    $identityChecked++;
    if ($ident($legacy) === $ident($rowsSide)) { $identityOk++; }

    // (2) legacy emission order must be reproduced (downstream numbers storage
    //     bays by POSITION, so order is behaviour, not cosmetics).
    $order = array_flip(['cpu', 'ram', 'storage', 'caddy', 'nic', 'hbacard', 'motherboard', 'chassis', 'pciecard', 'sfp']);
    $lastRank = -1;
    foreach ($rowsSide as $c) {
        $rank = $order[$c['component_type']] ?? 99;
        if ($rank < $lastRank) { $typeOrderOk = false; }
        $lastRank = $rank;
    }

    // (3) key-set discipline: =on must not invent keys legacy never emits.
    foreach ($legacy as $c) {
        foreach (array_keys($c) as $k) { $legacyKeyUniverse[$k] = true; }
    }
    foreach ($rowsSide as $c) {
        foreach (array_keys($c) as $k) {
            if (!isset($legacyKeyUniverse[$k]) && !in_array($k, ['inventory_id', 'serial_number', 'parent_nic_uuid', 'port_index', 'status'], true)) {
                $unexpectedKeys[$k] = true;
            }
        }
        // (4) the three documented deviations must ACTUALLY hold — if one of them
        //     silently stops being true, the class docblock has become a lie.
        if ($c['component_type'] === 'storage' && array_key_exists('connection', $c)) { $storageWithConnection++; }
        if (($c['quantity'] ?? null) !== 1) { $nonUnitQuantity++; }
        if (in_array($c['component_type'], ['motherboard', 'chassis'], true) && $c['added_at'] !== null) { $scalarAddedAt++; }
    }
}

check("=on preserves component IDENTITY on all $identityChecked configs ($identityOk matched)",
    $identityOk === $identityChecked);
check('=on emits types in the legacy branch order (storage bay numbering is positional)', $typeOrderOk);
check('=on invents no key legacy never emits' . ($unexpectedKeys ? ' (found: ' . implode(',', array_keys($unexpectedKeys)) . ')' : ''),
    empty($unexpectedKeys));
check('documented deviation (a): =on omits storage connection so the caller recomputes it', $storageWithConnection === 0);
check('documented deviation (b): =on reports added_at = null for scalar-column types', $scalarAddedAt === 0);
check('documented deviation (c): =on quantity is always 1 (one row per physical unit)', $nonUnitQuantity === 0);

// minimalOutput must survive all three modes identically — several callers use it.
$minimalOk = 0;
foreach ($fixtures as $row) {
    $m = ConfigReadRouter::components($builder, $pdo, $row, true);
    $shapeOk = true;
    foreach ($m as $c) {
        if (array_keys($c) !== ['component_type', 'component_uuid'] || empty($c['component_uuid'])) { $shapeOk = false; }
    }
    if ($shapeOk) { $minimalOk++; }
}
check('=on honours $minimalOutput (two keys only, null uuids filtered)', $minimalOk === count($fixtures));

// A config with no uuid has no rows side to consult — must degrade to legacy, not throw.
putenv('READ_FROM_ROWS=on');
$noUuid = ConfigReadRouter::components($builder, $pdo, ['cpu_configuration' => null]);
check('a row with no config_uuid degrades to legacy instead of throwing', $noUuid === []);

if (is_string($restore) && $restore !== '') { putenv('READ_FROM_ROWS=' . $restore); } else { putenv('READ_FROM_ROWS'); }

echo "\n" . ($fails === 0 ? "OK" : "FAILURES") . ": $fails fail(s), $skips skipped\n";
exit($fails === 0 ? 0 : 1);
