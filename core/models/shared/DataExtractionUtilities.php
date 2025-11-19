<?php
/**
 * Infrastructure Management System - Data Extraction Utilities
 * File: includes/models/DataExtractionUtilities.php
 * 
 * Comprehensive utilities for extracting component specifications from JSON files
 */

class DataExtractionUtilities {
    private $jsonCache = [];
    private $cacheTimeout = 3600; // 1 hour
    private $paths = [
        'storage' => __DIR__ . '/../../resources/specifications/storage-jsons/storage-level-3.json',
        'motherboard' => __DIR__ . '/../../resources/specifications/motherboard-jsons/motherboard-level-3.json',
        'chassis' => __DIR__ . '/../../resources/specifications/chassis-jsons/chassis-level-3.json',
        'cpu' => __DIR__ . '/../../resources/specifications/cpu-jsons/Cpu-details-level-3.json',
        'ram' => __DIR__ . '/../../resources/specifications/Ram-jsons/ram_detail.json',
        'pciecard' => __DIR__ . '/../../resources/specifications/pci-jsons/pci-level-3.json'
    ];
    
    /**
     * Load JSON data with caching
     */
    private function loadJsonData($type) {
        $cacheKey = $type . '_data';
        
        // Check cache validity
        if (isset($this->jsonCache[$cacheKey]) && 
            isset($this->jsonCache[$cacheKey . '_timestamp']) &&
            (time() - $this->jsonCache[$cacheKey . '_timestamp']) < $this->cacheTimeout) {
            return $this->jsonCache[$cacheKey];
        }
        
        $path = $this->paths[$type] ?? null;
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
        
        // Cache the data
        $this->jsonCache[$cacheKey] = $data;
        $this->jsonCache[$cacheKey . '_timestamp'] = time();
        
        return $data;
    }
    
    /**
     * Find component by UUID in JSON data
     */
    private function findComponentByUuid($type, $uuid) {
        $data = $this->loadJsonData($type);
        
        // Handle different JSON structures
        switch ($type) {
            case 'chassis':
                return $this->findChassisInData($data, $uuid);
            case 'storage':
                return $this->findStorageInData($data, $uuid);
            case 'motherboard':
                return $this->findMotherboardInData($data, $uuid);
            case 'cpu':
                return $this->findCpuInData($data, $uuid);
            case 'ram':
                return $this->findRamInData($data, $uuid);
            case 'pciecard':
                return $this->findPCIeCardInData($data, $uuid);
            default:
                return null;
        }
    }
    
    /**
     * Find chassis in chassis data structure
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
                        return $model;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Find storage in storage data structure
     */
    private function findStorageInData($data, $uuid) {
        foreach ($data as $brand) {
            if (!isset($brand['models'])) continue;
            
            foreach ($brand['models'] as $model) {
                if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                    return $model;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Find motherboard in motherboard data structure
     */
    private function findMotherboardInData($data, $uuid) {
        foreach ($data as $brand) {
            if (!isset($brand['models'])) continue;
            
            foreach ($brand['models'] as $model) {
                if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                    return $model;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Find CPU in CPU data structure
     */
    private function findCpuInData($data, $uuid) {
        // CPU JSON structure: brand → models array with UUID field
        foreach ($data as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    // Check both 'UUID' and 'uuid' for case-insensitive matching
                    $modelUuid = $model['UUID'] ?? $model['uuid'] ?? null;
                    if ($modelUuid === $uuid) {
                        return $model;
                    }
                }
            }
        }

        return null;
    }
    
    /**
     * Find RAM in RAM data structure
     */
    private function findRamInData($data, $uuid) {
        // RAM JSON structure may vary - implement based on actual structure
        foreach ($data as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                        return $model;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find PCIe card in PCI data structure
     */
    private function findPCIeCardInData($data, $uuid) {
        // PCIe JSON structure: array of categories with models
        // component_subtype is at category level, must merge it into model data
        foreach ($data as $category) {
            if (isset($category['models'])) {
                foreach ($category['models'] as $model) {
                    if (isset($model['UUID']) && $model['UUID'] === $uuid) {
                        // Merge category-level data (like component_subtype) into model
                        if (isset($category['component_subtype'])) {
                            $model['component_subtype'] = $category['component_subtype'];
                        }
                        if (isset($category['brand'])) {
                            $model['brand'] = $category['brand'];
                        }
                        if (isset($category['series'])) {
                            $model['series'] = $category['series'];
                        }
                        return $model;
                    }
                }
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
     * Extract motherboard storage connectors
     */
    public function extractMotherboardStorageConnectors($motherboardUuid) {
        try {
            $motherboard = $this->findComponentByUuid('motherboard', $motherboardUuid);
            if (!$motherboard) {
                return [];
            }
            
            $connectors = [];
            
            // Extract SATA ports
            if (isset($motherboard['storage']['sata_ports'])) {
                $sataCount = $motherboard['storage']['sata_ports'];
                for ($i = 0; $i < $sataCount; $i++) {
                    $connectors[] = 'SATA';
                }
            }
            
            // Extract M.2 slots
            if (isset($motherboard['storage']['m2_slots'])) {
                foreach ($motherboard['storage']['m2_slots'] as $slot) {
                    $connectors[] = 'M.2_NVMe';
                }
            }
            
            // Extract SAS connectors
            if (isset($motherboard['storage']['sas_connectors'])) {
                foreach ($motherboard['storage']['sas_connectors'] as $connector) {
                    $connectors[] = 'SFF-8643';
                }
            }
            
            // Extract PCIe slots that can be used for storage controllers
            if (isset($motherboard['expansion_slots']['pcie_slots'])) {
                foreach ($motherboard['expansion_slots']['pcie_slots'] as $slot) {
                    if ($slot['lanes'] >= 4) { // Minimum for NVMe
                        for ($i = 0; $i < $slot['count']; $i++) {
                            $connectors[] = 'PCIe_x' . $slot['lanes'];
                        }
                    }
                }
            }
            
            return array_unique($connectors);
            
        } catch (Exception $e) {
            error_log("Error extracting motherboard storage connectors: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Extract motherboard PCIe lane availability
     */
    public function extractMotherboardPCIeLanes($motherboardUuid) {
        try {
            $motherboard = $this->findComponentByUuid('motherboard', $motherboardUuid);
            if (!$motherboard) {
                return [
                    'total' => 0,
                    'available' => 0,
                    'slots' => []
                ];
            }
            
            $totalLanes = 0;
            $availableLanes = 0;
            $slots = [];
            
            if (isset($motherboard['expansion_slots']['pcie_slots'])) {
                foreach ($motherboard['expansion_slots']['pcie_slots'] as $slotType) {
                    $lanes = $slotType['lanes'];
                    $count = $slotType['count'];
                    
                    $totalLanes += ($lanes * $count);
                    $availableLanes += ($lanes * $count); // Assume all available initially
                    
                    $slots[] = [
                        'type' => $slotType['type'],
                        'lanes' => $lanes,
                        'count' => $count,
                        'total_lanes' => $lanes * $count
                    ];
                }
            }
            
            return [
                'total' => $totalLanes,
                'available' => $availableLanes,
                'slots' => $slots
            ];
            
        } catch (Exception $e) {
            error_log("Error extracting motherboard PCIe lanes: " . $e->getMessage());
            return [
                'total' => 0,
                'available' => 0,
                'slots' => []
            ];
        }
    }
    
    /**
     * Extract storage controllers from motherboard
     */
    public function extractMotherboardStorageControllers($motherboardUuid) {
        try {
            $motherboard = $this->findComponentByUuid('motherboard', $motherboardUuid);
            if (!$motherboard) {
                return [];
            }
            
            $controllers = [];
            
            // Extract integrated storage controllers
            if (isset($motherboard['storage'])) {
                $storage = $motherboard['storage'];
                
                if (!empty($storage['sata_ports'])) {
                    $controllers[] = [
                        'type' => 'SATA',
                        'version' => $storage['sata_version'] ?? '3.0',
                        'ports' => $storage['sata_ports']
                    ];
                }
                
                if (!empty($storage['m2_slots'])) {
                    $controllers[] = [
                        'type' => 'NVMe',
                        'version' => $storage['nvme_version'] ?? '1.3',
                        'slots' => count($storage['m2_slots'])
                    ];
                }
                
                if (!empty($storage['sas_connectors'])) {
                    $controllers[] = [
                        'type' => 'SAS',
                        'version' => $storage['sas_version'] ?? '3.0',
                        'connectors' => count($storage['sas_connectors'])
                    ];
                }
            }
            
            return $controllers;
            
        } catch (Exception $e) {
            error_log("Error extracting motherboard storage controllers: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Extract NVMe support information from motherboard
     */
    public function extractMotherboardNVMeSupport($motherboardUuid) {
        try {
            $motherboard = $this->findComponentByUuid('motherboard', $motherboardUuid);
            if (!$motherboard) {
                return [
                    'supported' => false,
                    'm2_slots' => 0,
                    'pcie_slots' => 0,
                    'max_pcie_lanes' => 0
                ];
            }
            
            $m2Slots = 0;
            $pcieSlots = 0;
            $maxPcieLanes = 0;
            
            // Count M.2 slots
            if (isset($motherboard['storage']['m2_slots'])) {
                $m2Slots = count($motherboard['storage']['m2_slots']);
            }
            
            // Count PCIe slots suitable for NVMe (x4 or higher)
            if (isset($motherboard['expansion_slots']['pcie_slots'])) {
                foreach ($motherboard['expansion_slots']['pcie_slots'] as $slot) {
                    if ($slot['lanes'] >= 4) {
                        $pcieSlots += $slot['count'];
                        $maxPcieLanes = max($maxPcieLanes, $slot['lanes']);
                    }
                }
            }
            
            $supported = ($m2Slots > 0) || ($pcieSlots > 0);
            
            return [
                'supported' => $supported,
                'm2_slots' => $m2Slots,
                'pcie_slots' => $pcieSlots,
                'max_pcie_lanes' => $maxPcieLanes
            ];
            
        } catch (Exception $e) {
            error_log("Error extracting motherboard NVMe support: " . $e->getMessage());
            return [
                'supported' => false,
                'm2_slots' => 0,
                'pcie_slots' => 0,
                'max_pcie_lanes' => 0
            ];
        }
    }
    
    /**
     * Extract SATA/SAS port count from motherboard
     */
    public function extractMotherboardSATASASPorts($motherboardUuid) {
        try {
            $motherboard = $this->findComponentByUuid('motherboard', $motherboardUuid);
            if (!$motherboard) {
                return [
                    'sata_ports' => 0,
                    'sas_ports' => 0,
                    'total_ports' => 0
                ];
            }
            
            $sataPorts = $motherboard['storage']['sata_ports'] ?? 0;
            $sasPorts = 0;
            
            if (isset($motherboard['storage']['sas_connectors'])) {
                $sasPorts = count($motherboard['storage']['sas_connectors']);
            }
            
            return [
                'sata_ports' => $sataPorts,
                'sas_ports' => $sasPorts,
                'total_ports' => $sataPorts + $sasPorts
            ];
            
        } catch (Exception $e) {
            error_log("Error extracting motherboard SATA/SAS ports: " . $e->getMessage());
            return [
                'sata_ports' => 0,
                'sas_ports' => 0,
                'total_ports' => 0
            ];
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
     * Clear JSON cache
     */
    public function clearCache() {
        $this->jsonCache = [];
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        $stats = [];
        foreach ($this->jsonCache as $key => $value) {
            if (strpos($key, '_timestamp') === false) {
                $timestampKey = $key . '_timestamp';
                $stats[$key] = [
                    'cached' => true,
                    'timestamp' => $this->jsonCache[$timestampKey] ?? 0,
                    'age_seconds' => time() - ($this->jsonCache[$timestampKey] ?? time())
                ];
            }
        }

        return $stats;
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
     * Extract maximum memory frequency
     */
    public function extractMaxMemoryFrequency($specs) {
        if (isset($specs['memory']['max_frequency_MHz'])) {
            return $specs['memory']['max_frequency_MHz'];
        }

        if (isset($specs['max_memory_frequency'])) {
            return $specs['max_memory_frequency'];
        }

        return 3200; // Default fallback
    }

    /**
     * Extract maximum memory capacity
     */
    public function extractMaxMemoryCapacity($specs) {
        if (isset($specs['max_memory_capacity_TB'])) {
            return $specs['max_memory_capacity_TB'] * 1024; // Convert TB to GB
        }

        if (isset($specs['memory']['max_capacity_GB'])) {
            return $specs['memory']['max_capacity_GB'];
        }

        if (isset($specs['max_memory_capacity'])) {
            return $specs['max_memory_capacity'];
        }

        return 128; // Default fallback
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
     * Extract per-slot memory capacity
     */
    public function extractPerSlotMemoryCapacity($specs) {
        if (isset($specs['memory']['max_per_slot_GB'])) {
            return $specs['memory']['max_per_slot_GB'];
        }

        if (isset($specs['max_per_slot_capacity'])) {
            return $specs['max_per_slot_capacity'];
        }

        return 32; // Default fallback
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