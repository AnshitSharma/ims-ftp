<?php
/**
 * NIC Port Tracker
 *
 * Stateless calculator for tracking NIC port assignments and SFP compatibility.
 * Similar pattern to UnifiedSlotTracker - reads from database JSON on-demand.
 *
 * Data Source: server_configurations.sfp_configuration JSON column
 * Purpose: Track which SFPs are assigned to which NIC ports
 */

require_once(__DIR__ . '/ComponentDataService.php');

class NICPortTracker {
    private $pdo;
    private $componentDataService;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->componentDataService = ComponentDataService::getInstance();
    }

    /**
     * Get port availability for a specific NIC
     *
     * @param string $configUuid Server configuration UUID
     * @param string $nicUuid NIC component UUID
     * @return array Port status with occupied/available info
     */
    public function getPortAvailability($configUuid, $nicUuid) {
        // Get NIC specifications to determine total ports and port type
        $nicSpecs = $this->componentDataService->getComponentSpecs('nic', $nicUuid);

        if (!$nicSpecs || !isset($nicSpecs['ports']) || !isset($nicSpecs['port_type'])) {
            return [
                'error' => 'Unable to load NIC specifications',
                'total_ports' => 0,
                'port_type' => 'unknown',
                'ports' => []
            ];
        }

        $totalPorts = (int)$nicSpecs['ports'];
        $portType = $nicSpecs['port_type'];

        // Initialize all ports as available
        $ports = [];
        for ($i = 1; $i <= $totalPorts; $i++) {
            $ports[$i] = [
                'port_index' => $i,
                'occupied' => false,
                'sfp_uuid' => null,
                'sfp_model' => null
            ];
        }

        // Get current SFP assignments from sfp_configuration JSON
        $assignments = $this->getSfpAssignments($configUuid);

        // Mark occupied ports
        foreach ($assignments as $assignment) {
            if ($assignment['parent_nic_uuid'] === $nicUuid) {
                $portIndex = (int)$assignment['port_index'];
                if ($portIndex >= 1 && $portIndex <= $totalPorts) {
                    $ports[$portIndex]['occupied'] = true;
                    $ports[$portIndex]['sfp_uuid'] = $assignment['uuid'];
                    $ports[$portIndex]['sfp_model'] = $assignment['sfp_model'] ?? null;
                }
            }
        }

        // Calculate utilization
        $occupied = 0;
        foreach ($ports as $port) {
            if ($port['occupied']) {
                $occupied++;
            }
        }

        return [
            'total_ports' => $totalPorts,
            'port_type' => $portType,
            'ports' => array_values($ports), // Convert to indexed array
            'utilization' => [
                'occupied' => $occupied,
                'available' => $totalPorts - $occupied
            ]
        ];
    }

    /**
     * Get port utilization for all NICs in a configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Port tracking data for all NICs
     */
    public function getPortUtilizationForConfig($configUuid) {
        // Get NIC configuration from server_configurations
        $stmt = $this->pdo->prepare("
            SELECT nic_config
            FROM server_configurations
            WHERE uuid = ?
        ");
        $stmt->execute([$configUuid]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config || empty($config['nic_config'])) {
            return ['nics' => []];
        }

        $nicConfig = json_decode($config['nic_config'], true);
        if (!$nicConfig || !isset($nicConfig['nics'])) {
            return ['nics' => []];
        }

        $result = ['nics' => []];

        foreach ($nicConfig['nics'] as $nic) {
            $nicUuid = $nic['uuid'];
            $sourceType = $nic['source_type'] ?? 'component';

            // Get NIC specs
            $nicSpecs = $this->componentDataService->getComponentSpecs('nic', $nicUuid);

            if (!$nicSpecs) {
                continue; // Skip if specs not found
            }

            // Get port availability for this NIC
            $portInfo = $this->getPortAvailability($configUuid, $nicUuid);

            $result['nics'][] = [
                'uuid' => $nicUuid,
                'source_type' => $sourceType,
                'model' => $nicSpecs['model'] ?? 'Unknown',
                'total_ports' => $portInfo['total_ports'],
                'port_type' => $portInfo['port_type'],
                'ports' => $portInfo['ports'],
                'utilization' => $portInfo['utilization']
            ];
        }

        return $result;
    }

    /**
     * Check if a specific port on a NIC is available
     *
     * @param string $configUuid Server configuration UUID
     * @param string $nicUuid NIC component UUID
     * @param int $portIndex Port number (1-based)
     * @return bool True if port is available
     */
    public function isPortAvailable($configUuid, $nicUuid, $portIndex) {
        $portInfo = $this->getPortAvailability($configUuid, $nicUuid);

        foreach ($portInfo['ports'] as $port) {
            if ($port['port_index'] == $portIndex) {
                return !$port['occupied'];
            }
        }

        return false; // Port index out of range
    }

    /**
     * Get all SFP assignments from sfp_configuration JSON
     *
     * @param string $configUuid Server configuration UUID
     * @return array List of SFP assignments
     */
    private function getSfpAssignments($configUuid) {
        $stmt = $this->pdo->prepare("
            SELECT sfp_configuration
            FROM server_configurations
            WHERE uuid = ?
        ");
        $stmt->execute([$configUuid]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config || empty($config['sfp_configuration'])) {
            return [];
        }

        $sfpConfig = json_decode($config['sfp_configuration'], true);

        if (!$sfpConfig || !isset($sfpConfig['sfps'])) {
            return [];
        }

        // Enrich with SFP model info
        $assignments = [];
        foreach ($sfpConfig['sfps'] as $sfp) {
            $sfpSpecs = $this->componentDataService->getComponentSpecs('sfp', $sfp['uuid']);
            $sfp['sfp_model'] = $sfpSpecs['model'] ?? null;
            $assignments[] = $sfp;
        }

        return $assignments;
    }

    /**
     * Check if an SFP type is compatible with a NIC port type
     *
     * Compatibility Matrix:
     * - SFP+ port: Accepts SFP+ only
     * - SFP28 port: Accepts SFP28 and SFP+ (backward compatible)
     * - QSFP+ port: Accepts QSFP+ only
     * - QSFP28 port: Accepts QSFP28 and QSFP+ (backward compatible)
     * - QSFP56 port: Accepts QSFP56, QSFP28, QSFP+ (backward compatible)
     * - RJ45 port: No SFP compatibility
     *
     * @param string $nicPortType NIC port type (e.g., "SFP+", "SFP28")
     * @param string $sfpType SFP module type (e.g., "SFP+", "SFP28")
     * @return bool True if compatible
     */
    public static function isCompatible($nicPortType, $sfpType) {
        // Normalize to uppercase for comparison
        $nicPortType = strtoupper(trim($nicPortType));
        $sfpType = strtoupper(trim($sfpType));

        // RJ45 ports don't accept SFPs
        if (strpos($nicPortType, 'RJ45') !== false || strpos($nicPortType, 'RJ-45') !== false) {
            return false;
        }

        // Exact match is always compatible
        if ($nicPortType === $sfpType) {
            return true;
        }

        // SFP28 ports accept SFP+ modules (backward compatibility)
        if ($nicPortType === 'SFP28' && $sfpType === 'SFP+') {
            return true;
        }

        // SFP28 ports accept SFP+ DAC
        if ($nicPortType === 'SFP28' && $sfpType === 'SFP+ DAC') {
            return true;
        }

        // QSFP28 ports accept QSFP+ modules (backward compatibility)
        if ($nicPortType === 'QSFP28' && $sfpType === 'QSFP+') {
            return true;
        }

        // QSFP56 ports accept QSFP28 and QSFP+ modules (backward compatibility)
        if ($nicPortType === 'QSFP56' && ($sfpType === 'QSFP28' || $sfpType === 'QSFP+')) {
            return true;
        }

        // OSFP ports (future-proofing)
        if ($nicPortType === 'OSFP' && in_array($sfpType, ['OSFP', 'QSFP56', 'QSFP28'])) {
            return true;
        }

        // No other combinations are compatible
        return false;
    }

    /**
     * Get all compatible SFP types for a given NIC port type
     *
     * @param string $nicPortType NIC port type
     * @return array List of compatible SFP types
     */
    public static function getCompatibleSfpTypes($nicPortType) {
        $nicPortType = strtoupper(trim($nicPortType));

        $compatibilityMap = [
            'SFP+' => ['SFP+', 'SFP+ DAC'],
            'SFP28' => ['SFP28', 'SFP+', 'SFP+ DAC'],
            'QSFP+' => ['QSFP+'],
            'QSFP28' => ['QSFP28', 'QSFP+'],
            'QSFP56' => ['QSFP56', 'QSFP28', 'QSFP+'],
            'OSFP' => ['OSFP', 'QSFP56', 'QSFP28'],
            'RJ45' => [],
            'RJ-45' => []
        ];

        return $compatibilityMap[$nicPortType] ?? [];
    }

    /**
     * Validate speed compatibility between NIC and SFP
     * SFP speed must be <= NIC max speed (allows downshift, blocks upshift)
     *
     * @param string $nicMaxSpeed NIC maximum speed (e.g., "25GbE", "10Gbps")
     * @param string $sfpSpeed SFP speed (e.g., "10Gbps", "25GbE")
     * @return bool True if compatible (SFP speed <= NIC speed)
     */
    public static function validateSpeedCompatibility($nicMaxSpeed, $sfpSpeed) {
        $nicSpeedValue = self::extractSpeedValue($nicMaxSpeed);
        $sfpSpeedValue = self::extractSpeedValue($sfpSpeed);

        // Allow downshift: 25G NIC can accept 10G SFP
        // Block upshift: 10G NIC cannot accept 25G SFP
        return $sfpSpeedValue <= $nicSpeedValue;
    }

    /**
     * Extract numeric speed value from speed string
     *
     * @param string $speedStr Speed string (e.g., "10GbE", "25Gbps", "100G")
     * @return int Speed value in Gbps
     */
    private static function extractSpeedValue($speedStr) {
        $speedStr = strtoupper(trim($speedStr));

        // Extract numeric value
        if (preg_match('/(\d+(?:\.\d+)?)/', $speedStr, $matches)) {
            $value = floatval($matches[1]);

            // Handle Mbps (convert to Gbps)
            if (strpos($speedStr, 'M') !== false) {
                $value = $value / 1000;
            }

            return (int)$value;
        }

        return 0;
    }

}
