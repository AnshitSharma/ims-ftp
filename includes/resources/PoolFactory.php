<?php
require_once __DIR__ . '/ResourceRegistry.php';

/**
 * Pool Factory
 *
 * Factory for creating resource pools and registries.
 * Simplifies pool creation with validation and error handling.
 *
 * Responsibilities:
 * 1. Validate input configuration
 * 2. Create ResourceRegistry instance
 * 3. Throw exceptions for invalid configurations
 */
class PoolFactory {

    /**
     * Create resource registry from configuration
     *
     * LOGIC:
     * 1. Validate configuration has required components
     * 2. Create ResourceRegistry
     * 3. Return registry
     *
     * @param string $configUuid Configuration UUID
     * @param array $configuration Full configuration
     * @return ResourceRegistry Resource registry
     * @throws InvalidArgumentException If configuration invalid
     */
    public static function createRegistry(string $configUuid, array $configuration): ResourceRegistry {
        // Validate configuration
        if (empty($configUuid)) {
            throw new InvalidArgumentException('Configuration UUID cannot be empty');
        }

        if (empty($configuration)) {
            throw new InvalidArgumentException('Configuration cannot be empty');
        }

        // Validate required components
        if (!isset($configuration['motherboard']) || empty($configuration['motherboard'])) {
            throw new InvalidArgumentException('Configuration must contain at least one motherboard');
        }

        if (!isset($configuration['cpu']) || empty($configuration['cpu'])) {
            throw new InvalidArgumentException('Configuration must contain at least one CPU');
        }

        // Create registry
        return ResourceRegistry::createFromConfiguration($configUuid, $configuration);
    }

    /**
     * Create resource registry with validation
     *
     * Extended version with additional checks for specific resource requirements.
     *
     * LOGIC:
     * 1. Call createRegistry() for basic validation
     * 2. Check registry for resource availability
     * 3. Log warnings for low-resource configurations
     *
     * @param string $configUuid Configuration UUID
     * @param array $configuration Full configuration
     * @param bool $validateResources If true, check for minimum resources
     * @return ResourceRegistry Resource registry
     * @throws InvalidArgumentException If validation fails
     */
    public static function createRegistryWithValidation(
        string $configUuid,
        array $configuration,
        bool $validateResources = true
    ): ResourceRegistry {
        // Create base registry
        $registry = self::createRegistry($configUuid, $configuration);

        // Validate resources if requested
        if ($validateResources) {
            $bottlenecks = $registry->getBottleneckResources();

            if (!empty($bottlenecks)) {
                error_log(
                    "[PoolFactory] Configuration {$configUuid} has bottleneck resources: " .
                    implode(', ', $bottlenecks)
                );
            }
        }

        return $registry;
    }

    /**
     * Create all individual pools from configuration
     *
     * For scenarios where individual pools are needed instead of a registry.
     *
     * @param array $configuration Full configuration
     * @return array Array of created pools [lane, slot, ram, m2, u2, sata]
     * @throws InvalidArgumentException If configuration invalid
     */
    public static function createIndividualPools(array $configuration): array {
        if (empty($configuration)) {
            throw new InvalidArgumentException('Configuration cannot be empty');
        }

        return [
            'lane' => PCIeLanePool::createFromConfiguration($configuration),
            'slot' => PCIeSlotPool::createFromConfiguration($configuration),
            'ram' => RAMSlotPool::createFromConfiguration($configuration),
            'm2' => M2SlotPool::createFromConfiguration($configuration),
            'u2' => U2SlotPool::createFromConfiguration($configuration),
            'sata' => SATAPortPool::createFromConfiguration($configuration)
        ];
    }

    /**
     * Validate configuration structure
     *
     * Deep validation of configuration format without creating pools.
     *
     * LOGIC:
     * 1. Check required top-level keys
     * 2. Validate component arrays
     * 3. Return validation result
     *
     * @param array $configuration Configuration to validate
     * @return array Validation result [valid => bool, errors => array]
     */
    public static function validateConfiguration(array $configuration): array {
        $errors = [];

        // Check required components
        if (!isset($configuration['motherboard'])) {
            $errors[] = 'Missing required component: motherboard';
        } elseif (!is_array($configuration['motherboard']) || empty($configuration['motherboard'])) {
            $errors[] = 'Motherboard must be a non-empty array';
        }

        if (!isset($configuration['cpu'])) {
            $errors[] = 'Missing required component: cpu';
        } elseif (!is_array($configuration['cpu']) || empty($configuration['cpu'])) {
            $errors[] = 'CPU must be a non-empty array';
        }

        // Check for valid component types
        $validTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'chassis', 'pciecard', 'hbacard'];

        foreach ($configuration as $type => $components) {
            if (!in_array($type, $validTypes) && !in_array($type, ['psus', 'metadata'])) {
                error_log("[PoolFactory] Warning: Unknown component type '{$type}' in configuration");
            }

            if (is_array($components)) {
                // Validate each component has required fields
                foreach ($components as $index => $component) {
                    if (!is_array($component)) {
                        $errors[] = "Component at {$type}[{$index}] is not an array";
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get pool creation requirements
     *
     * Returns information about what configuration data is needed.
     *
     * @return array Requirements specification
     */
    public static function getRequirements(): array {
        return [
            'required_components' => ['motherboard', 'cpu'],
            'optional_components' => ['ram', 'storage', 'nic', 'caddy', 'chassis', 'pciecard', 'hbacard'],
            'pools_created' => [
                'PCIeLanePool' => 'Tracks PCIe lane allocation',
                'PCIeSlotPool' => 'Tracks PCIe slot allocation',
                'RAMSlotPool' => 'Tracks RAM slot allocation',
                'M2SlotPool' => 'Tracks M.2 slot allocation',
                'U2SlotPool' => 'Tracks U.2 slot allocation',
                'SATAPortPool' => 'Tracks SATA port allocation'
            ]
        ];
    }
}
