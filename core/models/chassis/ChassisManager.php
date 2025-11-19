<?php
/**
 * Infrastructure Management System - Chassis Manager
 * File: includes/models/ChassisManager.php
 * 
 * Manages chassis JSON data loading, caching, and validation
 */

class ChassisManager {
    private $chassisJsonPath;
    private $jsonCache = [];
    private $cacheTimestamp = null;
    private $cacheTimeout = 3600; // 1 hour cache
    
    public function __construct() {
        $this->chassisJsonPath = __DIR__ . '/../../resources/specifications/chassis/chasis-level-3.json';
    }
    
    /**
     * Load chassis specifications from JSON with caching
     */
    private function loadChassisSpecifications() {
        $currentTime = time();
        
        // Check if cache is valid
        if (!empty($this->jsonCache) && 
            $this->cacheTimestamp && 
            ($currentTime - $this->cacheTimestamp) < $this->cacheTimeout) {
            return $this->jsonCache;
        }
        
        // Load JSON file
        if (!file_exists($this->chassisJsonPath)) {
            throw new Exception("Chassis JSON file not found: " . $this->chassisJsonPath);
        }
        
        $jsonContent = file_get_contents($this->chassisJsonPath);
        if ($jsonContent === false) {
            throw new Exception("Failed to read chassis JSON file");
        }
        
        $data = json_decode($jsonContent, true);
        if ($data === null) {
            throw new Exception("Invalid JSON in chassis specifications file: " . json_last_error_msg());
        }
        
        // Cache the data
        $this->jsonCache = $data;
        $this->cacheTimestamp = $currentTime;
        
        return $this->jsonCache;
    }
    
    /**
     * Load chassis specifications by UUID
     */
    public function loadChassisSpecsByUUID($uuid) {
        try {
            $data = $this->loadChassisSpecifications();
            
            if (!isset($data['chassis_specifications']['manufacturers'])) {
                return [
                    'found' => false,
                    'error' => 'Invalid chassis JSON structure: manufacturers not found'
                ];
            }
            
            // Search through manufacturers and series
            $uuidsFound = [];
            foreach ($data['chassis_specifications']['manufacturers'] as $manufacturer) {
                if (!isset($manufacturer['series'])) continue;

                foreach ($manufacturer['series'] as $series) {
                    if (!isset($series['models'])) continue;

                    foreach ($series['models'] as $model) {
                        // Debug: collect all UUIDs found
                        if (isset($model['uuid'])) {
                            $uuidsFound[] = $model['uuid'];
                        }

                        if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                            return [
                                'found' => true,
                                'specifications' => $model,
                                'manufacturer' => $manufacturer['manufacturer'],
                                'series_name' => $series['series_name']
                            ];
                        }
                    }
                }
            }

            
            return [
                'found' => false,
                'error' => "Chassis UUID not found: $uuid"
            ];
            
        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate chassis availability in database
     */
    public function validateChassisAvailability($uuid, $pdo) {
        try {
            $stmt = $pdo->prepare("SELECT Status FROM chassisinventory WHERE UUID = ? LIMIT 1");
            $stmt->execute([$uuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return [
                    'available' => false,
                    'error' => 'Chassis not found in inventory'
                ];
            }

            return [
                'available' => $result['Status'] == 1,
                'status' => $result['Status'],
                'error' => $result['Status'] != 1 ? 'Chassis is not available (Status: ' . $result['Status'] . ')' : null
            ];
        } catch (Exception $e) {
            return [
                'available' => false,
                'error' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate chassis exists by UUID
     */
    public function validateChassisExists($uuid) {
        $result = $this->loadChassisSpecsByUUID($uuid);
        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error']
        ];
    }
    
    /**
     * Get chassis specifications for display
     */
    public function getChassisSpecifications($chassisUUID) {
        $chassisSpecs = $this->loadChassisSpecsByUUID($chassisUUID);
        if (!$chassisSpecs['found']) {
            return [
                'success' => false,
                'error' => $chassisSpecs['error'],
                'specifications' => null
            ];
        }

        return [
            'success' => true,
            'specifications' => $chassisSpecs['specifications'],
            'manufacturer' => $chassisSpecs['manufacturer'],
            'series_name' => $chassisSpecs['series_name']
        ];
    }
    
    /**
     * Validate JSON structure integrity
     */
    public function validateJsonStructure() {
        try {
            $data = $this->loadChassisSpecifications();
            $errors = [];
            $warnings = [];
            
            // Check root structure
            if (!isset($data['chassis_specifications'])) {
                $errors[] = "Missing root 'chassis_specifications' object";
                return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
            }
            
            $chassisSpecs = $data['chassis_specifications'];
            
            // Check manufacturers array
            if (!isset($chassisSpecs['manufacturers']) || !is_array($chassisSpecs['manufacturers'])) {
                $errors[] = "Missing or invalid 'manufacturers' array";
                return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
            }
            
            // Validate each manufacturer
            foreach ($chassisSpecs['manufacturers'] as $index => $manufacturer) {
                $manPrefix = "Manufacturer [$index]";
                
                if (!isset($manufacturer['manufacturer'])) {
                    $errors[] = "$manPrefix: Missing manufacturer name";
                }
                
                if (!isset($manufacturer['series']) || !is_array($manufacturer['series'])) {
                    $errors[] = "$manPrefix: Missing or invalid series array";
                    continue;
                }
                
                // Validate each series
                foreach ($manufacturer['series'] as $sIndex => $series) {
                    $serPrefix = "$manPrefix Series [$sIndex]";
                    
                    if (!isset($series['series_name'])) {
                        $warnings[] = "$serPrefix: Missing series_name";
                    }
                    
                    if (!isset($series['models']) || !is_array($series['models'])) {
                        $errors[] = "$serPrefix: Missing or invalid models array";
                        continue;
                    }
                    
                    // Validate each model
                    foreach ($series['models'] as $mIndex => $model) {
                        $modPrefix = "$serPrefix Model [$mIndex]";
                        
                        // Required fields
                        $requiredFields = ['uuid', 'model', 'brand', 'form_factor', 'chassis_type'];
                        foreach ($requiredFields as $field) {
                            if (!isset($model[$field])) {
                                $errors[] = "$modPrefix: Missing required field '$field'";
                            }
                        }
                        
                        // Validate drive_bays structure
                        if (!isset($model['drive_bays'])) {
                            $errors[] = "$modPrefix: Missing drive_bays configuration";
                        } else {
                            $driveBays = $model['drive_bays'];
                            if (!isset($driveBays['total_bays']) || !isset($driveBays['bay_configuration'])) {
                                $errors[] = "$modPrefix: Invalid drive_bays structure";
                            }
                        }
                        
                        // Validate backplane structure
                        if (!isset($model['backplane'])) {
                            $errors[] = "$modPrefix: Missing backplane configuration";
                        } else {
                            $backplane = $model['backplane'];
                            $requiredBackplaneFields = ['interface', 'supports_sata', 'supports_sas', 'supports_nvme'];
                            foreach ($requiredBackplaneFields as $field) {
                                if (!isset($backplane[$field])) {
                                    $warnings[] = "$modPrefix: Missing backplane field '$field'";
                                }
                            }
                        }
                        
                        // Check for duplicate UUIDs
                        if (isset($model['uuid'])) {
                            static $uuidsSeen = [];
                            if (in_array($model['uuid'], $uuidsSeen)) {
                                $errors[] = "$modPrefix: Duplicate UUID: " . $model['uuid'];
                            } else {
                                $uuidsSeen[] = $model['uuid'];
                            }
                        }
                    }
                }
            }
            
            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'total_models' => $this->countTotalModels($data)
            ];
            
        } catch (Exception $e) {
            return [
                'valid' => false,
                'errors' => [$e->getMessage()],
                'warnings' => []
            ];
        }
    }
    
    /**
     * Count total models in JSON
     */
    private function countTotalModels($data) {
        $count = 0;
        if (isset($data['chassis_specifications']['manufacturers'])) {
            foreach ($data['chassis_specifications']['manufacturers'] as $manufacturer) {
                if (isset($manufacturer['series'])) {
                    foreach ($manufacturer['series'] as $series) {
                        if (isset($series['models'])) {
                            $count += count($series['models']);
                        }
                    }
                }
            }
        }
        return $count;
    }
    
    /**
     * Get all chassis UUIDs for validation
     */
    public function getAllChassisUUIDs() {
        try {
            $data = $this->loadChassisSpecifications();
            $uuids = [];
            
            if (isset($data['chassis_specifications']['manufacturers'])) {
                foreach ($data['chassis_specifications']['manufacturers'] as $manufacturer) {
                    if (isset($manufacturer['series'])) {
                        foreach ($manufacturer['series'] as $series) {
                            if (isset($series['models'])) {
                                foreach ($series['models'] as $model) {
                                    if (isset($model['uuid'])) {
                                        $uuids[] = $model['uuid'];
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            return $uuids;
        } catch (Exception $e) {
            error_log("Error getting chassis UUIDs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clear JSON cache (for testing or forced refresh)
     */
    public function clearCache() {
        $this->jsonCache = [];
        $this->cacheTimestamp = null;
    }
}
?>