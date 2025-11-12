<?php

/**
 * Slot Availability Validator
 *
 * Validates overall slot and port availability across the configuration.
 *
 * Priority: 0 (Lowest - final catch-all validation)
 * Dependencies: All component validators
 *
 * Phase 3 Part 2 - File 36
 */

require_once __DIR__ . '/BaseValidator.php';

class SlotAvailabilityValidator extends BaseValidator {

    const PRIORITY = 0;

    public function getName(): string {
        return 'Slot Availability Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Final validation of overall slot and port availability';
    }

    public function canRun(ValidationContext $context): bool {
        // Always run as final validation
        return $context->hasComponent('motherboard');
    }

    /**
     * Validate overall slot availability
     *
     * LOGIC:
     * 1. Get all motherboard slots/ports
     * 2. Count all component requirements
     * 3. Generate slot usage report
     * 4. Identify bottlenecks
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $motherboard = $context->getComponent('motherboard', 0);

            if (!$motherboard) {
                return $result;
            }

            // Build slot usage report
            $slotUsage = $this->calculateSlotUsage($motherboard, $context);
            $this->reportSlotUsage($slotUsage, $result);

            // Identify bottlenecks
            $this->identifyBottlenecks($slotUsage, $result);

        } catch (\Exception $e) {
            $result->addError('Slot availability validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Calculate slot usage across all components
     */
    private function calculateSlotUsage(array $motherboard, ValidationContext $context): array {
        $usage = [
            'pcie_slots' => [
                'total' => $motherboard['pcie_slots'] ?? 0,
                'used' => 0,
                'details' => [],
            ],
            'ram_slots' => [
                'total' => $motherboard['ram_slots'] ?? 0,
                'used' => 0,
                'details' => [],
            ],
            'm2_slots' => [
                'total' => $motherboard['m2_slots'] ?? 0,
                'used' => 0,
                'details' => [],
            ],
            'sata_ports' => [
                'total' => $motherboard['sata_ports'] ?? 0,
                'used' => 0,
                'details' => [],
            ],
            'u2_ports' => [
                'total' => $motherboard['u2_ports'] ?? 0,
                'used' => 0,
                'details' => [],
            ],
        ];

        // Count PCIe slots
        $pcieCards = count($context->getComponents('pciecard'));
        $nics = count($context->getComponents('nic'));
        $hbaCards = count($context->getComponents('hbacard'));

        $usage['pcie_slots']['used'] = $pcieCards + $nics + $hbaCards;
        $usage['pcie_slots']['details'] = [
            'expansion_cards' => $pcieCards,
            'network_cards' => $nics,
            'hba_cards' => $hbaCards,
        ];

        // Count RAM slots
        $ramCount = count($context->getComponents('ram'));
        $usage['ram_slots']['used'] = $ramCount;
        $usage['ram_slots']['details'] = ['modules' => $ramCount];

        // Count storage
        foreach ($context->getComponents('storage') as $storage) {
            $interface = strtoupper($storage['interface'] ?? 'SATA');

            if ($interface === 'NVME') {
                $usage['m2_slots']['used']++;
            } elseif ($interface === 'SATA') {
                $usage['sata_ports']['used']++;
            } elseif ($interface === 'U.2') {
                $usage['u2_ports']['used']++;
            }
        }

        return $usage;
    }

    /**
     * Report slot usage
     */
    private function reportSlotUsage(array $slotUsage, ValidationResult $result): void {
        $result->addInfo("=== Slot Usage Report ===");

        if ($slotUsage['pcie_slots']['total'] > 0) {
            $used = $slotUsage['pcie_slots']['used'];
            $total = $slotUsage['pcie_slots']['total'];
            $percent = round(($used / $total) * 100);

            $msg = "PCIe Slots: {$used}/{$total} used ({$percent}%)";
            if (isset($slotUsage['pcie_slots']['details'])) {
                $msg .= " - " . implode(', ', array_map(
                    fn($k, $v) => "{$k}: {$v}",
                    array_keys($slotUsage['pcie_slots']['details']),
                    array_values($slotUsage['pcie_slots']['details'])
                ));
            }
            $result->addInfo($msg);
        }

        if ($slotUsage['ram_slots']['total'] > 0) {
            $used = $slotUsage['ram_slots']['used'];
            $total = $slotUsage['ram_slots']['total'];
            $percent = round(($used / $total) * 100);
            $result->addInfo("RAM Slots: {$used}/{$total} used ({$percent}%)");
        }

        if ($slotUsage['m2_slots']['total'] > 0) {
            $used = $slotUsage['m2_slots']['used'];
            $total = $slotUsage['m2_slots']['total'];
            $percent = round(($used / $total) * 100);
            $result->addInfo("M.2 Slots: {$used}/{$total} used ({$percent}%)");
        }

        if ($slotUsage['sata_ports']['total'] > 0) {
            $used = $slotUsage['sata_ports']['used'];
            $total = $slotUsage['sata_ports']['total'];
            $percent = round(($used / $total) * 100);
            $result->addInfo("SATA Ports: {$used}/{$total} used ({$percent}%)");
        }

        if ($slotUsage['u2_ports']['total'] > 0) {
            $used = $slotUsage['u2_ports']['used'];
            $total = $slotUsage['u2_ports']['total'];
            $percent = round(($used / $total) * 100);
            $result->addInfo("U.2 Ports: {$used}/{$total} used ({$percent}%)");
        }
    }

    /**
     * Identify slot bottlenecks
     */
    private function identifyBottlenecks(array $slotUsage, ValidationResult $result): void {
        $bottlenecks = [];

        foreach ($slotUsage as $slotType => $data) {
            $total = $data['total'];
            $used = $data['used'];

            if ($total === 0) {
                continue;
            }

            $utilization = ($used / $total) * 100;

            if ($utilization >= 100) {
                $bottlenecks[] = "CRITICAL: {$slotType} fully utilized ({$used}/{$total})";
            } elseif ($utilization >= 90) {
                $bottlenecks[] = "WARNING: {$slotType} nearly full ({$used}/{$total})";
            } elseif ($utilization >= 75) {
                $bottlenecks[] = "INFO: {$slotType} well-utilized ({$used}/{$total})";
            }
        }

        if (!empty($bottlenecks)) {
            $result->addInfo("=== Resource Utilization ===");
            foreach ($bottlenecks as $msg) {
                if (stripos($msg, 'CRITICAL') !== false) {
                    $result->addError($msg);
                } elseif (stripos($msg, 'WARNING') !== false) {
                    $result->addWarning($msg);
                } else {
                    $result->addInfo($msg);
                }
            }
        }
    }
}

?>
