<?php
// Pre-upload check for the cable catalogue (IMS 1.1, tasks/cable-inventory.md): parse, project with the real
// SpecProjector, validate with the real SpecValidator (schemas/cable.schema.json),
// and enforce the change list's rules the schema cannot express.
//
// Run:  php ims-ftp/tests/cable_catalogue_check.php [path]   (default: ims-data/cable/cable-level-3.json)
// Exit: 0 clean, 1 problems found. Not deployed (tests/ is never uploaded).
$root = __DIR__ . '/../core/models/components';
require_once "$root/SpecProjector.php";
require_once "$root/SpecValidator.php";

$path = $argv[1] ?? __DIR__ . '/../../ims-data/cable/cable-level-3.json';
$raw = @file_get_contents($path);
if ($raw === false) { fwrite(STDERR, "cannot read $path\n"); exit(2); }
json_decode($raw);
echo "json: ", json_last_error_msg(), PHP_EOL;
if (json_last_error() !== JSON_ERROR_NONE) exit(1);

$records = SpecProjector::projectType('cable', $path);
echo "models projected: ", count($records), PHP_EOL;
foreach ($records as $r) {
    printf("  %s  %-40s  brand=%s series=%s\n", $r['spec_uuid'], $r['display_name'], $r['brand'] ?? '-', $r['series'] ?? '-');
}

$problems = [];
foreach (SpecValidator::validateAll(['cable' => $records]) ?? [['message' => 'NO SCHEMAS LOADED', 'spec_uuid' => '-']] as $v) {
    $problems[] = "schema  {$v['spec_uuid']}: {$v['message']}";
}

// Rules the schema can't say.
$seen = [];
foreach ($records as $r) {
    $s = $r['specs']; $u = $r['spec_uuid'];
    if (isset($seen[$u])) $problems[] = "rule    $u: duplicate uuid";
    $seen[$u] = true;
    $medium = $s['medium'] ?? null;
    if ($medium === 'fibre') {
        foreach (['fibre_mode', 'fibre_grade'] as $k) if (!isset($s[$k])) $problems[] = "rule    $u: fibre cable without $k";
        if (isset($s['copper_category']) || isset($s['shielding'])) $problems[] = "rule    $u: fibre cable carries copper fields";
        $g = $s['fibre_grade'] ?? ''; $m = $s['fibre_mode'] ?? '';
        if (($m === 'SMF' && strpos($g, 'OS') !== 0) || ($m === 'MMF' && strpos($g, 'OM') !== 0)) $problems[] = "rule    $u: fibre_mode $m does not match grade $g";
        foreach (['connector_a', 'connector_b'] as $k) if (($s[$k] ?? '') === 'RJ45') $problems[] = "rule    $u: fibre cable with an RJ45 $k";
    } elseif ($medium === 'copper') {
        if (!isset($s['copper_category'])) $problems[] = "rule    $u: copper cable without copper_category";
        foreach (['fibre_mode', 'fibre_grade', 'fibre_count', 'polish'] as $k) if (isset($s[$k])) $problems[] = "rule    $u: copper cable carries $k";
        foreach (['connector_a', 'connector_b'] as $k) if (($s[$k] ?? '') !== 'RJ45') $problems[] = "rule    $u: copper cable with a non-RJ45 $k";
    }
    foreach (['connector_a_gender', 'connector_b_gender', 'mpo_polarity'] as $k) {
        if (isset($s[$k]) && strpos(($s['connector_a'] ?? '') . ($s['connector_b'] ?? ''), 'MPO') === false) $problems[] = "rule    $u: $k on a non-MPO cable";
    }
}

echo $problems ? "PROBLEMS (" . count($problems) . "):\n  " . implode("\n  ", $problems) . "\n" : "clean: no schema violations, no rule problems\n";
exit($problems ? 1 : 0);
