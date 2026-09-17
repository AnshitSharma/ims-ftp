<?php
/**
 * Proof that SpecRepository returns what ComponentDataLoader::loadJSONData() returns.
 * (audit Phase 4 / roadmap item 17, second adapter after ComponentDataService)
 *
 * ComponentDataLoader is the file-loading half of ComponentCompatibility's pairwise checks
 * (getComponentData() = this JSON lookup merged with a DB row). Before loadJSONData()'s body
 * is replaced with a call to SpecRepository::find(), the two must be proven shape-identical --
 * same pattern as tests/spec_repository_equivalence.php, same reason: a changed shape here
 * shows up as a compatibility verdict silently flipping, not as an error.
 *
 * Run:  php ims-ftp/tests/component_data_loader_equivalence.php
 * Exit: 0 identical everywhere, 1 on any difference.
 *
 * Not deployed (tests/ never uploads). This is a pre-change gate, run by hand. No DB needed --
 * ComponentDataLoader is constructed with a null PDO since loadJSONData() never touches it.
 */

require_once __DIR__ . '/../core/models/components/ComponentSpecPaths.php';
require_once __DIR__ . '/../core/models/components/SpecProjector.php';
require_once __DIR__ . '/../core/models/components/SpecRepository.php';
require_once __DIR__ . '/../core/models/components/ComponentDataLoader.php';

$loader = new ComponentDataLoader(null, null);
$repo = SpecRepository::getInstance();

$types = array_keys(ComponentSpecPaths::getAll());
$checked = 0;
$differences = [];
$onlyLoader = 0;
$onlyRepo = 0;

foreach ($types as $type) {
    if ($type === 'serverplatform') {
        // ComponentDataLoader's JSON walk has no serverplatform branch -- it was written for
        // ComponentCompatibility's pairwise checks, which never take a platform as one side of
        // a pair. Confirmed out of scope by the class's own comment (getComponentData()).
        continue;
    }

    $records = SpecProjector::projectType($type, ComponentSpecPaths::getPath($type));

    foreach ($records as $record) {
        $uuid = $record['spec_uuid'];
        $checked++;

        $a = $loader->loadJSONData($type, $uuid);
        $b = $repo->find($type, $uuid);

        if ($a === null && $b === null) {
            continue;
        }
        if ($a === null) { $onlyRepo++;   $differences[] = [$type, $uuid, 'resolved by repository only']; continue; }
        if ($b === null) { $onlyLoader++; $differences[] = [$type, $uuid, 'resolved by loader only'];     continue; }

        ksort($a);
        ksort($b);

        if ($a !== $b) {
            $missing = array_diff(array_keys($a), array_keys($b));
            $extra   = array_diff(array_keys($b), array_keys($a));
            $changed = [];
            foreach ($a as $k => $v) {
                if (array_key_exists($k, $b) && $b[$k] !== $v) {
                    $changed[] = $k;
                }
            }
            $differences[] = [$type, $uuid, trim(
                ($missing ? 'missing[' . implode(',', $missing) . '] ' : '') .
                ($extra   ? 'extra['   . implode(',', $extra)   . '] ' : '') .
                ($changed ? 'changed[' . implode(',', $changed) . ']' : '')
            )];
        }
    }
}

printf("checked            %d model uuids across %d types (serverplatform excluded)\n", $checked, count($types) - 1);
printf("resolved by both   %d\n", $checked - $onlyLoader - $onlyRepo);
printf("differences        %d\n\n", count($differences));

if ($differences) {
    foreach (array_slice($differences, 0, 40) as $d) {
        printf("  %-16s %s  %s\n", $d[0], $d[1], $d[2]);
    }
    if (count($differences) > 40) {
        printf("  ... %d more\n", count($differences) - 40);
    }
    fwrite(STDERR, "\nFAIL: SpecRepository does not match ComponentDataLoader::loadJSONData(). Do not\n");
    fwrite(STDERR, "re-point it until this is 0.\n");
    exit(1);
}

echo "\nOK: SpecRepository is shape-identical to ComponentDataLoader::loadJSONData() on every model.\n";
exit(0);
