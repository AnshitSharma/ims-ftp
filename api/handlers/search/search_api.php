<?php
/**
 * Search handler — global inventory search.
 *
 * Included by api/api.php for the `search` module.
 */

function handleSearchOperations($operation, $user) {
    global $pdo;

    if (!hasPermission($pdo, 'search.use', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for search operations");
    }

    switch ($operation) {
        case 'global':
            $query = $_GET['q'] ?? $_POST['q'] ?? '';
            $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 20);

            if (empty($query)) {
                send_json_response(0, 1, 400, "Search query is required");
            }

            try {
                $results = performGlobalSearch($pdo, $query, $limit, $user);
            } catch (Exception $e) {
                // performGlobalSearch throws on DB failure so an outage is a
                // 500, not a 200 with zero results.
                error_log("Error performing global search: " . $e->getMessage());
                send_json_response(0, 1, 500, "Search failed");
            }
            send_json_response(1, 1, 200, "Search completed", $results);
            break;

        default:
            send_json_response(0, 1, 400, "Invalid search operation: $operation");
    }
}
