<?php
/**
 * Validation Result
 *
 * Standard format for validation results across all validators.
 *
 * Structure:
 * - success: bool (overall validation success)
 * - code: string (validation status code)
 * - errors: array (blocking errors)
 * - warnings: array (non-blocking issues)
 * - info: array (informational messages)
 * - recommendations: array (optimization suggestions)
 * - metadata: array (additional data for debugging)
 */
class ValidationResult {

    /** Validation status codes */
    const STATUS_COMPATIBLE = 'compatible';
    const STATUS_BLOCKED = 'blocked';
    const STATUS_WARNING = 'warning';
    const STATUS_SKIPPED = 'skipped';

    /** Error severity levels */
    const SEVERITY_BLOCKING = 'blocking';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_INFO = 'info';

    /** @var bool Overall validation success */
    private bool $success;

    /** @var string Validation status code */
    private string $code;

    /** @var array Blocking errors */
    private array $errors = [];

    /** @var array Non-blocking warnings */
    private array $warnings = [];

    /** @var array Informational messages */
    private array $info = [];

    /** @var array Optimization recommendations */
    private array $recommendations = [];

    /** @var array Additional metadata */
    private array $metadata = [];

    /**
     * Constructor
     *
     * @param bool $success Overall success
     * @param string $code Status code (use constants)
     */
    public function __construct(bool $success = true, string $code = self::STATUS_COMPATIBLE) {
        $this->success = $success;
        $this->code = $code;
    }

    /**
     * Create successful validation result
     *
     * FACTORY METHOD
     *
     * @return self New success result
     */
    public static function success(): self {
        return new self(true, self::STATUS_COMPATIBLE);
    }

    /**
     * Create failed validation result
     *
     * FACTORY METHOD
     *
     * @param string $errorMessage Error message
     * @return self New failure result
     */
    public static function failure(string $errorMessage): self {
        $result = new self(false, self::STATUS_BLOCKED);
        $result->addError($errorMessage);
        return $result;
    }

    /**
     * Create warning validation result
     *
     * FACTORY METHOD
     *
     * @param string $warningMessage Warning message
     * @return self New warning result
     */
    public static function warning(string $warningMessage): self {
        $result = new self(true, self::STATUS_WARNING);
        $result->addWarning($warningMessage);
        return $result;
    }

    /**
     * Add blocking error
     *
     * LOGIC:
     * 1. Add error to errors array
     * 2. Set success = false
     * 3. Set code = STATUS_BLOCKED
     *
     * @param string $message Error message
     * @param string|null $field Field that caused error (optional)
     * @return self For method chaining
     */
    public function addError(string $message, ?string $field = null): self {
        $error = [
            'message' => $message,
            'severity' => self::SEVERITY_BLOCKING
        ];

        if ($field !== null) {
            $error['field'] = $field;
        }

        $this->errors[] = $error;
        $this->success = false;
        $this->code = self::STATUS_BLOCKED;

        return $this;
    }

    /**
     * Add non-blocking warning
     *
     * @param string $message Warning message
     * @param string|null $field Field that caused warning (optional)
     * @return self For method chaining
     */
    public function addWarning(string $message, ?string $field = null): self {
        $warning = [
            'message' => $message,
            'severity' => self::SEVERITY_WARNING
        ];

        if ($field !== null) {
            $warning['field'] = $field;
        }

        $this->warnings[] = $warning;

        // Update code if no errors
        if (empty($this->errors) && $this->code === self::STATUS_COMPATIBLE) {
            $this->code = self::STATUS_WARNING;
        }

        return $this;
    }

    /**
     * Add informational message
     *
     * @param string $message Info message
     * @return self For method chaining
     */
    public function addInfo(string $message): self {
        $this->info[] = [
            'message' => $message,
            'severity' => self::SEVERITY_INFO
        ];

        return $this;
    }

    /**
     * Add optimization recommendation
     *
     * @param string $message Recommendation message
     * @param string|null $priority Priority level (high/medium/low)
     * @return self For method chaining
     */
    public function addRecommendation(string $message, ?string $priority = 'medium'): self {
        $this->recommendations[] = [
            'message' => $message,
            'priority' => $priority
        ];

        return $this;
    }

    /**
     * Add metadata
     *
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return self For method chaining
     */
    public function addMetadata(string $key, $value): self {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Merge another validation result into this one
     *
     * LOGIC:
     * 1. Merge all errors
     * 2. Merge all warnings
     * 3. Merge all info
     * 4. Merge all recommendations
     * 5. Merge metadata
     * 6. Update success status (AND operation)
     * 7. Update code (most severe)
     *
     * @param ValidationResult $other Result to merge
     * @return self For method chaining
     */
    public function merge(ValidationResult $other): self {
        // Merge arrays
        $this->errors = array_merge($this->errors, $other->getErrors());
        $this->warnings = array_merge($this->warnings, $other->getWarnings());
        $this->info = array_merge($this->info, $other->getInfo());
        $this->recommendations = array_merge($this->recommendations, $other->getRecommendations());
        $this->metadata = array_merge($this->metadata, $other->getMetadata());

        // Update success (AND operation)
        $this->success = $this->success && $other->isSuccess();

        // Update code (most severe)
        if (!$other->isSuccess()) {
            $this->code = self::STATUS_BLOCKED;
        } elseif ($other->hasWarnings() && $this->code === self::STATUS_COMPATIBLE) {
            $this->code = self::STATUS_WARNING;
        }

        return $this;
    }

    // Getters

    public function isSuccess(): bool {
        return $this->success;
    }

    public function getCode(): string {
        return $this->code;
    }

    public function getErrors(): array {
        return $this->errors;
    }

    public function getWarnings(): array {
        return $this->warnings;
    }

    public function getInfo(): array {
        return $this->info;
    }

    public function getRecommendations(): array {
        return $this->recommendations;
    }

    public function getMetadata(): array {
        return $this->metadata;
    }

    public function hasErrors(): bool {
        return !empty($this->errors);
    }

    public function hasWarnings(): bool {
        return !empty($this->warnings);
    }

    public function hasInfo(): bool {
        return !empty($this->info);
    }

    public function hasRecommendations(): bool {
        return !empty($this->recommendations);
    }

    /**
     * Convert to array format
     *
     * Used for API responses and debugging
     *
     * @return array Result as array
     */
    public function toArray(): array {
        return [
            'success' => $this->success,
            'code' => $this->code,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'info' => $this->info,
            'recommendations' => $this->recommendations,
            'metadata' => $this->metadata
        ];
    }

    /**
     * Convert to JSON string
     *
     * @return string JSON representation
     */
    public function toJson(): string {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}
