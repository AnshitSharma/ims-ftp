<?php
/**
 * Dashboard.php — split out of BaseFunctions.php (audit Phase 4, roadmap item 23).
 *
 * Dashboard aggregation and global search — the two cross-cutting read paths that touch every
 * component inventory table at once, neither of which is CRUD for a single type (Inventory.php)
 * nor an ACL concern. Mechanical split: bodies unchanged, still global functions.
 */

/**
 * Check if server system is initialized
 */
function serverSystemInitialized($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'server_configurations'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

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

/**
 * Perform global search across all component inventory tables.
 *
 * Throws on database failure — callers must catch and return a 5xx, so an
 * outage is distinguishable from "no results".
 */
function performGlobalSearch($pdo, $query, $limit, $user) {
    $results = [];

    // JSON-011: make search reach the MODEL, not just the unit's own columns.
    //
    // This function searched AssetTag, SerialNumber, Notes and Location only -- none of which
    // carry what the hardware IS. Verified live on 2026-09-16 before this change: "PERC"
    // returned 0 results with four PERC models and four units in stock, "8480" returned 0,
    // and the exact Samsung part number M393A4K40BB2-CTD returned 0. "Samsung" returned 20,
    // but only because the word appears in some Notes fields -- coincidence, not search.
    //
    // A unit's model lives in ims-data, keyed by UUID, so the query is first resolved against
    // the catalogue and the matching spec uuids are then added to each type's WHERE. That
    // needs no new table: ModelSearch reads component_models when it exists and the spec
    // files otherwise, so this works before the Phase 2.1 seeder has been run.
    $modelUuidsByType = [];
    $modelSearchFile = __DIR__ . '/../models/components/ModelSearch.php';
    if (is_readable($modelSearchFile)) {
        try {
            require_once $modelSearchFile;
            // withCounts=false: the counts cost one query per type and nothing here reads
            // them. MAX_LIMIT caps the uuid list, which also bounds the IN() clause below.
            $modelHits = ModelSearch::search($pdo, $query, null, ModelSearch::MAX_LIMIT, false);
            foreach ($modelHits['models'] as $model) {
                $modelUuidsByType[$model['component_type']][] = $model['spec_uuid'];
            }
        } catch (Throwable $e) {
            // Catalogue unreadable: fall back to exactly the old behaviour rather than
            // failing a search that would otherwise return unit-column matches.
            error_log('performGlobalSearch: model resolution skipped: ' . $e->getMessage());
            $modelUuidsByType = [];
        }
    }

    foreach (VALID_COMPONENT_TYPES as $type) {
        // Skip a type whose table has not been migrated yet -- see
        // inventoryTableExists(). A fleet-wide search must not 500 because one
        // type is mid-rollout.
        if (!inventoryTableExists($pdo, $type)) {
            continue;
        }

        // SEARCH IS NOT A SEPARATE DOOR. [M-02] This function took $user and
        // ignored it, so `search.use` alone read every inventory table —
        // including the types whose `.view` permission the account had
        // deliberately been denied. Searching is reading; it obeys the same
        // permission the list view obeys.
        if (!hasPermission($pdo, $type . '.view', $user['id'] ?? null)) {
            continue;
        }

        $tableName = getComponentTableName($type);

        $escapedQuery = addcslashes($query, '%_\\');
        $searchTerm = '%' . $escapedQuery . '%';

        $conditions = [
            'AssetTag LIKE ?',
            'SerialNumber LIKE ?',
            'Notes LIKE ?',
            'Location LIKE ?',
        ];
        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];

        // Units whose MODEL matched the query. The uuids come from the catalogue, never from
        // the request, and are bound as parameters regardless.
        $matchedModelUuids = $modelUuidsByType[$type] ?? [];
        if ($matchedModelUuids) {
            $conditions[] = 'UUID IN (' . implode(',', array_fill(0, count($matchedModelUuids), '?')) . ')';
            foreach ($matchedModelUuids as $modelUuid) {
                $params[] = $modelUuid;
            }
        }

        // Named columns, not SELECT *. [M-02] A hit is "this part exists, here is
        // what it is and where" — the fields a person needs to go and find the
        // unit. A star projection hands back every column the table will ever
        // grow, which is how a search result becomes an export of whatever the
        // next seeder adds.
        $sql = "SELECT ID, UUID, AssetTag, SerialNumber, Status, status_v2, ServerUUID,
                       Location, location_uuid, RackPosition, StoreLocation, Notes, CreatedAt,
                       '$type' as component_type
                FROM $tableName WHERE
                " . implode(' OR ', $conditions) . "
                ORDER BY ID DESC
                LIMIT ?";

        $params[] = $limit;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $typeResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Now that the projection names columns, a table that has not caught
            // up with a seeder yet fails HERE rather than returning extra fields.
            // Same rule as inventoryTableExists() above: one type mid-rollout must
            // not take the whole search down.
            error_log("performGlobalSearch: skipping $type: " . $e->getMessage());
            continue;
        }
        $results = array_merge($results, $typeResults);
    }

    // Newest-first across all component types, so the global limit below
    // doesn't arbitrarily favor types searched earlier in the loop
    usort($results, function ($a, $b) {
        return strtotime($b['CreatedAt'] ?? '1970-01-01') <=> strtotime($a['CreatedAt'] ?? '1970-01-01');
    });

    // Limit total results
    $results = array_slice($results, 0, $limit);

    // Attach the physical address to every hit. Searching for a serial number
    // and being told the part exists, without being told where it is, answers
    // half the question people actually ask.
    try {
        require_once(__DIR__ . '/../models/location/LocationResolver.php');
        LocationResolver::enrichComponentRows($pdo, $results);
    } catch (Throwable $e) {
        // Search must still return its hits.
        error_log("[location] global search enrichment failed: " . $e->getMessage());
    }

    return [
        'query' => $query,
        'results' => $results,
        'total_found' => count($results)
    ];
}
