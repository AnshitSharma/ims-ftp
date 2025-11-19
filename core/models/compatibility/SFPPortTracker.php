<?php
/**
 * SFPPortTracker
 *
 * Tracks SFP module assignments to NIC card ports
 * Prevents double-assignment of SFP modules to the same port
 * Similar pattern to PCIeSlotTracker for PCIe cards
 *
 * @package BDC_IMS
 * @subpackage Models
 */

class SFPPortTracker {
    /**
     * @var array Map of NIC UUID => [port_index => sfp_uuid]
     */
    private $nicPortAssignments = [];

    /**
     * @var array Map of NIC UUID => total port count
     */
    private $nicPortCounts = [];

    /**
     * Track an SFP module assignment to a specific NIC port
     *
     * @param string $nicUuid UUID of the parent NIC card
     * @param int $portIndex Port number on the NIC (1-based)
     * @param string $sfpUuid UUID of the SFP module being assigned
     * @return void
     */
    public function trackSFPAssignment($nicUuid, $portIndex, $sfpUuid) {
        if (!isset($this->nicPortAssignments[$nicUuid])) {
            $this->nicPortAssignments[$nicUuid] = [];
        }
        $this->nicPortAssignments[$nicUuid][$portIndex] = $sfpUuid;
        error_log("SFPPortTracker: Assigned SFP $sfpUuid to NIC $nicUuid port $portIndex");
    }

    /**
     * Set the total port count for a NIC card
     *
     * @param string $nicUuid UUID of the NIC card
     * @param int $portCount Total number of ports on the NIC
     * @return void
     */
    public function setNICPortCount($nicUuid, $portCount) {
        $this->nicPortCounts[$nicUuid] = $portCount;
        error_log("SFPPortTracker: Set NIC $nicUuid port count to $portCount");
    }

    /**
     * Check if a specific port on a NIC is already occupied
     *
     * @param string $nicUuid UUID of the NIC card
     * @param int $portIndex Port number to check
     * @return bool True if port is occupied, false otherwise
     */
    public function isPortOccupied($nicUuid, $portIndex) {
        $occupied = isset($this->nicPortAssignments[$nicUuid][$portIndex]);
        error_log("SFPPortTracker: Port $portIndex on NIC $nicUuid is " . ($occupied ? "occupied" : "available"));
        return $occupied;
    }

    /**
     * Get the SFP UUID assigned to a specific port
     *
     * @param string $nicUuid UUID of the NIC card
     * @param int $portIndex Port number
     * @return string|null UUID of assigned SFP or null if port is free
     */
    public function getAssignedSFP($nicUuid, $portIndex) {
        return $this->nicPortAssignments[$nicUuid][$portIndex] ?? null;
    }

    /**
     * Get all occupied ports on a specific NIC
     *
     * @param string $nicUuid UUID of the NIC card
     * @return array Map of port_index => sfp_uuid for occupied ports
     */
    public function getOccupiedPorts($nicUuid) {
        return $this->nicPortAssignments[$nicUuid] ?? [];
    }

    /**
     * Get all available (unoccupied) port numbers on a NIC
     *
     * @param string $nicUuid UUID of the NIC card
     * @param int $totalPorts Total number of ports on the NIC
     * @return array List of available port numbers
     */
    public function getAvailablePorts($nicUuid, $totalPorts) {
        $occupiedPorts = $this->getOccupiedPorts($nicUuid);
        $availablePorts = [];

        for ($i = 1; $i <= $totalPorts; $i++) {
            if (!isset($occupiedPorts[$i])) {
                $availablePorts[] = $i;
            }
        }

        error_log("SFPPortTracker: NIC $nicUuid has " . count($availablePorts) . " available ports out of $totalPorts");
        return $availablePorts;
    }

    /**
     * Get the first available port number on a NIC
     *
     * @param string $nicUuid UUID of the NIC card
     * @param int $totalPorts Total number of ports on the NIC
     * @return int|null First available port number or null if all ports occupied
     */
    public function getAvailablePort($nicUuid, $totalPorts) {
        $availablePorts = $this->getAvailablePorts($nicUuid, $totalPorts);
        return !empty($availablePorts) ? $availablePorts[0] : null;
    }

    /**
     * Check if a NIC has any available ports
     *
     * @param string $nicUuid UUID of the NIC card
     * @param int $totalPorts Total number of ports on the NIC
     * @return bool True if at least one port is available
     */
    public function hasAvailablePorts($nicUuid, $totalPorts) {
        $occupiedCount = count($this->getOccupiedPorts($nicUuid));
        return $occupiedCount < $totalPorts;
    }

    /**
     * Get count of occupied ports on a NIC
     *
     * @param string $nicUuid UUID of the NIC card
     * @return int Number of occupied ports
     */
    public function getOccupiedPortCount($nicUuid) {
        return count($this->getOccupiedPorts($nicUuid));
    }

    /**
     * Remove SFP assignment from a port
     *
     * @param string $nicUuid UUID of the NIC card
     * @param int $portIndex Port number to free
     * @return void
     */
    public function removeSFPAssignment($nicUuid, $portIndex) {
        if (isset($this->nicPortAssignments[$nicUuid][$portIndex])) {
            $sfpUuid = $this->nicPortAssignments[$nicUuid][$portIndex];
            unset($this->nicPortAssignments[$nicUuid][$portIndex]);
            error_log("SFPPortTracker: Removed SFP $sfpUuid from NIC $nicUuid port $portIndex");
        }
    }

    /**
     * Remove all SFP assignments for a specific NIC
     *
     * @param string $nicUuid UUID of the NIC card
     * @return void
     */
    public function clearNICAssignments($nicUuid) {
        if (isset($this->nicPortAssignments[$nicUuid])) {
            unset($this->nicPortAssignments[$nicUuid]);
            error_log("SFPPortTracker: Cleared all SFP assignments for NIC $nicUuid");
        }
    }

    /**
     * Get port utilization statistics for a NIC
     *
     * @param string $nicUuid UUID of the NIC card
     * @param int $totalPorts Total number of ports on the NIC
     * @return array Statistics with occupied, available, and percentage
     */
    public function getPortUtilization($nicUuid, $totalPorts) {
        $occupied = $this->getOccupiedPortCount($nicUuid);
        $available = $totalPorts - $occupied;
        $percentage = $totalPorts > 0 ? round(($occupied / $totalPorts) * 100, 2) : 0;

        return [
            'nic_uuid' => $nicUuid,
            'total_ports' => $totalPorts,
            'occupied_ports' => $occupied,
            'available_ports' => $available,
            'utilization_percentage' => $percentage
        ];
    }

    /**
     * Get all NIC cards that have SFP assignments
     *
     * @return array List of NIC UUIDs with port assignments
     */
    public function getNICsWithAssignments() {
        return array_keys($this->nicPortAssignments);
    }

    /**
     * Get complete tracking state (for debugging)
     *
     * @return array All port assignments and port counts
     */
    public function getTrackingState() {
        return [
            'port_assignments' => $this->nicPortAssignments,
            'port_counts' => $this->nicPortCounts
        ];
    }

    /**
     * Validate that a port assignment is valid
     *
     * @param string $nicUuid UUID of the NIC card
     * @param int $portIndex Port number to validate
     * @param int $totalPorts Total ports on the NIC
     * @return array Validation result with 'valid' and 'message'
     */
    public function validatePortAssignment($nicUuid, $portIndex, $totalPorts) {
        // Check if port index is within valid range
        if ($portIndex < 1 || $portIndex > $totalPorts) {
            return [
                'valid' => false,
                'message' => "Port index $portIndex is out of range (1-$totalPorts) for NIC $nicUuid"
            ];
        }

        // Check if port is already occupied
        if ($this->isPortOccupied($nicUuid, $portIndex)) {
            $existingSFP = $this->getAssignedSFP($nicUuid, $portIndex);
            return [
                'valid' => false,
                'message' => "Port $portIndex on NIC $nicUuid is already occupied by SFP $existingSFP"
            ];
        }

        return [
            'valid' => true,
            'message' => "Port $portIndex on NIC $nicUuid is available"
        ];
    }
}
?>
