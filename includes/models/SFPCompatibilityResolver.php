<?php
/**
 * SFP Compatibility Resolver
 *
 * Handles dynamic SFP-NIC dependency resolution, automatic assignment,
 * and smart placement decisions (onboard vs add-on NIC).
 *
 * Purpose: Enable adding SFPs without NICs, auto-assign when NIC added,
 *          and provide compatibility-based filtering and suggestions.
 */

require_once(__DIR__ . '/ComponentDataService.php');
require_once(__DIR__ . '/NICPortTracker.php');

class SFPCompatibilityResolver {
    private $pdo;
    private $componentDataService;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->componentDataService = ComponentDataService::getInstance();
    }

    /**
     * Validate that all unassigned SFPs have uniform type and speed
     * Requirement #1: All selected SFPs must have same type and speed
     *
     * @param array $sfpUuids Array of SFP UUIDs to validate
     * @return array Validation result with success/errors/warnings
     */
    public function validateUnassignedSFPs($sfpUuids) {
        if (empty($sfpUuids)) {
            return [
                'success' => false,
                'errors' => ['No SFP UUIDs provided']
            ];
        }

        $types = [];
        $speeds = [];
        $sfpDetails = [];

        foreach ($sfpUuids as $sfpUuid) {
            $specs = $this->componentDataService->getComponentSpecs('sfp', $sfpUuid);

            if (!$specs) {
                return [
                    'success' => false,
                    'errors' => ["SFP UUID {$sfpUuid} not found in specifications"]
                ];
            }

            $type = strtoupper(trim($specs['type'] ?? ''));
            $speed = $this->normalizeSpeed($specs['speed'] ?? '');

            if (empty($type)) {
                return [
                    'success' => false,
                    'errors' => ["SFP {$sfpUuid} has no type specified"]
                ];
            }

            if (empty($speed)) {
                return [
                    'success' => false,
                    'errors' => ["SFP {$sfpUuid} has no speed specified"]
                ];
            }

            $types[] = $type;
            $speeds[] = $speed;
            $sfpDetails[] = [
                'uuid' => $sfpUuid,
                'model' => $specs['model'] ?? 'Unknown',
                'type' => $type,
                'speed' => $speed
            ];
        }

        // Check uniformity
        $uniqueTypes = array_unique($types);
        $uniqueSpeeds = array_unique($speeds);

        if (count($uniqueTypes) > 1) {
            return [
                'success' => false,
                'errors' => [
                    'All SFP modules must have the same type',
                    'Found types: ' . implode(', ', $uniqueTypes)
                ],
                'sfp_details' => $sfpDetails
            ];
        }

        if (count($uniqueSpeeds) > 1) {
            return [
                'success' => false,
                'errors' => [
                    'All SFP modules must have the same speed',
                    'Found speeds: ' . implode(', ', $uniqueSpeeds)
                ],
                'sfp_details' => $sfpDetails
            ];
        }

        return [
            'success' => true,
            'uniform_type' => $types[0],
            'uniform_speed' => $speeds[0],
            'sfp_details' => $sfpDetails
        ];
    }

    /**
     * Automatically assign SFPs to NIC ports when NIC is added
     * Requirement #2: Auto-map SFPs to NIC after NIC addition
     *
     * @param string $configUuid Server configuration UUID
     * @param string $nicUuid NIC component UUID
     * @param array $unassignedSfps Array of unassigned SFP UUIDs
     * @return array Assignment result with success/errors/assignments
     */
    public function autoAssignSFPsToNIC($configUuid, $nicUuid, $unassignedSfps) {
        if (empty($unassignedSfps)) {
            return ['success' => true, 'message' => 'No unassigned SFPs to assign'];
        }

        // Get NIC specifications
        $nicSpecs = $this->componentDataService->getComponentSpecs('nic', $nicUuid);

        if (!$nicSpecs) {
            return [
                'success' => false,
                'errors' => ['NIC specifications not found']
            ];
        }

        $nicPortCount = (int)($nicSpecs['ports'] ?? 0);
        $nicPortType = strtoupper(trim($nicSpecs['port_type'] ?? ''));
        $nicMaxSpeed = $this->extractMaxSpeed($nicSpecs['speeds'] ?? []);

        // Check if NIC has enough ports
        if (count($unassignedSfps) > $nicPortCount) {
            $compatibleNICs = $this->getCompatibleNICsForSFPs($unassignedSfps);
            return [
                'success' => false,
                'errors' => [
                    "NIC has {$nicPortCount} ports but " . count($unassignedSfps) . " SFPs need assignment"
                ],
                'suggestions' => $compatibleNICs
            ];
        }

        // Validate type and speed compatibility for each SFP
        $assignments = [];
        $portIndex = 1;
        $errors = [];

        foreach ($unassignedSfps as $sfpUuid) {
            $sfpSpecs = $this->componentDataService->getComponentSpecs('sfp', $sfpUuid);

            if (!$sfpSpecs) {
                $errors[] = "SFP {$sfpUuid} specifications not found";
                continue;
            }

            $sfpType = strtoupper(trim($sfpSpecs['type'] ?? ''));
            $sfpSpeed = $this->normalizeSpeed($sfpSpecs['speed'] ?? '');

            // Check type compatibility
            if (!NICPortTracker::isCompatible($nicPortType, $sfpType)) {
                $compatibleTypes = NICPortTracker::getCompatibleSfpTypes($nicPortType);
                $errors[] = "SFP type {$sfpType} incompatible with NIC port type {$nicPortType}. Compatible types: " . implode(', ', $compatibleTypes);
                continue;
            }

            // Check speed compatibility
            if (!$this->validateSpeedCompatibility($nicMaxSpeed, $sfpSpeed)) {
                $errors[] = "SFP speed {$sfpSpeed} exceeds NIC max speed {$nicMaxSpeed}";
                continue;
            }

            // Assign to next available port
            $assignments[] = [
                'uuid' => $sfpUuid,
                'parent_nic_uuid' => $nicUuid,
                'port_index' => $portIndex,
                'sfp_model' => $sfpSpecs['model'] ?? 'Unknown',
                'sfp_type' => $sfpType,
                'sfp_speed' => $sfpSpeed
            ];
            $portIndex++;
        }

        if (!empty($errors)) {
            $compatibleNICs = $this->getCompatibleNICsForSFPs($unassignedSfps);
            return [
                'success' => false,
                'errors' => $errors,
                'suggestions' => $compatibleNICs
            ];
        }

        return [
            'success' => true,
            'assignments' => $assignments,
            'message' => count($assignments) . ' SFPs successfully assigned to NIC'
        ];
    }

    /**
     * Get compatible NICs for a set of SFP modules
     * Requirement #2: Suggest compatible NICs when incompatibility detected
     *
     * @param array $sfpUuids Array of SFP UUIDs
     * @return array List of compatible NIC suggestions
     */
    public function getCompatibleNICsForSFPs($sfpUuids) {
        if (empty($sfpUuids)) {
            return [];
        }

        // Validate SFPs first
        $validation = $this->validateUnassignedSFPs($sfpUuids);

        if (!$validation['success']) {
            return [
                'error' => 'Cannot suggest NICs: SFPs have incompatible types or speeds',
                'details' => $validation['errors']
            ];
        }

        $requiredType = $validation['uniform_type'];
        $requiredSpeed = $validation['uniform_speed'];
        $requiredPortCount = count($sfpUuids);

        // Get compatible port types for the SFP type
        $compatiblePortTypes = $this->getCompatiblePortTypesForSFP($requiredType);

        // Search NIC JSON for compatible options
        $nicJsonData = $this->componentDataService->loadJsonData('nic');
        $suggestions = [];

        foreach ($nicJsonData as $brandData) {
            foreach ($brandData['series'] ?? [] as $series) {
                foreach ($series['models'] ?? [] as $model) {
                    $portType = strtoupper(trim($model['port_type'] ?? ''));
                    $portCount = (int)($model['ports'] ?? 0);
                    $speeds = $model['speeds'] ?? [];
                    $maxSpeed = $this->extractMaxSpeed($speeds);

                    // Check if NIC meets requirements
                    if (in_array($portType, $compatiblePortTypes) &&
                        $portCount >= $requiredPortCount &&
                        $this->validateSpeedCompatibility($maxSpeed, $requiredSpeed)) {

                        $suggestions[] = [
                            'brand' => $brandData['brand'] ?? '',
                            'model' => $model['model'] ?? '',
                            'ports' => $portCount,
                            'port_type' => $portType,
                            'max_speed' => $maxSpeed,
                            'uuid' => $model['uuid'] ?? ''
                        ];
                    }
                }
            }
        }

        // Sort by speed (descending) then port count (ascending)
        usort($suggestions, function($a, $b) {
            $speedCmp = $this->compareSpeedValues($b['max_speed'], $a['max_speed']);
            if ($speedCmp !== 0) return $speedCmp;
            return $a['ports'] - $b['ports'];
        });

        return [
            'required_specs' => [
                'min_ports' => $requiredPortCount,
                'compatible_port_types' => $compatiblePortTypes,
                'min_speed' => $requiredSpeed
            ],
            'suggestions' => array_slice($suggestions, 0, 10) // Top 10 suggestions
        ];
    }

    /**
     * Choose optimal NIC between onboard and add-on options
     * Requirement #4: Prefer higher speed, then add-on if equal
     *
     * @param array $sfpUuids Array of SFP UUIDs to place
     * @param array $availableNICs Array of NIC options (onboard and add-on)
     * @return array Optimal NIC selection result
     */
    public function chooseOptimalNIC($sfpUuids, $availableNICs) {
        if (empty($availableNICs)) {
            return [
                'success' => false,
                'error' => 'No NICs available for placement'
            ];
        }

        // Validate SFPs
        $validation = $this->validateUnassignedSFPs($sfpUuids);
        if (!$validation['success']) {
            return [
                'success' => false,
                'errors' => $validation['errors']
            ];
        }

        $sfpType = $validation['uniform_type'];
        $sfpSpeed = $validation['uniform_speed'];
        $sfpCount = count($sfpUuids);

        $compatibleNICs = [];

        foreach ($availableNICs as $nic) {
            $nicUuid = $nic['uuid'];
            $nicSpecs = $this->componentDataService->getComponentSpecs('nic', $nicUuid);

            if (!$nicSpecs) continue;

            $portType = strtoupper(trim($nicSpecs['port_type'] ?? ''));
            $portCount = (int)($nicSpecs['ports'] ?? 0);
            $maxSpeed = $this->extractMaxSpeed($nicSpecs['speeds'] ?? []);
            $sourceType = $nic['source_type'] ?? 'component'; // 'onboard' or 'component'

            // Check compatibility
            if (NICPortTracker::isCompatible($portType, $sfpType) &&
                $portCount >= $sfpCount &&
                $this->validateSpeedCompatibility($maxSpeed, $sfpSpeed)) {

                $compatibleNICs[] = [
                    'uuid' => $nicUuid,
                    'model' => $nicSpecs['model'] ?? '',
                    'port_type' => $portType,
                    'port_count' => $portCount,
                    'max_speed' => $maxSpeed,
                    'source_type' => $sourceType,
                    'speed_value' => $this->extractSpeedValue($maxSpeed)
                ];
            }
        }

        if (empty($compatibleNICs)) {
            return [
                'success' => false,
                'error' => 'No compatible NICs found for the given SFPs',
                'suggestions' => $this->getCompatibleNICsForSFPs($sfpUuids)
            ];
        }

        // Sort by: 1) Speed (descending), 2) Add-on > Onboard, 3) Port count (ascending)
        usort($compatibleNICs, function($a, $b) {
            // Compare speed first (higher is better)
            $speedCmp = $b['speed_value'] - $a['speed_value'];
            if ($speedCmp !== 0) return $speedCmp;

            // Prefer add-on (component) over onboard
            if ($a['source_type'] !== $b['source_type']) {
                return ($a['source_type'] === 'component') ? -1 : 1;
            }

            // Prefer fewer ports (more efficient utilization)
            return $a['port_count'] - $b['port_count'];
        });

        return [
            'success' => true,
            'optimal_nic' => $compatibleNICs[0],
            'all_compatible' => $compatibleNICs
        ];
    }

    /**
     * Get compatible SFPs for an existing NIC
     * Requirement #3: Filter SFPs when NIC already exists
     *
     * @param string $nicUuid NIC component UUID
     * @return array List of compatible SFP UUIDs and specs
     */
    public function getCompatibleSFPsForNIC($nicUuid) {
        $nicSpecs = $this->componentDataService->getComponentSpecs('nic', $nicUuid);

        if (!$nicSpecs) {
            return [
                'error' => 'NIC specifications not found'
            ];
        }

        $nicPortType = strtoupper(trim($nicSpecs['port_type'] ?? ''));
        $nicMaxSpeed = $this->extractMaxSpeed($nicSpecs['speeds'] ?? []);

        // Get compatible SFP types
        $compatibleTypes = NICPortTracker::getCompatibleSfpTypes($nicPortType);

        if (empty($compatibleTypes)) {
            return [
                'nic_port_type' => $nicPortType,
                'compatible_sfps' => [],
                'message' => 'NIC port type does not support SFP modules (e.g., RJ45)'
            ];
        }

        // Load all SFP specs and filter
        $sfpJsonData = $this->componentDataService->loadJsonData('sfp');
        $compatibleSFPs = [];

        foreach ($sfpJsonData as $categoryData) {
            foreach ($categoryData['series'] ?? [] as $series) {
                foreach ($series['models'] ?? [] as $model) {
                    $sfpType = strtoupper(trim($model['type'] ?? ''));
                    $sfpSpeed = $this->normalizeSpeed($model['speed'] ?? '');

                    if (in_array($sfpType, $compatibleTypes) &&
                        $this->validateSpeedCompatibility($nicMaxSpeed, $sfpSpeed)) {

                        $compatibleSFPs[] = [
                            'uuid' => $model['uuid'] ?? '',
                            'brand' => $categoryData['brand'] ?? '',
                            'model' => $model['model'] ?? '',
                            'type' => $sfpType,
                            'speed' => $sfpSpeed,
                            'reach' => $model['reach'] ?? '',
                            'fiber_type' => $model['fiber_type'] ?? ''
                        ];
                    }
                }
            }
        }

        return [
            'nic_uuid' => $nicUuid,
            'nic_model' => $nicSpecs['model'] ?? '',
            'nic_port_type' => $nicPortType,
            'nic_max_speed' => $nicMaxSpeed,
            'compatible_sfp_types' => $compatibleTypes,
            'compatible_sfps' => $compatibleSFPs
        ];
    }

    /**
     * Validate speed compatibility (SFP speed must be <= NIC max speed)
     * Allows downshift but blocks upshift
     *
     * @param string $nicMaxSpeed NIC maximum speed (e.g., "25GbE", "10Gbps")
     * @param string $sfpSpeed SFP speed (e.g., "10Gbps", "25GbE")
     * @return bool True if compatible
     */
    private function validateSpeedCompatibility($nicMaxSpeed, $sfpSpeed) {
        $nicSpeedValue = $this->extractSpeedValue($nicMaxSpeed);
        $sfpSpeedValue = $this->extractSpeedValue($sfpSpeed);

        // Allow downshift: 25G NIC can accept 10G SFP
        // Block upshift: 10G NIC cannot accept 25G SFP
        return $sfpSpeedValue <= $nicSpeedValue;
    }

    /**
     * Extract numeric speed value from speed string
     *
     * @param string $speedStr Speed string (e.g., "10GbE", "25Gbps", "100G")
     * @return int Speed value in Gbps
     */
    private function extractSpeedValue($speedStr) {
        $speedStr = strtoupper(trim($speedStr));

        // Extract numeric value
        if (preg_match('/(\d+(?:\.\d+)?)/', $speedStr, $matches)) {
            $value = floatval($matches[1]);

            // Handle Mbps (convert to Gbps)
            if (strpos($speedStr, 'M') !== false) {
                $value = $value / 1000;
            }

            return (int)$value;
        }

        return 0;
    }

    /**
     * Extract maximum speed from speeds array
     *
     * @param array $speeds Array of speed strings
     * @return string Maximum speed string
     */
    private function extractMaxSpeed($speeds) {
        if (empty($speeds)) {
            return '0Gbps';
        }

        $maxSpeed = '';
        $maxValue = 0;

        foreach ($speeds as $speed) {
            $value = $this->extractSpeedValue($speed);
            if ($value > $maxValue) {
                $maxValue = $value;
                $maxSpeed = $speed;
            }
        }

        return $maxSpeed ?: $speeds[0];
    }

    /**
     * Normalize speed format for comparison
     *
     * @param string $speedStr Speed string
     * @return string Normalized speed string
     */
    private function normalizeSpeed($speedStr) {
        $speedStr = strtoupper(trim($speedStr));

        // Standardize to "XGbps" format
        if (preg_match('/(\d+)/', $speedStr, $matches)) {
            $value = $matches[1];

            if (strpos($speedStr, 'M') !== false) {
                return "{$value}Mbps";
            } else {
                return "{$value}Gbps";
            }
        }

        return $speedStr;
    }

    /**
     * Compare two speed values
     *
     * @param string $speed1 First speed
     * @param string $speed2 Second speed
     * @return int -1 if speed1 < speed2, 0 if equal, 1 if speed1 > speed2
     */
    private function compareSpeedValues($speed1, $speed2) {
        $value1 = $this->extractSpeedValue($speed1);
        $value2 = $this->extractSpeedValue($speed2);

        if ($value1 < $value2) return -1;
        if ($value1 > $value2) return 1;
        return 0;
    }

    /**
     * Get compatible NIC port types for a given SFP type
     *
     * @param string $sfpType SFP module type
     * @return array List of compatible NIC port types
     */
    private function getCompatiblePortTypesForSFP($sfpType) {
        $sfpType = strtoupper(trim($sfpType));

        // Reverse mapping: which NIC port types accept this SFP type
        $compatibilityMap = [
            'SFP+' => ['SFP+', 'SFP28'],
            'SFP+ DAC' => ['SFP+', 'SFP28'],
            'SFP28' => ['SFP28'],
            'QSFP+' => ['QSFP+', 'QSFP28', 'QSFP56', 'OSFP'],
            'QSFP28' => ['QSFP28', 'QSFP56', 'OSFP'],
            'QSFP56' => ['QSFP56', 'OSFP'],
            'OSFP' => ['OSFP']
        ];

        return $compatibilityMap[$sfpType] ?? [];
    }
}
