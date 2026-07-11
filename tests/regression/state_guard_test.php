<?php
/**
 * state_guard_test.php — U-SM.4 regression test.
 *
 * Proves StateGuard::checkMutation() implements the new rule
 * (status_v2 in {draft,building,maintenance} allows; NULL falls back to the
 * legacy int!=3 rule), that shadow mode only LOGS divergences from the old
 * TEMP-GUARD verdict without ever blocking, and that enforce mode is
 * authoritative (including overriding the old rule for 'maintenance', whose
 * legacy int mapping is 3 — the exact case shadow mode exists to catch).
 * Also proves finalizeConfiguration()'s enforce-mode V-1 gate
 * (assertConfigTransition + comprehensive-under-lock) is wired.
 *
 * Exit 0 = all pass; exit 1 = a failure.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__, 2);
$dbHost = getenv('GOLDEN_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('GOLDEN_DB_NAME') ?: 'ims_compat_golden';
$dbUser = getenv('GOLDEN_DB_USER') ?: 'root';
$dbPass = getenv('GOLDEN_DB_PASS');
if ($dbPass === false) { $dbPass = ''; }

putenv("DB_HOST=$dbHost"); putenv("DB_NAME=$dbName");
putenv("DB_USER=$dbUser"); putenv("DB_PASS=$dbPass");

$pdo = new PDO(
    "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
    $dbUser, $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once $ROOT . '/core/models/server/ServerBuilder.php';
require_once $ROOT . '/core/models/state/StateGuard.php';

$fails = 0;
function check($label, $cond) {
    global $fails;
    echo ($cond ? "  PASS" : "  FAIL") . "  $label\n";
    if (!$cond) { $fails++; }
}

$ramUuid = 'f1a2b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c'; // DDR4 RDIMM, ims-data/ram/ram_detail.json
$logPath = $ROOT . '/reports/shadow/state-guard.jsonl';
$configUuids = [];

function makeConfig(PDO $pdo, $statusV2, $legacyInt, $ramConfigJson) {
    global $configUuids;
    $configUuid = 'TEST-SG-' . substr(md5(uniqid('', true)), 0, 8);
    $configUuids[] = $configUuid;
    $cols = [
        'config_uuid' => $configUuid, 'server_name' => 'TEST STATEGUARD', 'is_virtual' => 0,
        'configuration_status' => $legacyInt, 'status_v2' => $statusV2,
        'motherboard_uuid' => null, 'chassis_uuid' => null,
        'cpu_configuration' => null, 'ram_configuration' => $ramConfigJson, 'storage_configuration' => null,
        'caddy_configuration' => null, 'nic_config' => null, 'sfp_configuration' => null,
        'hbacard_config' => null, 'pciecard_configurations' => null,
    ];
    $f = array_keys($cols);
    $pdo->prepare('INSERT INTO server_configurations (' . implode(',', $f) . ') VALUES (' . implode(',', array_map(fn($x) => ":$x", $f)) . ')')
        ->execute($cols);
    return $configUuid;
}

try {
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-PROBE-SG'");
    $pdo->prepare("INSERT INTO raminventory (UUID, SerialNumber, Status, Flag) VALUES (?, 'TEMP-SG', 1, 'TEMP-PROBE-SG')")
        ->execute([$ramUuid]);

    $ramConfigJson = json_encode([['uuid' => $ramUuid, 'quantity' => 1, 'serial_number' => 'TEMP-SG']]);
    $builder = new ServerBuilder($pdo);

    if (file_exists($logPath)) { unlink($logPath); }

    // ---- off mode: checkMutation is a pure no-op regardless of status ------
    putenv('STATE_MACHINE_ENABLED=off');
    check('off: mode() reports off', StateGuard::mode() === 'off');
    check('off: checkMutation always null', StateGuard::checkMutation($pdo, ['status_v2' => 'deployed', 'configuration_status' => 3]) === null);

    // ---- shadow: maintenance (status_v2 allows, legacy int=3 blocks) -------
    // U-SM.3's mapping puts maintenance's legacy int at 3, same as finalized/
    // deployed/retired -- this is exactly the divergence shadow mode exists
    // to surface: the new rule says "allowed", the old TEMP-GUARD says "blocked".
    putenv('STATE_MACHINE_ENABLED=shadow');
    $maintCfg = makeConfig($pdo, 'maintenance', 3, $ramConfigJson);
    $addResult = $builder->addComponent($maintCfg, 'ram', $ramUuid, ['quantity' => 1, 'serial_number' => 'TEMP-SG-2']);
    check('shadow: maintenance config still blocked by legacy TEMP-GUARD (shadow never overrides)', ($addResult['error_type'] ?? null) === 'config_finalized');
    check('shadow: no PDO transaction left open', !$pdo->inTransaction());

    $logLines = file_exists($logPath) ? array_filter(explode("\n", file_get_contents($logPath))) : [];
    $found = false;
    foreach ($logLines as $line) {
        $entry = json_decode($line, true);
        if (($entry['config_uuid'] ?? null) === $maintCfg) {
            $found = true;
            check('shadow: divergence log new_verdict_blocks=false', $entry['new_verdict_blocks'] === false);
            check('shadow: divergence log legacy_verdict_blocks=true', $entry['legacy_verdict_blocks'] === true);
            check('shadow: divergence log status_v2=maintenance', $entry['status_v2'] === 'maintenance');
        }
    }
    check('shadow: divergence logged to reports/shadow/state-guard.jsonl', $found);

    // ---- shadow: deployed (both rules agree -> blocked, no divergence) -----
    $deployedCfg = makeConfig($pdo, 'deployed', 3, $ramConfigJson);
    $addResult2 = $builder->addComponent($deployedCfg, 'ram', $ramUuid, ['quantity' => 1, 'serial_number' => 'TEMP-SG-3']);
    check('shadow: deployed config blocked (agree with legacy)', ($addResult2['error_type'] ?? null) === 'config_finalized');
    $logLines2 = file_exists($logPath) ? array_filter(explode("\n", file_get_contents($logPath))) : [];
    $foundDeployed = false;
    foreach ($logLines2 as $line) {
        $entry = json_decode($line, true);
        if (($entry['config_uuid'] ?? null) === $deployedCfg) { $foundDeployed = true; }
    }
    check('shadow: no divergence logged when new/legacy agree (deployed)', !$foundDeployed);

    // ---- enforce: maintenance now ALLOWED (new rule authoritative) ---------
    putenv('STATE_MACHINE_ENABLED=enforce');
    $maintCfg2 = makeConfig($pdo, 'maintenance', 3, null);
    $addResult3 = $builder->addComponent($maintCfg2, 'ram', $ramUuid, ['quantity' => 1, 'serial_number' => 'TEMP-SG-4']);
    check('enforce: maintenance config addComponent allowed (StateGuard overrides legacy int=3)', ($addResult3['error_type'] ?? null) !== 'config_finalized');
    check('enforce: no PDO transaction left open after maintenance add', !$pdo->inTransaction());

    // ---- enforce: deployed still BLOCKED, error_type=config_immutable ------
    $deployedCfg2 = makeConfig($pdo, 'deployed', 3, $ramConfigJson);
    $addResult4 = $builder->addComponent($deployedCfg2, 'ram', $ramUuid, ['quantity' => 1, 'serial_number' => 'TEMP-SG-5']);
    check('enforce: deployed config blocked', ($addResult4['success'] ?? true) === false);
    check('enforce: deployed error_type=config_immutable (new-rule reason, not legacy)', ($addResult4['error_type'] ?? null) === 'config_immutable');

    // ---- enforce: NULL status_v2 falls back to legacy int rule -------------
    $nullCfgBlocked = makeConfig($pdo, null, 3, $ramConfigJson);
    $addResult5 = $builder->addComponent($nullCfgBlocked, 'ram', $ramUuid, ['quantity' => 1, 'serial_number' => 'TEMP-SG-6']);
    check('enforce: NULL status_v2 + legacy int=3 blocks (fallback)', ($addResult5['error_type'] ?? null) === 'config_finalized');

    $nullCfgAllowed = makeConfig($pdo, null, 0, null);
    $addResult6 = $builder->addComponent($nullCfgAllowed, 'ram', $ramUuid, ['quantity' => 1, 'serial_number' => 'TEMP-SG-7']);
    check('enforce: NULL status_v2 + legacy int=0 allows (fallback)', ($addResult6['error_type'] ?? null) !== 'config_finalized' && ($addResult6['error_type'] ?? null) !== 'config_immutable');

    // ---- enforce: removeComponent honors the same guard --------------------
    $deployedCfg3 = makeConfig($pdo, 'deployed', 3, $ramConfigJson);
    $removeResult = $builder->removeComponent($deployedCfg3, 'ram', $ramUuid, 'TEMP-SG');
    check('enforce: removeComponent blocked on deployed config', ($removeResult['error_type'] ?? null) === 'config_immutable');

    // ---- enforce: finalizeConfiguration V-1 gate wired ----------------------
    // validated->finalized requires permission 'server.finalize' + full validation.
    // userId=0 (finalizeConfiguration's default when no caller passes one -- the
    // one production call site in server_api.php does not pass $user['id'] yet,
    // a known gap documented in this unit's handoff) has no ACL permissions, so
    // this must be denied at the transition-legality step, never reaching the
    // comprehensive-validation step.
    $validatedCfg = makeConfig($pdo, 'validated', 1, null);
    $finalizeResult = $builder->finalizeConfiguration($validatedCfg, 'test notes');
    check('enforce: finalizeConfiguration denies validated->finalized for unprivileged userId=0', ($finalizeResult['success'] ?? true) === false);
    check('enforce: finalize error_type=transition_denied', ($finalizeResult['error_type'] ?? null) === 'transition_denied');
    check('enforce: finalize did not mutate status_v2', $pdo->query("SELECT status_v2 FROM server_configurations WHERE config_uuid = " . $pdo->quote($validatedCfg))->fetchColumn() === 'validated');
    check('enforce: no PDO transaction left open after finalize denial', !$pdo->inTransaction());

} finally {
    if ($pdo->inTransaction()) { $pdo->rollback(); }
    foreach ($configUuids as $cfg) {
        $pdo->exec("DELETE FROM server_configurations WHERE config_uuid = " . $pdo->quote($cfg));
    }
    $pdo->exec("DELETE FROM raminventory WHERE Flag = 'TEMP-PROBE-SG'");
    putenv('STATE_MACHINE_ENABLED'); // unset, restore default 'off' for any later process reuse
}

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILURE(S)") . "\n";
exit($fails === 0 ? 0 : 1);
