<?php
/**
 * Complete JWT-Based API with ACL Integration, Server Management, and Compatibility System
 * File: api/api.php
 */

// Disable output buffering and clean any existing output
if (ob_get_level()) {
    ob_end_clean();
}

// Error reporting settingsok 
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set content type header early
header('Content-Type: application/json');

// Include required files BEFORE setting CORS headers (config defines CORS_ALLOWED_ORIGINS)
require_once(__DIR__ . '/../core/config/app.php');
require_once(__DIR__ . '/../core/helpers/BaseFunctions.php');

// Set CORS headers AFTER config is loaded (fail-closed: reject unknown origins)
$allowedOrigins = defined('CORS_ALLOWED_ORIGINS') ? CORS_ALLOWED_ORIGINS : [];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin) && is_array($allowedOrigins) && in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}
// If origin is not in allowlist, do NOT set CORS header (fail-closed)
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Global error handler
set_error_handler(function($severity, $message, $file, $line) {
    error_log("PHP Error: $message in $file on line $line");
    if ($severity === E_ERROR || $severity === E_PARSE || $severity === E_CORE_ERROR) {
        send_json_response(0, 0, 500, "Internal server error");
    }
});

// Exception handler
set_exception_handler(function($exception) {
    error_log("Uncaught exception: " . $exception->getMessage());
    send_json_response(0, 0, 500, "Internal server error");
});

// Initialize ACL system
initializeACLSystem($pdo);

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    if (empty($action)) {
        send_json_response(0, 0, 400, "Action parameter is required");
    }
    
    $parts = explode('-', $action, 2);
    $module = $parts[0] ?? '';
    $operation = $parts[1] ?? '';
    
    error_log("API called with action: $action (Module: $module, Operation: $operation)");
    
    // Authentication operations (no login required)
    if ($module === 'auth') {
        handleAuthOperations($operation);
        exit();
    }
    
    // All other operations require JWT authentication
    $user = authenticateWithJWT($pdo);
    if (!$user) {
        send_json_response(0, 0, 401, "Valid JWT token required - please login");
    }
    
    error_log("Authenticated user: " . $user['username'] . " (ID: " . $user['id'] . ")");
    
    // Route to appropriate module handlers
    switch ($module) {
        case 'server':
            handleServerModule($operation, $user);
            break;
            
        case 'compatibility':
            handleCompatibilityModule($operation, $user);
            break;
            
        case 'acl':
            handleACLOperations($operation, $user);
            break;
            
        case 'roles':
            handleRolesOperations($operation, $user);
            break;
            
        case 'permissions':
            handlePermissionsOperations($operation, $user);
            break;
            
        case 'dashboard':
            handleDashboardOperations($operation, $user);
            break;
            
        case 'search':
            handleSearchOperations($operation, $user);
            break;
            
        case 'users':
            handleUserOperations($operation, $user);
            break;
            
        // Component operations
        case 'cpu':
        case 'ram':
        case 'storage':
        case 'motherboard':
        case 'nic':
        case 'caddy':
        case 'chassis':
        case 'pciecard':
        case 'hbacard':
        case 'sfp':
            handleComponentOperations($module, $operation, $user);
            break;

        case 'ticket':
            handleTicketOperations($operation, $user);
            break;

        default:
            error_log("Invalid module requested: $module");
            send_json_response(0, 1, 400, "Invalid module: $module");
    }
    
} catch (Exception $e) {
    error_log("API error: " . $e->getMessage());
    send_json_response(0, 0, 500, "Internal server error");
}

/**
 * Handle server creation and management operations
 */
function handleServerModule($operation, $user) {
    global $pdo;
    
    // Map operations to their required permissions and internal actions
    $permissionMap = [
        'create-start' => 'server.create',
        'add-component' => 'server.create',
        'remove-component' => 'server.edit',
        'get-compatible' => 'server.view',
        'validate-config' => 'server.view',
        'save-config' => 'server.create',
        'load-config' => 'server.view',
        'list-configs' => 'server.view',
        'delete-config' => 'server.delete',
        'clone-config' => 'server.create',
        'get-statistics' => 'server.view_statistics',
        'update-config' => 'server.edit',
        'get-components' => 'server.view',
        'export-config' => 'server.view',
        'get-config' => 'server.view',
        'finalize-config' => 'server.create',
        'get-available-components' => 'server.view',
        'import-virtual' => 'server.create',
        'search-by-serial' => 'server.view',
        'update-location' => 'server.edit',
        'fix-onboard-nics' => 'server.edit',
        'debug-motherboard-nics' => 'server.view'
    ];
    
    $requiredPermission = $permissionMap[$operation] ?? 'server.view';
    
    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
    }
    
    // Include appropriate server handler based on operation
    // Pass operation to server_api.php via global scope
    $GLOBALS['operation'] = $operation;
    require_once(__DIR__ . '/handlers/server/server_api.php');
}

/**
 * Handle compatibility checking operations
 */
function handleCompatibilityModule($operation, $user) {
    global $pdo;
    
    // Map operations to their required permissions
    $permissionMap = [
        'check' => 'compatibility.check',
        'check-pair' => 'compatibility.check',
        'check-multiple' => 'compatibility.check',
        'get-compatible-for' => 'compatibility.check',
        'batch-check' => 'compatibility.check',
        'analyze-configuration' => 'compatibility.check',
        'get-rules' => 'compatibility.view_statistics',
        'test-rule' => 'compatibility.manage_rules',
        'get-statistics' => 'compatibility.view_statistics'
    ];
    
    $requiredPermission = $permissionMap[$operation] ?? 'compatibility.check';
    
    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
    }
    
    // Include compatibility API handler
    require_once(__DIR__ . '/handlers/server/compatibility_api.php');
}

/**
 * Handle authentication operations (no login required)
 */
function handleAuthOperations($operation) {
    error_log("Auth operation: $operation");

    global $pdo;

    // Rate limit login, forgot_password, and reset_password
    if (in_array($operation, ['login', 'forgot_password', 'reset_password'])) {
        require_once(__DIR__ . '/../core/helpers/RateLimiter.php');
        $rateLimiter = new RateLimiter();
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $limits = [
            'login' => [10, 60],            // 10 attempts per minute
            'forgot_password' => [3, 3600], // 3 attempts per hour
            'reset_password' => [5, 3600],  // 5 attempts per hour
        ];
        [$maxAttempts, $window] = $limits[$operation];

        if (!$rateLimiter->attempt("$operation:$clientIp", $maxAttempts, $window)) {
            send_json_response(0, 0, 429, "Too many requests. Please try again later.");
        }
    }

    switch ($operation) {
        case 'login':
            handleLogin();
            break;
            
        case 'logout':
            handleLogout();
            break;
            
        case 'refresh':
            handleTokenRefresh();
            break;
            
        case 'verify_token':
            handleTokenVerification();
            break;
            
        case 'register':
            // Registration requires authentication and user.create permission
            $regUser = authenticateWithJWT($pdo);
            if (!$regUser) {
                send_json_response(0, 0, 401, "Authentication required for registration");
            }
            if (!hasPermission($pdo, 'user.create', $regUser['id'])) {
                send_json_response(0, 1, 403, "Permission denied: user.create required");
            }
            handleRegistration();
            break;
            
        case 'forgot_password':
            handleForgotPassword();
            break;
            
        case 'reset_password':
            handleResetPassword();
            break;
            
        default:
            send_json_response(0, 0, 400, "Invalid authentication operation: $operation");
    }
}

/**
 * Handle login request
 */
function handleLogin() {
    global $pdo;
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = filter_var($_POST['remember_me'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    error_log("Login attempt - Username: '$username'");
    
    if (empty($username) || empty($password)) {
        send_json_response(0, 0, 400, "Username and password are required");
    }
    
    try {
        // Authenticate user
        $user = authenticateUser($pdo, $username, $password);
        
        if (!$user) {
            error_log("Authentication failed for: $username");
            send_json_response(0, 0, 401, "Invalid credentials");
        }
        
        error_log("Login successful for user: $username (ID: " . $user['id'] . ")");
        
        // Generate JWT tokens
        $jwtExpiryHours = defined('JWT_EXPIRY_HOURS') ? JWT_EXPIRY_HOURS : 24;
        $accessTokenExpiry = $rememberMe ? 86400 : ($jwtExpiryHours * 3600); // 24h or configured hours
        $refreshTokenExpiry = $rememberMe ? 2592000 : 604800; // 30 days or 7 days
        
        $accessToken = JWTHelper::generateToken([
            'user_id' => $user['id'],
            'username' => $user['username']
        ], $accessTokenExpiry);
        
        $refreshToken = JWTHelper::generateRefreshToken();
        
        // Store refresh token
        JWTHelper::storeRefreshToken($pdo, $user['id'], $refreshToken, $refreshTokenExpiry);
        
        // Get user permissions
        $permissions = getUserPermissions($pdo, $user['id']);
        
        send_json_response(1, 1, 200, "Login successful", [
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname']
            ],
            'tokens' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => $accessTokenExpiry
            ]
            
        ]);
        
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        send_json_response(0, 0, 500, "Login failed");
    }
}

/**
 * Handle logout request
 *
 * Revokes BOTH the refresh tokens (by deleting auth_tokens rows) AND the
 * current access token (by inserting its jti into revoked_tokens). Without
 * the jti blacklist the signed JWT would remain valid until its natural
 * `exp` even after the user logged out.
 *
 * verifyToken is called WITHOUT $pdo on purpose: if the token was already
 * revoked, we still want to be idempotent and return 200 rather than
 * refusing to let the client "log out again".
 */
function handleLogout() {
    global $pdo;

    try {
        $token = JWTHelper::getTokenFromHeader();

        if ($token) {
            $payload = JWTHelper::verifyToken($token);
            $userId = $payload['user_id'];

            // 1. Revoke all refresh tokens for this user
            $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 2. Blacklist THIS access token's jti so verifyToken() will
            //    reject it on subsequent requests until it expires naturally.
            if (!empty($payload['jti']) && !empty($payload['exp'])) {
                try {
                    $expiresAt = date('Y-m-d H:i:s', (int)$payload['exp']);
                    $stmt = $pdo->prepare(
                        "INSERT IGNORE INTO revoked_tokens (jti, user_id, expires_at) VALUES (?, ?, ?)"
                    );
                    $stmt->execute([$payload['jti'], $userId, $expiresAt]);
                } catch (PDOException $e) {
                    // Table missing = migration not applied. Log loudly
                    // but don't crash the logout request.
                    error_log("handleLogout: failed to insert revoked_tokens: " . $e->getMessage());
                }
            }
        }

        send_json_response(1, 1, 200, "Logged out successfully");

    } catch (Exception $e) {
        // Even if token verification fails, we consider logout successful
        send_json_response(1, 1, 200, "Logged out successfully");
    }
}

/**
 * Handle token refresh
 */
function handleTokenRefresh() {
    global $pdo;
    
    $refreshToken = $_POST['refresh_token'] ?? '';
    
    if (empty($refreshToken)) {
        send_json_response(0, 0, 400, "Refresh token is required");
    }
    
    try {
        // Verify refresh token
        $stmt = $pdo->prepare("
            SELECT user_id, expires_at 
            FROM auth_tokens 
            WHERE token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$refreshToken]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tokenData) {
            send_json_response(0, 0, 401, "Invalid or expired refresh token");
        }
        
        // Get user data
        $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname FROM users WHERE id = ?");
        $stmt->execute([$tokenData['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            send_json_response(0, 0, 401, "User not found");
        }
        
        // Generate new access token
        $jwtExpiryHours = defined('JWT_EXPIRY_HOURS') ? JWT_EXPIRY_HOURS : 24;
        $tokenExpiry = $jwtExpiryHours * 3600;
        $accessToken = JWTHelper::generateToken([
            'user_id' => $user['id'],
            'username' => $user['username']
        ], $tokenExpiry);
        
        send_json_response(1, 1, 200, "Token refreshed successfully", [
            'access_token' => $accessToken,
            'expires_in' => $tokenExpiry,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname']
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Token refresh error: " . $e->getMessage());
        send_json_response(0, 0, 401, "Token refresh failed");
    }
}

/**
 * Handle token verification
 */
function handleTokenVerification() {
    global $pdo;
    
    try {
        $user = authenticateWithJWT($pdo);
        
        if (!$user) {
            send_json_response(0, 0, 401, "Invalid token");
        }
        
        send_json_response(1, 1, 200, "Token is valid", [
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname']
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Token verification error: " . $e->getMessage());
        send_json_response(0, 0, 401, "Token verification failed");
    }
}

/**
 * Handle user registration (if enabled)
 */
function handleRegistration() {
    global $pdo;

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    
    if (empty($username) || empty($email) || empty($password)) {
        send_json_response(0, 0, 400, "Username, email, and password are required");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_json_response(0, 0, 400, "Invalid email format");
    }

    if (strlen($password) < 8) {
        send_json_response(0, 0, 400, "Password must be at least 8 characters");
    }
    if (!preg_match('/[A-Z]/', $password)) {
        send_json_response(0, 0, 400, "Password must contain at least one uppercase letter");
    }
    if (!preg_match('/[0-9]/', $password)) {
        send_json_response(0, 0, 400, "Password must contain at least one number");
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        send_json_response(0, 0, 400, "Password must contain at least one special character");
    }

    if (strlen($username) < 3 || strlen($username) > 50) {
        send_json_response(0, 0, 400, "Username must be between 3 and 50 characters");
    }

    try {
        // Check if username/email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            send_json_response(0, 0, 409, "Username or email already exists");
        }
        
        // Create user
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, firstname, lastname, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$username, $email, $hashedPassword, $firstname, $lastname]);
        
        $userId = $pdo->lastInsertId();
        
        // Assign default role (read from DB instead of hardcoding)
        $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE is_default = 1 LIMIT 1");
        $roleStmt->execute();
        $defaultRole = $roleStmt->fetch(PDO::FETCH_ASSOC);
        $defaultRoleId = $defaultRole ? $defaultRole['id'] : 2; // Fallback to 2 if no default set
        assignRoleToUser($pdo, $userId, $defaultRoleId);
        
        send_json_response(1, 1, 201, "Registration successful", [
            'user_id' => (int)$userId,
            'username' => $username,
            'message' => "Please login with your credentials"
        ]);
        
    } catch (Exception $e) {
        error_log("Registration error: " . $e->getMessage());
        send_json_response(0, 0, 500, "Registration failed");
    }
}

/**
 * Handle forgot password
 */
function handleForgotPassword() {
    global $pdo;

    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        send_json_response(0, 0, 400, "Email is required");
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_json_response(0, 0, 400, "Invalid email format");
    }

    try {
        // Look up user by email
        $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = :email AND status = 'active'");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // If user exists, generate and send reset token
        if ($user) {
            // Generate secure token
            $resetToken = bin2hex(random_bytes(32)); // 64-char hex string
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Clean up old unused tokens for this user (prevent token spam)
            $stmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = :user_id AND used_at IS NULL");
            $stmt->execute(['user_id' => $user['id']]);

            // Store new token
            $stmt = $pdo->prepare(
                "INSERT INTO password_resets (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)"
            );
            $stmt->execute([
                'user_id' => $user['id'],
                'token' => $resetToken,
                'expires_at' => $expiresAt
            ]);

            // Construct reset link
            $frontendUrl = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
            $resetLink = $frontendUrl . '/reset-password?token=' . $resetToken;

            // Send email
            require_once __DIR__ . '/../core/helpers/EmailHelper.php';
            $emailSent = EmailHelper::sendPasswordResetEmail($user['email'], $user['username'], $resetLink);

            if (!$emailSent) {
                error_log("[handleForgotPassword] Failed to send reset email to {$user['email']}");
            }
        }

        // ALWAYS return success (security: don't leak user existence)
        send_json_response(1, 1, 200, "If an account with that email exists, a password reset link has been sent");

    } catch (PDOException $e) {
        error_log("[handleForgotPassword] Database error: " . $e->getMessage());
        send_json_response(0, 0, 500, "An error occurred. Please try again later");
    }
}

/**
 * Handle password reset
 */
function handleResetPassword() {
    global $pdo;

    $token = trim($_POST['token'] ?? $_GET['token'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');

    // Validate input
    if (empty($token)) {
        send_json_response(0, 0, 400, "Reset token is required");
    }

    if (empty($newPassword)) {
        send_json_response(0, 0, 400, "New password is required");
    }

    // Enforce password strength (minimum 8 characters + uppercase + number + special char)
    if (strlen($newPassword) < 8) {
        send_json_response(0, 0, 400, "Password must be at least 8 characters long");
    }
    if (!preg_match('/[A-Z]/', $newPassword)) {
        send_json_response(0, 0, 400, "Password must contain at least one uppercase letter");
    }
    if (!preg_match('/[0-9]/', $newPassword)) {
        send_json_response(0, 0, 400, "Password must contain at least one number");
    }
    if (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        send_json_response(0, 0, 400, "Password must contain at least one special character");
    }

    try {
        // Look up token
        $stmt = $pdo->prepare(
            "SELECT user_id, expires_at, used_at FROM password_resets WHERE token = :token"
        );
        $stmt->execute(['token' => $token]);
        $resetRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        // Validate token exists
        if (!$resetRecord) {
            send_json_response(0, 0, 400, "Invalid or expired reset token");
        }

        // Check if already used
        if ($resetRecord['used_at'] !== null) {
            send_json_response(0, 0, 400, "This reset link has already been used");
        }

        // Check if expired
        if (strtotime($resetRecord['expires_at']) < time()) {
            send_json_response(0, 0, 400, "This reset link has expired");
        }

        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update user password AND stamp password_changed_at. That timestamp
        // is the cutoff used by JWTHelper::verifyToken — every still-valid
        // access token issued before this instant becomes unusable, which is
        // what we want after a password reset.
        $stmt = $pdo->prepare("UPDATE users SET password = :password, password_changed_at = NOW() WHERE id = :user_id");
        $stmt->execute([
            'password' => $hashedPassword,
            'user_id' => $resetRecord['user_id']
        ]);

        // Mark token as used
        $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token = :token");
        $stmt->execute(['token' => $token]);

        // Invalidate all user's refresh tokens (force logout everywhere).
        // Access tokens are handled by the password_changed_at cutoff above.
        $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $resetRecord['user_id']]);

        error_log("[handleResetPassword] Password successfully reset for user_id {$resetRecord['user_id']}");

        send_json_response(1, 1, 200, "Password has been reset successfully. Please login with your new password.");

    } catch (PDOException $e) {
        error_log("[handleResetPassword] Database error: " . $e->getMessage());
        send_json_response(0, 0, 500, "An error occurred. Please try again later");
    }
}

/**
 * Handle ACL operations
 */
function handleACLOperations($operation, $user) {
    global $pdo;
    
    error_log("ACL operation: $operation");
    
    // Check if user has ACL management permissions
    if (!hasPermission($pdo, 'acl.manage', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for ACL operations");
    }
    
    switch ($operation) {
        case 'get_user_permissions':
            $targetUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? '';
            if (empty($targetUserId)) {
                send_json_response(0, 1, 400, "User ID is required");
            }
            
            $permissions = getUserPermissions($pdo, $targetUserId);
            $roles = getUserRoles($pdo, $targetUserId);
            
            send_json_response(1, 1, 200, "User permissions retrieved", [
                'user_id' => (int)$targetUserId,
                'permissions' => $permissions,
                'roles' => $roles
            ]);
            break;
            
        case 'assign_permission':
            $targetUserId = $_POST['user_id'] ?? '';
            $permission = $_POST['permission'] ?? '';
            
            if (empty($targetUserId) || empty($permission)) {
                send_json_response(0, 1, 400, "User ID and permission are required");
            }
            
            $success = assignPermissionToUser($pdo, $targetUserId, $permission);
            
            if ($success) {
                send_json_response(1, 1, 200, "Permission assigned successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to assign permission");
            }
            break;
            
        case 'revoke_permission':
            $targetUserId = $_POST['user_id'] ?? '';
            $permission = $_POST['permission'] ?? '';
            
            if (empty($targetUserId) || empty($permission)) {
                send_json_response(0, 1, 400, "User ID and permission are required");
            }
            
            $success = revokePermissionFromUser($pdo, $targetUserId, $permission);
            
            if ($success) {
                send_json_response(1, 1, 200, "Permission revoked successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to revoke permission");
            }
            break;
            
        case 'assign_role':
            $targetUserId = $_POST['user_id'] ?? '';
            $roleId = $_POST['role_id'] ?? '';
            
            if (empty($targetUserId) || empty($roleId)) {
                send_json_response(0, 1, 400, "User ID and role ID are required");
            }
            
            $success = assignRoleToUser($pdo, $targetUserId, $roleId);
            
            if ($success) {
                send_json_response(1, 1, 200, "Role assigned successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to assign role");
            }
            break;
            
        case 'revoke_role':
            $targetUserId = $_POST['user_id'] ?? '';
            $roleId = $_POST['role_id'] ?? '';
            
            if (empty($targetUserId) || empty($roleId)) {
                send_json_response(0, 1, 400, "User ID and role ID are required");
            }
            
            $success = revokeRoleFromUser($pdo, $targetUserId, $roleId);
            
            if ($success) {
                send_json_response(1, 1, 200, "Role revoked successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to revoke role");
            }
            break;
            
        case 'get_all_roles':
            $roles = getAllRoles($pdo);
            send_json_response(1, 1, 200, "Roles retrieved successfully", ['roles' => $roles]);
            break;
            
        case 'get_all_permissions':
            $permissions = getAllPermissions($pdo);
            send_json_response(1, 1, 200, "Permissions retrieved successfully", ['permissions' => $permissions]);
            break;
            
        case 'check_permission':
            $targetUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? $user['id'];
            $permission = $_GET['permission'] ?? $_POST['permission'] ?? '';
            
            if (empty($permission)) {
                send_json_response(0, 1, 400, "Permission is required");
            }
            
            $hasPermission = hasPermission($pdo, $permission, $targetUserId);
            
            send_json_response(1, 1, 200, "Permission check completed", [
                'user_id' => (int)$targetUserId,
                'permission' => $permission,
                'has_permission' => $hasPermission
            ]);
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid ACL operation: $operation");
    }
}

/**
 * Handle roles operations
 */
function handleRolesOperations($operation, $user) {
    global $pdo, $acl;

    // Include the dedicated roles API handler
    require_once(__DIR__ . '/handlers/acl/roles_api.php');
    exit();
}

/**
 * Handle permissions operations
 */
function handlePermissionsOperations($operation, $user) {
    global $pdo, $acl;

    // Include the dedicated permissions API handler
    require_once(__DIR__ . '/handlers/acl/permissions_api.php');
    exit();
}

/**
 * Handle dashboard operations
 */
function handleDashboardOperations($operation, $user) {
    global $pdo;
    
    if (!hasPermission($pdo, 'dashboard.view', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for dashboard access");
    }
    
    switch ($operation) {
        case 'get_data':
            $dashboardData = getDashboardData($pdo, $user);
            send_json_response(1, 1, 200, "Dashboard data retrieved", $dashboardData);
            break;

        case 'get-logs':
            // Admin/super admin only
            if (!hasPermission($pdo, 'acl.manage', $user['id']) && !hasPermission($pdo, 'user.view', $user['id'])) {
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

/**
 * Handle search operations
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
            
            $results = performGlobalSearch($pdo, $query, $limit, $user);
            send_json_response(1, 1, 200, "Search completed", $results);
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid search operation: $operation");
    }
}

/**
 * Handle user operations
 */
function handleUserOperations($operation, $user) {
    global $pdo;
    
    switch ($operation) {
        case 'list':
            if (!hasPermission($pdo, 'user.view', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user listing");
            }
            
            $users = getAllUsers($pdo);
            send_json_response(1, 1, 200, "Users retrieved successfully", ['users' => $users]);
            break;
            
        case 'create':
            if (!hasPermission($pdo, 'user.create', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user creation");
            }
            
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname = trim($_POST['lastname'] ?? '');
            
            if (empty($username) || empty($email) || empty($password)) {
                send_json_response(0, 1, 400, "Username, email, and password are required");
            }
            
            $userId = createUser($pdo, $username, $email, $password, $firstname, $lastname);
            
            if ($userId) {
                send_json_response(1, 1, 201, "User created successfully", ['user_id' => $userId]);
            } else {
                send_json_response(0, 1, 400, "Failed to create user");
            }
            break;
            
        case 'update':
            if (!hasPermission($pdo, 'user.edit', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user updates");
            }
            
            $targetUserId = $_POST['user_id'] ?? '';
            $updateData = [];
            
            if (isset($_POST['username'])) $updateData['username'] = trim($_POST['username']);
            if (isset($_POST['email'])) $updateData['email'] = trim($_POST['email']);
            if (isset($_POST['firstname'])) $updateData['firstname'] = trim($_POST['firstname']);
            if (isset($_POST['lastname'])) $updateData['lastname'] = trim($_POST['lastname']);
            if (isset($_POST['status'])) $updateData['status'] = $_POST['status'];
            
            if (empty($targetUserId) || empty($updateData)) {
                send_json_response(0, 1, 400, "User ID and at least one field to update are required");
            }
            
            $success = updateUser($pdo, $targetUserId, $updateData);
            
            if ($success) {
                send_json_response(1, 1, 200, "User updated successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to update user");
            }
            break;
            
        case 'delete':
            if (!hasPermission($pdo, 'user.delete', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user deletion");
            }
            
            $targetUserId = $_POST['user_id'] ?? '';
            
            if (empty($targetUserId)) {
                send_json_response(0, 1, 400, "User ID is required");
            }
            
            if ($targetUserId == $user['id']) {
                send_json_response(0, 1, 400, "Cannot delete your own account");
            }
            
            $success = deleteUser($pdo, $targetUserId);
            
            if ($success) {
                send_json_response(1, 1, 200, "User deleted successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to delete user");
            }
            break;
            
        case 'get':
            if (!hasPermission($pdo, 'user.view', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user viewing");
            }
            
            $targetUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? '';
            
            if (empty($targetUserId)) {
                send_json_response(0, 1, 400, "User ID is required");
            }
            
            $userData = getUserById($pdo, $targetUserId);
            
            if ($userData) {
                send_json_response(1, 1, 200, "User retrieved successfully", ['user' => $userData]);
            } else {
                send_json_response(0, 1, 404, "User not found");
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid user operation: $operation");
    }
}

/**
 * Handle ticket operations
 */
function handleTicketOperations($operation, $user) {
    global $pdo;

    try {
        // Make user_id and acl available to the included handler file
        $user_id = $user['id'];
        $GLOBALS['user_id'] = $user_id;

        // Get or create ACL instance
        $acl = $GLOBALS['acl'] ?? null;

        if (!$acl) {
            require_once(__DIR__ . '/../core/auth/ACL.php');
            $acl = new ACL($pdo);
        }

        // Make ACL available in global scope for included handler files
        $GLOBALS['acl'] = $acl;

        // Map operations to endpoint files
        $endpointMap = [
            'create' => 'ticket-create.php',
            'list' => 'ticket-list.php',
            'get' => 'ticket-get.php',
            'update' => 'ticket-update.php',
            'delete' => 'ticket-delete.php',
            // 'debug' endpoint removed for security
        ];

        if (!isset($endpointMap[$operation])) {
            send_json_response(0, 1, 400, "Invalid ticket operation: $operation");
            return;
        }

        $endpointFile = __DIR__ . '/handlers/tickets/' . $endpointMap[$operation];

        if (!file_exists($endpointFile)) {
            error_log("Ticket endpoint file not found: $endpointFile");
            send_json_response(0, 1, 500, "Ticket endpoint not implemented: $operation");
            return;
        }

        // Include and execute the endpoint
        require $endpointFile;

    } catch (Exception $e) {
        error_log("Ticket handler error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        send_json_response(0, 1, 500, "Ticket operation failed");
    } catch (Error $e) {
        error_log("Ticket handler fatal error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        send_json_response(0, 1, 500, "Ticket operation failed");
    }
}

/**
 * Handle component operations (CPU, RAM, Storage, etc.)
 */
function handleComponentOperations($module, $operation, $user) {
    global $pdo;
    
    // Map operations to permissions
    $permissionMap = [
        'list' => "$module.view",
        'get' => "$module.view",
        'add' => "$module.create",
        'update' => "$module.edit",
        'delete' => "$module.delete",
        'bulk_update' => "$module.edit",
        'bulk_delete' => "$module.delete"
    ];
    
    $requiredPermission = $permissionMap[$operation] ?? "$module.view";
    
    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
    }
    
    switch ($operation) {
        case 'list':
            $components = getComponentsByType($pdo, $module);

            // Resolve ModelName from JSON specs via UUID
            $componentService = null;
            try {
                require_once __DIR__ . '/../core/models/components/ComponentDataService.php';
                $componentService = ComponentDataService::getInstance();
            } catch (Exception $e) {
                error_log("[ModelName] ComponentDataService load failed: " . $e->getMessage());
            }

            foreach ($components as &$comp) {
                $comp['ModelName'] = null;
                if ($componentService !== null && !empty($comp['UUID'])) {
                    try {
                        $spec = $componentService->findComponentByUuid($module, $comp['UUID']);
                        if ($spec !== null) {
                            $brand = $spec['brand'] ?? null;
                            $model = $spec['model'] ?? $spec['name'] ?? $spec['model_name'] ?? $spec['product_name'] ?? null;

                            // RAM: build "Brand Type CapacityGB Module"
                            if ($model === null && $module === 'ram') {
                                $parts = array_filter([$brand, $spec['memory_type'] ?? null,
                                    isset($spec['capacity_GB']) ? $spec['capacity_GB'] . 'GB' : null,
                                    $spec['module_type'] ?? null]);
                                $comp['ModelName'] = $parts ? implode(' ', $parts) : null;
                            }
                            // Storage: build "Brand Type CapacityGB"
                            elseif ($model === null && $module === 'storage') {
                                $cap = null;
                                if (isset($spec['capacity_GB'])) {
                                    $cap = $spec['capacity_GB'] >= 1000
                                        ? round($spec['capacity_GB'] / 1000, 1) . 'TB'
                                        : $spec['capacity_GB'] . 'GB';
                                }
                                $parts = array_filter([$brand, $spec['storage_type'] ?? null, $cap]);
                                $comp['ModelName'] = $parts ? implode(' ', $parts) : null;
                            }
                            elseif ($brand && $model) {
                                $comp['ModelName'] = $brand . ' ' . $model;
                            } elseif ($model) {
                                $comp['ModelName'] = $model;
                            }
                        }
                    } catch (Exception $e) {
                        // Silent fail per component
                    }
                }
                // Fallback: extract from Notes field
                if ($comp['ModelName'] === null && !empty($comp['Notes'])) {
                    if (preg_match('/Brand:\s*([^,]+).*Model:\s*(.+?)(\r|\n|$)/i', $comp['Notes'], $matches)) {
                        $comp['ModelName'] = trim($matches[1]) . ' ' . trim($matches[2]);
                    }
                }
            }
            unset($comp);

            send_json_response(1, 1, 200, ucfirst($module) . " components retrieved", [
                'components' => $components,
                'total_count' => count($components)
            ]);
            break;
            
        case 'get':
            $componentId = $_GET['id'] ?? $_POST['id'] ?? '';
            
            if (empty($componentId)) {
                send_json_response(0, 1, 400, "Component ID is required");
            }
            
            $component = getComponentById($pdo, $module, $componentId);
            
            if ($component) {
                send_json_response(1, 1, 200, "Component retrieved successfully", ['component' => $component]);
            } else {
                send_json_response(0, 1, 404, "Component not found");
            }
            break;
            
        case 'add':
            // SECURITY: do NOT loop over $_POST and copy every key into the
            // payload. That's a mass-assignment hole — an attacker can add
            // fields like Status=2 to mark inventory in_use, or overwrite
            // ServerUUID, or stuff arbitrary columns in. The downstream
            // addComponent() now filters against the real table schema, but
            // strip well-known request metadata here too so it never even
            // reaches the handler.
            $componentData = $_POST;
            unset($componentData['action']);

            if (empty($componentData)) {
                send_json_response(0, 1, 400, "Component data is required");
            }

            try {
                $result = addComponent($pdo, $module, $componentData, $user['id']);

                if ($result) {
                    error_log("Successfully added $module component with ID: " . $result['id']);
                    send_json_response(1, 1, 201, ucfirst($module) . " component added successfully", [
                        'component_id' => $result['id'],
                        'uuid' => $result['uuid']
                    ]);
                } else {
                    send_json_response(0, 1, 400, "Failed to add " . $module . " component");
                }
            } catch (InvalidArgumentException $e) {
                // Column whitelist / UUID validation rejection — show the
                // specific reason (it's already our own text, not PDO output).
                error_log("Validation error adding $module component: " . $e->getMessage());
                send_json_response(0, 1, 400, $e->getMessage());
            } catch (Exception $e) {
                error_log("Error adding $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to add component");
            }
            break;

        case 'update':
            $componentId = $_POST['id'] ?? '';
            $updateData = $_POST;
            unset($updateData['action'], $updateData['id']);

            if (empty($componentId) || empty($updateData)) {
                send_json_response(0, 1, 400, "Component ID and update data are required");
            }
            
            try {
                $success = updateComponent($pdo, $module, $componentId, $updateData, $user['id']);

                if ($success) {
                    send_json_response(1, 1, 200, ucfirst($module) . " component updated successfully");
                } else {
                    send_json_response(0, 1, 400, "Failed to update " . $module . " component");
                }
            } catch (InvalidArgumentException $e) {
                error_log("Validation error updating $module component: " . $e->getMessage());
                send_json_response(0, 1, 400, $e->getMessage());
            } catch (Exception $e) {
                error_log("Error updating $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to update component");
            }
            break;
            
        case 'delete':
            $componentId = $_POST['id'] ?? '';
            
            if (empty($componentId)) {
                send_json_response(0, 1, 400, "Component ID is required");
            }
            
            try {
                $success = deleteComponent($pdo, $module, $componentId, $user['id']);
                
                if ($success) {
                    send_json_response(1, 1, 200, ucfirst($module) . " component deleted successfully");
                } else {
                    send_json_response(0, 1, 400, "Failed to delete " . $module . " component");
                }
            } catch (Exception $e) {
                error_log("Error deleting $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to delete component");
            }
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid $module operation: $operation");
    }
}

// Helper functions (These would typically be in separate include files)

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
 * Get dashboard data
 */
function getDashboardData($pdo, $user) {
    $data = [];
    
    try {
        // Get component counts
        $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'pciecard', 'chassis', 'hbacard', 'sfp'];
        $componentCounts = [];
        $totalComponents = 0;
        
        foreach ($componentTypes as $type) {
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
        
    } catch (Exception $e) {
        error_log("Error getting dashboard data: " . $e->getMessage());
        $data = ['error' => 'Unable to fetch dashboard data'];
    }
    
    return $data;
}

/**
 * Perform global search
 */
function performGlobalSearch($pdo, $query, $limit, $user) {
    $results = [];
    $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'pciecard', 'chassis', 'hbacard', 'sfp'];
    
    try {
        foreach ($componentTypes as $type) {
            $tableName = getComponentTableName($type);
            $sql = "SELECT *, '$type' as component_type FROM $tableName WHERE 
                    SerialNumber LIKE ? OR 
                    Notes LIKE ? OR 
                    Location LIKE ? 
                    LIMIT ?";
            
            $escapedQuery = addcslashes($query, '%_\\');
            $searchTerm = '%' . $escapedQuery . '%';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limit]);
            
            $typeResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $results = array_merge($results, $typeResults);
        }
        
        // Limit total results
        $results = array_slice($results, 0, $limit);
        
    } catch (Exception $e) {
        error_log("Error performing global search: " . $e->getMessage());
    }
    
    return [
        'query' => $query,
        'results' => $results,
        'total_found' => count($results)
    ];
}

/**
 * Map component type to actual table name
 */
function getComponentTableName($type) {
    $tableMap = [
        'chassis' => 'chassisinventory',
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory',
        'pciecard' => 'pciecardinventory',
        'hbacard' => 'hbacardinventory',
        'sfp' => 'sfpinventory'
    ];

    if (!isset($tableMap[$type])) {
        throw new InvalidArgumentException("Invalid component type: " . htmlspecialchars($type));
    }
    return $tableMap[$type];
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
        'flag' => 'Flag',
        'notes' => 'Notes'
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
 * Get components by type
 */
function getComponentsByType($pdo, $type) {
    $tableName = getComponentTableName($type);
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM $tableName ORDER BY id DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting $type components from table $tableName: " . $e->getMessage());
        return [];
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
            // real component spec in ims-data/. Previously this check only
            // existed in BaseFunctions::addComponent (which is shadowed by
            // this function due to PHP function hoisting), so the live path
            // accepted any UUID and silently skipped validation.
            require_once(__DIR__ . '/../core/models/components/ComponentDataService.php');
            $componentService = ComponentDataService::getInstance();
            if (defined('VALID_COMPONENT_TYPES') && in_array($type, VALID_COMPONENT_TYPES, true)) {
                if (!$componentService->validateComponentUuid($type, $convertedData['UUID'])) {
                    throw new InvalidArgumentException(
                        "Component UUID not found in $type specifications"
                    );
                }
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

        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($values);

        if ($result) {
            return [
                'id' => $pdo->lastInsertId(),
                'uuid' => $safeData['UUID'] ?? ($convertedData['UUID'] ?? null)
            ];
        }

        return false;

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
 * Get all users
 */
function getAllUsers($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, status, created_at FROM users ORDER BY username");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        // Remove password from direct updates for security
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

?>
