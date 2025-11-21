<?php
/**
 * Infrastructure Management System - Server Creation Endpoint
 * File: api/server/create_server.php
 * 
 * UPDATED: Now includes location, rack_position, notes support
 * Note: User authentication and permission checks are handled in the main api.php
 * This file is included after those checks pass
 */

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'initialize':
            handleInitializeServerCreation();
            break;
            
        case 'step_add_component':
            handleStepAddComponent();
            break;
            
        case 'step_remove_component':
            handleStepRemoveComponent();
            break;
            
        case 'get_next_options':
            handleGetNextOptions();
            break;
            
        case 'validate_current':
            handleValidateCurrent();
            break;
            
        case 'finalize':
            handleFinalizeServer();
            break;
            
        case 'save_draft':
            handleSaveDraft();
            break;
            
        case 'load_draft':
            handleLoadDraft();
            break;
            
        case 'reset_configuration':
            handleResetConfiguration();
            break;
            
        case 'list_drafts':
            handleListDrafts();
            break;
            
        case 'delete_draft':
            handleDeleteDraft();
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid server creation action: $action");
    }
} catch (Exception $e) {
    error_log("Server creation error: " . $e->getMessage());
    send_json_response(0, 1, 500, "Server creation failed: " . $e->getMessage());
}

/**
 * UPDATED: Initialize new server creation session - Now includes location, rack_position, and notes
 */
function handleInitializeServerCreation() {
    global $pdo, $user;

    $serverName = trim($_POST['server_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'custom');
    $location = trim($_POST['location'] ?? '');
    $rackPosition = trim($_POST['rack_position'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $isVirtual = isset($_POST['is_virtual']) ? (int)$_POST['is_virtual'] : 0;


    if (empty($serverName)) {
        send_json_response(0, 1, 400, "Server name is required");
    }

    try {
        $pdo->beginTransaction();

        // Create new server configuration record with additional fields
        $configUuid = generateUUID();
        $stmt = $pdo->prepare("
            INSERT INTO server_configurations (
                config_uuid, server_name, description, location, rack_position, notes,
                created_by, created_at, updated_at, configuration_status, is_virtual
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 0, ?)
        ");
        $stmt->execute([
            $configUuid, $serverName, $description,
            $location, $rackPosition, $notes, $user['id'], $isVirtual
        ]);

        
        // Log the initialization with enhanced metadata
        logServerBuildAction($pdo, $configUuid, 'initialize', null, null, [
            'server_name' => $serverName,
            'description' => $description,
            'location' => $location,
            'rack_position' => $rackPosition,
            'notes' => $notes,
            'is_virtual' => $isVirtual
        ], $user['id']);


        $pdo->commit();

        send_json_response(1, 1, 200, "Server creation initialized", [
            'config_uuid' => $configUuid,
            'server_name' => $serverName,
            'description' => $description,
            'location' => $location,
            'rack_position' => $rackPosition,
            'notes' => $notes,
            'is_virtual' => (bool)$isVirtual
        ]);

        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error initializing server creation: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to initialize server creation: " . $e->getMessage());
    }
}

/**
 * Add component in step-by-step process
 * UPDATED: Now passes flag to ServerBuilder
 */
function handleStepAddComponent() {
    global $pdo, $user;

    $configUuid = $_POST['config_uuid'] ?? '';
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 1);
    $slotPosition = $_POST['slot_position'] ?? '';
    $notes = $_POST['notes'] ?? '';

    if (empty($configUuid) || empty($componentType) || empty($componentUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID, component type, and component UUID are required");
    }

    try {
        $pdo->beginTransaction();

        // Verify ownership, status
        $stmt = $pdo->prepare("SELECT created_by, configuration_status FROM server_configurations WHERE config_uuid = ? AND created_by = ?");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found or access denied");
        }

        if ($config['configuration_status'] > 0) {
            send_json_response(0, 1, 400, "Cannot modify configuration that has been validated or finalized");
        }


        // Check if component is available
        $tableName = $componentType . 'inventory';
        $stmt = $pdo->prepare("SELECT Status FROM $tableName WHERE UUID = ?");
        $stmt->execute([$componentUuid]);
        $component = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$component) {
            send_json_response(0, 1, 404, "Component not found");
        }

        // For real builds, only allow Status = 1 (available)
        if ($component['Status'] != 1) {
            send_json_response(0, 1, 400, "Component is not available (Status: {$component['Status']})");
        }

        
        // Use ServerBuilder to handle component addition (which manages JSON updates)
        require_once __DIR__ . '/../../../core/models/server/ServerBuilder.php';
        $serverBuilder = new ServerBuilder($pdo);

        $addResult = $serverBuilder->addComponent($configUuid, $componentType, $componentUuid, [
            'quantity' => $quantity,
            'slot_position' => $slotPosition,
            'notes' => $notes
        ]);

        if (!$addResult['success']) {
            $pdo->rollBack();
            send_json_response(0, 1, 400, $addResult['message']);
        }

        // Update component status to "In Use" ONLY for real builds (not test builds)
        
        $stmt = $pdo->prepare("UPDATE $tableName SET Status = 2, ServerUUID = ? WHERE UUID = ?");
        $stmt->execute([$configUuid, $componentUuid]);


        // Update configuration's updated_by and updated_at
        $stmt = $pdo->prepare("UPDATE server_configurations SET updated_by = ?, updated_at = NOW() WHERE config_uuid = ?");
        $stmt->execute([$user['id'], $configUuid]);

        // Log the action
        logServerBuildAction($pdo, $configUuid, 'component_added', $componentType, $componentUuid, [
            'quantity' => $quantity,
            'slot_position' => $slotPosition,
            'notes' => $notes
        ], $user['id']);


        $pdo->commit();

        send_json_response(1, 1, 200, "Component added successfully", [
            'component_type' => $componentType,
            'component_uuid' => $componentUuid
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error adding component: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to add component: " . $e->getMessage());
    }
}

/**
 * Remove component from step-by-step process
 * UPDATED: Now handles flag for builds
 */
function handleStepRemoveComponent() {
    global $pdo, $user;

    $configUuid = $_POST['config_uuid'] ?? '';
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? '';

    if (empty($configUuid) || empty($componentType) || empty($componentUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID, component type, and component UUID are required");
    }

    try {
        $pdo->beginTransaction();

        // Verify ownership
        $stmt = $pdo->prepare("SELECT created_by, configuration_status FROM server_configurations WHERE config_uuid = ? AND created_by = ?");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found or access denied");
        }

        if ($config['configuration_status'] > 0) {
            send_json_response(0, 1, 400, "Cannot modify configuration that has been validated or finalized");
        }


        // Use ServerBuilder to handle component removal (which manages JSON updates)
        require_once __DIR__ . '/../../../core/models/server/ServerBuilder.php';
        $serverBuilder = new ServerBuilder($pdo);

        $removeResult = $serverBuilder->removeComponent($configUuid, $componentType, $componentUuid);

        if (!$removeResult['success']) {
            $pdo->rollBack();
            send_json_response(0, 1, 400, $removeResult['message']);
        }

        // Update component status back to "Available" ONLY for real builds (not test builds)
        
        $tableName = $componentType . 'inventory';
        $stmt = $pdo->prepare("UPDATE $tableName SET Status = 1, ServerUUID = NULL WHERE UUID = ?");
        $stmt->execute([$componentUuid]);


        // Update configuration's updated_by and updated_at
        $stmt = $pdo->prepare("UPDATE server_configurations SET updated_by = ?, updated_at = NOW() WHERE config_uuid = ?");
        $stmt->execute([$user['id'], $configUuid]);

        // Log the action
        logServerBuildAction($pdo, $configUuid, 'component_removed', $componentType, $componentUuid, [], $user['id']);


        $pdo->commit();

        send_json_response(1, 1, 200, "Component removed successfully", [
            'component_type' => $componentType,
            'component_uuid' => $componentUuid
        ]);

        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error removing component: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to remove component: " . $e->getMessage());
    }
}

/**
 * Get next component options
 */
function handleGetNextOptions() {
    global $pdo, $user;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Verify ownership
        $stmt = $pdo->prepare("SELECT created_by FROM server_configurations WHERE config_uuid = ? AND created_by = ?");
        $stmt->execute([$configUuid, $user['id']]);
        
        if (!$stmt->fetch()) {
            send_json_response(0, 1, 404, "Server configuration not found or access denied");
        }
        
        // Get current components from JSON columns
        require_once __DIR__ . '/../../../core/models/server/ServerBuilder.php';
        $serverBuilder = new ServerBuilder($pdo);

        $stmt = $pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        $configData = $stmt->fetch(PDO::FETCH_ASSOC);

        $components = $serverBuilder->extractComponentsFromJson($configData);
        $currentComponents = [];
        foreach ($components as $comp) {
            $type = $comp['component_type'];
            $currentComponents[$type] = ($currentComponents[$type] ?? 0) + 1;
        }
        
        // Determine what components are still needed
        $allTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
        $options = [];
        
        foreach ($allTypes as $type) {
            if (!isset($currentComponents[$type])) {
                $options[$type] = getAvailableComponents($pdo, $type);
            }
        }
        
        // Suggest next step
        $nextSuggestion = 'complete';
        if (!isset($currentComponents['cpu'])) {
            $nextSuggestion = 'cpu';
        } elseif (!isset($currentComponents['motherboard'])) {
            $nextSuggestion = 'motherboard';
        } elseif (!isset($currentComponents['ram'])) {
            $nextSuggestion = 'ram';
        } elseif (!isset($currentComponents['storage'])) {
            $nextSuggestion = 'storage';
        } elseif (!isset($currentComponents['nic'])) {
            $nextSuggestion = 'nic';
        }
        
        send_json_response(1, 1, 200, "Next options retrieved", [
            'available_components' => $options,
            'current_components' => $currentComponents,
            'next_suggestion' => $nextSuggestion,
            'can_finalize' => isset($currentComponents['cpu']) && 
                            isset($currentComponents['motherboard']) && 
                            isset($currentComponents['ram']) && 
                            isset($currentComponents['storage'])
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting next options: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get next options: " . $e->getMessage());
    }
}

/**
 * Validate current configuration
 */
function handleValidateCurrent() {
    global $pdo, $user;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Verify ownership
        $stmt = $pdo->prepare("SELECT created_by FROM server_configurations WHERE config_uuid = ? AND created_by = ?");
        $stmt->execute([$configUuid, $user['id']]);
        
        if (!$stmt->fetch()) {
            send_json_response(0, 1, 404, "Server configuration not found or access denied");
        }
        
        // Get configuration details
        require_once __DIR__ . '/../../../core/models/server/ServerBuilder.php';
        $serverBuilder = new ServerBuilder($pdo);
        $details = $serverBuilder->getConfigurationDetails($configUuid);
        
        // Generate validation results
        $validation = generateValidationResults($pdo, $configUuid, $details);

        // Update configuration with validation results
        $stmt = $pdo->prepare("
            UPDATE server_configurations
            SET validation_results = ?, updated_by = ?, updated_at = NOW()
            WHERE config_uuid = ?
        ");
        $stmt->execute([json_encode($validation), $user['id'], $configUuid]);

        // Log validation
        logServerBuildAction($pdo, $configUuid, 'validated', null, null, [
            'is_complete' => $validation['is_complete'],
            'errors_count' => count($validation['errors']),
            'warnings_count' => count($validation['warnings'])
        ], $user['id']);

        send_json_response(1, 1, 200, "Configuration validated", [
            'validation_results' => $validation,
            'can_finalize' => $validation['is_complete'] && empty($validation['critical_errors'])
        ]);
        
    } catch (Exception $e) {
        error_log("Error validating configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to validate configuration: " . $e->getMessage());
    }
}


/**
 * UPDATED: Finalize server configuration - Now properly sets deployed_date and updated_by
 * UPDATED: Prevents finalizing test builds
 */
function handleFinalizeServer() {
    global $pdo, $user;

    $configUuid = $_POST['config_uuid'] ?? '';
    $finalNotes = $_POST['notes'] ?? '';

    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }

    try {
        $pdo->beginTransaction();

        // Verify ownership, current status
        $stmt = $pdo->prepare("
            SELECT created_by, configuration_status, notes
            FROM server_configurations
            WHERE config_uuid = ? AND created_by = ?
        ");

        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found or access denied");
        }

        if ($config['configuration_status'] == 3) {
            send_json_response(0, 1, 400, "Configuration is already finalized");
        }


        
        // Validate configuration before finalizing
        require_once __DIR__ . '/../../../core/models/server/ServerBuilder.php';
        $serverBuilder = new ServerBuilder($pdo);
        $details = $serverBuilder->getConfigurationDetails($configUuid);
        $validation = generateValidationResults($pdo, $configUuid, $details);
        
        if (!$validation['is_complete'] || !empty($validation['critical_errors'])) {
            send_json_response(0, 1, 400, "Configuration cannot be finalized - validation failed", [
                'validation_results' => $validation
            ]);
        }
        
        // UPDATED: Set all required fields when finalizing
        $deployedDate = date('Y-m-d H:i:s');
        $builtDate = $deployedDate; // For step-by-step creation, built and deployed are the same
        $combinedNotes = !empty($finalNotes) ? $finalNotes . "\n\n" . ($config['notes'] ?? '') : ($config['notes'] ?? '');

        $stmt = $pdo->prepare("
            UPDATE server_configurations
            SET configuration_status = 3,
                built_date = ?,
                deployed_date = ?,
                updated_by = ?,
                updated_at = NOW(),
                validation_results = ?,
                notes = ?
            WHERE config_uuid = ?
        ");

        $stmt->execute([
            $builtDate,
            $deployedDate,
            $user['id'],
            json_encode($validation),
            $combinedNotes,
            $configUuid
        ]);

        // Log the finalization
        logServerBuildAction($pdo, $configUuid, 'finalized', null, null, [
            'finalized_by' => $user['id'],
            'built_date' => $builtDate,
            'deployed_date' => $deployedDate,
            'final_notes' => $finalNotes
        ], $user['id']);

        $pdo->commit();

        send_json_response(1, 1, 200, "Server configuration finalized successfully", [
            'config_uuid' => $configUuid,
            'built_date' => $builtDate,
            'deployed_date' => $deployedDate,
            'validation_results' => $validation,
            'configuration_status' => 3,
            'configuration_status_text' => 'Finalized'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error finalizing server: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to finalize server: " . $e->getMessage());
    }
}

/**
 * Save draft configuration
 */
function handleSaveDraft() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $draftName = $_POST['draft_name'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Verify ownership
        $stmt = $pdo->prepare("SELECT created_by FROM server_configurations WHERE config_uuid = ? AND created_by = ?");
        $stmt->execute([$configUuid, $user['id']]);
        
        if (!$stmt->fetch()) {
            send_json_response(0, 1, 404, "Server configuration not found or access denied");
        }
        
        // Update draft information
        $stmt = $pdo->prepare("
            UPDATE server_configurations 
            SET server_name = COALESCE(?, server_name), 
                notes = COALESCE(?, notes),
                updated_by = ?,
                updated_at = NOW()
            WHERE config_uuid = ?
        ");
        $stmt->execute([$draftName, $notes, $user['id'], $configUuid]);
        
        // Log the save
        logServerBuildAction($pdo, $configUuid, 'draft_saved', null, null, [
            'draft_name' => $draftName,
            'notes' => $notes
        ], $user['id']);
        
        send_json_response(1, 1, 200, "Draft saved successfully", [
            'config_uuid' => $configUuid,
            'draft_name' => $draftName
        ]);
        
    } catch (Exception $e) {
        error_log("Error saving draft: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to save draft: " . $e->getMessage());
    }
}

/**
 * Load draft configuration
 */
function handleLoadDraft() {
    global $pdo, $user;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Get configuration details
        $stmt = $pdo->prepare("
            SELECT sc.*, u.username as created_by_username, uu.username as updated_by_username
            FROM server_configurations sc
            LEFT JOIN users u ON sc.created_by = u.id 
            LEFT JOIN users uu ON sc.updated_by = uu.id
            WHERE sc.config_uuid = ? AND sc.created_by = ?
        ");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            send_json_response(0, 1, 404, "Draft configuration not found or access denied");
        }
        
        // Get components from JSON columns
        require_once __DIR__ . '/../../../core/models/server/ServerBuilder.php';
        $serverBuilder = new ServerBuilder($pdo);

        $configDetails = $serverBuilder->getConfigurationDetails($configUuid);

        send_json_response(1, 1, 200, "Draft loaded successfully", [
            'configuration' => $config,
            'components' => $configDetails['components'] ?? []
        ]);
        
    } catch (Exception $e) {
        error_log("Error loading draft: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to load draft: " . $e->getMessage());
    }
}

/**
 * Reset configuration
 */
function handleResetConfiguration() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        $pdo->beginTransaction();
        
        // Verify ownership and status
        $stmt = $pdo->prepare("
            SELECT created_by, configuration_status 
            FROM server_configurations 
            WHERE config_uuid = ? AND created_by = ?
        ");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found or access denied");
        }
        
        if ($config['configuration_status'] > 1) {
            send_json_response(0, 1, 400, "Cannot reset configuration that has been built or finalized");
        }
        
        // Reset configuration by clearing all component JSON columns
        // Components are now stored in JSON columns, no separate table to delete

        // Reset configuration status and clear all component JSON columns
        $stmt = $pdo->prepare("
            UPDATE server_configurations
            SET configuration_status = 0,
                cpu_configuration = NULL,
                ram_configuration = NULL,
                storage_configuration = NULL,
                caddy_configuration = NULL,
                nic_config = NULL,
                hbacard_uuid = NULL,
                motherboard_uuid = NULL,
                chassis_uuid = NULL,
                pciecard_configurations = NULL,
                validation_results = NULL,
                power_consumption = NULL,
                updated_by = ?,
                updated_at = NOW()
            WHERE config_uuid = ?
        ");
        $stmt->execute([$user['id'], $configUuid]);
        
        // Log the reset
        logServerBuildAction($pdo, $configUuid, 'reset', null, null, [], $user['id']);
        
        $pdo->commit();
        
        send_json_response(1, 1, 200, "Configuration reset successfully", [
            'config_uuid' => $configUuid
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error resetting configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to reset configuration: " . $e->getMessage());
    }
}

/**
 * List draft configurations
 */
function handleListDrafts() {
    global $pdo, $user;
    
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    
    try {
        $stmt = $pdo->prepare("
            SELECT sc.*, 
                   u.username as created_by_username, 
                   uu.username as updated_by_username
            FROM server_configurations sc
            LEFT JOIN users u ON sc.created_by = u.id 
            LEFT JOIN users uu ON sc.updated_by = uu.id
            WHERE sc.created_by = ? AND sc.configuration_status < 3
            ORDER BY sc.updated_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user['id'], $limit, $offset]);
        $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get component counts for each draft from JSON columns
        require_once __DIR__ . '/../../../core/models/server/ServerBuilder.php';
        $serverBuilder = new ServerBuilder($pdo);

        foreach ($drafts as &$draft) {
            $configDetails = $serverBuilder->getConfigurationDetails($draft['config_uuid']);
            $draft['component_counts'] = $configDetails['component_counts'] ?? [];
            $draft['total_components'] = $configDetails['total_components'] ?? 0;
        }
        
        // Get total count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM server_configurations 
            WHERE created_by = ? AND configuration_status < 3
        ");
        $stmt->execute([$user['id']]);
        $totalCount = $stmt->fetch()['total'];
        
        send_json_response(1, 1, 200, "Drafts retrieved successfully", [
            'drafts' => $drafts,
            'pagination' => [
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error listing drafts: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to list drafts: " . $e->getMessage());
    }
}

/**
 * Delete draft configuration
 */
function handleDeleteDraft() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        $pdo->beginTransaction();
        
        // Verify ownership and status
        $stmt = $pdo->prepare("
            SELECT created_by, configuration_status 
            FROM server_configurations 
            WHERE config_uuid = ? AND created_by = ?
        ");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            send_json_response(0, 1, 404, "Draft configuration not found or access denied");
        }
        
        if ($config['configuration_status'] == 3) {
            send_json_response(0, 1, 400, "Cannot delete finalized configuration");
        }
        
        // Delete related records
        // Note: server_configuration_components table no longer exists - components are stored in JSON

        $stmt = $pdo->prepare("DELETE FROM server_configuration_history WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);

        $stmt = $pdo->prepare("DELETE FROM server_configurations WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        
        $pdo->commit();
        
        send_json_response(1, 1, 200, "Draft deleted successfully");
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error deleting draft: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to delete draft: " . $e->getMessage());
    }
}

// Helper functions

/**
 * Get available components of a specific type
 */
function getAvailableComponents($pdo, $componentType) {
    try {
        $tableName = $componentType . 'inventory';
        
        $stmt = $pdo->prepare("
            SELECT ID, UUID, SerialNumber, Status, Location, RackPosition, Notes, Flag
            FROM $tableName 
            WHERE Status = 1 
            ORDER BY Notes, SerialNumber
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting available components for $componentType: " . $e->getMessage());
        return [];
    }
}

/**
 * Log server build action
 */
function logServerBuildAction($pdo, $configUuid, $action, $componentType = null, $componentUuid = null, $metadata = null, $userId = null) {
    try {
        // Check if history table exists, create if it doesn't
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'server_configuration_history'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            createHistoryTable($pdo);
        } else {
            // Table exists, ensure it has all required columns
            ensureHistoryTableColumns($pdo);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO server_configuration_history 
            (config_uuid, action, component_type, component_uuid, metadata, created_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $configUuid, 
            $action, 
            $componentType, 
            $componentUuid, 
            json_encode($metadata),
            $userId
        ]);
        
    } catch (Exception $e) {
        error_log("Error logging server build action: " . $e->getMessage());
        // Don't throw exception as this shouldn't break the main operation
    }
}

/**
 * Create server configuration history table if it doesn't exist
 */
function createHistoryTable($pdo) {
    try {
        $sql = "
            CREATE TABLE IF NOT EXISTS server_configuration_history (
                id int(11) NOT NULL AUTO_INCREMENT,
                config_uuid varchar(36) NOT NULL,
                action varchar(50) NOT NULL COMMENT 'created, updated, component_added, component_removed, validated, etc.',
                component_type varchar(20) DEFAULT NULL,
                component_uuid varchar(36) DEFAULT NULL,
                metadata text DEFAULT NULL COMMENT 'JSON metadata for the action',
                created_by int(11) DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (id),
                KEY idx_config_uuid (config_uuid),
                KEY idx_component_uuid (component_uuid),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $pdo->exec($sql);
        error_log("Created server_configuration_history table");
    } catch (Exception $e) {
        error_log("Error creating history table: " . $e->getMessage());
    }
}

/**
 * Ensure server_configuration_history table has all required columns
 */
function ensureHistoryTableColumns($pdo) {
    try {
        // Check if component_type column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM server_configuration_history LIKE 'component_type'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE server_configuration_history ADD COLUMN component_type varchar(20) DEFAULT NULL AFTER action");
            error_log("Added component_type column to server_configuration_history");
        }

        // Check if component_uuid column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM server_configuration_history LIKE 'component_uuid'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE server_configuration_history ADD COLUMN component_uuid varchar(36) DEFAULT NULL AFTER component_type");
            error_log("Added component_uuid column to server_configuration_history");
        }

        // Check if metadata column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM server_configuration_history LIKE 'metadata'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE server_configuration_history ADD COLUMN metadata TEXT DEFAULT NULL COMMENT 'JSON metadata for the action' AFTER component_uuid");
            error_log("Added metadata column to server_configuration_history");
        }

        // Check if created_by column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM server_configuration_history LIKE 'created_by'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE server_configuration_history ADD COLUMN created_by int(11) DEFAULT NULL AFTER metadata");
            error_log("Added created_by column to server_configuration_history");
        }
    } catch (Exception $e) {
        error_log("Error ensuring history table columns: " . $e->getMessage());
    }
}

/**
 * Generate validation results for a configuration
 */
function generateValidationResults($pdo, $configUuid, $details = null) {
    try {
        if (!$details) {
            require_once __DIR__ . '/../../../core/models/server/ServerBuilder.php';
            $serverBuilder = new ServerBuilder($pdo);
            $details = $serverBuilder->getConfigurationDetails($configUuid);
        }
        
        $validation = [
            'is_complete' => false,
            'errors' => [],
            'warnings' => [],
            'critical_errors' => [],
            'component_status' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $components = $details['components'] ?? [];
        $requiredComponents = ['cpu', 'motherboard', 'ram', 'storage'];
        
        // Check for required components
        foreach ($requiredComponents as $requiredType) {
            if (empty($components[$requiredType])) {
                $validation['critical_errors'][] = "Missing required component: " . strtoupper($requiredType);
                $validation['component_status'][$requiredType] = 'missing';
            } else {
                $validation['component_status'][$requiredType] = 'present';
            }
        }
        
        // Check for optional but recommended components
        $optionalComponents = ['nic'];
        foreach ($optionalComponents as $optionalType) {
            if (empty($components[$optionalType])) {
                $validation['warnings'][] = "Recommended component missing: " . strtoupper($optionalType);
                $validation['component_status'][$optionalType] = 'missing';
            } else {
                $validation['component_status'][$optionalType] = 'present';
            }
        }
        
        // Power consumption validation
        $powerConsumption = $details['power_consumption']['total_with_overhead_watts'] ?? 0;
        if ($powerConsumption > 1000) {
            $validation['warnings'][] = "High power consumption: {$powerConsumption}W";
        } elseif ($powerConsumption > 1500) {
            $validation['errors'][] = "Excessive power consumption: {$powerConsumption}W";
        }
        
        // Compatibility score validation
        $compatibilityScore = calculateCompatibilityScore($pdo, $configUuid);
        if ($compatibilityScore && $compatibilityScore < 60) {
            $validation['critical_errors'][] = "Low compatibility score: {$compatibilityScore}%";
        } elseif ($compatibilityScore && $compatibilityScore < 80) {
            $validation['warnings'][] = "Moderate compatibility score: {$compatibilityScore}%";
        }
        
        // Check if configuration is complete
        $validation['is_complete'] = empty($validation['critical_errors']);
        
        return $validation;
        
    } catch (Exception $e) {
        error_log("Error generating validation results: " . $e->getMessage());
        return [
            'is_complete' => false,
            'errors' => ['Validation system error'],
            'warnings' => [],
            'critical_errors' => ['Unable to validate configuration'],
            'component_status' => [],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

/**
 * Check CPU-Motherboard compatibility
 */
function checkCpuMotherboardCompatibility($pdo, $cpuUuid, $motherboardUuid) {
    try {
        // Get CPU socket info
        $stmt = $pdo->prepare("SELECT Notes FROM cpuinventory WHERE UUID = ?");
        $stmt->execute([$cpuUuid]);
        $cpu = $stmt->fetch();
        
        // Get Motherboard socket info
        $stmt = $pdo->prepare("SELECT Notes FROM motherboardinventory WHERE UUID = ?");
        $stmt->execute([$motherboardUuid]);
        $motherboard = $stmt->fetch();
        
        if (!$cpu || !$motherboard) {
            return 50.0;
        }
        
        $cpuNotes = strtolower($cpu['Notes'] ?? '');
        $motherboardNotes = strtolower($motherboard['Notes'] ?? '');
        
        // Extract socket types
        $commonSockets = ['lga1151', 'lga1200', 'lga1700', 'am4', 'am5', 'tr4'];
        
        $cpuSocket = null;
        $motherboardSocket = null;
        
        foreach ($commonSockets as $socket) {
            if (strpos($cpuNotes, $socket) !== false) {
                $cpuSocket = $socket;
                break;
            }
        }
        
        foreach ($commonSockets as $socket) {
            if (strpos($motherboardNotes, $socket) !== false) {
                $motherboardSocket = $socket;
                break;
            }
        }
        
        if ($cpuSocket && $motherboardSocket && $cpuSocket === $motherboardSocket) {
            return 95.0; // Perfect compatibility
        } elseif ($cpuSocket && $motherboardSocket) {
            return 30.0; // Incompatible sockets
        } else {
            return 75.0; // Unknown compatibility
        }
        
    } catch (Exception $e) {
        error_log("Error checking CPU-Motherboard compatibility: " . $e->getMessage());
        return 70.0;
    }
}

/**
 * Check RAM-Motherboard compatibility
 */
function checkRamMotherboardCompatibility($pdo, $ramUuid, $motherboardUuid) {
    try {
        // Get RAM type info
        $stmt = $pdo->prepare("SELECT Notes FROM raminventory WHERE UUID = ?");
        $stmt->execute([$ramUuid]);
        $ram = $stmt->fetch();
        
        // Get Motherboard memory support info
        $stmt = $pdo->prepare("SELECT Notes FROM motherboardinventory WHERE UUID = ?");
        $stmt->execute([$motherboardUuid]);
        $motherboard = $stmt->fetch();
        
        if (!$ram || !$motherboard) {
            return 50.0;
        }
        
        $ramNotes = strtolower($ram['Notes'] ?? '');
        $motherboardNotes = strtolower($motherboard['Notes'] ?? '');
        
        // Check DDR type compatibility
        if ((strpos($ramNotes, 'ddr4') !== false && strpos($motherboardNotes, 'ddr4') !== false) ||
            (strpos($ramNotes, 'ddr5') !== false && strpos($motherboardNotes, 'ddr5') !== false)) {
            return 90.0; // Good compatibility
        } elseif ((strpos($ramNotes, 'ddr4') !== false && strpos($motherboardNotes, 'ddr5') !== false) ||
                  (strpos($ramNotes, 'ddr5') !== false && strpos($motherboardNotes, 'ddr4') !== false)) {
            return 20.0; // Incompatible DDR types
        } else {
            return 75.0; // Unknown compatibility
        }
        
    } catch (Exception $e) {
        error_log("Error checking RAM-Motherboard compatibility: " . $e->getMessage());
        return 70.0;
    }
}




/**
 * Get configuration status text
 */
function getConfigurationStatusText($status) {
    $statusMap = [
        0 => 'Draft',
        1 => 'Validated', 
        2 => 'Built',
        3 => 'Finalized'
    ];
    
    return $statusMap[$status] ?? 'Unknown';
}

/**
 * Validate server configuration completeness
 */
function validateServerConfiguration($pdo, $configData) {
    $validation = [
        'is_complete' => false,
        'errors' => [],
        'warnings' => [],
        'critical_errors' => []
    ];
    
    // Check for required components
    $requiredComponents = ['cpu', 'motherboard', 'ram', 'storage'];
    
    if (!isset($configData['components'])) {
        $validation['critical_errors'][] = 'No components configured';
        return $validation;
    }
    
    foreach ($requiredComponents as $required) {
        if (empty($configData['components'][$required])) {
            $validation['critical_errors'][] = "Missing required component: " . strtoupper($required);
        }
    }
    
    // Check if configuration is complete
    $validation['is_complete'] = empty($validation['critical_errors']);
    
    return $validation;
}

?>