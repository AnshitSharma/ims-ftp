<?php
/**
 * Infrastructure Management System - Chassis Manager
 * File: includes/models/ChassisManager.php
 * 
 * Manages chassis JSON data loading, caching, and validation
 */

require_once __DIR__ . '/../components/ComponentSpecPaths.php';
require_once __DIR__ . '/../components/PlatformSpecIndex.php';

class ChassisManager {
    private $chassisJsonPath;
    private $jsonCache = [];
    private $cacheTimestamp = null;
    private $cacheTimeout = 3600; // 1 hour cache
    
    public function __construct() {
        $this->chassisJsonPath = ComponentSpecPaths::getPath('chassis');
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
     * Traverse all chassis models, calling $callback for each.
     * Callback receives ($model, $manufacturer, $series). Return non-null to stop and return that value.
     */
    private function traverseChassisModels($callback) {
        $data = $this->loadChassisSpecifications();
        if (!isset($data['chassis_specifications']['manufacturers'])) {
            return null;
        }
        foreach ($data['chassis_specifications']['manufacturers'] as $manufacturer) {
            if (!isset($manufacturer['series'])) continue;
            foreach ($manufacturer['series'] as $series) {
                if (!isset($series['models'])) continue;
                foreach ($series['models'] as $model) {
                    $result = $callback($model, $manufacturer, $series);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Load chassis specifications by UUID
     */
    public function loadChassisSpecsByUUID($uuid) {
        // Platform-owned chassis live in the serverplatform catalog, not chasis-level-3.json,
        // and have no inventory row of their own. Resolve through the shared index first so
        // this resolver cannot disagree with ComponentDataService / DataExtractionUtilities.
        $platformSpec = PlatformSpecIndex::find('chassis', $uuid);
        if ($platformSpec !== null) {
            return [
                'found' => true,
                'specifications' => $platformSpec,
                'manufacturer' => $platformSpec['platform_name'] ?? ($platformSpec['manufacturer'] ?? 'Unknown'),
                'series_name' => $platformSpec['platform_name'] ?? 'Server Platform',
                'platform_owned' => true
            ];
        }

        try {
            $result = $this->traverseChassisModels(function($model, $manufacturer, $series) use ($uuid) {
                if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                    return [
                        'found' => true,
                        'specifications' => $model,
                        'manufacturer' => $manufacturer['manufacturer'],
                        'series_name' => $series['series_name']
                    ];
                }
                return null;
            });

            return $result ?? ['found' => false, 'error' => "Chassis UUID not found: $uuid"];
        } catch (Exception $e) {
            return ['found' => false, 'error' => $e->getMessage()];
        }
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
     * Get all chassis UUIDs for validation
     */
    public function getAllChassisUUIDs() {
        try {
            $uuids = [];
            $this->traverseChassisModels(function($model) use (&$uuids) {
                if (isset($model['uuid'])) {
                    $uuids[] = $model['uuid'];
                }
                return null; // continue traversal
            });
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
