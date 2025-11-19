# BDC Inventory Management System - Folder Structure Optimization Plan

**Analysis Date:** 2025-11-19
**Analyzed By:** Senior PHP Architect
**Project:** BDC IMS (Inventory Management System)
**Current Version:** v1.0 (Stable, 2025-09-18)

---

## Executive Summary

The BDC IMS is a functional PHP 7.4+ REST API for hardware inventory management with **43 PHP files** that needs folder structure reorganization for better maintainability and scalability.

**Scope of This Plan:**
- ✅ Reorganize existing files into logical folder structure
- ✅ Create proper test folder structure for future testing
- ✅ Improve code organization without changing technology stack
- ✅ Maintain backward compatibility (no breaking changes)
- ❌ No Docker, Composer, or deployment changes
- ❌ No API versioning or architectural refactoring
- ❌ No namespace migration (keeping current code as-is)

**Goal:** Simple, incremental folder structure improvements that can be implemented in **1-2 weeks** with minimal risk.

---

## Table of Contents

1. [Current Folder Structure Analysis](#current-folder-structure-analysis)
2. [Identified Organization Issues](#identified-organization-issues)
3. [Proposed Optimized Folder Structure](#proposed-optimized-folder-structure)
4. [File Migration Plan](#file-migration-plan)
5. [Test Folder Structure](#test-folder-structure)
6. [Implementation Steps](#implementation-steps)
7. [Risks and Mitigation](#risks-and-mitigation)
8. [Timeline](#timeline)

---

## Current Folder Structure Analysis

### Current Directory Layout

```
ims ftp/
├── api/                         # API endpoints (mixed routing)
│   ├── api.php                  # Main router (1,479 lines, monolithic)
│   ├── acl/                     # ACL endpoints (4 files)
│   ├── auth/                    # Authentication endpoints (2 files)
│   ├── chassis/                 # Chassis operations (1 file)
│   ├── components/              # Component CRUD (1 file)
│   ├── server/                  # Server config (1 massive file - 5,724 lines)
│   ├── ticket/                  # Ticketing system (2 files)
│   └── dashboard/               # EMPTY - should be removed
│   └── functions/               # EMPTY - should be removed
│   └── search/                  # EMPTY - should be removed
│
├── includes/                    # Core logic (mixed concerns)
│   ├── config.php               # Config + DB connection mixed
│   ├── BaseFunctions.php        # 972 lines of utility functions
│   ├── JWTHelper.php            # JWT utilities
│   ├── ACL.php                  # ACL system
│   └── models/                  # 22 model classes
│       ├── ComponentCompatibility.php         # 229KB - HUGE
│       ├── ServerBuilder.php                  # 227KB - HUGE
│       ├── StorageConnectionValidator.php     # 74KB
│       ├── FlexibleCompatibilityValidator.php
│       ├── PCIeSlotTracker.php
│       ├── ChassisManager.php
│       ├── ComponentDataService.php
│       ├── DataExtractionUtilities.php
│       ├── ServerConfiguration.php
│       └── ... (13 more model files)
│
├── All-JSON/                    # Component specifications (9 types)
│   ├── cpu-jsons/               # CPU specification files
│   ├── motherboard-jsons/       # Motherboard specifications
│   ├── ram-jsons/               # RAM specifications
│   ├── storage-jsons/           # Storage specifications
│   ├── nic-jsons/               # Network card specifications
│   ├── caddy-jsons/             # Caddy specifications
│   ├── chassis-jsons/           # Chassis specifications
│   ├── pciecard-jsons/          # PCIe card specifications
│   └── hbacard-jsons/           # HBA card specifications
│
├── tests/                       # Minimal testing
│   └── unit/
│       └── cache/
│           └── CacheInterfaceTest.php  # Only 1 test file exists
│
├── database/
│   └── migrations/              # EMPTY - no migration files
│
├── documentation/               # Documentation files
│   ├── FOLDER_STRUCTURE.md
│   └── modernization-plan/
│       └── README.md            # This file
│
├── claude/                      # Claude Code documentation
│   ├── API_REFERENCE.md
│   ├── ARCHITECTURE.md
│   ├── DATABASE_SCHEMA.md
│   ├── DEVELOPMENT_GUIDELINES.md
│   └── FOLDER_STRUCTURE.md
│
├── .env                         # Environment config
├── .gitignore
├── CLAUDE.md                    # Main Claude instructions
├── README.md
└── shubhams_ims_dev.sql         # Database dump
```

### File Statistics

- **Total PHP Files:** 43
- **API Endpoint Files:** 15
- **Model Classes:** 22 (all in `includes/models/`)
- **Test Files:** 1 (minimal, in `tests/unit/cache/`)
- **JSON Spec Files:** 20+ (in `All-JSON/`)
- **Configuration Files:** 2 (`.env`, `includes/config.php`)
- **Empty Directories:** 3 (`api/dashboard/`, `api/functions/`, `api/search/`)

---

## Identified Organization Issues

### 1. Mixed Concerns in Folders

**Problem:** Files with different purposes are grouped together.

- `includes/` contains both configuration (`config.php`) and business logic models
- `api/api.php` mixes routing, authentication, and authorization logic in one file
- Empty legacy folders (`api/dashboard/`, `api/functions/`, `api/search/`) create confusion

**Impact:**
- Hard to find specific files
- Unclear separation of concerns
- Developers unsure where new files should go

### 2. Inconsistent Naming Conventions

**Problem:** Folder names don't follow a consistent pattern.

- `All-JSON/` uses capitalized name with hyphen
- `includes/` is generic and doesn't describe contents
- `claude/` folder for documentation (should be in `docs/`)

**Impact:**
- Confusing for new developers
- Hard to remember folder locations

### 3. No Organized Test Structure

**Problem:** Test directory is incomplete.

- Only `tests/unit/cache/` exists with 1 test file
- No structure for integration tests, API tests, or feature tests
- No test fixtures or helpers folder

**Impact:**
- Can't scale testing efforts
- No clear testing organization for future

### 4. Configuration Files Scattered

**Problem:** Configuration spread across multiple locations.

- `.env` in root
- `includes/config.php` mixes config with DB connection
- No centralized config directory

**Impact:**
- Hard to manage environment-specific settings
- Configuration changes require editing multiple files

### 5. Large Model Files

**Problem:** Some model files are extremely large.

- `ComponentCompatibility.php`: 229KB (5,000+ lines)
- `ServerBuilder.php`: 227KB (5,000+ lines)
- `server_api.php`: 253KB (5,724 lines)

**Impact:**
- Hard to navigate and maintain
- High risk of merge conflicts
- Performance issues in IDEs

**Note:** This plan doesn't split these files (that's for future refactoring), but organizes them better.

### 6. Resource Files Not Clearly Separated

**Problem:** JSON specification files in `All-JSON/` are data resources, not code.

**Impact:**
- Mixed code and data in root directory structure
- Not immediately clear these are data files, not configuration

---

## Proposed Optimized Folder Structure

### New Organization Principles

1. **Separate code, data, config, and tests** into distinct top-level folders
2. **Group related API endpoints** by domain (not implementation detail)
3. **Create consistent naming** conventions
4. **Remove empty directories**
5. **Organize models** by domain area
6. **Prepare test structure** for future expansion

### Optimized Directory Layout

```
ims-ftp/
│
├── api/                          # API layer
│   ├── index.php                 # Main API entry point (renamed from api.php)
│   │
│   ├── handlers/                 # API endpoint handlers (organized by domain)
│   │   ├── auth/
│   │   │   ├── login.php
│   │   │   └── logout.php
│   │   │
│   │   ├── acl/
│   │   │   ├── permissions.php
│   │   │   ├── roles.php
│   │   │   ├── users.php
│   │   │   └── groups.php
│   │   │
│   │   ├── components/
│   │   │   └── component_api.php  # Handles all component CRUD
│   │   │
│   │   ├── server/
│   │   │   └── server_api.php     # Server configuration endpoints
│   │   │
│   │   ├── chassis/
│   │   │   └── chassis_api.php
│   │   │
│   │   └── tickets/
│   │       ├── ticket_list.php
│   │       └── ticket_operations.php
│   │
│   └── middleware/               # Shared API middleware (future use)
│       └── .gitkeep
│
├── core/                         # Core application logic (renamed from includes/)
│   ├── config/                   # Configuration files
│   │   ├── app.php               # Application config (renamed from config.php)
│   │   └── database.php          # Database connection setup
│   │
│   ├── auth/                     # Authentication & Authorization
│   │   ├── JWTHelper.php
│   │   └── ACL.php
│   │
│   ├── models/                   # Domain models (organized by area)
│   │   ├── server/               # Server-related models
│   │   │   ├── ServerBuilder.php
│   │   │   ├── ServerConfiguration.php
│   │   │   └── ServerStateManager.php
│   │   │
│   │   ├── compatibility/        # Compatibility validation models
│   │   │   ├── FlexibleCompatibilityValidator.php
│   │   │   ├── ComponentCompatibility.php
│   │   │   ├── StorageConnectionValidator.php
│   │   │   ├── PCIeSlotTracker.php
│   │   │   └── CompatibilityRulesEngine.php
│   │   │
│   │   ├── components/           # Component-related models
│   │   │   ├── ComponentDataService.php
│   │   │   ├── ComponentDataExtractor.php
│   │   │   ├── ComponentDataLoader.php
│   │   │   ├── ComponentValidator.php
│   │   │   └── ComponentQueryModel.php
│   │   │
│   │   ├── chassis/              # Chassis-related models
│   │   │   ├── ChassisManager.php
│   │   │   └── ChassisConfigLoader.php
│   │   │
│   │   └── shared/               # Shared models/utilities
│   │       ├── DataExtractionUtilities.php
│   │       ├── QueryModel.php
│   │       └── ValidationHelper.php
│   │
│   ├── services/                 # Business logic services
│   │   └── .gitkeep              # For future service classes
│   │
│   ├── helpers/                  # Helper functions
│   │   └── BaseFunctions.php     # Utility functions
│   │
│   └── cache/                    # Cache implementations (currently missing)
│       ├── CacheInterface.php
│       └── .gitkeep
│
├── resources/                    # Data resources (renamed from All-JSON/)
│   ├── specifications/           # Component specification files
│   │   ├── cpu/
│   │   ├── motherboard/
│   │   ├── ram/
│   │   ├── storage/
│   │   ├── nic/
│   │   ├── caddy/
│   │   ├── chassis/
│   │   ├── pciecard/
│   │   └── hbacard/
│   │
│   └── templates/                # For future email/report templates
│       └── .gitkeep
│
├── tests/                        # Test suite (expanded structure)
│   ├── unit/                     # Unit tests
│   │   ├── models/
│   │   │   ├── compatibility/
│   │   │   │   └── .gitkeep
│   │   │   ├── components/
│   │   │   │   └── .gitkeep
│   │   │   └── server/
│   │   │       └── .gitkeep
│   │   │
│   │   ├── helpers/
│   │   │   └── .gitkeep
│   │   │
│   │   └── cache/
│   │       └── CacheInterfaceTest.php  # Existing test
│   │
│   ├── integration/              # Integration tests (future)
│   │   ├── api/
│   │   │   └── .gitkeep
│   │   └── database/
│   │       └── .gitkeep
│   │
│   ├── fixtures/                 # Test data fixtures
│   │   ├── sample_cpu_specs.json
│   │   ├── sample_motherboard_specs.json
│   │   └── .gitkeep
│   │
│   ├── helpers/                  # Test helper functions
│   │   └── .gitkeep
│   │
│   └── bootstrap.php             # Test bootstrap file
│
├── database/                     # Database files
│   ├── migrations/               # Database migrations (currently empty)
│   │   └── .gitkeep
│   ├── seeds/                    # Database seeders
│   │   └── .gitkeep
│   └── schema.sql                # Full schema (renamed from shubhams_ims_dev.sql)
│
├── docs/                         # Documentation (consolidated)
│   ├── api/
│   │   └── API_REFERENCE.md
│   ├── architecture/
│   │   ├── ARCHITECTURE.md
│   │   ├── DATABASE_SCHEMA.md
│   │   └── FOLDER_STRUCTURE.md
│   ├── development/
│   │   └── DEVELOPMENT_GUIDELINES.md
│   ├── planning/
│   │   └── modernization-plan/
│   │       └── README.md         # This file
│   └── README.md                 # Documentation index
│
├── logs/                         # Application logs (gitignored)
│   └── .gitkeep
│
├── .env                          # Environment configuration
├── .gitignore
├── CLAUDE.md                     # Claude Code instructions
└── README.md                     # Project README
```

### Key Changes Summary

| Old Location | New Location | Reason |
|--------------|--------------|--------|
| `api/api.php` | `api/index.php` | Clearer main entry point |
| `api/acl/*.php` | `api/handlers/acl/` | Group by domain |
| `api/auth/*.php` | `api/handlers/auth/` | Group by domain |
| `api/components/*.php` | `api/handlers/components/` | Group by domain |
| `api/server/*.php` | `api/handlers/server/` | Group by domain |
| `api/chassis/*.php` | `api/handlers/chassis/` | Group by domain |
| `api/ticket/*.php` | `api/handlers/tickets/` | Group by domain |
| `includes/` | `core/` | More descriptive name |
| `includes/config.php` | `core/config/app.php` | Separate config folder |
| `includes/JWTHelper.php` | `core/auth/JWTHelper.php` | Group auth files |
| `includes/ACL.php` | `core/auth/ACL.php` | Group auth files |
| `includes/BaseFunctions.php` | `core/helpers/BaseFunctions.php` | Separate helpers |
| `includes/models/` | `core/models/` (organized by domain) | Better organization |
| `All-JSON/` | `resources/specifications/` | Clearer purpose |
| `shubhams_ims_dev.sql` | `database/schema.sql` | Better naming |
| `claude/` | `docs/` | Consolidated docs |
| `documentation/` | `docs/planning/` | Consolidated docs |

---

## File Migration Plan

### Phase 1: Prepare New Structure (Low Risk)

**Goal:** Create new folders without moving files yet.

**Actions:**
1. Create new folder structure (all folders listed above)
2. Add `.gitkeep` files to empty folders to track them in git
3. Create `tests/bootstrap.php` file
4. Commit changes

**Risk:** None - just creating folders

### Phase 2: Move Documentation Files (Low Risk)

**Goal:** Consolidate documentation.

**Actions:**
1. Move `claude/*.md` → `docs/api/` and `docs/architecture/`
2. Move `documentation/modernization-plan/` → `docs/planning/modernization-plan/`
3. Update internal links in markdown files
4. Remove empty `claude/` and `documentation/` folders
5. Test all documentation links
6. Commit changes

**Risk:** Low - only affects documentation

### Phase 3: Reorganize Core Files (Medium Risk)

**Goal:** Move core logic files to new structure.

**Actions:**

1. **Move configuration files:**
   ```bash
   mkdir -p core/config
   cp includes/config.php core/config/app.php
   # Update require paths in core/config/app.php
   ```

2. **Move authentication files:**
   ```bash
   mkdir -p core/auth
   mv includes/JWTHelper.php core/auth/
   mv includes/ACL.php core/auth/
   # Update require paths in moved files
   ```

3. **Move helpers:**
   ```bash
   mkdir -p core/helpers
   mv includes/BaseFunctions.php core/helpers/
   ```

4. **Organize models by domain:**
   ```bash
   mkdir -p core/models/{server,compatibility,components,chassis,shared}

   # Server models
   mv includes/models/ServerBuilder.php core/models/server/
   mv includes/models/ServerConfiguration.php core/models/server/
   mv includes/models/ServerStateManager.php core/models/server/

   # Compatibility models
   mv includes/models/FlexibleCompatibilityValidator.php core/models/compatibility/
   mv includes/models/ComponentCompatibility.php core/models/compatibility/
   mv includes/models/StorageConnectionValidator.php core/models/compatibility/
   mv includes/models/PCIeSlotTracker.php core/models/compatibility/
   mv includes/models/CompatibilityRulesEngine.php core/models/compatibility/

   # Component models
   mv includes/models/ComponentDataService.php core/models/components/
   mv includes/models/ComponentDataExtractor.php core/models/components/
   mv includes/models/ComponentDataLoader.php core/models/components/
   mv includes/models/ComponentValidator.php core/models/components/
   mv includes/models/ComponentQueryModel.php core/models/components/

   # Chassis models
   mv includes/models/ChassisManager.php core/models/chassis/
   mv includes/models/ChassisConfigLoader.php core/models/chassis/

   # Shared utilities
   mv includes/models/DataExtractionUtilities.php core/models/shared/
   mv includes/models/QueryModel.php core/models/shared/
   mv includes/models/ValidationHelper.php core/models/shared/
   ```

5. **Update all `require_once` statements** in moved files to reflect new paths
6. **Test application** thoroughly
7. **Commit changes**

**Risk:** Medium - requires updating require paths

### Phase 4: Reorganize API Handlers (Medium Risk)

**Goal:** Organize API endpoint files by domain.

**Actions:**

1. **Create handler folders:**
   ```bash
   mkdir -p api/handlers/{auth,acl,components,server,chassis,tickets}
   ```

2. **Move API handler files:**
   ```bash
   # Auth handlers
   mv api/auth/login.php api/handlers/auth/
   mv api/auth/logout.php api/handlers/auth/

   # ACL handlers
   mv api/acl/permissions.php api/handlers/acl/
   mv api/acl/roles.php api/handlers/acl/
   mv api/acl/users.php api/handlers/acl/
   mv api/acl/groups.php api/handlers/acl/

   # Component handler
   mv api/components/component_api.php api/handlers/components/

   # Server handler
   mv api/server/server_api.php api/handlers/server/

   # Chassis handler
   mv api/chassis/chassis_api.php api/handlers/chassis/

   # Ticket handlers
   mv api/ticket/ticket_list.php api/handlers/tickets/
   mv api/ticket/ticket_operations.php api/handlers/tickets/
   ```

3. **Update `api/api.php`:**
   - Rename to `api/index.php`
   - Update all require paths to point to `handlers/` subdirectories
   - Update routing logic to reflect new paths

4. **Update web server configuration** (if needed) to point to `api/index.php`

5. **Test all API endpoints**

6. **Remove empty old folders:**
   ```bash
   rm -rf api/auth
   rm -rf api/acl
   rm -rf api/components
   rm -rf api/server
   rm -rf api/chassis
   rm -rf api/ticket
   rm -rf api/dashboard  # empty
   rm -rf api/functions  # empty
   rm -rf api/search     # empty
   ```

7. **Commit changes**

**Risk:** Medium - affects API routing

### Phase 5: Move Resource Files (Low Risk)

**Goal:** Rename and organize JSON specification files.

**Actions:**

1. **Move JSON files:**
   ```bash
   mkdir -p resources/specifications
   mv All-JSON/cpu-jsons resources/specifications/cpu
   mv All-JSON/motherboard-jsons resources/specifications/motherboard
   mv All-JSON/ram-jsons resources/specifications/ram
   mv All-JSON/storage-jsons resources/specifications/storage
   mv All-JSON/nic-jsons resources/specifications/nic
   mv All-JSON/caddy-jsons resources/specifications/caddy
   mv All-JSON/chassis-jsons resources/specifications/chassis
   mv All-JSON/pciecard-jsons resources/specifications/pciecard
   mv All-JSON/hbacard-jsons resources/specifications/hbacard
   ```

2. **Update all code references** from `All-JSON/` to `resources/specifications/`
   - Search in `core/models/components/ComponentDataService.php`
   - Search in `core/models/chassis/ChassisManager.php`
   - Search in any other files that load JSON specs

3. **Remove old folder:**
   ```bash
   rm -rf All-JSON
   ```

4. **Test component loading** to ensure JSON files are found

5. **Commit changes**

**Risk:** Low - just file paths

### Phase 6: Organize Database Files (Low Risk)

**Goal:** Better organize database-related files.

**Actions:**

1. **Move SQL dump:**
   ```bash
   mv shubhams_ims_dev.sql database/schema.sql
   ```

2. **Update documentation** referencing the old SQL file

3. **Commit changes**

**Risk:** None - just renaming

### Phase 7: Clean Up Root Directory (Low Risk)

**Goal:** Remove old `includes/` folder after verifying everything works.

**Actions:**

1. **Verify application works** with new structure
2. **Run comprehensive tests**
3. **Remove old `includes/` folder:**
   ```bash
   rm -rf includes
   ```
4. **Commit changes**

**Risk:** Low if previous steps tested properly

---

## Test Folder Structure

### Organized Test Structure

```
tests/
├── unit/                         # Unit tests (test individual classes/functions)
│   ├── models/
│   │   ├── compatibility/        # Tests for compatibility validators
│   │   │   ├── ComponentCompatibilityTest.php
│   │   │   ├── StorageConnectionValidatorTest.php
│   │   │   └── PCIeSlotTrackerTest.php
│   │   │
│   │   ├── components/           # Tests for component models
│   │   │   ├── ComponentDataServiceTest.php
│   │   │   └── ComponentValidatorTest.php
│   │   │
│   │   └── server/               # Tests for server models
│   │       ├── ServerBuilderTest.php
│   │       └── ServerConfigurationTest.php
│   │
│   ├── helpers/                  # Tests for helper functions
│   │   └── BaseFunctionsTest.php
│   │
│   ├── auth/                     # Tests for auth classes
│   │   ├── JWTHelperTest.php
│   │   └── ACLTest.php
│   │
│   └── cache/
│       └── CacheInterfaceTest.php  # Existing test
│
├── integration/                  # Integration tests (test multiple components together)
│   ├── api/                      # API endpoint tests
│   │   ├── AuthApiTest.php
│   │   ├── ComponentApiTest.php
│   │   ├── ServerApiTest.php
│   │   └── ACLApiTest.php
│   │
│   ├── database/                 # Database integration tests
│   │   ├── ComponentRepositoryTest.php
│   │   └── ServerConfigurationTest.php
│   │
│   └── compatibility/            # End-to-end compatibility tests
│       └── ServerBuildWorkflowTest.php
│
├── fixtures/                     # Test data
│   ├── components/
│   │   ├── sample_cpu.json
│   │   ├── sample_motherboard.json
│   │   ├── sample_ram.json
│   │   └── sample_storage.json
│   │
│   ├── servers/
│   │   ├── sample_server_config.json
│   │   └── sample_server_with_components.json
│   │
│   └── database/
│       ├── test_users.sql
│       └── test_components.sql
│
├── helpers/                      # Test helper functions
│   ├── TestCase.php              # Base test case class
│   ├── DatabaseTestHelper.php   # Database setup/teardown helpers
│   └── ApiTestHelper.php        # API testing utilities
│
└── bootstrap.php                 # Test bootstrap (loads autoloader, sets up test env)
```

### Test Bootstrap File

**File:** `tests/bootstrap.php`

```php
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

// Load core configuration
require_once __DIR__ . '/../core/config/app.php';

// Load test helpers
require_once __DIR__ . '/helpers/DatabaseTestHelper.php';
require_once __DIR__ . '/helpers/ApiTestHelper.php';

// Set up test database connection (separate from production)
// $testPdo = new PDO(
//     "mysql:host=localhost;dbname=bdc_ims_test",
//     "test_user",
//     "test_password"
// );

// Helper function to load test fixtures
function loadFixture($fixtureName) {
    $fixturePath = __DIR__ . '/fixtures/' . $fixtureName;
    if (!file_exists($fixturePath)) {
        throw new Exception("Fixture not found: {$fixtureName}");
    }
    return file_get_contents($fixturePath);
}

// Helper function to reset test database
function resetTestDatabase() {
    // Implement database reset logic
}
```

### Sample Test Files (For Reference)

**Example Unit Test:** `tests/unit/auth/JWTHelperTest.php`

```php
<?php
/**
 * Unit test for JWTHelper class
 */

require_once __DIR__ . '/../../../core/auth/JWTHelper.php';

class JWTHelperTest extends PHPUnit\Framework\TestCase {

    public function testGenerateToken() {
        $payload = ['user_id' => 123, 'role' => 'admin'];
        $token = JWTHelper::generateToken($payload, 3600);

        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }

    public function testValidateToken() {
        $payload = ['user_id' => 123, 'role' => 'admin'];
        $token = JWTHelper::generateToken($payload, 3600);

        $decoded = JWTHelper::validateToken($token);

        $this->assertEquals(123, $decoded['user_id']);
        $this->assertEquals('admin', $decoded['role']);
    }

    public function testValidateExpiredToken() {
        $payload = ['user_id' => 123];
        $token = JWTHelper::generateToken($payload, -1); // Expired

        $decoded = JWTHelper::validateToken($token);

        $this->assertNull($decoded);
    }
}
```

**Example Integration Test:** `tests/integration/api/ComponentApiTest.php`

```php
<?php
/**
 * Integration test for Component API endpoints
 */

class ComponentApiTest extends PHPUnit\Framework\TestCase {

    private $authToken;

    protected function setUp(): void {
        // Get auth token for API requests
        $this->authToken = $this->loginAsAdmin();
    }

    public function testListCpuComponents() {
        $response = $this->makeApiRequest('POST', '/api/index.php', [
            'action' => 'cpu-list'
        ], $this->authToken);

        $this->assertEquals(200, $response['code']);
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
    }

    public function testAddCpuComponent() {
        $componentData = [
            'action' => 'cpu-add',
            'UUID' => 'test-cpu-001',
            'SerialNumber' => 'SN123456789',
            'Status' => 1
        ];

        $response = $this->makeApiRequest('POST', '/api/index.php',
            $componentData, $this->authToken);

        $this->assertEquals(201, $response['code']);
        $this->assertTrue($response['success']);
    }

    private function loginAsAdmin() {
        $response = $this->makeApiRequest('POST', '/api/index.php', [
            'action' => 'auth-login',
            'username' => 'admin',
            'password' => 'password'
        ]);

        return $response['data']['tokens']['access_token'] ?? null;
    }

    private function makeApiRequest($method, $url, $data, $token = null) {
        // Implement API request helper
        // This would use CURL or similar to make actual HTTP requests
    }
}
```

---

## Implementation Steps

### Step-by-Step Implementation

#### Week 1: Preparation and Low-Risk Moves

**Day 1-2: Create New Folder Structure**
- [ ] Create all new folders as specified
- [ ] Add `.gitkeep` files to empty folders
- [ ] Create `tests/bootstrap.php`
- [ ] Commit changes

**Day 3-4: Move Documentation**
- [ ] Move `claude/*.md` to `docs/`
- [ ] Move `documentation/` to `docs/planning/`
- [ ] Update all internal documentation links
- [ ] Test documentation links
- [ ] Commit changes

**Day 5: Move Database Files**
- [ ] Rename `shubhams_ims_dev.sql` to `database/schema.sql`
- [ ] Update documentation references
- [ ] Commit changes

#### Week 2: Core File Reorganization

**Day 1-2: Move Core Files**
- [ ] Create `core/config/`, `core/auth/`, `core/helpers/` folders
- [ ] Copy (don't move yet) `includes/config.php` to `core/config/app.php`
- [ ] Copy auth files to `core/auth/`
- [ ] Copy `BaseFunctions.php` to `core/helpers/`
- [ ] Update `require_once` paths in copied files
- [ ] Test application with dual paths (old and new)
- [ ] If working, remove old files
- [ ] Commit changes

**Day 3-4: Reorganize Models**
- [ ] Create `core/models/` subdirectories (server, compatibility, components, chassis, shared)
- [ ] Copy model files to new locations
- [ ] Update `require_once` paths in all copied files
- [ ] Update `require_once` paths in files that use these models
- [ ] Test thoroughly
- [ ] Remove old `includes/models/` files
- [ ] Commit changes

**Day 5: Reorganize API Handlers**
- [ ] Create `api/handlers/` subdirectories
- [ ] Copy API handler files to new locations
- [ ] Update `api/api.php` routing to use new paths
- [ ] Rename `api/api.php` to `api/index.php`
- [ ] Update web server config (if needed)
- [ ] Test all API endpoints
- [ ] Remove old handler files and empty folders
- [ ] Commit changes

#### Week 3: Resource Files and Final Cleanup

**Day 1-2: Move JSON Specifications**
- [ ] Create `resources/specifications/` folders
- [ ] Copy JSON files from `All-JSON/` to `resources/specifications/`
- [ ] Update all code references to new paths
- [ ] Test component data loading
- [ ] Remove old `All-JSON/` folder
- [ ] Commit changes

**Day 3: Final Cleanup**
- [ ] Verify all functionality works with new structure
- [ ] Run comprehensive tests
- [ ] Remove old `includes/` folder
- [ ] Update `FOLDER_STRUCTURE.md` documentation
- [ ] Commit changes

**Day 4-5: Testing and Documentation**
- [ ] Test all API endpoints thoroughly
- [ ] Test component compatibility validation
- [ ] Test server configuration workflows
- [ ] Update `docs/architecture/FOLDER_STRUCTURE.md`
- [ ] Create migration guide document
- [ ] Final commit

---

## Risks and Mitigation

### Risk 1: Breaking `require_once` Paths

**Likelihood:** High
**Impact:** Critical (application won't work)

**Mitigation:**
- Use copy-first approach (keep old files until new ones are tested)
- Update paths systematically, one folder at a time
- Test after each move before committing
- Keep comprehensive checklist of all files moved
- Use search/replace carefully for path updates

**Recovery Plan:**
- Git revert to previous commit
- Review path changes one by one
- Fix broken paths incrementally

### Risk 2: Missing File References

**Likelihood:** Medium
**Impact:** Medium (some features break)

**Mitigation:**
- Search entire codebase for old path references before removing old folders
- Use IDE "Find Usages" feature
- Grep for old folder names: `grep -r "includes/models" .`
- Create checklist of all moved files
- Test all major features after migration

**Recovery Plan:**
- Use git to find moved files
- Fix missing references
- Add to test suite

### Risk 3: Web Server Configuration Issues

**Likelihood:** Low
**Impact:** Medium (API inaccessible)

**Mitigation:**
- Document current web server config before changes
- Test on development environment first
- Have `.htaccess` or nginx config ready
- Keep backup of working config

**Recovery Plan:**
- Restore old web server config
- Use symbolic links temporarily if needed

### Risk 4: JSON Specification Path Errors

**Likelihood:** Medium
**Impact:** High (component validation breaks)

**Mitigation:**
- Search for all `All-JSON` references before moving
- Update `ComponentDataService.php` carefully
- Test component loading after move
- Keep copy of JSON files until verified working

**Recovery Plan:**
- Restore JSON files to old location
- Fix path references
- Re-test

### Risk 5: Team Confusion During Transition

**Likelihood:** Medium
**Impact:** Medium (productivity loss)

**Mitigation:**
- Create clear migration guide
- Communicate changes in team meeting
- Update documentation immediately
- Provide "old path → new path" reference guide
- Complete migration in one sprint (don't drag out)

---

## Timeline

### Total Time: 2-3 Weeks

**Week 1: Preparation & Low-Risk Moves**
- Create folder structure
- Move documentation
- Move database files
- **Deliverable:** New structure in place, low-risk files moved

**Week 2: Core Migration**
- Move core files (`includes/` → `core/`)
- Reorganize models by domain
- Reorganize API handlers
- **Deliverable:** Main code reorganized and working

**Week 3: Cleanup & Testing**
- Move resource files (JSON specs)
- Remove old folders
- Comprehensive testing
- Update documentation
- **Deliverable:** Migration complete, all tests passing

### Daily Time Estimate

- **1-2 hours/day** for systematic file moves and testing
- **4-6 hours** for comprehensive testing at end
- **Total: ~15-20 hours of focused work**

### Milestones

- [ ] **Milestone 1 (End of Week 1):** New structure created, docs moved, application still works
- [ ] **Milestone 2 (End of Week 2):** Core files reorganized, API working, tests passing
- [ ] **Milestone 3 (End of Week 3):** Full migration complete, old folders removed, documentation updated

---

## Success Criteria

### Functional Requirements

- [ ] All API endpoints work correctly
- [ ] Component CRUD operations functional
- [ ] Server configuration workflows operational
- [ ] Authentication and authorization working
- [ ] Component compatibility validation working
- [ ] JSON specification loading successful

### Structural Requirements

- [ ] No empty legacy folders (`api/dashboard/`, `api/functions/`, `api/search/`)
- [ ] All core files in `core/` directory
- [ ] API handlers organized in `api/handlers/` by domain
- [ ] Models organized by domain area (server, compatibility, components)
- [ ] Test folder structure ready for expansion
- [ ] Documentation consolidated in `docs/`
- [ ] Resource files in `resources/specifications/`

### Documentation Requirements

- [ ] `docs/architecture/FOLDER_STRUCTURE.md` updated
- [ ] Migration guide created
- [ ] All code path references updated
- [ ] README files in new folders

---

## Next Steps

### Before Starting

1. **Get team approval** for folder structure changes
2. **Create feature branch:** `feature/folder-structure-optimization`
3. **Back up database** and codebase
4. **Schedule implementation** during low-traffic period
5. **Inform stakeholders** of upcoming changes

### After Completion

1. **Create pull request** with detailed description of changes
2. **Conduct code review** with team
3. **Merge to main branch**
4. **Deploy to staging** environment first
5. **Test thoroughly** on staging
6. **Deploy to production** after verification
7. **Monitor** for any issues post-deployment

---

## Conclusion

This folder structure optimization plan provides a **pragmatic, low-risk approach** to improving code organization without requiring major architectural changes.

**Benefits:**
- ✅ Better organized code (easier to find files)
- ✅ Clear separation of concerns (API, core logic, resources, tests)
- ✅ Scalable test structure for future expansion
- ✅ Consolidated documentation
- ✅ Removal of legacy empty folders
- ✅ More intuitive folder names

**What's NOT Included (For Future):**
- ❌ Namespace migration (PSR-4)
- ❌ Dependency injection
- ❌ Docker containerization
- ❌ API versioning
- ❌ Database migrations
- ❌ CI/CD pipelines
- ❌ Splitting large files

**Estimated Effort:** 2-3 weeks (15-20 hours)
**Risk Level:** Low-Medium (with proper testing)
**Expected Outcome:** Cleaner, more maintainable folder structure

---

**Document Version:** 2.0 (Focused on Folder Structure Only)
**Last Updated:** 2025-11-19
**Maintained By:** Development Team
