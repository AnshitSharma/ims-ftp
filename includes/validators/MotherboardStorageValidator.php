<?php

/**
 * Motherboard Storage Validator
 *
 * Validates storage port availability on motherboard.
 *
 * Priority: 50 (Medium - validates storage port allocation)
 * Dependencies: StorageValidator, MotherboardValidator
 *
 * Phase 3 Part 2 - File 25
 */

require_once __DIR__ . '/BaseValidator.php';

class MotherboardStorageValidator extends BaseValidator {

    const PRIORITY = 50;

    public function getName(): string {
        return 'Motherboard Storage Port Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates storage device port availability on motherboard';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->hasComponent('motherboard') && $context->countComponents('storage') > 0;
    }

    /**
     * Validate storage ports on motherboard
     *
     * LOGIC:
     * 1. Get motherboard port counts (SATA, M.2, U.2)
     * 2. Count storage devices by interface
     * 3. Validate sufficient ports available
     * 4. Check for port conflicts
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $motherboard = $context->getComponent('motherboard', 0);
            $storageDevices = $context->getComponents('storage');

            if (!$motherboard || empty($storageDevices)) {
                return $result;
            }

            // Count storage by interface
            $interfaceCounts = [
                'SATA' => 0,
                'NVME' => 0,
                'U.2' => 0,
                'SAS' => 0,
            ];

            foreach ($storageDevices as $storage) {
                $interface = strtoupper($storage['interface'] ?? 'SATA');
                if (isset($interfaceCounts[$interface])) {
                    $interfaceCounts[$interface]++;
                }
            }

            // Validate SATA ports
            $sataPorts = $motherboard['sata_ports'] ?? 0;
            if ($interfaceCounts['SATA'] > 0 && $sataPorts === 0) {
                $result->addError("Configuration includes SATA drives but motherboard has no SATA ports");
            } elseif ($interfaceCounts['SATA'] > $sataPorts && $sataPorts > 0) {
                $result->addError("Configuration needs {$interfaceCounts['SATA']} SATA ports but motherboard only has {$sataPorts}");
            }

            // Validate M.2 slots
            $m2Slots = $motherboard['m2_slots'] ?? 0;
            if ($interfaceCounts['NVME'] > 0 && $m2Slots === 0) {
                $result->addError("Configuration includes NVMe drives but motherboard has no M.2 slots");
            } elseif ($interfaceCounts['NVME'] > $m2Slots && $m2Slots > 0) {
                $result->addError("Configuration needs {$interfaceCounts['NVME']} M.2 slots but motherboard only has {$m2Slots}");
            }

            // Validate U.2 ports
            $u2Ports = $motherboard['u2_ports'] ?? 0;
            if ($interfaceCounts['U.2'] > 0 && $u2Ports === 0) {
                $result->addWarning("Configuration includes U.2 drives but motherboard has no U.2 ports");
            } elseif ($interfaceCounts['U.2'] > $u2Ports && $u2Ports > 0) {
                $result->addError("Configuration needs {$interfaceCounts['U.2']} U.2 ports but motherboard only has {$u2Ports}");
            }

            // Validate M.2 slot specifications (NVMe vs SATA)
            $this->validateM2SlotTypes($motherboard, $storageDevices, $result);

        } catch (\Exception $e) {
            $result->addError('Motherboard storage validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate M.2 slot types support NVMe
     */
    private function validateM2SlotTypes(array $motherboard, array $storageDevices, ValidationResult $result): void {
        $m2SlotType = $motherboard['m2_slot_type'] ?? 'NVME';

        foreach ($storageDevices as $index => $storage) {
            if (strtoupper($storage['interface'] ?? '') === 'NVME') {
                if (stripos($m2SlotType, 'SATA') !== false) {
                    $result->addWarning("NVMe drive but motherboard M.2 slots are SATA only");
                }
            }
        }
    }
}

?>
