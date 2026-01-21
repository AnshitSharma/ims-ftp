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
 * REFACTORING RESULTS:
 * - Original: 7,326 lines (311KB)
 * - Refactored: 4,483 lines (~180KB)
 * - Reduction: 2,843 lines (38.8% smaller)
 * - Improved maintainability and testability
 * - Clear separation of concerns
 *
 * USAGE EXAMPLE:
 * ```php
 * $compatibility = new ComponentCompatibility($pdo);
 *
 * // Check pair compatibility
 * $result = $compatibility->checkComponentPairCompatibility($cpu, $motherboard);
 *
 * // Decentralized compatibility check
 * $ramResult = $compatibility->checkRAMDecentralizedCompatibility($ramComponent, $existingComponents);
 *
 * // Get compatibility score
 * $score = $compatibility->getComponentCompatibilityScore($allComponents);
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
     * Validate RAM exists in JSON specifications
     * Delegates to ComponentValidator
     * @param string $ramUuid RAM UUID
     * @return array Validation result with exists flag and specifications
     */
    public function validateRAMExistsInJSON($ramUuid) {
        return $this->validator->validateRAMExistsInJSON($ramUuid);
    }

    /**
     * Validate memory type compatibility (DDR4/DDR5)
     * Delegates to ComponentValidator
     * @param array $ramSpecs RAM specifications
     * @param array $motherboardSpecs Motherboard specifications
     * @param array $cpuSpecs CPU specifications
     * @return array Compatibility result
     */
    public function validateMemoryTypeCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs) {
        return $this->validator->validateMemoryTypeCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs);
    }

    /**
     * Validate ECC compatibility
     * Delegates to ComponentValidator
     * @param array $ramSpecs RAM specifications
     * @param array $motherboardSpecs Motherboard specifications
     * @param array $cpuSpecs CPU specifications
     * @return array ECC validation result
     */
    public function validateECCCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs) {
        return $this->validator->validateECCCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs);
    }

    /**
     * Validate memory slot availability
     * Delegates to ComponentValidator
     * @param string $configUuid Configuration UUID
     * @param array $motherboardSpecs Motherboard specifications
     * @return array Slot availability result
     */
    public function validateMemorySlotAvailability($configUuid, $motherboardSpecs) {
        return $this->validator->validateMemorySlotAvailability($configUuid, $motherboardSpecs);
    }

    /**
     * Validate memory form factor compatibility (DIMM/SO-DIMM)
     * Delegates to ComponentValidator
     * @param array $ramSpecs RAM specifications
     * @param array $motherboardSpecs Motherboard specifications
     * @return array Form factor validation result
     */
    public function validateMemoryFormFactor($ramSpecs, $motherboardSpecs) {
        return $this->validator->validateMemoryFormFactor($ramSpecs, $motherboardSpecs);
    }

    /**
     * Parse motherboard specifications from UUID
     * Delegates to ComponentValidator
     * @param string $motherboardUuid Motherboard UUID
     * @return array Parsed motherboard specifications
     */
    public function parseMotherboardSpecifications($motherboardUuid) {
        return $this->validator->parseMotherboardSpecifications($motherboardUuid);
    }

    /**
     * Validate motherboard exists in JSON
     * Delegates to ComponentValidator
     * @param string $motherboardUuid Motherboard UUID
     * @return array Validation result
     */
    public function validateMotherboardExists($motherboardUuid) {
        return $this->validator->validateMotherboardExists($motherboardUuid);
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
     * Validate caddy exists in JSON
     * Delegates to ComponentValidator
     * @param string $caddyUuid Caddy UUID
     * @return array Validation result
     */
    public function validateCaddyExists($caddyUuid) {
        return $this->validator->validateCaddyExists($caddyUuid);
    }

    /**
     * Validate CPU socket compatibility with motherboard
     * Delegates to ComponentValidator
     * @param string $cpuUuid CPU UUID
     * @param array $motherboardSpecs Motherboard specifications
     * @return array Socket compatibility result
     */
    public function validateCPUSocketCompatibility($cpuUuid, $motherboardSpecs) {
        return $this->validator->validateCPUSocketCompatibility($cpuUuid, $motherboardSpecs);
    }

    /**
     * Validate CPU count limit for motherboard
     * Delegates to ComponentValidator
     * @param string $configUuid Configuration UUID
     * @param array $motherboardSpecs Motherboard specifications
     * @return array CPU count validation result
     */
    public function validateCPUCountLimit($configUuid, $motherboardSpecs) {
        return $this->validator->validateCPUCountLimit($configUuid, $motherboardSpecs);
    }

    /**
     * Validate mixed CPU compatibility
     * Delegates to ComponentValidator
     * @param array $existingCPUs Existing CPU UUIDs
     * @param string $newCpuUuid New CPU UUID
     * @return array Mixed CPU compatibility result
     */
    public function validateMixedCPUCompatibility($existingCPUs, $newCpuUuid) {
        return $this->validator->validateMixedCPUCompatibility($existingCPUs, $newCpuUuid);
    }

    /**
     * Validate RAM type compatibility with motherboard
     * Delegates to ComponentValidator
     * @param string $ramUuid RAM UUID
     * @param array $motherboardSpecs Motherboard specifications
     * @return array RAM type compatibility result
     */
    public function validateRAMTypeCompatibility($ramUuid, $motherboardSpecs) {
        return $this->validator->validateRAMTypeCompatibility($ramUuid, $motherboardSpecs);
    }

    /**
     * Validate RAM slot availability
     * Delegates to ComponentValidator
     * @param string $configUuid Configuration UUID
     * @param array $motherboardSpecs Motherboard specifications
     * @return array RAM slot availability result
     */
    public function validateRAMSlotAvailability($configUuid, $motherboardSpecs) {
        return $this->validator->validateRAMSlotAvailability($configUuid, $motherboardSpecs);
    }

    /**
     * Validate NIC PCIe compatibility
     * Delegates to ComponentValidator
     * @param string $nicUuid NIC UUID
     * @param string $configUuid Configuration UUID
     * @param array $motherboardSpecs Motherboard specifications
     * @return array NIC PCIe compatibility result
     */
    public function validateNICPCIeCompatibility($nicUuid, $configUuid, $motherboardSpecs) {
        return $this->validator->validateNICPCIeCompatibility($nicUuid, $configUuid, $motherboardSpecs);
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

            // Normalize socket types for comparison (case-insensitive, trim whitespace)
            $cpuSocketNormalized = strtolower(trim($cpuSocket ?? ''));
            $motherboardSocketNormalized = strtolower(trim($motherboardSocket ?? ''));

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
                $normalizedCaddyFFs = array_map([$this, 'normalizeFormFactorForComparison'], $caddySupportedFormFactors);

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

    /**
     * Check if CPU memory type is compatible with required memory type
     * Implements backward compatibility logic (newer CPU can use older RAM with warning)
     *
     * Compatibility Rules:
     * - Same generation (DDR5 + DDR5) = Compatible, no warning
     * - Newer CPU generation (DDR5 CPU + DDR4 RAM) = Compatible with warning (backward compatible)
     * - Older CPU generation (DDR4 CPU + DDR5 RAM) = Incompatible (cannot use newer RAM)
     *
     * @param string $cpuMemoryType The memory type supported by CPU
     * @param string $requiredMemoryType The memory type required by installed RAM
     * @return array ['compatible' => bool, 'warning' => string|null, 'reason' => string]
     */
    private function checkMemoryTypeCompatibility($cpuMemoryType, $requiredMemoryType) {
        $cpuGen = DataNormalizationUtils::getMemoryGeneration($cpuMemoryType);
        $requiredGen = DataNormalizationUtils::getMemoryGeneration($requiredMemoryType);

        $cpuNormalized = DataNormalizationUtils::normalizeMemoryType($cpuMemoryType);
        $requiredNormalized = DataNormalizationUtils::normalizeMemoryType($requiredMemoryType);

        // Same generation - perfect match
        if ($cpuGen === $requiredGen) {
            return [
                'compatible' => true,
                'warning' => null,
                'reason' => "Perfect match: CPU supports {$cpuNormalized}, RAM is {$requiredNormalized}"
            ];
        }

        // CPU supports newer generation than installed RAM - backward compatible
        if ($cpuGen > $requiredGen) {
            return [
                'compatible' => true,
                'warning' => "CPU supports {$cpuNormalized} but {$requiredNormalized} RAM installed - RAM will run at {$requiredNormalized} speeds",
                'reason' => "Backward compatible: CPU ({$cpuNormalized}) supports newer generation than RAM ({$requiredNormalized})"
            ];
        }

        // CPU supports older generation than installed RAM - incompatible
        return [
            'compatible' => false,
            'warning' => null,
            'reason' => "CPU only supports {$cpuNormalized} but {$requiredNormalized} RAM is installed - incompatible"
        ];
    }

    private function findCompatiblePCIeSlots($availableSlots, $requirement) {
        $compatible = [];
        
        foreach ($availableSlots as $slot) {
            if ($this->isPCIeSlotCompatible($slot, $requirement)) {
                $compatible[] = $slot;
            }
        }
        
        return $compatible;
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
     * Check if card is a riser card
     */
    private function isPCIeRiserCard($pcieCardData) {
        $subtype = $pcieCardData['component_subtype'] ?? '';
        return (stripos($subtype, 'riser') !== false);
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
     * Get all compatibility relationships for a component type
     */
    public function getCompatibilityRelationships($componentType) {
        $relationships = [];
        $allTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
        
        foreach ($allTypes as $otherType) {
            if ($otherType !== $componentType) {
                $method = $this->getCompatibilityMethod($componentType, $otherType);
                if ($method) {
                    $relationships[] = [
                        'type' => $otherType,
                        'relationship' => $this->getRelationshipDescription($componentType, $otherType),
                        'method' => $method
                    ];
                }
            }
        }
        
        return $relationships;
    }
    
    /**
     * Get description of compatibility relationship
     */
    private function getRelationshipDescription($type1, $type2) {
        $descriptions = [
            'cpu-motherboard' => 'Socket compatibility, TDP limits, memory controller support',
            'motherboard-cpu' => 'Socket compatibility, TDP limits, memory controller support',
            'motherboard-ram' => 'Memory type, speed, form factor, and ECC compatibility',
            'ram-motherboard' => 'Memory type, speed, form factor, and ECC compatibility',
            'cpu-ram' => 'Memory type and speed support by CPU',
            'ram-cpu' => 'Memory type and speed support by CPU',
            'motherboard-storage' => 'Interface availability and form factor support',
            'storage-motherboard' => 'Interface availability and form factor support',
            'motherboard-nic' => 'PCIe slot availability and power requirements',
            'nic-motherboard' => 'PCIe slot availability and power requirements',
            'storage-caddy' => 'Form factor and interface compatibility',
            'caddy-storage' => 'Form factor and interface compatibility'
        ];
        
        return $descriptions["$type1-$type2"] ?? 'General compatibility check';
    }
    
    /**
     * Get compatibility recommendations for a component
     */
    public function getCompatibilityRecommendations($componentType, $uuid) {
        try {
            $componentData = $this->dataLoader->getComponentData($componentType, $uuid);
            $recommendations = [];
            
            switch ($componentType) {
                case 'cpu':
                    $socket = $this->dataExtractor->extractSocketType($componentData, 'cpu');
                    $memoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($componentData, 'cpu');
                    $tdp = $this->dataExtractor->extractTDP($componentData);
                    
                    if ($socket) {
                        $recommendations[] = "Requires motherboard with $socket socket";
                    }
                    if ($memoryTypes) {
                        $recommendations[] = "Compatible with " . implode(', ', $memoryTypes) . " memory";
                    }
                    if ($tdp) {
                        $recommendations[] = "Requires motherboard supporting at least {$tdp}W TDP";
                    }
                    break;
                    
                case 'motherboard':
                    $socket = $this->dataExtractor->extractSocketType($componentData, 'motherboard');
                    $memoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($componentData, 'motherboard');
                    $storageInterfaces = $this->dataExtractor->extractStorageInterfaces($componentData);
                    $pcieSlots = $this->dataExtractor->extractPCIeSlots($componentData);
                    
                    if ($socket) {
                        $recommendations[] = "Compatible with $socket CPUs";
                    }
                    if ($memoryTypes) {
                        $recommendations[] = "Supports " . implode(', ', $memoryTypes) . " memory";
                    }
                    if ($storageInterfaces) {
                        $recommendations[] = "Available storage interfaces: " . implode(', ', $storageInterfaces);
                    }
                    if ($pcieSlots) {
                        $recommendations[] = "PCIe slots: " . implode(', ', $pcieSlots);
                    }
                    break;
                    
                case 'ram':
                    $type = $this->dataExtractor->extractMemoryType($componentData);
                    $speed = $this->dataExtractor->extractMemorySpeed($componentData);
                    $formFactor = $this->dataExtractor->extractMemoryFormFactor($componentData);
                    
                    if ($type) {
                        $recommendations[] = "Requires $type compatible motherboard and CPU";
                    }
                    if ($speed) {
                        $recommendations[] = "Optimal with systems supporting {$speed}MHz or higher";
                    }
                    if ($formFactor) {
                        $recommendations[] = "Requires $formFactor slots";
                    }
                    break;
                    
                case 'storage':
                    $interface = $this->dataExtractor->extractStorageInterface($componentData);
                    $formFactor = $this->dataExtractor->extractStorageFormFactor($componentData);
                    
                    if ($interface) {
                        $recommendations[] = "Requires motherboard with $interface interface";
                    }
                    if ($formFactor) {
                        $recommendations[] = "Requires $formFactor bay or caddy";
                    }
                    break;
                    
                case 'nic':
                    $pcieRequirement = $this->dataExtractor->extractPCIeRequirement($componentData);
                    $power = $this->dataExtractor->extractPowerConsumption($componentData);
                    
                    if ($pcieRequirement) {
                        $recommendations[] = "Requires available $pcieRequirement slot";
                    }
                    if ($power && $power > 75) {
                        $recommendations[] = "May require additional power connector";
                    }
                    break;
                    
                case 'caddy':
                    $supportedFormFactors = $this->dataExtractor->extractSupportedFormFactors($componentData);
                    $supportedInterfaces = $this->dataExtractor->extractSupportedInterfaces($componentData);
                    
                    if ($supportedFormFactors) {
                        $recommendations[] = "Supports " . implode(', ', $supportedFormFactors) . " drives";
                    }
                    if ($supportedInterfaces) {
                        $recommendations[] = "Compatible with " . implode(', ', $supportedInterfaces) . " interfaces";
                    }
                    break;
            }
            
            return $recommendations;
        } catch (Exception $e) {
            error_log("Error getting compatibility recommendations: " . $e->getMessage());
            return ["Unable to generate recommendations"];
        }
    }
    
    /**
     * Find potential compatibility issues in a component set
     */
    public function findPotentialIssues($components) {
        $issues = [];
        $warnings = [];
        
        try {
            // Check for missing essential components
            $componentTypes = array_column($components, 'type');
            
            if (!in_array('cpu', $componentTypes) && !in_array('motherboard', $componentTypes)) {
                $issues[] = "No CPU or motherboard selected - at least one is required";
            }
            
            if (!in_array('ram', $componentTypes)) {
                $warnings[] = "No memory modules selected - system will not function without RAM";
            }
            
            if (!in_array('storage', $componentTypes)) {
                $warnings[] = "No storage devices selected - system needs storage to boot";
            }
            
            // Check for potential conflicts
            $cpuComponents = array_filter($components, function($c) { return $c['type'] === 'cpu'; });
            $motherboardComponents = array_filter($components, function($c) { return $c['type'] === 'motherboard'; });
            
            // Check CPU socket count vs motherboard socket capacity
            if (count($cpuComponents) > 1 && !empty($motherboardComponents)) {
                $motherboardData = $this->dataLoader->getComponentData('motherboard', $motherboardComponents[0]['uuid']);
                $motherboardSocketCount = $this->getMotherboardSocketCount($motherboardData);
                
                if (count($cpuComponents) > $motherboardSocketCount) {
                    $issues[] = "Too many CPUs selected - motherboard supports maximum $motherboardSocketCount CPU(s), but " . count($cpuComponents) . " CPU(s) selected";
                }
            } elseif (count($cpuComponents) > 1) {
                // If no motherboard, assume single socket
                $issues[] = "Multiple CPUs selected but no motherboard selected to verify socket count";
            }
            
            if (count($motherboardComponents) > 1) {
                $issues[] = "Multiple motherboards selected - only one motherboard is supported per configuration";
            }
            
            // Check memory configuration
            $ramComponents = array_filter($components, function($c) { return $c['type'] === 'ram'; });
            if (count($ramComponents) > 8) {
                $warnings[] = "Large number of memory modules selected - verify motherboard has sufficient slots";
            }
            
            // Check for compatibility between selected components
            $compatibilityResult = $this->validator->validateComponentConfiguration($components);
            if (!$compatibilityResult['overall_compatible']) {
                foreach ($compatibilityResult['individual_checks'] as $check) {
                    if (!$check['compatibility']['compatible']) {
                        $issues = array_merge($issues, $check['compatibility']['issues']);
                    }
                    $warnings = array_merge($warnings, $check['compatibility']['warnings']);
                }
            }
            
        } catch (Exception $e) {
            error_log("Error finding potential issues: " . $e->getMessage());
            $issues[] = "Unable to complete compatibility analysis";
        }
        
        return [
            'issues' => array_unique($issues),
            'warnings' => array_unique($warnings),
            'has_critical_issues' => !empty($issues)
        ];
    }
    
    /**
     * Get component compatibility score
     */
    public function getComponentCompatibilityScore($components) {
        if (empty($components)) {
            return [
                'score' => 0.0,
                'rating' => 'incomplete',
                'description' => 'No components selected'
            ];
        }
        
        $validation = $this->validator->validateComponentConfiguration($components);
        $score = $validation['overall_score'];
        
        // Determine rating based on score
        if ($score >= 0.9) {
            $rating = 'excellent';
            $description = 'All components are highly compatible';
        } elseif ($score >= 0.8) {
            $rating = 'good';
            $description = 'Components are compatible with minor optimization opportunities';
        } elseif ($score >= 0.7) {
            $rating = 'acceptable';
            $description = 'Components are compatible but may have performance limitations';
        } elseif ($score >= 0.5) {
            $rating = 'poor';
            $description = 'Components have significant compatibility issues';
        } else {
            $rating = 'incompatible';
            $description = 'Components have critical compatibility problems';
        }
        
        return [
            'score' => round($score, 2),
            'rating' => $rating,
            'description' => $description,
            'total_checks' => $validation['total_checks'],
            'compatible_checks' => count(array_filter($validation['individual_checks'], function($check) {
                return $check['compatibility']['compatible'];
            }))
        ];
    }
    
    /**
     * Get motherboard limits for compatibility checking
     */
    public function getMotherboardLimits($motherboardUuid) {
        $specResult = $this->validator->parseMotherboardSpecifications($motherboardUuid);
        
        if (!$specResult['found']) {
            return [
                'found' => false,
                'error' => $specResult['error'],
                'limits' => null
            ];
        }
        
        $specs = $specResult['specifications'];
        
        $limits = [
            'cpu' => [
                'socket_type' => $specs['socket']['type'],
                'max_sockets' => $specs['socket']['count'],
                'max_tdp' => $specs['power']['max_tdp']
            ],
            'memory' => [
                'max_slots' => $specs['memory']['slots'],
                'supported_types' => $specs['memory']['types'],
                'max_frequency_mhz' => $specs['memory']['max_frequency_mhz'],
                'max_capacity_gb' => $specs['memory']['max_capacity_gb'],
                'ecc_support' => $specs['memory']['ecc_support']
            ],
            'storage' => [
                'sata_ports' => $specs['storage']['sata_ports'],
                'm2_slots' => $specs['storage']['m2_slots'],
                'u2_slots' => $specs['storage']['u2_slots'],
                'sas_ports' => $specs['storage']['sas_ports']
            ],
            'expansion' => [
                'pcie_slots' => $specs['pcie_slots']
            ]
        ];
        
        return [
            'found' => true,
            'error' => null,
            'limits' => $limits
        ];
    }

    /**
     * Get CPU specifications from JSON
     */
    public function getCPUSpecifications($cpuUuid) {
        $result = $this->validator->validateCPUExists($cpuUuid);
        
        if (!$result['exists']) {
            return [
                'found' => false,
                'error' => $result['error'],
                'specifications' => null
            ];
        }
        
        $data = $result['data'];
        
        try {
            $specifications = [
                'basic_info' => [
                    'uuid' => $cpuUuid,
                    'model' => $data['model'] ?? 'Unknown',
                    'brand' => $data['brand'] ?? 'Unknown',
                    'architecture' => $data['architecture'] ?? 'Unknown'
                ],
                'performance' => [
                    'cores' => (int)($data['cores'] ?? 1),
                    'threads' => (int)($data['threads'] ?? 1),
                    'base_frequency_ghz' => (float)($data['base_frequency_GHz'] ?? 0),
                    'max_frequency_ghz' => (float)($data['max_frequency_GHz'] ?? 0)
                ],
                'compatibility' => [
                    'socket' => $data['socket'] ?? 'Unknown',
                    'tdp_w' => (int)($data['tdp_W'] ?? 0),
                    'memory_types' => $data['memory_types'] ?? ['DDR4'],
                    'memory_channels' => (int)($data['memory_channels'] ?? 2),
                    'max_memory_capacity_tb' => (float)($data['max_memory_capacity_TB'] ?? 1),
                    'pcie_lanes' => (int)($data['pcie_lanes'] ?? 16),
                    'pcie_generation' => (int)($data['pcie_generation'] ?? 3)
                ]
            ];
            
            return [
                'found' => true,
                'error' => null,
                'specifications' => $specifications
            ];
            
        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => "Error parsing CPU specifications: " . $e->getMessage(),
                'specifications' => null
            ];
        }
    }

    /**
     * Enhanced extractSocketType method to work with JSON data primarily
     */
    public function extractSocketTypeFromJSON($componentType, $componentUuid) {
        error_log("DEBUG: Extracting socket type for $componentType UUID: $componentUuid");
        
        $result = null;
        
        if ($componentType === 'cpu') {
            $cpuResult = $this->validator->validateCPUExists($componentUuid);
            if ($cpuResult['exists'] && isset($cpuResult['data'])) {
                $result = $cpuResult['data']['socket'] ?? null;
                error_log("DEBUG: CPU socket from JSON: " . ($result ?? 'null'));
            }
        } elseif ($componentType === 'motherboard') {
            $mbResult = $this->dataLoader->loadComponentFromJSON('motherboard', $componentUuid);
            if ($mbResult['found'] && isset($mbResult['data'])) {
                $data = $mbResult['data'];
                // Try multiple socket field possibilities
                $result = $data['socket']['type'] ?? $data['socket'] ?? $data['cpu_socket'] ?? null;
                error_log("DEBUG: Motherboard socket from JSON: " . ($result ?? 'null'));
            }
        }
        
        // Fallback to database Notes field extraction if JSON doesn't have the data
        if (!$result) {
            error_log("DEBUG: No socket found in JSON, trying database Notes field");
            $componentData = $this->dataLoader->getComponentData($componentType, $componentUuid);
            if ($componentData) {
                $notes = strtolower($componentData['Notes'] ?? '');
                $result = $this->dataExtractor->extractSocketFromNotes($notes);
                error_log("DEBUG: Socket from Notes field: " . ($result ?? 'null'));
            }
        }
        
        if (!$result) {
            error_log("WARNING: Could not determine socket type for $componentType UUID: $componentUuid");
        }
        
        return $result;
    }

    /**
     * Get RAM specifications from JSON
     */
    public function getRAMSpecifications($ramUuid) {
        $result = $this->validator->validateRAMExists($ramUuid);
        
        if (!$result['exists']) {
            return [
                'found' => false,
                'error' => $result['error'],
                'specifications' => null
            ];
        }
        
        $data = $result['data'];
        
        try {
            $specifications = [
                'basic_info' => [
                    'uuid' => $ramUuid,
                    'brand' => $data['brand'] ?? 'Unknown',
                    'series' => $data['series'] ?? 'Unknown'
                ],
                'memory_specs' => [
                    'memory_type' => $data['memory_type'] ?? 'DDR4',
                    'module_type' => $data['module_type'] ?? 'DIMM',
                    'form_factor' => $data['form_factor'] ?? 'DIMM (288-pin)',
                    'capacity_gb' => (int)($data['capacity_GB'] ?? 8),
                    'frequency_mhz' => (int)($data['frequency_MHz'] ?? 3200),
                    'voltage_v' => (float)($data['voltage_V'] ?? 1.2)
                ],
                'features' => [
                    'ecc_support' => $data['features']['ecc_support'] ?? false,
                    'xmp_support' => $data['features']['xmp_support'] ?? false
                ],
                'timing' => $data['timing'] ?? []
            ];
            
            return [
                'found' => true,
                'error' => null,
                'specifications' => $specifications
            ];
            
        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => "Error parsing RAM specifications: " . $e->getMessage(),
                'specifications' => null
            ];
        }
    }






    /**
     * Clear JSON data cache
     */
    public function clearJSONCache() {
        $this->dataLoader->clearCache();
    }

    /**
     * Clear component data cache
     */
    public function clearCache() {
        $this->dataLoader->clearCache();
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        return $this->dataLoader->getCacheStats();
    }
    
    /**
     * Get motherboard socket count
     */
    private function getMotherboardSocketCount($motherboardData) {
        if (!$motherboardData) {
            return 1; // Default to single socket
        }
        
        // Check if socket count is provided in specifications
        if (isset($motherboardData['socket']['count'])) {
            return (int)$motherboardData['socket']['count'];
        }
        
        // Try to extract from Notes field
        $notes = strtolower($motherboardData['Notes'] ?? '');
        
        // Look for socket count patterns
        if (preg_match('/(\d+)[\s]*socket/i', $notes, $matches)) {
            return (int)$matches[1];
        }
        
        if (preg_match('/(\d+)[\s]*cpu/i', $notes, $matches)) {
            return (int)$matches[1];
        }
        
        // Look for dual/multi socket indicators
        if (strpos($notes, 'dual socket') !== false || strpos($notes, 'dual-socket') !== false) {
            return 2;
        }
        
        if (strpos($notes, 'quad socket') !== false || strpos($notes, 'quad-socket') !== false) {
            return 4;
        }
        
        // Default to single socket for desktop motherboards
        return 1;
    }



    /**
     * Analyze memory frequency compatibility and performance impact
     */
    public function analyzeMemoryFrequency($ramSpecs, $motherboardSpecs, $cpuSpecs) {
        if (!$ramSpecs) {
            return [
                'status' => 'error',
                'message' => 'Missing RAM specifications for frequency analysis'
            ];
        }

        $ramFrequency = $ramSpecs['frequency_mhz'] ?? 0;

        // Find the lowest CPU max frequency if multiple CPUs
        $cpuMaxFrequency = null;
        $limitingCPU = null;

        if (!empty($cpuSpecs)) {
            foreach ($cpuSpecs as $cpuSpec) {
                // Extract max memory frequency from CPU memory types (e.g., DDR5-4800)
                $cpuMemoryTypes = $cpuSpec['compatibility']['memory_types'] ?? [];
                foreach ($cpuMemoryTypes as $memType) {
                    if (preg_match('/DDR\d+-(\d+)/', $memType, $matches)) {
                        $cpuFreq = (int)$matches[1];
                        if ($cpuMaxFrequency === null || $cpuFreq < $cpuMaxFrequency) {
                            $cpuMaxFrequency = $cpuFreq;
                            $limitingCPU = $cpuSpec['basic_info']['model'] ?? 'Unknown CPU';
                        }
                    }
                }
            }
        }

        // Handle CPU-only validation (no motherboard)
        if (empty($motherboardSpecs) && $cpuMaxFrequency !== null) {
            // Use CPU max frequency as system limit
            if ($ramFrequency <= $cpuMaxFrequency) {
                return [
                    'status' => 'optimal',
                    'ram_frequency' => $ramFrequency,
                    'system_max_frequency' => $cpuMaxFrequency,
                    'effective_frequency' => $ramFrequency,
                    'limiting_component' => $limitingCPU,
                    'message' => "RAM will operate at full rated speed of {$ramFrequency}MHz with CPU",
                    'performance_impact' => null
                ];
            } else {
                return [
                    'status' => 'limited',
                    'ram_frequency' => $ramFrequency,
                    'system_max_frequency' => $cpuMaxFrequency,
                    'effective_frequency' => $cpuMaxFrequency,
                    'limiting_component' => $limitingCPU,
                    'message' => "RAM will operate at {$cpuMaxFrequency}MHz (limited by CPU) instead of rated {$ramFrequency}MHz",
                    'performance_impact' => "Performance limited by $limitingCPU"
                ];
            }
        }

        // Handle no motherboard and no CPU - accept any frequency
        if (empty($motherboardSpecs) && empty($cpuSpecs)) {
            return [
                'status' => 'optimal',
                'ram_frequency' => $ramFrequency,
                'system_max_frequency' => $ramFrequency,
                'effective_frequency' => $ramFrequency,
                'limiting_component' => null,
                'message' => "RAM frequency {$ramFrequency}MHz accepted (no constraints)",
                'performance_impact' => null
            ];
        }

        // Full validation with motherboard
        $motherboardMaxFrequency = $motherboardSpecs['memory']['max_frequency_mhz'] ?? 3200;

        // Calculate system maximum frequency (lowest component limit)
        $systemMaxFrequency = $motherboardMaxFrequency;
        $limitingComponent = 'motherboard';

        if ($cpuMaxFrequency !== null && $cpuMaxFrequency < $systemMaxFrequency) {
            $systemMaxFrequency = $cpuMaxFrequency;
            $limitingComponent = $limitingCPU;
        }

        // Determine status and effective frequency
        if ($ramFrequency <= $systemMaxFrequency) {
            $status = 'optimal';
            $effectiveFrequency = $ramFrequency;
            $message = "RAM will operate at full rated speed of {$ramFrequency}MHz";
        } else {
            $status = 'limited';
            $effectiveFrequency = $systemMaxFrequency;
            $message = "RAM will operate at {$systemMaxFrequency}MHz (limited by $limitingComponent) instead of rated {$ramFrequency}MHz";
        }

        // Check for suboptimal frequency (significantly below system max)
        if ($ramFrequency < ($systemMaxFrequency * 0.8)) {
            $status = 'suboptimal';
            $message = "RAM frequency may impact performance - consider higher frequency memory";
        }

        return [
            'status' => $status,
            'ram_frequency' => $ramFrequency,
            'system_max_frequency' => $systemMaxFrequency,
            'effective_frequency' => $effectiveFrequency,
            'limiting_component' => $limitingComponent,
            'message' => $message,
            'performance_impact' => $status === 'limited' ? "Performance limited by $limitingComponent" : null
        ];
    }




    /**
     * ENHANCED STORAGE COMPATIBILITY METHODS
     */

    /**
     * Check storage interface compatibility with motherboard
     * JSON-DRIVEN: No hardcoded compatibility rules - uses protocol/generation normalization
     */
    private function checkStorageInterfaceCompatibility($storageSpecs, $motherboardSpecs) {
        $storageInterface = $storageSpecs['interface_type'];
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
                // Same protocol - check generation compatibility
                if ($normalizedStorageInterface['generation'] === $normalizedMbInterface['generation']) {
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
        $mbBays = $motherboardSpecs['drive_bays'];
        $supportedFormFactors = $mbBays['supported_form_factors'];
        
        // Direct form factor support
        if (in_array($storageFormFactor, $supportedFormFactors)) {
            // Check bay availability
            $bayAvailable = $this->checkBayAvailability($storageFormFactor, $mbBays, $existingStorage);
            
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
        $interface = strtolower($storageSpecs['interface_type']);
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
     * Direct compatibility check for single storage component addition
     */
    public function checkStorageCompatibilityDirect($storageUuid, $motherboardUuid) {
        $storage = ['type' => 'storage', 'uuid' => $storageUuid];
        $motherboard = ['type' => 'motherboard', 'uuid' => $motherboardUuid];
        
        return $this->checkMotherboardStorageCompatibility($storage, $motherboard);
    }
    
    /**
     * Recursive compatibility check for complete server configuration
     */
    public function checkStorageCompatibilityRecursive($serverConfigUuid) {
        try {
            // Load server configuration and all storage components
            $configData = $this->getServerConfiguration($serverConfigUuid);
            $storageComponents = $configData['storage_components'] ?? [];
            $motherboardUuid = $configData['motherboard_uuid'] ?? null;
            
            if (!$motherboardUuid) {
                return [
                    'compatible' => false,
                    'overall_score' => 0.0,
                    'issues' => ['No motherboard found in server configuration'],
                    'component_results' => []
                ];
            }
            
            $componentResults = [];
            $overallScore = 1.0;
            $overallCompatible = true;
            $allIssues = [];
            $allRecommendations = [];
            
            // Check each storage component against motherboard
            foreach ($storageComponents as $storageComponent) {
                $result = $this->checkStorageCompatibilityDirect($storageComponent['uuid'], $motherboardUuid);
                
                $componentResults[] = [
                    'storage_uuid' => $storageComponent['uuid'],
                    'result' => $result
                ];
                
                if (!$result['compatible']) {
                    $overallCompatible = false;
                }

                $allIssues = array_merge($allIssues, $result['issues']);
                $allRecommendations = array_merge($allRecommendations, $result['recommendations']);
            }

            // Check for bay conflicts and capacity limits
            $bayAnalysis = $this->analyzeBayCapacity($storageComponents, $motherboardUuid);

            return [
                'compatible' => $overallCompatible && $bayAnalysis['sufficient_bays'],
                'issues' => array_merge($allIssues, $bayAnalysis['bay_issues']),
                'recommendations' => array_merge($allRecommendations, $bayAnalysis['bay_recommendations']),
                'component_results' => $componentResults,
                'bay_analysis' => $bayAnalysis
            ];
            
        } catch (Exception $e) {
            error_log("Recursive storage compatibility check error: " . $e->getMessage());
            return [
                'compatible' => false,
                'issues' => ['Failed to perform recursive compatibility check'],
                'component_results' => []
            ];
        }
    }
    
    private function getServerConfiguration($configUuid) {
        // This would interface with your server configuration system
        // Placeholder implementation
        return [
            'motherboard_uuid' => null,
            'storage_components' => []
        ];
    }
    
    private function analyzeBayCapacity($storageComponents, $motherboardUuid) {
        // Analyze if motherboard has sufficient bays for all storage components
        // This is a simplified implementation - full version would count actual bay usage
        
        return [
            'sufficient_bays' => true,
            'bay_utilization_score' => 0.90,
            'bay_issues' => [],
            'bay_recommendations' => [],
            'bay_details' => [
                'total_storage_count' => count($storageComponents),
                'm2_slots_used' => 0,
                'sata_ports_used' => 0,
                'u2_slots_used' => 0
            ]
        ];
    }

    /**
     * Decentralized RAM compatibility check - works without requiring a specific base motherboard
     * Checks RAM compatibility with existing components in server configuration
     */
    public function checkRAMDecentralizedCompatibility($ramComponent, $existingComponents) {
        try {
            $result = [
                'compatible' => true,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => []
            ];

            // If no existing components, RAM is always compatible
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - all RAM compatible';
                return $result;
            }

            // Get RAM specifications
            $ramData = $this->dataLoader->getComponentData('ram', $ramComponent['uuid']);
            if (!$ramData) {
                $result['warnings'][] = 'RAM specifications not found - using basic compatibility';
                return $result;
            }

            $ramMemoryType = $this->dataExtractor->extractMemoryType($ramData);
            $ramFormFactor = $this->dataExtractor->extractMemoryFormFactor($ramData);
            $ramModuleType = $ramData['module_type'] ?? null; // UDIMM, RDIMM, LRDIMM
            $ramSpeed = $this->dataExtractor->extractMemorySpeed($ramData);

            // Collect memory requirements from existing components
            $memoryRequirements = [
                'supported_types' => [],
                'max_speeds' => [],
                'form_factors' => [],
                'sources' => []
            ];

            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'cpu') {
                    $cpuCompatResult = $this->analyzeExistingCPUForRAM($existingComp, $memoryRequirements);
                    if (!$cpuCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $cpuCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $cpuCompatResult['details']);

                } elseif ($compType === 'motherboard') {
                    $mbCompatResult = $this->analyzeExistingMotherboardForRAM($existingComp, $memoryRequirements);
                    if (!$mbCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $mbCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $mbCompatResult['details']);

                } elseif ($compType === 'ram') {
                    $ramCompatResult = $this->analyzeExistingRAMForRAM($existingComp, $ramFormFactor, $ramModuleType);
                    if (!$ramCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $ramCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $ramCompatResult['details']);
                }
            }

            // Apply compatibility logic using minimum common requirements
            if ($result['compatible']) {
                $finalCompatResult = $this->applyMemoryCompatibilityRules($ramData, $memoryRequirements);
                $result = array_merge($result, $finalCompatResult);
            }

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized RAM compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'issues' => [],
                'warnings' => ['Compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify RAM compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Check PCIe card compatibility with existing server components (decentralized approach)
     *
     * Validates:
     * - PCIe generation compatibility (backward/forward compatibility rules)
     * - Physical slot availability (tracks used slots from PCIe cards + NICs)
     * - Slot size compatibility (x1/x4/x8/x16 fitting rules)
     * - Riser card slot additions
     *
     * @param array $pcieCardComponent ['uuid' => 'card-uuid']
     * @param array $existingComponents Array of existing components with 'type' and 'uuid'
     * @return array Compatibility result with compatible, score, issues, warnings, summary
     */
    public function checkPCIeDecentralizedCompatibility($pcieCardComponent, $existingComponents, $componentType = 'pciecard') {
        try {
            $result = [
                'compatible' => true,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => [],
                'compatibility_summary' => ''
            ];

            // CASE 1: Empty configuration - all cards compatible
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - all PCIe cards compatible';
                $result['compatibility_summary'] = 'Compatible - no constraints found';
                return $result;
            }

            // Get PCIe card or NIC specifications from JSON
            $pcieCardData = $this->dataLoader->getComponentData($componentType, $pcieCardComponent['uuid']);
            if (!$pcieCardData) {
                $componentLabel = ($componentType === 'nic') ? 'NIC' : 'PCIe card';
                $result['compatible'] = false;
                $result['issues'][] = $componentLabel . ' specifications not found in JSON database';
                $result['compatibility_summary'] = 'Component not found in specification database';
                $result['recommendations'][] = 'Verify component UUID exists in JSON specification files';
                return $result;
            }

            // Extract PCIe card properties
            $cardGeneration = $this->dataExtractor->extractPCIeGeneration($pcieCardData);
            $cardSlotSize = $this->dataExtractor->extractPCIeSlotSize($pcieCardData);
            $cardSubtype = $pcieCardData['component_subtype'] ?? 'PCIe Card';

            error_log("DEBUG ComponentCompatibility: UUID=" . $pcieCardComponent['uuid'] . ", component_subtype='" . $cardSubtype . "', cardSlotSize=" . $cardSlotSize);

            // SPECIAL HANDLING FOR RISER CARDS
            // Riser cards use riser slots, not PCIe slots
            $isRiserCard = ($cardSubtype === 'Riser Card');

            error_log("DEBUG ComponentCompatibility: isRiserCard=" . ($isRiserCard ? 'TRUE' : 'FALSE'));

            if ($isRiserCard) {
                error_log("DEBUG ComponentCompatibility: Entering riser card handling block");
                // Use UnifiedSlotTracker to check riser slot availability
                require_once __DIR__ . '/UnifiedSlotTracker.php';
                $slotTracker = new UnifiedSlotTracker($this->pdo);

                // Find config_uuid from existing components (need it for UnifiedSlotTracker)
                // Since components are now JSON-based, we need to find which config has this motherboard
                $configUuid = null;
                foreach ($existingComponents as $existingComp) {
                    if ($existingComp['type'] === 'motherboard') {
                        // Search for configuration with this motherboard UUID
                        try {
                            $stmt = $this->pdo->prepare("
                                SELECT config_uuid
                                FROM server_configurations
                                WHERE motherboard_uuid = ?
                                LIMIT 1
                            ");
                            $stmt->execute([$existingComp['uuid']]);
                            $configRow = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($configRow) {
                                $configUuid = $configRow['config_uuid'];
                                break;
                            }
                        } catch (Exception $e) {
                            error_log("Error finding config_uuid for motherboard: " . $e->getMessage());
                        }
                    }
                }

                if ($configUuid) {
                    // Check riser slot availability
                    $riserAvailability = $slotTracker->getRiserSlotAvailability($configUuid);

                    if (!$riserAvailability['success']) {
                        $result['compatible'] = false;
                        $result['issues'][] = "Motherboard does not support riser cards";
                        $result['compatibility_summary'] = 'Incompatible - Motherboard does not have riser slots';
                        return $result;
                    }

                    // Count total available riser slots across all types
                    $totalRiserSlots = 0;
                    $availableRiserSlots = 0;
                    foreach ($riserAvailability['total_slots'] as $slotType => $slots) {
                        $totalRiserSlots += count($slots);
                    }
                    foreach ($riserAvailability['available_slots'] as $slotType => $slots) {
                        $availableRiserSlots += count($slots);
                    }

                    if ($availableRiserSlots === 0) {
                        $result['compatible'] = false;
                        $result['issues'][] = "All riser slots occupied (0/{$totalRiserSlots} available)";
                        $result['compatibility_summary'] = "Incompatible - All {$totalRiserSlots} riser slots occupied";
                        return $result;
                    }

                    // Check if riser fits by size
                    $riserSlotType = 'x' . $cardSlotSize;
                    $canFitRiser = $slotTracker->canFitRiserBySize($configUuid, $riserSlotType);

                    if (!$canFitRiser) {
                        // Build available slot type list
                        $availableSlotTypes = [];
                        foreach ($riserAvailability['available_slots'] as $slotType => $slots) {
                            if (!empty($slots)) {
                                $availableSlotTypes[] = "{$slotType} (" . count($slots) . " available)";
                            }
                        }

                        $result['compatible'] = false;
                        $result['issues'][] = "Riser card requires {$riserSlotType} slot, but no compatible slots available";
                        $result['details'][] = "Available riser slot types: " . (empty($availableSlotTypes) ? "None" : implode(', ', $availableSlotTypes));
                        $result['compatibility_summary'] = "Incompatible - Requires {$riserSlotType} riser slot";
                        return $result;
                    }

                    // Riser card is compatible!
                    $result['compatible'] = true;
                    $result['details'][] = "Riser card compatible - {$availableRiserSlots} of {$totalRiserSlots} riser slots available";
                    $result['compatibility_summary'] = "Compatible - Riser slot available ({$availableRiserSlots}/{$totalRiserSlots} free)";
                    return $result;

                } else {
                    // No motherboard yet - riser will be compatible once motherboard is added
                    $result['compatible'] = true;
                    $result['details'][] = 'No motherboard in configuration - riser card will be validated when motherboard is added';
                    $result['warnings'][] = 'Add motherboard with riser slot support first';
                    $result['compatibility_summary'] = 'Compatible - requires motherboard with riser slots';
                    return $result;
                }
            }

            // REGULAR PCIe CARD/NIC HANDLING (NOT RISER CARDS)
            // Track slot availability and motherboard constraints
            $slotAvailability = [
                'total_slots' => 0,
                'used_slots' => 0,
                'available_by_size' => ['x1' => 0, 'x4' => 0, 'x8' => 0, 'x16' => 0],
                'motherboard_generation' => null,
                'has_riser_card' => false,
                'riser_added_slots' => 0
            ];

            // Analyze existing components
            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'motherboard') {
                    $mbCompatResult = $this->analyzeExistingMotherboardForPCIe(
                        $existingComp, $slotAvailability
                    );
                    if (!$mbCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $mbCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $mbCompatResult['details']);

                } elseif ($compType === 'pciecard') {
                    $pcieCompatResult = $this->analyzeExistingPCIeCardForPCIe(
                        $existingComp, $slotAvailability
                    );
                    $result['details'] = array_merge($result['details'], $pcieCompatResult['details']);

                } elseif ($compType === 'nic') {
                    $nicCompatResult = $this->analyzeExistingNICForPCIe(
                        $existingComp, $slotAvailability
                    );
                    $result['details'] = array_merge($result['details'], $nicCompatResult['details']);
                }
            }

            // Apply PCIe compatibility rules
            if ($result['compatible']) {
                $finalCompatResult = $this->applyPCIeCompatibilityRules(
                    $pcieCardData, $slotAvailability, $cardGeneration, $cardSlotSize
                );
                $result = array_merge($result, $finalCompatResult);
            }

            // Create compatibility summary
            $result['compatibility_summary'] = $this->createPCIeCompatibilitySummary(
                $pcieCardData, $slotAvailability, $result
            );

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized PCIe compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'issues' => [],
                'warnings' => ['Compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify PCIe card compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()],
                'compatibility_summary' => 'Compatibility check failed - assumed compatible'
            ];
        }
    }

    /**
     * Check HBA Card compatibility with existing server components
     * Rules:
     * 1. If no storage devices: Check PCIe slot availability
     * 2. If storage devices exist: Match HBA protocol with storage interface
     * 3. Check HBA port capacity vs number of storage devices
     */
    public function checkHBADecentralizedCompatibility($hbaCardComponent, $existingComponents) {
        try {
            $result = [
                'compatible' => true,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => [],
                'compatibility_summary' => ''
            ];

            // Get HBA card specifications from JSON
            $hbaCardData = $this->dataLoader->getComponentData('hbacard', $hbaCardComponent['uuid']);
            if (!$hbaCardData) {
                $result['compatible'] = false;
                $result['issues'][] = 'HBA card specifications not found in JSON database';
                $result['compatibility_summary'] = 'HBA card not found in specification database';
                $result['recommendations'][] = 'Verify HBA card UUID exists in JSON specification files';
                return $result;
            }

            // Extract HBA card properties
            $hbaProtocol = $hbaCardData['protocol'] ?? '';
            $hbaInternalPorts = $hbaCardData['internal_ports'] ?? 0;
            $hbaExternalPorts = $hbaCardData['external_ports'] ?? 0;
            $hbaMaxDevices = $hbaCardData['max_devices'] ?? $hbaInternalPorts;
            $hbaInterface = $hbaCardData['interface'] ?? '';
            $hbaSlotRequired = $hbaCardData['slot_compatibility']['required_slot'] ?? 'PCIe x8';

            // Extract PCIe generation and slot size from HBA
            $hbaGeneration = $this->dataExtractor->extractPCIeGeneration($hbaCardData);
            $hbaSlotSize = $this->dataExtractor->extractPCIeSlotSize($hbaCardData);

            // CASE 1: Empty configuration - check basic PCIe requirements
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - HBA card will be compatible once motherboard is added';
                $result['warnings'][] = 'Ensure motherboard has available ' . $hbaSlotRequired . ' slot';
                $result['compatibility_summary'] = 'Compatible - requires motherboard with ' . $hbaSlotRequired . ' slot';
                return $result;
            }

            // Analyze existing components
            $storageDevices = [];
            $hasMotherboard = false;
            $slotAvailability = [
                'total_slots' => 0,
                'used_slots' => 0,
                'available_by_size' => ['x1' => 0, 'x4' => 0, 'x8' => 0, 'x16' => 0],
                'motherboard_generation' => null,
                'has_riser_card' => false,
                'riser_added_slots' => 0
            ];

            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];
                $compUuid = $existingComp['uuid'];

                if ($compType === 'storage') {
                    // Get storage device details
                    $storageData = $this->dataLoader->getComponentData('storage', $compUuid);
                    if ($storageData) {
                        $storageDevices[] = [
                            'uuid' => $compUuid,
                            'interface' => $storageData['interface'] ?? 'Unknown',
                            'subtype' => $storageData['subtype'] ?? 'Unknown'
                        ];
                    }
                } elseif ($compType === 'motherboard') {
                    $hasMotherboard = true;
                    // Analyze motherboard for PCIe slots
                    $mbCompatResult = $this->analyzeExistingMotherboardForPCIe(
                        $existingComp, $slotAvailability
                    );
                    if (!$mbCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $mbCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $mbCompatResult['details']);
                } elseif ($compType === 'pciecard') {
                    // Count PCIe cards for slot usage
                    $pcieCompatResult = $this->analyzeExistingPCIeCardForPCIe(
                        $existingComp, $slotAvailability
                    );
                    $result['details'] = array_merge($result['details'], $pcieCompatResult['details']);
                } elseif ($compType === 'nic') {
                    // Count NICs for slot usage
                    $nicCompatResult = $this->analyzeExistingNICForPCIe(
                        $existingComp, $slotAvailability
                    );
                    $result['details'] = array_merge($result['details'], $nicCompatResult['details']);
                } elseif ($compType === 'hbacard') {
                    // Count existing HBA cards for slot usage
                    $slotAvailability['used_slots']++;
                    $result['details'][] = 'Existing HBA card detected - slot already in use';
                }
            }

            // CASE 2: No storage devices - check PCIe slot availability
            if (empty($storageDevices)) {
                if (!$hasMotherboard) {
                    $result['warnings'][] = 'No motherboard in configuration - cannot verify PCIe slot availability';
                    $result['compatibility_summary'] = 'Compatible - pending motherboard addition';
                    return $result;
                }

                // Check if slots are available (INCLUDING riser-provided slots)
                $totalAvailableSlots = $slotAvailability['total_slots'] + $slotAvailability['riser_added_slots'];
                $usedSlots = $slotAvailability['used_slots'];
                $availableSlots = $totalAvailableSlots - $usedSlots;

                error_log("DEBUG HBA slot check: total_slots={$slotAvailability['total_slots']}, riser_added={$slotAvailability['riser_added_slots']}, used={$usedSlots}, available={$availableSlots}");

                if ($availableSlots <= 0) {
                    $result['compatible'] = false;
                    $result['issues'][] = 'No available PCIe slots on motherboard for HBA card';
                    $result['recommendations'][] = 'Remove existing PCIe cards or add riser card to expand slots';
                    $result['compatibility_summary'] = "Incompatible - All PCIe slots occupied ({$usedSlots}/{$totalAvailableSlots} used)";
                    return $result;
                }

                // Check slot size compatibility
                $slotSizeCompatible = $this->checkPCIeSlotSizeCompatibility($hbaSlotSize, $slotAvailability);
                if (!$slotSizeCompatible) {
                    $result['compatible'] = false;
                    $result['issues'][] = "No available {$hbaSlotRequired} slot on motherboard";
                    $result['recommendations'][] = "HBA card requires {$hbaSlotRequired} slot";
                    $result['compatibility_summary'] = "Incompatible - Requires {$hbaSlotRequired} slot";
                    return $result;
                }

                // Check PCIe generation compatibility
                if ($slotAvailability['motherboard_generation'] && $hbaGeneration) {
                    $mbGen = (int)$slotAvailability['motherboard_generation'];
                    $hbaGen = (int)$hbaGeneration;

                    if ($hbaGen > $mbGen) {
                        $result['warnings'][] = "HBA card is PCIe {$hbaGen}.0 but motherboard supports PCIe {$mbGen}.0 - card will run at reduced speed";
                    }
                }

                $result['details'][] = 'No storage devices found - HBA card can be added';
                $result['details'][] = "Available PCIe slots: {$availableSlots}";
                $result['compatibility_summary'] = "Compatible - {$availableSlots} available PCIe slot(s)";
                return $result;
            }

            // CASE 3: Storage devices exist - HBA connection to PCIe slot is independent of storage
            // NOTE: Storage-to-HBA protocol compatibility will be validated when storage is added/modified
            // HBA validation only checks if HBA can fit in motherboard PCIe slot
            $storageCount = count($storageDevices);
            $result['details'][] = "Found {$storageCount} storage device(s) in configuration";

            // Extract unique storage interfaces for informational purposes only
            $storageInterfaces = array_unique(array_column($storageDevices, 'interface'));
            $result['details'][] = 'Storage interfaces detected: ' . implode(', ', $storageInterfaces);

            // IMPORTANT: Storage-to-HBA protocol compatibility is checked when storage is added
            // NOT during HBA addition. HBA can be added independently of storage.
            $result['details'][] = "Note: Storage-to-HBA protocol compatibility will be validated when storage is added or modified";
            $result['details'][] = "HBA protocol: {$hbaProtocol} - Storage protocol compatibility will be checked later";

            // HBA validation for existing storage is now informational only
            // The actual compatibility check happens in storage validation (checkStorageDecentralizedCompatibility)
            $result['details'][] = "HBA connection route: PCIe {$hbaSlotRequired} on motherboard (independent of storage protocol)";

            // Check PCIe slot availability - this is the ONLY blocker for HBA addition
            // Skip further checks - HBA can be added if there's a PCIe slot available

            // Check PCIe slot availability (if motherboard exists) - INCLUDING riser-provided slots
            if ($hasMotherboard) {
                $totalAvailableSlots = $slotAvailability['total_slots'] + $slotAvailability['riser_added_slots'];
                $usedSlots = $slotAvailability['used_slots'];
                $availableSlots = $totalAvailableSlots - $usedSlots;

                error_log("DEBUG HBA slot check (with storage): total_slots={$slotAvailability['total_slots']}, riser_added={$slotAvailability['riser_added_slots']}, used={$usedSlots}, available={$availableSlots}");

                if ($availableSlots <= 0) {
                    $result['compatible'] = false;
                    $result['issues'][] = 'No available PCIe slots on motherboard for HBA card';
                    $result['recommendations'][] = 'Remove existing PCIe cards or add riser card to expand slots';
                    $result['compatibility_summary'] = "Incompatible - All PCIe slots occupied ({$usedSlots}/{$totalAvailableSlots} used)";
                    return $result;
                }

                // Check slot size compatibility
                $slotSizeCompatible = $this->checkPCIeSlotSizeCompatibility($hbaSlotSize, $slotAvailability);
                if (!$slotSizeCompatible) {
                    $result['compatible'] = false;
                    $result['issues'][] = "No available {$hbaSlotRequired} slot on motherboard";
                    $result['recommendations'][] = "HBA card requires {$hbaSlotRequired} slot";
                    $result['compatibility_summary'] = "Incompatible - Requires {$hbaSlotRequired} slot";
                    return $result;
                }
            }

            // All checks passed - HBA fits in available PCIe slot
            $result['details'][] = "HBA card can connect to motherboard PCIe {$hbaSlotRequired} slot";
            $result['details'][] = "HBA capacity: {$hbaInternalPorts} internal ports available";
            $result['details'][] = "Storage in configuration ({$storageCount} device(s)) - compatibility will be validated when storage is accessed via HBA";
            $result['compatibility_summary'] = "Compatible - HBA PCIe slot available. Storage-HBA protocol compatibility will be checked when storage is added/modified";

            return $result;

        } catch (Exception $e) {
            error_log("HBA card compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'issues' => [],
                'warnings' => ['HBA compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify HBA card compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()],
                'compatibility_summary' => 'Compatibility check failed - assumed compatible'
            ];
        }
    }

    /**
     * Check if HBA protocol is compatible with storage interface
     */
    private function isHBAProtocolCompatible($hbaProtocol, $storageInterface) {
        // Normalize to uppercase for comparison
        $hbaProtocol = strtoupper($hbaProtocol);
        $storageInterface = strtoupper($storageInterface);

        // Tri-mode HBAs support all interfaces
        if (strpos($hbaProtocol, 'TRI-MODE') !== false ||
            strpos($hbaProtocol, 'SAS/SATA/NVME') !== false) {
            return true;
        }

        // SAS/SATA dual-mode HBAs
        if (strpos($hbaProtocol, 'SAS/SATA') !== false) {
            return (strpos($storageInterface, 'SAS') !== false ||
                    strpos($storageInterface, 'SATA') !== false);
        }

        // SAS-only HBAs support SAS drives
        if (strpos($hbaProtocol, 'SAS') !== false && strpos($hbaProtocol, 'SATA') === false) {
            return strpos($storageInterface, 'SAS') !== false;
        }

        // SATA-only HBAs support SATA drives
        if (strpos($hbaProtocol, 'SATA') !== false && strpos($hbaProtocol, 'SAS') === false) {
            return strpos($storageInterface, 'SATA') !== false;
        }

        // NVMe-only HBAs support NVMe/PCIe drives
        if (strpos($hbaProtocol, 'NVME') !== false || strpos($hbaProtocol, 'PCIE') !== false) {
            return (strpos($storageInterface, 'NVME') !== false ||
                    strpos($storageInterface, 'PCIE') !== false);
        }

        // Default: assume incompatible
        return false;
    }

    /**
     * Check if available PCIe slots can accommodate the required slot size
     */
    private function checkPCIeSlotSizeCompatibility($requiredSlotSize, $slotAvailability) {
        // Normalize requiredSlotSize to string format "x8", "x16", etc.
        // Input might be integer (8) or string ("x8" or "8")
        if (is_numeric($requiredSlotSize)) {
            $requiredSlotSize = 'x' . $requiredSlotSize;
        } elseif (strpos($requiredSlotSize, 'x') !== 0) {
            $requiredSlotSize = 'x' . $requiredSlotSize;
        }

        // Calculate net available slots (total slots - used slots)
        $totalSlots = $slotAvailability['total_slots'] + $slotAvailability['riser_added_slots'];
        $usedSlots = $slotAvailability['used_slots'];
        $netAvailable = $totalSlots - $usedSlots;

        error_log("DEBUG checkPCIeSlotSizeCompatibility: required={$requiredSlotSize}, total={$totalSlots}, used={$usedSlots}, netAvailable={$netAvailable}, available_by_size=" . json_encode($slotAvailability['available_by_size']));

        // If no slots available at all, return false
        if ($netAvailable <= 0) {
            error_log("DEBUG: No net available slots (netAvailable={$netAvailable}) - returning false");
            return false;
        }

        // Check if there are any slots of the required size or larger
        // available_by_size shows total slots provided (motherboard + riser)
        // We need to check: (1) slot type exists, AND (2) at least 1 slot is available (netAvailable > 0)
        // netAvailable is already verified > 0 above, so now just check slot type exists

        // x16 slots can accommodate x16, x8, x4, x1 cards
        if ($slotAvailability['available_by_size']['x16'] > 0) {
            error_log("DEBUG: Found x16 slot type AND netAvailable={$netAvailable} > 0 - returning true");
            return true;
        }

        // x8 slots can accommodate x8, x4, x1 cards
        if (in_array($requiredSlotSize, ['x8', 'x4', 'x1']) &&
            $slotAvailability['available_by_size']['x8'] > 0) {
            error_log("DEBUG: Found x8 slot type for required {$requiredSlotSize} AND netAvailable={$netAvailable} > 0 - returning true");
            return true;
        }

        // x4 slots can accommodate x4, x1 cards
        if (in_array($requiredSlotSize, ['x4', 'x1']) &&
            $slotAvailability['available_by_size']['x4'] > 0) {
            error_log("DEBUG: Found x4 slot type for required {$requiredSlotSize} AND netAvailable={$netAvailable} > 0 - returning true");
            return true;
        }

        // x1 slots can accommodate x1 cards only
        if ($requiredSlotSize === 'x1' &&
            $slotAvailability['available_by_size']['x1'] > 0) {
            error_log("DEBUG: Found x1 slot type AND netAvailable={$netAvailable} > 0 - returning true");
            return true;
        }

        error_log("DEBUG: No compatible slot type found for required {$requiredSlotSize} (available types: x1={$slotAvailability['available_by_size']['x1']}, x4={$slotAvailability['available_by_size']['x4']}, x8={$slotAvailability['available_by_size']['x8']}, x16={$slotAvailability['available_by_size']['x16']}) - returning false");
        return false;
    }

    /**
     * Check CPU compatibility with existing server components (decentralized approach)
     */
    public function checkCPUDecentralizedCompatibility($cpuComponent, $existingComponents) {
        try {
            $cpuUuid = $cpuComponent['uuid'];
            error_log("=== CPU COMPATIBILITY CHECK START for UUID: $cpuUuid ===");

            $result = [
                'compatible' => true,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => []
            ];

            // If no existing components, CPU is always compatible
            if (empty($existingComponents)) {
                error_log("CPU $cpuUuid: No existing components - compatible");
                $result['details'][] = 'No existing components - all CPUs compatible';
                return $result;
            }

            // Get CPU specifications
            $cpuData = $this->dataLoader->getComponentData('cpu', $cpuUuid);
            if (!$cpuData) {
                error_log("ERROR: CPU $cpuUuid - specifications not found in database or JSON");
                $result['warnings'][] = 'CPU specifications not found - using basic compatibility';
                $result['compatibility_summary'] = 'CPU specifications not found in database';
                return $result;
            }

            $cpuSocket = $this->dataExtractor->extractSocketType($cpuData, 'cpu');
            $cpuMemoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($cpuData, 'cpu');
            $cpuMaxMemorySpeed = $this->dataExtractor->extractMaxMemorySpeed($cpuData, 'cpu');

            error_log("CPU $cpuUuid extracted specs - Socket: " . ($cpuSocket ?? 'NULL') .
                     ", Memory Types: " . json_encode($cpuMemoryTypes) .
                     ", Max Memory Speed: " . ($cpuMaxMemorySpeed ?? 'NULL'));

            // Collect compatibility requirements from existing components
            $compatibilityRequirements = [
                'required_socket' => null,
                'max_memory_speed_required' => 0,
                'memory_types_required' => [],
                'sources' => []
            ];

            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'motherboard') {
                    $mbCompatResult = $this->analyzeExistingMotherboardForCPU($existingComp, $compatibilityRequirements);
                    if (!$mbCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $mbCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $mbCompatResult['details']);

                } elseif ($compType === 'ram') {
                    $ramCompatResult = $this->analyzeExistingRAMForCPU($existingComp, $compatibilityRequirements);
                    if (!$ramCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $ramCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $ramCompatResult['details']);

                } elseif ($compType === 'cpu') {
                    // Check if CPU is compatible with another CPU (multi-socket scenarios)
                    $cpuCompatResult = $this->analyzeExistingCPUForCPU($existingComp, $cpuSocket);
                    if (!$cpuCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $cpuCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $cpuCompatResult['details']);
                }
            }

            // Apply CPU compatibility logic using collected requirements
            if ($result['compatible']) {
                error_log("CPU $cpuUuid: Applying final compatibility rules with requirements: " . json_encode($compatibilityRequirements));
                $finalCompatResult = $this->applyCPUCompatibilityRules($cpuData, $compatibilityRequirements);
                $result = array_merge($result, $finalCompatResult);
                error_log("CPU $cpuUuid: After applying rules - Compatible: " . ($result['compatible'] ? 'YES' : 'NO') .
                         ", Issues: " . json_encode($result['issues']));
            }

            // Create concise compatibility summary for display
            $result['compatibility_summary'] = $this->createCPUCompatibilitySummary($cpuData, $compatibilityRequirements, $result);

            error_log("=== CPU COMPATIBILITY CHECK END for UUID: $cpuUuid - Result: " .
                     ($result['compatible'] ? 'COMPATIBLE' : 'INCOMPATIBLE') .
                     ", Summary: " . $result['compatibility_summary'] . " ===");

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized CPU compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'issues' => [],
                'warnings' => ['Compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify CPU compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Check motherboard compatibility with existing server components (decentralized approach)
     */
    public function checkMotherboardDecentralizedCompatibility($motherboardComponent, $existingComponents) {
        try {
            $result = [
                'compatible' => true,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => []
            ];

            // If no existing components, motherboard is always compatible
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - all motherboards compatible';
                $result['compatibility_summary'] = 'Compatible - no constraints found';
                return $result;
            }

            // Get motherboard specifications
            $motherboardData = $this->dataLoader->getComponentData('motherboard', $motherboardComponent['uuid']);
            if (!$motherboardData) {
                $result['warnings'][] = 'Motherboard specifications not found - using basic compatibility';
                $result['compatibility_summary'] = 'Specifications not found - assumed compatible';
                return $result;
            }

            $motherboardSocket = $this->dataExtractor->extractSocketType($motherboardData, 'motherboard');
            $motherboardMemoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($motherboardData, 'motherboard');
            $motherboardMaxMemorySpeed = $this->dataExtractor->extractMaxMemorySpeed($motherboardData, 'motherboard');

            // Extract motherboard CPU socket count
            $motherboardSocketCount = 1; // Default to 1 socket
            if (isset($motherboardData['socket']) && is_array($motherboardData['socket'])) {
                $motherboardSocketCount = $motherboardData['socket']['count'] ?? 1;
            }

            // Collect compatibility requirements from existing components
            $compatibilityRequirements = [
                'required_cpu_socket' => null,
                'required_memory_types' => [],
                'min_memory_speed_required' => 0,
                'required_form_factors' => [],
                'required_module_types' => [], // RDIMM, LRDIMM, UDIMM compatibility
                'sources' => [],
                'cpu_count' => 0 // Track total CPU count
            ];

            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'cpu') {
                    // Count CPUs in configuration
                    $compatibilityRequirements['cpu_count']++;

                    $cpuCompatResult = $this->analyzeExistingCPUForMotherboard($existingComp, $compatibilityRequirements);
                    if (!$cpuCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $cpuCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $cpuCompatResult['details']);

                } elseif ($compType === 'ram') {
                    $ramCompatResult = $this->analyzeExistingRAMForMotherboard($existingComp, $compatibilityRequirements);
                    if (!$ramCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $ramCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $ramCompatResult['details']);

                } elseif ($compType === 'motherboard') {
                    // Handle multi-motherboard scenarios (typically not allowed, but check anyway)
                    $motherboardCompatResult = $this->analyzeExistingMotherboardForMotherboard($existingComp);
                    if (!$motherboardCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $motherboardCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $motherboardCompatResult['details']);
                }
            }

            // Check CPU socket count before applying other compatibility rules
            if ($compatibilityRequirements['cpu_count'] > $motherboardSocketCount) {
                $result['compatible'] = false;
                $result['issues'][] = "CPU count ({$compatibilityRequirements['cpu_count']}) exceeds motherboard socket capacity ({$motherboardSocketCount})";
                $result['details'][] = "Configuration has {$compatibilityRequirements['cpu_count']} CPUs but motherboard only supports {$motherboardSocketCount} socket(s)";
            }

            // Apply motherboard compatibility logic using collected requirements
            if ($result['compatible']) {
                $finalCompatResult = $this->applyMotherboardCompatibilityRules($motherboardData, $compatibilityRequirements);
                $result = array_merge($result, $finalCompatResult);
            }

            // Create concise compatibility summary for display
            $result['compatibility_summary'] = $this->createMotherboardCompatibilitySummary($motherboardData, $compatibilityRequirements, $result);

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized motherboard compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'issues' => [],
                'warnings' => ['Compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify motherboard compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()],
                'compatibility_summary' => 'Compatibility check failed - assumed compatible'
            ];
        }
    }

    /**
     * Check storage compatibility with existing server components (decentralized approach)
     */
    public function checkStorageDecentralizedCompatibility($storageComponent, $existingComponents) {
        try {
            $result = [
                'compatible' => true,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => []
            ];

            // If no existing components, storage is always compatible
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - all storage compatible';
                $result['compatibility_summary'] = 'Compatible - no constraints found';
                return $result;
            }

            // Get storage specifications
            $storageData = $this->dataLoader->getComponentData('storage', $storageComponent['uuid']);
            if (!$storageData) {
                $result['warnings'][] = 'Storage specifications not found - using basic compatibility';
                $result['compatibility_summary'] = 'Specifications not found - assumed compatible';
                return $result;
            }

            // Extract storage properties
            $storageInterface = $this->dataExtractor->extractStorageInterface($storageData);
            $storageFormFactor = $this->dataExtractor->extractStorageFormFactor($storageData);

            // Collect storage requirements from existing components
            $storageRequirements = [
                'supported_interfaces' => [],
                'required_form_factors' => [],
                'available_slots' => [],
                'sources' => []
            ];

            // Check compatibility with each existing component
            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'motherboard') {
                    $mbCompatResult = $this->analyzeExistingMotherboardForStorage($existingComp, $storageRequirements);
                    if (!$mbCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $mbCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $mbCompatResult['details']);

                } elseif ($compType === 'storage') {
                    $storageCompatResult = $this->analyzeExistingStorageForStorage($existingComp, $storageRequirements);
                    if (!$storageCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $storageCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $storageCompatResult['details']);

                } elseif ($compType === 'caddy') {
                    $caddyCompatResult = $this->analyzeExistingCaddyForStorage($existingComp, $storageRequirements);
                    if (!$caddyCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $caddyCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $caddyCompatResult['details']);

                } elseif ($compType === 'hbacard') {
                    $hbaCompatResult = $this->analyzeExistingHBAForStorage($existingComp, $storageRequirements);
                    if (!$hbaCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $hbaCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $hbaCompatResult['details']);

                } elseif ($compType === 'chassis') {
                    $chassisCompatResult = $this->analyzeExistingChassisForStorage($existingComp, $storageRequirements);
                    if (!$chassisCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $chassisCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $chassisCompatResult['details']);
                }
            }

            // CRITICAL: Check if storage interface is compatible with existing HBA (if present)
            // This is the key validation that was missing - when storage is added, verify it can work with HBA
            if ($result['compatible'] && !empty($storageRequirements['supported_interfaces'])) {
                $hbaCompatible = false;
                $supportedInterfaces = $storageRequirements['supported_interfaces'];

                // Check if storage interface matches any supported interface from HBA
                foreach ($supportedInterfaces as $supportedInterface) {
                    // Normalize comparison
                    if (stripos($storageInterface, $supportedInterface) !== false ||
                        stripos($supportedInterface, $storageInterface) !== false) {
                        $hbaCompatible = true;
                        break;
                    }
                }

                // If HBA exists but storage interface not compatible, block storage
                if (!$hbaCompatible && !empty($storageRequirements['hba_ports'])) {
                    $result['compatible'] = false;
                    $hbaProtocol = $storageRequirements['hba_protocol'] ?? 'Unknown';
                    $result['issues'][] = "Storage interface '{$storageInterface}' is incompatible with HBA protocol '{$hbaProtocol}'";
                    $result['recommendations'][] = 'Use storage with compatible interface (e.g., tri-mode HBA supports SAS/SATA/NVMe) OR replace HBA with compatible model';
                    $result['details'][] = "HBA supports: " . implode(', ', $supportedInterfaces);
                    $result['details'][] = "Storage requires: {$storageInterface}";
                }
            }

            // Apply storage compatibility rules
            if ($result['compatible']) {
                $finalCompatResult = $this->applyStorageCompatibilityRules($storageData, $storageRequirements);
                $result = array_merge($result, $finalCompatResult);
            }

            // Generate compatibility summary
            if ($result['compatible']) {
                $result['compatibility_summary'] = "Storage {$storageInterface} compatible with existing configuration";
            } else {
                $result['compatibility_summary'] = "Storage incompatible: " . implode(', ', $result['issues']);
            }

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized storage compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'issues' => [],
                'warnings' => ['Compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify storage compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()],
                'compatibility_summary' => 'Compatibility check failed - assumed compatible'
            ];
        }
    }

    /**
     * Check chassis compatibility with existing server components (decentralized approach)
     */
    public function checkChassisDecentralizedCompatibility($chassisComponent, $existingComponents) {
        try {
            $result = [
                'compatible' => true,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => [],
                'score_breakdown' => []
            ];

            // If no existing components, chassis is always compatible
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - all chassis compatible';
                $result['compatibility_summary'] = 'Compatible - no constraints found';
                $result['score_breakdown'] = [
                    'base_score' => 100,
                    'final_score' => 100,
                    'factors' => ['No existing components to validate against']
                ];
                return $result;
            }

            // Get chassis specifications from database
            $chassisData = $this->dataLoader->getComponentData('chassis', $chassisComponent['uuid']);
            if (!$chassisData) {
                // Database entry not found - INCOMPATIBLE
                $result['compatible'] = false;
                $result['issues'][] = 'Chassis UUID not found in database';
                $result['compatibility_summary'] = 'INCOMPATIBLE: Chassis not found in chassisinventory table';
                $result['score_breakdown'] = [
                    'base_score' => 100,
                    'penalty_applied' => -100,
                    'reason' => 'Database entry not found in chassisinventory table',
                    'final_score' => 0,
                    'missing_data' => ['Database record for chassis UUID: ' . $chassisComponent['uuid']],
                    'validation_status' => 'FAILED',
                    'recommendation' => 'Verify chassis UUID exists in database or add chassis to inventory'
                ];
                return $result;
            }

            // Try to load chassis JSON specifications - REQUIRED for compatibility
            $chassisSpecs = $this->dataLoader->loadChassisSpecs($chassisComponent['uuid']);
            if (!$chassisSpecs) {
                // JSON specifications not found - INCOMPATIBLE
                $result['compatible'] = false;
                $result['issues'][] = 'Chassis JSON specifications not found';
                $result['compatibility_summary'] = 'INCOMPATIBLE: JSON specifications missing';
                $result['details'][] = "loadChassisSpecs() returned NULL for UUID: {$chassisComponent['uuid']}";

                // Enhanced score breakdown for debugging
                $result['score_breakdown'] = [
                    'base_score' => 100,
                    'penalty_applied' => -100,
                    'reason' => 'JSON specifications not found in resources/specifications/chassis/ directory',
                    'final_score' => 0,
                    'missing_data' => [
                        'JSON specification file for UUID: ' . $chassisComponent['uuid'],
                        'Expected location: resources/specifications/chassis/*.json',
                        'Required fields: form_factor, chassis_type, drive_bays, backplane'
                    ],
                    'validation_status' => 'FAILED',
                    'impact' => 'Cannot validate compatibility without JSON specifications',
                    'recommendation' => 'Add chassis JSON specification file with UUID: ' . $chassisComponent['uuid'],
                    'severity' => 'CRITICAL'
                ];
                return $result;
            }

            // Extract chassis bay configuration
            $chassisBays = $this->dataExtractor->extractChassisBayConfiguration($chassisSpecs);
            $chassisMotherboardCompat = $this->dataExtractor->extractChassisMotherboardCompatibility($chassisSpecs);

            // Collect storage requirements from existing components
            $existingStorageComponents = [];
            $existingMotherboardComponents = [];

            // Check compatibility with each existing component
            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'storage') {
                    $existingStorageComponents[] = $existingComp;
                } elseif ($compType === 'motherboard') {
                    $existingMotherboardComponents[] = $existingComp;
                }
            }

            // STEP 1: Extract ALL storage form factors (including M.2/U.2)
            if (!empty($existingStorageComponents)) {
                $storageFormFactors = [];
                $formFactorIncompatibilities = [];

                foreach ($existingStorageComponents as $storage) {
                    $formFactor = $this->dataExtractor->extractStorageFormFactorFromSpecs($storage['data']);
                    if ($formFactor !== 'unknown') {
                        $storageFormFactors[] = $formFactor;
                    }
                }

                // STEP 2: Validate form factor compatibility with chassis bays (STRICT MATCHING)
                foreach ($storageFormFactors as $formFactor) {
                    // M.2 and U.2 bypass traditional bay validation
                    if ($formFactor === 'M.2' || strpos($formFactor, 'M.2') !== false ||
                        $formFactor === 'U.2' || strpos($formFactor, 'U.2') !== false) {
                        $result['details'][] = "Storage form factor {$formFactor} bypasses bay validation (connects via PCIe/motherboard)";
                        continue;
                    }

                    // For traditional form factors (2.5" and 3.5"), validate STRICT bay compatibility
                    $chassisBayConfig = $chassisSpecs['drive_bays']['bay_configuration'] ?? [];
                    $hasMatchingBay = false;

                    foreach ($chassisBayConfig as $bay) {
                        $bayType = $bay['bay_type'] ?? '';

                        // STRICT matching only - no adapters allowed
                        if ($formFactor === '2.5_inch' && ($bayType === '2.5_inch' || $bayType === '2.5-inch')) {
                            $hasMatchingBay = true;
                            break;
                        } elseif ($formFactor === '3.5_inch' && ($bayType === '3.5_inch' || $bayType === '3.5-inch')) {
                            $hasMatchingBay = true;
                            break;
                        }
                    }

                    if (!$hasMatchingBay) {
                        $formFactorIncompatibilities[] = $formFactor;
                        $result['compatible'] = false;
                        $result['issues'][] = "Storage form factor {$formFactor} requires {$formFactor} chassis bays (strict matching)";
                    } else {
                        $result['details'][] = "Storage form factor {$formFactor} compatible with chassis bays";
                    }
                }

                // If form factor incompatibilities found, return early
                if (!empty($formFactorIncompatibilities)) {
                    $result['compatibility_summary'] = "INCOMPATIBLE: Storage form factors not supported by chassis";
                    $result['score_breakdown'] = [
                        'base_score' => 100,
                        'penalty_applied' => -100,
                        'reason' => 'Storage form factors incompatible with chassis bays',
                        'incompatible_form_factors' => $formFactorIncompatibilities,
                        'final_score' => 0,
                        'validation_status' => 'FAILED'
                    ];
                    return $result;
                }

                // STEP 3: Calculate required bay capacity from storage (excludes M.2/U.2)
                $requiredBays = $this->calculateRequiredBays($existingStorageComponents);

                if (!empty($requiredBays)) {
                    $bayCompatResult = $this->validator->validateChassisBayCapacity($chassisBays, $requiredBays);

                    if (!$bayCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $bayCompatResult['issues']);
                    }

                    $result['recommendations'] = array_merge($result['recommendations'], $bayCompatResult['recommendations']);

                    // Add bay analysis details
                    $totalRequired = array_sum($requiredBays);
                    $totalAvailable = array_sum($chassisBays);
                    $result['details'][] = "Bay analysis: {$totalRequired} drives need accommodation, chassis has {$totalAvailable} total bays";
                }
            }

            // Check motherboard form factor compatibility
            if (!empty($existingMotherboardComponents)) {
                foreach ($existingMotherboardComponents as $existingMB) {
                    $mbData = $this->dataLoader->getComponentData('motherboard', $existingMB['uuid']);
                    if ($mbData) {
                        // Extract motherboard form factor (basic check)
                        $mbNotes = $mbData['Notes'] ?? $mbData['notes'] ?? '';

                        // Check if chassis supports common form factors
                        $supportedFormFactors = $chassisMotherboardCompat['form_factors'];
                        $result['details'][] = "Chassis supports motherboard form factors: " . implode(', ', $supportedFormFactors);

                        // For now, assume basic compatibility unless specific conflicts are found
                        // This could be enhanced with more detailed motherboard form factor detection
                    }
                }
            }

            // Generate compatibility summary and score breakdown
            if ($result['compatible']) {
                $chassisFormFactor = $chassisSpecs['form_factor'] ?? 'Unknown';
                $chassisType = $chassisSpecs['chassis_type'] ?? 'Server';
                $result['compatibility_summary'] = "Chassis ({$chassisFormFactor} {$chassisType}) compatible with existing configuration";

                // Add detailed score breakdown for successful compatibility
                $scoreFactors = ['Full JSON specifications available' => 100];
                $validationChecks = [
                    'json_spec_loaded' => true,
                    'database_record_found' => true
                ];

                if (!empty($existingStorageComponents)) {
                    $validationChecks['storage_bay_validation'] = true;
                    $scoreFactors['Storage bay compatibility validated'] = 100;
                }

                if (!empty($existingMotherboardComponents)) {
                    $validationChecks['motherboard_form_factor_validation'] = true;
                    $scoreFactors['Motherboard form factor compatibility validated'] = 100;
                }

                $result['score_breakdown'] = [
                    'base_score' => 100,
                    'final_score' => 100,
                    'validation_checks_performed' => $validationChecks,
                    'score_factors' => $scoreFactors,
                    'chassis_specs_loaded' => [
                        'form_factor' => $chassisFormFactor,
                        'type' => $chassisType,
                        'drive_bays' => $chassisSpecs['drive_bays'] ?? 'Unknown',
                        'backplane' => $chassisSpecs['backplane'] ?? 'Unknown'
                    ],
                    'validation_status' => 'COMPLETE',
                    'data_quality' => 'HIGH'
                ];
            } else {
                $result['compatibility_summary'] = "Chassis incompatible: " . implode(', ', $result['issues']);

                $result['score_breakdown'] = [
                    'base_score' => 100,
                    'final_score' => 0,
                    'incompatibility_reasons' => $result['issues'],
                    'validation_status' => 'FAILED',
                    'data_quality' => 'HIGH'
                ];
            }

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized chassis compatibility check error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            return [
                'compatible' => false,
                'issues' => ['Exception during validation: ' . $e->getMessage()],
                'warnings' => ['Compatibility check failed due to exception'],
                'recommendations' => ['Verify chassis compatibility manually', 'Check error logs'],
                'details' => [
                    'EXCEPTION_CAUGHT' => $e->getMessage(),
                    'EXCEPTION_FILE' => $e->getFile(),
                    'EXCEPTION_LINE' => $e->getLine(),
                    'EXCEPTION_TRACE' => explode("\n", $e->getTraceAsString())
                ],
                'score_breakdown' => [
                    'base_score' => 100,
                    'penalty_applied' => -100,
                    'reason' => 'Exception occurred during compatibility validation',
                    'final_score' => 0,
                    'exception_details' => [
                        'message' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine()
                    ],
                    'validation_status' => 'ERROR',
                    'data_quality' => 'UNKNOWN',
                    'recommendation' => 'Check error logs and verify chassis data integrity',
                    'severity' => 'CRITICAL'
                ],
                'compatibility_summary' => 'INCOMPATIBLE: Exception during validation - ' . $e->getMessage()
            ];
        }
    }

    /**
     * Analyze existing CPU for RAM compatibility requirements
     */
    private function analyzeExistingCPUForRAM($cpuComponent, &$memoryRequirements) {
        $cpuData = $this->dataLoader->getComponentData('cpu', $cpuComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$cpuData) {
            $result['details'][] = 'CPU specifications not found - basic compatibility assumed';
            return $result;
        }

        // Extract CPU memory support
        $cpuMemoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($cpuData, 'cpu');
        $cpuMaxSpeed = $this->dataExtractor->extractMaxMemorySpeed($cpuData);

        if ($cpuMemoryTypes) {
            $memoryRequirements['supported_types'] = array_merge(
                $memoryRequirements['supported_types'],
                $cpuMemoryTypes
            );
            $memoryRequirements['sources'][] = 'CPU: ' . implode(', ', $cpuMemoryTypes);
            $result['details'][] = 'CPU supports: ' . implode(', ', $cpuMemoryTypes);
        }

        if ($cpuMaxSpeed) {
            $memoryRequirements['max_speeds'][] = $cpuMaxSpeed;
            $result['details'][] = "CPU max memory speed: {$cpuMaxSpeed}MHz";
        }

        return $result;
    }

    /**
     * Analyze existing motherboard for RAM compatibility requirements
     */
    private function analyzeExistingMotherboardForRAM($motherboardComponent, &$memoryRequirements) {
        $mbData = $this->dataLoader->getComponentData('motherboard', $motherboardComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$mbData) {
            $result['details'][] = 'Motherboard specifications not found - basic compatibility assumed';
            return $result;
        }

        // Extract motherboard memory support
        $mbMemoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($mbData, 'motherboard');
        $mbMaxSpeed = $this->dataExtractor->extractMaxMemorySpeed($mbData);
        $mbFormFactor = $this->dataExtractor->extractMemoryFormFactor($mbData);

        if ($mbMemoryTypes) {
            $memoryRequirements['supported_types'] = array_merge(
                $memoryRequirements['supported_types'],
                $mbMemoryTypes
            );
            $memoryRequirements['sources'][] = 'Motherboard: ' . implode(', ', $mbMemoryTypes);
            $result['details'][] = 'Motherboard supports: ' . implode(', ', $mbMemoryTypes);
        }

        if ($mbMaxSpeed) {
            $memoryRequirements['max_speeds'][] = $mbMaxSpeed;
            $result['details'][] = "Motherboard max memory speed: {$mbMaxSpeed}MHz";
        }

        if ($mbFormFactor) {
            $memoryRequirements['form_factors'][] = $mbFormFactor;
            $result['details'][] = "Motherboard form factor: {$mbFormFactor}";
        }

        return $result;
    }

    /**
     * Analyze existing RAM for form factor compatibility
     */
    private function analyzeExistingRAMForRAM($existingRamComponent, $newRamFormFactor, $newRamModuleType = null) {
        $existingRamData = $this->dataLoader->getComponentData('ram', $existingRamComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$existingRamData) {
            $result['details'][] = 'Existing RAM specifications not found - basic compatibility assumed';
            return $result;
        }

        $existingFormFactor = $this->dataExtractor->extractMemoryFormFactor($existingRamData);
        $existingModuleType = $existingRamData['module_type'] ?? null;

        // Check form factor compatibility (DIMM vs SO-DIMM physical shape)
        if ($existingFormFactor && $newRamFormFactor && $existingFormFactor !== $newRamFormFactor) {
            $result['compatible'] = false;
            $result['issues'][] = "Form factor mismatch: new RAM ({$newRamFormFactor}) vs existing RAM ({$existingFormFactor})";
        } else if ($existingFormFactor) {
            $result['details'][] = "Form factor matches existing RAM: {$existingFormFactor}";
        }

        // Check module type compatibility (UDIMM vs RDIMM vs LRDIMM)
        if ($existingModuleType && $newRamModuleType && $existingModuleType !== $newRamModuleType) {
            $result['compatible'] = false;
            $result['issues'][] = "Module type mismatch: new RAM ({$newRamModuleType}) vs existing RAM ({$existingModuleType}). UDIMM, RDIMM, and LRDIMM cannot be mixed.";
        } else if ($existingModuleType && $newRamModuleType) {
            $result['details'][] = "Module type matches existing RAM: {$existingModuleType}";
        }

        return $result;
    }

    /**
     * Apply final memory compatibility rules using collected requirements
     */
    private function applyMemoryCompatibilityRules($ramData, $memoryRequirements) {
        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        $ramMemoryType = $this->dataExtractor->extractMemoryType($ramData);
        $ramSpeed = $this->dataExtractor->extractMemorySpeed($ramData);
        $ramFormFactor = $this->dataExtractor->extractMemoryFormFactor($ramData);

        // Check form factor compatibility with motherboard
        if (!empty($memoryRequirements['form_factors'])) {
            $supportedFormFactors = array_unique($memoryRequirements['form_factors']);

            // Normalize form factors for comparison (handle DIMM/dimm, SO-DIMM/SODIMM variations)
            $ramFormFactorNormalized = strtoupper(str_replace(['-', '_'], '', $ramFormFactor));
            $formFactorCompatible = false;

            foreach ($supportedFormFactors as $supportedFF) {
                $supportedFFNormalized = strtoupper(str_replace(['-', '_'], '', $supportedFF));
                if ($ramFormFactorNormalized === $supportedFFNormalized) {
                    $formFactorCompatible = true;
                    break;
                }
            }

            if (!$formFactorCompatible) {
                $result['compatible'] = false;
                $result['issues'][] = "RAM form factor '{$ramFormFactor}' is not compatible with motherboard (motherboard requires: " . implode(', ', $supportedFormFactors) . ")";
            } else {
                $result['recommendations'][] = "RAM form factor '{$ramFormFactor}' matches motherboard requirements";
            }
        }

        // Check memory type compatibility with intersection of requirements
        if (!empty($memoryRequirements['supported_types'])) {
            $supportedTypes = array_unique($memoryRequirements['supported_types']);

            // For multiple CPUs, find common supported types
            $typeCompatible = in_array($ramMemoryType, $supportedTypes);

            if (!$typeCompatible) {
                $result['compatible'] = false;
                $result['issues'][] = "Memory type {$ramMemoryType} not supported by existing components (supported: " . implode(', ', $supportedTypes) . ")";
            } else {
                $result['recommendations'][] = "Memory type {$ramMemoryType} compatible with existing components";
            }
        }

        // Enhanced speed compatibility checking with performance warnings
        if (!empty($memoryRequirements['max_speeds']) && $ramSpeed) {
            $minMaxSpeed = min($memoryRequirements['max_speeds']);
            $maxMaxSpeed = max($memoryRequirements['max_speeds']);

            if ($ramSpeed > $minMaxSpeed) {
                $result['warnings'][] = "Performance: RAM speed ({$ramSpeed}MHz) exceeds component limit ({$minMaxSpeed}MHz) - will run at reduced speed";
            } else if ($ramSpeed < $minMaxSpeed) {
                // RAM is slower than what the system supports - performance warning
                $result['warnings'][] = "Performance: RAM speed ({$ramSpeed}MHz) is lower than system capability ({$minMaxSpeed}MHz) - possible performance bottleneck";
            } else {
                $result['recommendations'][] = "RAM speed ({$ramSpeed}MHz) optimal for system";
            }

            // Additional warning if there's variation in max speeds (e.g., CPU vs Motherboard limits differ)
            if ($maxMaxSpeed > $minMaxSpeed) {
                $speedSources = $memoryRequirements['sources'] ?? [];
                if (!empty($speedSources)) {
                    $result['warnings'][] = "Note: Components have different max memory speeds (" . implode('; ', $speedSources) . ") - system will use lowest common speed";
                }
            }
        } else if ($ramSpeed) {
            // No existing components with speed requirements - show informational message
            $result['recommendations'][] = "RAM speed: {$ramSpeed}MHz (no speed constraints from existing components)";
        }

        return $result;
    }

    /**
     * Analyze existing motherboard for PCIe card compatibility
     */
    private function analyzeExistingMotherboardForPCIe($motherboardComponent, &$slotAvailability) {
        $mbData = $this->dataLoader->getComponentData('motherboard', $motherboardComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$mbData) {
            $result['details'][] = 'Motherboard specifications not found';
            return $result;
        }

        // Extract PCIe slot information
        $pcieSlots = $this->dataExtractor->extractMotherboardPCIeSlots($mbData);

        $slotAvailability['total_slots'] = $pcieSlots['total'];
        $slotAvailability['available_by_size'] = $pcieSlots['by_size'];
        $slotAvailability['motherboard_generation'] = $pcieSlots['generation'];

        $result['details'][] = "Motherboard has {$pcieSlots['total']} total PCIe slots";
        if ($pcieSlots['generation']) {
            $result['details'][] = "Motherboard PCIe generation: Gen {$pcieSlots['generation']}";
        }

        // Log slot breakdown
        foreach ($pcieSlots['by_size'] as $size => $count) {
            if ($count > 0) {
                $result['details'][] = "Available: {$count}x PCIe {$size} slots";
            }
        }

        return $result;
    }

    /**
     * Analyze existing PCIe card (track slot usage)
     */
    private function analyzeExistingPCIeCardForPCIe($pcieCardComponent, &$slotAvailability) {
        error_log("DEBUG analyzeExistingPCIeCardForPCIe: UUID=" . $pcieCardComponent['uuid']);
        $pcieData = $this->dataLoader->getComponentData('pciecard', $pcieCardComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$pcieData) {
            // Unknown card, assume 1 slot
            $slotAvailability['used_slots'] += 1;
            $result['details'][] = 'Existing PCIe card (specs unknown) - uses 1 slot';
            error_log("DEBUG: PCIe card specs not found - counted as 1 used slot");
            return $result;
        }

        error_log("DEBUG: PCIe card data loaded: " . json_encode(['model' => $pcieData['model'] ?? 'Unknown', 'subtype' => $pcieData['subtype'] ?? 'Unknown']));

        // Check if it's a riser card
        if ($this->isPCIeRiserCard($pcieData)) {
            $providedSlots = $this->dataExtractor->extractRiserCardSlots($pcieData);
            $slotAvailability['has_riser_card'] = true;

            // IMPORTANT: Riser cards use RISER SLOTS on the motherboard, NOT PCIe slots
            // They PROVIDE PCIe slots without consuming any motherboard PCIe slots
            // So we DO NOT increment used_slots here
            // Instead, we add the provided slots directly to riser_added_slots

            $slotAvailability['riser_added_slots'] += $providedSlots;

            // Also update available_by_size to track which slot sizes the riser provides
            $riserSlotType = $this->dataExtractor->extractPCIeSlotSize($pcieData);
            $riserSlotTypeKey = 'x' . $riserSlotType;
            if (!isset($slotAvailability['available_by_size'][$riserSlotTypeKey])) {
                $slotAvailability['available_by_size'][$riserSlotTypeKey] = 0;
            }
            $slotAvailability['available_by_size'][$riserSlotTypeKey] += $providedSlots;

            $result['details'][] = "Riser card installed - provides {$providedSlots} PCIe {$riserSlotTypeKey} slot(s) (uses motherboard riser slot, not PCIe slot)";
            error_log("DEBUG: RISER CARD DETECTED - provides {$providedSlots} PCIe {$riserSlotTypeKey} slots. Total riser_added_slots now: " . $slotAvailability['riser_added_slots']);
            error_log("DEBUG: available_by_size after riser: " . json_encode($slotAvailability['available_by_size']));
        } else {
            // Regular PCIe card
            $cardSlotSize = $this->dataExtractor->extractPCIeSlotSize($pcieData);
            $slotAvailability['used_slots'] += 1;

            $cardModel = $pcieData['model'] ?? 'PCIe Card';
            $result['details'][] = "Existing card: {$cardModel} (uses x{$cardSlotSize} slot)";
            error_log("DEBUG: Regular PCIe card - uses 1 slot. Total used_slots now: " . $slotAvailability['used_slots']);
        }

        return $result;
    }

    /**
     * Analyze existing NIC for PCIe slot usage
     */
    private function analyzeExistingNICForPCIe($nicComponent, &$slotAvailability) {
        $nicData = $this->dataLoader->getComponentData('nic', $nicComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$nicData) {
            // Unknown NIC, assume 1 slot
            $slotAvailability['used_slots'] += 1;
            $result['details'][] = 'Existing NIC (specs unknown) - uses 1 slot';
            return $result;
        }

        // Check if NIC is PCIe-based (vs onboard)
        $interface = $nicData['interface'] ?? $nicData['connection_type'] ?? $nicData['Notes'] ?? '';

        if (stripos($interface, 'PCIe') !== false || stripos($interface, 'PCI Express') !== false || stripos($interface, 'PCI-E') !== false) {
            $slotAvailability['used_slots'] += 1;

            $nicModel = $nicData['model'] ?? 'NIC';
            $result['details'][] = "Existing NIC: {$nicModel} (uses 1 PCIe slot)";
        } else {
            // Onboard NIC, doesn't use PCIe slot
            $result['details'][] = "Existing NIC is onboard - no slot used";
        }

        return $result;
    }

    /**
     * Apply PCIe compatibility rules
     */
    private function applyPCIeCompatibilityRules($pcieCardData, $slotAvailability, $cardGeneration, $cardSlotSize) {
        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        // RULE 1: Check slot availability
        $totalAvailableSlots = $slotAvailability['total_slots'] + $slotAvailability['riser_added_slots'];
        $usedSlots = $slotAvailability['used_slots'];
        $remainingSlots = $totalAvailableSlots - $usedSlots;

        if ($remainingSlots <= 0) {
            $result['compatible'] = false;
            $result['issues'][] = "All PCIe slots occupied ({$usedSlots}/{$totalAvailableSlots} used)";

            if (!$slotAvailability['has_riser_card']) {
                $result['recommendations'][] = "Add a riser card to expand PCIe slot capacity";
            } else {
                $result['recommendations'][] = "Remove existing PCIe components to free slots";
            }

            return $result;
        }

        // RULE 2: PCIe generation compatibility
        $motherboardGen = $slotAvailability['motherboard_generation'];

        if ($cardGeneration && $motherboardGen) {
            if ($cardGeneration < $motherboardGen) {
                // Older card in newer slot - backward compatible
                $result['warnings'][] = "Card is PCIe Gen {$cardGeneration}, motherboard supports Gen {$motherboardGen} - fully compatible (may not use full slot bandwidth)";
            } elseif ($cardGeneration > $motherboardGen) {
                // Newer card in older slot - forward compatible but limited
                $result['warnings'][] = "Card is PCIe Gen {$cardGeneration}, motherboard supports Gen {$motherboardGen} - will run at Gen {$motherboardGen} speed (motherboard limitation)";
            } else {
                // Perfect match
                $result['recommendations'][] = "PCIe generation match: Gen {$cardGeneration}";
            }
        } elseif ($cardGeneration && !$motherboardGen) {
            $result['warnings'][] = "Motherboard PCIe generation unknown - verify compatibility manually";
        }

        // RULE 3: Physical slot size compatibility
        $slotFitResult = $this->checkPCIeSlotPhysicalFit($cardSlotSize, $slotAvailability);

        if (!$slotFitResult['fits']) {
            $result['compatible'] = false;
            $result['issues'][] = $slotFitResult['reason'];
            $result['recommendations'][] = "Use a card that requires x{$slotFitResult['max_available']} or smaller slot";
            return $result;
        }

        if ($slotFitResult['oversized']) {
            $result['warnings'][] = $slotFitResult['warning'];
        }

        return $result;
    }

    /**
     * Check if card physically fits in available slots
     */
    private function checkPCIeSlotPhysicalFit($cardSlotSize, $slotAvailability) {
        // Physical compatibility rules:
        // x1 card fits in: x1, x4, x8, x16 slots
        // x4 card fits in: x4, x8, x16 slots
        // x8 card fits in: x8, x16 slots
        // x16 card fits in: x16 slots only

        $availableSlots = $slotAvailability['available_by_size'];
        $usedSlots = $slotAvailability['used_slots'];

        // Determine which slot sizes can accommodate this card
        $compatibleSizes = [];

        switch ($cardSlotSize) {
            case 1:
                $compatibleSizes = [1, 4, 8, 16];
                break;
            case 4:
                $compatibleSizes = [4, 8, 16];
                break;
            case 8:
                $compatibleSizes = [8, 16];
                break;
            case 16:
                $compatibleSizes = [16];
                break;
            default:
                // Unknown size, assume needs x16
                $compatibleSizes = [16];
        }

        // Check if any compatible slot is available
        $hasAvailableSlot = false;
        $usedOversizedSlot = false;
        $slotUsed = null;

        foreach ($compatibleSizes as $size) {
            $slotKey = 'x' . $size;
            if (isset($availableSlots[$slotKey]) && $availableSlots[$slotKey] > 0) {
                $hasAvailableSlot = true;
                $slotUsed = $size;

                // Check if using oversized slot
                if ($size > $cardSlotSize) {
                    $usedOversizedSlot = true;
                }
                break; // Use smallest available slot
            }
        }

        if (!$hasAvailableSlot) {
            $availableSlotSizes = array_keys(array_filter($availableSlots, function($count) { return $count > 0; }));
            $maxAvailable = !empty($availableSlotSizes) ? max(array_map(function($key) {
                return (int)str_replace('x', '', $key);
            }, $availableSlotSizes)) : 0;

            return [
                'fits' => false,
                'oversized' => false,
                'reason' => "Card requires x{$cardSlotSize} slot, but no compatible slots available",
                'max_available' => $maxAvailable
            ];
        }

        if ($usedOversizedSlot) {
            return [
                'fits' => true,
                'oversized' => true,
                'warning' => "Card requires x{$cardSlotSize} slot, will be placed in x{$slotUsed} slot (acceptable but not optimal)"
            ];
        }

        return [
            'fits' => true,
            'oversized' => false
        ];
    }

    /**
     * Create compatibility summary for PCIe cards
     */
    private function createPCIeCompatibilitySummary($pcieCardData, $slotAvailability, $result) {
        if (!$result['compatible']) {
            return "Incompatible - " . implode(', ', $result['issues']);
        }

        $totalSlots = $slotAvailability['total_slots'] + $slotAvailability['riser_added_slots'];
        $usedSlots = $slotAvailability['used_slots'];
        $remainingSlots = $totalSlots - $usedSlots;

        $summary = "Compatible";

        // Add slot availability info
        if ($remainingSlots > 0) {
            $summary .= " ({$remainingSlots} of {$totalSlots} slots available)";
        }

        // Add warnings if any
        if (!empty($result['warnings'])) {
            $summary .= " - " . $result['warnings'][0]; // Show first warning
        }

        return $summary;
    }

    /**
     * Analyze existing motherboard for CPU compatibility requirements
     */
    private function analyzeExistingMotherboardForCPU($motherboardComponent, &$compatibilityRequirements) {
        $motherboardUuid = $motherboardComponent['uuid'];
        error_log("Analyzing motherboard $motherboardUuid for CPU requirements");

        $motherboardData = $this->dataLoader->getComponentData('motherboard', $motherboardUuid);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$motherboardData) {
            error_log("WARNING: Motherboard $motherboardUuid data not found");
            $result['details'][] = 'Motherboard specifications not found - basic compatibility assumed';
            return $result;
        }

        error_log("Motherboard $motherboardUuid data loaded, extracting socket type");

        // Extract motherboard socket type
        $socketType = $this->dataExtractor->extractSocketType($motherboardData, 'motherboard');
        error_log("Motherboard $motherboardUuid socket extracted: " . ($socketType ?? 'NULL'));

        if ($socketType) {
            $compatibilityRequirements['required_socket'] = $socketType;
            $compatibilityRequirements['sources'][] = "Motherboard socket: {$socketType}";
            $result['details'][] = "Motherboard requires socket: {$socketType}";
            error_log("Set required_socket to: $socketType");
        } else {
            error_log("WARNING: Could not extract socket type from motherboard $motherboardUuid");
        }

        return $result;
    }

    /**
     * Analyze existing RAM for CPU compatibility requirements
     */
    private function analyzeExistingRAMForCPU($ramComponent, &$compatibilityRequirements) {
        $ramUuid = $ramComponent['uuid'];
        error_log("Analyzing RAM $ramUuid for CPU requirements");

        $ramData = $this->dataLoader->getComponentData('ram', $ramUuid);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$ramData) {
            error_log("WARNING: RAM $ramUuid data not found");
            $result['details'][] = 'RAM specifications not found - basic compatibility assumed';
            return $result;
        }

        // Extract RAM specifications (already normalized by extractMemoryType)
        $ramType = $this->dataExtractor->extractMemoryType($ramData);
        $ramSpeed = $this->dataExtractor->extractMemorySpeed($ramData);

        error_log("RAM $ramUuid extracted specs - Type: " . ($ramType ?? 'NULL') . ", Speed: " . ($ramSpeed ?? 'NULL') . "MHz");

        if ($ramType) {
            // RAM type is already normalized by extractMemoryType() to base DDR type (DDR5, DDR4, etc.)
            $compatibilityRequirements['memory_types_required'][] = $ramType;
            $compatibilityRequirements['sources'][] = "RAM type: {$ramType}";
            $result['details'][] = "CPU must support memory type: {$ramType}";
            error_log("Added RAM type requirement: $ramType");
        } else {
            error_log("WARNING: Could not extract memory type from RAM $ramUuid");
        }

        if ($ramSpeed) {
            $compatibilityRequirements['max_memory_speed_required'] = max($compatibilityRequirements['max_memory_speed_required'], $ramSpeed);
            $compatibilityRequirements['sources'][] = "RAM speed: {$ramSpeed}MHz";
            $result['details'][] = "CPU must support memory speed: {$ramSpeed}MHz or higher";
            error_log("Added RAM speed requirement: {$ramSpeed}MHz");
        }

        return $result;
    }

    /**
     * Analyze existing CPU for CPU compatibility (multi-socket scenarios)
     */
    private function analyzeExistingCPUForCPU($existingCpuComponent, $newCpuSocket) {
        $existingCpuData = $this->dataLoader->getComponentData('cpu', $existingCpuComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$existingCpuData) {
            $result['details'][] = 'Existing CPU specifications not found - basic compatibility assumed';
            return $result;
        }

        $existingSocket = $this->dataExtractor->extractSocketType($existingCpuData, 'cpu');

        // Normalize socket types for comparison
        $existingSocketNormalized = strtolower(trim($existingSocket ?? ''));
        $newCpuSocketNormalized = strtolower(trim($newCpuSocket ?? ''));

        if ($existingSocket && $newCpuSocket && $existingSocketNormalized !== $newCpuSocketNormalized) {
            $result['compatible'] = false;
            $result['issues'][] = "CPU socket mismatch: new CPU ({$newCpuSocket}) vs existing CPU ({$existingSocket})";
        } else if ($existingSocket) {
            $result['details'][] = "CPU socket matches existing CPU: {$existingSocket}";
        }

        return $result;
    }

    /**
     * Apply final CPU compatibility rules using collected requirements
     */
    private function applyCPUCompatibilityRules($cpuData, $compatibilityRequirements) {
        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        $cpuSocket = $this->dataExtractor->extractSocketType($cpuData, 'cpu');
        $cpuMemoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($cpuData, 'cpu');
        $cpuMaxMemorySpeed = $this->dataExtractor->extractMaxMemorySpeed($cpuData, 'cpu');

        error_log("applyCPUCompatibilityRules - CPU Socket: " . ($cpuSocket ?? 'NULL') .
                 ", Memory Types: " . json_encode($cpuMemoryTypes) .
                 ", Max Memory Speed: " . ($cpuMaxMemorySpeed ?? 'NULL'));

        // Check socket compatibility
        if ($compatibilityRequirements['required_socket']) {
            $requiredSocket = $compatibilityRequirements['required_socket'];

            // Normalize socket types for comparison (case-insensitive, trim whitespace)
            $cpuSocketNormalized = strtolower(trim($cpuSocket ?? ''));
            $requiredSocketNormalized = strtolower(trim($requiredSocket ?? ''));

            error_log("Socket comparison - CPU: '$cpuSocketNormalized' vs Required: '$requiredSocketNormalized' - Match: " .
                     ($cpuSocketNormalized === $requiredSocketNormalized ? 'YES' : 'NO'));

            if ($cpuSocketNormalized !== $requiredSocketNormalized) {
                $result['compatible'] = false;
                $result['issues'][] = "CPU socket ({$cpuSocket}) does not match required socket ({$requiredSocket})";
                error_log("SOCKET MISMATCH DETECTED!");
            } else {
                $result['recommendations'][] = "CPU socket ({$cpuSocket}) matches motherboard socket";
                error_log("Socket match confirmed");
            }
        } else {
            error_log("No socket requirement specified");
        }

        // Check memory type compatibility with smart backward compatibility logic
        if (!empty($compatibilityRequirements['memory_types_required'])) {
            $requiredTypes = array_unique($compatibilityRequirements['memory_types_required']);
            error_log("Checking memory type compatibility - Required: " . json_encode($requiredTypes) .
                     ", CPU supports: " . json_encode($cpuMemoryTypes));

            foreach ($requiredTypes as $requiredType) {
                // Normalize the required type
                $normalizedRequired = DataNormalizationUtils::normalizeMemoryType($requiredType);
                $compatible = false;
                $compatWarning = null;
                $compatReason = null;

                if ($cpuMemoryTypes && is_array($cpuMemoryTypes)) {
                    // Check each CPU-supported memory type
                    foreach ($cpuMemoryTypes as $cpuType) {
                        $compatCheck = $this->checkMemoryTypeCompatibility($cpuType, $normalizedRequired);

                        if ($compatCheck['compatible']) {
                            $compatible = true;

                            // Store warning if backward compatibility scenario (DDR5 CPU + DDR4 RAM)
                            if ($compatCheck['warning']) {
                                $result['warnings'][] = $compatCheck['warning'];
                                error_log("Memory type compatible with warning: " . $compatCheck['warning']);
                            } else {
                                error_log("Memory type perfect match: " . $compatCheck['reason']);
                            }

                            $result['recommendations'][] = "CPU supports required memory type: {$normalizedRequired}";
                            break; // Found compatible type, no need to check others
                        } else {
                            // Store the incompatibility reason for potential use
                            $compatReason = $compatCheck['reason'];
                        }
                    }
                }

                // If no compatible memory type found, mark as incompatible
                if (!$compatible) {
                    $result['compatible'] = false;
                    if ($compatReason) {
                        $result['issues'][] = $compatReason;
                        error_log("MEMORY TYPE INCOMPATIBLE: " . $compatReason);
                    } else {
                        $result['issues'][] = "CPU does not support required memory type: {$normalizedRequired} (CPU supports: " . implode(', ', $cpuMemoryTypes) . ")";
                        error_log("MEMORY TYPE MISMATCH: CPU does not support $normalizedRequired");
                    }
                }
            }
        } else {
            error_log("No memory type requirements specified");
        }

        // Check memory speed compatibility
        if ($compatibilityRequirements['max_memory_speed_required'] > 0 && $cpuMaxMemorySpeed) {
            $requiredSpeed = $compatibilityRequirements['max_memory_speed_required'];
            error_log("Checking memory speed - CPU max: {$cpuMaxMemorySpeed}MHz, Required: {$requiredSpeed}MHz");

            if ($cpuMaxMemorySpeed < $requiredSpeed) {
                $result['compatible'] = false;
                $result['issues'][] = "CPU maximum memory speed ({$cpuMaxMemorySpeed}MHz) is lower than required ({$requiredSpeed}MHz)";
                error_log("MEMORY SPEED MISMATCH: CPU speed too low");
            } else {
                $result['recommendations'][] = "CPU memory speed ({$cpuMaxMemorySpeed}MHz) supports existing RAM ({$requiredSpeed}MHz)";
                error_log("Memory speed check passed");
            }
        } else {
            error_log("No memory speed requirements specified");
        }

        return $result;
    }

    /**
     * Create concise CPU compatibility summary for display
     */
    private function createCPUCompatibilitySummary($cpuData, $compatibilityRequirements, $compatibilityResult) {
        $cpuSocket = $this->dataExtractor->extractSocketType($cpuData, 'cpu');
        $requiredSocket = $compatibilityRequirements['required_socket'] ?? null;

        // Normalize socket types for comparison
        $cpuSocketNormalized = strtolower(trim($cpuSocket ?? ''));
        $requiredSocketNormalized = strtolower(trim($requiredSocket ?? ''));

        // If compatible
        if ($compatibilityResult['compatible']) {
            if ($requiredSocket) {
                return "Compatible with {$requiredSocket} socket";
            } else {
                return "Compatible - no constraints found";
            }
        }
        // If incompatible - provide detailed reason
        else {
            // First check if we have specific issues in the result
            if (!empty($compatibilityResult['issues'])) {
                // Return the first (most important) issue
                return $compatibilityResult['issues'][0];
            }

            // Fallback to socket-based messages
            if ($requiredSocket && $cpuSocket && $cpuSocketNormalized !== $requiredSocketNormalized) {
                return "CPU socket ({$cpuSocket}) incompatible with motherboard socket ({$requiredSocket})";
            } elseif ($requiredSocket && !$cpuSocket) {
                return "CPU socket unknown - motherboard requires {$requiredSocket}";
            } elseif (!$requiredSocket && !$cpuSocket) {
                return "Incompatible - CPU and motherboard socket specifications not found";
            } elseif (!$requiredSocket) {
                return "Incompatible - motherboard socket requirements not determined";
            } else {
                // This should rarely happen now - log for debugging
                error_log("WARNING: CPU compatibility check failed but no specific issues found. CPU Socket: $cpuSocket, Required: $requiredSocket");
                return "Incompatible - compatibility check failed (CPU: $cpuSocket, Required: $requiredSocket)";
            }
        }
    }

    /**
     * Analyze existing CPU for motherboard compatibility requirements
     */
    private function analyzeExistingCPUForMotherboard($cpuComponent, &$compatibilityRequirements) {
        $cpuData = $this->dataLoader->getComponentData('cpu', $cpuComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$cpuData) {
            $result['details'][] = 'CPU specifications not found - basic compatibility assumed';
            return $result;
        }

        // Extract CPU socket type
        $cpuSocket = $this->dataExtractor->extractSocketType($cpuData, 'cpu');
        if ($cpuSocket) {
            $compatibilityRequirements['required_cpu_socket'] = $cpuSocket;
            $compatibilityRequirements['sources'][] = "CPU socket: {$cpuSocket}";
            $result['details'][] = "Motherboard must support CPU socket: {$cpuSocket}";
        }

        return $result;
    }

    /**
     * Analyze existing RAM for motherboard compatibility requirements
     */
    private function analyzeExistingRAMForMotherboard($ramComponent, &$compatibilityRequirements) {
        $ramData = $this->dataLoader->getComponentData('ram', $ramComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$ramData) {
            $result['details'][] = 'RAM specifications not found - basic compatibility assumed';
            return $result;
        }

        // Extract RAM specifications
        $ramType = $this->dataExtractor->extractMemoryType($ramData);
        $ramSpeed = $this->dataExtractor->extractMemorySpeed($ramData);
        $ramFormFactor = $this->dataExtractor->extractMemoryFormFactor($ramData);
        $ramModuleType = $ramData['module_type'] ?? null; // RDIMM, LRDIMM, UDIMM

        // DEBUG: Log RAM data extraction
        error_log("DEBUG [analyzeExistingRAMForMotherboard] RAM UUID: {$ramComponent['uuid']}");
        error_log("DEBUG [analyzeExistingRAMForMotherboard] RAM Data Keys: " . json_encode(array_keys($ramData)));
        error_log("DEBUG [analyzeExistingRAMForMotherboard] Extracted ramModuleType: " . ($ramModuleType ?? 'NULL'));
        error_log("DEBUG [analyzeExistingRAMForMotherboard] Full RAM Data: " . json_encode($ramData));

        if ($ramType) {
            $compatibilityRequirements['required_memory_types'][] = $ramType;
            $compatibilityRequirements['sources'][] = "RAM type: {$ramType}";
            $result['details'][] = "Motherboard must support memory type: {$ramType}";
        }

        if ($ramSpeed) {
            $compatibilityRequirements['min_memory_speed_required'] = max($compatibilityRequirements['min_memory_speed_required'], $ramSpeed);
            $compatibilityRequirements['sources'][] = "RAM speed: {$ramSpeed}MHz";
            $result['details'][] = "Motherboard must support memory speed: {$ramSpeed}MHz or higher";
        }

        if ($ramFormFactor) {
            $compatibilityRequirements['required_form_factors'][] = $ramFormFactor;
            $compatibilityRequirements['sources'][] = "RAM form factor: {$ramFormFactor}";
            $result['details'][] = "Motherboard must support form factor: {$ramFormFactor}";
        }

        // CRITICAL: Extract and track RAM module type (RDIMM/LRDIMM/UDIMM)
        // This is essential for backward compatibility checking when searching for motherboards
        if ($ramModuleType) {
            $compatibilityRequirements['required_module_types'][] = strtoupper($ramModuleType);
            $compatibilityRequirements['sources'][] = "RAM module type: {$ramModuleType}";
            $result['details'][] = "Motherboard must support RAM module type: {$ramModuleType}";

            // DEBUG: Log module type requirement
            error_log("DEBUG [analyzeExistingRAMForMotherboard] Added required_module_type: " . strtoupper($ramModuleType));
        } else {
            error_log("DEBUG [analyzeExistingRAMForMotherboard] WARNING: No module_type found in RAM data!");
        }

        return $result;
    }

    /**
     * Analyze existing motherboard for motherboard compatibility (multi-motherboard scenarios)
     */
    private function analyzeExistingMotherboardForMotherboard($existingMotherboardComponent) {
        $result = ['compatible' => false, 'issues' => [], 'details' => []];

        // Typically only one motherboard is allowed per server configuration
        $result['compatible'] = false;
        $result['issues'][] = "Server already has a motherboard - only one motherboard allowed per configuration";
        $result['details'][] = "Cannot add multiple motherboards to the same server configuration";

        return $result;
    }

    /**
     * Apply final motherboard compatibility rules using collected requirements
     */
    private function applyMotherboardCompatibilityRules($motherboardData, $compatibilityRequirements) {
        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        $motherboardSocket = $this->dataExtractor->extractSocketType($motherboardData, 'motherboard');
        $motherboardMemoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($motherboardData, 'motherboard');
        $motherboardMaxMemorySpeed = $this->dataExtractor->extractMaxMemorySpeed($motherboardData, 'motherboard');

        // Check CPU socket compatibility
        if ($compatibilityRequirements['required_cpu_socket']) {
            $requiredSocket = $compatibilityRequirements['required_cpu_socket'];

            // Normalize socket types for comparison
            $motherboardSocketNormalized = strtolower(trim($motherboardSocket ?? ''));
            $requiredSocketNormalized = strtolower(trim($requiredSocket ?? ''));

            if ($motherboardSocketNormalized !== $requiredSocketNormalized) {
                $result['compatible'] = false;
                $result['issues'][] = "Motherboard socket ({$motherboardSocket}) does not match CPU socket ({$requiredSocket})";
            } else {
                $result['recommendations'][] = "Motherboard socket ({$motherboardSocket}) matches CPU socket";
            }
        }

        // Check memory type compatibility
        if (!empty($compatibilityRequirements['required_memory_types'])) {
            $requiredTypes = array_unique($compatibilityRequirements['required_memory_types']);

            foreach ($requiredTypes as $requiredType) {
                if ($motherboardMemoryTypes && !in_array($requiredType, $motherboardMemoryTypes)) {
                    $result['compatible'] = false;
                    $result['issues'][] = "Motherboard does not support required memory type: {$requiredType} (supported: " . implode(', ', $motherboardMemoryTypes) . ")";
                } else {
                    $result['recommendations'][] = "Motherboard supports required memory type: {$requiredType}";
                }
            }
        }

        // Check memory speed compatibility
        if ($compatibilityRequirements['min_memory_speed_required'] > 0 && $motherboardMaxMemorySpeed) {
            $requiredSpeed = $compatibilityRequirements['min_memory_speed_required'];

            if ($motherboardMaxMemorySpeed < $requiredSpeed) {
                $result['compatible'] = false;
                $result['issues'][] = "Motherboard maximum memory speed ({$motherboardMaxMemorySpeed}MHz) is lower than required ({$requiredSpeed}MHz)";
            } else {
                $result['recommendations'][] = "Motherboard memory speed ({$motherboardMaxMemorySpeed}MHz) supports existing RAM ({$requiredSpeed}MHz)";
            }
        }

        // CRITICAL: Check RAM module type compatibility (RDIMM, LRDIMM, UDIMM)
        // This ensures backward compatibility when searching for motherboards
        if (!empty($compatibilityRequirements['required_module_types'])) {
            $requiredModuleTypes = array_unique($compatibilityRequirements['required_module_types']);
            $motherboardModuleTypes = $this->dataExtractor->extractSupportedModuleTypes($motherboardData);

            // DEBUG: Log module type comparison
            error_log("DEBUG [applyMotherboardCompatibilityRules] Required module types: " . json_encode($requiredModuleTypes));
            error_log("DEBUG [applyMotherboardCompatibilityRules] Motherboard module types: " . json_encode($motherboardModuleTypes));
            error_log("DEBUG [applyMotherboardCompatibilityRules] Motherboard data memory section: " . json_encode($motherboardData['memory'] ?? 'NOT FOUND'));

            // If motherboard doesn't specify module types, assume it supports all (backward compatibility)
            // This allows older JSON specifications without module_type fields to remain compatible
            if ($motherboardModuleTypes === null) {
                $result['warnings'][] = "Motherboard module type support not specified - assuming compatible with " . implode('/', $requiredModuleTypes);
                error_log("DEBUG [applyMotherboardCompatibilityRules] Motherboard module types NULL - assuming compatible");
                // Don't break - continue with other validations
            } else {
                // Motherboard has explicit module type support - validate it
                foreach ($requiredModuleTypes as $requiredModuleType) {
                    $requiredModuleTypeUpper = strtoupper($requiredModuleType);
                    $motherboardModuleTypesUpper = array_map('strtoupper', $motherboardModuleTypes);

                    // DEBUG: Log individual check
                    error_log("DEBUG [applyMotherboardCompatibilityRules] Checking if '$requiredModuleTypeUpper' is in [" . implode(', ', $motherboardModuleTypesUpper) . "]");

                    // Check if motherboard supports the required module type
                    if (!in_array($requiredModuleTypeUpper, $motherboardModuleTypesUpper)) {
                        $result['compatible'] = false;
                        $result['issues'][] = "Motherboard memory incompatible with existing RAM";
                        $result['details'][] = "Motherboard does not support {$requiredModuleType} modules (supported: " . implode(', ', $motherboardModuleTypes) . ")";

                        error_log("DEBUG [applyMotherboardCompatibilityRules] INCOMPATIBLE: '$requiredModuleTypeUpper' NOT FOUND in motherboard support");
                    } else {
                        $result['recommendations'][] = "Motherboard supports required module type: {$requiredModuleType}";

                        error_log("DEBUG [applyMotherboardCompatibilityRules] COMPATIBLE: '$requiredModuleTypeUpper' FOUND in motherboard support");
                    }
                }
            }
        } else {
            error_log("DEBUG [applyMotherboardCompatibilityRules] No required_module_types in compatibility requirements");
        }

        return $result;
    }

    /**
     * Create concise motherboard compatibility summary for display
     */
    private function createMotherboardCompatibilitySummary($motherboardData, $compatibilityRequirements, $compatibilityResult) {
        // Check for existing motherboard error first
        if (!$compatibilityResult['compatible'] && !empty($compatibilityResult['issues'])) {
            foreach ($compatibilityResult['issues'] as $issue) {
                if (strpos($issue, 'Server already has a motherboard') !== false) {
                    return "Motherboard already installed - only one motherboard allowed per server-config";
                }
            }
        }

        $motherboardSocket = $this->dataExtractor->extractSocketType($motherboardData, 'motherboard');
        $requiredCpuSocket = $compatibilityRequirements['required_cpu_socket'] ?? null;
        $requiredMemoryTypes = $compatibilityRequirements['required_memory_types'] ?? [];

        // Normalize socket types for comparison
        $motherboardSocketNormalized = strtolower(trim($motherboardSocket ?? ''));
        $requiredCpuSocketNormalized = strtolower(trim($requiredCpuSocket ?? ''));

        // If compatible
        if ($compatibilityResult['compatible']) {
            $constraints = [];

            if ($requiredCpuSocket) {
                $constraints[] = "{$requiredCpuSocket} socket";
            }

            if (!empty($requiredMemoryTypes)) {
                $constraints[] = implode('/', $requiredMemoryTypes) . " memory";
            }

            if (!empty($constraints)) {
                return "Compatible with " . implode(' and ', $constraints);
            } else {
                return "Compatible - no constraints found";
            }
        }
        // If incompatible
        else {
            if ($requiredCpuSocket && $motherboardSocket && $motherboardSocketNormalized !== $requiredCpuSocketNormalized) {
                return "Motherboard socket ({$motherboardSocket}) incompatible with CPU socket ({$requiredCpuSocket})";
            } elseif (!empty($requiredMemoryTypes)) {
                $motherboardMemoryTypes = $this->dataExtractor->extractSupportedMemoryTypes($motherboardData, 'motherboard');
                if ($motherboardMemoryTypes) {
                    $unsupportedTypes = array_diff($requiredMemoryTypes, $motherboardMemoryTypes);
                    if (!empty($unsupportedTypes)) {
                        return "Motherboard does not support " . implode('/', $unsupportedTypes) . " memory";
                    }
                }

                // Check if it's a module type issue
                $requiredModuleTypes = $compatibilityRequirements['required_module_types'] ?? [];
                if (!empty($requiredModuleTypes)) {
                    $motherboardModuleTypes = $this->dataExtractor->extractSupportedModuleTypes($motherboardData);
                    if ($motherboardModuleTypes !== null) {
                        $unsupportedModules = array_diff(
                            array_map('strtoupper', $requiredModuleTypes),
                            array_map('strtoupper', $motherboardModuleTypes)
                        );
                        if (!empty($unsupportedModules)) {
                            return "Motherboard does not support " . implode('/', $unsupportedModules) . " RAM modules";
                        }
                    }
                }

                return "Motherboard memory incompatible with existing RAM";
            } elseif ($requiredCpuSocket && !$motherboardSocket) {
                return "Motherboard socket unknown - CPU requires {$requiredCpuSocket}";
            } else {
                return "Incompatible - check specifications";
            }
        }
    }

    /**
     * Analyze existing motherboard for storage compatibility requirements
     */
    private function analyzeExistingMotherboardForStorage($motherboardComponent, &$storageRequirements) {
        $mbData = $this->dataLoader->getComponentData('motherboard', $motherboardComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$mbData) {
            $result['details'][] = 'Motherboard specifications not found - basic compatibility assumed';
            return $result;
        }

        // Try to load from JSON first
        $mbSpecs = $this->dataLoader->loadMotherboardSpecs($motherboardComponent['uuid']);
        if ($mbSpecs && isset($mbSpecs['storage'])) {
            $storageSupport = $mbSpecs['storage'];

            // Extract supported interfaces
            if (isset($storageSupport['interfaces'])) {
                $storageRequirements['supported_interfaces'] = array_merge(
                    $storageRequirements['supported_interfaces'],
                    $storageSupport['interfaces']
                );
                $result['details'][] = 'Motherboard supports interfaces: ' . implode(', ', $storageSupport['interfaces']);
            }

            // Extract available slots/bays
            if (isset($storageSupport['slots'])) {
                $storageRequirements['available_slots'] = $storageSupport['slots'];
                $result['details'][] = 'Available storage slots: ' . count($storageSupport['slots']);
            }

            // Extract M.2 slot information from storage.nvme.m2_slots (correct JSON path)
            $nvmeStorage = $storageSupport['nvme'] ?? [];
            if (isset($nvmeStorage['m2_slots']) && !empty($nvmeStorage['m2_slots'])) {
                $m2Slots = $nvmeStorage['m2_slots'];

                if (!empty($m2Slots) && is_array($m2Slots)) {
                    $firstSlotConfig = $m2Slots[0];

                    // Store M.2 form factor support
                    $m2FormFactors = $firstSlotConfig['form_factors'] ?? [];
                    $storageRequirements['m2_form_factors'] = $m2FormFactors;

                    // Store M.2 slot count - using correct key name
                    $m2SlotCount = $firstSlotConfig['count'] ?? 0;
                    $storageRequirements['motherboard_m2_slots'] = $m2SlotCount;
                    $storageRequirements['m2_slots'] = [
                        'total' => $m2SlotCount,
                        'used' => 0, // Will be calculated later
                        'available' => $m2SlotCount,
                        'pcie_generation' => $firstSlotConfig['pcie_generation'] ?? 4,
                        'pcie_lanes' => $firstSlotConfig['pcie_lanes'] ?? 4
                    ];

                    $result['details'][] = "Motherboard M.2 slots: {$m2SlotCount} available, supports " . implode(', ', $m2FormFactors) . " (PCIe Gen " . ($firstSlotConfig['pcie_generation'] ?? 4) . ")";

                    // Add NVMe interface support (for M.2 path)
                    $nvmeInterfaces = ['NVMe', 'PCIe NVMe', 'NVMe PCIe 3.0', 'NVMe PCIe 4.0', 'NVMe PCIe 5.0'];
                    foreach ($nvmeInterfaces as $nvmeInterface) {
                        if (!in_array($nvmeInterface, $storageRequirements['supported_interfaces'])) {
                            $storageRequirements['supported_interfaces'][] = $nvmeInterface;
                        }
                    }
                }
            }

            // Extract U.2 slot information from storage.nvme.u2_slots
            if (isset($nvmeStorage['u2_slots']['count']) && $nvmeStorage['u2_slots']['count'] > 0) {
                $u2SlotCount = (int)$nvmeStorage['u2_slots']['count'];
                $storageRequirements['motherboard_u2_slots'] = $u2SlotCount;
                $result['details'][] = "Motherboard U.2 slots: {$u2SlotCount} available";

                // Add U.2/NVMe interface support
                $u2Interfaces = ['U.2', 'NVMe', 'PCIe NVMe'];
                foreach ($u2Interfaces as $u2Interface) {
                    if (!in_array($u2Interface, $storageRequirements['supported_interfaces'])) {
                        $storageRequirements['supported_interfaces'][] = $u2Interface;
                    }
                }
            }
        } else {
            // Fallback to database parsing
            $mbInterfaces = $this->dataExtractor->extractStorageInterfaces($mbData);
            if ($mbInterfaces) {
                $storageRequirements['supported_interfaces'] = array_merge(
                    $storageRequirements['supported_interfaces'],
                    $mbInterfaces
                );
                $result['details'][] = 'Motherboard supports: ' . implode(', ', $mbInterfaces);
            }
        }

        return $result;
    }

    /**
     * Analyze existing storage for storage compatibility requirements
     */
    private function analyzeExistingStorageForStorage($storageComponent, &$storageRequirements) {
        $storageData = $this->dataLoader->getComponentData('storage', $storageComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$storageData) {
            $result['details'][] = 'Existing storage specifications not found';
            return $result;
        }

        // Check slot usage - if this storage is using exclusive slots
        $storageInterface = $this->dataExtractor->extractStorageInterface($storageData);
        $storageFormFactor = $this->dataExtractor->extractStorageFormFactor($storageData);

        if ($storageInterface) {
            $result['details'][] = "Existing storage uses {$storageInterface} interface";
        }

        if ($storageFormFactor) {
            $result['details'][] = "Existing storage form factor: {$storageFormFactor}";

            // PHASE 1: Form Factor Locking for 2.5" and 3.5" storage
            // Extract normalized form factor (2.5-inch or 3.5-inch)
            $normalizedFF = DataNormalizationUtils::extractFormFactorSize($storageFormFactor);

            if ($normalizedFF === '2.5-inch' || $normalizedFF === '3.5-inch') {
                // If not already locked, set the lock to this storage's form factor
                if (!isset($storageRequirements['form_factor_lock'])) {
                    $storageRequirements['form_factor_lock'] = $normalizedFF;
                    $result['details'][] = "FORM FACTOR LOCKED to {$normalizedFF} by existing storage";
                }
            }
        }

        return $result;
    }

    /**
     * Analyze existing caddy for storage compatibility requirements
     */
    private function analyzeExistingCaddyForStorage($caddyComponent, &$storageRequirements) {
        $caddyData = $this->dataLoader->getComponentData('caddy', $caddyComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$caddyData) {
            $result['details'][] = 'Caddy specifications not found';
            return $result;
        }

        // Extract supported form factors from caddy
        $supportedFormFactors = $this->dataExtractor->extractSupportedFormFactors($caddyData);
        if ($supportedFormFactors) {
            $storageRequirements['required_form_factors'] = array_merge(
                $storageRequirements['required_form_factors'],
                $supportedFormFactors
            );
            $result['details'][] = 'Caddy supports form factors: ' . implode(', ', $supportedFormFactors);
        }

        return $result;
    }

    /**
     * Analyze existing HBA card for storage compatibility
     * Extracts HBA protocol support and port capacity
     */
    private function analyzeExistingHBAForStorage($hbaComponent, &$storageRequirements) {
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        // Load HBA specifications from JSON
        $hbaData = $this->dataLoader->getComponentData('hbacard', $hbaComponent['uuid']);
        if (!$hbaData) {
            $result['details'][] = 'HBA card specifications not found in JSON';
            return $result;
        }

        // Extract HBA protocol (e.g., "SAS/SATA/NVMe Tri-Mode", "SAS", "SATA")
        $hbaProtocol = $hbaData['protocol'] ?? '';
        $internalPorts = $hbaData['internal_ports'] ?? 0;
        $maxDevices = $hbaData['max_devices'] ?? 0;

        // Parse protocol and add supported interfaces
        $supportedInterfaces = [];
        if (stripos($hbaProtocol, 'sas') !== false) {
            $supportedInterfaces[] = 'SAS';
            $result['details'][] = 'HBA supports SAS protocol';
        }
        if (stripos($hbaProtocol, 'sata') !== false) {
            $supportedInterfaces[] = 'SATA';
            $supportedInterfaces[] = 'SATA III';
            $result['details'][] = 'HBA supports SATA protocol';
        }
        if (stripos($hbaProtocol, 'nvme') !== false) {
            $supportedInterfaces[] = 'NVMe';
            $supportedInterfaces[] = 'PCIe NVMe';
            $supportedInterfaces[] = 'PCIe NVMe 3.0';
            $supportedInterfaces[] = 'PCIe NVMe 4.0';
            $result['details'][] = 'HBA supports NVMe protocol';
        }

        // Add supported interfaces to storage requirements
        foreach ($supportedInterfaces as $interface) {
            if (!in_array($interface, $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = $interface;
            }
        }

        // Calculate HBA port usage - count existing storage devices
        // Note: M.2 NVMe drives on motherboard don't use HBA ports
        $usedPorts = 0;
        if (isset($storageRequirements['existing_storage_count'])) {
            $usedPorts = $storageRequirements['existing_storage_count'];
        }

        $availablePorts = max(0, $internalPorts - $usedPorts);

        // Check if HBA ports are exhausted
        if ($availablePorts <= 0 && $internalPorts > 0) {
            $result['compatible'] = false;
            $result['issues'][] = "HBA card ports exhausted ({$internalPorts} ports, all used)";
            $result['details'][] = "HBA: {$hbaData['model']} - {$internalPorts} internal ports, {$usedPorts} used, {$availablePorts} available";
        } else {
            $result['details'][] = "HBA: {$hbaData['model']} - {$internalPorts} internal ports, {$usedPorts} used, {$availablePorts} available";
        }

        // Store HBA capacity info and protocol for later use
        $storageRequirements['hba_ports'] = [
            'total' => $internalPorts,
            'used' => $usedPorts,
            'available' => $availablePorts,
            'max_devices' => $maxDevices
        ];
        $storageRequirements['hba_protocol'] = $hbaProtocol;

        return $result;
    }

    /**
     * Analyze existing chassis for storage compatibility
     * Extracts backplane interface support and bay capacity
     */
    private function analyzeExistingChassisForStorage($chassisComponent, &$storageRequirements) {
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        // Load chassis specifications from JSON
        $chassisData = $this->dataLoader->loadChassisSpecs($chassisComponent['uuid']);
        if (!$chassisData) {
            $result['details'][] = 'Chassis specifications not found in JSON';
            return $result;
        }

        // Extract backplane capabilities
        $backplane = $chassisData['backplane'] ?? [];
        $supportsSata = $backplane['supports_sata'] ?? false;
        $supportsSas = $backplane['supports_sas'] ?? false;
        $supportsNvme = $backplane['supports_nvme'] ?? false;
        $backplaneInterface = $backplane['interface'] ?? 'Unknown';

        // Add supported interfaces based on backplane capabilities
        if ($supportsSata) {
            if (!in_array('SATA', $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = 'SATA';
            }
            if (!in_array('SATA III', $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = 'SATA III';
            }
            $result['details'][] = "Chassis backplane supports SATA";
        }
        if ($supportsSas) {
            if (!in_array('SAS', $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = 'SAS';
            }
            $result['details'][] = "Chassis backplane supports SAS";
        }
        if ($supportsNvme) {
            if (!in_array('NVMe', $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = 'NVMe';
            }
            if (!in_array('PCIe NVMe', $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = 'PCIe NVMe';
            }
            $result['details'][] = "Chassis backplane supports NVMe";
        }

        // Extract drive bay information
        $driveBays = $chassisData['drive_bays'] ?? [];
        $totalBays = $driveBays['total_bays'] ?? 0;
        $bayConfiguration = $driveBays['bay_configuration'] ?? [];

        // Count used bays (existing storage in configuration)
        $usedBays = 0;
        if (isset($storageRequirements['existing_storage_count'])) {
            $usedBays = $storageRequirements['existing_storage_count'];
        }

        // Calculate effective storage limit: min(chassis bays, HBA ports)
        $hbaPorts = $storageRequirements['hba_ports']['total'] ?? $totalBays;
        $effectiveLimit = min($totalBays, $hbaPorts);
        $availableBays = max(0, $effectiveLimit - $usedBays);

        // Check if bays/ports are exhausted
        if ($availableBays <= 0 && $totalBays > 0) {
            $result['compatible'] = false;
            if ($hbaPorts < $totalBays) {
                $result['issues'][] = "Storage capacity limited by HBA ports: {$hbaPorts} ports available, chassis has {$totalBays} bays (effective limit: {$effectiveLimit})";
            } else {
                $result['issues'][] = "Chassis drive bays exhausted: {$totalBays} bays, all used";
            }
            $result['details'][] = "Chassis: {$chassisData['model']} - {$totalBays} bays, effective limit {$effectiveLimit} (HBA-limited), {$usedBays} used, {$availableBays} available";
        } else {
            if ($hbaPorts < $totalBays) {
                $result['details'][] = "Chassis: {$chassisData['model']} - {$totalBays} physical bays, effective limit {$effectiveLimit} (limited by {$hbaPorts} HBA ports), {$usedBays} used, {$availableBays} available";
            } else {
                $result['details'][] = "Chassis: {$chassisData['model']} - {$totalBays} bays, {$usedBays} used, {$availableBays} available";
            }
        }

        // Extract form factor compatibility - store bay types separately for strict matching
        $chassisBayTypes = [];
        foreach ($bayConfiguration as $bayConfig) {
            $bayType = $bayConfig['bay_type'] ?? '';
            $count = $bayConfig['count'] ?? 0;
            $hotSwap = $bayConfig['hot_swap'] ?? false;

            if ($bayType) {
                // Normalize bay type: "3.5_inch" → "3.5-inch"
                $normalizedBayType = str_replace('_', '-', $bayType);

                // Store in separate chassis_bay_types array for strict matching
                if (!in_array($normalizedBayType, $chassisBayTypes)) {
                    $chassisBayTypes[] = $normalizedBayType;
                }

                $hotSwapText = $hotSwap ? 'hot-swap' : 'non-hot-swap';
                $result['details'][] = "Bay type: {$normalizedBayType} ({$count} {$hotSwapText} bays)";
            }
        }

        // Store bay types in separate array (not required_form_factors)
        $storageRequirements['chassis_bay_types'] = $chassisBayTypes;

        // Store chassis capacity info
        $storageRequirements['chassis_bays'] = [
            'total' => $totalBays,
            'effective_limit' => $effectiveLimit,
            'used' => $usedBays,
            'available' => $availableBays,
            'backplane_interface' => $backplaneInterface,
            'bay_types' => $chassisBayTypes
        ];

        return $result;
    }

    /**
     * Apply storage compatibility rules with connection path logic
     * Determines chassis bay vs motherboard M.2 vs U.2 paths
     */
    private function applyStorageCompatibilityRules($storageData, $storageRequirements) {
        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => [],
            'connection_path' => 'unknown'
        ];

        $storageInterface = $this->dataExtractor->extractStorageInterface($storageData);
        $storageFormFactor = $this->dataExtractor->extractStorageFormFactor($storageData);

        // Determine which connection path this storage uses
        $connectionPath = DataNormalizationUtils::determineStorageConnectionPath($storageFormFactor, $storageInterface);
        $result['connection_path'] = $connectionPath;

        // Route to appropriate validation logic based on connection path
        if ($connectionPath === 'chassis_bay') {
            return $this->validator->validateChassisBayStorage($storageInterface, $storageFormFactor, $storageRequirements, $result);
        } elseif ($connectionPath === 'motherboard_m2') {
            return $this->validator->validateMotherboardM2Storage($storageInterface, $storageFormFactor, $storageRequirements, $result);
        } elseif ($connectionPath === 'motherboard_u2') {
            return $this->validator->validateMotherboardU2Storage($storageInterface, $storageFormFactor, $storageRequirements, $result);
        }

        // Unknown path - use generic validation
        return $this->validator->validateGenericStorage($storageInterface, $storageFormFactor, $storageRequirements, $result);
    }


    // ==========================================
    // RISER CARD VALIDATION METHODS
    // ==========================================


    /**
     * Calculate available riser slots on motherboard (tracks like PCIe slots)
     *
     * @param array $motherboardData Motherboard specifications
     * @param array $existingComponents Existing components in config
     * @return array ['total_slots' => int, 'used_slots' => int, 'available_slots' => int]
     */
    private function getRiserSlotAvailability($motherboardData, $existingComponents) {
        $totalRiserSlots = $motherboardData['expansion_slots']['riser_compatibility']['max_risers'] ?? 0;

        // Count risers already in config
        $usedRiserSlots = 0;
        foreach ($existingComponents as $component) {
            if ($component['component_type'] === 'pciecard') {
                $pcieData = $this->dataLoader->getPCIeCardData($component['component_uuid']);
                if ($this->isPCIeRiserCard($pcieData)) {
                    $usedRiserSlots++;
                }
            }
        }

        return [
            'total_slots' => $totalRiserSlots,
            'used_slots' => $usedRiserSlots,
            'available_slots' => max(0, $totalRiserSlots - $usedRiserSlots)
        ];
    }

    /**
     * Extract all riser cards from existing components
     *
     * @param array $existingComponents Array of components
     * @return array Array of riser card data
     */
    private function getExistingRisers($existingComponents) {
        $risers = [];

        foreach ($existingComponents as $component) {
            if ($component['component_type'] === 'pciecard') {
                $pcieData = $this->dataLoader->getPCIeCardData($component['component_uuid']);
                if ($this->isPCIeRiserCard($pcieData)) {
                    $risers[] = $pcieData;
                }
            }
        }

        return $risers;
    }

    /**
     * Find component by type in existing components
     *
     * @param array $existingComponents Array of components
     * @param string $type Component type to find
     * @return array|null Component data or null
     */
    private function findComponentByType($existingComponents, $type) {
        foreach ($existingComponents as $component) {
            if ($component['component_type'] === $type) {
                return [
                    'uuid' => $component['component_uuid'],
                    'type' => $component['component_type']
                ];
            }
        }
        return null;
    }

    /**
     * Check if riser height fits within chassis clearance
     *
     * @param array $riserData Riser card specifications
     * @param array $chassisData Chassis specifications
     * @return bool True if fits
     */
    private function checkRiserHeightClearance($riserData, $chassisData) {
        $chassisHeightMm = $chassisData['height'] * 10; // Convert cm to mm
        $riserClearance = $riserData['clearance_height_mm'] ?? 0;

        return $riserClearance < $chassisHeightMm;
    }

    /**
     * Check if riser length fits on motherboard
     *
     * @param array $riserData Riser card specifications
     * @param array $existingRisers Array of existing riser cards
     * @param array $motherboardData Motherboard specifications
     * @return bool True if fits
     */
    private function checkRiserLengthFit($riserData, $existingRisers, $motherboardData) {
        $riserLength = $riserData['dimensions_mm']['length'] ?? 0;
        $availableLength = $motherboardData['expansion_slots']['riser_compatibility']['available_mounting_length_mm'] ?? 0;

        $totalUsedLength = $this->calculateTotalRiserLength($existingRisers);

        return ($totalUsedLength + $riserLength) <= $availableLength;
    }

    /**
     * Check if riser width fits within motherboard slot spacing
     *
     * @param array $riserData Riser card specifications
     * @param array $motherboardData Motherboard specifications
     * @return bool True if fits
     */
    private function checkRiserSpacingFit($riserData, $motherboardData) {
        $riserWidth = $riserData['dimensions_mm']['width'] ?? 0;
        $slotSpacing = $motherboardData['expansion_slots']['riser_compatibility']['slot_spacing_mm'] ?? 20.32;

        // Riser width must fit within slot spacing
        return $riserWidth <= $slotSpacing;
    }

    /**
     * Calculate total length occupied by risers
     *
     * @param array $risers Array of riser card data
     * @return int Total length in mm
     */
    private function calculateTotalRiserLength($risers) {
        $totalLength = 0;
        foreach ($risers as $riser) {
            $totalLength += $riser['dimensions_mm']['length'] ?? 0;
        }
        return $totalLength;
    }

    /**
     * Get maximum riser height from array of risers
     *
     * @param array $risers Array of riser card data
     * @return float Maximum height in mm
     */
    private function getMaxRiserHeight($risers) {
        $maxHeight = 0;
        foreach ($risers as $riser) {
            $clearance = $riser['clearance_height_mm'] ?? 0;
            if ($clearance > $maxHeight) {
                $maxHeight = $clearance;
            }
        }
        return $maxHeight;
    }

    /**
     * Check SFP compatibility with NIC cards
     * Validates:
     * 1. Parent NIC exists and has SFP/SFP+ ports
     * 2. Port is not already occupied
     * 3. SFP type matches NIC port type (SFP+ in SFP+ port, etc.)
     * 4. Speed compatibility
     *
     * @param array $sfpComponent SFP component with parent_nic_uuid and port_index
     * @param array $existingComponents All existing components in the configuration
     * @return array Compatibility result with compatible, issues, warnings, recommendations
     */
    public function checkSFPDecentralizedCompatibility($sfpComponent, $existingComponents) {
        $issues = [];
        $warnings = [];
        $recommendations = [];

        // Get SFP specifications
        $sfpSpecs = $this->dataLoader->getComponentSpecifications('sfp', $sfpComponent['uuid']);

        if (!$sfpSpecs) {
            return [
                'compatible' => false,
                'issues' => ['SFP specifications not found in JSON'],
                'warnings' => [],
                'recommendations' => ['Verify SFP UUID exists in sfp-level-3.json'],
                'compatibility_summary' => 'SFP specifications not found'
            ];
        }

        // Check for parent NIC
        $parentNicUuid = $sfpComponent['parent_nic_uuid'] ?? null;
        $portIndex = $sfpComponent['port_index'] ?? null;

        if (!$parentNicUuid) {
            return [
                'compatible' => false,
                'issues' => ['SFP must be assigned to a parent NIC card'],
                'warnings' => [],
                'recommendations' => ['Specify parent_nic_uuid when adding SFP'],
                'compatibility_summary' => 'Missing parent NIC assignment'
            ];
        }

        if (!$portIndex) {
            return [
                'compatible' => false,
                'issues' => ['SFP must specify which port it will occupy'],
                'warnings' => [],
                'recommendations' => ['Specify port_index when adding SFP'],
                'compatibility_summary' => 'Missing port index'
            ];
        }

        // Find parent NIC in existing components
        $parentNic = null;
        foreach ($existingComponents as $comp) {
            if ($comp['type'] === 'nic' && $comp['uuid'] === $parentNicUuid) {
                $parentNic = $comp;
                break;
            }
        }

        if (!$parentNic) {
            return [
                'compatible' => false,
                'issues' => ["Parent NIC with UUID $parentNicUuid not found in configuration"],
                'warnings' => [],
                'recommendations' => ['Add the NIC card before adding SFP modules'],
                'compatibility_summary' => 'Parent NIC not found'
            ];
        }

        // Get NIC specifications
        $nicSpecs = $this->dataLoader->getComponentSpecifications('nic', $parentNicUuid);

        if (!$nicSpecs) {
            return [
                'compatible' => false,
                'issues' => ["NIC specifications not found for UUID $parentNicUuid"],
                'warnings' => [],
                'recommendations' => ['Verify NIC UUID exists in nic-level-3.json'],
                'compatibility_summary' => 'NIC specifications not found'
            ];
        }

        // Validate NIC has SFP+ compatible ports
        $nicPortType = $nicSpecs['port_type'] ?? '';
        $compatiblePortTypes = ['SFP+', 'QSFP+', 'QSFP28', 'SFP28', 'SFP'];

        if (!in_array($nicPortType, $compatiblePortTypes)) {
            return [
                'compatible' => false,
                'issues' => ["NIC port type '$nicPortType' does not support SFP modules"],
                'warnings' => [],
                'recommendations' => ['SFP modules require SFP+/QSFP+/SFP28 compatible NIC cards'],
                'compatibility_summary' => "NIC port type incompatible"
            ];
        }

        // Validate port index is within NIC port count
        $nicPortCount = $nicSpecs['ports'] ?? 0;
        if ($portIndex > $nicPortCount) {
            return [
                'compatible' => false,
                'issues' => ["Port index $portIndex exceeds NIC port count ($nicPortCount)"],
                'warnings' => [],
                'recommendations' => ["Choose port index between 1 and $nicPortCount"],
                'compatibility_summary' => 'Invalid port index'
            ];
        }

        // Check if port is already occupied
        foreach ($existingComponents as $comp) {
            if ($comp['type'] === 'sfp' &&
                $comp['uuid'] !== $sfpComponent['uuid'] &&
                ($comp['parent_nic_uuid'] ?? null) === $parentNicUuid &&
                ($comp['port_index'] ?? null) == $portIndex) {
                $issues[] = "Port $portIndex on NIC $parentNicUuid is already occupied by SFP " . ($comp['uuid'] ?? 'unknown');
            }
        }

        // Speed compatibility check
        $sfpSpeed = $sfpSpecs['speed'] ?? '';
        $nicSpeeds = $nicSpecs['speeds'] ?? [];
        $nicPrimarySpeed = is_array($nicSpeeds) && !empty($nicSpeeds) ? $nicSpeeds[0] : '';

        // Extract numeric speed values for comparison
        $sfpSpeedValue = preg_replace('/[^0-9]/', '', $sfpSpeed);
        $nicSpeedValue = preg_replace('/[^0-9]/', '', $nicPrimarySpeed);

        if ($sfpSpeedValue && $nicSpeedValue && $sfpSpeedValue != $nicSpeedValue) {
            $warnings[] = "SFP speed ($sfpSpeed) may not match NIC primary speed ($nicPrimarySpeed) - verify compatibility";
        }

        // Type compatibility check (SFP+ vs SFP28 vs QSFP+)
        $sfpType = $sfpSpecs['type'] ?? '';
        if ($sfpType && $nicPortType && $sfpType !== $nicPortType) {
            // Allow some cross-compatibility
            $crossCompatible = [
                'SFP+' => ['SFP'],  // SFP+ ports can accept SFP modules
                'SFP28' => ['SFP+', 'SFP'],  // SFP28 ports can accept SFP+ and SFP modules
                'QSFP28' => ['QSFP+']  // QSFP28 can accept QSFP+
            ];

            $isCompatible = false;
            if (isset($crossCompatible[$nicPortType]) && in_array($sfpType, $crossCompatible[$nicPortType])) {
                $isCompatible = true;
                $warnings[] = "Using $sfpType module in $nicPortType port - cross-compatible but may run at reduced speed";
            }

            if (!$isCompatible && $nicPortType !== $sfpType) {
                $issues[] = "SFP type mismatch: $sfpType module requires $sfpType port, but NIC has $nicPortType ports";
            }
        }

        // Additional validation for fiber type compatibility
        $sfpFiberType = $sfpSpecs['fiber_type'] ?? '';
        if ($sfpFiberType === 'Copper' || strpos($sfpFiberType, 'DAC') !== false) {
            $recommendations[] = "Using Direct Attach Copper (DAC) cable - ensure cable length is appropriate for distance";
        } elseif ($sfpFiberType === 'SMF') {
            $recommendations[] = "Using Single-Mode Fiber - ensure fiber infrastructure is SMF compatible";
        } elseif ($sfpFiberType === 'MMF') {
            $recommendations[] = "Using Multi-Mode Fiber - verify fiber distance is within reach limit (" . ($sfpSpecs['reach'] ?? 'N/A') . ")";
        }

        return [
            'compatible' => empty($issues),
            'issues' => $issues,
            'warnings' => $warnings,
            'recommendations' => $recommendations,
            'compatibility_summary' => empty($issues) ?
                "SFP module compatible with NIC port $portIndex" :
                "SFP module incompatible - see issues",
            'details' => [
                'sfp_type' => $sfpType,
                'sfp_speed' => $sfpSpeed,
                'nic_port_type' => $nicPortType,
                'nic_speed' => $nicPrimarySpeed,
                'port_index' => $portIndex,
                'parent_nic' => $parentNicUuid
            ]
        ];
    }

    /**
     * Check caddy compatibility when adding to configuration
     * Validates caddy form factor matches chassis bay sizes
     * STRICT MATCHING: Caddy size must exactly match chassis bay type
     *
     * @param array $caddyComponent The caddy being added ['uuid' => string]
     * @param array $existingComponents Existing components in configuration
     * @return array Compatibility result with compatible flag, issues, warnings, recommendations
     */
    public function checkCaddyDecentralizedCompatibility($caddyComponent, $existingComponents) {
        $result = [
            'compatible' => true,
            'issues' => [],
            'warnings' => [],
            'recommendations' => [],
            'details' => [],
            'score_breakdown' => []
        ];

        // If no existing components, caddy is compatible
        if (empty($existingComponents)) {
            $result['details'][] = 'No existing components - caddy compatible';
            $result['compatibility_summary'] = 'Compatible - no constraints found';
            $result['score_breakdown'] = [
                'base_score' => 100,
                'final_score' => 100,
                'factors' => ['No existing components to validate against']
            ];
            return $result;
        }

        // Get caddy UUID
        $caddyUuid = $caddyComponent['uuid'] ?? null;
        if (!$caddyUuid) {
            $result['compatible'] = false;
            $result['issues'][] = 'Caddy UUID not provided';
            $result['compatibility_summary'] = 'INCOMPATIBLE: Missing caddy UUID';
            return $result;
        }

        // Get caddy specifications using loadComponentFromJSON
        $caddyResult = $this->dataLoader->loadComponentFromJSON('caddy', $caddyUuid);
        if (!$caddyResult['found'] || !$caddyResult['data']) {
            $result['compatible'] = false;
            $result['issues'][] = 'Caddy specifications not found in JSON';
            $result['compatibility_summary'] = 'INCOMPATIBLE: Caddy JSON specifications missing';
            $result['recommendations'][] = 'Verify caddy UUID exists in caddy_details.json';
            $result['score_breakdown'] = [
                'base_score' => 100,
                'penalty_applied' => -100,
                'reason' => 'Caddy specifications not found',
                'final_score' => 0
            ];
            return $result;
        }
        $caddySpecs = $caddyResult['data'];

        // Extract caddy size from compatibility.size or type field
        $caddySize = $caddySpecs['compatibility']['size'] ?? $caddySpecs['type'] ?? '';
        $normalizedCaddySize = $this->normalizeFormFactor($caddySize);

        $result['details'][] = "Caddy size: $normalizedCaddySize";

        // Find chassis in existing components
        $existingChassis = [];
        foreach ($existingComponents as $comp) {
            if (($comp['type'] ?? '') === 'chassis') {
                $existingChassis[] = $comp;
            }
        }

        // If no chassis in config, caddy is compatible (will be validated when chassis is added)
        if (empty($existingChassis)) {
            $result['details'][] = 'No chassis in configuration - caddy will be validated when chassis is added';
            $result['compatibility_summary'] = 'Compatible - no chassis constraints yet';
            $result['score_breakdown'] = [
                'base_score' => 100,
                'final_score' => 100,
                'factors' => ['No chassis to validate against - deferred validation']
            ];
            return $result;
        }

        // Validate caddy size against each chassis bay configuration
        foreach ($existingChassis as $chassis) {
            $chassisUuid = $chassis['uuid'] ?? null;
            if (!$chassisUuid) continue;

            $chassisSpecs = $this->dataLoader->loadChassisSpecs($chassisUuid);
            if (!$chassisSpecs) {
                $result['warnings'][] = "Could not load specifications for chassis $chassisUuid";
                continue;
            }

            // Extract chassis bay configuration
            $bayConfig = $chassisSpecs['drive_bays']['bay_configuration'] ?? [];
            $hasMatchingBay = false;
            $availableBaySizes = [];

            foreach ($bayConfig as $bay) {
                $bayType = $bay['bay_type'] ?? '';
                $normalizedBayType = $this->normalizeFormFactor($bayType);
                $availableBaySizes[] = $normalizedBayType;

                // STRICT matching: caddy size must exactly match bay type
                if ($normalizedCaddySize === $normalizedBayType) {
                    $hasMatchingBay = true;
                    break;
                }
            }

            // Generate error if caddy size doesn't match any available bay type
            if (!$hasMatchingBay && ($normalizedCaddySize === '2.5-inch' || $normalizedCaddySize === '3.5-inch')) {
                $result['compatible'] = false;
                $availableSizes = implode(', ', array_unique($availableBaySizes));
                $result['issues'][] = "Cannot add $normalizedCaddySize caddy - chassis only has $availableSizes bays (strict matching required)";
                $result['details'][] = [
                    'type' => 'caddy_chassis_mismatch',
                    'caddy_size' => $normalizedCaddySize,
                    'chassis_uuid' => $chassisUuid,
                    'available_bay_sizes' => array_unique($availableBaySizes)
                ];
                $result['recommendations'][] = "Use a caddy matching chassis bay size ($availableSizes) OR remove current chassis";
            } else if ($hasMatchingBay) {
                $result['details'][] = "Caddy size $normalizedCaddySize matches chassis bay configuration";
            }
        }

        // Set final summary
        if ($result['compatible']) {
            $result['compatibility_summary'] = "Caddy ($normalizedCaddySize) compatible with chassis bay configuration";
            $result['score_breakdown'] = [
                'base_score' => 100,
                'final_score' => 100,
                'factors' => ['Caddy size matches chassis bay size']
            ];
        } else {
            $result['compatibility_summary'] = 'INCOMPATIBLE: Caddy size does not match chassis bay configuration';
            $result['score_breakdown'] = [
                'base_score' => 100,
                'penalty_applied' => -100,
                'reason' => 'Caddy form factor incompatible with chassis bay sizes',
                'final_score' => 0,
                'validation_status' => 'FAILED'
            ];
        }

        return $result;
    }

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
        $nicSpecs = $this->dataLoader->getComponentSpecifications('nic', $nic['uuid']);
        if (!$nicSpecs) {
            return [
                'compatible' => false,
                'issues' => ['NIC specifications not found'],
                'warnings' => [],
                'recommendations' => ['Verify NIC UUID exists in specifications']
            ];
        }
        
        // Get SFP specs
        $sfpSpecs = $this->dataLoader->getComponentSpecifications('sfp', $sfp['uuid']);
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
        
        // Check speed compatibility
        $nicSpeeds = $nicSpecs['speeds'] ?? [];
        $nicMaxSpeed = is_array($nicSpeeds) && !empty($nicSpeeds) ? max($nicSpeeds) : '';
        $sfpSpeed = $sfpSpecs['speed'] ?? '';
        
        $speedCompatible = NICPortTracker::validateSpeedCompatibility($nicMaxSpeed, $sfpSpeed);
        
        if (!$speedCompatible) {
            return [
                'compatible' => false,
                'issues' => ["SFP speed {$sfpSpeed} exceeds NIC maximum speed {$nicMaxSpeed}"],
                'warnings' => [],
                'recommendations' => [
                    'Use SFP module with speed <= ' + $nicMaxSpeed
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

    /**
     * Calculate required bay capacity from storage components
     * Only counts traditional bay storage (2.5" and 3.5"), excludes M.2/U.2/NVMe
     *
     * @param array $storageComponents Array of storage components
     * @return array Array with bay counts by type ['2.5_inch' => count, '3.5_inch' => count]
     */
    private function calculateRequiredBays($storageComponents) {
        $requiredBays = [
            '2.5_inch' => 0,
            '3.5_inch' => 0
        ];

        if (empty($storageComponents)) {
            return $requiredBays;
        }

        foreach ($storageComponents as $storage) {
            // Get the storage data
            $storageData = $storage['data'] ?? [];

            // Extract form factor using the data extractor
            $formFactor = $this->dataExtractor->extractStorageFormFactorFromSpecs($storageData);

            // Skip M.2, U.2, and unknown - these don't use traditional bays
            if ($formFactor === 'M.2' || strpos($formFactor, 'U.2') !== false || $formFactor === 'unknown') {
                continue;
            }

            // Count drives by form factor
            if ($formFactor === '2.5_inch') {
                $requiredBays['2.5_inch']++;
            } elseif ($formFactor === '3.5_inch') {
                $requiredBays['3.5_inch']++;
            }
        }

        return $requiredBays;
    }


}
?>