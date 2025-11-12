<?php
/**
 * Infrastructure Management System - Component Data Loader
 * File: includes/models/ComponentDataLoader.php
 *
 * Handles all JSON file loading, database access, and data caching for components
 * Extracted from ComponentCompatibility.php for better maintainability
 */

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

        // Get from database
        $tableMap = [
            'cpu' => 'cpuinventory',
            'motherboard' => 'motherboardinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory',
            'pciecard' => 'pciecardinventory',
            'chassis' => 'chassisinventory',
            'hbacard' => 'hbacardinventory'
        ];

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
        return [
            'chassis' => __DIR__ . '/../../All-JSON/chasis-jsons/chasis-level-3.json',
            'cpu' => __DIR__ . '/../../All-JSON/cpu-jsons/Cpu-details-level-3.json',
            'motherboard' => __DIR__ . '/../../All-JSON/motherboad-jsons/motherboard-level-3.json',
            'ram' => __DIR__ . '/../../All-JSON/Ram-jsons/ram_detail.json',
            'storage' => __DIR__ . '/../../All-JSON/storage-jsons/storage-level-3.json',
            'nic' => __DIR__ . '/../../All-JSON/nic-jsons/nic-level-3.json',
            'caddy' => __DIR__ . '/../../All-JSON/caddy-jsons/caddy_details.json',
            'pciecard' => __DIR__ . '/../../All-JSON/pci-jsons/pci-level-3.json',
            'hbacard' => __DIR__ . '/../../All-JSON/hbacard-jsons/hbacard-level-3.json'
        ];
    }

    /**
     * Load component from JSON by UUID
     * @param string $componentType Component type
     * @param string $uuid Component UUID
     * @return array Result with 'found', 'error', and 'data' keys
     */
    public function loadComponentFromJSON($componentType, $uuid) {
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
     * Load JSON data for component with enhanced debugging
     * @param string $type Component type
     * @param string $uuid Component UUID
     * @return array|null Component data from JSON
     */
    public function loadJSONData($type, $uuid) {
        $jsonPaths = $this->getJSONFilePaths();

        if (!isset($jsonPaths[$type])) {
            error_log("Unknown component type for JSON loading: $type");
            return null;
        }

        $filePath = $jsonPaths[$type];

        if (!file_exists($filePath)) {
            error_log("JSON file not found: $filePath for type: $type");
            return null;
        }

        try {
            $jsonContent = file_get_contents($filePath);
            if ($jsonContent === false) {
                error_log("Failed to read JSON file: $filePath");
                return null;
            }

            $jsonData = json_decode($jsonContent, true);

            if (!$jsonData) {
                error_log("Failed to parse JSON for type: $type, JSON error: " . json_last_error_msg());
                return null;
            }

            error_log("DEBUG: Successfully loaded JSON for type: $type, searching for UUID: $uuid");

        } catch (Exception $e) {
            error_log("Error loading JSON for type $type: " . $e->getMessage());
            return null;
        }

        // Find the specific component by UUID with detailed logging
        $foundComponents = 0;

        // Special handling for caddy JSON structure: {"caddies": [...]}
        if ($type === 'caddy' && isset($jsonData['caddies']) && is_array($jsonData['caddies'])) {
            foreach ($jsonData['caddies'] as $caddy) {
                $foundComponents++;
                $caddyUuid = $caddy['UUID'] ?? $caddy['uuid'] ?? '';
                if ($caddyUuid === $uuid) {
                    error_log("DEBUG: Found caddy $uuid in caddies array");
                    return $caddy;
                }
            }
            error_log("DEBUG: Caddy $uuid not found in caddies array (searched $foundComponents caddies)");
            return null;
        }

        foreach ($jsonData as $brandData) {
            $modelArray = null;

            // Chassis structure: manufacturers -> series -> models
            if ($type === 'chassis' && isset($brandData['manufacturer'])) {
                if (isset($brandData['series']) && is_array($brandData['series'])) {
                    foreach ($brandData['series'] as $series) {
                        if (isset($series['models']) && is_array($series['models'])) {
                            foreach ($series['models'] as $model) {
                                $foundComponents++;
                                $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                                if ($modelUuid === $uuid) {
                                    $manufacturerName = $brandData['manufacturer'] ?? 'Unknown';
                                    error_log("DEBUG: Found chassis $uuid in manufacturer $manufacturerName");
                                    return $model;
                                }
                            }
                        }
                    }
                }
                continue; // Skip to next manufacturer
            }

            // Standard structure: models array directly in brand
            if (isset($brandData['models']) && is_array($brandData['models'])) {
                $modelArray = $brandData['models'];
            }
            // NIC structure: brand -> series -> models (NOT families -> port_configurations)
            elseif (isset($brandData['series']) && is_array($brandData['series'])) {
                foreach ($brandData['series'] as $series) {
                    // Check for direct models array in series
                    if (isset($series['models']) && is_array($series['models'])) {
                        foreach ($series['models'] as $model) {
                            $foundComponents++;
                            $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                            if ($modelUuid === $uuid) {
                                $brandName = $brandData['brand'] ?? 'Unknown';
                                $seriesName = $series['name'] ?? 'Unknown';
                                error_log("DEBUG: Found component $uuid in brand $brandName, series $seriesName");

                                // IMPORTANT: Merge parent-level fields (like component_subtype) into model data
                                // This ensures fields like 'component_subtype' from the brand level are available
                                $enrichedModel = $model;
                                if (isset($brandData['component_subtype'])) {
                                    $enrichedModel['component_subtype'] = $brandData['component_subtype'];
                                }
                                if (isset($brandData['brand'])) {
                                    $enrichedModel['brand'] = $brandData['brand'];
                                }

                                return $enrichedModel;
                            }
                        }
                    }
                    // Fallback: families -> port_configurations (legacy structure)
                    elseif (isset($series['families'])) {
                        foreach ($series['families'] as $family) {
                            if (isset($family['port_configurations'])) {
                                foreach ($family['port_configurations'] as $model) {
                                    $foundComponents++;
                                    $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                                    if ($modelUuid === $uuid) {
                                        $brandName = $brandData['brand'] ?? 'Unknown';
                                        error_log("DEBUG: Found component $uuid in brand $brandName (port_configurations)");
                                        return $model;
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
                    $foundComponents++;
                    $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                    if ($modelUuid === $uuid) {
                        $brandName = $brandData['brand'] ?? 'Unknown';
                        error_log("DEBUG: Found component $uuid in brand $brandName");

                        // IMPORTANT: Merge parent-level fields (like component_subtype) into model data
                        // This ensures fields like 'component_subtype' from the brand level are available
                        $enrichedModel = $model;
                        if (isset($brandData['component_subtype'])) {
                            $enrichedModel['component_subtype'] = $brandData['component_subtype'];
                        }
                        if (isset($brandData['brand'])) {
                            $enrichedModel['brand'] = $brandData['brand'];
                        }

                        return $enrichedModel;
                    }
                }
            }
        }

        error_log("DEBUG: Component $uuid not found in $type JSON (searched $foundComponents components)");
        return null;
    }

    /**
     * Load storage specifications from JSON with UUID validation
     * @param string $uuid Storage UUID
     * @return array|null Storage specifications
     */
    public function loadStorageSpecs($uuid) {
        $jsonPath = __DIR__ . '/../../All-JSON/storage-jsons/storage-level-3.json';

        if (!file_exists($jsonPath)) {
            error_log("Storage JSON file not found: $jsonPath");
            return null;
        }

        $jsonData = json_decode(file_get_contents($jsonPath), true);
        if (!$jsonData) {
            error_log("Failed to decode storage JSON");
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

        error_log("Storage UUID $uuid not found in storage-level-3.json");
        return null;
    }

    /**
     * Load motherboard specifications from JSON with UUID validation
     * @param string $uuid Motherboard UUID
     * @return array|null Motherboard specifications
     */
    public function loadMotherboardSpecs($uuid) {
        $jsonPath = __DIR__ . '/../../All-JSON/motherboad-jsons/motherboard-level-3.json';

        if (!file_exists($jsonPath)) {
            error_log("Motherboard JSON file not found: $jsonPath");
            return null;
        }

        $jsonData = json_decode(file_get_contents($jsonPath), true);
        if (!$jsonData) {
            error_log("Failed to decode motherboard JSON");
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

        error_log("Motherboard UUID $uuid not found in motherboard-level-3.json");
        return null;
    }

    /**
     * Load chassis specifications from JSON with UUID validation
     * @param string $uuid Chassis UUID
     * @return array|null Chassis specifications
     */
    public function loadChassisSpecs($uuid) {
        $jsonPath = __DIR__ . '/../../All-JSON/chasis-jsons/chasis-level-3.json';

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
     * Get motherboard data by UUID
     * @param string $uuid Motherboard UUID
     * @return array Motherboard specifications
     */
    public function getMotherboardData($uuid) {
        // Note: This method uses parseMotherboardSpecifications which is in ComponentCompatibility
        // This method will need to be refactored when parseMotherboardSpecifications is extracted
        // For now, we leave it as a placeholder that will be updated in Phase 4
        return [];
    }

    /**
     * Get chassis data by UUID
     * @param string $uuid Chassis UUID
     * @return array Chassis specifications
     */
    public function getChassisData($uuid) {
        // Parse chassis JSON to get specifications
        $chassisJsonPath = __DIR__ . '/../../All-JSON/chasis-jsons/chasis-level-3.json';

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
        $pcieJsonPath = __DIR__ . '/../../All-JSON/pci-jsons/pci-level-3.json';

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
