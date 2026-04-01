<?php

require_once __DIR__ . '/../components/ComponentSpecPaths.php';
require_once __DIR__ . '/../compatibility/UnifiedSlotTracker.php';
require_once __DIR__ . '/../chassis/ChassisManager.php';
require_once __DIR__ . '/../components/ComponentDataService.php';
require_once __DIR__ . '/ServerConfiguration.php';

class ServerBuilder {

    private $pdo;
    private $componentTables;
    private $dataUtils;
    private $configCache;
    private $activeLocks = [];  // P4.1: Track acquired locks for deterministic ordering

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->componentTables = [
            'chassis' => 'chassisinventory',
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory',
            'pciecard' => 'pciecardinventory',
            'hbacard' => 'hbacardinventory',
            'sfp' => 'sfpinventory'
        ];

        // Initialize DataExtractionUtilities for JSON spec lookups
        require_once __DIR__ . '/../shared/DataExtractionUtilities.php';
        $this->dataUtils = new DataExtractionUtilities($pdo);

        // Initialize configuration cache if available
        $cacheFile = __DIR__ . '/../../cache/ConfigurationCache.php';
        if (file_exists($cacheFile)) {
            require_once $cacheFile;
            $this->configCache = new ConfigurationCache(300); // 5 minute TTL
        } else {
            $this->configCache = null; // Cache not available
        }
    }
    
    /**
     * Get the inventory table name for a given component type
     */
    private function getComponentInventoryTable($componentType) {
        return $this->componentTables[$componentType] ?? null;
    }

    /**
     * Extract components from JSON columns in server_configurations table
     * Supports: cpu_configuration, ram_configuration, storage_configuration, caddy_configuration, nic_config, hbacard_uuid, pciecard_configurations
     *
     * @param array $configData Configuration data from server_configurations table
     * @param bool $minimalOutput If true, returns only component_type and component_uuid keys, filtering null UUIDs
     * @return array Array of components with type, uuid, and optionally quantity, added_at, etc.
     */
    public function extractComponentsFromJson($configData, $minimalOutput = false) {
        $components = [];

        // CPU configuration (JSON array) - P5.2: Use safe JSON decoder
        if (!empty($configData['cpu_configuration'])) {
            $cpuConfig = $this->safeJsonDecode($configData['cpu_configuration'], true, 'cpu_configuration');
            if (isset($cpuConfig['cpus']) && is_array($cpuConfig['cpus'])) {
                foreach ($cpuConfig['cpus'] as $cpu) {
                    $component = [
                        'component_type' => 'cpu',
                        'component_uuid' => $cpu['uuid'] ?? null,
                        'quantity' => $cpu['quantity'] ?? 1,
                        'added_at' => $cpu['added_at'] ?? date('Y-m-d H:i:s')
                    ];
                    // CRITICAL: Include serial_number to identify specific physical component
                    if (isset($cpu['serial_number'])) {
                        $component['serial_number'] = $cpu['serial_number'];
                    }
                    $components[] = $component;
                }
            }
        }

        // RAM configuration (JSON array) - P5.2: Use safe JSON decoder
        if (!empty($configData['ram_configuration'])) {
            $ramConfigs = $this->safeJsonDecode($configData['ram_configuration'], true, 'ram_configuration');
            if (is_array($ramConfigs)) {
                foreach ($ramConfigs as $ram) {
                    $components[] = [
                        'component_type' => 'ram',
                        'component_uuid' => $ram['uuid'] ?? null,
                        'quantity' => $ram['quantity'] ?? 1,
                        'added_at' => $ram['added_at'] ?? date('Y-m-d H:i:s')
                    ];
                }
            }
        }

        // Storage configuration (JSON array) - P5.2: Use safe JSON decoder
        if (!empty($configData['storage_configuration'])) {
            $storageConfigs = $this->safeJsonDecode($configData['storage_configuration'], true, 'storage_configuration');
            if (is_array($storageConfigs)) {
                foreach ($storageConfigs as $storage) {
                    $components[] = [
                        'component_type' => 'storage',
                        'component_uuid' => $storage['uuid'] ?? null,
                        'quantity' => $storage['quantity'] ?? 1,
                        'added_at' => $storage['added_at'] ?? date('Y-m-d H:i:s'),
                        'connection' => $storage['connection'] ?? null
                    ];
                }
            }
        }

        // Caddy configuration (JSON array) - P5.2: Use safe JSON decoder
        if (!empty($configData['caddy_configuration'])) {
            $caddyConfigs = $this->safeJsonDecode($configData['caddy_configuration'], true, 'caddy_configuration');
            if (is_array($caddyConfigs)) {
                foreach ($caddyConfigs as $caddy) {
                    $components[] = [
                        'component_type' => 'caddy',
                        'component_uuid' => $caddy['uuid'] ?? null,
                        'quantity' => $caddy['quantity'] ?? 1,
                        'added_at' => $caddy['added_at'] ?? date('Y-m-d H:i:s')
                    ];
                }
            }
        }

        // NIC configuration (JSON object with nics array) - P5.2: Use safe JSON decoder
        if (!empty($configData['nic_config'])) {
            $nicConfig = $this->safeJsonDecode($configData['nic_config'], true, 'nic_config');
            if (isset($nicConfig['nics']) && is_array($nicConfig['nics'])) {
                foreach ($nicConfig['nics'] as $nic) {
                    $components[] = [
                        'component_type' => 'nic',
                        'component_uuid' => $nic['uuid'] ?? null,
                        'quantity' => 1,
                        'added_at' => date('Y-m-d H:i:s')
                    ];
                }
            }
        }

        // HBA Card configuration (JSON array, with legacy scalar fallback)
        if (!empty($configData['hbacard_config'])) {
            $hbaConfigs = $this->safeJsonDecode($configData['hbacard_config'], true, 'hbacard_config');
            if (is_array($hbaConfigs)) {
                // Handle migration: single object (has 'uuid' at top level) vs array of objects
                if (isset($hbaConfigs['uuid'])) {
                    $hbaConfigs = [$hbaConfigs];
                }
                foreach ($hbaConfigs as $hba) {
                    $components[] = [
                        'component_type' => 'hbacard',
                        'component_uuid' => $hba['uuid'] ?? null,
                        'quantity' => 1,
                        'added_at' => $hba['added_at'] ?? date('Y-m-d H:i:s')
                    ];
                }
            }
        } elseif (!empty($configData['hbacard_uuid'])) {
            // Backward compatibility: fall back to legacy scalar column
            $components[] = [
                'component_type' => 'hbacard',
                'component_uuid' => $configData['hbacard_uuid'],
                'quantity' => 1,
                'added_at' => date('Y-m-d H:i:s')
            ];
        }

        // Motherboard (simple column)
        if (!empty($configData['motherboard_uuid'])) {
            $components[] = [
                'component_type' => 'motherboard',
                'component_uuid' => $configData['motherboard_uuid'],
                'quantity' => 1,
                'added_at' => date('Y-m-d H:i:s')
            ];
        }

        // Chassis (simple column)
        if (!empty($configData['chassis_uuid'])) {
            $components[] = [
                'component_type' => 'chassis',
                'component_uuid' => $configData['chassis_uuid'],
                'quantity' => 1,
                'added_at' => date('Y-m-d H:i:s')
            ];
        }

        // PCIe Card configuration (JSON array) - P5.2: Use safe JSON decoder
        if (!empty($configData['pciecard_configurations'])) {
            $pcieConfigs = $this->safeJsonDecode($configData['pciecard_configurations'], true, 'pciecard_configurations');
            if (is_array($pcieConfigs)) {
                foreach ($pcieConfigs as $pcie) {
                    $components[] = [
                        'component_type' => 'pciecard',
                        'component_uuid' => $pcie['uuid'] ?? null,
                        'quantity' => $pcie['quantity'] ?? 1,
                        'added_at' => $pcie['added_at'] ?? date('Y-m-d H:i:s')
                    ];
                }
            }
        }

        // SFP configuration (JSON object with sfps array) - P5.2: Use safe JSON decoder
        if (!empty($configData['sfp_configuration'])) {
            $sfpConfig = $this->safeJsonDecode($configData['sfp_configuration'], true, 'sfp_configuration');

            // Extract assigned SFPs (with parent NIC and port assignments)
            if (isset($sfpConfig['sfps']) && is_array($sfpConfig['sfps'])) {
                foreach ($sfpConfig['sfps'] as $sfp) {
                    $components[] = [
                        'component_type' => 'sfp',
                        'component_uuid' => $sfp['uuid'] ?? null,
                        'parent_nic_uuid' => $sfp['parent_nic_uuid'] ?? null,
                        'port_index' => $sfp['port_index'] ?? null,
                        'quantity' => 1,
                        'added_at' => $sfp['added_at'] ?? date('Y-m-d H:i:s')
                    ];
                }
            }

            // Extract unassigned SFPs (added before NIC, awaiting assignment)
            if (isset($sfpConfig['unassigned_sfps']) && is_array($sfpConfig['unassigned_sfps'])) {
                foreach ($sfpConfig['unassigned_sfps'] as $sfp) {
                    $components[] = [
                        'component_type' => 'sfp',
                        'component_uuid' => $sfp['uuid'] ?? null,
                        'parent_nic_uuid' => null,
                        'port_index' => null,
                        'quantity' => 1,
                        'added_at' => $sfp['added_at'] ?? date('Y-m-d H:i:s'),
                        'status' => 'unassigned'
                    ];
                }
            }
        }

        // Apply minimal output filter if requested (for backward compatibility with extractComponentsFromConfigData)
        if ($minimalOutput) {
            $components = array_map(function($c) {
                return [
                    'component_type' => $c['component_type'],
                    'component_uuid'  => $c['component_uuid']
                ];
            }, $components);
            // Filter out entries with null/empty UUIDs to match standalone function behavior
            $components = array_values(array_filter($components, function($c) {
                return !empty($c['component_uuid']);
            }));
        }

        return $components;
    }

    /**
     * Get a human-readable component name from JSON spec files.
     * Returns model/name from the spec, or null if not found.
     */
    private function getComponentNameFromSpec($componentType, $componentUuid) {
        try {
            // Handle onboard NICs - they don't exist in the NIC JSON spec file
            if ($componentType === 'nic' && strpos($componentUuid, 'onboard-') === 0) {
                return $this->getOnboardNICName($componentUuid);
            }

            $spec = $this->dataUtils->getComponentSpecifications($componentType, $componentUuid);
            if (!$spec || empty($spec['found'])) {
                return null;
            }
            $s = $spec['specifications'];
            // Try common name fields in priority order
            foreach (['model', 'name', 'model_name', 'product_name'] as $field) {
                if (!empty($s[$field])) return $s[$field];
            }
            // For RAM: build "Brand Type CapacityGB Module"
            if ($componentType === 'ram') {
                $parts = array_filter([$s['brand'] ?? null, $s['memory_type'] ?? null,
                    isset($s['capacity_GB']) ? $s['capacity_GB'] . 'GB' : null,
                    $s['module_type'] ?? null]);
                if ($parts) return implode(' ', $parts);
            }
            // For Storage: build "Brand Type CapacityGB"
            if ($componentType === 'storage') {
                $cap = null;
                if (isset($s['capacity_GB'])) {
                    $cap = $s['capacity_GB'] >= 1000
                        ? round($s['capacity_GB'] / 1000, 1) . 'TB'
                        : $s['capacity_GB'] . 'GB';
                }
                $parts = array_filter([$s['brand'] ?? null, $s['storage_type'] ?? null, $cap]);
                if ($parts) return implode(' ', $parts);
            }
            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get onboard NIC name from motherboard specs via nicinventory
     */
    private function getOnboardNICName($onboardNicUuid) {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT ParentComponentUUID, OnboardNICIndex FROM nicinventory WHERE UUID = ? AND SourceType = 'onboard'"
            );
            $stmt->execute([$onboardNicUuid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || empty($row['ParentComponentUUID'])) {
                return 'Onboard NIC';
            }

            $mbSpecs = $this->dataUtils->getMotherboardByUUID($row['ParentComponentUUID']);
            if (!$mbSpecs || !isset($mbSpecs['networking']['onboard_nics'])) {
                return 'Onboard NIC';
            }

            $index = ($row['OnboardNICIndex'] ?? 1) - 1;
            $onboardNICs = $mbSpecs['networking']['onboard_nics'];
            if (!isset($onboardNICs[$index])) {
                return 'Onboard NIC';
            }

            $nic = $onboardNICs[$index];
            return sprintf('%s %dp %s %s',
                $nic['controller'] ?? 'Onboard',
                $nic['ports'] ?? 0,
                $nic['speed'] ?? '',
                $nic['connector'] ?? ''
            );
        } catch (Exception $e) {
            return 'Onboard NIC';
        }
    }

    /**
     * Get component serial number and other details from inventory table
     */
    private function getComponentDetails($componentType, $componentUuid, $serverUuid = null, $excludeSerials = []) {
        try {
            $table = $this->getComponentInventoryTable($componentType);
            if (!$table) {
                return null;
            }

            $params = [$componentUuid];
            $sql = "SELECT SerialNumber, Status FROM `$table` WHERE UUID = ?";
            
            if (!empty($excludeSerials)) {
                $placeholders = str_repeat('?,', count($excludeSerials) - 1) . '?';
                $sql .= " AND SerialNumber NOT IN ($placeholders)";
                foreach ($excludeSerials as $serial) {
                    $params[] = $serial;
                }
            }
            
            if ($serverUuid !== null) {
                // Prioritize exact ServerUUID matches, but allow fallback if DB is inconsistent
                $sql .= " ORDER BY CASE WHEN ServerUUID = ? THEN 1 ELSE 0 END DESC";
                $params[] = $serverUuid;
            }
            
            $sql .= " LIMIT 1";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result;
        } catch (Exception $e) {
            error_log("Error getting component details: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new server configuration
     */
    public function createConfiguration($serverName, $createdBy, $options = []) {
        try {
            $configUuid = $this->generateUuid();
            $description = $options['description'] ?? '';
            $location = $options['location'] ?? '';
            $rackPosition = $options['rack_position'] ?? '';
            $isVirtual = $options['is_virtual'] ?? 0;

            $stmt = $this->pdo->prepare("
                INSERT INTO server_configurations
                (config_uuid, server_name, description, location, rack_position, created_by, created_at, updated_at, configuration_status, is_virtual)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 0, ?)
            ");

            $stmt->execute([$configUuid, $serverName, $description, $location, $rackPosition, $createdBy, $isVirtual]);

            return $configUuid;

        } catch (Exception $e) {
            error_log("Error creating server configuration: " . $e->getMessage());
            throw new Exception("Failed to create server configuration: " . $e->getMessage());
        }
    }
    
    /**
     * Add component to server configuration with proper database updates
     */
    public function addComponent($configUuid, $componentType, $componentUuid, $options = []) {
        // RACE CONDITION FIX: Initialize transaction control early
        $ownTransaction = false;

        try {
            // Phase 1: Validate component type
            if (!$this->isValidComponentType($componentType)) {
                return [
                    'success' => false,
                    'message' => "Invalid component type: $componentType"
                ];
            }

            // Phase 1.1: Extract serial_number early for duplicate detection
            // CRITICAL: Get serial_number from options to identify specific physical component
            $serialNumber = $options['serial_number'] ?? null;

            // RACE CONDITION FIX: Start transaction BEFORE any availability checks
            // This ensures all checks happen atomically with component locking
            $ownTransaction = !$this->pdo->inTransaction();
            if ($ownTransaction) {
                $this->pdo->beginTransaction();
            }

            // Phase 1.2: Auto-resolve serial_number if not provided
            // When multiple inventory items share the same UUID (same model), we need a serial
            // to identify which specific physical component is being added.
            // Without this, the duplicate check treats all same-UUID components as identical.
            if ($serialNumber === null) {
                $table = $this->getComponentInventoryTable($componentType);
                if ($table) {
                    $stmt = $this->pdo->prepare("
                        SELECT SerialNumber FROM `$table`
                        WHERE UUID = ? AND Status = 1
                        ORDER BY ID ASC
                        LIMIT 1
                    ");
                    $stmt->execute([$componentUuid]);
                    $autoResolved = $stmt->fetchColumn();
                    if ($autoResolved) {
                        $serialNumber = $autoResolved;
                    }
                }
            }

            // Phase 1.5: Validate compatibility with existing components (flexible order)
            $compatibilityValidation = $this->validateComponentCompatibility($configUuid, $componentType, $componentUuid);
            if (!$compatibilityValidation['success']) {
                if ($ownTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollback();
                }
                return $compatibilityValidation;
            }

            // Phase 2: Check for duplicate component with configuration row locked
            // CRITICAL: Pass serial_number to allow multiple components with same UUID but different serials
            // Skip duplicate check for virtual configs (allow same component UUID multiple times for testing)
            if (!$this->isVirtualConfig($configUuid) && $this->isDuplicateComponent($configUuid, $componentUuid, $serialNumber)) {
                if ($ownTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollback();
                }
                return [
                    'success' => false,
                    'message' => "Component $componentUuid is already added to this configuration"
                ];
            }
            
            // Phase 3: Validate component exists in JSON specifications
            $compatibility = null; // Initialize for later phases
            try {
                // Special handling for chassis - use ChassisManager directly
                if ($componentType === 'chassis') {
                    require_once __DIR__ . '/../chassis/ChassisManager.php';
                    $chassisManager = new ChassisManager();

                    $chassisResult = $chassisManager->loadChassisSpecsByUUID($componentUuid);
                    if (!$chassisResult['found']) {
                        if ($ownTransaction && $this->pdo->inTransaction()) {
                            $this->pdo->rollback();
                        }
                        return [
                            'success' => false,
                            'message' => "Chassis $componentUuid not found in JSON specifications: " . ($chassisResult['error'] ?? 'Unknown error')
                        ];
                    }

                    // Create compatibility object for other validation phases
                    require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
                    if (class_exists('ComponentCompatibility')) {
                        $compatibility = new ComponentCompatibility($this->pdo);
                    }
                } else {
                    // Use ComponentCompatibility for all other components (including storage)
                    require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
                    if (!class_exists('ComponentCompatibility')) {
                        error_log("ComponentCompatibility class not found after require_once");
                        if ($ownTransaction && $this->pdo->inTransaction()) {
                            $this->pdo->rollback();
                        }
                        return [
                            'success' => false,
                            'message' => "Component validation system not available"
                        ];
                    }
                    $compatibility = new ComponentCompatibility($this->pdo);

                    // Skip JSON validation for virtual configs (they don't need real inventory)
                    $isVirtual = $this->isVirtualConfig($configUuid);

                    // Skip JSON validation for storage, nic, caddy, chassis - database UUIDs don't match JSON UUIDs
                    // Only validate: cpu, motherboard, ram, pciecard, hbacard
                    // BUT: Skip validation entirely for virtual configs
                    $componentsToValidate = ['cpu', 'motherboard', 'ram', 'pciecard', 'hbacard'];
                    if (!$isVirtual && in_array($componentType, $componentsToValidate)) {
                        $existsResult = $compatibility->validateComponentExistsInJSON($componentType, $componentUuid);
                        if (!$existsResult) {
                            if ($ownTransaction && $this->pdo->inTransaction()) {
                                $this->pdo->rollback();
                            }
                            return [
                                'success' => false,
                                'message' => "Component $componentUuid not found in $componentType JSON specifications database"
                            ];
                        }
                    }
                }
            } catch (Exception $compatError) {
                error_log("Error in component validation for type '$componentType': " . $compatError->getMessage());
                // Return error instead of skipping
                if ($ownTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollback();
                }
                return [
                    'success' => false,
                    'message' => 'Component validation error: ' . $compatError->getMessage()
                ];
            }
            
            // Phase 4: RACE CONDITION FIX - Lock and get component details atomically
            // Note: $serialNumber was already extracted in Phase 1.1
            // For virtual configs, create dummy component details if not found in inventory

            // Check if this is a virtual config
            $isVirtual = $this->isVirtualConfig($configUuid);

            if (!$isVirtual) {
                // Real config - LOCK the component row to prevent race conditions
                $lockResult = $this->lockAndCheckComponent($componentType, $componentUuid, $serialNumber);

                if (!$lockResult['found']) {
                    if ($ownTransaction && $this->pdo->inTransaction()) {
                        $this->pdo->rollback();
                    }
                    return [
                        'success' => false,
                        'message' => $lockResult['error']
                    ];
                }

                // Component is now LOCKED - no other transaction can modify it
                $componentDetails = $lockResult['data'];
            } else {
                // Virtual config - create dummy component details (no locking needed)
                $componentDetails = [
                    'UUID' => $componentUuid,
                    'SerialNumber' => $serialNumber ?? 'VIRTUAL-' . substr($componentUuid, 0, 8),
                    'Status' => 1, // Virtual component (always "available")
                    'ServerUUID' => null,
                    'Location' => null,
                    'Notes' => 'Virtual component for testing'
                ];
            }

            // Extract the actual serial number from component details (in case it wasn't provided in options)
            // CRITICAL: Keep $serialNumber as original user input (null if not provided) for JSON config logic.
            // $resolvedSerialNumber is used only for inventory DB row targeting.
            $resolvedSerialNumber = $componentDetails['SerialNumber'] ?? $serialNumber;
            
            // Phase 5: Chassis-specific validations BEFORE adding
            // Validation already done in Phase 3 for chassis, skip here

            // Phase 5.1: CPU-specific validations BEFORE adding
            if ($componentType === 'cpu' && isset($compatibility)) {
                try {
                    $cpuValidation = $this->validateCPUAddition($configUuid, $componentUuid, $compatibility);
                    if (!$cpuValidation['success']) {
                        if ($ownTransaction && $this->pdo->inTransaction()) {
                            $this->pdo->rollback();
                        }
                        return $cpuValidation;
                    }
                } catch (Exception $cpuError) {
                    error_log("Error in CPU validation: " . $cpuError->getMessage());
                }
            }
            
            // Phase 5.5: RAM-specific validations BEFORE adding
            $ramValidationResults = null;
            if ($componentType === 'ram' && isset($compatibility)) {
                try {
                    $ramValidation = $this->validateRAMAddition($configUuid, $componentUuid, $compatibility);
                    if (!$ramValidation['success']) {
                        if ($ownTransaction && $this->pdo->inTransaction()) {
                            $this->pdo->rollback();
                        }
                        return $ramValidation;
                    }
                    // Store validation results for inclusion in response
                    $ramValidationResults = $ramValidation;
                } catch (Exception $ramError) {
                    error_log("Error in RAM validation: " . $ramError->getMessage());
                    // Continue without RAM validation
                }
            }

            // Phase 6: Comprehensive component validation (Phase 2 consolidation)
            if (isset($compatibility)) {
                try {
                    $parentNicUuid = $options['parent_nic_uuid'] ?? null;
                    $portIndex = $options['port_index'] ?? null;
                    $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
                    $stmt->execute([$configUuid]);
                    $configDataForValidation = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $compatibilityValidation = $this->validateComponentAddition($configUuid, $componentType, $componentUuid, $compatibility, $configDataForValidation, $parentNicUuid, $portIndex);
                    if (!$compatibilityValidation['success']) {
                        if ($ownTransaction && $this->pdo->inTransaction()) {
                            $this->pdo->rollback();
                        }
                        return $compatibilityValidation;
                    }
                } catch (Exception $compatError) {
                    error_log("Error in compatibility validation: " . $compatError->getMessage());
                    error_log("Stack trace: " . $compatError->getTraceAsString());
                    // Continue without compatibility validation
                }
            }

            // Phase 7: Check availability WITH LOCKED DATA (race condition prevented)
            $availability = $this->checkComponentAvailability($componentDetails, $configUuid, $options);
            if (!$availability['available'] && !($options['override_used'] ?? false)) {
                if ($ownTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollback();
                }
                return [
                    'success' => false,
                    'message' => $availability['message'],
                    'availability_details' => $availability
                ];
            }

            // For single-instance components, check if already exists in config
            if ($this->isSingleInstanceComponent($componentType)) {
                $existingComponent = $this->getConfigurationComponent($configUuid, $componentType);
                if ($existingComponent) {
                    if ($ownTransaction && $this->pdo->inTransaction()) {
                        $this->pdo->rollback();
                    }
                    return [
                        'success' => false,
                        'message' => "Configuration already has a $componentType. Remove existing component first."
                    ];
                }
            }
            
            // Get server configuration location and rack position for component assignment
            $stmt = $this->pdo->prepare("SELECT location, rack_position FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $serverConfig = $stmt->fetch(PDO::FETCH_ASSOC);
            $serverLocation = $serverConfig['location'] ?? null;
            $serverRackPosition = $serverConfig['rack_position'] ?? null;
            $serverRackPosition = $serverConfig['rack_position'] ?? null;


            // RACE CONDITION FIX: Transaction already started at beginning of method
            // Component is already locked with SELECT FOR UPDATE
            // All validations complete - now proceed with updates

            // Extract quantity and slot position from options (component data now stored in JSON columns)
            $quantity = $options['quantity'] ?? 1;

            // P5.1: Validate quantity for slot-based components (RAM, Storage, PCIe cards, etc.)
            $quantityValidation = $this->validateComponentQuantity($componentType, $componentUuid, $quantity, $configUuid);
            if (!$quantityValidation['valid']) {
                if ($ownTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollback();
                }
                return [
                    'success' => false,
                    'message' => $quantityValidation['message'],
                    'details' => $quantityValidation['details'] ?? null
                ];
            }
            $slotPosition = $options['slot_position'] ?? null;

            // Phase 10.5: Expansion slot assignment for PCIe cards, NICs, HBA cards
            // Moved from handleAddComponent() lines 503-600
            if (in_array($componentType, ['pciecard', 'nic', 'hbacard'])) {
                try {
                    // Load component specs for slot assignment
                    $componentDataService = ComponentDataService::getInstance();
                    $componentSpecs = $componentDataService->getComponentSpecifications(
                        $componentType,
                        $componentUuid,
                        $componentDetails
                    );

                    // Attempt to assign slot (may return null if no suitable slots)
                    $slotAssignmentResult = $this->assignComponentSlot(
                        $configUuid,
                        $componentType,
                        $componentUuid,
                        $componentSpecs ?? [],
                        $slotPosition ?? null  // Pass manual override if provided
                    );

                    if (!$slotAssignmentResult['success']) {
                        // Riser card error is blocking - cannot proceed
                        if (isset($slotAssignmentResult['error_code']) &&
                            $slotAssignmentResult['error_code'] === 'no_riser_slots_available') {
                            if ($ownTransaction && $this->pdo->inTransaction()) {
                                $this->pdo->rollback();
                            }
                            return [
                                'success' => false,
                                'message' => $slotAssignmentResult['message'],
                                'details' => [
                                    'component_type' => $componentType,
                                    'component_uuid' => $componentUuid,
                                    'required_slot_type' => $slotAssignmentResult['required_slot_type'] ?? null
                                ]
                            ];
                        }
                    }

                    // Update $slotPosition with assigned slot ID if successful
                    if ($slotAssignmentResult['success'] && $slotAssignmentResult['slot_id']) {
                        $slotPosition = $slotAssignmentResult['slot_id'];
                    }
                } catch (Exception $slotError) {
                    error_log("Slot assignment exception: " . $slotError->getMessage());
                    // Continue without slot assignment - not fatal for non-riser cards
                }
            }

            // Note: Component data is now stored in JSON columns in server_configurations table
            // No separate server_configuration_components table needed

            // For storage components, compute connection path before persisting
            if ($componentType === 'storage') {
                $storageConnectionData = $this->computeStorageConnectionPath($configUuid, $componentUuid);
                $options['connection_data'] = $storageConnectionData;
            }

            // P4.3 FIX: Update JSON BEFORE status (safer transaction order)
            // This ensures JSON is persisted even if status update fails

            // Update the main server_configurations table with component info (FIRST)
            // CRITICAL: Pass serial_number to store in configuration JSON
            $this->updateServerConfigurationTable($configUuid, $componentType, $componentUuid, $quantity, 'add', $serialNumber, $options);

            // Update component status to "In Use" ONLY for real builds (not virtual/test builds) (SECOND)
            // Virtual configs don't lock components - they're for testing only
            if (!$this->isVirtualConfig($configUuid)) {
                // Update component status to "In Use" AND set ServerUUID, location, rack position, and installation date
                // CRITICAL: Pass serial number to update only the specific physical component
                $this->updateComponentStatusAndServerUuid($componentType, $componentUuid, 2, $configUuid, "Added to configuration $configUuid", $serverLocation, $serverRackPosition, $resolvedSerialNumber);
            }
            
            // Update calculated fields (power, compatibility, etc.)
            $this->updateConfigurationMetrics($configUuid);
            
            // Log the action
            $this->logConfigurationAction($configUuid, 'add_component', $componentType, $componentUuid, $options);

            // Only commit if we started the transaction
            if ($ownTransaction) {
                $this->pdo->commit();
            }

            // Invalidate configuration cache if available
            if ($this->configCache !== null) {
                $this->configCache->invalidateConfiguration($configUuid);
            }

            // Build response with optional RAM validation details
            $response = [
                'success' => true,
                'message' => "Component added successfully",
                'component_details' => $componentDetails
            ];

            // Include RAM validation results if available
            if ($ramValidationResults !== null && $componentType === 'ram') {
                $response['warnings'] = $ramValidationResults['warnings'] ?? [];
                $response['compatibility_details'] = $ramValidationResults['compatibility_details'] ?? [];
            }

            // Phase 3: Post-add side effects consolidation

            // SIDE EFFECT 1: AUTO-ADD ONBOARD NICs when motherboard is added
            if ($componentType === 'motherboard') {
                try {
                    require_once __DIR__ . '/../compatibility/OnboardNICHandler.php';
                    $nicHandler = new OnboardNICHandler($this->pdo);
                    $onboardNICResult = $nicHandler->autoAddOnboardNICs($configUuid, $componentUuid);

                    if ($onboardNICResult['count'] > 0) {
                        $response['onboard_nics_added'] = $onboardNICResult;
                    }
                } catch (Exception $nicError) {
                    error_log("Error auto-adding onboard NICs: " . $nicError->getMessage());
                    // Don't fail the motherboard addition if onboard NIC addition fails
                    $response['onboard_nic_warning'] = "Motherboard added but onboard NICs could not be auto-added: " . $nicError->getMessage();
                }
            }

            // SIDE EFFECT 2: UPDATE NIC CONFIG when NIC is added
            if ($componentType === 'nic') {
                try {
                    require_once __DIR__ . '/../compatibility/OnboardNICHandler.php';
                    $nicHandler = new OnboardNICHandler($this->pdo);
                    $nicHandler->updateNICConfigJSON($configUuid);
                } catch (Exception $nicError) {
                    error_log("Error updating NIC config: " . $nicError->getMessage());
                    // Don't fail the NIC addition if config update fails
                }
            }

            return $response;
            
        } catch (Exception $e) {
            // Only rollback if we started the transaction
            if (isset($ownTransaction) && $ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollback();
            }
            error_log("Error adding component to configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to add component: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Remove component from server configuration
     * UPDATED: Now reads from JSON columns and updates JSON instead of relational table
     */
    public function removeComponent($configUuid, $componentType, $componentUuid, $serialNumber = null) {
        try {
            $this->pdo->beginTransaction();

            // Get configuration with all data
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                $this->pdo->rollback();
                return [
                    'success' => false,
                    'message' => "Configuration not found"
                ];
            }



            // Check if component exists in configuration by extracting from JSON
            $components = $this->extractComponentsFromJson($config);
            $componentFound = false;
            $componentSerialNumber = null;

            foreach ($components as $comp) {
                // Match by serial_number if provided, otherwise fallback to UUID only
                $isMatch = false;
                if ($serialNumber !== null && isset($comp['serial_number'])) {
                    $isMatch = ($comp['component_type'] === $componentType &&
                                $comp['component_uuid'] === $componentUuid &&
                                $comp['serial_number'] === $serialNumber);
                } else {
                    $isMatch = ($comp['component_type'] === $componentType &&
                                $comp['component_uuid'] === $componentUuid);
                }

                if ($isMatch) {
                    $componentFound = true;
                    // Extract serial number from config if not provided
                    $componentSerialNumber = $comp['serial_number'] ?? $serialNumber;
                    break;
                }
            }

            if (!$componentFound) {
                $this->pdo->rollback();
                $serialInfo = $serialNumber ? " with SerialNumber '$serialNumber'" : "";
                return [
                    'success' => false,
                    'message' => "Component not found in configuration$serialInfo"
                ];
            }

            // Phase 3: NIC removal validation - Check if any SFPs are installed on ports
            if ($componentType === 'nic') {
                $sfpConfigJson = $config['sfp_configuration'] ?? null;
                if ($sfpConfigJson) {
                    $sfpConfig = json_decode($sfpConfigJson, true);
                    $occupiedPorts = [];

                    if (isset($sfpConfig['sfps']) && is_array($sfpConfig['sfps'])) {
                        foreach ($sfpConfig['sfps'] as $sfp) {
                            if (isset($sfp['parent_nic_uuid']) && $sfp['parent_nic_uuid'] === $componentUuid) {
                                $occupiedPorts[] = [
                                    'port_index' => $sfp['port_index'],
                                    'sfp_uuid' => $sfp['uuid']
                                ];
                            }
                        }
                    }

                    if (!empty($occupiedPorts)) {
                        $this->pdo->rollback();
                        return [
                            'success' => false,
                            'message' => "Cannot remove NIC - " . count($occupiedPorts) . " SFP module(s) installed on ports",
                            'nic_uuid' => $componentUuid,
                            'occupied_ports' => $occupiedPorts,
                            'hint' => 'Remove all SFP modules from this NIC before removing the NIC itself'
                        ];
                    }
                }
            }

            // SPECIAL HANDLING: If removing a motherboard, also remove its onboard NICs
            if ($componentType === 'motherboard') {
                // Remove onboard NICs via OnboardNICHandler
                require_once __DIR__ . '/../compatibility/OnboardNICHandler.php';
                $nicHandler = new OnboardNICHandler($this->pdo);
                $removeResult = $nicHandler->removeOnboardNICs($componentUuid, $configUuid);

                if (!$removeResult['success']) {
                    error_log("Warning: Failed to remove onboard NICs: " . ($removeResult['error'] ?? 'Unknown error'));
                }
            }

            // P4.3 FIX: Update JSON BEFORE status (safer transaction order)
            // This ensures JSON is persisted even if status update fails

            // Update the main server_configurations table (FIRST)
            // CRITICAL: Pass serial number to remove correct component from JSON
            $this->updateServerConfigurationTable($configUuid, $componentType, $componentUuid, 0, 'remove', $componentSerialNumber);

            // Update component status back to "Available" and clear ServerUUID, installation date, and rack position (SECOND)
            // CRITICAL: Pass serial number to update only the specific physical component
            $this->updateComponentStatusAndServerUuid($componentType, $componentUuid, 1, null, "Removed from configuration $configUuid", null, null, $componentSerialNumber);

            // P3.4 FIX: Recalculate form factor lock on chassis/storage removal
            if ($componentType === 'chassis' || $componentType === 'storage') {
                $this->recalculateFormFactorLock($configUuid);
            }

            // Update calculated fields
            $this->updateConfigurationMetrics($configUuid);

            // Log the action
            $this->logConfigurationAction($configUuid, 'remove_component', $componentType, $componentUuid);

            $this->pdo->commit();

            // Invalidate configuration cache if available
            if ($this->configCache !== null) {
                $this->configCache->invalidateConfiguration($configUuid);
            }

            $response = [
                'success' => true,
                'message' => "Component removed successfully"
            ];

            // Phase 3: POST-REMOVAL SIDE EFFECTS

            // SIDE EFFECT 3: UPDATE NIC CONFIG when NIC is removed
            if ($componentType === 'nic') {
                try {
                    require_once __DIR__ . '/../compatibility/OnboardNICHandler.php';
                    $nicHandler = new OnboardNICHandler($this->pdo);
                    $nicHandler->updateNICConfigJSON($configUuid);
                } catch (Exception $nicError) {
                    error_log("Error updating NIC config after removal: " . $nicError->getMessage());
                    // Don't fail the NIC removal if config update fails
                }
            }

            return $response;

        } catch (\Throwable $e) {
            $this->pdo->rollback();
            error_log("Error removing component from configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to remove component: " . $e->getMessage()
            ];
        }
    }

    /**
     * Phase 4: Get compatible components for a given component type
     * Consolidated from handleGetCompatible() in server_api.php
     * Enables all code paths (HTTP, batch, CLI) to query compatible components
     */
    public function getCompatibleComponents($configUuid, $componentType, $options = []) {
        try {
            // Extract options
            $availableOnly = $options['available_only'] ?? true;
            $includeDebug = $options['include_debug'] ?? false;

            // Step 1: Validate configuration exists
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);
            if (!$config) {
                return [
                    'success' => false,
                    'message' => 'Server configuration not found'
                ];
            }

            // For virtual configs, show ALL components regardless of availability
            if ($config->get('is_virtual')) {
                $availableOnly = false;
            }

            // Step 2: Get existing components in configuration
            $existingComponents = $this->extractComponentsFromJson($config->getData(), true);

            // Process existing components for compatibility checking
            $existingComponentsData = [];
            foreach ($existingComponents as $existing) {
                $table = $this->getComponentInventoryTable($existing['component_type']);
                if ($table) {
                    $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ? LIMIT 1");
                    $stmt->execute([$existing['component_uuid']]);
                    $componentData = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($componentData) {
                        $existingComponentsData[] = [
                            'type' => $existing['component_type'],
                            'uuid' => $existing['component_uuid'],
                            'data' => $componentData
                        ];
                    }
                }
            }

            // Step 3: Get all components of requested type with availability filtering
            $table = $this->getComponentInventoryTable($componentType);
            if (!$table) {
                return [
                    'success' => false,
                    'message' => 'Invalid component type'
                ];
            }

            // Build WHERE clause based on available_only parameter
            if ($availableOnly) {
                $whereClause = "WHERE Status = 1"; // Only available components
            } else {
                $whereClause = "WHERE Status IN (0, 1, 2)"; // All statuses
            }

            // Get components (limit to 200 for performance)
            $stmt = $this->pdo->prepare("
                SELECT UUID, SerialNumber, Status, Location, Notes, ServerUUID
                FROM $table
                $whereClause
                ORDER BY Status ASC, SerialNumber ASC
                LIMIT 200
            ");
            $stmt->execute();
            $allComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Debug info
            $debugInfo = [
                'query_table' => $table,
                'where_clause' => $whereClause,
                'total_found_in_db' => count($allComponents),
                'available_only' => $availableOnly
            ];

            // Step 4: Run compatibility checks
            $compatibleComponents = [];

            require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
            require_once __DIR__ . '/../components/ComponentDataService.php';
            require_once __DIR__ . '/../compatibility/NICPortTracker.php';

            if (class_exists('ComponentCompatibility')) {
                $compatibility = new ComponentCompatibility($this->pdo);
                $componentDataService = ComponentDataService::getInstance();

                // Pre-filter: Only include components that exist in JSON
                $componentsWithJSON = [];
                $componentsWithoutJSON = [];
                $jsonValidationDetails = [];

                foreach ($allComponents as $component) {
                    $hasJSON = $compatibility->validateComponentExistsInJSON($componentType, $component['UUID']);
                    $validationDetail = [
                        'uuid' => $component['UUID'],
                        'serial_number' => $component['SerialNumber'],
                        'has_json' => $hasJSON,
                        'status' => $component['Status']
                    ];

                    if ($hasJSON) {
                        $componentsWithJSON[] = $component;
                        $validationDetail['result'] = 'included';
                    } else {
                        $componentsWithoutJSON[] = $component['UUID'];
                        $validationDetail['result'] = 'excluded - no JSON spec found';
                    }

                    $jsonValidationDetails[] = $validationDetail;
                }

                // Replace allComponents with filtered list
                $totalBeforeFiltering = count($allComponents);
                $allComponents = $componentsWithJSON;

                // Add to debug info
                $debugInfo['total_before_json_filter'] = $totalBeforeFiltering;
                $debugInfo['total_with_json'] = count($allComponents);
                $debugInfo['components_without_json'] = $componentsWithoutJSON;
                $debugInfo['json_validation_details'] = $jsonValidationDetails;

                // Add detailed component listing to debug
                $debugInfo['components_to_check'] = array_map(function($c) {
                    return [
                        'uuid' => $c['UUID'],
                        'serial' => $c['SerialNumber'],
                        'status' => $c['Status']
                    ];
                }, $allComponents);

                // Run compatibility checks for each component
                foreach ($allComponents as $component) {
                    $isCompatible = true;
                    $compatibilityReasons = [];
                    $fullChassisResult = null;

                    // If no existing components, all components are compatible
                    if (empty($existingComponentsData)) {
                        $isCompatible = true;
                        $compatibilityReasons[] = "No existing components - all components available";
                    } else {
                        // Component-type-specific compatibility checking
                        if ($componentType === 'ram') {
                            $ramCompatResult = $compatibility->checkRAMDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $ramCompatResult['compatible'];
                            $compatibilityReasons = array_merge(
                                $ramCompatResult['details'] ?? [],
                                $ramCompatResult['warnings'] ?? [],
                                $ramCompatResult['recommendations'] ?? []
                            );
                        } elseif ($componentType === 'cpu') {
                            $cpuCompatResult = $compatibility->checkCPUDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $cpuCompatResult['compatible'];
                            $compatibilityReasons = [$cpuCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                        } elseif ($componentType === 'motherboard') {
                            $motherboardCompatResult = $compatibility->checkMotherboardDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $motherboardCompatResult['compatible'];
                            $compatibilityReasons = [$motherboardCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                        } elseif ($componentType === 'storage') {
                            $storageCompatResult = $compatibility->checkStorageDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $storageCompatResult['compatible'];
                            $compatibilityReasons = [$storageCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                        } elseif ($componentType === 'chassis') {
                            $chassisCompatResult = $compatibility->checkChassisDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $chassisCompatResult['compatible'];
                            $compatibilityReasons = [$chassisCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                            $fullChassisResult = $chassisCompatResult;

                            if (isset($chassisCompatResult['details'])) {
                                $compatibilityReasons[] = 'DEBUG_DETAILS: ' . json_encode($chassisCompatResult['details']);
                            }
                        } elseif ($componentType === 'pciecard') {
                            $pcieCompatResult = $compatibility->checkPCIeDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $pcieCompatResult['compatible'];
                            $compatibilityReasons = [$pcieCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                        } elseif ($componentType === 'nic') {
                            $nicCompatResult = $compatibility->checkPCIeDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData, 'nic'
                            );
                            $isCompatible = $nicCompatResult['compatible'];
                            $compatibilityReasons = [$nicCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                        } elseif ($componentType === 'hbacard') {
                            $hbaCompatResult = $compatibility->checkHBADecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $hbaCompatResult['compatible'];
                            $compatibilityReasons = [$hbaCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];

                            // Add debug info for HBA samples
                            if (!isset($debugInfo['hba_compat_samples'])) {
                                $debugInfo['hba_compat_samples'] = [];
                            }
                            if (count($debugInfo['hba_compat_samples']) < 3) {
                                $debugInfo['hba_compat_samples'][] = [
                                    'uuid' => $component['UUID'],
                                    'serial' => $component['SerialNumber'],
                                    'result' => $hbaCompatResult
                                ];
                            }
                        } elseif ($componentType === 'sfp') {
                            // SFP compatibility checking based on NIC port types
                            $nicPortTypes = [];
                            $nicDetails = [];
                            foreach ($existingComponentsData as $existingComp) {
                                if ($existingComp['type'] === 'nic') {
                                    $nicSpecs = $componentDataService->getComponentSpecifications('nic', $existingComp['uuid']);
                                    if ($nicSpecs && isset($nicSpecs['port_type'])) {
                                        $portType = $nicSpecs['port_type'];
                                        $nicPortTypes[] = $portType;
                                        $nicDetails[] = [
                                            'uuid' => $existingComp['uuid'],
                                            'model' => $nicSpecs['model'] ?? 'Unknown',
                                            'port_type' => $portType
                                        ];
                                    }
                                }
                            }

                            if (empty($nicPortTypes)) {
                                // No NICs in configuration - ALL SFPs are compatible
                                $isCompatible = true;
                                $compatibilityReasons = ['SFP can be added now - will be assigned when compatible NIC is added'];
                            } else {
                                // Get SFP type from specs
                                $sfpSpecs = $componentDataService->getComponentSpecifications('sfp', $component['UUID']);
                                $sfpType = $sfpSpecs['type'] ?? null;

                                if (!$sfpType) {
                                    $isCompatible = false;
                                    $compatibilityReasons = ['SFP type information missing in specifications'];
                                } else {
                                    // Check if SFP type is compatible with at least one NIC port type
                                    $isCompatible = false;
                                    $compatibleWith = [];

                                    foreach ($nicDetails as $nicDetail) {
                                        if (NICPortTracker::isCompatible($nicDetail['port_type'], $sfpType)) {
                                            $isCompatible = true;
                                            $compatibleWith[] = "{$nicDetail['model']} ({$nicDetail['port_type']} port)";
                                        }
                                    }

                                    if ($isCompatible) {
                                        $compatibilityReasons = [
                                            "SFP type '{$sfpType}' compatible with: " . implode(', ', $compatibleWith)
                                        ];
                                    } else {
                                        $availablePortTypes = array_unique(array_column($nicDetails, 'port_type'));
                                        $compatibilityReasons = [
                                            "SFP type '{$sfpType}' incompatible with available NIC port types: " . implode(', ', $availablePortTypes)
                                        ];
                                    }
                                }
                            }
                        } elseif ($componentType === 'caddy') {
                            $newComponent = ['type' => 'caddy', 'uuid' => $component['UUID']];
                            $compatResult = $compatibility->checkCaddyDecentralizedCompatibility($newComponent, $existingComponentsData);
                            if (!$compatResult['compatible']) {
                                $isCompatible = false;
                                $compatibilityReasons = array_merge($compatibilityReasons, $compatResult['issues'] ?? []);
                            } else {
                                $compatibilityReasons[] = $compatResult['compatibility_summary'] ?? 'Compatible';
                            }
                        } else {
                            // Check compatibility with each existing component for other types
                            foreach ($existingComponentsData as $existingComp) {
                                $newComponent = ['type' => $componentType, 'uuid' => $component['UUID']];
                                $existingComponent = ['type' => $existingComp['type'], 'uuid' => $existingComp['uuid']];

                                $compatResult = $compatibility->checkComponentPairCompatibility($newComponent, $existingComponent);

                                if (!$compatResult['compatible']) {
                                    $isCompatible = false;
                                    $compatibilityReasons[] = "Incompatible with " . $existingComp['type'] . ": " .
                                                             implode(', ', $compatResult['issues'] ?? []);
                                    break;
                                } else {
                                    $compatibilityReasons[] = "Compatible with " . $existingComp['type'];
                                }
                            }
                        }
                    }

                    // Build component result
                    $componentStatus = (int)$component['Status'];
                    $statusLabels = [0 => 'failed', 1 => 'available', 2 => 'in_use'];

                    $compatibleComponent = [
                        'uuid' => $component['UUID'],
                        'serial_number' => $component['SerialNumber'],
                        'status' => $componentStatus,
                        'status_label' => $statusLabels[$componentStatus] ?? 'unknown',
                        'available_for_use' => ($componentStatus === 1),
                        'server_uuid' => $component['ServerUUID'] ?? null,
                        'location' => $component['Location'],
                        'notes' => $component['Notes'],
                        'compatibility_reason' => implode('; ', $compatibilityReasons),
                        'is_compatible' => $isCompatible
                    ];

                    // Add chassis-specific fields
                    if ($componentType === 'chassis' && isset($fullChassisResult['score_breakdown'])) {
                        $compatibleComponent['score_breakdown'] = $fullChassisResult['score_breakdown'];
                    }
                    if ($componentType === 'chassis' && isset($fullChassisResult['warnings']) && !empty($fullChassisResult['warnings'])) {
                        $compatibleComponent['warnings'] = $fullChassisResult['warnings'];
                    }

                    $compatibleComponents[] = $compatibleComponent;
                }
            } else {
                // Fallback if ComponentCompatibility not available
                error_log("WARNING: ComponentCompatibility class not available, using simplified fallback");

                foreach ($allComponents as $component) {
                    $componentStatus = (int)$component['Status'];
                    $statusLabels = [0 => 'failed', 1 => 'available', 2 => 'in_use'];

                    $compatibleComponent = [
                        'uuid' => $component['UUID'],
                        'serial_number' => $component['SerialNumber'],
                        'status' => $componentStatus,
                        'status_label' => $statusLabels[$componentStatus] ?? 'unknown',
                        'available_for_use' => ($componentStatus === 1),
                        'server_uuid' => $component['ServerUUID'] ?? null,
                        'location' => $component['Location'],
                        'notes' => $component['Notes'],
                        'compatibility_reason' => empty($existingComponentsData) ? "No existing components - all components available" : "Basic compatibility check passed",
                        'is_compatible' => true
                    ];

                    $compatibleComponents[] = $compatibleComponent;
                }
            }

            // Step 5: Build response
            $compatibleAndAvailable = array_filter($compatibleComponents, function($comp) {
                return $comp['is_compatible'] && $comp['available_for_use'];
            });
            $compatibleButNotAvailable = array_filter($compatibleComponents, function($comp) {
                return $comp['is_compatible'] && !$comp['available_for_use'];
            });
            $incompatibleOnly = array_filter($compatibleComponents, function($comp) {
                return !$comp['is_compatible'];
            });

            // Respect available_only parameter
            if ($availableOnly) {
                $allCompatibleComponents = array_values($compatibleAndAvailable);
            } else {
                $allCompatibleComponents = array_merge(
                    array_values($compatibleAndAvailable),
                    array_values($compatibleButNotAvailable)
                );
            }

            $responseData = [
                'config_uuid' => $configUuid,
                'component_type' => $componentType,
                'compatible_components' => $allCompatibleComponents,
                'incompatible_components' => array_values($incompatibleOnly),
                'total_compatible' => count($allCompatibleComponents),
                'total_compatible_and_available' => count($compatibleAndAvailable),
                'total_compatible_but_unavailable' => count($compatibleButNotAvailable),
                'total_incompatible' => count($incompatibleOnly),
                'total_found' => count($compatibleComponents),
                'filters_applied' => [
                    'available_only' => $availableOnly,
                    'component_type' => $componentType,
                    'note' => $availableOnly
                        ? 'Only available components shown (Status=1 and not assigned to another server).'
                        : 'All physical components shown. Check available_for_use flag to see which can be added.'
                ],
                'existing_components_summary' => [
                    'total_existing' => count($existingComponentsData),
                    'types' => array_values(array_unique(array_column($existingComponentsData, 'type')))
                ],
                'compatibility_summary' => [
                    'has_compatible' => count($allCompatibleComponents) > 0,
                    'has_incompatible' => count($incompatibleOnly) > 0,
                    'main_issues' => count($incompatibleOnly) > 0 ?
                        array_slice(array_unique(array_column($incompatibleOnly, 'compatibility_reason')), 0, 3) : []
                ]
            ];

            // Add inventory summary
            $stmt = $this->pdo->prepare("
                SELECT UUID, COUNT(*) as total_count,
                       SUM(CASE WHEN Status = 1 THEN 1 ELSE 0 END) as available_count,
                       SUM(CASE WHEN Status = 2 THEN 1 ELSE 0 END) as in_use_count,
                       SUM(CASE WHEN Status = 0 THEN 1 ELSE 0 END) as failed_count
                FROM $table
                GROUP BY UUID
                HAVING COUNT(*) > 0
            ");
            $stmt->execute();
            $inventoryCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $uuidInventorySummary = [];
            foreach ($inventoryCounts as $inv) {
                $uuidInventorySummary[$inv['UUID']] = [
                    'total' => (int)$inv['total_count'],
                    'available' => (int)$inv['available_count'],
                    'in_use' => (int)$inv['in_use_count'],
                    'failed' => (int)$inv['failed_count']
                ];
            }

            $responseData['inventory_summary'] = [
                'by_uuid' => $uuidInventorySummary,
                'note' => 'Multiple physical components can share the same UUID (representing the same model). Use serial_number to identify individual components.'
            ];

            // Add debug info if requested
            if ($includeDebug) {
                $responseData['debug_info'] = $debugInfo;
            }

            return [
                'success' => true,
                'message' => count($allCompatibleComponents) > 0 ? "Compatible components found" : "No compatible components found",
                'compatible_components' => $allCompatibleComponents,
                'incompatible_components' => array_values($incompatibleOnly),
                'totals' => [
                    'compatible_and_available' => count($compatibleAndAvailable),
                    'compatible_but_unavailable' => count($compatibleButNotAvailable),
                    'incompatible' => count($incompatibleOnly),
                    'total_found' => count($compatibleComponents)
                ],
                'filters_applied' => $responseData['filters_applied'],
                'debug_info' => $includeDebug ? $debugInfo : [],
                'inventory_summary' => $responseData['inventory_summary'],
                'compatibility_summary' => $responseData['compatibility_summary']
            ];

        } catch (Exception $e) {
            error_log("Error getting compatible components: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to get compatible components: " . $e->getMessage()
            ];
        }
    }

    /**
     * Migrate NIC slot positions for pre-existing components
     * Runs once per config to assign slot_position to component NICs that pre-date slot auto-assignment
     * Safe to run on every config load — only acts on NICs that still have no slot_position stored
     */
    public function migrateNICSlotPositions($configUuid) {
        try {
            $stmt = $this->pdo->prepare("SELECT nic_config FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $nicConfigJson = $stmt->fetchColumn();

            if (!$nicConfigJson) {
                return;
            }

            $nicConfig = json_decode($nicConfigJson, true);
            if (!isset($nicConfig['nics']) || !is_array($nicConfig['nics'])) {
                return;
            }

            $needsMigration = false;
            foreach ($nicConfig['nics'] as $nic) {
                if (($nic['source_type'] ?? '') === 'component' && empty($nic['slot_position'])) {
                    $needsMigration = true;
                    break;
                }
            }

            if (!$needsMigration) {
                return;
            }

            $slotTracker = new UnifiedSlotTracker($this->pdo);
            $dataService = ComponentDataService::getInstance();

            foreach ($nicConfig['nics'] as &$nic) {
                if (($nic['source_type'] ?? '') !== 'component' || !empty($nic['slot_position'])) {
                    continue;
                }

                $nicUuid = $nic['uuid'];
                $nicSpecs = $dataService->getComponentSpecifications('nic', $nicUuid);

                if ($nicSpecs && isset($nicSpecs['interface'])) {
                    preg_match('/x(\d+)/', $nicSpecs['interface'], $matches);
                    $slotSize = 'x' . ($matches[1] ?? '8');
                } else {
                    $slotSize = 'x8';
                }

                $slotPosition = $slotTracker->assignSlot($configUuid, $slotSize);
                if ($slotPosition) {
                    $nic['slot_position'] = $slotPosition;
                    error_log("NIC-MIGRATE: Assigned slot $slotPosition to NIC $nicUuid in config $configUuid");
                }
            }
            unset($nic);

            $updateStmt = $this->pdo->prepare("UPDATE server_configurations SET nic_config = ? WHERE config_uuid = ?");
            $updateStmt->execute([json_encode($nicConfig), $configUuid]);

        } catch (Exception $e) {
            error_log("NIC-MIGRATE: Error migrating NIC slots for config $configUuid: " . $e->getMessage());
            // Non-fatal — don't block the response
        }
    }

    /**
     * Get unified slot tracking for a server configuration
     * Consolidates PCIe, riser, and M.2 slot information from UnifiedSlotTracker
     *
     * @param string $configUuid Server configuration UUID
     * @return array Unified slot tracking data
     */
    public function getSlotTracking($configUuid) {
        try {
            $slotTracker = new UnifiedSlotTracker($this->pdo);

            // Get PCIe slot availability (includes riser-provided slots)
            $pcieAvailability = $slotTracker->getSlotAvailability($configUuid);

            // Get riser slot availability
            $riserAvailability = $slotTracker->getRiserSlotAvailability($configUuid);

            // Get M.2 slot availability
            $m2Availability = $slotTracker->getM2SlotAvailability($configUuid);

            // Build unified slot tracking response
            $result = [
                'pcie' => [
                    'success' => $pcieAvailability['success'],
                    'total_slots' => $pcieAvailability['total_slots'] ?? [],
                    'used_slots' => $pcieAvailability['used_slots'] ?? [],
                    'available_slots' => $pcieAvailability['available_slots'] ?? [],
                    'total_count' => 0,
                    'used_count' => 0,
                    'available_count' => 0
                ],
                'riser' => [
                    'success' => $riserAvailability['success'],
                    'total_slots' => $riserAvailability['total_slots'] ?? [],
                    'used_slots' => $riserAvailability['used_slots'] ?? [],
                    'available_slots' => $riserAvailability['available_slots'] ?? [],
                    'total_count' => 0,
                    'used_count' => 0,
                    'available_count' => 0
                ],
                'm2' => [
                    'success' => $m2Availability['success'],
                    'motherboard_slots' => $m2Availability['motherboard_slots'] ?? [
                        'total' => 0,
                        'used' => 0,
                        'available' => 0
                    ],
                    'expansion_card_slots' => $m2Availability['expansion_card_slots'] ?? [
                        'total' => 0,
                        'used' => 0,
                        'available' => 0,
                        'providers' => []
                    ],
                    'total_count' => 0,
                    'used_count' => 0,
                    'available_count' => 0
                ]
            ];

            // Calculate PCIe slot counts
            foreach ($result['pcie']['total_slots'] as $slotType => $slotIds) {
                $result['pcie']['total_count'] += count($slotIds);
            }
            $result['pcie']['used_count'] = count($result['pcie']['used_slots']);
            $result['pcie']['available_count'] = $result['pcie']['total_count'] - $result['pcie']['used_count'];

            // Calculate riser slot counts
            foreach ($result['riser']['total_slots'] as $slotType => $slotIds) {
                $result['riser']['total_count'] += count($slotIds);
            }
            $result['riser']['used_count'] = count($result['riser']['used_slots']);
            $result['riser']['available_count'] = $result['riser']['total_count'] - $result['riser']['used_count'];

            // Calculate M.2 slot counts
            $result['m2']['total_count'] =
                $result['m2']['motherboard_slots']['total'] +
                $result['m2']['expansion_card_slots']['total'];
            $result['m2']['used_count'] =
                $result['m2']['motherboard_slots']['used'] +
                $result['m2']['expansion_card_slots']['used'];
            $result['m2']['available_count'] =
                $result['m2']['motherboard_slots']['available'] +
                $result['m2']['expansion_card_slots']['available'];

            return $result;

        } catch (Exception $e) {
            error_log("Error getting slot tracking: " . $e->getMessage());
            return [
                'error' => 'Failed to get slot tracking: ' . $e->getMessage(),
                'pcie' => ['success' => false, 'total_count' => 0, 'used_count' => 0, 'available_count' => 0],
                'riser' => ['success' => false, 'total_count' => 0, 'used_count' => 0, 'available_count' => 0],
                'm2' => ['success' => false, 'total_count' => 0, 'used_count' => 0, 'available_count' => 0]
            ];
        }
    }

    /**
     * Get storage connectivity tracking for a server configuration
     */
    public function getStorageConnectivity($configUuid, $components) {
        try {
            $stmt = $this->pdo->prepare("SELECT chassis_uuid FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $chassisUuid = $stmt->fetchColumn();

            $totalBays = 0;
            if ($chassisUuid) {
                $chassisManager = new ChassisManager();
                $chassisResult = $chassisManager->loadChassisSpecsByUUID($chassisUuid);
                if ($chassisResult['found']) {
                    $totalBays = $chassisResult['specifications']['drive_bays']['total_bays'] ?? 0;
                }
            }

            $connections = [];
            $usedBays = 0;

            $storageComponents = $components['storage'] ?? [];
            foreach ($storageComponents as $storage) {
                $conn = $storage['connection'] ?? null;
                if ($conn && ($conn['type'] ?? '') === 'chassis_bay') {
                    $usedBays++;
                }
                $connections[] = [
                    'storage_uuid' => $storage['uuid'],
                    'storage_name' => $storage['component_name'] ?? 'Unknown',
                    'serial_number' => $storage['serial_number'] ?? 'Unknown',
                    'connection_type' => $conn['type'] ?? 'not_connected',
                    'bay_number' => $conn['bay_number'] ?? null,
                    'backplane_interface' => $conn['backplane_interface'] ?? null,
                    'storage_interface' => $conn['storage_interface'] ?? null,
                    'compatibility' => $conn['compatibility_type'] ?? null,
                    'description' => $conn['description'] ?? null
                ];
            }

            return [
                'drive_bays' => [
                    'total' => $totalBays,
                    'used' => $usedBays,
                    'available' => max(0, $totalBays - $usedBays)
                ],
                'connections' => $connections
            ];
        } catch (Exception $e) {
            error_log("Error getting storage connectivity: " . $e->getMessage());
            return ['drive_bays' => ['total' => 0, 'used' => 0, 'available' => 0], 'connections' => []];
        }
    }

    /**
     * Get unified network configuration for a server
     * Consolidates NIC data from multiple sources (onboard, component, port tracking)
     *
     * @param string $configUuid Server configuration UUID
     * @return array Unified network configuration data
     */
    public function getNetworkConfiguration($configUuid) {
        try {
            $result = [
                'summary' => [
                    'total_ports' => 0,
                    'onboard_ports' => 0,
                    'component_ports' => 0,
                    'total_nics' => 0,
                    'onboard_nics' => 0,
                    'component_nics' => 0
                ],
                'nics' => [],
                'success' => true
            ];

            // Get NIC configuration from database
            $stmt = $this->pdo->prepare("SELECT nic_config FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $nicConfigJson = $stmt->fetchColumn();

            if (!$nicConfigJson) {
                // No NIC configuration exists - return empty but successful
                return $result;
            }

            $nicConfiguration = json_decode($nicConfigJson, true);
            if (!is_array($nicConfiguration)) {
                return $result;
            }

            // Extract summary if available
            if (isset($nicConfiguration['summary'])) {
                $result['summary'] = $nicConfiguration['summary'];
            }

            // Extract NICs if available
            if (isset($nicConfiguration['nics']) && is_array($nicConfiguration['nics'])) {
                $result['nics'] = $nicConfiguration['nics'];
            }

            // Add SFP port mapping to each NIC
            $sfpStmt = $this->pdo->prepare("SELECT sfp_configuration FROM server_configurations WHERE config_uuid = ?");
            $sfpStmt->execute([$configUuid]);
            $sfpConfigJson = $sfpStmt->fetchColumn();
            $sfps = [];
            if ($sfpConfigJson) {
                $sfpDecoded = json_decode($sfpConfigJson, true);
                $sfps = $sfpDecoded['sfps'] ?? [];
            }

            foreach ($result['nics'] as &$nic) {
                $nicUuid = $nic['uuid'];
                $portCount = $nic['specifications']['ports'] ?? 0;
                $portMapping = [];

                for ($i = 1; $i <= $portCount; $i++) {
                    $portMapping[$i] = ['status' => 'empty', 'sfp' => null];
                }

                foreach ($sfps as $sfp) {
                    if (($sfp['parent_nic_uuid'] ?? null) === $nicUuid && isset($sfp['port_index'])) {
                        $portMapping[$sfp['port_index']] = [
                            'status' => 'occupied',
                            'sfp_uuid' => $sfp['uuid'],
                            'serial_number' => $sfp['serial_number'] ?? null
                        ];
                    }
                }

                $nic['port_mapping'] = $portMapping;
            }
            unset($nic); // break reference

            return $result;

        } catch (Exception $e) {
            error_log("Error getting network configuration: " . $e->getMessage());
            return [
                'summary' => [
                    'total_ports' => 0,
                    'onboard_ports' => 0,
                    'component_ports' => 0,
                    'total_nics' => 0,
                    'onboard_nics' => 0,
                    'component_nics' => 0
                ],
                'nics' => [],
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get current configuration warnings
     */
    public function getConfigurationWarnings(array $components): array {
        $dataUtils = $this->dataUtils;

        $warnings = [];

        try {
            // Check M.2 slot capacity
            $m2Count = 0;
            $m2TotalSlots = 0;

            if (isset($components['motherboard']) && !empty($components['motherboard'])) {
                $mbUuid = $components['motherboard'][0]['uuid'] ?? null;
                if ($mbUuid) {
                    $mbSpecs = $dataUtils->getMotherboardByUUID($mbUuid);
                    // P3.1 FIX: Sum ALL M.2 slot types (NVMe + SATA), not just the first one
                    if ($mbSpecs && isset($mbSpecs['storage']['nvme']['m2_slots'])) {
                        $m2TotalSlots = 0;
                        foreach ($mbSpecs['storage']['nvme']['m2_slots'] as $slotConfig) {
                            $m2TotalSlots += $slotConfig['count'] ?? 0;
                        }
                    }
                }
            }

            if (isset($components['storage']) && !empty($components['storage'])) {
                foreach ($components['storage'] as $storage) {
                    $storageUuid = $storage['uuid'] ?? null;
                    if ($storageUuid) {
                        $storageSpecs = $dataUtils->getStorageByUUID($storageUuid);
                        if ($storageSpecs) {
                            $formFactor = strtolower($storageSpecs['form_factor'] ?? '');
                            if (strpos($formFactor, 'm.2') !== false || strpos($formFactor, 'm2') !== false) {
                                $m2Count++;
                            }
                        }
                    }
                }
            }

            if ($m2Count > $m2TotalSlots && $m2TotalSlots > 0) {
                $warnings[] = [
                    'type' => 'm2_slots_exceeded',
                    'severity' => 'high',
                    'message' => "M.2 slots exceeded: Using $m2Count slots but only $m2TotalSlots available",
                    'recommendation' => "Remove " . ($m2Count - $m2TotalSlots) . " M.2 storage device(s)"
                ];
            } elseif ($m2Count == $m2TotalSlots && $m2TotalSlots > 0) {
                $warnings[] = [
                    'type' => 'm2_slots_full',
                    'severity' => 'info',
                    'message' => "All M.2 slots are in use ($m2Count/$m2TotalSlots)",
                    'recommendation' => "No additional M.2 storage can be added"
                ];
            }

            // Check for missing critical components (CPU, Motherboard, RAM, Chassis)
            $criticalComponents = ['cpu', 'motherboard', 'ram', 'chassis'];
            foreach ($criticalComponents as $type) {
                if (empty($components[$type])) {
                    $warnings[] = [
                        'type' => 'missing_component',
                        'severity' => 'critical',
                        'message' => ucfirst($type) . " is required but not present",
                        'recommendation' => "Add " . ucfirst($type) . " to complete server configuration"
                    ];
                }
            }

            // Check caddy-storage compatibility and count matching
            if (!empty($components['storage'])) {
                $storageByFormFactor = [
                    '2.5' => [],
                    '3.5' => []
                ];
                $caddyByFormFactor = [
                    '2.5' => [],
                    '3.5' => []
                ];

                // Count storage devices by form factor (2.5" and 3.5" only)
                foreach ($components['storage'] as $storage) {
                    $storageUuid = $storage['uuid'] ?? null;
                    if ($storageUuid) {
                        $storageSpecs = $dataUtils->getStorageByUUID($storageUuid);
                        if ($storageSpecs) {
                            $formFactor = strtolower($storageSpecs['form_factor'] ?? '');
                            // Only check for 2.5" and 3.5" drives (not M.2 or other form factors)
                            if (strpos($formFactor, '2.5') !== false) {
                                $storageByFormFactor['2.5'][] = $storageUuid;
                            } elseif (strpos($formFactor, '3.5') !== false) {
                                $storageByFormFactor['3.5'][] = $storageUuid;
                            }
                        }
                    }
                }

                // Count caddies by form factor
                if (!empty($components['caddy'])) {
                    foreach ($components['caddy'] as $caddy) {
                        $caddyUuid = $caddy['uuid'] ?? null;
                        if ($caddyUuid) {
                            $caddySpecs = $dataUtils->getCaddyByUUID($caddyUuid);
                            if ($caddySpecs) {
                                $formFactor = strtolower(
                                    $caddySpecs['compatibility']['size'] ??
                                    $caddySpecs['form_factor'] ??
                                    $caddySpecs['type'] ??
                                    ''
                                );
                                if (strpos($formFactor, '2.5') !== false) {
                                    $caddyByFormFactor['2.5'][] = $caddyUuid;
                                } elseif (strpos($formFactor, '3.5') !== false) {
                                    $caddyByFormFactor['3.5'][] = $caddyUuid;
                                }
                            }
                        }
                    }
                }

                // Check 2.5" storage vs caddy count
                $storage25Count = count($storageByFormFactor['2.5']);
                $caddy25Count = count($caddyByFormFactor['2.5']);

                if ($storage25Count > 0 && $caddy25Count < $storage25Count) {
                    $missing = $storage25Count - $caddy25Count;
                    $warnings[] = [
                        'type' => 'caddy_shortage',
                        'severity' => 'high',
                        'message' => "Insufficient 2.5\" caddies: {$storage25Count} 2.5\" storage device(s) require {$storage25Count} caddies, but only {$caddy25Count} available",
                        'recommendation' => "Add {$missing} 2.5\" caddy/caddies to match storage devices"
                    ];
                } elseif ($storage25Count > 0 && $caddy25Count > $storage25Count) {
                    $excess = $caddy25Count - $storage25Count;
                    $warnings[] = [
                        'type' => 'caddy_excess',
                        'severity' => 'info',
                        'message' => "Excess 2.5\" caddies: {$excess} extra 2.5\" caddy/caddies not currently needed",
                        'recommendation' => "You have {$caddy25Count} 2.5\" caddies for {$storage25Count} 2.5\" storage device(s)"
                    ];
                }

                // Check 3.5" storage vs caddy count
                $storage35Count = count($storageByFormFactor['3.5']);
                $caddy35Count = count($caddyByFormFactor['3.5']);

                if ($storage35Count > 0 && $caddy35Count < $storage35Count) {
                    $missing = $storage35Count - $caddy35Count;
                    $warnings[] = [
                        'type' => 'caddy_shortage',
                        'severity' => 'high',
                        'message' => "Insufficient 3.5\" caddies: {$storage35Count} 3.5\" storage device(s) require {$storage35Count} caddies, but only {$caddy35Count} available",
                        'recommendation' => "Add {$missing} 3.5\" caddy/caddies to match storage devices"
                    ];
                } elseif ($storage35Count > 0 && $caddy35Count > $storage35Count) {
                    $excess = $caddy35Count - $storage35Count;
                    $warnings[] = [
                        'type' => 'caddy_excess',
                        'severity' => 'info',
                        'message' => "Excess 3.5\" caddies: {$excess} extra 3.5\" caddy/caddies not currently needed",
                        'recommendation' => "You have {$caddy35Count} 3.5\" caddies for {$storage35Count} 3.5\" storage device(s)"
                    ];
                }
            }

            // Check for missing NIC - only warn if no onboard NICs are present
            if (empty($components['nic']) && isset($components['motherboard']) && !empty($components['motherboard'])) {
                // Check if motherboard has onboard NICs
                $hasOnboardNICs = false;
                $mbUuid = $components['motherboard'][0]['uuid'] ?? null;

                if ($mbUuid) {
                    $mbSpecs = $dataUtils->getMotherboardByUUID($mbUuid);
                    if ($mbSpecs && isset($mbSpecs['networking']['onboard_nics']) && !empty($mbSpecs['networking']['onboard_nics'])) {
                        $hasOnboardNICs = true;
                    }
                }

                // Only warn if no onboard NICs are available
                if (!$hasOnboardNICs) {
                    $warnings[] = [
                        'type' => 'missing_nic',
                        'severity' => 'high',
                        'message' => "No NIC component found in configuration",
                        'recommendation' => "Add a NIC component for network connectivity"
                    ];
                }
            } elseif (empty($components['nic']) && empty($components['motherboard'])) {
                // No motherboard and no NIC - warn about NIC
                $warnings[] = [
                    'type' => 'missing_nic',
                    'severity' => 'high',
                    'message' => "No NIC component found in configuration",
                    'recommendation' => "Add a NIC component for network connectivity"
                ];
            }

        } catch (Exception $e) {
            error_log("Error getting configuration warnings: " . $e->getMessage());
        }

        return $warnings;
    }

    /**
     * Auto-assign an available SFP port on a NIC
     * Determines port count from NIC specs and returns first unoccupied port
     *
     * @param string $nicUuid       NIC UUID to assign port on
     * @param string $configUuid    Server configuration UUID (used to read sfp_configuration)
     * @return int|null             First available port index (1-based), or null if all occupied
     */
    public function autoAssignSFPPort($nicUuid, $configUuid) {
        try {
            // Step 1: Determine the total port count for this NIC
            $portCount = 0;

            $stmt = $this->pdo->prepare("SELECT SourceType, ParentComponentUUID, OnboardNICIndex FROM nicinventory WHERE UUID = ? LIMIT 1");
            $stmt->execute([$nicUuid]);
            $nicRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($nicRow && ($nicRow['SourceType'] === 'onboard') && !empty($nicRow['ParentComponentUUID'])) {
                // Onboard NIC: get port count from parent motherboard JSON
                $dataService = ComponentDataService::getInstance();
                $mbSpecs = $dataService->findComponentByUuid('motherboard', $nicRow['ParentComponentUUID']);
                $onboardIndex = (int)($nicRow['OnboardNICIndex'] ?? 1);
                $onboardNics = $mbSpecs['networking']['onboard_nics'] ?? [];
                $nicSpec = $onboardNics[$onboardIndex - 1] ?? null;
                $portCount = (int)($nicSpec['ports'] ?? 0);
            } else {
                // Regular component NIC: get port count from NIC JSON
                $dataService = ComponentDataService::getInstance();
                $nicSpecs = $dataService->getComponentSpecifications('nic', $nicUuid);
                $portCount = (int)($nicSpecs['ports'] ?? 0);
            }

            if ($portCount < 1) {
                error_log("autoAssignSFPPort: could not determine port count for NIC $nicUuid");
                return null;
            }

            // Step 2: Find which ports are already occupied on this NIC
            $occupiedPorts = [];
            $stmt = $this->pdo->prepare("SELECT sfp_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['sfp_configuration'])) {
                $sfpConfig = json_decode($row['sfp_configuration'], true) ?? [];
                foreach ($sfpConfig['sfps'] ?? [] as $sfp) {
                    if (($sfp['parent_nic_uuid'] ?? null) === $nicUuid) {
                        $occupiedPorts[] = (int)$sfp['port_index'];
                    }
                }
            }

            // Step 3: Return first port not in occupied list
            for ($port = 1; $port <= $portCount; $port++) {
                if (!in_array($port, $occupiedPorts)) {
                    return $port;
                }
            }

            error_log("autoAssignSFPPort: all $portCount port(s) occupied on NIC $nicUuid");
            return null;

        } catch (Exception $e) {
            error_log("autoAssignSFPPort error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get complete configuration details with proper component handling
     * Now reads components from JSON columns in server_configurations table
     */
    public function getConfigurationDetails($configUuid) {
        try {
            // Try cache first if available
            if ($this->configCache !== null) {
                $cached = $this->configCache->getConfiguration($configUuid);
                if ($cached !== null) {
                    return $cached;
                }
            }

            // Get base configuration
            $stmt = $this->pdo->prepare("
                SELECT sc.*, u.username as created_by_username
                FROM server_configurations sc
                LEFT JOIN users u ON sc.created_by = u.id
                WHERE sc.config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $configData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$configData) {
                return [
                    'config_uuid' => $configUuid,
                    'error' => 'Configuration not found'
                ];
            }

            // Extract components from JSON columns using helper method
            $components = $this->extractComponentsFromJson($configData);

            // Build simplified component information
            $componentDetails = [];
            $componentCounts = [];
            $totalComponents = 0;
            $assignedSerials = []; // Track to prevent duplicates

            foreach ($components as $component) {
                $type = $component['component_type'];
                $uuid = $component['component_uuid'];

                if (!isset($componentDetails[$type])) {
                    $componentDetails[$type] = [];
                    $componentCounts[$type] = 0;
                }

                // Get serial number from inventory table (fallback only), excluding already assigned ones
                $excludeSerials = $assignedSerials[$type] ?? [];
                $inventoryDetails = $this->getComponentDetails($type, $uuid, $configUuid, $excludeSerials);

                // CRITICAL: Use serial_number from JSON first (already stored when component was added)
                // Only fall back to inventory query if not present in JSON
                $serialNumber = $component['serial_number'] ?? $inventoryDetails['SerialNumber'] ?? 'Not Found';
                
                // Track assigned serial to avoid duplicates when multiple identical components exist
                if ($serialNumber !== 'Not Found' && strpos($serialNumber, 'VIRTUAL-') !== 0) {
                    if (!isset($assignedSerials[$type])) {
                        $assignedSerials[$type] = [];
                    }
                    $assignedSerials[$type][] = $serialNumber;
                }

                $simplifiedComponent = [
                    'uuid' => $uuid,
                    'serial_number' => $serialNumber,
                    'component_name' => $this->getComponentNameFromSpec($type, $uuid),
                    'quantity' => $component['quantity'],
                    'added_at' => $component['added_at']
                ];

                // Include connection data for storage components (with lazy migration)
                if ($type === 'storage') {
                    $storedConnection = $component['connection'] ?? null;
                    $storedType = $storedConnection['type'] ?? 'not_connected';
                    if (!empty($storedConnection) && $storedType !== 'not_connected') {
                        $simplifiedComponent['connection'] = $storedConnection;
                    } else {
                        // Recompute: either missing or stored as not_connected (lazy migration for
                        // storage added before chassis). Bay number is position-based so each
                        // storage gets a distinct sequential bay slot.
                        $bayNumber = count($componentDetails[$type] ?? []) + 1;
                        $simplifiedComponent['connection'] = $this->computeStorageConnectionPath($configUuid, $uuid, $bayNumber);
                    }
                }

                // Include parent NIC mapping for SFP components
                if ($type === 'sfp') {
                    if (!empty($component['parent_nic_uuid'])) {
                        $simplifiedComponent['parent_nic_uuid'] = $component['parent_nic_uuid'];
                    }
                    if (isset($component['port_index'])) {
                        $simplifiedComponent['port_index'] = $component['port_index'];
                    }
                }

                $componentDetails[$type][] = $simplifiedComponent;
                $componentCounts[$type] += $component['quantity'];
                $totalComponents += $component['quantity'];
            }

            // Use stored power consumption from database
            $totalPowerConsumptionWithOverhead = $configData['power_consumption'] ?? 0;
            $configData['power_consumption'] = round($totalPowerConsumptionWithOverhead, 2);

            // Parse validation_results from JSON if it exists
            if (!empty($configData['validation_results'])) {
                $configData['validation_results'] = json_decode($configData['validation_results'], true);
            }

            // Build result
            $result = [
                'configuration' => $configData,
                'components' => $componentDetails,
                'component_counts' => $componentCounts,
                'total_components' => $totalComponents,
                'power_consumption' => [
                    'total_watts' => round($totalPowerConsumptionWithOverhead / 1.2, 2),
                    'total_with_overhead_watts' => round($totalPowerConsumptionWithOverhead, 2),
                    'overhead_percentage' => 20
                ],
                'configuration_status' => $configData['configuration_status'],
                'server_name' => $configData['server_name'],
                'created_at' => $configData['created_at'],
                'updated_at' => $configData['updated_at']
            ];

            // Store in cache before returning (if available)
            if ($this->configCache !== null) {
                $this->configCache->setConfiguration($configUuid, $result);
            }

            return $result;

        } catch (Exception $e) {
            error_log("Error getting configuration details: " . $e->getMessage());
            return [
                'config_uuid' => $configUuid,
                'error' => 'Failed to load configuration details: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Update server_configurations table with component information
     */
    private function updateServerConfigurationTable($configUuid, $componentType, $componentUuid, $quantity, $action, $serialNumber = null, $options = []) {
        try {
            $updateFields = [];
            $updateValues = [];

            switch ($componentType) {
                case 'chassis':
                    // Chassis is stored in chassis_uuid column (similar to motherboard)
                    if ($action === 'add') {
                        $updateFields[] = "chassis_uuid = ?";
                        $updateValues[] = $componentUuid;
                    } elseif ($action === 'remove') {
                        $updateFields[] = "chassis_uuid = NULL";
                    }
                    break;

                case 'cpu':
                    $this->updateCpuConfiguration($configUuid, $componentUuid, $quantity, $action, $serialNumber);
                    break;

                case 'motherboard':
                    if ($action === 'add') {
                        $updateFields[] = "motherboard_uuid = ?";
                        $updateValues[] = $componentUuid;

                        // Auto-create onboard NICs from motherboard JSON specs
                        $this->createOnboardNICsFromMotherboard($configUuid, $componentUuid);
                    } elseif ($action === 'remove') {
                        $updateFields[] = "motherboard_uuid = NULL";
                    }
                    break;

                case 'ram':
                    $this->updateRamConfiguration($configUuid, $componentUuid, $quantity, $action);
                    break;

                case 'storage':
                    $this->updateStorageConfiguration($configUuid, $componentUuid, $quantity, $action, $options);
                    break;

                case 'nic':
                    if ($action === 'add') {
                        $slotPosition = $options['slot_position'] ?? null;

                        if (!$slotPosition) {
                            // Auto-assign PCIe slot for NIC card (same pattern as HBA)
                            require_once __DIR__ . '/../compatibility/UnifiedSlotTracker.php';
                            require_once __DIR__ . '/../components/ComponentDataService.php';

                            $slotTracker = new UnifiedSlotTracker($this->pdo);
                            $dataService = ComponentDataService::getInstance();

                            $nicSpecs = $dataService->getComponentSpecifications('nic', $componentUuid);

                            if ($nicSpecs && isset($nicSpecs['interface'])) {
                                preg_match('/x(\d+)/', $nicSpecs['interface'], $matches);
                                $slotSize = 'x' . ($matches[1] ?? '8');
                            } else {
                                $slotSize = 'x8'; // Default NIC slot size
                            }

                            $slotPosition = $slotTracker->assignSlot($configUuid, $slotSize);

                            if (!$slotPosition) {
                                throw new Exception("No available PCIe slots for NIC (requires $slotSize slot)");
                            }
                        }

                        $this->updateNicConfiguration($configUuid, $componentUuid, $quantity, 'add', $slotPosition);
                    } elseif ($action === 'remove') {
                        $this->updateNicConfiguration($configUuid, $componentUuid, $quantity, 'remove', null);
                    }
                    break;

                case 'caddy':
                    $this->updateCaddyConfiguration($configUuid, $componentUuid, $quantity, $action);
                    break;

                case 'sfp':
                    // Extract SFP-specific parameters from options
                    $parentNicUuid = $options['parent_nic_uuid'] ?? null;
                    $portIndex = $options['port_index'] ?? null;
                    $this->updateSfpConfiguration($configUuid, $componentUuid, $quantity, $action, $serialNumber, $parentNicUuid, $portIndex);
                    break;

                case 'pciecard':
                    // Extract slot position from options if provided
                    $slotPosition = $options['slot_position'] ?? null;
                    $this->updatePcieCardConfiguration($configUuid, $componentUuid, $quantity, $action, $slotPosition);
                    break;

                case 'hbacard':
                    // HBA-TRACK FIX: Store HBA with PCIe slot position in JSON format
                    if ($action === 'add') {
                        // Get slot position from options or auto-assign
                        $slotPosition = $options['slot_position'] ?? null;

                        if (!$slotPosition) {
                            // Auto-assign PCIe slot for HBA card
                            require_once __DIR__ . '/../compatibility/UnifiedSlotTracker.php';
                            require_once __DIR__ . '/../components/ComponentDataService.php';

                            $slotTracker = new UnifiedSlotTracker($this->pdo);
                            $dataService = ComponentDataService::getInstance();

                            // Get HBA specs to determine slot size requirement
                            $hbaSpecs = $dataService->getComponentSpecifications('hbacard', $componentUuid);

                            if ($hbaSpecs && isset($hbaSpecs['interface'])) {
                                // Extract slot size from interface (e.g., "PCIe 3.0 x8" → "x8")
                                $interface = $hbaSpecs['interface'];
                                preg_match('/x(\d+)/', $interface, $matches);
                                $slotSize = 'x' . ($matches[1] ?? '8'); // Default to x8 if not found
                            } else {
                                $slotSize = 'x8'; // Default HBA slot size
                            }

                            // Assign available PCIe slot
                            $slotPosition = $slotTracker->assignSlot($configUuid, $slotSize);

                            if (!$slotPosition) {
                                error_log("HBA-TRACK: No available PCIe slots for HBA card (requires $slotSize)");
                                throw new Exception("No available PCIe slots for HBA card (requires $slotSize slot)");
                            }

                        }

                        // Delegate to dedicated method (handles JSON array accumulation)
                        $this->updateHbaCardConfiguration($configUuid, $componentUuid, 'add', $slotPosition, $serialNumber);

                    } elseif ($action === 'remove') {
                        $this->updateHbaCardConfiguration($configUuid, $componentUuid, 'remove');
                    }
                    break; // Skip generic UPDATE below — method handles its own SQL
            }

            if (!empty($updateFields)) {
                $sql = "UPDATE server_configurations SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE config_uuid = ?";
                $updateValues[] = $configUuid;
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($updateValues);
            }
            
        } catch (\Throwable $e) {
            error_log("Error updating server configuration table: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update CPU configuration in JSON format
     * New schema: cpu_configuration as JSON array supporting 1-2 CPUs
     */
    private function updateCpuConfiguration($configUuid, $componentUuid, $quantity, $action, $serialNumber = null) {
        try {
            if ($action === 'add') {
                // Retrieve current CPU configuration
                $stmt = $this->pdo->prepare("SELECT cpu_configuration FROM server_configurations WHERE config_uuid = ?");
                $stmt->execute([$configUuid]);
                $currentConfig = $stmt->fetchColumn();

                // Decode existing config or create new structure
                $cpuData = [];
                if (!empty($currentConfig)) {
                    $decoded = json_decode($currentConfig, true);
                    $cpuData = $decoded['cpus'] ?? [];
                }

                // Check if this is a virtual configuration
                $isVirtual = $this->isVirtualConfig($configUuid);

                // CRITICAL: Check for duplicates by BOTH uuid AND serial_number to prevent same physical component being added twice
                // Skip duplicate check for virtual configs - allow multiple instances of same component for testing
                $cpuExists = false;
                if (!$isVirtual) {
                    foreach ($cpuData as &$cpu) {
                        // FIXED: Proper matching logic to support multiple physical components with same UUID
                        $isMatch = false;

                        if ($serialNumber !== null) {
                            // When adding with serial_number, ONLY match if existing entry also has serial_number AND both match
                            // This allows multiple physical CPUs with same UUID (model) but different serial numbers
                            if (isset($cpu['serial_number'])) {
                                $isMatch = ($cpu['uuid'] === $componentUuid && $cpu['serial_number'] === $serialNumber);
                            }
                            // If existing CPU doesn't have serial_number, it's NOT a match (different physical component)
                        } else {
                            // When adding without serial_number, only match if existing entry also has no serial
                            // (same-model CPUs with serial numbers are different physical units)
                            if (!isset($cpu['serial_number'])) {
                                $isMatch = ($cpu['uuid'] === $componentUuid);
                            }
                        }

                        if ($isMatch) {
                            $cpuExists = true;
                            // Update quantity if CPU already exists
                            $cpu['quantity'] = $quantity;
                            break;
                        }
                    }
                    unset($cpu); // Break reference
                }

                // Add new CPU if it doesn't exist (or always add for virtual configs)
                if (!$cpuExists) {
                    $newCpu = [
                        'uuid' => $componentUuid,
                        'quantity' => $quantity,
                        'socket' => 'LGA3647', // Default socket - will be overridden by actual specs
                        'added_at' => date('Y-m-d H:i:s')
                    ];
                    // CRITICAL: Store serial_number to identify specific physical component
                    // For virtual configs, generate unique serial to differentiate multiple instances
                    if ($isVirtual) {
                        $virtualIndex = count($cpuData) + 1;
                        $newCpu['serial_number'] = 'VIRTUAL-CPU-' . $virtualIndex . '-' . time();
                    } elseif ($serialNumber !== null) {
                        $newCpu['serial_number'] = $serialNumber;
                    }
                    $cpuData[] = $newCpu;
                }

                // Build new JSON structure
                $newConfig = json_encode(['cpus' => $cpuData]);

                // Update database
                $stmt = $this->pdo->prepare("UPDATE server_configurations SET cpu_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
                $stmt->execute([$newConfig, $configUuid]);

            } elseif ($action === 'remove') {
                // Retrieve current CPU configuration
                $stmt = $this->pdo->prepare("SELECT cpu_configuration FROM server_configurations WHERE config_uuid = ?");
                $stmt->execute([$configUuid]);
                $currentConfig = $stmt->fetchColumn();

                if (!empty($currentConfig)) {
                    $decoded = json_decode($currentConfig, true);
                    $cpuData = $decoded['cpus'] ?? [];

                    // CRITICAL: Remove the specified CPU by matching both UUID and serial_number
                    $cpuData = array_filter($cpuData, function($cpu) use ($componentUuid, $serialNumber) {
                        // Match by serial_number if provided, otherwise fallback to uuid only
                        if ($serialNumber !== null && isset($cpu['serial_number'])) {
                            // Match by both uuid and serial_number
                            return !($cpu['uuid'] === $componentUuid && $cpu['serial_number'] === $serialNumber);
                        } else {
                            // Legacy: Match by uuid only
                            return $cpu['uuid'] !== $componentUuid;
                        }
                    });

                    // Re-index array
                    $cpuData = array_values($cpuData);

                    // Update database
                    if (empty($cpuData)) {
                        // If no CPUs left, set to NULL
                        $stmt = $this->pdo->prepare("UPDATE server_configurations SET cpu_configuration = NULL, updated_at = NOW() WHERE config_uuid = ?");
                        $stmt->execute([$configUuid]);
                    } else {
                        // Update with remaining CPUs
                        $newConfig = json_encode(['cpus' => $cpuData]);
                        $stmt = $this->pdo->prepare("UPDATE server_configurations SET cpu_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
                        $stmt->execute([$newConfig, $configUuid]);
                    }
                }
            }

        } catch (Exception $e) {
            error_log("Error updating CPU configuration: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update RAM configuration in JSON format
     */
    private function updateRamConfiguration($configUuid, $componentUuid, $quantity, $action) {
        try {
            $stmt = $this->pdo->prepare("SELECT ram_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentConfig = $stmt->fetchColumn();
            
            $ramConfig = $currentConfig ? json_decode($currentConfig, true) : [];
            if (!is_array($ramConfig)) {
                $ramConfig = [];
            }
            
            if ($action === 'add') {
                $ramConfig[] = [
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'added_at' => date('Y-m-d H:i:s')
                ];
            } elseif ($action === 'remove') {
                $ramConfig = array_filter($ramConfig, function($ram) use ($componentUuid) {
                    return $ram['uuid'] !== $componentUuid;
                });
                $ramConfig = array_values($ramConfig); // Reindex array
            }
            
            $stmt = $this->pdo->prepare("UPDATE server_configurations SET ram_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($ramConfig), $configUuid]);
            
        } catch (Exception $e) {
            error_log("Error updating RAM configuration: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update storage configuration in JSON format
     */
    private function updateStorageConfiguration($configUuid, $componentUuid, $quantity, $action, $options = []) {
        try {
            $stmt = $this->pdo->prepare("SELECT storage_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentConfig = $stmt->fetchColumn();

            $storageConfig = $currentConfig ? json_decode($currentConfig, true) : [];
            if (!is_array($storageConfig)) {
                $storageConfig = [];
            }

            if ($action === 'add') {
                $entry = [
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'added_at' => date('Y-m-d H:i:s')
                ];
                if (!empty($options['connection_data'])) {
                    $entry['connection'] = $options['connection_data'];
                }
                $storageConfig[] = $entry;
            } elseif ($action === 'remove') {
                $storageConfig = array_filter($storageConfig, function($storage) use ($componentUuid) {
                    return $storage['uuid'] !== $componentUuid;
                });
                $storageConfig = array_values($storageConfig);
            }
            
            $stmt = $this->pdo->prepare("UPDATE server_configurations SET storage_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($storageConfig), $configUuid]);
            
        } catch (Exception $e) {
            error_log("Error updating storage configuration: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Compute storage connection path using StorageConnectionValidator
     */
    private function computeStorageConnectionPath($configUuid, $storageUuid, $bayNumber = null) {
        try {
            require_once __DIR__ . '/../compatibility/StorageConnectionValidator.php';
            $storageValidator = new StorageConnectionValidator($this->pdo);
            $existingComponents = $this->getExistingComponentsForValidation($configUuid);

            $validation = $storageValidator->validate($configUuid, $storageUuid, $existingComponents);

            if ($validation['valid'] && isset($validation['primary_path'])) {
                $path = $validation['primary_path'];
                $details = $path['details'] ?? [];

                // Use caller-supplied bay number (position-based, avoids duplication when recomputing
                // for existing storage). Fall back to count+1 for the add-component flow where
                // the new storage is not yet in existingComponents.
                if ($bayNumber === null) {
                    $existingStorageCount = count($existingComponents['storage'] ?? []);
                    $bayNumber = $existingStorageCount + 1;
                }

                return [
                    'type' => $path['type'],
                    'bay_number' => $bayNumber,
                    'controller_uuid' => $details['chassis_uuid'] ?? $details['hba_uuid'] ?? null,
                    'backplane_interface' => $details['backplane_interface'] ?? null,
                    'storage_interface' => $details['storage_interface'] ?? null,
                    'compatibility_type' => $details['compatibility_type'] ?? null,
                    'description' => $path['description'] ?? null
                ];
            }

            return ['type' => 'not_connected'];
        } catch (Exception $e) {
            error_log("Error computing storage connection path: " . $e->getMessage());
            return ['type' => 'not_connected'];
        }
    }

    /**
     * Update NIC configuration in JSON format
     */
    private function updateNicConfiguration($configUuid, $componentUuid, $quantity, $action, $slotPosition = null) {
        try {
            $stmt = $this->pdo->prepare("SELECT nic_config FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentConfig = $stmt->fetchColumn();

            $nicConfig = $currentConfig ? json_decode($currentConfig, true) : [];
            if (!is_array($nicConfig)) {
                $nicConfig = [];
            }

            // Detect format: nested {"nics": [...], ...} (from OnboardNICHandler) vs flat array
            $isNestedFormat = isset($nicConfig['nics']) && is_array($nicConfig['nics']);

            if ($action === 'add') {
                $newNic = [
                    'uuid' => $componentUuid,
                    'source_type' => 'component',
                    'quantity' => $quantity,
                    'added_at' => date('Y-m-d H:i:s')
                ];
                if ($slotPosition !== null) {
                    $newNic['slot_position'] = $slotPosition;
                }
                if ($isNestedFormat) {
                    $nicConfig['nics'][] = $newNic;
                } else {
                    $nicConfig[] = $newNic;
                }
            } elseif ($action === 'remove') {
                if ($isNestedFormat) {
                    // Filter within the nics array, preserve the rest of the structure
                    $nicConfig['nics'] = array_values(array_filter($nicConfig['nics'], function($nic) use ($componentUuid) {
                        return !is_array($nic) || ($nic['uuid'] ?? null) !== $componentUuid;
                    }));
                } else {
                    $nicConfig = array_values(array_filter($nicConfig, function($nic) use ($componentUuid) {
                        return !is_array($nic) || ($nic['uuid'] ?? null) !== $componentUuid;
                    }));
                }
            }

            $stmt = $this->pdo->prepare("UPDATE server_configurations SET nic_config = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($nicConfig), $configUuid]);

        } catch (\Throwable $e) {
            error_log("Error updating NIC configuration: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update caddy configuration in JSON format
     */
    private function updateCaddyConfiguration($configUuid, $componentUuid, $quantity, $action) {
        try {
            $stmt = $this->pdo->prepare("SELECT caddy_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentConfig = $stmt->fetchColumn();
            
            $caddyConfig = $currentConfig ? json_decode($currentConfig, true) : [];
            if (!is_array($caddyConfig)) {
                $caddyConfig = [];
            }
            
            if ($action === 'add') {
                $caddyConfig[] = [
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'added_at' => date('Y-m-d H:i:s')
                ];
            } elseif ($action === 'remove') {
                $caddyConfig = array_filter($caddyConfig, function($caddy) use ($componentUuid) {
                    return $caddy['uuid'] !== $componentUuid;
                });
                $caddyConfig = array_values($caddyConfig);
            }
            
            $stmt = $this->pdo->prepare("UPDATE server_configurations SET caddy_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($caddyConfig), $configUuid]);

        } catch (Exception $e) {
            error_log("Error updating caddy configuration: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update SFP configuration in JSON format
     * Stores port assignments with parent NIC and port index
     */
    private function updateSfpConfiguration($configUuid, $componentUuid, $quantity, $action, $serialNumber = null, $parentNicUuid = null, $portIndex = null) {
        try {
            $stmt = $this->pdo->prepare("SELECT sfp_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentConfig = $stmt->fetchColumn();

            // Decode existing config or create new structure
            $sfpData = [];
            if (!empty($currentConfig)) {
                $decoded = json_decode($currentConfig, true);
                $sfpData = $decoded['sfps'] ?? [];
            }

            if ($action === 'add') {
                // Add new SFP with port assignment
                $sfpEntry = [
                    'uuid' => $componentUuid,
                    'parent_nic_uuid' => $parentNicUuid,
                    'port_index' => $portIndex,
                    'added_at' => date('Y-m-d H:i:s')
                ];

                // Add serial number if provided
                if ($serialNumber !== null) {
                    $sfpEntry['serial_number'] = $serialNumber;
                }

                $sfpData[] = $sfpEntry;


            } elseif ($action === 'remove') {
                // Remove SFP by UUID and optionally serial number
                $sfpData = array_filter($sfpData, function($sfp) use ($componentUuid, $serialNumber) {
                    if ($serialNumber !== null) {
                        // Match by both UUID and serial number
                        return !($sfp['uuid'] === $componentUuid &&
                                isset($sfp['serial_number']) &&
                                $sfp['serial_number'] === $serialNumber);
                    } else {
                        // Match by UUID only
                        return $sfp['uuid'] !== $componentUuid;
                    }
                });
                $sfpData = array_values($sfpData); // Re-index array

            }

            // Wrap in structure
            $sfpConfig = ['sfps' => $sfpData];

            $stmt = $this->pdo->prepare("UPDATE server_configurations SET sfp_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($sfpConfig), $configUuid]);

        } catch (Exception $e) {
            error_log("Error updating SFP configuration: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update PCIe card configuration in JSON format
     * Stores PCIe cards (including riser cards, NVMe adapters, etc.) in pciecard_configurations column
     */
    private function updatePcieCardConfiguration($configUuid, $componentUuid, $quantity, $action, $slotPosition = null) {
        try {
            $stmt = $this->pdo->prepare("SELECT pciecard_configurations FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentConfig = $stmt->fetchColumn();

            $pcieConfig = $currentConfig ? json_decode($currentConfig, true) : [];
            if (!is_array($pcieConfig)) {
                $pcieConfig = [];
            }

            if ($action === 'add') {
                $pcieEntry = [
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'added_at' => date('Y-m-d H:i:s')
                ];

                // Add slot position if provided
                if ($slotPosition !== null) {
                    $pcieEntry['slot_position'] = $slotPosition;
                }

                $pcieConfig[] = $pcieEntry;

            } elseif ($action === 'remove') {
                $pcieConfig = array_filter($pcieConfig, function($pcie) use ($componentUuid) {
                    return $pcie['uuid'] !== $componentUuid;
                });
                $pcieConfig = array_values($pcieConfig); // Re-index array
            }

            $stmt = $this->pdo->prepare("UPDATE server_configurations SET pciecard_configurations = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($pcieConfig), $configUuid]);

        } catch (Exception $e) {
            error_log("Error updating PCIe card configuration: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update HBA card configuration using JSON array (supports multiple HBA cards)
     * Mirrors updatePcieCardConfiguration() pattern: read array → append/remove → write back
     */
    private function updateHbaCardConfiguration($configUuid, $componentUuid, $action, $slotPosition = null, $serialNumber = null) {
        try {
            $stmt = $this->pdo->prepare("SELECT hbacard_config, hbacard_uuid FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $hbaArray = [];

            // Decode existing hbacard_config — handle 3 formats:
            // 1. JSON array (new format) — use as-is
            // 2. Single JSON object with 'uuid' key (migration format) — wrap in array
            // 3. null/empty — seed from legacy hbacard_uuid if present
            if (!empty($row['hbacard_config'])) {
                $decoded = json_decode($row['hbacard_config'], true);
                if (is_array($decoded)) {
                    if (isset($decoded['uuid'])) {
                        $hbaArray = [$decoded]; // Single object → array
                    } else {
                        $hbaArray = $decoded;
                    }
                }
            } elseif (!empty($row['hbacard_uuid']) && $action === 'add') {
                // Legacy fallback: seed array from scalar column
                $hbaArray = [[
                    'uuid' => $row['hbacard_uuid'],
                    'slot_position' => null,
                    'added_at' => date('Y-m-d H:i:s'),
                    'serial_number' => null
                ]];
            }

            if ($action === 'add') {
                $hbaEntry = [
                    'uuid' => $componentUuid,
                    'slot_position' => $slotPosition,
                    'added_at' => date('Y-m-d H:i:s'),
                    'serial_number' => $serialNumber
                ];
                $hbaArray[] = $hbaEntry;

            } elseif ($action === 'remove') {
                $hbaArray = array_filter($hbaArray, function($hba) use ($componentUuid) {
                    return ($hba['uuid'] ?? null) !== $componentUuid;
                });
                $hbaArray = array_values($hbaArray); // Re-index
            }

            // Write back: JSON array + backward-compat scalar (first entry's UUID)
            $firstUuid = !empty($hbaArray) ? ($hbaArray[0]['uuid'] ?? null) : null;

            $stmt = $this->pdo->prepare("UPDATE server_configurations SET hbacard_config = ?, hbacard_uuid = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([
                !empty($hbaArray) ? json_encode($hbaArray) : null,
                $firstUuid,
                $configUuid
            ]);

        } catch (Exception $e) {
            error_log("Error updating HBA card configuration: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create onboard NICs from motherboard JSON specifications
     */
    private function createOnboardNICsFromMotherboard($configUuid, $motherboardUuid) {
        try {
            // Load motherboard JSON specs using ComponentDataService
            require_once __DIR__ . '/../components/ComponentDataService.php';
            $dataService = ComponentDataService::getInstance($this->pdo);

            $motherboardSpecs = $dataService->findComponentByUuid('motherboard', $motherboardUuid);

            if (!$motherboardSpecs) {
                return;
            }

            // Check if motherboard has onboard NICs
            $onboardNics = $motherboardSpecs['networking']['onboard_nics'] ?? [];

            if (empty($onboardNics)) {
                return;
            }

            // Create each onboard NIC
            foreach ($onboardNics as $index => $nicSpec) {
                $onboardNicIndex = $index + 1;

                // Generate a unique UUID for this onboard NIC
                $onboardNicUuid = "onboard-nic-" . substr($motherboardUuid, 0, 24) . "-{$onboardNicIndex}";

                // Prepare data for insert
                $serialNumber = "ONBOARD-NIC-{$motherboardUuid}-{$onboardNicIndex}";
                $controller = $nicSpec['controller'] ?? 'Unknown';
                $ports = $nicSpec['ports'] ?? 1;
                $speed = $nicSpec['speed'] ?? 'Unknown';
                $connector = $nicSpec['connector'] ?? 'Unknown';
                $location = "Onboard NIC #{$onboardNicIndex} from Motherboard";
                $notes = "Auto-created onboard NIC from motherboard $motherboardUuid";

                // Insert into nicinventory table
                $insertNicStmt = $this->pdo->prepare("
                    INSERT INTO nicinventory
                    (UUID, SerialNumber, Status, SourceType, ParentComponentUUID, OnboardIndex,
                     Controller, Ports, Speed, Connector, Location, Notes, ServerUUID)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $insertNicStmt->execute([
                    $onboardNicUuid,
                    $serialNumber,
                    2, // In use
                    'onboard',
                    $motherboardUuid,
                    $onboardNicIndex,
                    $controller,
                    $ports,
                    $speed,
                    $connector,
                    $location,
                    $notes,
                    $configUuid
                ]);

                // Update nic_configuration JSON column
                $this->updateNicConfiguration($configUuid, $onboardNicUuid, 1, 'add');
            }

        } catch (Exception $e) {
            error_log("Error in createOnboardNICsFromMotherboard: " . $e->getMessage());
            // Don't throw - allow motherboard addition to succeed even if onboard NIC creation fails
        }
    }

    /**
     * Update configuration metrics (power, compatibility, validation)
     */
    private function updateConfigurationMetrics($configUuid) {
        try {
            $details = $this->getConfigurationSummary($configUuid);

            $totalPower = 0;
            foreach ($details['components'] ?? [] as $type => $components) {
                foreach ($components as $component) {
                    // Fetch component specs from JSON using UUID
                    $componentUuid = $component['uuid'] ?? null;
                    if (!$componentUuid) {
                        continue;
                    }

                    $power = $this->calculateComponentPowerFromJSON($type, $componentUuid);
                    $totalPower += $power * ($component['quantity'] ?? 1);
                }
            }

            $totalPowerWithOverhead = $totalPower * 1.2;

            // Calculate compatibility score
            $compatibilityScore = null;
            if (class_exists('CompatibilityEngine')) {
                try {
                    $compatibilityResult = $this->calculateHardwareCompatibilityScore($details);
                    $compatibilityScore = $compatibilityResult['score'];
                } catch (Exception $e) {
                    error_log("Error calculating compatibility: " . $e->getMessage());
                }
            }

            // Update the configuration
            $this->updateConfigurationCalculatedFields($configUuid, $totalPowerWithOverhead, $compatibilityScore);

        } catch (Exception $e) {
            error_log("Error updating configuration metrics: " . $e->getMessage());
        }
    }
    
    /**
     * Update calculated fields in configuration
     */
    private function updateConfigurationCalculatedFields($configUuid, $powerConsumption) {
        try {
            $sql = "UPDATE server_configurations SET power_consumption = ?, updated_at = NOW() WHERE config_uuid = ?";
            $params = [$powerConsumption, $configUuid];
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
        } catch (Exception $e) {
            error_log("Error updating calculated fields: " . $e->getMessage());
        }
    }
    
    /**
     * Update configuration with compatibility score and validation results
     */
    public function updateConfigurationValidation($configUuid, $validationResults = null) {
        try {
            $setParts = [];
            $params = [];
            
            if ($validationResults !== null) {
                $setParts[] = "validation_results = ?";
                $params[] = json_encode($validationResults);
            }
            
            if (!empty($setParts)) {
                $setParts[] = "updated_at = NOW()";
                $sql = "UPDATE server_configurations SET " . implode(', ', $setParts) . " WHERE config_uuid = ?";
                $params[] = $configUuid;
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                
            }
            
        } catch (Exception $e) {
            error_log("Error updating configuration validation: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get configuration summary - backwards compatibility
     */
    public function getConfigurationSummary($configUuid) {
        $details = $this->getConfigurationDetails($configUuid);
        
        // Return summary format for backwards compatibility
        return [
            'config_uuid' => $configUuid,
            'components' => $details['components'] ?? [],
            'component_counts' => $details['component_counts'] ?? [],
            'total_components' => $details['total_components'] ?? 0,
            'server_name' => $details['server_name'] ?? '',
            'configuration_status' => $details['configuration_status'] ?? 0,
            'error' => $details['error'] ?? null
        ];
    }
    
    /**
     * Validate server configuration with FIXED compatibility-based scoring
     */
    public function validateConfiguration($configUuid) {
        try {
            $summary = $this->getConfigurationSummary($configUuid);
            
            $validation = [
                'is_valid' => true,
                'overall_score' => 1.0, // Start with perfect score and deduct for issues
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'global_checks' => [],
                'component_summary' => [
                    'total_components' => $summary['total_components'] ?? 0,
                    'component_counts' => $summary['component_counts'] ?? []
                ]
            ];
            
            // Check for required components
            $requiredComponents = ['cpu', 'motherboard', 'ram'];
            $presentComponents = array_keys($summary['components'] ?? []);
            
            foreach ($requiredComponents as $required) {
                $isPresent = in_array($required, $presentComponents);
                $validation['global_checks'][] = [
                    'check' => ucfirst($required) . ' Required',
                    'passed' => $isPresent,
                    'message' => $isPresent ? ucfirst($required) . ' component is present' : ucfirst($required) . ' is required for server configuration'
                ];
                
                if (!$isPresent) {
                    $validation['is_valid'] = false;
                    $validation['issues'][] = "Missing required component: " . ucfirst($required);
                    $validation['overall_score'] -= 0.3; // Deduct 30% for each missing required component
                }
            }
            
            // Check recommended components
            $recommendedComponents = ['storage'];
            foreach ($recommendedComponents as $recommended) {
                $isPresent = in_array($recommended, $presentComponents);
                $validation['global_checks'][] = [
                    'check' => ucfirst($recommended) . ' Recommended',
                    'passed' => $isPresent,
                    'message' => $isPresent ? ucfirst($recommended) . ' component is present' : "At least one " . $recommended . " device is recommended"
                ];
                
                if (!$isPresent) {
                    $validation['warnings'][] = "Missing recommended component: " . ucfirst($recommended);
                    $validation['overall_score'] -= 0.1; // Deduct 10% for each missing recommended component
                }
            }
            
            // FIXED: Check for "in-use" warnings based on ServerUUID - only warn if in different config
            foreach ($summary['components'] ?? [] as $type => $components) {
                foreach ($components as $component) {
                    if (isset($component['details']['Status'])) {
                        $status = (int)$component['details']['Status'];
                        $serverUuid = $component['details']['ServerUUID'] ?? null;
                        
                        if ($status === 2) { // In Use
                            // Only show warning if component is in use in a DIFFERENT configuration
                            if ($serverUuid && $serverUuid !== $configUuid) {
                                $validation['warnings'][] = "Component {$component['component_uuid']} is in use in another configuration ($serverUuid)";
                            }
                            // If ServerUUID matches current config or is null, no warning needed
                        } elseif ($status === 0) {
                            $validation['issues'][] = "Component {$component['component_uuid']} is marked as failed/defective";
                        }
                    }
                }
            }
            
            // ENHANCED: Calculate compatibility score with detailed diagnostics
            $compatibilityResult = $this->calculateHardwareCompatibilityScore($summary);
            $compatibilityDiagnostics = $compatibilityResult['diagnostics'];

            // Add detailed compatibility diagnostics to recommendations
            if (!empty($compatibilityDiagnostics)) {
                $validation['recommendations'] = array_merge($validation['recommendations'], $compatibilityDiagnostics);
            }

            // Add general recommendations based on validation
            if (!$validation['is_valid']) {
                $validation['recommendations'][] = "Resolve all compatibility issues before finalizing";
            }

            // Ensure overall_score is within bounds
            $validation['overall_score'] = max(0.0, min(1.0, $validation['overall_score']));

            return $validation;
            
        } catch (Exception $e) {
            error_log("Error validating configuration: " . $e->getMessage());
            return [
                'is_valid' => false,
                'issues' => ['Validation failed due to system error: ' . $e->getMessage()],
                'warnings' => [],
                'recommendations' => [],
                'component_summary' => [
                    'total_components' => 0,
                    'component_counts' => []
                ]
            ];
        }
    }

    /**
     * Enhanced JSON-based configuration validation
     */
    public function validateConfigurationEnhanced($configUuid) {
        try {
            // Initialize compatibility engine
            require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);
            
            // Start with basic validation
            $basicValidation = $this->validateConfiguration($configUuid);

            // Get all components in configuration from JSON columns
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $configData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$configData) {
                return [
                    'is_valid' => false,
                    'issues' => ['Configuration not found'],
                    'warnings' => [],
                    'recommendations' => []
                ];
            }

            // Extract components from JSON columns
            $components = $this->extractComponentsFromJson($configData);
            
            $enhancedValidation = [
                'is_valid' => $basicValidation['is_valid'],
                'overall_score' => $basicValidation['overall_score'],
                'issues' => $basicValidation['issues'],
                'warnings' => $basicValidation['warnings'],
                'recommendations' => $basicValidation['recommendations'],
                'global_checks' => $basicValidation['global_checks'],
                'component_summary' => $basicValidation['component_summary'],
                'json_validation' => [
                    'enabled' => true,
                    'component_checks' => [],
                    'compatibility_matrix' => [],
                    'detailed_scores' => []
                ]
            ];
            
            // Phase 1: Validate all components exist in JSON
            foreach ($components as $component) {
                $existsResult = $compatibility->validateComponentExistsInJSON(
                    $component['component_type'],
                    $component['component_uuid']
                );

                $checkResult = [
                    'component_type' => $component['component_type'],
                    'component_uuid' => $component['component_uuid'],
                    'exists_in_json' => $existsResult,
                    'status' => $existsResult ? 'pass' : 'fail'
                ];

                if (!$existsResult) {
                    $enhancedValidation['is_valid'] = false;
                    $enhancedValidation['issues'][] = "Component {$component['component_uuid']} ({$component['component_type']}) not found in JSON specifications database";
                    $enhancedValidation['overall_score'] -= 0.2;
                }
                
                $enhancedValidation['json_validation']['component_checks'][] = $checkResult;
            }
            
            // Phase 2: Run comprehensive compatibility matrix
            $motherboardSpecs = $this->getConfigurationMotherboardSpecs($configUuid);
            
            if ($motherboardSpecs['found']) {
                $limits = $motherboardSpecs['limits'];
                
                // Check CPU compatibility
                foreach ($components as $component) {
                    if ($component['component_type'] === 'cpu') {
                        $socketResult = $compatibility->validateCPUSocketCompatibility(
                            $component['component_uuid'], 
                            $limits
                        );
                        
                        $matrixEntry = [
                            'component1_type' => 'motherboard',
                            'component2_type' => 'cpu',
                            'component2_uuid' => $component['component_uuid'],
                            'compatibility_check' => 'socket_compatibility',
                            'result' => $socketResult
                        ];
                        
                        if (!$socketResult['compatible']) {
                            $enhancedValidation['is_valid'] = false;
                            $enhancedValidation['issues'][] = $socketResult['error'];
                        }
                        
                        $enhancedValidation['json_validation']['compatibility_matrix'][] = $matrixEntry;
                    }
                }
                
                // Check RAM compatibility
                foreach ($components as $component) {
                    if ($component['component_type'] === 'ram') {
                        $typeResult = $compatibility->validateRAMTypeCompatibility(
                            $component['component_uuid'], 
                            $limits
                        );
                        
                        $slotResult = $compatibility->validateRAMSlotAvailability($configUuid, $limits);
                        
                        $matrixEntry = [
                            'component1_type' => 'motherboard',
                            'component2_type' => 'ram',
                            'component2_uuid' => $component['component_uuid'],
                            'compatibility_check' => 'type_and_slot_compatibility',
                            'result' => [
                                'type_compatible' => $typeResult['compatible'],
                                'slots_available' => $slotResult['available'],
                                'type_error' => $typeResult['error'],
                                'slot_error' => $slotResult['error']
                            ]
                        ];
                        
                        if (!$typeResult['compatible']) {
                            $enhancedValidation['is_valid'] = false;
                            $enhancedValidation['issues'][] = $typeResult['error'];
                        }
                        
                        if (!$slotResult['available']) {
                            $enhancedValidation['is_valid'] = false;
                            $enhancedValidation['issues'][] = $slotResult['error'];
                        }
                        
                        $enhancedValidation['json_validation']['compatibility_matrix'][] = $matrixEntry;
                    }
                }
                
                // Check NIC compatibility
                foreach ($components as $component) {
                    if ($component['component_type'] === 'nic') {
                        $nicResult = $compatibility->validateNICPCIeCompatibility(
                            $component['component_uuid'], 
                            $configUuid, 
                            $limits
                        );
                        
                        $matrixEntry = [
                            'component1_type' => 'motherboard',
                            'component2_type' => 'nic',
                            'component2_uuid' => $component['component_uuid'],
                            'compatibility_check' => 'pcie_compatibility',
                            'result' => $nicResult
                        ];
                        
                        if (!$nicResult['compatible']) {
                            $enhancedValidation['warnings'][] = $nicResult['error'];
                        }
                        
                        $enhancedValidation['json_validation']['compatibility_matrix'][] = $matrixEntry;
                    }
                }
            }
            
            // Phase 3: Generate detailed compatibility scores
            $enhancedValidation['json_validation']['detailed_scores'] = [
                'json_existence_score' => $this->calculateJSONExistenceScore($enhancedValidation['json_validation']['component_checks']),
                'compatibility_matrix_score' => $this->calculateCompatibilityMatrixScore($enhancedValidation['json_validation']['compatibility_matrix']),
                'overall_json_score' => min(
                    $this->calculateJSONExistenceScore($enhancedValidation['json_validation']['component_checks']),
                    $this->calculateCompatibilityMatrixScore($enhancedValidation['json_validation']['compatibility_matrix'])
                )
            ];
            
            // Adjust overall scores based on JSON validation
            $jsonScore = $enhancedValidation['json_validation']['detailed_scores']['overall_json_score'];
            $enhancedValidation['overall_score'] = min($enhancedValidation['overall_score'], $jsonScore / 100.0);

            if ($jsonScore < 70) {
                $enhancedValidation['is_valid'] = false;
                $enhancedValidation['issues'][] = "JSON-based compatibility validation failed (score: {$jsonScore}%)";
            }

            return $enhancedValidation;

        } catch (Exception $e) {
            error_log("Error in enhanced validation: " . $e->getMessage());
            return [
                'is_valid' => false,
                'overall_score' => 0.0,
                'issues' => ['Enhanced validation failed: ' . $e->getMessage()],
                'warnings' => [],
                'recommendations' => [],
                'json_validation' => [
                    'enabled' => false,
                    'error' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Calculate JSON existence score
     */
    private function calculateJSONExistenceScore($componentChecks) {
        if (empty($componentChecks)) {
            return 100;
        }
        
        $passCount = 0;
        foreach ($componentChecks as $check) {
            if ($check['status'] === 'pass') {
                $passCount++;
            }
        }
        
        return round(($passCount / count($componentChecks)) * 100, 1);
    }

    /**
     * Calculate compatibility matrix score
     */
    private function calculateCompatibilityMatrixScore($compatibilityMatrix) {
        if (empty($compatibilityMatrix)) {
            return 100;
        }
        
        $totalScore = 0;
        $totalChecks = count($compatibilityMatrix);
        
        foreach ($compatibilityMatrix as $check) {
            $checkScore = 100;
            
            if (isset($check['result']['compatible']) && !$check['result']['compatible']) {
                $checkScore = 0;
            } elseif (isset($check['result']['type_compatible']) && !$check['result']['type_compatible']) {
                $checkScore = 0;
            } elseif (isset($check['result']['slots_available']) && !$check['result']['slots_available']) {
                $checkScore = 50; // Slot availability issues are less critical than incompatibility
            }
            
            $totalScore += $checkScore;
        }
        
        return round($totalScore / $totalChecks, 1);
    }

    /**
     * Finalize configuration
     */
    public function finalizeConfiguration($configUuid, $notes = '') {
        try {
            // Validate configuration first
            $validation = $this->validateConfiguration($configUuid);
            if (!$validation['is_valid']) {
                return [
                    'success' => false,
                    'message' => "Configuration is not valid for finalization",
                    'validation_errors' => $validation['issues']
                ];
            }
            
            // Update configuration status
            $stmt = $this->pdo->prepare("
                UPDATE server_configurations 
                SET configuration_status = 3, notes = ?, updated_at = NOW()
                WHERE config_uuid = ?
            ");
            $stmt->execute([$notes, $configUuid]);
            
            // Log the finalization
            $this->logConfigurationAction($configUuid, 'finalize', 'configuration', null, [
                'notes' => $notes
            ]);

            return [
                'success' => true,
                'message' => "Configuration finalized successfully",
                'finalization_timestamp' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            error_log("Error finalizing configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to finalize configuration: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete configuration
     */
    public function deleteConfiguration($configUuid) {
        try {
            $this->pdo->beginTransaction();
            
            // Get all components in the configuration from JSON columns
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $configData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($configData) {
                $components = $this->extractComponentsFromJson($configData);

                // Release components back to available status and clear ServerUUID, installation date, and rack position
                foreach ($components as $component) {
                    $this->updateComponentStatusAndServerUuid(
                        $component['component_type'],
                        $component['component_uuid'],
                        1,
                        null,
                        "Released from deleted configuration $configUuid"
                    );
                }
            }

            // Note: Component data is now stored in JSON columns, no separate table to delete
            
            // Delete configuration history if exists
            try {
                $stmt = $this->pdo->prepare("DELETE FROM server_configuration_history WHERE config_uuid = ?");
                $stmt->execute([$configUuid]);
            } catch (Exception $historyError) {
                error_log("Could not delete history (table might not exist): " . $historyError->getMessage());
            }
            
            // Delete configuration
            $stmt = $this->pdo->prepare("DELETE FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => "Configuration deleted successfully",
                'components_released' => count($components)
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollback();
            error_log("Error deleting configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to delete configuration: " . $e->getMessage()
            ];
        }
    }
    
    // Private helper methods
    
    /**
     * Check if component UUID already exists in configuration
     * RACE CONDITION FIX: Now locks configuration row with FOR UPDATE
     *
     * IMPORTANT: This method MUST be called within a transaction (started in addComponent)
     * The FOR UPDATE lock prevents concurrent modifications to the configuration JSON
     *
     * @param string $configUuid Configuration UUID
     * @param string $componentUuid Component UUID to check
     * @param string|null $serialNumber Optional serial number for physical component identification
     * @return bool True if duplicate found, false otherwise
     */
    private function isDuplicateComponent($configUuid, $componentUuid, $serialNumber = null) {
        try {
            // RACE CONDITION FIX: Lock configuration row to prevent concurrent JSON updates
            // This REQUIRES being in a transaction (should be started in addComponent)
            // Lock configuration row with FOR UPDATE
            $stmt = $this->pdo->prepare("
                SELECT cpu_configuration, ram_configuration, storage_configuration,
                       caddy_configuration, nic_config, hbacard_uuid, hbacard_config, motherboard_uuid,
                       chassis_uuid, pciecard_configurations, sfp_configuration
                FROM server_configurations
                WHERE config_uuid = ?
                FOR UPDATE
            ");
            $stmt->execute([$configUuid]);
            $configData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$configData) {
                return false;
            }

            // Extract components and check for duplicate
            $components = $this->extractComponentsFromJson($configData);
            $isDuplicate = false;
            $componentType = null;

            foreach ($components as $comp) {
                // CRITICAL: Check both UUID and serial_number to identify specific physical component
                $isMatch = false;

                if ($serialNumber !== null) {
                    // When serial_number is provided, ONLY match if both UUID and serial_number match
                    // This allows multiple physical components with same UUID (model) but different serials
                    if (isset($comp['serial_number'])) {
                        $isMatch = ($comp['component_uuid'] === $componentUuid &&
                                   $comp['serial_number'] === $serialNumber);
                    }
                    // If existing component doesn't have serial_number, it's NOT a match
                    // (they're different physical components)
                } else {
                    // When adding without serial_number, only match if existing entry also has no serial
                    // (same-model components with serial numbers are different physical units)
                    if (!isset($comp['serial_number'])) {
                        $isMatch = ($comp['component_uuid'] === $componentUuid);
                    }
                }

                if ($isMatch) {
                    $isDuplicate = true;
                    $componentType = $comp['component_type'];
                    break;
                }
            }

            return $isDuplicate;

        } catch (PDOException $e) {
            error_log("Error in duplicate check: " . $e->getMessage());
            // On error, return true to prevent addition (fail-safe)
            return true;

        } catch (Exception $e) {
            error_log("Error checking duplicate component: " . $e->getMessage());
            // On error, return true to prevent addition (fail-safe)
            return true;
        }
    }
    
    /**
     * Validate CPU addition with socket and count limits
     */
    private function validateCPUAddition($configUuid, $cpuUuid, $compatibility) {
        try {
            // Get motherboard specifications
            $motherboardSpecs = $this->getConfigurationMotherboardSpecs($configUuid);
            if (!$motherboardSpecs['found']) {
                return [
                    'success' => false,
                    'message' => 'No motherboard found in configuration - add motherboard first'
                ];
            }
            
            $limits = $motherboardSpecs['limits'];

            // Check current CPU count from JSON column
            $stmt = $this->pdo->prepare("SELECT cpu_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $cpuData = $stmt->fetch(PDO::FETCH_ASSOC);

            $currentCPUCount = 0;
            if ($cpuData && !empty($cpuData['cpu_configuration'])) {
                try {
                    $cpuConfig = json_decode($cpuData['cpu_configuration'], true);
                    if (isset($cpuConfig['cpus']) && is_array($cpuConfig['cpus'])) {
                        $currentCPUCount = count($cpuConfig['cpus']);
                    }
                } catch (Exception $e) {
                    error_log("Error parsing CPU configuration: " . $e->getMessage());
                }
            }
            
            $maxSockets = $limits['cpu']['max_sockets'] ?? 1;
            
            // STRICT LIMIT ENFORCEMENT - reject if limit exceeded
            if ($currentCPUCount >= $maxSockets) {
                return [
                    'success' => false,
                    'message' => "CPU limit exceeded: motherboard supports maximum {$maxSockets} CPUs, currently has {$currentCPUCount} CPUs"
                ];
            }
            
            // Socket compatibility validation using JSON data
            $socketResult = $compatibility->validateCPUSocketCompatibility($cpuUuid, $limits);
            if (!$socketResult['compatible']) {
                return [
                    'success' => false,
                    'message' => $socketResult['error']
                ];
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log("Error validating CPU addition: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error validating CPU compatibility: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Comprehensive RAM validation before adding to configuration
     * Implements all critical compatibility checks as specified in Important-fix
     */
    private function validateRAMAddition($configUuid, $ramUuid, $compatibility) {
        try {
            // Task 1: JSON existence validation - RAM UUID must exist in JSON
            $ramValidation = $compatibility->validateRAMExistsInJSON($ramUuid);
            if (!$ramValidation['exists']) {
                return [
                    'success' => false,
                    'message' => 'RAM not found in specifications database',
                    'details' => ['error' => $ramValidation['error']]
                ];
            }

            $ramSpecs = $ramValidation['specifications'];

            // Get system component specifications for compatibility checking
            $motherboardResult = $this->getMotherboardSpecsFromConfig($configUuid);
            $cpuResult = $this->getCPUSpecsFromConfig($configUuid);

            $hasMB = $motherboardResult['found'];
            $hasCPU = $cpuResult['found'];

            // FLEXIBLE VALIDATION LOGIC:
            // 1. No MB and No CPU -> Allow all RAM (no compatibility checks needed)
            // 2. CPU exists but No MB -> Check CPU-RAM compatibility only
            // 3. MB exists -> Check MB-RAM (and CPU-RAM if CPU present) compatibility

            if (!$hasMB && !$hasCPU) {
                // Scenario 1: No motherboard, no CPU - allow any RAM
                return [
                    'success' => true,
                    'message' => 'RAM validated successfully (no compatibility constraints)',
                    'warnings' => ['No motherboard or CPU in configuration - compatibility will be validated when they are added'],
                    'compatibility_details' => [
                        'validation_mode' => 'no_constraints',
                        'note' => 'RAM can be added without compatibility checks until motherboard or CPU is added'
                    ]
                ];
            }

            $motherboardSpecs = $hasMB ? $motherboardResult['specifications'] : [];
            $cpuSpecs = $hasCPU ? $cpuResult['specifications'] : [];
            $warnings = [];

            if ($hasCPU && !$hasMB) {
                // Scenario 2: CPU exists but no motherboard - validate CPU-RAM compatibility only

                // Check memory type compatibility with CPU
                $typeCheck = $compatibility->validateMemoryTypeCompatibility($ramSpecs, [], $cpuSpecs);
                if (!$typeCheck['compatible']) {
                    return [
                        'success' => false,
                        'message' => $typeCheck['message'],
                        'details' => ['supported_types' => $typeCheck['supported_types']]
                    ];
                }

                // ECC compatibility with CPU
                $eccCheck = $compatibility->validateECCCompatibility($ramSpecs, [], $cpuSpecs);
                if (isset($eccCheck['warning'])) {
                    $warnings[] = $eccCheck['warning'];
                }
                if (isset($eccCheck['recommendation'])) {
                    $warnings[] = $eccCheck['recommendation'];
                }

                // Frequency analysis with CPU only
                $frequencyAnalysis = $compatibility->analyzeMemoryFrequency($ramSpecs, [], $cpuSpecs);
                if ($frequencyAnalysis['status'] === 'error') {
                    $warnings[] = $frequencyAnalysis['message'];
                } else {
                    if ($frequencyAnalysis['status'] === 'limited') {
                        $warnings[] = $frequencyAnalysis['message'];
                    } elseif ($frequencyAnalysis['status'] === 'suboptimal') {
                        $warnings[] = $frequencyAnalysis['message'];
                    }
                }

                $warnings[] = 'No motherboard in configuration - full validation will occur when motherboard is added';

                return [
                    'success' => true,
                    'message' => 'RAM compatible with CPU',
                    'warnings' => $warnings,
                    'compatibility_details' => [
                        'validation_mode' => 'cpu_only',
                        'memory_type' => $typeCheck,
                        'frequency_analysis' => $frequencyAnalysis,
                        'ecc_support' => $eccCheck
                    ]
                ];
            }

            // Scenario 3: Motherboard exists - perform full validation

            // Task 2: Memory type compatibility - DDR4/DDR5 matching
            $typeCheck = $compatibility->validateMemoryTypeCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs);
            if (!$typeCheck['compatible']) {
                return [
                    'success' => false,
                    'message' => $typeCheck['message'],
                    'details' => ['supported_types' => $typeCheck['supported_types']]
                ];
            }

            // Task 5: Memory slot limit validation
            $slotCheck = $compatibility->validateMemorySlotAvailability($configUuid, $motherboardSpecs);
            if (!$slotCheck['available']) {
                return [
                    'success' => false,
                    'message' => $slotCheck['error'],
                    'details' => [
                        'used_slots' => $slotCheck['used_slots'],
                        'total_slots' => $slotCheck['total_slots']
                    ]
                ];
            }

            // Task 4: Form factor validation - DIMM/SO-DIMM compatibility
            $formFactorCheck = $compatibility->validateMemoryFormFactor($ramSpecs, $motherboardSpecs);
            if (!$formFactorCheck['compatible']) {
                return [
                    'success' => false,
                    'message' => $formFactorCheck['message']
                ];
            }

            // Task 4: ECC compatibility validation
            $eccCheck = $compatibility->validateECCCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs);
            // Note: ECC compatibility issues are warnings, not blocking errors
            if (isset($eccCheck['warning'])) {
                $warnings[] = $eccCheck['warning'];
            }
            if (isset($eccCheck['recommendation'])) {
                $warnings[] = $eccCheck['recommendation'];
            }

            // Task 3: Advanced frequency analysis - complex performance logic
            $frequencyAnalysis = $compatibility->analyzeMemoryFrequency($ramSpecs, $motherboardSpecs, $cpuSpecs);
            if ($frequencyAnalysis['status'] === 'error') {
                $warnings[] = $frequencyAnalysis['message'];
            } else {
                // Add performance warnings for frequency limitations
                if ($frequencyAnalysis['status'] === 'limited') {
                    $warnings[] = $frequencyAnalysis['message'];
                } elseif ($frequencyAnalysis['status'] === 'suboptimal') {
                    $warnings[] = $frequencyAnalysis['message'];
                }
            }

            return [
                'success' => true,
                'message' => 'RAM compatibility validation passed',
                'warnings' => $warnings,
                'compatibility_details' => [
                    'validation_mode' => 'full',
                    'memory_type' => $typeCheck,
                    'frequency_analysis' => $frequencyAnalysis,
                    'form_factor' => $formFactorCheck,
                    'ecc_support' => $eccCheck,
                    'slot_availability' => $slotCheck
                ]
            ];

        } catch (Exception $e) {
            error_log("Error validating RAM addition: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error validating RAM compatibility: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Comprehensive component validation before adding - consolidates all validation logic
     * Phase 2 Consolidation: Moves SFP, riser, singleton, and compatibility validation from handler
     */
    public function validateComponentAddition($configUuid, $componentType, $componentUuid, $compatibility, $configData, $parentNicUuid = null, $portIndex = null) {
        try {
            $warnings = [];

            // ===== SINGLETON CHECKS: Motherboard and Chassis =====
            if ($componentType === 'motherboard') {
                // Check if configuration already has a motherboard
                if (!empty($configData['motherboard_uuid'])) {
                    return [
                        'success' => false,
                        'message' => 'Configuration already has a motherboard. Remove existing motherboard first.'
                    ];
                }
            }

            if ($componentType === 'chassis') {
                // Check if configuration already has a chassis
                if (!empty($configData['chassis_uuid'])) {
                    return [
                        'success' => false,
                        'message' => 'Configuration already has a chassis. Remove existing chassis first.'
                    ];
                }
            }

            // ===== SFP-SPECIFIC VALIDATION =====
            if ($componentType === 'sfp') {
                $sfpValidation = $this->validateSFPAddition($configData, $componentUuid, $parentNicUuid, $portIndex);
                if (!$sfpValidation['success']) {
                    return $sfpValidation;
                }
                if (!empty($sfpValidation['warnings'])) {
                    $warnings = array_merge($warnings, $sfpValidation['warnings']);
                }
            }

            // ===== RISER CARD VALIDATION =====
            if ($componentType === 'pciecard') {
                // Check if this is actually a Riser Card (not NVMe Adaptor or other PCIe device)
                require_once __DIR__ . '/../components/ComponentDataService.php';
                $componentDataService = ComponentDataService::getInstance();

                // Get component details from inventory to check specs
                $table = $this->getComponentInventoryTable($componentType);
                $stmt = $this->pdo->prepare("SELECT * FROM `$table` WHERE UUID = ? LIMIT 1");
                $stmt->execute([$componentUuid]);
                $componentDetails = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($componentDetails) {
                    $pcieCardSpecs = $componentDataService->getComponentSpecifications('pciecard', $componentUuid, $componentDetails);
                    $pcieComponentSubtype = $pcieCardSpecs['component_subtype'] ?? null;
                    $isActualRiserCard = ($pcieComponentSubtype === 'Riser Card') || (stripos($componentUuid, 'riser-') === 0);

                    // Only validate riser card ordering for actual riser cards
                    if ($isActualRiserCard) {
                        require_once __DIR__ . '/../components/ComponentValidator.php';
                        $componentValidator = new ComponentValidator($this->pdo);

                        $existingComponents = $this->extractComponentsFromJson($configData, true);
                        $riserValidation = $componentValidator->validateAddRiserCard(
                            ['uuid' => $componentUuid, 'type' => 'pcie_card'],
                            $existingComponents
                        );

                        if (!$riserValidation['valid']) {
                            return [
                                'success' => false,
                                'message' => "Cannot add riser card: " . ($riserValidation['error'] ?? 'Compatibility issue')
                            ];
                        }
                    }
                }
            }

            // ===== COMPONENT-SPECIFIC COMPATIBILITY CHECKS =====
            // Check both decentralized and pairwise compatibility
            $existingComponents = $this->extractComponentsFromJson($configData);

            if (!empty($existingComponents)) {
                // Build existing components data for compatibility checks
                $tableMap = [];
                foreach ($this->componentTables as $type => $table) {
                    $tableMap[$type] = $table;
                }

                // For decentralized checks, get full component data
                $existingComponentsData = [];
                foreach ($this->extractComponentsFromJson($configData, true) as $existing) {
                    $table = $tableMap[$existing['component_type']] ?? null;
                    if ($table) {
                        $stmt = $this->pdo->prepare("SELECT * FROM `$table` WHERE UUID = ? LIMIT 1");
                        $stmt->execute([$existing['component_uuid']]);
                        $componentData = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($componentData) {
                            $existingComponentsData[] = [
                                'type' => $existing['component_type'],
                                'uuid' => $existing['component_uuid'],
                                'data' => $componentData
                            ];
                        }
                    }
                }

                // Perform decentralized compatibility check based on component type
                $newComponent = ['uuid' => $componentUuid, 'type' => $componentType];

                // Add SFP-specific parameters if this is an SFP component
                if ($componentType === 'sfp') {
                    $newComponent['parent_nic_uuid'] = $parentNicUuid;
                    $newComponent['port_index'] = $portIndex;
                }

                $compatibilityResult = null;
                switch ($componentType) {
                    case 'cpu':
                        $compatibilityResult = $compatibility->checkCPUDecentralizedCompatibility($newComponent, $existingComponentsData);
                        break;
                    case 'motherboard':
                        $compatibilityResult = $compatibility->checkMotherboardDecentralizedCompatibility($newComponent, $existingComponentsData);
                        break;
                    case 'ram':
                        $compatibilityResult = $compatibility->checkRAMDecentralizedCompatibility($newComponent, $existingComponentsData);
                        break;
                    case 'storage':
                        $compatibilityResult = $compatibility->checkStorageDecentralizedCompatibility($newComponent, $existingComponentsData);
                        break;
                    case 'chassis':
                        $compatibilityResult = $compatibility->checkChassisDecentralizedCompatibility($newComponent, $existingComponentsData);
                        break;
                    case 'nic':
                    case 'pciecard':
                        $compatibilityResult = $compatibility->checkPCIeDecentralizedCompatibility($newComponent, $existingComponentsData, $componentType);
                        break;
                    case 'hbacard':
                        $compatibilityResult = $compatibility->checkHBADecentralizedCompatibility($newComponent, $existingComponentsData);
                        break;
                    case 'sfp':
                        $compatibilityResult = $compatibility->checkSFPDecentralizedCompatibility($newComponent, $existingComponentsData);
                        break;
                    case 'caddy':
                        $compatibilityResult = $compatibility->checkCaddyDecentralizedCompatibility($newComponent, $existingComponentsData);
                        break;
                    default:
                        $compatibilityResult = ['compatible' => true, 'warnings' => [], 'recommendations' => []];
                }

                // Check if component is incompatible
                if ($compatibilityResult && !$compatibilityResult['compatible']) {
                    $errorDetails = array_merge(
                        $compatibilityResult['details'] ?? [],
                        $compatibilityResult['issues'] ?? []
                    );

                    return [
                        'success' => false,
                        'message' => "Cannot add component due to compatibility issues: " .
                                   ($compatibilityResult['compatibility_summary'] ?? 'Incompatible with existing components'),
                        'details' => $errorDetails,
                        'recommendations' => $compatibilityResult['recommendations'] ?? []
                    ];
                }

                // Store warnings for response
                if ($compatibilityResult) {
                    $warnings = array_merge($warnings, $compatibilityResult['warnings'] ?? []);
                }
            }

            return [
                'success' => true,
                'message' => 'Component validation passed',
                'warnings' => $warnings
            ];

        } catch (Exception $e) {
            error_log("Error in component validation: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return [
                'success' => false,
                'message' => 'Error validating component: ' . $e->getMessage()
            ];
        }
    }

    /**
     * SFP-specific validation logic
     * Phase 2 Consolidation: Moved from server_api.php handleAddComponent()
     */
    private function validateSFPAddition($configData, $componentUuid, $parentNicUuid = null, $portIndex = null) {
        try {
            require_once __DIR__ . '/../compatibility/NICPortTracker.php';
            require_once __DIR__ . '/../components/ComponentDataService.php';

            $portTracker = new NICPortTracker($this->pdo);
            $componentDataService = ComponentDataService::getInstance();

            // Get config UUID for tracking
            $configUuid = null;
            if (!empty($configData) && is_array($configData)) {
                $configUuid = $configData['config_uuid'] ?? null;
            }

            if (!$configUuid) {
                return ['success' => false, 'message' => 'Invalid configuration'];
            }

            // 1. Validate parent_nic_uuid and port_index are provided
            if (empty($parentNicUuid)) {
                return ['success' => false, 'message' => 'Parent NIC UUID is required for SFP modules'];
            }
            if ($portIndex === null || $portIndex === '') {
                return ['success' => false, 'message' => 'Port index is required for SFP modules'];
            }

            // 2. Check if parent NIC exists in configuration
            $existingComponents = $this->extractComponentsFromJson($configData, true);
            $nicExists = false;
            foreach ($existingComponents as $comp) {
                if ($comp['component_type'] === 'nic' && $comp['component_uuid'] === $parentNicUuid) {
                    $nicExists = true;
                    break;
                }
            }

            if (!$nicExists) {
                return [
                    'success' => false,
                    'message' => "Parent NIC $parentNicUuid not found in configuration. Add the NIC first before assigning SFP modules."
                ];
            }

            // 3. Check if port is available
            if (!$portTracker->isPortAvailable($configUuid, $parentNicUuid, $portIndex)) {
                return [
                    'success' => false,
                    'message' => "Port {$portIndex} on NIC {$parentNicUuid} is already occupied. Choose a different port or remove the existing SFP first."
                ];
            }

            // 4. Validate SFP type compatibility with NIC port type
            $nicSpecs = $componentDataService->getComponentSpecifications('nic', $parentNicUuid);
            $sfpSpecs = $componentDataService->getComponentSpecifications('sfp', $componentUuid);

            if (!$nicSpecs || !isset($nicSpecs['port_type'])) {
                // Fallback for onboard NICs: get port_type from NICPortTracker
                $portInfo = $portTracker->getPortAvailability($configUuid, $parentNicUuid);
                if (!empty($portInfo['port_type']) && $portInfo['port_type'] !== 'unknown') {
                    $nicSpecs = ['port_type' => $portInfo['port_type'], 'model' => 'Onboard NIC'];
                } else {
                    return [
                        'success' => false,
                        'message' => "Unable to load NIC specifications for {$parentNicUuid}"
                    ];
                }
            }

            if (!$sfpSpecs || !isset($sfpSpecs['type'])) {
                return [
                    'success' => false,
                    'message' => "Unable to load SFP specifications for {$componentUuid}"
                ];
            }

            $nicPortType = $nicSpecs['port_type'];
            $sfpType = $sfpSpecs['type'];

            if (!NICPortTracker::isCompatible($nicPortType, $sfpType)) {
                return [
                    'success' => false,
                    'message' => "SFP module type '{$sfpType}' is incompatible with NIC port type '{$nicPortType}'. " .
                                "This NIC has {$nicPortType} ports which cannot accept {$sfpType} modules."
                ];
            }

            return ['success' => true];

        } catch (Exception $e) {
            error_log("Error validating SFP: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'SFP validation error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate component inventory status with override support
     * Moved from handleAddComponent() early validation
     *
     * @return array ['valid' => bool, 'message' => string, 'override_used' => bool, 'status_code' => int]
     */
    private function validateComponentInventoryStatus(
        $componentType,
        $componentUuid,
        $configUuid,
        $componentDetails,
        $overrideUsed = false
    ) {
        $componentStatus = (int)($componentDetails['Status'] ?? -1);
        $componentServerUuid = $componentDetails['ServerUUID'] ?? null;
        $isAvailable = false;
        $statusMessage = '';

        switch ($componentStatus) {
            case 0:
                $statusMessage = "Component is marked as Failed/Defective";
                break;
            case 1:
                $isAvailable = true;
                $statusMessage = "Component is Available";
                break;
            case 2:
                if ($componentServerUuid === $configUuid) {
                    $isAvailable = true;
                    $statusMessage = "Component is already assigned to this configuration";
                } else {
                    if ($overrideUsed) {
                        $isAvailable = true;
                        $statusMessage = $componentServerUuid ?
                            "Component is In Use in configuration $componentServerUuid (override enabled)" :
                            "Component is In Use (override enabled)";
                    } else {
                        $statusMessage = $componentServerUuid ?
                            "Component is currently In Use in configuration: $componentServerUuid" :
                            "Component is currently In Use";
                    }
                }
                break;
            default:
                $statusMessage = "Component has unknown status: $componentStatus";
        }

        return [
            'valid' => $isAvailable || $overrideUsed,
            'message' => $statusMessage,
            'override_used' => $isAvailable && $overrideUsed,
            'status_code' => $componentStatus
        ];
    }

    /**
     * Assign expansion slot for PCIe cards, NICs, HBA cards
     * Moved from handleAddComponent() lines 503-600
     *
     * Handles:
     * - Size-aware riser slot assignment
     * - PCIe slot assignment with size matching
     * - Fallback to legacy assignment
     *
     * @param string $configUuid
     * @param string $componentType One of: 'pciecard', 'nic', 'hbacard'
     * @param string $componentUuid
     * @param array $componentSpecs Specs from ComponentDataService
     * @param string|null $manualSlotPosition User-provided slot (optional override)
     * @return array ['success' => bool, 'slot_id' => string|null, 'message' => string]
     */
    private function assignComponentSlot(
        $configUuid,
        $componentType,
        $componentUuid,
        $componentSpecs,
        $manualSlotPosition = null
    ) {
        try {
            // Only relevant for PCIe-capable components
            if (!in_array($componentType, ['pciecard', 'nic', 'hbacard'])) {
                return [
                    'success' => true,
                    'slot_id' => null,
                    'message' => 'Component type does not require slot assignment'
                ];
            }

            // Initialize UnifiedSlotTracker (already required at top of file)
            $slotTracker = new UnifiedSlotTracker($this->pdo);

            // Check if motherboard exists in configuration
            $stmt = $this->pdo->prepare("SELECT motherboard_uuid FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $configResult = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$configResult || empty($configResult['motherboard_uuid'])) {
                // No motherboard - cannot assign slots
                return [
                    'success' => true,
                    'slot_id' => null,
                    'message' => 'No motherboard in configuration - slot assignment skipped'
                ];
            }

            // Get component specifications (already provided as parameter, use it directly)
            if (!$componentSpecs || empty($componentSpecs)) {
                return [
                    'success' => true,
                    'slot_id' => null,
                    'message' => 'Component specs not found - slot assignment skipped'
                ];
            }

            // Check component subtype to determine if riser or regular PCIe card
            $componentSubtype = $componentSpecs['component_subtype'] ?? null;
            $isRiserCard = false;

            if ($componentSubtype === 'Riser Card') {
                $isRiserCard = true;
            } elseif (stripos($componentUuid, 'riser-') === 0) {
                // Fallback: UUID starts with "riser-"
                $isRiserCard = true;
            }

            // Extract PCIe slot size from specs
            $slotSize = $this->extractPCIeSlotSize($componentSpecs);

            if ($isRiserCard) {
                // ===== RISER CARD - MUST GO TO RISER SLOTS ONLY =====
                if ($slotSize) {
                    // Use size-aware assignment (returns string slot ID)
                    $assignedSlot = $slotTracker->assignRiserSlotBySize($configUuid, $slotSize);
                    if ($assignedSlot) {
                        return [
                            'success' => true,
                            'slot_id' => $assignedSlot,
                            'message' => "Riser card assigned to slot $assignedSlot"
                        ];
                    } else {
                        return [
                            'success' => false,
                            'slot_id' => null,
                            'message' => "Cannot add riser card: No compatible riser slots available on motherboard",
                            'required_slot_type' => $slotSize,
                            'error_code' => 'no_riser_slots_available'
                        ];
                    }
                } else {
                    // Fallback to legacy assignment if size cannot be determined
                    $assignedSlot = $slotTracker->assignRiserSlot($configUuid);
                    if ($assignedSlot) {
                        return [
                            'success' => true,
                            'slot_id' => $assignedSlot,
                            'message' => "Riser card assigned to slot $assignedSlot (size-aware assignment unavailable)"
                        ];
                    } else {
                        return [
                            'success' => false,
                            'slot_id' => null,
                            'message' => "Cannot add riser card: No riser slots available on motherboard",
                            'error_code' => 'no_riser_slots_available'
                        ];
                    }
                }
            } else {
                // ===== REGULAR PCIe CARD/NIC - ASSIGN TO PCIe SLOTS =====
                if ($slotSize) {
                    // Assign optimal PCIe slot
                    $assignedSlot = $slotTracker->assignSlot($configUuid, $slotSize);
                    if ($assignedSlot) {
                        return [
                            'success' => true,
                            'slot_id' => $assignedSlot,
                            'message' => "PCIe card assigned to slot $assignedSlot"
                        ];
                    } else {
                        return [
                            'success' => true,
                            'slot_id' => null,
                            'message' => 'No suitable PCIe slots available - component added without slot assignment'
                        ];
                    }
                } else {
                    return [
                        'success' => true,
                        'slot_id' => null,
                        'message' => 'Could not determine PCIe slot size - component added without slot assignment'
                    ];
                }
            }

        } catch (Exception $slotError) {
            error_log("Slot assignment error: " . $slotError->getMessage());
            return [
                'success' => true,
                'slot_id' => null,
                'message' => 'Slot assignment unavailable - component added without slot assignment'
            ];
        }
    }

    /**
     * Helper: Extract PCIe slot size from component specs
     * Returns string like 'x16', 'x8', 'x4', 'x1' or null
     */
    private function extractPCIeSlotSize($specs) {
        // Check interface field (most common)
        $interface = $specs['interface'] ?? '';
        if (preg_match('/x(\d+)/i', $interface, $matches)) {
            return 'x' . $matches[1];
        }

        // Check slot_type field
        $slotType = $specs['slot_type'] ?? '';
        if (preg_match('/x(\d+)/i', $slotType, $matches)) {
            return 'x' . $matches[1];
        }

        // Check pcie_interface field
        $pcieInterface = $specs['pcie_interface'] ?? '';
        if (preg_match('/x(\d+)/i', $pcieInterface, $matches)) {
            return 'x' . $matches[1];
        }

        return null;
    }

    /**
     * Generate UUID for configuration
     */
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Check if component type is valid
     */
    private function isValidComponentType($componentType) {
        return isset($this->componentTables[$componentType]);
    }
    
    /**
     * Check if component can only have single instance in configuration
     */
    private function isSingleInstanceComponent($componentType) {
        return in_array($componentType, ['chassis', 'motherboard']);
    }

    /**
     * Validate component addition order
     */
    private function validateComponentCompatibility($configUuid, $componentType, $componentUuid) {
        try {
            // Get existing components in the configuration from JSON columns
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $configData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$configData) {
                return ['success' => true, 'message' => 'Configuration not found'];
            }

            $existingComponents = $this->extractComponentsFromJson($configData);

            // If no existing components, allow any component to be added first
            if (empty($existingComponents)) {
                return ['success' => true, 'message' => 'First component can be any type'];
            }

            // Special handling for chassis - only one chassis allowed per configuration
            if ($componentType === 'chassis') {
                $hasChasis = false;
                foreach ($existingComponents as $existing) {
                    if ($existing['component_type'] === 'chassis') {
                        $hasChasis = true;
                        break;
                    }
                }
                if ($hasChasis) {
                    return [
                        'success' => false,
                        'message' => 'Chassis already exists in this configuration. Remove existing chassis first.'
                    ];
                }
                return ['success' => true, 'message' => 'Chassis can be added'];
            }

            // Check compatibility with each existing component
            require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);

            $newComponent = [
                'type' => $componentType,
                'uuid' => $componentUuid
            ];

            // Caddies are configuration-scoped accessories, not components that must
            // be pairwise compatible with every installed storage device. Use the same
            // decentralized chassis-aware validation already used by the API pre-check.
            if ($componentType === 'caddy') {
                $existingComponentsData = array_map(function($existing) {
                    return [
                        'type' => $existing['component_type'],
                        'uuid' => $existing['component_uuid']
                    ];
                }, $existingComponents);

                $compatResult = $compatibility->checkCaddyDecentralizedCompatibility($newComponent, $existingComponentsData);
                if (!$compatResult['compatible']) {
                    $issues = array_values(array_filter(array_merge(
                        $compatResult['issues'] ?? [],
                        isset($compatResult['compatibility_summary']) ? [$compatResult['compatibility_summary']] : []
                    )));

                    return [
                        'success' => false,
                        'message' => "Component $componentUuid is not compatible with existing configuration. " .
                                   implode(', ', $issues)
                    ];
                }

                return ['success' => true, 'message' => 'Component is compatible with existing configuration'];
            }

            foreach ($existingComponents as $existing) {
                $existingComponent = [
                    'type' => $existing['component_type'],
                    'uuid' => $existing['component_uuid']
                ];

                // Skip NIC-SFP pairwise checks — SFPs are validated against their parent NIC
                // via checkSFPDecentralizedCompatibility when added. Existing SFPs belong to
                // other NICs and should not block adding a new NIC.
                $pairKey = [$componentType, $existing['component_type']];
                sort($pairKey);
                if ($pairKey[0] === 'nic' && $pairKey[1] === 'sfp') {
                    continue;
                }

                // Check compatibility between new component and each existing one
                $compatResult = $compatibility->checkComponentPairCompatibility($newComponent, $existingComponent);

                // If any incompatibility found, reject the addition
                if (!$compatResult['compatible']) {
                    return [
                        'success' => false,
                        'message' => "Component $componentUuid is not compatible with existing " .
                                   $existing['component_type'] . " (" . $existing['component_uuid'] . "). " .
                                   implode(', ', $compatResult['issues'] ?? [])
                    ];
                }
            }

            return ['success' => true, 'message' => 'Component is compatible with existing configuration'];

        } catch (Exception $e) {
            error_log("Error validating component compatibility: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error validating component compatibility: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get component by UUID with improved error handling
     */
    private function getComponentByUuid($componentType, $componentUuid) {
        if (!isset($this->componentTables[$componentType])) {
            error_log("Invalid component type: $componentType");
            return null;
        }

        try {
            $table = $this->componentTables[$componentType];

            // CRITICAL FIX: Prioritize available components (Status=1) when multiple components share same UUID
            // This ensures we select an available component instead of a random one

            // Step 1: Try to get an available component (Status=1) first
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ? AND Status = 1 LIMIT 1");
            $stmt->execute([$componentUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return $result;
            }

            // Step 2: If no available component, try case-insensitive match with Status=1
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE TRIM(UPPER(UUID)) = UPPER(TRIM(?)) AND Status = 1 LIMIT 1");
            $stmt->execute([$componentUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return $result;
            }

            // Step 3: Fallback - get any component with this UUID for validation/error messages
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ? LIMIT 1");
            $stmt->execute([$componentUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return $result;
            }

            // Step 4: Final fallback - case-insensitive any status
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE TRIM(UPPER(UUID)) = UPPER(TRIM(?)) LIMIT 1");
            $stmt->execute([$componentUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result;

        } catch (Exception $e) {
            error_log("Error getting component by UUID from {$this->componentTables[$componentType]}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get component by UUID and optionally by SerialNumber
     * When SerialNumber is provided, returns that specific physical component
     * When SerialNumber is null, falls back to getComponentByUuid logic
     */
    private function getComponentByUuidAndSerial($componentType, $componentUuid, $serialNumber = null) {
        if (!isset($this->componentTables[$componentType])) {
            error_log("Invalid component type: $componentType");
            return null;
        }

        try {
            $table = $this->componentTables[$componentType];

            // If serial number is provided, get that specific component
            if ($serialNumber !== null) {
                $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ? AND SerialNumber = ? LIMIT 1");
                $stmt->execute([$componentUuid, $serialNumber]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                return $result ?: null;
            }

            // If no serial number provided, fall back to original logic
            return $this->getComponentByUuid($componentType, $componentUuid);

        } catch (Exception $e) {
            error_log("Error in getComponentByUuidAndSerial: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a server configuration is virtual/test mode
     */
    private function isVirtualConfig($configUuid) {
        try {
            $stmt = $this->pdo->prepare("SELECT is_virtual FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (bool)$result['is_virtual'] : false;
        } catch (Exception $e) {
            error_log("Error checking is_virtual: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all components from a server configuration (all JSON columns)
     * Used for importing virtual configs to real configs
     */
    public function getConfigComponents($configUuid) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    cpu_configuration, ram_configuration, storage_configuration,
                    caddy_configuration, nic_config, pciecard_configurations,
                    hbacard_uuid, hbacard_config, sfp_configuration, motherboard_uuid, chassis_uuid
                FROM server_configurations
                WHERE config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                return null;
            }

            $components = [];

            // Parse CPU configuration
            if (!empty($config['cpu_configuration'])) {
                $cpus = json_decode($config['cpu_configuration'], true);
                if (isset($cpus['cpus']) && is_array($cpus['cpus'])) {
                    foreach ($cpus['cpus'] as $cpu) {
                        $components[] = [
                            'component_type' => 'cpu',
                            'uuid' => $cpu['uuid'],
                            'quantity' => $cpu['quantity'] ?? 1,
                            'serial_number' => $cpu['serial_number'] ?? null
                        ];
                    }
                }
            }

            // Parse RAM configuration
            if (!empty($config['ram_configuration'])) {
                $rams = json_decode($config['ram_configuration'], true);
                if (is_array($rams)) {
                    foreach ($rams as $ram) {
                        $components[] = [
                            'component_type' => 'ram',
                            'uuid' => $ram['uuid'],
                            'quantity' => $ram['quantity'] ?? 1,
                            'serial_number' => $ram['serial_number'] ?? null
                        ];
                    }
                }
            }

            // Parse Storage configuration
            if (!empty($config['storage_configuration'])) {
                $storages = json_decode($config['storage_configuration'], true);
                if (is_array($storages)) {
                    foreach ($storages as $storage) {
                        $components[] = [
                            'component_type' => 'storage',
                            'uuid' => $storage['uuid'],
                            'quantity' => $storage['quantity'] ?? 1,
                            'serial_number' => $storage['serial_number'] ?? null
                        ];
                    }
                }
            }

            // Parse Caddy configuration
            if (!empty($config['caddy_configuration'])) {
                $caddies = json_decode($config['caddy_configuration'], true);
                if (is_array($caddies)) {
                    foreach ($caddies as $caddy) {
                        $components[] = [
                            'component_type' => 'caddy',
                            'uuid' => $caddy['uuid'],
                            'quantity' => $caddy['quantity'] ?? 1,
                            'serial_number' => $caddy['serial_number'] ?? null
                        ];
                    }
                }
            }

            // Parse PCIe Card configuration
            if (!empty($config['pciecard_configurations'])) {
                $pciCards = json_decode($config['pciecard_configurations'], true);
                if (is_array($pciCards)) {
                    foreach ($pciCards as $card) {
                        $components[] = [
                            'component_type' => 'pciecard',
                            'uuid' => $card['uuid'],
                            'quantity' => $card['quantity'] ?? 1,
                            'serial_number' => $card['serial_number'] ?? null,
                            'slot_position' => $card['slot_position'] ?? null
                        ];
                    }
                }
            }

            // Parse HBA Cards (JSON array, with legacy fallback)
            if (!empty($config['hbacard_config'])) {
                $hbaConfigs = json_decode($config['hbacard_config'], true);
                if (is_array($hbaConfigs)) {
                    if (isset($hbaConfigs['uuid'])) {
                        $hbaConfigs = [$hbaConfigs]; // Single object → array
                    }
                    foreach ($hbaConfigs as $hba) {
                        $components[] = [
                            'component_type' => 'hbacard',
                            'uuid' => $hba['uuid'] ?? null,
                            'quantity' => 1,
                            'serial_number' => $hba['serial_number'] ?? null,
                            'slot_position' => $hba['slot_position'] ?? null
                        ];
                    }
                }
            } elseif (!empty($config['hbacard_uuid'])) {
                $components[] = [
                    'component_type' => 'hbacard',
                    'uuid' => $config['hbacard_uuid'],
                    'quantity' => 1
                ];
            }

            // Parse Motherboard (single instance)
            if (!empty($config['motherboard_uuid'])) {
                $components[] = [
                    'component_type' => 'motherboard',
                    'uuid' => $config['motherboard_uuid'],
                    'quantity' => 1
                ];
            }

            // Parse Chassis (single instance)
            if (!empty($config['chassis_uuid'])) {
                $components[] = [
                    'component_type' => 'chassis',
                    'uuid' => $config['chassis_uuid'],
                    'quantity' => 1
                ];
            }

            // Parse NIC configuration (complex structure)
            if (!empty($config['nic_config'])) {
                $nicConfig = json_decode($config['nic_config'], true);
                if (isset($nicConfig['nics']) && is_array($nicConfig['nics'])) {
                    foreach ($nicConfig['nics'] as $nic) {
                        // Only add component NICs, not onboard NICs
                        if (isset($nic['source_type']) && $nic['source_type'] === 'component') {
                            $components[] = [
                                'component_type' => 'nic',
                                'uuid' => $nic['uuid'],
                                'quantity' => 1,
                                'serial_number' => $nic['serial_number'] ?? null
                            ];
                        }
                    }
                }
            }

            return $components;

        } catch (Exception $e) {
            error_log("Error getting config components: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find first available component with matching UUID (Status=1)
     * Returns component details or null if not found
     */
    public function findAvailableComponent($componentType, $uuid) {
        try {
            $tableName = $this->getComponentInventoryTable($componentType);
            if (!$tableName) {
                return null;
            }

            $stmt = $this->pdo->prepare("
                SELECT * FROM $tableName
                WHERE UUID = ? AND Status = 1
                LIMIT 1
            ");
            $stmt->execute([$uuid]);
            $component = $stmt->fetch(PDO::FETCH_ASSOC);

            return $component ?: null;

        } catch (Exception $e) {
            error_log("Error finding available component: " . $e->getMessage());
            return null;
        }
    }

    /**
     * FIXED: Check component availability with ServerUUID context
     * UPDATED: Bypass availability check for virtual configs
     */
    private function checkComponentAvailability($componentDetails, $configUuid, $options = []) {
        // Virtual configs don't need availability checks
        if ($this->isVirtualConfig($configUuid)) {
            return [
                'available' => true,
                'status' => $componentDetails['Status'] ?? null,
                'server_uuid' => $componentDetails['ServerUUID'] ?? null,
                'message' => 'Virtual configuration - availability checks bypassed',
                'can_override' => false,
                'is_virtual' => true
            ];
        }

        $status = (int)$componentDetails['Status'];
        $serverUuid = $componentDetails['ServerUUID'] ?? null;
        
        $result = [
            'available' => false,
            'status' => $status,
            'server_uuid' => $serverUuid,
            'message' => '',
            'can_override' => false
        ];
        
        switch ($status) {
            case 0:
                $result['message'] = "Component is marked as Failed/Defective";
                $result['can_override'] = false;
                break;
            case 1:
                $result['available'] = true;
                $result['message'] = "Component is Available";
                break;
            case 2:
                if ($serverUuid === $configUuid) {
                    $result['available'] = true;
                    $result['message'] = "Component is already assigned to this configuration";
                } elseif ($serverUuid) {
                    $result['message'] = "Component is currently in use in configuration: $serverUuid";
                    $result['can_override'] = true;
                } else {
                    $result['message'] = "Component is currently In Use";
                    $result['can_override'] = true;
                }
                break;
            default:
                $result['message'] = "Component has unknown status: $status";
                $result['can_override'] = false;
        }
        
        return $result;
    }
    
    /**
     * Get configuration component by type from JSON columns
     */
    private function getConfigurationComponent($configUuid, $componentType) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $configData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$configData) {
                return null;
            }

            // Extract components and find the one matching the type
            $components = $this->extractComponentsFromJson($configData);
            foreach ($components as $component) {
                if ($component['component_type'] === $componentType) {
                    return $component;
                }
            }

            return null;
        } catch (Exception $e) {
            error_log("Error getting configuration component: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update component status, ServerUUID, location, rack position, and installation date
     * CRITICAL: Now requires $serialNumber to update only the specific physical component
     */
    private function updateComponentStatusAndServerUuid($componentType, $componentUuid, $newStatus, $serverUuid, $reason = '', $serverLocation = null, $serverRackPosition = null, $serialNumber = null) {
        if (!isset($this->componentTables[$componentType])) {
            error_log("Cannot update status - invalid component type: $componentType");
            return false;
        }

        try {
            $table = $this->componentTables[$componentType];

            // Build WHERE clause - MUST include SerialNumber if provided to target specific physical component
            $whereClause = "WHERE UUID = ?";
            $whereParams = [$componentUuid];

            if ($serialNumber !== null) {
                $whereClause .= " AND SerialNumber = ?";
                $whereParams[] = $serialNumber;
            }

            // Get current status first for logging
            $stmt = $this->pdo->prepare("SELECT Status, ServerUUID, Location, RackPosition, InstallationDate, SerialNumber FROM $table $whereClause");
            $stmt->execute($whereParams);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($current === false) {
                $serialInfo = $serialNumber ? " with SerialNumber '$serialNumber'" : "";
                error_log("Cannot update status - component not found: $componentUuid$serialInfo in $table");
                return false;
            }

            // Prepare update fields and values
            $updateFields = ["Status = ?", "ServerUUID = ?", "UpdatedAt = NOW()"];
            $updateValues = [$newStatus, $serverUuid];

            // Handle installation date
            if ($newStatus == 2 && $serverUuid !== null) {
                // Component is being assigned to a server - set installation date to current timestamp
                $updateFields[] = "InstallationDate = CURDATE()";
            } elseif ($newStatus == 1 && $serverUuid === null) {
                // Component is being released from server - clear installation date
                $updateFields[] = "InstallationDate = NULL";
            }

            // Handle location and rack position updates
            if ($newStatus == 2 && $serverUuid !== null) {
                // Component is being assigned to a server - always update location and rack position
                $updateFields[] = "Location = ?";
                $updateValues[] = $serverLocation; // This can be null if server has no location

                $updateFields[] = "RackPosition = ?";
                $updateValues[] = $serverRackPosition; // This can be null if server has no rack position

            } elseif ($newStatus == 1 && $serverUuid === null) {
                // Component is being released from server - clear rack position but keep location
                $updateFields[] = "RackPosition = NULL";
                // We don't clear location as component still exists in physical location
            }

            // Add WHERE parameters to update values
            $updateValues = array_merge($updateValues, $whereParams);

            // Execute update with SerialNumber constraint
            $sql = "UPDATE $table SET " . implode(', ', $updateFields) . " $whereClause";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($updateValues);

            if ($result) {
                $locationInfo = "";
                if ($serverLocation !== null || $serverRackPosition !== null) {
                    $locationInfo = " Location: '$serverLocation', RackPosition: '$serverRackPosition'";
                }
                $serialInfo = " SerialNumber: '{$current['SerialNumber']}'";
            }

            return $result;

        } catch (Exception $e) {
            error_log("Error updating component assignment: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get component IDs and UUIDs for detailed response
     */
    private function getComponentIdsAndUuids($components) {
        $idsUuids = [];
        
        foreach ($components as $component) {
            $type = $component['component_type'];
            if (!isset($idsUuids[$type])) {
                $idsUuids[$type] = [];
            }
            
            $idsUuids[$type][] = [
                'component_uuid' => $component['component_uuid'],
                'quantity' => $component['quantity'],
                'slot_position' => $component['slot_position'],
                'notes' => $component['notes'],
                'added_at' => $component['added_at']
            ];
        }
        
        return $idsUuids;
    }

    /**
     * RACE CONDITION FIX: Lock component row and retrieve details atomically
     * Uses SELECT ... FOR UPDATE to prevent race conditions during component addition
     *
     * @param string $componentType Component type (cpu, motherboard, etc.)
     * @param string $componentUuid Component UUID
     * @param string|null $serialNumber Optional serial number for multi-component UUIDs
     * @return array ['found' => bool, 'data' => array|null, 'error' => string|null]
     */
    private function lockAndCheckComponent($componentType, $componentUuid, $serialNumber = null) {
        try {
            $table = $this->getComponentInventoryTable($componentType);
            if (!$table) {
                return [
                    'found' => false,
                    'data' => null,
                    'error' => "Invalid component type: $componentType"
                ];
            }

            // CRITICAL: Use FOR UPDATE to lock the row and prevent race conditions
            if ($serialNumber !== null) {
                // Lock specific physical component by UUID + SerialNumber
                $stmt = $this->pdo->prepare("
                    SELECT UUID, SerialNumber, Status, ServerUUID, Location, RackPosition
                    FROM `$table`
                    WHERE UUID = ? AND SerialNumber = ?
                    FOR UPDATE
                ");
                $stmt->execute([$componentUuid, $serialNumber]);
            } else {
                // Lock by UUID only, preferring available (Status=1) rows first.
                // This ensures a second available unit is picked for multi-socket configurations
                // rather than re-fetching the already-in-use unit.
                $stmt = $this->pdo->prepare("
                    SELECT UUID, SerialNumber, Status, ServerUUID, Location, RackPosition
                    FROM `$table`
                    WHERE UUID = ?
                    ORDER BY Status ASC
                    FOR UPDATE
                ");
                $stmt->execute([$componentUuid]);
            }

            $component = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$component) {
                $serialInfo = $serialNumber ? " with SerialNumber '$serialNumber'" : "";
                return [
                    'found' => false,
                    'data' => null,
                    'error' => "Component not found in inventory: $componentUuid$serialInfo"
                ];
            }

            return [
                'found' => true,
                'data' => $component,
                'error' => null
            ];

        } catch (PDOException $e) {
            error_log("Error locking component: " . $e->getMessage());
            return [
                'found' => false,
                'data' => null,
                'error' => "Database error: " . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate component power consumption from JSON specifications
     */
    private function calculateComponentPowerFromJSON($componentType, $componentUuid) {
        // Default power estimates by component type (watts)
        $defaultPower = [
            'cpu' => 150,
            'ram' => 8,
            'storage' => 15,
            'motherboard' => 50,
            'nic' => 25,
            'caddy' => 5,
            'pciecard' => 30,
            'hbacard' => 20,
            'chassis' => 0,  // Chassis doesn't consume power directly
            'sfp' => 2  // SFP modules: typically 1-3W
        ];

        try {
            // Fetch component specs from JSON files
            $specs = null;
            switch ($componentType) {
                case 'cpu':
                    $specs = $this->dataUtils->getCPUByUUID($componentUuid);
                    if ($specs && isset($specs['tdp_W'])) {
                        return (int)$specs['tdp_W'];
                    }
                    break;

                case 'ram':
                    $specs = $this->dataUtils->getRAMByUUID($componentUuid);
                    // RAM power consumption is typically 3-8W per module
                    // DDR5 consumes more than DDR4
                    if ($specs) {
                        $type = strtolower($specs['memory_type'] ?? '');
                        if (strpos($type, 'ddr5') !== false) {
                            return 8; // DDR5: ~8W per module
                        } elseif (strpos($type, 'ddr4') !== false) {
                            return 5; // DDR4: ~5W per module
                        } elseif (strpos($type, 'ddr3') !== false) {
                            return 4; // DDR3: ~4W per module
                        }
                    }
                    return 8; // Default DDR5

                case 'storage':
                    $specs = $this->dataUtils->getStorageByUUID($componentUuid);
                    if ($specs) {
                        $interface = strtolower($specs['interface'] ?? '');
                        $formFactor = strtolower($specs['form_factor'] ?? '');

                        // NVMe M.2: 5-10W active, 3-5W idle (average 7W)
                        if (strpos($interface, 'nvme') !== false && strpos($formFactor, 'm.2') !== false) {
                            return 7;
                        }
                        // NVMe U.2: 10-15W active, 5-8W idle (average 10W)
                        elseif (strpos($interface, 'nvme') !== false && strpos($formFactor, 'u.2') !== false) {
                            return 10;
                        }
                        // SAS HDD: 10-12W active, 6-8W idle (average 10W)
                        elseif (strpos($interface, 'sas') !== false) {
                            return 10;
                        }
                        // SATA SSD: 2-5W active, 1-2W idle (average 3W)
                        elseif (strpos($interface, 'sata') !== false && strpos($formFactor, 'ssd') !== false) {
                            return 3;
                        }
                        // SATA HDD: 6-10W active, 4-6W idle (average 8W)
                        elseif (strpos($interface, 'sata') !== false) {
                            return 8;
                        }
                    }
                    return 15; // Default

                case 'motherboard':
                    // Motherboards typically consume 40-80W depending on complexity
                    return 60; // Average estimate

                case 'nic':
                    $specs = $this->dataUtils->getNICByUUID($componentUuid);
                    if ($specs) {
                        // JSON has power as string like "8W", parse numeric value
                        if (isset($specs['power']) && is_string($specs['power'])) {
                            return (int)$specs['power'];
                        }
                        // Fallback: estimate from speeds array
                        $speeds = $specs['speeds'] ?? [];
                        $speedStr = implode(' ', $speeds);
                        if (strpos($speedStr, '25GbE') !== false || strpos($speedStr, '25G') !== false) {
                            return 30;
                        } elseif (strpos($speedStr, '10GbE') !== false || strpos($speedStr, '10G') !== false) {
                            return 25;
                        } elseif (strpos($speedStr, '1GbE') !== false || strpos($speedStr, '1G') !== false) {
                            return 8;
                        }
                    }
                    return 25; // Default

                case 'pciecard':
                    $specs = $this->dataUtils->getPCIeCardByUUID($componentUuid);
                    if ($specs && isset($specs['power_consumption']['typical_W'])) {
                        return (int)$specs['power_consumption']['typical_W'];
                    }
                    // Estimate based on card type
                    $cardType = strtolower($specs['type'] ?? '');
                    if (strpos($cardType, 'gpu') !== false) {
                        return 75; // Mid-range GPU
                    } elseif (strpos($cardType, 'raid') !== false) {
                        return 25; // RAID controllers
                    }
                    return 30; // Default PCIe card

                case 'hbacard':
                    $specs = $this->dataUtils->getHBACardByUUID($componentUuid);
                    if ($specs && isset($specs['power_consumption']['typical_W'])) {
                        return (int)$specs['power_consumption']['typical_W'];
                    }
                    return 20; // Default HBA card power

                case 'sfp':
                    $specs = $this->dataUtils->getSFPByUUID($componentUuid);
                    if ($specs && isset($specs['power_consumption']) && is_string($specs['power_consumption'])) {
                        return (int)$specs['power_consumption']; // e.g. "1.5W" -> 1
                    }
                    return 2; // Default SFP power

                case 'caddy':
                    return 0; // Caddies don't consume power

                case 'chassis':
                    return 0; // Chassis doesn't consume power (fans calculated separately)
            }

        } catch (Exception $e) {
            error_log("Error calculating power for $componentType ($componentUuid): " . $e->getMessage());
        }

        // Return default if unable to calculate
        return $defaultPower[$componentType] ?? 50;
    }

    /**
     * DEPRECATED: Calculate component power consumption from database Notes field
     * Kept for backwards compatibility but replaced by calculateComponentPowerFromJSON
     */
    private function calculateComponentPower($componentType, $componentDetails) {
        // Default power estimates by component type (watts)
        $defaultPower = [
            'cpu' => 150,
            'ram' => 8,
            'storage' => 15,
            'motherboard' => 50,
            'nic' => 25,
            'caddy' => 5
        ];

        $basePower = $defaultPower[$componentType] ?? 50;

        try {
            // Try to extract power from Notes field or component specifications
            $notes = $componentDetails['Notes'] ?? '';

            // Look for power consumption patterns in notes
            if (preg_match('/(\d+)\s*W(?:att)?s?/i', $notes, $matches)) {
                return (int)$matches[1];
            }

            // Look for TDP patterns
            if (preg_match('/TDP[:\s]*(\d+)\s*W/i', $notes, $matches)) {
                return (int)$matches[1];
            }

            // Component-specific power calculation
            switch ($componentType) {
                case 'cpu':
                    // Try to extract core count and frequency for better estimation
                    if (preg_match('/(\d+)-core/i', $notes, $matches)) {
                        $cores = (int)$matches[1];
                        $basePower = min(300, $cores * 2.5); // Rough estimate: 2.5W per core, max 300W
                    }
                    break;

                case 'ram':
                    // Try to extract memory size
                    if (preg_match('/(\d+)\s*GB/i', $notes, $matches)) {
                        $size = (int)$matches[1];
                        $basePower = max(4, min(16, $size / 4)); // Rough: 1W per 4GB, min 4W, max 16W
                    }
                    break;
                    
                case 'storage':
                    // SSDs generally consume less power than HDDs
                    if (stripos($notes, 'SSD') !== false || stripos($notes, 'NVMe') !== false) {
                        $basePower = 8;
                    } elseif (stripos($notes, 'HDD') !== false) {
                        $basePower = 12;
                    }
                    break;
            }
            
        } catch (Exception $e) {
            error_log("Error calculating power for component: " . $e->getMessage());
        }
        
        return $basePower;
    }
    
    /**
     * ENHANCED: Calculate hardware compatibility score with detailed diagnostics
     */
    private function calculateHardwareCompatibilityScore($summary) {
        $score = 100.0;
        $components = $summary['components'] ?? [];
        $diagnostics = [];
        
        try {
            // If we don't have basic components, score is low
            if (empty($components)) {
                return ['score' => 0.0, 'diagnostics' => ['No components found in configuration']];
            }
            
            $motherboard = null;
            $cpus = [];
            $rams = [];
            
            // Extract key components
            if (isset($components['motherboard']) && !empty($components['motherboard'])) {
                $motherboard = $components['motherboard'][0]['details'] ?? null;
            }
            
            if (isset($components['cpu'])) {
                foreach ($components['cpu'] as $cpu) {
                    if (isset($cpu['details'])) {
                        $cpus[] = $cpu['details'];
                    }
                }
            }
            
            if (isset($components['ram'])) {
                foreach ($components['ram'] as $ram) {
                    if (isset($ram['details'])) {
                        $rams[] = $ram['details'];
                    }
                }
            }
            
            // Check motherboard-CPU compatibility
            if ($motherboard && !empty($cpus)) {
                $cpuResult = $this->checkMotherboardCpuCompatibilityDetailed($motherboard, $cpus);
                $score = min($score, $cpuResult['score']);
                if (!empty($cpuResult['issues'])) {
                    $diagnostics = array_merge($diagnostics, $cpuResult['issues']);
                }
            }
            
            // Check motherboard-RAM compatibility
            if ($motherboard && !empty($rams)) {
                $ramResult = $this->checkMotherboardRamCompatibilityDetailed($motherboard, $rams);
                $score = min($score, $ramResult['score']);
                if (!empty($ramResult['issues'])) {
                    $diagnostics = array_merge($diagnostics, $ramResult['issues']);
                }
            }
            
            // Check power requirements vs motherboard capacity
            $powerResult = $this->checkPowerCompatibilityDetailed($components);
            $score = min($score, $powerResult['score']);
            if (!empty($powerResult['issues'])) {
                $diagnostics = array_merge($diagnostics, $powerResult['issues']);
            }
            
            // Check form factor compatibility
            $formFactorResult = $this->checkFormFactorCompatibilityDetailed($components);
            $score = min($score, $formFactorResult['score']);
            if (!empty($formFactorResult['issues'])) {
                $diagnostics = array_merge($diagnostics, $formFactorResult['issues']);
            }
            
        } catch (Exception $e) {
            error_log("Error calculating hardware compatibility score: " . $e->getMessage());
            $score = 50.0;
            $diagnostics[] = "Error during compatibility analysis: " . $e->getMessage();
        }
        
        return [
            'score' => round($score, 1),
            'diagnostics' => $diagnostics
        ];
    }
    
    /**
     * Check motherboard-CPU socket compatibility with detailed diagnostics
     */
    private function checkMotherboardCpuCompatibilityDetailed($motherboard, $cpus) {
        $score = 100.0;
        $issues = [];
        
        try {
            $mbNotes = strtolower($motherboard['Notes'] ?? '');
            $mbSerialNumber = $motherboard['SerialNumber'] ?? 'Unknown';
            
            // Extract motherboard socket type
            $mbSocket = $this->extractSocketType($mbNotes);
            
            foreach ($cpus as $cpu) {
                $cpuNotes = strtolower($cpu['Notes'] ?? '');
                $cpuSerialNumber = $cpu['SerialNumber'] ?? 'Unknown';
                $cpuSocket = $this->extractSocketType($cpuNotes);
                
                if ($mbSocket && $cpuSocket) {
                    if ($mbSocket !== $cpuSocket) {
                        $score = 0.0; // Complete incompatibility
                        $issues[] = "Critical: CPU socket mismatch - Motherboard ($mbSerialNumber) has $mbSocket socket, but CPU ($cpuSerialNumber) requires $cpuSocket socket";
                        break;
                    }
                } else {
                    // If we can't determine socket types, reduce score but don't fail completely
                    $score = min($score, 70.0);
                    if (!$mbSocket && !$cpuSocket) {
                        $issues[] = "Warning: Cannot determine socket compatibility for Motherboard ($mbSerialNumber) and CPU ($cpuSerialNumber) - socket information missing from component specifications";
                    } elseif (!$mbSocket) {
                        $issues[] = "Warning: Cannot determine motherboard socket type for ($mbSerialNumber) - missing socket specification";
                    } else {
                        $issues[] = "Warning: Cannot determine CPU socket type for ($cpuSerialNumber) - missing socket specification";
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("Error checking motherboard-CPU compatibility: " . $e->getMessage());
            $score = 50.0;
            $issues[] = "Error: Failed to analyze CPU-Motherboard compatibility - " . $e->getMessage();
        }
        
        return [
            'score' => $score,
            'issues' => $issues
        ];
    }
    
    /**
     * Check motherboard-RAM compatibility with detailed diagnostics
     */
    private function checkMotherboardRamCompatibilityDetailed($motherboard, $rams) {
        $score = 100.0;
        $issues = [];
        
        try {
            $mbNotes = strtolower($motherboard['Notes'] ?? '');
            $mbSerialNumber = $motherboard['SerialNumber'] ?? 'Unknown';
            
            // Extract motherboard supported RAM types
            $mbMemoryTypes = $this->extractMemoryTypes($mbNotes);
            
            foreach ($rams as $ram) {
                $ramNotes = strtolower($ram['Notes'] ?? '');
                $ramSerialNumber = $ram['SerialNumber'] ?? 'Unknown';
                $ramType = $this->extractMemoryType($ramNotes);
                
                if (!empty($mbMemoryTypes) && $ramType) {
                    if (!in_array($ramType, $mbMemoryTypes)) {
                        $score = min($score, 10.0); // Major incompatibility
                        $issues[] = "Critical: Memory type incompatibility - Motherboard ($mbSerialNumber) supports " . implode(', ', $mbMemoryTypes) . ", but RAM ($ramSerialNumber) is $ramType";
                    }
                } else {
                    // If we can't determine memory types, reduce score slightly
                    $score = min($score, 80.0);
                    if (empty($mbMemoryTypes) && !$ramType) {
                        $issues[] = "Warning: Cannot determine memory compatibility for Motherboard ($mbSerialNumber) and RAM ($ramSerialNumber) - memory type specifications missing";
                    } elseif (empty($mbMemoryTypes)) {
                        $issues[] = "Warning: Cannot determine supported memory types for Motherboard ($mbSerialNumber) - specification missing";
                    } else {
                        $issues[] = "Warning: Cannot determine memory type for RAM ($ramSerialNumber) - specification missing";
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("Error checking motherboard-RAM compatibility: " . $e->getMessage());
            $score = 60.0;
            $issues[] = "Error: Failed to analyze RAM-Motherboard compatibility - " . $e->getMessage();
        }
        
        return [
            'score' => $score,
            'issues' => $issues
        ];
    }
    
    /**
     * Check power compatibility (legacy method)
     */
    private function checkPowerCompatibility($components) {
        $result = $this->checkPowerCompatibilityDetailed($components);
        return $result['score'];
    }
    
    /**
     * Check power compatibility with detailed diagnostics
     */
    private function checkPowerCompatibilityDetailed($components) {
        $score = 100.0;
        $issues = [];
        
        try {
            $totalPower = 0;
            $componentPowerBreakdown = [];
            
            foreach ($components as $type => $typeComponents) {
                $typePower = 0;
                foreach ($typeComponents as $component) {
                    $details = $component['details'] ?? [];
                    $power = $this->calculateComponentPower($type, $details);
                    $quantity = $component['quantity'] ?? 1;
                    $typePower += $power * $quantity;
                    $totalPower += $power * $quantity;
                }
                if ($typePower > 0) {
                    $componentPowerBreakdown[$type] = $typePower;
                }
            }
            
            // Check if total power is reasonable (not too high for typical motherboard)
            if ($totalPower > 1000) { // Very high power consumption
                $score = 30.0;
                $issues[] = "Critical: Very high power consumption ({$totalPower}W) - may exceed typical PSU capacity and cause system instability";
                $issues[] = "Power breakdown: " . $this->formatPowerBreakdown($componentPowerBreakdown);
            } elseif ($totalPower > 750) {
                $score = 60.0;
                $issues[] = "Warning: High power consumption ({$totalPower}W) - ensure adequate PSU capacity (recommended 850W+ PSU)";
                $issues[] = "Power breakdown: " . $this->formatPowerBreakdown($componentPowerBreakdown);
            } elseif ($totalPower > 500) {
                $score = 85.0;
                $issues[] = "Note: Moderate power consumption ({$totalPower}W) - ensure PSU capacity is at least 650W";
            }
            
        } catch (Exception $e) {
            error_log("Error checking power compatibility: " . $e->getMessage());
            $score = 75.0;
            $issues[] = "Error: Failed to analyze power compatibility - " . $e->getMessage();
        }
        
        return [
            'score' => $score,
            'issues' => $issues
        ];
    }
    
    /**
     * Check form factor compatibility (legacy method)
     */
    private function checkFormFactorCompatibility($components) {
        $result = $this->checkFormFactorCompatibilityDetailed($components);
        return $result['score'];
    }
    
    /**
     * Check form factor compatibility with detailed diagnostics
     */
    private function checkFormFactorCompatibilityDetailed($components) {
        $score = 100.0;
        $issues = [];
        
        try {
            // Check memory slot constraints
            if (isset($components['motherboard']) && isset($components['ram'])) {
                $motherboard = $components['motherboard'][0]['details'] ?? null;
                $mbSerialNumber = $motherboard['SerialNumber'] ?? 'Unknown';
                $ramCount = count($components['ram']);
                
                // Estimate memory slots based on motherboard type or use default
                $estimatedSlots = $this->estimateMemorySlots($motherboard);
                
                if ($ramCount > $estimatedSlots) {
                    $score = 20.0;
                    $issues[] = "Critical: Memory slot overflow - trying to install $ramCount RAM modules but motherboard ($mbSerialNumber) likely has only $estimatedSlots slots";
                } elseif ($ramCount > 8) {
                    $score = 40.0;
                    $issues[] = "Warning: Very high RAM module count ($ramCount) - verify motherboard ($mbSerialNumber) supports this many modules";
                } elseif ($ramCount > 6) {
                    $score = 70.0;
                    $issues[] = "Note: High RAM module count ($ramCount) - ensure motherboard ($mbSerialNumber) has sufficient slots";
                }
            }
            
            // Check storage interface constraints
            if (isset($components['motherboard']) && isset($components['storage'])) {
                $storageCount = count($components['storage']);
                $motherboard = $components['motherboard'][0]['details'] ?? null;
                $mbSerialNumber = $motherboard['SerialNumber'] ?? 'Unknown';
                
                if ($storageCount > 8) {
                    $score = min($score, 60.0);
                    $issues[] = "Warning: Very high storage device count ($storageCount) - ensure motherboard ($mbSerialNumber) has sufficient SATA/NVMe ports";
                } elseif ($storageCount > 6) {
                    $score = min($score, 80.0);
                    $issues[] = "Note: High storage device count ($storageCount) - verify sufficient ports on motherboard ($mbSerialNumber)";
                }
            }
            
        } catch (Exception $e) {
            error_log("Error checking form factor compatibility: " . $e->getMessage());
            $score = 85.0;
            $issues[] = "Error: Failed to analyze form factor compatibility - " . $e->getMessage();
        }
        
        return [
            'score' => $score,
            'issues' => $issues
        ];
    }
    
    /**
     * Format power breakdown for display
     */
    private function formatPowerBreakdown($powerBreakdown) {
        $formatted = [];
        foreach ($powerBreakdown as $type => $power) {
            $formatted[] = ucfirst($type) . ": {$power}W";
        }
        return implode(', ', $formatted);
    }
    
    /**
     * Estimate memory slots based on motherboard specifications
     */
    private function estimateMemorySlots($motherboard) {
        if (!$motherboard) {
            return 4; // Default assumption
        }
        
        $notes = strtolower($motherboard['Notes'] ?? '');
        
        // Try to extract memory slot count from notes
        if (preg_match('/(\d+)\s*(dimm|memory)\s*slot/i', $notes, $matches)) {
            return (int)$matches[1];
        }
        
        // Check for server/workstation indicators that typically have more slots
        if (strpos($notes, 'server') !== false || strpos($notes, 'workstation') !== false) {
            return 8; // Server motherboards typically have 8+ slots
        }
        
        // Check for high-end desktop indicators
        if (strpos($notes, 'x99') !== false || strpos($notes, 'x299') !== false || strpos($notes, 'trx40') !== false) {
            return 8; // HEDT platforms typically have 8 slots
        }
        
        return 4; // Standard desktop assumption
    }
    
    /**
     * Extract socket type from component notes with enhanced component knowledge base
     */
    private function extractSocketType($notes) {
        $notes = strtolower($notes);
        
        // Component knowledge base for common server components
        $componentSocketMap = [
            // Intel Xeon CPUs
            'platinum 8480+' => 'lga4677',
            'platinum 8480' => 'lga4677',
            'platinum 8470' => 'lga4677',
            'platinum 8460' => 'lga4677',
            'platinum 8450' => 'lga4677',
            'gold 6430' => 'lga4677',
            'gold 6420' => 'lga4677',
            'gold 6410' => 'lga4677',
            'silver 4410' => 'lga4677',
            'bronze 3408' => 'lga4677',
            'xeon 8' => 'lga4677', // Generic 4th gen Xeon pattern
            
            // AMD EPYC CPUs
            'epyc 9534' => 'sp5',
            'epyc 9554' => 'sp5',
            'epyc 9634' => 'sp5',
            'epyc 9654' => 'sp5',
            'epyc 64-core' => 'sp5', // Generic EPYC pattern
            
            // Motherboard models
            'x13dri-n' => 'lga4677',
            'x13dpi-n' => 'lga4677',
            'x12dpi-nt6' => 'lga4189',
            'x12dpi-n6' => 'lga4189',
            'h12dsi-n6' => 'sp3',
            'h12ssl-i' => 'sp3',
            'mz93-fs0' => 'sp5',
            'z790 godlike' => 'lga1700',
            'z790' => 'lga1700',
            'b650' => 'am5',
        ];
        
        // Check component knowledge base first
        foreach ($componentSocketMap as $component => $socket) {
            if (strpos($notes, $component) !== false) {
                return $socket;
            }
        }
        
        // Fallback to socket pattern matching
        $commonSockets = [
            'lga4677', 'lga4189', 'lga3647', 'lga2066', 'lga2011',
            'lga1700', 'lga1200', 'lga1151', 'lga1150', 'lga1155', 'lga1156',
            'sp5', 'sp3', 'sp4', 'am5', 'am4', 'tr4', 'strx4',
            'socket 4677', 'socket 4189', 'socket 3647', 'socket 2066', 'socket 2011',
            'socket 1700', 'socket 1200', 'socket 1151', 'socket 1150',
            'socket am5', 'socket am4', 'socket sp5', 'socket sp3'
        ];
        
        foreach ($commonSockets as $socket) {
            if (strpos($notes, $socket) !== false) {
                // Normalize socket name
                $socket = str_replace('socket ', '', $socket);
                return $socket;
            }
        }
        
        return null;
    }
    
    /**
     * Extract memory types from motherboard notes
     */
    private function extractMemoryTypes($notes) {
        $types = [];
        
        if (strpos($notes, 'ddr5') !== false) {
            $types[] = 'ddr5';
        }
        if (strpos($notes, 'ddr4') !== false) {
            $types[] = 'ddr4';
        }
        if (strpos($notes, 'ddr3') !== false) {
            $types[] = 'ddr3';
        }
        
        return $types;
    }
    
    /**
     * Extract memory type from RAM notes
     */
    private function extractMemoryType($notes) {
        if (strpos($notes, 'ddr5') !== false) {
            return 'ddr5';
        }
        if (strpos($notes, 'ddr4') !== false) {
            return 'ddr4';
        }
        if (strpos($notes, 'ddr3') !== false) {
            return 'ddr3';
        }
        
        return null;
    }
    
    /**
     * Log configuration action
     */
    private function logConfigurationAction($configUuid, $action, $componentType = null, $componentUuid = null, $metadata = null) {
        try {
            // Check if history table exists
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'server_configuration_history'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                // Create history table if it doesn't exist
                $this->createHistoryTable();
            } else {
                // Table exists, ensure it has all required columns
                $this->ensureHistoryTableColumns();
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO server_configuration_history 
                (config_uuid, action, component_type, component_uuid, metadata, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $configUuid, 
                $action, 
                $componentType, 
                $componentUuid, 
                json_encode($metadata)
            ]);
        } catch (Exception $e) {
            error_log("Error logging configuration action: " . $e->getMessage());
        }
    }
    
    /**
     * Create history table if it doesn't exist
     */
    private function createHistoryTable() {
        try {
            $sql = "
                CREATE TABLE IF NOT EXISTS server_configuration_history (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    config_uuid varchar(36) NOT NULL,
                    action varchar(50) NOT NULL COMMENT 'created, updated, component_added, component_removed, validated, etc.',
                    component_type varchar(20) DEFAULT NULL,
                    component_uuid varchar(36) DEFAULT NULL,
                    metadata text DEFAULT NULL COMMENT 'JSON metadata for the action',
                    created_at timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (id),
                    KEY idx_config_uuid (config_uuid),
                    KEY idx_component_uuid (component_uuid),
                    KEY idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $this->pdo->exec($sql);
            error_log("Created server_configuration_history table");
        } catch (Exception $e) {
            error_log("Error creating history table: " . $e->getMessage());
        }
    }

    /**
     * Ensure server_configuration_history table has all required columns
     */
    private function ensureHistoryTableColumns() {
        try {
            // Check if component_type column exists
            $stmt = $this->pdo->query("SHOW COLUMNS FROM server_configuration_history LIKE 'component_type'");
            if (!$stmt->fetch()) {
                $this->pdo->exec("ALTER TABLE server_configuration_history ADD COLUMN component_type varchar(20) DEFAULT NULL AFTER action");
                error_log("Added component_type column to server_configuration_history");
            }

            // Check if component_uuid column exists
            $stmt = $this->pdo->query("SHOW COLUMNS FROM server_configuration_history LIKE 'component_uuid'");
            if (!$stmt->fetch()) {
                $this->pdo->exec("ALTER TABLE server_configuration_history ADD COLUMN component_uuid varchar(36) DEFAULT NULL AFTER component_type");
                error_log("Added component_uuid column to server_configuration_history");
            }
        } catch (Exception $e) {
            error_log("Error ensuring history table columns: " . $e->getMessage());
        }
    }

    /**
     * Add component to additional_components JSON field
     */
    private function addToAdditionalComponents($configUuid, $componentType, $componentUuid) {
        try {
            $stmt = $this->pdo->prepare("SELECT additional_components FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentComponents = $stmt->fetchColumn();
            
            $additionalComponents = $currentComponents ? json_decode($currentComponents, true) : [];
            if (!is_array($additionalComponents)) {
                $additionalComponents = [];
            }
            
            // Initialize component type array if not exists
            if (!isset($additionalComponents[$componentType])) {
                $additionalComponents[$componentType] = [];
            }
            
            // Add the component UUID
            $additionalComponents[$componentType][] = [
                'uuid' => $componentUuid,
                'added_at' => date('Y-m-d H:i:s')
            ];
            
            $stmt = $this->pdo->prepare("UPDATE server_configurations SET additional_components = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($additionalComponents), $configUuid]);
            
        } catch (Exception $e) {
            error_log("Error adding to additional components: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Remove component from additional_components JSON field
     */
    private function removeFromAdditionalComponents($configUuid, $componentType, $componentUuid) {
        try {
            $stmt = $this->pdo->prepare("SELECT additional_components FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentComponents = $stmt->fetchColumn();
            
            $additionalComponents = $currentComponents ? json_decode($currentComponents, true) : [];
            if (!is_array($additionalComponents)) {
                $additionalComponents = [];
            }
            
            // Remove the component UUID if it exists
            if (isset($additionalComponents[$componentType])) {
                $additionalComponents[$componentType] = array_filter(
                    $additionalComponents[$componentType], 
                    function($component) use ($componentUuid) {
                        return $component['uuid'] !== $componentUuid;
                    }
                );
                
                // Reindex array
                $additionalComponents[$componentType] = array_values($additionalComponents[$componentType]);
                
                // Remove empty component type arrays
                if (empty($additionalComponents[$componentType])) {
                    unset($additionalComponents[$componentType]);
                }
            }
            
            $stmt = $this->pdo->prepare("UPDATE server_configurations SET additional_components = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($additionalComponents), $configUuid]);
            
        } catch (Exception $e) {
            error_log("Error removing from additional components: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get configuration motherboard specifications
     */
    public function getConfigurationMotherboardSpecs($configUuid) {
        try {
            // Get motherboard UUID from configuration
            $stmt = $this->pdo->prepare("SELECT motherboard_uuid FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result || empty($result['motherboard_uuid'])) {
                return [
                    'found' => false,
                    'error' => 'No motherboard found in configuration',
                    'specifications' => null
                ];
            }

            $motherboard = ['component_uuid' => $result['motherboard_uuid']];
            
            // Initialize compatibility engine
            require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);
            
            // Get motherboard limits
            return $compatibility->getMotherboardLimits($motherboard['component_uuid']);
            
        } catch (Exception $e) {
            error_log("Error getting motherboard specs: " . $e->getMessage());
            return [
                'found' => false,
                'error' => 'Error retrieving motherboard specifications: ' . $e->getMessage(),
                'specifications' => null
            ];
        }
    }


    /**
     * Get motherboard specifications from configuration
     * Returns structured motherboard specs for compatibility checking
     */
    public function getMotherboardSpecsFromConfig($configUuid) {
        try {
            // Get motherboard UUID from configuration
            $stmt = $this->pdo->prepare("SELECT motherboard_uuid FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result || !$result['motherboard_uuid']) {
                return [
                    'found' => false,
                    'error' => 'No motherboard found in configuration',
                    'specifications' => null
                ];
            }

            $motherboardUuid = $result['motherboard_uuid'];
            
            // Load motherboard specifications using ComponentCompatibility
            require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);
            
            return $compatibility->parseMotherboardSpecifications($motherboardUuid);
            
        } catch (Exception $e) {
            error_log("Error getting motherboard specs from config: " . $e->getMessage());
            return [
                'found' => false,
                'error' => 'Error loading motherboard specifications: ' . $e->getMessage(),
                'specifications' => null
            ];
        }
    }

    /**
     * Get CPU specifications from configuration
     * Returns array of CPU specs with UUIDs for compatibility checking
     */
    public function getCPUSpecsFromConfig($configUuid) {
        try {
            // Get all CPU UUIDs from configuration JSON
            $stmt = $this->pdo->prepare("SELECT cpu_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $configData = $stmt->fetch(PDO::FETCH_ASSOC);

            $cpuResults = [];
            if ($configData && !empty($configData['cpu_configuration'])) {
                try {
                    $cpuConfig = json_decode($configData['cpu_configuration'], true);
                    if (isset($cpuConfig['cpus']) && is_array($cpuConfig['cpus'])) {
                        $cpuResults = array_map(function($cpu) {
                            return ['component_uuid' => $cpu['uuid']];
                        }, $cpuConfig['cpus']);
                    }
                } catch (Exception $e) {
                    error_log("Error parsing cpu_configuration: " . $e->getMessage());
                }
            }

            if (empty($cpuResults)) {
                return [
                    'found' => false,
                    'error' => 'No CPUs found in configuration',
                    'specifications' => []
                ];
            }
            
            // Load specifications for each CPU
            require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);
            
            $cpuSpecs = [];
            foreach ($cpuResults as $cpu) {
                $cpuUuid = $cpu['component_uuid'];
                $specResult = $compatibility->getCPUSpecifications($cpuUuid);
                
                if ($specResult['found']) {
                    $cpuSpecs[] = $specResult['specifications'];
                }
            }
            
            return [
                'found' => !empty($cpuSpecs),
                'error' => empty($cpuSpecs) ? 'No valid CPU specifications found' : null,
                'specifications' => $cpuSpecs
            ];
            
        } catch (Exception $e) {
            error_log("Error getting CPU specs from config: " . $e->getMessage());
            return [
                'found' => false,
                'error' => 'Error loading CPU specifications: ' . $e->getMessage(),
                'specifications' => []
            ];
        }
    }

    /**
     * Get unified system memory limits combining motherboard and CPU constraints
     */
    public function getSystemMemoryLimits($configUuid) {
        try {
            $motherboardResult = $this->getMotherboardSpecsFromConfig($configUuid);
            $cpuResult = $this->getCPUSpecsFromConfig($configUuid);
            
            if (!$motherboardResult['found'] && !$cpuResult['found']) {
                return [
                    'found' => false,
                    'error' => 'No motherboard or CPU found in configuration',
                    'limits' => null
                ];
            }
            
            // Initialize with motherboard limits or defaults
            $limits = [
                'max_slots' => 4,
                'supported_types' => ['DDR4'],
                'max_frequency_mhz' => 3200,
                'max_capacity_gb' => 128,
                'ecc_support' => false
            ];
            
            // Apply motherboard constraints if available
            if ($motherboardResult['found']) {
                $mbSpecs = $motherboardResult['specifications'];
                $limits['max_slots'] = $mbSpecs['memory']['slots'] ?? 4;
                $limits['supported_types'] = $mbSpecs['memory']['types'] ?? ['DDR4'];
                $limits['max_frequency_mhz'] = $mbSpecs['memory']['max_frequency_mhz'] ?? 3200;
                $limits['max_capacity_gb'] = $mbSpecs['memory']['max_capacity_gb'] ?? 128;
                $limits['ecc_support'] = $mbSpecs['memory']['ecc_support'] ?? false;
            }
            
            // Apply CPU constraints if available (find most restrictive)
            if ($cpuResult['found']) {
                $cpuSpecs = $cpuResult['specifications'];
                $cpuMaxFrequency = null;
                $cpuSupportedTypes = [];
                
                foreach ($cpuSpecs as $cpu) {
                    // Find lowest CPU memory frequency limit
                    $cpuMemoryTypes = $cpu['compatibility']['memory_types'] ?? [];
                    foreach ($cpuMemoryTypes as $memType) {
                        if (preg_match('/DDR\d+-(\d+)/', $memType, $matches)) {
                            $freq = (int)$matches[1];
                            if ($cpuMaxFrequency === null || $freq < $cpuMaxFrequency) {
                                $cpuMaxFrequency = $freq;
                            }
                        }
                        
                        // Extract memory type (DDR4, DDR5)
                        if (preg_match('/(DDR\d+)/', $memType, $matches)) {
                            if (!in_array($matches[1], $cpuSupportedTypes)) {
                                $cpuSupportedTypes[] = $matches[1];
                            }
                        }
                    }
                }
                
                // Apply CPU frequency limit if more restrictive
                if ($cpuMaxFrequency !== null && $cpuMaxFrequency < $limits['max_frequency_mhz']) {
                    $limits['max_frequency_mhz'] = $cpuMaxFrequency;
                }
                
                // Intersect supported memory types
                if (!empty($cpuSupportedTypes)) {
                    $limits['supported_types'] = array_intersect($limits['supported_types'], $cpuSupportedTypes);
                }
            }
            
            return [
                'found' => true,
                'error' => null,
                'limits' => $limits
            ];
            
        } catch (Exception $e) {
            error_log("Error getting system memory limits: " . $e->getMessage());
            return [
                'found' => false,
                'error' => 'Error determining system memory limits: ' . $e->getMessage(),
                'limits' => null
            ];
        }
    }

    /**
     * COMPREHENSIVE Configuration Validation with Detailed Resource Tracking
     *
     * Performs in-depth validation of server configuration including:
     * - Required component presence checks
     * - Resource availability tracking (slots, bays, ports, lanes)
     * - Compatibility scoring across all components
     * - Detailed error/warning/info messages
     *
     * @param string $configUuid Server configuration UUID
     * @return array Comprehensive validation results with scores and resource tracking
     */
    public function validateConfigurationComprehensive($configUuid) {
        try {
            // Initialize result structure
            $result = [
                'valid' => true,
                'category_scores' => [
                    'cpu' => 100,
                    'motherboard' => 100,
                    'ram' => 100,
                    'storage' => 100,
                    'pcie' => 100,
                    'nic' => 100,
                    'chassis' => 100,
                    'caddy' => 100
                ],
                'required_components' => [],
                'resource_availability' => [],
                'errors' => [],
                'warnings' => [],
                'info' => [],
                'detailed_checks' => [
                    'storage_connections' => [],
                    'pcie_assignments' => [],
                    'compatibility_matrix' => []
                ]
            ];

            // Step 1: Get all components in configuration from JSON columns
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $configData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$configData) {
                return [
                    'valid' => false,
                    'errors' => [['type' => 'not_found', 'message' => 'Configuration not found']],
                    'warnings' => [],
                    'category_scores' => []
                ];
            }

            $components = $this->extractComponentsFromJson($configData);

            if (empty($components)) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'no_components',
                    'message' => 'Configuration has no components'
                ];
                return $result;
            }

            // Step 2: Group components by type
            $componentsByType = [];
            foreach ($components as $component) {
                $type = $component['component_type'];
                if (!isset($componentsByType[$type])) {
                    $componentsByType[$type] = [];
                }
                $componentsByType[$type][] = $component;
            }

            // Step 3: REQUIRED COMPONENT VALIDATION
            $this->validateRequiredComponents($componentsByType, $result);

            // Step 4: SINGLE COMPONENT CONSTRAINTS (motherboard, chassis, HBA)
            $this->validateSingleComponentConstraints($componentsByType, $result);

            // Step 5: RESOURCE AVAILABILITY TRACKING
            if (isset($componentsByType['motherboard'])) {
                $this->trackCPUSocketAvailability($configUuid, $componentsByType, $result);
                $this->trackRAMSlotAvailability($configUuid, $componentsByType, $result);
                $this->trackPCIeLaneAvailability($configUuid, $componentsByType, $result);
            }

            if (isset($componentsByType['chassis'])) {
                $this->trackStorageBayAvailability($configUuid, $componentsByType, $result);
            }

            // Step 6: PCIE SLOT VALIDATION (Reuse existing tracker)
            $this->validatePCIeSlots($configUuid, $result);

            // Step 7: STORAGE CONNECTION VALIDATION (Reuse existing validator)
            $this->validateStorageConnections($configUuid, $componentsByType, $result);

            // Step 8: CPU COMPATIBILITY CHECKS
            $this->validateCPUCompatibilityComprehensive($configUuid, $componentsByType, $result);

            // Step 9: RAM COMPATIBILITY CHECKS
            $this->validateRAMCompatibilityComprehensive($configUuid, $componentsByType, $result);

            // Step 10: CADDY REQUIREMENT VALIDATION
            $this->validateCaddyRequirements($configUuid, $componentsByType, $result);

            // Step 11: HBA-CHASSIS INTERFACE MATCHING
            $this->validateHBAChassisInterfaceMatch($componentsByType, $result);

            // Step 12: BUILD COMPREHENSIVE COMPATIBILITY MATRIX
            $this->buildComprehensiveCompatibilityMatrix($configUuid, $componentsByType, $result);

            // Step 13: CALCULATE FINAL COMPATIBILITY SCORE
            $this->calculateFinalCompatibilityScore($result);

            return $result;

        } catch (Exception $e) {
            error_log("Error in comprehensive validation: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return [
                'valid' => false,
                'category_scores' => [],
                'required_components' => [],
                'resource_availability' => [],
                'errors' => [[
                    'type' => 'validation_exception',
                    'message' => 'Validation failed: ' . $e->getMessage()
                ]],
                'warnings' => [],
                'info' => [],
                'detailed_checks' => []
            ];
        }
    }

    /**
     * Validate presence of required components
     */
    private function validateRequiredComponents($componentsByType, &$result) {
        $requiredComponents = [
            'chassis' => 'Chassis',
            'motherboard' => 'Motherboard',
            'cpu' => 'CPU',
            'ram' => 'RAM',
            'storage' => 'Storage',
            'nic' => 'Network Interface Card (NIC)'
        ];

        foreach ($requiredComponents as $type => $name) {
            $present = isset($componentsByType[$type]) && count($componentsByType[$type]) > 0;
            $count = $present ? count($componentsByType[$type]) : 0;

            $result['required_components'][$type] = [
                'present' => $present,
                'count' => $count,
                'name' => $name
            ];

            if (!$present) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'missing_required_component',
                    'component' => $type,
                    'message' => "Missing required component: $name"
                ];
            } else {
                $result['info'][] = [
                    'type' => 'component_present',
                    'message' => "$name: $count component(s) present"
                ];
            }
        }
    }

    /**
     * Validate single component constraints (only 1 motherboard, chassis, HBA allowed)
     */
    private function validateSingleComponentConstraints($componentsByType, &$result) {
        // Only one motherboard allowed
        if (isset($componentsByType['motherboard']) && count($componentsByType['motherboard']) > 1) {
            $result['valid'] = false;
            $result['errors'][] = [
                'type' => 'multiple_motherboards',
                'message' => 'Configuration has multiple motherboards. Only one motherboard allowed per server.'
            ];
        }

        // Only one chassis allowed
        if (isset($componentsByType['chassis']) && count($componentsByType['chassis']) > 1) {
            $result['valid'] = false;
            $result['errors'][] = [
                'type' => 'multiple_chassis',
                'message' => 'Configuration has multiple chassis. Only one chassis allowed per server.'
            ];
        }

        // Only one HBA card allowed
        if (isset($componentsByType['hbacard']) && count($componentsByType['hbacard']) > 1) {
            $hbaCount = count($componentsByType['hbacard']);
            $result['valid'] = false;
            $result['errors'][] = [
                'type' => 'multiple_hba_cards',
                'message' => "Configuration has $hbaCount HBA cards. Only one HBA card allowed per server."
            ];
        }
    }

    /**
     * Track CPU socket availability
     */
    private function trackCPUSocketAvailability($configUuid, $componentsByType, &$result) {
        try {
            $motherboard = $componentsByType['motherboard'][0];
            $mbSpecs = $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']);

            if (!$mbSpecs) {
                $result['warnings'][] = [
                    'type' => 'motherboard_specs_not_found',
                    'message' => 'Could not load motherboard specifications'
                ];
                return;
            }

            $maxSockets = $mbSpecs['socket']['count'] ?? 1;
            $usedSockets = isset($componentsByType['cpu']) ? count($componentsByType['cpu']) : 0;
            $availableSockets = max(0, $maxSockets - $usedSockets);

            $result['resource_availability']['cpu_sockets'] = [
                'max' => $maxSockets,
                'used' => $usedSockets,
                'available' => $availableSockets
            ];

            if ($usedSockets > $maxSockets) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'cpu_socket_exceeded',
                    'message' => "Too many CPUs: $usedSockets CPUs but only $maxSockets socket(s) available"
                ];
                $result['category_scores']['cpu'] = 0;
            }

        } catch (Exception $e) {
            error_log("Error tracking CPU sockets: " . $e->getMessage());
        }
    }

    /**
     * Track RAM slot availability
     */
    private function trackRAMSlotAvailability($configUuid, $componentsByType, &$result) {
        try {
            $motherboard = $componentsByType['motherboard'][0];
            $mbSpecs = $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']);

            if (!$mbSpecs) {
                return;
            }

            $maxSlots = $mbSpecs['memory']['slots'] ?? 4;
            $maxCapacityGB = $mbSpecs['memory']['max_capacity_gb'] ?? 128;
            $usedSlots = isset($componentsByType['ram']) ? count($componentsByType['ram']) : 0;
            $availableSlots = max(0, $maxSlots - $usedSlots);

            // Calculate total RAM capacity
            $totalCapacityGB = 0;
            if (isset($componentsByType['ram'])) {
                foreach ($componentsByType['ram'] as $ram) {
                    $ramSpecs = $this->dataUtils->getRAMByUUID($ram['component_uuid']);
                    if ($ramSpecs) {
                        $capacity = $ramSpecs['capacity_gb'] ?? 0;
                        $totalCapacityGB += $capacity;
                    }
                }
            }

            $result['resource_availability']['ram_slots'] = [
                'max' => $maxSlots,
                'used' => $usedSlots,
                'available' => $availableSlots,
                'max_capacity_gb' => $maxCapacityGB,
                'used_capacity_gb' => $totalCapacityGB,
                'available_capacity_gb' => max(0, $maxCapacityGB - $totalCapacityGB)
            ];

            if ($usedSlots > $maxSlots) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'ram_slot_exceeded',
                    'message' => "Too many RAM modules: $usedSlots modules but only $maxSlots slot(s) available"
                ];
                $result['category_scores']['ram'] = 0;
            }

            if ($totalCapacityGB > $maxCapacityGB) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'ram_capacity_exceeded',
                    'message' => "RAM capacity exceeded: {$totalCapacityGB}GB installed but maximum is {$maxCapacityGB}GB"
                ];
                $result['category_scores']['ram'] = max(0, $result['category_scores']['ram'] - 30);
            }

        } catch (Exception $e) {
            error_log("Error tracking RAM slots: " . $e->getMessage());
        }
    }

    /**
     * Track PCIe lane availability (CPU + Chipset lanes)
     */
    private function trackPCIeLaneAvailability($configUuid, $componentsByType, &$result) {
        try {
            $totalLanes = 0;
            $laneSources = [];

            // Get CPU PCIe lanes
            if (isset($componentsByType['cpu'])) {
                $cpuIndex = 1;
                foreach ($componentsByType['cpu'] as $cpu) {
                    $cpuSpecs = $this->dataUtils->getCPUByUUID($cpu['component_uuid']);
                    if ($cpuSpecs) {
                        $lanes = $cpuSpecs['pcie_lanes'] ?? 0;
                        $totalLanes += $lanes;
                        $laneSources[] = "CPU $cpuIndex: $lanes lanes";
                        $cpuIndex++;
                    }
                }
            }

            // Get chipset PCIe lanes from motherboard
            if (isset($componentsByType['motherboard'])) {
                $motherboard = $componentsByType['motherboard'][0];
                $mbSpecs = $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']);
                if ($mbSpecs) {
                    $chipsetLanes = $mbSpecs['chipset_pcie_lanes'] ?? 0;
                    $totalLanes += $chipsetLanes;
                    $laneSources[] = "Chipset: $chipsetLanes lanes";
                }
            }

            // Calculate used lanes from PCIe cards, HBA cards and NICs
            $usedLanes = 0;
            if (isset($componentsByType['pciecard'])) {
                foreach ($componentsByType['pciecard'] as $card) {
                    $cardSpecs = $this->dataUtils->getPCIeCardByUUID($card['component_uuid']);
                    if ($cardSpecs) {
                        $interface = $cardSpecs['interface'] ?? '';
                        if (preg_match('/x(\d+)/i', $interface, $matches)) {
                            $usedLanes += (int)$matches[1];
                        }
                    }
                }
            }

            if (isset($componentsByType['hbacard'])) {
                foreach ($componentsByType['hbacard'] as $card) {
                    $cardSpecs = $this->dataUtils->getHBACardByUUID($card['component_uuid']);
                    if ($cardSpecs) {
                        $interface = $cardSpecs['interface'] ?? '';
                        if (preg_match('/x(\d+)/i', $interface, $matches)) {
                            $usedLanes += (int)$matches[1];
                        }
                    }
                }
            }

            // P3.2 FIX: Use actual PCIe lane requirements from specs instead of hardcoded defaults
            if (isset($componentsByType['nic'])) {
                foreach ($componentsByType['nic'] as $nic) {
                    $nicSpecs = $this->dataUtils->getNICByUUID($nic['component_uuid']);
                    if ($nicSpecs) {
                        // Extract lanes from interface field (e.g., "PCIe 3.0 x4" → 4)
                        $interface = $nicSpecs['interface'] ?? '';
                        $lanes = 4; // default
                        if (preg_match('/x(\d+)/i', $interface, $matches)) {
                            $lanes = (int)$matches[1];
                        }
                        // Account for quantity if present
                        $quantity = $nic['quantity'] ?? 1;
                        $usedLanes += $lanes * $quantity;
                    } else {
                        // Fallback: assume x4 if specs not found
                        $usedLanes += 4;
                    }
                }
            }

            // Account for NVMe storage that uses PCIe slots (not M.2)
            // P3.2 FIX: Use actual lane requirements from specs, not hardcoded x4
            if (isset($componentsByType['storage'])) {
                foreach ($componentsByType['storage'] as $storage) {
                    $storageSpecs = $this->dataUtils->getStorageByUUID($storage['component_uuid']);
                    if ($storageSpecs) {
                        $interface = strtolower($storageSpecs['interface'] ?? '');
                        $formFactor = strtolower($storageSpecs['form_factor'] ?? '');

                        // Only count non-M.2 NVMe (U.2, PCIe add-in cards)
                        $isM2 = (strpos($formFactor, 'm.2') !== false || strpos($formFactor, 'm2') !== false);
                        $isNVMe = (strpos($interface, 'nvme') !== false || strpos($interface, 'pcie') !== false);

                        if ($isNVMe && !$isM2) {
                            // Extract actual lane requirement from interface
                            $lanes = 4; // default
                            if (preg_match('/x(\d+)/i', $interface, $matches)) {
                                $lanes = (int)$matches[1];
                            }
                            // Account for quantity if present
                            $quantity = $storage['quantity'] ?? 1;
                            $usedLanes += $lanes * $quantity;
                        }
                    }
                }
            }

            $availableLanes = max(0, $totalLanes - $usedLanes);

            $result['resource_availability']['pcie_lanes'] = [
                'total' => $totalLanes,
                'used' => $usedLanes,
                'available' => $availableLanes,
                'sources' => $laneSources
            ];

            if ($usedLanes > $totalLanes) {
                $result['warnings'][] = [
                    'type' => 'pcie_lanes_exceeded',
                    'message' => "PCIe lane budget exceeded: $usedLanes lanes used but only $totalLanes lanes available. Some devices may run at reduced bandwidth."
                ];
                $result['category_scores']['pcie'] = max(0, $result['category_scores']['pcie'] - 20);
            }

        } catch (Exception $e) {
            error_log("Error tracking PCIe lanes: " . $e->getMessage());
        }
    }

    /**
     * Track storage bay availability
     */
    private function trackStorageBayAvailability($configUuid, $componentsByType, &$result) {
        try {
            $chassis = $componentsByType['chassis'][0];
            $chassisSpecs = $this->dataUtils->getChassisSpecifications($chassis['component_uuid']);

            if (!$chassisSpecs) {
                $result['warnings'][] = [
                    'type' => 'chassis_specs_not_found',
                    'message' => 'Could not load chassis specifications'
                ];
                return;
            }

            $driveBays = $chassisSpecs['drive_bays'] ?? [];
            $totalBays = $driveBays['total_bays'] ?? 0;
            $bayConfiguration = $driveBays['bay_configuration'] ?? [];

            // CRITICAL: Only count storage devices that connect via chassis backplane
            // Storage connecting via motherboard M.2/SATA does NOT use chassis bays
            $usedBays = 0;
            $backplaneStorageList = [];

            if (isset($componentsByType['storage'])) {
                require_once __DIR__ . '/../compatibility/StorageConnectionValidator.php';
                $storageValidator = new StorageConnectionValidator($this->pdo);
                $existingComponents = $this->getExistingComponentsForValidation($configUuid);

                foreach ($componentsByType['storage'] as $storage) {
                    $validation = $storageValidator->validate($configUuid, $storage['component_uuid'], $existingComponents);

                    // Check if primary connection path is chassis bay
                    if ($validation['valid'] && isset($validation['primary_path'])) {
                        if ($validation['primary_path']['type'] === 'chassis_bay') {
                            $usedBays++;

                            $storageSpecs = $this->dataUtils->getStorageByUUID($storage['component_uuid']);
                            $backplaneStorageList[] = [
                                'uuid' => $storage['component_uuid'],
                                'model' => $storageSpecs['model'] ?? 'Unknown',
                                'form_factor' => $storageSpecs['form_factor'] ?? 'Unknown'
                            ];
                        }
                    }
                }
            }

            $availableBays = max(0, $totalBays - $usedBays);

            $result['resource_availability']['storage_bays'] = [
                'max' => $totalBays,
                'used' => $usedBays,
                'available' => $availableBays,
                'bay_configuration' => $bayConfiguration,
                'backplane_connected_storage' => $backplaneStorageList
            ];

            if ($usedBays > $totalBays) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'storage_bay_exceeded',
                    'message' => "Too many storage devices connected to chassis backplane: $usedBays devices but only $totalBays bay(s) available"
                ];
                $result['category_scores']['storage'] = 0;
            }

        } catch (Exception $e) {
            error_log("Error tracking storage bays: " . $e->getMessage());
        }
    }

    /**
     * Validate PCIe slot assignments using UnifiedSlotTracker
     */
    private function validatePCIeSlots($configUuid, &$result) {
        try {
            require_once __DIR__ . '/../compatibility/UnifiedSlotTracker.php';
            $slotTracker = new UnifiedSlotTracker($this->pdo);

            $pcieValidation = $slotTracker->validateAllSlots($configUuid);

            // Track slot availability
            if ($pcieValidation['valid']) {
                $slotAvailability = $slotTracker->getSlotAvailability($configUuid);

                if ($slotAvailability['success']) {
                    $slotSummary = [];
                    foreach ($slotAvailability['total_slots'] as $slotSize => $slots) {
                        $usedCount = 0;
                        foreach ($slotAvailability['used_slots'] as $slotId => $componentUuid) {
                            if (strpos($slotId, $slotSize) !== false) {
                                $usedCount++;
                            }
                        }

                        $slotSummary[$slotSize] = [
                            'max' => count($slots),
                            'used' => $usedCount,
                            'available' => count($slots) - $usedCount
                        ];
                    }

                    $result['resource_availability']['pcie_slots'] = $slotSummary;
                }
            }

            // Add PCIe validation results
            $result['detailed_checks']['pcie_assignments'] = $pcieValidation;

            if (!$pcieValidation['valid']) {
                $result['valid'] = false;
                $result['errors'] = array_merge($result['errors'], array_map(function($err) {
                    return ['type' => 'pcie_slot_error', 'message' => $err];
                }, $pcieValidation['errors']));
                $result['category_scores']['pcie'] = 0;
            }

            if (!empty($pcieValidation['warnings'])) {
                $result['warnings'] = array_merge($result['warnings'], array_map(function($warn) {
                    return ['type' => 'pcie_slot_warning', 'message' => $warn];
                }, $pcieValidation['warnings']));
                $result['category_scores']['pcie'] = max(0, $result['category_scores']['pcie'] - 10);
            }

        } catch (Exception $e) {
            error_log("Error validating PCIe slots: " . $e->getMessage());
            $result['warnings'][] = [
                'type' => 'pcie_validation_error',
                'message' => 'PCIe slot validation could not be completed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate storage connections using existing StorageConnectionValidator
     */
    private function validateStorageConnections($configUuid, $componentsByType, &$result) {
        try {
            if (!isset($componentsByType['storage'])) {
                return; // No storage to validate
            }

            require_once __DIR__ . '/StorageConnectionValidator.php';
            $storageValidator = new StorageConnectionValidator($this->pdo);

            // Get existing components for validation
            $existingComponents = $this->getExistingComponentsForValidation($configUuid);

            $storageResults = [];
            $caddyRequired = 0;
            $caddyAvailable = isset($componentsByType['caddy']) ? count($componentsByType['caddy']) : 0;
            $caddiesNeeded = []; // Track which storage devices need caddies

            foreach ($componentsByType['storage'] as $storage) {
                $validation = $storageValidator->validate($configUuid, $storage['component_uuid'], $existingComponents);

                // Create simplified storage connection entry
                $storageSpecs = $this->dataUtils->getStorageByUUID($storage['component_uuid']);
                $simplifiedEntry = [
                    'storage_uuid' => $storage['component_uuid'],
                    'interface' => strtoupper($storageSpecs['interface'] ?? 'Unknown'),
                    'form_factor' => $storageSpecs['form_factor'] ?? 'Unknown',
                    'valid' => $validation['valid'],
                    'primary_path' => [
                        'type' => $validation['primary_path']['type'] ?? 'none',
                        'description' => $validation['primary_path']['description'] ?? 'No connection available'
                    ]
                ];

                // Add only if there are errors or warnings
                if (!empty($validation['errors'])) {
                    $simplifiedEntry['errors'] = $validation['errors'];
                }
                if (!empty($validation['warnings'])) {
                    $simplifiedEntry['warnings'] = $validation['warnings'];
                }

                $storageResults[] = $simplifiedEntry;

                // CRITICAL: Check if storage connects via chassis backplane
                $usesBackplane = false;
                if ($validation['valid'] && isset($validation['primary_path'])) {
                    if ($validation['primary_path']['type'] === 'chassis_bay') {
                        $usesBackplane = true;
                        $caddyRequired++;

                        // Get storage specs to determine caddy form factor requirement
                        $storageSpecs = $this->dataUtils->getStorageByUUID($storage['component_uuid']);
                        $formFactor = $storageSpecs['form_factor'] ?? 'Unknown';

                        $caddiesNeeded[] = [
                            'storage_uuid' => $storage['component_uuid'],
                            'form_factor' => $formFactor,
                            'connection_type' => 'chassis_backplane'
                        ];
                    }
                }

                // Also check for caddy warnings from validator (2.5" in 3.5" bay scenarios)
                if (!empty($validation['warnings'])) {
                    foreach ($validation['warnings'] as $warning) {
                        if (isset($warning['type']) && $warning['type'] === 'caddy_recommended') {
                            // Only increment if not already counted above
                            if (!$usesBackplane) {
                                $caddyRequired++;

                                $storageSpecs = $this->dataUtils->getStorageByUUID($storage['component_uuid']);
                                $formFactor = $storageSpecs['form_factor'] ?? 'Unknown';

                                $caddiesNeeded[] = [
                                    'storage_uuid' => $storage['component_uuid'],
                                    'form_factor' => $formFactor,
                                    'connection_type' => 'size_adapter'
                                ];
                            }
                        }
                    }
                }

                if (!$validation['valid']) {
                    $result['valid'] = false;
                    $result['errors'] = array_merge($result['errors'], array_map(function($err) use ($storage) {
                        return [
                            'type' => 'storage_connection_error',
                            'storage_uuid' => $storage['component_uuid'],
                            'message' => $err['message'] ?? $err
                        ];
                    }, $validation['errors']));
                    $result['category_scores']['storage'] = 0;
                }

                if (!empty($validation['warnings'])) {
                    $result['warnings'] = array_merge($result['warnings'], array_map(function($warn) use ($storage) {
                        return [
                            'type' => 'storage_connection_warning',
                            'storage_uuid' => $storage['component_uuid'],
                            'message' => $warn['message'] ?? $warn
                        ];
                    }, $validation['warnings']));
                    $result['category_scores']['storage'] = max(0, $result['category_scores']['storage'] - 5);
                }
            }

            $result['detailed_checks']['storage_connections'] = $storageResults;

            // Validate caddy availability and form factor matching
            $caddyErrors = [];
            $caddyWarnings = [];

            if ($caddyRequired > 0) {
                // Get all caddies in configuration
                $availableCaddies = [];
                if (isset($componentsByType['caddy'])) {
                    // Load caddy JSON specifications
                    $caddyJsonPath = ComponentSpecPaths::getPath('caddy');
                    $caddySpecs = [];
                    if (file_exists($caddyJsonPath)) {
                        $caddyJson = json_decode(file_get_contents($caddyJsonPath), true);
                        if (isset($caddyJson['caddies'])) {
                            foreach ($caddyJson['caddies'] as $spec) {
                                if (isset($spec['uuid'])) {
                                    $caddySpecs[$spec['uuid']] = $spec;
                                }
                            }
                        }
                    }

                    foreach ($componentsByType['caddy'] as $caddy) {
                        $caddyUuid = $caddy['component_uuid'];
                        $size = 'Unknown';

                        // Try to get size from JSON specifications first
                        if (isset($caddySpecs[$caddyUuid]) && isset($caddySpecs[$caddyUuid]['compatibility']['size'])) {
                            $size = $caddySpecs[$caddyUuid]['compatibility']['size'];
                        } else {
                            // Fallback to Notes field if JSON not found
                            $stmt = $this->pdo->prepare("SELECT Notes FROM caddyinventory WHERE UUID = ?");
                            $stmt->execute([$caddyUuid]);
                            $caddyData = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($caddyData) {
                                $notes = $caddyData['Notes'] ?? '';
                                if (preg_match('/2\.5[\s\-]?inch/i', $notes)) {
                                    $size = '2.5-inch';
                                } elseif (preg_match('/3\.5[\s\-]?inch/i', $notes)) {
                                    $size = '3.5-inch';
                                }
                            }
                        }

                        $availableCaddies[] = [
                            'uuid' => $caddyUuid,
                            'size' => $size
                        ];
                    }
                }

                // Match caddies to storage devices
                foreach ($caddiesNeeded as $need) {
                    $matchFound = false;
                    $storageFormFactor = $need['form_factor'];

                    // Normalize form factor to caddy size format
                    $requiredCaddySize = null;
                    if (stripos($storageFormFactor, '2.5') !== false || stripos($storageFormFactor, '2.5"') !== false) {
                        $requiredCaddySize = '2.5';
                    } elseif (stripos($storageFormFactor, '3.5') !== false || stripos($storageFormFactor, '3.5"') !== false) {
                        $requiredCaddySize = '3.5';
                    }

                    foreach ($availableCaddies as $idx => $caddy) {
                        if ($requiredCaddySize && stripos($caddy['size'], $requiredCaddySize) !== false) {
                            $matchFound = true;
                            unset($availableCaddies[$idx]); // Remove matched caddy
                            break;
                        }
                    }

                    if (!$matchFound) {
                        $caddyErrors[] = [
                            'type' => 'missing_caddy',
                            'storage_uuid' => $need['storage_uuid'],
                            'required_form_factor' => $storageFormFactor,
                            'message' => "Storage device ({$need['storage_uuid']}) requires {$storageFormFactor} caddy for {$need['connection_type']} connection"
                        ];
                    }
                }

                // Add errors to result
                if (!empty($caddyErrors)) {
                    $result['valid'] = false;
                    $result['errors'] = array_merge($result['errors'], $caddyErrors);
                    $result['category_scores']['storage'] = max(0, $result['category_scores']['storage'] - 20);
                }
            }

            // Track caddy availability with details
            $result['resource_availability']['caddies'] = [
                'required' => $caddyRequired,
                'available' => $caddyAvailable,
                'missing' => max(0, $caddyRequired - $caddyAvailable),
                'details' => $caddiesNeeded
            ];

            // Don't add summary error if specific caddy errors already added (avoid duplicate penalty)
            // The specific errors above already provide detailed information

        } catch (Exception $e) {
            error_log("Error validating storage connections: " . $e->getMessage());
            $result['warnings'][] = [
                'type' => 'storage_validation_error',
                'message' => 'Storage validation could not be completed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate CPU compatibility (socket, dual CPU warnings) - Comprehensive validation
     */
    private function validateCPUCompatibilityComprehensive($configUuid, $componentsByType, &$result) {
        try {
            if (!isset($componentsByType['cpu']) || !isset($componentsByType['motherboard'])) {
                return;
            }

            require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);

            $motherboard = $componentsByType['motherboard'][0];
            $mbSpecs = $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']);

            if (!$mbSpecs) {
                return;
            }

            $cpuModels = [];
            foreach ($componentsByType['cpu'] as $cpu) {
                $cpuSpecs = $this->dataUtils->getCPUByUUID($cpu['component_uuid']);

                // Socket compatibility check
                $socketResult = $compatibility->validateCPUSocketCompatibility($cpu['component_uuid'], [
                    'cpu' => [
                        'socket_type' => $mbSpecs['socket']['type'] ?? 'Unknown'
                    ]
                ]);

                if (!$socketResult['compatible']) {
                    $result['valid'] = false;
                    $result['errors'][] = [
                        'type' => 'cpu_socket_incompatible',
                        'cpu_uuid' => $cpu['component_uuid'],
                        'message' => $socketResult['error']
                    ];
                    $result['category_scores']['cpu'] = 0;
                }

                if ($cpuSpecs) {
                    $cpuModels[] = $cpuSpecs['model'] ?? 'Unknown';
                }
            }

            // Dual CPU warning if different models
            if (count($cpuModels) === 2 && $cpuModels[0] !== $cpuModels[1]) {
                $result['warnings'][] = [
                    'type' => 'dual_cpu_different_models',
                    'message' => "Dual CPUs detected with different models ({$cpuModels[0]} and {$cpuModels[1]}). This may cause performance issues or system instability."
                ];
                // Don't reduce category score - warning penalty is already applied in final calculation
                // This prevents double penalty (was -15 category + -3 warning = -18 total)
            }

        } catch (Exception $e) {
            error_log("Error validating CPU compatibility: " . $e->getMessage());
        }
    }

    /**
     * Validate RAM compatibility (type, speed, capacity) - Comprehensive validation
     */
    private function validateRAMCompatibilityComprehensive($configUuid, $componentsByType, &$result) {
        try {
            if (!isset($componentsByType['ram']) || !isset($componentsByType['motherboard'])) {
                return;
            }

            require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);

            $motherboard = $componentsByType['motherboard'][0];
            $mbSpecs = $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']);

            if (!$mbSpecs) {
                return;
            }

            foreach ($componentsByType['ram'] as $ram) {
                // Type compatibility check - pass full motherboard specs for flexible field handling
                $typeResult = $compatibility->validateRAMTypeCompatibility($ram['component_uuid'], $mbSpecs);

                if (!$typeResult['compatible']) {
                    $result['valid'] = false;
                    $result['errors'][] = [
                        'type' => 'ram_type_incompatible',
                        'ram_uuid' => $ram['component_uuid'],
                        'message' => $typeResult['error']
                    ];
                    $result['category_scores']['ram'] = 0;
                }
            }

        } catch (Exception $e) {
            error_log("Error validating RAM compatibility: " . $e->getMessage());
        }
    }

    /**
     * Validate caddy requirements for storage devices
     */
    private function validateCaddyRequirements($configUuid, $componentsByType, &$result) {
        try {
            if (!isset($componentsByType['storage']) || !isset($componentsByType['chassis'])) {
                return;
            }

            // This is already handled in validateStorageConnections
            // Kept as separate method for clarity and future enhancements

        } catch (Exception $e) {
            error_log("Error validating caddy requirements: " . $e->getMessage());
        }
    }

    /**
     * Validate HBA card interface matches chassis backplane interface
     */
    private function validateHBAChassisInterfaceMatch($componentsByType, &$result) {
        try {
            if (!isset($componentsByType['chassis']) || !isset($componentsByType['hbacard'])) {
                return;
            }

            // Find HBA card
            $hbaCard = null;
            if (isset($componentsByType['hbacard']) && !empty($componentsByType['hbacard'])) {
                $card = $componentsByType['hbacard'][0];
                $hbaCard = $this->dataUtils->getHBACardByUUID($card['component_uuid']);
            }

            if (!$hbaCard) {
                return; // No HBA card, skip check
            }

            // Get chassis backplane interface
            $chassis = $componentsByType['chassis'][0];
            $chassisSpecs = $this->dataUtils->getChassisSpecifications($chassis['component_uuid']);

            if (!$chassisSpecs) {
                return;
            }

            $backplane = $chassisSpecs['backplane'] ?? [];
            $backplaneInterface = strtoupper($backplane['interface'] ?? 'Unknown');
            $hbaInterface = strtoupper($hbaCard['interface'] ?? 'Unknown');

            // Extract protocol from interfaces
            $backplaneProtocol = '';
            if (strpos($backplaneInterface, 'SATA') !== false) $backplaneProtocol = 'SATA';
            if (strpos($backplaneInterface, 'SAS') !== false) $backplaneProtocol = 'SAS';

            $hbaProtocol = '';
            if (strpos($hbaInterface, 'SATA') !== false) $hbaProtocol = 'SATA';
            if (strpos($hbaInterface, 'SAS') !== false) $hbaProtocol = 'SAS';

            // SAS HBA can support SATA (backward compatible), but SATA HBA cannot support SAS
            if ($backplaneProtocol === 'SAS' && $hbaProtocol === 'SATA') {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'hba_chassis_interface_mismatch',
                    'message' => "HBA card interface ($hbaInterface) incompatible with chassis backplane ($backplaneInterface). SAS backplane requires SAS HBA."
                ];
                $result['category_scores']['storage'] = 0;
            } elseif ($backplaneProtocol === 'SATA' && $hbaProtocol === 'SAS') {
                // This is OK - SAS HBA can handle SATA backplane
                $result['info'][] = [
                    'type' => 'hba_chassis_compatible',
                    'message' => "SAS HBA is compatible with SATA backplane (backward compatible)"
                ];
            } elseif ($backplaneProtocol !== $hbaProtocol && $backplaneProtocol && $hbaProtocol) {
                $result['warnings'][] = [
                    'type' => 'hba_chassis_interface_mismatch',
                    'message' => "HBA interface ($hbaInterface) may not match chassis backplane ($backplaneInterface). Verify compatibility."
                ];
                $result['category_scores']['storage'] = max(0, $result['category_scores']['storage'] - 10);
            }

        } catch (Exception $e) {
            error_log("Error validating HBA-chassis interface match: " . $e->getMessage());
        }
    }

    /**
     * Build comprehensive compatibility matrix showing ALL component pair validations
     */
    private function buildComprehensiveCompatibilityMatrix($configUuid, $componentsByType, &$result) {
        try {
            require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);

            $matrix = [];

            // Get component specs
            $motherboard = isset($componentsByType['motherboard']) ? $componentsByType['motherboard'][0] : null;
            $chassis = isset($componentsByType['chassis']) ? $componentsByType['chassis'][0] : null;

            $mbSpecs = $motherboard ? $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']) : null;
            $chassisSpecs = $chassis ? $this->dataUtils->getChassisSpecifications($chassis['component_uuid']) : null;

            // 1. MOTHERBOARD ↔ CPU COMPATIBILITY
            if ($motherboard && isset($componentsByType['cpu'])) {
                $cpuList = [];
                $allCompatible = true;
                foreach ($componentsByType['cpu'] as $cpu) {
                    $cpuSpecs = $this->dataUtils->getCPUByUUID($cpu['component_uuid']);
                    $socketResult = $compatibility->validateCPUSocketCompatibility($cpu['component_uuid'], [
                        'cpu' => ['socket_type' => $mbSpecs['socket']['type'] ?? 'Unknown']
                    ]);

                    if (!$socketResult['compatible']) {
                        $allCompatible = false;
                    }

                    $cpuList[] = [
                        'model' => $cpuSpecs['model'] ?? 'Unknown',
                        'compatible' => $socketResult['compatible']
                    ];
                }

                $matrix[] = [
                    'pair' => 'motherboard_cpu',
                    'check' => 'Socket: ' . ($mbSpecs['socket']['type'] ?? 'Unknown'),
                    'count' => count($cpuList),
                    'items' => $cpuList,
                    'compatible' => $allCompatible
                ];
            }

            // 2. MOTHERBOARD ↔ RAM COMPATIBILITY
            if ($motherboard && isset($componentsByType['ram'])) {
                $ramList = [];
                $allCompatible = true;
                foreach ($componentsByType['ram'] as $ram) {
                    $ramSpecs = $this->dataUtils->getRAMByUUID($ram['component_uuid']);
                    $typeResult = $compatibility->validateRAMTypeCompatibility($ram['component_uuid'], $mbSpecs);

                    if (!$typeResult['compatible']) {
                        $allCompatible = false;
                    }

                    $ramList[] = [
                        'type' => $ramSpecs['memory_type'] ?? 'Unknown',
                        'compatible' => $typeResult['compatible']
                    ];
                }

                // Get supported RAM types - handle both 'type' and 'types' fields
                $supportedTypes = $mbSpecs['memory']['types'] ?? [$mbSpecs['memory']['type'] ?? 'DDR4'];
                $matrix[] = [
                    'pair' => 'motherboard_ram',
                    'check' => 'Type: ' . implode('/', $supportedTypes),
                    'count' => count($ramList),
                    'items' => $ramList,
                    'compatible' => $allCompatible
                ];
            }

            // 3. STORAGE CONNECTIVITY (Use actual validation results)
            if (isset($componentsByType['storage'])) {
                $storageList = [];
                $allCompatible = true;

                // Reuse the storage validation results from detailed_checks
                if (isset($result['detailed_checks']['storage_connections'])) {
                    foreach ($result['detailed_checks']['storage_connections'] as $storageValidation) {
                        $storageSpecs = $this->dataUtils->getStorageByUUID($storageValidation['storage_uuid']);
                        $interface = strtoupper($storageSpecs['interface'] ?? 'Unknown');

                        $compatible = $storageValidation['validation']['valid'];
                        $connectionPath = 'No path';

                        // Extract connection path from primary_path
                        if (isset($storageValidation['validation']['primary_path'])) {
                            $primaryPath = $storageValidation['validation']['primary_path'];
                            switch ($primaryPath['type']) {
                                case 'chassis_bay':
                                    $connectionPath = 'Chassis backplane';
                                    break;
                                case 'motherboard_m2':
                                    $connectionPath = 'Motherboard M.2';
                                    break;
                                case 'motherboard_sata':
                                    $connectionPath = 'Motherboard SATA';
                                    break;
                                case 'hba_card':
                                    $connectionPath = 'HBA Card';
                                    break;
                                case 'pcie_adapter':
                                    $connectionPath = 'PCIe Adapter';
                                    break;
                                default:
                                    $connectionPath = ucwords(str_replace('_', ' ', $primaryPath['type']));
                            }
                        }

                        if (!$compatible) {
                            $allCompatible = false;
                        }

                        $storageList[] = [
                            'interface' => $interface,
                            'path' => $connectionPath,
                            'compatible' => $compatible
                        ];
                    }
                }

                $matrix[] = [
                    'pair' => 'storage_connectivity',
                    'check' => 'All storage devices have valid connection path',
                    'count' => count($storageList),
                    'items' => $storageList,
                    'compatible' => $allCompatible
                ];
            }

            // 4. PCIE CARDS COMPATIBILITY
            if ($motherboard && isset($componentsByType['pciecard'])) {
                $pcieList = [];
                $allCompatible = true;
                foreach ($componentsByType['pciecard'] as $card) {
                    $cardSpecs = $this->dataUtils->getPCIeCardByUUID($card['component_uuid']);
                    $cardType = $cardSpecs['component_subtype'] ?? 'PCIe Card';

                    // Check if motherboard has available slots (basic check)
                    $compatible = isset($mbSpecs['expansion_slots']) && !empty($mbSpecs['expansion_slots']);

                    if (!$compatible) {
                        $allCompatible = false;
                    }

                    $pcieList[] = [
                        'type' => $cardType,
                        'compatible' => $compatible
                    ];
                }

                $matrix[] = [
                    'pair' => 'pcie_cards',
                    'check' => 'PCIe slot availability',
                    'count' => count($pcieList),
                    'items' => $pcieList,
                    'compatible' => $allCompatible
                ];
            }

            // 4b. HBA CARDS COMPATIBILITY
            if ($motherboard && isset($componentsByType['hbacard'])) {
                $hbaList = [];
                $allCompatible = true;
                foreach ($componentsByType['hbacard'] as $card) {
                    $cardSpecs = $this->dataUtils->getHBACardByUUID($card['component_uuid']);
                    $cardType = 'HBA Card';

                    // Check if motherboard has available slots (basic check)
                    $compatible = isset($mbSpecs['expansion_slots']) && !empty($mbSpecs['expansion_slots']);

                    if (!$compatible) {
                        $allCompatible = false;
                    }

                    $hbaList[] = [
                        'type' => $cardType,
                        'model' => $cardSpecs['model'] ?? 'Unknown',
                        'compatible' => $compatible
                    ];
                }

                $matrix[] = [
                    'pair' => 'hba_cards',
                    'check' => 'HBA slot availability',
                    'count' => count($hbaList),
                    'items' => $hbaList,
                    'compatible' => $allCompatible
                ];
            }

            // 5. CHASSIS ↔ MOTHERBOARD FORM FACTOR
            if ($chassis && $motherboard) {
                $chassisFormFactor = $chassisSpecs['form_factor'] ?? 'Unknown';
                $mbFormFactor = $mbSpecs['form_factor'] ?? 'Unknown';
                $compatible = (stripos($chassisFormFactor, $mbFormFactor) !== false);

                $matrix[] = [
                    'pair' => 'chassis_motherboard',
                    'check' => "$chassisFormFactor ↔ $mbFormFactor",
                    'compatible' => $compatible
                ];
            }

            $result['detailed_checks']['compatibility_matrix'] = $matrix;

        } catch (Exception $e) {
            error_log("Error building compatibility matrix: " . $e->getMessage());
        }
    }

    /**
     * Calculate final compatibility score based on category scores and issues
     */
    private function calculateFinalCompatibilityScore(&$result) {
        // Start with average of category scores
        $categoryScores = array_values($result['category_scores']);
        $avgScore = count($categoryScores) > 0 ? array_sum($categoryScores) / count($categoryScores) : 0;

        // Apply error penalty
        $errorPenalty = count($result['errors']) * 10;

        // Apply warning penalty
        $warningPenalty = count($result['warnings']) * 3;

        // Calculate final score
        $finalScore = max(0, min(100, $avgScore - $errorPenalty - $warningPenalty));


        // Set valid to false if score is too low
        if ($finalScore < 50) {
            $result['valid'] = false;
        }
    }

    /**
     * Get existing components formatted for validation
     */
    private function getExistingComponentsForValidation($configUuid) {
        $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        $configData = $stmt->fetch(PDO::FETCH_ASSOC);

        $components = [];
        if ($configData) {
            $components = $this->extractComponentsFromJson($configData);
        }

        $formatted = [
            'chassis' => null,
            'motherboard' => null,
            'cpu' => [],
            'ram' => [],
            'storage' => [],
            'nic' => [],
            'pciecard' => [],
            'hbacard' => [],
            'caddy' => []
        ];

        foreach ($components as $component) {
            $type = $component['component_type'];
            if ($type === 'chassis' || $type === 'motherboard') {
                $formatted[$type] = $component;
            } else {
                $formatted[$type][] = $component;
            }
        }

        return $formatted;
    }

    /**
     * P5.2: Safely parse JSON with error handling
     * Prevents fatal errors from malformed JSON in database columns
     *
     * @param string $jsonString JSON string to parse
     * @param bool $associative Return associative array (default true)
     * @param string $fieldName Field name for error logging
     * @return array Parsed data or empty array on error
     */
    private function safeJsonDecode($jsonString, $associative = true, $fieldName = 'unknown') {
        if (empty($jsonString)) {
            return $associative ? [] : new stdClass();
        }

        try {
            $decoded = json_decode($jsonString, $associative);

            // P5.2: Check for JSON parse errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMsg = json_last_error_msg();
                error_log("P5.2 JSON ERROR in $fieldName: " . $errorMsg . " | Raw: " . substr($jsonString, 0, 100));
                return $associative ? [] : new stdClass();
            }

            // Handle null result (valid JSON null, but we treat as empty)
            if ($decoded === null && $jsonString !== 'null') {
                error_log("P5.2 JSON NULL in $fieldName: JSON decoded to null unexpectedly | Raw: " . substr($jsonString, 0, 100));
                return $associative ? [] : new stdClass();
            }

            return $decoded;

        } catch (Exception $e) {
            error_log("P5.2 JSON EXCEPTION in $fieldName: " . $e->getMessage());
            return $associative ? [] : new stdClass();
        }
    }

    /**
     * P5.1: Validate that we have enough slots/capacity for component quantity
     * Prevents adding more components than the system can support (e.g., 16 RAM sticks to 4-DIMM board)
     *
     * @param string $componentType Component type (ram, storage, nic, pciecard, etc.)
     * @param string $componentUuid Component UUID
     * @param int $quantity Quantity being added
     * @param string $configUuid Server configuration UUID
     * @return array Validation result
     */
    private function validateComponentQuantity($componentType, $componentUuid, $quantity, $configUuid) {
        // P5.1: Only validate slot-based components
        $slotBasedTypes = ['ram', 'storage', 'pciecard', 'hbacard', 'nic'];

        if (!in_array($componentType, $slotBasedTypes)) {
            return ['valid' => true]; // Non-slot components don't need quantity validation
        }

        try {
            // Get current configuration
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                return ['valid' => false, 'message' => 'Configuration not found'];
            }

            // Get motherboard specs to determine slot limits
            $motherboardUuid = $config['motherboard_uuid'] ?? null;
            if (!$motherboardUuid) {
                // No motherboard, can't validate
                return ['valid' => true];
            }

            $mbSpecs = $this->dataUtils->getMotherboardByUUID($motherboardUuid);
            if (!$mbSpecs) {
                return ['valid' => true]; // Can't validate without specs
            }

            // P5.1: Validate based on component type
            switch ($componentType) {
                case 'ram':
                    $dimms = $mbSpecs['memory']['slots'] ?? 0;
                    // Count existing RAM
                    $existingRam = [];
                    if (!empty($config['ram_configurations'])) {
                        $ramConfigs = json_decode($config['ram_configurations'], true);
                        if (is_array($ramConfigs)) {
                            $existingRam = $ramConfigs;
                        }
                    }
                    $totalRam = count($existingRam) + $quantity;

                    if ($totalRam > $dimms) {
                        return [
                            'valid' => false,
                            'message' => "Insufficient RAM slots: trying to add $quantity RAM modules but only " . ($dimms - count($existingRam)) . " slots available (board has $dimms total, $existingRam currently used)",
                            'details' => [
                                'total_slots' => $dimms,
                                'used_slots' => count($existingRam),
                                'requesting' => $quantity,
                                'available' => $dimms - count($existingRam)
                            ]
                        ];
                    }
                    break;

                case 'storage':
                    // Count storage bays
                    $driveBays = $mbSpecs['storage']['drive_bays']['total_bays'] ?? 0;
                    if ($driveBays === 0) {
                        // Check if chassis has bays
                        $chassis = $config['chassis_uuid'] ?? null;
                        if ($chassis) {
                            $chassisSpecs = $this->dataUtils->getChassisSpecifications($chassis);
                            if ($chassisSpecs) {
                                $driveBays = $chassisSpecs['drive_bays']['total_bays'] ?? 0;
                            }
                        }
                    }

                    if ($driveBays > 0) {
                        // Count existing storage
                        $existingStorage = [];
                        if (!empty($config['storage_configurations'])) {
                            $storageConfigs = json_decode($config['storage_configurations'], true);
                            if (is_array($storageConfigs)) {
                                $existingStorage = $storageConfigs;
                            }
                        }
                        $totalStorage = count($existingStorage) + $quantity;

                        if ($totalStorage > $driveBays) {
                            return [
                                'valid' => false,
                                'message' => "Insufficient drive bays: trying to add $quantity storage devices but only " . ($driveBays - count($existingStorage)) . " bays available (chassis has $driveBays total, " . count($existingStorage) . " currently used)",
                                'details' => [
                                    'total_bays' => $driveBays,
                                    'used_bays' => count($existingStorage),
                                    'requesting' => $quantity,
                                    'available' => $driveBays - count($existingStorage)
                                ]
                            ];
                        }
                    }
                    break;

                case 'pciecard':
                    // Count PCIe slots
                    $pcieSlots = $mbSpecs['expansion_slots']['pcie_slots'] ?? [];
                    $totalPcieSlots = 0;
                    foreach ($pcieSlots as $slot) {
                        $totalPcieSlots += $slot['count'] ?? 0;
                    }

                    if ($totalPcieSlots > 0) {
                        // Count existing PCIe cards
                        $existingPcie = [];
                        if (!empty($config['pciecard_configurations'])) {
                            $pcieConfigs = json_decode($config['pciecard_configurations'], true);
                            if (is_array($pcieConfigs)) {
                                $existingPcie = $pcieConfigs;
                            }
                        }
                        $totalPcie = count($existingPcie) + $quantity;

                        if ($totalPcie > $totalPcieSlots) {
                            return [
                                'valid' => false,
                                'message' => "Insufficient PCIe slots: trying to add $quantity PCIe cards but only " . ($totalPcieSlots - count($existingPcie)) . " slots available (motherboard has $totalPcieSlots total, " . count($existingPcie) . " currently used)",
                                'details' => [
                                    'total_slots' => $totalPcieSlots,
                                    'used_slots' => count($existingPcie),
                                    'requesting' => $quantity,
                                    'available' => $totalPcieSlots - count($existingPcie)
                                ]
                            ];
                        }
                    }
                    break;
            }

            return ['valid' => true];

        } catch (Exception $e) {
            error_log("Error validating component quantity: " . $e->getMessage());
            return ['valid' => true]; // Don't block on validation errors
        }
    }

    /**
     * P4.4: Detect and fix orphaned ServerUUID assignments
     * When a component shows ServerUUID but is not in that config's JSON, remove the orphaned reference
     *
     * @param string $configUuid Server configuration UUID
     * @return array Fix summary
     */
    public function fixOrphanedServerUUIDs($configUuid) {
        try {
            $fixed = [];
            $errors = [];

            // Get configuration
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                return ['success' => false, 'message' => 'Configuration not found'];
            }

            // Extract components actually in this config
            $components = $this->extractComponentsFromJson($config);
            $configComponentUuids = [];
            foreach ($components as $comp) {
                $configComponentUuids[$comp['component_type']][] = $comp['component_uuid'];
            }

            // P4.4: Check each component type table for orphaned ServerUUID
            foreach ($this->componentTables as $type => $table) {
                try {
                    $stmt = $this->pdo->prepare("SELECT UUID FROM $table WHERE ServerUUID = ?");
                    $stmt->execute([$configUuid]);
                    $rows = $stmt->fetchAll();

                    foreach ($rows as $row) {
                        $uuid = $row['UUID'];
                        // Check if this component is actually in the configuration
                        $isInConfig = in_array($uuid, $configComponentUuids[$type] ?? []);

                        if (!$isInConfig) {
                            // P4.4: ORPHANED! Clear ServerUUID
                            $updateStmt = $this->pdo->prepare("UPDATE $table SET ServerUUID = NULL, Location = NULL, RackPosition = NULL, InstallationDate = NULL WHERE UUID = ?");
                            if ($updateStmt->execute([$uuid])) {
                                $fixed[] = "Cleared orphaned ServerUUID from $type:$uuid";
                                error_log("P4.4 AUTOFIX: Cleared orphaned ServerUUID from $type:$uuid in config $configUuid");
                            } else {
                                $errors[] = "Failed to fix $type:$uuid";
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("P4.4: Error checking $table for orphaned ServerUUIDs: " . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'fixed_count' => count($fixed),
                'fixed_items' => $fixed,
                'errors' => $errors,
                'message' => count($fixed) > 0 ? "Fixed " . count($fixed) . " orphaned ServerUUID(s)" : "No orphaned ServerUUIDs found"
            ];

        } catch (Exception $e) {
            error_log("Error fixing orphaned ServerUUIDs: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to fix orphaned ServerUUIDs: ' . $e->getMessage()
            ];
        }
    }

    /**
     * P4.1: Get deterministic lock order for multiple resources
     * Prevents deadlocks by always locking in same order (alphabetical)
     *
     * @param array $resourceIds Resource identifiers to lock
     * @return array Sorted resource IDs
     */
    private function getDeterministicLockOrder($resourceIds) {
        // P4.1: Always sort to ensure consistent lock order
        sort($resourceIds);
        return $resourceIds;
    }

    /**
     * P4.1: Lock multiple resources in deterministic order
     * Always locks server_configurations first (if config exists), then components in alphabetical order
     *
     * @param string|null $configUuid Server configuration UUID (locked first)
     * @param array $componentIds Component UUIDs to lock (locked second, in sorted order)
     * @return bool True if all locks acquired
     */
    private function acquireDeterministicLocks($configUuid = null, $componentIds = []) {
        try {
            $this->activeLocks = [];
            $lockOrder = [];

            // P4.1: Lock server_configurations first (has highest priority)
            if ($configUuid) {
                $lockOrder[] = ['type' => 'config', 'id' => $configUuid];
            }

            // P4.1: Lock components in alphabetical order
            $sortedComponents = $this->getDeterministicLockOrder($componentIds);
            foreach ($sortedComponents as $uuid) {
                $lockOrder[] = ['type' => 'component', 'id' => $uuid];
            }

            // Acquire locks in determined order
            foreach ($lockOrder as $lock) {
                $startTime = microtime(true);

                if ($lock['type'] === 'config') {
                    $stmt = $this->pdo->prepare("SELECT config_uuid FROM server_configurations WHERE config_uuid = ? FOR UPDATE");
                    if (!$stmt->execute([$lock['id']])) {
                        error_log("P4.1 DEADLOCK: Failed to lock config {$lock['id']}");
                        return false;
                    }
                    $this->activeLocks[] = $lock;
                    $elapsed = (microtime(true) - $startTime) * 1000;
                    if ($elapsed > 100) {
                        error_log("P4.1 LOCK WAIT: Config lock took {$elapsed}ms");
                    }
                } elseif ($lock['type'] === 'component') {
                    // Lock in server_configuration_components table
                    $stmt = $this->pdo->prepare("SELECT component_uuid FROM server_configuration_components WHERE component_uuid = ? LIMIT 1 FOR UPDATE");
                    if (!$stmt->execute([$lock['id']])) {
                        error_log("P4.1 DEADLOCK: Failed to lock component {$lock['id']}");
                        return false;
                    }
                    $this->activeLocks[] = $lock;
                    $elapsed = (microtime(true) - $startTime) * 1000;
                    if ($elapsed > 100) {
                        error_log("P4.1 LOCK WAIT: Component lock took {$elapsed}ms");
                    }
                }
            }

            return true;

        } catch (Exception $e) {
            error_log("P4.1: Error acquiring deterministic locks: " . $e->getMessage());
            return false;
        }
    }

    /**
     * P4.1: Release all locks in reverse order
     * (locks are released in transaction rollback/commit, this is for logging/cleanup)
     *
     * @return void
     */
    private function releaseDeterministicLocks() {
        // P4.1: Locks are released in reverse order by transaction management
        // This method is for logging/cleanup purposes
        $this->activeLocks = [];
    }

    /**
     * P3.4: Recalculate form factor lock when chassis or storage is removed
     * If only one storage form factor remains, set lock. If no storage, clear lock.
     *
     * @param string $configUuid Server configuration UUID
     * @return void
     */
    private function recalculateFormFactorLock($configUuid) {
        try {
            require_once __DIR__ . '/../shared/DataExtractionUtilities.php';
            $dataUtils = new DataExtractionUtilities();

            // Get current configuration
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                return;
            }

            // Extract storage components from JSON
            $storageComponents = [];
            if (!empty($config['storage_configurations'])) {
                $storageConfigs = json_decode($config['storage_configurations'], true);
                if (is_array($storageConfigs)) {
                    $storageComponents = $storageConfigs;
                }
            }

            // Determine new form factor lock
            $formFactors = [];
            foreach ($storageComponents as $storage) {
                $storageUuid = $storage['uuid'] ?? null;
                if ($storageUuid) {
                    $storageSpecs = $dataUtils->getStorageByUUID($storageUuid);
                    if ($storageSpecs) {
                        $formFactor = strtolower($storageSpecs['form_factor'] ?? '');
                        // Normalize form factor
                        if (strpos($formFactor, '2.5') !== false) {
                            $formFactor = '2.5-inch';
                        } elseif (strpos($formFactor, '3.5') !== false) {
                            $formFactor = '3.5-inch';
                        } elseif (strpos($formFactor, 'm.') !== false || strpos($formFactor, 'm2') !== false) {
                            $formFactor = 'm.2';
                        }
                        $formFactors[$formFactor] = true;
                    }
                }
            }

            // Form factor lock is informational only (no DB update needed here)

        } catch (Exception $e) {
            error_log("Error recalculating form factor lock: " . $e->getMessage());
        }
    }
}
