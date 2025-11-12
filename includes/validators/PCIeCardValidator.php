<?php

/**
 * PCIe Card Validator
 *
 * Validates PCIe expansion card compatibility.
 *
 * Priority: 60 (Medium - validates PCIe devices)
 * Dependencies: MotherboardValidator
 *
 * Phase 3 Part 1 - File 23
 */

require_once __DIR__ . '/BaseValidator.php';
require_once __DIR__ . '/../models/ComponentDataService.php';

class PCIeCardValidator extends BaseValidator {

    const PRIORITY = 60;

    private ComponentDataService $componentDataService;

    public function __construct(ComponentDataService $componentDataService = null) {
        $this->componentDataService = $componentDataService ?? new ComponentDataService();
    }

    public function getName(): string {
        return 'PCIe Card Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates PCIe expansion cards and slot compatibility';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->countComponents('pciecard') > 0 ||
               $context->countComponents('nic') > 0 ||
               $context->countComponents('hbacard') > 0;
    }

    /**
     * Validate PCIe cards
     *
     * LOGIC:
     * 1. Count total PCIe slots needed
     * 2. Validate PCIe generation compatibility
     * 3. Check lane requirements
     * 4. Validate physical fit in chassis
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $pcieCards = $context->getComponents('pciecard');
            $nics = $context->getComponents('nic');
            $hbaCards = $context->getComponents('hbacard');

            $totalCards = count($pcieCards) + count($nics) + count($hbaCards);

            if ($totalCards === 0) {
                return $result;
            }

            // Validate motherboard has PCIe slots
            if (!$context->hasComponent('motherboard')) {
                $result->addWarning('No motherboard found - cannot validate PCIe slots');
                return $result;
            }

            $mb = $context->getComponent('motherboard', 0);
            $mbPcieSlots = $mb['pcie_slots'] ?? 0;

            if ($mbPcieSlots === 0) {
                $result->addError('Motherboard has no PCIe slots but configuration includes PCIe cards');
                return $result;
            }

            // Count slots needed
            $slotsNeeded = 0;
            foreach (array_merge($pcieCards, $nics, $hbaCards) as $card) {
                $slotsNeeded += max(1, $card['pcie_slots'] ?? 1);
            }

            if ($slotsNeeded > $mbPcieSlots) {
                $result->addError("Configuration needs {$slotsNeeded} PCIe slots but motherboard has {$mbPcieSlots}");
            } else {
                $result->addInfo("PCIe slot usage: {$slotsNeeded}/{$mbPcieSlots} slots required");
            }

            // Validate each card
            foreach ($pcieCards as $index => $card) {
                $this->validatePCIeCard($card, $index, $context, $result);
            }

            foreach ($nics as $index => $nic) {
                $this->validateNIC($nic, $index, $result);
            }

            foreach ($hbaCards as $index => $hba) {
                $this->validateHBACard($hba, $index, $result);
            }

        } catch (\Exception $e) {
            $result->addError('PCIe validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate generic PCIe card
     */
    private function validatePCIeCard(array $card, int $index, ValidationContext $context, ValidationResult $result): void {
        if (empty($card['model'])) {
            $result->addWarning("PCIe card {$index}: model not specified");
        }

        $pcieGen = $card['pcie_generation'] ?? null;
        $pcieSlots = $card['pcie_slots'] ?? 1;

        if ($pcieSlots < 1 || $pcieSlots > 16) {
            $result->addWarning("PCIe card {$index}: unusual slot count ({$pcieSlots})");
        }

        // Check PCIe generation compatibility
        if ($pcieGen && $context->hasComponent('motherboard')) {
            $mb = $context->getComponent('motherboard', 0);
            $mbGen = $mb['pcie_generation'] ?? 4;

            if ((int)$pcieGen > (int)$mbGen) {
                $result->addWarning("PCIe card {$index} (Gen {$pcieGen}) exceeds motherboard capability (Gen {$mbGen}) - will run at reduced speed");
            }
        }
    }

    /**
     * Validate NIC card
     */
    private function validateNIC(array $nic, int $index, ValidationResult $result): void {
        if (empty($nic['model'])) {
            $result->addWarning("NIC {$index}: model not specified");
        }

        $speed = $nic['speed_gbps'] ?? null;
        if (!$speed) {
            $result->addWarning("NIC {$index}: network speed not specified");
        }

        $lanes = $nic['pcie_lanes'] ?? 4;
        if ($lanes < 1 || $lanes > 16) {
            $result->addWarning("NIC {$index}: unusual PCIe lane requirement ({$lanes})");
        }

        // High-speed NICs typically need x4 or x8 lanes
        if ($speed && $speed >= 25 && $lanes < 4) {
            $result->addWarning("NIC {$index}: {$speed}Gbps NIC with {$lanes} lanes may be insufficient");
        }
    }

    /**
     * Validate HBA card
     */
    private function validateHBACard(array $hba, int $index, ValidationResult $result): void {
        if (empty($hba['model'])) {
            $result->addWarning("HBA card {$index}: model not specified");
        }

        $ports = $hba['port_count'] ?? null;
        if (!$ports || $ports < 1) {
            $result->addWarning("HBA card {$index}: invalid port count");
        }

        $gen = $hba['sas_generation'] ?? null;
        if ($gen) {
            $result->addInfo("HBA card {$index}: SAS {$gen} with {$ports} ports");
        }

        // Check for battery backup if RAID capable
        if (!empty($hba['raid_support']) && empty($hba['battery_backup'])) {
            $result->addWarning("HBA card {$index}: RAID-capable card without battery backup may risk data loss on power failure");
        }
    }
}

?>
