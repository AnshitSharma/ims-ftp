<?php
/**
 * BDC IMS - Validator Factory
 * File: includes/models/ValidatorFactory.php
 *
 * Factory pattern for creating component validators
 * Centralizes validator instantiation and dependency injection
 *
 * Features:
 * - Singleton factory for reusing validator instances
 * - Lazy loading of validator classes
 * - Automatic dependency injection
 * - Validator registration and discovery
 * - Fallback to main validator for unmapped types
 *
 * Usage:
 * ```php
 * $factory = ValidatorFactory::getInstance($pdo);
 * $cpuValidator = $factory->createValidator('cpu');
 * $result = $cpuValidator->validateAddition($configUuid, $componentUuid, $existing);
 * ```
 */

class ValidatorFactory {
    private static $instance = null;
    private $pdo;
    private $cacheManager;
    private $dataService;
    private $compatibilityEngine;
    private $validators = [];
    private $validatorMap = [
        'cpu' => 'CPUCompatibilityValidator',
        'motherboard' => 'MotherboardCompatibilityValidator',
        'ram' => 'RAMCompatibilityValidator',
        'storage' => 'StorageCompatibilityValidator',
        'chassis' => 'ChassisCompatibilityValidator',
        'nic' => 'NICCompatibilityValidator',
        'pciecard' => 'PCIeCardCompatibilityValidator',
        'caddy' => 'CaddyCompatibilityValidator',
        'hbacard' => 'HBACardCompatibilityValidator'
    ];

    /**
     * Get factory singleton instance
     *
     * @param PDO $pdo Database connection
     * @return self Factory instance
     */
    public static function getInstance($pdo = null) {
        if (self::$instance === null) {
            if ($pdo === null) {
                throw new InvalidArgumentException("PDO connection required for first initialization");
            }
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    /**
     * Constructor (private for singleton)
     *
     * @param PDO $pdo Database connection
     */
    private function __construct($pdo) {
        $this->pdo = $pdo;
        $this->cacheManager = ComponentCacheManager::getInstance();
        $this->dataService = ComponentDataService::getInstance();
        $this->compatibilityEngine = new CompatibilityEngine($pdo);

        // Require base validator class
        $basePath = dirname(__FILE__) . '/validators/BaseComponentValidator.php';
        if (file_exists($basePath)) {
            require_once $basePath;
        }
    }

    /**
     * Create validator for component type
     *
     * @param string $componentType Component type (cpu, motherboard, ram, etc.)
     * @return BaseComponentValidator Validator instance
     * @throws Exception If validator not found
     */
    public function createValidator($componentType) {
        // Return cached instance if exists
        if (isset($this->validators[$componentType])) {
            return $this->validators[$componentType];
        }

        // Check if validator is registered
        if (!isset($this->validatorMap[$componentType])) {
            throw new Exception("No validator registered for component type: $componentType");
        }

        // Create validator instance
        $validatorClass = $this->validatorMap[$componentType];
        $validatorPath = dirname(__FILE__) . "/validators/{$validatorClass}.php";

        if (!file_exists($validatorPath)) {
            throw new Exception("Validator file not found: $validatorPath");
        }

        require_once $validatorPath;

        if (!class_exists($validatorClass)) {
            throw new Exception("Validator class not found: $validatorClass");
        }

        // Instantiate with dependency injection
        $validator = new $validatorClass(
            $this->pdo,
            $this->cacheManager,
            $this->dataService,
            $this->compatibilityEngine
        );

        // Cache instance for reuse
        $this->validators[$componentType] = $validator;

        return $validator;
    }

    /**
     * Get all registered validators
     *
     * @return array Validator map
     */
    public function getRegisteredValidators() {
        return $this->validatorMap;
    }

    /**
     * Register custom validator
     *
     * @param string $componentType Component type
     * @param string $validatorClass Validator class name
     */
    public function registerValidator($componentType, $validatorClass) {
        $this->validatorMap[$componentType] = $validatorClass;
        // Clear cached instance if exists
        unset($this->validators[$componentType]);
    }

    /**
     * Check if validator exists for type
     *
     * @param string $componentType Component type
     * @return bool True if registered
     */
    public function hasValidator($componentType) {
        return isset($this->validatorMap[$componentType]);
    }

    /**
     * Reset factory (clear cached validators)
     */
    public function reset() {
        $this->validators = [];
    }

    /**
     * Validate component addition using appropriate validator
     *
     * @param string $componentType Component type
     * @param string $configUuid Configuration UUID
     * @param string $componentUuid Component UUID
     * @param array $existingComponents Existing components
     * @return array Validation result
     */
    public function validate($componentType, $configUuid, $componentUuid, $existingComponents) {
        try {
            $validator = $this->createValidator($componentType);
            return $validator->validateAddition($configUuid, $componentUuid, $existingComponents);
        } catch (Exception $e) {
            error_log("ValidatorFactory error: " . $e->getMessage());
            return [
                'validation_status' => 'blocked',
                'critical_errors' => [[
                    'type' => 'validator_error',
                    'message' => 'Validation system error: ' . $e->getMessage()
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }
    }

    /**
     * Batch validate multiple components
     *
     * @param array $components Components to validate (format: ['type' => 'component_type', 'uuid' => 'uuid'])
     * @param string $configUuid Configuration UUID
     * @param array $existingComponents Existing components
     * @return array Validation results by component
     */
    public function batchValidate($components, $configUuid, $existingComponents) {
        $results = [];

        foreach ($components as $component) {
            $type = $component['type'] ?? null;
            $uuid = $component['uuid'] ?? null;

            if (!$type || !$uuid) {
                continue;
            }

            $results[$uuid] = $this->validate($type, $configUuid, $uuid, $existingComponents);
        }

        return $results;
    }

    /**
     * Get validator configuration (for debugging/admin)
     *
     * @return array Factory configuration
     */
    public function getConfiguration() {
        return [
            'total_registered' => count($this->validatorMap),
            'cached_validators' => count($this->validators),
            'registered_validators' => array_keys($this->validatorMap),
            'cached_types' => array_keys($this->validators)
        ];
    }

    /**
     * Clear and reinitialize factory
     * Useful after system updates or configuration changes
     */
    public function reinitialize() {
        $this->validators = [];
        error_log("ValidatorFactory reinitialized");
    }
}

?>
