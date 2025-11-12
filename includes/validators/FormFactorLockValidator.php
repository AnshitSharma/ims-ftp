<?php

/**
 * Form Factor Lock Validator
 *
 * Ensures all components fit physically within chassis constraints.
 *
 * Priority: 30 (Medium-Low - validates physical fit)
 * Dependencies: FormFactorValidator, StorageBayValidator
 *
 * Phase 3 Part 2 - File 29
 */

require_once __DIR__ . '/BaseValidator.php';

class FormFactorLockValidator extends BaseValidator {

    const PRIORITY = 30;

    public function getName(): string {
        return 'Form Factor Lock Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Ensures all components fit physically within chassis';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->hasComponent('chassis');
    }

    /**
     * Validate physical fit of all components
     *
     * LOGIC:
     * 1. Get chassis dimensions and constraints
     * 2. Check motherboard fits
     * 3. Check PSU fits
     * 4. Check cooler clearance
     * 5. Check GPU/PCIe clearance
     * 6. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $chassis = $context->getComponent('chassis', 0);

            if (!$chassis) {
                return $result;
            }

            $result->addInfo("Validating physical fit for " . ($chassis['model'] ?? 'unknown') . " chassis");

            // Check motherboard fit
            $this->validateMotherboardFit($chassis, $context, $result);

            // Check PSU fit
            $this->validatePSUFit($chassis, $context, $result);

            // Check cooler clearance
            $this->validateCoolerClearance($chassis, $context, $result);

            // Check PCIe card clearance
            $this->validatePCIeClearance($chassis, $context, $result);

        } catch (\Exception $e) {
            $result->addError('Form factor lock validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate motherboard fits in chassis
     */
    private function validateMotherboardFit(array $chassis, ValidationContext $context, ValidationResult $result): void {
        if (!$context->hasComponent('motherboard')) {
            return;
        }

        $motherboard = $context->getComponent('motherboard', 0);
        $mbFormFactor = strtoupper($motherboard['form_factor'] ?? 'ATX');
        $chassisType = strtoupper($chassis['form_factor'] ?? 'Tower');

        // Form factor compatibility check
        $compatible = $this->isFormFactorCompatible($mbFormFactor, $chassisType);

        if (!$compatible) {
            $result->addError("Motherboard form factor '{$mbFormFactor}' incompatible with '{$chassisType}' chassis");
        } else {
            $result->addInfo("Motherboard '{$mbFormFactor}' compatible with '{$chassisType}' chassis");
        }
    }

    /**
     * Validate PSU fits in chassis
     */
    private function validatePSUFit(array $chassis, ValidationContext $context, ValidationResult $result): void {
        if (!$context->hasComponent('psu')) {
            return;
        }

        $psu = $context->getComponent('psu', 0);
        $psuFormFactor = strtoupper($psu['form_factor'] ?? 'ATX');
        $chassisType = strtoupper($chassis['form_factor'] ?? 'Tower');

        // Get supported PSU form factors
        $supportedPSU = $chassis['supported_psu_form_factors'] ?? [];
        if (is_string($supportedPSU)) {
            $supportedPSU = [$supportedPSU];
        }

        // Convert to uppercase for comparison
        $supportedPSU = array_map('strtoupper', (array)$supportedPSU);

        if (!in_array($psuFormFactor, $supportedPSU)) {
            $result->addError("PSU form factor '{$psuFormFactor}' not supported by '{$chassisType}' chassis. Supported: " . implode(', ', $supportedPSU));
        } else {
            $result->addInfo("PSU form factor '{$psuFormFactor}' fits in chassis");
        }
    }

    /**
     * Validate CPU cooler fits with motherboard
     */
    private function validateCoolerClearance(array $chassis, ValidationContext $context, ValidationResult $result): void {
        if (!$context->hasComponent('cpu')) {
            return;
        }

        $cpu = $context->getComponent('cpu', 0);
        $cpuTdp = $cpu['tdp_watts'] ?? 0;

        // Check chassis cooling capacity
        $maxCoolerHeight = $chassis['max_cooler_height_mm'] ?? 160;
        $cooling = $chassis['cooling_fans'] ?? 1;

        if ($cpuTdp > 200 && $cooling < 2) {
            $result->addWarning("High TDP CPU ({$cpuTdp}W) with limited chassis cooling (only {$cooling} fans)");
        }

        if ($cpuTdp > 150 && $maxCoolerHeight < 130) {
            $result->addWarning("High TDP CPU ({$cpuTdp}W) but chassis cooler clearance is limited ({$maxCoolerHeight}mm)");
        }
    }

    /**
     * Validate PCIe card physical clearance
     */
    private function validatePCIeClearance(array $chassis, ValidationContext $context, ValidationResult $result): void {
        $pcieCards = $context->getComponents('pciecard');
        $nics = $context->getComponents('nic');
        $hbaCards = $context->getComponents('hbacard');

        $allCards = array_merge($pcieCards, $nics, $hbaCards);

        if (empty($allCards)) {
            return;
        }

        // Check for long cards in compact chassis
        $chassisType = strtoupper($chassis['form_factor'] ?? 'Tower');

        if (stripos($chassisType, 'MINI') !== false || stripos($chassisType, 'COMPACT') !== false) {
            foreach ($allCards as $index => $card) {
                $length = $card['length_mm'] ?? 280;
                if ($length > 250) {
                    $result->addWarning("PCIe card {$index} ({$length}mm) may be too long for compact chassis");
                }
            }
        }
    }

    /**
     * Check form factor compatibility
     */
    private function isFormFactorCompatible(string $mbFormFactor, string $chassisType): bool {
        // Compatibility matrix
        $compatibility = [
            'ATX' => ['TOWER', 'FULL-TOWER', 'MID-TOWER', 'RACK'],
            'EATX' => ['FULL-TOWER', 'TOWER', 'RACK'],
            'MATX' => ['TOWER', 'MID-TOWER', 'MINI-TOWER', 'COMPACT'],
            'ITX' => ['MINI-TOWER', 'COMPACT', 'SMALL'],
            'MINI-ITX' => ['MINI-TOWER', 'COMPACT', 'SMALL'],
        ];

        $compatible = $compatibility[$mbFormFactor] ?? [];
        return in_array($chassisType, $compatible);
    }
}

?>
