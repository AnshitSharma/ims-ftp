<?php
/**
 * Infrastructure Management System - Component Compatibility Engine
 * File: includes/models/ComponentCompatibility.php
 *
 * REFACTORED ARCHITECTURE (2025-11-13)
 * =====================================
 * This class now follows Single Responsibility Principle with delegated concerns:
 *
 * CORE RESPONSIBILITIES:
 * - Component pair compatibility checking (CPU-Motherboard, RAM-Motherboard, etc.)
 * - Decentralized compatibility validation for server configurations
 * - Cross-component compatibility analysis and scoring
 * - Compatibility recommendations and issue detection
 *
 * DELEGATED CONCERNS (via Dependency Injection):
 * 1. DataNormalizationUtils (Static Utility)
 *    - Normalizes memory types, form factors, storage interfaces
 *    - Extracts PCIe generations, memory generations
 *    - Determines storage connection paths
 *
 * 2. ComponentDataExtractor
 *    - Extracts specifications from JSON component data
 *    - 36 specialized extraction methods for different component types
 *    - Handles socket types, memory specs, storage specs, PCIe requirements
 *
 * 3. ComponentDataLoader
 *    - Loads component data from JSON files and database
 *    - Manages JSON data caching for performance
 *    - 15 data loading methods for different component types
 *    - Provides cache management (clearCache, getCacheStats)
 *
 * 4. ComponentValidator
 *    - Validates component existence in JSON specifications
 *    - Performs component-specific validation (CPU, RAM, storage, NIC, etc.)
 *    - 28 validation and parsing methods
 *    - Tracks used slots (memory, PCIe, storage interfaces)
 *
 * WHAT THIS CLASS IS NOW (2026-09-16): a pairwise compatibility checker for
 * ticket validation, and nothing else.
 *
 * It used to be 5,434 lines holding a second, complete compatibility engine
 * alongside the rule-based ValidationEngine. That half was reachable only
 * through ServerBuilder's `$engineVerdicts === null` branch, and
 * evaluateCandidatesWithEngine() has no null return path, so it had not run in
 * production for some time. 75 methods (~4,100 lines) were deleted after
 * confirming `verdict_source` reads 'validation_engine' for every component
 * type on a live build, and that all 11 server-get-compatible responses were
 * byte-identical before and after.
 *
 * Deleted with it: the eight check*DecentralizedCompatibility() methods, the
 * analyzeExisting..., apply...CompatibilityRules and
 * create...CompatibilitySummary
 * families, analyzeMemoryFrequency(), getMotherboardLimits(),
 * calculateRequiredBays() and the scoring/recommendation helpers. Docblocks
 * elsewhere (the validation rules, ResourceCatalog) cite those methods and
 * their old line numbers as PROVENANCE for rules that replaced them -- those
 * citations describe the pre-2026-09-16 file and are recoverable from git
 * history. They are history, not live references.
 *
 * THE LIVE SURFACE: TicketValidator is the only external consumer of the
 * pairwise path -- canComponentTypesBeCompatible() then
 * checkComponentPairCompatibility(), which dispatches through
 * getCompatibilityMethod()'s map to seven check*Compatibility() methods BY
 * NAME. That dynamic dispatch is why those seven cannot be found by searching
 * for callers; do not delete a method here without checking that map.
 * ServerBuilder also calls validateComponentExistsInJSON().
 *
 * USAGE EXAMPLE:
 * ```php
 * $compatibility = new ComponentCompatibility($pdo);
 *
 * // Check pair compatibility (the live path)
 * $result = $compatibility->checkComponentPairCompatibility($cpu, $motherboard);
 * ```
 */

require_once __DIR__ . '/../shared/DataNormalizationUtils.php';
require_once __DIR__ . '/../components/ComponentDataExtractor.php';
require_once __DIR__ . '/../components/ComponentDataLoader.php';
require_once __DIR__ . '/../components/ComponentValidator.php';
require_once __DIR__ . '/NICPortTracker.php';

class ComponentCompatibility {
    private $pdo;                  // Database connection
    private $dataExtractor;        // Component data extraction helper
    private $dataLoader;           // Component data loading helper (with caching)
    private $validator;            // Component validation helper
    private $validationCache = []; // Cache for compatibility validation results

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->dataExtractor = new ComponentDataExtractor();
        $this->dataLoader = new ComponentDataLoader($pdo, $this->dataExtractor);
        $this->validator = new ComponentValidator($pdo, $this->dataLoader, $this->dataExtractor);
    }

    /**
     * Validate component exists in JSON specifications
     * Delegates to ComponentDataLoader
     * @param string $componentType Component type (cpu, motherboard, ram, etc.)
     * @param string $uuid Component UUID
     * @return bool True if component exists in JSON
     */
    public function validateComponentExistsInJSON($componentType, $uuid) {
        return $this->dataLoader->validateComponentExistsInJSON($componentType, $uuid);
    }








    /**
     * Validate CPU exists in JSON
     * Delegates to ComponentValidator
     * @param string $cpuUuid CPU UUID
     * @return array Validation result
     */
    public function validateCPUExists($cpuUuid) {
        return $this->validator->validateCPUExists($cpuUuid);
    }








    /**
     * Check compatibility between two specific components - ENHANCED with proper validation
     */
    public function checkComponentPairCompatibility($component1, $component2) {
        $type1 = $component1['type'];
        $type2 = $component2['type'];
        
        // Enhanced cross-component compatibility checking
        if (($type1 === 'cpu' && $type2 === 'motherboard') || ($type1 === 'motherboard' && $type2 === 'cpu')) {
            $cpu = $type1 === 'cpu' ? $component1 : $component2;
            $motherboard = $type1 === 'motherboard' ? $component1 : $component2;
            
            // Get motherboard specifications
            $mbSpecsResult = $this->validator->parseMotherboardSpecifications($motherboard['uuid']);
            if (!$mbSpecsResult['found']) {
                return [
                    'compatible' => false,
                    'issues' => [$mbSpecsResult['error']],
                    'warnings' => [],
                    'recommendations' => ['Ensure motherboard exists in JSON specifications']
                ];
            }

            $mbLimits = $this->convertSpecsToLimits($mbSpecsResult['specifications']);
            $socketResult = $this->validator->validateCPUSocketCompatibility($cpu['uuid'], $mbLimits);

            if (!$socketResult['compatible']) {
                return [
                    'compatible' => false,
                    'issues' => [$socketResult['error']],
                    'warnings' => [],
                    'recommendations' => ['Use CPU and motherboard with matching socket types']
                ];
            }

            return [
                'compatible' => true,
                'issues' => [],
                'warnings' => [],
                'recommendations' => []
            ];
        }
        
        // Use existing compatibility method for other component pairs
        $compatibilityMethod = $this->getCompatibilityMethod($type1, $type2);
        
        if ($compatibilityMethod) {
            return $this->$compatibilityMethod($component1, $component2);
        }
        
        // Default compatibility if no specific rules
        return [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];
    }
    
    /**
     * Convert motherboard specifications to limits format
     */
    private function convertSpecsToLimits($specifications) {
        return [
            'cpu' => [
                'socket_type' => $specifications['socket']['type'] ?? 'Unknown',
                'max_sockets' => $specifications['socket']['count'] ?? 1,
                'max_tdp' => $specifications['power']['max_tdp'] ?? 150
            ],
            'memory' => [
                'max_slots' => $specifications['memory']['slots'] ?? 4,
                'supported_types' => $specifications['memory']['types'] ?? ['DDR4'],
                'max_frequency_mhz' => $specifications['memory']['max_frequency_mhz'] ?? 3200,
                'max_capacity_gb' => $specifications['memory']['max_capacity_gb'] ?? 128,
                'ecc_support' => $specifications['memory']['ecc_support'] ?? false
            ],
            'storage' => [
                'sata_ports' => $specifications['storage']['sata_ports'] ?? 0,
                'm2_slots' => $specifications['storage']['m2_slots'] ?? 0,
                'u2_slots' => $specifications['storage']['u2_slots'] ?? 0,
                'sas_ports' => $specifications['storage']['sas_ports'] ?? 0
            ],
            'expansion' => [
                'pcie_slots' => $specifications['pcie_slots'] ?? []
            ]
        ];
    }
    
    /**
     * Get appropriate compatibility check method
     */
    private function getCompatibilityMethod($type1, $type2) {
        $compatibilityMap = [
            'cpu-motherboard' => 'checkCPUMotherboardCompatibility',
            'motherboard-cpu' => 'checkCPUMotherboardCompatibility',
            'motherboard-ram' => 'checkMotherboardRAMCompatibility',
            'ram-motherboard' => 'checkMotherboardRAMCompatibility',
            'cpu-ram' => 'checkCPURAMCompatibility',
            'ram-cpu' => 'checkCPURAMCompatibility',
            'motherboard-storage' => 'checkMotherboardStorageCompatibility',
            'storage-motherboard' => 'checkMotherboardStorageCompatibility',
            'motherboard-nic' => 'checkMotherboardNICCompatibility',
            'nic-motherboard' => 'checkMotherboardNICCompatibility',
            'storage-caddy' => 'checkStorageCaddyCompatibility',
            'caddy-storage' => 'checkStorageCaddyCompatibility',
            'sfp-nic' => 'checkSFPNICCompatibility',
            'nic-sfp' => 'checkSFPNICCompatibility'
        ];
        
        $key = "$type1-$type2";
        return $compatibilityMap[$key] ?? null;
    }
    
    /**
     * Check CPU-Motherboard compatibility
     */
    private function checkCPUMotherboardCompatibility($component1, $component2) {
        $cpu = $component1['type'] === 'cpu' ? $component1 : $component2;
        $motherboard = $component1['type'] === 'motherboard' ? $component1 : $component2;
        
        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        try {
            $cpuData = $this->dataLoader->getComponentData($cpu['type'], $cpu['uuid']);
            $motherboardData = $this->dataLoader->getComponentData($motherboard['type'], $motherboard['uuid']);

            // Socket compatibility check
            $cpuSocket = $this->dataExtractor->extractSocketType($cpuData, 'cpu');
            $motherboardSocket = $this->dataExtractor->extractSocketType($motherboardData, 'motherboard');

            // Normalize socket types for comparison (handles FC prefix, spaces, case)
            $cpuSocketNormalized = DataNormalizationUtils::normalizeSocketType($cpuSocket);
            $motherboardSocketNormalized = DataNormalizationUtils::normalizeSocketType($motherboardSocket);

            if ($cpuSocket && $motherboardSocket && $cpuSocketNormalized !== $motherboardSocketNormalized) {
                $result['compatible'] = false;
                $result['issues'][] = "Socket mismatch: CPU socket ($cpuSocket) does not match motherboard socket ($motherboardSocket)";
                return $result;
            }

            // TDP compatibility check
            $cpuTDP = $this->dataExtractor->extractTDP($cpuData);
            $motherboardMaxTDP = $this->dataExtractor->extractMaxTDP($motherboardData);

            if ($cpuTDP && $motherboardMaxTDP && $cpuTDP > $motherboardMaxTDP) {
                $result['warnings'][] = "CPU TDP ({$cpuTDP}W) may exceed motherboard's recommended limit ({$motherboardMaxTDP}W)";
            }

            // Memory controller compatibility
            $cpuMemoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($cpuData, 'cpu');
            $motherboardMemoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($motherboardData, 'motherboard');

            if ($cpuMemoryTypes && $motherboardMemoryTypes) {
                $commonTypes = array_intersect($cpuMemoryTypes, $motherboardMemoryTypes);
                if (empty($commonTypes)) {
                    $result['warnings'][] = "No common memory types supported between CPU and motherboard";
                }
            }
            
            // PCIe version compatibility
            $cpuPCIeVersion = $this->dataExtractor->extractPCIeVersion($cpuData, 'cpu');
            $motherboardPCIeVersion = $this->dataExtractor->extractPCIeVersion($motherboardData, 'motherboard');
            
            if ($cpuPCIeVersion && $motherboardPCIeVersion) {
                if (version_compare($cpuPCIeVersion, $motherboardPCIeVersion, '>')) {
                    $result['warnings'][] = "CPU supports newer PCIe version ($cpuPCIeVersion) than motherboard ($motherboardPCIeVersion)";
                    $result['recommendations'][] = "Consider upgrading motherboard for full PCIe performance";
                }
            }
            
        } catch (Exception $e) {
            error_log("CPU-Motherboard compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform detailed compatibility check";
        }
        
        return $result;
    }
    
    /**
     * Check Motherboard-RAM compatibility
     */
    private function checkMotherboardRAMCompatibility($component1, $component2) {
        $motherboard = $component1['type'] === 'motherboard' ? $component1 : $component2;
        $ram = $component1['type'] === 'ram' ? $component1 : $component2;
        
        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        try {
            $motherboardData = $this->dataLoader->getComponentData($motherboard['type'], $motherboard['uuid']);
            $ramData = $this->dataLoader->getComponentData($ram['type'], $ram['uuid']);

            // Memory type compatibility
            $motherboardMemoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($motherboardData, 'motherboard');
            $ramType = $this->dataExtractor->extractMemoryType($ramData);

            if ($motherboardMemoryTypes && $ramType && !in_array($ramType, $motherboardMemoryTypes)) {
                $result['compatible'] = false;
                $result['issues'][] = "Memory type incompatible: $ramType not supported by motherboard";
                return $result;
            }

            // Memory speed compatibility
            $motherboardMaxSpeed = $this->dataExtractor->extractMaxMemorySpeed($motherboardData);
            $ramSpeed = $this->dataExtractor->extractMemorySpeed($ramData);

            if ($motherboardMaxSpeed && $ramSpeed && $ramSpeed > $motherboardMaxSpeed) {
                $result['warnings'][] = "RAM speed ({$ramSpeed}MHz) exceeds motherboard maximum ({$motherboardMaxSpeed}MHz) - will run at reduced speed";
            }

            // Form factor compatibility
            $motherboardFormFactor = $this->dataExtractor->extractMemoryFormFactor($motherboardData);
            $ramFormFactor = $this->dataExtractor->extractMemoryFormFactor($ramData);

            if ($motherboardFormFactor && $ramFormFactor && $motherboardFormFactor !== $ramFormFactor) {
                $result['compatible'] = false;
                $result['issues'][] = "Memory form factor incompatible: $ramFormFactor not supported by motherboard ($motherboardFormFactor)";
                return $result;
            }

            // ECC compatibility
            $motherboardECC = $this->dataExtractor->extractECCSupport($motherboardData);
            $ramECC = $this->dataExtractor->extractECCSupport($ramData);

            if ($ramECC && !$motherboardECC) {
                $result['warnings'][] = "ECC memory used with non-ECC motherboard - ECC features will be disabled";
            }
            
        } catch (Exception $e) {
            error_log("Motherboard-RAM compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform detailed compatibility check";
        }
        
        return $result;
    }
    
    /**
     * Check CPU-RAM compatibility
     */
    private function checkCPURAMCompatibility($component1, $component2) {
        $cpu = $component1['type'] === 'cpu' ? $component1 : $component2;
        $ram = $component1['type'] === 'ram' ? $component1 : $component2;
        
        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        try {
            $cpuData = $this->dataLoader->getComponentData($cpu['type'], $cpu['uuid']);
            $ramData = $this->dataLoader->getComponentData($ram['type'], $ram['uuid']);

            // Memory type support
            $cpuMemoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($cpuData, 'cpu');
            $ramType = $this->dataExtractor->extractMemoryType($ramData);

            if ($cpuMemoryTypes && $ramType) {
                // Normalize memory types: "DDR5-4800" -> "DDR5"
                $normalizedCpuTypes = array_map(function($type) {
                    return preg_replace('/-\d+$/', '', $type);
                }, $cpuMemoryTypes);

                if (!in_array($ramType, $normalizedCpuTypes)) {
                    // Don't block, just warn
                    $result['warnings'][] = "RAM type $ramType may have compatibility issues with CPU (supports " . implode(', ', $normalizedCpuTypes) . ")";
                }
            }

            // Memory speed limits
            $cpuMaxSpeed = $this->dataExtractor->extractMaxMemorySpeed($cpuData, 'cpu');
            $ramSpeed = $this->dataExtractor->extractMemorySpeed($ramData);

            if ($cpuMaxSpeed && $ramSpeed && $ramSpeed > $cpuMaxSpeed) {
                $result['warnings'][] = "RAM speed ({$ramSpeed}MHz) exceeds CPU specification ({$cpuMaxSpeed}MHz)";
                $result['recommendations'][] = "Memory will run at CPU's maximum supported speed";
            }
            
        } catch (Exception $e) {
            error_log("CPU-RAM compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform detailed compatibility check";
        }
        
        return $result;
    }
    
    /**
     * Check Motherboard-Storage compatibility - ENHANCED WITH JSON VALIDATION
     */
    private function checkMotherboardStorageCompatibility($component1, $component2) {
        $motherboard = $component1['type'] === 'motherboard' ? $component1 : $component2;
        $storage = $component1['type'] === 'storage' ? $component1 : $component2;

        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        try {
            // Load storage specifications from JSON with UUID validation
            $storageSpecs = $this->dataLoader->loadStorageSpecs($storage['uuid']);
            if (!$storageSpecs) {
                $result['compatible'] = false;
                $result['issues'][] = "Storage UUID {$storage['uuid']} not found in storage-level-3.json";
                $result['warnings'][] = "Falling back to database Notes field parsing";
                return $this->fallbackStorageCompatibilityCheck($component1, $component2);
            }

            // Load motherboard specifications from JSON
            $motherboardSpecs = $this->dataLoader->loadMotherboardSpecs($motherboard['uuid']);
            if (!$motherboardSpecs) {
                $result['compatible'] = false;
                $result['issues'][] = "Motherboard UUID {$motherboard['uuid']} not found in motherboard-level-3.json";
                return $result;
            }

            // Interface Type Compatibility Check
            $interfaceResult = $this->checkStorageInterfaceCompatibility($storageSpecs, $motherboardSpecs);
            if (!$interfaceResult['compatible']) {
                $result['compatible'] = false;
                $result['issues'][] = $interfaceResult['message'];
                $result['recommendations'][] = $interfaceResult['recommendation'];
                return $result;
            }

            // Form Factor and Connector Validation
            $formFactorResult = $this->checkFormFactorCompatibility($storageSpecs, $motherboardSpecs);
            if (!$formFactorResult['compatible']) {
                $result['compatible'] = false;
                $result['issues'][] = $formFactorResult['message'];
                $result['recommendations'][] = $formFactorResult['recommendation'];
                return $result;
            }

            // PCIe Bandwidth Validation for NVMe storage
            // SKIP for M.2/U.2/U.3 FORM FACTOR - they use dedicated motherboard slots or chassis bays
            // 2.5"/3.5" drives with NVMe interface use chassis bays, NOT PCIe expansion slots
            if ($this->isNVMeStorage($storageSpecs)) {
                $formFactor = strtolower($storageSpecs['form_factor'] ?? '');

                // ONLY check form_factor, NOT subtype
                // Form factor determines physical connection, not protocol
                $isM2FormFactor = (strpos($formFactor, 'm.2') !== false || strpos($formFactor, 'm2') !== false);
                $isU2U3FormFactor = (strpos($formFactor, 'u.2') !== false || strpos($formFactor, 'u.3') !== false);
                $is25or35Inch = (strpos($formFactor, '2.5') !== false || strpos($formFactor, '3.5') !== false);

                // Skip PCIe bandwidth check for:
                // - M.2 form factor (uses motherboard M.2 slots or M.2 adapters)
                // - U.2/U.3 form factor (uses motherboard U.2 ports)
                // - 2.5"/3.5" form factor (uses chassis bays, even if NVMe protocol)
                if (!$isM2FormFactor && !$isU2U3FormFactor && !$is25or35Inch) {
                    $bandwidthResult = $this->checkPCIeBandwidthCompatibility($storageSpecs, $motherboardSpecs);
                    if (!$bandwidthResult['compatible']) {
                        if ($bandwidthResult['score'] < 0.5) {
                            $result['compatible'] = false;
                            $result['issues'][] = $bandwidthResult['message'];
                        } else {
                            $result['warnings'][] = $bandwidthResult['message'];
                        }
                        $result['recommendations'][] = $bandwidthResult['recommendation'];
                    }
                }
            }

        } catch (Exception $e) {
            error_log("Motherboard-Storage compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform detailed compatibility check";
        }

        return $result;
    }
    
    /**
     * Check Motherboard-NIC compatibility
     */
    private function checkMotherboardNICCompatibility($component1, $component2) {
        // Temporarily simplified: NICs don't have JSON data yet, so skip detailed compatibility
        // This prevents 500 errors when NIC UUIDs don't match JSON
        return [
            'compatible' => true,
            'issues' => [],
            'warnings' => ['NIC compatibility check skipped - NIC specifications pending'],
            'recommendations' => []
        ];
    }
    
    /**
     * Check Storage-Caddy compatibility
     */
    private function checkStorageCaddyCompatibility($component1, $component2) {
        $storage = $component1['type'] === 'storage' ? $component1 : $component2;
        $caddy = $component1['type'] === 'caddy' ? $component1 : $component2;

        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        try {
            $storageData = $this->dataLoader->getComponentData($storage['type'], $storage['uuid']);
            $caddyData = $this->dataLoader->getComponentData($caddy['type'], $caddy['uuid']);

            // Get storage form factor
            $storageFormFactor = $this->dataExtractor->extractStorageFormFactor($storageData);
            $normalizedStorageFF = DataNormalizationUtils::normalizeFormFactorForComparison($storageFormFactor);

            // CRITICAL: M.2 and U.2 storage do NOT use caddies
            // They connect directly to motherboard M.2 slots or PCIe adapters
            // Only 2.5" and 3.5" storage require caddy compatibility checks
            if (strpos($normalizedStorageFF, 'm.2') !== false ||
                strpos($normalizedStorageFF, 'm2') !== false ||
                strpos($normalizedStorageFF, 'u.2') !== false ||
                strpos($normalizedStorageFF, 'u.3') !== false) {
                // M.2/U.2 storage - skip caddy check, always compatible
                $result['warnings'][] = "M.2/U.2 storage does not require caddy - connects directly to motherboard/PCIe adapter";
                return $result;
            }

            // Form factor compatibility for 2.5" and 3.5" storage only
            $caddySupportedFormFactors = $this->dataExtractor->extractSupportedFormFactors($caddyData);

            if ($storageFormFactor && $caddySupportedFormFactors) {
                $normalizedCaddyFFs = array_map([DataNormalizationUtils::class, 'normalizeFormFactorForComparison'], $caddySupportedFormFactors);

                if (!in_array($normalizedStorageFF, $normalizedCaddyFFs)) {
                    $result['compatible'] = false;
                    $result['issues'][] = "Storage form factor ($storageFormFactor) not supported by caddy";
                    return $result;
                }
            }

            // NOTE: Interface compatibility check REMOVED
            // Caddies are passive physical mounting brackets - they don't have electrical interfaces.
            // Interface compatibility (SATA/SAS/NVMe) is handled by chassis backplane/HBA/motherboard.
            // The "interface" field in caddy JSON is metadata only, not a compatibility constraint.

        } catch (Exception $e) {
            error_log("Storage-Caddy compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform detailed compatibility check";
        }

        return $result;
    }

    
    private function isPCIeSlotCompatible($availableSlot, $requiredSlot) {
        // Extract slot sizes
        preg_match('/x(\d+)/', $availableSlot, $availableMatches);
        preg_match('/x(\d+)/', $requiredSlot, $requiredMatches);
        
        $availableSize = isset($availableMatches[1]) ? (int)$availableMatches[1] : 1;
        $requiredSize = isset($requiredMatches[1]) ? (int)$requiredMatches[1] : 1;
        
        // Larger slots can accommodate smaller cards
        return $availableSize >= $requiredSize;
    }
    

    /**
     * Get comprehensive component specifications
     */
    public function getComponentSpecifications($componentType, $uuid) {
        try {
            $data = $this->dataLoader->getComponentData($componentType, $uuid);
            
            $specs = [
                'basic_info' => [
                    'type' => $componentType,
                    'uuid' => $uuid,
                    'model' => $data['model'] ?? 'Unknown',
                    'brand' => $data['brand'] ?? $data['manufacturer'] ?? 'Unknown'
                ],
                'compatibility_fields' => []
            ];
            
            switch ($componentType) {
                case 'cpu':
                    $specs['compatibility_fields'] = [
                        'socket' => $this->dataExtractor->extractSocketType($data, 'cpu'),
                        'memory_types' => $this->dataExtractor->extractSupportedMemoryTypes($data, 'cpu'),
                        'max_memory_speed' => $this->dataExtractor->extractMaxMemorySpeed($data, 'cpu'),
                        'tdp' => $this->dataExtractor->extractTDP($data),
                        'pcie_version' => $this->dataExtractor->extractPCIeVersion($data, 'cpu')
                    ];
                    break;
                    
                case 'motherboard':
                    $specs['compatibility_fields'] = [
                        'socket' => $this->dataExtractor->extractSocketType($data, 'motherboard'),
                        'memory_types' => $this->dataExtractor->extractSupportedMemoryTypes($data, 'motherboard'),
                        'max_memory_speed' => $this->dataExtractor->extractMaxMemorySpeed($data, 'motherboard'),
                        'max_tdp' => $this->dataExtractor->extractMaxTDP($data),
                        'pcie_version' => $this->dataExtractor->extractPCIeVersion($data, 'motherboard'),
                        'pcie_slots' => $this->dataExtractor->extractPCIeSlots($data),
                        'storage_interfaces' => $this->dataExtractor->extractStorageInterfaces($data)
                    ];
                    break;
                    
                case 'ram':
                    $specs['compatibility_fields'] = [
                        'type' => $this->dataExtractor->extractMemoryType($data),
                        'speed' => $this->dataExtractor->extractMemorySpeed($data),
                        'form_factor' => $this->dataExtractor->extractMemoryFormFactor($data),
                        'ecc_support' => $this->dataExtractor->extractECCSupport($data)
                    ];
                    break;
                    
                case 'storage':
                    $specs['compatibility_fields'] = [
                        'interface' => $this->dataExtractor->extractStorageInterface($data),
                        'form_factor' => $this->dataExtractor->extractStorageFormFactor($data),
                        'power_consumption' => $this->dataExtractor->extractPowerConsumption($data)
                    ];
                    break;
                    
                case 'nic':
                    $specs['compatibility_fields'] = [
                        'pcie_requirement' => $this->dataExtractor->extractPCIeRequirement($data),
                        'pcie_version' => $this->dataExtractor->extractPCIeVersion($data, 'nic'),
                        'power_consumption' => $this->dataExtractor->extractPowerConsumption($data)
                    ];
                    // For onboard NICs the JSON UUID doesn't exist in nic JSON files —
                    // specs live in the parent motherboard's networking.onboard_nics array.
                    if (($data['SourceType'] ?? '') === 'onboard' && !empty($data['ParentComponentUUID'])) {
                        $mbData = $this->dataLoader->getComponentData('motherboard', $data['ParentComponentUUID']);
                        $onboardIndex = (int)($data['OnboardNICIndex'] ?? 1);
                        $onboardNics = $mbData['networking']['onboard_nics'] ?? [];
                        $nicSpec = $onboardNics[$onboardIndex - 1] ?? null;
                        $specs['port_type'] = $nicSpec['connector'] ?? '';
                        $specs['ports']     = (int)($nicSpec['ports'] ?? 0);
                        $specs['speeds']    = !empty($nicSpec['speed']) ? [$nicSpec['speed']] : [];
                    } else {
                        // Regular component NIC: fields come from the NIC JSON data
                        $specs['port_type'] = $data['port_type'] ?? '';
                        $specs['ports']     = $data['ports'] ?? 0;
                        $specs['speeds']    = $data['speeds'] ?? [];
                    }
                    break;
                    
                case 'sfp':
                    $specs['compatibility_fields'] = [];
                    // Add SFP-specific fields from raw data
                    $specs['type'] = $data['type'] ?? '';
                    $specs['speed'] = $data['speed'] ?? '';
                    $specs['fiber_type'] = $data['fiber_type'] ?? '';
                    $specs['reach'] = $data['reach'] ?? '';
                    $specs['compatible_interfaces'] = $data['compatible_interfaces'] ?? [];
                    break;

                case 'caddy':
                    $specs['compatibility_fields'] = [
                        'supported_form_factors' => $this->dataExtractor->extractSupportedFormFactors($data),
                        'supported_interfaces' => $this->dataExtractor->extractSupportedInterfaces($data)
                    ];
                    break;
            }
            
            return $specs;
        } catch (Exception $e) {
            error_log("Error getting component specifications: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if two component types can be compatible
     */
    public function canComponentTypesBeCompatible($type1, $type2) {
        $compatibilityMethod = $this->getCompatibilityMethod($type1, $type2);
        return $compatibilityMethod !== null;
    }
    
    
    
    

    
    


    /**
     * Enhanced extractSocketType method to work with JSON data primarily
     */
    public function extractSocketTypeFromJSON($componentType, $componentUuid) {
        $result = null;

        if ($componentType === 'cpu') {
            $cpuResult = $this->validator->validateCPUExists($componentUuid);
            if ($cpuResult['exists'] && isset($cpuResult['data'])) {
                $result = $cpuResult['data']['socket'] ?? null;
            }
        } elseif ($componentType === 'motherboard') {
            $mbResult = $this->dataLoader->loadComponentFromJSON('motherboard', $componentUuid);
            if ($mbResult['found'] && isset($mbResult['data'])) {
                $data = $mbResult['data'];
                // Try multiple socket field possibilities
                $result = $data['socket']['type'] ?? $data['socket'] ?? $data['cpu_socket'] ?? null;
            }
        }

        // Fallback to database Notes field extraction if JSON doesn't have the data
        if (!$result) {
            $componentData = $this->dataLoader->getComponentData($componentType, $componentUuid);
            if ($componentData) {
                $notes = strtolower($componentData['Notes'] ?? '');
                $result = $this->dataExtractor->extractSocketFromNotes($notes);
            }
        }

        return $result;
    }









    







    /**
     * ENHANCED STORAGE COMPATIBILITY METHODS
     */

    /**
     * Check storage interface compatibility with motherboard
     * JSON-DRIVEN: No hardcoded compatibility rules - uses protocol/generation normalization
     */
    private function checkStorageInterfaceCompatibility($storageSpecs, $motherboardSpecs) {
        $storageInterface = $storageSpecs['interface'] ?? $storageSpecs['interface_type'] ?? '';
        $mbInterfaces = $motherboardSpecs['storage_interfaces'];

        // Direct interface match (exact string - highest score)
        if (in_array($storageInterface, $mbInterfaces)) {
            return [
                'compatible' => true,
                'score' => 0.95,
                'message' => "Perfect interface match: $storageInterface",
                'recommendation' => 'Native interface support provides optimal performance'
            ];
        }

        // Normalize storage interface and compare with normalized motherboard interfaces
        $normalizedStorageInterface = DataNormalizationUtils::normalizeStorageInterface($storageInterface);

        foreach ($mbInterfaces as $mbInterface) {
            $normalizedMbInterface = DataNormalizationUtils::normalizeStorageInterface($mbInterface);

            // Check if normalized interfaces match (protocol and generation)
            if ($normalizedStorageInterface['protocol'] === $normalizedMbInterface['protocol']) {
                // Same protocol - check generation compatibility.
                // BUGFIX (M5): use a type-tolerant comparison so an int generation
                // (e.g. SATA 3) and a float generation (e.g. 3.0) are recognised as an
                // exact match instead of falling through to the lower-scored branch.
                if ($normalizedStorageInterface['generation'] !== null &&
                    $normalizedMbInterface['generation'] !== null &&
                    (float)$normalizedStorageInterface['generation'] === (float)$normalizedMbInterface['generation']) {
                    // Perfect match
                    return [
                        'compatible' => true,
                        'score' => 0.95,
                        'message' => "Interface compatible: $storageInterface matches $mbInterface",
                        'recommendation' => 'Native interface support provides optimal performance'
                    ];
                } elseif ($normalizedStorageInterface['generation'] !== null &&
                          $normalizedMbInterface['generation'] !== null &&
                          $normalizedStorageInterface['generation'] <= $normalizedMbInterface['generation']) {
                    // Backward compatible (storage gen <= motherboard gen)
                    return [
                        'compatible' => true,
                        'score' => 0.90,
                        'message' => "Interface compatible: $storageInterface works with $mbInterface (backward compatible)",
                        'recommendation' => 'Backward compatible - full functionality supported'
                    ];
                } elseif ($normalizedStorageInterface['generation'] === null ||
                          $normalizedMbInterface['generation'] === null) {
                    // One has no generation specified - assume compatible
                    return [
                        'compatible' => true,
                        'score' => 0.85,
                        'message' => "Interface compatible: $storageInterface works with $mbInterface",
                        'recommendation' => 'Compatible with potential performance differences'
                    ];
                }
            }
        }

        // Check if NVMe can work via PCIe slot
        if ($normalizedStorageInterface['protocol'] === 'nvme') {
            if (!empty($motherboardSpecs['pcie_slots'])) {
                return [
                    'compatible' => true,
                    'score' => 0.80,
                    'message' => "NVMe storage can use PCIe slot with adapter",
                    'recommendation' => 'Consider M.2 to PCIe adapter for compatibility'
                ];
            }
        }

        // NO EVIDENCE IS NOT EVIDENCE OF INCOMPATIBILITY (fixed 2026-07-27).
        // extractMotherboardStorageInterfaces() derives this list ENTIRELY from the
        // board's `storage` block in ims-data. 3 of the 23 boards there have no such
        // block at all (SA5212H5, S5B-MB 1U, S5B-MB 2U), so the list is empty and
        // this gate rejected every drive with "motherboard only supports: " -- an
        // empty supported-list, which is the tell. That is a verdict drawn from
        // missing data, and always a false positive: the drive still reaches the
        // board through the chassis backplane or an HBA. Those paths are validated
        // by StorageConnectionValidator and enforced again at finalize, so defer.
        if (empty($mbInterfaces)) {
            return [
                'compatible' => true,
                'score' => 0.70,
                'message' => "Motherboard spec declares no storage interfaces; deferring $storageInterface to backplane/HBA validation",
                'recommendation' => 'Board storage interfaces are absent from ims-data - the connection path is enforced by chassis/HBA validation and at finalize'
            ];
        }

        // No compatible interface found
        return [
            'compatible' => false,
            'score' => 0.25,
            'message' => "Storage requires $storageInterface but motherboard only supports: " . implode(', ', $mbInterfaces),
            'recommendation' => 'Use storage device with compatible interface or upgrade motherboard'
        ];
    }

    /**
     * Normalize storage interface string to extract protocol and generation
     * This allows flexible matching regardless of word order or formatting
     *
     * Examples:
     *   "NVMe PCIe 4.0" -> ['protocol' => 'nvme', 'generation' => 4.0]
     *   "PCIe NVMe 4.0" -> ['protocol' => 'nvme', 'generation' => 4.0]
     *   "SATA III" -> ['protocol' => 'sata', 'generation' => 3]
     *   "SAS3" -> ['protocol' => 'sas', 'generation' => 3]
     */
    /**
     * Check form factor and connector compatibility
     */
    private function checkFormFactorCompatibility($storageSpecs, $motherboardSpecs, $existingStorage = []) {
        $storageFormFactor = $storageSpecs['form_factor'];
        // Default to [] rather than reading blind: boards whose ims-data entry has no
        // `storage` block produce no drive_bays content, and `in_array($ff, null)` is a
        // warning on PHP 7.4 but a fatal TypeError on PHP 8.
        $mbBays = $motherboardSpecs['drive_bays'] ?? [];
        $supportedFormFactors = $mbBays['supported_form_factors'] ?? [];

        // Extract physical form factor from compound form factors for bay matching.
        // e.g. "2.5-inch U.2" → "2.5-inch": the physical size determines which chassis bay
        // the drive occupies; the protocol suffix (U.2) is irrelevant for bay compatibility.
        $physicalFormFactor = $storageFormFactor;
        $ffLower = strtolower($storageFormFactor);
        if (strpos($ffLower, '2.5') !== false) {
            $physicalFormFactor = '2.5-inch';
        } elseif (strpos($ffLower, '3.5') !== false) {
            $physicalFormFactor = '3.5-inch';
        }
        // Use physical form factor for all checks when it differs from the original
        $checkFormFactor = ($physicalFormFactor !== $storageFormFactor) ? $physicalFormFactor : $storageFormFactor;

        // Direct form factor support
        if (in_array($checkFormFactor, $supportedFormFactors)) {
            // Check bay availability
            $bayAvailable = $this->checkBayAvailability($checkFormFactor, $mbBays, $existingStorage);
            
            if ($bayAvailable) {
                return [
                    'compatible' => true,
                    'score' => 0.95,
                    'message' => "Native form factor support: $storageFormFactor",
                    'recommendation' => 'Perfect physical fit with native bay support'
                ];
            } else {
                return [
                    'compatible' => false,
                    'score' => 0.30,
                    'message' => "Form factor supported but no available bays for $storageFormFactor",
                    'recommendation' => 'Remove existing storage or use different form factor'
                ];
            }
        }
        
        // Check for adapter compatibility
        $adapterCompatibility = $this->checkAdapterCompatibility($storageFormFactor, $supportedFormFactors);
        if ($adapterCompatibility['possible']) {
            return [
                'compatible' => true,
                'score' => 0.85,
                'message' => $adapterCompatibility['message'],
                'recommendation' => $adapterCompatibility['recommendation']
            ];
        }
        
        // NO EVIDENCE IS NOT EVIDENCE OF INCOMPATIBILITY (fixed 2026-07-27).
        // Same root cause as the empty-list guard in checkStorageInterfaceCompatibility():
        // extractDriveBays() derives supported_form_factors ENTIRELY from the board's
        // `storage` block, so for the 3 boards in ims-data that lack one this gate
        // rejected EVERY drive of EVERY form factor. That is the reported production
        // failure: "Storage form factor 2.5-inch U.2 not compatible with motherboard
        // bays" on S5B-MB 2U, with an empty "supported form factor:" list after it.
        // A 2.5"/3.5" drive is mounted by a CHASSIS bay, not by the board, and bay
        // availability is already enforced by StorageConnectionValidator and
        // ComponentValidator::validateChassisBayStorage -- so defer to them.
        if (empty($supportedFormFactors)) {
            return [
                'compatible' => true,
                'score' => 0.70,
                'message' => "Motherboard spec declares no drive bays; deferring $storageFormFactor to chassis bay validation",
                'recommendation' => 'Board drive-bay data is absent from ims-data - the physical mount is enforced by chassis bay validation and at finalize'
            ];
        }

        // No compatible form factor
        return [
            'compatible' => false,
            'score' => 0.25,
            'message' => "Storage form factor $storageFormFactor not compatible with motherboard bays",
            'recommendation' => 'Use storage with supported form factor: ' . implode(', ', $supportedFormFactors)
        ];
    }

    /**
     * Check PCIe bandwidth compatibility for NVMe storage
     */
    private function checkPCIeBandwidthCompatibility($storageSpecs, $motherboardSpecs) {
        $requiredPCIeGen = $storageSpecs['pcie_version'];
        $requiredLanes = $storageSpecs['pcie_lanes'];
        
        if (!$requiredPCIeGen || !$requiredLanes) {
            return [
                'compatible' => true,
                'score' => 0.90,
                'message' => 'PCIe requirements not specified, assuming compatibility',
                'recommendation' => 'Verify PCIe requirements with storage documentation'
            ];
        }
        
        $mbPCIeGen = $motherboardSpecs['pcie_version'];
        $availableSlots = $motherboardSpecs['pcie_slots'];
        
        // Check if motherboard PCIe version meets storage requirements
        if ($this->comparePCIeVersions($mbPCIeGen, $requiredPCIeGen) >= 0) {
            // Full bandwidth available
            $suitableSlot = $this->findSuitablePCIeSlot($availableSlots, $requiredLanes);
            
            if ($suitableSlot) {
                return [
                    'compatible' => true,
                    'score' => 0.95,
                    'message' => "Full bandwidth available: PCIe $mbPCIeGen x$requiredLanes",
                    'recommendation' => 'Optimal PCIe bandwidth for maximum performance'
                ];
            }
        } else {
            // Backward compatibility (reduced bandwidth)
            $suitableSlot = $this->findSuitablePCIeSlot($availableSlots, $requiredLanes);
            
            if ($suitableSlot) {
                return [
                    'compatible' => true,
                    'score' => 0.85,
                    'message' => "Reduced bandwidth: Storage requires PCIe $requiredPCIeGen but motherboard provides $mbPCIeGen",
                    'recommendation' => 'Storage will work but with reduced performance due to PCIe version limitation'
                ];
            }
        }
        
        // Insufficient lanes or no compatible slots
        return [
            'compatible' => false,
            'score' => 0.40,
            'message' => "Insufficient PCIe resources: Storage requires $requiredPCIeGen x$requiredLanes",
            'recommendation' => 'Use storage with lower PCIe requirements or upgrade motherboard'
        ];
    }

    /**
     * Fallback storage compatibility check using database data
     */
    private function fallbackStorageCompatibilityCheck($component1, $component2) {
        $motherboard = $component1['type'] === 'motherboard' ? $component1 : $component2;
        $storage = $component1['type'] === 'storage' ? $component1 : $component2;

        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => ['Using fallback compatibility check - JSON data not available'],
            'recommendations' => ['Update storage-level-3.json for enhanced compatibility validation']
        ];

        try {
            $motherboardData = $this->dataLoader->getComponentData($motherboard['type'], $motherboard['uuid']);
            $storageData = $this->dataLoader->getComponentData($storage['type'], $storage['uuid']);

            // Basic interface checking from database notes
            $motherboardInterfaces = $this->dataExtractor->extractStorageInterfaces($motherboardData);
            $storageInterface = $this->dataExtractor->extractStorageInterface($storageData);

            if ($motherboardInterfaces && $storageInterface && !in_array($storageInterface, $motherboardInterfaces)) {
                $result['compatible'] = false;
                $result['issues'][] = "Storage interface possibly incompatible: $storageInterface";
                $result['recommendations'][] = 'Verify interface compatibility manually';
            }

        } catch (Exception $e) {
            error_log("Fallback storage compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform fallback compatibility check";
        }

        return $result;
    }

    /**
     * Helper methods for storage compatibility
     */
    
    private function isNVMeStorage($storageSpecs) {
        $interface = strtolower($storageSpecs['interface'] ?? $storageSpecs['interface_type'] ?? '');
        return strpos($interface, 'nvme') !== false || strpos($interface, 'pcie') !== false;
    }

    private function checkBayAvailability($formFactor, $mbBays, $existingStorage) {
        // Simplified bay checking - in real implementation, count used bays
        switch ($formFactor) {
            case 'M.2 2280':
            case 'M.2 22110':
                return !empty($mbBays['m2_slots']);
            case '2.5-inch':
                return $mbBays['sata_ports'] > 0 || $mbBays['u2_slots'] > 0;
            case '3.5-inch':
                return $mbBays['sata_ports'] > 0;
            default:
                return false;
        }
    }
    
    private function checkAdapterCompatibility($storageFormFactor, $supportedFormFactors) {
        // Define adapter possibilities
        $adapterMatrix = [
            'M.2 2280' => [
                'target' => '3.5-inch',
                'message' => 'M.2 2280 can use PCIe slot with M.2 to PCIe adapter',
                'recommendation' => 'Purchase M.2 to PCIe adapter card'
            ],
            '2.5-inch' => [
                'target' => '3.5-inch',
                'message' => '2.5-inch drive can fit in 3.5-inch bay with adapter',
                'recommendation' => 'Use 2.5" to 3.5" drive adapter bracket'
            ]
        ];
        
        if (isset($adapterMatrix[$storageFormFactor])) {
            $adapter = $adapterMatrix[$storageFormFactor];
            if (in_array($adapter['target'], $supportedFormFactors)) {
                return [
                    'possible' => true,
                    'message' => $adapter['message'],
                    'recommendation' => $adapter['recommendation']
                ];
            }
        }
        
        return ['possible' => false];
    }
    
    private function comparePCIeVersions($motherboardVersion, $requiredVersion) {
        return version_compare($motherboardVersion, $requiredVersion);
    }
    
    private function findSuitablePCIeSlot($availableSlots, $requiredLanes) {
        foreach ($availableSlots as $slot) {
            if (($slot['lanes'] ?? 1) >= $requiredLanes && ($slot['count'] ?? 0) > 0) {
                return $slot;
            }
        }
        return null;
    }

    /**
     * Direct vs Recursive checking modes
     */
    
    














    /**
     * Analyze existing motherboard for PCIe card compatibility
     */
























    // NOTE (M7): The riser physical-fit helpers that previously lived here
    // (getRiserSlotAvailability/getExistingRisers/findComponentByType/
    // checkRiserHeightClearance/checkRiserLengthFit/checkRiserSpacingFit/
    // calculateTotalRiserLength/getMaxRiserHeight) were dead code. They keyed off
    // component_type/component_uuid (callers pass type/uuid), read dimension fields
    // that no ims-data spec defines, and had no callers. Riser slot accounting is
    // handled by UnifiedSlotTracker::getRiserSlotAvailability($configUuid). Removed
    // to eliminate a misleading "we validate riser physical fit" surface.



    /**
     * Normalize form factor string to standard format (e.g., "2.5-inch", "3.5-inch")
     * @param string $formFactor Raw form factor string
     * @return string Normalized form factor
     */
    private function normalizeFormFactor($formFactor) {
        $normalized = strtolower(trim($formFactor));

        // Handle various 2.5" formats
        if (strpos($normalized, '2.5') !== false) {
            return '2.5-inch';
        }

        // Handle various 3.5" formats
        if (strpos($normalized, '3.5') !== false) {
            return '3.5-inch';
        }

        // Handle underscore format (e.g., "2.5_inch")
        $normalized = str_replace('_', '-', $normalized);

        return $normalized;
    }


    /**
     * Check SFP-NIC compatibility
     * Uses NICPortTracker for type and speed validation
     *
     * @param array $component1 First component
     * @param array $component2 Second component
     * @return array Compatibility result
     */
    private function checkSFPNICCompatibility($component1, $component2) {
        // Determine which is SFP and which is NIC
        $type1 = $component1['type'] ?? '';
        $type2 = $component2['type'] ?? '';
        
        $sfp = $type1 === 'sfp' ? $component1 : $component2;
        $nic = $type1 === 'nic' ? $component1 : $component2;
        
        // Get NIC specs
        $nicSpecs = $this->getComponentSpecifications('nic', $nic['uuid']);
        if (!$nicSpecs) {
            return [
                'compatible' => false,
                'issues' => ['NIC specifications not found'],
                'warnings' => [],
                'recommendations' => ['Verify NIC UUID exists in specifications']
            ];
        }
        
        // Get SFP specs
        $sfpSpecs = $this->getComponentSpecifications('sfp', $sfp['uuid']);
        if (!$sfpSpecs) {
            return [
                'compatible' => false,
                'issues' => ['SFP specifications not found'],
                'warnings' => [],
                'recommendations' => ['Verify SFP UUID exists in specifications']
            ];
        }
        
        $nicPortType = strtoupper(trim($nicSpecs['port_type'] ?? ''));
        $sfpType = strtoupper(trim($sfpSpecs['type'] ?? ''));

        // RJ45 NICs use copper cabling and never interact with SFP modules.
        // They can coexist with SFPs in the same config (SFPs belong to other NICs).
        if (strpos($nicPortType, 'RJ45') !== false) {
            return [
                'compatible' => true,
                'issues' => [],
                'warnings' => [],
                'recommendations' => []
            ];
        }

        // Check type compatibility using NICPortTracker
        $typeCompatible = NICPortTracker::isCompatible($nicPortType, $sfpType);
        
        if (!$typeCompatible) {
            $compatibleTypes = NICPortTracker::getCompatibleSfpTypes($nicPortType);
            return [
                'compatible' => false,
                'issues' => ["SFP type {$sfpType} not compatible with NIC port type {$nicPortType}"],
                'warnings' => [],
                'recommendations' => [
                    'Compatible SFP types for this NIC: ' . implode(', ', $compatibleTypes)
                ]
            ];
        }
        
        // Check speed compatibility.
        // BUGFIX (M6): pick the NIC's fastest speed by NUMERIC Gbps, not lexically.
        // A raw max() on strings ranks "40G" above "100G" ('4' > '1'), mis-reporting
        // the maximum speed of a 100G NIC.
        $nicSpeeds = $nicSpecs['speeds'] ?? [];
        $nicMaxSpeed = '';
        if (is_array($nicSpeeds) && !empty($nicSpeeds)) {
            $nicMaxSpeed = $nicSpeeds[0];
            $maxVal = NICPortTracker::extractSpeedValue($nicMaxSpeed);
            foreach ($nicSpeeds as $speedCandidate) {
                $candidateVal = NICPortTracker::extractSpeedValue($speedCandidate);
                if ($candidateVal > $maxVal) {
                    $maxVal = $candidateVal;
                    $nicMaxSpeed = $speedCandidate;
                }
            }
        }
        $sfpSpeed = $sfpSpecs['speed'] ?? '';
        
        $speedCompatible = NICPortTracker::validateSpeedCompatibility($nicMaxSpeed, $sfpSpeed);
        
        if (!$speedCompatible) {
            return [
                'compatible' => false,
                'issues' => ["SFP speed {$sfpSpeed} exceeds NIC maximum speed {$nicMaxSpeed}"],
                'warnings' => [],
                'recommendations' => [
                    'Use SFP module with speed <= ' . $nicMaxSpeed
                ]
            ];
        }
        
        // All checks passed
        return [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];
    }



}
?>
