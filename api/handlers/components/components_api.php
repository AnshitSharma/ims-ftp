<?php
require_once __DIR__ . '/../../../core/config/app.php';
require_once __DIR__ . '/../../../core/helpers/BaseFunctions.php';

header('Content-Type: application/json');

// Initialize authentication
$user = null;
$authResult = check_auth();

if (!$authResult['authenticated']) {
    send_json_response(0, 0, 401, "Authentication required");
}

$user = $authResult['user'];

// Get action and component type from POST data
$action = $_POST['action'] ?? '';
$componentType = '';
$table = '';

// Extract component type from action
if (preg_match('/^([a-z]+)-(get|add|update|delete|list)$/', $action, $matches)) {
    $componentType = $matches[1];
    $actionType = $matches[2];
} else {
    send_json_response(0, 1, 400, "Invalid action format");
}

// Map component types to database tables
$tableMap = [
    'chassis' => 'chassisinventory',
    'cpu' => 'cpuinventory',
    'ram' => 'raminventory',
    'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory',
    'nic' => 'nicinventory',
    'caddy' => 'caddyinventory'
];

if (!isset($tableMap[$componentType])) {
    send_json_response(0, 1, 400, "Invalid component type: $componentType");
}

$table = $tableMap[$componentType];

// Check permissions
$requiredPermissions = [
    'get' => "$componentType.view",
    'list' => "$componentType.view",
    'add' => "$componentType.create",
    'update' => "$componentType.edit",
    'delete' => "$componentType.delete"
];

if (isset($requiredPermissions[$actionType])) {
    if (!hasPermission($pdo, $requiredPermissions[$actionType], $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for this action");
    }
}

// Route to appropriate handler
switch ($actionType) {
    case 'get':
        handleGetComponent();
        break;
    case 'list':
        handleListComponents();
        break;
    case 'add':
        handleAddComponent();
        break;
    case 'update':
        handleUpdateComponent();
        break;
    case 'delete':
        handleDeleteComponent();
        break;
    default:
        send_json_response(0, 1, 400, "Invalid action type: $actionType");
}

/**
 * Get single component by ID
 */
function handleGetComponent() {
    global $pdo, $table, $componentType, $user;
    
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        send_json_response(0, 1, 400, "Valid component ID is required");
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = ?");
        $stmt->execute([$id]);
        $component = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$component) {
            send_json_response(0, 1, 404, "Component not found");
        }
        
        // Add additional information
        $component['StatusText'] = getStatusText($component['Status']) ?? 'Unknown';
        
        // Get component history (if history table exists)
        try {
            $historyStmt = $pdo->prepare("SELECT * FROM {$table}_history WHERE component_id = :id ORDER BY created_at DESC LIMIT 10");
            $historyStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $historyStmt->execute();
            $component['history'] = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // History table doesn't exist, that's okay
            $component['history'] = [];
        }
        
        // Get server configurations using this component (if server system is available)
        if (function_exists('serverSystemInitialized') && serverSystemInitialized($pdo)) {
            $component['server_usage'] = getComponentServerUsage($pdo, $componentType, $component['UUID']);
        }
        
        // Parse JSON specifications if available
        $jsonFields = ['Specifications', 'JsonData', 'TechnicalSpecs'];
        foreach ($jsonFields as $field) {
            if (isset($component[$field]) && !empty($component[$field])) {
                $decoded = json_decode($component[$field], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $component[$field . '_parsed'] = $decoded;
                }
            }
        }
        
        // Check system features availability
        $serverSystemAvailable = function_exists('serverSystemInitialized') && serverSystemInitialized($pdo);
        
        send_json_response(1, 1, 200, "Component retrieved successfully", [
            'component' => $component,
            'available_features' => [
                'compatibility_checking' => $serverSystemAvailable,
                'server_configurations' => $serverSystemAvailable,
                'json_specifications' => !empty($component['Specifications'] ?? '')
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleGetComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred: " . $e->getMessage());
    } catch (Exception $e) {
        error_log("General error in handleGetComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "An unexpected error occurred");
    }
}

/**
 * List components with filtering and pagination
 */
function handleListComponents() {
    global $pdo, $table, $componentType, $user;
    
    $limit = min((int)($_POST['limit'] ?? 50), 100); // Max 100 items
    $offset = (int)($_POST['offset'] ?? 0);
    $search = trim($_POST['search'] ?? '');
    $status = $_POST['status'] ?? '';
    $sortBy = $_POST['sort_by'] ?? 'ID';
    $sortOrder = strtoupper($_POST['sort_order'] ?? 'DESC');
    
    // Validate sort order
    if (!in_array($sortOrder, ['ASC', 'DESC'])) {
        $sortOrder = 'DESC';
    }
    
    // Validate sort field (prevent SQL injection)
    $allowedSortFields = ['ID', 'SerialNumber', 'Status', 'CreatedAt', 'UpdatedAt', 'Location', 'RackPosition'];
    if (!in_array($sortBy, $allowedSortFields)) {
        $sortBy = 'ID';
    }
    
    try {
        $whereConditions = [];
        $params = [];
        
        // Add search filter
        if (!empty($search)) {
            $searchFields = ['SerialNumber', 'Location', 'RackPosition', 'Notes'];
            $searchConditions = [];
            foreach ($searchFields as $field) {
                $searchConditions[] = "$field LIKE ?";
                $params[] = "%$search%";
            }
            $whereConditions[] = "(" . implode(" OR ", $searchConditions) . ")";
        }
        
        // Add status filter
        if ($status !== '') {
            $whereConditions[] = "Status = ?";
            $params[] = $status;
        }
        
        $whereClause = empty($whereConditions) ? "" : "WHERE " . implode(" AND ", $whereConditions);
        
        // Get total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM $table $whereClause");
        $countStmt->execute($params);
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get components
        $stmt = $pdo->prepare("
            SELECT * FROM $table 
            $whereClause 
            ORDER BY $sortBy $sortOrder 
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add status text to each component
        foreach ($components as &$component) {
            $component['StatusText'] = getStatusText($component['Status']);
            
            // Parse JSON specifications if available
            if (isset($component['Specifications']) && !empty($component['Specifications'])) {
                $decoded = json_decode($component['Specifications'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $component['Specifications_parsed'] = $decoded;
                }
            }
        }
        
        // Get status counts
        $statusStmt = $pdo->prepare("
            SELECT 
                Status,
                COUNT(*) as count 
            FROM $table 
            GROUP BY Status
        ");
        $statusStmt->execute();
        $statusCounts = [];
        $totalComponents = 0;
        while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
            $statusCounts[$row['Status']] = $row['count'];
            $totalComponents += $row['count'];
        }
        
        send_json_response(1, 1, 200, "Components retrieved successfully", [
            'components' => $components,
            'pagination' => [
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder
            ],
            'statistics' => [
                'total_components' => $totalComponents,
                'status_counts' => [
                    'available' => $statusCounts['1'] ?? 0,
                    'in_use' => $statusCounts['2'] ?? 0,
                    'failed' => $statusCounts['0'] ?? 0
                ]
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleListComponents: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred");
    } catch (Exception $e) {
        error_log("General error in handleListComponents: " . $e->getMessage());
        send_json_response(0, 1, 500, "An unexpected error occurred");
    }
}

/**
 * Add new component with enhanced validation
 */
function handleAddComponent() {
    global $pdo, $table, $componentType, $user;
    
    // Required fields
    $requiredFields = ['SerialNumber', 'Status'];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            send_json_response(0, 1, 400, "Required field missing: $field");
        }
    }
    
    $serialNumber = trim($_POST['SerialNumber']);
    $status = (int)$_POST['Status'];
    $uuid = trim($_POST['UUID'] ?? '');
    $serverUUID = trim($_POST['ServerUUID'] ?? '');
    $validateCompatibility = filter_var($_POST['validate_compatibility'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Validate status
    if (!in_array($status, [0, 1, 2])) {
        send_json_response(0, 1, 400, "Invalid status. Must be 0 (Failed), 1 (Available), or 2 (In Use)");
    }
    
    // If status is "In Use", ServerUUID should be provided
    if ($status == 2 && empty($serverUUID)) {
        send_json_response(0, 1, 400, "ServerUUID is required when status is 'In Use'");
    }
    
    // Generate UUID if not provided
    if (empty($uuid)) {
        $uuid = generateUUID();
    }
    
    try {
        // Check for duplicate serial number
        $checkStmt = $pdo->prepare("SELECT ID FROM $table WHERE SerialNumber = ?");
        $checkStmt->execute([$serialNumber]);
        if ($checkStmt->fetch()) {
            send_json_response(0, 1, 409, "Component with this serial number already exists");
        }
        
        // Check for duplicate UUID
        $checkStmt = $pdo->prepare("SELECT ID FROM $table WHERE UUID = ?");
        $checkStmt->execute([$uuid]);
        if ($checkStmt->fetch()) {
            send_json_response(0, 1, 409, "Component with this UUID already exists");
        }
        
        // Prepare fields for insertion
        $fields = [
            'UUID' => $uuid,
            'SerialNumber' => $serialNumber,
            'Status' => $status,
            'CreatedAt' => date('Y-m-d H:i:s'),
            'UpdatedAt' => date('Y-m-d H:i:s'),
            'CreatedBy' => $user['username'],
            'UpdatedBy' => $user['username']
        ];
        
        // Optional fields
        $optionalFields = [
            'ServerUUID', 'Location', 'RackPosition', 'PurchaseDate', 
            'InstallationDate', 'WarrantyEndDate', 'Flag', 'Notes', 'Specifications'
        ];
        
        foreach ($optionalFields as $field) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $fields[$field] = trim($_POST[$field]);
            }
        }
        
        // Validate JSON specifications if provided
        if (isset($fields['Specifications']) && !empty($fields['Specifications'])) {
            $decoded = json_decode($fields['Specifications'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                send_json_response(0, 1, 400, "Invalid JSON in Specifications field: " . json_last_error_msg());
            }
            
            // Validate specifications format if compatibility checking is enabled
            if ($validateCompatibility && class_exists('ComponentCompatibility')) {
                $compatibilityEngine = new ComponentCompatibility($pdo);
                $specValidation = $compatibilityEngine->validateSpecifications($componentType, $decoded);
                if (!$specValidation['valid']) {
                    send_json_response(0, 1, 400, "Invalid specifications format", [
                        'specification_errors' => $specValidation['errors']
                    ]);
                }
            }
        }
        
        // Build INSERT query
        $fieldNames = array_keys($fields);
        $placeholders = array_fill(0, count($fields), '?');
        $values = array_values($fields);
        
        $query = "INSERT INTO $table (" . implode(', ', $fieldNames) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($query);
        $stmt->execute($values);
        
        $insertId = $pdo->lastInsertId();
        
        // Log the addition
        logComponentAction($pdo, $componentType, $insertId, 'create', 'Component created', $fields, $user['id']);
        
        // Get the inserted component
        $getStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = ?");
        $getStmt->execute([$insertId]);
        $newComponent = $getStmt->fetch(PDO::FETCH_ASSOC);
        $newComponent['StatusText'] = getStatusText($newComponent['Status']);
        
        send_json_response(1, 1, 201, "Component added successfully", [
            'component' => $newComponent,
            'component_id' => $insertId,
            'uuid' => $uuid,
            'serial_number' => $serialNumber
        ]);
        
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Integrity constraint violation
            send_json_response(0, 1, 409, "Duplicate entry detected");
        } else {
            error_log("Database error in handleAddComponent: " . $e->getMessage());
            send_json_response(0, 1, 500, "Database error occurred");
        }
    } catch (Exception $e) {
        error_log("General error in handleAddComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "An unexpected error occurred");
    }
}

/**
 * Update existing component
 */
function handleUpdateComponent() {
    global $pdo, $table, $componentType, $user;
    
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        send_json_response(0, 1, 400, "Valid component ID is required");
    }
    
    try {
        // Check if component exists
        $checkStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = ?");
        $checkStmt->execute([$id]);
        $existingComponent = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingComponent) {
            send_json_response(0, 1, 404, "Component not found");
        }
        
        // Check if component is in use and prevent certain updates
        if ($existingComponent['Status'] == 2) {
            $restrictedFields = ['UUID', 'SerialNumber'];
            foreach ($restrictedFields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== $existingComponent[$field]) {
                    send_json_response(0, 1, 400, "Cannot modify $field while component is in use");
                }
            }
        }
        
        $updateFields = [];
        $updateValues = [];
        $changes = [];
        
        // Updatable fields
        $allowedFields = [
            'SerialNumber', 'Status', 'ServerUUID', 'Location', 'RackPosition',
            'PurchaseDate', 'InstallationDate', 'WarrantyEndDate', 'Flag', 'Notes', 'Specifications'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($_POST[$field])) {
                $newValue = trim($_POST[$field]);
                $oldValue = $existingComponent[$field] ?? '';
                
                if ($newValue !== $oldValue) {
                    $updateFields[] = "$field = ?";
                    $updateValues[] = $newValue;
                    $changes[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue
                    ];
                }
            }
        }
        
        if (empty($updateFields)) {
            send_json_response(0, 1, 400, "No changes detected");
        }
        
        // Validate status change if status is being updated
        if (isset($changes['Status'])) {
            $oldStatus = (int)$changes['Status']['old'];
            $newStatus = (int)$changes['Status']['new'];
            
            $validTransitions = [
                0 => [1], // Failed -> Available (after repair)
                1 => [0, 2], // Available -> Failed or In Use
                2 => [0, 1] // In Use -> Failed or Available
            ];
            
            if (!isset($validTransitions[$oldStatus]) || !in_array($newStatus, $validTransitions[$oldStatus])) {
                send_json_response(0, 1, 400, "Invalid status transition from " . getStatusText($oldStatus) . " to " . getStatusText($newStatus));
            }
            
            // If changing to "In Use", ServerUUID should be provided
            if ($newStatus == 2 && empty($_POST['ServerUUID'])) {
                send_json_response(0, 1, 400, "ServerUUID is required when setting status to 'In Use'");
            }
            
            // If changing from "In Use", clear ServerUUID if not provided
            if ($oldStatus == 2 && $newStatus != 2 && !isset($_POST['ServerUUID'])) {
                $updateFields[] = "ServerUUID = ?";
                $updateValues[] = '';
                $changes['ServerUUID'] = [
                    'old' => $existingComponent['ServerUUID'] ?? '',
                    'new' => ''
                ];
            }
        }
        
        // Validate JSON specifications if provided
        if (isset($changes['Specifications'])) {
            $decoded = json_decode($changes['Specifications']['new'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                send_json_response(0, 1, 400, "Invalid JSON in Specifications field: " . json_last_error_msg());
            }
        }
        
        // Add update timestamp and user
        $updateFields[] = "UpdatedAt = ?";
        $updateFields[] = "UpdatedBy = ?";
        $updateValues[] = date('Y-m-d H:i:s');
        $updateValues[] = $user['username'];
        $updateValues[] = $id;
        
        // Perform update
        $query = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE ID = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute($updateValues);
        
        // Log the update
        logComponentAction($pdo, $componentType, $id, 'update', 'Component updated', $changes, $user['id']);
        
        // Get updated component
        $getStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = ?");
        $getStmt->execute([$id]);
        $updatedComponent = $getStmt->fetch(PDO::FETCH_ASSOC);
        $updatedComponent['StatusText'] = getStatusText($updatedComponent['Status']);
        
        send_json_response(1, 1, 200, "Component updated successfully", [
            'component' => $updatedComponent,
            'changes' => $changes,
            'fields_updated' => count($changes)
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleUpdateComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred");
    } catch (Exception $e) {
        error_log("General error in handleUpdateComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "An unexpected error occurred");
    }
}

/**
 * Delete component (soft delete by setting status to failed)
 */
function handleDeleteComponent() {
    global $pdo, $table, $componentType, $user;
    
    $id = (int)($_POST['id'] ?? 0);
    $hardDelete = filter_var($_POST['hard_delete'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if ($id <= 0) {
        send_json_response(0, 1, 400, "Valid component ID is required");
    }
    
    try {
        // Check if component exists
        $checkStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = ?");
        $checkStmt->execute([$id]);
        $component = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$component) {
            send_json_response(0, 1, 404, "Component not found");
        }
        
        // Check if component is in use
        if ($component['Status'] == 2 && !$hardDelete) {
            send_json_response(0, 1, 400, "Cannot delete component that is currently in use", [
                'component_status' => 'In Use',
                'server_uuid' => $component['ServerUUID'] ?? null,
                'can_force_delete' => hasPermission($pdo, "$componentType.force_delete", $user['id'])
            ]);
        }
        
        // Check for server configurations using this component
        $usageInfo = getComponentServerUsage($pdo, $componentType, $component['UUID']);
        if (!empty($usageInfo['configurations']) && !$hardDelete) {
            send_json_response(0, 1, 400, "Component is used in server configurations", [
                'server_configurations' => $usageInfo['configurations'],
                'usage_count' => count($usageInfo['configurations']),
                'can_force_delete' => hasPermission($pdo, "$componentType.force_delete", $user['id'])
            ]);
        }
        
        if ($hardDelete) {
            // Hard delete requires special permission
            if (!hasPermission($pdo, "$componentType.force_delete", $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for hard delete");
            }
            
            // Remove from server configurations first
            if (function_exists('removeComponentFromAllConfigurations')) {
                removeComponentFromAllConfigurations($pdo, $componentType, $component['UUID']);
            }
            
            // Delete component record
            $stmt = $pdo->prepare("DELETE FROM $table WHERE ID = ?");
            $stmt->execute([$id]);
            
            $message = "Component permanently deleted";
            $logAction = 'hard_delete';
        } else {
            // Soft delete - mark as failed/decommissioned
            $stmt = $pdo->prepare("UPDATE $table SET Status = 0, ServerUUID = '', UpdatedAt = ?, UpdatedBy = ? WHERE ID = ?");
            $stmt->execute([date('Y-m-d H:i:s'), $user['username'], $id]);
            
            $message = "Component marked as failed/decommissioned";
            $logAction = 'soft_delete';
        }
        
        // Log the deletion
        logComponentAction($pdo, $componentType, $id, $logAction, $message, [
            'component_data' => $component,
            'hard_delete' => $hardDelete
        ], $user['id']);
        
        send_json_response(1, 1, 200, $message, [
            'component_id' => $id,
            'serial_number' => $component['SerialNumber'],
            'uuid' => $component['UUID'],
            'deletion_type' => $hardDelete ? 'permanent' : 'soft',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleDeleteComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred");
    } catch (Exception $e) {
        error_log("General error in handleDeleteComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "An unexpected error occurred");
    }
}

// Helper Functions

/**
 * Get status text from status code
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
 * Generate UUID v4
 */
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Log component actions
 */
function logComponentAction($pdo, $componentType, $componentId, $action, $description, $details = [], $userId = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO component_action_log 
            (component_type, component_id, action, description, action_details, user_id, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $componentType,
            $componentId,
            $action,
            $description,
            json_encode($details),
            $userId
        ]);
    } catch (Exception $e) {
        error_log("Failed to log component action: " . $e->getMessage());
        // Don't fail the main operation if logging fails
    }
}

/**
 * Get component server usage information
 */
function getComponentServerUsage($pdo, $componentType, $componentUuid) {
    try {
        if (!function_exists('serverSystemInitialized') || !serverSystemInitialized($pdo)) {
            return ['configurations' => [], 'usage_count' => 0];
        }
        
        $stmt = $pdo->prepare("
            SELECT sc.config_uuid, sc.server_name, sc.configuration_status, scc.added_at, scc.quantity
            FROM server_configuration_components scc
            JOIN server_configurations sc ON scc.config_uuid = sc.config_uuid
            WHERE scc.component_type = ? AND scc.component_uuid = ?
            ORDER BY scc.added_at DESC
        ");
        $stmt->execute([$componentType, $componentUuid]);
        $configurations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'configurations' => $configurations,
            'usage_count' => count($configurations)
        ];
        
    } catch (Exception $e) {
        error_log("Error getting component server usage: " . $e->getMessage());
        return ['configurations' => [], 'usage_count' => 0];
    }
}

/**
 * Remove component from all server configurations
 */
function removeComponentFromAllConfigurations($pdo, $componentType, $componentUuid) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM server_configuration_components 
            WHERE component_type = ? AND component_uuid = ?
        ");
        $stmt->execute([$componentType, $componentUuid]);
        
        return $stmt->rowCount();
        
    } catch (Exception $e) {
        error_log("Error removing component from configurations: " . $e->getMessage());
        return 0;
    }
}

/**
 * Check if server system is initialized
 */
function serverSystemInitialized($pdo) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'server_configurations'");
        $stmt->execute();
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Enhanced component availability checking with detailed status information
 */
function checkComponentAvailabilityDetailed($pdo, $componentType, $componentUuid) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return [
            'available' => false,
            'status' => -1,
            'message' => 'Invalid component type',
            'can_override' => false,
            'component_exists' => false
        ];
    }
    
    try {
        $table = $tableMap[$componentType];
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
        $stmt->execute([$componentUuid]);
        $component = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$component) {
            return [
                'available' => false,
                'status' => -1,
                'message' => 'Component not found',
                'can_override' => false,
                'component_exists' => false
            ];
        }
        
        $status = (int)$component['Status'];
        $result = [
            'component_exists' => true,
            'component_details' => $component,
            'status' => $status,
            'available' => false,
            'can_override' => false,
            'message' => ''
        ];
        
        switch ($status) {
            case 0:
                $result['message'] = 'Component is marked as Failed/Defective and cannot be used';
                $result['can_override'] = false;
                break;
            case 1:
                $result['available'] = true;
                $result['message'] = 'Component is Available for use';
                $result['can_override'] = false; // No need to override
                break;
            case 2:
                $result['message'] = 'Component is currently In Use in another configuration';
                $result['can_override'] = true; // Allow override for development/testing
                break;
            default:
                $result['message'] = "Component has unknown status: $status";
                $result['can_override'] = false;
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error checking component availability: " . $e->getMessage());
        return [
            'available' => false,
            'status' => -1,
            'message' => 'Database error occurred while checking component availability',
            'can_override' => false,
            'component_exists' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get alternative components when the requested one is not available
 */
function getAlternativeComponents($pdo, $componentType, $excludeUuid = null, $limit = 5) {
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
    
    try {
        $table = $tableMap[$componentType];
        $whereClause = "WHERE Status = 1"; // Only available components
        $params = [];
        
        if ($excludeUuid) {
            $whereClause .= " AND UUID != ?";
            $params[] = $excludeUuid;
        }
        
        $params[] = $limit;
        
        $stmt = $pdo->prepare("
            SELECT UUID, SerialNumber, Status, Notes 
            FROM $table 
            $whereClause 
            ORDER BY SerialNumber 
            LIMIT ?
        ");
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting alternative components: " . $e->getMessage());
        return [];
    }
}

/**
 * Enhanced component status update with validation
 */
function updateComponentStatusWithValidation($pdo, $componentType, $componentUuid, $newStatus, $reason = '', $userId = null) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return [
            'success' => false,
            'message' => 'Invalid component type'
        ];
    }
    
    try {
        $table = $tableMap[$componentType];
        
        // Get current component details
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
        $stmt->execute([$componentUuid]);
        $component = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$component) {
            return [
                'success' => false,
                'message' => 'Component not found'
            ];
        }
        
        $oldStatus = $component['Status'];
        
        // Validate status transition
        $validTransitions = [
            0 => [1], // Failed -> Available (after repair)
            1 => [0, 2], // Available -> Failed or In Use
            2 => [0, 1] // In Use -> Failed or Available
        ];
        
        if (!isset($validTransitions[$oldStatus]) || !in_array($newStatus, $validTransitions[$oldStatus])) {
            return [
                'success' => false,
                'message' => "Invalid status transition from $oldStatus to $newStatus"
            ];
        }
        
        // Update component status
        $stmt = $pdo->prepare("
            UPDATE $table 
            SET Status = ?, UpdatedAt = NOW() 
            WHERE UUID = ?
        ");
        $stmt->execute([$newStatus, $componentUuid]);
        
        // Log the status change
        try {
            $stmt = $pdo->prepare("
                INSERT INTO component_status_history 
                (component_type, component_uuid, old_status, new_status, reason, changed_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$componentType, $componentUuid, $oldStatus, $newStatus, $reason, $userId]);
        } catch (Exception $e) {
            // History table might not exist, that's okay
            error_log("Could not log status change: " . $e->getMessage());
        }
        
        return [
            'success' => true,
            'message' => 'Component status updated successfully',
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ];
        
    } catch (Exception $e) {
        error_log("Error updating component status: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Database error occurred while updating component status'
        ];
    }
}

/**
 * Get component usage information
 */
function getComponentUsageInfo($pdo, $componentType, $componentUuid) {
    try {
        // Check if component is used in any server configurations
        $stmt = $pdo->prepare("
            SELECT sc.config_uuid, sc.server_name, sc.configuration_status, scc.added_at
            FROM server_configuration_components scc
            JOIN server_configurations sc ON scc.config_uuid = sc.config_uuid
            WHERE scc.component_type = ? AND scc.component_uuid = ?
            ORDER BY scc.added_at DESC
        ");
        $stmt->execute([$componentType, $componentUuid]);
        $configurations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'is_in_use' => !empty($configurations),
            'configurations' => $configurations,
            'usage_count' => count($configurations)
        ];
        
    } catch (Exception $e) {
        error_log("Error getting component usage info: " . $e->getMessage());
        return [
            'is_in_use' => false,
            'configurations' => [],
            'usage_count' => 0,
            'error' => 'Could not retrieve usage information'
        ];
    }
}

/**
 * Validate component before adding to configuration
 */
function validateComponentForConfiguration($pdo, $componentType, $componentUuid, $configUuid = null) {
    $result = [
        'valid' => false,
        'issues' => [],
        'warnings' => [],
        'component_info' => null
    ];
    
    // Check component availability
    $availability = checkComponentAvailabilityDetailed($pdo, $componentType, $componentUuid);
    
    if (!$availability['component_exists']) {
        $result['issues'][] = 'Component does not exist';
        return $result;
    }
    
    $result['component_info'] = $availability['component_details'];
    
    if (!$availability['available']) {
        if ($availability['can_override']) {
            $result['warnings'][] = $availability['message'] . ' (can be overridden)';
        } else {
            $result['issues'][] = $availability['message'];
            return $result;
        }
    }
    
    // Check component usage
    $usage = getComponentUsageInfo($pdo, $componentType, $componentUuid);
    if ($usage['is_in_use'] && $configUuid) {
        // Check if it's used in the same configuration (which is okay)
        $usedInSameConfig = false;
        foreach ($usage['configurations'] as $config) {
            if ($config['config_uuid'] === $configUuid) {
                $usedInSameConfig = true;
                break;
            }
        }
        
        if (!$usedInSameConfig) {
            $result['warnings'][] = 'Component is currently used in other configurations';
        }
    }
    
    // Check component condition/specifications if available
    $component = $availability['component_details'];
    if (isset($component['Notes']) && !empty($component['Notes'])) {
        if (stripos($component['Notes'], 'defect') !== false || 
            stripos($component['Notes'], 'issue') !== false) {
            $result['warnings'][] = 'Component has notes indicating potential issues: ' . $component['Notes'];
        }
    }
    
    // If we get here with no critical issues, the component is valid
    if (empty($result['issues'])) {
        $result['valid'] = true;
    }
    
    return $result;
}

/**
 * Bulk update component status
 */
function bulkUpdateComponentStatus($pdo, $componentType, $componentUuids, $newStatus, $reason = '', $userId = null) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return [
            'success' => false,
            'message' => 'Invalid component type'
        ];
    }
    
    if (empty($componentUuids) || !is_array($componentUuids)) {
        return [
            'success' => false,
            'message' => 'No component UUIDs provided'
        ];
    }
    
    try {
        $table = $tableMap[$componentType];
        $updated = 0;
        $errors = [];
        
        $pdo->beginTransaction();
        
        foreach ($componentUuids as $uuid) {
            $result = updateComponentStatusWithValidation($pdo, $componentType, $uuid, $newStatus, $reason, $userId);
            if ($result['success']) {
                $updated++;
            } else {
                $errors[$uuid] = $result['message'];
            }
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => "Updated $updated components",
            'updated_count' => $updated,
            'total_count' => count($componentUuids),
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Error in bulk update: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Bulk update failed: ' . $e->getMessage()
        ];
    }
}

/**
 * Export components to CSV
 */
function exportComponentsToCSV($pdo, $componentType, $filters = []) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return false;
    }
    
    try {
        $table = $tableMap[$componentType];
        $whereConditions = [];
        $params = [];
        
        // Apply filters
        if (isset($filters['status']) && $filters['status'] !== '') {
            $whereConditions[] = "Status = ?";
            $params[] = $filters['status'];
        }
        
        if (isset($filters['search']) && !empty($filters['search'])) {
            $whereConditions[] = "(SerialNumber LIKE ? OR Location LIKE ? OR Notes LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = empty($whereConditions) ? "" : "WHERE " . implode(" AND ", $whereConditions);
        
        $stmt = $pdo->prepare("SELECT * FROM $table $whereClause ORDER BY SerialNumber");
        $stmt->execute($params);
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($components)) {
            return false;
        }
        
        // Generate CSV
        $filename = $componentType . '_export_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = sys_get_temp_dir() . '/' . $filename;
        
        $file = fopen($filepath, 'w');
        
        // Write header
        $headers = array_keys($components[0]);
        fputcsv($file, $headers);
        
        // Write data
        foreach ($components as $component) {
            // Add status text
            $component['StatusText'] = getStatusText($component['Status']);
            fputcsv($file, $component);
        }
        
        fclose($file);
        
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'record_count' => count($components)
        ];
        
    } catch (Exception $e) {
        error_log("Error exporting components: " . $e->getMessage());
        return false;
    }
}

/**
 * Import components from CSV
 */
function importComponentsFromCSV($pdo, $componentType, $csvFile, $options = []) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return [
            'success' => false,
            'message' => 'Invalid component type'
        ];
    }
    
    if (!file_exists($csvFile)) {
        return [
            'success' => false,
            'message' => 'CSV file not found'
        ];
    }
    
    try {
        $table = $tableMap[$componentType];
        $imported = 0;
        $skipped = 0;
        $errors = [];
        
        $file = fopen($csvFile, 'r');
        $headers = fgetcsv($file);
        
        if (!$headers) {
            return [
                'success' => false,
                'message' => 'Invalid CSV format'
            ];
        }
        
        $pdo->beginTransaction();
        
        while (($row = fgetcsv($file)) !== false) {
            $data = array_combine($headers, $row);
            
            // Validate required fields
            if (empty($data['SerialNumber'])) {
                $errors[] = "Row skipped: Missing SerialNumber";
                $skipped++;
                continue;
            }
            
            // Check for duplicates
            $checkStmt = $pdo->prepare("SELECT ID FROM $table WHERE SerialNumber = ?");
            $checkStmt->execute([$data['SerialNumber']]);
            if ($checkStmt->fetch()) {
                if ($options['skip_duplicates'] ?? true) {
                    $skipped++;
                    continue;
                } else {
                    $errors[] = "Duplicate SerialNumber: {$data['SerialNumber']}";
                    $skipped++;
                    continue;
                }
            }
            
            // Generate UUID if not provided
            if (empty($data['UUID'])) {
                $data['UUID'] = generateUUID();
            }
            
            // Set defaults
            $data['Status'] = $data['Status'] ?? 1;
            $data['CreatedAt'] = date('Y-m-d H:i:s');
            $data['UpdatedAt'] = date('Y-m-d H:i:s');
            $data['CreatedBy'] = $options['created_by'] ?? 'Import';
            $data['UpdatedBy'] = $options['updated_by'] ?? 'Import';
            
            // Build insert query
            $fields = array_keys($data);
            $placeholders = array_fill(0, count($data), '?');
            $values = array_values($data);
            
            $query = "INSERT INTO $table (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $pdo->prepare($query);
            
            if ($stmt->execute($values)) {
                $imported++;
            } else {
                $errors[] = "Failed to import: {$data['SerialNumber']}";
                $skipped++;
            }
        }
        
        fclose($file);
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => "Import completed: $imported imported, $skipped skipped",
            'imported_count' => $imported,
            'skipped_count' => $skipped,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        if (isset($file)) fclose($file);
        $pdo->rollback();
        error_log("Error importing components: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage()
        ];
    }
}

?>