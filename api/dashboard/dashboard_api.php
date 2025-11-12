<?php
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: *');

session_start();

// Check if user is logged in
if (!isUserLoggedIn($pdo)) {
    send_json_response(0, 0, 401, "Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response(1, 0, 405, "Method Not Allowed");
}

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$componentType = isset($_GET['component']) ? $_GET['component'] : 'all';

// Table mapping
$tableMap = [
    'cpu' => 'cpuinventory',
    'ram' => 'raminventory',
    'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory',
    'nic' => 'nicinventory',
    'caddy' => 'caddyinventory'
];

// Function to get component counts
function getComponentCounts($pdo, $statusFilter = null) {
    $counts = [
        'cpu' => 0,
        'ram' => 0,
        'storage' => 0,
        'motherboard' => 0,
        'nic' => 0,
        'caddy' => 0,
        'total' => 0
    ];
    
    $tables = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    foreach ($tables as $key => $table) {
        try {
            $query = "SELECT COUNT(*) as count FROM $table";
            if ($statusFilter !== null && $statusFilter !== 'all') {
                $query .= " WHERE Status = :status";
            }
            
            $stmt = $pdo->prepare($query);
            if ($statusFilter !== null && $statusFilter !== 'all') {
                $stmt->bindParam(':status', $statusFilter, PDO::PARAM_INT);
            }
            $stmt->execute();
            $result = $stmt->fetch();
            $counts[$key] = (int)$result['count'];
            $counts['total'] += (int)$result['count'];
        } catch (PDOException $e) {
            error_log("Error counting $key: " . $e->getMessage());
        }
    }
    
    return $counts;
}

// Function to get recent activity
function getRecentActivity($pdo, $limit = 10) {
    try {
        $activities = [];
        $tables = [
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        foreach ($tables as $type => $table) {
            $stmt = $pdo->prepare("
                SELECT 
                    ID, 
                    SerialNumber, 
                    Status, 
                    UpdatedAt,
                    '$type' as component_type
                FROM $table 
                ORDER BY UpdatedAt DESC 
                LIMIT $limit
            ");
            $stmt->execute();
            $results = $stmt->fetchAll();
            
            foreach ($results as $result) {
                $activities[] = [
                    'id' => $result['ID'],
                    'component_type' => $result['component_type'],
                    'serial_number' => $result['SerialNumber'],
                    'status' => $result['Status'],
                    'updated_at' => $result['UpdatedAt'],
                    'action' => 'Updated'
                ];
            }
        }
        
        // Sort by updated_at desc and limit
        usort($activities, function($a, $b) {
            return strtotime($b['updated_at']) - strtotime($a['updated_at']);
        });
        
        return array_slice($activities, 0, $limit);
        
    } catch (PDOException $e) {
        error_log("Error getting recent activity: " . $e->getMessage());
        return [];
    }
}

// Function to get warranty alerts
function getWarrantyAlerts($pdo, $days = 90) {
    try {
        $alerts = [];
        $tables = [
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        $alertDate = date('Y-m-d', strtotime("+$days days"));
        
        foreach ($tables as $type => $table) {
            $stmt = $pdo->prepare("
                SELECT 
                    ID, 
                    SerialNumber, 
                    WarrantyEndDate,
                    '$type' as component_type
                FROM $table 
                WHERE WarrantyEndDate IS NOT NULL 
                AND WarrantyEndDate <= :alert_date
                AND Status != 0
                ORDER BY WarrantyEndDate ASC
            ");
            $stmt->bindParam(':alert_date', $alertDate);
            $stmt->execute();
            $results = $stmt->fetchAll();
            
            foreach ($results as $result) {
                $daysUntilExpiry = floor((strtotime($result['WarrantyEndDate']) - time()) / (60 * 60 * 24));
                $alerts[] = [
                    'id' => $result['ID'],
                    'component_type' => $result['component_type'],
                    'serial_number' => $result['SerialNumber'],
                    'warranty_end_date' => $result['WarrantyEndDate'],
                    'days_until_expiry' => $daysUntilExpiry,
                    'severity' => $daysUntilExpiry <= 30 ? 'high' : ($daysUntilExpiry <= 60 ? 'medium' : 'low')
                ];
            }
        }
        
        return $alerts;
        
    } catch (PDOException $e) {
        error_log("Error getting warranty alerts: " . $e->getMessage());
        return [];
    }
}

try {
    // Get counts for current filter
    $componentCounts = getComponentCounts($pdo, $statusFilter);
    
    // Get counts by status for overview
    $statusCounts = [
        'available' => getComponentCounts($pdo, '1')['total'],
        'in_use' => getComponentCounts($pdo, '2')['total'],
        'failed' => getComponentCounts($pdo, '0')['total'],
        'total' => getComponentCounts($pdo, 'all')['total']
    ];
    
    // Get recent activity
    $recentActivity = getRecentActivity($pdo, 10);
    
    // Get warranty alerts
    $warrantyAlerts = getWarrantyAlerts($pdo, 90);
    
    // Get user info
    $userInfo = [
        'id' => $_SESSION['id'],
        'username' => $_SESSION['username'],
        'email' => $_SESSION['email']
    ];
    
    $responseData = [
        'user_info' => $userInfo,
        'status_counts' => $statusCounts,
        'component_counts' => $componentCounts,
        'recent_activity' => $recentActivity,
        'warranty_alerts' => $warrantyAlerts,
        'filters' => [
            'current_status' => $statusFilter,
            'current_component' => $componentType
        ]
    ];
    
    send_json_response(1, 1, 200, "Dashboard data retrieved successfully", $responseData);
    
} catch (Exception $e) {
    error_log("Dashboard API error: " . $e->getMessage());
    send_json_response(1, 0, 500, "Internal server error");
}
?>