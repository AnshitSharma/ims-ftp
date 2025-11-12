<?php

/**
 * Storage Bay Validator
 *
 * Validates storage device placement in chassis bays.
 *
 * Priority: 35 (Medium - validates bay allocation)
 * Dependencies: StorageValidator, ChassisBackplaneValidator
 *
 * Phase 3 Part 2 - File 28
 */

require_once __DIR__ . '/BaseValidator.php';

class StorageBayValidator extends BaseValidator {

    const PRIORITY = 35;

    public function getName(): string {
        return 'Storage Bay Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates storage device placement in chassis bays';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->hasComponent('chassis') && $context->countComponents('storage') > 0;
    }

    /**
     * Validate storage bay allocation
     *
     * LOGIC:
     * 1. Count required drive bay spaces
     * 2. Get chassis available bays by size
     * 3. Allocate drives to appropriate bays
     * 4. Validate sufficient space
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $chassis = $context->getComponent('chassis', 0);
            $storageDevices = $context->getComponents('storage');

            if (!$chassis || empty($storageDevices)) {
                return $result;
            }

            // Count bay requirements by size
            $bayRequirements = [
                '2.5' => 0,
                '3.5' => 0,
                'M.2' => 0,
                'U.2' => 0,
            ];

            foreach ($storageDevices as $storage) {
                $formFactor = strtoupper($storage['form_factor'] ?? '2.5"');
                $interface = strtoupper($storage['interface'] ?? '');

                // Map form factor to bay type
                $bayType = $this->getBayTypeForFormFactor($formFactor, $interface);
                if (isset($bayRequirements[$bayType])) {
                    $bayRequirements[$bayType]++;
                }
            }

            // Validate bay availability
            $this->validateBayAvailability($chassis, $bayRequirements, $result);

        } catch (\Exception $e) {
            $result->addError('Storage bay validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Get bay type for form factor and interface
     */
    private function getBayTypeForFormFactor(string $formFactor, string $interface): string {
        if (str_contains($formFactor, 'M.2')) {
            return 'M.2';
        }
        if ($interface === 'U.2') {
            return 'U.2';
        }
        if (str_contains($formFactor, '3.5')) {
            return '3.5';
        }
        return '2.5';
    }

    /**
     * Validate bay availability in chassis
     */
    private function validateBayAvailability(array $chassis, array $bayRequirements, ValidationResult $result): void {
        $driveBays = $chassis['drive_bays'] ?? 0;

        // Total all drive bay requirements
        $total25 = $bayRequirements['2.5'] ?? 0;
        $total35 = $bayRequirements['3.5'] ?? 0;
        $totalM2 = $bayRequirements['M.2'] ?? 0;
        $totalU2 = $bayRequirements['U.2'] ?? 0;

        $totalDrives = $total25 + $total35;

        // For simple bay count, check total
        if ($totalDrives > $driveBays) {
            $result->addError("Configuration needs {$totalDrives} drive bays but chassis has {$driveBays}");
        } elseif ($totalDrives > 0) {
            $result->addInfo("Drive bay usage: {$totalDrives}/{$driveBays} bays used");
        }

        // Check for specific bay types if specified
        if (!empty($chassis['bays_2_5']) || !empty($chassis['bays_3_5'])) {
            $bays25 = $chassis['bays_2_5'] ?? 0;
            $bays35 = $chassis['bays_3_5'] ?? 0;

            if ($total25 > $bays25 && $bays25 > 0) {
                $result->addWarning("{$total25} 2.5\" drives but chassis has only {$bays25} 2.5\" bays");
            }

            if ($total35 > $bays35 && $bays35 > 0) {
                $result->addWarning("{$total35} 3.5\" drives but chassis has only {$bays35} 3.5\" bays");
            }
        }

        // Check M.2 bay if applicable
        if ($totalM2 > 0) {
            $m2Bays = $chassis['m2_bays'] ?? 0;
            if ($m2Bays === 0) {
                $result->addWarning("{$totalM2} M.2 drives but chassis has no dedicated M.2 bays");
            } elseif ($totalM2 > $m2Bays) {
                $result->addWarning("{$totalM2} M.2 drives but chassis has only {$m2Bays} M.2 bays");
            }
        }

        // Check U.2 bay if applicable
        if ($totalU2 > 0) {
            $u2Bays = $chassis['u2_bays'] ?? 0;
            if ($u2Bays === 0) {
                $result->addWarning("{$totalU2} U.2 drives but chassis has no U.2 bays");
            } elseif ($totalU2 > $u2Bays) {
                $result->addWarning("{$totalU2} U.2 drives but chassis has only {$u2Bays} U.2 bays");
            }
        }
    }
}

?>
