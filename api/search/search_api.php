<?php
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: *');

session_start();

// Check if user is logged in
if (!isUserLoggedIn($pdo)) {
    send_json_response(0, 0, 401, "Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json_response(1, 0, 405, "Method Not Allowed");
}

$query = $_GET['q'] ?? '';
$componentType = $_GET['type'] ?? 'all';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

if (empty($query)) {
    send_json_response(1, 0, 400, "Search query is required");
}

// Table mapping
$tableMap = [
    'chassis' => 'chassisinventory',
    'cpu' => 'cpuinventory',
    'ram' => 'raminventory',
    'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory',
    'nic' => 'nicinventory',
    'caddy' => 'caddyinventory'
];

try {
    $results = [];
    $searchTables = [];
    
    if ($componentType === 'all') {
        $searchTables = $tableMap;
    } elseif (isset($tableMap[$componentType])) {
        $searchTables = [$componentType => $tableMap[$componentType]];
    } else {
        send_json_response(1, 0, 400, "Invalid component type");
    }
    
    foreach ($searchTables as $type => $table) {
        $searchQuery = "
            SELECT 
                ID,
                UUID,
                SerialNumber,
                Status,
                ServerUUID,
                Location,
                RackPosition,
                PurchaseDate,
                WarrantyEndDate,
                Flag,
                Notes,
                CreatedAt,
                UpdatedAt,
                '$type' as component_type
            FROM $table 
            WHERE 
                SerialNumber LIKE :query 
                OR UUID LIKE :query 
                OR Location LIKE :query 
                OR RackPosition LIKE :query 
                OR Flag LIKE :query 
                OR Notes LIKE :query
        ";
        
        // Add NIC-specific search fields
        if ($type === 'nic') {
            $searchQuery = "
                SELECT 
                    ID,
                    UUID,
                    SerialNumber,
                    Status,
                    ServerUUID,
                    Location,
                    RackPosition,
                    MacAddress,
                    IPAddress,
                    NetworkName,
                    PurchaseDate,
                    WarrantyEndDate,
                    Flag,
                    Notes,
                    CreatedAt,
                    UpdatedAt,
                    '$type' as component_type
                FROM $table 
                WHERE 
                    SerialNumber LIKE :query 
                    OR UUID LIKE :query 
                    OR Location LIKE :query 
                    OR RackPosition LIKE :query 
                    OR Flag LIKE :query 
                    OR Notes LIKE :query
                    OR MacAddress LIKE :query
                    OR IPAddress LIKE :query
                    OR NetworkName LIKE :query
            ";
        }
        
        $searchQuery .= " ORDER BY CreatedAt DESC LIMIT :limit";
        
        $stmt = $pdo->prepare($searchQuery);
        $searchTerm = '%' . $query . '%';
        $stmt->bindParam(':query', $searchTerm);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $componentResults = $stmt->fetchAll();
        $results = array_merge($results, $componentResults);
    }
    
    // Sort results by relevance (exact matches first, then by date)
    usort($results, function($a, $b) use ($query) {
        $aExact = (stripos($a['SerialNumber'], $query) !== false) ? 1 : 0;
        $bExact = (stripos($b['SerialNumber'], $query) !== false) ? 1 : 0;
        
        if ($aExact !== $bExact) {
            return $bExact - $aExact; // Exact matches first
        }
        
        return strtotime($b['UpdatedAt']) - strtotime($a['UpdatedAt']); // Then by date
    });
    
    // Limit total results
    $results = array_slice($results, 0, $limit);
    
    send_json_response(1, 1, 200, "Search completed successfully", [
        'results' => $results,
        'total_found' => count($results),
        'query' => $query,
        'component_type' => $componentType
    ]);
    
} catch (PDOException $e) {
    error_log("Search API error: " . $e->getMessage());
    send_json_response(1, 0, 500, "Database error occurred");
} catch (Exception $e) {
    error_log("Search API error: " . $e->getMessage());
    send_json_response(1, 0, 500, "Internal server error");
}
?>