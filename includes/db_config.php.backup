<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Load environment variables from .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }
}

$dbHost = "localhost";
$dbUser = "shubhams_api";
$dbPass = "5C8R.wRErC_(";
$dbName = "shubhams_ims_dev";

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
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
        'error' => $e->getMessage()
    ]);
    exit;
}

?>