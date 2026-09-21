<?php
/**
 * BaseFunctions.php — shared helpers: JWT auth, ACL, component CRUD, users, dashboard.
 *
 * This is the SINGLE definition site for every function below. api.php and the
 * handler files must not redefine any of them — PHP will fatal on redeclare,
 * which is intentional (it makes a reintroduced duplicate loud instead of
 * silently shadowed, which is how the inventory API once lost UUID validation).
 *
 * Split by concern (audit Phase 4, roadmap item 23) into Acl.php / Inventory.php /
 * Users.php / Response.php / ActivityLog.php / Dashboard.php below — this file is now a thin
 * shim so none of the 6 files that `require_once` this one by its unchanged path and filename
 * had to change. Function names are unchanged and still global (no namespace), so every call
 * site anywhere in the codebase keeps working exactly as before. The one-time initialization
 * below (JWT secret, permission cache, VALID_COMPONENT_TYPES) MUST run exactly once before any
 * of the six files below are loaded, so it stays here rather than moving into any of them.
 */

// Include JWT Helper and ACL classes
require_once(__DIR__ . '/../auth/JWTHelper.php');
require_once(__DIR__ . '/../auth/ACL.php');
require_once(__DIR__ . '/SchemaHelper.php');

// Initialize JWT secret
$jwtSecret = defined('JWT_SECRET_KEY') ? JWT_SECRET_KEY : getenv('JWT_SECRET');
if (!$jwtSecret) {
    throw new RuntimeException('JWT_SECRET not configured');
}
JWTHelper::init($jwtSecret);

// Initialize permission cache (request-level caching for performance)
$GLOBALS['_permission_cache'] = [];

// Whitelist of valid component types (defense-in-depth for dynamic table names)
// 'serverplatform' (2026-08-25) is the shipped server product itself -- a physical box
// we stock, whose system board and chassis live INSIDE it and are never stocked on
// their own. It is a full component type: {type}inventory, ACL module, asset tags.
define('VALID_COMPONENT_TYPES', ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'chassis', 'pciecard', 'risercard', 'hbacard', 'sfp', 'serverplatform']);

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Acl.php';
require_once __DIR__ . '/Dashboard.php';
require_once __DIR__ . '/Inventory.php';
require_once __DIR__ . '/Users.php';
require_once __DIR__ . '/ActivityLog.php';
