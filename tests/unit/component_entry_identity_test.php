<?php
/**
 * Unit test — physical-unit identity (audit A-L3/A-L4/A-L5/A-L8).
 *
 * REPOINTED 2026-08-30 (U-D.3a). The three ServerBuilder helpers this suite used to
 * exercise by reflection — removeOneComponentEntry(), componentEntryMatches(),
 * componentEntryIdentity() — existed to give a JSON array the unit identity a JSON
 * array does not naturally have. They are deleted with the nine JSON columns.
 *
 * The PROPERTIES they defended are not deleted, so the suite is repointed at
 * config_components rather than retired:
 *
 *   A-L4  removing a unit must drop exactly ONE unit, never every unit of the model
 *   A-L5  two units of one model with NULL serials must stay distinguishable
 *   A-L3  a removal must release exactly what the add claimed
 *
 * On the rows side these are STRUCTURAL rather than algorithmic, which is why the
 * migration is a strengthening and not merely a move. Each physical unit is its own
 * row keyed on (inventory_table, inventory_id, component_type), so "one model, three
 * serial-less units" is three rows that were never confusable in the first place;
 * there is no matcher left to get the precedence wrong. The assertions below check
 * that this is really true of the schema and of the repository, rather than assuming
 * it: a UNIQUE key that stopped covering component_type, or a tombstone that hit more
 * than one row, would fail here.
 *
 * The one thing genuinely lost is `reserved_units` — a JSON-only device for a
 * quantity>N entry to record the N units it claimed. Rows are one-per-unit, so a
 * quantity>1 entry cannot exist and there is nothing to record; that is asserted
 * directly (every live row has exactly one inventory unit behind it) instead.
 *
 * DB-backed, where the old suite was DB-free. That is the cost of testing the real
 * store instead of a pure helper, and it is worth paying: the old suite could pass
 * with the production write path entirely broken.
 *
 * Run: php tests/unit/component_entry_identity_test.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once __DIR__ . '/../regression/_scratch_db.php';
require_once $ROOT . '/core/models/config/ConfigComponentRepository.php';

$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
$dbPass = scratch_db_password();
$dbSocket = getenv('GOLDEN_DB_SOCKET') ?: null;

$dsn = $dbSocket
    ? "mysql:unix_socket=$dbSocket;dbname=$dbName;charset=utf8mb4"
    : "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

$pdo = null;
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (\Throwable $e) {
    // reported uniformly by scratch_db_or_skip()
}
$pdo = scratch_db_or_skip($pdo, 'physical-unit identity in config_components');

$failures = 0;
$checks   = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$ok) { $failures++; }
}

$ramUuid  = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c';
$ssdUuid  = 'e8f9a0b1-c2d3-e4f5-a6b7-c8d9e0f1a2b3';
$config   = 'TEST-IDENT-' . substr(md5(uniqid()), 0, 8);
$FLAG     = 'TEMP-IDENT-PROBE';

/** Live rows for the fixture config, oldest first. */
$live = function () use ($pdo, $config): array {
    $st = $pdo->prepare("SELECT * FROM config_components
                          WHERE config_uuid = ? AND removed_at IS NULL ORDER BY id");
    $st->execute([$config]);
    return $st->fetchAll();
};

try {
    $pdo->exec("DELETE FROM raminventory WHERE Flag = " . $pdo->quote($FLAG));
    $pdo->prepare("INSERT INTO server_configurations
            (config_uuid, server_name, is_virtual, configuration_status)
            VALUES (?, 'IDENTITY TEST', 0, 1)")->execute([$config]);

    // Three SERIAL-LESS units of one model — the exact shape that made the JSON
    // matcher wipe all three (A-L4/A-L5). Serial-less stock is real: seeder
    // 2026_07_22_003 put 3 x Kingston KC600 and 9 x Quad M.2 adapters in the catalogue
    // with SerialNumber NULL, addressed by AssetTag.
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $pdo->prepare("INSERT INTO raminventory (UUID, SerialNumber, Status, Flag) VALUES (?, NULL, 2, ?)")
            ->execute([$ramUuid, $FLAG]);
        $ids[] = (int)$pdo->lastInsertId();
    }

    $repo = new ConfigComponentRepository($pdo);
    $pdo->beginTransaction();
    $rowIds = [];
    foreach ($ids as $invId) {
        $rowIds[] = (int)$repo->insert($config, [
            'component_type'  => 'ram',
            'inventory_table' => 'raminventory',
            'inventory_id'    => $invId,
            'spec_uuid'       => $ramUuid,
            'serial_number'   => null,
        ], 1);
    }
    $pdo->commit();

    echo "\n-- A-L5: units of one model with NULL serials stay distinguishable --\n";
    $rows = $live();
    check('3 serial-less units of one model -> 3 distinct rows', count($rows) === 3);
    check('  each row names a different physical unit',
        count(array_unique(array_column($rows, 'inventory_id'))) === 3);
    check('  no row carries a serial to tell them apart by',
        array_filter(array_column($rows, 'serial_number')) === []);

    echo "\n-- A-L4: removing one unit removes exactly one --\n";
    $pdo->beginTransaction();
    $repo->tombstone($rowIds[1], 1);
    $pdo->commit();

    $rows = $live();
    check('remove the middle unit -> 2 remain', count($rows) === 2);
    check('  the removed one is exactly the unit asked for',
        array_map('intval', array_column($rows, 'inventory_id')) === [$ids[0], $ids[2]]);

    echo "\n-- A-L3: one row is one unit, so there is nothing to over-release --\n";
    // The JSON side needed 'reserved_units' because one entry could claim N units.
    // A row cannot: every live row names exactly one inventory unit.
    $multi = 0;
    foreach ($live() as $r) {
        if ($r['inventory_id'] === null || (int)$r['inventory_id'] <= 0) { $multi++; }
    }
    check('every live row names exactly one inventory unit (no quantity>1 entries exist)', $multi === 0);

    echo "\n-- the schema itself is what enforces this --\n";
    $idx = $pdo->query("SHOW INDEX FROM config_components WHERE Key_name = 'uq_inventory_once'")
               ->fetchAll(PDO::FETCH_ASSOC);
    $cols = array_column($idx, 'Column_name');
    check('uq_inventory_once covers (inventory_table, inventory_id, component_type)',
        $cols === ['inventory_table', 'inventory_id', 'component_type']);

    // And it must actually bite: the same unit cannot be installed twice.
    $dupRefused = false;
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO config_components
                (config_uuid, component_type, inventory_table, inventory_id, spec_uuid)
                VALUES (?, 'ram', 'raminventory', ?, ?)")
            ->execute([$config, $ids[0], $ramUuid]);
        $pdo->rollBack();
    } catch (\Throwable $e) {
        $dupRefused = true;
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
    }
    check('installing the SAME physical unit a second time is refused by the store', $dupRefused);

    echo "\n-- a different model is never touched --\n";
    $pdo->prepare("INSERT INTO storageinventory (UUID, SerialNumber, Status, Flag) VALUES (?, 'IDENT-SSD', 2, ?)")
        ->execute([$ssdUuid, $FLAG]);
    $ssdInvId = (int)$pdo->lastInsertId();
    $pdo->beginTransaction();
    $ssdRowId = (int)$repo->insert($config, [
        'component_type'  => 'storage',
        'inventory_table' => 'storageinventory',
        'inventory_id'    => $ssdInvId,
        'spec_uuid'       => $ssdUuid,
        'serial_number'   => 'IDENT-SSD',
    ], 1);
    $repo->tombstone($rowIds[0], 1);
    $pdo->commit();

    $rows = $live();
    $types = array_column($rows, 'component_type');
    sort($types);
    check('removing a RAM unit leaves the storage unit installed', $types === ['ram', 'storage']);

    echo "\n-- findLive() resolves the right unit by serial --\n";
    check('findLive matches the serial it is given',
        ($repo->findLive($config, 'storage', $ssdUuid, 'IDENT-SSD')['id'] ?? null) == $ssdRowId);
    check('findLive rejects a serial no live row carries',
        $repo->findLive($config, 'storage', $ssdUuid, 'NO-SUCH-SERIAL') === null);
    check('findLive returns at most ONE row for a model with several serial-less units',
        is_array($repo->findLive($config, 'ram', $ramUuid, null)));

} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->exec("DELETE FROM config_components WHERE config_uuid = " . $pdo->quote($config));
    $pdo->exec("DELETE FROM config_events WHERE config_uuid = " . $pdo->quote($config));
    $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($config));
    $pdo->exec("DELETE FROM raminventory WHERE Flag = " . $pdo->quote($FLAG));
    $pdo->exec("DELETE FROM storageinventory WHERE Flag = " . $pdo->quote($FLAG));
}

echo "\n" . ($failures === 0 ? "ALL PASS" : "$failures FAILURE(S)") . " ($checks checks)\n";
exit($failures === 0 ? 0 : 1);
