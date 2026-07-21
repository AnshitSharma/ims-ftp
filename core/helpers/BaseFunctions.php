<?php
/**
 * BaseFunctions.php — shared helpers: JWT auth, ACL, component CRUD, users, dashboard.
 *
 * This is the SINGLE definition site for every function below. api.php and the
 * handler files must not redefine any of them — PHP will fatal on redeclare,
 * which is intentional (it makes a reintroduced duplicate loud instead of
 * silently shadowed, which is how the inventory API once lost UUID validation).
 */

// Include JWT Helper and ACL classes
require_once(__DIR__ . '/../auth/JWTHelper.php');
require_once(__DIR__ . '/../auth/ACL.php');

// Initialize JWT secret
$jwtSecret = defined('JWT_SECRET_KEY') ? JWT_SECRET_KEY : getenv('JWT_SECRET');
if (!$jwtSecret) {
    throw new RuntimeException('JWT_SECRET not configured');
}
JWTHelper::init($jwtSecret);

// Initialize permission cache (request-level caching for performance)
$GLOBALS['_permission_cache'] = [];

// Whitelist of valid component types (defense-in-depth for dynamic table names)
define('VALID_COMPONENT_TYPES', ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'chassis', 'pciecard', 'hbacard', 'sfp']);

function validateComponentType($type) {
    if (!in_array($type, VALID_COMPONENT_TYPES, true)) {
        throw new InvalidArgumentException("Invalid component type: $type");
    }
}

/**
 * Generate UUID v4
 */
function generateUUID() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Send JSON response with proper error handling
 */
function send_json_response($success, $authenticated, $code, $message, $data = null) {
    // Clean any output buffer
    if (ob_get_level()) {
        ob_clean();
    }

    http_response_code($code);
    header('Content-Type: application/json');

    $response = [
        'success' => (bool)$success,
        'authenticated' => (bool)$authenticated,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
        'code' => $code
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode($response, JSON_PRETTY_PRINT);
    exit();
}

/**
 * Safe session start (kept for backward compatibility)
 */
function safeSessionStart() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * JWT Authentication - Get authenticated user from JWT token
 */
function authenticateWithJWT($pdo) {
    try {
        $token = JWTHelper::getTokenFromHeader();

        if (!$token) {
            return false;
        }

        // Pass $pdo so verifyToken can consult revoked_tokens and the
        // password_changed_at cutoff. Without it, logout/password-reset
        // revocation is silently bypassed.
        $payload = JWTHelper::verifyToken($token, $pdo);

        // Get user from database
        $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname FROM users WHERE id = ?");
        $stmt->execute([$payload['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return false;
        }

        // Update last activity
        $stmt = $pdo->prepare("UPDATE auth_tokens SET last_used_at = NOW() WHERE user_id = ?");
        $stmt->execute([$user['id']]);

        return $user;
    } catch (Exception $e) {
        error_log("JWT Authentication failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Authenticate user with username/password
 */
function authenticateUser($pdo, $username, $password) {
    try {
        $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, password FROM users WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Always run exactly one bcrypt verification, even when the
        // username doesn't exist, so response timing can't be used to
        // enumerate valid usernames. The dummy hash is a syntactically
        // valid bcrypt hash; its result is discarded when $user is false.
        $hashToCheck = $user
            ? $user['password']
            : '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $passwordValid = password_verify($password, $hashToCheck);

        if ($user && $passwordValid) {
            unset($user['password']);
            return $user;
        }

        return false;
    } catch (Exception $e) {
        error_log("Authentication error: " . $e->getMessage());
        return false;
    }
}

/**
 * Initialize ACL System
 *
 * Performs lightweight check on each request; only runs expensive INSERT IGNORE
 * operations when permissions/roles are missing (first run or after schema update).
 */
function initializeACLSystem($pdo) {
    // Fast path: once the seeding checks below have passed, a flag file marks
    // the ACL system as initialized and we skip the ~5 metadata queries per
    // request. Delete logs/.acl_seeded to force a re-check (e.g. after running
    // ACL migrations/seeds or restoring the database).
    $seededFlagFile = __DIR__ . '/../../logs/.acl_seeded';
    if (file_exists($seededFlagFile)) {
        try {
            $GLOBALS['acl'] = new ACL($pdo);
            return true;
        } catch (Exception $e) {
            error_log("ACL initialization error (fast path): " . $e->getMessage());
            return false;
        }
    }

    try {
        // Check if ACL tables exist
        $stmt = $pdo->query("SHOW TABLES LIKE 'permissions'");
        if ($stmt->rowCount() == 0) {
            error_log("ACL tables not found. Please run database migrations.");
            return false;
        }

        $acl = new ACL($pdo);

        // Store ACL instance globally so handlers can reuse it
        $GLOBALS['acl'] = $acl;

        // Only run expensive initialization if permissions table is empty
        // (first deployment or after reset - not on every request)
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM permissions");
        $permCount = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($permCount['count'] == 0) {
            error_log("Initializing default ACL permissions...");
            $acl->initializeDefaultPermissions();
        }

        // Check if roles table is empty - if so, initialize roles
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM roles");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] == 0) {
            error_log("Initializing default ACL roles...");
            $acl->initializeDefaultRoles();
        }

        // Only reassign admin permissions if admin role exists and has fewer permissions than total
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'admin' LIMIT 1");
        $stmt->execute();
        $adminRole = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($adminRole) {
            $adminRoleId = $adminRole['id'];

            // Check if admin role is missing any permissions before doing INSERT IGNORE
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total FROM permissions WHERE id NOT IN
                (SELECT permission_id FROM role_permissions WHERE role_id = ?)
            ");
            $stmt->execute([$adminRoleId]);
            $missing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($missing['total'] > 0) {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO role_permissions (role_id, permission_id, granted)
                    SELECT ?, id, 1 FROM permissions
                ");
                $stmt->execute([$adminRoleId]);
            }
        }

        // All seeding checks passed - mark as initialized so subsequent
        // requests take the fast path. If logs/ isn't writable this fails
        // silently and we simply keep doing the full check each request.
        @file_put_contents($seededFlagFile, date('c'));

        return true;
    } catch (Exception $e) {
        error_log("ACL initialization error: " . $e->getMessage());
        return false;
    }
}

/**
 * Load user permission data into cache - OPTIMIZED: Loads all permissions in 1-2 queries
 */
function loadUserPermissionData($pdo, $userId) {
    $data = [
        'is_admin' => false,
        'permissions' => []
    ];

    try {
        // Query 1: Check if user has admin/super_admin role
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? AND r.name IN ('super_admin', 'admin')
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $data['is_admin'] = ($result['count'] > 0);

        // If not admin, load all permissions (direct + role-based) in single query
        if (!$data['is_admin']) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT ap.name
                FROM permissions ap
                WHERE ap.id IN (
                    SELECT permission_id FROM user_permissions WHERE user_id = ?
                    UNION
                    SELECT rp.permission_id
                    FROM user_roles ur
                    JOIN role_permissions rp ON ur.role_id = rp.role_id
                    WHERE ur.user_id = ? AND rp.granted = 1
                )
            ");
            $stmt->execute([$userId, $userId]);
            $data['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Exception $e) {
        error_log("Permission cache load error: " . $e->getMessage());
    }

    return $data;
}

/**
 * Check if user has specific permission - OPTIMIZED with request-level caching
 */
function hasPermission($pdo, $permission, $userId) {
    // Check cache first
    $cacheKey = "user_{$userId}";

    if (!isset($GLOBALS['_permission_cache'][$cacheKey])) {
        // Load all permissions for this user once per request
        $GLOBALS['_permission_cache'][$cacheKey] = loadUserPermissionData($pdo, $userId);
    }

    $cache = $GLOBALS['_permission_cache'][$cacheKey];

    // Admin bypass - has all permissions
    if ($cache['is_admin']) {
        return true;
    }

    // Check if permission exists in cached list
    return in_array($permission, $cache['permissions']);
}

/**
 * Get all permissions for a user - OPTIMIZED with request-level caching
 */
function getUserPermissions($pdo, $userId) {
    $cacheKey = "user_{$userId}";

    if (!isset($GLOBALS['_permission_cache'][$cacheKey])) {
        $GLOBALS['_permission_cache'][$cacheKey] = loadUserPermissionData($pdo, $userId);
    }

    $cache = $GLOBALS['_permission_cache'][$cacheKey];

    // Admin has all permissions
    if ($cache['is_admin']) {
        return ['*'];
    }

    return $cache['permissions'];
}

/**
 * Clear permission cache - call after permission changes
 */
function clearPermissionCache($userId = null) {
    if ($userId) {
        unset($GLOBALS['_permission_cache']["user_{$userId}"]);
    } else {
        $GLOBALS['_permission_cache'] = [];
    }
}

/**
 * Get all roles for a user
 */
function getUserRoles($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.id, r.name as role_name, r.display_name, r.description
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get user roles error: " . $e->getMessage());
        return [];
    }
}

/**
 * Check whether a user holds a specific role (by role name).
 *
 * Use this for role-gated modules where hasPermission() is unsuitable —
 * hasPermission() grants admin and super_admin an identical blanket bypass,
 * so it cannot distinguish the two. Rack View, for example, must be limited
 * to super_admin only, which requires an actual role check.
 */
function userHasRole($pdo, $userId, $roleName) {
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? AND r.name = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $roleName]);
        return (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("userHasRole error: " . $e->getMessage());
        return false;
    }
}

/**
 * Assign permission to user (direct grant, on top of role-based permissions).
 *
 * Resolves the permission name against the `permissions` table — the same
 * table loadUserPermissionData/hasPermission read from. (It previously used
 * the legacy `acl_permissions` table, whose IDs belong to a different
 * sequence, so grants landed on the wrong permission or none at all.)
 */
function assignPermissionToUser($pdo, $userId, $permission) {
    try {
        // Get permission ID
        $stmt = $pdo->prepare("SELECT id FROM permissions WHERE name = ?");
        $stmt->execute([$permission]);
        $permissionData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$permissionData) {
            return false; // Permission doesn't exist
        }

        // Check if already assigned
        $stmt = $pdo->prepare("SELECT id FROM user_permissions WHERE user_id = ? AND permission_id = ?");
        $stmt->execute([$userId, $permissionData['id']]);
        if ($stmt->fetch()) {
            return true; // Already assigned
        }

        // Assign permission
        $stmt = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_id, created_at) VALUES (?, ?, NOW())");
        $result = $stmt->execute([$userId, $permissionData['id']]);
        clearPermissionCache($userId);
        return $result;
    } catch (Exception $e) {
        error_log("Assign permission error: " . $e->getMessage());
        return false;
    }
}

/**
 * Revoke permission from user (direct grant only; role-based permissions
 * are unaffected). Same `permissions` table as assignPermissionToUser.
 */
function revokePermissionFromUser($pdo, $userId, $permission) {
    try {
        $stmt = $pdo->prepare("
            DELETE up FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? AND p.name = ?
        ");
        $result = $stmt->execute([$userId, $permission]);
        clearPermissionCache($userId);
        return $result;
    } catch (Exception $e) {
        error_log("Revoke permission error: " . $e->getMessage());
        return false;
    }
}

/**
 * Assign role to user
 */
function assignRoleToUser($pdo, $userId, $roleId) {
    try {
        // Check if already assigned
        $stmt = $pdo->prepare("SELECT id FROM user_roles WHERE user_id = ? AND role_id = ?");
        $stmt->execute([$userId, $roleId]);
        if ($stmt->fetch()) {
            return true; // Already assigned
        }

        // Assign role. Schema is user_roles(id, user_id, role_id, assigned_by,
        // assigned_at) — there is no created_at column; assigned_at defaults to
        // CURRENT_TIMESTAMP.
        $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
        $result = $stmt->execute([$userId, $roleId]);
        clearPermissionCache($userId);
        return $result;
    } catch (Exception $e) {
        error_log("Assign role error: " . $e->getMessage());
        return false;
    }
}

/**
 * Revoke role from user
 */
function revokeRoleFromUser($pdo, $userId, $roleId) {
    try {
        $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");
        $result = $stmt->execute([$userId, $roleId]);
        clearPermissionCache($userId);
        return $result;
    } catch (Exception $e) {
        error_log("Revoke role error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all roles
 */
function getAllRoles($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT id, name as role_name, display_name, description, is_system, is_default, created_at FROM roles ORDER BY name");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get all roles error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all permissions.
 *
 * Reads the `permissions` table (single source of truth). `name` is aliased
 * to `permission_name` to preserve the response shape clients already
 * consume from the acl-get_all_permissions endpoint.
 */
function getAllPermissions($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT id, name AS permission_name, description, category FROM permissions ORDER BY category, name");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Get all permissions error: " . $e->getMessage());
        return [];
    }
}

/**
 * Create new role
 */
function createRole($pdo, $name, $description = '') {
    try {
        // Generate display name from role name
        $displayName = ucwords(str_replace('_', ' ', $name));

        $stmt = $pdo->prepare("INSERT INTO roles (name, display_name, description, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
        $result = $stmt->execute([$name, $displayName, $description]);

        if ($result) {
            return $pdo->lastInsertId();
        }
        return false;
    } catch (Exception $e) {
        error_log("Create role error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update role
 */
function updateRole($pdo, $roleId, $name, $description = '') {
    try {
        // Generate display name from role name
        $displayName = ucwords(str_replace('_', ' ', $name));

        $stmt = $pdo->prepare("UPDATE roles SET name = ?, display_name = ?, description = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$name, $displayName, $description, $roleId]);
    } catch (Exception $e) {
        error_log("Update role error: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete role
 */
function deleteRole($pdo, $roleId) {
    try {
        // Start transaction
        $pdo->beginTransaction();

        // Remove role permissions
        $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $stmt->execute([$roleId]);

        // Remove user roles
        $stmt = $pdo->prepare("DELETE FROM user_roles WHERE role_id = ?");
        $stmt->execute([$roleId]);

        // Delete role
        $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
        $result = $stmt->execute([$roleId]);

        $pdo->commit();
        clearPermissionCache();
        return $result;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Delete role error: " . $e->getMessage());
        return false;
    }
}

/**
 * Server Management Functions
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

    foreach (VALID_COMPONENT_TYPES as $type) {
        $tableName = getComponentTableName($type);

        // This query is more efficient as it gets all counts in one go.
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN Status = 1 THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN Status = 2 THEN 1 ELSE 0 END) as in_use,
                SUM(CASE WHEN Status = 0 THEN 1 ELSE 0 END) as failed
            FROM $tableName
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

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

    foreach (VALID_COMPONENT_TYPES as $type) {
        $tableName = getComponentTableName($type);
        $sql = "SELECT *, '$type' as component_type FROM $tableName WHERE
                AssetTag LIKE ? OR
                SerialNumber LIKE ? OR
                Notes LIKE ? OR
                Location LIKE ?
                ORDER BY id DESC
                LIMIT ?";

        $escapedQuery = addcslashes($query, '%_\\');
        $searchTerm = '%' . $escapedQuery . '%';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);

        $typeResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results = array_merge($results, $typeResults);
    }

    // Newest-first across all component types, so the global limit below
    // doesn't arbitrarily favor types searched earlier in the loop
    usort($results, function ($a, $b) {
        return strtotime($b['CreatedAt'] ?? '1970-01-01') <=> strtotime($a['CreatedAt'] ?? '1970-01-01');
    });

    // Limit total results
    $results = array_slice($results, 0, $limit);

    return [
        'query' => $query,
        'results' => $results,
        'total_found' => count($results)
    ];
}

/**
 * Component Inventory Functions
 */

/**
 * Map component type to actual table name
 */
function getComponentTableName($type) {
    validateComponentType($type);
    return $type . 'inventory';
}

/**
 * Three-letter asset-tag code for a component type.
 *
 * MUST stay in lock-step with seeder 2026_07_22_001, which backfilled every
 * pre-existing inventory row using these exact codes. Changing one here without
 * a migration would split a table's tags across two formats.
 */
function getComponentAssetTagCode($type) {
    validateComponentType($type);

    $codes = [
        'cpu'         => 'CPU',
        'ram'         => 'RAM',
        'storage'     => 'STO',
        'motherboard' => 'MBD',
        'nic'         => 'NIC',
        'caddy'       => 'CAD',
        'chassis'     => 'CHS',
        'pciecard'    => 'PCI',
        'hbacard'     => 'HBA',
        'sfp'         => 'SFP',
    ];

    if (!isset($codes[$type])) {
        throw new InvalidArgumentException("No asset tag code defined for component type: $type");
    }

    return $codes[$type];
}

/**
 * Build the asset tag for a unit from its own inventory primary key.
 *
 * The tag is the system-issued identity a technician can physically sticker on
 * hardware whose manufacturer serial is missing or unreadable. Deriving it from
 * the row's own auto-increment ID makes it unique BY CONSTRUCTION — no counter
 * table, no contention, no collision — and matches the backfill formula in
 * seeder 2026_07_22_001 exactly.
 *
 * IDs past 999999 simply produce a longer tag; the column has room to 20 chars.
 */
function formatAssetTag($type, $inventoryId) {
    return sprintf('BDC-%s-%06d', getComponentAssetTagCode($type), (int)$inventoryId);
}

/**
 * Convert CamelCase to snake_case
 */
function convertCamelToSnake($input) {
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $input));
}

/**
 * Get field mapping for component type (snake_case to CamelCase)
 */
function getComponentFieldMap($type) {
    // Common fields for all components
    $commonFields = [
        'uuid' => 'UUID',
        'serial_number' => 'SerialNumber',
        'status' => 'Status',
        'server_uuid' => 'ServerUUID',
        'location' => 'Location',
        'rack_position' => 'RackPosition',
        'purchase_date' => 'PurchaseDate',
        'installation_date' => 'InstallationDate',
        'warranty_end_date' => 'WarrantyEndDate',
        'fail_date' => 'FailDate',
        'flag' => 'Flag',
        'notes' => 'Notes',
        'vendor_id' => 'VendorID'
    ];

    // Component-specific fields
    $specificFields = [
        'nic' => [
            'mac_address' => 'MacAddress',
            'ip_address' => 'IPAddress',
            'network_name' => 'NetworkName'
        ],
        'pciecard' => [
            'card_type' => 'CardType',
            'attachables' => 'Attachables'
        ]
    ];

    return array_merge($commonFields, $specificFields[$type] ?? []);
}

/**
 * Build the WHERE clause + params for a component inventory search term.
 * Shared by getComponentsByType / getComponentCountByType so the row query
 * and the count query can never disagree on what matches.
 */
function buildComponentSearchWhere($search, &$params) {
    if ($search === '') {
        return '';
    }
    $term = '%' . addcslashes($search, '%_\\') . '%';
    $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
    return "WHERE (AssetTag LIKE ? OR SerialNumber LIKE ? OR UUID LIKE ? OR Notes LIKE ? OR Location LIKE ? OR RackPosition LIKE ?)";
}

/**
 * Get components by type.
 * $limit === null preserves the original return-everything behavior.
 */
function getComponentsByType($pdo, $type, $limit = null, $offset = 0, $search = '') {
    $tableName = getComponentTableName($type);

    try {
        $params = [];
        $where = buildComponentSearchWhere($search, $params);

        $sql = "SELECT * FROM $tableName $where ORDER BY id DESC";
        if ($limit !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = (int)$limit;
            $params[] = max(0, (int)$offset);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting $type components from table $tableName: " . $e->getMessage());
        return [];
    }
}

/**
 * Count components of a type matching an optional search term.
 */
function getComponentCountByType($pdo, $type, $search = '') {
    $tableName = getComponentTableName($type);

    try {
        $params = [];
        $where = buildComponentSearchWhere($search, $params);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $tableName $where");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error counting $type components in table $tableName: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get component by ID
 */
function getComponentById($pdo, $type, $id) {
    $tableName = getComponentTableName($type);

    try {
        $stmt = $pdo->prepare("SELECT * FROM $tableName WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting $type component by ID from table $tableName: " . $e->getMessage());
        return null;
    }
}

/**
 * Return the real column list for an inventory table, keyed by lower-case
 * name for O(1) lookup. Used by addComponent/updateComponent to whitelist
 * incoming fields — anything the caller sends that isn't a real column in
 * the target table is dropped (not passed to PDO).
 *
 * Cached per-request in a static so we only hit INFORMATION_SCHEMA once
 * per table per request.
 */
function getInventoryTableColumns($pdo, $tableName) {
    static $cache = [];
    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$tableName]);
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Map lower-case → canonical. Callers filter case-insensitively and
    // then use the canonical casing in the generated SQL.
    $byLower = [];
    foreach ($columns as $col) {
        $byLower[strtolower($col)] = $col;
    }
    $cache[$tableName] = $byLower;
    return $byLower;
}

/**
 * Columns that must NEVER be settable through the component CRUD endpoint,
 * even if the caller has a matching form field. Primary keys and audit
 * timestamps belong to the system, not the client.
 */
function getBlockedComponentColumns() {
    return [
        'id',               // primary key
        'assettag',         // system-issued unit identity — never client-settable
        'asset_tag',
        'created_at',
        'updated_at',
        'createdat',
        'updatedat',
    ];
}

/**
 * Add component
 *
 * Hardened against mass-assignment and UUID spoofing:
 *   1. INFORMATION_SCHEMA whitelist — only real columns of the target
 *      inventory table are accepted; extras are silently dropped.
 *   2. Blocked columns (id, created_at, ...) are stripped even if present.
 *   3. If a UUID is supplied it MUST exist in the ims-data JSON spec for
 *      this component type. No UUID? We generate one (legacy behaviour for
 *      virtual / custom records).
 */
function addComponent($pdo, $type, $data, $userId) {
    try {
        // Get the correct table name
        $tableName = getComponentTableName($type);

        // Get dynamic field mapping for this component type (snake_case →
        // CamelCase). Fields not in the map pass through untouched and
        // rely on the column whitelist below.
        $fieldMap = getComponentFieldMap($type);

        // Convert field names to match database columns
        $convertedData = [];
        foreach ($data as $key => $value) {
            $dbColumn = $fieldMap[$key] ?? $key;
            $convertedData[$dbColumn] = $value;
        }

        // Generate UUID if not provided
        if (!isset($convertedData['UUID']) || empty($convertedData['UUID'])) {
            $convertedData['UUID'] = generateUUID();
        } else {
            // SECURITY: when the caller supplies a UUID it must reference a
            // real component spec in ims-data/.
            require_once(__DIR__ . '/../models/components/ComponentDataService.php');
            $componentService = ComponentDataService::getInstance();
            if (!$componentService->validateComponentUuid($type, $convertedData['UUID'])) {
                throw new InvalidArgumentException(
                    "Component UUID not found in $type specifications"
                );
            }
        }

        // Whitelist against real table columns
        $allowedCols = getInventoryTableColumns($pdo, $tableName);
        $blocked     = array_flip(getBlockedComponentColumns());

        $safeData = [];
        foreach ($convertedData as $col => $value) {
            $lc = strtolower($col);
            if (isset($blocked[$lc])) {
                continue;
            }
            if (!isset($allowedCols[$lc])) {
                // Unknown column — silently drop rather than error so legacy
                // clients sending extra fields don't break.
                continue;
            }
            // Use the canonical casing from the DB schema
            $safeData[$allowedCols[$lc]] = $value;
        }

        // A unit with no readable manufacturer serial is normal (worn label,
        // white-box part, pull) — store that absence as NULL, never ''.
        // SerialNumber carries a UNIQUE index: MySQL permits many NULLs but only
        // ONE ''. An empty string from the form would therefore let the first
        // serial-less unit save and make the *second* one die on a duplicate-key
        // error. The unit stays addressable either way via its AssetTag.
        if (array_key_exists('SerialNumber', $safeData)
            && trim((string)$safeData['SerialNumber']) === '') {
            $safeData['SerialNumber'] = null;
        }

        if (empty($safeData)) {
            throw new InvalidArgumentException("No valid fields provided for $type component");
        }

        // Defence in depth: even though $safeData keys come from
        // INFORMATION_SCHEMA we keep the identifier regex as a belt-and-
        // braces check before the column names hit the SQL string.
        $columns = array_keys($safeData);
        foreach ($columns as $col) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                throw new InvalidArgumentException("Invalid column name: $col");
            }
        }
        $placeholders = array_fill(0, count($columns), '?');
        $values = array_values($safeData);

        $sql = "INSERT INTO $tableName (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        error_log("Inserting $type data into $tableName: " . json_encode(array_keys($safeData)));

        // The row and its asset tag must land together: a row that committed
        // without a tag would be a unit the system cannot name. The tag is
        // derived from the auto-increment ID, so it can only be written after
        // the INSERT — hence insert + tag in one transaction.
        //
        // Only own the transaction if no caller already opened one (import and
        // build flows call this inside their own); committing someone else's
        // transaction here would publish their half-finished work.
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare($sql);

            if (!$stmt->execute($values)) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return false;
            }

            $newId = (int)$pdo->lastInsertId();
            $assetTag = formatAssetTag($type, $newId);

            $tagStmt = $pdo->prepare("UPDATE `$tableName` SET AssetTag = ? WHERE ID = ?");
            $tagStmt->execute([$assetTag, $newId]);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'id' => $newId,
                'uuid' => $safeData['UUID'] ?? ($convertedData['UUID'] ?? null),
                'asset_tag' => $assetTag
            ];

        } catch (Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

    } catch (InvalidArgumentException $e) {
        throw $e;
    } catch (Exception $e) {
        error_log("Error adding $type component: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Update component
 *
 * Same whitelist rules as addComponent. Additionally forbids changing the
 * UUID — once an inventory record is tied to a spec it should not be
 * retargeted via a PATCH.
 */
function updateComponent($pdo, $type, $id, $data, $userId) {
    try {
        // Get the correct table name
        $tableName = getComponentTableName($type);

        // Get dynamic field mapping for this component type
        $fieldMap = getComponentFieldMap($type);

        // Convert field names to match database columns
        $convertedData = [];
        foreach ($data as $key => $value) {
            $dbColumn = $fieldMap[$key] ?? $key;
            $convertedData[$dbColumn] = $value;
        }

        // Whitelist against real table columns
        $allowedCols = getInventoryTableColumns($pdo, $tableName);
        $blocked     = array_flip(getBlockedComponentColumns());
        // UUID is fine to set on insert but must not change on update.
        $blocked['uuid'] = true;

        $safeData = [];
        foreach ($convertedData as $col => $value) {
            $lc = strtolower($col);
            if (isset($blocked[$lc])) {
                continue;
            }
            if (!isset($allowedCols[$lc])) {
                continue;
            }
            $safeData[$allowedCols[$lc]] = $value;
        }

        if (empty($safeData)) {
            throw new InvalidArgumentException("No valid fields provided for $type update");
        }

        $columns = array_keys($safeData);
        foreach ($columns as $col) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                throw new InvalidArgumentException("Invalid column name: $col");
            }
        }
        $setClause = implode(' = ?, ', $columns) . ' = ?';
        $values = array_values($safeData);
        $values[] = $id; // Add ID for WHERE clause

        $sql = "UPDATE $tableName SET $setClause WHERE ID = ?";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute($values);

    } catch (InvalidArgumentException $e) {
        throw $e;
    } catch (Exception $e) {
        error_log("Error updating $type component: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Delete component
 */
function deleteComponent($pdo, $type, $id, $userId) {
    $tableName = getComponentTableName($type);

    try {
        $stmt = $pdo->prepare("DELETE FROM $tableName WHERE id = ?");
        return $stmt->execute([$id]);

    } catch (Exception $e) {
        error_log("Error deleting $type component from table $tableName: " . $e->getMessage());
        throw $e;
    }
}

/**
 * User Management Functions
 */

/**
 * Get all users
 */
function getAllUsers($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, status, created_at FROM users ORDER BY username");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($users)) {
            return [];
        }

        // Attach each user's assigned roles in a single extra query (no N+1).
        // Additive: existing callers ignore the new `roles` field.
        $rolesByUser = [];
        $roleStmt = $pdo->query("
            SELECT ur.user_id, r.id, r.name, r.display_name
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            ORDER BY r.display_name
        ");
        foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rolesByUser[$row['user_id']][] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'display_name' => $row['display_name'],
            ];
        }

        foreach ($users as &$user) {
            $user['roles'] = $rolesByUser[$user['id']] ?? [];
        }
        unset($user);

        return $users;
    } catch (Exception $e) {
        error_log("Error getting all users: " . $e->getMessage());
        return [];
    }
}

/**
 * Create user
 */
function createUser($pdo, $username, $email, $password, $firstname, $lastname) {
    try {
        // Check if username/email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return false; // User already exists
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, firstname, lastname, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'active', NOW())
        ");
        $result = $stmt->execute([$username, $email, $hashedPassword, $firstname, $lastname]);

        if ($result) {
            return $pdo->lastInsertId();
        }

        return false;

    } catch (Exception $e) {
        error_log("Error creating user: " . $e->getMessage());
        return false;
    }
}

/**
 * Update user
 */
function updateUser($pdo, $userId, $data) {
    try {
        $allowedFields = ['username', 'email', 'password', 'firstname', 'lastname', 'status'];
        $data = array_intersect_key($data, array_flip($allowedFields));
        if (empty($data)) {
            return false;
        }

        // Hash password before storage
        if (isset($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $columns = array_keys($data);
        $setClause = implode(' = ?, ', $columns) . ' = ?';
        $values = array_values($data);
        $values[] = $userId;

        $sql = "UPDATE users SET $setClause WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute($values);

    } catch (Exception $e) {
        error_log("Error updating user: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete user
 */
function deleteUser($pdo, $userId) {
    try {
        // Start transaction
        $pdo->beginTransaction();

        // Delete user role assignments
        $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
        $stmt->execute([$userId]);

        // Delete user permission assignments
        $stmt = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?");
        $stmt->execute([$userId]);

        // Delete user auth tokens
        $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
        $stmt->execute([$userId]);

        // Delete user
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $result = $stmt->execute([$userId]);

        $pdo->commit();
        return $result;

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error deleting user: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user by ID
 */
function getUserById($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, status, created_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting user by ID: " . $e->getMessage());
        return null;
    }
}

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
