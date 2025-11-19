# BDC IMS - Server Build Validation Test

Automated end-to-end testing of the complete server configuration workflow with real API calls.

## Overview

This test validates the entire server build process by creating **3 complete server configurations** with different scenarios, including edge cases. After each configuration is tested, it is automatically deleted to free up components for the next test.

## Quick Start

### Run the Test

```bash
# Windows - Run with default settings (localhost:8000)
php tests\server_build_validation.php

# Windows - Run against staging server
php tests\server_build_validation.php https://shubham.staging.cloudmate.in/bdc_ims_dev

# Windows - Using batch script
tests\run_server_validation.bat
```

### Check Results

After the test completes, check the detailed log file:
```
tests\reports\server_build_validation_[timestamp].log
```

## What Gets Tested

### Three Complete Server Configurations

**Configuration 1: Standard Full Server Build**
- Creates server with chassis + motherboard
- Adds all component types: CPU, RAM, Storage, NIC, PCIe, HBA
- Validates complete configuration
- **Deletes configuration to free components**

**Configuration 2: Riser Slot Edge Case**
- Tests motherboard with only riser slots (no onboard PCIe)
- Validates PCIe devices are unavailable initially
- Installs riser card first
- Validates PCIe/NIC/HBA cards become available after riser
- **Deletes configuration to free components**

**Configuration 3: Onboard NIC Detection**
- Tests motherboard with integrated NIC
- Validates automatic onboard NIC detection
- Confirms it appears without manual add action
- **Deletes configuration to free components**

### Component Types Validated
- ✅ CPU (including dual-socket support)
- ✅ RAM (multiple modules, fills available slots)
- ✅ Storage (SSD/HDD, multiple devices)
- ✅ NIC (network cards)
- ✅ PCIe cards
- ✅ HBA cards

### API Endpoints Tested
- ✅ `auth-login` - Authentication
- ✅ `server-create` - Server initialization
- ✅ `server-get-compatibility` - Component compatibility checks
- ✅ `server-add-component` - Component addition
- ✅ `server-get-config` - Configuration retrieval
- ✅ `server-validate-config` - Configuration validation
- ✅ `server-delete-config` - **Configuration deletion (frees components)**

## Output

### Console Output (Real-time)

```
╔══════════════════════════════════════════════════════════════════════════╗
║    BDC IMS - Complete Server Configuration Workflow Validation          ║
╚══════════════════════════════════════════════════════════════════════════╝

[0.123s] [INFO   ] Authenticating as superadmin...
[0.456s] [SUCCESS] Authentication successful
================================================================================
CONFIGURATION 1: Standard Full Server Build
================================================================================
[1.567s] [INFO   ] Creating server: Standard Server Build #1
[1.890s] [SUCCESS] Server created successfully. Config ID: 123
[2.123s] [INFO   ] Processing component type: cpu
[2.456s] [SUCCESS] Successfully added cpu: Intel Xeon Gold 6248R
...
[15.678s] [SUCCESS] Configuration validation: PASSED
[15.890s] [INFO   ] Deleting configuration 123 to free components...
[16.123s] [SUCCESS] Configuration deleted. Components freed for reuse.
```

### Detailed Log File

Located at: `tests\reports\server_build_validation_[timestamp].log`

**Contains:**
- Complete API request/response for every call
- Compatibility check results
- Component addition details with quantities
- Configuration summaries for each server
- Validation results
- Component deletion confirmations
- Timing information

## Key Features

### Component Reuse
After each server configuration is validated, it is automatically deleted. This frees up all components (CPU, RAM, Storage, etc.) so they can be used in the next server build test. This is critical when you have limited components in your inventory.

**Workflow:**
1. Build Server 1 → Test → Validate → **Delete** → Components freed
2. Build Server 2 → Test → Validate → **Delete** → Components freed
3. Build Server 3 → Test → Validate → **Delete** → Components freed

### Intelligent Component Addition
- **Dual CPU support** - Adds 2 CPUs if motherboard supports it
- **Multiple RAM modules** - Fills available slots intelligently
- **Multi-device storage** - Adds 2-4 storage devices
- **Quantity optimization** - Determines optimal quantities automatically

### Edge Case Validation
- **Riser Slot Test**: Verifies PCIe expansion locked until riser installed
- **Onboard NIC Test**: Verifies automatic detection of integrated components

### Comprehensive Logging
- Every API call logged with request/response
- Color-coded console output
- Detailed log files for inspection
- Component deletion tracking

## Troubleshooting

### Issue: No Compatible Components Found

**Symptoms:**
```
[WARNING] Found 0 compatible cpus
[WARNING] No compatible cpus available, skipping
```

**Causes:**
- No components in inventory with status=1 (available)
- Components still marked as in_use from previous test

**Solutions:**
1. Check component inventory status:
   ```sql
   SELECT * FROM cpuinventory WHERE status = 1;
   ```
2. Manually free components if delete failed:
   ```sql
   UPDATE cpuinventory SET status = 1 WHERE status = 2;
   ```

### Issue: Delete Configuration Failed

**Symptoms:**
```
[WARNING] Failed to delete configuration: Configuration not found
```

**Causes:**
- Configuration already deleted
- API endpoint not implemented

**Solutions:**
1. Check if `server-delete-config` endpoint exists in `api/api.php`
2. Verify configuration ID exists in database
3. Manually delete if needed:
   ```sql
   DELETE FROM server_configurations WHERE id = [config_id];
   ```

## Expected Results

✅ **3 server configurations created successfully**
✅ **All component types added correctly**
✅ **Compatibility checks return valid components**
✅ **Riser slot logic works (devices locked/unlocked)**
✅ **Onboard NIC detected automatically**
✅ **All configurations validated successfully**
✅ **All configurations deleted successfully**
✅ **Components freed and reusable**

## Test Duration

Typical execution time: **20-40 seconds**
- Authentication: ~0.5s
- Component listing: ~0.3s per type
- Server creation: ~1s per server
- Compatibility checks: ~0.5s per type
- Component addition: ~0.8s per component
- Deletion: ~0.5s per config

## Files

| File | Purpose |
|------|---------|
| `server_build_validation.php` | Main test script (650+ lines) |
| `run_server_validation.bat` | Windows batch launcher |
| `SERVER_BUILD_VALIDATION.md` | Detailed documentation |
| `README.md` | This file |
| `reports/` | Test logs and reports |

## Advanced Usage

### Run Against Different Server

```bash
php tests\server_build_validation.php http://your-custom-server:8080
```

### Check Exit Code

```bash
php tests\server_build_validation.php
echo %ERRORLEVEL%
# 0 = success, 1 = failure
```

### Parse Logs

```bash
# Find errors
findstr "ERROR" tests\reports\server_build_validation_*.log

# Find successful component additions
findstr "Successfully added" tests\reports\server_build_validation_*.log

# Find deletion confirmations
findstr "Configuration deleted" tests\reports\server_build_validation_*.log
```

## For AI Agents

AI agents can execute this test and analyze results programmatically:

```bash
# Execute test
php tests\server_build_validation.php https://staging-api.example.com

# Check success
if %ERRORLEVEL% EQU 0 (
    echo All tests passed
) else (
    echo Tests failed
)

# Get latest log
for /f "delims=" %%i in ('dir /b /od tests\reports\server_build_validation_*.log') do set LATEST=%%i
type tests\reports\%LATEST%
```

## Summary

This test provides comprehensive validation of:
- ✅ Complete server build workflow
- ✅ Component compatibility logic
- ✅ Multi-unit component support
- ✅ Edge cases (riser slots, onboard NICs)
- ✅ API endpoint functionality
- ✅ **Component reuse through configuration deletion**

**Key Benefit**: Automatically frees components after each test, allowing multiple server builds even with limited inventory.

For detailed technical documentation, see [SERVER_BUILD_VALIDATION.md](SERVER_BUILD_VALIDATION.md).
