<?php

/**
 * NIC Validator
 *
 * Validates network interface card compatibility.
 *
 * Priority: 15 (Low - NIC specific validation)
 * Dependencies: PCIeCardValidator
 *
 * Phase 3 Part 2 - File 32
 */

require_once __DIR__ . '/BaseValidator.php';

class NICValidator extends BaseValidator {

    const PRIORITY = 15;

    public function getName(): string {
        return 'NIC Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates network interface card compatibility';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->countComponents('nic') > 0;
    }

    /**
     * Validate NIC specifications
     *
     * LOGIC:
     * 1. Validate each NIC specs
     * 2. Check network speed support
     * 3. Validate PCIe generation support
     * 4. Check port count
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $nics = $context->getComponents('nic');

            if (empty($nics)) {
                return $result;
            }

            // Validate each NIC
            foreach ($nics as $index => $nic) {
                $this->validateNICCard($nic, $index, $context, $result);
            }

            // Validate NIC configuration consistency
            $this->validateNICConfiguration($nics, $context, $result);

        } catch (\Exception $e) {
            $result->addError('NIC validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate individual NIC card
     */
    private function validateNICCard(array $nic, int $index, ValidationContext $context, ValidationResult $result): void {
        // Validate required fields
        if (empty($nic['model'])) {
            $result->addWarning("NIC {$index}: model not specified");
        }

        // Validate network speed
        $speed = $nic['speed_gbps'] ?? null;
        if (!$speed) {
            $result->addError("NIC {$index}: network speed not specified");
            return;
        }

        if ($speed <= 0) {
            $result->addError("NIC {$index}: invalid speed ({$speed}Gbps)");
        }

        // Validate port count
        $portCount = $nic['port_count'] ?? 1;
        if ($portCount < 1) {
            $result->addError("NIC {$index}: must have at least 1 port");
        }

        // Validate PCIe lane requirements
        $lanes = $nic['pcie_lanes'] ?? 4;
        $this->validatePCIeLanes($nic, $index, $context, $result);

        // Check for specific NIC features
        $this->validateNICFeatures($nic, $index, $result);

        $result->addInfo("NIC {$index}: {$portCount}× {$speed}Gbps (" . ($lanes ?? 4) . " lanes)");
    }

    /**
     * Validate PCIe lane requirements
     */
    private function validatePCIeLanes(array $nic, int $index, ValidationContext $context, ValidationResult $result): void {
        $speed = $nic['speed_gbps'] ?? 0;
        $lanes = $nic['pcie_lanes'] ?? 4;

        // Lane requirement based on speed
        // Typical: 1GbE = 1 lane, 10GbE = 4 lanes, 25GbE = 8 lanes, 40GbE+ = 16 lanes
        $recommendedLanes = $this->getRecommendedLanes($speed);

        if ($lanes < $recommendedLanes) {
            $result->addWarning("NIC {$index}: {$speed}Gbps typically requires {$recommendedLanes} lanes but only has {$lanes}");
        }

        // Check PCIe generation support
        if ($context->hasComponent('motherboard')) {
            $mb = $context->getComponent('motherboard', 0);
            $mbGen = $mb['pcie_generation'] ?? 4;

            if ($speed >= 100 && $mbGen < 4) {
                $result->addWarning("NIC {$index}: {$speed}Gbps NIC requires PCIe Gen 4+ but motherboard is Gen {$mbGen}");
            }
        }
    }

    /**
     * Get recommended PCIe lanes for network speed
     */
    private function getRecommendedLanes(float $speed): int {
        if ($speed <= 1) return 1;
        if ($speed <= 10) return 4;
        if ($speed <= 25) return 8;
        return 16;
    }

    /**
     * Validate NIC-specific features
     */
    private function validateNICFeatures(array $nic, int $index, ValidationResult $result): void {
        // Check for offload capabilities (beneficial for high-speed)
        if (!empty($nic['tcp_offload']) || !empty($nic['rss'])) {
            $result->addInfo("NIC {$index}: has hardware offload capabilities");
        }

        // Check for virtual function support (for servers)
        if (!empty($nic['sriov'])) {
            $result->addInfo("NIC {$index}: supports SR-IOV virtualization");
        }

        // Check for management features
        if (!empty($nic['ipmi'])) {
            $result->addInfo("NIC {$index}: has integrated management (IPMI)");
        }

        // Check for wake-on-LAN
        if (!empty($nic['wol'])) {
            $result->addInfo("NIC {$index}: supports Wake-on-LAN");
        }
    }

    /**
     * Validate overall NIC configuration
     */
    private function validateNICConfiguration(array $nics, ValidationContext $context, ValidationResult $result): void {
        $totalPorts = 0;
        $totalSpeed = 0;

        foreach ($nics as $nic) {
            $portCount = $nic['port_count'] ?? 1;
            $speed = $nic['speed_gbps'] ?? 0;

            $totalPorts += $portCount;
            $totalSpeed += ($speed * $portCount);
        }

        $nicCount = count($nics);

        if ($nicCount === 1) {
            $result->addInfo("Single NIC configuration: {$totalPorts} port(s) at {$totalSpeed}Gbps total");
        } else {
            $result->addInfo("Dual NIC redundancy: {$nicCount} cards, {$totalPorts} port(s) at {$totalSpeed}Gbps total");
        }

        // Check for network redundancy in servers
        if ($nicCount > 1 && $context->hasComponent('chassis')) {
            $chassis = $context->getComponent('chassis', 0);
            if (stripos($chassis['form_factor'] ?? '', 'RACK') !== false || stripos($chassis['form_factor'] ?? '', 'SERVER') !== false) {
                $result->addInfo("Server configuration with dual NICs - supports network failover");
            }
        }
    }
}

?>
