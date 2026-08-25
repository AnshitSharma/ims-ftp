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

// Global error handler.
// NOTE: PHP never dispatches E_ERROR / E_PARSE / E_CORE_ERROR to a userland error
// handler, so this only ever sees non-fatal diagnostics. Fatals are covered by the
// shutdown function below (A-E3).
set_error_handler(function($severity, $message, $file, $line) {
    error_log("PHP Error: $message in $file on line $line");
    return false; // let PHP's standard handler run too (display_errors is off)
});

// Exception handler. \Throwable, not Exception: an Error/TypeError is not an
// Exception and would otherwise bypass this entirely (A-E3).
set_exception_handler(function($exception) {
    error_log("Uncaught throwable: " . $exception->getMessage()
        . " in " . $exception->getFile() . ":" . $exception->getLine());
    send_json_response(0, 0, 500, "Internal server error");
});

// Fatal-error safety net (A-E3). Without this a fatal — OOM on a large compatibility
// sweep, a TypeError in a strict path — ended the request with an EMPTY body under an
// already-sent `Content-Type: application/json`, which every client parses as a
// protocol error rather than a server error.
register_shutdown_function(function() {
    $lastError = error_get_last();
    if ($lastError === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
    if (!in_array($lastError['type'], $fatalTypes, true)) {
        return;
    }

    error_log("FATAL: {$lastError['message']} in {$lastError['file']}:{$lastError['line']}");

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    // Hard rule #8: never leak the message, file path or any internals to the client.
    echo json_encode([
        'success' => false,
        'authenticated' => 0,
        'code' => 500,
        'message' => 'Internal server error',
        'timestamp' => date('c'),
        'data' => null
    ]);
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

    // A-P6: per-request logging is diagnostic only. It was unconditional, costing an
    // I/O write on every call and persisting usernames indefinitely (see below).
    $verboseRequestLog = defined('APP_ENV') && APP_ENV !== 'production';
    if ($verboseRequestLog) {
        error_log("API called with action: $action (Module: $module, Operation: $operation)");
    }

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

    if ($verboseRequestLog) {
        error_log("Authenticated user: " . $user['username'] . " (ID: " . $user['id'] . ")");
    }

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
            // Rack View is accessible to admin and super_admin. hasPermission()
            // grants a blanket bypass to both of those roles, so the explicit
            // role gate below just keeps every other role out before the
            // standard permission check runs.
            if (!userHasRole($pdo, $user['id'], 'super_admin') && !userHasRole($pdo, $user['id'], 'admin')) {
                send_json_response(0, 1, 403, "Insufficient permissions: admin or super_admin role required");
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
        case 'risercard':
        case 'hbacard':
        case 'sfp':
        case 'serverplatform':
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

} catch (\Throwable $e) {
    // A-E3: \Throwable -- an Error/TypeError raised in a handler is not an Exception
    // and used to escape this block entirely.
    error_log("API error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
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

    // Every component type shares the 'component' permission template.
    $moduleKey = in_array($module, VALID_COMPONENT_TYPES, true) ? 'component' : $module;

    if (!isset($map[$moduleKey][$operation])) {
        send_json_response(0, 1, 400, "Unknown operation: $module-$operation");
    }

    $requiredPermission = str_replace('{module}', $module, $map[$moduleKey][$operation]);

    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        // Second chance for per-configuration temporary access: the user may hold
        // this permission scoped to the very configuration this request names.
        // Scoped grants are kept out of the flat permission list on purpose, so
        // this is the only path that sees them — and it needs a config_uuid in
        // the request, which means server-create-start and the list endpoints can
        // never be satisfied this way.
        if (!hasScopedPermissionForRequest($pdo, $requiredPermission, $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
        }
    }

    // A build permission granted by a Request is only as wide as the Request:
    // server.create says nothing about WHICH hardware may be fitted, so the
    // component type this call names must be one the requester actually asked
    // for. Outside the fallback branch on purpose — an "Any server" grant is
    // global and satisfies the check above outright, and needs narrowing too.
    $narrowed = requestScopedComponentPermission($pdo, $user['id'], $module, $operation, $requiredPermission);
    if ($narrowed !== null) {
        send_json_response(0, 1, 403, "Insufficient permissions: $narrowed required");
    }
}

/**
 * Handle pipeline operations — dispatches to per-operation endpoint files in
 * handlers/pipelines/. Permission checks live inside each endpoint file
 * ($acl + $user_id are exposed via globals).
 */
function handlePipelineOperations($operation, $user) {
    global $pdo;

    // Raising and tracking a Request is open to any authenticated user — that is
    // the point of the Requests module, and it is what lets a viewer ask for
    // temporary access. The four operations below fall through to each handler's
    // own ACL check (pipeline.create / .view_own / .template_view), which is the
    // real gate.
    //
    // Everything else — editing Request Types, claiming, completing, reassigning,
    // cancelling — stays admin/super_admin in code, on top of ACL. Approving is
    // in that set on purpose: it is what grants access.
    // 'servers' is in this set because it feeds the create form's server picker:
    // the requester naming a server is by definition not an admin, and is usually
    // someone without server.view (the handler gates on pipeline.create instead).
    $selfServiceOperations = ['create', 'list', 'get', 'template-list', 'servers'];

    if (!in_array($operation, $selfServiceOperations, true)) {
        if (!userHasRole($pdo, $user['id'], 'super_admin') && !userHasRole($pdo, $user['id'], 'admin')) {
            send_json_response(0, 1, 403, "Insufficient permissions: admin or super_admin role required");
            return;
        }
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
            'servers'         => 'pipeline-servers.php',
            'list'            => 'pipeline-list.php',
            'get'             => 'pipeline-get.php',
            'claim'           => 'pipeline-claim.php',
            'complete'        => 'pipeline-complete.php',
            'reject'          => 'pipeline-reject.php',
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
