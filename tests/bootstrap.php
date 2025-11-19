<?php
/**
 * PHPUnit Bootstrap File
 * Sets up testing environment
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define test mode constant
define('TEST_MODE', true);

// Load core configuration (will be updated to core/config/app.php after migration)
// require_once __DIR__ . '/../core/config/app.php';

// Load test helpers (to be implemented)
// require_once __DIR__ . '/helpers/DatabaseTestHelper.php';
// require_once __DIR__ . '/helpers/ApiTestHelper.php';

// Set up test database connection (separate from production)
// $testPdo = new PDO(
//     "mysql:host=localhost;dbname=bdc_ims_test",
//     "test_user",
//     "test_password"
// );

/**
 * Helper function to load test fixtures
 *
 * @param string $fixtureName The fixture file name
 * @return string The fixture contents
 * @throws Exception If fixture not found
 */
function loadFixture($fixtureName) {
    $fixturePath = __DIR__ . '/fixtures/' . $fixtureName;
    if (!file_exists($fixturePath)) {
        throw new Exception("Fixture not found: {$fixtureName}");
    }
    return file_get_contents($fixturePath);
}

/**
 * Helper function to reset test database
 */
function resetTestDatabase() {
    // Implement database reset logic
    // This will truncate tables and reload test data
}
