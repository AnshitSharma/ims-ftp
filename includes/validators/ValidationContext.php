<?php

/**
 * Validation Context
 *
 * Context data holder for validation process.
 * Manages component data, specifications, and state.
 *
 * Features:
 * 1. Component storage and retrieval
 * 2. Specification access with dot-notation
 * 3. Internal caching for performance
 * 4. Component counting and querying
 * 5. State management
 */
class ValidationContext {

    /** @var array All components in configuration */
    private array $components = [];

    /** @var string Current component type being validated */
    private string $componentType = 'unknown';

    /** @var array Cached spec values */
    private array $specCache = [];

    /** @var array Configuration metadata */
    private array $metadata = [];

    /**
     * Constructor
     *
     * @param array $components Components array
     * @param string $componentType Current component type
     */
    public function __construct(array $components = [], string $componentType = 'unknown') {
        $this->components = $components;
        $this->componentType = $componentType;
    }

    /**
     * Add component
     *
     * @param string $type Component type
     * @param int $index Component index
     * @param array $data Component data
     * @return $this For method chaining
     */
    public function addComponent(string $type, int $index, array $data): self {
        if (!isset($this->components[$type])) {
            $this->components[$type] = [];
        }
        $this->components[$type][$index] = $data;
        $this->specCache = []; // Clear cache on modification
        return $this;
    }

    /**
     * Get component
     *
     * @param string $type Component type
     * @param int $index Component index
     * @return array|null Component data or null
     */
    public function getComponent(string $type, int $index = 0): ?array {
        if (!isset($this->components[$type][$index])) {
            return null;
        }
        return $this->components[$type][$index];
    }

    /**
     * Get all components of type
     *
     * @param string $type Component type
     * @return array All components of type
     */
    public function getComponents(string $type): array {
        return $this->components[$type] ?? [];
    }

    /**
     * Check if component exists
     *
     * @param string $type Component type
     * @param int $index Component index
     * @return bool True if exists
     */
    public function hasComponent(string $type, int $index = 0): bool {
        return isset($this->components[$type][$index]);
    }

    /**
     * Count components
     *
     * @param string $type Component type
     * @return int Component count
     */
    public function countComponents(string $type): int {
        return count($this->components[$type] ?? []);
    }

    /**
     * Get specification value with dot-notation
     *
     * Examples:
     * - 'socket' => gets $component['socket']
     * - 'memory.type' => gets $component['memory']['type']
     * - 'specs.0.name' => gets $component['specs'][0]['name']
     *
     * @param string $path Dot-notation path
     * @param mixed $default Default value
     * @return mixed Specification value
     */
    public function getSpecValue(string $path, $default = null) {
        // Check cache first
        if (isset($this->specCache[$path])) {
            return $this->specCache[$path];
        }

        $component = $this->getComponent($this->componentType);
        if ($component === null) {
            return $default;
        }

        // Navigate the path
        $parts = explode('.', $path);
        $value = $component;

        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } else {
                $this->specCache[$path] = $default;
                return $default;
            }
        }

        $this->specCache[$path] = $value;
        return $value;
    }

    /**
     * Set specification value
     *
     * @param string $path Dot-notation path
     * @param mixed $value Value to set
     * @return $this For method chaining
     */
    public function setSpecValue(string $path, $value): self {
        $component = $this->getComponent($this->componentType);
        if ($component === null) {
            return $this;
        }

        // Navigate and set
        $parts = explode('.', $path);
        $current = &$component;

        for ($i = 0; $i < count($parts) - 1; $i++) {
            $part = $parts[$i];
            if (!isset($current[$part]) || !is_array($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }

        $current[$parts[count($parts) - 1]] = $value;
        $this->specCache = []; // Clear cache
        return $this;
    }

    /**
     * Set current component type
     *
     * @param string $type Component type
     * @return $this For method chaining
     */
    public function setComponentType(string $type): self {
        $this->componentType = $type;
        return $this;
    }

    /**
     * Get current component type
     *
     * @return string Component type
     */
    public function getComponentType(): string {
        return $this->componentType;
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
     * Get all components
     *
     * @return array All components
     */
    public function getAllComponents(): array {
        return $this->components;
    }

    /**
     * Get summary of configuration
     *
     * @return array Configuration summary
     */
    public function getSummary(): array {
        $summary = [];

        foreach ($this->components as $type => $items) {
            $summary[$type] = count($items);
        }

        return $summary;
    }

    /**
     * Clear all cached specs
     *
     * @return $this For method chaining
     */
    public function clearSpecCache(): self {
        $this->specCache = [];
        return $this;
    }

    /**
     * Clone context
     *
     * @return ValidationContext Cloned context
     */
    public function cloneContext(): ValidationContext {
        $newContext = new ValidationContext($this->components, $this->componentType);
        $newContext->metadata = $this->metadata;
        return $newContext;
    }

    /**
     * Validate context integrity
     *
     * LOGIC:
     * 1. Check for required component fields
     * 2. Verify data types
     * 3. Return validation result
     *
     * @return array Validation results
     */
    public function validateIntegrity(): array {
        $issues = [];

        foreach ($this->components as $type => $items) {
            if (!is_array($items)) {
                $issues[] = "Component type '{$type}' is not an array";
                continue;
            }

            foreach ($items as $index => $component) {
                if (!is_array($component)) {
                    $issues[] = "Component '{$type}[{$index}]' is not an array";
                }
                if (empty($component)) {
                    $issues[] = "Component '{$type}[{$index}]' is empty";
                }
            }
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
        ];
    }
}
