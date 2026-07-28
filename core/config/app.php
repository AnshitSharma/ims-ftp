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
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

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

    // F-7: the REAL environment WINS over this file.
    //
    // .env is a defaults file, not an override. Until now every key here was
    // putenv()'d unconditionally, so a value the caller had deliberately supplied
    // -- `DB_NAME=... php scripts/...`, a proc_open() $env array, a CI job, a cron
    // -- was silently replaced by whatever .env said. Two consequences, both real:
    //
    //   * tests/backfill/ledger_backfill_test.php hands its subprocess an explicit
    //     DB_NAME for a scratch replica; .env overwrote it, so backfill.php reported
    //     "Config not found (or is virtual)" about a config that is definitely
    //     present in the replica it was told to use. That is the whole of that
    //     file's 22 failures.
    //   * every gate report that boots through this file (equivalence, inventory,
    //     ledger, schema, slot, dual_write_soak_monitor) could NOT be pointed at a
    //     replica by environment at all -- which is why previous sessions had to
    //     clone the entire tree and edit the clone's .env. A report told to verify
    //     replica X while actually reading database Y would report GREEN about the
    //     wrong data: the same family as F-10, where reports exited 0 having run
    //     nothing.
    //
    // Safe on production: none of the keys this file carries is a standard
    // CGI/server variable (there is no PATH/SERVER_*/HTTP_*/DOCUMENT_ROOT among
    // them), so under LiteSpeed nothing pre-sets them and the skip below never
    // triggers there -- .env remains the sole source, exactly as before. If a host
    // ever DOES pre-set one at the vhost level, the key name is written to the
    // error log once per request rather than being resolved silently, so the
    // surprise is visible instead of inferred. Values are never logged.
    $overriddenByEnvironment = [];

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

        // Already supplied by the real environment => the caller wins, leave it be.
        // getenv() returning '' (rather than false) counts as supplied: setting a
        // variable to empty is an explicit choice, e.g. a blank DB_PASS.
        if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
            $overriddenByEnvironment[] = $key;
            continue;
        }

        // Set environment variable
        putenv("$key=$value");
        $_ENV[$key] = $value;

        // Do NOT define as constants here - let the main config define them
        // This prevents duplicate constant definition warnings
    }

    if ($overriddenByEnvironment) {
        // Names only -- never values; several of these keys are secrets.
        error_log('loadEnvFile: environment takes precedence over .env for: '
            . implode(', ', $overriddenByEnvironment));
    }

    return true;
}

// Load .env file from project root
$envPath = __DIR__ . '/../../.env';
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
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('APP_NAME', getenv('APP_NAME') ?: 'BDC Inventory Management System');
define('MAIN_SITE_URL', getenv('MAIN_SITE_URL') ?: 'https://localhost');

// =============================================================================
// JWT CONFIGURATION
// =============================================================================

$jwtSecret = getenv('JWT_SECRET');
if (!$jwtSecret) {
    throw new RuntimeException('JWT_SECRET not configured in environment');
}
define('JWT_SECRET_KEY', $jwtSecret);
define('JWT_ALGORITHM', getenv('JWT_ALGORITHM') ?: 'HS256');
define('JWT_EXPIRY_HOURS', (float)(getenv('JWT_EXPIRY_HOURS') ?: 24));
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

$corsOrigins = getenv('CORS_ALLOWED_ORIGINS') ?: '';
define('CORS_ALLOWED_ORIGINS', $corsOrigins ? array_map('trim', explode(',', $corsOrigins)) : []);

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
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');
if (!$dbUser || !$dbPass) {
    throw new RuntimeException('Database credentials not configured in environment');
}
$dbName = getenv('DB_NAME') ?: 'imsbdcmsbharatda_Ims_Production';

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

    error_log("P4.2: Transaction timeout configured: lock_wait=$lockWaitTimeout sec");

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database connection failed: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
        'error' => 'Internal server error'
    ]);
    // F-10 (2026-07-27): a bare `exit` is exit code 0. Under the web SAPI that is
    // irrelevant (the 500 above is what the client sees), but under CLI it meant
    // every scripts/verify/*_report.php exited 0 when the database was simply
    // unreachable -- so run_all.php reported the report GREEN and a whole gate
    // could pass having executed nothing. Caught live: `run_all.php --gate P2`
    // printed "equivalence: GREEN / ledger: GREEN / inventory: GREEN" and exit 0
    // against a replica the configured user had no rights on. Gate results are
    // only meaningful if a connection failure is loud, hence the CLI branch.
    // Web behavior is deliberately byte-identical (PHP_SAPI is 'litespeed' in
    // production, never 'cli'), so this cannot change the API's responses.
    exit(PHP_SAPI === 'cli' ? 1 : 0);
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
