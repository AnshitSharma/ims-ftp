<?php
/**
 * Infrastructure Management System - Component Data Extraction
 * File: includes/models/ComponentDataExtractor.php
 *
 * Handles extraction of component specifications from JSON data
 * Extracted from ComponentCompatibility.php for better maintainability
 */

require_once __DIR__ . '/DataNormalizationUtils.php';

class ComponentDataExtractor {

    // ====================
    // SOCKET & CPU METHODS
    // ====================

    /**
     * Extract socket type from component data
     */
    public function extractSocketType($data, $componentType) {
        if ($componentType === 'cpu') {
            return $data['socket'] ?? $data['socket_type'] ?? null;
        } elseif ($componentType === 'motherboard') {
            // Handle nested socket object from JSON: {"socket": {"type": "LGA 4189", "count": 2}}
            if (isset($data['socket'])) {
                if (is_array($data['socket']) && isset($data['socket']['type'])) {
                    return $data['socket']['type'];
                } elseif (is_string($data['socket'])) {
                    return $data['socket'];
                }
            }
            return $data['socket_type'] ?? $data['cpu_socket'] ?? null;
        }
        return null;
    }

    /**
     * Extract TDP (Thermal Design Power) from component data
     */
    public function extractTDP($data) {
        return $data['tdp'] ?? $data['tdp_watts'] ?? $data['power_consumption'] ?? null;
    }

    /**
     * Extract maximum TDP supported
     */
    public function extractMaxTDP($data) {
        return $data['max_tdp'] ?? $data['max_cpu_tdp'] ?? 150; // Default assumption
    }

    /**
     * Extract socket type from notes field using pattern matching
     */
    public function extractSocketFromNotes($notes) {
        // Common socket patterns
        $socketPatterns = [
            '/\b(lga\s?4677)\b/i' => 'lga4677',
            '/\b(lga\s?4189)\b/i' => 'lga4189',
            '/\b(lga\s?3647)\b/i' => 'lga3647',
            '/\b(lga\s?2066)\b/i' => 'lga2066',
            '/\b(lga\s?1700)\b/i' => 'lga1700',
            '/\b(lga\s?1200)\b/i' => 'lga1200',
            '/\b(lga\s?1151)\b/i' => 'lga1151',
            '/\b(sp5)\b/i' => 'sp5',
            '/\b(sp3)\b/i' => 'sp3',
            '/\b(am5)\b/i' => 'am5',
            '/\b(am4)\b/i' => 'am4',
            '/\b(tr4)\b/i' => 'tr4',
            '/\b(strx4)\b/i' => 'strx4'
        ];

        foreach ($socketPatterns as $pattern => $socket) {
            if (preg_match($pattern, $notes)) {
                return $socket;
            }
        }

        return null;
    }

    // ================
    // MEMORY METHODS
    // ================

    /**
     * Extract supported memory types from component data
     */
    public function extractSupportedMemoryTypes($data, $componentType) {
        $types = null;

        // For motherboards, check the memory object first
        if ($componentType === 'motherboard' && isset($data['memory']) && is_array($data['memory'])) {
            $memoryType = $data['memory']['type'] ?? null;
            if ($memoryType) {
                // Return as array even if it's a single type
                $types = is_array($memoryType) ? $memoryType : [$memoryType];
            }
        }

        // For other components or fallback
        if (!$types) {
            $memoryTypes = $data['memory_types'] ?? $data['supported_memory'] ?? null;

            if (is_string($memoryTypes)) {
                $types = explode(',', $memoryTypes);
            } elseif (is_array($memoryTypes)) {
                $types = $memoryTypes;
            }
        }

        // Normalize all memory types to base DDR type (remove speed suffixes)
        if ($types && is_array($types)) {
            $normalizedTypes = [];
            foreach ($types as $type) {
                $normalized = DataNormalizationUtils::normalizeMemoryType(trim($type));
                if ($normalized) {
                    $normalizedTypes[] = $normalized;
                }
            }
            return !empty($normalizedTypes) ? array_unique($normalizedTypes) : null;
        }

        return null;
    }

    /**
     * Extract memory type from component data
     */
    public function extractMemoryType($data) {
        $type = $data['type'] ?? $data['memory_type'] ?? null;
        // Normalize to base DDR type (DDR5, DDR4, etc.) without speed suffix
        return DataNormalizationUtils::normalizeMemoryType($type);
    }

    /**
     * Extract memory speed/frequency
     */
    public function extractMemorySpeed($data) {
        return $data['speed'] ?? $data['frequency'] ?? $data['frequency_MHz'] ?? $data['memory_speed'] ?? null;
    }

    /**
     * Extract maximum memory speed supported
     */
    public function extractMaxMemorySpeed($data, $componentType = 'motherboard') {
        if ($componentType === 'cpu') {
            return $data['max_memory_speed'] ?? $data['memory_speed'] ?? null;
        }

        // For motherboards, check the memory object first
        if ($componentType === 'motherboard' && isset($data['memory']) && is_array($data['memory'])) {
            $maxFreq = $data['memory']['max_frequency_MHz'] ?? null;
            if ($maxFreq) {
                return $maxFreq;
            }
        }

        return $data['max_memory_speed'] ?? $data['memory_speed_max'] ?? null;
    }

    /**
     * Extract memory form factor (DIMM, SO-DIMM, etc.)
     */
    public function extractMemoryFormFactor($data) {
        // For motherboards, check the memory object first
        if (isset($data['memory']) && is_array($data['memory'])) {
            // Check if memory object has form_factor
            if (isset($data['memory']['form_factor'])) {
                return $data['memory']['form_factor'];
            }

            // Infer RAM form factor from motherboard memory type
            // Server/workstation motherboards with DDR4/DDR5 use DIMM
            // Desktop/laptop motherboards might use SO-DIMM (we'll default to DIMM for servers)
            $memoryType = $data['memory']['type'] ?? '';
            if (in_array($memoryType, ['DDR3', 'DDR4', 'DDR5'])) {
                // Server motherboards (EATX, ATX) use DIMM
                $mbFormFactor = $data['form_factor'] ?? '';
                if (in_array($mbFormFactor, ['EATX', 'ATX', 'E-ATX', 'SSI EEB'])) {
                    return 'DIMM';
                }
                // Mini-ITX and smaller might use SO-DIMM, but default to DIMM
                return 'DIMM';
            }
        }

        // For RAM modules, use the direct form_factor field
        // Handle variations like "DIMM (288-pin)" -> normalize to "DIMM"
        $formFactor = $data['form_factor'] ?? $data['memory_form_factor'] ?? 'DIMM';

        // Normalize form factor - extract just the base form factor type
        if (strpos($formFactor, 'DIMM') !== false) {
            if (strpos(strtoupper($formFactor), 'SO-DIMM') !== false || strpos(strtoupper($formFactor), 'SODIMM') !== false) {
                return 'SO-DIMM';
            }
            return 'DIMM';
        }

        return $formFactor;
    }

    /**
     * Extract supported RAM module types from motherboard specifications
     * Module types: RDIMM (Registered), LRDIMM (Load-Reduced), UDIMM (Unbuffered)
     *
     * @param array $data Motherboard JSON data
     * @return array|null Array of supported module types, or null if not specified
     */
    public function extractSupportedModuleTypes($data) {
        // DEBUG: Log extraction attempt
        error_log("DEBUG [extractSupportedModuleTypes] Starting extraction");
        error_log("DEBUG [extractSupportedModuleTypes] Has 'memory' key: " . (isset($data['memory']) ? 'YES' : 'NO'));

        // Check memory object for module_types array
        if (isset($data['memory']) && is_array($data['memory'])) {
            error_log("DEBUG [extractSupportedModuleTypes] memory section keys: " . json_encode(array_keys($data['memory'])));

            if (isset($data['memory']['module_types']) && is_array($data['memory']['module_types'])) {
                $result = array_map('strtoupper', $data['memory']['module_types']);
                error_log("DEBUG [extractSupportedModuleTypes] Found module_types array: " . json_encode($result));
                return $result;
            }

            // Check for single module_type string
            if (isset($data['memory']['module_type'])) {
                $result = [strtoupper($data['memory']['module_type'])];
                error_log("DEBUG [extractSupportedModuleTypes] Found module_type string: " . json_encode($result));
                return $result;
            }
        }

        // Check root level supported_module_types
        if (isset($data['supported_module_types'])) {
            if (is_array($data['supported_module_types'])) {
                $result = array_map('strtoupper', $data['supported_module_types']);
                error_log("DEBUG [extractSupportedModuleTypes] Found root level supported_module_types array: " . json_encode($result));
                return $result;
            }
            $result = [strtoupper($data['supported_module_types'])];
            error_log("DEBUG [extractSupportedModuleTypes] Found root level supported_module_types string: " . json_encode($result));
            return $result;
        }

        // Check root level module_types
        if (isset($data['module_types'])) {
            if (is_array($data['module_types'])) {
                $result = array_map('strtoupper', $data['module_types']);
                error_log("DEBUG [extractSupportedModuleTypes] Found root level module_types array: " . json_encode($result));
                return $result;
            }
            $result = [strtoupper($data['module_types'])];
            error_log("DEBUG [extractSupportedModuleTypes] Found root level module_types string: " . json_encode($result));
            return $result;
        }

        // No module type specification found - return null to indicate unknown support
        error_log("DEBUG [extractSupportedModuleTypes] No module types found - returning NULL");
        // This allows for backward compatibility with older JSON specs
        return null;
    }

    /**
     * Extract ECC support status
     */
    public function extractECCSupport($data) {
        return $data['ecc_support'] ?? $data['ecc'] ?? false;
    }

    // =================
    // STORAGE METHODS
    // =================

    /**
     * Extract storage interfaces from component data
     */
    public function extractStorageInterfaces($data) {
        $interfaces = $data['storage_interfaces'] ?? $data['interfaces'] ?? null;

        if (is_string($interfaces)) {
            return explode(',', $interfaces);
        } elseif (is_array($interfaces)) {
            return $interfaces;
        }

        return ['SATA']; // Default assumption
    }

    /**
     * Extract storage interface from storage component
     */
    public function extractStorageInterface($data) {
        return $data['interface'] ?? $data['interface_type'] ?? null;
    }

    /**
     * Extract storage form factor
     */
    public function extractStorageFormFactor($data) {
        return $data['form_factor'] ?? $data['size'] ?? null;
    }

    /**
     * Extract supported storage form factors
     */
    public function extractSupportedStorageFormFactors($data) {
        $formFactors = $data['storage_form_factors'] ?? $data['supported_drives'] ?? null;

        if (is_string($formFactors)) {
            return explode(',', $formFactors);
        } elseif (is_array($formFactors)) {
            return $formFactors;
        }

        return ['2.5"', '3.5"']; // Default assumption
    }

    /**
     * Extract storage specifications from model data
     */
    public function extractStorageSpecifications($model) {
        return [
            'interface_type' => $model['interface'] ?? 'Unknown',
            'form_factor' => $model['form_factor'] ?? 'Unknown',
            'storage_type' => $model['storage_type'] ?? 'Unknown',
            'subtype' => $model['subtype'] ?? null,
            'capacity_GB' => $model['capacity_GB'] ?? 0,
            'power_consumption' => $model['power_consumption_W'] ?? null,
            'specifications' => $model['specifications'] ?? [],
            'pcie_version' => $this->extractPCIeVersionFromInterface($model['interface'] ?? ''),
            'pcie_lanes' => $this->extractPCIeLanes($model['interface'] ?? ''),
            'uuid' => $model['uuid']
        ];
    }

    /**
     * Extract storage interfaces supported by motherboard
     */
    public function extractMotherboardStorageInterfaces($model) {
        $interfaces = [];

        if (isset($model['storage'])) {
            $storage = $model['storage'];

            if (isset($storage['sata']['ports']) && $storage['sata']['ports'] > 0) {
                $interfaces[] = 'SATA III';
                $interfaces[] = 'SATA';
            }

            if (isset($storage['sas']['ports']) && $storage['sas']['ports'] > 0) {
                $interfaces[] = 'SAS';
            }

            if (isset($storage['nvme']['m2_slots']) && !empty($storage['nvme']['m2_slots'])) {
                $interfaces[] = 'PCIe NVMe';
                $interfaces[] = 'NVMe';
            }

            if (isset($storage['nvme']['u2_slots']['count']) && $storage['nvme']['u2_slots']['count'] > 0) {
                $interfaces[] = 'U.2';
                $interfaces[] = 'PCIe NVMe 4.0';
            }
        }

        return $interfaces;
    }

    /**
     * Extract drive bays and form factor support
     */
    public function extractDriveBays($model) {
        $bays = [
            'sata_ports' => $model['storage']['sata']['ports'] ?? 0,
            'm2_slots' => [],
            'u2_slots' => $model['storage']['nvme']['u2_slots']['count'] ?? 0,
            'supported_form_factors' => []
        ];

        // Extract M.2 slot details
        if (isset($model['storage']['nvme']['m2_slots'])) {
            foreach ($model['storage']['nvme']['m2_slots'] as $m2Slot) {
                $bays['m2_slots'][] = [
                    'count' => $m2Slot['count'] ?? 1,
                    'form_factors' => $m2Slot['form_factors'] ?? ['M.2 2280'],
                    'pcie_lanes' => $m2Slot['pcie_lanes'] ?? 4,
                    'pcie_generation' => $m2Slot['pcie_generation'] ?? 4
                ];
            }
        }

        // Determine supported form factors
        if ($bays['sata_ports'] > 0) {
            $bays['supported_form_factors'][] = '2.5-inch';
            $bays['supported_form_factors'][] = '3.5-inch';
        }

        if (!empty($bays['m2_slots'])) {
            foreach ($bays['m2_slots'] as $slot) {
                $bays['supported_form_factors'] = array_merge($bays['supported_form_factors'], $slot['form_factors']);
            }
        }

        if ($bays['u2_slots'] > 0) {
            $bays['supported_form_factors'][] = '2.5-inch';
            $bays['supported_form_factors'][] = 'U.2';
        }

        return $bays;
    }

    /**
     * Extract PCIe version from storage interface string
     */
    public function extractPCIeVersionFromInterface($interface) {
        if (preg_match('/PCIe.*?(\d\.\d)/', $interface, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(\d)\.0/', $interface, $matches)) {
            return $matches[1] . '.0';
        }
        return null;
    }

    /**
     * Extract PCIe lanes from interface string
     */
    public function extractPCIeLanes($interface) {
        if (preg_match('/x(\d+)/', $interface, $matches)) {
            return (int)$matches[1];
        }
        return 4; // Default to x4 for NVMe
    }

    /**
     * Extract storage form factor from storage component specs
     */
    public function extractStorageFormFactorFromSpecs($storageData) {
        // Try form_factor field first
        if (isset($storageData['form_factor'])) {
            $formFactor = $storageData['form_factor'];

            // Normalize form factor naming
            if (strpos($formFactor, '3.5') !== false) {
                return '3.5_inch';
            } elseif (strpos($formFactor, '2.5') !== false) {
                return '2.5_inch';
            } elseif (strpos($formFactor, 'M.2') !== false) {
                return 'M.2';
            }
        }

        // Fallback to Notes field parsing
        $notes = $storageData['Notes'] ?? $storageData['notes'] ?? '';
        if (preg_match('/3\.5["\']?/i', $notes)) {
            return '3.5_inch';
        } elseif (preg_match('/2\.5["\']?/i', $notes)) {
            return '2.5_inch';
        } elseif (preg_match('/M\.2/i', $notes)) {
            return 'M.2';
        }

        // Default fallback based on storage type
        if (stripos($notes, 'HDD') !== false) {
            return '3.5_inch'; // HDDs are typically 3.5"
        } elseif (stripos($notes, 'SSD') !== false) {
            return '2.5_inch'; // SSDs are typically 2.5"
        }

        return 'unknown';
    }

    // ==============
    // PCIE METHODS
    // ==============

    /**
     * Extract PCIe version from component data
     */
    public function extractPCIeVersion($data, $componentType) {
        return $data['pcie_version'] ?? $data['pci_version'] ?? null;
    }

    /**
     * Extract PCIe slots configuration
     */
    public function extractPCIeSlots($data) {
        $slots = $data['pcie_slots'] ?? $data['expansion_slots'] ?? null;

        if (is_string($slots)) {
            // Parse string like "PCIe x16,PCIe x8,PCIe x4,PCIe x1"
            $slotArray = explode(',', $slots);
            return array_map('trim', $slotArray);
        } elseif (is_array($slots)) {
            return $slots;
        }

        return ['PCIe x16', 'PCIe x1']; // Default assumption
    }

    /**
     * Extract PCIe requirement from component
     */
    public function extractPCIeRequirement($data) {
        return $data['pcie_requirement'] ?? $data['slot_requirement'] ?? 'PCIe x1';
    }

    /**
     * Extract PCIe generation from card data
     * Parses "PCIe 3.0 x8" → returns 3
     */
    public function extractPCIeGeneration($pcieCardData) {
        $interface = $pcieCardData['interface'] ?? '';

        // Match patterns: "PCIe 3.0", "PCIe Gen4", "PCIe 5.0", "PCIe 3.0/4.0"
        if (preg_match('/PCIe\s+(?:Gen\s*)?([3-5])(?:\\.0)?/i', $interface, $matches)) {
            return (int)$matches[1];
        }

        return null; // Unknown generation
    }

    /**
     * Extract PCIe slot size from card data
     * Parses "PCIe 3.0 x8" → returns 8
     */
    public function extractPCIeSlotSize($pcieCardData) {
        $interface = $pcieCardData['interface'] ?? '';

        // Match pattern: "x8", "x16", etc.
        if (preg_match('/x(\d+)/i', $interface, $matches)) {
            return (int)$matches[1];
        }

        // Fallback: check slot_type for riser cards
        $slotType = $pcieCardData['slot_type'] ?? '';
        if (preg_match('/x(\d+)/i', $slotType, $matches)) {
            return (int)$matches[1];
        }

        return 16; // Default assumption for unknown cards
    }

    /**
     * Extract PCIe slots configuration from motherboard data
     */
    public function extractMotherboardPCIeSlots($motherboardData) {
        $slots = [
            'total' => 0,
            'by_size' => ['x1' => 0, 'x4' => 0, 'x8' => 0, 'x16' => 0],
            'generation' => null
        ];

        if (!isset($motherboardData['expansion_slots']['pcie_slots'])) {
            return $slots;
        }

        foreach ($motherboardData['expansion_slots']['pcie_slots'] as $slotType) {
            $count = $slotType['count'] ?? 0;
            $lanes = $slotType['lanes'] ?? 0;
            $type = $slotType['type'] ?? '';

            // Extract generation from first slot
            if ($slots['generation'] === null) {
                if (preg_match('/PCIe\s+([3-5])(?:\\.0)?/i', $type, $matches)) {
                    $slots['generation'] = (int)$matches[1];
                }
            }

            // Count slots by size
            $slotKey = 'x' . $lanes;
            if (isset($slots['by_size'][$slotKey])) {
                $slots['by_size'][$slotKey] += $count;
            }

            $slots['total'] += $count;
        }

        return $slots;
    }

    /**
     * Extract motherboard PCIe version
     */
    public function extractMotherboardPCIeVersion($model) {
        if (isset($model['expansion_slots']['pcie_slots'])) {
            foreach ($model['expansion_slots']['pcie_slots'] as $slot) {
                if (preg_match('/PCIe\s+(\d+\.\d+)/', $slot['type'], $matches)) {
                    return $matches[1];
                }
            }
        }
        return '4.0'; // Default assumption
    }

    /**
     * Extract additional slots provided by riser card
     */
    public function extractRiserCardSlots($pcieCardData) {
        $subtype = $pcieCardData['component_subtype'] ?? '';
        if (stripos($subtype, 'riser') === false) {
            return 0;
        }

        // Riser cards have "pcie_slots" field indicating how many slots they add
        return $pcieCardData['pcie_slots'] ?? 1;
    }

    // =================
    // CHASSIS METHODS
    // =================

    /**
     * Extract critical chassis specifications from JSON model
     */
    public function extractChassisSpecifications($model) {
        return [
            'drive_bays' => $model['drive_bays'] ?? [],
            'backplane' => $model['backplane'] ?? [],
            'motherboard_compatibility' => $model['motherboard_compatibility'] ?? [],
            'form_factor' => $model['form_factor'] ?? 'Unknown',
            'chassis_type' => $model['chassis_type'] ?? 'Unknown',
            'expansion_slots' => $model['expansion_slots'] ?? [],
            'power_supply' => $model['power_supply'] ?? [],
            'dimensions' => $model['dimensions'] ?? [],
            'uuid' => $model['uuid']
        ];
    }

    /**
     * Extract chassis bay configuration
     */
    public function extractChassisBayConfiguration($chassisSpecs) {
        if (!isset($chassisSpecs['drive_bays']['bay_configuration'])) {
            return [];
        }

        $bayCapacity = [];
        foreach ($chassisSpecs['drive_bays']['bay_configuration'] as $bayConfig) {
            $bayType = $bayConfig['bay_type'] ?? 'unknown';
            $count = $bayConfig['count'] ?? 0;

            $bayCapacity[$bayType] = ($bayCapacity[$bayType] ?? 0) + $count;
        }

        return $bayCapacity;
    }

    /**
     * Extract chassis motherboard form factor compatibility
     */
    public function extractChassisMotherboardCompatibility($chassisSpecs) {
        if (!isset($chassisSpecs['motherboard_compatibility'])) {
            return ['form_factors' => ['ATX']]; // Default assumption
        }

        return [
            'form_factors' => $chassisSpecs['motherboard_compatibility']['form_factors'] ?? ['ATX'],
            'mounting_points' => $chassisSpecs['motherboard_compatibility']['mounting_points'] ?? 'standard_atx',
            'max_size' => $chassisSpecs['motherboard_compatibility']['max_motherboard_size'] ?? null
        ];
    }

    // ================
    // GENERAL METHODS
    // ================

    /**
     * Extract power consumption from component data
     */
    public function extractPowerConsumption($data) {
        return $data['power_consumption'] ?? $data['power'] ?? $data['watts'] ?? null;
    }

    /**
     * Extract supported form factors
     */
    public function extractSupportedFormFactors($data) {
        // Try standard fields first
        $formFactors = $data['supported_form_factors'] ?? $data['form_factors'] ?? null;

        // For caddies, check compatibility.size field
        if (!$formFactors && isset($data['compatibility']['size'])) {
            $formFactors = $data['compatibility']['size'];
        }

        if (is_string($formFactors)) {
            // Return as array with single element
            return [trim($formFactors)];
        } elseif (is_array($formFactors)) {
            return $formFactors;
        }

        return ['2.5-inch', '3.5-inch']; // Default assumption (updated format)
    }

    /**
     * Extract supported interfaces
     */
    public function extractSupportedInterfaces($data) {
        $interfaces = $data['supported_interfaces'] ?? $data['interfaces'] ?? null;

        if (is_string($interfaces)) {
            return explode(',', $interfaces);
        } elseif (is_array($interfaces)) {
            return $interfaces;
        }

        return ['SATA']; // Default assumption
    }

    /**
     * Extract motherboard specifications relevant to storage
     */
    public function extractMotherboardSpecifications($model) {
        return [
            'storage_interfaces' => $this->extractMotherboardStorageInterfaces($model),
            'drive_bays' => $this->extractDriveBays($model),
            'pcie_slots' => $model['expansion_slots']['pcie_slots'] ?? [],
            'pcie_version' => $this->extractMotherboardPCIeVersion($model),
            'uuid' => $model['uuid']
        ];
    }
}
?>
