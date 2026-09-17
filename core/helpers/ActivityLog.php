<?php
/**
 * ActivityLog.php — split out of BaseFunctions.php (audit Phase 4, roadmap item 23).
 *
 * Audit-trail writing and the shared filter-building helper the activity log's list/count
 * queries both use. Mechanical split: function bodies unchanged, still global functions.
 */


/**
 * Log activity for audit trail
 */
function logActivity($pdo, $userId, $action, $module, $objectId = null, $description = '') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO inventory_log (user_id, component_type, component_id, action, notes, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        return $stmt->execute([
            $userId, $module, $objectId, $action, $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("[logActivity] Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Build WHERE clauses + bindings for filtering inventory_log listings
 * (search keyword, user, date range). Shared by any handler that lists
 * activity logs so filtering stays consistent across them.
 *
 * @return array [string[] $where, array $bindings]
 */
function buildInventoryLogFilters(array $params) {
    $where = [];
    $bindings = [];

    if (!empty($params['search'])) {
        // One placeholder per occurrence: PDO runs with ATTR_EMULATE_PREPARES=false,
        // and native prepares reject a named placeholder reused across the statement
        // (SQLSTATE HY093), which turned any search into a 500.
        $where[] = "(u.username LIKE :search_username OR il.action LIKE :search_action"
            . " OR il.notes LIKE :search_notes OR CAST(il.component_id AS CHAR) LIKE :search_component)";
        $term = '%' . $params['search'] . '%';
        $bindings[':search_username'] = $term;
        $bindings[':search_action'] = $term;
        $bindings[':search_notes'] = $term;
        $bindings[':search_component'] = $term;
    }
    if (!empty($params['user_id'])) {
        $where[] = "il.user_id = :user_id";
        $bindings[':user_id'] = (int)$params['user_id'];
    }
    if (!empty($params['date_from'])) {
        $where[] = "il.created_at >= :date_from";
        $bindings[':date_from'] = $params['date_from'] . ' 00:00:00';
    }
    if (!empty($params['date_to'])) {
        $where[] = "il.created_at <= :date_to";
        $bindings[':date_to'] = $params['date_to'] . ' 23:59:59';
    }

    return [$where, $bindings];
}
