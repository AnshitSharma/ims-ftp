<?php

/**
 * Motherboard Validator
 *
 * Validates motherboard specifications and capabilities.
 *
 * Priority: 80 (High - validates motherboard features)
 * Dependencies: SocketCompatibilityValidator, FormFactorValidator
 *
 * Phase 3 Part 1 - File 20
 */

require_once __DIR__ . '/BaseValidator.php';
require_once __DIR__ . '/../models/ComponentDataService.php';

class MotherboardValidator extends BaseValidator {

    const PRIORITY = 80;

    private ComponentDataService $componentDataService;

    public function __construct(ComponentDataService $componentDataService = null) {
        $this->componentDataService = $componentDataService ?? new ComponentDataService();
    }

    public function getName(): string {
        return 'Motherboard Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates motherboard specifications and slot availability';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->hasComponent('motherboard');
    }

    /**
     * Validate motherboard specifications
     *
     * LOGIC:
     * 1. Validate required fields (model, socket, form_factor)
     * 2. Validate slot counts (RAM, PCIe, M.2, SATA)
     * 3. Check slot availability for added components
     * 4. Validate VRM capability
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $motherboard = $context->getComponent('motherboard', 0);

            if (!$motherboard) {
                $result->addError('No motherboard found in configuration');
                return $result;
            }

            // Validate required fields
            $this->validateRequiredFields($motherboard, $result);

            if (!$result->isValid()) {
                return $result;
            }

            // Validate slot counts
            $this->validateSlotCounts($motherboard, $context, $result);

            // Validate slot availability
            $this->validateSlotAvailability($motherboard, $context, $result);

            // Validate VRM
            $this->validateVRM($motherboard, $context, $result);

        } catch (\Exception $e) {
            $result->addError('Motherboard validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate required motherboard fields
     */
    private function validateRequiredFields(array $mb, ValidationResult $result): void {
        $required = ['model', 'socket', 'form_factor'];

        foreach ($required as $field) {
            if (empty($mb[$field])) {
                $result->addError("Motherboard field '{$field}' is required but missing");
            }
        }
    }

    /**
     * Validate slot counts
     */
    private function validateSlotCounts(array $mb, ValidationContext $context, ValidationResult $result): void {
        // Validate RAM slots
        $ramSlots = $mb['ram_slots'] ?? 0;
        if ($ramSlots < 1) {
            $result->addError('Motherboard must have at least 1 RAM slot');
        } elseif ($ramSlots > 16) {
            $result->addInfo("Motherboard has {$ramSlots} RAM slots");
        }

        // Validate PCIe slots
        $pcieSlots = $mb['pcie_slots'] ?? 0;
        if ($pcieSlots < 1) {
            $result->addWarning('Motherboard has no standard PCIe slots');
        }

        // Validate M.2 slots if present
        if (!empty($mb['m2_slots']) && $mb['m2_slots'] < 0) {
            $result->addWarning('Invalid M.2 slot count');
        }

        // Validate SATA ports
        if (!empty($mb['sata_ports']) && $mb['sata_ports'] < 0) {
            $result->addWarning('Invalid SATA port count');
        }
    }

    /**
     * Validate slot availability
     */
    private function validateSlotAvailability(array $mb, ValidationContext $context, ValidationResult $result): void {
        $ramSlots = $mb['ram_slots'] ?? 0;
        $ramCount = $context->countComponents('ram');

        if ($ramCount > 0 && $ramCount > $ramSlots) {
            $result->addError("Configuration has {$ramCount} RAM modules but motherboard only has {$ramSlots} slots");
        } elseif ($ramCount > 0) {
            $result->addInfo("RAM allocation: {$ramCount}/{$ramSlots} slots used");
        }

        $pcieSlots = $mb['pcie_slots'] ?? 0;
        $pcieCount = $context->countComponents('pciecard');

        if ($pcieCount > 0 && $pcieCount > $pcieSlots) {
            $result->addError("Configuration has {$pcieCount} PCIe cards but motherboard only has {$pcieSlots} slots");
        }
    }

    /**
     * Validate VRM capability for CPU
     */
    private function validateVRM(array $mb, ValidationContext $context, ValidationResult $result): void {
        $vrmPhases = $mb['vrm_phases'] ?? null;
        $vrmQuality = $mb['vrm_quality'] ?? null;

        if (!$vrmPhases) {
            $result->addWarning('Motherboard VRM phase count not specified');
            return;
        }

        if ($context->hasComponent('cpu')) {
            $cpu = $context->getComponent('cpu', 0);
            $cpuTdp = $cpu['tdp_watts'] ?? $cpu['tdp'] ?? 0;

            // Conservative rule: need 1 phase per 10W for high-end CPUs
            if ($cpuTdp > 200 && $vrmPhases < ($cpuTdp / 10)) {
                $result->addWarning("CPU TDP ({$cpuTdp}W) may exceed VRM capability ({$vrmPhases} phases)");
            }
        }
    }
}

?>
