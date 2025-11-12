<?php
/**
 * Base Component Validator
 *
 * Abstract base class for component-specific validators.
 * Provides shared dependencies, helper methods, and standardized response format.
 *
 * All component validators (CPU, Motherboard, RAM, etc.) extend this class
 * to ensure consistency and reduce code duplication.
 *
 * @package BDC_IMS
 * @subpackage Validators
 * @version 1.0
 */

require_once __DIR__ . '/ComponentDataService.php';
require_once __DIR__ . '/DataExtractionUtilities.php';
require_once __DIR__ . '/ComponentCacheManager.php';

abstract class BaseComponentValidator {

    /**
     * @var PDO Database connection
     */
    protected $pdo;

    /**
     * @var ComponentDataService Singleton instance for loading JSON specs
     */
    protected $componentDataService;

    /**
     * @var DataExtractionUtilities Utility class for extracting specs from JSON
     */
    protected $dataUtils;

    /**
     * @var ComponentCacheManager|null Cache manager instance (optional)
     */
    protected $cacheManager;

    /**
     * @var array Component type to inventory table mapping
     */
    protected $componentTables = [
        'chassis' => 'chassisinventory',
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory',
        'pciecard' => 'pciecardinventory',
        'hbacard' => 'hbacardinventory'
    ];

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     * @param ComponentCacheManager|null $cacheManager Optional cache manager
     * @param ComponentDataService|null $dataService Optional data service (uses singleton if not provided)
     */
    public function __construct($pdo, $cacheManager = null, $dataService = null) {
        $this->pdo = $pdo;
        $this->cacheManager = $cacheManager;
        $this->componentDataService = $dataService ?? ComponentDataService::getInstance();
        $this->dataUtils = new DataExtractionUtilities();
    }

    /**
     * Abstract method - must be implemented by child classes
     * Validates addition of a component to configuration
     *
     * @param string $configUuid Server configuration UUID
     * @param string $componentUuid Component UUID being added
     * @param array $existingComponents Existing components in configuration
     * @return array Validation result with structure:
     *               - validation_status: 'allowed'|'allowed_with_warnings'|'blocked'
     *               - critical_errors: array of error objects
     *               - warnings: array of warning objects
     *               - info_messages: array of info objects
     */
    abstract public function validateAddition($configUuid, $componentUuid, $existingComponents);

    // ========================================
    // PROTECTED HELPER METHODS
    // ========================================

    /**
     * Load all existing components in a configuration
     * Organizes by component type for easy access
     *
     * @param string $configUuid Server configuration UUID
     * @return array Organized components by type
     * @throws PDOException on database errors
     */
    protected function getExistingComponents($configUuid) {
        try {
            // Load configuration and extract components from JSON columns
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);
            if (!$config) {
                // Return empty structure if config not found
                return [
                    'cpu' => [],
                    'motherboard' => null,
                    'ram' => [],
                    'storage' => [],
                    'chassis' => null,
                    'nic' => [],
                    'pciecard' => [],
                    'caddy' => [],
                    'hbacard' => []
                ];
            }

            // Use server_api helper function to extract components
            require_once __DIR__ . '/../../api/server/server_api.php';
            $components = extractComponentsFromConfigData($config->getData());

            // Organize by type
            $organized = [
                'cpu' => [],
                'motherboard' => null,
                'ram' => [],
                'storage' => [],
                'chassis' => null,
                'nic' => [],
                'pciecard' => [],
                'caddy' => [],
                'hbacard' => []
            ];

            foreach ($components as $component) {
                $type = $component['component_type'];

                // Single instance components
                if (in_array($type, ['motherboard', 'chassis'])) {
                    $organized[$type] = $component;
                } else {
                    // Multiple instance components
                    $organized[$type][] = $component;
                }
            }

            return $organized;

        } catch (PDOException $e) {
            error_log("BaseComponentValidator::getExistingComponents Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get component specifications from JSON
     * Uses caching for performance optimization
     *
     * @param string $componentType Component type (cpu, ram, motherboard, etc.)
     * @param string $componentUuid Component UUID
     * @return array|null Component specifications or null if not found
     */
    protected function getComponentSpecs($componentType, $componentUuid) {
        try {
            // Check cache first if available
            if ($this->cacheManager) {
                $cached = $this->cacheManager->get("specs_{$componentType}_{$componentUuid}");
                if ($cached !== null) {
                    return $cached;
                }
            }

            // Load from JSON
            $specs = null;

            switch ($componentType) {
                case 'cpu':
                case 'motherboard':
                case 'ram':
                case 'nic':
                case 'caddy':
                case 'pciecard':
                case 'hbacard':
                    $specs = $this->componentDataService->findComponentByUuid($componentType, $componentUuid);
                    break;

                case 'storage':
                    $specs = $this->getStorageSpecs($componentUuid);
                    break;

                case 'chassis':
                    $specs = $this->getChassisSpecs($componentUuid);
                    break;

                default:
                    error_log("BaseComponentValidator: Unknown component type: $componentType");
                    return null;
            }

            // Store in cache if available
            if ($specs && $this->cacheManager) {
                $this->cacheManager->set("specs_{$componentType}_{$componentUuid}", $specs, 3600); // 1 hour cache
            }

            return $specs;

        } catch (Exception $e) {
            error_log("BaseComponentValidator::getComponentSpecs Error for $componentType/$componentUuid: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get storage specifications from JSON
     *
     * @param string $storageUuid Storage component UUID
     * @return array|null Storage specifications
     */
    protected function getStorageSpecs($storageUuid) {
        $jsonPath = dirname(__DIR__, 2) . '/All-JSON/storage-jsons/storage-level-3.json';

        if (!file_exists($jsonPath)) {
            error_log("BaseComponentValidator: Storage JSON not found at $jsonPath");
            return null;
        }

        $jsonContent = file_get_contents($jsonPath);
        $storageData = json_decode($jsonContent, true);

        if (is_array($storageData)) {
            foreach ($storageData as $brand) {
                if (isset($brand['models']) && is_array($brand['models'])) {
                    foreach ($brand['models'] as $model) {
                        if (isset($model['uuid']) && $model['uuid'] === $storageUuid) {
                            return $model;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get chassis specifications from JSON
     *
     * @param string $chassisUuid Chassis component UUID
     * @return array|null Chassis specifications
     */
    protected function getChassisSpecs($chassisUuid) {
        require_once __DIR__ . '/ChassisManager.php';
        $chassisManager = new ChassisManager();
        $result = $chassisManager->loadChassisSpecsByUUID($chassisUuid);

        return $result['found'] ? $result['specifications'] : null;
    }

    /**
     * Get component from database inventory
     *
     * @param string $componentType Component type
     * @param string $componentUuid Component UUID
     * @return array|null Database record or null if not found
     */
    protected function getComponentFromInventory($componentType, $componentUuid) {
        $table = $this->componentTables[$componentType] ?? null;
        if (!$table) {
            error_log("BaseComponentValidator: Unknown table for component type: $componentType");
            return null;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
            $stmt->execute([$componentUuid]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("BaseComponentValidator::getComponentFromInventory Error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Record a validation error
     * Creates standardized error object
     *
     * @param string $type Error type identifier
     * @param string $message Human-readable error message
     * @param string $severity Severity level (critical, high, medium, low)
     * @param array $details Additional error details
     * @param string|null $resolution Suggested resolution
     * @return array Error object
     */
    protected function recordValidationError($type, $message, $severity = 'critical', $details = [], $resolution = null) {
        $error = [
            'type' => $type,
            'severity' => $severity,
            'message' => $message
        ];

        if (!empty($details)) {
            $error['details'] = $details;
        }

        if ($resolution) {
            $error['resolution'] = $resolution;
        }

        // Log critical errors
        if ($severity === 'critical') {
            error_log("Validation Error [$type]: $message");
        }

        return $error;
    }

    /**
     * Record a validation warning
     * Creates standardized warning object
     *
     * @param string $type Warning type identifier
     * @param string $message Human-readable warning message
     * @param string $severity Severity level (high, medium, low)
     * @param array $details Additional warning details
     * @param string|null $performanceImpact Description of performance impact
     * @return array Warning object
     */
    protected function recordValidationWarning($type, $message, $severity = 'medium', $details = [], $performanceImpact = null) {
        $warning = [
            'type' => $type,
            'severity' => $severity,
            'message' => $message
        ];

        if (!empty($details)) {
            $warning['details'] = $details;
        }

        if ($performanceImpact) {
            $warning['performance_impact'] = $performanceImpact;
        }

        return $warning;
    }

    /**
     * Record validation information
     * Creates standardized info object
     *
     * @param string $type Info type identifier
     * @param string $message Human-readable info message
     * @param array $specs Specifications or additional data
     * @return array Info object
     */
    protected function recordValidationInfo($type, $message, $specs = []) {
        $info = [
            'type' => $type,
            'message' => $message
        ];

        if (!empty($specs)) {
            $info['specs'] = $specs;
        }

        return $info;
    }

    /**
     * Build standardized validation response
     *
     * @param array $errors Array of error objects
     * @param array $warnings Array of warning objects
     * @param array $info Array of info objects
     * @return array Standardized validation response
     */
    protected function buildValidationResponse($errors, $warnings, $info) {
        // Determine validation status
        $status = empty($errors)
            ? (empty($warnings) ? 'allowed' : 'allowed_with_warnings')
            : 'blocked';

        return [
            'validation_status' => $status,
            'critical_errors' => $errors,
            'warnings' => $warnings,
            'info_messages' => $info
        ];
    }

    /**
     * Check if RAM memory type is compatible with supported types
     * Matches base type (DDR5 matches DDR5-4800, DDR5-5600, etc.)
     *
     * @param string $ramType RAM memory type (e.g., "DDR5", "DDR4")
     * @param array $supportedTypes Supported memory types (e.g., ["DDR5-4800", "DDR5-5600"])
     * @return bool True if compatible
     */
    protected function isMemoryTypeCompatible($ramType, $supportedTypes) {
        if (empty($supportedTypes)) {
            return true;
        }

        // Extract base RAM type (DDR5, DDR4, etc.) - remove speed suffix
        $ramBaseType = preg_replace('/-\d+$/', '', $ramType);

        foreach ($supportedTypes as $supportedType) {
            // Extract base supported type - remove speed suffix
            $supportedBaseType = preg_replace('/-\d+$/', '', $supportedType);

            // Case-insensitive comparison of base types
            if (strcasecmp($ramBaseType, $supportedBaseType) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract PCIe slot size from component specs
     *
     * @param array $componentSpecs Component specifications
     * @return int PCIe lanes (1, 4, 8, 16, etc.)
     */
    protected function extractPCIeSlotSize($componentSpecs) {
        // Check various field names
        $slotSize = $componentSpecs['pcie_slot_size'] ??
                   $componentSpecs['slot_size'] ??
                   $componentSpecs['pcie_lanes'] ??
                   $componentSpecs['lanes'] ??
                   $componentSpecs['interface'] ?? // For HBA cards: "PCIe 4.0 x8"
                   16; // Default to x16 if not specified

        // Extract numeric value if it's a string like "x16" or "PCIe 4.0 x8"
        if (is_string($slotSize)) {
            // Match patterns like "x8", "x16", "PCIe 3.0 x8", "PCIe 4.0 x16"
            preg_match('/x(\d+)/i', $slotSize, $matches);
            $slotSize = $matches[1] ?? 16;
        }

        return (int)$slotSize;
    }

    /**
     * Parse capacity value from various formats
     * Handles: "16GB", "16", 16, "16 GB"
     *
     * @param mixed $capacity Capacity value in various formats
     * @return int Capacity in GB
     */
    protected function parseCapacity($capacity) {
        if (is_int($capacity)) {
            return $capacity;
        }

        if (is_string($capacity)) {
            // Extract numeric value (e.g., "16GB" → 16)
            preg_match('/(\d+)/', $capacity, $matches);
            return isset($matches[1]) ? (int)$matches[1] : 0;
        }

        return 0;
    }

    /**
     * Parse frequency value from various formats
     * Handles: "3200MHz", "3200", 3200, "3200 MHz"
     *
     * @param mixed $frequency Frequency value in various formats
     * @return int Frequency in MHz
     */
    protected function parseFrequency($frequency) {
        if (is_int($frequency)) {
            return $frequency;
        }

        if (is_string($frequency)) {
            // Extract numeric value (e.g., "3200MHz" → 3200)
            preg_match('/(\d+)/', $frequency, $matches);
            return isset($matches[1]) ? (int)$matches[1] : 0;
        }

        return 0;
    }

    /**
     * Build error response for component not found in JSON
     *
     * @param string $componentType Component type
     * @param string $componentUuid Component UUID
     * @return array Validation response with error
     */
    protected function buildComponentNotFoundResponse($componentType, $componentUuid) {
        return [
            'validation_status' => 'blocked',
            'critical_errors' => [[
                'type' => $componentType . '_not_found_in_json',
                'severity' => 'critical',
                'message' => ucfirst($componentType) . " $componentUuid not found in specifications database",
                'resolution' => "Verify component UUID exists in All-JSON/{$componentType}-jsons/"
            ]],
            'warnings' => [],
            'info_messages' => []
        ];
    }
}
