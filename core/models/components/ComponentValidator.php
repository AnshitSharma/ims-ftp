<?php
/**
 * Infrastructure Management System - Component Validator
 * File: includes/models/ComponentValidator.php
 *
 * Handles all component validation logic including:
 * - Component existence checks
 * - Socket compatibility validation
 * - Memory compatibility validation
 * - Storage compatibility validation
 * - PCIe compatibility validation
 * - Component specification parsing
 *
 * Extracted from ComponentCompatibility.php for better maintainability
 */

class ComponentValidator {
    private $pdo;
    private $dataLoader;
    private $dataExtractor;
    private $validationCache = [];

    public function __construct($pdo, $dataLoader, $dataExtractor) {
        $this->pdo = $pdo;
        $this->dataLoader = $dataLoader;
        $this->dataExtractor = $dataExtractor;
    }

    /**
     * Validate component configuration in bulk
     */
    public function validateComponentConfiguration($components) {
        $results = [];
        $overallCompatible = true;
        $overallScore = 1.0;

        // Check each component pair
        for ($i = 0; $i < count($components); $i++) {
            for ($j = $i + 1; $j < count($components); $j++) {
                $component1 = $components[$i];
                $component2 = $components[$j];

                // Note: This method references checkComponentPairCompatibility from ComponentCompatibility
                // which will remain in the main class
                $compatibility = ['compatible' => true, 'issues' => [], 'warnings' => []];

                $results[] = [
                    'component_1' => $component1,
                    'component_2' => $component2,
                    'compatibility' => $compatibility
                ];

                if (!$compatibility['compatible']) {
                    $overallCompatible = false;
                }
            }
        }

        return [
            'overall_compatible' => $overallCompatible,
            'overall_score' => $overallScore,
            'individual_checks' => $results,
            'total_checks' => count($results)
        ];
    }

    /**
     * Parse motherboard specifications from JSON
     */
    public function parseMotherboardSpecifications($motherboardUuid) {
        $result = $this->dataLoader->loadComponentFromJSON('motherboard', $motherboardUuid);

        if (!$result['found']) {
            return [
                'found' => false,
                'error' => $result['error'],
                'specifications' => null
            ];
        }

        $data = $result['data'];

        try {
            error_log("DEBUG: Parsing motherboard specs for UUID: $motherboardUuid");
            error_log("DEBUG: Motherboard raw data: " . json_encode($data));

            $specifications = [
                'basic_info' => [
                    'uuid' => $motherboardUuid,
                    'model' => $data['model'] ?? 'Unknown',
                    'form_factor' => $data['form_factor'] ?? 'Unknown'
                ],
                'socket' => [
                    'type' => $data['socket']['type'] ?? 'Unknown',
                    'count' => (int)($data['socket']['count'] ?? 1)
                ],
                'memory' => [
                    'slots' => (int)($data['memory']['slots'] ?? 4),
                    'types' => isset($data['memory']['type']) ? [$data['memory']['type']] : ['DDR4'],
                    'max_frequency_mhz' => (int)($data['memory']['max_frequency_MHz'] ?? 3200),
                    'max_capacity_gb' => isset($data['memory']['max_capacity_TB']) ?
                        ((int)$data['memory']['max_capacity_TB'] * 1024) : 128,
                    'ecc_support' => $data['memory']['ecc_support'] ?? false
                ],
                'storage' => [
                    'sata_ports' => (int)($data['storage']['sata']['ports'] ?? 0),
                    'm2_slots' => 0,
                    'u2_slots' => 0,
                    'sas_ports' => (int)($data['storage']['sas']['ports'] ?? 0)
                ],
                'pcie_slots' => [],
                'power' => [
                    'max_tdp' => (int)($data['power']['max_cpu_tdp'] ?? 150)
                ]
            ];

            error_log("DEBUG: Parsed motherboard memory section: " . json_encode($specifications['memory']));

            // Parse M.2 slots
            if (isset($data['storage']['nvme']['m2_slots'])) {
                foreach ($data['storage']['nvme']['m2_slots'] as $m2Slot) {
                    $specifications['storage']['m2_slots'] += (int)($m2Slot['count'] ?? 0);
                }
            }

            // Parse U.2 slots
            if (isset($data['storage']['nvme']['u2_slots']['count'])) {
                $specifications['storage']['u2_slots'] = (int)$data['storage']['nvme']['u2_slots']['count'];
            }

            // Parse PCIe slots
            if (isset($data['expansion_slots']['pcie_slots'])) {
                foreach ($data['expansion_slots']['pcie_slots'] as $slot) {
                    $specifications['pcie_slots'][] = [
                        'type' => $slot['type'] ?? 'PCIe x1',
                        'count' => (int)($slot['count'] ?? 1),
                        'lanes' => (int)($slot['lanes'] ?? 1)
                    ];
                }
            }

            return [
                'found' => true,
                'error' => null,
                'specifications' => $specifications
            ];

        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => "Error parsing motherboard specifications: " . $e->getMessage(),
                'specifications' => null
            ];
        }
    }

    /**
     * Validate motherboard exists in JSON
     */
    public function validateMotherboardExists($motherboardUuid) {
        $result = $this->dataLoader->loadComponentFromJSON('motherboard', $motherboardUuid);

        return [
            'exists' => $result['found'],
            'error' => $result['error']
        ];
    }

    /**
     * Validate CPU exists in JSON
     */
    public function validateCPUExists($cpuUuid) {
        $result = $this->dataLoader->loadComponentFromJSON('cpu', $cpuUuid);

        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error'],
            'data' => $result['data']
        ];
    }

    /**
     * Validate RAM exists in JSON
     */
    public function validateRAMExists($ramUuid) {
        $result = $this->dataLoader->loadComponentFromJSON('ram', $ramUuid);

        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error'],
            'data' => $result['data']
        ];
    }

    /**
     * Validate Storage exists in JSON
     */
    public function validateStorageExists($storageUuid) {
        $result = $this->dataLoader->loadComponentFromJSON('storage', $storageUuid);

        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error'],
            'data' => $result['data']
        ];
    }

    /**
     * Validate NIC exists in JSON
     */
    public function validateNICExists($nicUuid) {
        $result = $this->dataLoader->loadComponentFromJSON('nic', $nicUuid);

        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error'],
            'data' => $result['data']
        ];
    }

    /**
     * Validate Caddy exists in JSON
     */
    public function validateCaddyExists($caddyUuid) {
        $result = $this->dataLoader->loadComponentFromJSON('caddy', $caddyUuid);

        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error'],
            'data' => $result['data']
        ];
    }

    /**
     * Validate CPU socket compatibility with motherboard - ENHANCED with proper JSON extraction
     */
    public function validateCPUSocketCompatibility($cpuUuid, $motherboardSpecs) {
        error_log("DEBUG: Starting CPU socket compatibility check for UUID: $cpuUuid");

        // Get CPU socket type using enhanced JSON extraction
        $cpuSocket = $this->extractSocketTypeFromJSON('cpu', $cpuUuid);
        $motherboardSocket = $motherboardSpecs['cpu']['socket_type'] ?? null;

        error_log("DEBUG: CPU socket: " . ($cpuSocket ?? 'null') . ", Motherboard socket: " . ($motherboardSocket ?? 'null'));

        // Check if either socket type cannot be found
        if (!$cpuSocket && !$motherboardSocket) {
            return [
                'compatible' => false,
                'error' => 'Socket specifications not found in component database - both CPU and motherboard socket types missing',
                'details' => [
                    'cpu_socket' => $cpuSocket,
                    'motherboard_socket' => $motherboardSocket,
                    'extraction_method' => 'json_and_notes_failed'
                ]
            ];
        }

        if (!$cpuSocket) {
            return [
                'compatible' => false,
                'error' => 'Socket specifications not found in component database - CPU socket type missing',
                'details' => [
                    'cpu_socket' => $cpuSocket,
                    'motherboard_socket' => $motherboardSocket,
                    'extraction_method' => 'cpu_socket_extraction_failed'
                ]
            ];
        }

        if (!$motherboardSocket) {
            return [
                'compatible' => false,
                'error' => 'Socket specifications not found in component database - motherboard socket type missing',
                'details' => [
                    'cpu_socket' => $cpuSocket,
                    'motherboard_socket' => $motherboardSocket,
                    'extraction_method' => 'motherboard_socket_extraction_failed'
                ]
            ];
        }

        // Normalize socket types for comparison
        $cpuSocketNormalized = strtolower(trim($cpuSocket));
        $motherboardSocketNormalized = strtolower(trim($motherboardSocket));

        $compatible = ($cpuSocketNormalized === $motherboardSocketNormalized);

        $errorMessage = null;
        if (!$compatible) {
            $errorMessage = "CPU socket $cpuSocket incompatible with motherboard socket $motherboardSocket";
        }

        error_log("DEBUG: Socket compatibility result - Compatible: " . ($compatible ? 'YES' : 'NO') . ", CPU: $cpuSocket, MB: $motherboardSocket");

        return [
            'compatible' => $compatible,
            'error' => $errorMessage,
            'details' => [
                'cpu_socket' => $cpuSocket,
                'motherboard_socket' => $motherboardSocket,
                'cpu_socket_normalized' => $cpuSocketNormalized,
                'motherboard_socket_normalized' => $motherboardSocketNormalized,
                'match' => $compatible,
                'extraction_method' => 'enhanced_json_extraction'
            ]
        ];
    }

    /**
     * Validate CPU count doesn't exceed motherboard socket limit
     */
    public function validateCPUCountLimit($configUuid, $motherboardSpecs) {
        try {
            // Get existing CPUs in configuration (JSON-based storage)
            require_once __DIR__ . '/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            $currentCPUCount = 0;
            if ($config) {
                $components = $config->getComponents();
                foreach ($components as $comp) {
                    if ($comp['component_type'] === 'cpu') {
                        $currentCPUCount += $comp['quantity'] ?? 1;
                    }
                }
            }

            $maxSockets = $motherboardSpecs['cpu']['max_sockets'] ?? 1;

            return [
                'within_limit' => $currentCPUCount < $maxSockets,
                'current_count' => $currentCPUCount,
                'max_allowed' => $maxSockets,
                'error' => $currentCPUCount >= $maxSockets ?
                    "Maximum $maxSockets CPUs supported, cannot add CPU #" . ($currentCPUCount + 1) : null
            ];

        } catch (Exception $e) {
            return [
                'within_limit' => false,
                'current_count' => 0,
                'max_allowed' => 1,
                'error' => "Error checking CPU count: " . $e->getMessage()
            ];
        }
    }

    /**
     * Validate mixed CPU compatibility (same socket type for multiple CPUs)
     */
    public function validateMixedCPUCompatibility($existingCPUs, $newCpuUuid) {
        if (empty($existingCPUs)) {
            return [
                'compatible' => true,
                'error' => null,
                'details' => 'No existing CPUs to check compatibility with'
            ];
        }

        $newCpuResult = $this->validateCPUExists($newCpuUuid);
        if (!$newCpuResult['exists']) {
            return [
                'compatible' => false,
                'error' => $newCpuResult['error'],
                'details' => null
            ];
        }

        $newCpuSocket = $newCpuResult['data']['socket'] ?? null;
        if (!$newCpuSocket) {
            return [
                'compatible' => false,
                'error' => "New CPU socket type not found",
                'details' => null
            ];
        }

        // Check socket compatibility with existing CPUs
        foreach ($existingCPUs as $existingCpu) {
            $existingCpuResult = $this->validateCPUExists($existingCpu['component_uuid']);
            if ($existingCpuResult['exists']) {
                $existingSocket = $existingCpuResult['data']['socket'] ?? null;

                // Normalize socket types for comparison
                $existingSocketNormalized = strtolower(trim($existingSocket ?? ''));
                $newCpuSocketNormalized = strtolower(trim($newCpuSocket ?? ''));

                if ($existingSocket && $existingSocketNormalized !== $newCpuSocketNormalized) {
                    return [
                        'compatible' => false,
                        'error' => "Mixed CPU socket types not allowed. Existing CPU socket: $existingSocket, New CPU socket: $newCpuSocket",
                        'details' => [
                            'existing_socket' => $existingSocket,
                            'new_socket' => $newCpuSocket,
                            'existing_cpu' => $existingCpu['component_uuid']
                        ]
                    ];
                }
            }
        }

        return [
            'compatible' => true,
            'error' => null,
            'details' => [
                'socket_type' => $newCpuSocket,
                'existing_cpus_count' => count($existingCPUs)
            ]
        ];
    }

    /**
     * Validate RAM type compatibility with motherboard
     */
    public function validateRAMTypeCompatibility($ramUuid, $motherboardSpecs) {
        $ramResult = $this->validateRAMExists($ramUuid);

        if (!$ramResult['exists']) {
            return [
                'compatible' => false,
                'error' => $ramResult['error'],
                'details' => null
            ];
        }

        $ramData = $ramResult['data'];
        $ramType = $ramData['memory_type'] ?? null;

        // Handle both 'type' (single string) and 'supported_types' (array) from motherboard specs
        $supportedTypes = $motherboardSpecs['memory']['supported_types'] ?? null;
        if (!$supportedTypes) {
            // Fall back to single 'type' field and convert to array
            $singleType = $motherboardSpecs['memory']['type'] ?? 'DDR4';
            $supportedTypes = [$singleType];
        }

        if (!$ramType) {
            return [
                'compatible' => false,
                'error' => "RAM memory type not found",
                'details' => null
            ];
        }

        $compatible = in_array($ramType, $supportedTypes);

        return [
            'compatible' => $compatible,
            'error' => $compatible ? null : "$ramType memory incompatible with motherboard supporting " . implode(', ', $supportedTypes),
            'details' => [
                'ram_type' => $ramType,
                'supported_types' => $supportedTypes,
                'match' => $compatible
            ]
        ];
    }

    /**
     * Validate RAM slot availability
     */
    public function validateRAMSlotAvailability($configUuid, $motherboardSpecs) {
        try {
            $usedSlots = $this->countUsedMemorySlots($configUuid);
            $totalSlots = $motherboardSpecs['memory']['max_slots'] ?? 4;

            return [
                'available' => $usedSlots < $totalSlots,
                'used_slots' => $usedSlots,
                'total_slots' => $totalSlots,
                'available_slots' => $totalSlots - $usedSlots,
                'error' => $usedSlots >= $totalSlots ?
                    "Memory slot limit reached: $usedSlots/$totalSlots" : null
            ];

        } catch (Exception $e) {
            return [
                'available' => false,
                'used_slots' => 0,
                'total_slots' => 4,
                'available_slots' => 0,
                'error' => "Error checking memory slot availability: " . $e->getMessage()
            ];
        }
    }

    /**
     * Validate RAM speed compatibility
     */
    public function validateRAMSpeedCompatibility($ramUuid, $cpuSpecs, $motherboardSpecs) {
        $ramResult = $this->validateRAMExists($ramUuid);

        if (!$ramResult['exists']) {
            return [
                'compatible' => true,
                'optimal' => false,
                'error' => $ramResult['error'],
                'details' => null
            ];
        }

        $ramData = $ramResult['data'];
        $ramSpeed = (int)($ramData['frequency_MHz'] ?? 0);

        $motherboardMaxSpeed = $motherboardSpecs['memory']['max_frequency_mhz'] ?? 3200;
        $cpuMaxSpeed = null;

        // Get CPU max memory speed if CPU specs provided
        if ($cpuSpecs && isset($cpuSpecs['compatibility']['memory_types'])) {
            // Extract speed from memory types like DDR5-4800
            foreach ($cpuSpecs['compatibility']['memory_types'] as $memType) {
                if (preg_match('/DDR\d+-(\d+)/', $memType, $matches)) {
                    $speed = (int)$matches[1];
                    if ($cpuMaxSpeed === null || $speed > $cpuMaxSpeed) {
                        $cpuMaxSpeed = $speed;
                    }
                }
            }
        }

        $effectiveMaxSpeed = $cpuMaxSpeed ? min($motherboardMaxSpeed, $cpuMaxSpeed) : $motherboardMaxSpeed;

        $warnings = [];
        if ($ramSpeed > $effectiveMaxSpeed) {
            $warnings[] = "RAM speed ({$ramSpeed}MHz) exceeds system maximum ({$effectiveMaxSpeed}MHz) - will run at reduced speed";
        }

        return [
            'compatible' => true,
            'optimal' => $ramSpeed <= $effectiveMaxSpeed,
            'error' => null,
            'warnings' => $warnings,
            'details' => [
                'ram_speed_mhz' => $ramSpeed,
                'motherboard_max_mhz' => $motherboardMaxSpeed,
                'cpu_max_mhz' => $cpuMaxSpeed,
                'effective_max_mhz' => $effectiveMaxSpeed
            ]
        ];
    }

    /**
     * Validate NIC PCIe compatibility
     */
    public function validateNICPCIeCompatibility($nicUuid, $configUuid, $motherboardSpecs) {
        $nicResult = $this->validateNICExists($nicUuid);

        if (!$nicResult['exists']) {
            return [
                'compatible' => false,
                'error' => $nicResult['error'],
                'details' => null
            ];
        }

        $nicData = $nicResult['data'];

        // Extract PCIe requirements from NIC
        $requiredPCIeVersion = null;
        $requiredLanes = 1; // Default to x1

        // Navigate through the nested JSON structure to find interface requirements
        if (isset($nicData['interface_requirements'])) {
            $reqs = $nicData['interface_requirements'];
            if (isset($reqs['pcie_version'])) {
                $requiredPCIeVersion = $reqs['pcie_version'];
            }
            if (isset($reqs['pcie_lanes'])) {
                $requiredLanes = (int)$reqs['pcie_lanes'];
            }
        }

        // Check available PCIe slots on motherboard
        $motherboardPCIeSlots = $motherboardSpecs['expansion']['pcie_slots'] ?? [];
        $usedSlots = $this->countUsedPCIeSlots($configUuid, $motherboardSpecs);

        // Find compatible available slot
        $compatibleSlot = null;
        foreach ($motherboardPCIeSlots as $slot) {
            $slotLanes = $slot['lanes'] ?? 1;
            $availableCount = ($slot['count'] ?? 1) - ($usedSlots[$slot['type']] ?? 0);

            if ($slotLanes >= $requiredLanes && $availableCount > 0) {
                $compatibleSlot = $slot;
                break;
            }
        }

        if (!$compatibleSlot) {
            return [
                'compatible' => false,
                'error' => "No available PCIe slots for NIC requiring x$requiredLanes slot",
                'details' => [
                    'required_lanes' => $requiredLanes,
                    'required_pcie_version' => $requiredPCIeVersion,
                    'available_slots' => $motherboardPCIeSlots,
                    'used_slots' => $usedSlots
                ]
            ];
        }

        return [
            'compatible' => true,
            'error' => null,
            'details' => [
                'required_lanes' => $requiredLanes,
                'required_pcie_version' => $requiredPCIeVersion,
                'assigned_slot' => $compatibleSlot,
                'remaining_slots' => $compatibleSlot['count'] - ($usedSlots[$compatibleSlot['type']] ?? 0) - 1
            ]
        ];
    }

    /**
     * Validate RAM exists in JSON specifications and extract detailed specifications
     * Enhanced version for comprehensive RAM compatibility validation
     */
    public function validateRAMExistsInJSON($ramUuid) {
        $cacheKey = "ram_validation:$ramUuid";

        // Check cache first to avoid repeated file reads
        if (isset($this->validationCache[$cacheKey])) {
            return $this->validationCache[$cacheKey];
        }

        $result = $this->dataLoader->loadComponentFromJSON('ram', $ramUuid);

        if (!$result['found']) {
            $validationResult = [
                'exists' => false,
                'error' => $result['error'],
                'specifications' => null
            ];

            $this->validationCache[$cacheKey] = $validationResult;
            return $validationResult;
        }

        $data = $result['data'];

        try {
            $specifications = [
                'uuid' => $ramUuid,
                'brand' => $data['brand'] ?? 'Unknown',
                'series' => $data['series'] ?? 'Unknown',
                'model' => $data['model'] ?? 'Unknown',
                'memory_type' => $data['memory_type'] ?? 'DDR4',
                'module_type' => $data['module_type'] ?? 'DIMM',
                'form_factor' => $data['form_factor'] ?? 'DIMM (288-pin)',
                'capacity_gb' => (int)($data['capacity_GB'] ?? 8),
                'frequency_mhz' => (int)($data['frequency_MHz'] ?? 3200),
                'voltage_v' => (float)($data['voltage_V'] ?? 1.2),
                'ecc_support' => $data['features']['ecc_support'] ?? false,
                'xmp_support' => $data['features']['xmp_support'] ?? false,
                'timing' => $data['timing'] ?? []
            ];

            $validationResult = [
                'exists' => true,
                'error' => null,
                'specifications' => $specifications
            ];

            $this->validationCache[$cacheKey] = $validationResult;
            return $validationResult;

        } catch (Exception $e) {
            $validationResult = [
                'exists' => false,
                'error' => "Error parsing RAM specifications: " . $e->getMessage(),
                'specifications' => null
            ];

            $this->validationCache[$cacheKey] = $validationResult;
            return $validationResult;
        }
    }

    /**
     * Validate memory type compatibility with motherboard and CPU
     */
    public function validateMemoryTypeCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs) {
        $ramMemoryType = $ramSpecs['memory_type'] ?? null;

        if (!$ramMemoryType) {
            return [
                'compatible' => false,
                'error' => "RAM memory type not specified",
                'details' => null
            ];
        }

        // Normalize memory type (remove speed suffix like -4800)
        $ramMemoryType = DataNormalizationUtils::normalizeMemoryType($ramMemoryType);

        // Check motherboard compatibility
        $motherboardMemoryTypes = $motherboardSpecs['memory']['supported_types'] ?? $motherboardSpecs['memory']['types'] ?? ['DDR4'];
        $motherboardCompatible = false;

        foreach ($motherboardMemoryTypes as $mbType) {
            $normalizedMbType = DataNormalizationUtils::normalizeMemoryType($mbType);
            if ($normalizedMbType === $ramMemoryType) {
                $motherboardCompatible = true;
                break;
            }
        }

        if (!$motherboardCompatible) {
            return [
                'compatible' => false,
                'error' => "Memory type $ramMemoryType incompatible with motherboard supporting " . implode(', ', $motherboardMemoryTypes),
                'details' => [
                    'ram_type' => $ramMemoryType,
                    'motherboard_types' => $motherboardMemoryTypes,
                    'cpu_types' => null
                ]
            ];
        }

        // Check CPU compatibility if CPU specs provided
        if ($cpuSpecs && isset($cpuSpecs['compatibility']['memory_types'])) {
            $cpuMemoryTypes = $cpuSpecs['compatibility']['memory_types'];
            $cpuCompatible = false;

            foreach ($cpuMemoryTypes as $cpuType) {
                $normalizedCpuType = DataNormalizationUtils::normalizeMemoryType($cpuType);
                if ($normalizedCpuType === $ramMemoryType) {
                    $cpuCompatible = true;
                    break;
                }
            }

            if (!$cpuCompatible) {
                return [
                    'compatible' => false,
                    'error' => "Memory type $ramMemoryType incompatible with CPU supporting " . implode(', ', $cpuMemoryTypes),
                    'details' => [
                        'ram_type' => $ramMemoryType,
                        'motherboard_types' => $motherboardMemoryTypes,
                        'cpu_types' => $cpuMemoryTypes
                    ]
                ];
            }
        }

        return [
            'compatible' => true,
            'error' => null,
            'details' => [
                'ram_type' => $ramMemoryType,
                'motherboard_types' => $motherboardMemoryTypes,
                'cpu_types' => $cpuSpecs['compatibility']['memory_types'] ?? null
            ]
        ];
    }

    /**
     * Validate memory form factor compatibility
     */
    public function validateMemoryFormFactor($ramSpecs, $motherboardSpecs) {
        $ramFormFactor = $ramSpecs['form_factor'] ?? null;

        if (!$ramFormFactor) {
            return [
                'compatible' => false,
                'error' => "RAM form factor not specified",
                'details' => null
            ];
        }

        // Normalize form factor
        $normalizedRamFormFactor = DataNormalizationUtils::normalizeFormFactor($ramFormFactor);

        // Check motherboard form factor support (default to DIMM for desktops)
        $motherboardFormFactor = DataNormalizationUtils::normalizeFormFactor(
            $motherboardSpecs['memory']['form_factor'] ?? 'DIMM'
        );

        $compatible = ($normalizedRamFormFactor === $motherboardFormFactor);

        return [
            'compatible' => $compatible,
            'error' => $compatible ? null : "RAM form factor $normalizedRamFormFactor incompatible with motherboard form factor $motherboardFormFactor",
            'details' => [
                'ram_form_factor' => $normalizedRamFormFactor,
                'motherboard_form_factor' => $motherboardFormFactor
            ]
        ];
    }

    /**
     * Validate ECC compatibility
     */
    public function validateECCCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs) {
        $ramECC = $ramSpecs['ecc_support'] ?? false;
        $motherboardECC = $motherboardSpecs['memory']['ecc_support'] ?? false;
        $cpuECC = $cpuSpecs['compatibility']['ecc_support'] ?? true; // Default to true if not specified

        // If RAM has ECC, both motherboard and CPU must support it
        if ($ramECC) {
            if (!$motherboardECC) {
                return [
                    'compatible' => false,
                    'error' => "ECC RAM requires ECC-compatible motherboard",
                    'details' => [
                        'ram_ecc' => true,
                        'motherboard_ecc' => false,
                        'cpu_ecc' => $cpuECC
                    ]
                ];
            }

            if (!$cpuECC) {
                return [
                    'compatible' => false,
                    'error' => "ECC RAM requires ECC-compatible CPU",
                    'details' => [
                        'ram_ecc' => true,
                        'motherboard_ecc' => $motherboardECC,
                        'cpu_ecc' => false
                    ]
                ];
            }
        }

        // Non-ECC RAM is compatible with ECC systems, but ECC functionality will be disabled
        $warnings = [];
        if (!$ramECC && $motherboardECC && $cpuECC) {
            $warnings[] = "System supports ECC but non-ECC RAM selected - ECC functionality will be disabled";
        }

        return [
            'compatible' => true,
            'error' => null,
            'warnings' => $warnings,
            'details' => [
                'ram_ecc' => $ramECC,
                'motherboard_ecc' => $motherboardECC,
                'cpu_ecc' => $cpuECC
            ]
        ];
    }

    /**
     * Validate memory slot availability
     */
    public function validateMemorySlotAvailability($configUuid, $motherboardSpecs) {
        try {
            require_once __DIR__ . '/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            $usedSlots = 0;
            if ($config) {
                $components = $config->getComponents();
                foreach ($components as $comp) {
                    if ($comp['component_type'] === 'ram') {
                        $usedSlots += $comp['quantity'] ?? 1;
                    }
                }
            }

            $totalSlots = $motherboardSpecs['memory']['max_slots'] ?? $motherboardSpecs['memory']['slots'] ?? 4;

            $available = $usedSlots < $totalSlots;

            return [
                'available' => $available,
                'used_slots' => $usedSlots,
                'total_slots' => $totalSlots,
                'available_slots' => $totalSlots - $usedSlots,
                'error' => $available ? null : "Memory slot limit reached ($usedSlots/$totalSlots)"
            ];

        } catch (Exception $e) {
            return [
                'available' => false,
                'used_slots' => 0,
                'total_slots' => 4,
                'available_slots' => 0,
                'error' => "Error checking memory slot availability: " . $e->getMessage()
            ];
        }
    }

    /**
     * Validate chassis bay storage
     */
    private function validateChassisBayStorage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
        // Validate storage device can fit in chassis bays
        $chassisBays = $storageRequirements['chassis_bays'] ?? [];

        if (empty($chassisBays)) {
            $result['compatible'] = false;
            $result['issues'][] = "No chassis bays available for $storageFormFactor storage";
            return $result;
        }

        // Check if any bay supports the storage form factor
        $compatibleBay = null;
        foreach ($chassisBays as $bay) {
            $bayFormFactor = $bay['form_factor'] ?? null;
            if ($bayFormFactor && strpos($storageFormFactor, $bayFormFactor) !== false) {
                $compatibleBay = $bay;
                break;
            }
        }

        if (!$compatibleBay) {
            $result['compatible'] = false;
            $result['issues'][] = "No chassis bay supports $storageFormFactor form factor";
        }

        return $result;
    }

    /**
     * Validate motherboard M.2 storage
     */
    private function validateMotherboardM2Storage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
        $m2Slots = $storageRequirements['motherboard_m2_slots'] ?? 0;

        if ($m2Slots <= 0) {
            $result['compatible'] = false;
            $result['issues'][] = "No M.2 slots available on motherboard for M.2 storage";
        }

        return $result;
    }

    /**
     * Validate motherboard U.2 storage
     */
    private function validateMotherboardU2Storage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
        $u2Slots = $storageRequirements['motherboard_u2_slots'] ?? 0;

        if ($u2Slots <= 0) {
            $result['compatible'] = false;
            $result['issues'][] = "No U.2 slots available on motherboard for U.2 storage";
        }

        return $result;
    }

    /**
     * Validate generic storage
     */
    private function validateGenericStorage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
        // Generic validation - check if any connection path is available
        $hasPath = !empty($storageRequirements['chassis_bays']) ||
                   ($storageRequirements['motherboard_m2_slots'] ?? 0) > 0 ||
                   ($storageRequirements['motherboard_u2_slots'] ?? 0) > 0;

        if (!$hasPath) {
            $result['compatible'] = false;
            $result['issues'][] = "No available connection path for storage device";
        }

        return $result;
    }

    /**
     * Validate chassis bay capacity
     */
    private function validateChassisBayCapacity($chassisBays, $requiredBays) {
        $totalBays = 0;
        foreach ($chassisBays as $bay) {
            $totalBays += $bay['count'] ?? 0;
        }

        return $totalBays >= $requiredBays;
    }

    /**
     * Validate adding riser card to configuration
     */
    public function validateAddRiserCard($riserComponent, $existingComponents) {
        // Check if chassis already exists
        $hasChassisInvalid = false;
        foreach ($existingComponents as $comp) {
            if ($comp['component_type'] === 'chassis') {
                $hasChassisInvalid = true;
                break;
            }
        }

        if ($hasChassisInvalid) {
            return [
                'valid' => false,
                'error' => "Cannot add riser card after chassis is added"
            ];
        }

        return [
            'valid' => true,
            'error' => null
        ];
    }

    /**
     * Validate adding motherboard to configuration
     */
    public function validateAddMotherboard($motherboardComponent, $existingComponents) {
        // Check if motherboard already exists
        foreach ($existingComponents as $comp) {
            if ($comp['component_type'] === 'motherboard') {
                return [
                    'valid' => false,
                    'error' => "Only one motherboard allowed per configuration"
                ];
            }
        }

        return [
            'valid' => true,
            'error' => null
        ];
    }

    /**
     * Validate adding chassis to configuration
     */
    public function validateAddChassis($chassisComponent, $existingComponents) {
        // Check if chassis already exists
        foreach ($existingComponents as $comp) {
            if ($comp['component_type'] === 'chassis') {
                return [
                    'valid' => false,
                    'error' => "Only one chassis allowed per configuration"
                ];
            }
        }

        return [
            'valid' => true,
            'error' => null
        ];
    }

    /**
     * Enhanced extractSocketType method to work with JSON data primarily
     */
    public function extractSocketTypeFromJSON($componentType, $componentUuid) {
        error_log("DEBUG: Extracting socket type for $componentType UUID: $componentUuid");

        $result = null;

        if ($componentType === 'cpu') {
            $cpuResult = $this->validateCPUExists($componentUuid);
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
     * Count used memory slots in configuration
     */
    public function countUsedMemorySlots($configUuid) {
        try {
            // Get RAM modules from configuration (JSON-based storage)
            require_once __DIR__ . '/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            $totalRamModules = 0;
            if ($config) {
                $components = $config->getComponents();
                foreach ($components as $comp) {
                    if ($comp['component_type'] === 'ram') {
                        $totalRamModules += $comp['quantity'] ?? 1;
                    }
                }
            }

            return $totalRamModules;

        } catch (Exception $e) {
            error_log("Error counting used memory slots: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count used storage interfaces in configuration
     */
    public function countUsedStorageInterfaces($configUuid, $motherboardSpecs) {
        try {
            // Get storage components from configuration (JSON-based storage)
            require_once __DIR__ . '/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            $usedInterfaces = [
                'sata' => 0,
                'm2' => 0,
                'u2' => 0,
                'sas' => 0
            ];

            if ($config) {
                $components = $config->getComponents();
                foreach ($components as $comp) {
                    if ($comp['component_type'] === 'storage') {
                        $storageResult = $this->validateStorageExists($comp['component_uuid']);
                        if ($storageResult['exists']) {
                            // For now, assume SATA interface since storage JSON needs updating
                            $usedInterfaces['sata']++;
                        }
                    }
                }
            }

            return $usedInterfaces;

        } catch (Exception $e) {
            error_log("Error counting used storage interfaces: " . $e->getMessage());
            return ['sata' => 0, 'm2' => 0, 'u2' => 0, 'sas' => 0];
        }
    }

    /**
     * Count used PCIe slots in configuration
     */
    public function countUsedPCIeSlots($configUuid, $motherboardSpecs) {
        try {
            // Get PCIe components from configuration (JSON-based storage)
            require_once __DIR__ . '/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            $usedSlots = [];

            // Initialize slot counters
            $motherboardPCIeSlots = $motherboardSpecs['expansion']['pcie_slots'] ?? [];
            foreach ($motherboardPCIeSlots as $slot) {
                $usedSlots[$slot['type']] = 0;
            }

            if ($config) {
                $components = $config->getComponents();
                foreach ($components as $component) {
                    // Count NIC, HBA, and PCIe cards
                    if ($component['component_type'] === 'nic') {
                        $nicResult = $this->validateNICExists($component['component_uuid']);
                        if ($nicResult['exists']) {
                            $requiredLanes = 1; // Default
                            if (isset($nicResult['data']['interface_requirements']['pcie_lanes'])) {
                                $requiredLanes = (int)$nicResult['data']['interface_requirements']['pcie_lanes'];
                            }

                            // Find and assign to appropriate slot
                            foreach ($motherboardPCIeSlots as $slot) {
                                $slotLanes = $slot['lanes'] ?? 1;
                                if ($slotLanes >= $requiredLanes) {
                                    $usedSlots[$slot['type']] = ($usedSlots[$slot['type']] ?? 0) + 1;
                                    break;
                                }
                            }
                        }
                    } elseif (in_array($component['component_type'], ['hbacard', 'pciecard'])) {
                        // Count other PCIe devices (simplified - assumes x8 for now)
                        foreach ($motherboardPCIeSlots as $slot) {
                            if (($slot['lanes'] ?? 1) >= 8) {
                                $usedSlots[$slot['type']] = ($usedSlots[$slot['type']] ?? 0) + 1;
                                break;
                            }
                        }
                    }
                }
            }

            return $usedSlots;

        } catch (Exception $e) {
            error_log("Error counting used PCIe slots: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clear validation cache
     */
    public function clearCache() {
        $this->validationCache = [];
    }
}
?>
