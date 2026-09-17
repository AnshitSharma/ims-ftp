<?php
/**
 * Infrastructure Management System - Component Data Loader
 * File: includes/models/ComponentDataLoader.php
 *
 * Handles all JSON file loading, database access, and data caching for components
 * Extracted from ComponentCompatibility.php for better maintainability
 */

require_once __DIR__ . '/ComponentSpecPaths.php';
require_once __DIR__ . '/PlatformSpecIndex.php';
require_once __DIR__ . '/SpecRepository.php';

class ComponentDataLoader {
    private $pdo;
    private $jsonDataCache = [];
    private $dataExtractor;

    /**
     * Constructor
     * @param PDO $pdo Database connection
     * @param ComponentDataExtractor $dataExtractor Data extraction utility
     */
    public function __construct($pdo, $dataExtractor) {
        $this->pdo = $pdo;
        $this->dataExtractor = $dataExtractor;
    }

    /**
     * Clear the JSON data cache
     */
    public function clearCache() {
        $this->jsonDataCache = [];
    }

    /**
     * Get cache stats
     * @return array Cache statistics
     */
    public function getCacheStats() {
        return [
            'cached_components' => count($this->jsonDataCache),
            'cache_keys' => array_keys($this->jsonDataCache)
        ];
    }

    /**
     * Get component data from database and JSON
     * @param string $type Component type
     * @param string $uuid Component UUID
     * @return array|null Combined component data
     */
    public function getComponentData($type, $uuid) {
        // Check cache first
        $cacheKey = "$type:$uuid";
        if (isset($this->jsonDataCache[$cacheKey])) {
            return $this->jsonDataCache[$cacheKey];
        }

        // Get from database. The eleven buildable types, defined once in BaseFunctions.php.
        // This class is only ever reached from ComponentCompatibility's pairwise checks, so
        // 'serverplatform' is correctly absent -- a platform is never one side of a pair.
        if (!function_exists('getBuildableComponentTables')) {
            require_once __DIR__ . '/../../helpers/BaseFunctions.php';
        }
        $tableMap = getBuildableComponentTables();

        $table = $tableMap[$type] ?? null;
        if (!$table) {
            return null;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
        $stmt->execute([$uuid]);
        $dbData = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get JSON specifications
        $jsonData = $this->loadJSONData($type, $uuid);

        // Combine data
        $componentData = array_merge($dbData ?: [], $jsonData ?: []);

        // Cache the result
        $this->jsonDataCache[$cacheKey] = $componentData;

        return $componentData;
    }

    /**
     * Get JSON file paths for all component types
     * @return array Mapping of component types to JSON file paths
     */
    public function getJSONFilePaths() {
        return ComponentSpecPaths::getAll();
    }

    /**
     * Load component from JSON by UUID
     * @param string $componentType Component type
     * @param string $uuid Component UUID
     * @return array Result with 'found', 'error', and 'data' keys
     */
    public function loadComponentFromJSON($componentType, $uuid) {
        // Platform-owned board/chassis resolve through the shared index (see PlatformSpecIndex).
        $platformSpec = PlatformSpecIndex::find($componentType, $uuid);
        if ($platformSpec !== null) {
            return ['found' => true, 'error' => null, 'data' => $platformSpec];
        }

        $jsonPaths = $this->getJSONFilePaths();

        if (!isset($jsonPaths[$componentType])) {
            return [
                'found' => false,
                'error' => "Unknown component type: $componentType",
                'data' => null
            ];
        }

        $filePath = $jsonPaths[$componentType];

        if (!file_exists($filePath)) {
            return [
                'found' => false,
                'error' => "JSON file not found for component type: $componentType",
                'data' => null
            ];
        }

        try {
            $jsonContent = file_get_contents($filePath);
            $jsonData = json_decode($jsonContent, true);

            if (!$jsonData) {
                return [
                    'found' => false,
                    'error' => "Failed to parse JSON for component type: $componentType",
                    'data' => null
                ];
            }

            // Handle chassis special structure: chassis_specifications -> manufacturers
            if ($componentType === 'chassis' && isset($jsonData['chassis_specifications']['manufacturers'])) {
                $jsonData = $jsonData['chassis_specifications']['manufacturers'];
            }

            // Handle caddy special structure: caddies array wrapper
            if ($componentType === 'caddy' && isset($jsonData['caddies'])) {
                // Caddy JSON has direct array of models, not brand/series hierarchy
                foreach ($jsonData['caddies'] as $caddy) {
                    $caddyUuid = $caddy['UUID'] ?? $caddy['uuid'] ?? '';
                    if ($caddyUuid === $uuid) {
                        return [
                            'found' => true,
                            'error' => null,
                            'data' => $caddy
                        ];
                    }
                }
                // Not found in caddies array
                return [
                    'found' => false,
                    'error' => "Component UUID $uuid not found in caddy JSON",
                    'data' => null
                ];
            }

            // Search for component by UUID
            foreach ($jsonData as $brandData) {
                // Handle different JSON structures
                $modelArray = null;

                // Standard structure: models array directly in brand
                if (isset($brandData['models'])) {
                    $modelArray = $brandData['models'];
                }
                // NIC structure: series -> models (NEW format)
                elseif (isset($brandData['series'])) {
                    foreach ($brandData['series'] as $series) {
                        // Check for direct models in series
                        if (isset($series['models'])) {
                            foreach ($series['models'] as $model) {
                                $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                                if ($modelUuid === $uuid) {
                                    return [
                                        'found' => true,
                                        'error' => null,
                                        'data' => $model
                                    ];
                                }
                            }
                        }
                        // OLD NIC structure: series -> families -> port_configurations (for backward compatibility)
                        elseif (isset($series['families'])) {
                            foreach ($series['families'] as $family) {
                                if (isset($family['port_configurations'])) {
                                    foreach ($family['port_configurations'] as $model) {
                                        $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                                        if ($modelUuid === $uuid) {
                                            return [
                                                'found' => true,
                                                'error' => null,
                                                'data' => $model
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // Search through the model array (for standard structure)
                if ($modelArray) {
                    foreach ($modelArray as $model) {
                        $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                        if ($modelUuid === $uuid) {
                            return [
                                'found' => true,
                                'error' => null,
                                'data' => $model
                            ];
                        }
                    }
                }
            }

            return [
                'found' => false,
                'error' => "Component UUID $uuid not found in $componentType JSON",
                'data' => null
            ];

        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => "Error loading JSON for $componentType: " . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Validate component exists in JSON
     * @param string $componentType Component type
     * @param string $uuid Component UUID
     * @return bool True if component exists in JSON
     */
    public function validateComponentExistsInJSON($componentType, $uuid) {
        $result = $this->loadComponentFromJSON($componentType, $uuid);
        return $result['found'];
    }

    /**
     * Load JSON data for a component, by type and UUID.
     *
     * Delegates to SpecRepository as of 2026-09-17 (audit Phase 4 / roadmap item 17 -- the
     * first of four planned adapters; ChassisManager, ComponentDataService and
     * DataExtractionUtilities are unwired, separate changes). This replaces what used to be
     * this class's own file walk (caddy's {"caddies":[...]}, chassis's manufacturers -> series
     * -> models, standard brand -> models, series-based brand -> series -> models, and the
     * legacy families -> port_configurations shape) plus its own PlatformSpecIndex check --
     * SpecRepository checks PlatformSpecIndex first internally, so nothing here needs to.
     *
     * NOT a byte-identical swap: tests/component_data_loader_equivalence.php shows
     * SpecRepository's shape is a SUPERSET (it adds uuid/brand/series/family/
     * component_subtype -- manufacturer/series for chassis, component_type for caddy -- on
     * top of the same spec body; enrichModel() used to add only component_subtype/brand, and
     * only for the two "models" structures, never for chassis/caddy/legacy-NIC). Verified
     * safe despite the non-zero diff by tracing every consumer of this method's return value:
     * getComponentData() merges it into $componentData, which ComponentCompatibility hands
     * only to ComponentDataExtractor's extractXxx() methods (extractSocketType, extractTDP,
     * extractSupportedMemoryTypes, ...) -- each reads a fixed, unrelated key name (socket,
     * tdp, memory_types, ...), never iterates the array generically, never compares it whole.
     * None of the added keys can be read by anything downstream of this method.
     *
     * @param string $type Component type
     * @param string $uuid Component UUID
     * @return array|null Component data from JSON
     */
    public function loadJSONData($type, $uuid) {
        return SpecRepository::getInstance()->find($type, $uuid);
    }

    /**
     * Load storage specifications from JSON with UUID validation
     * @param string $uuid Storage UUID
     * @return array|null Storage specifications
     */
    public function loadStorageSpecs($uuid) {
        $jsonPath = ComponentSpecPaths::getPath('storage');

        if (!file_exists($jsonPath)) {
            return null;
        }

        $jsonData = json_decode(file_get_contents($jsonPath), true);
        if (!$jsonData) {
            return null;
        }

        // First try direct UUID lookup at model level
        foreach ($jsonData as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                        return $this->dataExtractor->extractStorageSpecifications($model);
                    }
                }
            }
        }

        // Then try recursive search in nested structures
        foreach ($jsonData as $brand) {
            if (isset($brand['series'])) {
                foreach ($brand['series'] as $series) {
                    if (isset($series['models'])) {
                        foreach ($series['models'] as $model) {
                            if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                                return $this->dataExtractor->extractStorageSpecifications($model);
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Load motherboard specifications from JSON with UUID validation
     * @param string $uuid Motherboard UUID
     * @return array|null Motherboard specifications
     */
    public function loadMotherboardSpecs($uuid) {
        // Platform-owned boards go through the SAME extractor as catalog boards, so callers
        // cannot tell the two apart (see PlatformSpecIndex).
        $platformSpec = PlatformSpecIndex::find('motherboard', $uuid);
        if ($platformSpec !== null) {
            return $this->dataExtractor->extractMotherboardSpecifications($platformSpec);
        }

        $jsonPath = ComponentSpecPaths::getPath('motherboard');

        if (!file_exists($jsonPath)) {
            return null;
        }

        $jsonData = json_decode(file_get_contents($jsonPath), true);
        if (!$jsonData) {
            return null;
        }

        // Search through the nested structure
        foreach ($jsonData as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                        return $this->dataExtractor->extractMotherboardSpecifications($model);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Load chassis specifications from JSON with UUID validation
     * @param string $uuid Chassis UUID
     * @return array|null Chassis specifications
     */
    public function loadChassisSpecs($uuid) {
        // Platform-owned chassis go through the SAME extractor as catalog chassis (see PlatformSpecIndex).
        $platformSpec = PlatformSpecIndex::find('chassis', $uuid);
        if ($platformSpec !== null) {
            return $this->dataExtractor->extractChassisSpecifications($platformSpec);
        }

        $jsonPath = ComponentSpecPaths::getPath('chassis');

        if (!file_exists($jsonPath)) {
            return null;
        }

        $jsonData = json_decode(file_get_contents($jsonPath), true);
        if (!$jsonData || !isset($jsonData['chassis_specifications'])) {
            return null;
        }

        // Search through the chassis specifications structure
        foreach ($jsonData['chassis_specifications']['manufacturers'] as $manufacturer) {
            foreach ($manufacturer['series'] as $series) {
                foreach ($series['models'] as $model) {
                    if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                        return $this->dataExtractor->extractChassisSpecifications($model);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get chassis data by UUID
     * @param string $uuid Chassis UUID
     * @return array Chassis specifications
     */
    public function getChassisData($uuid) {
        // Platform-owned chassis resolve through the shared index (see PlatformSpecIndex).
        $platformSpec = PlatformSpecIndex::find('chassis', $uuid);
        if ($platformSpec !== null) {
            return $platformSpec;
        }

        // Parse chassis JSON to get specifications
        $chassisJsonPath = ComponentSpecPaths::getPath('chassis');

        if (!isset($this->jsonDataCache[$chassisJsonPath])) {
            if (file_exists($chassisJsonPath)) {
                $jsonContent = file_get_contents($chassisJsonPath);
                $data = json_decode($jsonContent, true);
                $this->jsonDataCache[$chassisJsonPath] = $data['chassis_specifications']['manufacturers'] ?? [];
            } else {
                return [];
            }
        }

        $manufacturers = $this->jsonDataCache[$chassisJsonPath];

        foreach ($manufacturers as $manufacturer) {
            foreach ($manufacturer['series'] as $series) {
                foreach ($series['models'] as $model) {
                    if ($model['uuid'] === $uuid) {
                        return $model;
                    }
                }
            }
        }

        return [];
    }

    /**
     * Get PCIe card data by UUID
     * @param string $uuid PCIe card UUID
     * @return array PCIe card specifications
     */
    public function getPCIeCardData($uuid) {
        // Parse PCIe JSON to get specifications
        $pcieJsonPath = ComponentSpecPaths::getPath('pciecard');

        if (!isset($this->jsonDataCache[$pcieJsonPath])) {
            if (file_exists($pcieJsonPath)) {
                $jsonContent = file_get_contents($pcieJsonPath);
                $data = json_decode($jsonContent, true);
                $this->jsonDataCache[$pcieJsonPath] = $data ?? [];
            } else {
                return [];
            }
        }

        $pcieData = $this->jsonDataCache[$pcieJsonPath];

        foreach ($pcieData as $category) {
            if (isset($category['models'])) {
                foreach ($category['models'] as $model) {
                    if ($model['UUID'] === $uuid) {
                        return $model;
                    }
                }
            }
        }

        return [];
    }
}
?>
