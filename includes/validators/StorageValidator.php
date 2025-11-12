<?php

/**
 * Storage Validator
 *
 * Validates storage device specifications and configuration.
 *
 * Priority: 65 (Medium-High - validates storage compatibility)
 * Dependencies: MotherboardValidator
 *
 * Phase 3 Part 1 - File 22
 */

require_once __DIR__ . '/BaseValidator.php';
require_once __DIR__ . '/../models/ComponentDataService.php';

class StorageValidator extends BaseValidator {

    const PRIORITY = 65;

    private ComponentDataService $componentDataService;

    public function __construct(ComponentDataService $componentDataService = null) {
        $this->componentDataService = $componentDataService ?? new ComponentDataService();
    }

    public function getName(): string {
        return 'Storage Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates storage devices and configurations';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->countComponents('storage') > 0;
    }

    /**
     * Validate storage specifications
     *
     * LOGIC:
     * 1. Validate each storage device specs
     * 2. Check interface types (NVMe, SATA, SAS)
     * 3. Validate form factors
     * 4. Check total capacity
     * 5. Validate storage controller support
     * 6. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $storageDevices = $context->getComponents('storage');

            if (empty($storageDevices)) {
                return $result;
            }

            // Validate each storage device
            foreach ($storageDevices as $index => $storage) {
                $this->validateStorageDevice($storage, $index, $context, $result);
            }

            // Validate overall storage configuration
            $this->validateStorageConfiguration($storageDevices, $context, $result);

        } catch (\Exception $e) {
            $result->addError('Storage validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate individual storage device
     */
    private function validateStorageDevice(array $storage, int $index, ValidationContext $context, ValidationResult $result): void {
        // Validate required fields
        $required = ['capacity_gb', 'interface', 'form_factor'];

        foreach ($required as $field) {
            if (empty($storage[$field])) {
                $result->addError("Storage device {$index}: field '{$field}' is required but missing");
            }
        }

        // Validate capacity
        $capacity = $storage['capacity_gb'] ?? 0;
        if ($capacity <= 0) {
            $result->addError("Storage device {$index}: capacity must be greater than 0 GB");
        }

        // Validate interface
        $interface = strtoupper($storage['interface'] ?? '');
        $validInterfaces = ['NVME', 'SATA', 'SAS', 'U.2', 'M.2'];
        if (!in_array($interface, $validInterfaces)) {
            $result->addWarning("Storage device {$index}: unknown interface '{$interface}'");
        }

        // Validate form factor
        $formFactor = strtoupper($storage['form_factor'] ?? '');
        $validFormFactors = ['2.5"', '3.5"', 'M.2', '2.5', '3.5'];
        if (!in_array($formFactor, $validFormFactors) && !str_contains($formFactor, 'M.2')) {
            $result->addWarning("Storage device {$index}: unusual form factor '{$formFactor}'");
        }

        // Validate interface/form factor combination
        if ($interface === 'NVME' && !str_contains($formFactor, 'M.2')) {
            $result->addError("Storage device {$index}: NVMe interface requires M.2 form factor");
        }

        if ($interface === 'SATA' && str_contains($formFactor, 'M.2')) {
            $result->addWarning("Storage device {$index}: SATA interface with M.2 form factor is unusual");
        }
    }

    /**
     * Validate overall storage configuration
     */
    private function validateStorageConfiguration(array $storageDevices, ValidationContext $context, ValidationResult $result): void {
        $totalCapacity = 0;
        $nvmeCount = 0;
        $sataCount = 0;
        $sasCount = 0;

        foreach ($storageDevices as $storage) {
            $totalCapacity += $storage['capacity_gb'] ?? 0;

            $interface = strtoupper($storage['interface'] ?? '');
            if ($interface === 'NVME') {
                $nvmeCount++;
            } elseif ($interface === 'SATA') {
                $sataCount++;
            } elseif ($interface === 'SAS') {
                $sasCount++;
            }
        }

        // Check total capacity
        if ($totalCapacity > 100000) {
            $result->addWarning("Very large total storage capacity: {$totalCapacity}GB - ensure proper cooling and power");
        }

        // Check NVMe slots if present
        if ($nvmeCount > 0 && $context->hasComponent('motherboard')) {
            $mb = $context->getComponent('motherboard', 0);
            $m2Slots = $mb['m2_slots'] ?? 0;

            if ($m2Slots > 0 && $nvmeCount > $m2Slots) {
                $result->addError("More NVMe drives ({$nvmeCount}) than motherboard M.2 slots ({$m2Slots})");
            }
        }

        // Check SATA ports if present
        if ($sataCount > 0 && $context->hasComponent('motherboard')) {
            $mb = $context->getComponent('motherboard', 0);
            $sataPorts = $mb['sata_ports'] ?? 0;

            if ($sataPorts > 0 && $sataCount > $sataPorts) {
                $result->addWarning("More SATA drives ({$sataCount}) than motherboard SATA ports ({$sataPorts}) - may need adapter");
            }
        }

        // Check SAS drives require HBA card
        if ($sasCount > 0) {
            if (!$context->hasComponent('hbacard')) {
                $result->addError("SAS drives present but no HBA card found - SAS drives require HBA controller");
            }
        }

        $result->addInfo("Storage summary: {$nvmeCount} NVMe, {$sataCount} SATA, {$sasCount} SAS drives - Total: {$totalCapacity}GB");
    }
}

?>
