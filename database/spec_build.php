<?php
/**
 * spec_build -- project ims-data into component_models. (audit Phase 2.2)
 *
 *     php ims-ftp/database/spec_build.php            # build
 *     php ims-ftp/database/spec_build.php --check    # report drift, change nothing
 *     php ims-ftp/database/spec_build.php --verbose  # name every row that changes
 *
 * RUN THIS AFTER EVERY ims-data UPLOAD. ims-data has no deploy watcher -- specs are uploaded
 * by hand -- so there is no hook to hang an automatic build on, and a build that ran on a
 * timer would be a build nobody watched. `--check` exits 1 when the table disagrees with the
 * files, which is the form to put in a cron or a dashboard warning.
 *
 * WHAT IT DOES
 *   validate -> gate on uuid uniqueness -> checksum -> upsert -> retire the vanished.
 *
 * The uuid gate is here rather than only in tests/ because tests/ never deploys. A duplicate
 * uuid is not a style problem: ComponentDataService::buildUuidIndex() keeps the LAST writer,
 * so the earlier model silently becomes unreachable and any inventory stocked as it validates
 * against the wrong compatibility envelope. Two such collisions were live until 2026-09-16.
 * The build REFUSES on one rather than writing a projection it knows is wrong -- with the
 * UNIQUE key on spec_uuid it could not write both rows anyway, and failing loudly beats
 * failing on an INSERT with a driver-level message.
 *
 * NOTHING IS DELETED. A model that has left the catalogue is flagged is_retired=1: inventory
 * rows may still point at it, and a dangling join is a worse outcome than a flagged row.
 * A retired model that reappears is un-retired.
 *
 * SAFE TO RUN REPEATEDLY, and safe to run before the seeder: it detects the missing table and
 * says which file to apply instead of fataling. Code reaches production about twenty seconds
 * after save and seeders are applied by hand afterwards, so that window is normal here.
 */

if (PHP_SAPI !== 'cli') {
    // This is a maintenance script with no authentication of its own. It lives under the
    // deployed tree because it has to run on the server, so it refuses the web explicitly.
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../core/config/app.php';
require_once __DIR__ . '/../core/models/components/SpecProjector.php';

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);
$checkOnly = in_array('--check', $argv, true);

$pdo = getDatabase();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "No database connection.\n");
    exit(2);
}

// ---------------------------------------------------------------------------------------
// 0. The table has to exist. It ships as a seeder, and seeders are hand-run after the code
//    that references them has already deployed, so "not yet" is an expected state.
// ---------------------------------------------------------------------------------------
try {
    $pdo->query('SELECT 1 FROM component_models LIMIT 1');
} catch (PDOException $e) {
    fwrite(STDERR, "component_models does not exist yet.\n");
    fwrite(STDERR, "Apply this first, then re-run:\n");
    fwrite(STDERR, "    ims-ftp/database/seeders/2026_09_16_002_component-models.sql\n");
    exit(2);
}

// ---------------------------------------------------------------------------------------
// 1. Project the files.
// ---------------------------------------------------------------------------------------
try {
    $projected = SpecProjector::projectAll();
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Projection failed: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Nothing was written.\n");
    exit(1);
}

$records = [];
foreach ($projected as $type => $rows) {
    foreach ($rows as $row) {
        $records[] = $row;
    }
}
printf("projected  %d models from %d files\n", count($records), count($projected));

// ---------------------------------------------------------------------------------------
// 2. Uniqueness gate. The catalogue is ONE namespace: lookups are type-scoped today, but
//    that is a property of today's call sites, not of the data.
// ---------------------------------------------------------------------------------------
$byUuid = [];
foreach ($records as $r) {
    $byUuid[$r['spec_uuid']][] = $r;
}

$collisions = array_filter($byUuid, static function ($group) {
    return count($group) > 1;
});

if ($collisions) {
    fwrite(STDERR, sprintf("\nREFUSING TO BUILD: %d duplicate uuid(s).\n\n", count($collisions)));
    foreach ($collisions as $uuid => $group) {
        $types = array_unique(array_column($group, 'component_type'));
        fwrite(STDERR, "  $uuid" . (count($types) > 1 ? '  [CROSS-TYPE]' : '') . "\n");
        foreach ($group as $r) {
            fwrite(STDERR, sprintf("      %-14s %-46s %s\n",
                $r['component_type'], substr($r['display_name'], 0, 46), $r['source_file']));
        }
    }
    fwrite(STDERR, "\nOne of them is unreachable at runtime. Renumber the record with no inventory\n");
    fwrite(STDERR, "behind it -- check with: SELECT COUNT(*) FROM {type}inventory WHERE UUID = '...'\n");
    fwrite(STDERR, "Nothing was written.\n");
    exit(1);
}
printf("gate       %d uuids, all unique\n", count($byUuid));

// ---------------------------------------------------------------------------------------
// 3. Validate against the schemas, when they are present. The schemas ship as files in
//    ims-data, which has no watcher, so treat their absence as "not uploaded yet" rather
//    than as a reason to refuse a build that would otherwise be correct.
// ---------------------------------------------------------------------------------------
$validatorPath = __DIR__ . '/../core/models/components/SpecValidator.php';
$violations = [];
if (is_readable($validatorPath)) {
    require_once $validatorPath;
    $violations = SpecValidator::validateAll($projected);
    if ($violations === null) {
        printf("schemas    not present -- skipped\n");
        $violations = [];
    } else {
        printf("schemas    %d record(s) with violations\n", count($violations));
        foreach (array_slice($violations, 0, $verbose ? PHP_INT_MAX : 10) as $v) {
            printf("             %-14s %-40s %s\n",
                $v['component_type'], substr($v['display_name'], 0, 40), $v['message']);
        }
        if (!$verbose && count($violations) > 10) {
            printf("             ... %d more (--verbose for all)\n", count($violations) - 10);
        }
    }
} else {
    printf("schemas    validator not deployed yet -- skipped\n");
}

// A violation warns but does not block. The corpus has known inconsistencies the audit
// catalogued (width as string/float/int, low_profile as bool/int, slot as name/index); making
// the build refuse on them would mean the table can never be built until every one is
// reconciled, and the table is what makes reconciling them tractable. Revisit once clean.

// ---------------------------------------------------------------------------------------
// 4. Diff against the table.
// ---------------------------------------------------------------------------------------
$existing = [];
$stmt = $pdo->query('SELECT spec_uuid, source_checksum, is_retired FROM component_models');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existing[$row['spec_uuid']] = $row;
}
printf("table      %d existing row(s)\n", count($existing));

$toInsert = [];
$toUpdate = [];
$seen = [];

foreach ($records as $r) {
    $r['source_checksum'] = SpecProjector::checksum($r['specs']);
    $seen[$r['spec_uuid']] = true;

    if (!isset($existing[$r['spec_uuid']])) {
        $toInsert[] = $r;
        continue;
    }

    $prior = $existing[$r['spec_uuid']];
    if ($prior['source_checksum'] !== $r['source_checksum'] || (int)$prior['is_retired'] === 1) {
        $toUpdate[] = $r;
    }
}

$toRetire = array_values(array_filter(array_keys($existing), static function ($uuid) use ($seen, $existing) {
    return !isset($seen[$uuid]) && (int)$existing[$uuid]['is_retired'] === 0;
}));

printf("\nchanges    +%d new   ~%d changed   -%d retired   =%d unchanged\n",
    count($toInsert), count($toUpdate), count($toRetire),
    count($records) - count($toInsert) - count($toUpdate));

if ($verbose) {
    foreach ($toInsert as $r) printf("  +  %-14s %s\n", $r['component_type'], $r['display_name']);
    foreach ($toUpdate as $r) printf("  ~  %-14s %s\n", $r['component_type'], $r['display_name']);
    foreach ($toRetire as $u)  printf("  -  %s\n", $u);
}

if ($checkOnly) {
    $drift = count($toInsert) + count($toUpdate) + count($toRetire);
    if ($drift > 0) {
        printf("\nSTALE: the table disagrees with the files in %d place(s). Run without --check.\n", $drift);
        exit(1);
    }
    printf("\nOK: component_models matches ims-data.\n");
    exit(0);
}

if (!$toInsert && !$toUpdate && !$toRetire) {
    printf("\nOK: nothing to do.\n");
    exit(0);
}

// ---------------------------------------------------------------------------------------
// 5. Write. One transaction: a half-built projection is worse than an old one, because
//    nothing downstream can tell the difference.
// ---------------------------------------------------------------------------------------
$columns = ['spec_uuid', 'component_type', 'model_name', 'display_name', 'brand', 'series',
    'part_number', 'capacity_gb', 'socket', 'form_factor', 'interface', 'memory_type',
    'tdp_w', 'ports', 'specs', 'source_checksum', 'source_file'];

$placeholders = implode(', ', array_fill(0, count($columns), '?'));
$updates = [];
foreach ($columns as $c) {
    if ($c === 'spec_uuid') continue;
    $updates[] = "$c = VALUES($c)";
}
$updates[] = 'is_retired = 0';

$upsert = $pdo->prepare(
    'INSERT INTO component_models (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ') ' .
    'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
);
$retire = $pdo->prepare('UPDATE component_models SET is_retired = 1 WHERE spec_uuid = ?');

$pdo->beginTransaction();
try {
    foreach (array_merge($toInsert, $toUpdate) as $r) {
        $upsert->execute([
            $r['spec_uuid'], $r['component_type'], $r['model_name'], $r['display_name'],
            $r['brand'], $r['series'], $r['part_number'], $r['capacity_gb'], $r['socket'],
            $r['form_factor'], $r['interface'], $r['memory_type'], $r['tdp_w'], $r['ports'],
            json_encode($r['specs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $r['source_checksum'], $r['source_file'],
        ]);
    }
    foreach ($toRetire as $uuid) {
        $retire->execute([$uuid]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nWrite failed, rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

printf("\nOK: component_models is current (%d live models).\n", count($records));
exit(0);
