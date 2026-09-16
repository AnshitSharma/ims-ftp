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
 * THIS IS NOT THE ONLY ENTRY POINT. This deployment has no shell, which made a CLI-only
 * script unrunnable by the person who does the uploads. The same pipeline is reachable as the
 * admin-only `spec-build` API action; both drive SpecBuildRunner, so there is one
 * implementation of the gate, the diff and the write. See core/models/components/SpecBuildRunner.php.
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
 * rows keep resolving, and the row comes back if the model returns.
 *
 * SAFE TO RUN REPEATEDLY, and safe to run before the seeder: it detects the missing table and
 * says which file to apply instead of fataling. Code reaches production about twenty seconds
 * after save and seeders are applied by hand afterwards, so that window is normal here.
 */

if (PHP_SAPI !== 'cli') {
    // This is a maintenance script with no authentication of its own. It lives under the
    // deployed tree because it has to run on the server, so it refuses the web explicitly.
    // The authenticated way in is the spec-build API action.
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../core/config/app.php';
require_once __DIR__ . '/../core/models/components/SpecProjector.php';
require_once __DIR__ . '/../core/models/components/SpecBuildRunner.php';

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);
$checkOnly = in_array('--check', $argv, true);

$pdo = getDatabase();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "No database connection.\n");
    exit(2);
}

$r = SpecBuildRunner::run($pdo, $checkOnly);

if ($r['status'] === 'missing_table') {
    fwrite(STDERR, $r['message'] . "\n");
    exit(2);
}

if ($r['status'] === 'projection_failed') {
    fwrite(STDERR, $r['message'] . "\n");
    exit(1);
}

printf("projected  %d models from %d files\n", $r['projected'], $r['files']);

if ($r['status'] === 'refused') {
    fwrite(STDERR, sprintf("\nREFUSING TO BUILD: %d duplicate uuid(s).\n\n", count($r['collisions'])));
    foreach ($r['collisions'] as $c) {
        fwrite(STDERR, '  ' . $c['uuid'] . ($c['cross_type'] ? '  [CROSS-TYPE]' : '') . "\n");
        foreach ($c['records'] as $rec) {
            fwrite(STDERR, sprintf("      %-14s %-46s %s\n",
                $rec['component_type'], substr($rec['display_name'], 0, 46), $rec['source_file']));
        }
    }
    fwrite(STDERR, "\nOne of them is unreachable at runtime. Renumber the record with no inventory\n");
    fwrite(STDERR, "behind it -- check with: SELECT COUNT(*) FROM {type}inventory WHERE UUID = '...'\n");
    fwrite(STDERR, "Nothing was written.\n");
    exit(1);
}

printf("gate       %d uuids, all unique\n", $r['uuids']);

if ($r['violations'] === null) {
    printf("schemas    not present -- skipped\n");
} else {
    printf("schemas    %d record(s) with violations\n", count($r['violations']));
    foreach (array_slice($r['violations'], 0, $verbose ? PHP_INT_MAX : 10) as $v) {
        printf("             %-14s %-40s %s\n",
            $v['component_type'], substr($v['display_name'], 0, 40), $v['message']);
    }
    if (!$verbose && count($r['violations']) > 10) {
        printf("             ... %d more (--verbose for all)\n", count($r['violations']) - 10);
    }
}

// A violation warns but does not block. The corpus has known inconsistencies the audit
// catalogued (width as string/float/int, low_profile as bool/int, slot as name/index); making
// the build refuse on them would mean the table can never be built until every one is
// reconciled, and the table is what makes reconciling them tractable. Revisit once clean.

printf("table      %d existing row(s)\n", $r['existing']);
printf("\nchanges    +%d new   ~%d changed   -%d retired   =%d unchanged\n",
    $r['changes']['new'], $r['changes']['changed'], $r['changes']['retired'], $r['changes']['unchanged']);

if ($verbose) {
    foreach ($r['detail']['new'] as $l)     printf("  +  %s\n", $l);
    foreach ($r['detail']['changed'] as $l) printf("  ~  %s\n", $l);
    foreach ($r['detail']['retired'] as $u) printf("  -  %s\n", $u);
}

if ($r['status'] === 'write_failed') {
    fwrite(STDERR, "\n" . $r['message'] . "\n");
    exit(1);
}

printf("\n%s\n", $r['message']);
exit($r['status'] === 'stale' ? 1 : 0);
