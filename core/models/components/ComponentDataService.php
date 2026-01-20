<?php

class ComponentDataService {
    private static $instance = null;
    private $jsonCache = [];
    private $componentSpecsCache = [];
    private $componentSearchCache = [];
    private $jsonBasePath;
    private $cacheStats = [];
    private $maxCacheSize = 1000; // Maximum cached components
    private $specCache; // ComponentSpecCache instance

    private $componentJsonPaths = [
        'cpu' => 'cpu/Cpu-details-level-3.json',
        'motherboard' => 'motherboard/motherboard-level-3.json',
        'ram' => 'ram/ram_detail.json',
        'storage' => 'storage/storage-level-3.json',
        'nic' => 'nic/nic-level-3.json',
        'caddy' => 'caddy/caddy_details.json',
        'pciecard' => 'pciecard/pci-level-3.json',
        'hbacard' => 'hbacard/hbacard-level-3.json',
        'sfp' => 'sfp/sfp-level-3.json'
    ];

    private function __construct() {
        $this->jsonBasePath = dirname(__DIR__, 3) . '/resources/specifications/';

        // Initialize ComponentSpecCache if available
        $cacheFile = __DIR__ . '/../../cache/ComponentSpecCache.php';
        if (file_exists($cacheFile)) {
            require_once $cacheFile;
            $this->specCache = new ComponentSpecCache();
        } else {
            $this->specCache = null; // Cache not available, will load from JSON each time
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function loadJsonData($componentType) {
        if (isset($this->jsonCache[$componentType])) {
            return $this->jsonCache[$componentType];
        }

        // Try to load from spec cache (persistent cache) if available
        if ($this->specCache !== null) {
            $cached = $this->specCache->getAllSpecsForType($componentType);
            if ($cached !== null) {
                $this->jsonCache[$componentType] = $cached;
                return $cached;
            }
        }

        if (!isset($this->componentJsonPaths[$componentType])) {
            throw new InvalidArgumentException("Unsupported component type: $componentType");
        }

        $filePath = $this->jsonBasePath . $this->componentJsonPaths[$componentType];

        if (!file_exists($filePath)) {
            throw new RuntimeException("JSON file not found: $filePath");
        }

        $jsonContent = file_get_contents($filePath);
        if ($jsonContent === false) {
            throw new RuntimeException("Failed to read JSON file: $filePath");
        }

        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON in file $filePath: " . json_last_error_msg());
        }

        $this->jsonCache[$componentType] = $data;

        // Store in persistent cache before returning (if cache is available)
        if ($this->specCache !== null) {
            $this->specCache->setAllSpecsForType($componentType, $data);
        }

        return $data;
    }

    public function findComponentByUuid($componentType, $uuid, $databaseRecord = null) {
        $jsonData = $this->loadJsonData($componentType);

        // Handle caddy JSON structure: {"caddies": [...]}
        if ($componentType === 'caddy' && isset($jsonData['caddies'])) {
            foreach ($jsonData['caddies'] as $caddy) {
                $caddyUuid = $caddy['uuid'] ?? $caddy['UUID'] ?? null;
                if ($caddyUuid === $uuid) {
                    return array_merge($caddy, [
                        'uuid' => $caddyUuid,
                        'component_type' => 'caddy'
                    ]);
                }
            }
        }

        // Handle chassis JSON structure: manufacturers -> series -> models
        if ($componentType === 'chassis' && isset($jsonData['chassis_specifications']['manufacturers'])) {
            foreach ($jsonData['chassis_specifications']['manufacturers'] as $manufacturer) {
                if (isset($manufacturer['series'])) {
                    foreach ($manufacturer['series'] as $series) {
                        if (isset($series['models'])) {
                            foreach ($series['models'] as $model) {
                                $modelUuid = $this->getOrGenerateUuid($model, $componentType);
                                if ($modelUuid === $uuid) {
                                    return array_merge($model, [
                                        'uuid' => $modelUuid,
                                        'manufacturer' => $manufacturer['manufacturer'] ?? null,
                                        'series' => $series['series_name'] ?? null
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        }

        // Handle NIC and SFP JSON structure: brand -> series -> models
        if ($componentType === 'nic' || $componentType === 'sfp') {
            foreach ($jsonData as $brand) {
                if (isset($brand['series'])) {
                    foreach ($brand['series'] as $series) {
                        if (isset($series['models'])) {
                            foreach ($series['models'] as $model) {
                                $modelUuid = $this->getOrGenerateUuid($model, $componentType);
                                if ($modelUuid === $uuid) {
                                    return array_merge($model, [
                                        'uuid' => $modelUuid,
                                        'brand' => $brand['brand'] ?? null,
                                        'series' => $series['name'] ?? null
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
        }

        // Handle motherboard JSON structure: brand -> series -> family -> models
        if ($componentType === 'motherboard') {
            foreach ($jsonData as $brand) {
                // Check if this has the series -> family -> models structure
                if (isset($brand['series']) && is_string($brand['series']) && isset($brand['family']) && isset($brand['models'])) {
                    foreach ($brand['models'] as $model) {
                        $modelUuid = $this->getOrGenerateUuid($model, $componentType);
                        if ($modelUuid === $uuid) {
                            return array_merge($model, [
                                'uuid' => $modelUuid,
                                'brand' => $brand['brand'] ?? null,
                                'series' => $brand['series'],
                                'family' => $brand['family']
                            ]);
                        }
                    }
                }
                // Fallback to direct models array
                elseif (isset($brand['models'])) {
                    foreach ($brand['models'] as $model) {
                        $modelUuid = $this->getOrGenerateUuid($model, $componentType);
                        if ($modelUuid === $uuid) {
                            return array_merge($model, [
                                'uuid' => $modelUuid,
                                'brand' => $brand['brand'] ?? null,
                                'series' => $brand['series'] ?? null,
                                'family' => $brand['family'] ?? null
                            ]);
                        }
                    }
                }
            }
        }

        // Standard structure: brand -> models (for CPU, RAM, storage, etc.)
        foreach ($jsonData as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    $modelUuid = $this->getOrGenerateUuid($model, $componentType);
                    if ($modelUuid === $uuid) {
                        return array_merge($model, [
                            'uuid' => $modelUuid,
                            'brand' => $brand['brand'] ?? null,
                            'series' => $brand['series'] ?? null,
                            'family' => $brand['family'] ?? null,
                            'component_subtype' => $brand['component_subtype'] ?? null  // Include component_subtype from brand level
                        ]);
                    }
                }
            }
        }

        // If direct match fails and we have database record, try smart matching
        if ($databaseRecord) {
            return $this->findComponentBySmartMatching($componentType, $databaseRecord, $jsonData);
        }

        return null;
    }

    /**
     * Validate if a component UUID exists in JSON files
     * Used by component-add APIs to ensure component exists before adding to inventory
     *
     * @param string $componentType Component type (cpu, ram, storage, motherboard, nic, caddy)
     * @param string $uuid Component UUID to validate
     * @return bool True if UUID exists in JSON, false otherwise
     */
    public function validateComponentUuid($componentType, $uuid) {
        try {
            error_log("ComponentDataService::validateComponentUuid called with type=$componentType, uuid=$uuid");

            // Skip JSON validation for synthetic onboard NIC UUIDs
            if ($componentType === 'nic' && str_starts_with($uuid, 'onboard-nic-')) {
                error_log("Skipping JSON validation for onboard NIC: $uuid");
                return true; // Onboard NICs don't exist in JSON, they are dynamically created
            }

            $jsonData = $this->loadJsonData($componentType);
            error_log("JSON data loaded successfully for $componentType");

            // Handle caddy JSON structure: {"caddies": [...]}
            if ($componentType === 'caddy' && isset($jsonData['caddies'])) {
                error_log("Processing caddy JSON structure, found " . count($jsonData['caddies']) . " caddies");
                foreach ($jsonData['caddies'] as $index => $caddy) {
                    $caddyUuid = $caddy['uuid'] ?? 'NO_UUID';
                    error_log("Checking caddy[$index]: uuid=$caddyUuid vs search=$uuid");
                    if (isset($caddy['uuid']) && $caddy['uuid'] === $uuid) {
                        error_log("UUID validation SUCCESS: $uuid found in caddy JSON at index $index");
                        return true;
                    }
                }
                error_log("UUID validation FAILED: $uuid not found in caddy JSON (searched " . count($jsonData['caddies']) . " items)");
                return false;
            }

            // Handle chassis JSON structure: chassis_specifications -> manufacturers -> series -> models
            if ($componentType === 'chassis' && isset($jsonData['chassis_specifications']['manufacturers'])) {
                error_log("Processing chassis JSON structure");
                foreach ($jsonData['chassis_specifications']['manufacturers'] as $manufacturer) {
                    if (isset($manufacturer['series'])) {
                        foreach ($manufacturer['series'] as $series) {
                            if (isset($series['models'])) {
                                foreach ($series['models'] as $model) {
                                    $modelUuid = $this->getOrGenerateUuid($model, $componentType);
                                    if ($modelUuid === $uuid) {
                                        error_log("UUID validation SUCCESS: $uuid found in chassis JSON");
                                        return true;
                                    }
                                }
                            }
                        }
                    }
                }
                error_log("UUID validation FAILED: $uuid not found in chassis JSON");
                return false;
            }

            // Handle NIC and SFP JSON structure: [...{brand, series: [{name, models: [...]}]}]
            if ($componentType === 'nic' || $componentType === 'sfp') {
                foreach ($jsonData as $brand) {
                    if (isset($brand['series'])) {
                        foreach ($brand['series'] as $series) {
                            if (isset($series['models'])) {
                                foreach ($series['models'] as $model) {
                                    $modelUuid = $this->getOrGenerateUuid($model, $componentType);
                                    if ($modelUuid === $uuid) {
                                        error_log("UUID validation SUCCESS: $uuid found in $componentType JSON");
                                        return true;
                                    }
                                }
                            }
                        }
                    }
                }
                error_log("UUID validation FAILED: $uuid not found in $componentType JSON");
                return false;
            }

            // Handle standard brand/models structure (cpu, motherboard, storage, ram)
            foreach ($jsonData as $brand) {
                if (isset($brand['models'])) {
                    foreach ($brand['models'] as $model) {
                        $modelUuid = $this->getOrGenerateUuid($model, $componentType);
                        if ($modelUuid === $uuid) {
                            error_log("UUID validation SUCCESS: $uuid found in $componentType JSON");
                            return true;
                        }
                    }
                }
            }

            error_log("UUID validation FAILED: $uuid not found in $componentType JSON");
            return false;

        } catch (Exception $e) {
            error_log("UUID validation ERROR for $componentType: " . $e->getMessage());
            return false;
        }
    }

    public function findComponentByModel($componentType, $brand, $model) {
        $jsonData = $this->loadJsonData($componentType);
        
        foreach ($jsonData as $brandData) {
            if (strcasecmp($brandData['brand'] ?? '', $brand) === 0) {
                if (isset($brandData['models'])) {
                    foreach ($brandData['models'] as $modelData) {
                        if (strcasecmp($modelData['model'] ?? '', $model) === 0) {
                            return array_merge($modelData, [
                                'uuid' => $this->getOrGenerateUuid($modelData, $componentType),
                                'brand' => $brandData['brand'] ?? null,
                                'series' => $brandData['series'] ?? null,
                                'family' => $brandData['family'] ?? null
                            ]);
                        }
                    }
                }
            }
        }
        
        return null;
    }

    private function getOrGenerateUuid($model, $componentType) {
        // Check both 'uuid' and 'UUID' (JSON may have either)
        if (isset($model['uuid'])) {
            return $model['uuid'];
        }
        if (isset($model['UUID'])) {
            return $model['UUID'];
        }

        return $this->generateUuidFromModel($model, $componentType);
    }

    private function generateUuidFromModel($model, $componentType) {
        $identifier = $componentType . '-' . ($model['model'] ?? 'unknown');
        
        if (isset($model['brand'])) {
            $identifier = $model['brand'] . '-' . $identifier;
        }
        
        return 'generated-' . md5($identifier);
    }

    public function getComponentSpecifications($componentType, $uuid, $databaseRecord = null) {
        $component = $this->findComponentByUuid($componentType, $uuid, $databaseRecord);
        
        if (!$component) {
            // If no JSON match found, return basic specs from database record
            if ($databaseRecord) {
                return $this->extractBasicSpecsFromDatabase($componentType, $databaseRecord);
            }
            return null;
        }

        switch ($componentType) {
            case 'cpu':
                return $this->extractCpuSpecs($component);
            case 'motherboard':
                return $this->extractMotherboardSpecs($component);
            case 'ram':
                return $this->extractRamSpecs($component);
            case 'storage':
                return $this->extractStorageSpecs($component);
            case 'nic':
                return $this->extractNicSpecs($component);
            case 'caddy':
                return $this->extractCaddySpecs($component);
            case 'sfp':
                return $this->extractSfpSpecs($component);
            default:
                return $component;
        }
    }

    private function extractCpuSpecs($component) {
        return [
            'uuid' => $component['uuid'] ?? null,
            'brand' => $component['brand'] ?? '',
            'model' => $component['model'] ?? '',
            'socket' => $component['socket'] ?? '',
            'cores' => $component['cores'] ?? 0,
            'threads' => $component['threads'] ?? 0,
            'base_frequency_ghz' => $component['base_frequency_GHz'] ?? 0,
            'max_frequency_ghz' => $component['max_frequency_GHz'] ?? 0,
            'tdp_w' => $component['tdp_W'] ?? 0,
            'memory_types' => $component['memory_types'] ?? [],
            'memory_channels' => $component['memory_channels'] ?? 0,
            'max_memory_capacity_tb' => $component['max_memory_capacity_TB'] ?? 0,
            'pcie_lanes' => $component['pcie_lanes'] ?? 0,
            'pcie_generation' => $component['pcie_generation'] ?? 0,
            'power_efficiency' => $component['power_efficiency'] ?? [],
            'match_confidence' => $component['match_confidence'] ?? 1.0,
            'matched_by' => $component['matched_by'] ?? 'direct_match'
        ];
    }

    private function extractMotherboardSpecs($component) {
        $socket = $component['socket'] ?? [];
        // Normalize socket format
        if (is_array($socket)) {
            $socketType = $socket['type'] ?? null;
            $socketCount = $socket['count'] ?? 1;
        } else {
            $socketType = $socket;
            $socketCount = 1;
        }
        
        return [
            'uuid' => $component['uuid'] ?? null,
            'brand' => $component['brand'] ?? '',
            'model' => $component['model'] ?? '',
            'form_factor' => $component['form_factor'] ?? '',
            'socket_type' => $socketType,
            'socket_count' => $socketCount,
            'socket' => $socket, // Keep original for backward compatibility
            'chipset' => $component['chipset'] ?? '',
            'memory' => $component['memory'] ?? [],
            'expansion_slots' => $component['expansion_slots'] ?? [],
            'storage' => $component['storage'] ?? [],
            'storage_interfaces' => $component['storage_interfaces'] ?? [],
            'network_interfaces' => $component['network_interfaces'] ?? [],
            'power_connectors' => $component['power_connectors'] ?? [],
            'dimensions' => $component['dimensions'] ?? [],
            'match_confidence' => $component['match_confidence'] ?? 1.0,
            'matched_by' => $component['matched_by'] ?? 'direct_match'
        ];
    }

    private function extractRamSpecs($component) {
        return [
            'brand' => $component['brand'] ?? '',
            'memory_type' => $component['memory_type'] ?? '',
            'module_type' => $component['module_type'] ?? '',
            'form_factor' => $component['form_factor'] ?? '',
            'capacity_gb' => $component['capacity_GB'] ?? 0,
            'frequency_mhz' => $component['frequency_MHz'] ?? 0,
            'speed_mts' => $component['speed_MTs'] ?? 0,
            'timing' => $component['timing'] ?? [],
            'voltage_v' => $component['voltage_V'] ?? 0,
            'features' => $component['features'] ?? []
        ];
    }

    private function extractStorageSpecs($component) {
        return [
            'brand' => $component['brand'] ?? '',
            'storage_type' => $component['storage_type'] ?? '',
            'subtype' => $component['subtype'] ?? '',
            'form_factor' => $component['form_factor'] ?? '',
            'interface' => $component['interface'] ?? '',
            'capacity_gb' => $component['capacity_GB'] ?? 0,
            'specifications' => $component['specifications'] ?? [],
            'power_consumption_w' => $component['power_consumption_W'] ?? []
        ];
    }

    private function extractNicSpecs($component) {
        return [
            'brand' => $component['brand'] ?? '',
            'model' => $component['model'] ?? '',
            'interface_type' => $component['interface_type'] ?? '',
            'form_factor' => $component['form_factor'] ?? '',
            'ports' => $component['ports'] ?? [],
            'specifications' => $component['specifications'] ?? []
        ];
    }

    private function extractCaddySpecs($component) {
        return [
            'brand' => $component['brand'] ?? '',
            'model' => $component['model'] ?? '',
            'form_factor' => $component['form_factor'] ?? '',
            'compatibility' => $component['compatibility'] ?? [],
            'specifications' => $component['specifications'] ?? []
        ];
    }

    private function extractSfpSpecs($component) {
        return [
            'uuid' => $component['uuid'] ?? null,
            'brand' => $component['brand'] ?? '',
            'model' => $component['model'] ?? '',
            'type' => $component['type'] ?? '',
            'speed' => $component['speed'] ?? '',
            'wavelength' => $component['wavelength'] ?? '',
            'reach' => $component['reach'] ?? '',
            'connector' => $component['connector'] ?? '',
            'fiber_type' => $component['fiber_type'] ?? '',
            'temperature_range' => $component['temperature_range'] ?? '',
            'power_consumption' => $component['power_consumption'] ?? '',
            'compatible_interfaces' => $component['compatible_interfaces'] ?? [],
            'features' => $component['features'] ?? []
        ];
    }

    public function getAllAvailableComponents($componentType) {
        $jsonData = $this->loadJsonData($componentType);
        $components = [];
        
        foreach ($jsonData as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    $components[] = array_merge($model, [
                        'uuid' => $this->getOrGenerateUuid($model, $componentType),
                        'brand' => $brand['brand'] ?? null,
                        'series' => $brand['series'] ?? null,
                        'family' => $brand['family'] ?? null
                    ]);
                }
            }
        }
        
        return $components;
    }

    public function searchComponents($componentType, $searchTerm) {
        $jsonData = $this->loadJsonData($componentType);
        $components = [];
        $searchTerm = strtolower($searchTerm);
        
        foreach ($jsonData as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    $modelName = strtolower($model['model'] ?? '');
                    $brandName = strtolower($brand['brand'] ?? '');
                    
                    if (strpos($modelName, $searchTerm) !== false || 
                        strpos($brandName, $searchTerm) !== false) {
                        $components[] = array_merge($model, [
                            'uuid' => $this->getOrGenerateUuid($model, $componentType),
                            'brand' => $brand['brand'] ?? null,
                            'series' => $brand['series'] ?? null,
                            'family' => $brand['family'] ?? null
                        ]);
                    }
                }
            }
        }
        
        return $components;
    }

    public function getCompatibleComponents($componentType, $constraints = []) {
        $allComponents = $this->getAllAvailableComponents($componentType);
        
        if (empty($constraints)) {
            return $allComponents;
        }
        
        $filtered = [];
        foreach ($allComponents as $component) {
            $specs = $this->getComponentSpecifications($componentType, $component['uuid']);
            
            if ($this->meetsConstraints($specs, $constraints)) {
                $filtered[] = $component;
            }
        }
        
        return $filtered;
    }

    private function meetsConstraints($specs, $constraints) {
        foreach ($constraints as $field => $value) {
            if (!isset($specs[$field])) {
                return false;
            }
            
            if (is_array($value)) {
                if (!in_array($specs[$field], $value)) {
                    return false;
                }
            } else {
                if ($specs[$field] !== $value) {
                    return false;
                }
            }
        }
        
        return true;
    }

    public function clearCache($componentType = null) {
        if ($componentType && isset($this->jsonCache[$componentType])) {
            unset($this->jsonCache[$componentType]);
        } else {
            $this->jsonCache = [];
        }
    }

    /**
     * Smart matching: Find JSON component by serial number, model name, or notes
     */
    private function findComponentBySmartMatching($componentType, $databaseRecord, $jsonData) {
        $serialNumber = $databaseRecord['SerialNumber'] ?? '';
        $notes = $databaseRecord['Notes'] ?? '';
        
        // Extract potential model name from notes or serial number
        $potentialMatches = $this->extractPotentialMatches($notes, $serialNumber);
        
        foreach ($jsonData as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    // Check if model matches any potential matches
                    $modelName = $model['model'] ?? '';
                    
                    foreach ($potentialMatches as $match) {
                        if ($this->isModelMatch($modelName, $match, $brand)) {
                            return array_merge($model, [
                                'uuid' => $this->getOrGenerateUuid($model, $componentType),
                                'brand' => $brand['brand'] ?? null,
                                'series' => $brand['series'] ?? null,
                                'family' => $brand['family'] ?? null,
                                'matched_by' => 'smart_matching',
                                'match_confidence' => $this->calculateMatchConfidence($modelName, $match, $notes)
                            ]);
                        }
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Extract potential model names from notes and serial number
     */
    private function extractPotentialMatches($notes, $serialNumber) {
        $matches = [];
        
        // Add serial number if it looks like a model name
        if (preg_match('/^[A-Z][A-Z0-9\-\.]+/i', $serialNumber)) {
            $matches[] = $serialNumber;
        }
        
        // Extract model patterns from notes
        if ($notes) {
            // Common CPU patterns: i7-12700K, Xeon E5-2680, etc.
            if (preg_match_all('/([A-Z]+[\-\s]?\d+[A-Z]*[\+\-]?)/i', $notes, $cpuMatches)) {
                $matches = array_merge($matches, $cpuMatches[1]);
            }
            
            // Motherboard patterns: X13DRG-H, Z690-A, etc.
            if (preg_match_all('/([A-Z]+\d+[A-Z\-]*)/i', $notes, $mbMatches)) {
                $matches = array_merge($matches, $mbMatches[1]);
            }
            
            // Memory patterns: DDR4-3200, DDR5-4800, etc.
            if (preg_match_all('/(DDR[45]\-\d+|\d+GB)/i', $notes, $ramMatches)) {
                $matches = array_merge($matches, $ramMatches[1]);
            }
        }
        
        return array_unique($matches);
    }

    /**
     * Check if model name matches potential match
     */
    private function isModelMatch($modelName, $potentialMatch, $brand) {
        $modelName = strtolower(trim($modelName));
        $potentialMatch = strtolower(trim($potentialMatch));
        
        // Exact match
        if ($modelName === $potentialMatch) {
            return true;
        }
        
        // Partial match with high confidence
        if (strlen($potentialMatch) > 3 && strpos($modelName, $potentialMatch) !== false) {
            return true;
        }
        
        // Reverse partial match
        if (strlen($modelName) > 3 && strpos($potentialMatch, $modelName) !== false) {
            return true;
        }
        
        // Brand-specific matching patterns
        $brandName = strtolower($brand['brand'] ?? '');
        if ($brandName === 'intel' && preg_match('/xeon|core|pentium|celeron/i', $potentialMatch)) {
            return $this->fuzzyMatch($modelName, $potentialMatch);
        }
        
        if ($brandName === 'amd' && preg_match('/ryzen|epyc|threadripper/i', $potentialMatch)) {
            return $this->fuzzyMatch($modelName, $potentialMatch);
        }
        
        return false;
    }

    /**
     * Calculate match confidence score
     */
    private function calculateMatchConfidence($modelName, $match, $notes) {
        $confidence = 0.0;
        
        // Exact match = 100%
        if (strtolower($modelName) === strtolower($match)) {
            return 1.0;
        }
        
        // Partial match confidence based on overlap
        $modelLower = strtolower($modelName);
        $matchLower = strtolower($match);
        
        if (strpos($modelLower, $matchLower) !== false) {
            $confidence = strlen($matchLower) / strlen($modelLower);
        } elseif (strpos($matchLower, $modelLower) !== false) {
            $confidence = strlen($modelLower) / strlen($matchLower);
        }
        
        // Boost confidence if found in notes context
        if (stripos($notes, $modelName) !== false) {
            $confidence += 0.3;
        }
        
        return min(1.0, $confidence);
    }

    /**
     * Fuzzy string matching for similar model names
     */
    private function fuzzyMatch($str1, $str2) {
        $str1 = strtolower(preg_replace('/[^a-z0-9]/', '', $str1));
        $str2 = strtolower(preg_replace('/[^a-z0-9]/', '', $str2));
        
        if (strlen($str1) === 0 || strlen($str2) === 0) {
            return false;
        }
        
        $similarity = 0;
        similar_text($str1, $str2, $similarity);
        
        return $similarity > 70; // 70% similarity threshold
    }

    /**
     * Extract basic specifications from database record when JSON not available
     */
    private function extractBasicSpecsFromDatabase($componentType, $databaseRecord) {
        $basicSpecs = [
            'uuid' => $databaseRecord['UUID'] ?? null,
            'serial_number' => $databaseRecord['SerialNumber'] ?? null,
            'notes' => $databaseRecord['Notes'] ?? null,
            'status' => $databaseRecord['Status'] ?? 0,
            'source' => 'database_fallback'
        ];
        
        // Component-specific extractions from notes field
        $notes = $databaseRecord['Notes'] ?? '';
        
        switch ($componentType) {
            case 'cpu':
                $basicSpecs['socket'] = $this->extractSocketFromNotes($notes);
                $basicSpecs['tdp_w'] = $this->extractTDPFromNotes($notes);
                $basicSpecs['cores'] = $this->extractCoresFromNotes($notes);
                break;
                
            case 'motherboard':
                $basicSpecs['socket'] = $this->extractSocketFromNotes($notes);
                $basicSpecs['memory_type'] = $this->extractMemoryTypeFromNotes($notes);
                $basicSpecs['form_factor'] = $this->extractFormFactorFromNotes($notes);
                break;
                
            case 'ram':
                $basicSpecs['memory_type'] = $this->extractMemoryTypeFromNotes($notes);
                $basicSpecs['capacity_gb'] = $this->extractCapacityFromNotes($notes);
                $basicSpecs['frequency_mhz'] = $this->extractFrequencyFromNotes($notes);
                break;
                
            case 'storage':
                $basicSpecs['interface'] = $this->extractStorageInterfaceFromNotes($notes);
                $basicSpecs['capacity_gb'] = $this->extractCapacityFromNotes($notes);
                $basicSpecs['storage_type'] = $this->extractStorageTypeFromNotes($notes);
                break;
        }
        
        return $basicSpecs;
    }

    // Helper methods for extracting information from notes
    private function extractSocketFromNotes($notes) {
        if (preg_match('/socket[\s:]*(LGA\s*\d+|AM[45]\+?|TR4|sTRX4)/i', $notes, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    private function extractTDPFromNotes($notes) {
        if (preg_match('/(\d+)\s*W(?:att)?/i', $notes, $matches)) {
            return intval($matches[1]);
        }
        return null;
    }

    private function extractCoresFromNotes($notes) {
        if (preg_match('/(\d+)\s*core/i', $notes, $matches)) {
            return intval($matches[1]);
        }
        return null;
    }

    private function extractMemoryTypeFromNotes($notes) {
        if (preg_match('/DDR[345]/i', $notes, $matches)) {
            return strtoupper($matches[0]);
        }
        return null;
    }

    private function extractFormFactorFromNotes($notes) {
        if (preg_match('/(ATX|mATX|ITX|EATX)/i', $notes, $matches)) {
            return strtoupper($matches[1]);
        }
        return null;
    }

    private function extractCapacityFromNotes($notes) {
        if (preg_match('/(\d+)\s*(GB|TB)/i', $notes, $matches)) {
            $capacity = intval($matches[1]);
            if (strtoupper($matches[2]) === 'TB') {
                $capacity *= 1024;
            }
            return $capacity;
        }
        return null;
    }

    private function extractFrequencyFromNotes($notes) {
        if (preg_match('/(\d{3,5})\s*MHz/i', $notes, $matches)) {
            return intval($matches[1]);
        }
        return null;
    }

    private function extractStorageInterfaceFromNotes($notes) {
        if (preg_match('/(SATA|NVMe|SAS|PCIe)/i', $notes, $matches)) {
            return strtoupper($matches[1]);
        }
        return null;
    }

    private function extractStorageTypeFromNotes($notes) {
        if (preg_match('/(SSD|HDD|NVMe)/i', $notes, $matches)) {
            return strtoupper($matches[1]);
        }
        return null;
    }
    
    /**
     * Cache component specifications with size management
     */
    private function cacheComponentSpecs($cacheKey, $specs) {
        // Implement LRU cache with size limit
        if (count($this->componentSpecsCache) >= $this->maxCacheSize) {
            // Remove oldest entries (simple FIFO for now)
            $keysToRemove = array_slice(array_keys($this->componentSpecsCache), 0, 100);
            foreach ($keysToRemove as $key) {
                unset($this->componentSpecsCache[$key]);
            }
        }
        
        $this->componentSpecsCache[$cacheKey] = $specs;
    }
    
    /**
     * Cache search results with size management
     */
    private function cacheSearchResults($cacheKey, $results) {
        // Limit search cache size
        if (count($this->componentSearchCache) >= 200) {
            // Remove oldest search results
            $keysToRemove = array_slice(array_keys($this->componentSearchCache), 0, 50);
            foreach ($keysToRemove as $key) {
                unset($this->componentSearchCache[$key]);
            }
        }
        
        $this->componentSearchCache[$cacheKey] = $results;
    }
    
    /**
     * Update cache statistics
     */
    private function updateCacheStats($operation, $componentType) {
        if (!isset($this->cacheStats[$operation])) {
            $this->cacheStats[$operation] = [];
        }
        
        if (!isset($this->cacheStats[$operation][$componentType])) {
            $this->cacheStats[$operation][$componentType] = 0;
        }
        
        $this->cacheStats[$operation][$componentType]++;
    }
    
    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        return [
            'json_cache_size' => count($this->jsonCache),
            'specs_cache_size' => count($this->componentSpecsCache),
            'search_cache_size' => count($this->componentSearchCache),
            'max_cache_size' => $this->maxCacheSize,
            'operations' => $this->cacheStats,
            'memory_usage_estimate' => [
                'json_cache_kb' => round(strlen(serialize($this->jsonCache)) / 1024, 2),
                'specs_cache_kb' => round(strlen(serialize($this->componentSpecsCache)) / 1024, 2),
                'search_cache_kb' => round(strlen(serialize($this->componentSearchCache)) / 1024, 2)
            ]
        ];
    }
    
    /**
     * Preload frequently used components for better performance
     */
    public function preloadPopularComponents($componentTypes = ['cpu', 'motherboard', 'ram']) {
        foreach ($componentTypes as $componentType) {
            try {
                // Load JSON data into cache
                $this->loadJsonData($componentType);

                // Preload first few components of each type
                $components = $this->getAllAvailableComponents($componentType);
                $popularComponents = array_slice($components, 0, 50); // First 50 components

                foreach ($popularComponents as $component) {
                    $uuid = $component['uuid'] ?? null;
                    if ($uuid) {
                        $this->getComponentSpecifications($componentType, $uuid);
                    }
                }

                $this->updateCacheStats('preload', $componentType);

            } catch (Exception $e) {
                error_log("Error preloading $componentType components: " . $e->getMessage());
            }
        }
    }

    /**
     * Get caddy specifications by UUID
     * Caddies have a special JSON structure: {"caddies": [...]}
     *
     * @param string $uuid Caddy UUID
     * @return array|null Caddy specifications or null if not found
     */
    public function getCaddyByUuid($uuid) {
        try {
            $jsonData = $this->loadJsonData('caddy');

            if (!isset($jsonData['caddies'])) {
                error_log("getCaddyByUuid: Invalid caddy JSON structure - 'caddies' key not found");
                return null;
            }

            foreach ($jsonData['caddies'] as $caddy) {
                if (isset($caddy['uuid']) && $caddy['uuid'] === $uuid) {
                    return $caddy;
                }
            }

            return null;

        } catch (Exception $e) {
            error_log("getCaddyByUuid error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Filter NVMe adapter cards from PCIe card list
     *
     * @param array $pcieCards Array of PCIe card records from existing components
     * @return array Array of NVMe adapter specifications
     */
    public function filterNvmeAdapters($pcieCards) {
        $nvmeAdapters = [];

        if (empty($pcieCards) || !is_array($pcieCards)) {
            return $nvmeAdapters;
        }

        foreach ($pcieCards as $card) {
            $cardUuid = $card['component_uuid'] ?? null;
            if (!$cardUuid) {
                continue;
            }

            // Get card specifications from JSON
            $cardSpecs = $this->findComponentByUuid('pciecard', $cardUuid);
            if (!$cardSpecs) {
                continue;
            }

            // Check if this is an NVMe adapter
            $componentSubtype = $cardSpecs['component_subtype'] ?? '';
            if ($componentSubtype === 'NVMe Adaptor') {
                $nvmeAdapters[] = [
                    'uuid' => $cardUuid,
                    'specs' => $cardSpecs,
                    'quantity' => $card['quantity'] ?? 1
                ];
            }
        }

        return $nvmeAdapters;
    }

    /**
     * Warm up cache by loading all component specs
     *
     * Call this on application startup for optimal performance
     *
     * @return array Statistics [types => int, specs => int, time => float]
     */
    public function warmupCache(): array {
        $startTime = microtime(true);
        $typeCount = 0;
        $specCount = 0;

        $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'pciecard', 'hbacard'];

        foreach ($componentTypes as $type) {
            try {
                $specs = $this->loadJsonData($type);

                if (!empty($specs)) {
                    if ($this->specCache !== null) {
                        $this->specCache->setAllSpecsForType($type, $specs);
                    }
                    $typeCount++;
                    // Count specs based on structure
                    if (is_array($specs)) {
                        $specCount += count($specs);
                    }
                }
            } catch (Exception $e) {
                error_log("Warning: Failed to warmup cache for type $type: " . $e->getMessage());
            }
        }

        $elapsedTime = microtime(true) - $startTime;

        return [
            'types' => $typeCount,
            'specs' => $specCount,
            'time' => round($elapsedTime, 3)
        ];
    }
}

?>