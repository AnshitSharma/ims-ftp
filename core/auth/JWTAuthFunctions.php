<?php
/**
 * JWT Authentication Functions - OPTIMIZED VERSION
 * Replace session-based functions with JWT equivalents
 *
 * File: includes/JWTAuthFunctions.php
 */

require_once __DIR__ . '/JWTHelper.php';

// Request-level cache for authenticated user (prevents multiple DB queries)
global $_jwt_auth_cache;
$_jwt_auth_cache = null;

/**
 * Authenticate user with JWT
 */
if (!function_exists('authenticateWithJWT')) {
    function authenticateWithJWT($pdo) {
        global $_jwt_auth_cache;

        // Return cached user if already authenticated in this request
        if ($_jwt_auth_cache !== null) {
            return $_jwt_auth_cache;
        }

        $token = JWTHelper::getTokenFromHeader();

        if (!$token) {
            return false;
        }

        // verifyToken throws on any failure (bad signature, expired, revoked,
        // pass-change cutoff, missing revocation table). Catch here so the
        // caller just sees false and returns 401 instead of a 500.
        try {
            $payload = JWTHelper::verifyToken($token, $pdo);
        } catch (Exception $e) {
            error_log("JWT verify failed: " . $e->getMessage());
            $_jwt_auth_cache = false;
            return false;
        }

        if (!$payload) {
            return false;
        }

        // Verify user still exists in database
        try {
            $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname FROM users WHERE id = ?");
            $stmt->execute([$payload['user_id']]);
            $user = $stmt->fetch();

            if (!$user) {
                $_jwt_auth_cache = false;
                return false;
            }

            // Add token payload data to user
            $user['token_payload'] = $payload;

            // Cache the authenticated user for this request
            $_jwt_auth_cache = $user;

            return $user;
        } catch (PDOException $e) {
            error_log("Error verifying JWT user: " . $e->getMessage());
            $_jwt_auth_cache = false;
            return false;
        }
    }
}

/**
 * Authenticate user with JWT and optionally load ACL
 * @param PDO $pdo Database connection
 * @param bool $loadFullACL Whether to load full permissions summary (expensive)
 */
if (!function_exists('authenticateWithJWTAndACL')) {
    function authenticateWithJWTAndACL($pdo, $loadFullACL = false) {
        $user = authenticateWithJWT($pdo);
        if (!$user) {
            return false;
        }

        // Add Simple ACL information to user data
        if (class_exists('SimpleACL')) {
            $acl = new SimpleACL($pdo, $user['id']);
            $user['role'] = $acl->getUserRole();  // Cached after first call
            $user['is_admin'] = $acl->isAdmin();
            $user['is_manager'] = $acl->isManagerOrAdmin();

            // Only load full permissions summary if explicitly requested (reduces DB queries)
            if ($loadFullACL) {
                $user['permissions'] = $acl->getPermissionsSummary();
            }
        } else {
            // Fallback if ACL is not available
            $user['role'] = 'viewer';
            $user['is_admin'] = false;
            $user['is_manager'] = false;
            if ($loadFullACL) {
                $user['permissions'] = [];
            }
        }

        return $user;
    }
}

/**
 * Check if current request has valid JWT
 */
if (!function_exists('hasValidJWT')) {
    function hasValidJWT($pdo) {
        return authenticateWithJWT($pdo) !== false;
    }
}

/**
 * Check JWT permission
 */
if (!function_exists('hasJWTPermission')) {
    function hasJWTPermission($pdo, $action, $componentType = null) {
        $user = authenticateWithJWT($pdo);
        if (!$user) {
            return false;
        }
        
        if (!class_exists('SimpleACL')) {
            // Allow basic read access but deny all other actions
            return ($action === 'read');
        }
        
        $acl = new SimpleACL($pdo, $user['id']);
        return $acl->hasPermission($action, $componentType);
    }
}

/**
 * Require JWT permission or exit with error
 */
if (!function_exists('requireJWTPermission')) {
    function requireJWTPermission($pdo, $action, $componentType = null) {
        if (!hasJWTPermission($pdo, $action, $componentType)) {
            http_response_code(403);
            send_json_response(0, 0, 403, "Access denied. Insufficient permissions.");
            exit();
        }
    }
}

/**
 * Require valid JWT or exit with error
 */
if (!function_exists('requireJWTAuth')) {
    function requireJWTAuth($pdo) {
        $user = authenticateWithJWT($pdo);
        if (!$user) {
            http_response_code(401);
            send_json_response(0, 0, 401, "Unauthorized. Valid JWT token required.");
            exit();
        }
        return $user;
    }
}

/**
 * Generate JWT token for user login
 */
if (!function_exists('generateUserJWT')) {
    function generateUserJWT($user, $pdo = null) {
        $payload = [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email']
        ];

        // Add role information if ACL is available
        if ($pdo && class_exists('SimpleACL')) {
            $acl = new SimpleACL($pdo, $user['id']);
            $payload['role'] = $acl->getUserRole();
            $payload['is_admin'] = $acl->isAdmin();
            $payload['is_manager'] = $acl->isManagerOrAdmin();
        }

        return JWTHelper::generateToken($payload);
    }
}

/**
 * REMOVED (audit K): getUserIdFromJWT(), refreshJWTToken() and logoutJWT().
 *
 * All three were unreachable -- zero callers fleet-wide -- and all three routed
 * through JWTHelper methods that call verifyToken() WITHOUT a $pdo, which skips
 * the revoked_tokens blacklist and the users.password_changed_at cutoff. Had
 * anything started calling them, a logged-out or password-reset token would have
 * been honoured.
 *
 * They are deleted rather than repaired because keeping them implies they are
 * supported:
 *   - Logout is handled by handleLogout() in api/handlers/auth/auth_api.php,
 *     which revokes the refresh tokens AND blacklists the access token's jti.
 *   - Token refresh is handled by handleTokenRefresh(), which goes through
 *     JWTHelper::verifyRefreshToken($pdo, ...) -- DB-backed, expiry-checked and
 *     scoped to active users.
 *   - The authenticated request path resolves the user via authenticateWithJWT()
 *     in core/helpers/BaseFunctions.php, which passes $pdo correctly.
 */


/**
 * Validate JWT middleware function
 */
if (!function_exists('validateJWTMiddleware')) {
    function validateJWTMiddleware($pdo) {
        // Check if Authorization header exists
        $token = JWTHelper::getTokenFromHeader();
        
        if (!$token) {
            http_response_code(401);
            send_json_response(0, 0, 401, "Missing Authorization header with Bearer token");
            exit();
        }

        // Verify token
        $user = authenticateWithJWT($pdo);
        if (!$user) {
            http_response_code(401);
            send_json_response(0, 0, 401, "Invalid or expired JWT token");
            exit();
        }

        return $user;
    }
}