# FOLDER_STRUCTURE.md

**BDC IMS Directory Layout and File Organization (Updated 2025-11-19)**

## Root Directory Structure

```
ims ftp/
├── .claude/                # Claude Code configuration and agents
├── .vscode/                # VSCode configuration (SFTP, settings)
├── .git/                   # Git version control
├── api/                    # API entry point and handlers
├── core/                   # Core application code (config, auth, models, helpers)
├── resources/              # Static resources and specifications
├── database/               # Database schema and migrations
├── docs/                   # Project documentation
├── tests/                  # Unit and integration tests
├── logs/                   # Application logs
├── md files/               # Legacy documentation files
├── .env                    # Environment configuration
├── .ftpquota               # FTP quota file
├── CLAUDE.md               # Main Claude Code instructions
├── COMPONENT_DEPENDENCY_MATRIX.md  # Component dependency mapping
├── GEMINI.md               # Gemini AI documentation
└── test_response.json      # Test response reference file
```

---

## Detailed Directory Breakdown

### `/api` - API Entry Point and Handlers

```
api/
├── api.php                 # ⭐ Main router and entry point (48KB)
│
└── handlers/               # API endpoint handlers organized by domain
    ├── auth/               # Authentication endpoints (JWT-based)
    │   ├── login_api.php       # JWT login handler
    │   ├── register_api.php    # User registration
    │   └── forgot_password_api.php # Password recovery
    │
    ├── acl/                # Access control endpoints
    │   ├── roles_api.php       # Role management
    │   └── permissions_api.php # Permission management
    │
    ├── components/         # Generic component operations
    │   └── components_api.php  # Unified component handler (JWT-protected)
    │
    ├── server/             # Server configuration endpoints
    │   ├── server_api.php      # Main server operations (253KB - largest API file)
    │   ├── create_server.php   # Step-by-step server creation wizard
    │   └── compatibility_api.php  # Compatibility checking operations
    │
    ├── chassis/            # Chassis-specific operations
    │   └── chassis_api.php     # Chassis management and querying
    │
    └── tickets/            # ⭐ Ticketing system endpoints
        ├── ticket-create.php   # Create new support tickets
        ├── ticket-list.php     # List all tickets
        ├── ticket-get.php      # Get ticket details
        ├── ticket-update.php   # Update ticket status/priority
        └── ticket-delete.php   # Delete tickets
```

**Key Files**:
- `api/api.php` - Main entry point, handles routing, auth, and ACL
- `api/handlers/auth/` - JWT-based authentication system
- `api/handlers/tickets/` - Ticketing system for support/issue tracking

---

### `/core` - Core Application Code

```
core/
├── config/                 # Application configuration
│   └── app.php             # ⭐ Main configuration, DB setup, environment loading
│
├── auth/                   # Authentication and authorization
│   ├── JWTHelper.php       # ⭐ JWT token generation/validation
│   ├── JWTAuthFunctions.php # JWT authentication helpers
│   └── ACL.php             # ⭐ Access Control List system (31KB)
│
├── helpers/                # Helper functions and utilities
│   └── BaseFunctions.php   # ⭐ Core CRUD and utility functions (34KB)
│
├── models/                 # Business logic classes organized by domain
│   ├── server/             # Server-related models
│   │   ├── ServerBuilder.php        # ⭐ Server config state manager (227KB)
│   │   └── ServerConfiguration.php  # Server config persistence
│   │
│   ├── compatibility/      # Compatibility validation models
│   │   ├── ComponentCompatibility.php      # ⭐ CPU-MB, RAM-MB validation (229KB - LARGEST)
│   │   ├── StorageConnectionValidator.php  # ⭐ Storage/HBA/backplane validation (74KB)
│   │   ├── UnifiedSlotTracker.php          # Unified slot tracking (46KB)
│   │   ├── NICPortTracker.php              # NIC port allocation & tracking
│   │   ├── SFPPortTracker.php              # SFP transceiver port tracking
│   │   ├── SFPCompatibilityResolver.php    # SFP compatibility validation
│   │   └── OnboardNICHandler.php           # Onboard NIC management
│   │
│   ├── components/         # Component data models
│   │   ├── ComponentDataService.php          # ⭐ JSON spec loader/cache (43KB)
│   │   ├── ComponentDataLoader.php           # Component data loading
│   │   ├── ComponentDataExtractor.php        # Spec data extraction
│   │   ├── ComponentValidator.php            # Component validation logic (45KB)
│   │   ├── ComponentQueryBuilder.php         # Query building utilities
│   │   ├── ComponentSpecificationAdapter.php # Spec adaptation
│   │   └── ComponentCacheManager.php         # Cache management
│   │
│   ├── chassis/            # Chassis models
│   │   └── ChassisManager.php  # Chassis JSON handler
│   │
│   ├── shared/             # Shared utilities
│   │   ├── DataExtractionUtilities.php  # Spec extraction utilities
│   │   └── DataNormalizationUtils.php   # Data normalization helpers
│   │
│   ├── tickets/            # ⭐ Ticketing system models (NEW)
│   │   ├── TicketManager.php     # Ticket management
│   │   └── TicketValidator.php   # Ticket validation logic
│   │
│   ├── services/           # Business logic services (empty, for future)
│   └── cache/              # Cache implementations (empty, for future)
│
└── (Other core modules can be added here)
```

**Key Files**:
- `core/config/app.php` - Application configuration & database connection
- `core/auth/JWTHelper.php` - JWT token generation, validation, refresh
- `core/auth/ACL.php` - Permission checking, role management
- `core/helpers/BaseFunctions.php` - All component CRUD operations, UUID validation
- `core/models/components/ComponentDataService.php` - JSON spec loader with caching
- `core/models/compatibility/ComponentCompatibility.php` - Component pair validation (largest file)
- `core/models/server/ServerBuilder.php` - Server configuration state management
- `core/models/compatibility/StorageConnectionValidator.php` - Storage/HBA/backplane validation
- `core/models/tickets/TicketManager.php` - Ticketing system manager

---

### `/resources` - Static Resources and Specifications

```
resources/
└── specifications/         # Component specification files (JSON)
    ├── cpu/                # CPU processor specifications
    │   ├── Cpu base level 1.json
    │   ├── Cpu family level 2.json
    │   └── Cpu-details-level-3.json
    │
    ├── motherboard/        # Server motherboard specifications
    │   ├── motherboard level 1.json
    │   ├── motherboard level 2.json
    │   └── motherboard-level-3.json
    │
    ├── ram/                # Memory module specifications
    │   ├── info_ram.json
    │   └── ram_detail.json
    │
    ├── storage/            # Hard drives & SSD specifications
    │   ├── storage.json
    │   ├── storagedetail.json
    │   └── storage-level-3.json
    │
    ├── nic/                # Network interface card specifications
    │   └── nic-level-3.json
    │
    ├── caddy/              # HDD/SSD mounting bracket specifications
    │   ├── caddy.json
    │   └── caddy_details.json
    │
    ├── chassis/            # Server chassis specifications
    │   └── chasis-level-3.json  # NOTE: typo in filename
    │
    ├── pciecard/           # PCIe expansion card specifications
    │   ├── pci-level-1.json
    │   ├── pci-level-2.json
    │   └── pci-level-3.json
    │
    ├── hbacard/            # Host bus adapter (SAS/RAID) specifications
    │   └── hbacard-level-3.json
    │
    └── sfp/                # SFP transceiver module specifications
        └── sfp-level-3.json
```

**Purpose**: Contains JSON specification files for all 10 component types. UUIDs in these files are the **only** valid UUIDs for inventory insertion.

**Critical Rule**: Component UUIDs must exist in corresponding JSON file before being added to inventory. This enforces strict control over what components are allowed in the system.

---

### `/database` - Database Files

```
database/
├── schema.sql              # ⭐ Main database schema dump
├── migrations/             # Database migration scripts (empty, for future)
└── seeds/                  # Database seed files (empty, for future)
```

**Main Schema**: [database/schema.sql](../../database/schema.sql) contains the complete database structure.

**Note**: The migrations and seeds directories exist but are currently empty. All schema is in the main SQL dump file.

---

### `/docs` - Project Documentation

```
docs/
├── api/                    # API documentation
│   └── API_REFERENCE.md        # ⭐ Complete API endpoint catalog (36KB)
│
├── architecture/           # Architecture and design documentation
│   ├── ARCHITECTURE.md         # System design and request flow (28KB)
│   ├── DATABASE_SCHEMA.md      # Complete database structure (18KB)
│   └── FOLDER_STRUCTURE.md     # ⭐ This file - directory layout
│
├── development/            # Development guidelines
│   └── DEVELOPMENT_GUIDELINES.md  # Coding standards and best practices (22KB)
│
└── planning/               # Planning and implementation documents
    ├── modernization-plan/
    │   └── README.md               # Folder structure modernization plan
    ├── deprecated-files-audit/
    │   └── README.md               # Deprecated files audit
    └── ticketing-system-implementation/  # ⭐ Ticketing system docs
        ├── COMPLETE_GUIDE.md       # Complete implementation guide
        ├── README.md               # Ticketing system overview
        └── UPDATED_PLAN_1.md       # Implementation plan
```

**Purpose**: Centralized documentation for architecture, API reference, development guidelines, and planning documents.

---

### `/tests` - Unit and Integration Tests

```
tests/
├── bootstrap.php           # ⭐ Test environment setup
│
├── unit/                   # Unit tests
│   ├── models/
│   │   ├── compatibility/  # Tests for compatibility validators
│   │   ├── components/     # Tests for component models
│   │   └── server/         # Tests for server models
│   └── cache/
│       └── CacheInterfaceTest.php  # Tests for cache interface
│
├── integration/            # Integration tests
│   ├── api/                # API endpoint tests
│   └── database/           # Database integration tests
│
├── fixtures/               # Test fixtures and sample data
└── helpers/                # Test helper functions
```

**Purpose**: Automated tests for validation and caching systems. Minimal test coverage currently. Structure ready for expansion.

---

### `/logs` - Application Logs

```
logs/
└── .gitkeep                # Keeps directory in git
```

**Purpose**: Application log files. Directory is empty by default and logs are created at runtime.

---

### `/.claude` - Claude Code Configuration

```
.claude/
├── agents/                 # Custom agent definitions
│   ├── api-tester.md
│   ├── code-reviewer.md
│   ├── database-optimizer.md
│   ├── frontend-developer.md
│   ├── php-backend-specialist.md
│   ├── senior-code-reviewer.md
│   └── ui-developer.md
└── settings.local.json     # Local Claude Code settings
```

**Purpose**: Claude Code integration configuration and custom agents.

---

### `/.vscode` - VSCode Configuration

```
.vscode/
└── sftp.json               # SFTP remote connection settings
```

**Purpose**: VSCode extension and workspace configuration.

---

## File Naming Conventions

### PHP Files

**API Endpoints**:
- Pattern: `{module}_api.php`
- Examples: `server_api.php`, `components_api.php`, `roles_api.php`

**Models/Classes**:
- Pattern: `PascalCase.php`
- Examples: `ServerBuilder.php`, `ComponentDataService.php`, `ComponentCompatibility.php`

**Functions/Utilities**:
- Pattern: `PascalCase.php` or `snake_case.php`
- Examples: `BaseFunctions.php`, `app.php`

### JSON Files

**Component Specifications**:
- Pattern: `{component}-level-3.json` or `{component}_detail.json`
- Examples: `Cpu-details-level-3.json`, `ram_detail.json`, `hbacard-level-3.json`

### Database Tables

**Inventory Tables** (9 component types):
- Pattern: `{component}inventory`
- Examples: `cpuinventory`, `raminventory`, `storageinventory`, `motherboardinventory`, `nicinventory`, `caddyinventory`, `chassisinventory`, `pciecardinventory`, `hbacardinventory`

**System Tables**:
- Examples: `users`, `auth_tokens`, `server_configurations`, `server_components`, `tickets`

**ACL Tables**:
- Pattern: `acl_{entity}` or `{entity}_{junction}`
- Examples: `acl_roles`, `acl_permissions`, `user_roles`, `role_permissions`

**Note**: SFP transceivers (`sfp/`) exist in specification files but do not have a dedicated inventory table yet.

---

## Key File Relationships

### Request Flow

```
1. HTTP Request
   ↓
2. api/api.php (main router)
   ↓
3. core/auth/JWTHelper.php (authentication)
   ↓
4. core/auth/ACL.php (permission check)
   ↓
5. api/handlers/{module}/{module}_api.php (module handler)
   ↓
6. core/helpers/BaseFunctions.php (CRUD operations)
   ├─→ core/models/components/ComponentDataService.php (JSON specs)
   └─→ core/config/app.php (database connection)
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
   ├─→ Reads resources/specifications/{component}/*.json
   └─→ Searches for matching UUID
   ↓
5. If found: INSERT into {component}inventory table
   If not found: Return error, block insertion
```

### Server Configuration Flow

```
1. server-initialize
   ↓
2. api/handlers/server/create_server.php or server_api.php
   ↓
3. ServerBuilder::createNewConfiguration()
   ↓
4. server-add-component (each component)
   ↓
5. ServerBuilder validates via:
   ├─→ ComponentCompatibility (CPU-MB, RAM-MB)
   ├─→ StorageConnectionValidator (Storage-HBA-Backplane)
   ├─→ UnifiedSlotTracker (PCIe, RAM, Storage slot allocation)
   ├─→ NICPortTracker & SFPPortTracker (Network port allocation)
   └─→ ComponentDataService (load JSON specs)
   ↓
6. server-validate-config (full validation check)
   ↓
7. server-finalize-config
   ↓
8. ServerConfiguration::saveConfiguration()
```

---

## File Size Guidelines

### Small Files (< 20KB)
- Configuration files: `app.php` (7KB), `JWTHelper.php` (7KB)
- Trackers: `SFPPortTracker.php` (8KB), `NICPortTracker.php` (11KB), `ChassisManager.php` (13KB)
- Validators: `TicketValidator.php` (16KB), `SFPCompatibilityResolver.php` (20KB)
- Simple models: `OnboardNICHandler.php` (22KB), `ServerConfiguration.php` (21KB)

### Medium Files (20-50KB)
- Main router: `api.php` (49KB)
- Core utilities: `BaseFunctions.php` (34KB), `ACL.php` (31KB)
- Data services: `ComponentDataService.php` (43KB)
- Validators: `ComponentValidator.php` (45KB), `UnifiedSlotTracker.php` (46KB)

### Large Files (50KB+)
- **ServerBuilder.php** (227KB) - Server configuration state management
- **ComponentCompatibility.php** (229KB) - LARGEST FILE - Component pair validation
- **server_api.php** (253KB) - LARGEST API FILE - Main server operations
- **StorageConnectionValidator.php** (74KB) - Storage/HBA/backplane validation

---

## Adding New Files

### New API Endpoint

1. Create file: `api/handlers/{module}/{module}_api.php`
2. Add route in `api/api.php` under appropriate `case` statement
3. Define permissions in ACL system
4. Test with curl or Postman

### New Model Class

1. Create file: `core/models/{domain}/YourModelName.php`
2. Use PascalCase naming
3. Include in files that need it: `require_once(__DIR__ . '/../../core/models/{domain}/YourModelName.php');`
4. Document public methods with PHPDoc comments

### New Component Type

1. JSON spec: `resources/specifications/{type}/{type}-level-3.json`
2. Database table: Create `{type}inventory` table
3. Register in: `ComponentDataService::$componentJsonPaths`
4. Map table in: `api.php::getComponentTableName()`
5. Add permissions: `{type}.view`, `{type}.create`, `{type}.edit`, `{type}.delete`

---

## Quick File Finder

**Need to modify...**

### Core System
- **API routing** → [api/api.php](../../api/api.php) (49KB - main entry point)
- **Component CRUD** → [core/helpers/BaseFunctions.php](../../core/helpers/BaseFunctions.php) (34KB)
- **JWT auth** → [core/auth/JWTHelper.php](../../core/auth/JWTHelper.php)
- **Permissions/ACL** → [core/auth/ACL.php](../../core/auth/ACL.php) (31KB)
- **Database config** → [core/config/app.php](../../core/config/app.php)

### Validation & Compatibility (CURRENT ACTIVE SYSTEM)
- **Component pair validation** → [core/models/compatibility/ComponentCompatibility.php](../../core/models/compatibility/ComponentCompatibility.php) (229KB - LARGEST)
- **Storage/HBA validation** → [core/models/compatibility/StorageConnectionValidator.php](../../core/models/compatibility/StorageConnectionValidator.php) (74KB)
- **Component validation** → [core/models/components/ComponentValidator.php](../../core/models/components/ComponentValidator.php) (45KB)
- **Slot tracking** → [core/models/compatibility/UnifiedSlotTracker.php](../../core/models/compatibility/UnifiedSlotTracker.php) (46KB)
- **JSON spec loading** → [core/models/components/ComponentDataService.php](../../core/models/components/ComponentDataService.php) (43KB)

### Server Configuration
- **Server state management** → [core/models/server/ServerBuilder.php](../../core/models/server/ServerBuilder.php) (227KB)
- **Server API operations** → [api/handlers/server/server_api.php](../../api/handlers/server/server_api.php) (253KB - LARGEST API)
- **Server persistence** → [core/models/server/ServerConfiguration.php](../../core/models/server/ServerConfiguration.php)
- **Compatibility API** → [api/handlers/server/compatibility_api.php](../../api/handlers/server/compatibility_api.php)

### Network & SFP Management
- **NIC port tracking** → [core/models/compatibility/NICPortTracker.php](../../core/models/compatibility/NICPortTracker.php)
- **SFP port tracking** → [core/models/compatibility/SFPPortTracker.php](../../core/models/compatibility/SFPPortTracker.php)
- **SFP compatibility** → [core/models/compatibility/SFPCompatibilityResolver.php](../../core/models/compatibility/SFPCompatibilityResolver.php)
- **Onboard NIC handling** → [core/models/compatibility/OnboardNICHandler.php](../../core/models/compatibility/OnboardNICHandler.php)

### Ticketing System
- **Ticket management** → [core/models/tickets/TicketManager.php](../../core/models/tickets/TicketManager.php)
- **Ticket validation** → [core/models/tickets/TicketValidator.php](../../core/models/tickets/TicketValidator.php)
- **Ticket API endpoints** → [api/handlers/tickets/](../../api/handlers/tickets/)

### Data Management
- **Component specifications** → [resources/specifications/{type}/](../../resources/specifications/)
- **Data extraction** → [core/models/shared/DataExtractionUtilities.php](../../core/models/shared/DataExtractionUtilities.php)
- **Data normalization** → [core/models/shared/DataNormalizationUtils.php](../../core/models/shared/DataNormalizationUtils.php)
- **Data loading** → [core/models/components/ComponentDataLoader.php](../../core/models/components/ComponentDataLoader.php)
- **Chassis management** → [core/models/chassis/ChassisManager.php](../../core/models/chassis/ChassisManager.php)
- **Cache management** → [core/models/components/ComponentCacheManager.php](../../core/models/components/ComponentCacheManager.php)

### Database & Schema
- **Database schema** → [database/schema.sql](../../database/schema.sql)
- **Migrations directory** → [database/migrations/](../../database/migrations/) (currently empty)

### Documentation
- **API reference** → [docs/api/API_REFERENCE.md](../api/API_REFERENCE.md) (36KB)
- **Architecture guide** → [docs/architecture/ARCHITECTURE.md](./ARCHITECTURE.md) (28KB)
- **Database schema docs** → [docs/architecture/DATABASE_SCHEMA.md](./DATABASE_SCHEMA.md) (18KB)
- **Development guidelines** → [docs/development/DEVELOPMENT_GUIDELINES.md](../development/DEVELOPMENT_GUIDELINES.md) (22KB)
- **This file** → [docs/architecture/FOLDER_STRUCTURE.md](./FOLDER_STRUCTURE.md)

---

## Migration Notes (2025-11-19)

**Folder Structure Reorganization**: The project underwent a major folder structure reorganization to improve code organization and maintainability:

### Changes Made:

1. **Core Code Consolidation** (`includes/` → `core/`)
   - `includes/config.php` → `core/config/app.php`
   - `includes/JWTHelper.php`, `JWTAuthFunctions.php`, `ACL.php` → `core/auth/`
   - `includes/BaseFunctions.php` → `core/helpers/`
   - `includes/models/` → `core/models/` (organized by domain)

2. **API Handlers Reorganization** (`api/{module}/` → `api/handlers/{module}/`)
   - All endpoint files moved to `api/handlers/` subdirectories
   - `api/api.php` remains as main entry point
   - Old `api/auth/`, `api/server/`, etc. directories removed

3. **Resource Specifications** (`All-JSON/` → `resources/specifications/`)
   - All component JSON specs moved to organized subdirectories
   - Maintains same structure but cleaner naming

4. **Documentation Consolidation** (`claude/` + `documentation/` → `docs/`)
   - API docs → `docs/api/`
   - Architecture docs → `docs/architecture/`
   - Development guidelines → `docs/development/`
   - Planning docs → `docs/planning/`

5. **Database Files** (`shubhams_ims_dev.sql` → `database/schema.sql`)
   - Main schema file moved to dedicated database directory
   - Ready for future migration and seed files

6. **Test Structure Expansion**
   - `tests/bootstrap.php` added
   - Organized subdirectories for unit/integration tests
   - Fixture and helper directories created

### Path Updates:

All `require_once` statements have been updated to reflect new paths:
- `includes/config.php` → `core/config/app.php`
- `includes/models/{Model}.php` → `core/models/{domain}/{Model}.php`
- `All-JSON/{type}-jsons/` → `resources/specifications/{type}/`

---

## File Permissions (Unix/Linux)

```bash
# Directories
755  (rwxr-xr-x)  # api/, core/, resources/

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
