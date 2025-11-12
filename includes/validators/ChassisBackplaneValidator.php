<?php

/**
 * Chassis Backplane Validator
 *
 * Validates chassis backplane compatibility with storage controllers.
 *
 * Priority: 55 (Medium - validates backplane connectivity)
 * Dependencies: StorageValidator, MotherboardValidator
 *
 * Phase 3 Part 2 - File 24
 */

require_once __DIR__ . '/BaseValidator.php';

class ChassisBackplaneValidator extends BaseValidator {

    const PRIORITY = 55;

    public function getName(): string {
        return 'Chassis Backplane Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates chassis backplane compatibility with storage and controllers';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->hasComponent('chassis') &&
               ($context->countComponents('storage') > 0 || $context->hasComponent('hbacard'));
    }

    /**
     * Validate backplane compatibility
     *
     * LOGIC:
     * 1. Get chassis backplane type
     * 2. Get storage interface types
     * 3. Validate backplane supports required interfaces
     * 4. Check hot-swap capability if needed
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $chassis = $context->getComponent('chassis', 0);

            if (!$chassis) {
                return $result;
            }

            $backplaneType = $chassis['backplane_type'] ?? null;
            $hotSwapCapable = $chassis['hot_swap_capable'] ?? false;

            // Get storage interface requirements
            $storageDevices = $context->getComponents('storage');
            $requiredInterfaces = [];

            foreach ($storageDevices as $storage) {
                $interface = strtoupper($storage['interface'] ?? '');
                if (!in_array($interface, $requiredInterfaces)) {
                    $requiredInterfaces[] = $interface;
                }
            }

            if (empty($requiredInterfaces)) {
                return $result;
            }

            // Validate backplane supports interfaces
            if (!$backplaneType) {
                $result->addWarning('Chassis backplane type not specified - cannot validate storage compatibility');
                return $result;
            }

            $this->validateBackplaneSupport($backplaneType, $requiredInterfaces, $result);
            $this->validateHotSwap($chassis, $storageDevices, $hotSwapCapable, $result);

        } catch (\Exception $e) {
            $result->addError('Backplane validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate backplane supports required interfaces
     */
    private function validateBackplaneSupport(string $backplaneType, array $requiredInterfaces, ValidationResult $result): void {
        $backplaneType = strtoupper(trim($backplaneType));

        // Define backplane interface support
        $backplaneMap = [
            'SATA' => ['SATA'],
            'SAS' => ['SAS', 'SATA'],
            'SAS3' => ['SAS', 'SATA'],
            'NVME' => ['NVME'],
            'HYBRID' => ['SATA', 'SAS', 'NVME'],
            'MULTIPORT' => ['SATA', 'SAS', 'NVME', 'U.2'],
        ];

        $supportedInterfaces = $backplaneMap[$backplaneType] ?? [];

        foreach ($requiredInterfaces as $interface) {
            if (!in_array($interface, $supportedInterfaces)) {
                $result->addError("Chassis backplane type '{$backplaneType}' does not support {$interface} storage");
            }
        }

        if (!empty($supportedInterfaces)) {
            $result->addInfo("Chassis backplane '{$backplaneType}' supports: " . implode(', ', $supportedInterfaces));
        }
    }

    /**
     * Validate hot-swap capability if needed
     */
    private function validateHotSwap(array $chassis, array $storageDevices, bool $hotSwapCapable, ValidationResult $result): void {
        $requiresHotSwap = false;

        // Check if any storage device requires hot-swap (enterprise drives)
        foreach ($storageDevices as $storage) {
            if (!empty($storage['type']) && (stripos($storage['type'], 'SAS') !== false || stripos($storage['model'], 'ENTERPRISE') !== false)) {
                $requiresHotSwap = true;
                break;
            }
        }

        if ($requiresHotSwap && !$hotSwapCapable) {
            $result->addWarning('Configuration includes enterprise storage but chassis does not support hot-swap');
        }

        if ($hotSwapCapable) {
            $result->addInfo('Chassis supports hot-swap - drives can be replaced without powering down');
        }
    }
}

?>
