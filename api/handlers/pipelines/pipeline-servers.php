<?php
/**
 * pipeline-servers.php
 * Action: pipeline-servers
 * Permission: pipeline.create | pipeline.manage  (identical to pipeline-create)
 *
 * The server list behind the "which server is this about?" picker in the create-
 * request form.
 *
 * WHY THIS EXISTS AND IS NOT server-list-configs
 * ----------------------------------------------
 * `server-list-configs` is gated on server.view. The typical requester here is a
 * viewer or technician asking for temporary access precisely BECAUSE they do not
 * hold server permissions — so they could never call it, which is why the form
 * used to ask them to type a 36-character config_uuid from memory. Left blank
 * (the normal outcome) the approval granted server access GLOBALLY, defeating the
 * per-configuration scoping this module exists to provide.
 *
 * The gate is deliberately the same pair of permissions as pipeline-create: if
 * you may raise a request naming a server, you may see the list of servers you
 * could name. It grants no reach that pipeline-create did not already imply.
 *
 * WHAT IT DELIBERATELY DOES NOT RETURN
 * ------------------------------------
 * Identity only — enough to recognise a machine and nothing more. No component
 * lists, no specs, no validation_results, no notes or description. Anyone wanting
 * that still needs server.view and the real server endpoints.
 *
 * Params:
 * - search (optional): matches name, uuid, location, rack position, platform
 * - limit  (optional): default 50, hard max 100
 */

require_once(__DIR__ . '/../../../core/models/state/StatusMap.php');

try {
    if (!$acl->hasPermission($user_id, 'pipeline.create')
        && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: pipeline.create required", null);
        exit;
    }

    $search = trim((string)($_POST['search'] ?? $_GET['search'] ?? ''));
    $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 50);
    $limit = min(100, max(1, $limit));

    $where = '';
    $params = [];
    if ($search !== '') {
        $where = " WHERE (sc.server_name LIKE ? OR sc.config_uuid LIKE ? OR sc.location LIKE ?
                          OR sc.rack_position LIKE ? OR sc.platform_name LIKE ?)";
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like, $like];
    }

    // Total before the cap, so the UI can say "showing 50 of 120 — refine your search"
    // instead of silently pretending the rest do not exist.
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM server_configurations sc" . $where);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // $limit is an int clamped above, never request text — LIMIT cannot be bound
    // as a parameter on every MariaDB/emulated-prepare combination.
    $stmt = $pdo->prepare(
        "SELECT sc.config_uuid, sc.server_name, sc.status_v2, sc.configuration_status,
                sc.location, sc.rack_position, sc.platform_name,
                sc.is_virtual, sc.is_sandbox, sc.created_by, sc.created_at, sc.updated_at,
                u.username AS created_by_username
         FROM server_configurations sc
         LEFT JOIN users u ON u.id = sc.created_by"
        . $where .
        " ORDER BY sc.updated_at DESC, sc.id DESC
          LIMIT " . $limit
    );
    $stmt->execute($params);

    $servers = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (empty($row['config_uuid'])) {
            continue; // Cannot be a scope target, so it cannot be picked.
        }

        // configuration_status is the pre-status_v2 column and is still the only
        // status some rows carry. StatusMap owns that pairing — never hardcode it.
        $status = !empty($row['status_v2'])
            ? $row['status_v2']
            : (StatusMap::CONFIG_LEGACY_TO_V2[(int)$row['configuration_status']] ?? 'unknown');

        $servers[] = [
            'config_uuid'    => $row['config_uuid'],
            'server_name'    => $row['server_name'],
            'status'         => $status,
            'location'       => $row['location'],
            'rack_position'  => $row['rack_position'],
            'platform_name'  => $row['platform_name'],
            'is_virtual'     => (int)$row['is_virtual'] === 1,
            'is_sandbox'     => (int)$row['is_sandbox'] === 1,
            // Owners can always act on their own configuration (see
            // userCanActOnConfig), so the UI can warn that requesting access to
            // this one is pointless.
            'is_own'         => (int)$row['created_by'] === (int)$user_id,
            'created_by'     => $row['created_by_username'],
            'updated_at'     => $row['updated_at'],
        ];
    }

    send_json_response(true, true, 200, "Servers retrieved successfully", [
        'servers' => $servers,
        'total' => $total,
        'truncated' => $total > count($servers),
    ]);
} catch (Exception $e) {
    error_log("pipeline-servers error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to retrieve servers");
}
