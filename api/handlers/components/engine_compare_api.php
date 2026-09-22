<?php
/**
 * Engine comparison harness -- Phase F of the 2026-09-21 backend audit. READ ONLY.
 *
 * Two compatibility engines are live. Request items are judged by the legacy pairwise one
 * (TicketValidator -> ComponentCompatibility); every server add, and the compatible-parts
 * listing, is judged by ValidationEngine. F.1 moves TicketValidator onto ValidationEngine
 * and F.3 deletes ~3,990 lines of the legacy engine. Before either, this asks BOTH the same
 * questions and reports where they disagree -- the blast radius of F.1, measured.
 *
 * Each engine is asked through the code that asks it in production, so the harness cannot
 * become a third opinion:
 *   legacy  TicketValidator::loadServerComponents() + checkItemCompatibilityWithComponents()
 *   new     ServerBuilder::evaluateCandidatesWithEngine() -- the listing's path, which
 *           mirrors AddComponentCommand's candidate row and diffs against the baseline so
 *           a pre-existing failure is never blamed on the candidate.
 * Both are private, so they are reached through reflection. That is acceptable ONLY
 * because this file is a throwaway diagnostic: it is deleted with the legacy engine in F.3.
 *
 * Two sources of questions:
 *   source=items    every ticket_items row naming a target server (the historical corpus).
 *                   Production had 4 rows on 2026-09-22, both targets since deleted, so
 *                   this alone measures nothing.
 *   source=configs  every live configuration x every distinct model in stock -- the
 *                   question F.1 will put to the new engine for each future request item.
 *                   Returns disagreements and errors only; agreements are counted.
 * Both judge against the server AS IT IS NOW.
 *
 * Role-gated to admin/super_admin in-handler, for the reason spec_api.php gives: an ACL
 * row needs a hand-run seeder, and a diagnostic nobody can run until then is useless. It
 * lives in handlers/components/ for the same reason spec_api.php does.
 *
 * Action: engine-compare   source (items|configs), limit, ticket_id (items), config_uuid (configs)
 */

function handleEngineCompareOperations($operation, $user) {
    global $pdo;

    $acl = $GLOBALS['acl'] ?? null;
    if (!$acl instanceof ACL) {
        $acl = new ACL($pdo);
    }
    if (!$acl->hasRole($user['id'], ['admin', 'super_admin'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: admin role required");
    }

    if ($operation !== 'compare') {
        send_json_response(0, 1, 400, "Invalid engine operation: $operation");
    }

    $root = __DIR__ . '/../../../core/models';
    foreach ([
        $root . '/tickets/TicketValidator.php',
        $root . '/server/ServerBuilder.php',
        $root . '/validation/ValidationEngine.php',
    ] as $file) {
        if (!is_readable($file)) {
            send_json_response(0, 1, 503, "Engine comparison is not available on this deployment");
        }
        require_once $file;
    }

    $source = (string)($_POST['source'] ?? $_GET['source'] ?? 'items');
    if (!in_array($source, ['items', 'configs'], true)) {
        send_json_response(0, 1, 400, "source must be items or configs");
    }
    $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 500);
    $limit = max(1, min(2000, $limit));

    @set_time_limit(300);

    try {
        $cases = $source === 'items'
            ? engineCompareItemCases($pdo, $limit, (int)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0))
            : engineCompareConfigCases($pdo, $limit, trim((string)($_POST['config_uuid'] ?? $_GET['config_uuid'] ?? '')));

        $validator = new TicketValidator($pdo);
        $loadLegacy = new ReflectionMethod($validator, 'loadServerComponents');
        $loadLegacy->setAccessible(true);
        $checkLegacy = new ReflectionMethod($validator, 'checkItemCompatibilityWithComponents');
        $checkLegacy->setAccessible(true);

        $builder = new ServerBuilder($pdo);
        $checkNew = new ReflectionMethod($builder, 'evaluateCandidatesWithEngine');
        $checkNew->setAccessible(true);

        $cds = ComponentDataService::getInstance();
    } catch (Throwable $e) {
        error_log("engine-compare setup failed: " . $e->getMessage());
        send_json_response(0, 1, 500, "Engine comparison could not start");
    }

    // One engine call per (server, type): the baseline is computed once per call, and the
    // listing path is built to take a batch.
    $newCache = [];
    $legacyCache = [];
    $rows = [];
    $patterns = [];
    $counts = [
        'compared' => 0, 'agree' => 0,
        'legacy_blocks_new_allows' => 0, 'new_blocks_legacy_allows' => 0,
        'skipped' => 0, 'errors' => 0,
    ];

    foreach ($cases['cases'] as $case) {
        $type = $case['component_type'];
        $uuid = $case['component_uuid'];
        $server = $case['server_uuid'];
        $out = $case;

        // Legacy only judges compatibility for a uuid that resolves in ims-data;
        // anything else is refused earlier and never reaches either engine.
        try {
            $known = $cds->validateComponentUuid($type, $uuid);
        } catch (Throwable $e) {
            $known = false;
        }
        if (!$known) {
            $out['outcome'] = 'skipped';
            $out['reason'] = 'uuid not in ims-data';
            $counts['skipped']++;
            $rows[] = $out;
            continue;
        }

        try {
            if (!array_key_exists($server, $legacyCache)) {
                $legacyCache[$server] = $loadLegacy->invoke($validator, $server);
            }
            if ($legacyCache[$server] === null) {
                $out['outcome'] = 'skipped';
                $out['reason'] = 'target server no longer exists';
                $counts['skipped']++;
                $rows[] = $out;
                continue;
            }

            $legacy = $checkLegacy->invoke($validator,
                ['component_type' => $type, 'component_uuid' => $uuid], $legacyCache[$server]);

            $key = $server . '|' . $type;
            if (!isset($newCache[$key])) {
                $batch = [];
                foreach ($cases['uuids_by_key'][$key] ?? [$uuid] as $u) {
                    $batch[] = ['UUID' => $u];
                }
                $newCache[$key] = $checkNew->invoke($builder, $server, $type, $batch, null);
            }
            $new = $newCache[$key][$uuid] ?? ['compatible' => false, 'reason' => 'no verdict returned', 'warnings' => []];
        } catch (Throwable $e) {
            error_log("engine-compare $server $type $uuid failed: " . $e->getMessage());
            $out['outcome'] = 'error';
            $out['reason'] = 'evaluation failed';
            $counts['errors']++;
            $rows[] = $out;
            continue;
        }

        $legacyOk = !empty($legacy['compatible']);
        $newOk = !empty($new['compatible']);
        $out['legacy'] = ['compatible' => $legacyOk, 'notes' => $legacy['notes'] ?? ''];
        $out['new'] = [
            'compatible' => $newOk,
            'reason'     => $new['reason'] ?? '',
            'warnings'   => $new['warnings'] ?? [],
        ];

        $counts['compared']++;
        if ($legacyOk === $newOk) {
            $out['outcome'] = 'agree';
            $counts['agree']++;
            if ($source === 'configs') {
                continue; // counted, not listed -- the report is the disagreements
            }
        } else {
            $out['outcome'] = $newOk ? 'legacy_blocks_new_allows' : 'new_blocks_legacy_allows';
            $counts[$out['outcome']]++;

            // Group by the refusing engine's message, so one systematic difference reads
            // as one line instead of hundreds.
            $why = $newOk ? $out['legacy']['notes'] : $out['new']['reason'];
            $pkey = $out['outcome'] . '|' . $type . '|' . $why;
            if (!isset($patterns[$pkey])) {
                $patterns[$pkey] = ['outcome' => $out['outcome'], 'component_type' => $type,
                                    'refusal' => $why, 'count' => 0];
            }
            $patterns[$pkey]['count']++;
        }
        $rows[] = $out;
    }

    // Disagreements first: they are the whole point of the report.
    $rank = ['new_blocks_legacy_allows' => 0, 'legacy_blocks_new_allows' => 1, 'error' => 2, 'skipped' => 3, 'agree' => 4];
    usort($rows, function ($a, $b) use ($rank) {
        return ($rank[$a['outcome']] ?? 9) <=> ($rank[$b['outcome']] ?? 9);
    });
    $patterns = array_values($patterns);
    usort($patterns, function ($a, $b) {
        return $b['count'] <=> $a['count'];
    });

    send_json_response(1, 1, 200, "Engine comparison complete", [
        'source'   => $source,
        'summary'  => $counts + $cases['meta'],
        'patterns' => $patterns,
        'items'    => $rows,
    ]);
}

/** Historical corpus: ticket_items rows whose request names a target server. */
function engineCompareItemCases(PDO $pdo, int $limit, int $ticketId): array {
    $sql = "SELECT ti.id AS item_id, ti.ticket_id, ti.component_type, ti.component_uuid,
                   ti.action, ti.is_compatible AS stored_compatible,
                   t.target_server_uuid, t.status AS ticket_status
              FROM ticket_items ti
              JOIN tickets t ON t.id = ti.ticket_id
             WHERE t.target_server_uuid IS NOT NULL AND t.target_server_uuid <> ''";
    $params = [];
    if ($ticketId > 0) {
        $sql .= " AND ti.ticket_id = ?";
        $params[] = $ticketId;
    }
    $sql .= " ORDER BY ti.ticket_id, ti.id LIMIT " . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $cases = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $cases[] = [
            'item_id'           => (int)$item['item_id'],
            'ticket_id'         => (int)$item['ticket_id'],
            'ticket_status'     => $item['ticket_status'],
            'server_uuid'       => (string)$item['target_server_uuid'],
            'component_type'    => (string)$item['component_type'],
            'component_uuid'    => (string)$item['component_uuid'],
            'action'            => $item['action'],
            'stored_compatible' => $item['stored_compatible'] === null ? null : (int)$item['stored_compatible'],
        ];
    }

    return [
        'cases' => $cases,
        'uuids_by_key' => [],
        'meta' => [
            'ticket_items_total' => (int)$pdo->query("SELECT COUNT(*) FROM ticket_items")->fetchColumn(),
            'cases' => count($cases),
            'limit' => $limit,
            'truncated' => count($cases) >= $limit,
        ],
    ];
}

/**
 * Synthetic corpus: each configuration (newest first, $limit of them) crossed with every
 * distinct model stocked in each inventory table. Compatibility is a property of the model,
 * so one row per spec uuid is the whole question.
 */
function engineCompareConfigCases(PDO $pdo, int $limit, string $configUuid): array {
    if ($configUuid !== '') {
        $stmt = $pdo->prepare("SELECT config_uuid, is_virtual FROM server_configurations WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
    } else {
        $stmt = $pdo->query("SELECT config_uuid, is_virtual FROM server_configurations
                              ORDER BY created_at DESC LIMIT " . $limit);
    }
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $models = [];
    foreach (VALID_COMPONENT_TYPES as $type) {
        if (!inventoryTableExists($pdo, $type)) {
            continue;
        }
        $table = getComponentTableName($type);
        $uuids = $pdo->query("SELECT DISTINCT UUID FROM $table WHERE UUID IS NOT NULL AND UUID <> ''")
                     ->fetchAll(PDO::FETCH_COLUMN);
        if ($uuids) {
            $models[$type] = $uuids;
        }
    }

    $cases = [];
    $byKey = [];
    foreach ($configs as $config) {
        $server = (string)$config['config_uuid'];
        foreach ($models as $type => $uuids) {
            $byKey[$server . '|' . $type] = $uuids;
            foreach ($uuids as $uuid) {
                $cases[] = [
                    'server_uuid'    => $server,
                    'is_virtual'     => (int)$config['is_virtual'],
                    'component_type' => $type,
                    'component_uuid' => (string)$uuid,
                ];
            }
        }
    }

    $modelCount = 0;
    foreach ($models as $uuids) {
        $modelCount += count($uuids);
    }

    return [
        'cases' => $cases,
        'uuids_by_key' => $byKey,
        'meta' => [
            'configs' => count($configs),
            'distinct_models' => $modelCount,
            'cases' => count($cases),
            'limit' => $limit,
        ],
    ];
}
