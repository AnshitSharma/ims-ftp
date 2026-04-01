<?php
require_once __DIR__ . '/../../../core/config/app.php';
require_once __DIR__ . '/../../../core/helpers/BaseFunctions.php';
require_once __DIR__ . '/../../../core/models/components/ComponentSpecPaths.php';
require_once __DIR__ . '/../../../core/models/server/ServerBuilder.php';
require_once __DIR__ . '/../../../core/models/server/ServerConfiguration.php';
require_once __DIR__ . '/../../../core/models/compatibility/UnifiedSlotTracker.php';


header('Content-Type: application/json');

// Use authentication already performed by api.php
global $pdo, $user;
if (!$pdo) {
    require_once __DIR__ . '/../../../core/config/app.php';
}

// Initialize ServerBuilder with enhanced error handling
try {
    if (!$pdo) {
        throw new Exception("Database connection not available");
    }
    $serverBuilder = new ServerBuilder($pdo);
} catch (Exception $e) {
    error_log("Failed to initialize ServerBuilder: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    send_json_response(0, 1, 500, "Server system unavailable");
}

// Shared component type to table name mapping
$GLOBALS['_serverComponentTableMap'] = [
    'cpu' => 'cpuinventory',
    'motherboard' => 'motherboardinventory',
    'ram' => 'raminventory',
    'storage' => 'storageinventory',
    'nic' => 'nicinventory',
    'caddy' => 'caddyinventory',
    'chassis' => 'chassisinventory',
    'pciecard' => 'pciecardinventory',
    'hbacard' => 'hbacardinventory',
    'sfp' => 'sfpinventory'
];

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

    // Update configuration endpoint
    case 'update-config':
    case 'server-update-config':
        handleUpdateConfiguration($serverBuilder, $user);
        break;

    case 'search-by-serial':
    case 'server-search-by-serial':
        handleSearchBySerial($serverBuilder, $user);
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
        if ((int)$config->get('created_by') !== (int)$user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
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
        send_json_response(0, 1, 500, "Failed to update configuration");
    }
}

/**
 * Start server creation process
 */
function handleCreateStart($serverBuilder, $user) {
    global $pdo;

    $serverName = trim($_POST['server_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $rackPosition = trim($_POST['rack_position'] ?? '');
    $isVirtual = filter_var($_POST['is_virtual'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

    if (empty($serverName)) {
        send_json_response(0, 1, 400, "Server name is required");
    }

    try {
        // Create configuration
        $configUuid = $serverBuilder->createConfiguration($serverName, $user['id'], [
            'description' => $description,
            'location' => $location,
            'rack_position' => $rackPosition,
            'is_virtual' => $isVirtual,
        ]);

        // Log server creation start
        $logResult = logActivity($pdo, $user['id'], 'Server configuration started', 'server', null,
            "Started server config creation: $serverName");
        send_json_response(1, 1, 200, "Server configuration created successfully", [
            'config_uuid' => $configUuid,
            'server_name' => $serverName,
            'description' => $description,
            'location' => $location,
            'rack_position' => $rackPosition,
            'is_virtual' => $isVirtual,
        ]);
        
    } catch (Exception $e) {
        error_log("Error in server creation start: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to initialize server creation");
    }
}


/**
 * FIXED: Add component to server configuration with proper ServerUUID handling
 */
function handleAddComponent($serverBuilder, $user) {
    global $pdo;

    try {
        $configUuid = $_POST['config_uuid'] ?? '';
        $componentType = $_POST['component_type'] ?? '';
        $componentUuid = $_POST['component_uuid'] ?? '';
        $serialNumber = $_POST['serial_number'] ?? null; // CRITICAL: Accept serial_number to identify specific physical component
        $quantity = (int)($_POST['quantity'] ?? 1);
        $slotPosition = $_POST['slot_position'] ?? null;
        $notes = $_POST['notes'] ?? '';
        $override = filter_var($_POST['override'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (empty($configUuid) || empty($componentType) || empty($componentUuid)) {
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
        // Accept both port_index and slot_position (alias for backward compatibility)
        $portIndex = isset($_POST['port_index']) ? (int)$_POST['port_index'] : (isset($_POST['slot_position']) ? (int)$_POST['slot_position'] : null);

        if (empty($parentNicUuid)) {
            send_json_response(0, 1, 400, "parent_nic_uuid is required for SFP modules", [
                'hint' => 'Specify which NIC card this SFP should be installed in'
            ]);
        }

        if (empty($portIndex) || $portIndex < 1) {
            // Auto-assign port index: find first available port on this NIC
            $portIndex = $serverBuilder->autoAssignSFPPort($parentNicUuid, $configUuid);
            if (!$portIndex) {
                send_json_response(0, 1, 400, "port_index is required for SFP modules and must be >= 1", [
                    'hint' => 'Specify which port number on the NIC (1, 2, 3, etc.) - all ports may be occupied'
                ]);
            }
        }
    }

    try {
        // Load the configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check if user owns this configuration or has edit permissions
        if ((int)$config->get('created_by') !== (int)$user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
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
            
        // Phase 2 Consolidation: Unified component validation in ServerBuilder
        try {
            require_once __DIR__ . '/../../../core/models/compatibility/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($pdo);

            // Load configuration data
            $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
            if (!$config) {
                throw new Exception("Configuration not found");
            }

            // Single consolidated validation call (moved to ServerBuilder::validateComponentAddition)
            $validationResult = $serverBuilder->validateComponentAddition(
                $configUuid,
                $componentType,
                $componentUuid,
                $compatibility,
                $config->getData(),
                $parentNicUuid,
                $portIndex
            );

            if (!$validationResult['success']) {
                // Format error response based on validation result
                $errorResponse = [
                    'component_type' => $componentType,
                    'component_uuid' => $componentUuid,
                    'validation_status' => 'blocked',
                    'error' => $validationResult['message']
                ];

                // Include additional details if available
                if (!empty($validationResult['details'])) {
                    $errorResponse['details'] = $validationResult['details'];
                }
                if (!empty($validationResult['recommendations'])) {
                    $errorResponse['recommendations'] = $validationResult['recommendations'];
                }

                send_json_response(0, 1, 400, $validationResult['message'], $errorResponse);
            }

            // Store warnings for response (will be added if successful)
            $validationWarnings = $validationResult['warnings'] ?? [];
            $validationInfo = [];

        } catch (Exception $validationError) {
            error_log("Validation error: " . $validationError->getMessage());
            error_log("Stack trace: " . $validationError->getTraceAsString());
            // Continue with addition - don't block on validation errors
            $validationWarnings = [];
            $validationInfo = ["Validation service unavailable - component added without full compatibility checks"];
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
                        } elseif (stripos($componentUuid, 'riser-') === 0) {
                            // Fallback: UUID starts with "riser-"
                            $isRiserCard = true;
                        }

                        if ($isRiserCard) {
                            // ===== RISER CARD - MUST GO TO RISER SLOTS ONLY =====
                            $riserSlotSize = extractPCIeSlotSizeFromSpecs($cardSpecs);

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

                                } else {
                                    // CRITICAL: No compatible riser slots available - BLOCK the addition
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
                                $assignedSlot = $slotTracker->assignRiserSlot($configUuid);
                                if ($assignedSlot) {
                                    $slotPosition = $assignedSlot;
                                } else {
                                    // CRITICAL: No riser slots available - BLOCK the addition
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

                            if ($cardSlotSize) {
                                // Assign optimal PCIe slot
                                $assignedSlot = $slotTracker->assignSlot($configUuid, $cardSlotSize);
                                if ($assignedSlot) {
                                    $slotPosition = $assignedSlot;
                                } else {
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
                    }
                    // Check if provided slot_position follows riser_ prefix convention but is old format
                    else if (strpos($providedSlotPosition, 'riser_') !== 0) {
                        // DEPRECATED: This old auto-correction should not run anymore
                        // The new system assigns proper slot IDs directly
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
                    }
                }
            } catch (Exception $validationError) {
                error_log("Error during post-insert validation: " . $validationError->getMessage());
                // Continue - don't block on validation errors
            }
        }

        if ($result['success']) {
            // Simple success response - avoid complex operations that could fail
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

                                // Add auto-assignment info to response
                                $responseData['sfp_auto_assignment'] = [
                                    'success' => true,
                                    'sfps_assigned' => count($assignmentResult['assignments']),
                                    'assignments' => $assignmentResult['assignments']
                                ];
                            } else {
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

            // Phase 3: Handle side effects from ServerBuilder (onboard NIC auto-add, NIC config update)
            // These are now executed in ServerBuilder::addComponent() for all code paths
            // Handler extracts and passes results through to response

            if (isset($result['onboard_nics_added'])) {
                $responseData['onboard_nics_added'] = $result['onboard_nics_added'];
            }

            if (isset($result['onboard_nic_warning'])) {
                $responseData['onboard_nic_warning'] = $result['onboard_nic_warning'];
            }

            // Log the component addition
            logActivity($pdo, $user['id'], 'Component added', 'server', $config->get('id'),
                "Added $componentType ($componentUuid) to server config $configUuid");

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
        
        send_json_response(0, 1, 500, "Failed to add component");
    }

    } catch (Exception $e) {
        error_log("FATAL ERROR in handleAddComponent: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
        send_json_response(0, 1, 500, "Component addition failed");
    } catch (Throwable $t) {
        error_log("FATAL PHP ERROR in handleAddComponent: " . $t->getMessage());
        error_log("Stack trace: " . $t->getTraceAsString());
        error_log("File: " . $t->getFile() . " Line: " . $t->getLine());
        send_json_response(0, 1, 500, "Component addition failed unexpectedly");
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
        if ((int)$config->get('created_by') !== (int)$user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to modify this configuration");
        }

        // Phase 3: NIC removal validation and NIC config update now handled in ServerBuilder::removeComponent()

        $result = $serverBuilder->removeComponent($configUuid, $componentType, $componentUuid);

        if ($result['success']) {
            // Log the component removal
            logActivity($pdo, $user['id'], 'Component removed', 'server', $config->get('id'),
                "Removed $componentType ($componentUuid) from server config $configUuid");

            send_json_response(1, 1, 200, "Component removed successfully", [
                'component_removed' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid,
                    'server_uuid_cleared' => true
                ]
            ]);
        } else {
            // Extract error details from removal result (e.g., NIC removal validation errors)
            $errorData = [
                'component_type' => $componentType,
                'component_uuid' => $componentUuid
            ];

            // Include occupied_ports if NIC removal blocked by SFPs
            if (isset($result['occupied_ports'])) {
                $errorData['occupied_ports'] = $result['occupied_ports'];
            }

            if (isset($result['hint'])) {
                $errorData['hint'] = $result['hint'];
            }

            if (isset($result['nic_uuid'])) {
                $errorData['nic_uuid'] = $result['nic_uuid'];
            }

            send_json_response(0, 1, 400, $result['message'] ?? "Failed to remove component", $errorData);
        }
        
    } catch (\Throwable $e) {
        error_log("Error removing component: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to remove component");
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

        // Migrate any component NICs that pre-date slot auto-assignment
        $serverBuilder->migrateNICSlotPositions($configUuid);

        // Get unified slot tracking (PCIe, riser, and M.2)
        $slotTracking = $serverBuilder->getSlotTracking($configUuid);

        // Get unified network configuration
        $networkConfig = $serverBuilder->getNetworkConfiguration($configUuid);

        // Get configuration warnings
        $configWarnings = getConfigurationWarnings($details['components'] ?? []);

        // Get storage connectivity tracking
        $storageConnectivity = $serverBuilder->getStorageConnectivity($configUuid, $details['components'] ?? []);

        send_json_response(1, 1, 200, "Configuration retrieved successfully", [
            'configuration' => [
                'config_uuid' => $configuration['config_uuid'],
                'server_name' => $configuration['server_name'],
                'description' => $configuration['description'] ?? '',
                'status' => $configuration['configuration_status'],
                'location' => $configuration['location'] ?? '',
                'created_at' => $configuration['created_at'],
                'updated_at' => $configuration['updated_at'] ?? $configuration['created_at']
            ],
            'components' => $details['components'] ?? [],
            'summary' => [
                'total_components' => $details['total_components'],
                'component_counts' => $details['component_counts'],
                'power_consumption' => [
                    'total_watts' => $details['power_consumption']['total_watts'] ?? 0,
                    'total_with_overhead_watts' => $details['power_consumption']['total_with_overhead_watts'] ?? 0
                ]
            ],
            'hardware' => [
                'slots' => [
                    'pcie' => $slotTracking['pcie'],
                    'riser' => $slotTracking['riser'],
                    'm2' => $slotTracking['m2']
                ],
                'network' => $networkConfig,
                'storage_connectivity' => $storageConnectivity
            ],
            'validation' => [
                'is_valid' => !empty($validationResults),
                'last_validated' => $configuration['updated_at'] ?? $configuration['created_at'],
                'warnings' => $configWarnings,
                'errors' => []
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to retrieve configuration");
    }
}

/**
 * List server configurations
 */
function handleListConfigurations($serverBuilder, $user) {
    global $pdo;

    $request = array_merge($_GET, $_POST);
    $limit = isset($request['limit']) ? max(1, min(200, (int)$request['limit'])) : 20;
    $offset = isset($request['offset']) ? max(0, (int)$request['offset']) : 0;

    $statusInput = $request['status'] ?? null;
    $status = null;
    if ($statusInput !== null && $statusInput !== '') {
        $status = (int)$statusInput;
    }

    $includeVirtual = strtolower(trim((string)($request['include_virtual'] ?? 'all'))); // all, true/1, false/0

    try {
        $whereParts = ["1=1"];
        $filterParams = [];

        // Filter by user if no admin permissions
        if (!hasPermission($pdo, 'server.view_all', $user['id'])) {
            $whereParts[] = "sc.created_by = :created_by";
            $filterParams[':created_by'] = (int)$user['id'];
        }

        // Filter by status if provided
        if ($status !== null) {
            $whereParts[] = "sc.configuration_status = :status";
            $filterParams[':status'] = $status;
        }

        // Filter by is_virtual flag
        if (in_array($includeVirtual, ['true', '1', 'yes'], true)) {
            $whereParts[] = "sc.is_virtual = 1";
        } elseif (in_array($includeVirtual, ['false', '0', 'no'], true)) {
            $whereParts[] = "sc.is_virtual = 0";
        }
        // If 'all' or any other value, no filtering on is_virtual

        $whereClause = "WHERE " . implode(' AND ', $whereParts);

        $stmt = $pdo->prepare("
            SELECT sc.*, u.username as created_by_username
            FROM server_configurations sc
            LEFT JOIN users u ON sc.created_by = u.id
            $whereClause
            ORDER BY sc.created_at DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($filterParams as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $configurations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add configuration status text and component count for each configuration
        foreach ($configurations as &$config) {
            $config['configuration_status_text'] = getConfigurationStatusText($config['configuration_status']);
            $config['is_virtual'] = (bool)($config['is_virtual'] ?? 0);

            try {
                $components = $serverBuilder->extractComponentsFromJson(is_array($config) ? $config : [], true);
            } catch (Throwable $parseError) {
                error_log("Error parsing configuration components for list view ({$config['config_uuid']}): " . $parseError->getMessage());
                $components = [];
            }

            $componentTypes = [];
            foreach ($components as $component) {
                if (!empty($component['component_type'])) {
                    $componentTypes[$component['component_type']] = true;
                }
            }
            $config['total_component_types'] = count($componentTypes);
        }
        unset($config);

        // Get total count
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM server_configurations sc
            $whereClause
        ");
        foreach ($filterParams as $name => $value) {
            $countStmt->bindValue($name, $value, PDO::PARAM_INT);
        }
        $countStmt->execute();
        $totalCount = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        send_json_response(1, 1, 200, "Configurations retrieved successfully", [
            'configurations' => $configurations,
            'pagination' => [
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ]);

    } catch (Throwable $e) {
        error_log("Error listing configurations: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        send_json_response(0, 1, 500, "Failed to list configurations");
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
        send_json_response(0, 1, 500, "Failed to import virtual configuration");
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
        
        // Validate configuration before finalizing (comprehensive check)
        $validation = $serverBuilder->validateConfigurationComprehensive($configUuid);
        if (!$validation['valid']) {
            send_json_response(0, 1, 400, "Configuration is not valid for finalization", [
                'validation_errors' => $validation['errors']
            ]);
        }
        
        $result = $serverBuilder->finalizeConfiguration($configUuid, $finalNotes);

        if ($result['success']) {
            $configId = $config->get('id');
            $serverName = $config->get('server_name');
            $logResult = logActivity($pdo, $user['id'], 'Server created', 'server', $configId,
                "Created server config $configUuid with name: $serverName");
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
        send_json_response(0, 1, 500, "Failed to finalize configuration");
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
            $releasedCount = $result['components_released'] ?? 0;
            logActivity($pdo, $user['id'], 'Server deleted', 'server', $config->get('id'),
                "Deleted server config $configUuid, released $releasedCount components");

            send_json_response(1, 1, 200, "Configuration deleted successfully", [
                'components_released' => $result['components_released']
            ]);
        } else {
            send_json_response(0, 1, 400, $result['message'] ?? "Failed to delete configuration");
        }
        
    } catch (Exception $e) {
        error_log("Error deleting configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to delete configuration");
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
        send_json_response(0, 1, 500, "Failed to get available components");
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
        send_json_response(0, 1, 500, "Failed to validate configuration");
    }
}

/**
 * Get compatible components for a server configuration - ENHANCED Implementation per Important-fix requirements
 * This endpoint finds components compatible with an existing server configuration's motherboard
 */
function handleGetCompatible($serverBuilder, $user) {
    global $pdo;

    // Parse parameters
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
        // Check permissions
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }

        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to view this configuration");
        }

        // Delegate to ServerBuilder
        $result = $serverBuilder->getCompatibleComponents(
            $configUuid,
            $componentType,
            [
                'available_only' => $availableOnly,
                'include_debug' => true
            ]
        );

        if (!$result['success']) {
            send_json_response(0, 1, 400, $result['message']);
        }

        // Format response
        send_json_response(1, 1, 200, $result['message'], [
            'data' => [
                'config_uuid' => $configUuid,
                'component_type' => $componentType,
                'compatible_components' => $result['compatible_components'],
                'incompatible_components' => $result['incompatible_components'],
                'total_compatible' => count($result['compatible_components']),
                'total_compatible_and_available' => $result['totals']['compatible_and_available'],
                'total_compatible_but_unavailable' => $result['totals']['compatible_but_unavailable'],
                'total_incompatible' => $result['totals']['incompatible'],
                'total_found' => $result['totals']['total_found'],
                'filters_applied' => $result['filters_applied'],
                'existing_components_summary' => [
                    'total_existing' => 0,
                    'types' => []
                ],
                'compatibility_summary' => $result['compatibility_summary'],
                'debug_info' => $result['debug_info'],
                'inventory_summary' => $result['inventory_summary']
            ]
        ]);

    } catch (Exception $e) {
        error_log("Error getting compatible components: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatible components");
    }
}

// Enhanced Helper Functions for Error Handling and Component Management

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
    $tableMap = $GLOBALS['_serverComponentTableMap'];

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
 * Get slot validation warnings for a configuration
 * Uses UnifiedSlotTracker to validate PCIe, riser, and M.2 slots
 *
 * @param string $configUuid Server configuration UUID
 * @return array Validation warnings
 */
function getSlotValidationWarnings($configUuid) {
    global $pdo;
    $warnings = [];

    try {
        require_once __DIR__ . '/../../../core/models/compatibility/UnifiedSlotTracker.php';
        $slotTracker = new UnifiedSlotTracker($pdo);

        // Validate PCIe slots
        $pcieValidation = $slotTracker->validateAllSlots($configUuid);
        if (!$pcieValidation['valid']) {
            foreach ($pcieValidation['errors'] as $error) {
                $warnings[] = [
                    'type' => 'pcie_slot_error',
                    'severity' => 'high',
                    'message' => $error,
                    'source' => 'PCIe Slots'
                ];
            }
        }
        if (!empty($pcieValidation['warnings'])) {
            foreach ($pcieValidation['warnings'] as $warning) {
                $warnings[] = [
                    'type' => 'pcie_slot_warning',
                    'severity' => 'info',
                    'message' => $warning,
                    'source' => 'PCIe Slots'
                ];
            }
        }

        // Validate riser slots
        $riserValidation = $slotTracker->validateAllRiserSlots($configUuid);
        if (!$riserValidation['valid']) {
            foreach ($riserValidation['errors'] as $error) {
                $warnings[] = [
                    'type' => 'riser_slot_error',
                    'severity' => 'high',
                    'message' => $error,
                    'source' => 'Riser Slots'
                ];
            }
        }
        if (!empty($riserValidation['warnings'])) {
            foreach ($riserValidation['warnings'] as $warning) {
                $warnings[] = [
                    'type' => 'riser_slot_warning',
                    'severity' => 'info',
                    'message' => $warning,
                    'source' => 'Riser Slots'
                ];
            }
        }

        // Validate M.2 slots
        $m2Validation = $slotTracker->validateM2Slots($configUuid);
        if (!$m2Validation['valid']) {
            foreach ($m2Validation['errors'] as $error) {
                $warnings[] = [
                    'type' => 'm2_slot_error',
                    'severity' => 'high',
                    'message' => $error,
                    'source' => 'M.2 Slots'
                ];
            }
        }
        if (!empty($m2Validation['warnings'])) {
            foreach ($m2Validation['warnings'] as $warning) {
                $warnings[] = [
                    'type' => 'm2_slot_warning',
                    'severity' => 'info',
                    'message' => $warning,
                    'source' => 'M.2 Slots'
                ];
            }
        }

    } catch (Exception $e) {
        error_log("Error getting slot validation warnings: " . $e->getMessage());
    }

    return $warnings;
}

/**
 * Helper function to get suggested alternatives
 */
function getSuggestedAlternatives($pdo, $componentType, $excludeUuid, $limit = 5) {
    $tableMap = $GLOBALS['_serverComponentTableMap'];
    
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
    $tableMap = $GLOBALS['_serverComponentTableMap'];
    
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
    $tableMap = $GLOBALS['_serverComponentTableMap'];
    
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
            ComponentSpecPaths::getPath('motherboard')
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
        $jsonFiles = [
            'cpu' => ComponentSpecPaths::getPath('cpu'),
            'ram' => ComponentSpecPaths::getPath('ram'),
            'motherboard' => ComponentSpecPaths::getPath('motherboard')
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
                    $modelUuid = $model['uuid'] ?? $model['UUID'] ?? null;
                    if ($modelUuid === $componentUUID) {
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
 * Search server configurations by component serial number.
 * Searches all inventory tables for the given serial, then finds the associated server config.
 */
function handleSearchBySerial($serverBuilder, $user) {
    global $pdo;

    $request = array_merge($_GET, $_POST);
    $serial = trim($request['serial_number'] ?? '');

    if (empty($serial)) {
        send_json_response(0, 1, 400, "serial_number is required");
    }

    $inventoryTables = $GLOBALS['_serverComponentTableMap'];

    try {
        $matchedConfigUuids = [];

        foreach ($inventoryTables as $type => $table) {
            $stmt = $pdo->prepare("SELECT SerialNumber, ServerUUID FROM `$table` WHERE SerialNumber LIKE ? LIMIT 50");
            $stmt->execute(['%' . $serial . '%']);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                if (!empty($row['ServerUUID']) && $row['ServerUUID'] !== 'null') {
                    // ServerUUID in inventory tables stores the config_uuid of the assigned server
                    $matchedConfigUuids[$row['ServerUUID']] = true;
                }
            }
        }

        if (empty($matchedConfigUuids)) {
            send_json_response(1, 1, 200, "No servers found with that serial number", ['config_uuids' => []]);
        }

        // Filter to configs the user can actually see
        $placeholders = implode(',', array_fill(0, count($matchedConfigUuids), '?'));
        $uuids = array_keys($matchedConfigUuids);

        $canViewAll = hasPermission($pdo, 'server.view_all', $user['id']);
        if ($canViewAll) {
            $stmt = $pdo->prepare("
                SELECT config_uuid FROM server_configurations
                WHERE config_uuid IN ($placeholders)
            ");
            $stmt->execute($uuids);
        } else {
            $stmt = $pdo->prepare("
                SELECT config_uuid FROM server_configurations
                WHERE config_uuid IN ($placeholders) AND created_by = ?
            ");
            $stmt->execute(array_merge($uuids, [$user['id']]));
        }

        $visibleUuids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        send_json_response(1, 1, 200, "Search complete", ['config_uuids' => $visibleUuids]);

    } catch (Exception $e) {
        error_log("Error in handleSearchBySerial: " . $e->getMessage());
        send_json_response(0, 1, 500, "Search failed: " . $e->getMessage());
    }
}

?>
