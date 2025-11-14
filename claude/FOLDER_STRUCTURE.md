# FOLDER_STRUCTURE.md

**BDC IMS Directory Layout and File Organization**

## Root Directory Structure

```
ims ftp/
├── .claude/                # Claude Code configuration and agents
├── .vscode/                # VSCode configuration (SFTP, settings)
├── api/                    # API endpoints and handlers
├── includes/               # Core PHP classes and utilities
├── All-JSON/               # Component specification files
├── database/               # Database schema and migrations
├── documentation/          # Project documentation and guides
├── md files/               # Legacy documentation files
├── tests/                  # Unit and integration tests
├── CLAUDE.md               # Main Claude Code instructions
├── COMPONENT_DEPENDENCY_MATRIX.md  # Component dependency mapping
├── CLEANUP_REPORT.md       # Code cleanup documentation
├── CODEBASE_CLEANUP_ANALYSIS.md    # Cleanup analysis
├── COMPLETION_STATUS.md    # Project completion status
├── shubhams ims_dev main.sql  # Current database schema dump
├── setup_acl.php           # ACL initialization script
└── test_response.json      # Test response reference file
```

---

## Detailed Directory Breakdown

### `/api` - API Endpoints

```
api/
├── api.php                 # ⭐ Main router and entry point
│
├── auth/                   # Authentication endpoints (JWT-based)
│   ├── login_api.php       # JWT login handler
│   ├── logout_api.php      # JWT logout handler
│   ├── register_api.php    # User registration
│   ├── check_session_api.php  # Session validation
│   ├── change_password_api.php # Password change
│   └── forgot_password_api.php # Password recovery
│
├── server/                 # Server configuration endpoints
│   ├── server_api.php      # Main server operations (add/remove/validate)
│   ├── create_server.php   # Step-by-step server creation wizard
│   └── compatibility_api.php  # Compatibility checking operations
│
├── acl/                    # Access control endpoints
│   ├── roles_api.php       # Role management
│   └── permissions_api.php # Permission management
│
├── auth/                   # Authentication endpoints (JWT-based - CURRENT)
│   ├── login_api.php       # JWT login handler
│   ├── register_api.php    # User registration
│   ├── forgot_password_api.php # Password recovery
│   └── (session-based endpoints REMOVED)
│
├── chassis/                # Chassis-specific operations
│   └── chassis_api.php     # Chassis management and querying
│
└── components/             # Generic component operations
    └── components_api.php  # Unified component handler (JWT-protected)
```

**Key Files**:
- `api/api.php` - Main entry point, handles routing, auth, and ACL
- `api/auth/` - JWT-based authentication system (current standard)

---

### `/includes` - Core Classes and Utilities

```
includes/
├── config.php              # Application configuration constants
├── config.env              # Environment variables (legacy)
├── db_config.php           # Database connection setup
├── BaseFunctions.php       # ⭐ Core CRUD and utility functions
├── JWTHelper.php           # ⭐ JWT token generation/validation
├── JWTAuthFunctions.php    # JWT authentication helpers
├── ACL.php                 # ⭐ Access Control List system
│
├── cache/                  # Caching system
│   ├── CacheInterface.php              # ⭐ Cache abstraction interface
│   ├── ComponentSpecCache.php          # Component spec caching
│   └── ConfigurationCache.php          # Server config caching
│
├── models/                 # Business logic classes
│   ├── FlexibleCompatibilityValidator.php  # ⭐ Main compatibility orchestrator
│   ├── ComponentCompatibility.php          # CPU-MB, RAM-MB compatibility
│   ├── StorageConnectionValidator.php      # ⭐ Storage/HBA/backplane validation
│   ├── PCIeSlotTracker.php                 # PCIe slot allocation tracker
│   ├── UnifiedSlotTracker.php              # Unified slot tracking system
│   ├── ComponentDataService.php            # ⭐ JSON spec loader/cache
│   ├── ComponentDataLoader.php             # Component data loading
│   ├── ComponentDataExtractor.php          # Spec data extraction
│   ├── DataExtractionUtilities.php         # Spec extraction utilities
│   ├── DataNormalizationUtils.php          # Data normalization helpers
│   ├── ChassisManager.php                  # Chassis JSON handler
│   ├── ServerBuilder.php                   # ⭐ Server config state manager
│   ├── ServerConfiguration.php             # Config persistence
│   ├── BaseComponentValidator.php          # Base validator class
│   ├── ComponentValidator.php              # Legacy validator
│   ├── CPUCompatibilityValidator.php       # CPU-specific validation
│   ├── ComponentCacheManager.php           # Cache management
│   ├── ComponentSpecificationAdapter.php   # Spec adaptation
│   ├── ComponentQueryBuilder.php           # Query building utilities
│   ├── ValidatorFactory.php                # Validator factory pattern
│   ├── OnboardNICHandler.php               # Onboard NIC management
│   └── CompatibilityEngine.php             # Legacy compatibility wrapper
│
├── resources/              # Resource pool management
│   ├── ResourcePoolInterface.php        # ⭐ Pool abstraction interface
│   ├── ResourceRegistry.php             # Resource pool registry
│   ├── PoolFactory.php                  # Factory for creating pools
│   ├── PCIeSlotPool.php                 # PCIe slot allocation pool
│   ├── PCIeLanePool.php                 # PCIe lane allocation pool
│   ├── RAMSlotPool.php                  # RAM slot allocation pool
│   ├── M2SlotPool.php                   # M.2 slot allocation pool
│   ├── U2SlotPool.php                   # U.2 slot allocation pool
│   └── SATAPortPool.php                 # SATA port allocation pool
│
├── validation/             # Validation context and results
│   ├── ValidationContext.php            # Validation context object
│   └── ValidationResult.php             # Validation result object
│
├── validators/             # Specialized validators (NEW ARCHITECTURE)
│   ├── BaseValidator.php                # Base validator class
│   ├── ValidatorOrchestrator.php        # ⭐ Main validator orchestrator
│   ├── OrchestratorFactory.php          # Factory for orchestrators
│   ├── ValidationContext.php            # Validation context
│   ├── ValidationResult.php             # Validation result
│   ├── APIIntegrationHelper.php         # API integration helper
│   ├── ChassisValidator.php             # Chassis validation
│   ├── ChassisBackplaneValidator.php    # Backplane validation
│   ├── MotherboardValidator.php         # Motherboard validation
│   ├── MotherboardStorageValidator.php  # MB storage interface validation
│   ├── CPUValidator.php                 # CPU validation
│   ├── RAMValidator.php                 # RAM validation
│   ├── StorageValidator.php             # Storage device validation
│   ├── StorageBayValidator.php          # Storage bay validation
│   ├── NVMeSlotValidator.php            # NVMe slot validation
│   ├── SocketCompatibilityValidator.php # Socket compatibility
│   ├── FormFactorValidator.php          # Form factor validation
│   ├── FormFactorLockValidator.php      # Form factor locking
│   ├── PCIeCardValidator.php            # PCIe card validation
│   ├── PCIeAdapterValidator.php         # PCIe adapter validation
│   ├── HBAValidator.php                 # HBA card validation
│   ├── HBARequirementValidator.php      # HBA requirement validation
│   ├── NICValidator.php                 # NIC validation
│   ├── CaddyValidator.php               # Caddy/adapter validation
│   └── SlotAvailabilityValidator.php    # Slot availability checking
│
└── helpers/                # Helper utilities
    └── safe_chassis_support.php         # Safe chassis support helpers
```

**Key Files**:
- `BaseFunctions.php` - All component CRUD operations, UUID validation
- `JWTHelper.php` - Token generation, validation, refresh
- `ACL.php` - Permission checking, role management
- `cache/CacheInterface.php` - New caching abstraction
- `resources/ResourceRegistry.php` - Resource pool management
- `validators/ValidatorOrchestrator.php` - New validator orchestrator
- `models/ComponentDataService.php` - JSON spec loader with caching

---

### `/All-JSON` - Component Specifications

```
All-JSON/
├── cpu-jsons/
│   ├── Cpu base level 1.json             # CPU base level specification
│   ├── Cpu family level 2.json           # CPU family level specification
│   └── Cpu-details-level-3.json          # CPU detailed specifications
│
├── motherboard-jsons/
│   ├── motherboard level 1.json          # Motherboard base level
│   ├── motherboard level 2.json          # Motherboard family level
│   └── motherboard-level-3.json          # Motherboard detailed specifications
│
├── Ram-jsons/
│   ├── info_ram.json                     # RAM information
│   └── ram_detail.json                   # RAM detailed specifications
│
├── storage-jsons/
│   ├── storage.json                      # Storage base specification
│   ├── storagedetail.json                # Storage detailed info
│   └── storage-level-3.json              # Storage level 3 specifications
│
├── nic-jsons/
│   └── nic-level-3.json                  # NIC detailed specifications
│
├── caddy-jsons/
│   └── caddy.json                        # Caddy/adapter specifications
│   └── caddy_details.json                # Caddy detailed specifications
│
├── chassis-jsons/
│   └── chassis-level-3.json              # Chassis detailed specifications
│
├── pci-jsons/
│   ├── pci-level-1.json                  # PCIe base level
│   ├── pci-level-2.json                  # PCIe family level
│   └── pci-level-3.json                  # PCIe detailed specifications
│
└── hbacard-jsons/
    └── hbacard-level-3.json              # HBA card detailed specifications
```

**Purpose**: Contains JSON specification files for all component types. UUIDs in these files are the **only** valid UUIDs for inventory insertion.

**Critical**: Component UUIDs must exist in corresponding JSON file before being added to inventory.

---

### `/database` - Database Files

```
database/
└── migrations/              # Database migration scripts
    ├── README.md           # Migration documentation
    ├── INDEX.md            # Migration index
    ├── MIGRATION_SUMMARY.md # Summary of all migrations
    ├── QUICK_REFERENCE.md  # Quick reference for migrations
    └── 2025_11_09_000001_optimization_phases_1_to_4.sql  # Phase optimization
```

**Main Schema**: `shubhams ims_dev main.sql` (in root)

---

### `/documentation` - Project Documentation

```
documentation/
├── DEPLOYMENT_GUIDE.md      # Deployment procedures
├── FILES_MANIFEST.md        # Complete file manifest
├── PROJECT_SUMMARY.md       # Project overview
├── VALIDATOR_SYSTEM_README.md  # Validator system documentation
├── PHASE3_VALIDATORS_SUMMARY.md # Phase 3 validator updates
└── componentcompatibility-optimization/  # Component compatibility docs
    └── README.md
```

**Purpose**: Comprehensive project documentation, guides, and analysis.

---

### `/md files` - Legacy Documentation

```
md files/
├── server Api.md
├── server_api_guide.md
└── md/
    ├── optimise.md
    ├── storage-analysis.md
    ├── usless.md
    └── usless2.md
```

**Purpose**: Legacy working documentation and development notes.

---

### `/tests` - Unit and Integration Tests

```
tests/
└── unit/
    └── cache/
        └── CacheInterfaceTest.php  # Tests for cache interface
```

**Purpose**: Automated tests for validation and caching systems.

---

### `/.claude` - Claude Code Configuration

```
.claude/
├── agents/                  # Custom agent definitions
│   ├── api-tester.md
│   ├── code-reviewer.md
│   ├── database-optimizer.md
│   ├── frontend-developer.md
│   ├── php-backend-specialist.md
│   ├── senior-code-reviewer.md
│   └── ui-developer.md
├── settings.local.json      # Local Claude Code settings
└── (other config files)
```

**Purpose**: Claude Code integration configuration and custom agents.

---

### `/.vscode` - VSCode Configuration

```
.vscode/
└── sftp.json                # SFTP remote connection settings
```

**Purpose**: VSCode extension and workspace configuration.

---

## File Naming Conventions

### PHP Files

**API Endpoints**:
- Pattern: `{module}_api.php`
- Examples: `server_api.php`, `dashboard_api.php`, `roles_api.php`

**Models/Classes**:
- Pattern: `PascalCase.php`
- Examples: `ServerBuilder.php`, `ComponentDataService.php`, `FlexibleCompatibilityValidator.php`

**Functions/Utilities**:
- Pattern: `snake_case.php` or `camelCase.php`
- Examples: `db_config.php`, `safe_chassis_support.php`

### JSON Files

**Component Specifications**:
- Pattern: `{component}-level-3.json` or `{component}_detail.json`
- Examples: `Cpu-details-level-3.json`, `ram_detail.json`, `hbacard-level-3.json`

### Database Tables

**Inventory Tables**:
- Pattern: `{component}inventory`
- Examples: `cpuinventory`, `raminventory`, `storageinventory`

**System Tables**:
- Examples: `users`, `auth_tokens`, `server_configurations`, `server_components`

**ACL Tables**:
- Pattern: `acl_{entity}` or `{entity}_{junction}`
- Examples: `acl_roles`, `acl_permissions`, `user_roles`, `role_permissions`

---

## Key File Relationships

### Request Flow

```
1. HTTP Request
   ↓
2. api/api.php (main router)
   ↓
3. includes/JWTHelper.php (authentication)
   ↓
4. includes/ACL.php (permission check)
   ↓
5. api/{module}/{module}_api.php (module handler)
   ↓
6. includes/BaseFunctions.php (CRUD operations)
   ├─→ includes/models/ComponentDataService.php (JSON specs)
   └─→ includes/db_config.php (database)
   ↓
7. JSON Response
```

### Component Addition Flow

```
1. api/api.php receives {component}-add
   ↓
2. Routes to handleComponentOperations()
   ↓
3. Calls BaseFunctions::addComponent()
   ↓
4. Validates UUID via ComponentDataService::validateComponentUuid()
   ├─→ Reads All-JSON/{component}-jsons/*.json
   └─→ Searches for matching UUID
   ↓
5. If found: INSERT into {component}inventory table
   If not found: Return error, block insertion
```

### Server Configuration Flow

```
1. server-initialize
   ↓
2. api/server/create_server.php or server_api.php
   ↓
3. ServerBuilder::createNewConfiguration()
   ↓
4. server-add-component (each component)
   ↓
5. FlexibleCompatibilityValidator::validate()
   ├─→ ComponentCompatibility (CPU-MB, RAM-MB)
   ├─→ StorageConnectionValidator (Storage-HBA-Backplane)
   ├─→ PCIeSlotTracker (PCIe allocation)
   └─→ ComponentDataService (load JSON specs)
   ↓
6. server-finalize-config
   ↓
7. ServerConfiguration::saveConfiguration()
```

---

## File Size Guidelines

### Small Files (< 500 lines)
- Configuration files: `config.php`, `db_config.php`
- Single-purpose APIs: `dashboard_api.php`, `search_api.php`
- Simple models: `OnboardNICHandler.php`

### Medium Files (500-1500 lines)
- Main router: `api.php` (~900 lines)
- Core utilities: `BaseFunctions.php`, `ACL.php`
- Complex APIs: `server_api.php`, `compatibility_api.php`

### Large Files (1500+ lines)
- Main validators: `FlexibleCompatibilityValidator.php`, `StorageConnectionValidator.php`
- Data services: `ComponentDataService.php` (if extensive caching)

---

## Adding New Files

### New API Endpoint

1. Create file: `api/{module}/{module}_api.php`
2. Add route in `api/api.php` under appropriate `case` statement
3. Define permissions in ACL system
4. Test with curl or Postman

### New Model Class

1. Create file: `includes/models/YourModelName.php`
2. Use PascalCase naming
3. Include in files that need it: `require_once(__DIR__ . '/../includes/models/YourModelName.php');`
4. Document public methods with PHPDoc comments

### New Component Type

1. JSON spec: `All-JSON/{type}-jsons/{type}-level-3.json`
2. Database table: Create `{type}inventory` table
3. Register in: `ComponentDataService::$componentJsonPaths`
4. Map table in: `api.php::getComponentTableName()`
5. Add permissions: `{type}.view`, `{type}.create`, `{type}.edit`, `{type}.delete`

---

## Deprecated/Legacy Files & Systems

### Superseded by Phase 3 Validator System

**Legacy Compatibility Models** (Phase 2):
- `includes/models/FlexibleCompatibilityValidator.php`
- `includes/models/StorageConnectionValidator.php`
- `includes/models/PCIeSlotTracker.php`
- `includes/models/ComponentCompatibility.php`

**Status**: Functional but superseded by new `includes/validators/` system with ValidatorOrchestrator
**Migration Path**: Route validation through [includes/validators/ValidatorOrchestrator.php](../includes/validators/ValidatorOrchestrator.php)

### Removed - Legacy Authentication System

**Legacy Authentication** (`api/login/` - DELETED):
- Old session-based authentication system
- **Status**: ✅ REMOVED (2025-11-13)
- **Removed Files**: login.php, logout.php, signup.php, user.php, dashboard.php, error_log
- **Use Instead**: JWT-based `api/auth/` endpoints

**Legacy Session-Based APIs** (DELETED):
- `api/dashboard/dashboard_api.php` - Superseded by main API
- `api/search/search_api.php` - Superseded by main API
- `api/auth/check_session_api.php` - Session-based session checking
- `api/auth/logout_api.php` - Session-based logout
- `api/auth/change_password_api.php` - Session-based password change
- **Status**: ✅ REMOVED (2025-11-13)
- **Use Instead**: JWT-based endpoints in `api/api.php`

**Legacy Component UI Files** (DELETED):
- `api/components/list.php` - Legacy component listing UI
- `api/components/add_form.php` - Legacy component add form
- `api/components/edit_form.php` - Legacy component edit form
- **Status**: ✅ REMOVED (2025-11-13)
- **Use Instead**: `api/components/components_api.php` with `api/api.php` routing

### Superseded by Phase 3 (Resource Pools)

**Legacy Slot Tracking**:
- `includes/models/PCIeSlotTracker.php`
- `includes/models/UnifiedSlotTracker.php`

**Status**: Functional but superseded by resource pool system
**Use Instead**: [includes/resources/ResourceRegistry.php](../includes/resources/ResourceRegistry.php)
- PCIe: [includes/resources/PCIeSlotPool.php](../includes/resources/PCIeSlotPool.php)
- RAM: [includes/resources/RAMSlotPool.php](../includes/resources/RAMSlotPool.php)
- Storage: [includes/resources/M2SlotPool.php](../includes/resources/M2SlotPool.php), [includes/resources/U2SlotPool.php](../includes/resources/U2SlotPool.php), [includes/resources/SATAPortPool.php](../includes/resources/SATAPortPool.php)

---

## File Permissions (Unix/Linux)

```bash
# Directories
755  (rwxr-xr-x)  # api/, includes/, All-JSON/

# PHP Files
644  (rw-r--r--)  # All PHP files

# JSON Files
644  (rw-r--r--)  # All JSON specification files

# Configuration
600  (rw-------)  # .env (sensitive credentials)

# Logs (if created)
666  (rw-rw-rw-)  # Log files (ensure PHP can write)
```

---

## Quick File Finder

**Need to modify...**

### Core System
- **API routing** → [api/api.php](../api/api.php)
- **Component CRUD** → [includes/BaseFunctions.php](../includes/BaseFunctions.php)
- **JWT auth** → [includes/JWTHelper.php](../includes/JWTHelper.php)
- **Permissions/ACL** → [includes/ACL.php](../includes/ACL.php)
- **Database config** → [includes/db_config.php](../includes/db_config.php)

### Validation & Compatibility (NEW ARCHITECTURE)
- **Main validator orchestrator** → [includes/validators/ValidatorOrchestrator.php](../includes/validators/ValidatorOrchestrator.php)
- **Component-specific validators** → [includes/validators/](../includes/validators/)
- **Resource pool management** → [includes/resources/ResourceRegistry.php](../includes/resources/ResourceRegistry.php)
- **Validation context/results** → [includes/validation/](../includes/validation/)

### Legacy Compatibility (Phase 2)
- **Main compatibility orchestrator** → [includes/models/FlexibleCompatibilityValidator.php](../includes/models/FlexibleCompatibilityValidator.php)
- **CPU-MB compatibility** → [includes/models/ComponentCompatibility.php](../includes/models/ComponentCompatibility.php)
- **Storage validation** → [includes/models/StorageConnectionValidator.php](../includes/models/StorageConnectionValidator.php)
- **PCIe slots** → [includes/models/PCIeSlotTracker.php](../includes/models/PCIeSlotTracker.php)
- **Unified slot tracking** → [includes/models/UnifiedSlotTracker.php](../includes/models/UnifiedSlotTracker.php)

### Data Management
- **JSON loading** → [includes/models/ComponentDataService.php](../includes/models/ComponentDataService.php)
- **Caching system** → [includes/cache/](../includes/cache/)
- **Component specs** → [All-JSON/{type}-jsons/*.json](../All-JSON/)

### Server Configuration
- **Server state** → [includes/models/ServerBuilder.php](../includes/models/ServerBuilder.php)
- **Server persistence** → [includes/models/ServerConfiguration.php](../includes/models/ServerConfiguration.php)
- **NIC handling** → [includes/models/OnboardNICHandler.php](../includes/models/OnboardNICHandler.php)

### Database & Schema
- **Database schema** → [shubhams ims_dev main.sql](../shubhams%20ims_dev%20main.sql)
- **Migrations** → [database/migrations/](../database/migrations/)
