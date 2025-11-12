<?php
/**
 * Orchestrator Factory
 *
 * Factory pattern for creating pre-configured ValidatorOrchestrator instances
 * with different profiles for various validation scenarios.
 *
 * Profiles:
 * - FULL: All validators (default, comprehensive)
 * - QUICK: Essential validators only (fast validation)
 * - STORAGE: Storage and HBA validators only
 * - NETWORK: Network and PCIe validators only
 * - THERMAL: Thermal and cooling validators only
 * - CUSTOM: User-defined validator set
 *
 * Phase 5 File 43
 */

require_once __DIR__ . '/ValidatorOrchestrator.php';
require_once __DIR__ . '/BaseValidator.php';
require_once __DIR__ . '/../models/ResourceRegistry.php';

class OrchestratorFactory {

    const PROFILE_FULL = 'full';
    const PROFILE_QUICK = 'quick';
    const PROFILE_STORAGE = 'storage';
    const PROFILE_NETWORK = 'network';
    const PROFILE_THERMAL = 'thermal';
    const PROFILE_CUSTOM = 'custom';

    private static $instances = [];
    private static $cache = [];

    /**
     * Create orchestrator with profile
     *
     * LOGIC:
     * 1. Check cache for existing instance
     * 2. Get validator list for profile
     * 3. Create ValidatorOrchestrator
     * 4. Filter validators by profile
     * 5. Cache and return
     *
     * @param string $profile Profile name (PROFILE_* constants)
     * @param ResourceRegistry|null $registry Optional custom registry
     * @return ValidatorOrchestrator Configured orchestrator
     */
    public static function create($profile = self::PROFILE_FULL, ResourceRegistry $registry = null) {
        // Create cache key
        $cacheKey = "orchestrator_" . $profile;

        // Check instance cache
        if (isset(self::$instances[$cacheKey])) {
            return self::$instances[$cacheKey];
        }

        // Create registry if not provided
        if ($registry === null) {
            $registry = new ResourceRegistry();
        }

        // Create orchestrator
        $orchestrator = new ValidatorOrchestrator($registry);

        // Filter validators by profile
        if ($profile !== self::PROFILE_FULL) {
            self::filterValidatorsByProfile($orchestrator, $profile);
        }

        // Cache instance
        self::$instances[$cacheKey] = $orchestrator;

        return $orchestrator;
    }

    /**
     * Create orchestrator for quick validation
     *
     * Uses only essential validators for fast turnaround:
     * - CPUValidator (priority 85)
     * - MotherboardValidator (priority 80)
     * - RAMValidator (priority 75)
     * - ChassisValidator (priority 72)
     * - StorageValidator (priority 70)
     *
     * Skips: All sub-validators, networking, PCIe, optional components
     *
     * @param ResourceRegistry|null $registry Optional custom registry
     * @return ValidatorOrchestrator Quick validation orchestrator
     */
    public static function createQuick(ResourceRegistry $registry = null) {
        return self::create(self::PROFILE_QUICK, $registry);
    }

    /**
     * Create orchestrator for storage validation
     *
     * Uses only storage-related validators:
     * - StorageValidator
     * - ChassisBackplaneValidator
     * - MotherboardStorageValidator
     * - HBARequirementValidator
     * - StorageBayValidator
     * - NVMeSlotValidator
     * - CaddyValidator
     *
     * @param ResourceRegistry|null $registry Optional custom registry
     * @return ValidatorOrchestrator Storage validation orchestrator
     */
    public static function createStorage(ResourceRegistry $registry = null) {
        return self::create(self::PROFILE_STORAGE, $registry);
    }

    /**
     * Create orchestrator for network validation
     *
     * Uses only network-related validators:
     * - NICValidator (priority 56)
     * - PCIeCardValidator (priority 52)
     * - HBAValidator (priority 54)
     * - SocketCompatibilityValidator (primitives)
     *
     * @param ResourceRegistry|null $registry Optional custom registry
     * @return ValidatorOrchestrator Network validation orchestrator
     */
    public static function createNetwork(ResourceRegistry $registry = null) {
        return self::create(self::PROFILE_NETWORK, $registry);
    }

    /**
     * Create orchestrator for thermal validation
     *
     * Uses validators that affect thermal load:
     * - ChassisValidator (cooling capacity)
     * - CPUValidator (TDP)
     * - RAMValidator (generates minimal heat)
     * - StorageValidator (drives generate heat)
     *
     * @param ResourceRegistry|null $registry Optional custom registry
     * @return ValidatorOrchestrator Thermal validation orchestrator
     */
    public static function createThermal(ResourceRegistry $registry = null) {
        return self::create(self::PROFILE_THERMAL, $registry);
    }

    /**
     * Create custom orchestrator
     *
     * Creates orchestrator and allows custom validator selection
     *
     * @param array $validatorNames List of validator class names to include
     * @param ResourceRegistry|null $registry Optional custom registry
     * @return ValidatorOrchestrator Custom validation orchestrator
     */
    public static function createCustom(array $validatorNames, ResourceRegistry $registry = null) {
        if ($registry === null) {
            $registry = new ResourceRegistry();
        }

        $orchestrator = new ValidatorOrchestrator($registry);
        self::filterValidatorsCustom($orchestrator, $validatorNames);

        return $orchestrator;
    }

    /**
     * Filter validators by profile
     *
     * LOGIC:
     * 1. Get all validators
     * 2. Determine validators to keep for profile
     * 3. Remove others via reflection
     *
     * @param ValidatorOrchestrator $orchestrator Orchestrator to filter
     * @param string $profile Profile name
     * @return void
     */
    private static function filterValidatorsByProfile(ValidatorOrchestrator $orchestrator, $profile) {
        $validators = $orchestrator->getValidators();
        $toKeep = [];

        switch ($profile) {
            case self::PROFILE_QUICK:
                $toKeep = ['CPUValidator', 'MotherboardValidator', 'RAMValidator', 'ChassisValidator', 'StorageValidator'];
                break;

            case self::PROFILE_STORAGE:
                $toKeep = [
                    'StorageValidator',
                    'ChassisBackplaneValidator',
                    'MotherboardStorageValidator',
                    'HBARequirementValidator',
                    'StorageBayValidator',
                    'NVMeSlotValidator',
                    'CaddyValidator',
                    'FormFactorLockValidator',
                ];
                break;

            case self::PROFILE_NETWORK:
                $toKeep = [
                    'NICValidator',
                    'PCIeCardValidator',
                    'HBAValidator',
                    'SocketCompatibilityValidator',
                ];
                break;

            case self::PROFILE_THERMAL:
                $toKeep = [
                    'ChassisValidator',
                    'CPUValidator',
                    'RAMValidator',
                    'StorageValidator',
                ];
                break;

            default:
                return; // No filtering for unknown profiles
        }

        // Filter via reflection (remove unwanted validators)
        self::removeValidators($orchestrator, $validators, $toKeep);
    }

    /**
     * Filter validators to custom set
     *
     * @param ValidatorOrchestrator $orchestrator Orchestrator to filter
     * @param array $validatorNames Names of validators to keep
     * @return void
     */
    private static function filterValidatorsCustom(ValidatorOrchestrator $orchestrator, array $validatorNames) {
        $validators = $orchestrator->getValidators();
        self::removeValidators($orchestrator, $validators, $validatorNames);
    }

    /**
     * Remove validators not in keep list
     *
     * Uses reflection to modify private validators array
     *
     * @param ValidatorOrchestrator $orchestrator Orchestrator
     * @param array $validators Current validators
     * @param array $toKeep Names to keep
     * @return void
     */
    private static function removeValidators(ValidatorOrchestrator $orchestrator, array $validators, array $toKeep) {
        try {
            $reflectionClass = new ReflectionClass(ValidatorOrchestrator::class);
            $validatorsProperty = $reflectionClass->getProperty('validators');
            $validatorsProperty->setAccessible(true);

            $filtered = array_filter($validators, function($validator) use ($toKeep) {
                $className = class_basename(get_class($validator));
                return in_array($className, $toKeep);
            });

            $validatorsProperty->setValue($orchestrator, array_values($filtered));
        } catch (Exception $e) {
            error_log("[OrchestratorFactory] Warning: Could not filter validators: " . $e->getMessage());
        }
    }

    /**
     * Get available profiles
     *
     * @return array List of available profile names
     */
    public static function getAvailableProfiles() {
        return [
            self::PROFILE_FULL,
            self::PROFILE_QUICK,
            self::PROFILE_STORAGE,
            self::PROFILE_NETWORK,
            self::PROFILE_THERMAL,
            self::PROFILE_CUSTOM,
        ];
    }

    /**
     * Get profile description
     *
     * @param string $profile Profile name
     * @return string Description of profile
     */
    public static function getProfileDescription($profile) {
        $descriptions = [
            self::PROFILE_FULL => 'All validators - comprehensive validation',
            self::PROFILE_QUICK => 'Essential validators only - fast validation',
            self::PROFILE_STORAGE => 'Storage and HBA validators - storage-focused validation',
            self::PROFILE_NETWORK => 'Network and PCIe validators - networking-focused validation',
            self::PROFILE_THERMAL => 'Thermal and cooling validators - thermal analysis',
            self::PROFILE_CUSTOM => 'User-defined validator set - custom validation',
        ];

        return $descriptions[$profile] ?? 'Unknown profile';
    }

    /**
     * Clear instance cache
     *
     * Useful for testing or when validators change
     *
     * @return void
     */
    public static function clearCache() {
        self::$instances = [];
        self::$cache = [];
    }

    /**
     * Get cache statistics
     *
     * @return array Cache information
     */
    public static function getCacheStats() {
        return [
            'cached_instances' => count(self::$instances),
            'cache_keys' => array_keys(self::$instances),
            'cached_data' => count(self::$cache),
        ];
    }

    /**
     * List validators by profile
     *
     * @param string $profile Profile name
     * @return array Validator names for profile
     */
    public static function listValidators($profile = self::PROFILE_FULL) {
        $orchestrator = self::create($profile);
        $validators = $orchestrator->getValidators();

        return array_map(function($v) {
            return [
                'name' => $v->getName(),
                'priority' => $v->getPriority(),
                'class' => get_class($v),
            ];
        }, $validators);
    }

    /**
     * Validate profile name
     *
     * @param string $profile Profile name to validate
     * @return bool True if valid profile
     */
    public static function isValidProfile($profile) {
        return in_array($profile, self::getAvailableProfiles());
    }
}
