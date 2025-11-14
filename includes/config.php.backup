<?php
/**
 * Updated config.php with Environment Loading
 * Add this to your includes/config.php file
 */

// Load environment variables from .env file
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
    }
    
    return true;
}

// Load .env file from project root
$envPath = __DIR__ . '/../.env';
loadEnvFile($envPath);

// Set timezone
$timezone = getenv('TIMEZONE') ?: 'UTC';
date_default_timezone_set($timezone);

// Main site URL
define('MAIN_SITE_URL', getenv('MAIN_SITE_URL') ?: 'https://localhost');

// JWT Configuration
define('JWT_SECRET_KEY', getenv('JWT_SECRET') ?: 'bdc-ims-default-secret-change-in-production-environment');
define('JWT_ALGORITHM', getenv('JWT_ALGORITHM') ?: 'HS256');
define('JWT_EXPIRY_HOURS', (int)(getenv('JWT_EXPIRY_HOURS') ?: 24));
define('JWT_ISSUER', getenv('JWT_ISSUER') ?: 'bdc-ims-api');
define('JWT_AUDIENCE', getenv('JWT_AUDIENCE') ?: 'bdc-ims-client');

// Application Configuration
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('APP_NAME', getenv('APP_NAME') ?: 'BDC Inventory Management System');

// Security Configuration
define('FORCE_HTTPS', filter_var(getenv('FORCE_HTTPS') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('SESSION_SECURE', filter_var(getenv('SESSION_SECURE') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// Rate Limiting
define('API_RATE_LIMIT_ENABLED', filter_var(getenv('API_RATE_LIMIT_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('API_RATE_LIMIT_REQUESTS', (int)(getenv('API_RATE_LIMIT_REQUESTS') ?: 1000));

// CORS Configuration
$corsOrigins = getenv('CORS_ALLOWED_ORIGINS') ?: '*';
define('CORS_ALLOWED_ORIGINS', explode(',', $corsOrigins));

// Logging
define('LOG_LEVEL', getenv('LOG_LEVEL') ?: 'info');
define('ERROR_LOG_ENABLED', filter_var(getenv('ERROR_LOG_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN));

// Component Settings
define('DEFAULT_COMPONENT_STATUS', (int)(getenv('DEFAULT_COMPONENT_STATUS') ?: 1));
define('AUTO_GENERATE_UUIDS', filter_var(getenv('AUTO_GENERATE_UUIDS') ?: 'true', FILTER_VALIDATE_BOOLEAN));

// Additional helpful functions
if (!function_exists('getEnv')) {
    function getEnv($key, $default = null) {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

function isProduction() {
    return getenv('APP_ENV') === 'production';
}

function isDevelopment() {
    return getenv('APP_ENV') === 'development';
}
?>