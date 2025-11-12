<?php

/**
 * HBA Validator
 *
 * Validates HBA (Host Bus Adapter) card compatibility and specifications.
 *
 * Priority: 10 (Low - HBA specific validation)
 * Dependencies: HBARequirementValidator, StorageValidator
 *
 * Phase 3 Part 2 - File 33
 */

require_once __DIR__ . '/BaseValidator.php';

class HBAValidator extends BaseValidator {

    const PRIORITY = 10;

    public function getName(): string {
        return 'HBA Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates HBA card specifications and compatibility';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->hasComponent('hbacard');
    }

    /**
     * Validate HBA specifications
     *
     * LOGIC:
     * 1. Validate HBA specs (ports, generation)
     * 2. Check SAS generation support
     * 3. Check RAID capabilities
     * 4. Validate cache and battery backup
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $hbaCards = $context->getComponents('hbacard');

            if (empty($hbaCards)) {
                return $result;
            }

            // Validate each HBA
            foreach ($hbaCards as $index => $hba) {
                $this->validateHBACard($hba, $index, $context, $result);
            }

            // Validate HBA configuration
            $this->validateHBAConfiguration($hbaCards, $context, $result);

        } catch (\Exception $e) {
            $result->addError('HBA validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate individual HBA card
     */
    private function validateHBACard(array $hba, int $index, ValidationContext $context, ValidationResult $result): void {
        // Validate required fields
        if (empty($hba['model'])) {
            $result->addWarning("HBA {$index}: model not specified");
        }

        // Validate port count
        $ports = $hba['port_count'] ?? 0;
        if ($ports <= 0) {
            $result->addError("HBA {$index}: must have at least 1 port");
            return;
        }

        if ($ports > 32) {
            $result->addInfo("HBA {$index}: high port count ({$ports}) - requires powerful backplane");
        }

        // Validate SAS generation
        $gen = $hba['sas_generation'] ?? null;
        if (!$gen) {
            $result->addWarning("HBA {$index}: SAS generation not specified");
        } else {
            $this->validateSASGeneration($hba, $index, $context, $result);
        }

        // Check cache memory
        $cache = $hba['cache_memory_mb'] ?? 0;
        if ($cache > 0) {
            $result->addInfo("HBA {$index}: {$cache}MB cache memory");
        } else {
            $result->addWarning("HBA {$index}: no cache memory specified");
        }

        // Check battery backup
        $hasBattery = $hba['battery_backup'] ?? false;
        if ($hasBattery) {
            $result->addInfo("HBA {$index}: battery-backed cache for data protection");
        } else {
            $result->addWarning("HBA {$index}: no battery backup - data at risk on power loss");
        }

        // Check RAID support
        if (!empty($hba['raid_support'])) {
            $raidLevels = $hba['raid_levels'] ?? [];
            if (!empty($raidLevels)) {
                $result->addInfo("HBA {$index}: supports RAID " . implode(', ', (array)$raidLevels));
            } else {
                $result->addInfo("HBA {$index}: RAID support enabled");
            }
        }
    }

    /**
     * Validate SAS generation
     */
    private function validateSASGeneration(array $hba, int $index, ValidationContext $context, ValidationResult $result): void {
        $gen = $hba['sas_generation'] ?? null;
        $speed = $this->getSASSpeed($gen);

        if (!$speed) {
            $result->addWarning("HBA {$index}: unknown SAS generation '{$gen}'");
            return;
        }

        // Check if storage devices match generation
        $storageDevices = $context->getComponents('storage');
        foreach ($storageDevices as $storage) {
            if (!empty($storage['sas_generation'])) {
                $driveGen = $storage['sas_generation'];
                if ($driveGen > $gen) {
                    $result->addWarning("SAS drive requires {$driveGen} but HBA {$index} is {$gen}");
                }
            }
        }

        $result->addInfo("HBA {$index}: SAS {$gen} ({$speed}Gbps)");
    }

    /**
     * Get SAS generation speed
     */
    private function getSASSpeed(string $gen): ?int {
        $speedMap = [
            'SAS1' => 3,
            'SAS1.1' => 3,
            'SAS2' => 6,
            'SAS2.1' => 6,
            'SAS3' => 12,
            'SAS3.1' => 12,
            'SAS4' => 22,
        ];

        return $speedMap[strtoupper($gen)] ?? null;
    }

    /**
     * Validate overall HBA configuration
     */
    private function validateHBAConfiguration(array $hbaCards, ValidationContext $context, ValidationResult $result): void {
        if (count($hbaCards) === 1) {
            $result->addInfo("Single HBA configuration");
        } elseif (count($hbaCards) > 1) {
            $result->addInfo("Multiple HBA configuration - " . count($hbaCards) . " cards for expanded storage");

            // Check for duplicate HBA models
            $models = array_column($hbaCards, 'model');
            if (count($models) !== count(array_unique($models))) {
                $result->addWarning("Multiple HBA cards with same model - may share resources");
            }
        }

        // Validate PCIe slots for HBAs
        if ($context->hasComponent('motherboard')) {
            $mb = $context->getComponent('motherboard', 0);
            $pcieSlots = $mb['pcie_slots'] ?? 0;

            if (count($hbaCards) > $pcieSlots) {
                $result->addError("More HBA cards (" . count($hbaCards) . ") than motherboard PCIe slots ({$pcieSlots})");
            }
        }
    }
}

?>
