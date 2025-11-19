# ARCHITECTURE.md

**BDC IMS System Design and Architecture**

## Table of Contents

- [System Overview](#system-overview)
- [Architecture Patterns](#architecture-patterns)
- [Request Flow](#request-flow)
- [Authentication System](#authentication-system)
- [Authorization (ACL) System](#authorization-acl-system)
- [Compatibility Engine](#compatibility-engine)
  - [Phase 2 (Legacy) Architecture](#phase-2-legacy-architecture)
  - [Phase 3 (New) Validator Architecture](#phase-3-new-validator-architecture)
  - [Resource Pool System](#resource-pool-system)
- [Network Component Management](#network-component-management)
  - [NIC Port Tracking](#nic-port-tracking)
  - [SFP Transceiver Management](#sfp-transceiver-management)
- [Component Data Layer](#component-data-layer)
- [State Management](#state-management)
- [Caching Strategy](#caching-strategy)
- [Error Handling](#error-handling)

---

## System Overview

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        HTTP Client                          │
│                    (curl, Postman, Frontend)                │
└───────────────┬─────────────────────────────────────────────┘
                │ HTTP POST/GET
                │ Authorization: Bearer <JWT>
                ▼
┌─────────────────────────────────────────────────────────────┐
│                     api/api.php (Router)                    │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐   │
│  │  CORS    │→ │ JWT Auth │→ │   ACL    │→ │  Module  │   │
│  │ Headers  │  │  Check   │  │  Check   │  │  Router  │   │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘   │
└───────────────┬─────────────────────────────────────────────┘
                │
                ├──────────────────┬──────────────────┬────────────────┐
                ▼                  ▼                  ▼                ▼
    ┌──────────────────┐  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
    │  Auth Module     │  │Server Module │  │Component Mgmt│  │  ACL Module  │
    │  (No Auth Req)   │  │ (Validated)  │  │ (Validated)  │  │ (Validated)  │
    └────────┬─────────┘  └───────┬──────┘  └──────┬───────┘  └──────┬───────┘
             │                    │                 │                 │
             ▼                    ▼                 ▼                 ▼
    ┌─────────────────────────────────────────────────────────────────────┐
    │                      includes/BaseFunctions.php                     │
    │                    (Core Business Logic Layer)                      │
    └───────────────────────┬─────────────────────────────────────────────┘
                            │
        ┌───────────────────┼───────────────────┐
        ▼                   ▼                   ▼
┌───────────────┐   ┌──────────────┐   ┌──────────────────┐
│ Compatibility │   │  Component   │   │    Database      │
│   Validators  │   │ Data Service │   │   (MySQL/PDO)    │
└───────────────┘   └──────────────┘   └──────────────────┘
        │                   │
        │                   ▼
        │           ┌──────────────┐
        └──────────>│  JSON Specs  │
                    │  (All-JSON/) │
                    └──────────────┘
```

### Technology Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 7.4+ |
| **Database** | MySQL 8.0+ / MariaDB 10.6+ |
| **Authentication** | JWT (JSON Web Tokens) |
| **Authorization** | Custom ACL (Access Control List) |
| **Data Format** | JSON |
| **Transport** | HTTP/HTTPS REST API |
| **ORM** | Native PDO (no ORM framework) |

---

## Architecture Patterns

### 1. Action-Based Routing Pattern

All API requests follow the pattern: `{module}-{operation}`

```php
// Route Parsing
$action = "cpu-add";
list($module, $operation) = explode('-', $action, 2);

// module = "cpu"
// operation = "add"
```

**Benefits**:
- Single entry point (`api/api.php`)
- Consistent URL structure
- Easy permission mapping
- Simplified routing logic

### 2. Module Handler Pattern

Each major feature area has a dedicated handler function:

```php
// api/api.php
switch ($module) {
    case 'auth':
        handleAuthOperations($operation);
        break;

    case 'server':
        handleServerModule($operation, $user);
        break;

    case 'cpu':
    case 'ram':
    case 'storage':
        handleComponentOperations($module, $operation, $user);
        break;
}
```

**Benefits**:
- Separation of concerns
- Isolated permission checks
- Module-specific logic encapsulation

### 3. Centralized Response Format

All responses use a unified structure via `send_json_response()`:

```php
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
    echo json_encode($response);
    exit;
}
```

**Benefits**:
- Consistent API contract
- Client-side parsing simplification
- Easier testing and debugging

---

## Request Flow

### Complete Request Lifecycle

```
1. HTTP Request Received
   ↓
2. CORS Headers Set (api/api.php:17-21)
   ↓
3. OPTIONS Preflight Handled (api/api.php:24-27)
   ↓
4. Action Parameter Extracted (api/api.php:52)
   ↓
5. Module/Operation Split (api/api.php:58-60)
   ↓
6. Auth Check (SKIP if module == 'auth')
   ├─ Extract JWT from Authorization header
   ├─ Validate JWT signature
   ├─ Check expiration
   └─ Load user data
   ↓
7. Permission Check (ACL System)
   ├─ Map operation to required permission
   ├─ Query user permissions (direct + role-based)
   └─ ALLOW or DENY
   ↓
8. Module Handler Execution
   ├─ Validate input parameters
   ├─ Perform business logic
   └─ Call BaseFunctions or specialized classes
   ↓
9. Database Operations (if needed)
   ├─ Prepared statements
   ├─ Transaction management
   └─ Error handling
   ↓
10. Response Generation
    ├─ Format data
    ├─ Call send_json_response()
    └─ HTTP response sent
```

### Authentication Bypass Flow

```
auth-login, auth-register, auth-refresh
    ↓
Skip JWT validation (api/api.php:65-68)
    ↓
Proceed directly to handleAuthOperations()
    ↓
Generate/validate credentials
    ↓
Return JWT tokens
```

---

## Authentication System

### JWT-Based Authentication

**Components**:
- `includes/JWTHelper.php` - Token generation/validation
- `includes/JWTAuthFunctions.php` - Auth helper functions
- `auth_tokens` table - Refresh token storage

### Token Flow

```
┌──────────────┐
│ auth-login   │
└──────┬───────┘
       │
       ▼
┌────────────────────────────┐
│ 1. Validate credentials    │
│    (username + password)   │
└──────┬─────────────────────┘
       │
       ▼
┌────────────────────────────┐
│ 2. Generate Access Token   │
│    (JWT, short-lived)      │
│    Payload: {user_id, iat, │
│             exp, username} │
└──────┬─────────────────────┘
       │
       ▼
┌────────────────────────────┐
│ 3. Generate Refresh Token  │
│    (Random hash, long-lived│
│    stored in auth_tokens)  │
└──────┬─────────────────────┘
       │
       ▼
┌────────────────────────────┐
│ 4. Return both tokens      │
│    to client               │
└────────────────────────────┘

┌──────────────────────────────┐
│ Subsequent Requests          │
└──────┬───────────────────────┘
       │
       ▼
┌────────────────────────────┐
│ Include Access Token in    │
│ Authorization: Bearer <JWT>│
└──────┬─────────────────────┘
       │
       ▼
┌────────────────────────────┐
│ api.php validates JWT      │
│ If expired → 401 error     │
│ If valid → proceed         │
└────────────────────────────┘

┌──────────────────────────────┐
│ Token Expired?               │
└──────┬───────────────────────┘
       │
       ▼
┌────────────────────────────┐
│ auth-refresh endpoint      │
│ Send refresh_token         │
└──────┬─────────────────────┘
       │
       ▼
┌────────────────────────────┐
│ Validate refresh token     │
│ Generate new access token  │
│ Return new token           │
└────────────────────────────┘
```

### JWT Payload Structure

```json
{
  "user_id": 5,
  "username": "admin",
  "iat": 1730822400,
  "exp": 1730908800
}
```

**Security Notes**:
- Secret key stored in `.env` (never hardcoded)
- Access tokens: 24 hours (configurable)
- Refresh tokens: 7-30 days (configurable)
- Tokens signed with HS256 algorithm

---

## Authorization (ACL) System

### Permission Resolution

```
User requests action "cpu-add"
    ↓
Map to permission: "cpu.create"
    ↓
Query user permissions:
    ├─ Direct user permissions (user_permissions table)
    └─ Role-based permissions (user_roles + role_permissions)
    ↓
Merge results (UNION)
    ↓
Check if "cpu.create" in merged list
    ├─ YES → ALLOW
    └─ NO → DENY (403 Forbidden)
```

### Permission Naming Convention

Format: `{module}.{action}`

**Examples**:
```
server.create       → Create server configurations
server.view         → View server configurations
server.edit         → Edit server configurations
server.delete       → Delete server configurations
cpu.view            → View CPU inventory
cpu.create          → Add CPU components
acl.manage          → Manage ACL system
dashboard.view      → Access dashboard
```

### Permission Hierarchy

```
Super Admin Role
├─ All permissions (wildcard)
│
Admin Role
├─ server.* (all server operations)
├─ *.view (view all components)
├─ *.create (create all components)
├─ acl.manage
│
Manager Role
├─ server.view
├─ server.create
├─ *.view (all components)
├─ *.create (all components)
│
Technician Role
├─ server.view
├─ *.view (all components)
├─ cpu.create
├─ ram.create
│
Viewer Role
└─ *.view (read-only access)
```

---

## Compatibility Engine

### Architecture

```
┌──────────────────────────────────────────────────┐
│   FlexibleCompatibilityValidator (Orchestrator)  │
│   includes/models/FlexibleCompatibilityValidator.php
└────────────┬─────────────────────────────────────┘
             │
    ┌────────┴─────────┬──────────────┬──────────────┐
    ▼                  ▼              ▼              ▼
┌──────────┐  ┌────────────────┐  ┌──────────┐  ┌──────────┐
│Component │  │    Storage     │  │  PCIe    │  │Expansion │
│Compat.   │  │  Connection    │  │  Slot    │  │  Slot    │
│          │  │   Validator    │  │ Tracker  │  │ Tracker  │
└────┬─────┘  └────────┬───────┘  └────┬─────┘  └────┬─────┘
     │                 │                │             │
     └─────────────────┴────────────────┴─────────────┘
                       │
                       ▼
           ┌───────────────────────┐
           │ ComponentDataService  │
           │  (JSON Spec Loader)   │
           └───────────┬───────────┘
                       │
                       ▼
              ┌────────────────┐
              │  All-JSON/*.json│
              │  (Specs)        │
              └────────────────┘
```

### Validation Types

#### 1. Component Pair Compatibility

**File**: `ComponentCompatibility.php`

**Validates**:
- CPU ↔ Motherboard (socket type)
- RAM ↔ Motherboard (memory type: DDR4, DDR5)
- General component pair checks

**Example**:
```php
$compatible = ComponentCompatibility::validateCpuMotherboardCompatibility(
    $cpuUuid,
    $motherboardUuid
);
// Checks: CPU socket matches motherboard socket
```

#### 2. Storage Connection Validation

**File**: `StorageConnectionValidator.php`

**Validates**:
- Storage interface (SATA/SAS/NVMe) ↔ Backplane/HBA
- Form factor (2.5"/3.5"/M.2) ↔ Chassis bays
- Connection path availability
- Caddy requirements

**Example**:
```php
$result = StorageConnectionValidator::validateStorageConnection(
    $storageUuid,
    $chassisUuid,
    $hbaUuid  // optional
);
// Returns: {compatible, connection_path, requires_caddy, warnings}
```

#### 3. PCIe Slot Allocation

**File**: `PCIeSlotTracker.php`

**Tracks**:
- Available PCIe slots in motherboard
- Slot allocation for HBA cards, NIC cards, PCIe cards
- Slot width requirements (x1, x4, x8, x16)

**Example**:
```php
$tracker = new PCIeSlotTracker($motherboardUuid);
$tracker->allocateSlot($hbaCardUuid, 'x8');
$availableSlots = $tracker->getRemainingSlots();
```

### Compatibility Workflow

```
server-add-component requested
    ↓
Load current configuration state
    ↓
┌─────────────────────────────────────┐
│ FlexibleCompatibilityValidator     │
│ ::validateConfiguration()           │
└───────────┬─────────────────────────┘
            │
    ┌───────┴───────┬────────────────┬─────────────┐
    ▼               ▼                ▼             ▼
CPU-MB Check   RAM-MB Check   Storage Valid.  PCIe Alloc.
    │               │                │             │
    └───────────────┴────────────────┴─────────────┘
                    │
            ┌───────▼───────┐
            │ Aggregate     │
            │ Results       │
            └───────┬───────┘
                    │
        ┌───────────┴───────────┐
        │ compatible: true/false│
        │ score: 0.0-1.0        │
        │ issues: []            │
        │ warnings: []          │
        └───────────────────────┘
```

---

### Phase 2 (Legacy) Architecture

The original compatibility engine uses a modular approach:

```
FlexibleCompatibilityValidator (Orchestrator)
├─ ComponentCompatibility (CPU-MB, RAM-MB)
├─ StorageConnectionValidator (Storage validation)
├─ PCIeSlotTracker (PCIe allocation)
└─ UnifiedSlotTracker (Multi-slot tracking)
```

**Status**: Functional but being superseded by Phase 3 validators.

---

### Phase 3 (New) Validator Architecture

New architecture uses specialized validators with a unified orchestrator:

```
ValidatorOrchestrator (Main Coordinator)
├─ ChassisValidator
├─ MotherboardValidator
├─ CPUValidator
├─ RAMValidator
├─ StorageValidator
├─ NICValidator
├─ PCIeCardValidator
├─ HBAValidator
├─ CaddyValidator
└─ SFP Module Validators
```

**Benefits**:
- Cleaner separation of concerns
- Easier to extend with new validators
- Better error reporting and context
- Component-specific validation logic

**Usage**:
```php
$orchestrator = ValidatorOrchestrator::getInstance();
$result = $orchestrator->validateConfiguration($configUuid);
```

---

### Resource Pool System

Unified resource allocation and tracking:

```
ResourceRegistry (Singleton)
├─ PCIeSlotPool (PCIe slot allocation)
├─ PCIeLanePool (PCIe lane allocation)
├─ RAMSlotPool (RAM DIMM slot tracking)
├─ M2SlotPool (M.2 NVMe slot tracking)
├─ U2SlotPool (U.2 storage slot tracking)
└─ SATAPortPool (SATA port allocation)
```

**Purpose**: Centralized resource allocation preventing conflicts and over-allocation.

**Example**:
```php
$registry = ResourceRegistry::getInstance();
$pciPool = $registry->getPool('pcie_slots', $chassisUuid);
$available = $pciPool->getAvailableSlots();
```

---

## Network Component Management

### NIC Port Tracking

**File**: `includes/models/NICPortTracker.php`

**Functionality**:
- Track available/allocated ports on NIC cards
- Associate SFP transceivers with ports
- Validate port configurations

**Workflow**:
```
NIC Added to Config
    ↓
NICPortTracker Initialized
    ├─ Load NIC specifications (ports, speed)
    └─ Initialize port tracking
    ↓
SFP Module Assignment
    ├─ Validate port number
    ├─ Check compatibility (speed, type)
    └─ Allocate port to SFP
    ↓
Configuration Validation
    └─ Verify all ports properly configured
```

---

### SFP Transceiver Management

**Files**:
- `includes/models/SFPPortTracker.php` - Port-level SFP tracking
- `includes/models/SFPCompatibilityResolver.php` - SFP compatibility checking
- `All-JSON/sfp-jsons/` - SFP specifications

**Features**:
- SFP module inventory management
- NIC to SFP compatibility validation
- Speed capability verification
- Port type compatibility checking

**Compatibility Matrix**:
```
NIC Specifications
├─ Port Count (4, 8, 16, etc.)
├─ Port Type (SFP+, QSFP+, etc.)
├─ Speed (1G, 10G, 25G, 40G, 100G)
└─ Form Factor

SFP Module Specifications
├─ Type (SFP, SFP+, QSFP, etc.)
├─ Speed (1G, 10G, 25G, etc.)
├─ Wavelength (SR, LR, ER, etc.)
└─ Connector Type (LC, MPO, etc.)
```

**Workflow**:
```
server-add-sfp Request
    ↓
Load NIC Specs
    ↓
Validate SFP Compatibility
    ├─ Check speed compatibility
    ├─ Check port type match
    ├─ Check form factor
    └─ Check connector type
    ↓
SFPCompatibilityResolver::validateCompatibility()
    ↓
Update NICPortTracker
    ↓
Mark SFP as allocated
```

---

## Component Data Layer

### ComponentDataService Architecture

**Purpose**: Load and cache JSON specifications

```
┌──────────────────────────────────────┐
│   ComponentDataService (Singleton)   │
│   includes/models/ComponentDataService.php
└──────────┬───────────────────────────┘
           │
    ┌──────┴──────┐
    ▼             ▼
┌────────┐   ┌────────┐
│ Cache  │   │  JSON  │
│ Layer  │   │ Loader │
└────┬───┘   └───┬────┘
     │           │
     └─────┬─────┘
           │
    ┌──────▼──────────────────┐
    │  All-JSON/*.json files  │
    └─────────────────────────┘
```

### JSON Specification Loading

```php
// On first request for component UUID
ComponentDataService::getComponentSpec('cpu-xeon-gold-6248r')
    ↓
1. Check in-memory cache
   ├─ HIT → Return cached spec
   └─ MISS → Continue
    ↓
2. Load JSON file: All-JSON/cpu-jsons/Cpu-details-level-3.json
    ↓
3. Parse JSON
    ↓
4. Search for UUID in JSON structure
    ↓
5. Cache result (UUID → spec mapping)
    ↓
6. Return spec
```

### UUID Validation Flow

```
Component Add Request (cpu-add)
    ↓
UUID: "cpu-xeon-gold-6248r-001"
    ↓
BaseFunctions::addComponent()
    ↓
ComponentDataService::validateComponentUuid()
    ↓
Search All-JSON/cpu-jsons/*.json for UUID
    ├─ FOUND → Proceed with insertion
    └─ NOT FOUND → Block insertion, return error
```

**Critical Rule**: Components can only be added to inventory if their UUID exists in JSON specifications.

---

## State Management

### Server Configuration State

**File**: `ServerBuilder.php`

```
┌──────────────────────────────────────┐
│        ServerBuilder                 │
│  (Manages in-progress configuration) │
└──────────┬───────────────────────────┘
           │
    ┌──────┴─────────┬─────────────┐
    ▼                ▼             ▼
┌────────┐   ┌──────────────┐  ┌─────────┐
│Chassis │   │  Components  │  │  State  │
│ UUID   │   │  List        │  │  (draft)│
└────────┘   └──────────────┘  └─────────┘
```

### Configuration Lifecycle

```
1. INITIALIZATION
   server-initialize
   ├─ Create config UUID
   ├─ Set chassis
   ├─ Status: draft
   └─ Store in server_configurations

2. BUILDING
   server-add-component (multiple calls)
   ├─ Validate compatibility
   ├─ Add to server_components
   ├─ Update component Status = 2 (in_use)
   └─ Repeat for each component

3. VALIDATION
   server-validate-config
   ├─ Run all compatibility checks
   ├─ Generate validation report
   └─ Return warnings/errors

4. FINALIZATION
   server-finalize-config
   ├─ Final validation check
   ├─ Update config status: active
   ├─ Lock all components
   └─ Set finalized_at timestamp

5. DEPLOYMENT (out of scope for IMS)
   Physical assembly based on configuration
```

---

## Caching Strategy

### ComponentDataService Cache

**Type**: In-memory (PHP runtime)

**Lifespan**: Single request lifecycle

**What's Cached**:
- Loaded JSON files
- UUID → Specification mappings
- Component metadata

**Cache Miss Handling**:
```php
if (isset($this->cache[$uuid])) {
    return $this->cache[$uuid];  // HIT
}

// MISS - Load from JSON
$spec = $this->loadFromJson($uuid);
$this->cache[$uuid] = $spec;
return $spec;
```

**Benefits**:
- Reduces file I/O
- Faster compatibility checks
- Prevents redundant JSON parsing

---

## Error Handling

### Error Flow

```
Exception Thrown
    ↓
Caught in try-catch block
    ↓
error_log() for debugging
    ↓
send_json_response() with:
├─ success: false
├─ code: 400/401/403/404/500
└─ message: User-friendly error
```

### Error Response Examples

**Authentication Error** (401):
```json
{
  "success": false,
  "authenticated": false,
  "code": 401,
  "message": "Valid JWT token required - please login",
  "timestamp": "2025-11-05 12:34:56"
}
```

**Permission Error** (403):
```json
{
  "success": false,
  "authenticated": true,
  "code": 403,
  "message": "Insufficient permissions: server.create required",
  "timestamp": "2025-11-05 12:34:56"
}
```

**Validation Error** (400):
```json
{
  "success": false,
  "authenticated": true,
  "code": 400,
  "message": "Component UUID 'invalid-uuid' not found in specifications",
  "timestamp": "2025-11-05 12:34:56"
}
```

**Server Error** (500):
```json
{
  "success": false,
  "authenticated": true,
  "code": 500,
  "message": "Internal server error",
  "timestamp": "2025-11-05 12:34:56"
}
```

**Security Note**: Never expose internal error details (database errors, file paths, stack traces) in production responses.

---

**Last Updated**: 2025-11-05
**Architecture Version**: 1.0
