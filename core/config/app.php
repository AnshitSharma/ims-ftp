<?php
/**
 * BDC IMS - Main Configuration File
 *
 * This file handles:
 * - Environment variable loading
 * - Application configuration
 * - Database connection
 * - JWT settings
 * - Security settings
 */

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// =============================================================================
// ENVIRONMENT LOADING
// =============================================================================

/**
 * Load environment variables from .env file
 */
function loadEnvFile($path) {
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments and empty lines
        if (strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        // Parse key-value pairs
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Remove quotes if present
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }

        // Set environment variable
        putenv("$key=$value");
        $_ENV[$key] = $value;

        // Do NOT define as constants here - let the main config define them
        // This prevents duplicate constant definition warnings
    }

    return true;
}

// Load .env file from project root
$envPath = __DIR__ . '/../.env';
loadEnvFile($envPath);

// =============================================================================
// TIMEZONE CONFIGURATION
// =============================================================================

$timezone = getenv('TIMEZONE') ?: 'UTC';
date_default_timezone_set($timezone);

// =============================================================================
// APPLICATION CONFIGURATION
// =============================================================================

define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('APP_NAME', getenv('APP_NAME') ?: 'BDC Inventory Management System');
define('MAIN_SITE_URL', getenv('MAIN_SITE_URL') ?: 'https://localhost');

// =============================================================================
// JWT CONFIGURATION
// =============================================================================

define('JWT_SECRET_KEY', getenv('JWT_SECRET') ?: 'bdc-ims-default-secret-change-in-production-environment');
define('JWT_ALGORITHM', getenv('JWT_ALGORITHM') ?: 'HS256');
define('JWT_EXPIRY_HOURS', (int)(getenv('JWT_EXPIRY_HOURS') ?: 24));
define('JWT_ISSUER', getenv('JWT_ISSUER') ?: 'bdc-ims-api');
define('JWT_AUDIENCE', getenv('JWT_AUDIENCE') ?: 'bdc-ims-client');

// =============================================================================
// SECURITY CONFIGURATION
// =============================================================================

define('FORCE_HTTPS', filter_var(getenv('FORCE_HTTPS') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('SESSION_SECURE', filter_var(getenv('SESSION_SECURE') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// =============================================================================
// API RATE LIMITING
// =============================================================================

define('API_RATE_LIMIT_ENABLED', filter_var(getenv('API_RATE_LIMIT_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('API_RATE_LIMIT_REQUESTS', (int)(getenv('API_RATE_LIMIT_REQUESTS') ?: 1000));

// =============================================================================
// CORS CONFIGURATION
// =============================================================================

$corsOrigins = getenv('CORS_ALLOWED_ORIGINS') ?: '*';
define('CORS_ALLOWED_ORIGINS', explode(',', $corsOrigins));

// =============================================================================
// LOGGING CONFIGURATION
// =============================================================================

define('LOG_LEVEL', getenv('LOG_LEVEL') ?: 'info');
define('ERROR_LOG_ENABLED', filter_var(getenv('ERROR_LOG_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN));

// =============================================================================
// COMPONENT SETTINGS
// =============================================================================

define('DEFAULT_COMPONENT_STATUS', (int)(getenv('DEFAULT_COMPONENT_STATUS') ?: 1));
define('AUTO_GENERATE_UUIDS', filter_var(getenv('AUTO_GENERATE_UUIDS') ?: 'true', FILTER_VALIDATE_BOOLEAN));

// =============================================================================
// DATABASE CONFIGURATION & CONNECTION
// =============================================================================

// Database credentials (from .env or defaults)
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbUser = getenv('DB_USER') ?: 'shubhams_api';
$dbPass = getenv('DB_PASS') ?: '5C8R.wRErC_(';
$dbName = getenv('DB_NAME') ?: 'shubhams_ims_dev';

try {
    // PDO connection with proper options
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,  // Throw exceptions on errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,  // Default fetch mode
            PDO::ATTR_EMULATE_PREPARES => false,  // Use real prepared statements
            PDO::ATTR_PERSISTENT => false,  // Don't use persistent connections
        ]
    );

    // P4.2: Set transaction timeout configuration (prevent hanging transactions)
    // Default: 50 seconds (innodb_lock_wait_timeout), configurable via env var
    $lockWaitTimeout = getenv('DB_LOCK_WAIT_TIMEOUT') ?: 50;
    $pdo->exec("SET innodb_lock_wait_timeout = " . (int)$lockWaitTimeout);

    // Also set max_execution_time for query operations
    $maxExecutionTime = getenv('DB_MAX_EXECUTION_TIME') ?: 300;
    $pdo->exec("SET max_execution_time = " . (int)$maxExecutionTime . "000"); // Convert to milliseconds

    error_log("P4.2: Transaction timeout configured: lock_wait=$lockWaitTimeout sec, max_execution=" . ($maxExecutionTime/60) . " min");

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
        'error' => APP_DEBUG ? $e->getMessage() : 'Internal server error'
    ]);
    exit;
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Get environment variable with default fallback
 */
if (!function_exists('getEnv')) {
    function getEnv($key, $default = null) {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

/**
 * Check if running in production environment
 */
function isProduction() {
    return getenv('APP_ENV') === 'production';
}

/**
 * Check if running in development environment
 */
function isDevelopment() {
    return getenv('APP_ENV') === 'development';
}

/**
 * Get database connection (for backwards compatibility)
 * @return PDO
 */
function getDatabase() {
    global $pdo;
    return $pdo;
}

?>
