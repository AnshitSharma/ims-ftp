<?php
require_once __DIR__ . '/../validation/ValidationResult.php';
require_once __DIR__ . '/../validation/ValidationContext.php';

/**
 * Base Validator
 *
 * Abstract base class for all component validators.
 * Provides common functionality and interface.
 *
 * Design:
 * - All validators extend this class
 * - Implement validate() method in subclasses
 * - Override canRun() for conditional execution
 * - Override getRequiredComponents() to declare dependencies
 *
 * Priority System:
 * - 100 = Highest priority (socket validation, critical)
 * - 50 = Medium priority (normal validations)
 * - 1 = Lowest priority (info messages, recommendations)
 *
 * Execution Flow:
 * 1. Check canRun() before executing
 * 2. Execute validate() if canRun() returns true
 * 3. Merge result with overall validation result
 * 4. Short-circuit on blocking errors if configured
 */
abstract class BaseValidator {

    /** @var string Validator name (for logging) */
    protected string $name;

    /** @var int Validation priority (100 = highest) */
    protected int $priority = 50;

    /**
     * Constructor
     *
     * @param string $name Validator name (for logging and identification)
     */
    public function __construct(string $name) {
        $this->name = $name;
    }

    /**
     * Validate component
     *
     * ABSTRACT METHOD - Must be implemented by subclasses
     *
     * @param ValidationContext $context Validation context with all needed data
     * @return ValidationResult Validation result with errors/warnings/info
     */
    abstract public function validate(ValidationContext $context): ValidationResult;

    /**
     * Get validator name
     *
     * @return string Validator name
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Get validation priority
     *
     * Higher priority validators run first.
     * Blocking errors from high-priority validators can short-circuit.
     *
     * @return int Priority (100 = highest, 1 = lowest)
     */
    public function getPriority(): int {
        return $this->priority;
    }

    /**
     * Set validation priority
     *
     * @param int $priority Priority value (1-100)
     * @return self For method chaining
     */
    public function setPriority(int $priority): self {
        $this->priority = max(1, min(100, $priority));
        return $this;
    }

    /**
     * Check if validator can run
     *
     * Override in subclasses to add prerequisites.
     * For example: only run if CPU exists, or if component type is 'storage'.
     *
     * LOGIC:
     * 1. Check prerequisite components exist
     * 2. Check context state is valid
     * 3. Return true if can proceed
     *
     * @param ValidationContext $context Validation context
     * @return bool True if validator can run, false to skip
     */
    public function canRun(ValidationContext $context): bool {
        return true; // By default, always can run
    }

    /**
     * Get required component types for this validator
     *
     * Override in subclasses to declare dependencies.
     * Pipeline will check these exist before running validator.
     *
     * EXAMPLE:
     * ```php
     * public function getRequiredComponents(): array {
     *     return ['motherboard', 'cpu'];
     * }
     * ```
     *
     * @return array Array of required component types (empty if none)
     */
    public function getRequiredComponents(): array {
        return []; // By default, no requirements
    }

    /**
     * Log validation message
     *
     * HELPER METHOD - Used by subclasses for structured logging
     *
     * Logs are stored in application error log with validator name and level.
     * Format: [ValidatorName] [info|warning|error] Message
     *
     * @param string $message Log message
     * @param string $level Log level (info, warning, error)
     * @return void
     */
    protected function log(string $message, string $level = 'info'): void {
        error_log("[{$this->name}] [{$level}] {$message}");
    }

    /**
     * Get component from context safely
     *
     * PROTECTED HELPER METHOD - Used by subclasses
     *
     * Returns first component of given type or null if not found.
     *
     * @param ValidationContext $context Validation context
     * @param string $componentType Component type to retrieve
     * @return array|null Component data or null if not found
     */
    protected function getComponentFromContext(ValidationContext $context, string $componentType): ?array {
        return $context->getComponent($componentType, 0);
    }

    /**
     * Check if component exists in context
     *
     * PROTECTED HELPER METHOD - Used by subclasses
     *
     * @param ValidationContext $context Validation context
     * @param string $componentType Component type to check
     * @return bool True if component exists
     */
    protected function hasComponentInContext(ValidationContext $context, string $componentType): bool {
        return $context->hasComponent($componentType);
    }

    /**
     * Compare two values for equality with fuzzy matching
     *
     * PROTECTED HELPER METHOD - Used by subclasses
     *
     * Handles case-insensitive comparison and whitespace normalization.
     *
     * @param string $value1 First value
     * @param string $value2 Second value
     * @param bool $caseSensitive If false, compare case-insensitively
     * @return bool True if values match
     */
    protected function compareValues(string $value1, string $value2, bool $caseSensitive = false): bool {
        if ($caseSensitive) {
            return trim($value1) === trim($value2);
        }

        return strtolower(trim($value1)) === strtolower(trim($value2));
    }

    /**
     * Normalize string value
     *
     * PROTECTED HELPER METHOD - Used by subclasses
     *
     * Removes extra whitespace, dashes, and normalizes case.
     *
     * @param string $value Value to normalize
     * @param bool $uppercase If true, convert to uppercase
     * @return string Normalized value
     */
    protected function normalizeValue(string $value, bool $uppercase = true): string {
        // Remove extra whitespace
        $normalized = trim($value);

        // Remove common separators for comparison
        $normalized = str_replace(['-', '_', ' '], '', $normalized);

        // Convert case if requested
        if ($uppercase) {
            $normalized = strtoupper($normalized);
        }

        return $normalized;
    }

    /**
     * Create successful validation result
     *
     * PROTECTED HELPER METHOD - Used by subclasses
     *
     * @return ValidationResult New success result
     */
    protected function success(): ValidationResult {
        return ValidationResult::success();
    }

    /**
     * Create failed validation result
     *
     * PROTECTED HELPER METHOD - Used by subclasses
     *
     * @param string $errorMessage Error message
     * @return ValidationResult New failure result
     */
    protected function failure(string $errorMessage): ValidationResult {
        return ValidationResult::failure($errorMessage);
    }

    /**
     * Create warning validation result
     *
     * PROTECTED HELPER METHOD - Used by subclasses
     *
     * @param string $warningMessage Warning message
     * @return ValidationResult New warning result
     */
    protected function warning(string $warningMessage): ValidationResult {
        return ValidationResult::warning($warningMessage);
    }
}
