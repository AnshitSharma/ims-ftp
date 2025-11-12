<?php

/**
 * Caddy Validator
 *
 * Validates drive caddy/bracket compatibility with drives and chassis.
 *
 * Priority: 5 (Very Low - caddy/bracket validation)
 * Dependencies: StorageValidator, StorageBayValidator
 *
 * Phase 3 Part 2 - File 35
 */

require_once __DIR__ . '/BaseValidator.php';

class CaddyValidator extends BaseValidator {

    const PRIORITY = 5;

    public function getName(): string {
        return 'Caddy Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates drive caddy/bracket compatibility';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->countComponents('caddy') > 0;
    }

    /**
     * Validate caddy specifications
     *
     * LOGIC:
     * 1. Validate caddy specs
     * 2. Check drive compatibility
     * 3. Validate mounting
     * 4. Check for required adapters
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $caddies = $context->getComponents('caddy');
            $storageDevices = $context->getComponents('storage');

            if (empty($caddies)) {
                return $result;
            }

            // Validate each caddy
            foreach ($caddies as $index => $caddy) {
                $this->validateCaddy($caddy, $index, $storageDevices, $context, $result);
            }

        } catch (\Exception $e) {
            $result->addError('Caddy validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate individual caddy
     */
    private function validateCaddy(array $caddy, int $index, array $storageDevices, ValidationContext $context, ValidationResult $result): void {
        // Validate required fields
        if (empty($caddy['model'])) {
            $result->addWarning("Caddy {$index}: model not specified");
        }

        // Validate form factor
        $formFactor = strtoupper($caddy['form_factor'] ?? '');
        $validFormFactors = ['2.5', '3.5', '2.5"', '3.5"', 'M.2', 'U.2'];

        if (!in_array($formFactor, $validFormFactors)) {
            $result->addWarning("Caddy {$index}: unknown form factor '{$formFactor}'");
        }

        // Check compatibility with storage drives
        $this->validateCaddyDriveCompatibility($caddy, $index, $storageDevices, $result);

        // Check mounting type
        $this->validateCaddyMounting($caddy, $index, $context, $result);

        // Check for hot-swap capability
        if (!empty($caddy['hot_swap'])) {
            $result->addInfo("Caddy {$index}: hot-swap capable");
        }

        $result->addInfo("Caddy {$index}: {$formFactor} drive caddy");
    }

    /**
     * Validate caddy-drive compatibility
     */
    private function validateCaddyDriveCompatibility(array $caddy, int $index, array $storageDevices, ValidationResult $result): void {
        $caddyFormFactor = strtoupper($caddy['form_factor'] ?? '');

        // Match caddy to compatible drives
        foreach ($storageDevices as $driveIndex => $drive) {
            $driveFormFactor = strtoupper($drive['form_factor'] ?? '');

            // Check if drive fits in caddy
            if (!$this->isCompatibleFormFactor($caddyFormFactor, $driveFormFactor)) {
                $result->addError("Caddy {$index} ({$caddyFormFactor}) incompatible with drive {$driveIndex} ({$driveFormFactor})");
            }
        }

        // Check material (aluminum vs plastic)
        if (!empty($caddy['material'])) {
            if (strtolower($caddy['material']) === 'plastic') {
                $result->addWarning("Caddy {$index}: plastic material - may not be durable for enterprise use");
            }
        }
    }

    /**
     * Validate caddy mounting type
     */
    private function validateCaddyMounting(array $caddy, int $index, ValidationContext $context, ValidationResult $result): void {
        $mountType = $caddy['mounting_type'] ?? null;

        if (!$mountType) {
            $result->addWarning("Caddy {$index}: mounting type not specified");
            return;
        }

        $mountType = strtoupper($mountType);

        switch ($mountType) {
            case 'RAIL':
                // Check for rail compatibility in chassis
                if ($context->hasComponent('chassis')) {
                    $chassis = $context->getComponent('chassis', 0);
                    if (empty($chassis['rail_compatible'])) {
                        $result->addWarning("Caddy {$index}: rail mounting but chassis may not have rail support");
                    }
                }
                $result->addInfo("Caddy {$index}: rail mounted");
                break;

            case 'BAY':
                $result->addInfo("Caddy {$index}: bay mounted");
                break;

            case 'BRACKET':
                $result->addInfo("Caddy {$index}: bracket mounted");
                break;

            default:
                $result->addWarning("Caddy {$index}: unknown mounting type '{$mountType}'");
        }
    }

    /**
     * Check form factor compatibility
     */
    private function isCompatibleFormFactor(string $caddyFormFactor, string $driveFormFactor): bool {
        // Normalize form factors
        $caddy = str_replace('"', '', $caddyFormFactor);
        $drive = str_replace('"', '', $driveFormFactor);

        // Same form factor = compatible
        if ($caddy === $drive) {
            return true;
        }

        // M.2 and U.2 are interchangeable with adapters
        if (($caddy === 'M.2' && $drive === 'U.2') || ($caddy === 'U.2' && $drive === 'M.2')) {
            return true;
        }

        return false;
    }
}

?>
