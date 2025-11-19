# Server Build Validation Test

Complete end-to-end validation of the BDC IMS server configuration workflow with real API calls.

## Overview

This automated test script validates the entire server build process by creating **3 complete server configurations** with different scenarios:

1. **Standard Server Build** - Full configuration with all component types
2. **Riser Slot Edge Case** - Motherboard with only riser slots, requires riser card installation first
3. **Onboard NIC Detection** - Motherboard with integrated NIC, validates automatic detection

## What It Tests

### Core Functionality
- ✅ Server creation via `server-create` API
- ✅ Component compatibility checking via `server-get-compatibility`
- ✅ Component addition via `server-add-component`
- ✅ Multi-unit component support (dual CPUs, multiple RAM sticks, etc.)
- ✅ Configuration validation
- ✅ Final configuration retrieval

### Component Types Tested
- CPU (including dual-socket support)
- RAM (multiple modules)
- Storage (SSD/HDD)
- NIC (network cards)
- PCIe cards
- HBA cards

### Edge Cases
- **Riser Slots**: Motherboards with no direct PCIe slots
  - Validates that no PCIe/NIC/HBA cards are available initially
  - Installs riser card first
  - Validates that PCIe slots become available after riser installation

- **Onboard Components**: Motherboards with integrated NICs
  - Validates automatic detection of onboard NIC
  - Confirms it appears in configuration without manual add

## Usage

### Basic Usage

```bash
# Run against local development server
php tests/server_build_validation.php

# Run against staging server
php tests/server_build_validation.php https://shubham.staging.cloudmate.in/bdc_ims_dev

# Run with custom base URL
php tests/server_build_validation.php http://your-server.com
```

### Quick Start Scripts

**Windows:**
```batch
tests\run_server_validation.bat
```

**Linux/Mac:**
```bash
./tests/run_server_validation.sh
```

## Output

### Console Output

The script provides real-time colored console output:

```
╔══════════════════════════════════════════════════════════════════════════╗
║    BDC IMS - Complete Server Configuration Workflow Validation          ║
╚══════════════════════════════════════════════════════════════════════════╝

Base URL: http://localhost:8000
Log File: tests/reports/server_build_validation_2025-11-19_15-30-45.log

[0.123s] [INFO   ] Authenticating as superadmin...
[0.456s] [SUCCESS] Authentication successful. Token: eyJhbGciOiJIUzI1NiIs...
[0.789s] [INFO   ] Fetching available chassis...
[1.234s] [INFO   ] Found 5 chassis(s)
================================================================================
CONFIGURATION 1: Standard Full Server Build
================================================================================
[1.567s] [INFO   ] Creating server: Standard Server Build #1
[1.890s] [SUCCESS] Server created successfully. Config ID: 123
[2.123s] [INFO   ] Checking for onboard components...
[2.456s] [INFO   ] Processing component type: cpu
...
```

### Detailed Log File

Located at: `tests/reports/server_build_validation_[timestamp].log`

Contains:
- Complete API call history with requests and responses
- Compatibility check results
- Component addition details
- Configuration summaries
- Validation results
- Timing information

**Example log structure:**
```
==================================================================================================
BDC IMS - Complete Server Configuration Workflow Validation
Test Started: 2025-11-19 15:30:45
==================================================================================================

----------------------------------------------------------------------------------------------------
API Call #1: auth-login
----------------------------------------------------------------------------------------------------
Request Parameters:
{
  "action": "auth-login",
  "username": "superadmin",
  "password": "password"
}

Response:
{
  "success": true,
  "authenticated": true,
  "code": 200,
  "data": {
    "tokens": {
      "access_token": "eyJhbGc..."
    }
  }
}
----------------------------------------------------------------------------------------------------

[0.456s] [SUCCESS] Authentication successful. Token: eyJhbGc...

==================================================================================================
CONFIGURATION 1: Standard Full Server Build
==================================================================================================
[1.567s] [INFO   ] Creating server: Standard Server Build #1
[1.567s] [DEBUG  ] Chassis UUID: 550e8400-e29b-41d4-a716-446655440000
[1.567s] [DEBUG  ] Motherboard UUID: 6ba7b810-9dad-11d1-80b4-00c04fd430c8

----------------------------------------------------------------------------------------------------
API Call #2: server-create
----------------------------------------------------------------------------------------------------
Request Parameters:
{
  "action": "server-create",
  "chassis_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "motherboard_uuid": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
  "server_name": "Standard Server Build #1"
}

Response:
{
  "success": true,
  "code": 200,
  "data": {
    "config_id": "123",
    "message": "Server configuration created successfully"
  }
}
----------------------------------------------------------------------------------------------------

==================================================================================================
SERVER CONFIGURATION SUMMARY - Config ID: 123
==================================================================================================
Server Name: Standard Server Build #1
Status: active

Components:
  - cpu: 2 item(s)
      * Intel Xeon Gold 6248R
      * Intel Xeon Gold 6248R
  - ram: 4 item(s)
      * Samsung 32GB DDR4-3200 ECC
      * Samsung 32GB DDR4-3200 ECC
      * Samsung 32GB DDR4-3200 ECC
      * Samsung 32GB DDR4-3200 ECC
  - storage: 2 item(s)
      * Samsung 970 EVO Plus 1TB NVMe
      * Seagate Exos 4TB HDD
  - nic: 1 item(s)
      * Intel X540-T2 Dual Port 10GbE
  - hbacard: 1 item(s)
      * LSI 9300-8i HBA
==================================================================================================

[15.678s] [SUCCESS] Configuration validation: PASSED

==================================================================================================
Test Completed: 2025-11-19 15:31:00
Total Duration: 15.68s
Total API Calls: 42
==================================================================================================
```

## Test Scenarios Explained

### Scenario 1: Standard Server Build

**Purpose**: Validate normal server build workflow

**Steps**:
1. Create server with chassis and motherboard
2. Get compatible CPUs → Add CPUs (single or dual)
3. Get compatible RAM → Add RAM modules
4. Get compatible storage → Add storage devices
5. Get compatible NICs → Add network card
6. Get compatible PCIe cards → Add if available
7. Get compatible HBA cards → Add if available
8. Validate complete configuration

**Expected Result**: Fully configured server with all component types

### Scenario 2: Riser Slot Edge Case

**Purpose**: Test motherboards that require riser cards for PCIe expansion

**Motherboard Characteristics**:
- Has riser slots defined in specifications
- Has NO direct PCIe slots
- Requires riser card installation to unlock PCIe functionality

**Steps**:
1. Create server with riser-only motherboard
2. Check compatibility for PCIe devices → **Should return empty/limited results**
3. Install riser card first
4. Re-check compatibility for NICs, HBA, PCIe cards → **Should now return available components**
5. Add PCIe-based components
6. Complete configuration

**Expected Result**:
- Initially, no PCIe devices available
- After riser installation, PCIe devices become available
- Final configuration includes riser + PCIe components

**Validation Points**:
```
✓ Initial PCIe compatibility check returns limited results
✓ Riser card is identified as compatible
✓ Riser card installation succeeds
✓ Post-riser compatibility check shows available PCIe devices
✓ PCIe devices can be added successfully
```

### Scenario 3: Onboard NIC Detection

**Purpose**: Validate automatic detection of integrated components

**Motherboard Characteristics**:
- Has `onboard_nic: true` in specifications
- Integrated network controller

**Steps**:
1. Create server with motherboard that has onboard NIC
2. Check configuration for onboard components → **Should show onboard NIC**
3. Verify onboard NIC appears without manual add action
4. Continue building with other components
5. Final configuration should include onboard NIC

**Expected Result**:
- Onboard NIC detected automatically
- Appears in configuration without manual `server-add-component` call
- Can still add additional discrete NICs if needed

**Validation Points**:
```
✓ Onboard NIC detected in initial configuration
✓ Onboard NIC appears in onboard_components section
✓ Onboard NIC counted in total NIC inventory
✓ Additional discrete NICs can still be added
```

## Interpreting Results

### Success Indicators

✅ **Authentication Success**
```
[SUCCESS] Authentication successful. Token: eyJhbGc...
```

✅ **Server Creation Success**
```
[SUCCESS] Server created successfully. Config ID: 123
```

✅ **Component Addition Success**
```
[SUCCESS] Successfully added cpu: Intel Xeon Gold 6248R
```

✅ **Validation Success**
```
[SUCCESS] Configuration validation: PASSED
```

### Failure Indicators

❌ **API Errors**
```
[ERROR] Failed to create server: Invalid chassis UUID
```

❌ **Compatibility Issues**
```
[WARNING] No compatible cpus available, skipping
```

❌ **Validation Errors**
```
[ERROR] Configuration validation: FAILED
  - Insufficient RAM for selected CPU
  - Missing required storage controller
```

## Common Issues and Solutions

### Issue: No Compatible Components Found

**Symptoms**:
```
[WARNING] Found 0 compatible cpus
[WARNING] No compatible cpus available, skipping
```

**Causes**:
- No components in inventory with status=1 (available)
- Component UUIDs not in JSON specification files
- Motherboard specs don't match any available components

**Solutions**:
1. Check component inventory: `SELECT * FROM cpuinventory WHERE status = 1`
2. Verify JSON files exist: `All-JSON/cpu-jsons/*.json`
3. Ensure UUID matching between inventory and JSON specs

### Issue: Riser Card Not Found

**Symptoms**:
```
[ERROR] No compatible riser cards found
```

**Causes**:
- No riser cards in pciecard inventory
- Riser card name doesn't contain "riser" keyword

**Solutions**:
1. Add riser card to inventory
2. Ensure name includes "riser" (case-insensitive)
3. Check compatibility logic in `FlexibleCompatibilityValidator.php`

### Issue: Onboard NIC Not Detected

**Symptoms**:
```
[INFO] No onboard components detected
```

**Causes**:
- Motherboard JSON doesn't have `onboard_nic: true`
- Compatibility engine not checking onboard components

**Solutions**:
1. Update motherboard JSON specifications
2. Verify `ComponentDataService` loads onboard specs
3. Check `ServerConfiguration::getOnboardComponents()`

## Script Architecture

### Class Structure

```
ServerBuildValidator (Main orchestrator)
  ├── IMSApiClient (API communication)
  │   ├── authenticate()
  │   ├── call()
  │   ├── createServer()
  │   ├── getCompatibility()
  │   ├── addComponent()
  │   └── validateConfiguration()
  │
  ├── TestLogger (Logging system)
  │   ├── log()
  │   ├── logApiCall()
  │   ├── logSection()
  │   ├── logConfigSummary()
  │   └── finalize()
  │
  └── ServerConfigurationBuilder (Build logic)
      ├── buildServer()
      ├── checkOnboardComponents()
      ├── installRiserCard()
      ├── verifyPCIeSlotsUnlocked()
      ├── addComponentsOfType()
      └── determineQuantity()
```

### Component Addition Logic

```php
foreach (component_types as type) {
    1. Get compatibility for type
    2. Check available slots
    3. Select components from compatible list
    4. Determine optimal quantity
    5. Add component(s) to server
    6. Re-check compatibility if needed
}
```

### Quantity Determination

- **CPU**: Up to 2 (dual-socket support)
- **RAM**: Half of available slots (minimum 4)
- **Storage**: 2-4 devices
- **NIC/HBA/PCIe**: 1 device per type

## Extending the Script

### Add New Component Type

```php
// In ServerConfigurationBuilder
private $componentTypes = [
    'cpu', 'ram', 'storage', 'nic',
    'pciecard', 'hbacard',
    'your_new_type'  // Add here
];
```

### Add Custom Validation

```php
// In ServerConfigurationBuilder::buildServer()
// After component addition
$this->performCustomValidation();

private function performCustomValidation() {
    // Your custom checks
    $config = $this->api->getConfiguration($this->configId);

    // Example: Ensure at least 64GB RAM
    $totalRAM = $this->calculateTotalRAM($config);
    if ($totalRAM < 64) {
        $this->logger->log("Insufficient RAM: {$totalRAM}GB", 'WARNING');
    }
}
```

### Add More Test Scenarios

```php
// In ServerBuildValidator::run()
$this->buildConfiguration4($chassis, $motherboards);

private function buildConfiguration4($chassis, $motherboards) {
    $this->logger->logSection("CONFIGURATION 4: Your Custom Test");

    // Custom test logic
    $this->builder->buildServer(
        $chassis[0]['uuid'],
        $motherboards[0]['uuid'],
        "Custom Test Server #4",
        ['custom_option' => true]
    );
}
```

## Integration with CI/CD

### GitHub Actions Example

```yaml
name: Server Build Validation

on: [push, pull_request]

jobs:
  validate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'

      - name: Start MySQL
        run: |
          sudo systemctl start mysql
          mysql -e "CREATE DATABASE bdc_ims_test;"
          mysql bdc_ims_test < shubhams_bdc_ims_main.sql

      - name: Start PHP Server
        run: php -S localhost:8000 &

      - name: Run Server Build Validation
        run: php tests/server_build_validation.php http://localhost:8000

      - name: Upload Test Logs
        if: always()
        uses: actions/upload-artifact@v2
        with:
          name: validation-logs
          path: tests/reports/server_build_validation_*.log
```

## Performance Considerations

### Typical Execution Time

- **Authentication**: ~0.5s
- **Component Listing**: ~0.3s per type
- **Server Creation**: ~1s per server
- **Compatibility Checks**: ~0.5s per component type
- **Component Addition**: ~0.8s per component

**Total for 3 Servers**: ~15-30 seconds (depending on number of components)

### Optimization Tips

1. **Reduce API Calls**: Cache component lists if running multiple tests
2. **Parallel Builds**: Run multiple configurations in parallel (requires threading)
3. **Selective Testing**: Use flags to test specific scenarios only

## Troubleshooting

### Enable Debug Mode

Add this to the script for more verbose output:

```php
// In TestLogger constructor
private $verbose = true;  // Already enabled by default
```

### Check API Responses

All API calls are logged in detail. Search log file for specific actions:

```bash
grep "server-create" tests/reports/server_build_validation_*.log
grep "ERROR" tests/reports/server_build_validation_*.log
```

### Verify Database State

After test completion, check database:

```sql
-- Check created configurations
SELECT * FROM server_configurations ORDER BY created_at DESC LIMIT 3;

-- Check component assignments
SELECT * FROM server_components WHERE config_id IN (
    SELECT id FROM server_configurations ORDER BY created_at DESC LIMIT 3
);
```

## Summary

This comprehensive test validates:
- ✅ Complete server build workflow
- ✅ Component compatibility logic
- ✅ Multi-unit component support
- ✅ Edge case: Riser slot motherboards
- ✅ Edge case: Onboard component detection
- ✅ API endpoint functionality
- ✅ Configuration validation
- ✅ Real-world server build scenarios

**Expected Outcome**: 3 fully configured servers with detailed logs demonstrating all compatibility rules work correctly.
