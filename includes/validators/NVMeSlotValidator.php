<?php

/**
 * NVMe Slot Validator
 *
 * Validates NVMe drive placement and M.2 slot compatibility.
 *
 * Priority: 25 (Medium-Low - NVMe specific validation)
 * Dependencies: StorageValidator, MotherboardStorageValidator
 *
 * Phase 3 Part 2 - File 30
 */

require_once __DIR__ . '/BaseValidator.php';

class NVMeSlotValidator extends BaseValidator {

    const PRIORITY = 25;

    public function getName(): string {
        return 'NVMe Slot Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates NVMe drive placement in M.2 slots';
    }

    public function canRun(ValidationContext $context): bool {
        $storage = $context->getComponents('storage');
        foreach ($storage as $s) {
            if (strtoupper($s['interface'] ?? '') === 'NVME') {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate NVMe configuration
     *
     * LOGIC:
     * 1. Count NVMe drives
     * 2. Get motherboard M.2 slots
     * 3. Check slot types (NVMe vs SATA)
     * 4. Validate thermal pads on high-speed NVMe
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $storageDevices = $context->getComponents('storage');
            $motherboard = $context->getComponent('motherboard', 0);

            if (!$motherboard) {
                $result->addWarning('No motherboard found - cannot validate M.2 slots');
                return $result;
            }

            $nvmeDevices = [];
            foreach ($storageDevices as $storage) {
                if (strtoupper($storage['interface'] ?? '') === 'NVME') {
                    $nvmeDevices[] = $storage;
                }
            }

            if (empty($nvmeDevices)) {
                return $result;
            }

            // Validate M.2 slots availability
            $m2Slots = $motherboard['m2_slots'] ?? 0;
            if ($m2Slots === 0) {
                $result->addError('No M.2 slots on motherboard but NVMe drives present');
                return $result;
            }

            if (count($nvmeDevices) > $m2Slots) {
                $result->addError("More NVMe drives (" . count($nvmeDevices) . ") than M.2 slots ({$m2Slots})");
            }

            // Validate each NVMe drive
            foreach ($nvmeDevices as $index => $drive) {
                $this->validateNVMeDrive($drive, $index, $motherboard, $result);
            }

            // Validate M.2 slot PCIe generation
            $this->validateM2PCIeGeneration($nvmeDevices, $motherboard, $result);

        } catch (\Exception $e) {
            $result->addError('NVMe slot validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate individual NVMe drive
     */
    private function validateNVMeDrive(array $drive, int $index, array $motherboard, ValidationResult $result): void {
        $capacity = $drive['capacity_gb'] ?? 0;
        $speed = $drive['speed_mbps'] ?? null;
        $formFactor = strtoupper($drive['form_factor'] ?? 'M.2');

        // Validate form factor
        if (!str_contains($formFactor, 'M.2')) {
            $result->addError("NVMe drive {$index}: unusual form factor '{$formFactor}' (should be M.2)");
        }

        // Validate capacity
        if ($capacity <= 0) {
            $result->addError("NVMe drive {$index}: invalid capacity");
        }

        // Check for thermal pads on high-speed NVMe
        if ($speed && $speed > 6000) {
            // High-speed NVMe (PCIe 4.0+)
            if (empty($drive['thermal_pads'])) {
                $result->addWarning("NVMe drive {$index}: high-speed ({$speed}Mbps) without thermal pads may thermal throttle");
            }
        }

        // Check M.2 slot type
        $m2SlotType = $motherboard['m2_slot_type'] ?? 'NVME';
        if (stripos($m2SlotType, 'SATA') !== false) {
            $result->addWarning("NVMe drive {$index} in SATA-only M.2 slot - may not work or work at limited speed");
        }

        $result->addInfo("NVMe drive {$index}: {$capacity}GB" . ($speed ? " ({$speed}Mbps)" : ''));
    }

    /**
     * Validate M.2 slot PCIe generation
     */
    private function validateM2PCIeGeneration(array $nvmeDevices, array $motherboard, ValidationResult $result): void {
        $m2Gen = $motherboard['m2_pcie_generation'] ?? 4;
        $mbPcieGen = $motherboard['pcie_generation'] ?? 4;

        // M.2 should match or be close to main PCIe generation
        if ($m2Gen < $mbPcieGen - 1) {
            $result->addWarning("M.2 slots are PCIe Gen {$m2Gen} but motherboard supports Gen {$mbPcieGen}");
        }

        // Check if any NVMe requires newer gen
        foreach ($nvmeDevices as $drive) {
            if (!empty($drive['pcie_generation'])) {
                $driveGen = $drive['pcie_generation'];
                if ($driveGen > $m2Gen) {
                    $result->addWarning("NVMe drive requires PCIe Gen {$driveGen} but M.2 slots are only Gen {$m2Gen}");
                }
            }
        }

        $result->addInfo("M.2 slots support PCIe Gen {$m2Gen}");
    }
}

?>
