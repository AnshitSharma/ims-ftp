# BDC IMS - Server Build Validation Test Report

**Generated**: 2025-11-19
**Test Type**: Complete Server Configuration Workflow Validation
**Target URL**: https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php

---

## Executive Summary

This report details the comprehensive API test suite for the BDC Inventory Management System (IMS). The test covers complete end-to-end server build workflows including authentication, component listing, server configuration, compatibility checking, and state management.

### Current Status
⚠️ **Staging Server Configuration Issue Detected**
- File path error on staging server: Missing JWT helper file path
- The refactored codebase structure doesn't match the deployed version
- **Recommendation**: Deploy latest code to staging and verify file paths

---

## Test Architecture Overview

### Test Classes & Components

#### 1. **IMSApiClient** (Lines 14-178)
Handles all HTTP communication with the API.

**Key Methods**:
- `authenticate()` - JWT token acquisition
- `call($action, $params)` - Generic API call handler
- `listComponents($type)` - List inventory by component type
- `createServer()` - Initialize server configuration
- `getCompatibility()` - Query compatible components
- `addComponent()` - Add component to server
- `validateConfiguration()` - Full config validation
- `finalizeConfiguration()` - Lock configuration state
- `deleteConfiguration()` - Free up components for reuse

**Error Handling**:
- CURL error detection with fallback messaging
- JSON response validation
- HTTP status code tracking

---

#### 2. **TestLogger** (Lines 180-282)
Structured logging system with color output and file persistence.

**Features**:
- Real-time console output with color coding
- File-based audit trail
- API call request/response logging
- Configuration summary generation
- Performance timing (millisecond precision)
- Test execution footer with statistics

**Log Levels**:
- `ERROR` (Red) - Critical failures
- `SUCCESS` (Green) - Successful operations
- `WARNING` (Yellow) - Non-critical issues
- `INFO` (Cyan) - General information
- `DEBUG` (Gray) - Detailed diagnostic info

---

#### 3. **ServerConfigurationBuilder** (Lines 284-523)
Orchestrates complete server build workflows.

**Core Workflow Steps**:

1. **Server Creation**
   - Selects chassis and motherboard
   - Initializes configuration in database
   - Returns unique config_id for state tracking

2. **Onboard Component Detection**
   - Checks motherboard for integrated components
   - Logs onboard NIC availability
   - Affects subsequent compatibility queries

3. **Riser Card Installation** (Edge Case)
   - Identifies motherboards with riser-only slots
   - Installs riser card to unlock PCIe slots
   - Verifies slot availability after installation

4. **Component Addition** (Type-specific)
   - CPU: Up to 2 units (dual-socket support)
   - RAM: Up to 4 DIMMs (half of available slots)
   - Storage: 2-4 units depending on slots
   - NICs/HBA/PCIe: 1 per compatible slot

5. **Configuration Validation**
   - Comprehensive compatibility check
   - Error reporting with specific violation details
   - Returns `is_valid` boolean and error array

6. **Finalization & Cleanup**
   - Locks configuration (marks components in_use)
   - Frees resources by deleting config
   - Enables component reuse across test iterations

---

#### 4. **ServerBuildValidator** (Lines 525-712)
Main orchestrator running three distinct test scenarios.

**Test Scenarios**:

**Scenario 1: Standard Full Server Build**
- Uses first available chassis and motherboard
- Adds all component types
- Validates complete configuration
- Tests standard workflow

**Scenario 2: Riser Slot Edge Case**
- Targets motherboards with riser-only slots
- Tests riser installation flow
- Validates PCIe slot unlocking
- Critical for supported hardware validation

**Scenario 3: Onboard NIC Detection**
- Selects motherboards with integrated NICs
- Verifies onboard component auto-detection
- Tests compatibility with add-on NICs
- Ensures no slot conflicts

---

## API Endpoint Test Coverage

### Authentication Endpoints

#### `auth-login`
**Request**:
```
POST /api/api.php
action=auth-login
username=superadmin
password=password
```

**Expected Response**:
```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "data": {
    "tokens": {
      "access_token": "eyJhbGc...",
      "token_type": "Bearer",
      "expires_in": 86400
    },
    "user": {
      "id": 1,
      "username": "superadmin",
      "email": "admin@bdc.local",
      "roles": ["super_admin"]
    }
  }
}
```

**Test Points**:
- ✓ JWT token generation
- ✓ Token structure and format
- ✓ Expiry calculation (24 hours default)
- ✓ User profile in response

---

### Component Listing Endpoints

All component types tested: `cpu`, `ram`, `storage`, `motherboard`, `nic`, `caddy`, `chassis`, `pciecard`, `hbacard`

#### `{type}-list` Pattern
**Request**:
```
POST /api/api.php
Authorization: Bearer {token}
action={type}-list
```

**Expected Response**:
```json
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "data": {
    "components": [
      {
        "uuid": "cpu-001-abc123",
        "name": "Intel Xeon E5-2690 v4",
        "specifications": {
          "cores": 14,
          "threads": 28,
          "tdp": 135,
          "socket": "LGA2011-v3"
        },
        "status": "available"
      }
    ]
  }
}
```

**Test Points**:
- ✓ Authentication validation (JWT required)
- ✓ All 9 component types available
- ✓ UUID format consistency
- ✓ Specification structure integrity
- ✓ Status field accuracy (available/in_use/failed)

---

### Server Management Endpoints

#### `server-create`
**Request**:
```
POST /api/api.php
Authorization: Bearer {token}
action=server-create
chassis_uuid=ch-2u-001
motherboard_uuid=mb-dual-xeon-001
server_name=Test Server 2025-11-19
```

**Expected Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "config_id": "cfg_12345_uuid",
    "server_name": "Test Server 2025-11-19",
    "status": "initializing",
    "chassis_uuid": "ch-2u-001",
    "motherboard_uuid": "mb-dual-xeon-001"
  }
}
```

**Test Points**:
- ✓ Config ID generation (unique identifier)
- ✓ Status initialization to "initializing"
- ✓ Chassis/motherboard validation
- ✓ Database transaction integrity

---

#### `server-get-config`
**Request**:
```
POST /api/api.php
Authorization: Bearer {token}
action=server-get-config
config_id=cfg_12345_uuid
```

**Expected Response**:
```json
{
  "success": true,
  "data": {
    "config_id": "cfg_12345_uuid",
    "server_name": "Test Server",
    "status": "initializing",
    "chassis": {...},
    "motherboard": {...},
    "components": {
      "cpu": [...],
      "ram": [...],
      "storage": [...],
      "nic": [...],
      "pciecard": [...]
    },
    "onboard_components": {
      "nic": [...]
    }
  }
}
```

**Test Points**:
- ✓ Complete configuration retrieval
- ✓ Component state tracking
- ✓ Onboard component detection
- ✓ Nested object serialization

---

#### `server-get-compatibility`
**Request**:
```
POST /api/api.php
Authorization: Bearer {token}
action=server-get-compatibility
config_id=cfg_12345_uuid
component_type=cpu
```

**Expected Response**:
```json
{
  "success": true,
  "data": {
    "compatible_components": [
      {
        "uuid": "cpu-intel-e5-v4",
        "name": "Intel Xeon E5-2690 v4",
        "compatibility_score": 95,
        "reasons": ["Matches motherboard socket", "Meets TDP requirements"]
      }
    ],
    "available_slots": 2,
    "reason_incompatible": []
  }
}
```

**Test Points**:
- ✓ Compatibility algorithm execution
- ✓ Slot availability calculation
- ✓ Compatibility scoring
- ✓ Incompatibility reason reporting
- ✓ Type-specific constraints applied

---

#### `server-add-component`
**Request**:
```
POST /api/api.php
Authorization: Bearer {token}
action=server-add-component
config_id=cfg_12345_uuid
component_type=cpu
component_uuid=cpu-intel-e5-v4
quantity=1
```

**Expected Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "message": "Component added successfully",
    "component": {
      "uuid": "cpu-intel-e5-v4",
      "quantity": 1,
      "status": "in_use"
    },
    "remaining_slots": 1
  }
}
```

**Test Points**:
- ✓ Component inventory status update (in_use)
- ✓ Slot decrement tracking
- ✓ UUID validation against JSON specs
- ✓ Quantity support for multi-slot items

---

#### `server-validate-config`
**Request**:
```
POST /api/api.php
Authorization: Bearer {token}
action=server-validate-config
config_id=cfg_12345_uuid
```

**Expected Response**:
```json
{
  "success": true,
  "data": {
    "validation_result": {
      "is_valid": true,
      "errors": [],
      "warnings": [],
      "component_count": 8,
      "validation_timestamp": "2025-11-19 14:30:00"
    }
  }
}
```

**Validation Rules Tested**:
- ✓ Minimum component requirements (CPU + Motherboard)
- ✓ CPU-Motherboard socket compatibility
- ✓ RAM-Motherboard type/speed compatibility
- ✓ Storage connection validation (SATA/SAS/NVMe)
- ✓ Power budget (PSU TDP vs component TDP)
- ✓ Physical slot constraints (PCIe/riser/onboard)
- ✓ HBA-Storage compatibility
- ✓ Onboard NIC conflict detection

---

#### `server-finalize-config`
**Request**:
```
POST /api/api.php
Authorization: Bearer {token}
action=server-finalize-config
config_id=cfg_12345_uuid
```

**Expected Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "config_id": "cfg_12345_uuid",
    "status": "finalized",
    "message": "Configuration locked and ready for deployment"
  }
}
```

**Test Points**:
- ✓ Status transition to "finalized"
- ✓ Component status locked to "in_use"
- ✓ Configuration immutability after finalization
- ✓ Deployment readiness marker

---

#### `server-delete-config`
**Request**:
```
POST /api/api.php
Authorization: Bearer {token}
action=server-delete-config
config_id=cfg_12345_uuid
```

**Expected Response**:
```json
{
  "success": true,
  "code": 200,
  "data": {
    "message": "Configuration deleted successfully",
    "freed_components": 8
  }
}
```

**Test Points**:
- ✓ Configuration record removal
- ✓ Component status reset to "available"
- ✓ Inventory reconciliation
- ✓ Freed component count accuracy

---

## Edge Cases & Special Tests

### 1. Riser Slot Handling (Lines 383-424)
**Scenario**: Motherboard with riser-only slots

**Test Flow**:
1. Identify motherboard with `riser_slots` but no `pci_slots`
2. Query compatibility to confirm no PCIe slots available
3. Install riser card from compatible list
4. Re-query compatibility to verify slot availability
5. Add devices to newly available slots

**Expected Behavior**:
- Riser card installation unlocks PCIe slots
- Compatibility engine recalculates after riser addition
- Devices can be added to riser-provided slots

**Validation Points**:
- ✓ Riser detection algorithm
- ✓ Slot tracking state machine
- ✓ Compatibility re-calculation trigger
- ✓ Component addition after riser installation

---

### 2. Onboard NIC Detection (Lines 359-378)
**Scenario**: Motherboard with integrated network interface

**Test Flow**:
1. Query configuration for `onboard_components`
2. Detect onboard NIC automatically
3. Track onboard NIC in slot calculations
4. Prevent add-on NIC if onboard NIC fills requirement
5. Validate slot conflicts

**Expected Behavior**:
- Onboard components detected during server creation
- Slots allocated to onboard components
- Compatibility engine excludes slots for onboard devices
- Logging of onboard component discovery

**Validation Points**:
- ✓ Onboard component auto-detection
- ✓ Slot reservation for onboard devices
- ✓ Conflict-free add-on NIC placement
- ✓ Transparent onboard availability

---

### 3. Multi-Quantity Components (Lines 501-518)
**Scenario**: Components that support multiple quantities

**Test Flow**:
1. **CPU**: Add 1-2 units (dual-socket support)
2. **RAM**: Add 1-4 DIMMs (half-fill test)
3. **Storage**: Add 2-4 drives (multi-device support)
4. Verify slot decrement for each quantity

**Expected Behavior**:
- Quantity parameter properly increments component count
- Slots decremented correctly based on quantity
- Multiple units tracked in configuration
- Validation passes with multiple quantities

**Validation Points**:
- ✓ Quantity parsing and validation
- ✓ Slot calculation with quantities
- ✓ Multi-unit compatibility checks
- ✓ Remaining slot accuracy

---

## Test Execution Flow

```
START
 ↓
Initialize Logger & API Client
 ↓
Authenticate (auth-login)
 ├─ Success: Continue
 └─ Failure: Exit with error
 ↓
List All Components (9 types)
 ├─ Track inventory counts
 └─ Validate JSON spec loading
 ↓
Configuration 1: Standard Build
 ├─ Select chassis & motherboard
 ├─ Create server configuration
 ├─ Check onboard components
 ├─ Add CPU, RAM, Storage, NICs, etc.
 ├─ Validate configuration
 ├─ Finalize configuration
 ├─ Delete configuration
 └─ Free resources
 ↓
Configuration 2: Riser Slot Edge Case
 ├─ Find riser-only motherboard
 ├─ Create server configuration
 ├─ Install riser card
 ├─ Verify PCIe slot availability
 ├─ Add components to riser slots
 ├─ Validate configuration
 ├─ Delete configuration
 └─ Free resources
 ↓
Configuration 3: Onboard NIC Detection
 ├─ Find motherboard with onboard NIC
 ├─ Create server configuration
 ├─ Verify onboard NIC detection
 ├─ Add compatible add-on NICs (if slots available)
 ├─ Validate configuration
 ├─ Delete configuration
 └─ Free resources
 ↓
Generate Report
 ├─ Log summary statistics
 ├─ Save JSON results
 └─ Output test duration
 ↓
END
```

---

## Expected Results Summary

### Test Metrics
- **Total API Calls**: 50-70 (depending on component availability)
- **Expected Duration**: 30-60 seconds
- **Pass Rate Target**: 100% (all validations should pass)
- **Error Rate Target**: 0%

### Component Listing Verification
| Type | Expected | Test |
|------|----------|------|
| CPU | ≥1 | ✓ |
| RAM | ≥1 | ✓ |
| Storage | ≥1 | ✓ |
| Motherboard | ≥1 | ✓ |
| NIC | ≥1 | ✓ |
| Caddy | ≥0 | ✓ |
| Chassis | ≥1 | ✓ |
| PCIe Card | ≥0 | ✓ |
| HBA Card | ≥0 | ✓ |

### Server Build Success Criteria
1. ✓ All 3 configurations created successfully
2. ✓ Compatibility queries return valid results
3. ✓ Components added without conflicts
4. ✓ Validation passes for all configurations
5. ✓ Finalization succeeds for all configs
6. ✓ Deletion frees components properly
7. ✓ Configuration state transitions are correct

---

## Current Deployment Issue

### Problem Identified
**Staging Server Error**:
```
Warning: require_once(/home/shubhams/public_html/bdc_ims_dev/core/helpers/JWTHelper.php):
Failed to open stream: No such file or directory
```

**Root Cause**:
- Codebase refactored from `includes/` to `core/helpers/` structure
- Deployment on staging hasn't been updated with new file paths
- `BaseFunctions.php` still references old file locations

**Impact**:
- ❌ Authentication endpoint returning PHP error instead of JSON
- ❌ Unable to generate JWT tokens
- ❌ All subsequent API calls fail
- ❌ Test suite cannot execute

### Required Actions for Testing

1. **Update Staging Deployment**:
   ```bash
   # Deploy latest code changes
   git pull origin main

   # Verify file structure
   ls -la core/helpers/
   ls -la core/config/

   # Check file permissions
   chmod 755 core/helpers/*.php
   chmod 755 core/config/*.php
   ```

2. **Verify Include Paths in `api/api.php`**:
   ```php
   require_once(__DIR__ . '/../core/config/app.php');
   require_once(__DIR__ . '/../core/helpers/BaseFunctions.php');
   ```

3. **Database Verification**:
   - Ensure MySQL connection works
   - Verify required tables exist
   - Check user: superadmin exists with valid password

4. **Test Post-Deployment**:
   ```bash
   php tests/server_build_validation.php https://shubham.staging.cloudmate.in/bdc_ims_dev
   ```

---

## Test Script Features

### Logging Capabilities
- **Console Output**: Real-time colored logging
- **File Logging**: Detailed audit trail (`tests/reports/server_build_validation_*.log`)
- **JSON Export**: Machine-readable results (`tests/reports/api_test_*.json`)
- **Timing**: Millisecond-precision execution tracking

### Error Recovery
- CURL error detection with descriptive messages
- Invalid JSON response handling
- Missing required fields validation
- Graceful degradation on component unavailability

### Report Generation
- Configuration summary with component details
- Success/failure statistics by operation
- Detailed error messages and reasons
- Total execution time and API call count

---

## Recommendations

### For Successful Testing

1. **Fix Staging Deployment**
   - Deploy latest refactored code
   - Verify all file paths match new structure
   - Test authentication manually before running suite

2. **Database Verification**
   - Import latest schema (if available)
   - Seed test data (chassis, motherboards, components)
   - Verify user credentials (superadmin/password)

3. **Local Testing Alternative**
   - Set up local PHP development environment
   - Use `php -S localhost:8000`
   - Run tests against local server first
   - Debug file path issues in local environment

4. **Component Data**
   - Ensure JSON files exist in `All-JSON/{type}-jsons/`
   - Verify UUID references in JSON match database
   - Test with at least 3 chassis and 5 motherboards
   - Include at least 1 riser-only motherboard
   - Include at least 1 motherboard with onboard NIC

### For Production Readiness

1. **Performance Testing**
   - Add load testing for concurrent server builds
   - Monitor database query performance
   - Test with 100+ components per type
   - Validate slot tracking with high concurrency

2. **Security Testing**
   - Validate JWT token expiration
   - Test ACL permission enforcement
   - Verify component ownership isolation
   - Test SQL injection prevention

3. **Integration Testing**
   - Test with actual hardware specifications
   - Validate against real PSU/TDP calculations
   - Test cable/connector compatibility
   - Validate firmware compatibility matrices

---

## Appendix: Test File Statistics

| Metric | Value |
|--------|-------|
| Total Lines | 739 |
| Classes | 4 |
| Test Scenarios | 3 |
| Component Types Tested | 9 |
| API Endpoints Tested | 11+ |
| Edge Cases | 2+ |
| Code Comments | Comprehensive |
| Documentation | Inline + external |

---

**Report Generated**: 2025-11-19
**Test Status**: Ready for execution (pending staging server fix)
**Next Steps**: Deploy refactored code and re-run tests
