<?php

/**
 * PCIe Adapter Validator
 *
 * Validates PCIe adapter requirements for storage devices.
 *
 * Priority: 40 (Medium - validates adapters for M.2 and U.2)
 * Dependencies: MotherboardStorageValidator, StorageValidator
 *
 * Phase 3 Part 2 - File 27
 */

require_once __DIR__ . '/BaseValidator.php';

class PCIeAdapterValidator extends BaseValidator {

    const PRIORITY = 40;

    public function getName(): string {
        return 'PCIe Adapter Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates PCIe adapter requirements for storage devices';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->countComponents('storage') > 0 && $context->hasComponent('motherboard');
    }

    /**
     * Validate PCIe adapter requirements
     *
     * LOGIC:
     * 1. Check storage devices needing adapters
     * 2. Validate motherboard supports adapter types
     * 3. Check PCIe slot availability for adapters
     * 4. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $storageDevices = $context->getComponents('storage');
            $motherboard = $context->getComponent('motherboard', 0);

            if (!$motherboard) {
                return $result;
            }

            // Determine adapter requirements
            $adapterNeeds = $this->determineAdapterNeeds($storageDevices, $motherboard);

            if (!empty($adapterNeeds)) {
                $this->validateAdapterAvailability($adapterNeeds, $context, $result);
            }

        } catch (\Exception $e) {
            $result->addError('PCIe adapter validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Determine what adapters are needed
     */
    private function determineAdapterNeeds(array $storageDevices, array $motherboard): array {
        $adapters = [
            'sata_to_m2' => 0,
            'nvme_to_pcie' => 0,
            'u2_adapter' => 0,
        ];

        $sataPorts = $motherboard['sata_ports'] ?? 0;
        $m2Slots = $motherboard['m2_slots'] ?? 0;
        $u2Ports = $motherboard['u2_ports'] ?? 0;

        foreach ($storageDevices as $storage) {
            $interface = strtoupper($storage['interface'] ?? 'SATA');
            $formFactor = strtoupper($storage['form_factor'] ?? '');

            // Check for SATA drive needing M.2 adapter
            if ($interface === 'SATA' && str_contains($formFactor, 'M.2')) {
                if ($sataPorts > 0) {
                    $adapters['sata_to_m2']++;
                }
            }

            // Check for NVMe to PCIe adapter (bifurcation)
            if ($interface === 'NVME' && $m2Slots === 0) {
                $adapters['nvme_to_pcie']++;
            }

            // Check for U.2 adapter
            if ($interface === 'U.2' && $u2Ports === 0) {
                $adapters['u2_adapter']++;
            }
        }

        // Remove adapters with 0 needs
        return array_filter($adapters, fn($count) => $count > 0);
    }

    /**
     * Validate adapter availability
     */
    private function validateAdapterAvailability(array $adapterNeeds, ValidationContext $context, ValidationResult $result): void {
        $motherboard = $context->getComponent('motherboard', 0);

        if ($adapterNeeds['sata_to_m2'] ?? 0 > 0) {
            $m2Slots = $motherboard['m2_slots'] ?? 0;
            if ($m2Slots > 0) {
                $result->addWarning("SATA drives with M.2 form factor need adapter - ensure M.2 slots support SATA");
            } else {
                $result->addError("SATA drives with M.2 form factor need adapter but no M.2 slots available");
            }
        }

        if ($adapterNeeds['nvme_to_pcie'] ?? 0 > 0) {
            if (!empty($motherboard['pcie_bifurcation'])) {
                $result->addInfo("Motherboard supports PCIe bifurcation - NVMe to PCIe adapter should work");
            } else {
                $result->addWarning("NVMe drives need PCIe adapter but motherboard may not support PCIe bifurcation");
            }
        }

        if ($adapterNeeds['u2_adapter'] ?? 0 > 0) {
            $pcieSlots = $motherboard['pcie_slots'] ?? 0;
            if ($pcieSlots > 1) {
                $result->addWarning("U.2 drives need PCIe adapter - PCIe slots available for adapter");
            } else {
                $result->addError("U.2 drives need PCIe adapter but insufficient PCIe slots");
            }
        }
    }
}

?>
