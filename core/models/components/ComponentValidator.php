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

require_once __DIR__ . '/../shared/DataNormalizationUtils.php';

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
     * Validate component configuration in bulk.
     *
     * DEPRECATED / SUPERSEDED: this method only ever returned a hardcoded
     * compatible=true / score=1.0 (it has no access to the real pairwise checks),
     * which made any displayed compatibility score meaningless. The orchestrator
     * now uses ComponentCompatibility::computeConfigurationCompatibility(), which runs
     * the genuine checkComponentPairCompatibility() for each pair. This stub is kept
     * only for backward binary compatibility and should not be relied upon. [C2]
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
     * Resolve a motherboard's maximum memory capacity in GB from its raw JSON
     * memory spec. Handles max_capacity_TB (float-aware: 1.5 TB -> 1536 GB, not the
     * old (int)1.5*1024 = 1024 GB) and max_capacity_GB (previously ignored, so such
     * boards silently defaulted to 128 GB). Falls back to 128 GB only when neither
     * key is declared. [Fixes TP-2B]
     *
     * @param array $memory Raw motherboard memory spec
     * @return int Maximum capacity in GB
     */
    private function resolveMaxCapacityGb($memory) {
        if (isset($memory['max_capacity_TB']) && is_numeric($memory['max_capacity_TB'])) {
            return (int)round(((float)$memory['max_capacity_TB']) * 1024);
        }
        if (isset($memory['max_capacity_GB']) && is_numeric($memory['max_capacity_GB'])) {
            return (int)$memory['max_capacity_GB'];
        }
        if (isset($memory['max_capacity_gb']) && is_numeric($memory['max_capacity_gb'])) {
            return (int)$memory['max_capacity_gb'];
        }
        return 128;
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
                    'max_capacity_gb' => $this->resolveMaxCapacityGb($data['memory'] ?? []),
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

        // Get CPU socket type using enhanced JSON extraction
        $cpuSocket = $this->extractSocketTypeFromJSON('cpu', $cpuUuid);
        $motherboardSocket = $motherboardSpecs['cpu']['socket_type'] ?? null;


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

        // Normalize socket types for comparison (handles FC prefix, spaces, case)
        $cpuSocketNormalized = DataNormalizationUtils::normalizeSocketType($cpuSocket);
        $motherboardSocketNormalized = DataNormalizationUtils::normalizeSocketType($motherboardSocket);

        $compatible = ($cpuSocketNormalized === $motherboardSocketNormalized);

        $errorMessage = null;
        if (!$compatible) {
            $errorMessage = "CPU socket $cpuSocket incompatible with motherboard socket $motherboardSocket";
        }


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
            require_once __DIR__ . '/../server/ServerConfiguration.php';
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

                // Normalize socket types for comparison (handles FC prefix, spaces, case)
                $existingSocketNormalized = DataNormalizationUtils::normalizeSocketType($existingSocket);
                $newCpuSocketNormalized = DataNormalizationUtils::normalizeSocketType($newCpuSocket);

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
            $totalSlots = $motherboardSpecs['memory']['slots'] ?? $motherboardSpecs['memory']['max_slots'] ?? 4;

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
     * Normalize a CPU spec argument that may arrive either as a single spec array
     * or as a list of spec arrays into a consistent list of specs.
     *
     * getCPUSpecsFromConfig() returns a LIST of CPU specs, but several memory
     * validators were written assuming a single spec and indexed it as
     * $cpuSpecs['compatibility'][...] — which is undefined for a list, so the CPU
     * branch silently never ran. Normalizing here lets the validators iterate every
     * installed CPU regardless of how the caller passes the data. [Fixes TP-2C]
     *
     * @param mixed $cpuSpecs Single CPU spec, list of CPU specs, or empty
     * @return array List of CPU spec arrays
     */
    private function normalizeCpuSpecList($cpuSpecs) {
        if (empty($cpuSpecs) || !is_array($cpuSpecs)) {
            return [];
        }
        // A numerically-indexed array (keys 0..n-1) is already a list of specs;
        // anything else is a single associative spec array.
        $isList = array_keys($cpuSpecs) === range(0, count($cpuSpecs) - 1);
        return $isList ? $cpuSpecs : [$cpuSpecs];
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
                'message' => "RAM memory type not specified",
                'supported_types' => [],
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
            $mbMsg = "Memory type $ramMemoryType incompatible with motherboard supporting " . implode(', ', $motherboardMemoryTypes);
            return [
                'compatible' => false,
                'error' => $mbMsg,
                'message' => $mbMsg,
                'supported_types' => $motherboardMemoryTypes,
                'details' => [
                    'ram_type' => $ramMemoryType,
                    'motherboard_types' => $motherboardMemoryTypes,
                    'cpu_types' => null
                ]
            ];
        }

        // Check CPU compatibility for EVERY installed CPU. cpuSpecs may be a single
        // spec or a list (TP-2C); normalizeCpuSpecList handles both. Every CPU's
        // memory controller must support the RAM type.
        $allCpuMemoryTypes = [];
        foreach ($this->normalizeCpuSpecList($cpuSpecs) as $cpu) {
            $cpuMemoryTypes = $cpu['compatibility']['memory_types'] ?? null;
            if (!is_array($cpuMemoryTypes) || empty($cpuMemoryTypes)) {
                continue; // this CPU does not declare memory types -> cannot constrain
            }
            $allCpuMemoryTypes = array_merge($allCpuMemoryTypes, $cpuMemoryTypes);

            $cpuCompatible = false;
            foreach ($cpuMemoryTypes as $cpuType) {
                if (DataNormalizationUtils::normalizeMemoryType($cpuType) === $ramMemoryType) {
                    $cpuCompatible = true;
                    break;
                }
            }

            if (!$cpuCompatible) {
                $cpuMsg = "Memory type $ramMemoryType incompatible with CPU supporting " . implode(', ', $cpuMemoryTypes);
                return [
                    'compatible' => false,
                    'error' => $cpuMsg,
                    'message' => $cpuMsg,
                    'supported_types' => array_values(array_unique($cpuMemoryTypes)),
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
            'message' => null,
            'supported_types' => $motherboardMemoryTypes,
            'details' => [
                'ram_type' => $ramMemoryType,
                'motherboard_types' => $motherboardMemoryTypes,
                'cpu_types' => array_values(array_unique($allCpuMemoryTypes)) ?: null
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
                'message' => "RAM form factor not specified",
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
        $ffMessage = $compatible ? null : "RAM form factor $normalizedRamFormFactor incompatible with motherboard form factor $motherboardFormFactor";

        return [
            'compatible' => $compatible,
            'error' => $ffMessage,
            'message' => $ffMessage,
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
        // RAM JSON nests ECC under features.ecc_support; a few entries also carry a
        // top-level ecc_support. Read the nested key first, then top level, so ECC
        // RAM is no longer mis-read as non-ECC. [Fixes TP-2E]
        $ramECC = $ramSpecs['features']['ecc_support'] ?? $ramSpecs['ecc_support'] ?? false;
        $motherboardECC = $motherboardSpecs['memory']['ecc_support'] ?? false;

        // Determine CPU ECC support across ALL installed CPUs. cpuSpecs may be a
        // single spec or a list (TP-2C) — previously it was indexed as a single
        // spec, so for a list $cpuECC silently defaulted to true and CPU ECC
        // mismatches were never caught. A CPU that does not declare the field is
        // assumed ECC-capable so we don't raise false warnings.
        $cpuECC = true;
        foreach ($this->normalizeCpuSpecList($cpuSpecs) as $cpu) {
            if (isset($cpu['compatibility']) && array_key_exists('ecc_support', $cpu['compatibility'])) {
                if (!$cpu['compatibility']['ecc_support']) {
                    $cpuECC = false;
                    break;
                }
            }
        }

        // If RAM has ECC, both motherboard and CPU must support it. The RAM-addition
        // flow treats ECC mismatches as warnings (not hard blocks), so we expose a
        // 'warning' key (which that caller reads) alongside 'error'/'compatible'.
        if ($ramECC) {
            if (!$motherboardECC) {
                return [
                    'compatible' => false,
                    'error' => "ECC RAM requires ECC-compatible motherboard",
                    'warning' => "ECC RAM selected but motherboard does not support ECC",
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
                    'warning' => "ECC RAM selected but an installed CPU does not support ECC",
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
        $warning = null;
        if (!$ramECC && $motherboardECC && $cpuECC) {
            $warning = "System supports ECC but non-ECC RAM selected - ECC functionality will be disabled";
            $warnings[] = $warning;
        }

        return [
            'compatible' => true,
            'error' => null,
            'warning' => $warning,
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
            require_once __DIR__ . '/../server/ServerConfiguration.php';
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
    public function validateChassisBayStorage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
        // Validate storage device can fit in chassis bays
        $chassisBays = $storageRequirements['chassis_bays'] ?? [];

        if (empty($chassisBays)) {
            $result['compatible'] = false;
            $result['issues'][] = "No chassis bays available for $storageFormFactor storage";
            return $result;
        }

        // chassis_bays is a summary object with 'bay_types' array, not an array of bay objects
        $bayTypes = $chassisBays['bay_types'] ?? [];

        if (empty($bayTypes)) {
            $result['compatible'] = false;
            $result['issues'][] = "No chassis bay supports $storageFormFactor form factor";
            return $result;
        }

        // Normalize storage form factor for comparison
        // Extract physical dimension from compound form factors (e.g., "2.5-inch U.2" → "2.5-inch")
        $normalizedStorageFF = strtolower(str_replace('_', '-', $storageFormFactor));
        if (strpos($normalizedStorageFF, '2.5') !== false) {
            $normalizedStorageFF = '2.5-inch';
        } elseif (strpos($normalizedStorageFF, '3.5') !== false) {
            $normalizedStorageFF = '3.5-inch';
        }

        $bayTypeMatch = false;
        foreach ($bayTypes as $bayType) {
            $normalizedBayType = strtolower(str_replace('_', '-', $bayType));

            // Direct match
            if ($normalizedStorageFF === $normalizedBayType) {
                $bayTypeMatch = true;
                break;
            }

            // Partial match (e.g., "2.5" in "2.5-inch")
            if (strpos($normalizedStorageFF, $normalizedBayType) !== false ||
                strpos($normalizedBayType, $normalizedStorageFF) !== false) {
                $bayTypeMatch = true;
                break;
            }

            // 2.5" storage in 3.5" bay (with caddy)
            if (strpos($normalizedStorageFF, '2.5') !== false && strpos($normalizedBayType, '3.5') !== false) {
                $bayTypeMatch = true;
                $result['warnings'][] = "2.5-inch storage in 3.5-inch bay requires a caddy adapter";
                break;
            }
        }

        if (!$bayTypeMatch) {
            $result['compatible'] = false;
            $result['issues'][] = "No chassis bay supports $storageFormFactor form factor";
        }

        return $result;
    }

    /**
     * Validate motherboard M.2 storage
     */
    public function validateMotherboardM2Storage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
        // M.2 slots may come from the motherboard AND/OR NVMe-adaptor PCIe cards.
        $m2Slots = ($storageRequirements['motherboard_m2_slots'] ?? 0)
                 + ($storageRequirements['adapter_m2_slots'] ?? 0);

        if ($m2Slots <= 0) {
            $result['compatible'] = false;
            $result['issues'][] = "No M.2 slots available (motherboard or NVMe adaptor) for M.2 storage";
        }

        return $result;
    }

    /**
     * Validate motherboard U.2 storage
     */
    public function validateMotherboardU2Storage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
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
    public function validateGenericStorage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
        // Generic validation - check if any connection path is available
        $hasPath = !empty($storageRequirements['chassis_bays']) ||
                   ($storageRequirements['motherboard_m2_slots'] ?? 0) > 0 ||
                   ($storageRequirements['adapter_m2_slots'] ?? 0) > 0 ||
                   ($storageRequirements['motherboard_u2_slots'] ?? 0) > 0;

        if (!$hasPath) {
            $result['compatible'] = false;
            $result['issues'][] = "No available connection path for storage device";
        }

        return $result;
    }

    /**
     * Validate chassis bay capacity
     * Checks if chassis has sufficient bays for required storage drives
     * Supports both simple count comparison and detailed bay type validation
     *
     * @param array $chassisBays Bay configuration from chassis specs
     * @param array|int $requiredBays Required bays by type or simple count
     * @return array Compatibility result with 'compatible', 'issues', 'recommendations'
     */
    public function validateChassisBayCapacity($chassisBays, $requiredBays) {
        // If requiredBays is an array with counts by type, validate by type
        if (is_array($requiredBays)) {
            $totalRequired = array_sum($requiredBays);
        } else {
            // Legacy support for simple integer count
            $totalRequired = (int)$requiredBays;
        }

        $totalBays = 0;
        foreach ($chassisBays as $bay) {
            $totalBays += $bay['count'] ?? 0;
        }

        // Check if total capacity is sufficient
        if ($totalBays < $totalRequired) {
            return [
                'compatible' => false,
                'issues' => [
                    "Insufficient chassis bays: {$totalRequired} drives required, but only {$totalBays} bays available"
                ],
                'recommendations' => [
                    "Choose chassis with more bays or reduce storage drive count",
                    "Current requirement: {$totalRequired} bays, chassis capacity: {$totalBays} bays"
                ]
            ];
        }

        // All checks passed
        return [
            'compatible' => true,
            'issues' => [],
            'recommendations' => []
        ];
    }

    /**
     * Validate adding riser card to configuration
     * Note: Actual riser slot compatibility is validated by UnifiedSlotTracker::assignRiserSlotBySize()
     * which checks motherboard riser slot availability
     */
    public function validateAddRiserCard($riserComponent, $existingComponents) {
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

        $result = null;

        if ($componentType === 'cpu') {
            $cpuResult = $this->validateCPUExists($componentUuid);
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
            require_once __DIR__ . '/../server/ServerConfiguration.php';
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
            require_once __DIR__ . '/../server/ServerConfiguration.php';
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
                    if ($comp['component_type'] !== 'storage') {
                        continue;
                    }
                    $storageResult = $this->validateStorageExists($comp['component_uuid']);
                    if (!$storageResult['exists']) {
                        continue;
                    }
                    // BUGFIX (TP-3A): classify each drive by its real interface +
                    // form factor instead of counting EVERY drive as SATA, and respect
                    // quantity. The old code over-subscribed SATA and mis-attributed
                    // SAS/NVMe drives entirely.
                    $specs = $storageResult['data'] ?? [];
                    $bucket = $this->classifyStorageInterfaceBucket(
                        $specs['interface'] ?? '',
                        $specs['form_factor'] ?? ''
                    );
                    if ($bucket !== null) {
                        $usedInterfaces[$bucket] += max(1, (int)($comp['quantity'] ?? 1));
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
     * Classify a storage drive into one of the motherboard interface buckets
     * (sata / sas / m2 / u2) from its interface + form-factor strings.
     * Returns null when it cannot be classified (e.g. a PCIe add-in-card SSD that
     * occupies an expansion slot rather than a chassis/board storage interface).
     * [Supports TP-3A]
     *
     * @param string $interface  e.g. "SATA III", "SAS", "NVMe PCIe 4.0"
     * @param string $formFactor e.g. "M.2 2280", "2.5-inch U.2", "3.5-inch"
     * @return string|null One of 'sata','sas','m2','u2' or null
     */
    private function classifyStorageInterfaceBucket($interface, $formFactor) {
        $iface = strtolower((string)$interface);
        $ff = strtolower((string)$formFactor);

        if (strpos($iface, 'sas') !== false) {
            return 'sas';
        }
        if (strpos($iface, 'sata') !== false) {
            return 'sata';
        }
        if (strpos($iface, 'nvme') !== false || strpos($iface, 'pcie') !== false) {
            if (strpos($ff, 'm.2') !== false || strpos($ff, 'm2') !== false) {
                return 'm2';
            }
            if (strpos($ff, 'u.2') !== false || strpos($ff, 'u.3') !== false ||
                strpos($ff, 'u2') !== false || strpos($ff, 'u3') !== false ||
                strpos($ff, '2.5') !== false) {
                return 'u2';
            }
            // PCIe add-in-card NVMe (no chassis bay / M.2 / U.2) is not a board
            // storage interface; it consumes an expansion slot instead.
            return null;
        }
        return null;
    }

    /**
     * Count used PCIe slots in configuration
     */
    public function countUsedPCIeSlots($configUuid, $motherboardSpecs) {
        try {
            // Get PCIe components from configuration (JSON-based storage)
            require_once __DIR__ . '/../server/ServerConfiguration.php';
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
                        // BUGFIX (TP-1F): derive the card's actual lane requirement from
                        // its spec instead of hardcoding x8 (which forced x4 cards onto
                        // x8+ slots and under-checked x16 cards). Parse the slot width
                        // from the interface; default to x8 only when unparseable. Also
                        // respect quantity (each unit needs its own slot).
                        $cardResult = $this->dataLoader->loadComponentFromJSON($component['component_type'], $component['component_uuid']);
                        $requiredLanes = 8;
                        if (!empty($cardResult['found']) && isset($cardResult['data'])) {
                            $cardData = $cardResult['data'];
                            $ifaceStr = $cardData['interface'] ?? $cardData['slot_type'] ?? $cardData['pcie_interface'] ?? '';
                            if (preg_match('/x(\d+)/i', (string)$ifaceStr, $laneMatch)) {
                                $requiredLanes = (int)$laneMatch[1];
                            }
                        }
                        $qty = max(1, (int)($component['quantity'] ?? 1));
                        for ($unit = 0; $unit < $qty; $unit++) {
                            foreach ($motherboardPCIeSlots as $slot) {
                                if (($slot['lanes'] ?? 1) >= $requiredLanes) {
                                    $usedSlots[$slot['type']] = ($usedSlots[$slot['type']] ?? 0) + 1;
                                    break;
                                }
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
