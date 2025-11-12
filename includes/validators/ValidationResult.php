<?php

/**
 * Validation Result
 *
 * Standardized result format for all validators.
 * Supports method chaining, result merging, and metadata tracking.
 *
 * Result Types:
 * - Error: Blocking issue preventing configuration
 * - Warning: Non-blocking issue but potential problem
 * - Info: Informational message
 *
 * Metadata:
 * - Can store arbitrary key-value data
 * - Used for result enrichment
 * - Supports nested structures
 */
class ValidationResult {

    /** @var bool Success flag */
    private bool $success = true;

    /** @var bool Blocking error present */
    private bool $blocking = false;

    /** @var array Error messages */
    private array $errors = [];

    /** @var array Warning messages */
    private array $warnings = [];

    /** @var array Info messages */
    private array $infos = [];

    /** @var array Metadata */
    private array $metadata = [];

    /**
     * Constructor
     *
     * @param bool $success Initial success state
     */
    private function __construct(bool $success = true) {
        $this->success = $success;
    }

    /**
     * Create success result
     *
     * @return ValidationResult Success result
     */
    public static function success(): ValidationResult {
        return new self(true);
    }

    /**
     * Create failure result
     *
     * @param string $message Error message
     * @param string $code Error code
     * @return ValidationResult Failure result
     */
    public static function failure(string $message, string $code = 'error'): ValidationResult {
        $result = new self(false);
        $result->addError($message, $code);
        return $result;
    }

    /**
     * Create warning result
     *
     * @param string $message Warning message
     * @param string $code Warning code
     * @return ValidationResult Warning result
     */
    public static function warning(string $message, string $code = 'warning'): ValidationResult {
        $result = new self(true);
        $result->addWarning($message, $code);
        return $result;
    }

    /**
     * Add error message
     *
     * @param string $message Error message
     * @param string $code Error code (optional)
     * @return $this For method chaining
     */
    public function addError(string $message, string $code = 'error'): self {
        $this->errors[] = [
            'message' => $message,
            'code' => $code,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        $this->success = false;
        return $this;
    }

    /**
     * Add warning message
     *
     * @param string $message Warning message
     * @param string $code Warning code (optional)
     * @return $this For method chaining
     */
    public function addWarning(string $message, string $code = 'warning'): self {
        $this->warnings[] = [
            'message' => $message,
            'code' => $code,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        return $this;
    }

    /**
     * Add info message
     *
     * @param string $message Info message
     * @return $this For method chaining
     */
    public function addInfo(string $message): self {
        $this->infos[] = [
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        return $this;
    }

    /**
     * Set blocking error flag
     *
     * @param bool $blocking Whether this is blocking
     * @return $this For method chaining
     */
    public function setBlocking(bool $blocking = true): self {
        $this->blocking = $blocking;
        return $this;
    }

    /**
     * Check if has errors
     *
     * @return bool True if has errors
     */
    public function hasErrors(): bool {
        return !empty($this->errors);
    }

    /**
     * Check if has warnings
     *
     * @return bool True if has warnings
     */
    public function hasWarnings(): bool {
        return !empty($this->warnings);
    }

    /**
     * Check if blocking error
     *
     * @return bool True if blocking
     */
    public function isBlocking(): bool {
        return $this->blocking && $this->hasErrors();
    }

    /**
     * Merge another result into this one
     *
     * LOGIC:
     * 1. Combine all errors, warnings, infos
     * 2. Update success flag
     * 3. Preserve metadata
     *
     * @param ValidationResult $other Other result to merge
     * @return $this For method chaining
     */
    public function merge(ValidationResult $other): self {
        $this->errors = array_merge($this->errors, $other->getErrors());
        $this->warnings = array_merge($this->warnings, $other->getWarnings());
        $this->infos = array_merge($this->infos, $other->getInfos());
        $this->success = $this->success && $other->isSuccess();

        if ($other->isBlocking()) {
            $this->blocking = true;
        }

        return $this;
    }

    /**
     * Set metadata
     *
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return $this For method chaining
     */
    public function setMetadata(string $key, $value): self {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Get metadata
     *
     * @param string $key Metadata key
     * @param mixed $default Default value
     * @return mixed Metadata value
     */
    public function getMetadata(string $key, $default = null) {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Get all metadata
     *
     * @return array All metadata
     */
    public function getAllMetadata(): array {
        return $this->metadata;
    }

    /**
     * Check if successful
     *
     * @return bool True if successful
     */
    public function isSuccess(): bool {
        return $this->success;
    }

    /**
     * Get all errors
     *
     * @return array Error messages
     */
    public function getErrors(): array {
        return $this->errors;
    }

    /**
     * Get all warnings
     *
     * @return array Warning messages
     */
    public function getWarnings(): array {
        return $this->warnings;
    }

    /**
     * Get all infos
     *
     * @return array Info messages
     */
    public function getInfos(): array {
        return $this->infos;
    }

    /**
     * Get error count
     *
     * @return int Number of errors
     */
    public function getErrorCount(): int {
        return count($this->errors);
    }

    /**
     * Get warning count
     *
     * @return int Number of warnings
     */
    public function getWarningCount(): int {
        return count($this->warnings);
    }

    /**
     * Get info count
     *
     * @return int Number of infos
     */
    public function getInfoCount(): int {
        return count($this->infos);
    }

    /**
     * Convert to array
     *
     * @return array Array representation
     */
    public function toArray(): array {
        return [
            'success' => $this->success,
            'blocking' => $this->blocking,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'infos' => $this->infos,
            'counts' => [
                'errors' => $this->getErrorCount(),
                'warnings' => $this->getWarningCount(),
                'infos' => $this->getInfoCount(),
            ],
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Convert to JSON
     *
     * @return string JSON representation
     */
    public function toJSON(): string {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get formatted report
     *
     * @return string Formatted validation report
     */
    public function getReport(): string {
        $report = "=== Validation Report ===\n";
        $report .= "Status: " . ($this->success ? "PASSED" : "FAILED") . "\n";
        $report .= "Blocking: " . ($this->blocking ? "YES" : "NO") . "\n\n";

        if ($this->hasErrors()) {
            $report .= "ERRORS (" . count($this->errors) . "):\n";
            foreach ($this->errors as $error) {
                $report .= "  - [{$error['code']}] {$error['message']}\n";
            }
            $report .= "\n";
        }

        if ($this->hasWarnings()) {
            $report .= "WARNINGS (" . count($this->warnings) . "):\n";
            foreach ($this->warnings as $warning) {
                $report .= "  - [{$warning['code']}] {$warning['message']}\n";
            }
            $report .= "\n";
        }

        if (!empty($this->infos)) {
            $report .= "INFO (" . count($this->infos) . "):\n";
            foreach ($this->infos as $info) {
                $report .= "  - {$info['message']}\n";
            }
        }

        return $report;
    }

    /**
     * Clear all messages
     *
     * @return $this For method chaining
     */
    public function clear(): self {
        $this->errors = [];
        $this->warnings = [];
        $this->infos = [];
        $this->success = true;
        $this->blocking = false;
        return $this;
    }

    /**
     * Filter messages by code
     *
     * @param string $code Code to filter by
     * @return array Matching messages
     */
    public function filterByCode(string $code): array {
        $results = [];

        foreach ($this->errors as $error) {
            if ($error['code'] === $code) {
                $results[] = $error;
            }
        }

        foreach ($this->warnings as $warning) {
            if ($warning['code'] === $code) {
                $results[] = $warning;
            }
        }

        return $results;
    }
}
