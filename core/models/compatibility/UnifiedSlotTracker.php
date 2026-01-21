<?php
/**
 * Infrastructure Management System - Unified Slot Tracker
 * File: includes/models/UnifiedSlotTracker.php
 *
 * Unified tracker for both PCIe and Riser slot availability and assignments
 * Replaces: PCIeSlotTracker.php and ExpansionSlotTracker.php
 * Calculates slot usage on-demand from server_configuration_components table
 */

require_once __DIR__ . '/../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/../components/ComponentDataService.php';

class UnifiedSlotTracker {

    private $pdo;
    private $dataExtractor;
    private $componentDataService;

    // PCIe backward compatibility matrix
    private $slotCompatibility = [
        'x1' => ['x1', 'x4', 'x8', 'x16'],
        'x4' => ['x4', 'x8', 'x16'],
        'x8' => ['x8', 'x16'],
        'x16' => ['x16']
    ];

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->dataExtractor = new DataExtractionUtilities();
        $this->componentDataService = ComponentDataService::getInstance();
    }

    /**
     * Get comprehensive PCIe slot availability for a server configuration
     * Includes riser-provided PCIe slots if risers are installed
     *
     * @param string $configUuid Server configuration UUID
     * @return array Slot availability details
     */
    public function getSlotAvailability($configUuid) {
        try {
            // Step 1: Get motherboard from configuration
            $motherboard = $this->getMotherboardFromConfig($configUuid);

            if (!$motherboard) {
                return [
                    'success' => false,
                    'error' => 'No motherboard found in configuration',
                    'total_slots' => [],
                    'used_slots' => [],
                    'available_slots' => []
                ];
            }

            // Step 2: Load motherboard PCIe slots from JSON
            $totalSlots = $this->loadMotherboardPCIeSlots($motherboard['component_uuid']);

            if (!$totalSlots['success']) {
                return [
                    'success' => false,
                    'error' => $totalSlots['error'],
                    'total_slots' => [],
                    'used_slots' => [],
                    'available_slots' => []
                ];
            }

            // Step 3: Get riser cards and add their provided PCIe slots
            $riserCards = $this->getRiserCardsInConfig($configUuid);
            $mergedSlots = $totalSlots['slots'];

            foreach ($riserCards as $riser) {
                $riserProvidedSlots = $this->loadRiserCardProvidedPCIeSlots($riser['component_uuid']);

                // Merge riser-provided slots into total slots by slot type
                foreach ($riserProvidedSlots as $slotType => $slotIds) {
                    if (!isset($mergedSlots[$slotType])) {
                        $mergedSlots[$slotType] = [];
                    }
                    $mergedSlots[$slotType] = array_merge($mergedSlots[$slotType], $slotIds);
                }
            }

            // Step 4: Get used PCIe slots from database
            $usedSlots = $this->getUsedPCIeSlots($configUuid);

            // Step 5: Calculate available slots
            $availableSlots = $this->calculateAvailableSlots($mergedSlots, $usedSlots);

            return [
                'success' => true,
                'total_slots' => $mergedSlots,
                'used_slots' => $usedSlots,
                'available_slots' => $availableSlots,
                'motherboard_uuid' => $motherboard['component_uuid']
            ];

        } catch (Exception $e) {
            error_log("UnifiedSlotTracker::getSlotAvailability error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to get slot availability: ' . $e->getMessage(),
                'total_slots' => [],
                'used_slots' => [],
                'available_slots' => []
            ];
        }
    }

    /**
     * Get comprehensive Riser slot availability for a server configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Riser slot availability details
     */
    public function getRiserSlotAvailability($configUuid) {
        try {
            // Step 1: Get motherboard from configuration
            $motherboard = $this->getMotherboardFromConfig($configUuid);

            if (!$motherboard) {
                return [
                    'success' => false,
                    'error' => 'No motherboard found in configuration',
                    'total_slots' => [],
                    'used_slots' => [],
                    'available_slots' => []
                ];
            }

            // Step 2: Load motherboard riser slots from JSON
            $totalRiserSlots = $this->loadMotherboardRiserSlots($motherboard['component_uuid']);

            if (!$totalRiserSlots['success']) {
                return [
                    'success' => false,
                    'error' => $totalRiserSlots['error'],
                    'total_slots' => [],
                    'used_slots' => [],
                    'available_slots' => []
                ];
            }

            // Step 3: Get used riser slots from database
            $usedRiserSlots = $this->getUsedRiserSlots($configUuid);

            // Step 4: Calculate available riser slots
            $availableRiserSlots = $this->calculateAvailableSlots($totalRiserSlots['slots'], $usedRiserSlots);

            return [
                'success' => true,
                'total_slots' => $totalRiserSlots['slots'],
                'used_slots' => $usedRiserSlots,
                'available_slots' => $availableRiserSlots,
                'motherboard_uuid' => $motherboard['component_uuid']
            ];

        } catch (Exception $e) {
            error_log("UnifiedSlotTracker::getRiserSlotAvailability error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to get riser slot availability: ' . $e->getMessage(),
                'total_slots' => [],
                'used_slots' => [],
                'available_slots' => []
            ];
        }
    }

    /**
     * Check if a PCIe card can fit in available slots
     *
     * @param string $configUuid Server configuration UUID
     * @param string $cardSlotSize Required slot size (x1, x4, x8, x16)
     * @return bool True if card can fit
     */
    public function canFitCard($configUuid, $cardSlotSize) {
        $availability = $this->getSlotAvailability($configUuid);

        if (!$availability['success']) {
            return false;
        }

        // Get compatible slot sizes (backward compatibility)
        $compatibleSlots = $this->slotCompatibility[$cardSlotSize] ?? [];

        // Check if any compatible slot is available
        foreach ($compatibleSlots as $slotSize) {
            if (!empty($availability['available_slots'][$slotSize])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a riser can fit in available riser slots
     *
     * @param string $configUuid Server configuration UUID
     * @return bool True if riser can fit
     */
    public function canFitRiser($configUuid) {
        $availability = $this->getRiserSlotAvailability($configUuid);

        if (!$availability['success']) {
            return false;
        }

        // Check if any riser slot is available
        return !empty($availability['available_slots']);
    }

    /**
     * Assign optimal PCIe slot to a new component
     * Uses smallest compatible slot first (x4 card prefers x4 over x16)
     *
     * @param string $configUuid Server configuration UUID
     * @param string $cardSlotSize Required slot size
     * @return string|null Assigned slot ID or null if no slots available
     */
    public function assignSlot($configUuid, $cardSlotSize) {
        $availability = $this->getSlotAvailability($configUuid);

        if (!$availability['success']) {
            return null;
        }

        // Get compatible slot sizes in preference order
        $compatibleSlots = $this->slotCompatibility[$cardSlotSize] ?? [];

        // Try each compatible slot size (smallest first)
        foreach ($compatibleSlots as $slotSize) {
            $availableSlots = $availability['available_slots'][$slotSize] ?? [];

            if (!empty($availableSlots)) {
                // Return first available slot of this size
                return $availableSlots[0];
            }
        }

        return null; // No compatible slots available
    }

    /**
     * Assign riser slot to a new riser card (legacy method - uses first available)
     *
     * @param string $configUuid Server configuration UUID
     * @return string|null Assigned riser slot ID or null if no slots available
     */
    public function assignRiserSlot($configUuid) {
        $availability = $this->getRiserSlotAvailability($configUuid);

        if (!$availability['success']) {
            return null;
        }

        // available_slots is grouped by type: ['x16' => [...], 'x8' => [...]]
        // Return first available slot from any type
        foreach ($availability['available_slots'] as $slotType => $slotIds) {
            if (!empty($slotIds)) {
                return $slotIds[0]; // Return first available slot ID
            }
        }

        return null; // No riser slots available
    }

    /**
     * Assign riser slot based on required slot size with backward compatibility
     *
     * @param string $configUuid Server configuration UUID
     * @param string $requiredSlotSize Required slot size (x8, x16, x4)
     * @return string|null Assigned slot ID or null if no compatible slots available
     */
    public function assignRiserSlotBySize($configUuid, $requiredSlotSize) {
        $availability = $this->getRiserSlotAvailability($configUuid);

        if (!$availability['success']) {
            return null;
        }

        // Normalize slot size (ensure format is "x16", "x8", etc.)
        $requiredSlotSize = strtolower($requiredSlotSize);
        if (!preg_match('/^x\d+$/', $requiredSlotSize)) {
            if (preg_match('/x?(\d+)/i', $requiredSlotSize, $matches)) {
                $requiredSlotSize = 'x' . $matches[1];
            } else {
                return null; // Invalid format
            }
        }

        // Get compatible slot sizes using backward compatibility matrix
        $compatibleSlots = $this->slotCompatibility[$requiredSlotSize] ?? [];

        if (empty($compatibleSlots)) {
            // No compatibility defined - try exact match only
            $compatibleSlots = [$requiredSlotSize];
        }

        // Try each compatible slot size (smallest first for optimal utilization)
        foreach ($compatibleSlots as $slotSize) {
            $availableSlots = $availability['available_slots'][$slotSize] ?? [];

            if (!empty($availableSlots)) {
                // Return first available slot of this size
                return $availableSlots[0];
            }
        }

        return null; // No compatible slots available
    }

    /**
     * Check if riser card can fit in available riser slots by size
     *
     * @param string $configUuid Server configuration UUID
     * @param string $riserSlotSize Required slot size (x8, x16)
     * @return bool True if compatible riser slot available
     */
    public function canFitRiserBySize($configUuid, $riserSlotSize) {
        return $this->assignRiserSlotBySize($configUuid, $riserSlotSize) !== null;
    }

    /**
     * Get all PCIe slot assignments for a configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Mapping of slot_id => component_uuid
     */
    public function getAllSlotAssignments($configUuid) {
        return $this->getUsedPCIeSlots($configUuid);
    }

    /**
     * Get all riser slot assignments for a configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Mapping of slot_id => component_uuid
     */
    public function getAllRiserSlotAssignments($configUuid) {
        return $this->getUsedRiserSlots($configUuid);
    }

    /**
     * Validate all PCIe slot assignments in a configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Validation results
     */
    public function validateAllSlots($configUuid) {
        $errors = [];
        $warnings = [];

        try {
            // Check 1: Get motherboard
            $motherboard = $this->getMotherboardFromConfig($configUuid);

            // Check if PCIe components exist (excluding risers)
            // Load configuration to count PCIe components
            require_once __DIR__ . '/../server/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            $pcieCount = 0;
            if ($config) {
                $components = $config->getComponents();
                foreach ($components as $comp) {
                    // Count pciecard (non-riser), hbacard, and component NICs
                    if (in_array($comp['component_type'], ['pciecard', 'hbacard', 'nic'])) {
                        $pcieCount++;
                    }
                }
            }

            if (!$motherboard && $pcieCount > 0) {
                $errors[] = "Configuration has $pcieCount PCIe cards/NICs but no motherboard";
                return [
                    'valid' => false,
                    'errors' => $errors,
                    'warnings' => ["Add motherboard to enable PCIe slot validation"],
                    'assignments' => []
                ];
            }

            if (!$motherboard) {
                // No motherboard and no PCIe components - valid
                return [
                    'valid' => true,
                    'errors' => [],
                    'warnings' => [],
                    'assignments' => []
                ];
            }

            // Check 2: Get slot availability
            $availability = $this->getSlotAvailability($configUuid);

            if (!$availability['success']) {
                // No PCIe slots available - check if any components need them
                if ($pcieCount > 0) {
                    // Components exist but no slots - validation error
                    $errors[] = "Configuration has $pcieCount PCIe components but motherboard has no PCIe slots";
                    return [
                        'valid' => false,
                        'errors' => $errors,
                        'warnings' => $warnings,
                        'assignments' => []
                    ];
                } else {
                    // No PCIe slots AND no PCIe components - valid configuration
                    return [
                        'valid' => true,
                        'errors' => [],
                        'warnings' => ['Motherboard has no PCIe slots - only riser slots available'],
                        'assignments' => [],
                        'total_slots' => 0,
                        'used_slots' => 0,
                        'available_slots' => 0
                    ];
                }
            }

            // Check 3: Count total slots vs used slots
            $totalSlotCount = $this->countTotalSlots($availability['total_slots']);
            $usedSlotCount = count($availability['used_slots']);

            if ($usedSlotCount > $totalSlotCount) {
                $errors[] = "Too many PCIe components: $usedSlotCount assigned but only $totalSlotCount slots available";
            }

            // Check 4: Validate each component fits in assigned slot
            foreach ($availability['used_slots'] as $slotId => $componentUuid) {
                $validation = $this->validateSlotAssignment($slotId, $componentUuid);

                if (!$validation['valid']) {
                    $errors[] = $validation['error'];
                } else if (!empty($validation['warning'])) {
                    $warnings[] = $validation['warning'];
                }
            }

            // Check 5: Duplicate slot assignments (data integrity check)
            $slotCounts = array_count_values(array_keys($availability['used_slots']));
            foreach ($slotCounts as $slotId => $count) {
                if ($count > 1) {
                    $errors[] = "Data corruption: Slot $slotId has multiple components assigned";
                }
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'assignments' => $availability['used_slots'],
                'total_slots' => $totalSlotCount,
                'used_slots' => $usedSlotCount,
                'available_slots' => $totalSlotCount - $usedSlotCount
            ];

        } catch (Exception $e) {
            error_log("UnifiedSlotTracker::validateAllSlots error: " . $e->getMessage());
            return [
                'valid' => false,
                'errors' => ['Validation error: ' . $e->getMessage()],
                'warnings' => [],
                'assignments' => []
            ];
        }
    }

    /**
     * Validate all riser slot assignments in a configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Validation results
     */
    public function validateAllRiserSlots($configUuid) {
        $errors = [];
        $warnings = [];

        try {
            // Check 1: Get motherboard
            $motherboard = $this->getMotherboardFromConfig($configUuid);

            // Check if riser components exist
            // Load configuration to count riser cards
            require_once __DIR__ . '/../server/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            $riserCount = 0;
            if ($config) {
                $configData = $config->getData();
                if (!empty($configData['pciecard_configurations'])) {
                    $pcieConfigs = json_decode($configData['pciecard_configurations'], true);
                    if (is_array($pcieConfigs)) {
                        foreach ($pcieConfigs as $pcie) {
                            // Count risers (slot_position starts with 'riser_')
                            if (!empty($pcie['slot_position']) &&
                                strpos($pcie['slot_position'], 'riser_') === 0) {
                                $riserCount++;
                            }
                        }
                    }
                }
            }

            if (!$motherboard && $riserCount > 0) {
                $errors[] = "Configuration has $riserCount risers but no motherboard";
                return [
                    'valid' => false,
                    'errors' => $errors,
                    'warnings' => ["Add motherboard to enable riser slot validation"],
                    'assignments' => []
                ];
            }

            if (!$motherboard) {
                // No motherboard and no risers - valid
                return [
                    'valid' => true,
                    'errors' => [],
                    'warnings' => [],
                    'assignments' => []
                ];
            }

            // Check 2: Get riser slot availability
            $availability = $this->getRiserSlotAvailability($configUuid);

            if (!$availability['success']) {
                // If no riser slots defined but risers exist, that's an error
                if ($riserCount > 0) {
                    $errors[] = "Motherboard does not support risers, but $riserCount risers are installed";
                } else {
                    // No riser slots AND no risers - valid but inform user
                    $warnings[] = 'Motherboard has no riser slots';
                }
                return [
                    'valid' => $riserCount === 0,
                    'errors' => $errors,
                    'warnings' => $warnings,
                    'assignments' => []
                ];
            }

            // Check 3: Count total riser slots vs used slots
            $totalRiserSlots = count($availability['total_slots']);
            $usedRiserSlots = count($availability['used_slots']);

            if ($usedRiserSlots > $totalRiserSlots) {
                $errors[] = "Too many risers: $usedRiserSlots assigned but only $totalRiserSlots riser slots available";
            }

            // Check 4: Validate each riser is assigned to riser slot
            foreach ($availability['used_slots'] as $slotId => $componentUuid) {
                // Verify component is actually a riser
                $subtype = $this->getComponentSubtype($componentUuid);
                if ($subtype !== 'Riser Card') {
                    $errors[] = "Component $componentUuid in riser slot $slotId is not a riser card (subtype: $subtype)";
                }
            }

            // Check 5: Verify no risers in PCIe slots
            // Get PCIe cards in PCIe slots (not riser slots) from configuration
            $pcieSlotComponents = [];
            if ($config) {
                $configData = $config->getData();
                if (!empty($configData['pciecard_configurations'])) {
                    $pcieConfigs = json_decode($configData['pciecard_configurations'], true);
                    if (is_array($pcieConfigs)) {
                        foreach ($pcieConfigs as $pcie) {
                            if (!empty($pcie['slot_position']) &&
                                strpos($pcie['slot_position'], 'pcie_') === 0) {
                                $pcieSlotComponents[] = [
                                    'component_uuid' => $pcie['uuid'],
                                    'slot_position' => $pcie['slot_position']
                                ];
                            }
                        }
                    }
                }
            }

            foreach ($pcieSlotComponents as $component) {
                $subtype = $this->getComponentSubtype($component['component_uuid']);
                if ($subtype === 'Riser Card') {
                    $errors[] = "Riser card {$component['component_uuid']} incorrectly assigned to PCIe slot {$component['slot_position']}. Risers must use riser slots.";
                }
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'assignments' => $availability['used_slots'],
                'total_riser_slots' => $totalRiserSlots,
                'used_riser_slots' => $usedRiserSlots,
                'available_riser_slots' => $totalRiserSlots - $usedRiserSlots
            ];

        } catch (Exception $e) {
            error_log("UnifiedSlotTracker::validateAllRiserSlots error: " . $e->getMessage());
            return [
                'valid' => false,
                'errors' => ['Validation error: ' . $e->getMessage()],
                'warnings' => [],
                'assignments' => []
            ];
        }
    }

    /**
     * Get comprehensive M.2 slot availability for a server configuration
     * Includes M.2 slots from motherboard and expansion cards
     *
     * @param string $configUuid Server configuration UUID
     * @return array M.2 slot availability details
     */
    public function getM2SlotAvailability($configUuid) {
        try {
            // Step 1: Get motherboard from configuration
            $motherboard = $this->getMotherboardFromConfig($configUuid);

            if (!$motherboard) {
                return [
                    'success' => false,
                    'error' => 'No motherboard found in configuration',
                    'motherboard_slots' => [
                        'total' => 0,
                        'used' => 0,
                        'available' => 0
                    ],
                    'expansion_card_slots' => [
                        'total' => 0,
                        'used' => 0,
                        'available' => 0,
                        'providers' => []
                    ]
                ];
            }

            // Step 2: Load motherboard M.2 slots from JSON
            $mbM2Slots = $this->loadMotherboardM2Slots($motherboard['component_uuid']);

            // Step 3: Get M.2 storage currently in configuration
            $usedM2Slots = $this->getUsedM2Slots($configUuid);

            // Step 4: Calculate available motherboard M.2 slots
            $availableMotherboardSlots = max(0, $mbM2Slots['total'] - count($usedM2Slots['motherboard']));

            // Step 5: Get expansion cards providing M.2 slots
            $expansionCardProviders = $this->getM2SlotProvidingCards($configUuid);

            // Calculate total expansion slots
            $totalExpansionSlots = 0;
            $usedExpansionSlots = 0;
            foreach ($expansionCardProviders as $provider) {
                $totalExpansionSlots += $provider['m2_slots_provided'];
                $usedExpansionSlots += count($provider['used_slots']);
            }

            return [
                'success' => true,
                'motherboard_slots' => [
                    'total' => $mbM2Slots['total'],
                    'used' => count($usedM2Slots['motherboard']),
                    'available' => $availableMotherboardSlots,
                    'assignments' => $usedM2Slots['motherboard']
                ],
                'expansion_card_slots' => [
                    'total' => $totalExpansionSlots,
                    'used' => $usedExpansionSlots,
                    'available' => max(0, $totalExpansionSlots - $usedExpansionSlots),
                    'providers' => $expansionCardProviders
                ],
                'motherboard_uuid' => $motherboard['component_uuid']
            ];

        } catch (Exception $e) {
            error_log("UnifiedSlotTracker::getM2SlotAvailability error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to get M.2 slot availability: ' . $e->getMessage(),
                'motherboard_slots' => [
                    'total' => 0,
                    'used' => 0,
                    'available' => 0
                ],
                'expansion_card_slots' => [
                    'total' => 0,
                    'used' => 0,
                    'available' => 0,
                    'providers' => []
                ]
            ];
        }
    }

    /**
     * Validate all M.2 slot assignments in a configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Validation results
     */
    public function validateM2Slots($configUuid) {
        $errors = [];
        $warnings = [];

        try {
            // Get M.2 slot availability
            $availability = $this->getM2SlotAvailability($configUuid);

            if (!$availability['success']) {
                return [
                    'valid' => true, // No motherboard = no M.2 slots to validate
                    'errors' => [],
                    'warnings' => ['No motherboard found - M.2 slots cannot be validated'],
                    'motherboard_slots' => 0,
                    'expansion_slots' => 0,
                    'total_m2_used' => 0
                ];
            }

            $mbTotal = $availability['motherboard_slots']['total'];
            $mbUsed = $availability['motherboard_slots']['used'];
            $expandTotal = $availability['expansion_card_slots']['total'];
            $expandUsed = $availability['expansion_card_slots']['used'];

            // Check motherboard M.2 capacity
            if ($mbUsed > $mbTotal && $mbTotal > 0) {
                $errors[] = "Too many M.2 drives: $mbUsed assigned but only $mbTotal motherboard M.2 slots available";
            }

            // Check expansion card M.2 capacity
            if ($expandUsed > $expandTotal && $expandTotal > 0) {
                $errors[] = "Too many M.2 drives on expansion cards: $expandUsed assigned but only $expandTotal expansion card M.2 slots available";
            }

            // Warning if motherboard M.2 slots are full
            if ($mbUsed === $mbTotal && $mbTotal > 0) {
                $warnings[] = "All motherboard M.2 slots are in use ($mbUsed/$mbTotal)";
            }

            // Warning if expansion M.2 slots are full
            if ($expandUsed === $expandTotal && $expandTotal > 0) {
                $warnings[] = "All expansion card M.2 slots are in use ($expandUsed/$expandTotal)";
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'motherboard_slots' => [
                    'total' => $mbTotal,
                    'used' => $mbUsed,
                    'available' => max(0, $mbTotal - $mbUsed)
                ],
                'expansion_slots' => [
                    'total' => $expandTotal,
                    'used' => $expandUsed,
                    'available' => max(0, $expandTotal - $expandUsed)
                ],
                'total_m2_used' => $mbUsed + $expandUsed,
                'total_m2_available' => ($mbTotal - $mbUsed) + ($expandTotal - $expandUsed)
            ];

        } catch (Exception $e) {
            error_log("UnifiedSlotTracker::validateM2Slots error: " . $e->getMessage());
            return [
                'valid' => false,
                'errors' => ['Validation error: ' . $e->getMessage()],
                'warnings' => [],
                'motherboard_slots' => ['total' => 0, 'used' => 0, 'available' => 0],
                'expansion_slots' => ['total' => 0, 'used' => 0, 'available' => 0]
            ];
        }
    }

    /**
     * Get component subtype from JSON specifications
     * Searches across pciecard, hbacard, and nic types
     *
     * @param string $componentUuid Component UUID
     * @return string|null Component subtype
     */
    private function getComponentSubtype($componentUuid) {
        try {
            // Try pciecard first
            $specs = $this->componentDataService->getComponentSpecifications('pciecard', $componentUuid);
            if ($specs && isset($specs['component_subtype'])) {
                return $specs['component_subtype'];
            }

            // Try hbacard
            $specs = $this->componentDataService->getComponentSpecifications('hbacard', $componentUuid);
            if ($specs && isset($specs['component_subtype'])) {
                return $specs['component_subtype'];
            }

            // Try nic
            $specs = $this->componentDataService->getComponentSpecifications('nic', $componentUuid);
            if ($specs && isset($specs['component_subtype'])) {
                return $specs['component_subtype'];
            }

            return null;

        } catch (Exception $e) {
            error_log("Error getting component subtype for $componentUuid: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get motherboard component from server configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array|null Motherboard component data
     */
    private function getMotherboardFromConfig($configUuid) {
        try {
            // Load configuration using ServerConfiguration class (JSON-based storage)
            require_once __DIR__ . '/../server/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            if (!$config) {
                error_log("UnifiedSlotTracker: Configuration $configUuid not found");
                return null;
            }

            // Get motherboard UUID from JSON column
            $motherboardUuid = $config->get('motherboard_uuid');

            if (empty($motherboardUuid)) {
                return null;
            }

            return [
                'component_uuid' => $motherboardUuid
            ];

        } catch (Exception $e) {
            error_log("Error getting motherboard: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Load PCIe slots from motherboard JSON specifications
     *
     * @param string $motherboardUuid Motherboard UUID
     * @return array Total slots configuration
     */
    private function loadMotherboardPCIeSlots($motherboardUuid) {
        try {
            $mbSpecs = $this->componentDataService->getComponentSpecifications('motherboard', $motherboardUuid);

            if (!$mbSpecs) {
                return [
                    'success' => false,
                    'error' => 'Motherboard specifications not found in JSON',
                    'slots' => []
                ];
            }

            // Extract PCIe slots from expansion_slots
            $pcieSlots = $mbSpecs['expansion_slots']['pcie_slots'] ?? [];

            if (empty($pcieSlots)) {
                return [
                    'success' => false,
                    'error' => 'No PCIe slots defined in motherboard specifications',
                    'slots' => []
                ];
            }

            // Convert JSON structure to slot ID array
            $slots = [];

            foreach ($pcieSlots as $slotConfig) {
                $slotType = $slotConfig['type'] ?? '';
                $count = $slotConfig['count'] ?? 0;

                // Extract slot size from type (e.g., "PCIe 5.0 x16" → "x16")
                if (preg_match('/x(\d+)/i', $slotType, $matches)) {
                    $slotSize = 'x' . $matches[1];

                    // Generate slot IDs
                    if (!isset($slots[$slotSize])) {
                        $slots[$slotSize] = [];
                    }

                    $startIndex = count($slots[$slotSize]) + 1;
                    for ($i = 0; $i < $count; $i++) {
                        $slots[$slotSize][] = "pcie_{$slotSize}_slot_" . ($startIndex + $i);
                    }
                }
            }

            return [
                'success' => true,
                'slots' => $slots
            ];

        } catch (Exception $e) {
            error_log("Error loading motherboard PCIe slots: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to load motherboard PCIe slots: ' . $e->getMessage(),
                'slots' => []
            ];
        }
    }

    /**
     * Load riser slots from motherboard JSON specifications
     *
     * @param string $motherboardUuid Motherboard UUID
     * @return array Total riser slots configuration
     */
    private function loadMotherboardRiserSlots($motherboardUuid) {
        try {
            $mbSpecs = $this->componentDataService->getComponentSpecifications('motherboard', $motherboardUuid);

            if (!$mbSpecs) {
                return [
                    'success' => false,
                    'error' => 'Motherboard specifications not found in JSON',
                    'slots' => []
                ];
            }

            // Check for riser_slots in expansion_slots
            $riserSlots = $mbSpecs['expansion_slots']['riser_slots'] ?? null;

            // If no riser_slots defined, check legacy riser_compatibility.max_risers
            if ($riserSlots === null) {
                $maxRisers = $mbSpecs['expansion_slots']['riser_compatibility']['max_risers'] ?? 0;

                if ($maxRisers > 0) {
                    // Generate riser slots from max_risers count (assume x16 for legacy)
                    $slots = ['x16' => []];
                    for ($i = 1; $i <= $maxRisers; $i++) {
                        $slots['x16'][] = "riser_x16_slot_$i";
                    }

                    return [
                        'success' => true,
                        'slots' => $slots
                    ];
                }

                return [
                    'success' => false,
                    'error' => 'No riser slots defined in motherboard specifications',
                    'slots' => []
                ];
            }

            // Process riser_slots array with size information
            $slots = [];

            foreach ($riserSlots as $slotConfig) {
                $count = $slotConfig['count'] ?? 1;
                $slotType = $slotConfig['type'] ?? 'PCIe x16 Riser';

                // Extract slot size from type
                $slotSize = 'x16'; // Default
                if (preg_match('/x(\d+)/i', $slotType, $matches)) {
                    $slotSize = 'x' . $matches[1];
                }

                // Initialize array for this slot size if not exists
                if (!isset($slots[$slotSize])) {
                    $slots[$slotSize] = [];
                }

                // Generate slot IDs for this type
                $startIndex = count($slots[$slotSize]) + 1;
                for ($i = 0; $i < $count; $i++) {
                    $slots[$slotSize][] = "riser_{$slotSize}_slot_" . ($startIndex + $i);
                }
            }

            if (empty($slots)) {
                return [
                    'success' => false,
                    'error' => 'No riser slots defined in motherboard specifications',
                    'slots' => []
                ];
            }

            return [
                'success' => true,
                'slots' => $slots
            ];

        } catch (Exception $e) {
            error_log("Error loading motherboard riser slots: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to load motherboard riser slots: ' . $e->getMessage(),
                'slots' => []
            ];
        }
    }

    /**
     * Get used PCIe slots from database (excludes risers)
     *
     * @param string $configUuid Server configuration UUID
     * @return array Mapping of slot_id => component_uuid
     */
    private function getUsedPCIeSlots($configUuid) {
        try {
            // Load configuration using ServerConfiguration class (JSON-based storage)
            require_once __DIR__ . '/../server/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            if (!$config) {
                error_log("UnifiedSlotTracker: Configuration $configUuid not found");
                return [];
            }

            $configData = $config->getData();
            $usedSlots = [];

            // Check PCIe cards configuration
            if (!empty($configData['pciecard_configurations'])) {
                $pcieConfigs = json_decode($configData['pciecard_configurations'], true);
                if (is_array($pcieConfigs)) {
                    foreach ($pcieConfigs as $pcie) {
                        if (!empty($pcie['slot_position']) &&
                            strpos($pcie['slot_position'], 'pcie_') === 0) {
                            $usedSlots[$pcie['slot_position']] = $pcie['uuid'];
                        }
                    }
                }
            }

            // HBA-TRACK FIX: Check HBA card with new JSON format (includes slot_position)
            if (!empty($configData['hbacard_config'])) {
                $hbaConfig = json_decode($configData['hbacard_config'], true);
                if (is_array($hbaConfig) && !empty($hbaConfig['slot_position'])) {
                    if (strpos($hbaConfig['slot_position'], 'pcie_') === 0) {
                        $usedSlots[$hbaConfig['slot_position']] = $hbaConfig['uuid'];
                        error_log("HBA-TRACK: HBA card occupies slot: {$hbaConfig['slot_position']}");
                    }
                }
            }
            // BACKWARD COMPATIBILITY: Check legacy hbacard_uuid column
            else if (!empty($configData['hbacard_uuid'])) {
                error_log("HBA-TRACK WARNING: HBA card {$configData['hbacard_uuid']} has no slot position (legacy format)");
                // Don't block - allow legacy configs to work
                // TODO: Add migration notice to admin dashboard
            }

            // Check NIC config
            if (!empty($configData['nic_config'])) {
                $nicConfig = json_decode($configData['nic_config'], true);
                if (isset($nicConfig['nics']) && is_array($nicConfig['nics'])) {
                    foreach ($nicConfig['nics'] as $nic) {
                        // Only component NICs use PCIe slots, not onboard NICs
                        if (($nic['source_type'] ?? 'component') === 'component' &&
                            !empty($nic['slot_position']) &&
                            strpos($nic['slot_position'], 'pcie_') === 0) {
                            $usedSlots[$nic['slot_position']] = $nic['uuid'];
                        }
                    }
                }
            }

            return $usedSlots;

        } catch (Exception $e) {
            error_log("Error getting used PCIe slots: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get used riser slots from database
     *
     * @param string $configUuid Server configuration UUID
     * @return array Mapping of slot_id => component_uuid
     */
    private function getUsedRiserSlots($configUuid) {
        try {
            // Load configuration using ServerConfiguration class (JSON-based storage)
            require_once __DIR__ . '/../server/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            if (!$config) {
                error_log("UnifiedSlotTracker: Configuration $configUuid not found");
                return [];
            }

            $configData = $config->getData();
            $usedSlots = [];

            // Check PCIe cards configuration for risers (riser cards have slot_position like 'riser_%')
            if (!empty($configData['pciecard_configurations'])) {
                $pcieConfigs = json_decode($configData['pciecard_configurations'], true);
                if (is_array($pcieConfigs)) {
                    foreach ($pcieConfigs as $pcie) {
                        if (!empty($pcie['slot_position']) &&
                            strpos($pcie['slot_position'], 'riser_') === 0) {
                            $usedSlots[$pcie['slot_position']] = $pcie['uuid'];
                        }
                    }
                }
            }

            return $usedSlots;

        } catch (Exception $e) {
            error_log("Error getting used riser slots: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Calculate available slots by removing used slots from total
     *
     * @param array $totalSlots Total slots by size
     * @param array $usedSlots Used slot IDs
     * @return array Available slots by size
     */
    private function calculateAvailableSlots($totalSlots, $usedSlots) {
        $availableSlots = [];

        foreach ($totalSlots as $slotSize => $slotIds) {
            $availableSlots[$slotSize] = array_values(
                array_diff($slotIds, array_keys($usedSlots))
            );
        }

        return $availableSlots;
    }

    /**
     * Count total number of slots across all sizes
     *
     * @param array $totalSlots Total slots by size
     * @return int Total slot count
     */
    private function countTotalSlots($totalSlots) {
        $count = 0;
        foreach ($totalSlots as $slotIds) {
            $count += count($slotIds);
        }
        return $count;
    }

    /**
     * Validate a single PCIe slot assignment
     *
     * @param string $slotId Slot identifier
     * @param string $componentUuid Component UUID
     * @return array Validation result
     */
    private function validateSlotAssignment($slotId, $componentUuid) {
        try {
            // Extract slot size from slot ID (e.g., "pcie_x16_slot_1" → "x16")
            if (preg_match('/pcie_(x\d+)_slot_/', $slotId, $matches)) {
                $slotSize = $matches[1];
            } else {
                return [
                    'valid' => false,
                    'error' => "Invalid slot ID format: $slotId"
                ];
            }

            // Get component type
            $componentType = $this->getComponentTypeByUuid($componentUuid);

            if (!$componentType) {
                return [
                    'valid' => false,
                    'error' => "Component $componentUuid not found in any inventory table"
                ];
            }

            // Get component specs
            $componentSpecs = $this->componentDataService->getComponentSpecifications($componentType, $componentUuid);

            if (!$componentSpecs) {
                return [
                    'valid' => true,
                    'warning' => "Unable to verify component $componentUuid specifications"
                ];
            }

            // Check if this is a riser in a PCIe slot (invalid)
            if (isset($componentSpecs['component_subtype']) && $componentSpecs['component_subtype'] === 'Riser Card') {
                return [
                    'valid' => false,
                    'error' => "Riser card $componentUuid cannot be installed in PCIe slot $slotId. Use riser slots instead."
                ];
            }

            // Extract card slot size
            $cardSize = $this->extractPCIeSlotSize($componentSpecs);

            if (!$cardSize) {
                return [
                    'valid' => true,
                    'warning' => "Unable to determine PCIe slot size for component $componentUuid"
                ];
            }

            // Check backward compatibility
            if (!$this->isPCIeSlotCompatible($cardSize, $slotSize)) {
                return [
                    'valid' => false,
                    'error' => "Component $componentUuid requires $cardSize slot but assigned to $slotSize slot ($slotId)"
                ];
            }

            // Check if using larger slot (acceptable but worth noting)
            if ($cardSize !== $slotSize) {
                return [
                    'valid' => true,
                    'warning' => "Component $componentUuid ($cardSize) installed in larger $slotSize slot ($slotId)"
                ];
            }

            return ['valid' => true];

        } catch (Exception $e) {
            error_log("Error validating slot assignment: " . $e->getMessage());
            return [
                'valid' => false,
                'error' => "Validation error for slot $slotId: " . $e->getMessage()
            ];
        }
    }

    /**
     * Get component type by UUID (search all inventory tables)
     *
     * @param string $uuid Component UUID
     * @return string|null Component type
     */
    private function getComponentTypeByUuid($uuid) {
        $tables = [
            'pciecard' => 'pciecardinventory',
            'hbacard' => 'hbacardinventory',
            'nic' => 'nicinventory'
        ];

        foreach ($tables as $type => $table) {
            try {
                $stmt = $this->pdo->prepare("SELECT UUID FROM $table WHERE UUID = ? LIMIT 1");
                $stmt->execute([$uuid]);
                if ($stmt->fetch()) {
                    return $type;
                }
            } catch (Exception $e) {
                error_log("Error checking $table: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Extract PCIe slot size from component specifications
     *
     * @param array $specs Component specifications
     * @return string|null Slot size (x1, x4, x8, x16)
     */
    private function extractPCIeSlotSize($specs) {
        // Check interface field
        $interface = $specs['interface'] ?? '';

        if (preg_match('/x(\d+)/i', $interface, $matches)) {
            return 'x' . $matches[1];
        }

        // Check slot_type field
        $slotType = $specs['slot_type'] ?? '';

        if (preg_match('/x(\d+)/i', $slotType, $matches)) {
            return 'x' . $matches[1];
        }

        return null;
    }

    /**
     * Check if card can fit in slot (PCIe backward compatibility)
     *
     * @param string $cardSize Card slot requirement (x1, x4, x8, x16)
     * @param string $slotSize Available slot size
     * @return bool True if compatible
     */
    private function isPCIeSlotCompatible($cardSize, $slotSize) {
        $compatibleSlots = $this->slotCompatibility[$cardSize] ?? [];
        return in_array($slotSize, $compatibleSlots);
    }

    /**
     * Get all riser cards currently in configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Array of riser card components with their details
     */
    private function getRiserCardsInConfig($configUuid) {
        try {
            // Load configuration using ServerConfiguration class (JSON-based storage)
            require_once __DIR__ . '/../server/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            if (!$config) {
                error_log("UnifiedSlotTracker: Configuration $configUuid not found");
                return [];
            }

            $configData = $config->getData();
            $riserCards = [];

            // Get riser cards from PCIe cards configuration
            if (!empty($configData['pciecard_configurations'])) {
                $pcieConfigs = json_decode($configData['pciecard_configurations'], true);
                if (is_array($pcieConfigs)) {
                    foreach ($pcieConfigs as $pcie) {
                        // Check if it's in a riser slot
                        if (!empty($pcie['slot_position']) &&
                            strpos($pcie['slot_position'], 'riser_') === 0) {

                            // Verify this is actually a riser card by checking JSON specs
                            $specs = $this->componentDataService->getComponentSpecifications('pciecard', $pcie['uuid']);
                            if ($specs && isset($specs['component_subtype']) && $specs['component_subtype'] === 'Riser Card') {
                                $riserCards[] = [
                                    'component_uuid' => $pcie['uuid'],
                                    'slot_position' => $pcie['slot_position'],
                                    'specs' => $specs
                                ];
                            }
                        }
                    }
                }
            }

            return $riserCards;

        } catch (Exception $e) {
            error_log("Error getting riser cards in config: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Load PCIe slots provided by a specific riser card
     *
     * @param string $riserUuid Riser card UUID
     * @return array PCIe slots provided by this riser, grouped by slot type
     */
    private function loadRiserCardProvidedPCIeSlots($riserUuid) {
        try {
            // Get riser card specifications from JSON
            $riserSpecs = $this->componentDataService->getComponentSpecifications('pciecard', $riserUuid);

            if (!$riserSpecs) {
                error_log("Riser card specifications not found for UUID: $riserUuid");
                return [];
            }

            // Verify this is a riser card
            if (($riserSpecs['component_subtype'] ?? '') !== 'Riser Card') {
                error_log("Component $riserUuid is not a riser card");
                return [];
            }

            // Extract PCIe slots provided by this riser
            $pcieSlots = $riserSpecs['pcie_slots'] ?? 0;
            $slotType = $riserSpecs['slot_type'] ?? 'x16';

            if ($pcieSlots <= 0) {
                error_log("Riser card $riserUuid does not provide any PCIe slots");
                return [];
            }

            // Normalize slot type (e.g., "x16" or "PCIe x16" → "x16")
            if (preg_match('/x(\d+)/i', $slotType, $matches)) {
                $slotSize = 'x' . $matches[1];
            } else {
                $slotSize = 'x16'; // Default fallback
            }

            // Generate slot IDs with riser UUID prefix
            $slots = [$slotSize => []];
            for ($i = 1; $i <= $pcieSlots; $i++) {
                $slots[$slotSize][] = "riser_{$riserUuid}_pcie_{$slotSize}_slot_{$i}";
            }

            return $slots;

        } catch (Exception $e) {
            error_log("Error loading riser card provided PCIe slots: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Load M.2 slots from motherboard JSON specifications
     *
     * @param string $motherboardUuid Motherboard UUID
     * @return array M.2 slots information
     */
    private function loadMotherboardM2Slots($motherboardUuid) {
        try {
            $mbSpecs = $this->componentDataService->getComponentSpecifications('motherboard', $motherboardUuid);

            if (!$mbSpecs) {
                return [
                    'total' => 0,
                    'details' => []
                ];
            }

            // Extract M.2 slots from storage.nvme.m2_slots
            $m2Total = 0;
            $m2Details = [];

            if (isset($mbSpecs['storage']['nvme']['m2_slots']) && is_array($mbSpecs['storage']['nvme']['m2_slots'])) {
                foreach ($mbSpecs['storage']['nvme']['m2_slots'] as $slotConfig) {
                    $count = $slotConfig['count'] ?? 0;
                    $m2Total += $count;
                    $m2Details[] = [
                        'count' => $count,
                        'type' => $slotConfig['type'] ?? 'M.2 (NVMe)',
                        'form_factor' => $slotConfig['form_factor'] ?? '2280'
                    ];
                }
            }

            return [
                'total' => $m2Total,
                'details' => $m2Details
            ];

        } catch (Exception $e) {
            error_log("Error loading motherboard M.2 slots: " . $e->getMessage());
            return [
                'total' => 0,
                'details' => []
            ];
        }
    }

    /**
     * Get M.2 storage currently used in configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array M.2 assignments grouped by source (motherboard/expansion_card)
     */
    private function getUsedM2Slots($configUuid) {
        try {
            require_once __DIR__ . '/../server/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            if (!$config) {
                return [
                    'motherboard' => [],
                    'expansion_cards' => []
                ];
            }

            $configData = $config->getData();
            $usedM2 = [
                'motherboard' => [],
                'expansion_cards' => []
            ];

            // Get storage components from configuration
            if (!empty($configData['storage_configurations'])) {
                $storageConfigs = json_decode($configData['storage_configurations'], true);
                if (is_array($storageConfigs)) {
                    foreach ($storageConfigs as $storage) {
                        $storageUuid = $storage['uuid'] ?? null;
                        $slotPosition = $storage['slot_position'] ?? '';

                        if ($storageUuid) {
                            // Check if this is M.2 storage
                            $storageSpecs = $this->componentDataService->getComponentSpecifications('storage', $storageUuid);
                            if ($storageSpecs) {
                                $formFactor = strtolower($storageSpecs['form_factor'] ?? '');
                                if (strpos($formFactor, 'm.2') !== false || strpos($formFactor, 'm2') !== false) {
                                    // Determine source based on slot_position
                                    if (strpos($slotPosition, 'expansion') !== false ||
                                        strpos($slotPosition, 'pcie') !== false ||
                                        strpos($slotPosition, 'adapter') !== false ||
                                        strpos($slotPosition, 'card') !== false) {
                                        $usedM2['expansion_cards'][] = [
                                            'uuid' => $storageUuid,
                                            'model' => $storageSpecs['model'] ?? 'Unknown',
                                            'slot_position' => $slotPosition
                                        ];
                                    } else {
                                        $usedM2['motherboard'][] = [
                                            'uuid' => $storageUuid,
                                            'model' => $storageSpecs['model'] ?? 'Unknown',
                                            'slot_position' => $slotPosition
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return $usedM2;

        } catch (Exception $e) {
            error_log("Error getting used M.2 slots: " . $e->getMessage());
            return [
                'motherboard' => [],
                'expansion_cards' => []
            ];
        }
    }

    /**
     * Get expansion cards that provide M.2 slots
     *
     * @param string $configUuid Server configuration UUID
     * @return array Array of M.2-capable expansion cards with slot info
     */
    private function getM2SlotProvidingCards($configUuid) {
        try {
            require_once __DIR__ . '/../server/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            if (!$config) {
                return [];
            }

            $configData = $config->getData();
            $m2Cards = [];

            // Check PCIe cards for M.2 provision capability
            if (!empty($configData['pciecard_configurations'])) {
                $pcieConfigs = json_decode($configData['pciecard_configurations'], true);
                if (is_array($pcieConfigs)) {
                    foreach ($pcieConfigs as $pcie) {
                        $pcieUuid = $pcie['uuid'] ?? null;
                        if ($pcieUuid) {
                            $pcieSpecs = $this->componentDataService->getComponentSpecifications('pciecard', $pcieUuid);
                            if ($pcieSpecs && isset($pcieSpecs['m2_slots']) && $pcieSpecs['m2_slots'] > 0) {
                                // This card provides M.2 slots - count how many are used
                                $usedM2 = $this->countM2SlotsOnCard($pcieUuid, $configData);
                                $m2Cards[] = [
                                    'card_uuid' => $pcieUuid,
                                    'model' => $pcieSpecs['model'] ?? 'Unknown',
                                    'm2_slots_provided' => $pcieSpecs['m2_slots'],
                                    'used_slots' => array_fill(0, $usedM2, true), // Array with n elements
                                    'form_factors' => $pcieSpecs['m2_form_factors'] ?? [],
                                    'slot_position' => $pcie['slot_position'] ?? 'N/A'
                                ];
                            }
                        }
                    }
                }
            }

            return $m2Cards;

        } catch (Exception $e) {
            error_log("Error getting M.2 slot providing cards: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Count how many M.2 slots are used on a specific card
     *
     * @param string $cardUuid PCIe card UUID
     * @param array $configData Configuration data
     * @return int Number of used M.2 slots
     */
    private function countM2SlotsOnCard($cardUuid, $configData) {
        $count = 0;

        if (!empty($configData['storage_configurations'])) {
            $storageConfigs = json_decode($configData['storage_configurations'], true);
            if (is_array($storageConfigs)) {
                foreach ($storageConfigs as $storage) {
                    $storageUuid = $storage['uuid'] ?? null;
                    $slotPosition = $storage['slot_position'] ?? '';

                    if ($storageUuid && strpos($slotPosition, $cardUuid) !== false) {
                        // Check if this is M.2 storage
                        $storageSpecs = $this->componentDataService->getComponentSpecifications('storage', $storageUuid);
                        if ($storageSpecs) {
                            $formFactor = strtolower($storageSpecs['form_factor'] ?? '');
                            if (strpos($formFactor, 'm.2') !== false || strpos($formFactor, 'm2') !== false) {
                                $count++;
                            }
                        }
                    }
                }
            }
        }

        return $count;
    }

    /**
     * UNIFIED METHOD: Count total M.2 slots available in configuration
     * Includes: motherboard direct M.2 slots + NVMe adapter M.2 slots
     *
     * @param string $configUuid Server configuration UUID
     * @return int Total M.2 slots available
     */
    public function countTotalM2Slots($configUuid) {
        try {
            $availability = $this->getM2SlotAvailability($configUuid);
            if (!$availability['success']) {
                return 0;
            }
            return $availability['motherboard_slots']['total'] + $availability['expansion_card_slots']['total'];
        } catch (Exception $e) {
            error_log("Error counting total M.2 slots: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * UNIFIED METHOD: Count M.2 slots currently used by existing storage
     *
     * @param string $configUuid Server configuration UUID
     * @return int Number of M.2 slots in use
     */
    public function countUsedM2Slots($configUuid) {
        try {
            $availability = $this->getM2SlotAvailability($configUuid);
            if (!$availability['success']) {
                return 0;
            }
            return $availability['motherboard_slots']['used'] + $availability['expansion_card_slots']['used'];
        } catch (Exception $e) {
            error_log("Error counting used M.2 slots: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * UNIFIED METHOD: Get detailed M.2/U.2 slot usage information
     *
     * @param string $configUuid Server configuration UUID
     * @return array Slot usage statistics
     */
    public function getNvmeSlotUsage($configUuid) {
        try {
            $m2Availability = $this->getM2SlotAvailability($configUuid);
            $u2Availability = null;

            // Note: Would need U.2 availability method for complete U.2 support
            // For now, use the CountUsedU2Slots logic from the original
            require_once __DIR__ . '/../server/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            $usedU2Slots = [];
            if ($config) {
                $configData = $config->getData();
                if (!empty($configData['storage_configurations'])) {
                    $storageConfigs = json_decode($configData['storage_configurations'], true);
                    if (is_array($storageConfigs)) {
                        foreach ($storageConfigs as $storage) {
                            $storageSpecs = $this->componentDataService->getComponentSpecifications('storage', $storage['uuid'] ?? null);
                            if ($storageSpecs) {
                                $formFactor = strtolower($storageSpecs['form_factor'] ?? '');
                                if (strpos($formFactor, 'u.2') !== false || strpos($formFactor, 'u.3') !== false) {
                                    $usedU2Slots[] = $storage['uuid'];
                                }
                            }
                        }
                    }
                }
            }

            return [
                'm2' => [
                    'total' => $m2Availability['success'] ? ($m2Availability['motherboard_slots']['total'] + $m2Availability['expansion_card_slots']['total']) : 0,
                    'used' => $m2Availability['success'] ? ($m2Availability['motherboard_slots']['used'] + $m2Availability['expansion_card_slots']['used']) : 0,
                    'available' => $m2Availability['success'] ? max(0, ($m2Availability['motherboard_slots']['total'] + $m2Availability['expansion_card_slots']['total']) - ($m2Availability['motherboard_slots']['used'] + $m2Availability['expansion_card_slots']['used'])) : 0
                ],
                'u2' => [
                    'total' => 0,
                    'used' => count($usedU2Slots),
                    'available' => 0
                ]
            ];
        } catch (Exception $e) {
            error_log("Error getting NVMe slot usage: " . $e->getMessage());
            return [
                'm2' => ['total' => 0, 'used' => 0, 'available' => 0],
                'u2' => ['total' => 0, 'used' => 0, 'available' => 0]
            ];
        }
    }

    /**
     * P2.3: Get all PCIe cards installed in a specific riser's provided slots
     * Used for cascade validation/removal when riser is removed
     *
     * @param string $configUuid Server configuration UUID
     * @param string $riserUuid Riser card UUID to check
     * @return array Array of PCIe cards installed in this riser's slots
     */
    public function getPCIeCardsOnRiser($configUuid, $riserUuid) {
        try {
            require_once __DIR__ . '/../server/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            if (!$config) {
                return [];
            }

            $configData = $config->getData();
            $cardsOnRiser = [];

            // Get all PCIe cards in configuration
            if (!empty($configData['pciecard_configurations'])) {
                $pcieConfigs = json_decode($configData['pciecard_configurations'], true);
                if (is_array($pcieConfigs)) {
                    foreach ($pcieConfigs as $pcie) {
                        $slotPosition = $pcie['slot_position'] ?? '';

                        // Check if this card is installed in a slot provided by the riser
                        // Slot format: "riser_{$riserUuid}_pcie_x16_slot_1"
                        if (strpos($slotPosition, "riser_{$riserUuid}_pcie_") === 0) {
                            $cardsOnRiser[] = [
                                'uuid' => $pcie['uuid'],
                                'slot_position' => $slotPosition,
                                'is_riser' => false
                            ];
                        }
                    }
                }
            }

            return $cardsOnRiser;

        } catch (Exception $e) {
            error_log("Error getting PCIe cards on riser: " . $e->getMessage());
            return [];
        }
    }

    /**
     * P2.3: Validate that a riser can be safely removed
     * Checks if there are dependent components that would be affected
     *
     * @param string $configUuid Server configuration UUID
     * @param string $riserUuid Riser card UUID to validate
     * @return array Validation result with dependencies list
     */
    public function validateRiserRemoval($configUuid, $riserUuid) {
        try {
            // Get PCIe cards that depend on this riser
            $dependentCards = $this->getPCIeCardsOnRiser($configUuid, $riserUuid);

            if (empty($dependentCards)) {
                return [
                    'can_remove' => true,
                    'dependent_components' => [],
                    'message' => 'Riser can be safely removed (no dependent components)'
                ];
            }

            $componentDetails = [];
            foreach ($dependentCards as $card) {
                $specs = $this->componentDataService->getComponentSpecifications('pciecard', $card['uuid']);
                $componentDetails[] = [
                    'uuid' => $card['uuid'],
                    'model' => $specs['model'] ?? 'Unknown',
                    'subtype' => $specs['component_subtype'] ?? 'PCIe Card',
                    'slot_position' => $card['slot_position']
                ];
            }

            return [
                'can_remove' => false,
                'dependent_components' => $componentDetails,
                'message' => 'Riser has ' . count($dependentCards) . ' dependent PCIe card(s). Remove those first or use cascade removal.',
                'cascade_required' => true
            ];

        } catch (Exception $e) {
            error_log("Error validating riser removal: " . $e->getMessage());
            return [
                'can_remove' => false,
                'dependent_components' => [],
                'message' => 'Validation error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * P2.3: Get cascade removal plan for a riser
     * Returns list of components that must be removed if riser is removed
     *
     * @param string $configUuid Server configuration UUID
     * @param string $riserUuid Riser card UUID to remove
     * @return array Cascade removal plan with dependencies
     */
    public function getCascadeRemovalPlan($configUuid, $riserUuid) {
        try {
            $removalPlan = [
                'riser_uuid' => $riserUuid,
                'dependent_pciecard' => [],
                'total_affected' => 0,
                'warning' => null
            ];

            // Get direct dependents (PCIe cards on riser slots)
            $directDependents = $this->getPCIeCardsOnRiser($configUuid, $riserUuid);

            foreach ($directDependents as $card) {
                $specs = $this->componentDataService->getComponentSpecifications('pciecard', $card['uuid']);
                $removalPlan['dependent_pciecard'][] = [
                    'uuid' => $card['uuid'],
                    'model' => $specs['model'] ?? 'Unknown',
                    'subtype' => $specs['component_subtype'] ?? 'PCIe Card',
                    'reason' => 'Installed in riser-provided PCIe slot'
                ];
            }

            $removalPlan['total_affected'] = count($removalPlan['dependent_pciecard']);

            if ($removalPlan['total_affected'] > 0) {
                $removalPlan['warning'] = "Removing this riser will cascade-remove {$removalPlan['total_affected']} dependent PCIe card(s)";
            }

            return $removalPlan;

        } catch (Exception $e) {
            error_log("Error getting cascade removal plan: " . $e->getMessage());
            return [
                'riser_uuid' => $riserUuid,
                'dependent_pciecard' => [],
                'total_affected' => 0,
                'error' => 'Failed to generate cascade plan: ' . $e->getMessage()
            ];
        }
    }

    /**
     * P2.3: Validate all riser slot assignments and detect conflicts
     * Can detect orphaned cards or invalid riser references
     *
     * @param string $configUuid Server configuration UUID
     * @return array Validation results with any issues found
     */
    public function validateRiserSlotIntegrity($configUuid) {
        try {
            require_once __DIR__ . '/../server/ServerConfiguration.php';
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);

            if (!$config) {
                return [
                    'valid' => true,
                    'errors' => [],
                    'warnings' => []
                ];
            }

            $configData = $config->getData();
            $errors = [];
            $warnings = [];

            // Get all riser cards in this configuration
            $riserCards = $this->getRiserCardsInConfig($configUuid);
            $riserUuids = array_column($riserCards, 'component_uuid');

            // Check all PCIe cards
            if (!empty($configData['pciecard_configurations'])) {
                $pcieConfigs = json_decode($configData['pciecard_configurations'], true);
                if (is_array($pcieConfigs)) {
                    foreach ($pcieConfigs as $pcie) {
                        $slotPosition = $pcie['slot_position'] ?? '';

                        // If card claims to be in a riser slot, verify riser exists
                        if (strpos($slotPosition, 'riser_') === 0) {
                            // Extract riser UUID from slot position
                            if (preg_match('/^riser_([a-z0-9-]+)_pcie_/', $slotPosition, $matches)) {
                                $referencedRiserUuid = $matches[1];

                                if (!in_array($referencedRiserUuid, $riserUuids)) {
                                    $errors[] = "PCIe card {$pcie['uuid']} references non-existent riser: $referencedRiserUuid";
                                }
                            } else {
                                $errors[] = "PCIe card {$pcie['uuid']} has invalid riser slot format: $slotPosition";
                            }
                        }
                    }
                }
            }

            // Validate riser specifications
            foreach ($riserCards as $riser) {
                $slotCount = $riser['specs']['pcie_slots'] ?? 0;
                if ($slotCount <= 0) {
                    $warnings[] = "Riser {$riser['component_uuid']} specifies 0 PCIe slots - may not function properly";
                }

                // Count cards actually in this riser's slots
                $dependentCount = count($this->getPCIeCardsOnRiser($configUuid, $riser['component_uuid']));
                if ($dependentCount > $slotCount) {
                    $errors[] = "Riser {$riser['component_uuid']} has $dependentCount cards but only provides $slotCount slots";
                }
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'riser_count' => count($riserCards),
                'configuration_valid' => empty($errors)
            ];

        } catch (Exception $e) {
            error_log("Error validating riser slot integrity: " . $e->getMessage());
            return [
                'valid' => false,
                'errors' => ['Validation error: ' . $e->getMessage()],
                'warnings' => []
            ];
        }
    }
}
?>
