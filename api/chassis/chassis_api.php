<?php
/**
 * Infrastructure Management System - Chassis API
 * File: api/chassis/chassis_api.php
 * 
 * CRUD operations for chassis inventory management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../../includes/config.php';
require_once '../../includes/BaseFunctions.php';
require_once '../../includes/models/ChassisManager.php';

$baseFunctions = new BaseFunctions($pdo);
$chassisManager = new ChassisManager();

// Authentication check
$authResult = $baseFunctions->authenticate();
if (!$authResult['authenticated']) {
    http_response_code(401);
    echo json_encode($authResult);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'chassis-list':
            echo handleChassisList($pdo, $baseFunctions);
            break;
            
        case 'chassis-add':
            echo handleChassisAdd($pdo, $baseFunctions, $chassisManager);
            break;
            
        case 'chassis-get':
            echo handleChassisGet($pdo, $baseFunctions, $chassisManager);
            break;
            
        case 'chassis-update':
            echo handleChassisUpdate($pdo, $baseFunctions);
            break;
            
        case 'chassis-delete':
            echo handleChassisDelete($pdo, $baseFunctions);
            break;
            
            
        case 'chassis-get-available-bays':
            echo handleGetAvailableBays($pdo, $baseFunctions, $chassisManager);
            break;
            
        case 'chassis-json-validate':
            echo handleJsonValidation($baseFunctions, $chassisManager);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'authenticated' => true,
                'message' => 'Invalid action specified',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'authenticated' => true,
        'message' => 'Server error: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * List all chassis inventory
 */
function handleChassisList($pdo, $baseFunctions) {
    if (!$baseFunctions->hasPermission('chassis.view')) {
        http_response_code(403);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Permission denied: chassis.view required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $stmt = $pdo->prepare("
        SELECT id, UUID, SerialNumber, Status, Location, RackPosition, 
               PurchaseDate, WarrantyEndDate, Flag, Notes, ServerUUID,
               CreatedAt, UpdatedAt
        FROM chassisinventory 
        ORDER BY CreatedAt DESC
    ");
    $stmt->execute();
    $chassis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add chassis specifications from JSON
    $chassisManager = new ChassisManager();
    foreach ($chassis as &$item) {
        $specs = $chassisManager->loadChassisSpecsByUUID($item['UUID']);
        if ($specs['found']) {
            $item['specifications'] = [
                'model' => $specs['specifications']['model'] ?? 'Unknown',
                'brand' => $specs['specifications']['brand'] ?? 'Unknown',
                'form_factor' => $specs['specifications']['form_factor'] ?? 'Unknown',
                'chassis_type' => $specs['specifications']['chassis_type'] ?? 'Unknown',
                'total_bays' => $specs['specifications']['drive_bays']['total_bays'] ?? 0
            ];
        } else {
            $item['specifications'] = ['error' => $specs['error']];
        }
    }
    
    return json_encode([
        'success' => true,
        'authenticated' => true,
        'message' => 'Chassis inventory retrieved successfully',
        'data' => $chassis,
        'count' => count($chassis),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Add new chassis to inventory
 */
function handleChassisAdd($pdo, $baseFunctions, $chassisManager) {
    if (!$baseFunctions->hasPermission('chassis.create')) {
        http_response_code(403);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Permission denied: chassis.create required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $uuid = $_POST['uuid'] ?? '';
    $serialNumber = $_POST['serial_number'] ?? '';
    $location = $_POST['location'] ?? null;
    $rackPosition = $_POST['rack_position'] ?? null;
    $purchaseDate = $_POST['purchase_date'] ?? null;
    $warrantyEndDate = $_POST['warranty_end_date'] ?? null;
    $notes = $_POST['notes'] ?? null;
    
    if (empty($uuid) || empty($serialNumber)) {
        http_response_code(400);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'UUID and Serial Number are required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    // Validate chassis UUID exists in JSON
    $validation = $chassisManager->validateChassisExists($uuid);
    if (!$validation['exists']) {
        http_response_code(400);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Invalid chassis UUID: ' . $validation['error'],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO chassisinventory (UUID, SerialNumber, Location, RackPosition, 
                                         PurchaseDate, WarrantyEndDate, Notes, Status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        
        $stmt->execute([
            $uuid, $serialNumber, $location, $rackPosition,
            $purchaseDate, $warrantyEndDate, $notes
        ]);
        
        $chassisId = $pdo->lastInsertId();
        
        // Get chassis specifications for response
        $specs = $chassisManager->loadChassisSpecsByUUID($uuid);
        
        return json_encode([
            'success' => true,
            'authenticated' => true,
            'message' => 'Chassis added successfully',
            'data' => [
                'id' => $chassisId,
                'uuid' => $uuid,
                'serial_number' => $serialNumber,
                'specifications' => $specs['found'] ? $specs['specifications'] : null
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { // Duplicate entry
            http_response_code(400);
            return json_encode([
                'success' => false,
                'authenticated' => true,
                'message' => 'Chassis with this UUID or Serial Number already exists',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        throw $e;
    }
}

/**
 * Get specific chassis details
 */
function handleChassisGet($pdo, $baseFunctions, $chassisManager) {
    if (!$baseFunctions->hasPermission('chassis.view')) {
        http_response_code(403);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Permission denied: chassis.view required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $uuid = $_GET['uuid'] ?? '';
    if (empty($uuid)) {
        http_response_code(400);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Chassis UUID is required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $stmt = $pdo->prepare("
        SELECT * FROM chassisinventory WHERE UUID = ?
    ");
    $stmt->execute([$uuid]);
    $chassis = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$chassis) {
        http_response_code(404);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Chassis not found',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    // Get detailed specifications from JSON
    $specs = $chassisManager->loadChassisSpecsByUUID($uuid);
    $bayConfig = $chassisManager->getBayConfiguration($uuid);
    
    $chassis['detailed_specifications'] = $specs['found'] ? $specs['specifications'] : null;
    $chassis['bay_configuration'] = $bayConfig['success'] ? $bayConfig : null;
    
    return json_encode([
        'success' => true,
        'authenticated' => true,
        'message' => 'Chassis details retrieved successfully',
        'data' => $chassis,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Update chassis inventory details
 */
function handleChassisUpdate($pdo, $baseFunctions) {
    if (!$baseFunctions->hasPermission('chassis.edit')) {
        http_response_code(403);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Permission denied: chassis.edit required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $uuid = $_POST['uuid'] ?? '';
    $serialNumber = $_POST['serial_number'] ?? '';
    $status = $_POST['status'] ?? null;
    $location = $_POST['location'] ?? null;
    $rackPosition = $_POST['rack_position'] ?? null;
    $purchaseDate = $_POST['purchase_date'] ?? null;
    $warrantyEndDate = $_POST['warranty_end_date'] ?? null;
    $notes = $_POST['notes'] ?? null;
    
    if (empty($uuid)) {
        http_response_code(400);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Chassis UUID is required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE chassisinventory 
            SET SerialNumber = ?, Status = ?, Location = ?, RackPosition = ?,
                PurchaseDate = ?, WarrantyEndDate = ?, Notes = ?, UpdatedAt = NOW()
            WHERE UUID = ?
        ");
        
        $stmt->execute([
            $serialNumber, $status, $location, $rackPosition,
            $purchaseDate, $warrantyEndDate, $notes, $uuid
        ]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            return json_encode([
                'success' => false,
                'authenticated' => true,
                'message' => 'Chassis not found',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        
        return json_encode([
            'success' => true,
            'authenticated' => true,
            'message' => 'Chassis updated successfully',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(400);
            return json_encode([
                'success' => false,
                'authenticated' => true,
                'message' => 'Serial number already exists',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        throw $e;
    }
}

/**
 * Delete chassis from inventory
 */
function handleChassisDelete($pdo, $baseFunctions) {
    if (!$baseFunctions->hasPermission('chassis.delete')) {
        http_response_code(403);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Permission denied: chassis.delete required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $uuid = $_POST['uuid'] ?? '';
    if (empty($uuid)) {
        http_response_code(400);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Chassis UUID is required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    // Check for active assignments
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM storage_chassis_mapping WHERE chassis_uuid = ?");
    $stmt->execute([$uuid]);
    $activeAssignments = $stmt->fetchColumn();
    
    if ($activeAssignments > 0) {
        http_response_code(400);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => "Cannot delete chassis: {$activeAssignments} active storage assignments found",
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $stmt = $pdo->prepare("DELETE FROM chassisinventory WHERE UUID = ?");
    $stmt->execute([$uuid]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Chassis not found',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    return json_encode([
        'success' => true,
        'authenticated' => true,
        'message' => 'Chassis deleted successfully',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}



/**
 * Get available bays for chassis
 */
function handleGetAvailableBays($pdo, $baseFunctions, $chassisManager) {
    if (!$baseFunctions->hasPermission('chassis.view')) {
        http_response_code(403);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Permission denied: chassis.view required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $chassisUUID = $_GET['chassis_uuid'] ?? '';
    $configUUID = $_GET['config_uuid'] ?? null;
    
    if (empty($chassisUUID)) {
        http_response_code(400);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Chassis UUID is required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $result = $chassisManager->getAvailableBays($chassisUUID, $configUUID, $pdo);
    
    return json_encode([
        'success' => $result['success'],
        'authenticated' => true,
        'message' => $result['success'] ? 'Available bays retrieved successfully' : $result['error'],
        'data' => $result,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Validate chassis JSON structure
 */
function handleJsonValidation($baseFunctions, $chassisManager) {
    if (!$baseFunctions->hasPermission('system.maintenance')) {
        http_response_code(403);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Permission denied: system.maintenance required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $result = $chassisManager->validateJsonStructure();
    
    return json_encode([
        'success' => $result['valid'],
        'authenticated' => true,
        'message' => $result['valid'] ? 'Chassis JSON structure is valid' : 'Chassis JSON structure has issues',
        'data' => $result,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>