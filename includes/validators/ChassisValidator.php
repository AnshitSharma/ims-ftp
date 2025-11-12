<?php

/**
 * Chassis Validator
 *
 * Validates overall chassis capabilities and specifications.
 *
 * Priority: 20 (Low - general chassis validation)
 * Dependencies: None
 *
 * Phase 3 Part 2 - File 31
 */

require_once __DIR__ . '/BaseValidator.php';

class ChassisValidator extends BaseValidator {

    const PRIORITY = 20;

    public function getName(): string {
        return 'Chassis Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates chassis specifications and capabilities';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->hasComponent('chassis');
    }

    /**
     * Validate chassis specifications
     *
     * LOGIC:
     * 1. Check required fields
     * 2. Validate drive bay count
     * 3. Check expansion capabilities
     * 4. Validate cooling capacity
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $chassis = $context->getComponent('chassis', 0);

            if (!$chassis) {
                $result->addError('No chassis found in configuration');
                return $result;
            }

            // Validate required fields
            $this->validateRequiredFields($chassis, $result);

            // Validate physical specifications
            $this->validatePhysicalSpecs($chassis, $result);

            // Validate expansion capabilities
            $this->validateExpansionCapability($chassis, $result);

            // Validate cooling capability
            $this->validateCoolingCapability($chassis, $context, $result);

        } catch (\Exception $e) {
            $result->addError('Chassis validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate required chassis fields
     */
    private function validateRequiredFields(array $chassis, ValidationResult $result): void {
        $required = ['model', 'form_factor'];

        foreach ($required as $field) {
            if (empty($chassis[$field])) {
                $result->addError("Chassis field '{$field}' is required but missing");
            }
        }
    }

    /**
     * Validate physical specifications
     */
    private function validatePhysicalSpecs(array $chassis, ValidationResult $result): void {
        $driveBays = $chassis['drive_bays'] ?? 0;
        $expansionSlots = $chassis['expansion_slots'] ?? 0;

        // Check drive bays
        if ($driveBays < 1) {
            $result->addWarning('Chassis has no drive bays');
        } elseif ($driveBays > 1) {
            $result->addInfo("Chassis has {$driveBays} drive bays");
        }

        // Check expansion slots
        if (!empty($chassis['expansion_slots'])) {
            if (is_array($chassis['expansion_slots'])) {
                $slotCount = count($chassis['expansion_slots']);
                $result->addInfo("Chassis has {$slotCount} expansion slot(s)");
            }
        }

        // Validate dimensions if present
        if (!empty($chassis['width_mm']) || !empty($chassis['height_mm']) || !empty($chassis['depth_mm'])) {
            $width = $chassis['width_mm'] ?? 0;
            $height = $chassis['height_mm'] ?? 0;
            $depth = $chassis['depth_mm'] ?? 0;

            if ($width > 0 && $height > 0 && $depth > 0) {
                $result->addInfo("Chassis dimensions: {$width}×{$height}×{$depth}mm");
            }
        }
    }

    /**
     * Validate expansion capabilities
     */
    private function validateExpansionCapability(array $chassis, ValidationResult $result): void {
        $pciSlots = $chassis['pcie_slots'] ?? 0;
        $rackUnits = $chassis['rack_units'] ?? null;

        if ($pciSlots === 0) {
            $result->addWarning('Chassis has no PCIe slots - cannot add expansion cards');
        } else {
            $result->addInfo("Chassis supports {$pciSlots} PCIe slot(s)");
        }

        // Check if rack-mounted
        if ($rackUnits) {
            $result->addInfo("Chassis is {$rackUnits}U rackmount");
        }

        // Check cable management
        if (!empty($chassis['cable_management_mm'])) {
            $result->addInfo("Chassis has {$chassis['cable_management_mm']}mm cable management space");
        }
    }

    /**
     * Validate cooling capability
     */
    private function validateCoolingCapability(array $chassis, ValidationContext $context, ValidationResult $result): void {
        $fans = $chassis['cooling_fans'] ?? 0;
        $maxAirflow = $chassis['max_airflow_cfm'] ?? 0;

        if ($fans === 0) {
            $result->addWarning('Chassis has no pre-installed cooling fans');
        } elseif ($fans === 1) {
            $result->addWarning('Chassis has only 1 cooling fan - limited thermal dissipation');
        } else {
            $result->addInfo("Chassis has {$fans} cooling fan(s)");
        }

        // Check thermal capacity against components
        if ($maxAirflow > 0 && $context->hasComponent('cpu')) {
            $cpu = $context->getComponent('cpu', 0);
            $cpuTdp = $cpu['tdp_watts'] ?? 0;

            if ($cpuTdp > 150 && $maxAirflow < 200) {
                $result->addWarning("High CPU TDP ({$cpuTdp}W) with limited chassis airflow ({$maxAirflow}CFM)");
            }
        }

        // Check thermal pads on internal components
        if (!empty($chassis['thermal_pads'])) {
            $result->addInfo('Chassis includes thermal pads for component cooling');
        }
    }
}

?>
