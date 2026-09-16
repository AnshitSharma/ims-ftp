<?php
/**
 * Proof that SpecRepository returns what ComponentDataService returns. (audit Phase 2.4)
 *
 * The collapse in JSON-003 only works if the four surviving resolvers can become thin
 * adapters over SpecRepository. An adapter that returns a different shape is not an adapter,
 * it is a rewrite of every call site -- and the call sites here are the 23 validation rules,
 * where a changed shape shows up as a compatibility verdict silently flipping rather than as
 * an error. So the shape is proven identical BEFORE anything is re-pointed.
 *
 * Run:  php ims-ftp/tests/spec_repository_equivalence.php
 * Exit: 0 identical everywhere, 1 on any difference.
 *
 * Not deployed (tests/ never uploads). This is a pre-change gate, run by hand.
 */

require_once __DIR__ . '/../core/models/components/ComponentSpecPaths.php';
require_once __DIR__ . '/../core/models/components/SpecProjector.php';
require_once __DIR__ . '/../core/models/components/SpecRepository.php';
require_once __DIR__ . '/../core/models/components/ComponentDataService.php';

$repo = SpecRepository::getInstance();
$legacy = ComponentDataService::getInstance();

$types = array_keys(ComponentSpecPaths::getAll());
$checked = 0;
$differences = [];
$onlyLegacy = 0;
$onlyRepo = 0;

foreach ($types as $type) {
    $records = SpecProjector::projectType($type, ComponentSpecPaths::getPath($type));

    foreach ($records as $record) {
        $uuid = $record['spec_uuid'];
        $checked++;

        $a = $legacy->findComponentByUuid($type, $uuid);
        $b = $repo->find($type, $uuid);

        if ($a === null && $b === null) {
            continue;
        }
        if ($a === null) { $onlyRepo++;   $differences[] = [$type, $uuid, 'resolved by repository only']; continue; }
        if ($b === null) { $onlyLegacy++; $differences[] = [$type, $uuid, 'resolved by legacy only'];     continue; }

        // Key order is not part of the contract -- every consumer reads by name.
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

printf("checked            %d model uuids across %d types\n", $checked, count($types));
printf("resolved by both   %d\n", $checked - $onlyLegacy - $onlyRepo);
printf("differences        %d\n\n", count($differences));

if ($differences) {
    foreach (array_slice($differences, 0, 40) as $d) {
        printf("  %-16s %s  %s\n", $d[0], $d[1], $d[2]);
    }
    if (count($differences) > 40) {
        printf("  ... %d more\n", count($differences) - 40);
    }
    fwrite(STDERR, "\nFAIL: SpecRepository does not match ComponentDataService. Do not re-point any\n");
    fwrite(STDERR, "call site until this is 0.\n");
    exit(1);
}

// A platform-owned board must resolve under 'motherboard' through BOTH. This is the case the
// PlatformSpecIndex outage was about, so it is asserted explicitly rather than left to the
// sweep above -- those uuids are not in the motherboard file at all.
$platformBoards = 0;
$platformOk = 0;
$platformSpecs = SpecProjector::projectType('serverplatform', ComponentSpecPaths::getPath('serverplatform'));
foreach ($platformSpecs as $p) {
    foreach (['system_board' => 'motherboard', 'chassis' => 'chassis'] as $key => $asType) {
        $sub = $p['specs'][$key] ?? null;
        $subUuid = is_array($sub) ? ($sub['uuid'] ?? $sub['UUID'] ?? null) : null;
        if (!$subUuid) {
            continue;
        }
        $platformBoards++;
        if ($repo->find($asType, $subUuid) !== null && $legacy->findComponentByUuid($asType, $subUuid) !== null) {
            $platformOk++;
        } else {
            printf("  platform-owned %s %s resolves through only one path\n", $asType, $subUuid);
        }
    }
}
printf("platform-owned     %d/%d board+chassis specs resolve through both\n", $platformOk, $platformBoards);

if ($platformOk !== $platformBoards) {
    fwrite(STDERR, "FAIL: platform-owned specs do not resolve identically.\n");
    exit(1);
}

echo "\nOK: SpecRepository is shape-identical to ComponentDataService on every model.\n";
exit(0);
