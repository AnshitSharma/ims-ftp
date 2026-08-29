<?php
/**
 * _ud3b_reader_parity.php — the U-D.3b gate.
 *
 * For each reader moved onto config_components, does it still answer what it answered
 * before? (It supersedes _ud3b_parity_probe.php, which asked the weaker question of
 * whether the rows side COULD substitute at all, and was deleted with U-D.3a: its
 * subject, ServerBuilder::extractComponentsFromJson(), no longer exists.)
 *
 * The "before" is not a stored snapshot — it is RECOMPUTED here from the JSON columns,
 * with the pre-migration logic inlined per reader. So this is a genuine before/after
 * comparison over the whole fixture rather than a diff against a baseline file that
 * could itself have gone stale. (The repo's other golden master,
 * tests/golden/compatibility_baseline.json, cannot serve as this gate: P9 deleted the
 * three methods it characterises, so every entry in it now reads "Call to undefined
 * method". It is a P9 artefact to repair or retire, not an instrument U-D.3 can lean on.)
 *
 * UNDERSCORE-PREFIXED, so run_tests.php does not discover it (run_tests.php:69). Not
 * because it is expected to fail — it must be ALL PASS before U-D.3a — but because it
 * reads the nine JSON columns to build its expectations, and U-D.3c deletes them. A
 * suite that becomes unrunnable by design does not belong in the standing set; the
 * durable half of what it proves is pinned in tests/regression/read_router_test.php.
 *
 * Read-only. Never run against production.
 *
 *   php tests/backfill/_ud3b_reader_parity.php
 * with GOLDEN_DB_HOST / GOLDEN_DB_NAME / GOLDEN_DB_USER / GOLDEN_DB_PASS set.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/core/models/server/ServerBuilder.php';
require_once $ROOT . '/core/models/server/ServerConfiguration.php';
require_once $ROOT . '/core/models/compatibility/UnifiedSlotTracker.php';
require_once $ROOT . '/core/models/compatibility/ServerState.php';
require_once $ROOT . '/core/models/config/ConfigReadRouter.php';

$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
$dbPass = getenv('GOLDEN_DB_PASS') ?: '';

$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$fails = 0;
$notes = [];
function check(string $label, bool $cond, string $detail = ''): void {
    global $fails;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fails++;
        if ($detail !== '') { echo "         " . $detail . "\n"; }
    }
}

/** Call a private/protected method for comparison purposes. */
function invoke($object, string $method, array $args) {
    $m = new ReflectionMethod(get_class($object), $method);
    $m->setAccessible(true);
    return $m->invoke($object, ...$args);
}

function decode($json): array {
    if (empty($json)) { return []; }
    $d = json_decode((string)$json, true);
    return is_array($d) ? $d : [];
}

/**
 * The identity set the LEGACY JSON columns describe, as "type|uuid" strings.
 *
 * Inlined here on purpose. It used to call ServerBuilder::extractComponentsFromJson(),
 * which U-D.3a deleted -- and a probe whose job is to compare the new code against the
 * old cannot depend on the old code still being present. Mirrors that extractor's
 * branch set (cpu / ram / storage / caddy / nic / hbacard+scalar / motherboard /
 * chassis / pciecard / sfp assigned+unassigned).
 */
function legacyIdentity(array $cd): array {
    $out = [];
    foreach (decode($cd['cpu_configuration'] ?? null)['cpus'] ?? [] as $e) {
        if (!empty($e['uuid'])) { $out[] = 'cpu|' . $e['uuid']; }
    }
    foreach (['ram' => 'ram_configuration', 'storage' => 'storage_configuration',
              'caddy' => 'caddy_configuration', 'pciecard' => 'pciecard_configurations'] as $type => $col) {
        foreach (decode($cd[$col] ?? null) as $e) {
            if (is_array($e) && !empty($e['uuid'])) { $out[] = $type . '|' . $e['uuid']; }
        }
    }
    foreach (decode($cd['nic_config'] ?? null)['nics'] ?? [] as $e) {
        if (!empty($e['uuid'])) { $out[] = 'nic|' . $e['uuid']; }
    }
    $hba = decode($cd['hbacard_config'] ?? null);
    if ($hba) {
        if (isset($hba['uuid'])) { $hba = [$hba]; }
        foreach ($hba as $e) {
            if (is_array($e) && !empty($e['uuid'])) { $out[] = 'hbacard|' . $e['uuid']; }
        }
    } elseif (!empty($cd['hbacard_uuid'])) {
        $out[] = 'hbacard|' . $cd['hbacard_uuid'];
    }
    if (!empty($cd['motherboard_uuid'])) { $out[] = 'motherboard|' . $cd['motherboard_uuid']; }
    if (!empty($cd['chassis_uuid']))     { $out[] = 'chassis|' . $cd['chassis_uuid']; }
    $sfp = decode($cd['sfp_configuration'] ?? null);
    foreach (array_merge($sfp['sfps'] ?? [], $sfp['unassigned_sfps'] ?? []) as $e) {
        if (is_array($e) && !empty($e['uuid'])) { $out[] = 'sfp|' . $e['uuid']; }
    }
    sort($out);
    return $out;
}

$builder = new ServerBuilder($pdo);
$tracker = new UnifiedSlotTracker($pdo);

$configs = $pdo->query("SELECT * FROM server_configurations ORDER BY config_uuid")->fetchAll();
echo "configs in fixture: " . count($configs) . "\n\n";

// A config with JSON but no rows is the one class where the two sides are KNOWN to
// disagree, and it disagrees because it cannot be represented in config_components at
// all (no inventory units to point at — see tasks/u-d3-execution.md). Excluded from the
// parity comparisons and asserted separately, so it cannot hide a real regression.
$rowless = [];
foreach ($configs as $row) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM config_components WHERE config_uuid = ? AND removed_at IS NULL");
    $st->execute([$row['config_uuid']]);
    if ((int)$st->fetchColumn() === 0) { $rowless[$row['config_uuid']] = true; }
}
$comparable = array_values(array_filter($configs, fn($r) => !isset($rowless[$r['config_uuid']])));
echo "comparable (has rows): " . count($comparable) . "   excluded (no rows): " . count($rowless) . "\n\n";

// =============================================================================
// 1. UnifiedSlotTracker::getUsedPCIeSlots  — slot => occupant uuid
// =============================================================================
$isPcieSlot = function (string $slot): bool {
    $m = new ReflectionMethod('UnifiedSlotTracker', 'isPcieSlotPosition');
    $m->setAccessible(true);
    return (bool)$m->invoke(null, $slot);
};

$legacyPcieSlots = function (array $cd) use ($isPcieSlot): array {
    $occupants = [];
    foreach (decode($cd['pciecard_configurations'] ?? null) as $e) {
        if (!empty($e['slot_position']) && $isPcieSlot($e['slot_position'])) {
            $occupants[$e['slot_position']][] = $e['uuid'];
        }
    }
    $hba = decode($cd['hbacard_config'] ?? null);
    if ($hba) {
        if (isset($hba['uuid'])) { $hba = [$hba]; }
        foreach ($hba as $e) {
            if (!empty($e['slot_position']) && $isPcieSlot($e['slot_position'])) {
                $occupants[$e['slot_position']][] = $e['uuid'];
            }
        }
    }
    $nic = decode($cd['nic_config'] ?? null);
    foreach ($nic['nics'] ?? [] as $e) {
        if (($e['source_type'] ?? 'component') === 'component'
            && !empty($e['slot_position']) && $isPcieSlot($e['slot_position'])) {
            $occupants[$e['slot_position']][] = $e['uuid'];
        }
    }
    foreach (decode($cd['storage_configuration'] ?? null) as $e) {
        if (!empty($e['slot_position']) && $isPcieSlot($e['slot_position'])) {
            $occupants[$e['slot_position']][] = $e['uuid'] ?? 'storage-aic';
        }
    }
    $out = [];
    foreach ($occupants as $slot => $uuids) { $out[$slot] = $uuids[0]; }
    ksort($out);
    return $out;
};

$pcieDiff = [];
foreach ($comparable as $row) {
    $want = $legacyPcieSlots($row);
    $got  = invoke($tracker, 'getUsedPCIeSlots', [$row['config_uuid'], null]);
    ksort($got);
    if ($want !== $got) { $pcieDiff[] = [$row['config_uuid'], $want, $got]; }
}
check('getUsedPCIeSlots: identical slot map on all ' . count($comparable) . ' configs', $pcieDiff === []);
foreach ($pcieDiff as [$u, $w, $g]) {
    echo "         $u\n           json: " . json_encode($w) . "\n           rows: " . json_encode($g) . "\n";
}

// =============================================================================
// 2. UnifiedSlotTracker::getUsedRiserSlots
// =============================================================================
$isRiserBay = function (string $slot): bool {
    $m = new ReflectionMethod('UnifiedSlotTracker', 'isRiserBaySlot');
    $m->setAccessible(true);
    return (bool)$m->invoke(null, $slot);
};
$riserDiff = [];
foreach ($comparable as $row) {
    $want = [];
    foreach (decode($row['pciecard_configurations'] ?? null) as $e) {
        if (!empty($e['slot_position']) && $isRiserBay($e['slot_position'])) {
            $want[$e['slot_position']] = $e['uuid'];
        }
    }
    ksort($want);
    $got = invoke($tracker, 'getUsedRiserSlots', [$row['config_uuid'], null]);
    ksort($got);
    if ($want !== $got) { $riserDiff[] = [$row['config_uuid'], $want, $got]; }
}
check('getUsedRiserSlots: identical riser-bay map on all ' . count($comparable) . ' configs', $riserDiff === []);
foreach ($riserDiff as [$u, $w, $g]) {
    echo "         $u\n           json: " . json_encode($w) . "\n           rows: " . json_encode($g) . "\n";
}

// =============================================================================
// 3. UnifiedSlotTracker::getInstalledCpuCount
// =============================================================================
$cpuDiff = [];
foreach ($comparable as $row) {
    $want = 0;
    foreach (decode($row['cpu_configuration'] ?? null)['cpus'] ?? [] as $c) {
        $want += max(1, (int)($c['quantity'] ?? 1));
    }
    $got = (int)invoke($tracker, 'getInstalledCpuCount', [$row['config_uuid'], null]);
    if ($want !== $got) { $cpuDiff[] = [$row['config_uuid'], $want, $got]; }
}
check('getInstalledCpuCount: identical socket count on all ' . count($comparable) . ' configs', $cpuDiff === []);
foreach ($cpuDiff as [$u, $w, $g]) { echo "         $u  json=$w rows=$g\n"; }

// =============================================================================
// 4. ServerConfiguration::getComponents() — identity
// =============================================================================
$identity = function (array $list): array {
    $out = [];
    foreach ($list as $c) {
        if (empty($c['component_uuid'])) { continue; }
        $out[] = ($c['component_type'] ?? '?') . '|' . $c['component_uuid'];
    }
    sort($out);
    return $out;
};
$scDiff = [];
foreach ($comparable as $row) {
    $want = legacyIdentity($row);
    $cfg = new ServerConfiguration($pdo, $row);
    $got = $identity($cfg->getComponents());
    if ($want !== $got) { $scDiff[] = [$row['config_uuid'], $want, $got]; }
}
check('ServerConfiguration::getComponents: identical identity set on all ' . count($comparable) . ' configs', $scDiff === []);
foreach ($scDiff as [$u, $w, $g]) {
    echo "         $u\n           json: " . json_encode($w) . "\n           rows: " . json_encode($g) . "\n";
}

// =============================================================================
// 5. getNetworkConfiguration() — which NICs, and how many ports
// =============================================================================
$netDiff = [];
foreach ($comparable as $row) {
    $legacyNics = [];
    foreach (decode($row['nic_config'] ?? null)['nics'] ?? [] as $n) {
        if (empty($n['uuid'])) { continue; }
        $legacyNics[(string)$n['uuid']] = ($n['source_type'] ?? 'component');
    }
    ksort($legacyNics);

    $net = $builder->getNetworkConfiguration($row['config_uuid']);
    $gotNics = [];
    foreach ($net['nics'] ?? [] as $n) { $gotNics[(string)$n['uuid']] = $n['source_type']; }
    ksort($gotNics);

    if ($legacyNics !== $gotNics) { $netDiff[] = [$row['config_uuid'], $legacyNics, $gotNics]; }
}
check('getNetworkConfiguration: same NIC set and source_type on all ' . count($comparable) . ' configs',
    $netDiff === []);
foreach ($netDiff as [$u, $w, $g]) {
    echo "         $u\n           json: " . json_encode($w) . "\n           rows: " . json_encode($g) . "\n";
}

// The summary is now COUNTED from specs rather than read from the blob's cached
// 'summary'. Assert it is SELF-CONSISTENT rather than equal to a cache that could have
// been stale — a stronger property than the one it replaces.
$summaryBad = [];
foreach ($comparable as $row) {
    $net = $builder->getNetworkConfiguration($row['config_uuid']);
    $s = $net['summary'];
    $onboard = 0; $component = 0;
    foreach ($net['nics'] as $n) { $n['source_type'] === 'onboard' ? $onboard++ : $component++; }
    if ($s['total_nics'] !== count($net['nics'])
        || $s['onboard_nics'] !== $onboard
        || $s['component_nics'] !== $component
        || $s['total_ports'] !== $s['onboard_ports'] + $s['component_ports']) {
        $summaryBad[] = [$row['config_uuid'], $s];
    }
}
check('getNetworkConfiguration: summary counts agree with the NIC list it ships', $summaryBad === []);
foreach ($summaryBad as [$u, $s]) { echo "         $u  " . json_encode($s) . "\n"; }

// =============================================================================
// 6. The excluded configs: prove the exclusion is the KNOWN one, not a new gap
// =============================================================================
$badExclusion = [];
foreach (array_keys($rowless) as $uuid) {
    $st = $pdo->prepare("SELECT is_virtual FROM server_configurations WHERE config_uuid = ?");
    $st->execute([$uuid]);
    $isVirtual = (int)$st->fetchColumn() === 1;

    $st = $pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
    $st->execute([$uuid]);
    $row = $st->fetch();
    $hasJson = legacyIdentity($row) !== [];

    // Empty-and-rowless is fine (a brand-new draft). Populated-and-rowless is only
    // acceptable for a virtual build, which has no inventory units to key rows on.
    if ($hasJson && !$isVirtual) { $badExclusion[] = $uuid; }
}
check('every excluded config is either empty or is_virtual=1 (the by-design carve-out)'
    . ($badExclusion ? ' -- REAL builds excluded: ' . implode(', ', $badExclusion) : ''),
    $badExclusion === []);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
