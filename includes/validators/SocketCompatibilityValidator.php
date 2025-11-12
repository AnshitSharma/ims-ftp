<?php

/**
 * Socket Compatibility Validator
 *
 * Validates CPU socket compatibility with motherboard socket.
 *
 * Priority: 100 (Critical - must pass before other CPU validations)
 * Dependencies: None
 *
 * Phase 3 Part 1 - File 17
 */

require_once __DIR__ . '/BaseValidator.php';
require_once __DIR__ . '/../models/ComponentDataService.php';

class SocketCompatibilityValidator extends BaseValidator {

    const PRIORITY = 100;

    private ComponentDataService $componentDataService;

    public function __construct(ComponentDataService $componentDataService = null) {
        $this->componentDataService = $componentDataService ?? new ComponentDataService();
    }

    public function getName(): string {
        return 'Socket Compatibility';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates CPU socket matches motherboard socket';
    }

    public function canRun(ValidationContext $context): bool {
        // Can run if both CPU and motherboard are present
        return $context->hasComponent('cpu') && $context->hasComponent('motherboard');
    }

    /**
     * Validate socket compatibility
     *
     * LOGIC:
     * 1. Get CPU socket from specs
     * 2. Get motherboard socket from specs
     * 3. Compare sockets
     * 4. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $cpu = $context->getComponent('cpu', 0);
            $motherboard = $context->getComponent('motherboard', 0);

            if (!$cpu || !$motherboard) {
                $result->addError('CPU or motherboard not found in configuration');
                return $result;
            }

            $cpuSocket = $cpu['socket'] ?? null;
            $mbSocket = $motherboard['socket'] ?? null;

            if (!$cpuSocket) {
                $result->addError('CPU socket specification missing');
                return $result;
            }

            if (!$mbSocket) {
                $result->addError('Motherboard socket specification missing');
                return $result;
            }

            // Normalize socket names for comparison
            $cpuSocket = $this->normalizeSocket($cpuSocket);
            $mbSocket = $this->normalizeSocket($mbSocket);

            if ($cpuSocket === $mbSocket) {
                $result->addInfo("CPU socket '{$cpuSocket}' matches motherboard socket");
            } else {
                $result->addError("Socket mismatch: CPU is '{$cpuSocket}' but motherboard is '{$mbSocket}'");
            }

        } catch (\Exception $e) {
            $result->addError('Socket validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Normalize socket names for comparison
     */
    private function normalizeSocket(string $socket): string {
        $socket = strtoupper(trim($socket));

        // Map common variants to standard names
        $socketMap = [
            'AM5' => 'AM5',
            'AM4' => 'AM4',
            'LGA1700' => 'LGA1700',
            'LGA1200' => 'LGA1200',
            'TRWP40' => 'TRWP40',
            'TR4' => 'TR4',
            'TRX4' => 'TRX4',
        ];

        return $socketMap[$socket] ?? $socket;
    }
}

?>
