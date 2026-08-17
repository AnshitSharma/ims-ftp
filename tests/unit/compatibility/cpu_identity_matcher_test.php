<?php
/**
 * Unit test — CPU-to-CPU SKU pairing (CpuIdentityMatcher).
 *
 * DB-free: reads ims-data/cpu/Cpu-details-level-3.json directly and merges the
 * group-level fields down onto each model exactly as
 * DataExtractionUtilities::findInBrandModels() does. No PDO, no config rows.
 *
 * The rule being pinned down: matching socket is necessary but NOT sufficient.
 * Once socket 1 is populated, CPU #1 constrains every remaining socket.
 *
 *   IDENTICAL  Gold 6338  + Gold 6338   the normal matched-pair 2S build
 *   VARIANT    Gold 6338  + Gold 6338N  same base SKU, different line suffix -> WARN
 *   MISMATCH   Gold 6338  + Gold 6342   both LGA4189 Ice Lake-SP -> still BLOCKED
 *
 * Consequences this test locks out:
 *   - the pre-2026-08-14 behaviour, where validateCPUAddition() checked the CPU
 *     against the motherboard only and never against the CPUs already installed,
 *     so 6338 + 6342 was accepted
 *   - a parser regression on any of the six model-naming shapes in ims-data
 *   - the warning tier silently collapsing into the mismatch tier: suffix variants
 *     legitimately differ on memory speed (6338 DDR4-3200 vs 6338N DDR4-2666) and
 *     core count (6338T is 24C), so those must NOT be treated as blocking
 *   - an unparseable model name being waved through instead of failing closed
 *
 * Run: php tests/unit/compatibility/cpu_identity_matcher_test.php
 */

require_once __DIR__ . '/../../../core/models/compatibility/CpuIdentityMatcher.php';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  PASS  $label\n";
    } else {
        $failures++;
        echo "  FAIL  $label\n";
    }
}

$specFile = __DIR__ . '/../../../../ims-data/cpu/Cpu-details-level-3.json';
if (!is_readable($specFile)) {
    fwrite(STDERR, "Cannot read CPU specs at $specFile\n");
    exit(2);
}
$groups = json_decode(file_get_contents($specFile), true);
if (!is_array($groups)) {
    fwrite(STDERR, "CPU spec file is not valid JSON\n");
    exit(2);
}

// Mirror findInBrandModels(): brand/series/family are merged down onto the model.
$byModel = [];
foreach ($groups as $group) {
    foreach ($group['models'] ?? [] as $model) {
        foreach (['brand', 'series', 'family'] as $field) {
            if (isset($group[$field]) && !isset($model[$field])) {
                $model[$field] = $group[$field];
            }
        }
        $byModel[$model['model']] = $model;
    }
}

$matcher = new CpuIdentityMatcher();

echo "\nParsing — every naming shape present in ims-data:\n";
$parseCases = [
    // model             tier        base     suffix  version
    ['Gold 6338',        'gold',     '6338',  '',     ''],
    ['Gold 6338N',       'gold',     '6338',  'N',    ''],
    ['Gold 6342',        'gold',     '6342',  '',     ''],
    ['Platinum 8480+',   'platinum', '8480',  '+',    ''],
    ['Platinum 8173M',   'platinum', '8173',  'M',    ''],
    ['E5-2680 v3',       'e5',       '2680',  '',     'v3'],
    ['E5-2680 v4',       'e5',       '2680',  '',     'v4'],
    ['EPYC 9374F',       'epyc',     '9374',  'F',    ''],
    ['Ryzen 9 5900XT',   'ryzen9',   '5900',  'XT',   ''],
    ['Core i5-12500',    'corei5',   '12500', '',     ''],
];
foreach ($parseCases as $case) {
    list($model, $tier, $base, $suffix, $version) = $case;
    $r = $matcher->parse($model);
    check(sprintf('%-16s -> %s/%s/%s%s', $model, $tier, $base,
            $suffix !== '' ? $suffix : '-', $version !== '' ? '/' . $version : ''),
        $r['parsed'] && $r['tier'] === $tier && $r['base_sku'] === $base
        && $r['suffix'] === $suffix && $r['version'] === $version);
}

echo "\nParsing — fails closed on unusable input:\n";
foreach (['', '   ', 'Unknown CPU', 'Xeon', 'A9', null] as $bad) {
    $r = $matcher->parse($bad);
    check('rejects ' . var_export($bad, true), $r['parsed'] === false);
}

echo "\nPairing verdicts:\n";
$pairCases = [
    ['Gold 6338',      'Gold 6338',      CpuIdentityMatcher::VERDICT_IDENTICAL, 'matched pair'],
    ['Gold 6338',      'Gold 6338N',     CpuIdentityMatcher::VERDICT_VARIANT,   'suffix variant'],
    ['Gold 6338N',     'Gold 6338',      CpuIdentityMatcher::VERDICT_VARIANT,   'suffix variant, reversed'],
    ['Gold 6338',      'Gold 6342',      CpuIdentityMatcher::VERDICT_MISMATCH,  'same socket, different SKU'],
    ['Gold 6338N',     'Gold 6342',      CpuIdentityMatcher::VERDICT_MISMATCH,  'variant vs different SKU'],
    ['Gold 6338',      'Gold 6149',      CpuIdentityMatcher::VERDICT_MISMATCH,  'same tier, different generation'],
    ['Gold 6338',      'Platinum 8168',  CpuIdentityMatcher::VERDICT_MISMATCH,  'different tier'],
    ['Gold 6338',      'EPYC 7763',      CpuIdentityMatcher::VERDICT_MISMATCH,  'cross-vendor'],
    ['E5-2680 v3',     'E5-2680 v3',     CpuIdentityMatcher::VERDICT_IDENTICAL, 'E5 matched pair'],
    ['E5-2680 v3',     'E5-2680 v4',     CpuIdentityMatcher::VERDICT_MISMATCH,  'same number, different gen'],
    ['E5-2680 v4',     'E5-2683 v4',     CpuIdentityMatcher::VERDICT_MISMATCH,  'same gen, different SKU'],
    ['EPYC 9534',      'EPYC 9374F',     CpuIdentityMatcher::VERDICT_MISMATCH,  'EPYC different base'],
    ['Ryzen 9 5900XT', 'Ryzen 9 5950X',  CpuIdentityMatcher::VERDICT_MISMATCH,  'Ryzen different base'],
];
foreach ($pairCases as $case) {
    list($a, $b, $want, $note) = $case;
    if (!isset($byModel[$a]) || !isset($byModel[$b])) {
        check("$a + $b ($note) — spec present", false);
        continue;
    }
    $r = $matcher->compare($byModel[$a], $byModel[$b]);
    check(sprintf('%-14s + %-14s -> %-9s (%s)', $a, $b, $r['verdict'], $note), $r['verdict'] === $want);
}

echo "\nVerdict payloads carry the right channel:\n";
$identical = $matcher->compare($byModel['Gold 6338'], $byModel['Gold 6338']);
check('identical is compatible, no warning, no error',
    $identical['compatible'] === true && $identical['warning'] === null && $identical['error'] === null);

$variant = $matcher->compare($byModel['Gold 6338'], $byModel['Gold 6338N']);
check('variant is compatible but carries a warning',
    $variant['compatible'] === true && !empty($variant['warning']) && $variant['error'] === null);
check('variant warning names the shared base SKU',
    strpos($variant['warning'], '6338') !== false);
check('variant reports the memory-speed divergence',
    !empty($variant['details']['divergences'])
    && strpos(implode(' ', $variant['details']['divergences']), 'memory support') !== false);

$mismatch = $matcher->compare($byModel['Gold 6338'], $byModel['Gold 6342']);
check('mismatch is incompatible and carries an error',
    $mismatch['compatible'] === false && !empty($mismatch['error']) && $mismatch['warning'] === null);
check('mismatch error names both models',
    strpos($mismatch['error'], 'Gold 6342') !== false && strpos($mismatch['error'], 'Gold 6338') !== false);

echo "\nSocket agreement is still enforced within a variant pair:\n";
$wrongSocket = $byModel['Gold 6338N'];
$wrongSocket['socket'] = 'LGA 4677';
$r = $matcher->compare($byModel['Gold 6338'], $wrongSocket);
check('same base SKU but conflicting socket -> mismatch',
    $r['verdict'] === CpuIdentityMatcher::VERDICT_MISMATCH);

$wrongArch = $byModel['Gold 6338N'];
$wrongArch['architecture'] = 'Sapphire Rapids';
$r = $matcher->compare($byModel['Gold 6338'], $wrongArch);
check('same base SKU but conflicting architecture -> mismatch',
    $r['verdict'] === CpuIdentityMatcher::VERDICT_MISMATCH);

echo "\nUnparseable model with a different UUID fails closed:\n";
$bogus = [
    'model' => 'Mystery Chip',
    'UUID' => 'ffffffff-0000-0000-0000-000000000000',
    'brand' => 'Intel', 'series' => 'Xeon Scalable', 'family' => 'Gold 6000',
    'architecture' => 'Ice Lake-SP', 'socket' => 'LGA4189', 'memory_channels' => 8,
];
$r = $matcher->compare($byModel['Gold 6338'], $bogus);
check('unparseable name is blocked, not allowed',
    $r['verdict'] === CpuIdentityMatcher::VERDICT_MISMATCH && $r['compatible'] === false);

echo "\nEvery model in ims-data parses:\n";
$unparsed = [];
foreach ($byModel as $name => $_) {
    if (!$matcher->parse($name)['parsed']) {
        $unparsed[] = $name;
    }
}
check(count($byModel) . ' models, 0 unparsed' . (empty($unparsed) ? '' : ' — failed: ' . implode(', ', $unparsed)),
    empty($unparsed));

echo "\n$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
