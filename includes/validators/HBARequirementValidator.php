<?php

/**
 * HBA Requirement Validator
 *
 * Validates when HBA card is required for storage configuration.
 *
 * Priority: 45 (Medium - determines if HBA is needed)
 * Dependencies: StorageValidator
 *
 * Phase 3 Part 2 - File 26
 */

require_once __DIR__ . '/BaseValidator.php';

class HBARequirementValidator extends BaseValidator {

    const PRIORITY = 45;

    public function getName(): string {
        return 'HBA Requirement Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates HBA card requirements for storage configuration';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->countComponents('storage') > 0;
    }

    /**
     * Validate HBA requirements
     *
     * LOGIC:
     * 1. Check storage device types
     * 2. Determine if HBA is required
     * 3. Validate HBA presence if required
     * 4. Validate HBA specs if present
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $storageDevices = $context->getComponents('storage');
            $hasHBA = $context->hasComponent('hbacard');

            // Check if SAS or other enterprise features are needed
            $requiresHBA = $this->checkHBARequirement($storageDevices);

            if ($requiresHBA && !$hasHBA) {
                $result->addError('Storage configuration requires HBA card but none is present');
            } elseif ($requiresHBA && $hasHBA) {
                $result->addInfo('HBA card present for enterprise storage support');
                $this->validateHBASpecs($context, $storageDevices, $result);
            } elseif (!$requiresHBA && $hasHBA) {
                $result->addInfo('HBA card present but not required for current storage configuration');
            }

        } catch (\Exception $e) {
            $result->addError('HBA requirement validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Check if HBA card is required
     */
    private function checkHBARequirement(array $storageDevices): bool {
        foreach ($storageDevices as $storage) {
            $interface = strtoupper($storage['interface'] ?? '');

            // SAS drives require HBA
            if ($interface === 'SAS') {
                return true;
            }

            // Check for enterprise features that require HBA
            $model = strtoupper($storage['model'] ?? '');
            if (stripos($model, 'ENTERPRISE') !== false || stripos($model, 'DATACENTER') !== false) {
                // Enterprise drives might need HBA for RAID
                return true;
            }

            // Check if RAID is specified for SATA drives
            if (!empty($storage['raid_group'])) {
                // Configuration includes RAID - may need HBA for advanced RAID
                return true;
            }
        }

        return false;
    }

    /**
     * Validate HBA specifications for storage
     */
    private function validateHBASpecs(ValidationContext $context, array $storageDevices, ValidationResult $result): void {
        $hbaCard = $context->getComponent('hbacard', 0);

        if (!$hbaCard) {
            return;
        }

        $portCount = $hbaCard['port_count'] ?? 0;
        $sasCount = 0;
        $raidRequired = false;

        // Count SAS devices and check for RAID
        foreach ($storageDevices as $storage) {
            if (strtoupper($storage['interface'] ?? '') === 'SAS') {
                $sasCount++;
            }

            if (!empty($storage['raid_group'])) {
                $raidRequired = true;
            }
        }

        // Validate port count
        if ($sasCount > 0 && $portCount === 0) {
            $result->addError('HBA card has no SAS ports but SAS drives are present');
        } elseif ($sasCount > $portCount) {
            $result->addError("SAS drives ({$sasCount}) exceed HBA port count ({$portCount})");
        }

        // Check RAID capability
        if ($raidRequired && empty($hbaCard['raid_support'])) {
            $result->addWarning('RAID configuration requires RAID-capable HBA but card does not support RAID');
        }

        // Check battery backup for RAID
        if ($raidRequired && empty($hbaCard['battery_backup'])) {
            $result->addWarning('RAID configuration recommended to use battery-backed cache for data protection');
        }

        $result->addInfo("HBA card has {$portCount} ports for {$sasCount} SAS devices");
    }
}

?>
