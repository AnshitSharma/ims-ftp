<?php
/**
 * Engine comparison harness -- Phase F of the 2026-09-21 backend audit. READ ONLY.
 *
 * Two compatibility engines are live. Request items are judged by the legacy pairwise one
 * (TicketValidator -> ComponentCompatibility); every server add, and the compatible-parts
 * listing, is judged by ValidationEngine. F.1 moves TicketValidator onto ValidationEngine
 * and F.3 deletes ~3,990 lines of the legacy engine. Before either, this replays BOTH over
 * every existing ticket_items row that names a target server, and reports where they
 * disagree -- the blast radius of F.1, measured instead of guessed.
 *
 * Each engine is asked the question it is asked in production, through the code that asks
 * it, so the harness cannot become a third opinion:
 *   legacy  TicketValidator::loadServerComponents() + checkItemCompatibilityWithComponents()
 *   new     ServerBuilder::evaluateCandidatesWithEngine() -- the listing's path, which
 *           mirrors AddComponentCommand's candidate row and diffs against the baseline so
 *           a pre-existing failure is never blamed on the item.
 * Both are private, so they are reached through reflection. That is acceptable ONLY
 * because this file is a throwaway diagnostic: it is deleted with the legacy engine in F.3.
 *
 * Both engines judge an item against the server AS IT IS NOW, not as it was when the
 * request was raised; the stored ticket_items.is_compatible is reported alongside as the
 * historical answer, not as a third engine.
 *
 * Role-gated to admin/super_admin in-handler, for the reason spec_api.php gives: an ACL
 * row needs a hand-run seeder, and a diagnostic nobody can run until then is useless. It
 * lives in handlers/components/ for the same reason spec_api.php does.
 *
 * Action: engine-compare   optional limit (default 500, max 2000), ticket_id
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
    $needed = [
        $root . '/tickets/TicketValidator.php',
        $root . '/server/ServerBuilder.php',
        $root . '/validation/ValidationEngine.php',
    ];
    foreach ($needed as $file) {
        if (!is_readable($file)) {
            send_json_response(0, 1, 503, "Engine comparison is not available on this deployment");
        }
        require_once $file;
    }

    $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 500);
    $limit = max(1, min(2000, $limit));
    $ticketId = (int)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? 0);

    try {
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
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalStmt = $pdo->query("SELECT COUNT(*) FROM ticket_items");
        $totalItems = (int)$totalStmt->fetchColumn();

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

    $legacyCache = [];
    $rows = [];
    $counts = [
        'compared' => 0, 'agree' => 0,
        'legacy_blocks_new_allows' => 0, 'new_blocks_legacy_allows' => 0,
        'skipped' => 0, 'errors' => 0,
    ];

    foreach ($items as $item) {
        $type = (string)$item['component_type'];
        $uuid = (string)$item['component_uuid'];
        $server = (string)$item['target_server_uuid'];
        $out = [
            'item_id'           => (int)$item['item_id'],
            'ticket_id'         => (int)$item['ticket_id'],
            'ticket_status'     => $item['ticket_status'],
            'server_uuid'       => $server,
            'component_type'    => $type,
            'component_uuid'    => $uuid,
            'action'            => $item['action'],
            'stored_compatible' => $item['stored_compatible'] === null ? null : (int)$item['stored_compatible'],
        ];

        // Legacy only judges compatibility for an item whose uuid resolves in ims-data;
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
            $verdicts = $checkNew->invoke($builder, $server, $type, [['UUID' => $uuid]], null);
            $new = $verdicts[$uuid] ?? ['compatible' => false, 'reason' => 'no verdict returned', 'warnings' => []];
        } catch (Throwable $e) {
            error_log("engine-compare item {$item['item_id']} failed: " . $e->getMessage());
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
        } elseif ($newOk) {
            $out['outcome'] = 'legacy_blocks_new_allows';
            $counts['legacy_blocks_new_allows']++;
        } else {
            $out['outcome'] = 'new_blocks_legacy_allows';
            $counts['new_blocks_legacy_allows']++;
        }
        $rows[] = $out;
    }

    // Disagreements first: they are the whole point of the report.
    $rank = ['new_blocks_legacy_allows' => 0, 'legacy_blocks_new_allows' => 1, 'error' => 2, 'skipped' => 3, 'agree' => 4];
    usort($rows, function ($a, $b) use ($rank) {
        return ($rank[$a['outcome']] ?? 9) <=> ($rank[$b['outcome']] ?? 9)
            ?: $a['item_id'] <=> $b['item_id'];
    });

    send_json_response(1, 1, 200, "Engine comparison complete", [
        'summary' => $counts + [
            'items_with_target_server' => count($items),
            'ticket_items_total'       => $totalItems,
            'limit'                    => $limit,
            'truncated'                => count($items) >= $limit,
        ],
        'items' => $rows,
    ]);
}
