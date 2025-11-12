<?php

/**
 * Form Factor Validator
 *
 * Validates physical form factor compatibility between components.
 * Ensures motherboard fits chassis, PSU fits chassis, etc.
 *
 * Priority: 95 (Very High - critical for physical compatibility)
 * Dependencies: None
 *
 * Phase 3 Part 1 - File 18
 */

require_once __DIR__ . '/BaseValidator.php';

class FormFactorValidator extends BaseValidator {

    const PRIORITY = 95;

    public function getName(): string {
        return 'Form Factor Compatibility';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates physical form factor compatibility between components';
    }

    public function canRun(ValidationContext $context): bool {
        // Can run if motherboard and chassis are present
        return $context->hasComponent('motherboard') && $context->hasComponent('chassis');
    }

    /**
     * Validate form factor compatibility
     *
     * LOGIC:
     * 1. Get motherboard form factor
     * 2. Get chassis supported form factors
     * 3. Check if motherboard fits in chassis
     * 4. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $motherboard = $context->getComponent('motherboard', 0);
            $chassis = $context->getComponent('chassis', 0);

            if (!$motherboard || !$chassis) {
                $result->addWarning('Motherboard or chassis not found');
                return $result;
            }

            $mbFormFactor = strtoupper($motherboard['form_factor'] ?? '');
            $chassisFormFactors = $this->extractFormFactors($chassis);

            if (empty($mbFormFactor)) {
                $result->addError('Motherboard form factor not specified');
                return $result;
            }

            if (empty($chassisFormFactors)) {
                $result->addWarning('Chassis supported form factors not specified');
                return $result;
            }

            // Check if motherboard fits in chassis
            if ($this->isCompatibleFormFactor($mbFormFactor, $chassisFormFactors)) {
                $result->addInfo("Motherboard form factor '{$mbFormFactor}' is compatible with chassis");
            } else {
                $result->addError("Motherboard form factor '{$mbFormFactor}' not supported by chassis. Supported: " . implode(', ', $chassisFormFactors));
            }

        } catch (\Exception $e) {
            $result->addError('Form factor validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Extract form factors from chassis specifications
     */
    private function extractFormFactors(array $chassis): array {
        $formFactors = [];

        // Check form_factor field
        if (!empty($chassis['form_factor'])) {
            $formFactors[] = strtoupper($chassis['form_factor']);
        }

        // Check supported_form_factors field
        if (!empty($chassis['supported_form_factors'])) {
            $ff = $chassis['supported_form_factors'];
            if (is_array($ff)) {
                $formFactors = array_merge($formFactors, array_map('strtoupper', $ff));
            } else {
                $formFactors[] = strtoupper($ff);
            }
        }

        return array_unique($formFactors);
    }

    /**
     * Check if motherboard form factor is compatible
     */
    private function isCompatibleFormFactor(string $mbFormFactor, array $chassisFormFactors): bool {
        // Normalize form factor names
        $formFactorMap = [
            'ATX' => ['ATX', 'EATX'],
            'EATX' => ['EATX'],
            'MATX' => ['EATX', 'ATX', 'MATX'],
            'MINI-ITX' => ['EATX', 'ATX', 'MATX', 'MINI-ITX'],
            'ITX' => ['EATX', 'ATX', 'MATX', 'MINI-ITX', 'ITX'],
        ];

        $compatible = $formFactorMap[$mbFormFactor] ?? [$mbFormFactor];

        foreach ($compatible as $form) {
            if (in_array($form, $chassisFormFactors)) {
                return true;
            }
        }

        return false;
    }
}

?>
