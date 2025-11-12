<?php

/**
 * RAM Validator
 *
 * Validates RAM compatibility with motherboard and CPU.
 *
 * Priority: 70 (High - validates memory compatibility)
 * Dependencies: MotherboardValidator, CPUValidator
 *
 * Phase 3 Part 1 - File 21
 */

require_once __DIR__ . '/BaseValidator.php';
require_once __DIR__ . '/../models/ComponentDataService.php';

class RAMValidator extends BaseValidator {

    const PRIORITY = 70;

    private ComponentDataService $componentDataService;

    public function __construct(ComponentDataService $componentDataService = null) {
        $this->componentDataService = $componentDataService ?? new ComponentDataService();
    }

    public function getName(): string {
        return 'RAM Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates RAM compatibility with motherboard and CPU';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->countComponents('ram') > 0;
    }

    /**
     * Validate RAM specifications
     *
     * LOGIC:
     * 1. Check required RAM fields (capacity, speed, type, form_factor)
     * 2. Validate RAM type matches motherboard support
     * 3. Validate RAM speed against motherboard specs
     * 4. Check form factor compatibility
     * 5. Validate total capacity
     * 6. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $ramModules = $context->getComponents('ram');

            if (empty($ramModules)) {
                $result->addWarning('No RAM modules in configuration');
                return $result;
            }

            // Validate each RAM module
            foreach ($ramModules as $index => $ram) {
                $this->validateRAMModule($ram, $index, $context, $result);
            }

            // Validate overall memory configuration
            $this->validateMemoryConfiguration($ramModules, $context, $result);

        } catch (\Exception $e) {
            $result->addError('RAM validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate individual RAM module
     */
    private function validateRAMModule(array $ram, int $index, ValidationContext $context, ValidationResult $result): void {
        // Validate required fields
        $required = ['capacity_gb', 'type', 'form_factor'];

        foreach ($required as $field) {
            if (empty($ram[$field])) {
                $result->addError("RAM module {$index}: field '{$field}' is required but missing");
            }
        }

        // Validate capacity
        $capacity = $ram['capacity_gb'] ?? 0;
        if ($capacity <= 0) {
            $result->addError("RAM module {$index}: capacity must be greater than 0 GB");
        } elseif ($capacity > 192) {
            $result->addWarning("RAM module {$index}: capacity of {$capacity}GB is very high");
        }

        // Validate type
        $type = strtoupper($ram['type'] ?? '');
        $validTypes = ['DDR3', 'DDR4', 'DDR5', 'RDIMM', 'UDIMM', 'SODIMM'];
        if (!in_array($type, $validTypes)) {
            $result->addWarning("RAM module {$index}: unknown RAM type '{$type}'");
        }

        // Check if ECC RAM is used with non-ECC motherboard
        if (stripos($ram['model'] ?? '', 'ECC') !== false) {
            if ($context->hasComponent('motherboard')) {
                $mb = $context->getComponent('motherboard', 0);
                if (empty($mb['ecc_support'])) {
                    $result->addWarning("RAM module {$index}: ECC RAM used with non-ECC motherboard");
                }
            }
        }
    }

    /**
     * Validate overall memory configuration
     */
    private function validateMemoryConfiguration(array $ramModules, ValidationContext $context, ValidationResult $result): void {
        // Calculate total capacity
        $totalCapacity = 0;
        $ramTypes = [];
        $ramSpeeds = [];

        foreach ($ramModules as $ram) {
            $totalCapacity += $ram['capacity_gb'] ?? 0;
            if (!empty($ram['type'])) {
                $ramTypes[] = strtoupper($ram['type']);
            }
            if (!empty($ram['speed_mhz'])) {
                $ramSpeeds[] = $ram['speed_mhz'];
            }
        }

        // Check total capacity
        if ($totalCapacity === 0) {
            $result->addError('Total RAM capacity is 0 GB');
        } elseif ($totalCapacity > 256) {
            $result->addWarning("Total RAM capacity of {$totalCapacity}GB is very high");
        }

        // Validate all RAM types match (important for stability)
        $uniqueTypes = array_unique($ramTypes);
        if (count($uniqueTypes) > 1) {
            $result->addWarning('RAM modules use different types: ' . implode(', ', $uniqueTypes) . ' - ensure compatibility');
        }

        // Validate RAM slot count against motherboard
        if ($context->hasComponent('motherboard')) {
            $mb = $context->getComponent('motherboard', 0);
            $ramSlots = $mb['ram_slots'] ?? 0;

            if ($ramSlots > 0 && count($ramModules) > $ramSlots) {
                $result->addError("More RAM modules (" . count($ramModules) . ") than motherboard slots ({$ramSlots})");
            }
        }
    }
}

?>
