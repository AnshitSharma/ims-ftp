<?php
/**
 * Infrastructure Management System - Data Extraction Utilities
 * File: includes/models/DataExtractionUtilities.php
 * 
 * Comprehensive utilities for extracting component specifications from JSON files
 */

require_once __DIR__ . '/../components/ComponentSpecPaths.php';
require_once __DIR__ . '/../components/PlatformSpecIndex.php';

class DataExtractionUtilities {
    /**
     * Shared across every instance: ValidationEngine builds a fresh rule object per
     * evaluate() call and each rule constructs its own DataExtractionUtilities, so a
     * per-instance cache re-read and re-decoded every spec file on every evaluation.
     * Mirrors PlatformSpecIndex::$index / clearCache().
     */
    private static $jsonCache = [];
    private $cacheTimeout = 3600; // 1 hour
    private $paths = [];

    public function __construct(...$unused) {
        // paths are lazy-loaded on first use via getPaths()
    }

    private function getPaths(): array {
        if (empty($this->paths)) {
            $this->paths = ComponentSpecPaths::getAll();
        }
        return $this->paths;
    }

    /**
     * Load JSON data with caching
     */
    private function loadJsonData($type) {
        if (isset(self::$jsonCache[$type]) &&
            (time() - self::$jsonCache[$type]['timestamp']) < $this->cacheTimeout) {
            return self::$jsonCache[$type]['data'];
        }

        $path = $this->getPaths()[$type] ?? null;
        if (!$path || !file_exists($path)) {
            throw new Exception("JSON file not found for type: $type");
        }

        $jsonContent = file_get_contents($path);
        if ($jsonContent === false) {
            throw new Exception("Failed to read JSON file for type: $type");
        }

        $data = json_decode($jsonContent, true);
        if ($data === null) {
            throw new Exception("Invalid JSON in file for type: $type - " . json_last_error_msg());
        }

        self::$jsonCache[$type] = ['data' => $data, 'timestamp' => time()];

        return $data;
    }
    
    /**
     * Find component by UUID in JSON data
     *
     * A board or chassis that comes inside a server compute platform is described in the
     * platform catalog, not in motherboard-/chasis-level-3.json, so it is resolved FIRST
     * -- before the per-type finders below, which would look in the wrong file and return
     * null. Every validation rule reaches specs through this method (ResourceCatalog,
     * CpuSocketMatchRule, ... via $this->dataUtils), so without this lookup a platform
     * build cannot be validated at all. Shared with ComponentDataService so the two
     * resolvers cannot drift apart again -- see PlatformSpecIndex.
     */
    private function findComponentByUuid($type, $uuid) {
        $platformOwned = PlatformSpecIndex::find($type, $uuid);
        if ($platformOwned !== null) {
            return $platformOwned;
        }

        $data = $this->loadJsonData($type);

        switch ($type) {
            case 'chassis':
                return $this->findChassisInData($data, $uuid);
            case 'cpu':
                // CPU uses both 'UUID' and 'uuid' keys
                return $this->findInBrandModels($data, $uuid, true);
            case 'storage':
            case 'motherboard':
            case 'ram':
                return $this->findInBrandModels($data, $uuid);
            case 'pciecard':
            case 'risercard':
            case 'hbacard':
                return $this->findInCategoryModels($data, $uuid);
            case 'nic':
            case 'sfp':
                return $this->findNICInData($data, $uuid);
            case 'caddy':
                return $this->findCaddyInData($data, $uuid);
            default:
                return null;
        }
    }

    /**
     * Find chassis in chassis data structure (manufacturer→series→models)
     */
    private function findChassisInData($data, $uuid) {
        if (!isset($data['chassis_specifications']['manufacturers'])) {
            return null;
        }

        foreach ($data['chassis_specifications']['manufacturers'] as $manufacturer) {
            if (!isset($manufacturer['series'])) continue;
            foreach ($manufacturer['series'] as $series) {
                if (!isset($series['models'])) continue;
                foreach ($series['models'] as $model) {
                    if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                        $model['manufacturer'] = $manufacturer['manufacturer'] ?? null;
                        $model['series'] = $series['series_name'] ?? null;
                        return $model;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Generic finder for brand→models hierarchy (storage, motherboard, ram, cpu)
     */
    private function findInBrandModels($data, $uuid, $caseInsensitiveUuid = false) {
        foreach ($data as $brand) {
            if (!isset($brand['models'])) continue;
            foreach ($brand['models'] as $model) {
                $modelUuid = $caseInsensitiveUuid
                    ? ($model['UUID'] ?? $model['uuid'] ?? null)
                    : ($model['uuid'] ?? null);
                if ($modelUuid === $uuid) {
                    // Merge brand-level fields into model (brand, series, family)
                    foreach (['brand', 'series', 'family'] as $field) {
                        if (isset($brand[$field]) && !isset($model[$field])) {
                            $model[$field] = $brand[$field];
                        }
                    }
                    return $model;
                }
            }
        }
        return null;
    }

    /**
     * Generic finder for category→models hierarchy (pciecard, hbacard)
     * Merges category-level fields (component_subtype, brand, series) into model
     */
    private function findInCategoryModels($data, $uuid) {
        foreach ($data as $category) {
            if (!isset($category['models'])) continue;
            foreach ($category['models'] as $model) {
                if (isset($model['UUID']) && $model['UUID'] === $uuid) {
                    foreach (['component_subtype', 'brand', 'series'] as $field) {
                        if (isset($category[$field])) {
                            $model[$field] = $category[$field];
                        }
                    }
                    return $model;
                }
            }
        }
        return null;
    }

    /**
     * Find NIC in NIC data structure (brand→series→models)
     */
    private function findNICInData($data, $uuid) {
        foreach ($data as $brand) {
            if (!isset($brand['series'])) continue;
            foreach ($brand['series'] as $series) {
                if (!isset($series['models'])) continue;
                foreach ($series['models'] as $model) {
                    if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                        $model['brand'] = $brand['brand'] ?? null;
                        $model['series_name'] = $series['name'] ?? null;
                        return $model;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Find caddy in caddy data structure (top-level caddies array)
     */
    private function findCaddyInData($data, $uuid) {
        foreach ($data['caddies'] ?? [] as $caddy) {
            $caddyUuid = $caddy['uuid'] ?? $caddy['UUID'] ?? null;
            if ($caddyUuid === $uuid) {
                return $caddy;
            }
        }
        return null;
    }

    /**
     * Extract storage form factor
     */
    public function extractStorageFormFactor($storageUuid) {
        try {
            $storage = $this->findComponentByUuid('storage', $storageUuid);
            if (!$storage) {
                return 'unknown';
            }
            
            $formFactor = $storage['form_factor'] ?? 'unknown';
            
            // Normalize form factor names
            $normalized = [
                '3.5-inch' => '3.5_inch',
                '2.5-inch' => '2.5_inch', 
                'M.2 2280' => 'M.2_2280',
                'M.2 2260' => 'M.2_2260',
                'M.2 2242' => 'M.2_2242'
            ];
            
            return $normalized[$formFactor] ?? $formFactor;
            
        } catch (Exception $e) {
            error_log("Error extracting storage form factor: " . $e->getMessage());
            return 'unknown';
        }
    }
    
    /**
     * Extract storage interface information
     */
    public function extractStorageInterface($storageUuid) {
        try {
            $storage = $this->findComponentByUuid('storage', $storageUuid);
            if (!$storage) {
                return [
                    'type' => 'unknown',
                    'version' => null,
                    'connector_type' => 'unknown',
                    'pcie_lanes' => null
                ];
            }
            
            $interface = $storage['interface'] ?? 'unknown';
            
            // Parse interface information
            $type = 'unknown';
            $version = null;
            $connectorType = 'unknown';
            $pcieLanes = null;
            
            if (strpos($interface, 'SATA') !== false) {
                $type = 'SATA';
                $connectorType = 'SATA';
                if (strpos($interface, 'III') !== false) {
                    $version = '3.0';
                } else if (strpos($interface, 'II') !== false) {
                    $version = '2.0';
                }
            } else if (strpos($interface, 'SAS') !== false) {
                $type = 'SAS';
                $connectorType = 'SFF-8643';
                if (strpos($interface, '12Gb') !== false) {
                    $version = '3.0';
                } else if (strpos($interface, '6Gb') !== false) {
                    $version = '2.0';
                }
            } else if (strpos($interface, 'NVMe') !== false || strpos($interface, 'PCIe') !== false) {
                $type = 'NVMe';
                $connectorType = 'M.2_NVMe';
                $pcieLanes = 4; // Default for NVMe
                
                // Extract PCIe generation if available
                if (strpos($interface, 'Gen4') !== false || strpos($interface, '4.0') !== false) {
                    $version = '4.0';
                } else if (strpos($interface, 'Gen3') !== false || strpos($interface, '3.0') !== false) {
                    $version = '3.0';
                } else if (strpos($interface, 'Gen5') !== false || strpos($interface, '5.0') !== false) {
                    $version = '5.0';
                }
            }
            
            return [
                'type' => $type,
                'version' => $version,
                'connector_type' => $connectorType,
                'pcie_lanes' => $pcieLanes
            ];
            
        } catch (Exception $e) {
            error_log("Error extracting storage interface: " . $e->getMessage());
            return [
                'type' => 'unknown',
                'version' => null,
                'connector_type' => 'unknown',
                'pcie_lanes' => null
            ];
        }
    }
    
    /**
     * Extract PCIe lanes requirement for storage
     */
    public function extractStoragePCIeLanes($storageUuid) {
        try {
            $interface = $this->extractStorageInterface($storageUuid);
            
            if ($interface['type'] === 'NVMe' || $interface['type'] === 'PCIe') {
                return $interface['pcie_lanes'] ?? 4;
            }
            
            return null; // SATA/SAS don't use PCIe lanes directly
            
        } catch (Exception $e) {
            error_log("Error extracting storage PCIe lanes: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get component specifications by UUID and type
     */
    public function getComponentSpecifications($componentType, $componentUuid) {
        try {
            $component = $this->findComponentByUuid($componentType, $componentUuid);
            if (!$component) {
                return [
                    'found' => false,
                    'error' => "Component not found: $componentUuid"
                ];
            }
            
            return [
                'found' => true,
                'specifications' => $component
            ];
            
        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get storage by UUID (wrapper for findComponentByUuid)
     */
    public function getStorageByUUID($uuid) {
        return $this->findComponentByUuid('storage', $uuid);
    }

    /**
     * Get chassis specifications (wrapper)
     */
    public function getChassisSpecifications($uuid) {
        return $this->findComponentByUuid('chassis', $uuid);
    }

    /**
     * Get PCIe card by UUID (wrapper for findComponentByUuid)
     */
    public function getPCIeCardByUUID($uuid) {
        return $this->findComponentByUuid('pciecard', $uuid);
    }

    /**
     * Get riser card by UUID (wrapper for findComponentByUuid)
     *
     * Risers were split out of the 'pciecard' type on 2026-08-14; their specs
     * now live in ims-data/risercard/riser-level-3.json.
     */
    public function getRiserCardByUUID($uuid) {
        return $this->findComponentByUuid('risercard', $uuid);
    }

    /**
     * Get HBA card by UUID (wrapper for findComponentByUuid)
     */
    public function getHBACardByUUID($uuid) {
        return $this->findComponentByUuid('hbacard', $uuid);
    }

    /**
     * Get motherboard by UUID (wrapper for findComponentByUuid)
     */
    public function getMotherboardByUUID($uuid) {
        return $this->findComponentByUuid('motherboard', $uuid);
    }

    /**
     * Get CPU by UUID (wrapper for findComponentByUuid)
     */
    public function getCPUByUUID($uuid) {
        return $this->findComponentByUuid('cpu', $uuid);
    }

    /**
     * Get RAM by UUID (wrapper for findComponentByUuid)
     */
    public function getRAMByUUID($uuid) {
        return $this->findComponentByUuid('ram', $uuid);
    }

    /**
     * Get NIC by UUID (wrapper for findComponentByUuid)
     */
    public function getNICByUUID($uuid) {
        return $this->findComponentByUuid('nic', $uuid);
    }

    /**
     * Get Caddy by UUID (wrapper for findComponentByUuid)
     */
    public function getCaddyByUUID($uuid) {
        return $this->findComponentByUuid('caddy', $uuid);
    }

    /**
     * Get SFP by UUID (wrapper for findComponentByUuid)
     */
    public function getSFPByUUID($uuid) {
        return $this->findComponentByUuid('sfp', $uuid);
    }

    /**
     * Clear JSON cache
     */
    public static function clearCache() {
        self::$jsonCache = [];
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        $stats = [];
        foreach (self::$jsonCache as $type => $entry) {
            $stats[$type] = [
                'cached' => true,
                'timestamp' => $entry['timestamp'],
                'age_seconds' => time() - $entry['timestamp']
            ];
        }
        return $stats;
    }

    /**
     * Memory types a CPU declares, e.g. ["DDR5-4800"]. Returns [] when it
     * declares none.
     *
     * ims-data CPU records carry `memory_types` at the TOP LEVEL (see
     * cpu/Cpu-details-level-3.json); the legacy ComponentValidator path passed
     * raw JSON through under a `compatibility` wrapper, so rules were written
     * against `compatibility.memory_types` — a key no fixture has ever had.
     * Both shapes are accepted here so there is one contract to read.
     *
     * Deliberately does NOT fall back to ['DDR4'] the way extractMemoryTypes()
     * does: callers must treat [] as "cannot constrain", never as a generation.
     */
    public function getCpuMemoryTypes($cpuSpec) {
        if (!is_array($cpuSpec)) {
            return [];
        }

        // Three shapes in circulation: raw ims-data (top level), the legacy
        // ComponentValidator passthrough (`compatibility`), and
        // ComponentCompatibility::parseSpecifications()'s wrapper
        // (`compatibility_fields`). Only the first one ever holds data.
        $types = $cpuSpec['memory_types']
            ?? ($cpuSpec['compatibility']['memory_types']
            ?? ($cpuSpec['compatibility_fields']['memory_types'] ?? null));
        if (!is_array($types)) {
            $types = ($types === null || $types === '') ? [] : [$types];
        }

        $out = [];
        foreach ($types as $type) {
            if (is_string($type) && trim($type) !== '') {
                $out[] = trim($type);
            }
        }
        return $out;
    }

    /**
     * Extract memory types from component specs (CPU or Motherboard)
     */
    public function extractMemoryTypes($specs) {
        if (isset($specs['memory_types']) && is_array($specs['memory_types'])) {
            return $specs['memory_types'];
        }

        if (isset($specs['memory']['type'])) {
            return is_array($specs['memory']['type']) ? $specs['memory']['type'] : [$specs['memory']['type']];
        }

        return ['DDR4']; // Default fallback
    }

    /**
     * Extract ECC support requirement
     */
    public function extractECCSupport($specs) {
        if (isset($specs['ecc_support'])) {
            return $specs['ecc_support'];
        }

        if (isset($specs['memory']['ecc'])) {
            return $specs['memory']['ecc'] === 'required' ? 'required' : 'optional';
        }

        return 'optional';
    }

    /**
     * Extract PCIe lanes from CPU specs
     */
    public function extractPCIeLanes($specs) {
        if (isset($specs['pcie_lanes'])) {
            return $specs['pcie_lanes'];
        }

        if (isset($specs['pcie']['lanes'])) {
            return $specs['pcie']['lanes'];
        }

        return 20; // Default fallback
    }

    /**
     * Extract PCIe version
     */
    public function extractPCIeVersion($specs) {
        if (isset($specs['pcie_generation'])) {
            return $specs['pcie_generation'];
        }

        if (isset($specs['pcie']['generation'])) {
            return $specs['pcie']['generation'];
        }

        if (isset($specs['pcie_version'])) {
            // Parse version like "4.0" or "PCIe 4.0"
            preg_match('/(\d+\.?\d*)/', $specs['pcie_version'], $matches);
            return isset($matches[1]) ? floatval($matches[1]) : 4.0;
        }

        return 4.0; // Default fallback
    }

    /**
     * Extract memory form factor (DIMM, SO-DIMM, etc.)
     */
    public function extractMemoryFormFactor($specs) {
        $formFactor = null;

        // Check memory-specific form factor fields only
        if (isset($specs['memory']['form_factor'])) {
            $formFactor = $specs['memory']['form_factor'];
        } elseif (isset($specs['memory_form_factor'])) {
            $formFactor = $specs['memory_form_factor'];
        } elseif (isset($specs['module_type']) || isset($specs['memory_type'])) {
            // For RAM components, check form_factor at root level
            $formFactor = $specs['form_factor'] ?? null;
        } else {
            return null; // No memory form factor specified
        }

        if (!$formFactor) {
            return null;
        }

        // Normalize: "DIMM (288-pin)" → "DIMM"
        if (preg_match('/^([A-Z\-]+)(?:\s*\([^)]+\))?/i', $formFactor, $matches)) {
            return trim($matches[1]);
        }

        return $formFactor;
    }

    /**
     * Extract PCIe slots from motherboard specs
     */
    public function extractPCIeSlots($specs) {
        if (isset($specs['expansion_slots']['pcie_slots'])) {
            return $specs['expansion_slots']['pcie_slots'];
        }

        if (isset($specs['pcie_slots'])) {
            return $specs['pcie_slots'];
        }

        // Default fallback - typical ATX motherboard
        return [
            ['type' => 'PCIe x16', 'lanes' => 16, 'count' => 2, 'size' => 16],
            ['type' => 'PCIe x4', 'lanes' => 4, 'count' => 2, 'size' => 4],
            ['type' => 'PCIe x1', 'lanes' => 1, 'count' => 2, 'size' => 1]
        ];
    }
}
?>
