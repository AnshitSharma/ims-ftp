<?php
/**
 * Dashboard.php — split out of BaseFunctions.php (audit Phase 4, roadmap item 23).
 *
 * Dashboard aggregation and global search — the two cross-cutting read paths that touch every
 * component inventory table at once, neither of which is CRUD for a single type (Inventory.php)
 * nor an ACL concern. Mechanical split: bodies unchanged, still global functions.
 */

/**
 * Get dashboard data: per-type component counts with status breakdown,
 * plus server configuration counts.
 *
 * Throws on database failure — callers must catch and return a 5xx. (It
 * previously swallowed the exception and returned an ['error' => ...]
 * payload inside a 200 response, which made DB outages look like data.)
 */
function getDashboardData($pdo, $user) {
    $data = [];

    // Get component counts
    $componentCounts = [];
    $totalComponents = 0;

    // JSON-014: one round trip for all twelve types instead of twelve.
    //
    // Each per-type aggregate was already a single pass (SUM(CASE WHEN Status...)), so the
    // scan cost was never the problem -- the round trips were. UNION ALL folds them into one
    // statement; each branch still scans its own table exactly once, so the work the server
    // does is unchanged and only the number of client/server exchanges drops.
    //
    // Measured against production (968 rows across 12 tables): the whole request was already
    // 0.15 s, so this is a small win, not a fix for a slow endpoint. It is worth having
    // because the cost grows with the number of TYPES, and Phase 3 adds PSU and GPU.
    //
    // The table names are NOT user input -- they come from getComponentTableName() over the
    // VALID_COMPONENT_TYPES constant, which validateComponentType() gates. They are still
    // whitelisted below rather than interpolated on trust.
    $branches = [];
    foreach (VALID_COMPONENT_TYPES as $type) {
        // A type registered in code but not yet migrated contributes no branch and reports
        // zeros, rather than 500-ing this whole aggregate -- see inventoryTableExists().
        if (!inventoryTableExists($pdo, $type)) {
            continue;
        }

        $tableName = getComponentTableName($type);
        if (!preg_match('/^[a-z0-9_]+$/i', $tableName)) {
            continue; // unreachable given the constant, but this string is interpolated
        }

        $branches[] = "
            SELECT
                " . $pdo->quote($type) . " AS component_type,
                COUNT(*) AS total,
                SUM(CASE WHEN Status = 1 THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN Status = 2 THEN 1 ELSE 0 END) AS in_use,
                SUM(CASE WHEN Status = 0 THEN 1 ELSE 0 END) AS failed
            FROM $tableName";
    }

    $rowsByType = [];
    if ($branches) {
        $stmt = $pdo->query(implode(' UNION ALL ', $branches));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rowsByType[$row['component_type']] = $row;
        }
    }

    // Iterate the constant, not the result set, so every type appears in the response in the
    // canonical order even when its table is absent or empty. The frontend renders a fixed
    // grid of tiles and a missing key would render as a blank tile, not as a zero.
    foreach (VALID_COMPONENT_TYPES as $type) {
        $result = $rowsByType[$type] ?? null;

        $stats = [
            'total' => (int)($result['total'] ?? 0),
            'available' => (int)($result['available'] ?? 0),
            'in_use' => (int)($result['in_use'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0)
        ];

        $componentCounts[$type] = $stats;
        $totalComponents += $stats['total'];
    }

    // Get server counts
    $serverStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN configuration_status = 0 THEN 1 ELSE 0 END) as draft,
            SUM(CASE WHEN configuration_status = 1 THEN 1 ELSE 0 END) as validated,
            SUM(CASE WHEN configuration_status = 2 THEN 1 ELSE 0 END) as built,
            SUM(CASE WHEN configuration_status = 3 THEN 1 ELSE 0 END) as finalized
        FROM server_configurations
        WHERE is_virtual = 0
    ");
    $serverStmt->execute();
    $serverResult = $serverStmt->fetch(PDO::FETCH_ASSOC);

    $serverStats = [
        'total' => (int)($serverResult['total'] ?? 0),
        'draft' => (int)($serverResult['draft'] ?? 0),
        'validated' => (int)($serverResult['validated'] ?? 0),
        'built' => (int)($serverResult['built'] ?? 0),
        'finalized' => (int)($serverResult['finalized'] ?? 0)
    ];

    $componentCounts['servers'] = $serverStats;

    $data['component_counts'] = $componentCounts;
    $data['total_components'] = $totalComponents + $serverStats['total'];

    // Recent activity (if you have activity logs)
    $data['recent_activity'] = [];

    return $data;
}
