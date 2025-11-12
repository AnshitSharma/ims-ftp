<?php
/**
 * Safe Chassis Support Functions
 * Only enables chassis functionality if table exists
 */

/**
 * Check if chassis support is available
 */
function isChassisSupported() {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'chassisinventory'");
        $stmt->execute();
        return (bool) $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error checking chassis support: " . $e->getMessage());
        return false;
    }
}

/**
 * Get component table map with conditional chassis support
 */
function getComponentTableMap() {
    $baseMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];

    // Add chassis support only if table exists
    if (isChassisSupported()) {
        $baseMap['chassis'] = 'chassisinventory';
    }

    return $baseMap;
}

/**
 * Get valid component types with conditional chassis support
 */
function getValidComponentTypes() {
    $baseTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];

    // Add chassis support only if table exists
    if (isChassisSupported()) {
        array_unshift($baseTypes, 'chassis'); // Add chassis at the beginning
    }

    return $baseTypes;
}

/**
 * Safe component details retrieval with chassis support
 */
function safeGetComponentDetails($pdo, $componentType, $componentUuid) {
    $tableMap = getComponentTableMap();

    if (!isset($tableMap[$componentType])) {
        return null;
    }

    $table = $tableMap[$componentType];

    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
        $stmt->execute([$componentUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting component details for $componentType: " . $e->getMessage());
        return null;
    }
}
?>