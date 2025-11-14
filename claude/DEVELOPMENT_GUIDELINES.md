# DEVELOPMENT_GUIDELINES.md

**BDC IMS Coding Standards and Best Practices**

## Table of Contents

1. [PHP Coding Standards](#php-coding-standards)
2. [Naming Conventions](#naming-conventions)
3. [Code Organization](#code-organization)
4. [Database Conventions](#database-conventions)
5. [API Design Patterns](#api-design-patterns)
6. [Security Best Practices](#security-best-practices)
7. [Error Handling](#error-handling)
8. [Testing Guidelines](#testing-guidelines)
9. [Documentation Standards](#documentation-standards)
10. [Git Workflow](#git-workflow)

---

## PHP Coding Standards

### Code Style

**Follow PSR-12 Extended Coding Style Guide** (with BDC IMS project adaptations)

```php
<?php
/**
 * File description
 *
 * @author Your Name
 * @date 2025-11-05
 */

namespace BDC\IMS\Models;

class ExampleClass {
    // Properties
    private $database;
    protected $config;
    public $publicProperty;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     */
    public function __construct($pdo) {
        $this->database = $pdo;
    }

    /**
     * Method description
     *
     * @param string $uuid Component UUID
     * @param int $quantity Quantity to add
     * @return array Result data
     * @throws Exception If validation fails
     */
    public function addComponent($uuid, $quantity = 1) {
        // Validate inputs
        if (empty($uuid)) {
            throw new Exception("UUID is required");
        }

        // Business logic here
        $result = $this->performAction($uuid, $quantity);

        return $result;
    }

    /**
     * Private helper method
     */
    private function performAction($uuid, $quantity) {
        // Implementation
    }
}
```

### Indentation & Spacing

```php
// ✅ CORRECT
if ($condition) {
    doSomething();
} else {
    doSomethingElse();
}

// ✅ CORRECT - Array formatting
$config = [
    'database' => 'bdc_ims',
    'host' => 'localhost',
    'port' => 3306
];

// ❌ INCORRECT - Missing spaces
if($condition){doSomething();}

// ❌ INCORRECT - Inconsistent indentation
if ($condition) {
  doSomething();
    somethingElse();
}
```

**Rules**:
- Use **4 spaces** for indentation (no tabs)
- One blank line between methods
- Opening brace `{` on same line for control structures
- Opening brace `{` on new line for functions and classes

### PHP Tags

```php
// ✅ CORRECT - Full opening tag
<?php

// ❌ INCORRECT - Short tags
<?

// ✅ CORRECT - No closing tag for files containing only PHP
// (Prevents accidental whitespace output)
```

---

## Naming Conventions

### Variables

```php
// ✅ CORRECT - camelCase for variables
$componentUuid = 'cpu-xeon-gold-001';
$totalCount = 100;
$isCompatible = true;

// ❌ INCORRECT
$ComponentUUID = 'cpu-xeon-gold-001';  // PascalCase (use for classes)
$total_count = 100;                     // snake_case (use for database columns)
```

### Functions & Methods

```php
// ✅ CORRECT - camelCase
function validateComponentUuid($uuid) {
    // Implementation
}

function getCompatibleComponents($configUuid, $type) {
    // Implementation
}

// ❌ INCORRECT
function ValidateComponentUuid($uuid) { }  // PascalCase
function get_compatible_components() { }   // snake_case
```

### Classes

```php
// ✅ CORRECT - PascalCase
class ComponentDataService { }
class FlexibleCompatibilityValidator { }
class PCIeSlotTracker { }

// ❌ INCORRECT
class componentDataService { }      // camelCase
class component_data_service { }    // snake_case
```

### Constants

```php
// ✅ CORRECT - UPPER_SNAKE_CASE
define('JWT_EXPIRY_HOURS', 24);
define('MAX_PCIE_SLOTS', 8);
const DATABASE_VERSION = '1.0';

// ❌ INCORRECT
define('jwtExpiryHours', 24);
define('MaxPcieSlots', 8);
```

### Database Tables & Columns

```php
// ✅ CORRECT - Tables: lowercase with suffix
// cpuinventory, raminventory, server_configurations

// ✅ CORRECT - Columns: PascalCase (BDC IMS convention)
// ID, UUID, SerialNumber, Status, CreatedAt

// Query example
$sql = "SELECT ID, UUID, SerialNumber FROM cpuinventory WHERE Status = ?";
```

### API Actions

```php
// ✅ CORRECT - Format: {module}-{operation}
// auth-login
// cpu-add
// server-add-component
// compatibility-check

// ❌ INCORRECT
// loginAuth          (reversed)
// add_cpu            (underscore)
// serveraddcomponent (no separator)
```

### File Names

```php
// ✅ CORRECT

// Classes (PascalCase.php)
ComponentDataService.php
FlexibleCompatibilityValidator.php

// APIs (snake_case_api.php)
server_api.php
compatibility_api.php
roles_api.php

// Config files (snake_case.php)
db_config.php
config.php

// ❌ INCORRECT
component-data-service.php   // kebab-case
ServerAPI.php                // Wrong casing for API file
DB_Config.php                // Wrong casing for config
```

---

## Code Organization

### File Structure

```php
<?php
/**
 * Component Data Service
 *
 * Loads and caches component specifications from JSON files
 *
 * @author BDC Team
 * @date 2025-11-05
 */

// 1. Includes/Dependencies (top of file)
require_once(__DIR__ . '/db_config.php');
require_once(__DIR__ . '/ChassisManager.php');

// 2. Class Definition
class ComponentDataService {

    // 3. Properties (grouped by visibility)
    private static $instance = null;
    private $cache = [];

    protected $componentJsonPaths = [];

    public $lastLoadTime;

    // 4. Constructor
    public function __construct() {
        $this->initializeJsonPaths();
    }

    // 5. Public Methods (alphabetical or logical grouping)
    public function getComponentSpec($uuid) { }

    public function validateComponentUuid($uuid, $type) { }

    // 6. Protected Methods
    protected function loadJsonFile($path) { }

    // 7. Private Methods
    private function initializeJsonPaths() { }

    private function cacheSpec($uuid, $spec) { }
}

// 8. Helper Functions (if needed)
function loadComponentJson($path) {
    // Standalone helper
}
```

### Separation of Concerns

```php
// ✅ CORRECT - Separate validation, business logic, and persistence

class ServerBuilder {
    /**
     * Add component with validation
     */
    public function addComponent($uuid, $type, $quantity) {
        // 1. Validate input
        $this->validateInput($uuid, $type, $quantity);

        // 2. Check compatibility
        $compatible = $this->validateCompatibility($uuid, $type);

        // 3. Perform business logic
        $result = $this->performAddComponent($uuid, $type, $quantity);

        // 4. Persist changes
        $this->saveConfiguration();

        return $result;
    }

    private function validateInput($uuid, $type, $quantity) { }
    private function validateCompatibility($uuid, $type) { }
    private function performAddComponent($uuid, $type, $quantity) { }
    private function saveConfiguration() { }
}

// ❌ INCORRECT - Everything in one method
public function addComponent($uuid, $type, $quantity) {
    // 200 lines of mixed validation, logic, and database calls
}
```

### Dependency Injection

```php
// ✅ CORRECT - Inject dependencies
class ServerBuilder {
    private $pdo;
    private $validator;
    private $componentService;

    public function __construct($pdo, $validator, $componentService) {
        $this->pdo = $pdo;
        $this->validator = $validator;
        $this->componentService = $componentService;
    }
}

// Usage
$builder = new ServerBuilder($pdo, $validator, $componentService);

// ❌ INCORRECT - Global state and hidden dependencies
class ServerBuilder {
    public function __construct() {
        global $pdo;  // Hidden dependency
        $this->pdo = $pdo;
    }
}
```

---

## Database Conventions

### Query Preparation

```php
// ✅ CORRECT - Prepared statements with placeholders
$sql = "SELECT * FROM cpuinventory WHERE UUID = ? AND Status = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$uuid, $status]);

// ✅ CORRECT - Named parameters
$sql = "INSERT INTO cpuinventory (UUID, SerialNumber, Status)
        VALUES (:uuid, :serial, :status)";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':uuid' => $uuid,
    ':serial' => $serialNumber,
    ':status' => $status
]);

// ❌ INCORRECT - SQL injection vulnerability
$sql = "SELECT * FROM cpuinventory WHERE UUID = '$uuid'";
$result = $pdo->query($sql);
```

### Transaction Management

```php
// ✅ CORRECT - Transactions for multi-step operations
try {
    $pdo->beginTransaction();

    // Insert configuration
    $stmt = $pdo->prepare("INSERT INTO server_configurations (UUID, Name) VALUES (?, ?)");
    $stmt->execute([$configUuid, $name]);

    // Insert components
    $stmt = $pdo->prepare("INSERT INTO server_components (ConfigUUID, ComponentUUID) VALUES (?, ?)");
    foreach ($components as $component) {
        $stmt->execute([$configUuid, $component['uuid']]);
    }

    $pdo->commit();
    return true;

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Transaction failed: " . $e->getMessage());
    throw $e;
}

// ❌ INCORRECT - No transaction for related inserts
// If second insert fails, database is left in inconsistent state
```

### Column Naming

```php
// ✅ CORRECT - PascalCase (BDC IMS standard)
CREATE TABLE cpuinventory (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    UUID VARCHAR(255) NOT NULL,
    SerialNumber VARCHAR(255),
    Status TINYINT DEFAULT 1,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP
);

// Access in PHP
$component['ID'];
$component['UUID'];
$component['SerialNumber'];
```

---

## API Design Patterns

### Response Format Consistency

```php
// ✅ CORRECT - Use helper function for consistent responses
function send_json_response($success, $authenticated, $code, $message, $data = null) {
    $response = [
        'success' => (bool)$success,
        'authenticated' => (bool)$authenticated,
        'code' => $code,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Usage
send_json_response(1, 1, 200, "Component added successfully", [
    'component_id' => $id,
    'uuid' => $uuid
]);

// ❌ INCORRECT - Inconsistent manual responses
echo json_encode(['success' => true, 'data' => $data]);  // Missing fields
```

### Action-Based Routing Pattern

```php
// ✅ CORRECT - Centralized routing in api.php
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$parts = explode('-', $action, 2);
$module = $parts[0] ?? '';
$operation = $parts[1] ?? '';

switch ($module) {
    case 'cpu':
    case 'ram':
    case 'storage':
        handleComponentOperations($module, $operation, $user);
        break;

    case 'server':
        handleServerModule($operation, $user);
        break;
}

// ❌ INCORRECT - Separate files for each operation
// cpu_add.php, cpu_list.php, cpu_update.php, etc.
```

### Permission Checking Pattern

```php
// ✅ CORRECT - Check permissions before operations
function handleComponentOperations($module, $operation, $user) {
    global $pdo;

    $permissionMap = [
        'list' => "$module.view",
        'add' => "$module.create",
        'update' => "$module.edit",
        'delete' => "$module.delete"
    ];

    $requiredPermission = $permissionMap[$operation] ?? "$module.view";

    if (!hasPermission($pdo, $requiredPermission, $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions: $requiredPermission required");
    }

    // Proceed with operation
}

// ❌ INCORRECT - Performing operation before checking permissions
```

---

## Security Best Practices

### Input Validation

```php
// ✅ CORRECT - Validate and sanitize all inputs
function addComponent($pdo, $type, $data) {
    // Type validation
    $validTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'chassis', 'pciecard', 'hbacard'];
    if (!in_array($type, $validTypes)) {
        throw new Exception("Invalid component type");
    }

    // Required field validation
    if (empty($data['UUID']) || empty($data['SerialNumber'])) {
        throw new Exception("UUID and SerialNumber are required");
    }

    // UUID validation against JSON specs
    if (!ComponentDataService::validateComponentUuid($data['UUID'], $type)) {
        throw new Exception("Component UUID not found in specifications");
    }

    // Status validation
    $status = isset($data['Status']) ? (int)$data['Status'] : 1;
    if (!in_array($status, [0, 1, 2])) {
        throw new Exception("Invalid status value");
    }

    // Proceed with insertion
}

// ❌ INCORRECT - No validation
function addComponent($pdo, $type, $data) {
    $sql = "INSERT INTO {$type}inventory (UUID, SerialNumber) VALUES (?, ?)";
    // Direct insertion without validation
}
```

### Password Handling

```php
// ✅ CORRECT - Use password_hash and password_verify
function createUser($pdo, $username, $email, $password) {
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $sql = "INSERT INTO users (Username, Email, Password) VALUES (?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$username, $email, $hashedPassword]);
}

function authenticateUser($pdo, $username, $password) {
    $sql = "SELECT * FROM users WHERE Username = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['Password'])) {
        return $user;
    }
    return false;
}

// ❌ INCORRECT - Storing plain text or MD5
$sql = "INSERT INTO users (Password) VALUES (MD5(?))";  // Weak hash
$sql = "INSERT INTO users (Password) VALUES (?)";        // Plain text
```

### JWT Token Security

```php
// ✅ CORRECT - Secure JWT implementation
class JWTHelper {
    private static $secretKey = null;

    private static function getSecretKey() {
        if (self::$secretKey === null) {
            // Load from environment, not hardcoded
            self::$secretKey = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET');

            if (empty(self::$secretKey)) {
                throw new Exception("JWT secret key not configured");
            }
        }
        return self::$secretKey;
    }

    public static function generateToken($payload, $expirySeconds = 86400) {
        $issuedAt = time();
        $expire = $issuedAt + $expirySeconds;

        $payload = array_merge($payload, [
            'iat' => $issuedAt,
            'exp' => $expire
        ]);

        return JWT::encode($payload, self::getSecretKey(), 'HS256');
    }
}

// ❌ INCORRECT - Hardcoded secret
$secretKey = "my-secret-key-123";  // Exposed in code
```

### SQL Injection Prevention

```php
// ✅ CORRECT - Always use prepared statements
function getComponentsByStatus($pdo, $status) {
    $sql = "SELECT * FROM cpuinventory WHERE Status = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ❌ INCORRECT - String concatenation
function getComponentsByStatus($pdo, $status) {
    $sql = "SELECT * FROM cpuinventory WHERE Status = " . $status;
    return $pdo->query($sql)->fetchAll();
}
```

---

## Error Handling

### Exception Handling

```php
// ✅ CORRECT - Catch, log, and respond appropriately
try {
    $component = addComponent($pdo, $type, $data);

    send_json_response(1, 1, 200, "Component added successfully", [
        'component_id' => $component['id']
    ]);

} catch (PDOException $e) {
    // Log full error for debugging
    error_log("Database error in addComponent: " . $e->getMessage());

    // Send generic error to user (don't expose internals)
    send_json_response(0, 1, 500, "Database error occurred");

} catch (Exception $e) {
    error_log("Error in addComponent: " . $e->getMessage());
    send_json_response(0, 1, 500, $e->getMessage());
}

// ❌ INCORRECT - No error handling
$component = addComponent($pdo, $type, $data);
echo json_encode($component);
```

### Error Logging

```php
// ✅ CORRECT - Structured error logging
error_log("API Error [action=$action, user_id=$userId]: " . $e->getMessage());
error_log("Stack trace: " . $e->getTraceAsString());

// ❌ INCORRECT - No logging or exposing to user
echo "Error: " . $e->getMessage();  // Exposes internal details
```

### Validation Error Responses

```php
// ✅ CORRECT - Specific validation error messages
if (empty($uuid)) {
    send_json_response(0, 1, 400, "UUID parameter is required");
}

if (!isValidUuid($uuid)) {
    send_json_response(0, 1, 400, "Invalid UUID format");
}

if (!uuidExistsInJson($uuid, $type)) {
    send_json_response(0, 1, 400, "Component UUID not found in specifications");
}

// ❌ INCORRECT - Generic error
if (empty($uuid) || !isValidUuid($uuid)) {
    send_json_response(0, 1, 400, "Invalid request");
}
```

---

## Testing Guidelines

### Manual API Testing

```bash
# Test authentication
curl -X POST http://localhost:8000/api/api.php \
  -d "action=auth-login" \
  -d "username=admin" \
  -d "password=admin123"

# Test component list (with auth)
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d "action=cpu-list"

# Test error handling (invalid UUID)
curl -X POST http://localhost:8000/api/api.php \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d "action=cpu-add" \
  -d "UUID=invalid-uuid-not-in-json" \
  -d "SerialNumber=TEST001"
```

### Test Checklist for New Endpoints

- [ ] Valid request with all required parameters
- [ ] Missing required parameters
- [ ] Invalid parameter values
- [ ] Invalid authentication token
- [ ] Missing authentication token
- [ ] Insufficient permissions
- [ ] Database constraint violations
- [ ] Concurrent request handling

---

## Documentation Standards

### PHPDoc Comments

```php
/**
 * Validate component compatibility with current configuration
 *
 * Checks CPU-motherboard socket compatibility, RAM-motherboard type compatibility,
 * PCIe slot availability, and storage connection paths.
 *
 * @param string $configUuid Server configuration UUID
 * @param string $componentType Component type (cpu, ram, storage, etc.)
 * @param string $componentUuid Component UUID to validate
 * @param int $quantity Number of components to add (default: 1)
 *
 * @return array Validation result with 'compatible' boolean, 'score' float (0-1),
 *               'issues' array of problems, 'warnings' array of cautions
 *
 * @throws Exception If configuration or component not found
 * @throws InvalidArgumentException If parameters are invalid
 *
 * @example
 * $result = validateCompatibility('config-123', 'cpu', 'cpu-xeon-001', 2);
 * if ($result['compatible']) {
 *     echo "Compatible with score: " . $result['score'];
 * }
 */
public function validateCompatibility($configUuid, $componentType, $componentUuid, $quantity = 1) {
    // Implementation
}
```

### Inline Comments

```php
// ✅ CORRECT - Explain WHY, not WHAT
// UUID must be validated against JSON before inventory insertion
// to ensure we only track components with known specifications
if (!ComponentDataService::validateComponentUuid($uuid, $type)) {
    throw new Exception("Invalid UUID");
}

// ❌ INCORRECT - Obvious comments
// Check if UUID is empty
if (empty($uuid)) { }

// Set status to 1
$status = 1;
```

---

## Git Workflow

### Commit Message Format

```
<type>(<scope>): <subject>

<body>

<footer>
```

**Types**: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`

**Examples**:
```
feat(server): Add PCIe slot allocation tracking

Implemented PCIeSlotTracker class to manage slot allocation
during server configuration. Prevents over-allocation of slots.

Closes #45

---

fix(compatibility): Correct storage-HBA interface validation

Fixed bug where NVMe drives were incorrectly marked as compatible
with SAS-only HBA cards.

Fixes #67

---

docs(api): Update API_REFERENCE.md with new endpoints

Added documentation for server-get-compatible and
compatibility-check-multiple endpoints.
```

### Branch Naming

```
feature/pcie-slot-tracking
bugfix/storage-hba-validation
hotfix/jwt-expiry-issue
docs/api-reference-update
```

---

## Quick Reference Checklist

### Before Committing Code

- [ ] Code follows PSR-12 style guidelines
- [ ] All variables, functions, classes use correct naming convention
- [ ] Input validation is implemented
- [ ] SQL queries use prepared statements
- [ ] Errors are logged with `error_log()`
- [ ] API responses use `send_json_response()` helper
- [ ] Permission checks are in place
- [ ] PHPDoc comments added for public methods
- [ ] No hardcoded credentials or secrets
- [ ] Code tested manually with curl/Postman

### Code Review Focus Areas

1. **Security**: SQL injection, XSS, auth bypass vulnerabilities
2. **Validation**: All inputs validated and sanitized
3. **Error Handling**: Exceptions caught and logged properly
4. **Performance**: No N+1 queries, proper indexing
5. **Consistency**: Follows existing patterns and conventions
6. **Documentation**: PHPDoc comments and inline explanations

---

**Last Updated**: 2025-11-05
**Version**: 1.0
