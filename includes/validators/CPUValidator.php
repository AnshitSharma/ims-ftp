<?php

/**
 * CPU Validator
 *
 * Comprehensive CPU validation including specifications, TDP, compatibility.
 *
 * Priority: 85 (High - validates CPU specs and motherboard support)
 * Dependencies: SocketCompatibilityValidator (must run after)
 *
 * Phase 3 Part 1 - File 19
 */

require_once __DIR__ . '/BaseValidator.php';
require_once __DIR__ . '/../models/ComponentDataService.php';

class CPUValidator extends BaseValidator {

    const PRIORITY = 85;

    private ComponentDataService $componentDataService;

    public function __construct(ComponentDataService $componentDataService = null) {
        $this->componentDataService = $componentDataService ?? new ComponentDataService();
    }

    public function getName(): string {
        return 'CPU Validation';
    }

    public function getPriority(): int {
        return self::PRIORITY;
    }

    public function getDescription(): string {
        return 'Validates CPU specifications and compatibility';
    }

    public function canRun(ValidationContext $context): bool {
        return $context->hasComponent('cpu');
    }

    /**
     * Validate CPU specifications
     *
     * LOGIC:
     * 1. Check required CPU fields (model, socket, cores, tdp)
     * 2. Validate TDP against PSU and cooling capability
     * 3. Validate core count against motherboard support
     * 4. Check for deprecated CPUs
     * 5. Return result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $result = new ValidationResult($this->getName());

        try {
            $cpu = $context->getComponent('cpu', 0);

            if (!$cpu) {
                $result->addError('No CPU found in configuration');
                return $result;
            }

            // Validate required fields
            $this->validateRequiredFields($cpu, $result);

            if (!$result->isValid()) {
                return $result;
            }

            // Validate TDP
            $this->validateTDP($cpu, $context, $result);

            // Validate core count
            $this->validateCoreCount($cpu, $context, $result);

            // Check for deprecated CPUs
            $this->checkDeprecated($cpu, $result);

        } catch (\Exception $e) {
            $result->addError('CPU validation error: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Validate required CPU fields
     */
    private function validateRequiredFields(array $cpu, ValidationResult $result): void {
        $required = ['model', 'socket', 'cores'];

        foreach ($required as $field) {
            if (empty($cpu[$field])) {
                $result->addError("CPU field '{$field}' is required but missing");
            }
        }

        // TDP is highly recommended but not always available
        if (empty($cpu['tdp_watts']) && empty($cpu['tdp'])) {
            $result->addWarning('CPU TDP not specified - cannot validate thermal requirements');
        }
    }

    /**
     * Validate TDP against system capacity
     */
    private function validateTDP(array $cpu, ValidationContext $context, ValidationResult $result): void {
        $cpuTdp = $cpu['tdp_watts'] ?? $cpu['tdp'] ?? null;

        if (!$cpuTdp) {
            return;
        }

        // Check against PSU if present
        if ($context->hasComponent('psu')) {
            $psu = $context->getComponent('psu', 0);
            $psuWattage = $psu['wattage'] ?? 0;

            if ($psuWattage > 0 && $cpuTdp > 0) {
                // CPU TDP should not exceed 40% of PSU wattage (conservative estimate)
                $maxAllowable = $psuWattage * 0.4;
                if ($cpuTdp > $maxAllowable) {
                    $result->addWarning("CPU TDP ({$cpuTdp}W) is high relative to PSU ({$psuWattage}W)");
                }
            }
        }

        // Check for excessive TDP (over 300W for consumer CPUs)
        if ($cpuTdp > 300) {
            $result->addInfo("CPU TDP is {$cpuTdp}W - ensure adequate cooling");
        }
    }

    /**
     * Validate core count support
     */
    private function validateCoreCount(array $cpu, ValidationContext $context, ValidationResult $result): void {
        $cores = $cpu['cores'] ?? null;

        if (!$cores || $cores < 1) {
            $result->addError('CPU core count must be at least 1');
            return;
        }

        if ($cores > 128) {
            $result->addWarning("CPU has {$cores} cores - ensure motherboard and cooling support this");
        }
    }

    /**
     * Check for deprecated CPU models
     */
    private function checkDeprecated(array $cpu, ValidationResult $result): void {
        $model = strtoupper($cpu['model'] ?? '');

        // List of deprecated/older CPU families (not exhaustive)
        $deprecated = [
            'RYZEN 1', 'RYZEN 2', 'RYZEN THREADRIPPER 1',
            'CORE I9-9000', 'XEON W-2100',
        ];

        foreach ($deprecated as $pattern) {
            if (stripos($model, $pattern) !== false) {
                $result->addWarning("CPU model '{$model}' may be end-of-life - verify support availability");
                break;
            }
        }
    }
}

?>
