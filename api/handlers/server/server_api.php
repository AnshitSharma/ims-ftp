<?php
require_once __DIR__ . '/../../../core/config/app.php';
require_once __DIR__ . '/../../../core/helpers/BaseFunctions.php';
require_once __DIR__ . '/../../../core/models/server/ServerBuilder.php';
require_once __DIR__ . '/../../../core/models/server/ServerConfiguration.php';
require_once __DIR__ . '/../../../core/models/compatibility/UnifiedSlotTracker.php';


header('Content-Type: application/json');

// Initialize database connection and authentication
global $pdo;
if (!$pdo) {
    require_once __DIR__ . '/../../../core/config/app.php';
}

$user = authenticateWithJWT($pdo);

if (!$user) {
    send_json_response(0, 0, 401, "Authentication required");
}

// Initialize ServerBuilder with enhanced error handling
try {
    if (!$pdo) {
        throw new Exception("Database connection not available");
    }
    $serverBuilder = new ServerBuilder($pdo);
    error_log("ServerBuilder initialized successfully");
} catch (Exception $e) {
    error_log("Failed to initialize ServerBuilder: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    send_json_response(0, 1, 500, "Server system unavailable: " . $e->getMessage());
}

// Get action from global operation or POST data
global $operation;
$action = $operation ?? $_POST['action'] ?? '';

switch ($action) {
    case 'create-start':
    case 'server-create-start':
        handleCreateStart($serverBuilder, $user);
        break;
    
    case 'add-component':
    case 'server-add-component':
        handleAddComponent($serverBuilder, $user);
        break;
    
    case 'remove-component':
    case 'server-remove-component':
        handleRemoveComponent($serverBuilder, $user);
        break;
    
    case 'get-config':
    case 'server-get-config':
        handleGetConfiguration($serverBuilder, $user);
        break;
    
    case 'list-configs':
    case 'server-list-configs':
        handleListConfigurations($serverBuilder, $user);
        break;

    case 'import-virtual':
    case 'server-import-virtual':
        handleImportVirtual($serverBuilder, $user);
        break;

    case 'finalize-config':
    case 'server-finalize-config':
        handleFinalizeConfiguration($serverBuilder, $user);
        break;
    
    case 'delete-config':
    case 'server-delete-config':
        handleDeleteConfiguration($serverBuilder, $user);
        break;
    
    case 'get-available-components':
    case 'server-get-available-components':
        handleGetAvailableComponents($user);
        break;
    
    case 'validate-config':
    case 'server-validate-config':
        handleValidateConfiguration($serverBuilder, $user);
        break;
    
    case 'get-compatible':
    case 'server-get-compatible':
        handleGetCompatible($serverBuilder, $user);
        break;
    
    // SFP Module Management Endpoints
    case 'add-sfp':
    case 'server-add-sfp':
        handleAddSFP($serverBuilder, $user);
        break;
    
    case 'remove-sfp':
    case 'server-remove-sfp':
        handleRemoveSFP($serverBuilder, $user);
        break;
    
    case 'assign-sfp-to-nic':
    case 'server-assign-sfp-to-nic':
        handleAssignSFPToNIC($serverBuilder, $user);
        break;
    
    case 'get-compatible-sfps':
    case 'server-get-compatible-sfps':
        handleGetCompatibleSFPs($serverBuilder, $user);
        break;
    
    // NEW: Update configuration endpoint
    case 'update-config':
    case 'server-update-config':
        handleUpdateConfiguration($serverBuilder, $user);
        break;
    
    // NEW: Update server location and propagate to components
    case 'update-location':
    case 'server-update-location':
        handleUpdateLocationAndPropagate($serverBuilder, $user);
        break;

    // Replace onboard NIC with component NIC
    case 'replace-onboard-nic':
    case 'server-replace-onboard-nic':
        handleReplaceOnboardNIC($user);
        break;

    // Fix/Sync onboard NICs for existing configurations
    case 'fix-onboard-nics':
    case 'server-fix-onboard-nics':
        handleFixOnboardNICs($user);
        break;

    // Debug endpoint to check motherboard specs
    case 'debug-motherboard-nics':
    case 'server-debug-motherboard-nics':
        handleDebugMotherboardNICs($user);
        break;

    default:
        send_json_response(0, 1, 400, "Invalid action specified");
}

/**
 * NEW: Update server configuration details
 * Updates all editable fields in server_configurations table except compatibility_score and validation_results
 */
function handleUpdateConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Load the configuration to verify it exists and check permissions
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check if user owns this configuration or has edit permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to modify this configuration");
        }
        
        // Get current configuration status to prevent updates on finalized configs
        $currentStatus = $config->get('configuration_status');
        $requestedStatus = isset($_POST['configuration_status']) ? (int)$_POST['configuration_status'] : null;
        
        // Prevent modification of finalized configurations (status 3) unless admin
        if ($currentStatus == 3 && !hasPermission($pdo, 'server.edit_finalized', $user['id'])) {
            send_json_response(0, 1, 403, "Cannot modify finalized configurations without proper permissions");
        }
        
        // Prevent status change from finalized to lower status unless admin
        if ($currentStatus == 3 && $requestedStatus !== null && $requestedStatus < 3 && !hasPermission($pdo, 'server.edit_finalized', $user['id'])) {
            send_json_response(0, 1, 403, "Cannot change status of finalized configuration without proper permissions");
        }
        
        // Define updatable fields (excluding calculated fields)
        $updatableFields = [
            'server_name',
            'description', 
            'configuration_status',
            'location',
            'rack_position',
            'notes'
        ];
        
        // Build update query dynamically based on provided fields
        $updateFields = [];
        $updateValues = [];
        $changes = [];
        
        foreach ($updatableFields as $field) {
            if (isset($_POST[$field])) {
                $newValue = $_POST[$field];
                $currentValue = $config->get($field);
                
                // Handle special field processing
                switch ($field) {
                    case 'server_name':
                        $newValue = trim($newValue);
                        if (empty($newValue)) {
                            send_json_response(0, 1, 400, "Server name cannot be empty");
                        }
                        break;
                        
                    case 'configuration_status':
                        $newValue = $newValue !== '' ? (int)$newValue : null;
                        break;
                        
                    default:
                        $newValue = trim($newValue);
                        break;
                }
                
                // Only add to update if value has changed
                if ($newValue !== $currentValue) {
                    $updateFields[] = "$field = ?";
                    $updateValues[] = $newValue;
                    $changes[$field] = [
                        'old' => $currentValue,
                        'new' => $newValue
                    ];
                }
            }
        }
        
        // If no changes, return success without database update
        if (empty($updateFields)) {
            send_json_response(1, 1, 200, "No changes detected - configuration is already up to date", [
                'config_uuid' => $configUuid,
                'changes_made' => []
            ]);
        }
        
        // Add updated_by and updated_at fields
        $updateFields[] = "updated_by = ?";
        $updateFields[] = "updated_at = NOW()";
        $updateValues[] = $user['id'];
        
        // Execute the update
        $sql = "UPDATE server_configurations SET " . implode(', ', $updateFields) . " WHERE config_uuid = ?";
        $updateValues[] = $configUuid;
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($updateValues);
        
        if (!$result) {
            send_json_response(0, 1, 500, "Failed to update configuration");
        }
        
        // Log the update action
        logConfigurationUpdate($pdo, $configUuid, $changes, $user['id']);
        
        // Get updated configuration details
        $updatedConfig = ServerConfiguration::loadByUuid($pdo, $configUuid);
        
        // Prepare response data
        $configData = [];
        foreach ($updatableFields as $field) {
            $configData[$field] = $updatedConfig->get($field);
        }
        
        // Add metadata fields
        $configData['config_uuid'] = $configUuid;
        $configData['created_by'] = $updatedConfig->get('created_by');
        $configData['updated_by'] = $updatedConfig->get('updated_by');
        $configData['created_at'] = $updatedConfig->get('created_at');
        $configData['updated_at'] = $updatedConfig->get('updated_at');
        $configData['validation_results'] = $updatedConfig->get('validation_results');
        
        // Parse JSON fields for response (none remaining)
        
        send_json_response(1, 1, 200, "Configuration updated successfully", [
            'config_uuid' => $configUuid,
            'changes_made' => $changes,
            'total_changes' => count($changes),
            'configuration' => $configData,
            'configuration_status_text' => getConfigurationStatusText($configData['configuration_status']),
            'updated_by_user_id' => $user['id'],
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        error_log("Error updating configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to update configuration: " . $e->getMessage());
    }
}

/**
 * Start server creation process
 */
function handleCreateStart($serverBuilder, $user) {
    global $pdo;
    
    $serverName = trim($_POST['server_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'custom');
    $motherboardUuid = trim($_POST['motherboard_uuid'] ?? '');
    
    if (empty($serverName)) {
        send_json_response(0, 1, 400, "Server name is required");
    }
    
    if (empty($motherboardUuid)) {
        send_json_response(0, 1, 400, "Motherboard UUID is required to start server creation");
    }
    
    try {
        // Validate motherboard exists and is available in database
        $motherboardDetails = getComponentDetails($pdo, 'motherboard', $motherboardUuid);
        if (!$motherboardDetails) {
            send_json_response(0, 1, 404, "Motherboard not found in inventory database", [
                'motherboard_uuid' => $motherboardUuid
            ]);
        }
        
        // Check motherboard availability
        $motherboardStatus = (int)$motherboardDetails['Status'];
        if ($motherboardStatus !== 1) {
            $statusMessage = getStatusText($motherboardStatus);
            send_json_response(0, 1, 400, "Motherboard is not available", [
                'motherboard_status' => $motherboardStatus,
                'status_message' => $statusMessage,
                'motherboard_uuid' => $motherboardUuid
            ]);
        }
        
        // Create configuration
        $configUuid = $serverBuilder->createConfiguration($serverName, $user['id'], [
            'description' => $description,
            'category' => $category
        ]);
        
        // Add motherboard to configuration
        $addResult = $serverBuilder->addComponent($configUuid, 'motherboard', $motherboardUuid, [
            'quantity' => 1,
            'notes' => 'Initial motherboard for server configuration',
            'user_id' => $user['id']
        ]);
        
        if (!$addResult['success']) {
            // If motherboard addition failed, clean up the configuration
            $serverBuilder->deleteConfiguration($configUuid);
            send_json_response(0, 1, 400, "Failed to add motherboard to configuration: " . $addResult['message']);
        }
        
        // Parse motherboard specifications for component limits
        $motherboardSpecs = parseMotherboardSpecs($motherboardDetails);
        
        send_json_response(1, 1, 200, "Server configuration created successfully with motherboard", [
            'config_uuid' => $configUuid,
            'server_name' => $serverName,
            'description' => $description,
            'category' => $category,
            'motherboard_added' => [
                'uuid' => $motherboardUuid,
                'serial_number' => $motherboardDetails['SerialNumber'],
                'specifications' => $motherboardSpecs
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error in server creation start: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to initialize server creation: " . $e->getMessage());
    }
}


/**
 * FIXED: Add component to server configuration with proper ServerUUID handling
 */
function handleAddComponent($serverBuilder, $user) {
    global $pdo;

    try {
        // Enhanced logging for debugging
        error_log("=== SERVER ADD COMPONENT REQUEST ===");
        error_log("POST data: " . json_encode($_POST));

        $configUuid = $_POST['config_uuid'] ?? '';
        $componentType = $_POST['component_type'] ?? '';
        $componentUuid = $_POST['component_uuid'] ?? '';
        $serialNumber = $_POST['serial_number'] ?? null; // CRITICAL: Accept serial_number to identify specific physical component
        $quantity = (int)($_POST['quantity'] ?? 1);
        $slotPosition = $_POST['slot_position'] ?? null;
        $notes = $_POST['notes'] ?? '';
        $override = filter_var($_POST['override'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $serialInfo = $serialNumber ? " Serial: $serialNumber" : "";
    error_log("Parsed parameters - Config: $configUuid, Type: $componentType, UUID: $componentUuid$serialInfo, Qty: $quantity");
    
    if (empty($configUuid) || empty($componentType) || empty($componentUuid)) {
        error_log("Missing required parameters");
        send_json_response(0, 1, 400, "Configuration UUID, component type, and component UUID are required");
    }

    // Basic component type validation
    $validComponentTypes = ['chassis', 'cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy', 'pciecard', 'hbacard', 'sfp'];
    if (!in_array($componentType, $validComponentTypes)) {
        send_json_response(0, 1, 400, "Invalid component type. Valid types: " . implode(', ', $validComponentTypes));
    }

    // SFP-specific parameter validation
    $parentNicUuid = null;
    $portIndex = null;
    if ($componentType === 'sfp') {
        $parentNicUuid = $_POST['parent_nic_uuid'] ?? null;
        $portIndex = isset($_POST['port_index']) ? (int)$_POST['port_index'] : null;

        if (empty($parentNicUuid)) {
            send_json_response(0, 1, 400, "parent_nic_uuid is required for SFP modules", [
                'hint' => 'Specify which NIC card this SFP should be installed in'
            ]);
        }

        if (empty($portIndex) || $portIndex < 1) {
            send_json_response(0, 1, 400, "port_index is required for SFP modules and must be >= 1", [
                'hint' => 'Specify which port number on the NIC (1, 2, 3, etc.)'
            ]);
        }

        error_log("SFP parameters - Parent NIC: $parentNicUuid, Port: $portIndex");
    }

    try {
        // Load the configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check if user owns this configuration or has edit permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to modify this configuration");
        }
        
        // EXISTING: Component existence validation (MODIFY to use new function)
        $componentValidation = validateComponentExists($componentType, $componentUuid);
        if (!$componentValidation['exists']) {
            send_json_response(0, 1, 404, $componentValidation['message'], [
                'component_type' => $componentType,
                'component_uuid' => $componentUuid
            ]);
        }

        if (!$componentValidation['available']) {
            send_json_response(0, 1, 400, "Component is not available", [
                'component_status' => $componentValidation['component']['Status'],
                'component_type' => $componentType,
                'component_uuid' => $componentUuid
            ]);
        }

        // Get component details for legacy compatibility
        $componentDetails = $componentValidation['component'];
        
        // FIXED: Enhanced availability check with ServerUUID context
        $componentStatus = (int)$componentDetails['Status'];
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
                // Check if component is in same config or different config
                if ($componentServerUuid === $configUuid) {
                    $isAvailable = true;
                    $statusMessage = "Component is already assigned to this configuration";
                } else {
                    if ($override) {
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
        
        if (!$isAvailable && !$override) {
            send_json_response(0, 1, 400, "Component is not available", [
                'component_status' => $componentStatus,
                'status_message' => $statusMessage,
                'component_server_uuid' => $componentServerUuid,
                'current_config_uuid' => $configUuid,
                'component_details' => [
                    'uuid' => $componentDetails['UUID'],
                    'serial_number' => $componentDetails['SerialNumber'],
                    'current_status' => getStatusText($componentStatus)
                ],
                'can_override' => $componentStatus === 2,
                'suggested_alternatives' => getSuggestedAlternatives($pdo, $componentType, $componentUuid)
            ]);
        }
            
        // Compatibility Validation using ComponentCompatibility directly
        error_log("Starting compatibility validation for $componentType");

        try {
            require_once __DIR__ . '/../../../core/models/compatibility/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($pdo);

            // Get existing components for validation
            $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
            if (!$config) {
                throw new Exception("Configuration not found");
            }

            $existingComponents = extractComponentsFromConfigData($config->getData());
            $existingComponentsData = [];

            foreach ($existingComponents as $existing) {
                $tableMap = [
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

                $table = $tableMap[$existing['component_type']];
                $stmt = $pdo->prepare("SELECT * FROM $table WHERE UUID = ? LIMIT 1");
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

            // Perform compatibility check based on component type
            $compatibilityResult = null;
            $newComponent = ['uuid' => $componentUuid, 'type' => $componentType];

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
                default:
                    $compatibilityResult = ['compatible' => true, 'warnings' => [], 'recommendations' => []];
            }

            error_log("Validation result: " . json_encode($compatibilityResult));

            // Check if component is incompatible
            if (!$compatibilityResult['compatible']) {
                // Critical errors - BLOCK addition
                $errorDetails = array_merge(
                    $compatibilityResult['details'] ?? [],
                    $compatibilityResult['issues'] ?? []
                );

                send_json_response(0, 1, 400,
                    "Cannot add component due to compatibility issues",
                    [
                        'component_type' => $componentType,
                        'component_uuid' => $componentUuid,
                        'validation_status' => 'blocked',
                        'compatibility_summary' => $compatibilityResult['compatibility_summary'] ?? 'Incompatible with existing components',
                        'critical_errors' => $errorDetails,
                        'recommendation' => implode('; ', $compatibilityResult['recommendations'] ?? [])
                    ]
                );
            }

            // Store warnings for response (will be added if successful)
            $validationWarnings = $compatibilityResult['warnings'] ?? [];
            $validationInfo = $compatibilityResult['recommendations'] ?? [];

            if (!empty($validationWarnings)) {
                error_log("Validation warnings: " . json_encode($validationWarnings));
            }

        } catch (Exception $validationError) {
            error_log("Compatibility validation error: " . $validationError->getMessage());
            error_log("Stack trace: " . $validationError->getTraceAsString());
            // Continue with addition - don't block on validation errors
            $validationWarnings = [];
            $validationInfo = ["Validation service unavailable - component added without compatibility checks"];
        }

        // NEW: Riser Card and Motherboard Specific Validation
        if (!class_exists('ComponentDataExtractor')) {
            require_once __DIR__ . '/../../../core/models/components/ComponentDataExtractor.php';
        }
        if (!class_exists('ComponentDataLoader')) {
            require_once __DIR__ . '/../../../core/models/components/ComponentDataLoader.php';
        }
        if (!class_exists('ComponentValidator')) {
            require_once __DIR__ . '/../../../core/models/components/ComponentValidator.php';
        }

        $dataExtractor = new ComponentDataExtractor();
        $dataLoader = new ComponentDataLoader($pdo, $dataExtractor);
        $componentValidator = new ComponentValidator($pdo, $dataLoader, $dataExtractor);

        // Get existing components in config for riser validation
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        $existingComponents = $config ? extractComponentsFromConfigData($config->getData()) : [];

        if ($componentType === 'pciecard') {
            // Validate adding riser card
            $riserValidation = $componentValidator->validateAddRiserCard(
                ['uuid' => $componentUuid, 'type' => 'pcie_card'],
                $existingComponents
            );

            if (!$riserValidation['valid']) {
                error_log("Riser card validation failed: " . $riserValidation['error']);
                send_json_response(0, 1, 400,
                    "Cannot add riser card due to compatibility issues",
                    [
                        'component_type' => $componentType,
                        'component_uuid' => $componentUuid,
                        'validation_status' => 'blocked',
                        'error' => $riserValidation['error']
                    ]
                );
            }
        }

        // SFP Port Validation
        if ($componentType === 'sfp') {
            require_once __DIR__ . '/../../../core/models/compatibility/NICPortTracker.php';
            require_once __DIR__ . '/../../../core/models/components/ComponentDataService.php';

            $portTracker = new NICPortTracker($pdo);
            $componentDataService = ComponentDataService::getInstance();

            // 1. Check if parent NIC exists in configuration
            $nicExists = false;
            foreach ($existingComponents as $comp) {
                if ($comp['type'] === 'nic' && $comp['uuid'] === $parentNicUuid) {
                    $nicExists = true;
                    break;
                }
            }

            if (!$nicExists) {
                send_json_response(0, 1, 400,
                    "Parent NIC not found in configuration",
                    [
                        'parent_nic_uuid' => $parentNicUuid,
                        'hint' => 'The specified NIC must be added to the configuration first'
                    ]
                );
            }

            // 2. Check if port is available
            if (!$portTracker->isPortAvailable($configUuid, $parentNicUuid, $portIndex)) {
                send_json_response(0, 1, 400,
                    "Port {$portIndex} on NIC is already occupied",
                    [
                        'parent_nic_uuid' => $parentNicUuid,
                        'port_index' => $portIndex,
                        'hint' => 'Choose a different port number or remove the existing SFP first'
                    ]
                );
            }

            // 3. Validate SFP type compatibility with NIC port type
            $nicSpecs = $componentDataService->getComponentSpecs('nic', $parentNicUuid);
            $sfpSpecs = $componentDataService->getComponentSpecs('sfp', $componentUuid);

            if (!$nicSpecs || !isset($nicSpecs['port_type'])) {
                send_json_response(0, 1, 500,
                    "Unable to load NIC specifications",
                    ['parent_nic_uuid' => $parentNicUuid]
                );
            }

            if (!$sfpSpecs || !isset($sfpSpecs['type'])) {
                send_json_response(0, 1, 500,
                    "Unable to load SFP specifications",
                    ['component_uuid' => $componentUuid]
                );
            }

            $nicPortType = $nicSpecs['port_type'];
            $sfpType = $sfpSpecs['type'];

            if (!NICPortTracker::isCompatible($nicPortType, $sfpType)) {
                send_json_response(0, 1, 400,
                    "SFP module type incompatible with NIC port type",
                    [
                        'nic_port_type' => $nicPortType,
                        'sfp_type' => $sfpType,
                        'nic_model' => $nicSpecs['model'] ?? 'Unknown',
                        'sfp_model' => $sfpSpecs['model'] ?? 'Unknown',
                        'hint' => "This NIC has {$nicPortType} ports which cannot accept {$sfpType} modules"
                    ]
                );
            }

            error_log("SFP port validation passed - NIC: $parentNicUuid, Port: $portIndex, Types: {$nicPortType} <- {$sfpType}");
        }

        if ($componentType === 'motherboard') {
            // Validate adding motherboard (check if one already exists)
            $motherboardValidation = $componentValidator->validateAddMotherboard(
                ['uuid' => $componentUuid, 'type' => 'motherboard'],
                $existingComponents
            );

            if (!$motherboardValidation['valid']) {
                error_log("Motherboard validation failed: " . $motherboardValidation['error']);
                send_json_response(0, 1, 400,
                    "Cannot add motherboard",
                    [
                        'component_type' => $componentType,
                        'component_uuid' => $componentUuid,
                        'validation_status' => 'blocked',
                        'error' => $motherboardValidation['error']
                    ]
                );
            }
        }

        if ($componentType === 'chassis') {
            // Validate adding chassis (check if one already exists)
            $chassisValidation = $componentValidator->validateAddChassis(
                ['uuid' => $componentUuid, 'type' => 'chassis'],
                $existingComponents
            );

            if (!$chassisValidation['valid']) {
                error_log("Chassis validation failed: " . $chassisValidation['error']);
                send_json_response(0, 1, 400,
                    "Cannot add chassis",
                    [
                        'component_type' => $componentType,
                        'component_uuid' => $componentUuid,
                        'validation_status' => 'blocked',
                        'error' => $chassisValidation['error']
                    ]
                );
            }
        }

        if ($componentType === 'hbacard') {
            // Validate adding HBA card - check storage device compatibility and PCIe slots
            if (!class_exists('ComponentCompatibility')) {
                require_once __DIR__ . '/../../../core/models/compatibility/ComponentCompatibility.php';
            }
            $componentCompatibility = new ComponentCompatibility($pdo);

            $hbaValidation = $componentCompatibility->checkHBADecentralizedCompatibility(
                ['uuid' => $componentUuid, 'type' => 'hbacard'],
                array_map(function($comp) {
                    return [
                        'type' => $comp['component_type'],
                        'uuid' => $comp['component_uuid']
                    ];
                }, $existingComponents)
            );

            if (!$hbaValidation['compatible']) {
                error_log("HBA card validation failed: " . json_encode($hbaValidation['issues']));
                send_json_response(0, 1, 400,
                    "Cannot add HBA card due to compatibility issues",
                    [
                        'component_type' => $componentType,
                        'component_uuid' => $componentUuid,
                        'validation_status' => 'blocked',
                        'errors' => $hbaValidation['issues'],
                        'warnings' => $hbaValidation['warnings'] ?? [],
                        'recommendations' => $hbaValidation['recommendations'] ?? [],
                        'compatibility_summary' => $hbaValidation['compatibility_summary']
                    ]
                );
            }

            // Add warnings to response if any
            if (!empty($hbaValidation['warnings'])) {
                $validationWarnings = array_merge($validationWarnings, $hbaValidation['warnings']);
            }
        }

        // Expansion slot assignment for PCIe cards, NICs, HBA cards, and Risers
        $assignedSlot = null;
        if ($componentType === 'pciecard' || $componentType === 'nic' || $componentType === 'hbacard') {
            try {
                $slotTracker = new UnifiedSlotTracker($pdo);

                // Check if motherboard exists
                $stmt = $pdo->prepare("SELECT motherboard_uuid FROM server_configurations WHERE config_uuid = ?");
                $stmt->execute([$configUuid]);
                $configResult = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($configResult && !empty($configResult['motherboard_uuid'])) {
                    $motherboardResult = ['component_uuid' => $configResult['motherboard_uuid']];
                    // Get component specifications
                    require_once __DIR__ . '/../../../core/models/components/ComponentDataService.php';
                    $componentDataService = ComponentDataService::getInstance();
                    $cardSpecs = $componentDataService->getComponentSpecifications($componentType, $componentUuid, $componentDetails);

                    if ($cardSpecs) {
                        // Check component subtype to determine if riser or regular PCIe card
                        $componentSubtype = $cardSpecs['component_subtype'] ?? null;

                        // ENHANCED RISER CARD DETECTION: Also check UUID pattern as fallback
                        $isRiserCard = false;
                        if ($componentSubtype === 'Riser Card') {
                            $isRiserCard = true;
                            error_log("🔍 Riser card detected via component_subtype field for UUID=$componentUuid");
                        } elseif (stripos($componentUuid, 'riser-') === 0) {
                            // Fallback: UUID starts with "riser-"
                            $isRiserCard = true;
                            error_log("🔍 Riser card detected via UUID pattern for UUID=$componentUuid (component_subtype was: " . ($componentSubtype ?? 'NULL') . ")");
                        }

                        error_log("🔎 Component detection: UUID=$componentUuid, component_subtype=" . ($componentSubtype ?? 'NULL') . ", isRiserCard=" . ($isRiserCard ? 'YES' : 'NO'));

                        if ($isRiserCard) {
                            // ===== RISER CARD - MUST GO TO RISER SLOTS ONLY =====
                            $riserSlotSize = extractPCIeSlotSizeFromSpecs($cardSpecs);

                            error_log("🎯 RISER CARD DETECTED: UUID=$componentUuid, extracted slot size=" . ($riserSlotSize ?? 'NULL') . ", specs=" . json_encode(['interface' => $cardSpecs['interface'] ?? 'missing', 'slot_type' => $cardSpecs['slot_type'] ?? 'missing', 'component_subtype' => $componentSubtype ?? 'missing']));

                            if ($riserSlotSize) {
                                // Use size-aware assignment (returns string slot ID)
                                $assignedSlot = $slotTracker->assignRiserSlotBySize($configUuid, $riserSlotSize);
                                if ($assignedSlot) {
                                    $slotPosition = $assignedSlot;

                                    // Extract slot type from slot ID (e.g., "riser_x16_slot_1" -> "x16")
                                    $assignedSlotType = 'unknown';
                                    if (preg_match('/riser_(x\d+)_slot_/', $assignedSlot, $matches)) {
                                        $assignedSlotType = $matches[1];
                                    }

                                    error_log("✅ Assigned riser slot: $assignedSlot (type: $assignedSlotType) for riser card $componentUuid (requires: $riserSlotSize)");
                                } else {
                                    // CRITICAL: No compatible riser slots available - BLOCK the addition
                                    error_log("❌ BLOCKING: No compatible riser slots available for riser card $componentUuid (requires: $riserSlotSize)");
                                    send_json_response(0, 1, 400,
                                        "Cannot add riser card: No compatible riser slots available on motherboard",
                                        [
                                            'component_uuid' => $componentUuid,
                                            'component_type' => 'Riser Card',
                                            'required_slot_type' => $riserSlotSize,
                                            'error' => 'no_riser_slots_available',
                                            'message' => "This riser card requires a $riserSlotSize riser slot, but no compatible slots are available on the motherboard."
                                        ]
                                    );
                                }
                            } else {
                                // Fallback to legacy assignment if size cannot be determined
                                error_log("⚠️ Riser slot size could not be determined for $componentUuid - using legacy assignment");
                                $assignedSlot = $slotTracker->assignRiserSlot($configUuid);
                                if ($assignedSlot) {
                                    $slotPosition = $assignedSlot;
                                    error_log("✅ Assigned riser slot (legacy): $assignedSlot for riser card $componentUuid");
                                } else {
                                    // CRITICAL: No riser slots available - BLOCK the addition
                                    error_log("❌ BLOCKING: No riser slots available for riser card $componentUuid");
                                    send_json_response(0, 1, 400,
                                        "Cannot add riser card: No riser slots available on motherboard",
                                        [
                                            'component_uuid' => $componentUuid,
                                            'component_type' => 'Riser Card',
                                            'error' => 'no_riser_slots_available',
                                            'message' => "No riser slots are available on the motherboard for this riser card."
                                        ]
                                    );
                                }
                            }
                        } else {
                            // ===== REGULAR PCIe CARD/NIC - ASSIGN TO PCIe SLOTS =====
                            $cardSlotSize = extractPCIeSlotSizeFromSpecs($cardSpecs);

                            error_log("🔌 Regular PCIe card detected: UUID=$componentUuid, slot size=" . ($cardSlotSize ?? 'NULL'));

                            if ($cardSlotSize) {
                                // Assign optimal PCIe slot
                                $assignedSlot = $slotTracker->assignSlot($configUuid, $cardSlotSize);
                                if ($assignedSlot) {
                                    $slotPosition = $assignedSlot;
                                    error_log("✅ Assigned PCIe slot: $assignedSlot for card $componentUuid");
                                } else {
                                    error_log("⚠️ WARNING: No available PCIe slots for card $componentUuid (requires $cardSlotSize)");
                                }
                            }
                        }
                    }
                }
            } catch (Exception $slotError) {
                error_log("Expansion slot assignment error: " . $slotError->getMessage());
                // Continue without slot assignment
            }
        }

        // Use original component addition method
        $componentOptions = [
            'quantity' => $quantity,
            'serial_number' => $serialNumber, // CRITICAL: Pass serial_number to identify specific physical component
            'slot_position' => $slotPosition,
            'notes' => $notes,
            'override_used' => $override
        ];

        // Add SFP-specific parameters
        if ($componentType === 'sfp') {
            $componentOptions['parent_nic_uuid'] = $parentNicUuid;
            $componentOptions['port_index'] = $portIndex;
        }

        $result = $serverBuilder->addComponent($configUuid, $componentType, $componentUuid, $componentOptions);

        // VALIDATION: If this is a riser card and slot_position was manually provided (not auto-assigned),
        // ensure it follows the 'riser_' prefix convention
        // IMPORTANT: Skip this if slot was auto-assigned by the system (starts with "riser_x")
        if ($result['success'] && $componentType === 'pciecard' && !empty($_POST['slot_position'])) {
            try {
                require_once __DIR__ . '/../../../core/models/shared/DataExtractionUtilities.php';
                $dataUtils = new DataExtractionUtilities($pdo);
                $cardSpecs = $dataUtils->getPCIeCardByUUID($componentUuid);

                $isRiserCard = isset($cardSpecs['component_subtype']) &&
                               $cardSpecs['component_subtype'] === 'Riser Card';

                if ($isRiserCard) {
                    $providedSlotPosition = $_POST['slot_position'];

                    // Check if this was auto-assigned by our new system (format: riser_x16_slot_1)
                    if (preg_match('/^riser_x\d+_slot_\d+$/', $slotPosition)) {
                        // This is a valid auto-assigned slot - don't touch it!
                        error_log("✅ Riser slot auto-assigned correctly: $slotPosition - skipping validation");
                    }
                    // Check if provided slot_position follows riser_ prefix convention but is old format
                    else if (strpos($providedSlotPosition, 'riser_') !== 0) {
                        // DEPRECATED: This old auto-correction should not run anymore
                        // The new system assigns proper slot IDs directly
                        error_log("⚠️ WARNING: User provided invalid riser slot format: '$providedSlotPosition' - this should be auto-assigned!");
                    }
                }
            } catch (Exception $correctionError) {
                error_log("Error during slot_position validation: " . $correctionError->getMessage());
                // Continue - don't block on this validation
            }
        }

        // POST-INSERT VALIDATION: Verify riser cards are in riser slots
        if ($result['success'] && $componentType === 'pciecard' && $slotPosition) {
            try {
                // Check if this component is a riser card
                require_once __DIR__ . '/../../../core/models/components/ComponentDataService.php';
                $componentDataService = ComponentDataService::getInstance();
                $cardSpecs = $componentDataService->getComponentSpecifications($componentType, $componentUuid, $componentDetails);

                $componentSubtype = $cardSpecs['component_subtype'] ?? null;
                $isRiserCard = ($componentSubtype === 'Riser Card') || (stripos($componentUuid, 'riser-') === 0);

                if ($isRiserCard) {
                    // Riser card MUST be in a riser slot (starts with "riser_")
                    if (strpos($slotPosition, 'riser_') !== 0) {
                        // CRITICAL ERROR: Riser card was assigned to non-riser slot!
                        error_log("❌ CRITICAL: Riser card $componentUuid was incorrectly assigned to slot $slotPosition (should be riser_*)");

                        // Rollback the insert
                        $serverBuilder->removeComponent($configUuid, $componentType, $componentUuid);

                        send_json_response(0, 1, 500,
                            "System error: Riser card was incorrectly assigned to a PCIe slot. Please contact support.",
                            [
                                'component_uuid' => $componentUuid,
                                'incorrect_slot' => $slotPosition,
                                'error' => 'riser_card_in_pcie_slot',
                                'message' => "Riser cards must be assigned to riser slots only. This is a system error."
                            ]
                        );
                    } else {
                        error_log("✅ POST-INSERT VALIDATION PASSED: Riser card $componentUuid correctly in slot $slotPosition");
                    }
                }
            } catch (Exception $validationError) {
                error_log("Error during post-insert validation: " . $validationError->getMessage());
                // Continue - don't block on validation errors
            }
        }

        if ($result['success']) {
            // Simple success response - avoid complex operations that could fail
            error_log("Component added successfully: $componentType/$componentUuid to config $configUuid");

            // AUTO-ASSIGNMENT TRIGGER: If NIC was added, check for unassigned SFPs and auto-assign (Requirement #2)
            if ($componentType === 'nic') {
                try {
                    // Get server configuration to check for unassigned SFPs
                    $stmt = $pdo->prepare("SELECT sfp_configuration FROM server_configurations WHERE config_uuid = ?");
                    $stmt->execute([$configUuid]);
                    $configData = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($configData && !empty($configData['sfp_configuration'])) {
                        $sfpConfig = json_decode($configData['sfp_configuration'], true);
                        $unassignedSfps = $sfpConfig['unassigned_sfps'] ?? [];

                        if (!empty($unassignedSfps)) {
                            error_log("Found " . count($unassignedSfps) . " unassigned SFPs - attempting auto-assignment to new NIC $componentUuid");

                            require_once __DIR__ . '/../../../core/models/compatibility/SFPCompatibilityResolver.php';
                            $resolver = new SFPCompatibilityResolver($pdo);

                            // Extract SFP UUIDs
                            $sfpUuids = array_map(function($sfp) {
                                return $sfp['uuid'];
                            }, $unassignedSfps);

                            // Try to auto-assign to the new NIC
                            $assignmentResult = $resolver->autoAssignSFPsToNIC($configUuid, $componentUuid, $sfpUuids);

                            if ($assignmentResult['success']) {
                                // Update configuration
                                $assignedSfps = $sfpConfig['sfps'] ?? [];
                                foreach ($assignmentResult['assignments'] as $assignment) {
                                    $assignedSfps[] = $assignment;

                                    // Update SFP inventory
                                    $stmt = $pdo->prepare("UPDATE sfpinventory SET ParentNICUUID = ?, PortIndex = ?, UpdatedAt = NOW() WHERE UUID = ?");
                                    $stmt->execute([$assignment['parent_nic_uuid'], $assignment['port_index'], $assignment['uuid']]);
                                }

                                // Clear unassigned SFPs (they are now assigned)
                                $sfpConfig['sfps'] = $assignedSfps;
                                $sfpConfig['unassigned_sfps'] = [];

                                $stmt = $pdo->prepare("UPDATE server_configurations SET sfp_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
                                $stmt->execute([json_encode($sfpConfig), $configUuid]);

                                error_log("✅ Auto-assigned " . count($assignmentResult['assignments']) . " SFPs to NIC $componentUuid");

                                // Add auto-assignment info to response
                                $responseData['sfp_auto_assignment'] = [
                                    'success' => true,
                                    'sfps_assigned' => count($assignmentResult['assignments']),
                                    'assignments' => $assignmentResult['assignments']
                                ];
                            } else {
                                error_log("⚠️ Auto-assignment failed: " . json_encode($assignmentResult['errors'] ?? []));
                                // Don't fail the NIC addition - just warn
                                $responseData['sfp_auto_assignment'] = [
                                    'success' => false,
                                    'message' => 'NIC added but SFPs could not be auto-assigned',
                                    'errors' => $assignmentResult['errors'] ?? [],
                                    'suggestions' => $assignmentResult['suggestions'] ?? []
                                ];
                            }
                        }
                    }
                } catch (Exception $autoAssignError) {
                    error_log("Error during SFP auto-assignment: " . $autoAssignError->getMessage());
                    // Don't fail the NIC addition - just log the error
                    $responseData['sfp_auto_assignment'] = [
                        'success' => false,
                        'error' => $autoAssignError->getMessage()
                    ];
                }
            }

            // Build response data
            $responseData = [
                'component_added' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'status_override_used' => $override,
                    'original_status' => $statusMessage ?? 'Unknown',
                    'server_uuid_updated' => $configUuid,
                    'slot_position' => $slotPosition ?? 'Not assigned'
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Add validation results from FlexibleCompatibilityValidator
            if (isset($validationWarnings) && !empty($validationWarnings)) {
                $responseData['validation_warnings'] = $validationWarnings;
            }

            if (isset($validationInfo) && !empty($validationInfo)) {
                $responseData['validation_info'] = $validationInfo;
            }

            // Add expansion slot assignment info if applicable
            if ($assignedSlot && ($componentType === 'pciecard' || $componentType === 'nic')) {
                // Determine if riser slot or PCIe slot
                if (strpos($assignedSlot, 'riser_') === 0) {
                    // Riser slot assignment
                    $responseData['riser_slot_assignment'] = [
                        'slot_assigned' => $assignedSlot,
                        'slot_type' => 'riser_slot'
                    ];

                    // Get updated riser slot availability
                    if (isset($slotTracker)) {
                        $updatedAvailability = $slotTracker->getRiserSlotAvailability($configUuid);
                        if ($updatedAvailability['success']) {
                            $responseData['riser_slot_assignment']['remaining_riser_slots'] = $updatedAvailability['available_slots'];

                            // Count total slots across all types (grouped format)
                            $totalRiserSlots = 0;
                            foreach ($updatedAvailability['total_slots'] as $slotType => $slotIds) {
                                $totalRiserSlots += count($slotIds);
                            }
                            $responseData['riser_slot_assignment']['total_riser_slots'] = $totalRiserSlots;
                        }
                    }
                } else {
                    // PCIe slot assignment
                    $responseData['pcie_slot_assignment'] = [
                        'slot_assigned' => $assignedSlot,
                        'slot_type' => extractSlotTypeFromSlotId($assignedSlot)
                    ];

                    // Get updated PCIe slot availability
                    if (isset($slotTracker)) {
                        $updatedAvailability = $slotTracker->getSlotAvailability($configUuid);
                        if ($updatedAvailability['success']) {
                            $responseData['pcie_slot_assignment']['remaining_slots'] = $updatedAvailability['available_slots'];
                        }
                    }
                }
            }
            
            // Enhanced RAM compatibility response structure as specified in Important-fix
            if ($componentType === 'ram' && isset($result['warnings']) && isset($result['compatibility_details'])) {
                $compatibilityDetails = $result['compatibility_details'];
                $frequencyAnalysis = $compatibilityDetails['frequency_analysis'] ?? [];
                
                $responseData['ram_compatibility'] = [
                    'memory_type' => [
                        'compatible' => $compatibilityDetails['memory_type']['compatible'] ?? true,
                        'message' => $compatibilityDetails['memory_type']['message'] ?? 'Memory type compatible'
                    ],
                    'frequency_analysis' => [
                        'ram_frequency' => $frequencyAnalysis['ram_frequency'] ?? 0,
                        'system_max_frequency' => $frequencyAnalysis['system_max_frequency'] ?? 0,
                        'effective_frequency' => $frequencyAnalysis['effective_frequency'] ?? 0,
                        'limiting_component' => $frequencyAnalysis['limiting_component'] ?? null,
                        'status' => $frequencyAnalysis['status'] ?? 'unknown',
                        'performance_impact' => $frequencyAnalysis['performance_impact'] ?? null
                    ],
                    'form_factor' => [
                        'compatible' => $compatibilityDetails['form_factor']['compatible'] ?? true,
                        'message' => $compatibilityDetails['form_factor']['message'] ?? 'Form factor compatible'
                    ],
                    'ecc_support' => [
                        'compatible' => $compatibilityDetails['ecc_support']['compatible'] ?? true,
                        'message' => $compatibilityDetails['ecc_support']['message'] ?? 'ECC configuration validated',
                        'warning' => $compatibilityDetails['ecc_support']['warning'] ?? null,
                        'recommendation' => $compatibilityDetails['ecc_support']['recommendation'] ?? null
                    ],
                    'slot_availability' => [
                        'available_slots' => $compatibilityDetails['slot_availability']['available_slots'] ?? 0,
                        'max_slots' => $compatibilityDetails['slot_availability']['max_slots'] ?? 4,
                        'used_slots' => $compatibilityDetails['slot_availability']['used_slots'] ?? 0,
                        'can_add_more' => $compatibilityDetails['slot_availability']['can_add'] ?? false
                    ]
                ];
                
                // Include performance warnings in main response
                if (!empty($result['warnings'])) {
                    $responseData['performance_warnings'] = $result['warnings'];
                }
                
                // Show effective operating frequency in component specifications
                if ($frequencyAnalysis['effective_frequency'] !== $frequencyAnalysis['ram_frequency']) {
                    $responseData['component_added']['effective_operating_frequency'] = $frequencyAnalysis['effective_frequency'] . 'MHz';
                    $responseData['component_added']['rated_frequency'] = $frequencyAnalysis['ram_frequency'] . 'MHz';
                }
            }

            // AUTO-ADD ONBOARD NICs when motherboard is added
            if ($componentType === 'motherboard') {
                try {
                    require_once __DIR__ . '/../../../core/models/compatibility/OnboardNICHandler.php';
                    $nicHandler = new OnboardNICHandler($pdo);

                    $onboardNICResult = $nicHandler->autoAddOnboardNICs($configUuid, $componentUuid);

                    if ($onboardNICResult['count'] > 0) {
                        $responseData['onboard_nics_added'] = $onboardNICResult;
                        error_log("Auto-added {$onboardNICResult['count']} onboard NIC(s) from motherboard $componentUuid");
                    } else {
                        error_log("No onboard NICs found in motherboard $componentUuid or already added");
                    }
                } catch (Exception $nicError) {
                    error_log("Error auto-adding onboard NICs: " . $nicError->getMessage());
                    // Don't fail the motherboard addition if onboard NIC addition fails
                    $responseData['onboard_nic_warning'] = "Motherboard added but onboard NICs could not be auto-added: " . $nicError->getMessage();
                }
            }

            // UPDATE NIC CONFIG when component NIC is added
            if ($componentType === 'nic') {
                try {
                    require_once __DIR__ . '/../../../core/models/compatibility/OnboardNICHandler.php';
                    $nicHandler = new OnboardNICHandler($pdo);
                    $nicHandler->updateNICConfigJSON($configUuid);
                    error_log("Updated NIC configuration JSON after adding component NIC $componentUuid");
                } catch (Exception $nicError) {
                    error_log("Error updating NIC config: " . $nicError->getMessage());
                    // Don't fail the NIC addition if config update fails
                }
            }

            send_json_response(1, 1, 200, "Component added successfully", $responseData);
        } else {
            // Error response with recommendations
            $errorType = $result['error_type'] ?? 'unknown';
            $errorMessage = $result['message'] ?? "Failed to add component";

            $errorDetails = [
                'component_type' => $componentType,
                'component_uuid' => $componentUuid,
                'error_type' => $errorType
            ];
            
            // Add recommendation based on error type
            if (isset($result['recommendation'])) {
                $errorDetails['recommendation'] = $result['recommendation'];
            }

            // Add warnings if present
            if (isset($result['warnings']) && !empty($result['warnings'])) {
                $errorDetails['warnings'] = $result['warnings'];
            }

            // Add details if present
            if (isset($result['details'])) {
                $errorDetails['details'] = $result['details'];
            }

            // Add specific recommendations based on error type
            switch ($errorType) {
                case 'socket_mismatch':
                    if (!isset($errorDetails['recommendation'])) {
                        $errorDetails['recommendation'] = 'Use components with matching socket types';
                    }
                    break;

                case 'cpu_limit_exceeded':
                    if (!isset($errorDetails['recommendation'])) {
                        $errorDetails['recommendation'] = 'Remove existing CPU or use multi-socket motherboard';
                    }
                    break;

                case 'duplicate_component':
                    if (!isset($errorDetails['recommendation'])) {
                        $errorDetails['recommendation'] = 'Component already exists in this configuration';
                    }
                    break;
            }

            send_json_response(0, 1, 400, $errorMessage, $errorDetails);
        }
        
    } catch (Exception $e) {
        error_log("Error adding component: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        error_log("Component details: Type=$componentType, UUID=$componentUuid, ConfigUUID=$configUuid");
        
        // Send detailed error for debugging instead of generic 500
        send_json_response(0, 1, 500, "Internal server error: " . $e->getMessage(), [
            'error_type' => 'exception_thrown',
            'component_type' => $componentType,
            'component_uuid' => $componentUuid,
            'config_uuid' => $configUuid,
            'error_details' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'debug_info' => [
                'php_version' => PHP_VERSION,
                'timestamp' => date('Y-m-d H:i:s'),
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
            ]
        ]);
    }

    } catch (Exception $e) {
        error_log("FATAL ERROR in handleAddComponent: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
        send_json_response(0, 1, 500, "Component addition failed: " . $e->getMessage(), [
            'error_file' => basename($e->getFile()),
            'error_line' => $e->getLine(),
            'error_type' => get_class($e)
        ]);
    } catch (Throwable $t) {
        error_log("FATAL PHP ERROR in handleAddComponent: " . $t->getMessage());
        error_log("Stack trace: " . $t->getTraceAsString());
        error_log("File: " . $t->getFile() . " Line: " . $t->getLine());
        send_json_response(0, 1, 500, "Fatal error: " . $t->getMessage(), [
            'error_file' => basename($t->getFile()),
            'error_line' => $t->getLine(),
            'error_type' => get_class($t)
        ]);
    }
}

/**
 * Remove component from server configuration
 */
function handleRemoveComponent($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? '';
    
    if (empty($configUuid) || empty($componentType) || empty($componentUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID, component type, and component UUID are required");
    }
    
    try {
        // Load the configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to modify this configuration");
        }

        // NIC removal validation: Check if any SFPs are installed on ports
        if ($componentType === 'nic') {
            // Load SFP configuration to check for port assignments
            $stmt = $pdo->prepare("SELECT sfp_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $sfpConfigJson = $stmt->fetchColumn();

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
                    send_json_response(0, 1, 400,
                        "Cannot remove NIC - " . count($occupiedPorts) . " SFP module(s) installed on ports",
                        [
                            'nic_uuid' => $componentUuid,
                            'occupied_ports' => $occupiedPorts,
                            'hint' => 'Remove all SFP modules from this NIC before removing the NIC itself'
                        ]
                    );
                }
            }
        }

        $result = $serverBuilder->removeComponent($configUuid, $componentType, $componentUuid);

        if ($result['success']) {
            // UPDATE NIC CONFIG when component NIC is removed
            if ($componentType === 'nic') {
                try {
                    require_once __DIR__ . '/../../../core/models/compatibility/OnboardNICHandler.php';
                    $nicHandler = new OnboardNICHandler($pdo);
                    $nicHandler->updateNICConfigJSON($configUuid);
                    error_log("Updated NIC configuration JSON after removing component NIC $componentUuid");
                } catch (Exception $nicError) {
                    error_log("Error updating NIC config: " . $nicError->getMessage());
                }
            }

            send_json_response(1, 1, 200, "Component removed successfully", [
                'component_removed' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid,
                    'server_uuid_cleared' => true
                ]
            ]);
        } else {
            send_json_response(0, 1, 400, $result['message'] ?? "Failed to remove component");
        }
        
    } catch (Exception $e) {
        error_log("Error removing component: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to remove component: " . $e->getMessage());
    }
}

/**
 * ENHANCED: Get server configuration details with compatibility scoring and validation
 */
function handleGetConfiguration($serverBuilder, $user) {
    global $pdo;

    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';

    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }

    try {
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to view this configuration");
        }
        
        // Use getConfigurationDetails for complete information
        $details = $serverBuilder->getConfigurationDetails($configUuid);
        
        if (isset($details['error'])) {
            send_json_response(0, 1, 500, "Failed to retrieve configuration: " . $details['error']);
        }
        
        // Use validation results from database
        $configuration = $details['configuration'];
        $validationResults = $configuration['validation_results'] ?? [];
        $individualComponentChecks = []; // Simplified - no individual component checks
        // Simple basic validation checks using stored data only
        $configurationValid = !empty($validationResults);


        // Simplified configuration data - use stored values from database
        $configuration['power_consumption'] = $details['power_consumption']['total_with_overhead_watts'] ?? 0;
        
        
        // Calculate PCIe lane usage, riser slot usage, and get warnings
        $pcieTracking = calculatePCIeLaneUsage($details['components'] ?? []);
        $riserTracking = calculateRiserSlotUsage($details['components'] ?? [], $configUuid);
        $configWarnings = getConfigurationWarnings($details['components'] ?? []);

        // Get NIC configuration with onboard/component tracking
        $nicConfiguration = null;
        $nicSummary = ['total_nics' => 0, 'onboard_nics' => 0, 'component_nics' => 0];
        $nicDebug = [];

        try {
            // Check if motherboard has onboard NICs that should be extracted
            $motherboardDebug = [];
            $motherboardStmt = $pdo->prepare("SELECT motherboard_uuid FROM server_configurations WHERE config_uuid = ?");
            $motherboardStmt->execute([$configUuid]);
            $mbResult = $motherboardStmt->fetch(PDO::FETCH_ASSOC);
            $motherboardUuid = $mbResult ? $mbResult['motherboard_uuid'] : null;

            if ($motherboardUuid) {
                $motherboardDebug['motherboard_uuid'] = $motherboardUuid;

                // Try to load motherboard specs
                try {
                    require_once __DIR__ . '/../../../core/models/components/ComponentDataService.php';
                    $dataService = ComponentDataService::getInstance();
                    $mbSpecs = $dataService->findComponentByUuid('motherboard', $motherboardUuid);

                    $motherboardDebug['motherboard_found_in_json'] = !is_null($mbSpecs);
                    $motherboardDebug['has_networking'] = isset($mbSpecs['networking']);
                    $motherboardDebug['has_onboard_nics'] = isset($mbSpecs['networking']['onboard_nics']);

                    if (isset($mbSpecs['networking']['onboard_nics'])) {
                        $motherboardDebug['expected_onboard_nics_count'] = count($mbSpecs['networking']['onboard_nics']);
                        $motherboardDebug['onboard_nics_details'] = $mbSpecs['networking']['onboard_nics'];

                        // Check if these NICs exist in database
                        $checkOnboardStmt = $pdo->prepare("
                            SELECT UUID, ServerUUID, Status FROM nicinventory
                            WHERE ParentComponentUUID = ? AND SourceType = 'onboard'
                        ");
                        $checkOnboardStmt->execute([$motherboardUuid]);
                        $allOnboardNICs = $checkOnboardStmt->fetchAll(PDO::FETCH_ASSOC);

                        $motherboardDebug['all_onboard_nics_in_db'] = $allOnboardNICs;
                        $motherboardDebug['onboard_nics_matching_config'] = array_filter($allOnboardNICs, function($nic) use ($configUuid) {
                            return $nic['ServerUUID'] === $configUuid;
                        });

                        $actualOnboardCount = count($motherboardDebug['onboard_nics_matching_config']);
                        $motherboardDebug['actual_onboard_nics_in_db'] = (int)$actualOnboardCount;
                        $motherboardDebug['onboard_nics_missing'] = $motherboardDebug['expected_onboard_nics_count'] - (int)$actualOnboardCount;

                        // If onboard NICs are missing, try to add them now
                        if ($motherboardDebug['onboard_nics_missing'] > 0) {
                            $motherboardDebug['attempting_auto_fix'] = true;
                            require_once __DIR__ . '/../../../core/models/compatibility/OnboardNICHandler.php';
                            $nicHandler = new OnboardNICHandler($pdo);
                            $autoAddResult = $nicHandler->autoAddOnboardNICs($configUuid, $motherboardUuid);
                            $motherboardDebug['auto_add_result'] = $autoAddResult;
                        }
                    }
                } catch (Exception $mbError) {
                    $motherboardDebug['error_loading_specs'] = $mbError->getMessage();
                }
            } else {
                $motherboardDebug['motherboard_uuid'] = null;
                $motherboardDebug['message'] = 'No motherboard in configuration';
            }

            $nicDebug['motherboard_check'] = $motherboardDebug;

            // Try to get nic_config from server_configurations
            $stmt = $pdo->prepare("SELECT nic_config FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $nicConfigJson = $stmt->fetchColumn();

            $nicDebug['nic_config_exists'] = !empty($nicConfigJson);
            $nicDebug['nic_config_length'] = strlen($nicConfigJson ?? '');

            if ($nicConfigJson) {
                $nicConfiguration = json_decode($nicConfigJson, true);
                $nicDebug['nic_config_parsed'] = !is_null($nicConfiguration);
                if ($nicConfiguration && isset($nicConfiguration['summary'])) {
                    $nicSummary = $nicConfiguration['summary'];
                    $nicDebug['summary_found'] = true;
                }
            }

            // Check if nic_config needs to be updated (missing or mismatched with actual NICs)
            // Note: server_configuration_components table no longer exists
            // Get NICs from JSON columns instead
            require_once __DIR__ . '/../../../core/models/server/ServerBuilder.php';
            $sb = new ServerBuilder($pdo);
            $stmt = $pdo->prepare("SELECT nic_config FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $nicJsonConfig = $stmt->fetch(PDO::FETCH_ASSOC);

            $actualNicCount = 0;
            if ($nicJsonConfig && !empty($nicJsonConfig['nic_config'])) {
                try {
                    $nicConfig = json_decode($nicJsonConfig['nic_config'], true);
                    $actualNicCount = isset($nicConfig['nics']) ? count($nicConfig['nics']) : 0;
                } catch (Exception $e) {
                    error_log("Error parsing nic_config: " . $e->getMessage());
                }
            }
            $nicDebug['nics_in_json_config'] = (int)$actualNicCount;

            // Get the count from existing nic_config JSON
            $configuredNicCount = isset($nicConfiguration['summary']['total_nics']) ?
                (int)$nicConfiguration['summary']['total_nics'] : 0;
            $nicDebug['nics_in_json_config'] = $configuredNicCount;

            // Update if: 1) no config exists, OR 2) mismatch between actual NICs and configured NICs
            $needsUpdate = !$nicConfiguration || ($actualNicCount !== $configuredNicCount);
            $nicDebug['needs_update'] = $needsUpdate;
            $nicDebug['update_reason'] = !$nicConfiguration ? 'No config exists' :
                ($actualNicCount !== $configuredNicCount ? "Mismatch: $actualNicCount actual vs $configuredNicCount configured" : 'No update needed');

            if ($needsUpdate && $actualNicCount > 0) {
                $nicDebug['attempting_update'] = true;
                require_once __DIR__ . '/../../../core/models/compatibility/OnboardNICHandler.php';
                $nicHandler = new OnboardNICHandler($pdo);
                $updateResult = $nicHandler->updateNICConfigJSON($configUuid);
                $nicDebug['update_result'] = $updateResult;

                // Fetch again after update
                $stmt->execute([$configUuid]);
                $nicConfigJson = $stmt->fetchColumn();
                $nicDebug['nic_config_after_update_length'] = strlen($nicConfigJson ?? '');

                if ($nicConfigJson) {
                    $nicConfiguration = json_decode($nicConfigJson, true);
                    $nicDebug['config_after_update'] = $nicConfiguration;
                    $nicDebug['json_decode_success'] = !is_null($nicConfiguration);
                    if ($nicConfiguration && isset($nicConfiguration['summary'])) {
                        $nicSummary = $nicConfiguration['summary'];
                        $nicDebug['summary_updated'] = true;
                    } else {
                        $nicDebug['summary_updated'] = false;
                        $nicDebug['summary_missing_reason'] = is_null($nicConfiguration) ? 'JSON decode failed' : 'Summary key not found';
                    }
                } else {
                    $nicDebug['nic_config_still_null'] = true;
                }
            } else {
                $nicDebug['attempting_update'] = false;
                $nicDebug['reason'] = $actualNicCount === 0 ? 'No NICs found in server_configuration_components' : 'Config is up to date';
            }
        } catch (Exception $nicError) {
            $nicDebug['error'] = $nicError->getMessage();
            $nicDebug['trace'] = $nicError->getTraceAsString();
            error_log("Error getting NIC configuration: " . $nicError->getMessage());
            // Continue without NIC config data
        }

        // Get NIC port tracking information
        $nicPortTracking = ['nics' => []];
        try {
            require_once __DIR__ . '/../../../core/models/compatibility/NICPortTracker.php';
            $portTracker = new NICPortTracker($pdo);
            $nicPortTracking = $portTracker->getPortUtilizationForConfig($configUuid);
        } catch (Exception $portError) {
            error_log("Error getting NIC port tracking: " . $portError->getMessage());
            // Continue without port tracking data
        }

        send_json_response(1, 1, 200, "Configuration retrieved successfully", [
            'configuration' => [
                'config_uuid' => $configuration['config_uuid'],
                'server_name' => $configuration['server_name'],
                'description' => $configuration['description'] ?? '',
                'configuration_status' => $configuration['configuration_status'],
                'power_consumption' => $configuration['power_consumption'],
                'created_at' => $configuration['created_at'],
                'location' => $configuration['location'] ?? '',
                'components' => $details['components'] ?? []
            ],
            'summary' => [
                'total_components' => $details['total_components'],
                'component_counts' => $details['component_counts'],
                'power_consumption' => $details['power_consumption']
            ],
            'status' => [
                'configuration_valid' => $configurationValid,
                'last_validation' => $configuration['updated_at'] ?? $configuration['created_at']
            ],
            'pcie_lanes' => $pcieTracking,
            'riser_slots' => $riserTracking,
            'warnings' => $configWarnings,
            'nic_port_tracking' => $nicPortTracking,
            'nic_configuration' => $nicConfiguration,
            'nic_summary' => $nicSummary
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to retrieve configuration: " . $e->getMessage());
    }
}

/**
 * List server configurations
 */
function handleListConfigurations($serverBuilder, $user) {
    global $pdo;
    
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? null;
    $includeVirtual = $_GET['include_virtual'] ?? 'all'; // 'all', 'true', 'false'

    try {
        $whereClause = "WHERE 1=1";
        $params = [];

        // Filter by user if no admin permissions
        if (!hasPermission($pdo, 'server.view_all', $user['id'])) {
            $whereClause .= " AND created_by = ?";
            $params[] = $user['id'];
        }

        // Filter by status if provided
        if ($status !== null) {
            $whereClause .= " AND configuration_status = ?";
            $params[] = $status;
        }

        // Filter by is_virtual flag
        if ($includeVirtual === 'true') {
            $whereClause .= " AND is_virtual = 1";
        } elseif ($includeVirtual === 'false') {
            $whereClause .= " AND is_virtual = 0";
        }
        // If 'all' or any other value, no filtering on is_virtual
        
        $stmt = $pdo->prepare("
            SELECT sc.*, u.username as created_by_username 
            FROM server_configurations sc 
            LEFT JOIN users u ON sc.created_by = u.id 
            $whereClause 
            ORDER BY sc.created_at DESC 
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $configurations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add configuration status text and component count for each configuration
        foreach ($configurations as &$config) {
            $config['configuration_status_text'] = getConfigurationStatusText($config['configuration_status']);
            $config['is_virtual'] = (bool)($config['is_virtual'] ?? 0); // Convert to boolean

            // Count distinct component types in this configuration
            $components = extractComponentsFromConfigData($config);
            $componentTypes = [];
            foreach ($components as $component) {
                $componentTypes[$component['component_type']] = true;
            }
            $config['total_component_types'] = count($componentTypes);
        }
        
        // Get total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM server_configurations sc $whereClause");
        $countStmt->execute(array_slice($params, 0, -2)); // Remove limit and offset
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        send_json_response(1, 1, 200, "Configurations retrieved successfully", [
            'configurations' => $configurations,
            'pagination' => [
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error listing configurations: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to list configurations: " . $e->getMessage());
    }
}

/**
 * Import virtual server configuration to real configuration
 * Creates a new real server with available components from the virtual config
 */
function handleImportVirtual($serverBuilder, $user) {
    global $pdo;

    $virtualConfigUuid = $_POST['virtual_config_uuid'] ?? '';
    $serverName = trim($_POST['server_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $rackPosition = trim($_POST['rack_position'] ?? '');

    // Validate required parameters
    if (empty($virtualConfigUuid)) {
        send_json_response(0, 1, 400, "Virtual configuration UUID is required");
    }

    if (empty($serverName)) {
        send_json_response(0, 1, 400, "Server name is required for the real configuration");
    }

    try {
        // Step 1: Validate virtual config exists and is actually virtual
        $virtualConfig = ServerConfiguration::loadByUuid($pdo, $virtualConfigUuid);
        if (!$virtualConfig) {
            send_json_response(0, 1, 404, "Virtual configuration not found");
        }

        if (!$virtualConfig->get('is_virtual')) {
            send_json_response(0, 1, 400, "Configuration is not a virtual configuration");
        }

        // Check permissions
        if ($virtualConfig->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.create', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to import this configuration");
        }

        // Step 2: Get all components from virtual config
        $components = $serverBuilder->getConfigComponents($virtualConfigUuid);
        if (!$components || empty($components)) {
            send_json_response(0, 1, 400, "Virtual configuration has no components to import");
        }

        // Step 3: Create new real server configuration
        $pdo->beginTransaction();

        $realConfigUuid = generateUUID();
        $stmt = $pdo->prepare("
            INSERT INTO server_configurations (
                config_uuid, server_name, description, location, rack_position,
                created_by, created_at, updated_at, configuration_status, is_virtual
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 0, 0)
        ");
        $stmt->execute([
            $realConfigUuid,
            $serverName,
            $description,
            $location,
            $rackPosition,
            $user['id']
        ]);

        // Step 4: Attempt to add each component from virtual to real config
        $importedComponents = [];
        $warnings = [];

        foreach ($components as $component) {
            $componentType = $component['component_type'];
            $uuid = $component['uuid'];

            // Find available component in inventory
            $availableComponent = $serverBuilder->findAvailableComponent($componentType, $uuid);

            if ($availableComponent) {
                // Component is available - add it to real config
                $options = [
                    'quantity' => $component['quantity'] ?? 1,
                    'serial_number' => $availableComponent['SerialNumber'] ?? null
                ];

                // Add slot_position for PCIe cards if present
                if (isset($component['slot_position'])) {
                    $options['slot_position'] = $component['slot_position'];
                }

                $addResult = $serverBuilder->addComponent(
                    $realConfigUuid,
                    $componentType,
                    $uuid,
                    $options
                );

                if ($addResult['success']) {
                    $importedComponents[] = [
                        'component_type' => $componentType,
                        'uuid' => $uuid,
                        'serial_number' => $availableComponent['SerialNumber'] ?? null,
                        'status' => 'imported'
                    ];
                } else {
                    // Add component failed due to compatibility or other reasons
                    $warnings[] = [
                        'component_type' => $componentType,
                        'uuid' => $uuid,
                        'reason' => 'import_failed',
                        'details' => $addResult['message'] ?? 'Failed to add component to real configuration'
                    ];
                }
            } else {
                // Component not available in inventory
                $warnings[] = [
                    'component_type' => $componentType,
                    'uuid' => $uuid,
                    'reason' => 'not_available',
                    'details' => 'No available components found in inventory'
                ];
            }
        }

        $pdo->commit();

        // Step 5: Prepare summary
        $totalComponents = count($components);
        $importedCount = count($importedComponents);
        $missingCount = count($warnings);

        $message = "Virtual configuration imported successfully";
        if ($missingCount > 0) {
            $message .= " with $missingCount component(s) unavailable";
        }

        send_json_response(1, 1, 200, $message, [
            'real_config_uuid' => $realConfigUuid,
            'virtual_config_uuid' => $virtualConfigUuid,
            'server_name' => $serverName,
            'imported_components' => $importedComponents,
            'warnings' => $warnings,
            'summary' => [
                'total_components' => $totalComponents,
                'imported' => $importedCount,
                'missing' => $missingCount
            ]
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error importing virtual configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to import virtual configuration: " . $e->getMessage());
    }
}

/**
 * Finalize server configuration
 */
function handleFinalizeConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $finalNotes = trim($_POST['notes'] ?? '');
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }

        // Block finalization of virtual configs
        if ($config->get('is_virtual')) {
            send_json_response(0, 1, 400, "Cannot finalize virtual/test configurations. Use server-import-virtual to convert to a real configuration first.");
        }

        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.finalize', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to finalize this configuration");
        }
        
        // Validate configuration before finalizing
        $validation = $serverBuilder->validateConfiguration($configUuid);
        if (!$validation['is_valid']) {
            send_json_response(0, 1, 400, "Configuration is not valid for finalization", [
                'validation_errors' => $validation['issues']
            ]);
        }
        
        $result = $serverBuilder->finalizeConfiguration($configUuid, $finalNotes);
        
        if ($result['success']) {
            send_json_response(1, 1, 200, "Configuration finalized successfully", [
                'config_uuid' => $configUuid,
                'finalization_details' => $result,
                'configuration_status' => 3,
                'configuration_status_text' => 'Finalized'
            ]);
        } else {
            send_json_response(0, 1, 400, $result['message'] ?? "Failed to finalize configuration");
        }
        
    } catch (Exception $e) {
        error_log("Error finalizing configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to finalize configuration: " . $e->getMessage());
    }
}

/**
 * Delete server configuration
 */
function handleDeleteConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.delete', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to delete this configuration");
        }
        
        // Prevent deletion of finalized configurations unless admin
        if ($config->get('configuration_status') == 3 && !hasPermission($pdo, 'server.delete_finalized', $user['id'])) {
            send_json_response(0, 1, 403, "Cannot delete finalized configurations");
        }
        
        $result = $serverBuilder->deleteConfiguration($configUuid);
        
        if ($result['success']) {
            send_json_response(1, 1, 200, "Configuration deleted successfully", [
                'components_released' => $result['components_released']
            ]);
        } else {
            send_json_response(0, 1, 400, $result['message'] ?? "Failed to delete configuration");
        }
        
    } catch (Exception $e) {
        error_log("Error deleting configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to delete configuration: " . $e->getMessage());
    }
}

/**
 * Get available components for selection
 */
function handleGetAvailableComponents($user) {
    global $pdo;

    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    $componentType = $_GET['component_type'] ?? $_POST['component_type'] ?? '';
    $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $limit = (int)($_GET['limit'] ?? 50);


    if (empty($componentType)) {
        send_json_response(0, 1, 400, "Component type is required");
    }

    try {
        // Continue with existing component listing logic
        $components = getAvailableComponents($pdo, $componentType, $availableOnly, $limit);
        $count = getComponentCount($pdo, $componentType);

        $responseData = [
            'component_type' => $componentType,
            'components' => $components,
            'counts' => $count,
            'available_only' => $availableOnly,
            'total_returned' => count($components)
        ];

        // Add configuration context if provided
        if ($configUuid) {
            $responseData['configuration_summary'] = $configSummary;
            $responseData['allowed_types'] = $allowedTypes;
        }

        send_json_response(1, 1, 200, "Available components retrieved successfully", $responseData);

    } catch (Exception $e) {
        error_log("Error getting available components: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get available components: " . $e->getMessage());
    }
}

/**
 * FIXED: Validate server configuration - Updated with proper compatibility scoring and warnings
 */
function handleValidateConfiguration($serverBuilder, $user) {
    global $pdo;

    $configUuid = $_POST['config_uuid'] ?? $_GET['config_uuid'] ?? '';

    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }

    try {
        // Load configuration to verify it exists
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }

        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to validate this configuration");
        }

        // Use the NEW comprehensive validation method
        $validation = $serverBuilder->validateConfigurationComprehensive($configUuid);

        // Update database with validation results
        try {
            $stmt = $pdo->prepare("
                UPDATE server_configurations
                SET validation_results = ?,
                    updated_at = NOW()
                WHERE config_uuid = ?
            ");
            $stmt->execute([
                json_encode($validation),
                $configUuid
            ]);
        } catch (Exception $dbError) {
            error_log("Failed to update validation results in database: " . $dbError->getMessage());
            // Don't fail the entire request if DB update fails
        }

        // Determine response message based on validation result
        $message = $validation['valid']
            ? "Configuration validation passed"
            : "Configuration validation failed";

        send_json_response(1, 1, 200, $message, [
            'config_uuid' => $configUuid,
            'validation' => $validation
        ]);

    } catch (Exception $e) {
        error_log("Error validating configuration: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        send_json_response(0, 1, 500, "Failed to validate configuration: " . $e->getMessage());
    }
}

/**
 * Get compatible components for a server configuration - ENHANCED Implementation per Important-fix requirements
 * This endpoint finds components compatible with an existing server configuration's motherboard
 */
function handleGetCompatible($serverBuilder, $user) {
    global $pdo;
    
    // Get parameters with exact names from Important-fix specification
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    $componentType = $_GET['component_type'] ?? $_POST['component_type'] ?? '';
    $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    // Validate required parameters
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    if (empty($componentType)) {
        send_json_response(0, 1, 400, "Component type is required");
    }
    
    // Validate component type
    $validComponentTypes = ['chassis', 'cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy', 'pciecard', 'hbacard', 'sfp'];
    if (!in_array($componentType, $validComponentTypes)) {
        send_json_response(0, 1, 400, "Invalid component type. Must be one of: " . implode(', ', $validComponentTypes));
    }
    
    try {
        // Step 1: Validate server configuration exists and belongs to user
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to view this configuration");
        }

        // For virtual configs, show ALL components regardless of availability
        if ($config->get('is_virtual')) {
            $availableOnly = false;
        }

        // Step 2: Get existing components in configuration for flexible compatibility checking
        $existingComponents = extractComponentsFromConfigData($config->getData());

        // If no existing components, show all available components of requested type
        if (empty($existingComponents)) {
            error_log("No existing components found, showing all available components of type: $componentType");
        }
        
        // Process existing components for compatibility checking
        $existingComponentsData = [];
        foreach ($existingComponents as $existing) {
            $tableMap = [
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

            $table = $tableMap[$existing['component_type']];
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE UUID = ? LIMIT 1");
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
        
        // Step 3: Get all components of requested type with availability filtering
        $tableMap = [
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
        
        $table = $tableMap[$componentType];

        // Build WHERE clause based on available_only parameter
        // When available_only=true: Only query Status=1 (available)
        // When available_only=false: Query all statuses (0=failed, 1=available, 2=in_use)
        if ($availableOnly) {
            $whereClause = "WHERE Status = 1"; // Only available components
        } else {
            $whereClause = "WHERE Status IN (0, 1, 2)"; // All statuses
        }

        // Get components with optimized query (limit to 200 for performance)
        $stmt = $pdo->prepare("
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
        
        // Step 4: Run compatibility checks using JSON integration and proper socket checking
        $compatibleComponents = [];
        
        // Always try to load the ComponentCompatibility class first
        $compatibilityClassFile = __DIR__ . '/../../core/models/compatibility/ComponentCompatibility.php';
        if (file_exists($compatibilityClassFile)) {
            require_once $compatibilityClassFile;
            error_log("DEBUG: ComponentCompatibility.php loaded successfully");
        } else {
            error_log("ERROR: ComponentCompatibility.php not found at: $compatibilityClassFile");
        }
        
        if (class_exists('ComponentCompatibility')) {
            $compatibility = new ComponentCompatibility($pdo);
            error_log("DEBUG: ComponentCompatibility class instantiated successfully");
            
            // Instantiate ComponentDataService for SFP compatibility checks
            require_once __DIR__ . '/../../../core/models/components/ComponentDataService.php';
            $componentDataService = ComponentDataService::getInstance();
            error_log("DEBUG: ComponentDataService instantiated successfully");

            // STEP 3.5: Pre-filter - Only include components that exist in JSON
            // This prevents "requirements not determined" errors for components lacking specifications
            $componentsWithJSON = [];
            $componentsWithoutJSON = [];
            $jsonValidationDetails = []; // Track detailed validation per component

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

            // Log components without JSON for debugging
            if (!empty($componentsWithoutJSON)) {
                error_log("Components excluded (no JSON): " . implode(', ', $componentsWithoutJSON));
            }

            // Replace allComponents with filtered list
            $totalBeforeFiltering = count($allComponents);
            $allComponents = $componentsWithJSON;

            error_log("Filtered components: " . count($allComponents) . " with JSON out of " . $totalBeforeFiltering . " total");

            // Add to debug info
            $debugInfo['total_before_json_filter'] = $totalBeforeFiltering;
            $debugInfo['total_with_json'] = count($allComponents);
            $debugInfo['components_without_json'] = $componentsWithoutJSON;
            $debugInfo['json_validation_details'] = $jsonValidationDetails;

            // Enhanced compatibility checking with flexible component support
            error_log("DEBUG: Starting flexible compatibility checking for $componentType components");
            error_log("DEBUG: Checking against " . count($existingComponentsData) . " existing components");

            // Add detailed component listing to debug
            $debugInfo['components_to_check'] = array_map(function($c) {
                return [
                    'uuid' => $c['UUID'],
                    'serial' => $c['SerialNumber'],
                    'status' => $c['Status']
                ];
            }, $allComponents);

            foreach ($allComponents as $component) {
                $isCompatible = true;
                $compatibilityScore = 100; // Integer score from 0-100
                $compatibilityReasons = [];
                $fullChassisResult = null; // Initialize for chassis components

                // If no existing components, all components are compatible
                if (empty($existingComponentsData)) {
                    $isCompatible = true;
                    $compatibilityScore = 100;
                    $compatibilityReasons[] = "No existing components - all components available";
                } else {
                    // Use specialized RAM compatibility checking for better accuracy
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
                        // Use specialized CPU compatibility checking for better accuracy
                        $cpuCompatResult = $compatibility->checkCPUDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        $isCompatible = $cpuCompatResult['compatible'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$cpuCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                    } elseif ($componentType === 'motherboard') {
                        // Use specialized motherboard compatibility checking for better accuracy
                        $motherboardCompatResult = $compatibility->checkMotherboardDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        $isCompatible = $motherboardCompatResult['compatible'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$motherboardCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                    } elseif ($componentType === 'storage') {
                        // Use specialized storage compatibility checking for better accuracy
                        $storageCompatResult = $compatibility->checkStorageDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        $isCompatible = $storageCompatResult['compatible'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$storageCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                    } elseif ($componentType === 'chassis') {
                        // Use specialized chassis compatibility checking for better accuracy
                        $chassisCompatResult = $compatibility->checkChassisDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        $isCompatible = $chassisCompatResult['compatible'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$chassisCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];

                        // Store the full compatibility result for chassis to include score_breakdown
                        $fullChassisResult = $chassisCompatResult;

                        // DEBUG: Include details array if present
                        if (isset($chassisCompatResult['details'])) {
                            $compatibilityReasons[] = 'DEBUG_DETAILS: ' . json_encode($chassisCompatResult['details']);
                        }
                    } elseif ($componentType === 'pciecard') {
                        // Use specialized PCIe card compatibility checking
                        error_log("DEBUG: Checking PCIe card compatibility for UUID: " . $component['UUID']);
                        $pcieCompatResult = $compatibility->checkPCIeDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        error_log("DEBUG: PCIe compatibility result for " . $component['UUID'] . ": " . json_encode($pcieCompatResult));
                        $isCompatible = $pcieCompatResult['compatible'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$pcieCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                    } elseif ($componentType === 'nic') {
                        // Use specialized PCIe compatibility checking for NICs
                        // NICs are treated as PCIe devices when checking slot availability
                        $nicCompatResult = $compatibility->checkPCIeDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData, 'nic'
                        );
                        $isCompatible = $nicCompatResult['compatible'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$nicCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                    } elseif ($componentType === 'hbacard') {
                        // Use specialized HBA card compatibility checking
                        // Checks storage device interface matching and PCIe slot availability
                        $hbaCompatResult = $compatibility->checkHBADecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        $isCompatible = $hbaCompatResult['compatible'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$hbaCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];

                        // Add debug info for first 3 HBA cards (to see different scenarios)
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
                        // Use specialized SFP compatibility checking based on NIC port types
                        require_once __DIR__ . '/../../../core/models/compatibility/NICPortTracker.php';

                        // Get all NICs in the configuration and their port types
                        $nicPortTypes = [];
                        $nicDetails = [];
                        foreach ($existingComponentsData as $existingComp) {
                            if ($existingComp['type'] === 'nic') {
                                $nicSpecs = $componentDataService->getComponentSpecs('nic', $existingComp['uuid']);
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
                            // REQUIREMENT #1: No NICs in configuration - ALL SFPs are compatible
                            // Users can add SFPs before adding NICs (dynamic dependency resolution)
                            $isCompatible = true;
                            $compatibilityReasons = ['SFP can be added now - will be assigned when compatible NIC is added'];
                        } else {
                            // Get SFP type from specs
                            $sfpSpecs = $componentDataService->getComponentSpecs('sfp', $component['UUID']);
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

                // Include component with compatibility information
                // ENHANCED: Add available_for_use flag to clearly indicate if component can be added
                $componentStatus = (int)$component['Status'];
                $statusLabels = [0 => 'failed', 1 => 'available', 2 => 'in_use'];

                $compatibleComponent = [
                    'uuid' => $component['UUID'],
                    'serial_number' => $component['SerialNumber'],
                    'status' => $componentStatus,
                    'status_label' => $statusLabels[$componentStatus] ?? 'unknown',
                    'available_for_use' => ($componentStatus === 1), // Only Status=1 can be added
                    'server_uuid' => $component['ServerUUID'] ?? null, // Show which server it's assigned to
                    'location' => $component['Location'],
                    'notes' => $component['Notes'],
                    'compatibility_reason' => implode('; ', $compatibilityReasons),
                    'is_compatible' => $isCompatible
                ];

                // Add score_breakdown for chassis components (for detailed debugging)
                if ($componentType === 'chassis' && isset($fullChassisResult['score_breakdown'])) {
                    $compatibleComponent['score_breakdown'] = $fullChassisResult['score_breakdown'];
                }

                // Add warnings if present (useful for all component types)
                if ($componentType === 'chassis' && isset($fullChassisResult['warnings']) && !empty($fullChassisResult['warnings'])) {
                    $compatibleComponent['warnings'] = $fullChassisResult['warnings'];
                }

                // Always add components to show compatibility details
                $compatibleComponents[] = $compatibleComponent;
            }
        } else {
            // Fallback: Simplified compatibility checking without motherboard dependency
            error_log("WARNING: ComponentCompatibility class not available, using simplified fallback");
            error_log("DEBUG: Class exists check: " . (class_exists('ComponentCompatibility') ? 'true' : 'false'));
            error_log("DEBUG: File exists check: " . (file_exists($compatibilityClassFile) ? 'true' : 'false'));

            foreach ($allComponents as $component) {
                $isCompatible = true;
                $compatibilityScore = 100; // Integer score from 0-100
                $compatibilityReason = "Basic compatibility check passed";

                // If no existing components, all components are compatible
                if (empty($existingComponentsData)) {
                    $compatibilityScore = 100;
                    $compatibilityReason = "No existing components - all components available";
                } else {
                    // Basic compatibility logic without motherboard dependency
                    $compatibilityScore = 100;
                    $compatibilityReason = "Compatible based on basic component validation";

                    // For RAM compatibility with existing components
                    if ($componentType === 'ram' && !empty($existingComponentsData)) {
                        $ramCompatibilityResult = checkRAMCompatibilityWithExistingComponents(
                            $component, $existingComponentsData, $pdo
                        );
                        $isCompatible = $ramCompatibilityResult['compatible'];
                        $compatibilityReason = $ramCompatibilityResult['reason'];
                    }
                }

                // ENHANCED: Add available_for_use flag to clearly indicate if component can be added
                $componentStatus = (int)$component['Status'];
                $statusLabels = [0 => 'failed', 1 => 'available', 2 => 'in_use'];

                $compatibleComponent = [
                    'uuid' => $component['UUID'],
                    'serial_number' => $component['SerialNumber'],
                    'status' => $componentStatus,
                    'status_label' => $statusLabels[$componentStatus] ?? 'unknown',
                    'available_for_use' => ($componentStatus === 1), // Only Status=1 can be added
                    'server_uuid' => $component['ServerUUID'] ?? null, // Show which server it's assigned to
                    'location' => $component['Location'],
                    'notes' => $component['Notes'],
                    'compatibility_reason' => $compatibilityReason,
                    'is_compatible' => $isCompatible
                ];

                // Always add components to show compatibility details
                $compatibleComponents[] = $compatibleComponent;
            }
        }
        
        // Step 5: Build response without base_motherboard dependency
        // ENHANCED: Show ALL physical components but separate by compatibility and availability
        $compatibleAndAvailable = array_filter($compatibleComponents, function($comp) {
            return $comp['is_compatible'] && $comp['available_for_use'];
        });
        $compatibleButNotAvailable = array_filter($compatibleComponents, function($comp) {
            return $comp['is_compatible'] && !$comp['available_for_use'];
        });
        $incompatibleOnly = array_filter($compatibleComponents, function($comp) {
            return !$comp['is_compatible'];
        });

        // Respect available_only parameter when building response
        if ($availableOnly) {
            // When available_only=true, only show compatible AND available components
            $allCompatibleComponents = array_values($compatibleAndAvailable);
        } else {
            // When available_only=false, show all compatible components (available and unavailable)
            $allCompatibleComponents = array_merge(
                array_values($compatibleAndAvailable),
                array_values($compatibleButNotAvailable)
            );
        }

        $responseData = [
            'config_uuid' => $configUuid,
            'component_type' => $componentType,
            'compatible_components' => $allCompatibleComponents, // Filtered based on available_only parameter
            'incompatible_components' => array_values($incompatibleOnly),
            'total_compatible' => count($allCompatibleComponents),
            'total_compatible_and_available' => count($compatibleAndAvailable), // Can actually be added
            'total_compatible_but_unavailable' => count($compatibleButNotAvailable), // Compatible but in_use or failed
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
            ],
            'debug_info' => $debugInfo
        ];

        // ENHANCED: Add inventory summary grouped by UUID to show full inventory counts
        // This helps users understand when multiple physical components share the same UUID
        $uuidInventorySummary = [];

        // Query all components of this type (not just available ones) to get full inventory
        $stmt = $pdo->prepare("
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

        // Determine appropriate message and response
        if (count($allCompatibleComponents) > 0) {
            $message = "Compatible components found";
        } else if (count($incompatibleOnly) > 0) {
            $message = "No compatible components found - all components incompatible";
            // Keep incompatible components in the response for debugging
            $responseData['incompatibility_summary'] = [
                'total_checked' => count($incompatibleOnly),
                'main_reasons' => array_slice(array_unique(array_column($incompatibleOnly, 'compatibility_reason')), 0, 5),
                'recommendation' => 'Check incompatible_components array for detailed breakdown'
            ];
        } else {
            $message = "No components found matching criteria";
        }

        send_json_response(1, 1, 200, $message, [
            'data' => $responseData
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting compatible components: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatible components: " . $e->getMessage());
    }
}

// Enhanced Helper Functions for Error Handling and Component Management

/**
 * Get motherboard specifications from configuration for validation
 */
function getMotherboardSpecsFromConfig($pdo, $configUuid) {
    try {
        // Get motherboard from configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            return null;
        }

        $motherboardUuid = $config->get('motherboard_uuid');
        if (!$motherboardUuid) {
            return null;
        }

        // Initialize compatibility engine
        require_once __DIR__ . '/../../../core/models/compatibility/ComponentCompatibility.php';
        $compatibility = new ComponentCompatibility($pdo);

        // Get motherboard limits
        $limitsResult = $compatibility->getMotherboardLimits($motherboardUuid);
        return $limitsResult['found'] ? $limitsResult['limits'] : null;
        
    } catch (Exception $e) {
        error_log("Error getting motherboard specs: " . $e->getMessage());
        return null;
    }
}

/**
 * Categorize error types for better error handling
 */
function categorizeError($errorType) {
    $errorCategories = [
        'json_not_found' => 'data_integrity',
        'json_data_not_found' => 'data_integrity',
        'socket_mismatch' => 'compatibility',
        'cpu_limit_exceeded' => 'hardware_limitation',
        'duplicate_component' => 'configuration_conflict',
        'motherboard_required' => 'dependency_missing',
        'compatibility_failure' => 'compatibility',
        'validation_system_error' => 'system_error',
        'unknown' => 'general_error'
    ];
    
    return $errorCategories[$errorType] ?? 'general_error';
}

/**
 * Generate recovery options based on error type and component type
 */
function generateRecoveryOptions($errorType, $componentType) {
    $recoveryOptions = [];
    
    switch ($errorType) {
        case 'json_not_found':
        case 'json_data_not_found':
            $recoveryOptions = [
                'Verify the component UUID is correct',
                'Check if component exists in the JSON specifications database',
                'Contact administrator to add missing component data',
                'Try using a different ' . $componentType . ' component'
            ];
            break;
            
        case 'socket_mismatch':
            if ($componentType === 'cpu') {
                $recoveryOptions = [
                    'Select a CPU with matching socket type',
                    'Choose a different motherboard with compatible socket',
                    'Verify socket specifications in component database',
                    'Contact support for socket compatibility information'
                ];
            } else {
                $recoveryOptions = [
                    'Select compatible components with matching specifications',
                    'Review component compatibility requirements',
                    'Choose alternative components from the same family'
                ];
            }
            break;
            
        case 'cpu_limit_exceeded':
            $recoveryOptions = [
                'Remove one of the existing CPUs from the configuration',
                'Use a motherboard that supports multiple CPU sockets',
                'Consider a single more powerful CPU instead of multiple CPUs',
                'Review motherboard specifications for socket count'
            ];
            break;
            
        case 'duplicate_component':
            $recoveryOptions = [
                'Component is already added - no action needed',
                'Remove the existing component first if replacement is intended',
                'Check configuration to see currently added components',
                'Use quantity parameter if multiple units are needed'
            ];
            break;
            
        case 'motherboard_required':
            $recoveryOptions = [
                'Add a motherboard to the configuration first',
                'Select a motherboard compatible with the desired CPU',
                'Review available motherboards in inventory',
                'Ensure motherboard supports the CPU socket type'
            ];
            break;
            
        case 'compatibility_failure':
            $recoveryOptions = [
                'Review component specifications for compatibility',
                'Choose components from the same generation or family',
                'Verify memory type, socket type, and interface compatibility',
                'Use the compatibility checker to find suitable alternatives'
            ];
            break;
            
        default:
            $recoveryOptions = [
                'Review component specifications and requirements',
                'Try selecting a different ' . $componentType . ' component',
                'Check system logs for more detailed error information',
                'Contact system administrator if problem persists'
            ];
    }
    
    return $recoveryOptions;
}

// Helper Functions

/**
 * NEW: Helper function to validate DateTime format
 */
function validateDateTime($dateTime) {
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $dateTime);
    return $d && $d->format('Y-m-d H:i:s') === $dateTime;
}

/**
 * NEW: Helper function to log configuration updates
 */
function logConfigurationUpdate($pdo, $configUuid, $changes, $userId) {
    try {
        // Check if history table exists
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'server_configuration_history'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            createConfigurationHistoryTable($pdo);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO server_configuration_history 
            (config_uuid, action, component_type, component_uuid, metadata, created_by, created_at) 
            VALUES (?, 'configuration_updated', NULL, NULL, ?, ?, NOW())
        ");
        $stmt->execute([
            $configUuid,
            json_encode([
                'changes' => $changes,
                'total_fields_changed' => count($changes),
                'updated_at' => date('Y-m-d H:i:s')
            ]),
            $userId
        ]);
    } catch (Exception $e) {
        error_log("Error logging configuration update: " . $e->getMessage());
        // Don't throw exception as this shouldn't break the main operation
    }
}

/**
 * NEW: Helper function to create configuration history table if it doesn't exist
 */
function createConfigurationHistoryTable($pdo) {
    try {
        $sql = "
            CREATE TABLE IF NOT EXISTS server_configuration_history (
                id int(11) NOT NULL AUTO_INCREMENT,
                config_uuid varchar(36) NOT NULL,
                action varchar(50) NOT NULL COMMENT 'created, updated, component_added, component_removed, validated, configuration_updated, etc.',
                component_type varchar(20) DEFAULT NULL,
                component_uuid varchar(36) DEFAULT NULL,
                metadata text DEFAULT NULL COMMENT 'JSON metadata for the action',
                created_by int(11) DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (id),
                KEY idx_config_uuid (config_uuid),
                KEY idx_component_uuid (component_uuid),
                KEY idx_created_at (created_at),
                KEY idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $pdo->exec($sql);
        error_log("Created server_configuration_history table");
    } catch (Exception $e) {
        error_log("Error creating history table: " . $e->getMessage());
    }
}

/**
 * Helper function to get component details
 */
function getComponentDetails($pdo, $componentType, $componentUuid) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];

    if (!isset($tableMap[$componentType])) {
        return null;
    }

    $table = $tableMap[$componentType];

    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
        $stmt->execute([$componentUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting component details: " . $e->getMessage());
        return null;
    }
}

/**
 * Helper function to get status text
 */
function getStatusText($statusCode) {
    $statusMap = [
        0 => 'Failed/Defective',
        1 => 'Available',
        2 => 'In Use'
    ];
    
    return $statusMap[$statusCode] ?? 'Unknown';
}

/**
 * Helper function to get configuration status text
 */
function getConfigurationStatusText($statusCode) {
    $statusMap = [
        0 => 'Draft',
        1 => 'Validated',
        2 => 'Built',
        3 => 'Finalized'
    ];

    return $statusMap[$statusCode] ?? 'Unknown';
}

/**
 * Calculate PCIe lane usage across the entire server configuration
 */
function calculatePCIeLaneUsage($components) {
    global $pdo;
    require_once __DIR__ . '/../../../core/models/shared/DataExtractionUtilities.php';
    $dataUtils = new DataExtractionUtilities($pdo);

    $result = [
        'cpu_lanes' => [
            'total' => 0,
            'used' => 0,
            'available' => 0
        ],
        'chipset_lanes' => [
            'total' => 0,
            'used' => 0,
            'available' => 0
        ],
        'm2_slots' => [
            'motherboard' => [
                'total' => 0,
                'used' => 0,
                'available' => 0
            ],
            'expansion_cards' => [
                'total' => 0,
                'used' => 0,
                'available' => 0,
                'providers' => []
            ]
        ],
        'expansion_slots' => [
            'total_x16' => 0,
            'used_x16' => 0,
            'total_x8' => 0,
            'used_x8' => 0,
            'total_x4' => 0,
            'used_x4' => 0
        ],
        'riser_provided_pcie_slots' => [
            'total_slots' => 0,
            'used_slots' => 0,
            'available_slots' => 0,
            'risers' => []
        ],
        'detailed_usage' => []
    ];

    try {
        // Get CPU PCIe lanes
        if (isset($components['cpu']) && !empty($components['cpu'])) {
            $cpuUuid = $components['cpu'][0]['uuid'] ?? null;
            if ($cpuUuid) {
                $cpuSpecs = $dataUtils->getCPUByUUID($cpuUuid);
                if ($cpuSpecs) {
                    $result['cpu_lanes']['total'] = $cpuSpecs['pcie_lanes'] ?? 0;
                }
            }
        }

        // Get motherboard M.2 slots and expansion slots
        if (isset($components['motherboard']) && !empty($components['motherboard'])) {
            $mbUuid = $components['motherboard'][0]['uuid'] ?? null;
            if ($mbUuid) {
                $mbSpecs = $dataUtils->getMotherboardByUUID($mbUuid);
                if ($mbSpecs) {
                    // M.2 slots from motherboard
                    if (isset($mbSpecs['storage']['nvme']['m2_slots'][0]['count'])) {
                        $result['m2_slots']['motherboard']['total'] = $mbSpecs['storage']['nvme']['m2_slots'][0]['count'];
                    }

                    // PCIe expansion slots
                    if (isset($mbSpecs['expansion_slots']['pcie_slots'])) {
                        foreach ($mbSpecs['expansion_slots']['pcie_slots'] as $slotType) {
                            $lanes = $slotType['lanes'] ?? 0;
                            $count = $slotType['count'] ?? 0;
                            if ($lanes == 16) {
                                $result['expansion_slots']['total_x16'] += $count;
                            } elseif ($lanes == 8) {
                                $result['expansion_slots']['total_x8'] += $count;
                            } elseif ($lanes == 4) {
                                $result['expansion_slots']['total_x4'] += $count;
                            }
                        }
                    }
                }
            }
        }

        // Count M.2 storage usage
        if (isset($components['storage']) && !empty($components['storage'])) {
            foreach ($components['storage'] as $storage) {
                $storageUuid = $storage['uuid'] ?? null;
                if ($storageUuid) {
                    $storageSpecs = $dataUtils->getStorageByUUID($storageUuid);
                    if ($storageSpecs) {
                        $formFactor = strtolower($storageSpecs['form_factor'] ?? '');
                        if (strpos($formFactor, 'm.2') !== false || strpos($formFactor, 'm2') !== false) {
                            // Determine which type of slot this M.2 storage is using
                            $slotPosition = strtolower($storage['slot_position'] ?? '');
                            $slotSource = 'motherboard'; // Default to motherboard

                            // Check if slot_position indicates expansion card usage
                            if (strpos($slotPosition, 'expansion') !== false ||
                                strpos($slotPosition, 'pcie') !== false ||
                                strpos($slotPosition, 'adapter') !== false ||
                                strpos($slotPosition, 'card') !== false) {
                                $slotSource = 'expansion_cards';
                            }

                            // Increment the appropriate slot usage counter
                            $result['m2_slots'][$slotSource]['used']++;

                            $result['detailed_usage'][] = [
                                'component' => 'M.2 Storage',
                                'uuid' => $storageUuid,
                                'type' => 'M.2 Slot',
                                'slot_source' => $slotSource,
                                'lanes_used' => 4
                            ];
                        }
                    }
                }
            }
        }

        // Count PCIe card usage
        if (isset($components['pciecard']) && !empty($components['pciecard'])) {
            foreach ($components['pciecard'] as $card) {
                $cardUuid = $card['uuid'] ?? null;
                if ($cardUuid) {
                    $cardSpecs = $dataUtils->getPCIeCardByUUID($cardUuid);
                    if ($cardSpecs) {
                        // Check if this is a riser card (riser cards use riser slots, not PCIe slots)
                        $isRiserCard = isset($cardSpecs['component_subtype']) &&
                                       $cardSpecs['component_subtype'] === 'Riser Card';

                        if ($isRiserCard) {
                            // DATA INTEGRITY CHECK: Warn if riser card has incorrect slot_position
                            $slotPos = strtolower($card['slot_position'] ?? '');
                            if (!empty($slotPos) && strpos($slotPos, 'riser_') !== 0) {
                                error_log("WARNING: Riser card $cardUuid has incorrect slot_position: '{$card['slot_position']}'. Should start with 'riser_'");
                            }

                            // Track riser card and its provided PCIe slots
                            $pcieSlots = $cardSpecs['pcie_slots'] ?? 0;
                            $slotType = $cardSpecs['slot_type'] ?? 'x16';

                            // Normalize slot type
                            if (preg_match('/x(\d+)/i', $slotType, $matches)) {
                                $slotSize = 'x' . $matches[1];
                            } else {
                                $slotSize = 'x16';
                            }

                            $riserInfo = [
                                'riser_uuid' => $cardUuid,
                                'riser_model' => $cardSpecs['model'] ?? 'Unknown Riser',
                                'riser_slot_position' => $card['slot_position'] ?? 'N/A',
                                'pcie_slots_provided' => $pcieSlots,
                                'slot_type' => $slotSize,
                                'cards_in_riser_slots' => []
                            ];

                            // Count how many slots this riser provides
                            $result['riser_provided_pcie_slots']['total_slots'] += $pcieSlots;

                            // Add riser to tracking list
                            $result['riser_provided_pcie_slots']['risers'][] = $riserInfo;

                            // Skip further processing - riser cards are tracked separately
                            continue;
                        }

                        $interface = $cardSpecs['interface'] ?? '';
                        preg_match('/x(\d+)/', $interface, $matches);
                        $lanes = (int)($matches[1] ?? 4);

                        $result['cpu_lanes']['used'] += $lanes;
                        $result['detailed_usage'][] = [
                            'component' => 'PCIe Card',
                            'uuid' => $cardUuid,
                            'type' => $interface,
                            'lanes_used' => $lanes
                        ];

                        // Track slot usage
                        if ($lanes == 16) {
                            $result['expansion_slots']['used_x16']++;
                        } elseif ($lanes == 8) {
                            $result['expansion_slots']['used_x8']++;
                        } elseif ($lanes == 4) {
                            $result['expansion_slots']['used_x4']++;
                        }

                        // Check if this PCIe card provides M.2 slots (NVMe adapters)
                        if (isset($cardSpecs['m2_slots']) && $cardSpecs['m2_slots'] > 0) {
                            $m2SlotsProvided = (int)$cardSpecs['m2_slots'];
                            $result['m2_slots']['expansion_cards']['total'] += $m2SlotsProvided;

                            // Add card to providers list with detailed specs
                            $result['m2_slots']['expansion_cards']['providers'][] = [
                                'uuid' => $cardUuid,
                                'name' => $cardSpecs['model'] ?? 'Unknown Model',
                                'slots_provided' => $m2SlotsProvided,
                                'max_capacity_per_slot' => $cardSpecs['max_capacity_per_slot'] ?? 'N/A',
                                'max_speed' => $cardSpecs['performance']['max_sequential_read'] ?? 'N/A',
                                'm2_form_factors' => $cardSpecs['m2_form_factors'] ?? []
                            ];
                        }
                    }
                }
            }
        }

        // After processing all PCIe cards, check which cards are in riser-provided slots
        // Loop through all non-riser PCIe cards, NICs, and HBA cards to find riser-slot assignments
        $allPCIeComponents = [];

        // Collect all PCIe components (EXCLUDING onboard NICs)
        if (isset($components['pciecard']) && !empty($components['pciecard'])) {
            foreach ($components['pciecard'] as $card) {
                $cardSpecs = $dataUtils->getPCIeCardByUUID($card['uuid'] ?? '');
                if ($cardSpecs && ($cardSpecs['component_subtype'] ?? '') !== 'Riser Card') {
                    $allPCIeComponents[] = $card;
                }
            }
        }
        if (isset($components['nic']) && !empty($components['nic'])) {
            foreach ($components['nic'] as $nic) {
                // CRITICAL: Skip onboard NICs - they don't use PCIe/riser slots
                $sourceType = $nic['source_type'] ?? '';
                if ($sourceType !== 'onboard') {
                    $allPCIeComponents[] = $nic;
                }
            }
        }
        if (isset($components['hbacard']) && !empty($components['hbacard'])) {
            $allPCIeComponents = array_merge($allPCIeComponents, $components['hbacard']);
        }

        // Check each component's slot_position to see if it's in a riser-provided slot
        $componentsWithExplicitSlots = [];
        $componentsWithoutSlots = [];

        foreach ($allPCIeComponents as $component) {
            $slotPosition = $component['slot_position'] ?? '';

            // Check if slot_position indicates riser-provided slot
            // Format: riser_{riser_uuid}_pcie_{slot_type}_slot_{n}
            if (preg_match('/^riser_([^_]+(?:_[^_]+)*?)_pcie_/', $slotPosition, $matches)) {
                $riserUuid = $matches[1];
                $result['riser_provided_pcie_slots']['used_slots']++;

                // Find the riser in our tracking list and add this card to it
                foreach ($result['riser_provided_pcie_slots']['risers'] as &$riser) {
                    if ($riser['riser_uuid'] === $riserUuid) {
                        $riser['cards_in_riser_slots'][] = [
                            'slot' => $slotPosition,
                            'card_uuid' => $component['uuid'] ?? 'unknown',
                            'card_type' => $component['component_type'] ?? 'unknown',
                            'serial_number' => $component['serial_number'] ?? 'N/A'
                        ];
                        break;
                    }
                }
                unset($riser); // Break reference
                $componentsWithExplicitSlots[] = $component;
            } else if (empty($slotPosition) || !preg_match('/^(pcie_x\d+_slot_|riser_)/', $slotPosition)) {
                // Component has no slot assignment OR has generic slot (like "slot 1")
                // Both cases might be using riser slot implicitly
                $componentsWithoutSlots[] = $component;
            }
        }

        // SMART DETECTION: If motherboard has 0 direct PCIe slots and risers exist,
        // components without explicit slot assignments MUST be using riser-provided slots
        $motherboardDirectPCIeSlots = $result['expansion_slots']['total_x16'] +
                                       $result['expansion_slots']['total_x8'] +
                                       $result['expansion_slots']['total_x4'];

        if ($motherboardDirectPCIeSlots === 0 &&
            !empty($result['riser_provided_pcie_slots']['risers']) &&
            !empty($componentsWithoutSlots)) {

            error_log("SMART DETECTION: Motherboard has 0 direct PCIe slots but has " . count($componentsWithoutSlots) . " PCIe components without slot assignment");

            // These components MUST be using riser-provided slots
            // Assign them to risers respecting physical slot limits
            $risers = &$result['riser_provided_pcie_slots']['risers'];

            foreach ($componentsWithoutSlots as $component) {
                $assigned = false;

                // Try to find a riser with available slots
                foreach ($risers as &$riser) {
                    $currentCardsInRiser = count($riser['cards_in_riser_slots']);
                    $maxSlotsInRiser = $riser['pcie_slots_provided'];

                    // Check if riser has available slots
                    if ($currentCardsInRiser < $maxSlotsInRiser) {
                        $result['riser_provided_pcie_slots']['used_slots']++;

                        $slotPositionValue = $component['slot_position'] ?? '';
                        $displaySlot = empty($slotPositionValue) ?
                            'auto-detected (no explicit slot assignment)' :
                            "auto-detected (generic slot: $slotPositionValue)";

                        $riser['cards_in_riser_slots'][] = [
                            'slot' => $displaySlot,
                            'card_uuid' => $component['uuid'] ?? 'unknown',
                            'card_type' => $component['component_type'] ?? 'unknown',
                            'serial_number' => $component['serial_number'] ?? 'N/A',
                            'note' => 'Motherboard has 0 direct PCIe slots - component must be using riser-provided slot'
                        ];

                        error_log("AUTO-ASSIGNED component {$component['uuid']} ({$component['component_type']}) to riser {$riser['riser_uuid']} (slot " . ($currentCardsInRiser + 1) . "/{$maxSlotsInRiser})");
                        $assigned = true;
                        break;
                    }
                }
                unset($riser); // Break reference

                // If component couldn't be assigned, log error
                if (!$assigned) {
                    error_log("ERROR: Component {$component['uuid']} ({$component['component_type']}) cannot be assigned - all riser slots full!");
                }
            }
        }

        // Calculate available slots
        $result['riser_provided_pcie_slots']['available_slots'] = max(0,
            $result['riser_provided_pcie_slots']['total_slots'] -
            $result['riser_provided_pcie_slots']['used_slots']
        );

        $result['cpu_lanes']['available'] = max(0, $result['cpu_lanes']['total'] - $result['cpu_lanes']['used']);
        $result['m2_slots']['motherboard']['available'] = max(0, $result['m2_slots']['motherboard']['total'] - $result['m2_slots']['motherboard']['used']);
        $result['m2_slots']['expansion_cards']['available'] = max(0, $result['m2_slots']['expansion_cards']['total'] - $result['m2_slots']['expansion_cards']['used']);

    } catch (Exception $e) {
        error_log("Error calculating PCIe lane usage: " . $e->getMessage());
    }

    return $result;
}

/**
 * Calculate riser slot usage and tracking with grouped slot type information
 */
function calculateRiserSlotUsage($components, $configUuid) {
    global $pdo;
    require_once __DIR__ . '/../../../core/models/compatibility/UnifiedSlotTracker.php';
    require_once __DIR__ . '/../../../core/models/shared/DataExtractionUtilities.php';

    $result = [
        'total_riser_slots' => 0,
        'used_riser_slots' => 0,
        'available_riser_slots' => 0,
        'total_slots_by_type' => [],
        'used_slots_by_type' => [],
        'available_slots_by_type' => [],
        'riser_assignments' => []
    ];

    try {
        $slotTracker = new UnifiedSlotTracker($pdo);
        $dataUtils = new DataExtractionUtilities($pdo);

        // Get motherboard riser slot information
        if (isset($components['motherboard']) && !empty($components['motherboard'])) {
            $mbUuid = $components['motherboard'][0]['uuid'] ?? null;
            if ($mbUuid) {
                // Get riser slot availability (now returns grouped format)
                $riserAvailability = $slotTracker->getRiserSlotAvailability($configUuid);

                if ($riserAvailability['success']) {
                    // Count total slots across all types (grouped format: ['x16' => [...], 'x8' => [...]])
                    $totalSlotsByType = [];
                    $totalCount = 0;
                    foreach ($riserAvailability['total_slots'] as $slotType => $slotIds) {
                        $count = count($slotIds);
                        $totalSlotsByType[$slotType] = $count;
                        $totalCount += $count;
                    }
                    $result['total_riser_slots'] = $totalCount;
                    $result['total_slots_by_type'] = $totalSlotsByType;

                    // Count used slots (flat mapping: ['riser_x16_slot_1' => 'uuid'])
                    $result['used_riser_slots'] = count($riserAvailability['used_slots']);

                    // Count used slots by type
                    $usedSlotsByType = [];
                    foreach ($riserAvailability['used_slots'] as $slotId => $riserUuid) {
                        // Extract slot type from slot ID (e.g., "riser_x16_slot_1" -> "x16")
                        if (preg_match('/riser_(x\d+)_slot_/', $slotId, $matches)) {
                            $slotType = $matches[1];
                            if (!isset($usedSlotsByType[$slotType])) {
                                $usedSlotsByType[$slotType] = 0;
                            }
                            $usedSlotsByType[$slotType]++;
                        }
                    }
                    $result['used_slots_by_type'] = $usedSlotsByType;

                    // Count available slots by type (grouped format)
                    $availableSlotsByType = [];
                    $availableCount = 0;
                    foreach ($riserAvailability['available_slots'] as $slotType => $slotIds) {
                        $count = count($slotIds);
                        $availableSlotsByType[$slotType] = $count;
                        $availableCount += $count;
                    }
                    $result['available_riser_slots'] = $availableCount;
                    $result['available_slots_by_type'] = $availableSlotsByType;

                    // Get detailed riser assignments
                    foreach ($riserAvailability['used_slots'] as $slotId => $riserUuid) {
                        $riserSpecs = $dataUtils->getPCIeCardByUUID($riserUuid);

                        // Extract slot type from slot ID
                        $slotType = 'unknown';
                        if (preg_match('/riser_(x\d+)_slot_/', $slotId, $matches)) {
                            $slotType = $matches[1];
                        }

                        $result['riser_assignments'][] = [
                            'slot_id' => $slotId,
                            'slot_type' => $slotType,
                            'riser_uuid' => $riserUuid,
                            'riser_model' => $riserSpecs['model'] ?? 'Unknown Riser',
                            'riser_slot_type' => $riserSpecs['slot_type'] ?? 'Unknown',
                            'interface' => $riserSpecs['interface'] ?? 'Unknown'
                        ];
                    }
                }
            }
        }

    } catch (Exception $e) {
        error_log("Error calculating riser slot usage: " . $e->getMessage());
    }

    return $result;
}

/**
 * Get current configuration warnings
 */
function getConfigurationWarnings($components) {
    global $pdo;
    require_once __DIR__ . '/../../../core/models/shared/DataExtractionUtilities.php';
    $dataUtils = new DataExtractionUtilities($pdo);

    $warnings = [];

    try {
        // Check M.2 slot capacity
        $m2Count = 0;
        $m2TotalSlots = 0;

        if (isset($components['motherboard']) && !empty($components['motherboard'])) {
            $mbUuid = $components['motherboard'][0]['uuid'] ?? null;
            if ($mbUuid) {
                $mbSpecs = $dataUtils->getMotherboardByUUID($mbUuid);
                if ($mbSpecs && isset($mbSpecs['storage']['nvme']['m2_slots'][0]['count'])) {
                    $m2TotalSlots = $mbSpecs['storage']['nvme']['m2_slots'][0]['count'];
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
                            $formFactor = strtolower($caddySpecs['form_factor'] ?? '');
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
 * Helper function to get suggested alternatives
 */
function getSuggestedAlternatives($pdo, $componentType, $excludeUuid, $limit = 5) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return [];
    }
    
    $table = $tableMap[$componentType];
    
    try {
        $stmt = $pdo->prepare("
            SELECT UUID, SerialNumber, Status, ServerUUID 
            FROM $table 
            WHERE UUID != ? AND Status = 1 
            ORDER BY SerialNumber 
            LIMIT ?
        ");
        $stmt->execute([$excludeUuid, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting suggested alternatives: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get available components
 */
function getAvailableComponents($pdo, $componentType, $availableOnly = true, $limit = 50) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return [];
    }
    
    $table = $tableMap[$componentType];
    $whereClause = $availableOnly ? "WHERE Status = 1" : "WHERE Status IN (1, 2)";
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM $table 
            $whereClause 
            ORDER BY Status ASC, SerialNumber ASC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting available components for $componentType: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get component count
 */
function getComponentCount($pdo, $componentType) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return ['total' => 0, 'available' => 0, 'in_use' => 0, 'failed' => 0];
    }
    
    $table = $tableMap[$componentType];
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN Status = 1 THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN Status = 2 THEN 1 ELSE 0 END) as in_use,
                SUM(CASE WHEN Status = 0 THEN 1 ELSE 0 END) as failed
            FROM $table
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting component count for $componentType: " . $e->getMessage());
        return ['total' => 0, 'available' => 0, 'in_use' => 0, 'failed' => 0];
    }
}

/**
 * Helper function to parse motherboard specifications from Notes field
 */
function parseMotherboardSpecs($motherboardDetails) {
    $specs = [
        'cpu_sockets' => 1,
        'memory_slots' => 4,
        'storage_slots' => [
            'sata_ports' => 4,
            'm2_slots' => 1,
            'u2_slots' => 0
        ],
        'pcie_slots' => [
            'x16_slots' => 1,
            'x8_slots' => 0,
            'x4_slots' => 0,
            'x1_slots' => 0
        ],
        'socket_type' => 'Unknown',
        'memory_type' => 'DDR4'
    ];
    
    try {
        // Try to find matching motherboard JSON data
        $jsonFiles = [
            __DIR__ . '/../../All-JSON/motherboard-jsons/motherboard-level-3.json'
        ];
        
        $serialNumber = $motherboardDetails['SerialNumber'];
        $notes = $motherboardDetails['Notes'] ?? '';
        
        foreach ($jsonFiles as $jsonFile) {
            if (file_exists($jsonFile)) {
                $jsonData = json_decode(file_get_contents($jsonFile), true);
                if ($jsonData) {
                    $matchedSpecs = findMotherboardInJSON($jsonData, $serialNumber, $notes);
                    if ($matchedSpecs) {
                        $specs = array_merge($specs, $matchedSpecs);
                        break;
                    }
                }
            }
        }
        
        // Fallback: Parse from Notes field if JSON not found
        if ($notes) {
            $parsedFromNotes = parseSpecsFromNotes($notes);
            $specs = array_merge($specs, $parsedFromNotes);
        }
        
    } catch (Exception $e) {
        error_log("Error parsing motherboard specs: " . $e->getMessage());
    }
    
    return $specs;
}

/**
 * Find motherboard specifications in JSON data
 */
function findMotherboardInJSON($jsonData, $serialNumber, $notes) {
    foreach ($jsonData as $brand) {
        if (isset($brand['models'])) {
            foreach ($brand['models'] as $model) {
                // Check if this model matches our motherboard
                if (stripos($notes, $model['model']) !== false) {
                    return extractSpecsFromJSON($model);
                }
            }
        }
        
        // Check family level models
        if (isset($brand['family']) && is_array($brand['family'])) {
            foreach ($brand['family'] as $family) {
                if (isset($family['models'])) {
                    foreach ($family['models'] as $model) {
                        if (stripos($notes, $model['model']) !== false) {
                            return extractSpecsFromJSON($model);
                        }
                    }
                }
            }
        }
        
        // Check series level
        if (isset($brand['series'])) {
            foreach ($brand as $key => $value) {
                if ($key === 'models' && is_array($value)) {
                    foreach ($value as $model) {
                        if (stripos($notes, $model['model']) !== false) {
                            return extractSpecsFromJSON($model);
                        }
                    }
                }
            }
        }
    }
    
    return null;
}

/**
 * Extract specifications from JSON model data
 */
function extractSpecsFromJSON($modelData) {
    $specs = [];
    
    // CPU socket information
    if (isset($modelData['socket'])) {
        $specs['cpu_sockets'] = $modelData['socket']['count'] ?? 1;
        $specs['socket_type'] = $modelData['socket']['type'] ?? 'Unknown';
    }
    
    // Memory information
    if (isset($modelData['memory'])) {
        $specs['memory_slots'] = $modelData['memory']['slots'] ?? 4;
        $specs['memory_type'] = $modelData['memory']['type'] ?? 'DDR4';
        $specs['memory_max_capacity'] = $modelData['memory']['max_capacity_TB'] ?? 1;
        $specs['memory_channels'] = $modelData['memory']['channels'] ?? 2;
    }
    
    // Storage information
    $storageSlots = [];
    if (isset($modelData['storage'])) {
        if (isset($modelData['storage']['sata']['ports'])) {
            $storageSlots['sata_ports'] = $modelData['storage']['sata']['ports'];
        }
        if (isset($modelData['storage']['nvme']['m2_slots'])) {
            $m2Count = 0;
            foreach ($modelData['storage']['nvme']['m2_slots'] as $m2Slot) {
                $m2Count += $m2Slot['count'] ?? 0;
            }
            $storageSlots['m2_slots'] = $m2Count;
        }
        if (isset($modelData['storage']['nvme']['u2_slots']['count'])) {
            $storageSlots['u2_slots'] = $modelData['storage']['nvme']['u2_slots']['count'];
        }
    }
    $specs['storage_slots'] = $storageSlots;
    
    // PCIe slots information
    $pcieSlots = [];
    if (isset($modelData['expansion_slots']['pcie_slots'])) {
        foreach ($modelData['expansion_slots']['pcie_slots'] as $slot) {
            $slotType = $slot['type'] ?? '';
            $count = $slot['count'] ?? 0;
            
            if (strpos($slotType, 'x16') !== false) {
                $pcieSlots['x16_slots'] = ($pcieSlots['x16_slots'] ?? 0) + $count;
            } elseif (strpos($slotType, 'x8') !== false) {
                $pcieSlots['x8_slots'] = ($pcieSlots['x8_slots'] ?? 0) + $count;
            } elseif (strpos($slotType, 'x4') !== false) {
                $pcieSlots['x4_slots'] = ($pcieSlots['x4_slots'] ?? 0) + $count;
            } elseif (strpos($slotType, 'x1') !== false) {
                $pcieSlots['x1_slots'] = ($pcieSlots['x1_slots'] ?? 0) + $count;
            }
        }
    }
    $specs['pcie_slots'] = $pcieSlots;
    
    return $specs;
}

/**
 * Parse basic specs from Notes field as fallback
 */
function parseSpecsFromNotes($notes) {
    $specs = [];
    
    // Try to extract basic information from notes
    if (preg_match('/(\d+)[\s]*socket/i', $notes, $matches)) {
        $specs['cpu_sockets'] = (int)$matches[1];
    }
    
    if (preg_match('/(\d+)[\s]*dimm/i', $notes, $matches)) {
        $specs['memory_slots'] = (int)$matches[1];
    }
    if (preg_match('/DDR(\d)/i', $notes, $matches)) {
        $specs['memory_type'] = 'DDR' . $matches[1];
    }
    
    return $specs;
}

/**
 * NEW: Update server location and rack position, and propagate to all assigned components
 * This function handles the specific case of updating deployment location information
 */
function handleUpdateLocationAndPropagate($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $location = trim($_POST['location'] ?? '');
    $rackPosition = trim($_POST['rack_position'] ?? '');
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Load the configuration to verify it exists and check permissions
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check if user owns this configuration or has edit permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to modify this configuration");
        }
        
        // Begin transaction to ensure all updates succeed or fail together
        $pdo->beginTransaction();
        
        // Update the server configuration location and rack position
        $updateFields = [];
        $updateValues = [];
        $changes = [];
        
        $currentLocation = $config->get('location');
        $currentRackPosition = $config->get('rack_position');
        
        if ($location !== $currentLocation) {
            $updateFields[] = "location = ?";
            $updateValues[] = $location ?: null;
            $changes['location'] = ['old' => $currentLocation, 'new' => $location ?: null];
        }
        
        if ($rackPosition !== $currentRackPosition) {
            $updateFields[] = "rack_position = ?";
            $updateValues[] = $rackPosition ?: null;
            $changes['rack_position'] = ['old' => $currentRackPosition, 'new' => $rackPosition ?: null];
        }
        
        if (empty($updateFields)) {
            $pdo->rollback();
            send_json_response(1, 1, 200, "No location changes detected", [
                'config_uuid' => $configUuid,
                'current_location' => $currentLocation,
                'current_rack_position' => $currentRackPosition
            ]);
        }
        
        // Update server configuration
        $updateFields[] = "updated_by = ?";
        $updateFields[] = "updated_at = NOW()";
        $updateValues[] = $user['id'];
        $updateValues[] = $configUuid;
        
        $sql = "UPDATE server_configurations SET " . implode(', ', $updateFields) . " WHERE config_uuid = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($updateValues);
        
        if (!$result) {
            $pdo->rollback();
            send_json_response(0, 1, 500, "Failed to update server configuration location");
        }
        
        // Get all components assigned to this configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        $assignedComponents = extractComponentsFromConfigData($config->getData());
        
        // Update all assigned components with new location and rack position
        $componentUpdateCount = 0;
        $componentTables = [
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        foreach ($assignedComponents as $component) {
            $componentType = $component['component_type'];
            $componentUuid = $component['component_uuid'];
            
            if (!isset($componentTables[$componentType])) {
                continue;
            }
            
            $table = $componentTables[$componentType];
    
            // Build component update query
            
            $compUpdateFields = [];
            $compUpdateValues = [];
            
            if (isset($changes['location'])) {
                $compUpdateFields[] = "Location = ?";
                $compUpdateValues[] = $location ?: null;
            }
            
            if (isset($changes['rack_position'])) {
                $compUpdateFields[] = "RackPosition = ?";
                $compUpdateValues[] = $rackPosition ?: null;
            }
            
            if (!empty($compUpdateFields)) {
                $compUpdateFields[] = "UpdatedAt = NOW()";
                $compUpdateValues[] = $componentUuid;
                
                $compSql = "UPDATE $table SET " . implode(', ', $compUpdateFields) . " WHERE UUID = ?";
                $compStmt = $pdo->prepare($compSql);
                if ($compStmt->execute($compUpdateValues)) {
                    $componentUpdateCount++;
                    error_log("Updated location for component $componentUuid in $table");
                } else {
                    error_log("Failed to update location for component $componentUuid in $table");
                }
            }
        }
        
        $pdo->commit();
        
        // Log the update action
        logConfigurationUpdate($pdo, $configUuid, $changes, $user['id']);
        
        send_json_response(1, 1, 200, "Server location updated and propagated to components successfully", [
            'config_uuid' => $configUuid,
            'changes_made' => $changes,
            'components_updated' => $componentUpdateCount,
            'total_assigned_components' => count($assignedComponents),
            'new_location' => $location ?: null,
            'new_rack_position' => $rackPosition ?: null,
            'updated_by_user_id' => $user['id'],
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Error updating server location: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to update server location: " . $e->getMessage());
    }
}

/**
 * Replace onboard NIC with a component NIC from inventory
 * Allows swapping out motherboard-integrated NICs with separate NIC cards
 */
/**
 * Debug endpoint to check motherboard specifications and onboard NIC details
 */
function handleDebugMotherboardNICs($user) {
    global $pdo;

    $configUuid = $_POST['config_uuid'] ?? $_GET['config_uuid'] ?? '';

    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }

    try {
        // Get motherboard from configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Configuration not found");
        }

        $motherboardUuid = $config->get('motherboard_uuid');

        if (!$motherboardUuid) {
            send_json_response(0, 1, 404, "No motherboard found in this configuration");
        }

        // Load motherboard specs from JSON
        require_once __DIR__ . '/../../../core/models/components/ComponentDataService.php';
        $dataService = ComponentDataService::getInstance();
        $mbSpecs = $dataService->findComponentByUuid('motherboard', $motherboardUuid);

        // Extract all components from configuration
        $components = extractComponentsFromConfigData($config->getData());

        $debugData = [
            'config_uuid' => $configUuid,
            'motherboard_uuid' => $motherboardUuid,
            'motherboard_found_in_json' => !is_null($mbSpecs),
            'has_networking_section' => isset($mbSpecs['networking']),
            'has_onboard_nics' => isset($mbSpecs['networking']['onboard_nics']),
            'onboard_nics_count' => isset($mbSpecs['networking']['onboard_nics']) ? count($mbSpecs['networking']['onboard_nics']) : 0,
            'onboard_nics_details' => $mbSpecs['networking']['onboard_nics'] ?? [],
            'full_networking_section' => $mbSpecs['networking'] ?? null
        ];

        // Check current database state
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM nicinventory
            WHERE ParentComponentUUID = ? AND SourceType = 'onboard'
        ");
        $stmt->execute([$motherboardUuid]);
        $debugData['nics_already_in_inventory'] = (int)$stmt->fetchColumn();

        // Count NICs in configuration from JSON
        $nicComponents = array_filter($components, function($c) { return $c['component_type'] === 'nic'; });
        $debugData['nics_in_config_components'] = count($nicComponents);

        send_json_response(1, 1, 200, "Motherboard debug information retrieved", $debugData);

    } catch (Exception $e) {
        error_log("Error in debug endpoint: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to retrieve debug information: " . $e->getMessage());
    }
}

/**
 * Fix/Sync onboard NICs for existing configurations
 * Retroactively adds onboard NICs from motherboards that were added before this feature existed
 */
function handleFixOnboardNICs($user) {
    global $pdo;

    $configUuid = $_POST['config_uuid'] ?? '';

    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }

    try {
        // Load the configuration to verify it exists and check permissions
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }

        // Check if user owns this configuration or has edit permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to modify this configuration");
        }

        // Get motherboard from configuration
        $motherboardUuid = $config->get('motherboard_uuid');

        if (!$motherboardUuid) {
            send_json_response(0, 1, 404, "No motherboard found in this configuration", [
                'config_uuid' => $configUuid,
                'message' => 'Cannot extract onboard NICs without a motherboard'
            ]);
        }

        // Check if onboard NICs already exist
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM nicinventory
            WHERE ParentComponentUUID = ? AND SourceType = 'onboard' AND ServerUUID = ?
        ");
        $stmt->execute([$motherboardUuid, $configUuid]);
        $existingOnboardNICs = $stmt->fetchColumn();

        if ($existingOnboardNICs > 0) {
            // Already have onboard NICs, just update the JSON
            require_once __DIR__ . '/../../../core/models/compatibility/OnboardNICHandler.php';
            $nicHandler = new OnboardNICHandler($pdo);
            $nicHandler->updateNICConfigJSON($configUuid);

            send_json_response(1, 1, 200, "Onboard NICs already exist, updated configuration", [
                'config_uuid' => $configUuid,
                'existing_onboard_nics' => (int)$existingOnboardNICs,
                'action' => 'updated_json_only'
            ]);
        }

        // Extract and add onboard NICs from motherboard
        require_once __DIR__ . '/../../../core/models/compatibility/OnboardNICHandler.php';
        $nicHandler = new OnboardNICHandler($pdo);

        $result = $nicHandler->autoAddOnboardNICs($configUuid, $motherboardUuid);

        // Add debug information to result
        $debugInfo = [
            'config_uuid' => $configUuid,
            'motherboard_uuid' => $motherboardUuid,
            'result' => $result
        ];

        if ($result['count'] > 0) {
            // Verify NICs were actually inserted
            $components = extractComponentsFromConfigData($config->getData());
            $nicCountInComponents = count(array_filter($components, function($c) { return $c['component_type'] === 'nic'; }));

            $verifyInventoryStmt = $pdo->prepare("
                SELECT COUNT(*) FROM nicinventory
                WHERE ServerUUID = ? AND SourceType = 'onboard'
            ");
            $verifyInventoryStmt->execute([$configUuid]);
            $nicCountInInventory = $verifyInventoryStmt->fetchColumn();

            send_json_response(1, 1, 200, "Successfully added onboard NICs from motherboard", [
                'config_uuid' => $configUuid,
                'motherboard_uuid' => $motherboardUuid,
                'onboard_nics_added' => $result['count'],
                'nics' => $result['nics'],
                'message' => $result['message'],
                'verification' => [
                    'nics_in_components_table' => (int)$nicCountInComponents,
                    'nics_in_inventory' => (int)$nicCountInInventory
                ]
            ]);
        } else {
            send_json_response(0, 1, 404, "No onboard NICs found in motherboard specifications", [
                'config_uuid' => $configUuid,
                'motherboard_uuid' => $motherboardUuid,
                'message' => $result['message'] ?? 'Motherboard does not have onboard NICs in JSON specifications',
                'debug' => $debugInfo
            ]);
        }

    } catch (Exception $e) {
        error_log("Error fixing onboard NICs: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to fix onboard NICs: " . $e->getMessage());
    }
}

function handleReplaceOnboardNIC($user) {
    global $pdo;

    $configUuid = $_POST['config_uuid'] ?? '';
    $onboardNICUuid = $_POST['onboard_nic_uuid'] ?? '';
    $componentNICUuid = $_POST['component_nic_uuid'] ?? '';

    if (empty($configUuid) || empty($onboardNICUuid) || empty($componentNICUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID, onboard NIC UUID, and component NIC UUID are required");
    }

    try {
        // Load the configuration to verify it exists and check permissions
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }

        // Check if user owns this configuration or has edit permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to modify this configuration");
        }

        // Verify onboard NIC UUID format
        if (!str_starts_with($onboardNICUuid, 'onboard-nic-')) {
            send_json_response(0, 1, 400, "Invalid onboard NIC UUID format. Must start with 'onboard-nic-'");
        }

        // Use OnboardNICHandler to perform replacement
        require_once __DIR__ . '/../../../core/models/compatibility/OnboardNICHandler.php';
        $nicHandler = new OnboardNICHandler($pdo);

        $result = $nicHandler->replaceOnboardNIC($configUuid, $onboardNICUuid, $componentNICUuid);

        if ($result['success']) {
            send_json_response(1, 1, 200, $result['message'], [
                'config_uuid' => $configUuid,
                'replaced_onboard_nic' => $result['replaced_onboard_nic'],
                'new_component_nic' => $result['new_component_nic'],
                'updated_by_user_id' => $user['id'],
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            send_json_response(0, 1, 400, $result['error'], [
                'config_uuid' => $configUuid,
                'onboard_nic_uuid' => $onboardNICUuid,
                'component_nic_uuid' => $componentNICUuid
            ]);
        }

    } catch (Exception $e) {
        error_log("Error replacing onboard NIC: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to replace onboard NIC: " . $e->getMessage());
    }
}

/**
 * Enhanced component compatibility checking using JSON integration and proper socket validation
 * This uses the existing ComponentCompatibility class methods to provide accurate compatibility scores
 */
function checkEnhancedComponentCompatibility($compatibility, $componentType, $component, $motherboard) {
    try {
        error_log("DEBUG: Checking compatibility for $componentType UUID: " . $component['UUID']);
        
        // Extract socket types using the existing JSON integration methods
        $componentSocket = null;
        $motherboardSocket = null;
        
        try {
            $componentSocket = $compatibility->extractSocketTypeFromJSON($componentType, $component['UUID']);
            error_log("DEBUG: Component socket extracted: " . ($componentSocket ?? 'null'));
        } catch (Exception $e) {
            error_log("ERROR: Failed to extract component socket: " . $e->getMessage());
        }
        
        try {
            $motherboardSocket = $compatibility->extractSocketTypeFromJSON('motherboard', $motherboard['UUID']);
            error_log("DEBUG: Motherboard socket extracted: " . ($motherboardSocket ?? 'null'));
        } catch (Exception $e) {
            error_log("ERROR: Failed to extract motherboard socket: " . $e->getMessage());
        }
        
        error_log("DEBUG: Final sockets - Component: " . ($componentSocket ?? 'null') . ", Motherboard: " . ($motherboardSocket ?? 'null'));
        
        // Component-specific compatibility checks
        switch ($componentType) {
            case 'cpu':
                return checkEnhancedCPUCompatibility($compatibility, $component, $motherboard, $componentSocket, $motherboardSocket);
            case 'ram':
                return checkEnhancedRAMCompatibility($compatibility, $component, $motherboard);
            case 'storage':
                return checkEnhancedStorageCompatibility($compatibility, $component, $motherboard);
            case 'nic':
                return checkEnhancedNICCompatibility($compatibility, $component, $motherboard);
            case 'caddy':
                return checkEnhancedCaddyCompatibility($compatibility, $component, $motherboard);
            default:
                return [
                    'compatible' => true,
                    'reason' => 'Component type compatibility checking not implemented'
                ];
        }
        
    } catch (Exception $e) {
        error_log("ERROR: Enhanced compatibility check failed: " . $e->getMessage());
        return [
            'compatible' => true,
            'reason' => 'Compatibility check error - defaulting to compatible',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Enhanced CPU compatibility checking with JSON socket validation
 */
function checkEnhancedCPUCompatibility($compatibility, $cpu, $motherboard, $cpuSocket, $motherboardSocket) {
    try {
        // Check if we have socket information from JSON
        if ($cpuSocket && $motherboardSocket) {
            // Normalize socket names for comparison
            $cpuSocketNorm = normalizeSocketName($cpuSocket);
            $motherboardSocketNorm = normalizeSocketName($motherboardSocket);
            
            if ($cpuSocketNorm === $motherboardSocketNorm) {
                return [
                    'compatible' => true,
                    'reason' => "Perfect match: Both CPU and motherboard use $cpuSocket socket",
                    'details' => ['cpu_socket' => $cpuSocket, 'motherboard_socket' => $motherboardSocket]
                ];
            } else {
                return [
                    'compatible' => false,
                    'reason' => "Incompatible: CPU uses $cpuSocket socket but motherboard uses $motherboardSocket",
                    'details' => ['cpu_socket' => $cpuSocket, 'motherboard_socket' => $motherboardSocket]
                ];
            }
        }
        
        // Fallback to Notes parsing if JSON data not available
        $cpuNotes = strtoupper($cpu['Notes'] ?? '');
        $motherboardNotes = strtoupper($motherboard['Notes'] ?? '');
        
        // Try to extract socket from Notes
        $cpuSocketFromNotes = extractSocketFromNotes($cpuNotes);
        $motherboardSocketFromNotes = extractSocketFromNotes($motherboardNotes);
        
        if ($cpuSocketFromNotes && $motherboardSocketFromNotes) {
            $cpuSocketNorm = normalizeSocketName($cpuSocketFromNotes);
            $motherboardSocketNorm = normalizeSocketName($motherboardSocketFromNotes);
            
            if ($cpuSocketNorm === $motherboardSocketNorm) {
                return [
                    'compatible' => true,
                    'reason' => "Good match: Socket types from notes compatible ($cpuSocketFromNotes)",
                    'details' => ['cpu_socket' => $cpuSocketFromNotes, 'motherboard_socket' => $motherboardSocketFromNotes, 'source' => 'notes']
                ];
            } else {
                return [
                    'compatible' => false,
                    'reason' => "Incompatible: Notes indicate socket mismatch ($cpuSocketFromNotes vs $motherboardSocketFromNotes)",
                    'details' => ['cpu_socket' => $cpuSocketFromNotes, 'motherboard_socket' => $motherboardSocketFromNotes, 'source' => 'notes']
                ];
            }
        }
        
        // Unknown compatibility - need manual verification
        return [
            'compatible' => true,
            'reason' => "Unknown: CPU socket not found in specifications - manual verification required",
            'details' => ['cpu_uuid' => $cpu['UUID'], 'motherboard_uuid' => $motherboard['UUID']]
        ];
        
    } catch (Exception $e) {
        error_log("ERROR: Enhanced CPU compatibility check failed: " . $e->getMessage());
        return [
            'compatible' => true,
            'reason' => 'CPU compatibility check failed - defaulting to compatible',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Enhanced RAM compatibility checking
 */
function checkEnhancedRAMCompatibility($compatibility, $ram, $motherboard) {
    // Implementation similar to existing checkRAMCompatibility but with JSON integration
    return checkRAMCompatibility(null, $ram, $motherboard, null);
}

/**
 * Enhanced Storage compatibility checking  
 */
/**
 * DEPRECATED: Enhanced storage compatibility check
 * Now handled by StorageConnectionValidator
 */
function checkEnhancedStorageCompatibility($compatibility, $storage, $motherboard) {
    return ['compatible' => true, 'reason' => 'Handled by FlexibleCompatibilityValidator', 'deprecated' => true];
}

/**
 * Enhanced NIC compatibility checking
 */
function checkEnhancedNICCompatibility($compatibility, $nic, $motherboard) {
    return checkNICCompatibility(null, $nic, $motherboard, null);
}

/**
 * Enhanced Caddy compatibility checking
 */
function checkEnhancedCaddyCompatibility($compatibility, $caddy, $motherboard) {
    return checkCaddyCompatibility(null, $caddy, $motherboard, null);
}

/**
 * Normalize socket names for consistent comparison
 */
function normalizeSocketName($socketName) {
    if (!$socketName) return null;
    
    $normalized = strtoupper(trim($socketName));
    
    // Remove spaces and normalize common variations
    $normalized = str_replace(' ', '', $normalized);
    $normalized = str_replace('-', '', $normalized);
    
    // Handle common socket name variations
    $socketMappings = [
        'LGA4189' => 'LGA4189',
        'LGA1200' => 'LGA1200', 
        'LGA1700' => 'LGA1700',
        'LGA2011' => 'LGA2011',
        'LGA2066' => 'LGA2066',
        'AM4' => 'AM4',
        'AM5' => 'AM5',
        'SP3' => 'SP3',
        'SP5' => 'SP5'
    ];
    
    return $socketMappings[$normalized] ?? $normalized;
}

/**
 * Extract socket type from Notes field using pattern matching
 */
function extractSocketFromNotes($notes) {
    if (!$notes) return null;
    
    // Common socket patterns
    $patterns = [
        '/LGA\s?(\d{4})/',    // LGA1200, LGA 1700, etc.
        '/AM(\d)/',           // AM4, AM5
        '/SP(\d)/',           // SP3, SP5
        '/Socket\s+(\w+)/',   // Socket AM4, Socket LGA1200
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $notes, $matches)) {
            if (isset($matches[1])) {
                // Reconstruct socket name
                if (strpos($pattern, 'LGA') !== false) {
                    return 'LGA' . $matches[1];
                } elseif (strpos($pattern, 'AM') !== false) {
                    return 'AM' . $matches[1];
                } elseif (strpos($pattern, 'SP') !== false) {
                    return 'SP' . $matches[1];
                }
            }
            return $matches[0];
        }
    }
    
    return null;
}

/**
 * Direct Notes-based compatibility checking (fallback when ComponentCompatibility class not available)
 */
function checkDirectNotesCompatibility($componentType, $component, $motherboard) {
    try {
        error_log("DEBUG: Direct Notes compatibility check for $componentType: " . $component['UUID']);
        
        $componentNotes = strtoupper($component['Notes'] ?? '');
        $motherboardNotes = strtoupper($motherboard['Notes'] ?? '');
        
        error_log("DEBUG: Component notes: " . $componentNotes);
        error_log("DEBUG: Motherboard notes: " . $motherboardNotes);
        
        switch ($componentType) {
            case 'cpu':
                return checkDirectCPUNotesCompatibility($component, $motherboard, $componentNotes, $motherboardNotes);
            case 'ram':
                return checkDirectRAMNotesCompatibility($component, $motherboard, $componentNotes, $motherboardNotes);
            default:
                return [
                    'compatible' => true,
                    'compatibility_score' => 75.0,
                    'reason' => 'Direct Notes compatibility check - component type not fully supported'
                ];
        }
        
    } catch (Exception $e) {
        error_log("ERROR: Direct Notes compatibility check failed: " . $e->getMessage());
        return [
            'compatible' => true,
            'reason' => 'Direct compatibility check error - defaulting to compatible'
        ];
    }
}

/**
 * Direct CPU Notes compatibility checking
 */
function checkDirectCPUNotesCompatibility($cpu, $motherboard, $cpuNotes, $motherboardNotes) {
    // Known CPU to motherboard socket mappings based on your data
    $cpuSocketMappings = [
        'PLATINUM 8480+' => 'LGA4189',
        'INTEL 8470' => 'LGA4189',
        'AMD EPYC' => 'SP3',
        'EPYC 64-CORE' => 'SP3'
    ];
    
    $motherboardSocketMappings = [
        'X13DRI-N' => 'LGA4189',
        'X13DRG' => 'LGA4189',
        'SUPERMICRO' => 'LGA4189' // Default for Supermicro server boards
    ];
    
    // Extract socket from CPU notes
    $cpuSocket = null;
    foreach ($cpuSocketMappings as $cpuModel => $socket) {
        if (strpos($cpuNotes, $cpuModel) !== false) {
            $cpuSocket = $socket;
            break;
        }
    }
    
    // Extract socket from motherboard notes  
    $motherboardSocket = null;
    foreach ($motherboardSocketMappings as $mbModel => $socket) {
        if (strpos($motherboardNotes, $mbModel) !== false) {
            $motherboardSocket = $socket;
            break;
        }
    }
    
    // Also try pattern matching
    if (!$cpuSocket) {
        $cpuSocket = extractSocketFromNotes($cpuNotes);
    }
    if (!$motherboardSocket) {
        $motherboardSocket = extractSocketFromNotes($motherboardNotes);
    }
    
    error_log("DEBUG: Extracted sockets - CPU: " . ($cpuSocket ?: 'null') . ", MB: " . ($motherboardSocket ?: 'null'));
    
    // Determine compatibility
    if ($cpuSocket && $motherboardSocket) {
        $cpuSocketNorm = normalizeSocketName($cpuSocket);
        $motherboardSocketNorm = normalizeSocketName($motherboardSocket);
        
        if ($cpuSocketNorm === $motherboardSocketNorm) {
            return [
                'compatible' => true,
                'reason' => "Good match: CPU $cpuSocket matches motherboard $motherboardSocket from Notes analysis"
            ];
        } else {
            return [
                'compatible' => false,
                'reason' => "Socket mismatch: CPU $cpuSocket vs motherboard $motherboardSocket from Notes analysis"
            ];
        }
    } elseif ($cpuSocket || $motherboardSocket) {
        $knownSocket = $cpuSocket ?: $motherboardSocket;
        return [
            'compatible' => true,
            'reason' => "Partial match: Found socket $knownSocket but need verification for other component"
        ];
    } else {
        // Check for already compatible components (Status = 2 means in use in this config)
        if ($cpu['Status'] == 2) {
            return [
                'compatible' => true,
                'reason' => "Already in configuration: Component successfully added previously, compatibility confirmed"
            ];
        }

        return [
            'compatible' => true,
            'reason' => "Socket types unknown from Notes - manual verification needed"
        ];
    }
}

/**
 * Direct RAM Notes compatibility checking
 */
function checkDirectRAMNotesCompatibility($ram, $motherboard, $ramNotes, $motherboardNotes) {
    // Basic DDR type checking
    $ramDDRType = 'DDR4'; // Default
    if (preg_match('/DDR(\d)/', $ramNotes, $matches)) {
        $ramDDRType = 'DDR' . $matches[1];
    }
    
    $motherboardDDRType = 'DDR5'; // Server boards typically support DDR5
    if (preg_match('/DDR(\d)/', $motherboardNotes, $matches)) {
        $motherboardDDRType = 'DDR' . $matches[1];
    }
    
    if ($ramDDRType === $motherboardDDRType) {
        return [
            'compatible' => true,
            'reason' => "Memory type compatible: Both support $ramDDRType"
        ];
    } else {
        return [
            'compatible' => false,
            'reason' => "Memory type mismatch: RAM is $ramDDRType but motherboard expects $motherboardDDRType"
        ];
    }
}

/**
 * Check component compatibility with motherboard - Component-specific compatibility checks
 * Implements the compatibility logic specified in Important-fix requirements
 */
function checkComponentCompatibilityWithMotherboard($pdo, $componentType, $component, $motherboard, $motherboardSpecs) {
    try {
        // Initialize compatibility result
        $result = [
            'compatible' => true,
            'reason' => 'Compatible',
            'details' => []
        ];
        
        // Component-specific compatibility checks as specified in Important-fix
        switch ($componentType) {
            case 'cpu':
                return checkCPUCompatibility($pdo, $component, $motherboard, $motherboardSpecs);
                
            case 'ram':
                return checkRAMCompatibility($pdo, $component, $motherboard, $motherboardSpecs);
                
            case 'storage':
                return checkStorageCompatibility($pdo, $component, $motherboard, $motherboardSpecs);
                
            case 'nic':
                return checkNICCompatibility($pdo, $component, $motherboard, $motherboardSpecs);
                
            case 'caddy':
                return checkCaddyCompatibility($pdo, $component, $motherboard, $motherboardSpecs);
                
            default:
                $result['compatibility_score'] = 85.0;
                $result['reason'] = 'Basic compatibility - component type validation not implemented';
                return $result;
        }
        
    } catch (Exception $e) {
        error_log("Error checking compatibility: " . $e->getMessage());
        return [
            'compatible' => true, // Default to compatible on errors to avoid blocking
            'compatibility_score' => 70.0,
            'reason' => 'Compatibility check failed - defaulting to compatible',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Check CPU compatibility with motherboard - Socket type compatibility
 */
function checkCPUCompatibility($pdo, $cpu, $motherboard, $motherboardSpecs) {
    try {
        // Use ComponentCompatibility class if available for enhanced socket checking
        if (class_exists('ComponentCompatibility')) {
            $compatibility = new ComponentCompatibility($pdo);
            
            // Get motherboard limits for CPU validation
            $motherboardLimits = $compatibility->getMotherboardLimits($motherboard['UUID']);
            
            if ($motherboardLimits['found']) {
                // Validate CPU socket compatibility
                $socketResult = $compatibility->validateCPUSocketCompatibility($cpu['UUID'], $motherboardLimits['limits']);
                
                if ($socketResult['compatible']) {
                    return [
                        'compatible' => true,
                        'compatibility_score' => 95.0,
                        'reason' => 'Socket compatibility confirmed: ' . ($socketResult['details']['socket_match'] ?? 'Compatible socket types'),
                        'details' => $socketResult['details'] ?? []
                    ];
                } else {
                    return [
                        'compatible' => false,
                        'compatibility_score' => 0.0,
                        'reason' => 'Socket mismatch: ' . ($socketResult['error'] ?? 'CPU and motherboard socket types incompatible'),
                        'details' => $socketResult['details'] ?? []
                    ];
                }
            }
        }
        
        // Fallback: Basic CPU compatibility
        return [
            'compatible' => true,
            'compatibility_score' => 80.0,
            'reason' => 'Basic CPU compatibility check - enhanced socket validation not available',
            'details' => []
        ];
        
    } catch (Exception $e) {
        error_log("CPU compatibility check error: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 70.0,
            'reason' => 'CPU compatibility check failed - defaulting to compatible',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Check RAM compatibility with motherboard - DDR type and speed compatibility
 */
function checkRAMCompatibility($pdo, $ram, $motherboard, $motherboardSpecs) {
    try {
        $score = 90.0;
        $reasons = [];
        
        // Check DDR type compatibility (DDR4/DDR5)
        $ramNotes = strtoupper($ram['Notes'] ?? '');
        $motherboardNotes = strtoupper($motherboard['Notes'] ?? '');
        
        // Extract DDR type from notes
        $ramDDRType = 'DDR4'; // Default
        if (preg_match('/DDR(\d)/', $ramNotes, $matches)) {
            $ramDDRType = 'DDR' . $matches[1];
        }
        
        $motherboardDDRType = 'DDR4'; // Default
        if (preg_match('/DDR(\d)/', $motherboardNotes, $matches)) {
            $motherboardDDRType = 'DDR' . $matches[1];
        }
        
        if ($ramDDRType !== $motherboardDDRType) {
            return [
                'compatible' => false,
                'compatibility_score' => 0.0,
                'reason' => "Memory type mismatch: RAM is $ramDDRType but motherboard supports $motherboardDDRType",
                'details' => ['ram_type' => $ramDDRType, 'motherboard_type' => $motherboardDDRType]
            ];
        }
        
        $reasons[] = "DDR type compatible ($ramDDRType)";
        
        // Check memory speed support (basic check)
        if (preg_match('/(\d{4,5})/', $ramNotes, $ramSpeed) && preg_match('/(\d{4,5})/', $motherboardNotes, $mbSpeed)) {
            if ((int)$ramSpeed[1] > (int)$mbSpeed[1]) {
                $score -= 10.0;
                $reasons[] = "RAM speed higher than motherboard specification - will run at reduced speed";
            }
        }
        
        // Consider ECC support if specified
        if (stripos($ramNotes, 'ECC') !== false && stripos($motherboardNotes, 'ECC') === false) {
            $score -= 15.0;
            $reasons[] = "ECC RAM on non-ECC motherboard - ECC features disabled";
        }
        
        return [
            'compatible' => true,
            'compatibility_score' => $score,
            'reason' => implode(', ', $reasons),
            'details' => ['ram_type' => $ramDDRType, 'motherboard_type' => $motherboardDDRType]
        ];
        
    } catch (Exception $e) {
        error_log("RAM compatibility check error: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 80.0,
            'reason' => 'RAM compatibility check completed with assumptions',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Check storage compatibility with motherboard - Interface compatibility
 */
/**
 * DEPRECATED: Storage compatibility check
 * Now handled by StorageConnectionValidator in FlexibleCompatibilityValidator
 */
function checkStorageCompatibility($pdo, $storage, $motherboard, $motherboardSpecs) {
    // DEPRECATED: Return default compatible for backward compatibility
    return [
        'compatible' => true,
        'compatibility_score' => 85.0,
        'reason' => 'Storage validation handled by FlexibleCompatibilityValidator',
        'deprecated' => true
    ];
}

/**
 * Check NIC compatibility with motherboard - PCIe slot compatibility
 */
function checkNICCompatibility($pdo, $nic, $motherboard, $motherboardSpecs) {
    try {
        $score = 85.0;
        $reasons = [];
        
        $nicNotes = strtoupper($nic['Notes'] ?? '');
        $motherboardNotes = strtoupper($motherboard['Notes'] ?? '');
        
        // Check PCIe slot compatibility
        $nicSlotType = 'PCIe'; // Default assumption
        if (preg_match('/PCIE?\s*X?(\d+)/i', $nicNotes, $matches)) {
            $nicSlotType = 'PCIe x' . $matches[1];
        }
        
        $reasons[] = "PCIe slot compatibility assumed ($nicSlotType)";
        
        // Consider power requirements (basic check)
        if (stripos($nicNotes, 'LOW POWER') !== false || stripos($nicNotes, 'LOW-PROFILE') !== false) {
            $reasons[] = "Low power/profile design";
        }
        
        return [
            'compatible' => true,
            'compatibility_score' => $score,
            'reason' => implode(', ', $reasons),
            'details' => ['slot_type' => $nicSlotType]
        ];
        
    } catch (Exception $e) {
        error_log("NIC compatibility check error: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 80.0,
            'reason' => 'NIC compatibility check completed with assumptions',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Check caddy compatibility with motherboard - Form factor compatibility
 */
function checkCaddyCompatibility($pdo, $caddy, $motherboard, $motherboardSpecs) {
    try {
        $score = 90.0;
        $reasons = [];
        
        $caddyNotes = strtoupper($caddy['Notes'] ?? '');
        
        // Check form factor support
        if (stripos($caddyNotes, '2.5') !== false) {
            $reasons[] = "2.5\" form factor support";
        }
        if (stripos($caddyNotes, '3.5') !== false) {
            $reasons[] = "3.5\" form factor support";
        }
        
        // Consider drive interface
        $driveInterface = 'SATA'; // Default
        if (stripos($caddyNotes, 'SAS') !== false) {
            $driveInterface = 'SAS';
        } elseif (stripos($caddyNotes, 'NVME') !== false || stripos($caddyNotes, 'U.2') !== false) {
            $driveInterface = 'U.2/NVMe';
        }
        
        $reasons[] = "Drive interface: $driveInterface";
        
        return [
            'compatible' => true,
            'compatibility_score' => $score,
            'reason' => implode(', ', $reasons),
            'details' => ['drive_interface' => $driveInterface]
        ];
        
    } catch (Exception $e) {
        error_log("Caddy compatibility check error: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 80.0,
            'reason' => 'Caddy compatibility check completed with assumptions',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Check user permissions (fallback function if not exists)
 */
if (!function_exists('hasPermission')) {
    function hasPermission($pdo, $permission, $userId) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM role_permissions rp
                JOIN user_roles ur ON rp.role_id = ur.role_id
                JOIN permissions p ON rp.permission_id = p.id
                WHERE ur.user_id = ? AND p.name = ?
            ");
            $stmt->execute([$userId, $permission]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            error_log("Error checking permission: " . $e->getMessage());
            return false;
        }
    }
}



/**
 * Validate component exists and get details
 */
function validateComponentExists($componentType, $componentUuid) {
    global $pdo;

    $tableName = getComponentTableName($componentType);
    if (!$tableName) {
        return [
            'exists' => false,
            'message' => 'Invalid component type',
            'component_type' => $componentType
        ];
    }

    // CRITICAL FIX: When multiple components share same UUID, prioritize Status=1 (available)
    // Query for Status=1 first, then fall back to any status if none available

    // Step 1: Try to get an available component (Status=1)
    $stmt = $pdo->prepare("SELECT Status, UUID, SerialNumber, Notes, ServerUUID, ID FROM $tableName WHERE UUID = ? AND Status = 1 LIMIT 1");
    $stmt->execute([$componentUuid]);
    $component = $stmt->fetch(PDO::FETCH_ASSOC);

    // Step 2: If no available component, get any component with this UUID for validation
    if (!$component) {
        $stmt = $pdo->prepare("SELECT Status, UUID, SerialNumber, Notes, ServerUUID, ID FROM $tableName WHERE UUID = ? LIMIT 1");
        $stmt->execute([$componentUuid]);
        $component = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$component) {
        return [
            'exists' => false,
            'message' => 'Component not found in inventory',
            'component_type' => $componentType,
            'component_uuid' => $componentUuid
        ];
    }

    // Step 3: Count available components with this UUID
    $stmt = $pdo->prepare("SELECT COUNT(*) as available_count FROM $tableName WHERE UUID = ? AND Status = 1");
    $stmt->execute([$componentUuid]);
    $availabilityCheck = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasAvailableComponent = $availabilityCheck['available_count'] > 0;

    return [
        'exists' => true,
        'component' => $component,
        'available' => $hasAvailableComponent,
        'available_count' => $availabilityCheck['available_count']
    ];
}

/**
 * Check RAM compatibility with existing components in server configuration
 * Implements decentralized compatibility checking without motherboard dependency
 */
function checkRAMCompatibilityWithExistingComponents($ramComponent, $existingComponents, $pdo) {
    try {
        $compatibilityScore = 1.0;
        $compatibilityReasons = [];
        $isCompatible = true;

        // If no existing components, RAM is always compatible
        if (empty($existingComponents)) {
            return [
                'compatible' => true,
                'score' => 1.0,
                'reason' => 'No existing components - all RAM compatible'
            ];
        }

        // Get RAM specifications from JSON
        $ramSpecs = loadComponentSpecsFromJSON($ramComponent['UUID'], 'ram');
        if (!$ramSpecs) {
            // Fallback to Notes-based compatibility
            return checkRAMCompatibilityWithNotesOnly($ramComponent, $existingComponents);
        }

        $ramMemoryType = $ramSpecs['memory_type'] ?? null; // DDR4, DDR5, etc.
        $ramFormFactor = $ramSpecs['form_factor'] ?? null; // DIMM, SO-DIMM, etc.
        $ramFrequency = $ramSpecs['frequency_MHz'] ?? null;

        // Check compatibility with each existing component
        foreach ($existingComponents as $existingComp) {
            $existingType = $existingComp['type'];

            if ($existingType === 'cpu') {
                $cpuCompatResult = checkRAMCPUCompatibility($ramSpecs, $existingComp, $pdo);
                if (!$cpuCompatResult['compatible']) {
                    $isCompatible = false;
                    $compatibilityReasons[] = $cpuCompatResult['reason'];
                } else {
                    $compatibilityScore = min($compatibilityScore, $cpuCompatResult['score']);
                    $compatibilityReasons[] = $cpuCompatResult['reason'];
                }
            } elseif ($existingType === 'motherboard') {
                $mbCompatResult = checkRAMMotherboardCompatibility($ramSpecs, $existingComp, $pdo);
                if (!$mbCompatResult['compatible']) {
                    $isCompatible = false;
                    $compatibilityReasons[] = $mbCompatResult['reason'];
                } else {
                    $compatibilityScore = min($compatibilityScore, $mbCompatResult['score']);
                    $compatibilityReasons[] = $mbCompatResult['reason'];
                }
            } elseif ($existingType === 'ram') {
                // Check RAM-to-RAM compatibility (form factor consistency)
                $existingRAMSpecs = loadComponentSpecsFromJSON($existingComp['uuid'], 'ram');
                if ($existingRAMSpecs) {
                    if ($ramFormFactor && $existingRAMSpecs['form_factor'] &&
                        $ramFormFactor !== $existingRAMSpecs['form_factor']) {
                        $isCompatible = false;
                        $compatibilityReasons[] = "Form factor mismatch: {$ramFormFactor} vs {$existingRAMSpecs['form_factor']}";
                    } else {
                        $compatibilityReasons[] = "Form factor compatible with existing RAM";
                    }
                }
            }
        }

        return [
            'compatible' => $isCompatible,
            'score' => $compatibilityScore,
            'reason' => implode('; ', $compatibilityReasons)
        ];

    } catch (Exception $e) {
        error_log("RAM compatibility check error: " . $e->getMessage());
        return [
            'compatible' => true,
            'score' => 0.7,
            'reason' => 'Compatibility check failed - defaulting to compatible'
        ];
    }
}

/**
 * Load component specifications from JSON files
 */
function loadComponentSpecsFromJSON($componentUUID, $componentType) {
    try {
        $jsonDir = __DIR__ . '/../../All-JSON';

        $jsonFiles = [
            'cpu' => $jsonDir . '/cpu-jsons/Cpu-details-level-3.json',
            'ram' => $jsonDir . '/Ram-jsons/ram_detail.json',
            'motherboard' => $jsonDir . '/motherboard-jsons/motherboard-level-3.json'
        ];

        if (!isset($jsonFiles[$componentType])) {
            return null;
        }

        $jsonFile = $jsonFiles[$componentType];
        if (!file_exists($jsonFile)) {
            error_log("JSON file not found: $jsonFile");
            return null;
        }

        $jsonData = json_decode(file_get_contents($jsonFile), true);
        if (!$jsonData) {
            error_log("Failed to parse JSON file: $jsonFile");
            return null;
        }

        // Search for component by UUID in the JSON structure
        foreach ($jsonData as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    if (isset($model['uuid']) && $model['uuid'] === $componentUUID) {
                        return $model;
                    }
                }
            }
        }

        return null;

    } catch (Exception $e) {
        error_log("Error loading component specs from JSON: " . $e->getMessage());
        return null;
    }
}

/**
 * Check RAM-CPU compatibility based on memory type and form factor
 */
function checkRAMCPUCompatibility($ramSpecs, $cpuComponent, $pdo) {
    $cpuSpecs = loadComponentSpecsFromJSON($cpuComponent['uuid'], 'cpu');

    if (!$cpuSpecs) {
        return [
            'compatible' => true,
            'score' => 0.8,
            'reason' => 'CPU specs not found - basic compatibility assumed'
        ];
    }

    $cpuMemoryTypes = $cpuSpecs['memory_types'] ?? [];
    $ramMemoryType = $ramSpecs['memory_type'] ?? null;

    // Check if RAM memory type is supported by CPU
    $typeCompatible = false;
    foreach ($cpuMemoryTypes as $supportedType) {
        if (strpos($supportedType, $ramMemoryType) !== false) {
            $typeCompatible = true;
            break;
        }
    }

    if (!$typeCompatible) {
        return [
            'compatible' => false,
            'score' => 0.0,
            'reason' => "Memory type mismatch: RAM {$ramMemoryType} not supported by CPU (" . implode(', ', $cpuMemoryTypes) . ")"
        ];
    }

    return [
        'compatible' => true,
        'score' => 1.0,
        'reason' => "Memory type {$ramMemoryType} compatible with CPU"
    ];
}

/**
 * Check RAM-Motherboard compatibility based on memory type and frequency
 */
function checkRAMMotherboardCompatibility($ramSpecs, $motherboardComponent, $pdo) {
    $mbSpecs = loadComponentSpecsFromJSON($motherboardComponent['uuid'], 'motherboard');

    if (!$mbSpecs) {
        return [
            'compatible' => true,
            'score' => 0.8,
            'reason' => 'Motherboard specs not found - basic compatibility assumed'
        ];
    }

    $mbMemoryType = $mbSpecs['memory']['type'] ?? null;
    $mbMaxFrequency = $mbSpecs['memory']['max_frequency_MHz'] ?? null;
    $ramMemoryType = $ramSpecs['memory_type'] ?? null;
    $ramFrequency = $ramSpecs['frequency_MHz'] ?? null;

    // Check memory type compatibility
    if ($mbMemoryType && $ramMemoryType && $mbMemoryType !== $ramMemoryType) {
        return [
            'compatible' => false,
            'score' => 0.0,
            'reason' => "Memory type mismatch: RAM {$ramMemoryType} vs Motherboard {$mbMemoryType}"
        ];
    }

    // Check frequency compatibility
    $score = 1.0;
    $reasons = [];

    if ($mbMemoryType) {
        $reasons[] = "Memory type {$mbMemoryType} compatible";
    }

    if ($mbMaxFrequency && $ramFrequency) {
        if ($ramFrequency > $mbMaxFrequency) {
            $score = 0.9; // Compatible but with frequency limitation
            $reasons[] = "RAM frequency {$ramFrequency}MHz will be limited to {$mbMaxFrequency}MHz by motherboard";
        } else {
            $reasons[] = "RAM frequency {$ramFrequency}MHz within motherboard limits";
        }
    }

    return [
        'compatible' => true,
        'score' => $score,
        'reason' => implode('; ', $reasons)
    ];
}

/**
 * Fallback RAM compatibility using Notes only (when JSON specs not available)
 */
function checkRAMCompatibilityWithNotesOnly($ramComponent, $existingComponents) {
    $ramNotes = strtoupper($ramComponent['Notes'] ?? '');
    $compatibilityReasons = [];
    $isCompatible = true;

    // Extract RAM type from notes
    $ramType = 'DDR4'; // Default
    if (preg_match('/DDR(\d)/', $ramNotes, $matches)) {
        $ramType = 'DDR' . $matches[1];
    }

    foreach ($existingComponents as $existingComp) {
        $existingNotes = strtoupper($existingComp['data']['Notes'] ?? '');

        if ($existingComp['type'] === 'cpu' || $existingComp['type'] === 'motherboard') {
            // Check if existing component notes mention RAM type
            $existingRAMType = null;
            if (preg_match('/DDR(\d)/', $existingNotes, $matches)) {
                $existingRAMType = 'DDR' . $matches[1];
            }

            if ($existingRAMType && $existingRAMType !== $ramType) {
                $isCompatible = false;
                $compatibilityReasons[] = "Memory type mismatch: RAM {$ramType} vs {$existingComp['type']} {$existingRAMType}";
            } else if ($existingRAMType) {
                $compatibilityReasons[] = "Memory type {$ramType} compatible with {$existingComp['type']}";
            }
        }
    }

    if (empty($compatibilityReasons)) {
        $compatibilityReasons[] = "Basic compatibility check passed using component notes";
    }

    return [
        'compatible' => $isCompatible,
        'score' => $isCompatible ? 0.8 : 0.0,
        'reason' => implode('; ', $compatibilityReasons)
    ];
}

/**
 * Analyze common compatibility issues from incompatible components
 */
function analyzeCommonCompatibilityIssues($incompatibleComponents) {
    $issues = [];
    $memoryTypeIssues = 0;
    $formFactorIssues = 0;

    foreach ($incompatibleComponents as $comp) {
        $reason = $comp['compatibility_reason'];
        if (strpos($reason, 'DDR') !== false) {
            $memoryTypeIssues++;
        }
        if (strpos($reason, 'form factor') !== false) {
            $formFactorIssues++;
        }
    }

    if ($memoryTypeIssues > 0) {
        $issues[] = "Memory type mismatch ({$memoryTypeIssues} components)";
    }
    if ($formFactorIssues > 0) {
        $issues[] = "Form factor incompatibility ({$formFactorIssues} components)";
    }

    return $issues;
}

/**
 * Generate compatibility suggestions based on existing components
 */
function generateCompatibilitySuggestions($componentType, $existingComponents) {
    $suggestions = [];

    if ($componentType === 'ram') {
        // Analyze existing components to suggest compatible RAM
        $cpuTypes = [];
        $mbTypes = [];

        foreach ($existingComponents as $comp) {
            if ($comp['type'] === 'cpu') {
                $notes = strtoupper($comp['data']['Notes'] ?? '');
                if (strpos($notes, 'DDR5') !== false) {
                    $cpuTypes[] = 'DDR5';
                } elseif (strpos($notes, 'DDR4') !== false) {
                    $cpuTypes[] = 'DDR4';
                }
            } elseif ($comp['type'] === 'motherboard') {
                $notes = strtoupper($comp['data']['Notes'] ?? '');
                if (strpos($notes, 'DDR5') !== false) {
                    $mbTypes[] = 'DDR5';
                } elseif (strpos($notes, 'DDR4') !== false) {
                    $mbTypes[] = 'DDR4';
                }
            }
        }

        $commonTypes = array_intersect($cpuTypes, $mbTypes);
        if (!empty($commonTypes)) {
            $suggestions[] = "Use " . implode(' or ', array_unique($commonTypes)) . " RAM modules";
            $suggestions[] = "Ensure RAM form factor matches motherboard (DIMM/SO-DIMM)";
        } else {
            $suggestions[] = "Check CPU and motherboard memory type compatibility";
        }
    }

    return $suggestions;
}

/**
 * Extract PCIe slot size from component specifications
 *
 * @param array $specs Component specifications from JSON
 * @return string|null Slot size (x1, x4, x8, x16) or null
 */
function extractPCIeSlotSizeFromSpecs($specs) {
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
 * Extract slot type from slot ID
 *
 * @param string $slotId Slot identifier (e.g., "pcie_x16_slot_1")
 * @return string|null Slot type (e.g., "x16") or null
 */
function extractSlotTypeFromSlotId($slotId) {
    if (preg_match('/pcie_(x\d+)_slot_/', $slotId, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Extract all components from server configuration JSON columns
 * Replaces the old server_configuration_components table queries
 *
 * @param array $configData Configuration data from server_configurations table
 * @return array Array of components with structure: [['component_type' => 'cpu', 'component_uuid' => '...', 'quantity' => 1], ...]
 */
function extractComponentsFromConfigData($configData) {
    $components = [];

    // CPU configuration (JSON)
    if (!empty($configData['cpu_configuration'])) {
        try {
            $cpuConfig = json_decode($configData['cpu_configuration'], true);
            if (isset($cpuConfig['cpus']) && is_array($cpuConfig['cpus'])) {
                foreach ($cpuConfig['cpus'] as $cpu) {
                    if (!empty($cpu['uuid'])) {
                        $components[] = [
                            'component_type' => 'cpu',
                            'component_uuid' => $cpu['uuid']
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error parsing cpu_configuration JSON: " . $e->getMessage());
        }
    }

    // RAM configuration (JSON array)
    if (!empty($configData['ram_configuration'])) {
        try {
            $ramConfigs = json_decode($configData['ram_configuration'], true);
            if (is_array($ramConfigs)) {
                foreach ($ramConfigs as $ram) {
                    if (!empty($ram['uuid'])) {
                        $components[] = [
                            'component_type' => 'ram',
                            'component_uuid' => $ram['uuid']
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error parsing ram_configuration JSON: " . $e->getMessage());
        }
    }

    // Storage configuration (JSON array)
    if (!empty($configData['storage_configuration'])) {
        try {
            $storageConfigs = json_decode($configData['storage_configuration'], true);
            if (is_array($storageConfigs)) {
                foreach ($storageConfigs as $storage) {
                    if (!empty($storage['uuid'])) {
                        $components[] = [
                            'component_type' => 'storage',
                            'component_uuid' => $storage['uuid']
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error parsing storage_configuration JSON: " . $e->getMessage());
        }
    }

    // Caddy configuration (JSON array)
    if (!empty($configData['caddy_configuration'])) {
        try {
            $caddyConfigs = json_decode($configData['caddy_configuration'], true);
            if (is_array($caddyConfigs)) {
                foreach ($caddyConfigs as $caddy) {
                    if (!empty($caddy['uuid'])) {
                        $components[] = [
                            'component_type' => 'caddy',
                            'component_uuid' => $caddy['uuid']
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error parsing caddy_configuration JSON: " . $e->getMessage());
        }
    }

    // NIC configuration (JSON object with nics array)
    if (!empty($configData['nic_config'])) {
        try {
            $nicConfig = json_decode($configData['nic_config'], true);
            if (isset($nicConfig['nics']) && is_array($nicConfig['nics'])) {
                foreach ($nicConfig['nics'] as $nic) {
                    if (!empty($nic['uuid'])) {
                        $components[] = [
                            'component_type' => 'nic',
                            'component_uuid' => $nic['uuid']
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error parsing nic_config JSON: " . $e->getMessage());
        }
    }

    // HBA Card (simple column, just one UUID)
    if (!empty($configData['hbacard_uuid'])) {
        $components[] = [
            'component_type' => 'hbacard',
            'component_uuid' => $configData['hbacard_uuid']
        ];
    }

    // Motherboard (simple column)
    if (!empty($configData['motherboard_uuid'])) {
        $components[] = [
            'component_type' => 'motherboard',
            'component_uuid' => $configData['motherboard_uuid']
        ];
    }

    // Chassis (simple column)
    if (!empty($configData['chassis_uuid'])) {
        $components[] = [
            'component_type' => 'chassis',
            'component_uuid' => $configData['chassis_uuid']
        ];
    }

    // PCIe Card configuration (JSON array)
    if (!empty($configData['pciecard_configurations'])) {
        try {
            $pcieConfigs = json_decode($configData['pciecard_configurations'], true);
            if (is_array($pcieConfigs)) {
                foreach ($pcieConfigs as $pcie) {
                    if (!empty($pcie['uuid'])) {
                        $components[] = [
                            'component_type' => 'pciecard',
                            'component_uuid' => $pcie['uuid']
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error parsing pciecard_configurations JSON: " . $e->getMessage());
        }
    }

    return $components;
}


// ============================================================================
// SFP MODULE MANAGEMENT HANDLERS
// ============================================================================

/**
 * Handle adding SFP modules to server configuration
 * Supports adding SFPs with or without NIC (dynamic dependency resolution)
 *
 * Required parameters:
 * - config_uuid: Server configuration UUID
 * - sfp_uuids: Array of SFP UUIDs to add (JSON array or comma-separated)
 *
 * Optional parameters:
 * - parent_nic_uuid: NIC UUID to assign SFPs to (if not provided, SFPs are unassigned)
 * - auto_assign: Boolean - automatically assign to best available NIC (default: false)
 */
function handleAddSFP($serverBuilder, $user) {
    try {
        // Validate required parameters
        $configUuid = $_POST['config_uuid'] ?? null;
        $sfpUuidsInput = $_POST['sfp_uuids'] ?? null;
        $parentNicUuid = $_POST['parent_nic_uuid'] ?? null;
        $autoAssign = filter_var($_POST['auto_assign'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$configUuid) {
            send_json_response(false, true, 400, 'Configuration UUID required', []);
            return;
        }

        if (!$sfpUuidsInput) {
            send_json_response(false, true, 400, 'SFP UUIDs required', []);
            return;
        }

        // Parse SFP UUIDs (support JSON array or comma-separated string)
        if (is_string($sfpUuidsInput)) {
            $decoded = json_decode($sfpUuidsInput, true);
            $sfpUuids = $decoded ? $decoded : explode(',', $sfpUuidsInput);
        } else {
            $sfpUuids = $sfpUuidsInput;
        }

        $sfpUuids = array_map('trim', $sfpUuids);

        // Get PDO connection
        global $pdo;
        require_once __DIR__ . '/../../../core/models/compatibility/SFPCompatibilityResolver.php';
        $resolver = new SFPCompatibilityResolver($pdo);

        // Validate that all SFPs have uniform type and speed (Requirement #1)
        $validation = $resolver->validateUnassignedSFPs($sfpUuids);

        if (!$validation['success']) {
            send_json_response(false, true, 400, 'SFP validation failed', $validation);
            return;
        }

        // Get current server configuration
        $stmt = $pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            send_json_response(false, true, 404, 'Configuration not found', []);
            return;
        }

        // Load existing SFP configuration
        $sfpConfig = [];
        if (!empty($config['sfp_configuration'])) {
            $sfpConfig = json_decode($config['sfp_configuration'], true) ?? [];
        }

        $assignedSfps = $sfpConfig['sfps'] ?? [];
        $unassignedSfps = $sfpConfig['unassigned_sfps'] ?? [];

        // Scenario 1: No parent NIC specified - add as unassigned (Requirement #1)
        if (!$parentNicUuid && !$autoAssign) {
            foreach ($sfpUuids as $sfpUuid) {
                $unassignedSfps[] = [
                    'uuid' => $sfpUuid,
                    'added_at' => date('Y-m-d H:i:s')
                ];

                // Update SFP inventory status to in_use (status=2)
                $stmt = $pdo->prepare("UPDATE sfpinventory SET Status = 2, ServerUUID = ?, UpdatedAt = NOW() WHERE UUID = ?");
                $stmt->execute([$configUuid, $sfpUuid]);
            }

            // Save configuration
            $sfpConfig['unassigned_sfps'] = $unassignedSfps;
            $sfpConfig['sfps'] = $assignedSfps;

            $stmt = $pdo->prepare("UPDATE server_configurations SET sfp_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($sfpConfig), $configUuid]);

            send_json_response(true, true, 200, 'SFPs added as unassigned (awaiting NIC)', [
                'sfps_added' => count($sfpUuids),
                'status' => 'unassigned',
                'warning' => 'SFPs will be auto-assigned when a compatible NIC is added'
            ]);
            return;
        }

        // Scenario 2: Auto-assign to best available NIC
        if ($autoAssign) {
            // Get all NICs in configuration
            $nicConfig = json_decode($config['nic_config'] ?? '{}', true);
            $availableNICs = $nicConfig['nics'] ?? [];

            $optimalNIC = $resolver->chooseOptimalNIC($sfpUuids, $availableNICs);

            if (!$optimalNIC['success']) {
                send_json_response(false, true, 400, 'No compatible NIC found for auto-assignment', $optimalNIC);
                return;
            }

            $parentNicUuid = $optimalNIC['optimal_nic']['uuid'];
        }

        // Scenario 3: Assign to specified NIC (Requirement #3)
        $assignmentResult = $resolver->autoAssignSFPsToNIC($configUuid, $parentNicUuid, $sfpUuids);

        if (!$assignmentResult['success']) {
            send_json_response(false, true, 400, 'SFP assignment failed', $assignmentResult);
            return;
        }

        // Add assignments to configuration
        foreach ($assignmentResult['assignments'] as $assignment) {
            $assignedSfps[] = $assignment;

            // Update SFP inventory
            $stmt = $pdo->prepare("UPDATE sfpinventory SET Status = 2, ServerUUID = ?, ParentNICUUID = ?, PortIndex = ?, UpdatedAt = NOW() WHERE UUID = ?");
            $stmt->execute([$configUuid, $assignment['parent_nic_uuid'], $assignment['port_index'], $assignment['uuid']]);
        }

        // Save configuration
        $sfpConfig['sfps'] = $assignedSfps;
        $sfpConfig['unassigned_sfps'] = $unassignedSfps;

        $stmt = $pdo->prepare("UPDATE server_configurations SET sfp_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
        $stmt->execute([json_encode($sfpConfig), $configUuid]);

        send_json_response(true, true, 200, 'SFPs successfully assigned to NIC', [
            'assignments' => $assignmentResult['assignments']
        ]);

    } catch (Exception $e) {
        error_log("Error in handleAddSFP: " . $e->getMessage());
        send_json_response(false, true, 500, 'Server error: ' . $e->getMessage(), []);
    }
}

/**
 * Handle assigning unassigned SFPs to a NIC
 * Triggered when NIC is added after SFPs (Requirement #2)
 *
 * Required parameters:
 * - config_uuid: Server configuration UUID
 * - nic_uuid: NIC UUID to assign SFPs to
 * - sfp_uuids: Array of SFP UUIDs to assign (optional - if not provided, assigns all unassigned)
 */
function handleAssignSFPToNIC($serverBuilder, $user) {
    try {
        $configUuid = $_POST['config_uuid'] ?? null;
        $nicUuid = $_POST['nic_uuid'] ?? null;
        $sfpUuidsInput = $_POST['sfp_uuids'] ?? null;

        if (!$configUuid || !$nicUuid) {
            send_json_response(false, true, 400, 'Configuration UUID and NIC UUID required', []);
            return;
        }

        global $pdo;
        require_once __DIR__ . '/../../../core/models/compatibility/SFPCompatibilityResolver.php';
        $resolver = new SFPCompatibilityResolver($pdo);

        // Get configuration
        $stmt = $pdo->prepare("SELECT sfp_configuration FROM server_configurations WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            send_json_response(false, true, 404, 'Configuration not found', []);
            return;
        }

        $sfpConfig = json_decode($config['sfp_configuration'] ?? '{}', true);
        $unassignedSfps = $sfpConfig['unassigned_sfps'] ?? [];

        // Get SFP UUIDs to assign
        $sfpUuids = [];
        if ($sfpUuidsInput) {
            if (is_string($sfpUuidsInput)) {
                $decoded = json_decode($sfpUuidsInput, true);
                $sfpUuids = $decoded ? $decoded : explode(',', $sfpUuidsInput);
            } else {
                $sfpUuids = $sfpUuidsInput;
            }
        } else {
            // Assign all unassigned SFPs
            foreach ($unassignedSfps as $sfp) {
                $sfpUuids[] = $sfp['uuid'];
            }
        }

        if (empty($sfpUuids)) {
            send_json_response(false, true, 400, 'No unassigned SFPs found', []);
            return;
        }

        // Auto-assign to NIC
        $assignmentResult = $resolver->autoAssignSFPsToNIC($configUuid, $nicUuid, $sfpUuids);

        if (!$assignmentResult['success']) {
            send_json_response(false, true, 400, 'Assignment failed', $assignmentResult);
            return;
        }

        // Update configuration
        $assignedSfps = $sfpConfig['sfps'] ?? [];
        foreach ($assignmentResult['assignments'] as $assignment) {
            $assignedSfps[] = $assignment;

            // Remove from unassigned
            $unassignedSfps = array_filter($unassignedSfps, function($sfp) use ($assignment) {
                return $sfp['uuid'] !== $assignment['uuid'];
            });

            // Update inventory
            $stmt = $pdo->prepare("UPDATE sfpinventory SET ParentNICUUID = ?, PortIndex = ?, UpdatedAt = NOW() WHERE UUID = ?");
            $stmt->execute([$assignment['parent_nic_uuid'], $assignment['port_index'], $assignment['uuid']]);
        }

        $sfpConfig['sfps'] = $assignedSfps;
        $sfpConfig['unassigned_sfps'] = array_values($unassignedSfps);

        $stmt = $pdo->prepare("UPDATE server_configurations SET sfp_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
        $stmt->execute([json_encode($sfpConfig), $configUuid]);

        send_json_response(true, true, 200, 'SFPs successfully assigned', [
            'assignments' => $assignmentResult['assignments']
        ]);

    } catch (Exception $e) {
        error_log("Error in handleAssignSFPToNIC: " . $e->getMessage());
        send_json_response(false, true, 500, 'Server error: ' . $e->getMessage(), []);
    }
}

/**
 * Handle removing SFP modules from configuration
 *
 * Required parameters:
 * - config_uuid: Server configuration UUID
 * - sfp_uuid: SFP UUID to remove
 * - port_index: Port index (optional, for specific assignment)
 */
function handleRemoveSFP($serverBuilder, $user) {
    try {
        $configUuid = $_POST['config_uuid'] ?? null;
        $sfpUuid = $_POST['sfp_uuid'] ?? null;
        $portIndex = $_POST['port_index'] ?? null;

        if (!$configUuid || !$sfpUuid) {
            send_json_response(false, true, 400, 'Configuration UUID and SFP UUID required', []);
            return;
        }

        global $pdo;

        // Get configuration
        $stmt = $pdo->prepare("SELECT sfp_configuration FROM server_configurations WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            send_json_response(false, true, 404, 'Configuration not found', []);
            return;
        }

        $sfpConfig = json_decode($config['sfp_configuration'] ?? '{}', true);
        $assignedSfps = $sfpConfig['sfps'] ?? [];
        $unassignedSfps = $sfpConfig['unassigned_sfps'] ?? [];

        // Remove from assigned SFPs
        $removed = false;
        $assignedSfps = array_filter($assignedSfps, function($sfp) use ($sfpUuid, $portIndex, &$removed) {
            $match = $sfp['uuid'] === $sfpUuid;
            if ($portIndex !== null) {
                $match = $match && ($sfp['port_index'] ?? null) == $portIndex;
            }
            if ($match) $removed = true;
            return !$match;
        });

        // Remove from unassigned SFPs
        $unassignedSfps = array_filter($unassignedSfps, function($sfp) use ($sfpUuid, &$removed) {
            $match = $sfp['uuid'] === $sfpUuid;
            if ($match) $removed = true;
            return !$match;
        });

        if (!$removed) {
            send_json_response(false, true, 404, 'SFP not found in configuration', []);
            return;
        }

        // Update configuration
        $sfpConfig['sfps'] = array_values($assignedSfps);
        $sfpConfig['unassigned_sfps'] = array_values($unassignedSfps);

        $stmt = $pdo->prepare("UPDATE server_configurations SET sfp_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
        $stmt->execute([json_encode($sfpConfig), $configUuid]);

        // Update SFP inventory - set to available
        $stmt = $pdo->prepare("UPDATE sfpinventory SET Status = 1, ServerUUID = NULL, ParentNICUUID = NULL, PortIndex = NULL, UpdatedAt = NOW() WHERE UUID = ?");
        $stmt->execute([$sfpUuid]);

        send_json_response(true, true, 200, 'SFP successfully removed', [
            'removed_uuid' => $sfpUuid
        ]);

    } catch (Exception $e) {
        error_log("Error in handleRemoveSFP: " . $e->getMessage());
        send_json_response(false, true, 500, 'Server error: ' . $e->getMessage(), []);
    }
}

/**
 * Get compatible SFPs for an existing NIC in configuration
 * Requirement #3: Filter SFPs when NIC already exists
 *
 * Required parameters:
 * - nic_uuid: NIC UUID to check compatibility
 */
function handleGetCompatibleSFPs($serverBuilder, $user) {
    try {
        $nicUuid = $_GET['nic_uuid'] ?? $_POST['nic_uuid'] ?? null;

        if (!$nicUuid) {
            send_json_response(false, true, 400, 'NIC UUID required', []);
            return;
        }

        global $pdo;
        require_once __DIR__ . '/../../../core/models/compatibility/SFPCompatibilityResolver.php';
        $resolver = new SFPCompatibilityResolver($pdo);

        $result = $resolver->getCompatibleSFPsForNIC($nicUuid);

        if (isset($result['error'])) {
            send_json_response(false, true, 404, $result['error'], $result);
            return;
        }

        send_json_response(true, true, 200, 'Compatible SFPs retrieved', $result);

    } catch (Exception $e) {
        error_log("Error in handleGetCompatibleSFPs: " . $e->getMessage());
        send_json_response(false, true, 500, 'Server error: ' . $e->getMessage(), []);
    }
}

?>
