<?php
/**
 * Main API router — JWT auth, ACL gate, module dispatch.
 * File: api/api.php
 *
 * This file only bootstraps the request and routes it. All business logic
 * lives in api/handlers/ and shared helpers in core/helpers/BaseFunctions.php.
 * Action → permission mapping is centralized in api/permission_map.php.
 */

// Disable output buffering and clean any existing output
if (ob_get_level()) {
    ob_end_clean();
}

// Error reporting settings
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set content type header early
header('Content-Type: application/json');

// Include required files BEFORE setting CORS headers (config defines CORS_ALLOWED_ORIGINS)
require_once(__DIR__ . '/../core/config/app.php');
require_once(__DIR__ . '/../core/helpers/BaseFunctions.php');

// CORS: only origins listed in CORS_ALLOWED_ORIGINS (.env, comma-separated)
// receive an Access-Control-Allow-Origin header. Requests from any other
// origin get no CORS headers and are blocked by the browser. Same-origin and
// non-browser requests send no Origin header and need none.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, CORS_ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
}

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
        require_once(__DIR__ . '/handlers/auth/auth_api.php');
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
            requireModulePermission('server', $operation, $user);
            // Pass operation to server_api.php via global scope
            $GLOBALS['operation'] = $operation;
            require_once(__DIR__ . '/handlers/server/server_api.php');
            break;

        case 'compatibility':
            requireModulePermission('compatibility', $operation, $user);
            // Pass the bare operation (e.g. 'check_pair') to the handler
            $GLOBALS['operation'] = $operation;
            require_once(__DIR__ . '/handlers/server/compatibility_api.php');
            break;

        case 'rack':
            // Rack View is restricted to super_admin ONLY. hasPermission()
            // grants a blanket bypass to both admin and super_admin, so the
            // permission map alone cannot keep admins out — enforce the role
            // explicitly here before the standard permission check.
            if (!userHasRole($pdo, $user['id'], 'super_admin')) {
                send_json_response(0, 1, 403, "Insufficient permissions: super_admin role required");
            }
            requireModulePermission('rack', $operation, $user);
            // Pass operation to rack_api.php via global scope
            $GLOBALS['operation'] = $operation;
            require_once(__DIR__ . '/handlers/rack/rack_api.php');
            break;

        case 'acl':
            require_once(__DIR__ . '/handlers/acl/acl_api.php');
            handleACLOperations($operation, $user);
            break;

        case 'roles':
            // Dedicated roles API handler (checks its own permissions)
            require_once(__DIR__ . '/handlers/acl/roles_api.php');
            break;

        case 'permissions':
            // Dedicated permissions API handler (checks its own permissions)
            require_once(__DIR__ . '/handlers/acl/permissions_api.php');
            break;

        case 'dashboard':
            require_once(__DIR__ . '/handlers/dashboard/dashboard_api.php');
            handleDashboardOperations($operation, $user);
            break;

        case 'search':
            require_once(__DIR__ . '/handlers/search/search_api.php');
            handleSearchOperations($operation, $user);
            break;

        case 'users':
            require_once(__DIR__ . '/handlers/users/users_api.php');
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
            requireModulePermission($module, $operation, $user);
            require_once(__DIR__ . '/handlers/components/component_crud_api.php');
            handleComponentOperations($module, $operation, $user);
            break;

        // NOTE: the legacy linear 'ticket' module was retired in favour of the
        // unified 'pipeline' (Requests) engine. Tickets now live as pipeline
        // instances; see handlePipelineOperations() and the Requests UI.

        case 'pipeline':
            handlePipelineOperations($operation, $user);
            break;

        case 'vendor':
            require_once(__DIR__ . '/handlers/vendors/vendor_api.php');
            handleVendorOperations($operation, $user);
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
 * Resolve and enforce the ACL permission for a module operation using the
 * central map in api/permission_map.php.
 *
 * Unknown operations are rejected outright (400). There is no fallback
 * permission on purpose — see the note in permission_map.php.
 */
function requireModulePermission($module, $operation, $user) {
    global $pdo;

    static $map = null;
    if ($map === null) {
        $map = require __DIR__ . '/permission_map.php';
    }

    // The 10 component types share the 'component' permission template.
    $moduleKey = in_array($module, VALID_COMPONENT_TYPES, true) ? 'component' : $module;

    if (!isset($map[$moduleKey][$operation])) {
        send_json_response(0, 1, 400, "Unknown operation: $module-$operation");
    }

    $requiredPermission = str_replace('{module}', $module, $map[$moduleKey][$operation]);

    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
    }
}

/**
 * Handle pipeline operations — dispatches to per-operation endpoint files in
 * handlers/pipelines/. Permission checks live inside each endpoint file
 * ($acl + $user_id are exposed via globals).
 */
function handlePipelineOperations($operation, $user) {
    global $pdo;

    // Pipelines are restricted to the super_admin role ONLY (mirrors Rack View).
    // Belt-and-braces: pipeline.* grants are also revoked from every other role
    // (seeder 2026_06_18_003), but this explicit gate guarantees it in code.
    if (!userHasRole($pdo, $user['id'], 'super_admin')) {
        send_json_response(0, 1, 403, "Insufficient permissions: super_admin role required");
        return;
    }

    try {
        $user_id = $user['id'];
        $GLOBALS['user_id'] = $user_id;

        $acl = $GLOBALS['acl'] ?? null;
        if (!$acl) {
            $acl = new ACL($pdo);
        }
        $GLOBALS['acl'] = $acl;

        $endpointMap = [
            'template-list'   => 'pipeline-template-list.php',
            'template-get'    => 'pipeline-template-get.php',
            'template-create' => 'pipeline-template-create.php',
            'template-update' => 'pipeline-template-update.php',
            'template-delete' => 'pipeline-template-delete.php',
            'create'          => 'pipeline-create.php',
            'list'            => 'pipeline-list.php',
            'get'             => 'pipeline-get.php',
            'claim'           => 'pipeline-claim.php',
            'complete'        => 'pipeline-complete.php',
            'reassign'        => 'pipeline-reassign.php',
            'cancel'          => 'pipeline-cancel.php',
        ];

        if (!isset($endpointMap[$operation])) {
            send_json_response(0, 1, 400, "Invalid pipeline operation: $operation");
            return;
        }

        $endpointFile = __DIR__ . '/handlers/pipelines/' . $endpointMap[$operation];

        if (!file_exists($endpointFile)) {
            error_log("Pipeline endpoint file not found: $endpointFile");
            send_json_response(0, 1, 500, "Pipeline endpoint not implemented: $operation");
            return;
        }

        require $endpointFile;

    } catch (Exception $e) {
        error_log("Pipeline handler error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        send_json_response(0, 1, 500, "Pipeline operation failed");
    } catch (Error $e) {
        error_log("Pipeline handler fatal error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        send_json_response(0, 1, 500, "Pipeline operation failed");
    }
}
