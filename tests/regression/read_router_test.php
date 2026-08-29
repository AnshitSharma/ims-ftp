<?php
/**
 * read_router_test.php — U-X.1 regression test for ConfigReadRouter.
 *
 * REWRITTEN 2026-08-30 (P9/U-D.4). The pack's original acceptance criteria were
 * "three modes on dual-written fixture" plus "sample log empty on healthy fixture,
 * non-empty on self-test corrupted fixture". READ_FROM_ROWS is deleted, and with
 * it ConfigReadRouter::mode(), the =off passthrough, the =sample comparison and
 * the divergence JSONL those criteria were written against. Roughly half of this
 * file described machinery that no longer exists.
 *
 * What is left is the criterion that actually mattered and still does — "shape
 * equality field-by-field in =on vs legacy snapshot" — now stated unconditionally,
 * because =on is the only behaviour there is.
 *
 * The =sample corrupted-fixture control is NOT dropped, it is re-aimed. Its job
 * was to stop the comparison passing vacuously (the F-11/F-18/F-21 lesson: an
 * empty derived list must never read as agreement). With the comparison gone, the
 * vacuity risk moved: every parity assertion below would still pass if the router
 * quietly returned the legacy extraction instead of reading rows at all. So the
 * control now hides a config_components row and asserts the router's ANSWER
 * changes — direct evidence that the rows side is genuinely the source.
 *
 * Safety: every DB write happens inside a transaction that is ALWAYS rolled back,
 * each preceded by an inTransaction() assertion — a mechanism, not a naming
 * convention. Point GOLDEN_DB_NAME at a replica anyway, never production.
 *
 * Fixture requirement: real configs carrying BOTH legacy JSON and live
 * config_components rows. Without them the parity assertions self-skip with honest
 * SKIPPED lines rather than passing vacuously.
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
echo "-- the flag is gone, and must not come back (no DB needed) --\n";

$routerSrc = file_get_contents("$ROOT/core/models/config/ConfigReadRouter.php");
$sbSrc     = file_get_contents("$ROOT/core/models/server/ServerBuilder.php");

check('ConfigReadRouter::mode() no longer exists (U-D.4 deleted READ_FROM_ROWS)',
    !method_exists('ConfigReadRouter', 'mode'));
check('the router reads no environment flag at all',
    strpos($routerSrc, 'getenv(') === false);
check('the sample-mode comparison and its divergence log are gone',
    !method_exists('ConfigReadRouter', 'sample')
    && strpos($routerSrc, 'logRead(') === false
    && strpos($routerSrc, 'KIND_DIVERGENCE') === false);

// components() is now the ONLY path, so its fail-closed property is no longer a
// property of one branch among three — it is the property of the method. A
// swallowed exception here would silently serve a wrong component list, which is
// worse than an error: it is a wrong answer that looks like a right one.
$compStart = strpos($routerSrc, 'public static function components(');
$compEnd   = $compStart !== false ? strpos($routerSrc, 'private static function ', $compStart) : false;
$compBody  = ($compStart !== false && $compEnd !== false && $compEnd > $compStart)
    ? substr($routerSrc, $compStart, $compEnd - $compStart)
    : '';
check('components() does not swallow exceptions (fail-closed: it must never silently fall back to legacy)',
    $compBody !== ''
    && strpos($compBody, 'return self::rowsToLegacyShape(') !== false
    && strpos($compBody, 'catch') === false
    && strpos($compBody, 'try {') === false);

// =========================================================================
echo "-- structural guarantees the docblock claims (no DB needed) --\n";

// U-X.1 checklist item 3: the cache sits ABOVE the router. Scoped to
// getConfigurationDetails()'s own body — a cache read in any other method must not
// satisfy it.
$gcdStart = strpos($sbSrc, 'function getConfigurationDetails(');
$gcdNext  = $gcdStart !== false ? strpos($sbSrc, "\n    public function ", $gcdStart + 1) : false;
$gcdBody  = $gcdStart === false
    ? ''
    : ($gcdNext === false ? substr($sbSrc, $gcdStart) : substr($sbSrc, $gcdStart, $gcdNext - $gcdStart));
$cachePos  = $gcdBody !== '' ? strpos($gcdBody, '$this->configCache->getConfiguration($configUuid)') : false;
$routerPos = $gcdBody !== '' ? strpos($gcdBody, 'ConfigReadRouter::components(') : false;
check('ServerBuilder routes getConfigurationDetails through ConfigReadRouter', $routerPos !== false);
check('the configuration cache is checked BEFORE the router is reached', $cachePos !== false && $routerPos !== false && $cachePos < $routerPos);

// Only the read entrypoints are routed; the mutation/validation callers still
// extract straight out of the JSON columns. Asserted EXACTLY (not ">=") on
// purpose: routing one more caller, or letting one drift back to direct
// extraction, must fail here and be a deliberate decision rather than a silent
// side effect.
//
// 2026-08-30: 13 -> 7 -> 0, and 0 is where it stays.
//
// P9/U-D.2 deleted six of the original thirteen along with the enclosing methods that
// made the calls (addComponent(), validateComponentAddition(), the validate/score
// family). U-D.3b then routed the remaining seven onto ConfigReadRouter, which is why
// this is now an EQUALITY ON ZERO rather than a shrinking carve-out: the assertion no
// longer tolerates a documented exception list, so a single new direct extract turns it
// red instead of merely needing the comment updated.
//
// The two blockers that stalled this at 7 on the first attempt are both resolved and
// neither needed a backfill (tasks/u-d3-execution.md):
//   1. "config_components is INCOMPLETE" -- the one config concerned is the only
//      is_virtual build in the system, and a virtual build has NO inventory units for a
//      row to point at. It is excluded from the rows store by design
//      (ConfigComponentWriter::afterLegacyAdd), not by an incomplete backfill. It is
//      still pinned at exactly one by the standing check above.
//   2. "NIC emission ORDER differs" -- the only consumer of first-of-type ordering,
//      ServerBuilder::getConfigurationComponent(), is private with zero callers.
$directCalls = substr_count($sbSrc, '$this->extractComponentsFromJson(');
check("no ServerBuilder reader extracts from the JSON columns directly (found $directCalls, want 0)",
    $directCalls === 0);
check('the single in-file rows seam exists (componentsFromRows -> ConfigReadRouter)',
    strpos($sbSrc, 'private function componentsFromRows(') !== false
    && strpos($sbSrc, 'ConfigReadRouter::components(') !== false);
$gccStart = strpos($sbSrc, 'function getCompatibleComponents(');
$gccNext  = $gccStart !== false ? strpos($sbSrc, "\n    public function ", $gccStart + 1) : false;
$gccBody  = $gccStart === false
    ? ''
    : ($gccNext === false ? substr($sbSrc, $gccStart) : substr($sbSrc, $gccStart, $gccNext - $gccStart));
check('getCompatibleComponents reads through ConfigReadRouter, not extractComponentsFromJson',
    $gccBody !== ''
    && strpos($gccBody, 'ConfigReadRouter::components(') !== false
    && strpos($gccBody, '$this->extractComponentsFromJson(') === false);

// portIndexFromSlotRef is the inverse of the writer's slot_ref format.
foreach ([['port_0', 0], ['port_3', 3], ['port_12', 12], [null, null], ['', null],
          ['slot_3', null], ['port_x', null], ['port_', null]] as [$in, $want]) {
    check('portIndexFromSlotRef(' . var_export($in, true) . ') === ' . var_export($want, true),
        callPrivate('portIndexFromSlotRef', [$in]) === $want);
}

// =========================================================================
echo "-- DB-backed: rows-derived shape vs the legacy JSON snapshot --\n";

$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
// Credential resolution is shared, not copy-pasted: scratch_db_password()
// honours GOLDEN_DB_PASS *and* GOLDEN_DB_PASS_FILE. See _scratch_db.php.
require_once __DIR__ . '/_scratch_db.php';
$dbPass = scratch_db_password();

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
    skip("scratch DB '$dbName' unreachable — every parity assertion");
    echo "\n" . ($fails === 0 ? "OK" : "FAILURES") . ": $fails fail(s), $skips skipped\n";
    exit($fails === 0 ? 0 : 1);
}

require_once $ROOT . '/core/models/server/ServerBuilder.php';
$builder = new ServerBuilder($pdo);

/** Configs that actually have BOTH sides — the only ones these assertions mean anything on. */
try {
    $fixtures = $pdo->query("
        SELECT sc.*
          FROM server_configurations sc
         WHERE sc.is_virtual = 0
           AND EXISTS (SELECT 1 FROM config_components cc
                        WHERE cc.config_uuid = sc.config_uuid AND cc.removed_at IS NULL)
         ORDER BY sc.config_uuid
    ")->fetchAll();
} catch (PDOException $e) {
    skip("fixture '$dbName' predates the P2 schema (" . $e->getCode() . ") — every parity assertion");
    echo "\n" . ($fails === 0 ? "OK" : "FAILURES") . ": $fails fail(s), $skips skipped\n";
    exit($fails === 0 ? 0 : 1);
}

if (!$fixtures) {
    skip('no dual-written config in the fixture (is_virtual=0 with live config_components rows)');
    echo "\n" . ($fails === 0 ? "OK" : "FAILURES") . ": $fails fail(s), $skips skipped\n";
    exit($fails === 0 ? 0 : 1);
}
echo "  (fixture: " . count($fixtures) . " dual-written config(s) in '$dbName')\n";

// ---- the anti-vacuity control, re-aimed ---------------------------------
// Every parity assertion below compares the router's answer against the legacy
// JSON extraction. All of them would pass just as happily if components() had
// quietly gone back to RETURNING the legacy extraction. Prove it does not: hide
// one live config_components row and the router's answer must change. If it does
// not, the comparisons that follow are worthless and this file says so FIRST,
// before printing a screen of green.
$rowsSideIsReal = false;
$corruptTarget = null;
foreach ($fixtures as $row) {
    $stmt = $pdo->prepare('SELECT id FROM config_components WHERE config_uuid = ? AND removed_at IS NULL LIMIT 1');
    $stmt->execute([$row['config_uuid']]);
    $id = $stmt->fetchColumn();
    if ($id !== false) { $corruptTarget = [$row, (int)$id]; break; }
}

if ($corruptTarget === null) {
    skip('rows-side-is-real control (no live config_components row to hide)');
} else {
    [$row, $ccId] = $corruptTarget;
    $before = ConfigReadRouter::components($builder, $pdo, $row);
    $pdo->beginTransaction();
    try {
        // Asserted mechanism, not a naming convention: no write without a transaction.
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('refusing to write outside a transaction');
        }
        $pdo->prepare('UPDATE config_components SET removed_at = NOW() WHERE id = ?')->execute([$ccId]);
        $after = ConfigReadRouter::components($builder, $pdo, $row);
        $rowsSideIsReal = (count($after) === count($before) - 1);
        check('hiding one config_components row removes exactly one component from the router\'s answer '
            . '(so the parity checks below are reading ROWS, not replaying legacy)',
            $rowsSideIsReal);
    } finally {
        $pdo->rollBack();
    }
    // Prove the rollback really happened — a leaked tombstone would silently
    // corrupt every later run of this and every other suite against this replica.
    $stmt = $pdo->prepare('SELECT removed_at FROM config_components WHERE id = ?');
    $stmt->execute([$ccId]);
    check('the corruption was rolled back (fixture left untouched)', $stmt->fetchColumn() === null);
}

// ---- U-D.3 PRECONDITION: is the rows store complete for everything that can
// ---- BE in it? -----------------------------------------------------------
// Found 2026-08-30 while probing the U-D.3b reader migration. The parity block below
// deliberately selects only configs that HAVE rows, so it cannot see a config with
// none -- and one exists: 3918a957 carries eight components in its JSON columns and
// zero config_components rows.
//
// DIAGNOSIS CORRECTED (see tasks/u-d3-execution.md). It is not an incomplete backfill
// and no seeder can fix it. That config is the only is_virtual=1 build in the system,
// and a virtual build reserves no stock: all eleven *inventory tables return zero rows
// for its ServerUUID. config_components.inventory_id is NOT NULL and keyed
// UNIQUE(inventory_table, inventory_id, component_type), so there is nothing for a row
// to point at. ConfigComponentWriter::afterLegacyAdd() says so in its own guard --
// virtual configs are excluded from the rows store BY DESIGN.
//
// So the pin below is split in two, and the second half is the one that matters:
//
//   (a) the virtual carve-out, pinned at its measured scope so it cannot GROW
//       silently. Guarded on the columns still existing, because U-D.3c drops them and
//       a test that dies on a missing column is a test that gets deleted.
//   (b) a REAL config -- is_virtual = 0 -- whose inventory says units are installed in
//       it while config_components says it is empty. That is the divergence that would
//       actually lose a build, it is impossible by construction today, and unlike (a)
//       it survives the drop because it never mentions a JSON column.
$jsonColumnsExist = (bool)$pdo->query("SHOW COLUMNS FROM server_configurations LIKE 'cpu_configuration'")->fetch();

if ($jsonColumnsExist) {
    $VIRTUAL_JSON_ONLY_EXPECTED = 1;
    $jsonOnly = $pdo->query("
        SELECT sc.config_uuid
          FROM server_configurations sc
         WHERE NOT EXISTS (SELECT 1 FROM config_components cc
                            WHERE cc.config_uuid = sc.config_uuid AND cc.removed_at IS NULL)
           AND (sc.cpu_configuration IS NOT NULL OR sc.ram_configuration IS NOT NULL
                OR sc.storage_configuration IS NOT NULL OR sc.caddy_configuration IS NOT NULL
                OR sc.nic_config IS NOT NULL OR sc.sfp_configuration IS NOT NULL
                OR sc.pciecard_configurations IS NOT NULL OR sc.hbacard_config IS NOT NULL
                OR sc.motherboard_uuid IS NOT NULL OR sc.chassis_uuid IS NOT NULL)
         ORDER BY sc.config_uuid
    ")->fetchAll(PDO::FETCH_COLUMN);
    check('(a) no NEW config has JSON components but no config_components rows '
        . '(expected ' . $VIRTUAL_JSON_ONLY_EXPECTED . ', found ' . count($jsonOnly) . ')',
        count($jsonOnly) <= $VIRTUAL_JSON_ONLY_EXPECTED);

    // and every one of them must be a VIRTUAL build -- the only class that legitimately
    // cannot be mirrored. A real build in this state is a data-loss bug, not a carve-out.
    $nonVirtual = [];
    foreach ($jsonOnly as $u) {
        $st = $pdo->prepare("SELECT is_virtual FROM server_configurations WHERE config_uuid = ?");
        $st->execute([$u]);
        if ((int)$st->fetchColumn() !== 1) { $nonVirtual[] = $u; }
    }
    check('(a) every rows-less JSON config is is_virtual=1 (the by-design carve-out)'
        . ($nonVirtual ? ' -- REAL builds affected: ' . implode(', ', $nonVirtual) : ''),
        $nonVirtual === []);
} else {
    echo "  INFO   legacy JSON columns are gone (U-D.3c applied); (a) no longer applicable\n";
}

// (b) survives the drop.
$inventoryTables = ['cpuinventory', 'raminventory', 'storageinventory', 'nicinventory',
                    'caddyinventory', 'motherboardinventory', 'chassisinventory',
                    'pciecardinventory', 'risercardinventory', 'hbacardinventory', 'sfpinventory'];
$union = [];
foreach ($inventoryTables as $t) {
    // The inventory tables do not share one collation, so an untagged UNION dies
    // with a 1271 illegal-mix error. Normalising each arm keeps the check portable.
    $union[] = "SELECT CONVERT(ServerUUID USING utf8mb4) COLLATE utf8mb4_general_ci AS ServerUUID"
             . " FROM `$t` WHERE ServerUUID IS NOT NULL AND ServerUUID <> ''";
}
$stranded = $pdo->query("
    SELECT DISTINCT sc.config_uuid
      FROM server_configurations sc
      JOIN (" . implode(' UNION ALL ', $union) . ") inv ON inv.ServerUUID = sc.config_uuid
     WHERE sc.is_virtual = 0
       AND NOT EXISTS (SELECT 1 FROM config_components cc
                        WHERE cc.config_uuid = sc.config_uuid AND cc.removed_at IS NULL)
     ORDER BY sc.config_uuid
")->fetchAll(PDO::FETCH_COLUMN);
check('(b) no REAL config has inventory assigned to it but zero config_components rows'
    . ($stranded ? ' (found: ' . implode(', ', $stranded) . ')' : ''),
    $stranded === []);

// ---- shape equality field-by-field, against config_components ITSELF ------
//
// U-D.3a deleted ServerBuilder::extractComponentsFromJson(), which this block used as
// its expectation. There is nothing left to compare the rows side against except the
// rows -- the same position sample() was in when P9 deleted it: a comparator with one
// input can only report that a thing equals itself.
//
// So the expectation is now built straight from SQL over config_components, not from a
// second decoder. That is STRICTER than the JSON comparison it replaces, on all three
// counts: identity is checked against the store rather than against a mirror that could
// drift with it, ORDER is asserted as the exact expected sequence (the old test only
// checked that type ranks never went backwards, which a sort could satisfy by
// accident), and the key set is checked value-by-value rather than name-by-name.
$typeOrderOk = true;
$identityOk = 0;
$identityChecked = 0;
$unexpectedKeys = [];
$slotMismatch = 0;
$slotMissing = 0;
$sourceTypeWrong = 0;
$storageWithConnection = 0;
$nonUnitQuantity = 0;
$scalarAddedAt = 0;

// The router's documented emission order: types in this sequence, and within a type
// added_at then id (the writer's append order). Reproduced here from the class docblock
// so a change to LEGACY_TYPE_ORDER has to be made deliberately in two places.
$TYPE_ORDER = ['cpu', 'ram', 'storage', 'caddy', 'nic', 'hbacard',
               'motherboard', 'chassis', 'risercard', 'pciecard', 'sfp'];

$expectedFromRows = function (string $configUuid) use ($pdo, $TYPE_ORDER): array {
    $st = $pdo->prepare("SELECT id, component_type, spec_uuid, added_at
                           FROM config_components
                          WHERE config_uuid = ? AND removed_at IS NULL");
    $st->execute([$configUuid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $rank = array_flip($TYPE_ORDER);
    usort($rows, function ($a, $b) use ($rank) {
        $ra = $rank[$a['component_type']] ?? count($rank);
        $rb = $rank[$b['component_type']] ?? count($rank);
        if ($ra !== $rb) { return $ra <=> $rb; }
        $cmp = strcmp((string)$a['added_at'], (string)$b['added_at']);
        return $cmp !== 0 ? $cmp : ((int)$a['id'] <=> (int)$b['id']);
    });
    return array_map(fn($r) => $r['component_type'] . '|' . $r['spec_uuid'], $rows);
};

foreach ($fixtures as $row) {
    $rowsSide = ConfigReadRouter::components($builder, $pdo, $row);

    $emitted = array_map(
        fn($c) => ($c['component_type'] ?? '?') . '|' . ($c['component_uuid'] ?? '?'),
        $rowsSide
    );
    $expected = $expectedFromRows($row['config_uuid']);

    // (1) IDENTITY — exactly the live rows, no more and no fewer.
    $identityChecked++;
    $a = $emitted; $b = $expected; sort($a); sort($b);
    if ($a === $b) { $identityOk++; }

    // (2) ORDER — the exact sequence, not merely a non-decreasing type rank.
    //     Downstream numbers storage bays by POSITION, so order is behaviour.
    if ($emitted !== $expected) { $typeOrderOk = false; }

    // (3) key-set discipline. U-D.3b added two keys the legacy extractor never
    //     emitted -- 'slot_position' and, for NICs, 'source_type' -- because the slot
    //     consumers that used to read the raw columns cannot be routed without them.
    //     Rather than widen the allow-list (which would let ANY value through under
    //     those names), each is checked for CORRECTNESS against config_components
    //     itself. That is strictly stronger than the key-set test it replaces: a wrong
    //     slot or a NIC mislabelled onboard now fails, where before only a wrong key
    //     NAME did.
    //
    //     The permitted set is now WRITTEN OUT rather than harvested from whatever the
    //     legacy extractor happened to emit. Harvesting made the assertion only as
    //     strict as the thing it was comparing against; a literal list cannot drift.
    $ALLOWED_KEYS = [
        'component_type', 'component_uuid', 'quantity', 'added_at',
        'serial_number', 'inventory_id', 'slot_position', 'source_type',
        'parent_nic_uuid', 'port_index', 'status', 'connection',
    ];
    $slotRefByKey = [];
    $st = $pdo->prepare("SELECT component_type, spec_uuid, slot_ref FROM config_components
                          WHERE config_uuid = ? AND removed_at IS NULL");
    $st->execute([$row['config_uuid']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $slotRefByKey[$r['component_type'] . '|' . $r['spec_uuid']][] = $r['slot_ref'];
    }
    foreach ($rowsSide as $c) {
        foreach (array_keys($c) as $k) {
            if (!in_array($k, $ALLOWED_KEYS, true)) {
                $unexpectedKeys[$k] = true;
            }
        }
        $key = $c['component_type'] . '|' . $c['component_uuid'];
        // slot_position, when emitted, must be one of the slot_refs the rows store
        // actually holds for that (type, spec) -- never invented, never stale.
        if (array_key_exists('slot_position', $c)
            && !in_array($c['slot_position'], $slotRefByKey[$key] ?? [], true)) {
            $slotMismatch++;
        }
        // a slot_ref the store HAS must be surfaced, so a routed slot consumer cannot
        // silently see an unplaced card.
        $storedSlots = array_values(array_filter($slotRefByKey[$key] ?? [], function ($v) {
            return $v !== null && $v !== '';
        }));
        if ($storedSlots && !array_key_exists('slot_position', $c)) {
            $slotMissing++;
        }
        if ($c['component_type'] === 'nic') {
            $want = strpos((string)$c['component_uuid'], 'onboard-') === 0 ? 'onboard' : 'component';
            if (($c['source_type'] ?? null) !== $want) { $sourceTypeWrong++; }
        } elseif (array_key_exists('source_type', $c)) {
            $sourceTypeWrong++; // source_type is a NIC-only field
        }
        // (4) the three documented deviations must ACTUALLY hold — if one of them
        //     silently stops being true, the class docblock has become a lie.
        if ($c['component_type'] === 'storage' && array_key_exists('connection', $c)) { $storageWithConnection++; }
        if (($c['quantity'] ?? null) !== 1) { $nonUnitQuantity++; }
        if (in_array($c['component_type'], ['motherboard', 'chassis'], true) && $c['added_at'] !== null) { $scalarAddedAt++; }
    }
}

check("the router emits exactly the live config_components rows, on all $identityChecked configs ($identityOk matched)",
    $identityOk === $identityChecked);
check('and in exactly the documented order (type rank, then added_at, then id)', $typeOrderOk);
check('no key is invented beyond the documented set' . ($unexpectedKeys ? ' (found: ' . implode(',', array_keys($unexpectedKeys)) . ')' : ''),
    empty($unexpectedKeys));
check("every emitted slot_position is a slot_ref config_components actually holds ($slotMismatch wrong)",
    $slotMismatch === 0);
check("every stored slot_ref reaches the caller as slot_position ($slotMissing dropped)",
    $slotMissing === 0);
check("source_type is emitted for NICs only, and matches the onboard- prefix ($sourceTypeWrong wrong)",
    $sourceTypeWrong === 0);
check('documented deviation (a): storage connection is omitted so the caller recomputes it', $storageWithConnection === 0);
check('documented deviation (b): added_at is null for scalar-column types', $scalarAddedAt === 0);
check('documented deviation (c): quantity is always 1 (one row per physical unit)', $nonUnitQuantity === 0);

// minimalOutput is used by several callers and must hold on the rows path too.
$minimalOk = 0;
foreach ($fixtures as $row) {
    $m = ConfigReadRouter::components($builder, $pdo, $row, true);
    $shapeOk = true;
    foreach ($m as $c) {
        if (array_keys($c) !== ['component_type', 'component_uuid'] || empty($c['component_uuid'])) { $shapeOk = false; }
    }
    if ($shapeOk) { $minimalOk++; }
}
check('$minimalOutput is honoured (two keys only, null uuids filtered)', $minimalOk === count($fixtures));

// A config row with no uuid has no rows to look up. Since U-D.3a there is no legacy
// extraction to fall back to either, so the only honest answer is an empty list — and
// it must be returned, not thrown.
$noUuid = ConfigReadRouter::components($builder, $pdo, []);
check('a row with no config_uuid yields an empty list instead of throwing', $noUuid === []);
check('ServerBuilder::extractComponentsFromJson is gone (U-D.3a), so nothing can fall back to it',
    !method_exists('ServerBuilder', 'extractComponentsFromJson'));

echo "\n" . ($fails === 0 ? "OK" : "FAILURES") . ": $fails fail(s), $skips skipped\n";
exit($fails === 0 ? 0 : 1);
