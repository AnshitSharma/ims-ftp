# FOLDER_STRUCTURE.md

**BDC IMS Directory Layout and File Organization**

## Root Directory Structure

```
ims ftp/
├── api/                    # API endpoints and handlers
├── includes/               # Core PHP classes and utilities
├── All-JSON/               # Component specification files
├── database/               # Database schema and migrations
├── md files/               # Documentation and reference files
├── .env                    # Environment configuration
├── .ftpquota               # FTP quota settings
├── CLAUDE.md               # Main Claude Code instructions
├── API_REFERENCE.md        # Complete API documentation
├── FOLDER_STRUCTURE.md     # This file
├── DEVELOPMENT_GUIDELINES.md  # Coding standards
├── DATABASE_SCHEMA.md      # Database documentation
└── shubhams_bdc_ims main.sql  # Database schema dump
```

---

## Detailed Directory Breakdown

### `/api` - API Endpoints

```
api/
├── api.php                 # ⭐ Main router and entry point
│
├── auth/                   # Authentication endpoints
│   ├── login_api.php
│   ├── logout_api.php
│   ├── register_api.php
│   ├── check_session_api.php
│   ├── change_password_api.php
│   └── forgot_password_api.php
│
├── server/                 # Server configuration endpoints
│   ├── server_api.php      # Main server operations (add/remove/validate)
│   ├── create_server.php   # Step-by-step server creation wizard
│   └── compatibility_api.php  # Compatibility checking operations
│
├── acl/                    # Access control endpoints
│   ├── roles_api.php
│   └── permissions_api.php
│
├── dashboard/              # Dashboard statistics
│   └── dashboard_api.php
│
├── search/                 # Global search
│   └── search_api.php
│
├── chassis/                # Chassis-specific operations
│   └── chassis_api.php
│
├── components/             # Generic component operations
│   ├── components_api.php
│   ├── add_form.php
│   ├── edit_form.php
│   └── list.php
│
└── functions/              # Legacy component-specific functions
    ├── cpu/
    │   ├── add_cpu.php
    │   ├── list_cpu.php
    │   └── remove_cpu.php
    ├── ram/
    │   ├── add_ram.php
    │   ├── list_ram.php
    │   └── remove_ram.php
    ├── storage/
    │   ├── add_storage.php
    │   ├── list_storage.php
    │   └── remove_storage.php
    ├── motherboard/
    │   ├── add_motherboard.php
    │   ├── list_motherboard.php
    │   └── remove_motherboard.php
    ├── nic/
    │   ├── add_nic.php
    │   ├── list_nic.php
    │   └── remove_nic.php
    └── caddy/
        ├── add_caddy.php
        ├── list_caddy.php
        └── remove_caddy.php
```

**Key Files**:
- `api/api.php` - Main entry point, handles routing, auth, and ACL

---

### `/includes` - Core Classes and Utilities

```
includes/
├── config.php              # Application configuration constants
├── db_config.php           # Database connection setup
├── BaseFunctions.php       # ⭐ Core CRUD and utility functions
├── JWTHelper.php           # ⭐ JWT token generation/validation
├── JWTAuthFunctions.php    # JWT authentication helpers
├── ACL.php                 # ⭐ Access Control List system
│
├── models/                 # Business logic classes
│   ├── FlexibleCompatibilityValidator.php  # ⭐ Main compatibility orchestrator
│   ├── ComponentCompatibility.php          # CPU-MB, RAM-MB compatibility
│   ├── StorageConnectionValidator.php      # ⭐ Storage/HBA/backplane validation
│   ├── PCIeSlotTracker.php                 # PCIe slot allocation tracker
│   ├── ExpansionSlotTracker.php            # Generic slot tracking
│   ├── ComponentDataService.php            # ⭐ JSON spec loader/cache
│   ├── ChassisManager.php                  # Chassis JSON handler
│   ├── DataExtractionUtilities.php         # Spec extraction utilities
│   ├── ServerBuilder.php                   # ⭐ Server config state manager
│   ├── ServerConfiguration.php             # Config persistence
│   ├── CompatibilityEngine.php             # Legacy compatibility wrapper
│   └── OnboardNICHandler.php               # Onboard NIC management
│
└── helpers/                # Helper utilities
    └── safe_chassis_support.php
```

**Key Files**:
- `BaseFunctions.php` - All component CRUD operations, UUID validation
- `JWTHelper.php` - Token generation, validation, refresh
- `ACL.php` - Permission checking, role management
- `models/FlexibleCompatibilityValidator.php` - Compatibility orchestrator
- `models/ComponentDataService.php` - JSON spec loader with caching

---

### `/All-JSON` - Component Specifications

```
All-JSON/
├── cpu-jsons/
│   └── Cpu-details-level-3.json          # CPU specifications
│
├── motherboad-jsons/  [sic - typo in folder name]
│   └── motherboard-level-3.json          # Motherboard specifications
│
├── Ram-jsons/
│   └── ram_detail.json                   # RAM specifications
│
├── storage-jsons/
│   └── storage-level-3.json              # Storage device specifications
│
├── nic-jsons/
│   └── nic-level-3.json                  # NIC specifications
│
├── caddy-jsons/
│   └── caddy_details.json                # Caddy/adapter specifications
│
├── chasis-jsons/  [sic - typo in folder name]
│   └── chasis-level-3.json               # Chassis specifications
│
├── pci-jsons/
│   └── pci-level-3.json                  # PCIe card specifications
│
└── hbacard-jsons/
    └── hbacard-level-3.json              # HBA card specifications
```

**Purpose**: Contains JSON specification files for all component types. UUIDs in these files are the **only** valid UUIDs for inventory insertion.

**Critical**: Component UUIDs must exist in corresponding JSON file before being added to inventory.

---

### `/database` - Database Files

```
database/
└── (Database schema files, migrations, backups)
```

**Main Schema**: `shubhams_bdc_ims main.sql` (in root)

---

### `/md files` - Documentation

```
md files/
├── info.md
├── server Api.md
├── server_api_guide.md
└── md/
    ├── optimise.md
    ├── storage-analysis.md
    ├── usless.md
    └── usless2.md
```

**Purpose**: Working documentation, development notes, and references.

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

## Deprecated/Legacy Files

**Legacy Component Functions** (`api/functions/`):
- Still functional but superseded by unified component handler in `api.php`
- Consider migrating to centralized `handleComponentOperations()` pattern

**Legacy Login Files** (`api/login/`):
- Old authentication system
- Superseded by JWT-based `api/auth/` endpoints

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

- **API routing** → `api/api.php`
- **Component CRUD** → `includes/BaseFunctions.php`
- **JWT auth** → `includes/JWTHelper.php`
- **Permissions** → `includes/ACL.php`
- **CPU-MB compatibility** → `includes/models/ComponentCompatibility.php`
- **Storage validation** → `includes/models/StorageConnectionValidator.php`
- **PCIe slots** → `includes/models/PCIeSlotTracker.php`
- **JSON loading** → `includes/models/ComponentDataService.php`
- **Server state** → `includes/models/ServerBuilder.php`
- **Component specs** → `All-JSON/{type}-jsons/*.json`
- **Database schema** → `shubhams_bdc_ims main.sql`
