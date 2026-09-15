<?php
/**
 * Catalogue UUID uniqueness gate. (audit JSON-005)
 *
 * A model's UUID is its ONLY identity and the sole join key between ims-data and every
 * {type}inventory row. Nothing in the repository checked it for uniqueness -- not a test,
 * not a load-time assertion -- and the UUIDs are hand-typed and visibly patterned
 * (b2c3d4e5-f6a7-..., c1d2e3f4-a5b6-...), which is exactly how collisions arise.
 *
 * WHY THE DUPLICATES MATTER
 *   ComponentDataService::buildUuidIndex() keeps the LAST writer for a repeated UUID. The
 *   earlier model becomes unreachable: any inventory row stocked as it silently validates
 *   against the other model's compatibility envelope. There is no error anywhere.
 *
 * SCOPE IS GLOBAL, NOT PER TYPE
 *   Lookups are type-scoped today, so a motherboard and an SSD sharing a UUID is currently
 *   harmless -- but "currently harmless" is a property of today's call sites, not of the
 *   data. The gate treats the catalogue as one namespace.
 *
 * Run:  php ims-ftp/tests/catalogue_uuid_gate.php
 * Exit: 0 clean, 1 unexpected duplicates found.
 *
 * This file is NOT deployed (tests/ is never uploaded) -- it is a pre-upload check.
 */

require_once __DIR__ . '/../core/models/components/ComponentSpecPaths.php';

/**
 * Duplicates that are DELIBERATE and must not fail the gate.
 *
 * Each entry is a UUID mapped to why it legitimately appears more than once. These are
 * modelling, not mistakes: a shipped platform embeds its own board and chassis objects, and
 * several platform versions are built on the same board, so that board's UUID appears once
 * per version by design. Same for a platform's included storage controller reusing the HBA
 * catalogue entry.
 *
 * Add to this list ONLY with a reason. An unexplained duplicate is a bug by default.
 */
const ALLOWED_DUPLICATES = [
    // -- One physical object described in two catalogues -------------------------------
    // A shipped platform embeds its own system_board / chassis / storage-controller
    // objects. They are the SAME object as the loose catalogue entry and must resolve to
    // the same UUID, because that is what server_configurations stamps.
    'c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f' => 'Hitachi S5B-MB 2U board: motherboard file + serverplatform 22 system_board',
    '0e9d4c37-6a5b-4f28-b1c9-3d7e8a204f56' => 'Dell PERC H745: hbacard file + 3 platform versions included_storage_controller',
    '4981e5a2-74b5-46ed-ac9d-7f9bbfdbc6d5' => 'Hitachi DS220 chassis: chassis file + serverplatform 22 chassis',

    // -- One board shared by several versions of one platform --------------------------
    // A platform's versions differ by drive layout, not by board, so the board object is
    // repeated once per version with the same UUID. Deliberate; see PlatformSpecIndex.
    '808f6570-aa64-5320-be8d-36fd30f10eba' => 'HPE DL360 Gen9 board, 2 versions',
    '9faa71a2-1b67-5847-9472-fd98bb267234' => 'HPE DL380 Gen10 board, 3 versions',
    '42f4d03f-a4b5-5b8c-b27e-cb2d4820524e' => 'HPE DL325 Gen10 Plus v2 board, 2 versions',
    'bbb50741-e80e-56dc-bb93-c088eff7796d' => 'Dell R630 board, 2 versions',
    '9325d0ba-7e58-5f87-8bde-b7dd79a21602' => 'Dell R740 board, 3 versions',
    '65873034-3ca9-5c35-8d48-2a8350301a9e' => 'Dell R6525 board, 2 versions',

    // -- REPAIRED 2026-09-16, kept here as history, NOT as permission ------------------
    // 'b2c3d4e5-...' was a GIGABYTE B550M DS3H motherboard AND a MiPHi MPSSDAI1002TB SSD.
    //   Two live storageinventory rows (ids 157, 158) carry it and resolve correctly, so
    //   the SSD kept the UUID; motherboardinventory was empty, so the board was renumbered
    //   to c48f2973-bead-4ad5-af4d-56cc15efcceb.
    // 'd7f1a3b5-...' was "Slim 2.5-inch SSD Caddy" AND "Generic Test Caddy 2" in one file.
    //   buildUuidIndex() keeps the last writer, so the real product was unreachable behind
    //   the test fixture. caddyinventory was empty, so the TEST caddy was renumbered to
    //   cf5f8847-fe08-4f25-92b6-f0e44c791695 and the Slim caddy is reachable again.
];

$paths = ComponentSpecPaths::getAll();

/** @var array<string, array<int, string>> uuid => list of "type:path" occurrences */
$seen = [];

/** Recursively collect every uuid/UUID key with a readable path to it. */
function collect(array $node, string $type, string $trail, array &$seen): void
{
    foreach ($node as $key => $value) {
        $here = $trail === '' ? (string)$key : $trail . '.' . $key;

        if (($key === 'uuid' || $key === 'UUID') && is_string($value) && $value !== '') {
            // Name the thing that owns the uuid, so the report is actionable.
            $label = $node['model'] ?? $node['name'] ?? $node['model_name']
                ?? $node['product_name'] ?? $node['version'] ?? null;
            $brand = $node['brand'] ?? $node['manufacturer'] ?? null;
            $desc = trim(($brand ? $brand . ' ' : '') . ($label ?? ''));
            $seen[$value][] = $type . '  ' . $trail . ($desc !== '' ? "  \"$desc\"" : '');
            continue;
        }

        if (is_array($value)) {
            collect($value, $type, $here, $seen);
        }
    }
}

$fileCount = 0;
foreach ($paths as $type => $path) {
    if (!is_readable($path)) {
        fwrite(STDERR, "MISSING: $type -> $path\n");
        continue;
    }
    $blob = json_decode(file_get_contents($path), true);
    if (!is_array($blob)) {
        fwrite(STDERR, "UNPARSEABLE: $type -> $path\n");
        exit(1);
    }
    $fileCount++;
    collect($blob, $type, '', $seen);
}

$duplicates = array_filter($seen, static function (array $occurrences) {
    return count($occurrences) > 1;
});

$unexpected = array_diff_key($duplicates, array_flip(array_keys(ALLOWED_DUPLICATES)));

printf("files scanned:      %d\n", $fileCount);
printf("distinct uuids:     %d\n", count($seen));
printf("total occurrences:  %d\n", array_sum(array_map('count', $seen)));
printf("duplicated uuids:   %d  (%d allowed, %d unexpected)\n\n",
    count($duplicates), count($duplicates) - count($unexpected), count($unexpected));

if ($unexpected) {
    // Cross-type collisions first: those are the ones that become live bugs the moment
    // any lookup stops being type-scoped.
    uasort($unexpected, static function ($a, $b) {
        $typesA = count(array_unique(array_map(static function ($o) { return strtok($o, ' '); }, $a)));
        $typesB = count(array_unique(array_map(static function ($o) { return strtok($o, ' '); }, $b)));
        return $typesB <=> $typesA;
    });

    foreach ($unexpected as $uuid => $occurrences) {
        $types = array_unique(array_map(static function ($o) { return strtok($o, ' '); }, $occurrences));
        $flag = count($types) > 1 ? '  [CROSS-TYPE]' : '';
        echo "DUPLICATE $uuid$flag\n";
        foreach ($occurrences as $o) {
            echo "    $o\n";
        }
        echo "\n";
    }

    fwrite(STDERR, sprintf("FAIL: %d unexpected duplicate uuid(s)\n", count($unexpected)));
    exit(1);
}

echo "OK: every uuid in the catalogue is unique (or explicitly allowed).\n";
exit(0);
