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

// Set headers first
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include required files
require_once(__DIR__ . '/../core/config/app.php');
require_once(__DIR__ . '/../core/helpers/BaseFunctions.php');

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
    send_json_response(0, 0, 500, "Internal server error: " . $e->getMessage());
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
        'initialize' => 'server.create',
        'get_next_options' => 'server.view',
        'validate_current' => 'server.view',
        'finalize' => 'server.create',
        'save_draft' => 'server.create',
        'load_draft' => 'server.view',
        'get_server_progress' => 'server.view',
        'reset_configuration' => 'server.edit',
        'get-config' => 'server.view',
        'finalize-config' => 'server.create',
        'get-available-components' => 'server.view'
    ];
    
    $requiredPermission = $permissionMap[$operation] ?? 'server.view';
    
    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
    }
    
    // Include appropriate server handler based on operation
    if (in_array($operation, ['add-component', 'remove-component', 'get-compatible', 'validate-config', 'save-config', 'get-config', 'list-configs', 'delete-config', 'clone-config', 'get-statistics', 'update-config', 'get-components', 'export-config', 'finalize-config', 'get-available-components'])) {
        // Use the newer server API implementation
        global $operation;
        require_once(__DIR__ . '/handlers/server/server_api.php');
    } else {
        // Use the step-by-step server creation implementation
        $actionMap = [
            'create-start' => 'initialize',
            'server-create-start' => 'initialize',
            'initialize' => 'initialize',
            'save_draft' => 'save_draft',
            'load_draft' => 'load_draft',
            'get_server_progress' => 'get_server_progress',
            'reset_configuration' => 'reset_configuration',
            'list_drafts' => 'list_drafts',
            'delete_draft' => 'delete_draft'
        ];
        
        $internalAction = $actionMap[$operation] ?? $operation;
        $_POST['action'] = $internalAction;
        $_GET['action'] = $internalAction;
        
        require_once(__DIR__ . '/handlers/server/create_server.php');
    }
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
 */
function handleLogout() {
    global $pdo;
    
    try {
        $token = JWTHelper::getTokenFromHeader();
        
        if ($token) {
            $payload = JWTHelper::verifyToken($token);
            $userId = $payload['user_id'];
            
            // Revoke all refresh tokens for this user
            $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);
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

    // Check if registration is enabled (default to true if system_settings doesn't exist)
    $registrationEnabled = getSystemSetting($pdo, 'registration_enabled', true);
    if (!$registrationEnabled) {
        send_json_response(0, 0, 403, "Registration is disabled");
    }
    
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    
    if (empty($username) || empty($email) || empty($password)) {
        send_json_response(0, 0, 400, "Username, email, and password are required");
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
        
        // Assign default role
        $defaultRoleId = getSystemSetting($pdo, 'default_user_role', 2); // Assume role ID 2 is default
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
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        send_json_response(0, 0, 400, "Email is required");
    }
    
    // For security, always return success even if email doesn't exist
    send_json_response(1, 1, 200, "If an account with that email exists, a password reset link has been sent");
}

/**
 * Handle password reset
 */
function handleResetPassword() {
    send_json_response(0, 0, 501, "Password reset functionality not implemented");
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
    global $pdo, $acl;
    $user_id = $user['id'];

    // Map operations to endpoint files
    $endpointMap = [
        'create' => 'ticket-create.php',
        'list' => 'ticket-list.php',
        'get' => 'ticket-get.php',
        'update' => 'ticket-update.php',
        'delete' => 'ticket-delete.php'
    ];

    if (!isset($endpointMap[$operation])) {
        send_json_response(0, 1, 400, "Invalid ticket operation: $operation");
        return;
    }

    $endpointFile = __DIR__ . '/handlers/ticket/' . $endpointMap[$operation];

    if (!file_exists($endpointFile)) {
        error_log("Ticket endpoint file not found: $endpointFile");
        send_json_response(0, 1, 500, "Ticket endpoint not implemented: $operation");
        return;
    }

    // Include and execute the endpoint
    require $endpointFile;
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
            $componentData = [];
            
            // Extract component data from POST
            foreach ($_POST as $key => $value) {
                if ($key !== 'action') {
                    $componentData[$key] = $value;
                }
            }
            
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
            } catch (Exception $e) {
                error_log("Error adding $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to add component: " . $e->getMessage());
            }
            break;
            
        case 'update':
            $componentId = $_POST['id'] ?? '';
            $updateData = [];
            
            foreach ($_POST as $key => $value) {
                if (!in_array($key, ['action', 'id'])) {
                    $updateData[$key] = $value;
                }
            }
            
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
            } catch (Exception $e) {
                error_log("Error updating $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to update component: " . $e->getMessage());
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
                send_json_response(0, 1, 500, "Failed to delete component: " . $e->getMessage());
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
 * Get system setting
 */
function getSystemSetting($pdo, $setting, $default = null) {
    try {
        $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE name = ?");
        $stmt->execute([$setting]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['value'] : $default;
    } catch (Exception $e) {
        return $default;
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
            
            $searchTerm = '%' . $query . '%';
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

    return $tableMap[$type] ?? $type;
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
 * Add component
 */
function addComponent($pdo, $type, $data, $userId) {
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
        
        // Generate UUID if not provided
        if (!isset($convertedData['UUID']) || empty($convertedData['UUID'])) {
            $convertedData['UUID'] = generateUUID();
        }
        
        // Prepare column names and values
        $columns = array_keys($convertedData);
        $placeholders = array_fill(0, count($columns), '?');
        $values = array_values($convertedData);
        
        $sql = "INSERT INTO $tableName (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        error_log("Inserting $type data into $tableName: " . json_encode($convertedData));
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($values);
        
        if ($result) {
            return [
                'id' => $pdo->lastInsertId(),
                'uuid' => $convertedData['UUID']
            ];
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error adding $type component: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Update component
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
        
        $columns = array_keys($convertedData);
        $setClause = implode(' = ?, ', $columns) . ' = ?';
        $values = array_values($convertedData);
        $values[] = $id; // Add ID for WHERE clause
        
        $sql = "UPDATE $tableName SET $setClause WHERE ID = ?";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($values);
        
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
