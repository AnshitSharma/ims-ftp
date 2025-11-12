# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 🏷️ CURRENT VERSION STATUS

**STABLE VERSION:** 1.0 (2025-09-18)
**STATUS:** Production Ready - Rollback Point Available
**LAST VERIFIED:** Server-add-component API fully functional

See [STABLE_VERSION_1.md](../STABLE_VERSION_1.md) for complete rollback instructions and change log.

## 📝 COMMUNICATION GUIDELINES

**CRITICAL: Token Conservation**
- **Be Concise**: Provide overviews and change summaries in bullet points
- **Changes Format**: `File: Action - Result` (e.g., `api.php: Added validation - Fixed 400 error`)
- **Overview Format**: List only: affected files, key changes, impact
- **No Repetition**: Don't restate what was asked, just answer directly

## 🗄️ DATABASE CHANGE PROTOCOL

**MANDATORY: Database Schema Changes**
- **ALWAYS** ask for user confirmation BEFORE implementing ANY database changes
- **NEVER** modify database schema without explicit user approval
- **PROVIDE** database changes in a separate `.sql` file when confirmed
- **FORMAT** SQL file for single execution (all commands in one file)
- **INCLUDE** rollback statements as comments for safety
- **LOCATION** Save ALL SQL migration files in `database/migrations/` folder ONLY

**Database Change Process:**
1. Identify database change needed
2. Ask user for confirmation with brief explanation
3. Wait for user approval
4. Create `.sql` file in `database/migrations/` with:
   - Header comments (date, purpose, tables affected)
   - Main SQL statements
   - Commented rollback statements
   - Summary section documenting changes
5. Provide file path and execution instructions

**Migration File Naming Convention:**
- Format: `{action}_{description}.sql`
- Examples:
  - `create_hbacardinventory.sql`
  - `add_new_motherboards_with_riser_slots.sql`
  - `alter_serverinventory_add_power_metrics.sql`

**Migration File Structure:**
```sql
-- --------------------------------------------------------
--
-- Migration: [Brief Description]
-- Date: YYYY-MM-DD
-- Description: [Detailed description of changes]
--
-- --------------------------------------------------------

-- Main SQL statements here
-- INSERT IGNORE for idempotent data migrations

-- --------------------------------------------------------
-- Summary of changes
-- --------------------------------------------------------
```

**Best Practices:**
- Use `INSERT IGNORE` for data migrations to prevent duplicates
- Make migrations idempotent (can run multiple times safely)
- Include comprehensive comments
- Test on development database first
- Execute migrations in chronological order

## 📁 PROJECT OVERVIEW

**BDC Inventory Management System (IMS)** - A comprehensive PHP-based REST API for hardware inventory tracking with:
- JWT authentication & role-based access control (ACL)
- Hardware component management (CPU, RAM, Storage, NIC, Motherboard, Chassis, Caddy, PCIe cards, HBA cards)
- Server builder with intelligent compatibility validation
- Hybrid database/JSON architecture for optimal performance

## 🏗️ ARCHITECTURE OVERVIEW

### Backend Structure
```
api/
├── api.php                    # Main API gateway & router
├── auth/                      # Authentication endpoints
├── acl/                       # Access control endpoints
├── components/                # Component CRUD operations
├── server/                    # Server builder & compatibility
├── chassis/                   # Chassis management
├── dashboard/                 # Dashboard data endpoints
├── search/                    # Search functionality
└── functions/                 # Legacy component functions
    ├── cpu/
    ├── ram/
    ├── storage/
    ├── motherboard/
    ├── nic/
    └── caddy/

includes/
├── models/                    # Core business logic (16,150 LOC)
│   ├── ComponentDataService.php          # JSON data management (786 LOC)
│   ├── DataExtractionUtilities.php       # Spec extraction (832 LOC)
│   ├── CompatibilityEngine.php           # Compatibility validation (702 LOC)
│   ├── FlexibleCompatibilityValidator.php # Order-independent validation (2,008 LOC)
│   ├── ComponentCompatibility.php        # Component matching (5,125 LOC)
│   ├── ServerBuilder.php                 # Server management (4,312 LOC)
│   ├── ServerConfiguration.php           # Config operations (540 LOC)
│   ├── ChassisManager.php                # Chassis handling (339 LOC)
│   ├── PCIeSlotTracker.php               # PCIe slot management (565 LOC)
│   └── StorageConnectionValidator.php    # Storage compatibility (941 LOC)
├── QueryModel.php             # Database abstraction layer
├── BaseFunctions.php          # Core utility functions
├── JWTHelper.php              # JWT token management
├── JWTAuthFunctions.php       # JWT authentication
├── ACL.php                    # Permission system
├── config.php                 # Application configuration
└── db_config.php              # Database connection

All-JSON/
├── cpu-jsons/                 # CPU specifications (3 levels)
├── motherboard-jsons/         # Motherboard specs (3 levels)
├── ram-jsons/                 # RAM specifications
├── storage-jsons/             # Storage device specs (3 levels)
├── nic-jsons/                 # Network card specs (3 levels)
├── pci-jsons/                 # PCIe card specs (3 levels)
├── hbacard-jsons/             # HBA card specs (3 levels)
├── caddy-jsons/               # Drive caddy specs
└── chasis-jsons/              # Chassis specs (3 levels)
```

### HYBRID DATA ARCHITECTURE (CRITICAL)

**Database (MySQL)** - Inventory tracking ONLY
- Stores: UUID, SerialNumber, Status, ServerUUID, DateAdded, LastModified, Notes (~10 fields)
- Purpose: Track component availability, assignments, and purchase information
- Goal: Minimize database calls for maximum performance
- Tables:
  - `cpuinventory`, `raminventory`, `storageinventory`, `motherboardinventory`
  - `nicinventory`, `caddyinventory`, `pciecardinventory`, `hbacardinventory`
  - `chasisinventory`
  - `users`, `roles`, `permissions`, `user_roles`, `role_permissions`
  - `server_configurations`, `server_configuration_components`, `server_configuration_history`
  - `component_compatibility`, `compatibility_rules`, `compatibility_log`

**JSON Files (All-JSON/)** - Complete component specifications
- Stores: socket, TDP, cores, threads, memory support, PCIe lanes, compatibility lists (~50+ fields)
- Structure: 3-level hierarchy (Brand → Family → Model with UUIDs)
- Cached: In-memory via `ComponentDataService` singleton pattern
- Cache Limits: Max 1000 component specs, 200 search results (LRU eviction)
- Location: `All-JSON/{component-type}-jsons/{level}.json`

**Data Flow:**
1. Query component → Database returns UUID, serial, status
2. Get specifications → JSON cache via `ComponentDataService`
3. Compatibility checks → JSON via `DataExtractionUtilities`
4. Update inventory → Database (status changes, assignments)

**Smart Matching:**
- Direct UUID match (fastest)
- Serial number pattern matching
- Model name extraction from Notes field
- Fuzzy matching for similar component names
- Returns match with confidence score (0.0-1.0)

### Key Classes & Their Roles

**ComponentDataService.php** (786 LOC)
- **PRIMARY** JSON specification service
- Singleton pattern with 1-hour cache
- Loads and caches all JSON component data
- Methods: `getComponentSpec()`, `getAllSpecs()`, `searchComponents()`, `clearCache()`

**DataExtractionUtilities.php** (832 LOC)
- Extracts detailed specs from JSON files
- Methods for socket, TDP, PCIe lanes, storage interfaces
- Used heavily by compatibility engine

**CompatibilityEngine.php** (702 LOC)
- Hardware compatibility validation
- Motherboard socket compatibility with CPUs
- Power supply wattage calculations
- Memory slot and type validation
- PCIe slot allocation

**FlexibleCompatibilityValidator.php** (2,008 LOC)
- **Order-independent validation** - Add components in ANY order
- CPU before motherboard OR motherboard before CPU
- Validates compatibility when second component added
- Defers validation until pairing components available

**ComponentCompatibility.php** (5,125 LOC)
- Comprehensive component matching logic
- Handles UUID matching, fuzzy search, pattern matching
- Component type validation
- Specification comparison

**ServerBuilder.php** (4,312 LOC)
- Server configuration management
- Component addition/removal
- **Orphaned record cleanup** - Auto-detects and removes orphaned entries
- **Duplicate prevention** - Checks both config table and inventory ServerUUID
- Configuration status tracking (Draft, Validated, Built, Finalized)

**QueryModel.php**
- Database abstraction layer
- PDO-based with prepared statements
- Handles all inventory CRUD operations
- SQL injection protection

**ACL.php**
- Role-based permission system
- Methods: `hasPermission()`, `getUserRoles()`, `assignPermission()`
- Granular permissions (e.g., `cpu.create`, `server.edit`)

**BaseFunctions.php**
- Core utility functions
- JWT integration
- Response formatting
- Error handling

## 🚀 DEVELOPMENT COMMANDS

### Starting Development Server
```bash
# PHP built-in server for API testing
php -S localhost:8000 -t .

# For frontend development
cd ims_frontend
# Initialize package.json first, then install dependencies
npm install
npm run dev
```

### Database Operations
```bash
# Import current database schema
mysql -u username -p database_name < "shubhams_bdc_ims claude.sql"

# Connection details (update in includes/db_config.php)
# Host: localhost:3306
# Database: shubhams_bdc_ims
# User: shubhams_api
```

### Testing API Endpoints

**IMPORTANT: Always use the staging URL for API testing:**
API Base URL: `https://shubham.staging.cloudmate.in/bdc_ims/api/api.php`

```bash
# STEP 1: Authentication - Login to get JWT token
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims/api/api.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "action=auth-login&username=superadmin&password=password"

# Response includes access_token in data.tokens.access_token
# Example: "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."

# STEP 2: Use JWT token for authenticated requests
# Add storage component to server configuration
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims/api/api.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=server-add-component&config_uuid=CONFIG_UUID&component_type=storage&component_uuid=COMPONENT_UUID&quantity=1&slot_position=slot 1&override=false"

# List components (requires JWT token)
curl -X POST "https://shubham.staging.cloudmate.in/bdc_ims/api/api.php" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d "action=cpu-list"

# Working Credentials:
# Username: superadmin
# Password: password
```

**CRITICAL: API Cache Timing**
- After modifying code, wait **30 seconds** before testing to allow server cache to clear
- This applies to ALL code changes affecting API responses
- If you get unexpected errors immediately after code changes, wait and retry

## ⚙️ CONFIGURATION MANAGEMENT

### Environment Setup
- Configuration uses environment variables loaded from `.env`
- Database credentials in [includes/db_config.php](../includes/db_config.php)
- JWT secrets and application settings in [includes/config.php](../includes/config.php)

### Key Environment Variables
```bash
JWT_SECRET=bdc-ims-default-secret-change-in-production-environment
APP_ENV=development  # or 'production'
APP_DEBUG=true
JWT_EXPIRY_HOURS=24
TIMEZONE=UTC
MAIN_SITE_URL=https://localhost
CORS_ALLOWED_ORIGINS=*
API_RATE_LIMIT_ENABLED=true
API_RATE_LIMIT_REQUESTS=1000
```

## 🔐 SECURITY CONSIDERATIONS

### Authentication & Authorization
- JWT tokens for stateless authentication
- Role-based permission system with granular controls
- Input validation and SQL injection prevention via PDO prepared statements
- CORS configuration for cross-origin requests

### Database Security
- Parameterized queries throughout codebase
- Connection credentials in separate config files
- Error logging without exposing sensitive information
- Production mode hides detailed errors from responses

## 📊 API INTEGRATION PATTERNS

### Authentication Flow
1. Login via `action=auth-login` to receive JWT token
2. Include `Authorization: Bearer <token>` header in subsequent requests
3. Tokens expire based on `JWT_EXPIRY_HOURS` configuration (default: 24 hours)

### Permission System
- Actions require specific permissions (e.g., `cpu.create`, `server.edit`)
- Check user permissions before component operations
- ACL methods: `hasPermission()`, `getUserRoles()`, `assignPermission()`

### Component CRUD Pattern
- All component types follow consistent naming: `{type}-{action}`
- Actions: `list`, `create`, `edit`, `delete`, `view`
- UUIDs auto-generated for all components
- Status codes: `0`=Failed/Decommissioned, `1`=Available, `2`=In Use

### API Response Format
```json
{
    "success": true|false,
    "authenticated": true|false,
    "message": "Human-readable message",
    "timestamp": "2025-01-XX XX:XX:XX",
    "code": 200|400|401|403|404|500,
    "data": { /* Response data */ }
}
```

### Common HTTP Status Codes
- `200`: Success
- `201`: Resource created successfully
- `400`: Bad request (validation error, missing parameters)
- `401`: Unauthorized (invalid/missing JWT token)
- `403`: Forbidden (insufficient permissions)
- `404`: Resource not found
- `500`: Internal server error

## 🛠️ SERVER BUILDING SYSTEM

### Compatibility Engine
- Hardware compatibility validation between components
- Motherboard socket compatibility with CPUs
- Power supply wattage calculations
- Memory slot and type validation
- **Flexible Component Order**: Components can be added in ANY order (CPU before motherboard, or vice versa)
- Uses `FlexibleCompatibilityValidator` for order-independent validation
- Validates compatibility when second component is added, not first

### Component Integration
- Use `ServerBuilder` class for configuration management
- Validate compatibility before adding components
- Track component quantities and configurations
- Generate server specifications and cost estimates
- **Orphaned Record Cleanup**: Automatically detects and removes orphaned configuration entries
- **Duplicate Prevention**: Checks both `server_configuration_components` table and inventory `ServerUUID` field

### Configuration Status Codes
- `0`: Draft (editable)
- `1`: Validated (compatibility checked, editable)
- `2`: Built (physically assembled, limited editing)
- `3`: Finalized (production deployed, read-only)

### Component Availability
- Check `Status` field: Only components with `Status=1` (Available) can be added to new configurations
- Components with `Status=2` (In Use) are assigned to a server (`ServerUUID` is set)
- Release component: Set `Status=1` and clear `ServerUUID` field

## ⚡ PERFORMANCE OPTIMIZATION TIPS

### 1. Minimize Database Queries
- Use `ComponentDataService` for component specifications (JSON-based)
- Only query database for inventory tracking (UUID, status, serial number)
- Batch operations when possible

### 2. Preload Popular Components
```php
$service = ComponentDataService::getInstance();
$service->preloadPopularComponents(['cpu', 'motherboard', 'ram']);
```

### 3. Component Lookup Strategy
- **Direct UUID lookup**: Fastest (O(1) with cache)
- **Model name search**: Moderate (O(n) through JSON)
- **Smart matching**: Slowest (pattern matching + fuzzy search)

### 4. JSON Cache Behavior
- JSON files loaded once per component type and cached in memory
- Cache persists for duration of PHP request
- No automatic cache invalidation - restart PHP-FPM to clear cache if JSON files updated
- Manual cache clear: `ComponentDataService::getInstance()->clearCache('cpu')`

## 🐛 COMMON GOTCHAS AND SOLUTIONS

### 1. Orphaned Server Configuration Components
- **Issue**: Component exists in `server_configuration_components` but `ServerUUID` in inventory table doesn't match
- **Solution**: System auto-detects and cleans up orphaned records before adding component
- **Location**: [ServerBuilder.php:80-93](../includes/models/ServerBuilder.php#L80)

### 2. Duplicate Component Prevention
- **Issue**: Same component UUID added multiple times to same configuration
- **Solution**: System checks both config table and inventory table before allowing addition
- **Location**: `ServerBuilder::isDuplicateComponent()`

### 3. Permission Denied Errors
- All API operations (except `auth-*`) require valid JWT token
- Each operation requires specific permission (e.g., `cpu.view`, `server.create`)
- Check user permissions: `action=acl-check_permission&permission=cpu.create`
- Assign permissions: Use ACL admin interface or `action=acl-assign_permission`

### 4. Component Not Found in JSON
- If UUID not found in JSON, system attempts smart matching
- Checks serial number patterns
- Extracts model name from Notes field
- Returns match with confidence score (0.0-1.0)
- Below threshold (0.7) is rejected

### 5. API Rate Limiting
- Default: 1000 requests per time window
- Configure via `API_RATE_LIMIT_REQUESTS` in [config.php](../includes/config.php)
- Disabled in development mode (`APP_ENV=development`)

## 📦 AVAILABLE COMPONENT TYPES

- **CPU**: Intel/AMD processors (socket, cores, threads, TDP)
- **Motherboard**: Server motherboards (socket, PCIe slots, RAM slots)
- **RAM**: Memory modules (DDR3/DDR4/DDR5, speed, capacity)
- **Storage**: HDD/SSD/NVMe drives (capacity, interface, form factor)
- **NIC**: Network interface cards (speed, ports, chipset)
- **Caddy**: Drive caddies (compatibility, form factor)
- **PCIe Cards**: Expansion cards (RAID, GPU, etc.)
- **HBA Cards**: Host bus adapters (SAS/SATA controllers)
- **Chassis**: Server cases (form factor, drive bays, dimensions)

Each type has:
- Dedicated inventory table with UUID-based identification
- Serial numbers must be unique across the system
- Status codes: 0=Failed, 1=Available, 2=In Use
- All components support server assignment via `ServerUUID` field

## 📈 STATISTICS

- **Total PHP Files**: 42 endpoint files
- **Core Models LOC**: 16,150 lines of code
- **Largest Model**: ComponentCompatibility.php (5,125 LOC)
- **JSON Data Files**: 19 specification files
- **Database Tables**: 20+ tables
- **Supported Component Types**: 9 types

## 🔄 VERSION CONTROL

- Current stable version: **1.0** (2025-09-18)
- See [STABLE_VERSION_1.md](../STABLE_VERSION_1.md) for rollback instructions
- All database migrations in `database/migrations/`
- Memory bank tracking in `memory-bank/` directory

## 📚 ADDITIONAL DOCUMENTATION

- [Server API Guide](server_api_guide.md) - Detailed server builder API documentation
- [Info](info.md) - Additional project information
- [Storage Analysis](md/storage-analysis.md) - Storage system deep dive
- [Optimization Notes](md/optimise.md) - Performance optimization strategies
