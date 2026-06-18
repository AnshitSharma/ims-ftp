<?php
/**
 * Dashboard handler — aggregate counts and activity logs.
 *
 * Included by api/api.php for the `dashboard` module.
 */

function handleDashboardOperations($operation, $user) {
    global $pdo;

    if (!hasPermission($pdo, 'dashboard.view', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for dashboard access");
    }

    switch ($operation) {
        case 'get_data':
            try {
                $dashboardData = getDashboardData($pdo, $user);
            } catch (Exception $e) {
                // getDashboardData throws on DB failure so an outage is a 500,
                // not a 200 with an error payload the frontend renders as data.
                error_log("Error getting dashboard data: " . $e->getMessage());
                send_json_response(0, 1, 500, "Unable to fetch dashboard data");
            }
            send_json_response(1, 1, 200, "Dashboard data retrieved", $dashboardData);
            break;

        case 'get-logs':
            // Admin/super admin only
            if (!hasPermission($pdo, 'acl.manage', $user['id']) && !hasPermission($pdo, 'users.view', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions to view activity logs");
            }
            $limit = max(1, min(200, (int)(($_GET['limit'] ?? $_POST['limit'] ?? 50))));
            $offset = max(0, (int)(($_GET['offset'] ?? $_POST['offset'] ?? 0)));
            $stmt = $pdo->prepare("
                SELECT il.id, il.user_id, u.username, il.component_type, il.component_id,
                       il.action, il.notes, il.ip_address, il.created_at
                FROM inventory_log il
                LEFT JOIN users u ON il.user_id = u.id
                ORDER BY il.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $countStmt = $pdo->query("SELECT COUNT(*) FROM inventory_log");
            $total = (int)$countStmt->fetchColumn();

            send_json_response(1, 1, 200, "Activity logs retrieved", [
                'logs' => $logs,
                'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]
            ]);
            break;

        default:
            send_json_response(0, 1, 400, "Invalid dashboard operation: $operation");
    }
}
