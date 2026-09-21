<?php
/**
 * Acl.php — split out of BaseFunctions.php (audit Phase 4, roadmap item 23).
 *
 * Authentication, session, and permission/role functions. Mechanical split: function bodies
 * are unchanged, still global (no namespace, no class wrapping) — every call site anywhere in
 * the codebase keeps calling these by the same bare name. Loaded via BaseFunctions.php's
 * require_once chain, which also carries the JWTHelper/ACL/TemporaryAccessManager requires and
 * the request-scoped $GLOBALS['_permission_cache'] these functions read and write.
 */

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

        // Get user from database.
        //
        // The status filter matches authenticateUser()'s login query (2026-09-13).
        // Without it, deactivating an account stopped that person LOGGING IN but
        // did nothing to the access token already in their hands: it stayed valid
        // for the rest of its 24h life, and refresh could extend that further.
        // Deactivation has to take effect on the next request, not the next day.
        $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$payload['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return false;
        }

        // A-L15: the per-request `UPDATE auth_tokens SET last_used_at = NOW()
        // WHERE user_id = ?` that used to live here has been removed.
        //   * It was scoped to the USER, not the presented token, so a user with five
        //     active sessions had all five rows stamped on every request -- which
        //     defeats the only purpose of the column (spotting a stale session).
        //   * auth_tokens holds REFRESH tokens; this path authenticates an ACCESS
        //     token, so the rows being stamped were not the credential in use.
        //   * It was an unconditional write on the hot path, executed before any
        //     permission check, taking a row lock per request.
        // The column is now stamped in JWTHelper::verifyRefreshToken(), against the
        // single row whose token was actually presented.

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

        // An admin's EFFECTIVE set is every permission there is. [F-13 part 2]
        //
        // This used to leave `permissions` empty for an admin and rely on the
        // is_admin bypass below, which is why the UI could not be told what an
        // admin may do: permissions-get_user_permissions asked
        // ACL::hasPermission() — an evaluator with NO admin bypass — and
        // published whatever the role rows happened to carry. Two evaluators,
        // two answers, and the screen showed the narrower one.
        //
        // The bypass in hasPermission() still decides, and deliberately: a
        // permission whose seeder has not been run yet is absent from this table,
        // and an admin must not be locked out of a new action for the length of
        // that window. This list is the effective set for PUBLICATION and for
        // every non-admin check.
        if ($data['is_admin']) {
            $all = $pdo->query("SELECT name FROM permissions");
            $data['permissions'] = $all ? $all->fetchAll(PDO::FETCH_COLUMN) : [];
        }

        // If not admin, load all permissions (direct + role-based) in single query.
        // Direct grants in user_permissions may be TEMPORARY: activeGrantClause()
        // drops the ones that have expired or been revoked, and returns an empty
        // string on a database where the expiry migration hasn't been applied yet
        // (in which case every direct grant is permanent, exactly as before).
        if (!$data['is_admin']) {
            // globalOnly: grants scoped to a single server configuration are
            // excluded here on purpose, so they can never widen a general
            // permission check. server_api.php consults them per-configuration.
            $activeGrant = TemporaryAccessManager::activeGrantClause($pdo, '', true);
            $stmt = $pdo->prepare("
                SELECT DISTINCT ap.name
                FROM permissions ap
                WHERE ap.id IN (
                    SELECT permission_id FROM user_permissions
                    WHERE user_id = ?{$activeGrant}
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
 * The ONE effective-capability evaluator. [F-13 part 2]
 *
 * Two lived in this codebase: this one, and ACL::hasPermission(), which had no
 * admin bypass — so the same account could be allowed an action by the endpoint
 * and told it could not perform it by the screen. ACL::hasPermission() now
 * delegates here, and this is the single answer to "what may this user do".
 *
 * @return array{is_admin:bool, permissions:string[]}
 */
function effectiveCapabilities($pdo, $userId) {
    $cacheKey = "user_{$userId}";
    if (!isset($GLOBALS['_permission_cache'][$cacheKey])) {
        $GLOBALS['_permission_cache'][$cacheKey] = loadUserPermissionData($pdo, $userId);
    }
    return $GLOBALS['_permission_cache'][$cacheKey];
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
 * Read the server configuration UUID this request is about, if any.
 *
 * Handlers accept it from POST or GET under a couple of spellings; this mirrors
 * what they do so the permission layer looks at the same value they will.
 * Returns null when the operation names no configuration (server-create-start,
 * server-list-configs, ...), which is exactly when a scoped grant must NOT help.
 */
function requestedConfigUuid() {
    $candidates = [
        $_POST['config_uuid'] ?? null,
        $_GET['config_uuid'] ?? null,
        $_POST['configUuid'] ?? null,
        $_GET['configUuid'] ?? null,
        $_POST['server_uuid'] ?? null,
        $_GET['server_uuid'] ?? null,
    ];

    foreach ($candidates as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return null;
}

/**
 * COARSE gate fallback for per-configuration temporary access.
 *
 * Grants scoped to a single configuration are deliberately excluded from the
 * flat permission list (see loadUserPermissionData), so hasPermission() cannot
 * see them — otherwise "edit server X" would satisfy every server.edit check in
 * the system. This is the one place that consults them, and only when the
 * request actually names a configuration.
 *
 * The FINE half — is this the right configuration — is enforced in
 * server_api.php via userCanActOnConfig().
 */
function hasScopedPermissionForRequest($pdo, $permission, $userId) {
    $configUuid = requestedConfigUuid();
    if ($configUuid === null) {
        return false;
    }

    static $manager = null;
    if ($manager === null) {
        $manager = new TemporaryAccessManager($pdo);
    }

    return $manager->hasScopedPermission($userId, $permission, $configUuid);
}

/**
 * Keep a build permission that came from a Request as narrow as the Request.
 *
 * `server-add-component` is gated on server.create, which says nothing about
 * WHICH hardware may be fitted — so an approval for "add an SFP to web-01"
 * would otherwise fit a CPU, a motherboard, anything, into that build. Same gap
 * on remove/replace (server.edit / server.replace). The request DID say which
 * hardware: its component permissions. So when the caller's build access is a
 * temporary grant, the component type this call names must be one they hold the
 * matching permission on — which, for a temporary grant, is exactly the set the
 * approver granted.
 *
 * Permanent access is untouched: step 3 returns early for anyone who already
 * holds the component permission, and every role that can build servers holds
 * all eleven (super_admin, admin, manager, technician). `viewer` is the only
 * role without them, and it has no server.create/.edit to begin with.
 *
 * @param  string $serverPermission the permission that gated this operation
 * @return string|null the component permission the caller is missing, meaning
 *                     the call must be refused; null when nothing needs
 *                     narrowing
 */
function requestScopedComponentPermission($pdo, $userId, $module, $operation, $serverPermission) {
    // The build operations that name a component type. Add asks for `.create`
    // because that is what the Add Hardware ceiling grants; remove and replace
    // ask for `.edit`, matching Edit/Remove Hardware.
    static $implies = [
        'add-component'     => 'create',
        'remove-component'  => 'edit',
        'replace-component' => 'edit',
    ];

    if ($module !== 'server' || !isset($implies[$operation])) {
        return null;
    }

    $type = strtolower(trim((string)($_POST['component_type'] ?? $_GET['component_type'] ?? '')));
    if (!in_array($type, VALID_COMPONENT_TYPES, true)) {
        // Not our business to report — the handler's own 400 says "Invalid
        // component type", which is the useful answer.
        return null;
    }

    $needed = $type . '.' . $implies[$operation];
    if (hasPermission($pdo, $needed, $userId)) {
        return null;
    }

    // They lack it, so this only matters if their build access is temporary.
    // listActive() reports live temporary grants only; on a database without the
    // expiry columns it returns [] and this gate stays inert.
    $manager = new TemporaryAccessManager($pdo);
    foreach ($manager->listActive($userId) as $grant) {
        if (($grant['permission'] ?? '') === $serverPermission) {
            return $needed;
        }
    }

    return null;
}

/**
 * Can this user act on THIS configuration?
 *
 * The idiom this replaces was copied ~10 times through server_api.php:
 *
 *     if ($config->get('created_by') != $user['id']
 *         && !hasPermission($pdo, 'server.edit_all', $user['id'])) { 403 }
 *
 * i.e. you must own it, or hold the blanket escalation permission. That left no
 * way to say "this person may work on this one server", which is what a targeted
 * access request grants — so a third branch was needed, and one helper is better
 * than ten near-identical conditions.
 *
 * @param object $config    a loaded ServerConfiguration
 * @param string $escalation the blanket permission that has always bypassed
 *                           ownership (server.edit_all / server.view_all)
 * @param bool   $allowScoped whether a per-configuration temporary grant counts
 *                            here. False for finalize and delete: a temporary
 *                            grant lets you CHANGE a build, not lock or destroy it.
 */
function userCanActOnConfig($pdo, $config, $userId, $escalation, $allowScoped = true) {
    if ((int)$config->get('created_by') === (int)$userId) {
        return true;
    }

    if (hasPermission($pdo, $escalation, $userId)) {
        return true;
    }

    if (!$allowScoped) {
        return false;
    }

    $configUuid = $config->get('config_uuid');
    if (!$configUuid) {
        return false;
    }

    $manager = new TemporaryAccessManager($pdo);
    return $manager->hasAnyScopedGrant($userId, $configUuid);
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
        // B.4 (audit §9.6): an empty array on a database error is
        // indistinguishable from "none", and for roles that silently
        // narrows what a user can see. api/api.php turns this into a 500.
        throw $e;
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
        // B.4 — see getUserRoles().
        throw $e;
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
        // B.4 — see getUserRoles().
        throw $e;
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
