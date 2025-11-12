<?php
/**
 * Validation Context
 *
 * Holds all context data needed for validation:
 * - Server configuration
 * - Component being validated
 * - Component specifications
 * - User information
 * - Request metadata
 *
 * Passed to all validators to provide context without repeated database queries.
 */
class ValidationContext {

    /** @var string Configuration UUID */
    private string $configUuid;

    /** @var array Server configuration data */
    private array $configuration;

    /** @var string Component type being validated */
    private string $componentType;

    /** @var string Component UUID being validated */
    private string $componentUuid;

    /** @var array Component specification data */
    private array $componentSpec;

    /** @var array Additional component data (from request) */
    private array $componentData;

    /** @var array User information */
    private array $userInfo;

    /** @var array Request metadata */
    private array $metadata;

    /** @var array Cached data (for performance) */
    private array $cache = [];

    /**
     * Constructor
     *
     * @param string $configUuid Configuration UUID
     * @param array $configuration Full configuration data
     * @param string $componentType Component type
     * @param string $componentUuid Component UUID
     * @param array $componentSpec Component specification
     * @param array $componentData Additional component data
     * @param array $userInfo User information
     */
    public function __construct(
        string $configUuid,
        array $configuration,
        string $componentType,
        string $componentUuid,
        array $componentSpec,
        array $componentData = [],
        array $userInfo = []
    ) {
        $this->configUuid = $configUuid;
        $this->configuration = $configuration;
        $this->componentType = $componentType;
        $this->componentUuid = $componentUuid;
        $this->componentSpec = $componentSpec;
        $this->componentData = $componentData;
        $this->userInfo = $userInfo;
        $this->metadata = [
            'created_at' => date('Y-m-d H:i:s'),
            'validation_id' => uniqid('val_', true)
        ];
    }

    // Getters

    public function getConfigUuid(): string {
        return $this->configUuid;
    }

    public function getConfiguration(): array {
        return $this->configuration;
    }

    public function getComponentType(): string {
        return $this->componentType;
    }

    public function getComponentUuid(): string {
        return $this->componentUuid;
    }

    public function getComponentSpec(): array {
        return $this->componentSpec;
    }

    public function getComponentData(): array {
        return $this->componentData;
    }

    public function getUserInfo(): array {
        return $this->userInfo;
    }

    public function getMetadata(): array {
        return $this->metadata;
    }

    /**
     * Get specific configuration value
     *
     * HELPER METHOD
     *
     * @param string $key Configuration key (supports dot notation: "cpu.0.model")
     * @param mixed $default Default value if not found
     * @return mixed Configuration value or default
     */
    public function getConfigValue(string $key, $default = null) {
        // Support dot notation
        $keys = explode('.', $key);
        $value = $this->configuration;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Get specific component spec value
     *
     * HELPER METHOD
     *
     * @param string $key Spec key (supports dot notation)
     * @param mixed $default Default value if not found
     * @return mixed Spec value or default
     */
    public function getSpecValue(string $key, $default = null) {
        $keys = explode('.', $key);
        $value = $this->componentSpec;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Check if configuration has specific component type
     *
     * HELPER METHOD
     *
     * @param string $componentType Component type to check
     * @return bool True if configuration has this component type
     */
    public function hasComponent(string $componentType): bool {
        return isset($this->configuration[$componentType]) &&
               !empty($this->configuration[$componentType]);
    }

    /**
     * Get all components of specific type from configuration
     *
     * HELPER METHOD
     *
     * @param string $componentType Component type
     * @return array Array of components (empty if none)
     */
    public function getComponents(string $componentType): array {
        return $this->configuration[$componentType] ?? [];
    }

    /**
     * Get single component from configuration
     *
     * HELPER METHOD
     *
     * @param string $componentType Component type
     * @param int $index Component index (default 0)
     * @return array|null Component data or null if not found
     */
    public function getComponent(string $componentType, int $index = 0): ?array {
        $components = $this->getComponents($componentType);
        return $components[$index] ?? null;
    }

    /**
     * Count components of specific type
     *
     * HELPER METHOD
     *
     * @param string $componentType Component type
     * @return int Number of components
     */
    public function countComponents(string $componentType): int {
        return count($this->getComponents($componentType));
    }

    /**
     * Cache arbitrary data in context
     *
     * Used for performance optimization (cache calculated values)
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @return void
     */
    public function setCached(string $key, $value): void {
        $this->cache[$key] = $value;
    }

    /**
     * Get cached data from context
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed Cached value or default
     */
    public function getCached(string $key, $default = null) {
        return $this->cache[$key] ?? $default;
    }

    /**
     * Check if context has cached data
     *
     * @param string $key Cache key
     * @return bool True if cached data exists
     */
    public function hasCached(string $key): bool {
        return isset($this->cache[$key]);
    }

    /**
     * Add metadata to context
     *
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return void
     */
    public function addMetadata(string $key, $value): void {
        $this->metadata[$key] = $value;
    }
}
