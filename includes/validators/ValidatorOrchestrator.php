<?php
require_once __DIR__ . '/BaseValidator.php';
require_once __DIR__ . '/primitives/SocketCompatibilityValidator.php';
require_once __DIR__ . '/primitives/FormFactorValidator.php';
require_once __DIR__ . '/primitives/SlotAvailabilityValidator.php';
require_once __DIR__ . '/components/CPUValidator.php';
require_once __DIR__ . '/components/MotherboardValidator.php';
require_once __DIR__ . '/components/RAMValidator.php';
require_once __DIR__ . '/components/StorageValidator.php';
require_once __DIR__ . '/components/ChassisValidator.php';
require_once __DIR__ . '/components/ChassisBackplaneValidator.php';
require_once __DIR__ . '/components/MotherboardStorageValidator.php';
require_once __DIR__ . '/components/HBARequirementValidator.php';
require_once __DIR__ . '/components/PCIeAdapterValidator.php';
require_once __DIR__ . '/components/StorageBayValidator.php';
require_once __DIR__ . '/components/FormFactorLockValidator.php';
require_once __DIR__ . '/components/NVMeSlotValidator.php';
require_once __DIR__ . '/components/NICValidator.php';
require_once __DIR__ . '/components/HBAValidator.php';
require_once __DIR__ . '/components/PCIeCardValidator.php';
require_once __DIR__ . '/components/CaddyValidator.php';

/**
 * Validator Orchestrator
 *
 * Central orchestration system for all component validators.
 * Manages validator lifecycle, execution order, and result aggregation.
 *
 * Responsibilities:
 * 1. Initialize all validators
 * 2. Execute validators in priority order
 * 3. Aggregate validation results
 * 4. Manage error blocking and cascading
 * 5. Provide comprehensive validation reports
 * 6. Track validation metrics and performance
 *
 * Validator Execution Flow:
 * 1. Sort validators by priority (highest first)
 * 2. Check canRun() for each validator
 * 3. Execute validate() method
 * 4. Merge results
 * 5. Stop if critical error (blocking)
 * 6. Continue with dependent validators
 *
 * Priority Scale:
 * 100-90: Socket compatibility (critical)
 * 89-80: Motherboard, CPU, PCIe
 * 79-70: RAM, Storage, Chassis
 * 69-50: Specialized storage, networking
 * 49-0: Accessories, optional components
 */
class ValidatorOrchestrator {

    /** @var array Array of all validators */
    private array $validators = [];

    /** @var ValidationResult Combined result */
    private ValidationResult $result;

    /** @var array Execution metrics */
    private array $metrics = [];

    /**
     * Constructor - Initialize all validators
     *
     * @param ResourceRegistry $registry Resource registry for slot validators
     */
    public function __construct(ResourceRegistry $registry) {
        $this->result = ValidationResult::success();
        $this->initializeValidators($registry);
    }

    /**
     * Initialize all validators
     *
     * LOGIC:
     * 1. Create instances of all validators
     * 2. Set up dependencies
     * 3. Store in array for ordering
     *
     * @param ResourceRegistry $registry Resource registry
     * @return void
     */
    private function initializeValidators(ResourceRegistry $registry): void {
        // Primitives
        $socketValidator = new SocketCompatibilityValidator();
        $formFactorValidator = new FormFactorValidator();
        $slotValidator = new SlotAvailabilityValidator($registry);

        // Components
        $this->validators = [
            // Critical socket validators
            new CPUValidator(),
            new MotherboardValidator(),

            // Memory
            new RAMValidator($slotValidator),

            // Chassis (foundation)
            new ChassisValidator(),

            // Storage orchestrators
            new StorageValidator($slotValidator, $formFactorValidator),
            new ChassisBackplaneValidator(),
            new MotherboardStorageValidator(),
            new HBARequirementValidator(),
            new PCIeAdapterValidator(),
            new StorageBayValidator(),
            new FormFactorLockValidator(),
            new NVMeSlotValidator(),

            // Network
            new NICValidator(),

            // Expansion
            new HBAValidator(),
            new PCIeCardValidator(),

            // Accessories
            new CaddyValidator(),
        ];

        // Sort by priority (descending)
        usort($this->validators, function($a, $b) {
            return $b->getPriority() - $a->getPriority();
        });
    }

    /**
     * Execute full validation
     *
     * LOGIC:
     * 1. Iterate through validators in priority order
     * 2. Check if validator can run
     * 3. Execute validation
     * 4. Merge results
     * 5. Check for blocking errors
     * 6. Return combined result
     *
     * @param ValidationContext $context Validation context
     * @return ValidationResult Combined validation result
     */
    public function validate(ValidationContext $context): ValidationResult {
        $this->result = ValidationResult::success();
        $this->metrics = [
            'total_validators' => count($this->validators),
            'executed_validators' => 0,
            'skipped_validators' => 0,
            'errors' => 0,
            'warnings' => 0,
            'infos' => 0,
            'execution_time_ms' => 0,
        ];

        $startTime = microtime(true);

        foreach ($this->validators as $validator) {
            $validatorName = $validator->getName();

            // Check if validator can run
            if (!$validator->canRun($context)) {
                $this->metrics['skipped_validators']++;
                error_log("[ValidatorOrchestrator] Skipped: {$validatorName}");
                continue;
            }

            // Execute validator
            error_log("[ValidatorOrchestrator] Running: {$validatorName} (priority: {$validator->getPriority()})");

            try {
                $validatorResult = $validator->validate($context);
                $this->result->merge($validatorResult);

                // Count results
                if ($validatorResult->hasErrors()) {
                    $this->metrics['errors']++;
                }
                if ($validatorResult->hasWarnings()) {
                    $this->metrics['warnings']++;
                }

                $this->metrics['executed_validators']++;

                // Check for blocking errors
                if ($validatorResult->isBlocking()) {
                    error_log("[ValidatorOrchestrator] Blocking error in {$validatorName} - stopping validation");
                    break;
                }
            } catch (Exception $e) {
                error_log("[ValidatorOrchestrator] Exception in {$validatorName}: " . $e->getMessage());
                $this->result->addError("Validator exception: {$validatorName}", 'system');
                break;
            }
        }

        $endTime = microtime(true);
        $this->metrics['execution_time_ms'] = round(($endTime - $startTime) * 1000, 2);

        // Add metrics to result metadata
        $this->result->setMetadata('orchestrator_metrics', $this->metrics);

        return $this->result;
    }

    /**
     * Validate only specific component
     *
     * LOGIC:
     * 1. Filter validators for component type
     * 2. Execute relevant validators
     * 3. Return focused result
     *
     * @param ValidationContext $context Validation context
     * @param string $componentType Component type to validate
     * @return ValidationResult Validation result
     */
    public function validateComponent(ValidationContext $context, string $componentType): ValidationResult {
        $result = ValidationResult::success();

        foreach ($this->validators as $validator) {
            // Only run validators that apply to this component
            if (!$validator->canRun($context)) {
                continue;
            }

            // Run validator
            try {
                $validatorResult = $validator->validate($context);
                $result->merge($validatorResult);

                if ($validatorResult->isBlocking()) {
                    break;
                }
            } catch (Exception $e) {
                $result->addError("Validator exception: {$validator->getName()}", 'system');
                break;
            }
        }

        return $result;
    }

    /**
     * Get validator by name
     *
     * PUBLIC HELPER
     *
     * @param string $name Validator name
     * @return BaseValidator|null Validator instance or null
     */
    public function getValidator(string $name): ?BaseValidator {
        foreach ($this->validators as $validator) {
            if ($validator->getName() === $name) {
                return $validator;
            }
        }
        return null;
    }

    /**
     * Get all validators
     *
     * PUBLIC HELPER
     *
     * @return array Array of all validators
     */
    public function getValidators(): array {
        return $this->validators;
    }

    /**
     * Get validators by priority range
     *
     * PUBLIC HELPER
     *
     * @param int $minPriority Minimum priority
     * @param int $maxPriority Maximum priority
     * @return array Filtered validators
     */
    public function getValidatorsByPriority(int $minPriority, int $maxPriority): array {
        return array_filter($this->validators, function($v) use ($minPriority, $maxPriority) {
            $p = $v->getPriority();
            return $p >= $minPriority && $p <= $maxPriority;
        });
    }

    /**
     * Get execution metrics
     *
     * PUBLIC HELPER
     *
     * @return array Execution metrics
     */
    public function getMetrics(): array {
        return $this->metrics;
    }

    /**
     * Get validator execution report
     *
     * PUBLIC HELPER - For debugging/logging
     *
     * @return string Formatted execution report
     */
    public function getExecutionReport(): string {
        $report = "=== Validator Orchestrator Report ===\n";
        $report .= "Total Validators: {$this->metrics['total_validators']}\n";
        $report .= "Executed: {$this->metrics['executed_validators']}\n";
        $report .= "Skipped: {$this->metrics['skipped_validators']}\n";
        $report .= "Errors: {$this->metrics['errors']}\n";
        $report .= "Warnings: {$this->metrics['warnings']}\n";
        $report .= "Execution Time: {$this->metrics['execution_time_ms']}ms\n";
        $report .= "\nValidators by Priority:\n";

        foreach ($this->validators as $validator) {
            $report .= sprintf("  [%3d] %s\n", $validator->getPriority(), $validator->getName());
        }

        return $report;
    }

    /**
     * Validate dependencies for component
     *
     * LOGIC:
     * 1. Get required components for validator
     * 2. Check if they exist in context
     * 3. Return dependency status
     *
     * @param ValidationContext $context Validation context
     * @param BaseValidator $validator Validator to check
     * @return array Dependency check results
     */
    public function validateDependencies(ValidationContext $context, BaseValidator $validator): array {
        $required = $validator->getRequiredComponents();
        $missing = [];

        foreach ($required as $component) {
            if (!$context->hasComponent($component)) {
                $missing[] = $component;
            }
        }

        return [
            'validator' => $validator->getName(),
            'required_components' => $required,
            'missing_components' => $missing,
            'dependencies_met' => empty($missing),
        ];
    }

    /**
     * Get validation summary by category
     *
     * PUBLIC HELPER
     *
     * @return array Summary organized by category
     */
    public function getValidationSummary(): array {
        $summary = [
            'critical' => $this->getValidatorsByPriority(80, 100),
            'high' => $this->getValidatorsByPriority(60, 79),
            'medium' => $this->getValidatorsByPriority(40, 59),
            'low' => $this->getValidatorsByPriority(0, 39),
        ];

        return array_map(function($validators) {
            return array_map(function($v) {
                return ['name' => $v->getName(), 'priority' => $v->getPriority()];
            }, $validators);
        }, $summary);
    }
}
