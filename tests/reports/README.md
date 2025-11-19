# BDC IMS Server Build Validation Test Reports

This directory contains comprehensive test analysis and reports for the BDC Inventory Management System (IMS) server build validation suite.

## Files in This Directory

### 1. **QUICK_SUMMARY.txt** - START HERE
**Purpose**: Quick overview of the test suite and findings
**Content**:
- Current deployment status
- Test suite overview
- Test scenarios (3 total)
- API endpoints covered (11+)
- Component types (9 total)
- Expected metrics
- Prerequisites
- Troubleshooting guide

**Read this first** if you want a quick understanding.

### 2. **TEST_ANALYSIS_REPORT.md** - DETAILED DOCUMENTATION
**Purpose**: Comprehensive analysis of the test suite
**Content**:
- Executive summary
- Test architecture (4 classes)
- API endpoint specifications with request/response examples
- Edge case handling (riser slots, onboard NICs, multi-quantity)
- Test execution flow
- Expected results and validation rules
- Current deployment issues and solutions
- Test script features and capabilities
- Recommendations for testing and production

**Read this** for in-depth understanding of what the tests do.

### 3. **VISUAL_TEST_OVERVIEW.txt** - FLOW DIAGRAMS
**Purpose**: Visual representation of test architecture and flow
**Content**:
- Architecture diagram
- Component flow diagram
- API call sequence
- Scenario breakdown (visual steps)
- Component addition logic
- Validation rules
- Expected output structure
- Error handling
- Success criteria

**Read this** if you prefer visual/graphical explanations.

### 4. **api_test.php** - Standalone Test Script
**Purpose**: Run API tests against the staging server
**Capabilities**:
- Authentication testing
- Component listing validation
- Server build workflow testing
- Configuration management
- Report generation

**Usage**:
```bash
php api_test.php
```

## Current Status

⚠️ **DEPLOYMENT ISSUE DETECTED**

The staging server (`https://shubham.staging.cloudmate.in/bdc_ims_dev/api/api.php`) is returning PHP errors due to:
- Code refactored from `includes/` to `core/helpers/` structure
- Deployment hasn't been updated with new file paths
- Missing file: `/core/helpers/JWTHelper.php`

### Impact
- ❌ Cannot execute tests against staging server
- ❌ Authentication fails with PHP error
- ❌ All subsequent API calls blocked

### Solution
1. Deploy latest refactored code to staging
2. Verify file structure matches new organization
3. Ensure database connection and credentials work
4. Re-run tests after deployment

## How to Run the Tests

### Option 1: Using Batch Script (Windows)
```batch
cd "c:\Users\anshi\Downloads\Work\mine\ims ftp"
tests\run_server_validation.bat https://your-staging-url
```

### Option 2: Using PHP Directly
```bash
php tests/server_build_validation.php https://your-staging-url
```

### Option 3: With Default Local URL
```bash
php tests/server_build_validation.php http://localhost:8000
```

## Test Scenarios

### Scenario 1: Standard Full Server Build
- Creates basic server configuration
- Tests all component types
- Validates complete workflow
- Tests standard compatibility engine

### Scenario 2: Riser Slot Edge Case
- Finds motherboards with riser-only slots
- Tests riser card installation
- Verifies PCIe slot unlocking
- Tests complex slot allocation

### Scenario 3: Onboard NIC Detection
- Finds motherboards with integrated NICs
- Tests automatic onboard detection
- Validates add-on NIC compatibility
- Prevents slot conflicts

## API Endpoints Tested

### Authentication
- `auth-login` - Get JWT token

### Component Listing (9 endpoints)
- `cpu-list`, `ram-list`, `storage-list`, `motherboard-list`
- `nic-list`, `caddy-list`, `chassis-list`, `pciecard-list`, `hbacard-list`

### Server Management (7 endpoints)
- `server-create` - Create configuration
- `server-get-config` - Retrieve configuration
- `server-get-compatibility` - Query compatible components
- `server-add-component` - Add component to server
- `server-validate-config` - Validate configuration
- `server-finalize-config` - Lock configuration
- `server-delete-config` - Delete configuration

## Expected Test Metrics

| Metric | Value |
|--------|-------|
| Total API Calls | 50-70 |
| Expected Duration | 30-60 seconds |
| Pass Rate | 100% (target) |
| Error Rate | 0% (target) |
| Component Types | 9 |
| Test Scenarios | 3 |
| Edge Cases | 2+ |

## Component Types Covered

1. **CPU** - Processors
2. **RAM** - Memory modules
3. **Storage** - Disk drives (SSD/HDD/NVMe)
4. **Motherboard** - System boards
5. **NIC** - Network interfaces (with onboard detection)
6. **Caddy** - Drive enclosures
7. **Chassis** - Server enclosures
8. **PCIe Card** - Expansion cards (including risers)
9. **HBA Card** - Host bus adapters

## Edge Cases Tested

### 1. Riser Slot Handling
- Identifies riser-only motherboards
- Installs riser cards dynamically
- Recalculates slot availability
- Adds components to riser slots

### 2. Onboard NIC Detection
- Auto-detects integrated network ports
- Reserves slots for onboard components
- Prevents dual-NIC conflicts
- Validates mixed scenarios

### 3. Multi-Quantity Components
- CPU: Dual-socket support (up to 2)
- RAM: DIMM slot filling (up to 4)
- Storage: Multi-drive support (2-4)

## Log Files Generated

After successful execution:

1. **server_build_validation_YYYY-MM-DD_HH-MM-SS.log**
   - Complete execution log with timestamps
   - Color-coded console output
   - API call details
   - Configuration summaries

2. **api_test_YYYY-MM-DD_HH-MM-SS.json**
   - Machine-readable results
   - Full response payloads
   - HTTP status codes
   - API call timing

## Prerequisites

### Server
- PHP 7.4+ with cURL extension
- MySQL 5.7+ database
- Network access to API server

### Database
- Schema imported with all tables
- Test user created (superadmin/password)
- Component inventory populated

### File Structure
- All-JSON files exist with component specs
- UUID references consistent with database
- Proper file permissions for PHP

## Troubleshooting

### Authentication Fails
```
Solution:
1. Verify credentials: superadmin/password
2. Check user exists in database
3. Verify database connection
4. Check JWT configuration
```

### No Components Found
```
Solution:
1. Verify All-JSON files exist
2. Check database inventory tables
3. Ensure JSON UUIDs match database
4. Verify file permissions
```

### Configuration Creation Fails
```
Solution:
1. Verify chassis_uuid and motherboard_uuid exist
2. Check database foreign keys
3. Verify server_configurations table exists
```

## For More Information

- **QUICK_SUMMARY.txt** - Start here for overview
- **TEST_ANALYSIS_REPORT.md** - Detailed documentation
- **VISUAL_TEST_OVERVIEW.txt** - Flow diagrams and visuals
- **Parent directory** - Source code and API implementations

## Next Steps

1. **Fix Staging Deployment**
   - Deploy latest refactored code
   - Verify file paths match new structure
   - Test authentication manually

2. **Run Tests**
   - Execute test suite against staging
   - Review generated logs
   - Fix any failing scenarios

3. **Production Readiness**
   - Load test with concurrent builds
   - Performance optimization
   - Security hardening
   - Monitor and alert setup

---

**Generated**: 2025-11-19
**Test Suite Status**: Ready (pending staging server fix)
**Contact**: For issues, check QUICK_SUMMARY.txt troubleshooting section
