# Fix: Motherboard M.2 Slots and PCIe Lanes Always Showing Zero

## Problem
- `server-add-component` returning "No M.2 slots available on motherboard for M.2 storage"
- `server-get-config` showing `m2_slots` and `pcie_lanes` as zero even with motherboard added

## Root Cause Analysis

### Issue 1: Wrong Relative Path Depth in DataExtractionUtilities.php
- Location: `core/models/shared/DataExtractionUtilities.php:12-21`
- Problem: Paths used `../../` (2 levels) but should be `../../../` (3 levels)
- From `core/models/shared/` to project root requires 3 `..` segments
- Result: `getMotherboardByUUID()` always returned `null` because files weren't found

### Issue 2: Wrong Folder Names AND Path Depth in ComponentDataLoader.php
- Location: `core/models/components/ComponentDataLoader.php`
- **Lines 427, 476, 510, 554, 588** had TWO problems:
  1. Wrong folder names (e.g., `motherboard-jsons` instead of `motherboard`)
  2. Wrong path depth (`/../../` instead of `/../../../`)
- These were hardcoded paths that didn't match the correct paths in `getJSONFilePaths()` (lines 95-106)

### Issue 3: Wrong Folder Names AND Path Depth in server_api.php
- Location: `api/handlers/server/server_api.php`
- **Line 3750**: Used `All-JSON/motherboard-jsons/` - folder doesn't exist
- **Lines 5096-5101**: Used `All-JSON/` directory which doesn't exist
- Should use `resources/specifications/` directory

### Issue 4: Missing `storage` Key in extractMotherboardSpecifications()
- Location: `core/models/components/ComponentDataExtractor.php:714-721`
- Problem: `extractMotherboardSpecifications()` didn't include the `storage` key
- ComponentCompatibility.php checks `if ($mbSpecs && isset($mbSpecs['storage']))` at line 4223
- Without the `storage` key, it fell back to database parsing which only found SATA
- Result: Even with correct paths, M.2 slots were never detected during validation

## Files Modified

### 1. core/models/shared/DataExtractionUtilities.php:12-21
Changed all paths from `../../resources/specifications/` to `../../../resources/specifications/`

```php
// Before (wrong):
'motherboard' => __DIR__ . '/../../resources/specifications/motherboard/motherboard-level-3.json',

// After (correct):
'motherboard' => __DIR__ . '/../../../resources/specifications/motherboard/motherboard-level-3.json',
```

### 2. core/models/components/ComponentDataLoader.php
Fixed 6 hardcoded paths:

| Line | Before | After |
|------|--------|-------|
| 96 | `chassis/chassis-level-3.json` | `chassis/chasis-level-3.json` |
| 427 | `/../../...storage-jsons/` | `/../../../...storage/` |
| 476 | `/../../...motherboard-jsons/` | `/../../../...motherboard/` |
| 510 | `/../../...chassis-jsons/chassis-level-3.json` | `/../../../...chassis/chasis-level-3.json` |
| 554 | `/../../...chassis-jsons/chassis-level-3.json` | `/../../../...chassis/chasis-level-3.json` |
| 588 | `/../../...pci-jsons/pci-level-3.json` | `/../../../...pciecard/pci-level-3.json` |

### 3. api/handlers/server/server_api.php
Fixed 2 locations:

| Line | Before | After |
|------|--------|-------|
| 3750 | `All-JSON/motherboard-jsons/` | `resources/specifications/motherboard/` |
| 5096-5101 | `All-JSON/*-jsons/` | `resources/specifications/*/` |

### 4. core/models/compatibility/ComponentCompatibility.php:2974-2978
Updated error message strings to reflect correct paths (cosmetic fix)

### 5. core/models/components/ComponentDataExtractor.php:714-725
Added `storage` and `expansion_slots` keys to extractMotherboardSpecifications()

```php
// Before:
return [
    'storage_interfaces' => $this->extractMotherboardStorageInterfaces($model),
    'drive_bays' => $this->extractDriveBays($model),
    'pcie_slots' => $model['expansion_slots']['pcie_slots'] ?? [],
    'pcie_version' => $this->extractMotherboardPCIeVersion($model),
    'uuid' => $model['uuid']
];

// After:
return [
    'storage_interfaces' => $this->extractMotherboardStorageInterfaces($model),
    'drive_bays' => $this->extractDriveBays($model),
    'pcie_slots' => $model['expansion_slots']['pcie_slots'] ?? [],
    'pcie_version' => $this->extractMotherboardPCIeVersion($model),
    'uuid' => $model['uuid'],
    // Include storage section for M.2/U.2/NVMe slot detection
    'storage' => $model['storage'] ?? [],
    'expansion_slots' => $model['expansion_slots'] ?? []
];
```

## Summary

**Root Cause**: Multiple issues combined:
1. Wrong relative path depth (`../../` instead of `../../../`) in multiple files
2. Old/non-existent folder names (`*-jsons` instead of actual folder names)
3. Non-existent `All-JSON/` directory references
4. **Missing `storage` key** in motherboard specification extraction - this was the final blocker

**Impact**:
- Path issues → `getMotherboardByUUID()` returned null → M.2 slots showed 0 in config
- Missing `storage` key → Validation fell back to database parsing → Only SATA detected

**Fix**:
- Corrected all 11 path references across 4 files
- Added `storage` key to `extractMotherboardSpecifications()` return value

## Test
After these fixes:
1. `server-get-config` shows `m2_slots.motherboard.total: 4` ✓ (verified working)
2. `server-add-component` for M.2 storage should now work
