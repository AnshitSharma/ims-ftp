<?php
/**
 * Storage Connection Validator - JSON-Driven Storage Compatibility Engine
 *
 * Implements 10-check validation flow WITHOUT hardcoded rules.
 * All connection logic derived from JSON specifications.
 *
 * Connection Paths (Priority Order):
 * 1. Chassis Bay (hot-swap capability)
 * 2. Motherboard Direct (native SATA/M.2/U.2 ports)
 * 3. HBA Card (SAS/SATA/U.2 controllers)
 * 4. PCIe Adapter (M.2/U.2 to PCIe adapters)
 *
 * Design Principles:
 * - NO hardcoded interface/form-factor rules
 * - Component-order agnostic (storage can be added before/after chassis)
 * - JSON-driven compatibility checks
 * - Actionable error messages with alternatives
 */

require_once __DIR__ . '/../components/ComponentDataService.php';
require_once __DIR__ . '/../shared/DataExtractionUtilities.php';
require_once __DIR__ . '/UnifiedSlotTracker.php';

class StorageConnectionValidator {

    private $pdo;
    private $dataUtils;
    private $componentDataService;
    private $slotTracker;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->dataUtils = new DataExtractionUtilities();
        $this->componentDataService = ComponentDataService::getInstance();
        $this->slotTracker = new UnifiedSlotTracker($pdo);
    }

    /**
     * Main validation entry point
     * Returns: ['valid' => bool, 'connection_paths' => [], 'errors' => [], 'warnings' => [], 'info' => []]
     */
    public function validate($configUuid, $storageUuid, $existingComponents) {
        try {
            error_log("StorageConnectionValidator::validate START");
            error_log("Config: $configUuid, Storage: $storageUuid");
            error_log("Existing components structure: " . json_encode(array_keys($existingComponents)));

            $errors = [];
            $warnings = [];
            $info = [];
            $connectionPaths = [];

            // Get storage specifications from JSON
            error_log("Getting storage specs for UUID: $storageUuid");
            $storageSpecs = $this->getStorageSpecs($storageUuid);
            error_log("Storage specs loaded: " . ($storageSpecs ? "YES" : "NO"));
        if (!$storageSpecs) {
            return [
                'valid' => false,
                'connection_paths' => [],
                'errors' => [['type' => 'storage_not_found', 'message' => "Storage $storageUuid not found in JSON specifications"]],
                'warnings' => [],
                'info' => []
            ];
        }

        $storageInterface = $storageSpecs['interface'] ?? 'Unknown';
        $storageFormFactor = $storageSpecs['form_factor'] ?? 'Unknown';
        $storageSubtype = $storageSpecs['subtype'] ?? '';

        // PHASE 1: Form Factor Consistency Validation (2.5" and 3.5" only)
        // Skip for M.2/U.2 drives (Phase 2 handles those)
        $normalizedFormFactor = $this->normalizeFormFactor($storageFormFactor);
        if ($normalizedFormFactor === '2.5-inch' || $normalizedFormFactor === '3.5-inch') {
            $formFactorCheck = $this->validateFormFactorConsistency($storageFormFactor, $existingComponents);
            if (!$formFactorCheck['valid']) {
                $errors[] = $formFactorCheck['error'];
            } else if (!empty($formFactorCheck['info'])) {
                $info[] = $formFactorCheck['info'];
            }
        }

        // CHECK 1: Chassis Backplane Capability
        $chassisPath = $this->checkChassisBackplaneCapability($storageInterface, $storageFormFactor, $existingComponents, $storageSpecs);
        if ($chassisPath['available']) {
            $connectionPaths[] = $chassisPath;
        }

        // CHECK 2: Motherboard Direct Connection
        $motherboardPath = $this->checkMotherboardDirectConnection($storageInterface, $storageFormFactor, $storageSubtype, $existingComponents, $storageSpecs, $configUuid);
        if ($motherboardPath['available']) {
            $connectionPaths[] = $motherboardPath;
        }

        // CHECK 3: HBA Card Requirement/Availability
        // QUANTITY-FIX: Pass storageSpecs to include quantity in port capacity check
        $hbaPath = $this->checkHBACardRequirement($storageInterface, $existingComponents, $storageSpecs);
        if ($hbaPath['available']) {
            $connectionPaths[] = $hbaPath;
        } elseif ($hbaPath['mandatory']) {
            $errors[] = $hbaPath['error'];
        }

        // CHECK 4: PCIe Adapter Card Check
        $adapterPath = $this->checkPCIeAdapterCard($storageFormFactor, $storageSubtype, $existingComponents, $configUuid);
        if ($adapterPath['available']) {
            $connectionPaths[] = $adapterPath;
        }

        // Determine primary connection path (priority order)
        $primaryPath = $this->selectPrimaryConnectionPath($connectionPaths);

        // CHECK 5: Bay Availability (ONLY if chassis exists and using chassis path)
        if ($primaryPath && $primaryPath['type'] === 'chassis_bay' && $existingComponents['chassis']) {
            $bayCheck = $this->checkBayAvailability($storageFormFactor, $existingComponents, $storageSpecs);
            if (!$bayCheck['available']) {
                $errors[] = $bayCheck['error'];
            } else {
                // CHECK 10: Caddy Requirement Check
                if ($bayCheck['caddy_required']) {
                    $caddyCheck = $this->checkCaddyRequirement($storageFormFactor, $existingComponents, $bayCheck);
                    if (!$caddyCheck['available']) {
                        $warnings[] = $caddyCheck['warning'];
                    } else {
                        $info[] = ['message' => $caddyCheck['message']];
                    }
                }
            }
        }

        // CHECK 6: Port/Slot Availability
        if ($primaryPath) {
            $portCheck = $this->checkPortSlotAvailability($primaryPath, $existingComponents, $storageSpecs);
            if (!$portCheck['available']) {
                $errors[] = $portCheck['error'];
            } elseif (!empty($portCheck['warning'])) {
                $warnings[] = $portCheck['warning'];
            }
        }

        // CHECK 7: PCIe Lane Budget (ONLY if motherboard/CPU exists and using NVMe)
        if (strpos(strtolower($storageInterface), 'nvme') !== false || strpos(strtolower($storageInterface), 'pcie') !== false) {
            // Only check PCIe lanes if there's a motherboard or CPU in config
            if ($existingComponents['motherboard'] || !empty($existingComponents['cpu'])) {
                $laneCheck = $this->checkPCIeLaneBudget($storageSpecs, $existingComponents);
                if (!$laneCheck['sufficient']) {
                    $warnings[] = $laneCheck['warning'];
                }
            }

            // CHECK 8: PCIe Version Compatibility (ONLY if there's a connection path)
            if ($primaryPath) {
                $versionCheck = $this->checkPCIeVersionCompatibility($storageSpecs, $primaryPath, $existingComponents);
                if (!empty($versionCheck['warning'])) {
                    $warnings[] = $versionCheck['warning'];
                }
            }

            // CHECK 9: Bifurcation Requirement (for multi-slot adapters)
            if ($primaryPath && $primaryPath['type'] === 'pcie_adapter') {
                $bifurcationCheck = $this->checkBifurcationRequirement($primaryPath, $existingComponents);
                if (!empty($bifurcationCheck['warning'])) {
                    $warnings[] = $bifurcationCheck['warning'];
                } elseif (!empty($bifurcationCheck['error'])) {
                    $errors[] = $bifurcationCheck['error'];
                }
            }
        }

        // Final validation decision - ALLOW in any order with recommendations
        $protocol = $this->extractProtocol($storageInterface);

        if (empty($connectionPaths)) {
            // SAS storage REQUIRES HBA or SAS chassis - BLOCK if neither available
            if ($protocol === 'sas') {
                $errors[] = [
                    'type' => 'sas_requires_hba_or_chassis',
                    'message' => 'SAS storage requires SAS HBA card OR chassis with SAS backplane',
                    'resolution' => 'Add SAS HBA card (e.g., LSI 9400-16i) OR add chassis with SAS backplane before adding SAS storage'
                ];
            } else {
                // Non-SAS storage - ALLOW with recommendations
                $recommendations = $this->generateRecommendations($storageInterface, $storageFormFactor, $storageSubtype, $existingComponents);
                $warnings[] = [
                    'type' => 'no_connection_path_yet',
                    'message' => 'Storage added but not yet connected to any component',
                    'recommendation' => 'Add one of the following to connect this storage',
                    'options' => $recommendations
                ];
                $info[] = [
                    'type' => 'component_order_flexible',
                    'message' => 'You can add required components (chassis/motherboard/adapter) later to connect this storage'
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'connection_paths' => $connectionPaths,
            'primary_path' => $primaryPath,
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info
        ];
        } catch (Exception $e) {
            error_log("StorageConnectionValidator Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return [
                'valid' => false,
                'connection_paths' => [],
                'errors' => [['type' => 'validation_error', 'message' => 'Internal validation error: ' . $e->getMessage()]],
                'warnings' => [],
                'info' => []
            ];
        }
    }

    /**
     * CHECK 1: Chassis Backplane Capability Check
     * Reads: chassis.backplane.supports_* from JSON
     */
    private function checkChassisBackplaneCapability($storageInterface, $storageFormFactor, $existing, $storageSpecs) {
        // ONLY M.2 form factor drives bypass chassis - they use motherboard M.2 slots or PCIe adapters
        // 2.5" and 3.5" drives ALWAYS use chassis bays, regardless of interface (SATA/SAS/NVMe/U.3)
        // U.2/U.3 form factor (not 2.5") use motherboard U.2 ports
        $normalizedFormFactor = strtolower($storageFormFactor);

        // ONLY bypass chassis for M.2 form factor
        if (strpos($normalizedFormFactor, 'm.2') !== false) {
            return ['available' => false, 'reason' => 'm2_bypass_chassis'];
        }

        // U.2/U.3 as form_factor (not subtype) also bypass chassis - they use motherboard U.2 ports
        if (strpos($normalizedFormFactor, 'u.2') !== false || strpos($normalizedFormFactor, 'u.3') !== false) {
            return ['available' => false, 'reason' => 'u2_u3_bypass_chassis'];
        }

        if (!$existing['chassis'] || !isset($existing['chassis']['component_uuid'])) {
            return ['available' => false, 'reason' => 'no_chassis'];
        }

        $chassisSpecs = $this->getChassisSpecs($existing['chassis']['component_uuid']);
        if (!$chassisSpecs) {
            return ['available' => false, 'reason' => 'chassis_specs_not_found'];
        }

        $backplane = $chassisSpecs['backplane'] ?? [];
        $supportsNvme = $backplane['supports_nvme'] ?? false;
        $supportsSata = $backplane['supports_sata'] ?? false;
        $supportsSas = $backplane['supports_sas'] ?? false;

        // Protocol extraction from storage interface
        $protocol = $this->extractProtocol($storageInterface);

        $compatible = false;
        if ($protocol === 'nvme' && $supportsNvme) $compatible = true;
        if ($protocol === 'sata' && $supportsSata) $compatible = true;
        if ($protocol === 'sas' && $supportsSas) $compatible = true;

        if ($compatible) {
            $backplaneInterface = $backplane['interface'] ?? 'Unknown';

            // Generate clear compatibility message
            if ($protocol === 'sata' && strtoupper($backplaneInterface) !== 'SATA3') {
                $compatibilityNote = "$storageInterface drive compatible with $backplaneInterface backplane (backward compatible)";
            } elseif ($protocol === 'sas' && strtoupper($backplaneInterface) !== 'SAS3') {
                $compatibilityNote = "$storageInterface drive on $backplaneInterface backplane";
            } else {
                $compatibilityNote = "$storageInterface drive on $backplaneInterface backplane (native support)";
            }

            return [
                'available' => true,
                'type' => 'chassis_bay',
                'priority' => 1,
                'description' => "Storage connects via chassis backplane ($compatibilityNote)",
                'details' => [
                    'chassis_uuid' => $existing['chassis']['component_uuid'],
                    'backplane_model' => $backplane['model'] ?? 'Unknown',
                    'backplane_interface' => $backplaneInterface,
                    'storage_interface' => $storageInterface,
                    'compatibility_type' => $protocol === strtolower($backplaneInterface) ? 'native' : 'backward_compatible'
                ]
            ];
        }

        return ['available' => false, 'reason' => 'backplane_incompatible'];
    }

    /**
     * CHECK 2: Motherboard Direct Connection Check
     * Reads: motherboard.storage.sata.ports, motherboard.storage.nvme.m2_slots[], motherboard.storage.nvme.u2_slots
     * PHASE 2 ENHANCED: Now tracks M.2/U.2 slot usage with UnifiedSlotTracker
     */
    private function checkMotherboardDirectConnection($storageInterface, $storageFormFactor, $storageSubtype, $existing, $storageSpecs, $configUuid = null) {
        if (!$existing['motherboard'] || !isset($existing['motherboard']['component_uuid'])) {
            // PHASE 2: For M.2/U.2 storage, check if NVMe adapters exist
            $isM2 = (strpos(strtolower($storageFormFactor), 'm.2') !== false || strpos(strtolower($storageSubtype), 'm.2') !== false);
            $isU2 = (strpos(strtolower($storageFormFactor), 'u.2') !== false || strpos(strtolower($storageFormFactor), 'u.3') !== false ||
                    strpos(strtolower($storageSubtype), 'u.2') !== false || strpos(strtolower($storageSubtype), 'u.3') !== false);

            if ($isM2 || $isU2) {
                // Check for NVMe adapters that can provide slots
                $nvmeAdapters = $this->componentDataService->filterNvmeAdapters($existing['pciecard'] ?? []);
                if (!empty($nvmeAdapters)) {
                    // NVMe adapters exist - connection possible via adapter
                    return ['available' => false, 'reason' => 'no_motherboard_but_adapter_exists'];
                }
                // No adapters - allow storage but warn
                return ['available' => false, 'reason' => 'no_motherboard_m2_u2_warning'];
            }

            return ['available' => false, 'reason' => 'no_motherboard'];
        }

        $motherboardSpecs = $this->getMotherboardSpecs($existing['motherboard']['component_uuid']);
        if (!$motherboardSpecs) {
            return ['available' => false, 'reason' => 'motherboard_specs_not_found'];
        }

        $storage = $motherboardSpecs['storage'] ?? [];
        $protocol = $this->extractProtocol($storageInterface);

        // SATA Port Check
        if ($protocol === 'sata' && isset($storage['sata']['ports']) && $storage['sata']['ports'] > 0) {
            return [
                'available' => true,
                'type' => 'motherboard_sata',
                'priority' => 2,
                'description' => "Storage connects via motherboard SATA port",
                'details' => [
                    'motherboard_uuid' => $existing['motherboard']['component_uuid'],
                    'total_sata_ports' => $storage['sata']['ports'],
                    'controller' => $storage['sata']['sata_controller'] ?? 'Integrated'
                ]
            ];
        }

        // PHASE 2: M.2 Slot Check with usage tracking using UnifiedSlotTracker
        // ONLY check form_factor, NOT subtype - subtype indicates protocol, form_factor indicates physical connection
        // P3.1 FIX: Sum ALL M.2 slot types (NVMe + SATA), not just the first one
        if (strpos(strtolower($storageFormFactor), 'm.2') !== false) {
            $m2Slots = $storage['nvme']['m2_slots'] ?? [];
            // P3.1 FIX: Calculate total by summing all slot configurations
            $totalM2Slots = 0;
            if (!empty($m2Slots) && is_array($m2Slots)) {
                foreach ($m2Slots as $slotConfig) {
                    $totalM2Slots += $slotConfig['count'] ?? 0;
                }
            }

            // UNIFIED: Use UnifiedSlotTracker for M.2 counting if configUuid available
            $usedM2Slots = 0;
            if ($configUuid) {
                $usedM2Slots = $this->slotTracker->countUsedM2Slots($configUuid);
            } else {
                // Fallback to old method if configUuid not provided
                $usedM2Slots = $this->countUsedM2SlotsLegacy($existing);
            }

            if ($totalM2Slots > 0 && $usedM2Slots < $totalM2Slots) {
                // Motherboard has available M.2 slots
                return [
                    'available' => true,
                    'type' => 'motherboard_m2',
                    'priority' => 2,
                    'description' => "Storage connects via motherboard M.2 slot",
                    'details' => [
                        'motherboard_uuid' => $existing['motherboard']['component_uuid'],
                        'total_m2_slots' => $totalM2Slots,
                        'used_m2_slots' => $usedM2Slots,
                        'available_m2_slots' => $totalM2Slots - $usedM2Slots,
                        'supported_form_factors' => $m2Slots[0]['form_factors'] ?? [],
                        'pcie_generation' => $m2Slots[0]['pcie_generation'] ?? 4
                    ]
                ];
            } else if ($totalM2Slots > 0) {
                // Motherboard has M.2 slots but all are used
                return ['available' => false, 'reason' => 'm2_slots_exhausted', 'total_slots' => $totalM2Slots, 'used_slots' => $usedM2Slots];
            } else {
                // Motherboard has no M.2 slots
                return ['available' => false, 'reason' => 'no_m2_slots'];
            }
        }

        // PHASE 2: U.2 Slot Check with usage tracking
        // ONLY check if form_factor explicitly says "U.2" or "U.3"
        // Do NOT check subtype - "2.5-inch" drives with U.3 protocol use chassis bays, not motherboard U.2 ports!
        if (strpos(strtolower($storageFormFactor), 'u.2') !== false || strpos(strtolower($storageFormFactor), 'u.3') !== false) {
            $u2Slots = $storage['nvme']['u2_slots'] ?? [];
            $totalU2Slots = isset($u2Slots['count']) ? $u2Slots['count'] : 0;
            $usedU2Slots = $this->countUsedU2Slots($existing);

            if ($totalU2Slots > 0 && $usedU2Slots < $totalU2Slots) {
                // Motherboard has available U.2 slots
                return [
                    'available' => true,
                    'type' => 'motherboard_u2',
                    'priority' => 2,
                    'description' => "Storage connects via motherboard U.2 slot",
                    'details' => [
                        'motherboard_uuid' => $existing['motherboard']['component_uuid'],
                        'total_u2_slots' => $totalU2Slots,
                        'used_u2_slots' => $usedU2Slots,
                        'available_u2_slots' => $totalU2Slots - $usedU2Slots,
                        'connection' => $u2Slots['connection'] ?? 'Unknown'
                    ]
                ];
            } else if ($totalU2Slots > 0) {
                // Motherboard has U.2 slots but all are used
                return ['available' => false, 'reason' => 'u2_slots_exhausted', 'total_slots' => $totalU2Slots, 'used_slots' => $usedU2Slots];
            } else {
                // Motherboard has no U.2 slots
                return ['available' => false, 'reason' => 'no_u2_slots'];
            }
        }

        return ['available' => false, 'reason' => 'no_compatible_motherboard_port'];
    }

    /**
     * CHECK 3: HBA Card Requirement Check
     * QUANTITY-FIX: Now includes quantity parameter to prevent port over-subscription
     * Reads: HBA cards from hbacardinventory (now separate component type)
     *
     * @param string $storageInterface Storage interface (e.g., "SATA 6Gb/s", "SAS 12Gb/s")
     * @param array $existing Existing components in configuration
     * @param array $storageSpecs Storage specifications including quantity
     * @return array Availability result with HBA connection details
     */
    private function checkHBACardRequirement($storageInterface, $existing, $storageSpecs = []) {
        $protocol = $this->extractProtocol($storageInterface);

        // Extract quantity being added (default to 1)
        $quantityBeingAdded = $storageSpecs['quantity'] ?? 1;

        // SAS storage REQUIRES HBA card
        if ($protocol === 'sas') {
            // Search for SAS HBA in existing HBA cards
            if (!empty($existing['hbacard']) && is_array($existing['hbacard'])) {
                foreach ($existing['hbacard'] as $hba) {
                    $hbaSpecs = $this->componentDataService->findComponentByUuid('hbacard', $hba['component_uuid']);
                    if ($hbaSpecs) {
                        // Check if HBA supports SAS protocol
                        $hbaProtocol = strtolower($hbaSpecs['protocol'] ?? '');
                        if (strpos($hbaProtocol, 'sas') !== false) {
                            // QUANTITY-FIX: Check HBA port capacity INCLUDING quantity being added
                            $internalPorts = $hbaSpecs['internal_ports'] ?? 0;
                            $currentStorageCount = $this->countChassisConnectedStorage($existing);
                            $totalAfterAddition = $currentStorageCount + $quantityBeingAdded;

                            if ($internalPorts < $totalAfterAddition) {
                                return [
                                    'available' => false,
                                    'mandatory' => true,
                                    'error' => [
                                        'type' => 'hba_ports_exhausted',
                                        'message' => "HBA card ({$hbaSpecs['model']}) has $internalPorts internal ports. Currently using $currentStorageCount, trying to add $quantityBeingAdded (total would be $totalAfterAddition)",
                                        'resolution' => "Reduce quantity OR remove existing storage OR replace with HBA having more ports"
                                    ]
                                ];
                            }

                            return [
                                'available' => true,
                                'type' => 'hba_card',
                                'priority' => 3,
                                'description' => "Storage connects via HBA card ({$hbaSpecs['model']})",
                                'details' => [
                                    'hba_uuid' => $hba['component_uuid'],
                                    'hba_model' => $hbaSpecs['model'] ?? 'Unknown',
                                    'internal_ports' => $internalPorts,
                                    'ports_used' => $currentStorageCount,
                                    'ports_available' => $internalPorts - $currentStorageCount,
                                    'max_devices' => $hbaSpecs['max_devices'] ?? 0
                                ]
                            ];
                        }
                    }
                }
            }

            // SAS storage but no HBA found - MANDATORY error
            return [
                'available' => false,
                'mandatory' => true,
                'error' => [
                    'type' => 'hba_required',
                    'message' => "SAS storage requires SAS HBA card",
                    'resolution' => "Add SAS HBA card (e.g., LSI 9400-16i) before adding SAS storage"
                ]
            ];
        }

        // Non-SAS storage - HBA not required but can be used if available
        if (!empty($existing['hbacard']) && is_array($existing['hbacard'])) {
            foreach ($existing['hbacard'] as $hba) {
                $hbaSpecs = $this->componentDataService->findComponentByUuid('hbacard', $hba['component_uuid']);
                if ($hbaSpecs) {
                    // Check if HBA supports this storage protocol
                    $hbaProtocol = strtolower($hbaSpecs['protocol'] ?? '');
                    if ($protocol === 'sata' && (strpos($hbaProtocol, 'sata') !== false || strpos($hbaProtocol, 'sas') !== false)) {
                        // QUANTITY-FIX: Check HBA port capacity INCLUDING quantity being added
                        $internalPorts = $hbaSpecs['internal_ports'] ?? 0;
                        $currentStorageCount = $this->countChassisConnectedStorage($existing);
                        $totalAfterAddition = $currentStorageCount + $quantityBeingAdded;

                        if ($internalPorts < $totalAfterAddition) {
                            return [
                                'available' => false,
                                'mandatory' => false,
                                'error' => [
                                    'type' => 'hba_ports_exhausted',
                                    'message' => "HBA card has all ports occupied"
                                ]
                            ];
                        }

                        return [
                            'available' => true,
                            'type' => 'hba_card',
                            'priority' => 3,
                            'description' => "Storage can connect via HBA card (optional)",
                            'details' => [
                                'hba_uuid' => $hba['component_uuid'],
                                'hba_model' => $hbaSpecs['model'] ?? 'Unknown',
                                'internal_ports' => $internalPorts,
                                'ports_used' => $currentStorageCount,
                                'ports_available' => $internalPorts - $currentStorageCount
                            ]
                        ];
                    }
                }
            }
        }

        return ['available' => false, 'mandatory' => false];
    }

    /**
     * Count chassis-connected storage devices in existing configuration
     */
    private function countChassisConnectedStorage($existing) {
        if (empty($existing['storage']) || !is_array($existing['storage'])) {
            return 0;
        }

        $count = 0;
        foreach ($existing['storage'] as $storage) {
            $storageSpecs = $this->getStorageSpecs($storage['component_uuid']);
            if (!$storageSpecs) {
                continue;
            }

            $formFactor = $storageSpecs['form_factor'] ?? '';
            $interface = $storageSpecs['interface'] ?? '';

            // M.2 and U.2 don't connect via chassis backplane
            if (stripos($formFactor, 'm.2') !== false || stripos($formFactor, 'u.2') !== false) {
                continue;
            }

            // Count SATA and SAS drives that use chassis bays
            if (stripos($interface, 'sata') !== false || stripos($interface, 'sas') !== false) {
                $count += ($storage['quantity'] ?? 1);
            }
        }

        return $count;
    }

    /**
     * CHECK 4: PCIe Adapter Card Check
     * Reads: PCIe cards with component_subtype='NVMe Adaptor'
     * PHASE 2 ENHANCED: Now tracks M.2/U.2 slot usage on adapters using UnifiedSlotTracker
     */
    private function checkPCIeAdapterCard($storageFormFactor, $storageSubtype, $existing, $configUuid = null) {
        $nvmeAdapters = $this->componentDataService->filterNvmeAdapters($existing['pciecard'] ?? []);

        if (empty($nvmeAdapters)) {
            return ['available' => false, 'reason' => 'no_nvme_adapters'];
        }

        $isM2 = (strpos(strtolower($storageFormFactor), 'm.2') !== false || strpos(strtolower($storageSubtype), 'm.2') !== false);
        $isU2 = (strpos(strtolower($storageFormFactor), 'u.2') !== false || strpos(strtolower($storageFormFactor), 'u.3') !== false ||
                strpos(strtolower($storageSubtype), 'u.2') !== false || strpos(strtolower($storageSubtype), 'u.3') !== false);

        foreach ($nvmeAdapters as $adapter) {
            $cardSpecs = $adapter['specs'];
            $cardUuid = $adapter['uuid'];
            $quantity = $adapter['quantity'];

            // Check M.2 support using UnifiedSlotTracker
            if ($isM2 && isset($cardSpecs['m2_slots']) && $cardSpecs['m2_slots'] > 0) {
                // UNIFIED: Use UnifiedSlotTracker for M.2 counting if configUuid available
                if ($configUuid) {
                    $m2Availability = $this->slotTracker->getM2SlotAvailability($configUuid);
                    if ($m2Availability['success']) {
                        $totalMotherboardSlots = $m2Availability['motherboard_slots']['total'];
                        $usedMotherboardSlots = $m2Availability['motherboard_slots']['used'];
                        $availableAdapterSlots = $m2Availability['expansion_card_slots']['available'];
                    } else {
                        $availableAdapterSlots = 0;
                    }
                } else {
                    // Fallback to old method
                    $totalAdapterM2Slots = $this->countTotalM2SlotsLegacy($existing);
                    $motherboardM2Slots = 0;
                    if (!empty($existing['motherboard'])) {
                        $mbSpecs = $this->getMotherboardSpecs($existing['motherboard']['component_uuid']);
                        if ($mbSpecs) {
                            $m2Slots = $mbSpecs['storage']['nvme']['m2_slots'] ?? [];
                            $motherboardM2Slots = (!empty($m2Slots) && isset($m2Slots[0]['count'])) ? $m2Slots[0]['count'] : 0;
                        }
                    }
                    $adapterProvidedSlots = $totalAdapterM2Slots - $motherboardM2Slots;
                    $usedM2Slots = $this->countUsedM2SlotsLegacy($existing);
                    $availableAdapterSlots = max(0, $adapterProvidedSlots - max(0, $usedM2Slots - $motherboardM2Slots));
                }

                if ($availableAdapterSlots > 0) {
                    $supportedFormFactors = $cardSpecs['m2_form_factors'] ?? [];
                    return [
                        'available' => true,
                        'type' => 'pcie_adapter',
                        'priority' => 4,
                        'description' => "Storage connects via PCIe M.2 adapter card ({$cardSpecs['model']})",
                        'details' => [
                            'adapter_uuid' => $cardUuid,
                            'adapter_model' => $cardSpecs['model'] ?? 'Unknown',
                            'm2_slots' => $cardSpecs['m2_slots'],
                            'available_slots' => $availableAdapterSlots,
                            'supported_form_factors' => $supportedFormFactors,
                            'requires_bifurcation' => ($cardSpecs['m2_slots'] ?? 0) > 1
                        ]
                    ];
                }
            }

            // Check U.2 support
            if ($isU2 && isset($cardSpecs['u2_slots']) && $cardSpecs['u2_slots'] > 0) {
                // Similar logic for U.2
                $totalAdapterU2Slots = $this->countTotalU2Slots($existing);
                $motherboardU2Slots = 0;
                if (!empty($existing['motherboard'])) {
                    $mbSpecs = $this->getMotherboardSpecs($existing['motherboard']['component_uuid']);
                    if ($mbSpecs) {
                        $u2Slots = $mbSpecs['storage']['nvme']['u2_slots'] ?? [];
                        $motherboardU2Slots = isset($u2Slots['count']) ? $u2Slots['count'] : 0;
                    }
                }
                $adapterProvidedSlots = $totalAdapterU2Slots - $motherboardU2Slots;
                $usedU2Slots = $this->countUsedU2Slots($existing);
                $availableAdapterSlots = max(0, $adapterProvidedSlots - max(0, $usedU2Slots - $motherboardU2Slots));

                if ($availableAdapterSlots > 0) {
                    return [
                        'available' => true,
                        'type' => 'pcie_adapter',
                        'priority' => 4,
                        'description' => "Storage connects via PCIe U.2 adapter card ({$cardSpecs['model']})",
                        'details' => [
                            'adapter_uuid' => $cardUuid,
                            'adapter_model' => $cardSpecs['model'] ?? 'Unknown',
                            'u2_slots' => $cardSpecs['u2_slots'],
                            'available_slots' => $availableAdapterSlots
                        ]
                    ];
                }
            }
        }

        // Adapters exist but all slots are full
        return ['available' => false, 'reason' => 'adapter_slots_exhausted'];
    }

    /**
     * CHECK 5: Bay Availability Check with Form Factor Lock Respect
     * P2.4 FIX: Respect form factor lock when calculating available bay count
     */
    private function checkBayAvailability($storageFormFactor, $existing, $storageSpecs) {
        if (!$existing['chassis'] || !isset($existing['chassis']['component_uuid'])) {
            return ['available' => false, 'error' => ['type' => 'no_chassis', 'message' => 'No chassis in configuration']];
        }

        $chassisSpecs = $this->getChassisSpecs($existing['chassis']['component_uuid']);
        if (!$chassisSpecs) {
            return ['available' => false, 'error' => ['type' => 'chassis_specs_not_found', 'message' => 'Chassis specifications not found']];
        }

        $driveBays = $chassisSpecs['drive_bays'] ?? [];
        $totalBays = $driveBays['total_bays'] ?? 0;
        $bayConfiguration = $driveBays['bay_configuration'] ?? [];

        // P2.4 FIX: Detect form factor lock from existing storage
        $existingFormFactorLock = $this->detectFormFactorLock($existing);
        $normalizedStorageFF = $this->normalizeFormFactor($storageFormFactor);

        // If form factor lock exists, validate new storage matches it
        if ($existingFormFactorLock && $existingFormFactorLock !== $normalizedStorageFF) {
            $lockedFF = $this->denormalizeFormFactor($existingFormFactorLock);
            return [
                'available' => false,
                'error' => [
                    'type' => 'form_factor_lock_violation',
                    'message' => "Chassis bays are locked to $lockedFF form factor. Cannot add $storageFormFactor",
                    'locked_form_factor' => $existingFormFactorLock,
                    'requested_form_factor' => $normalizedStorageFF,
                    'resolution' => "Add storage with $lockedFF form factor OR remove all existing storage to unlock"
                ]
            ];
        }

        // Count used bays (respect form factor lock)
        $usedBays = (!empty($existing['storage']) && is_array($existing['storage'])) ? count($existing['storage']) : 0;

        if ($usedBays >= $totalBays) {
            return [
                'available' => false,
                'error' => [
                    'type' => 'bay_limit_exceeded',
                    'message' => "Chassis has $totalBays drive bays, all occupied ($usedBays used)",
                    'resolution' => "Remove existing storage OR choose chassis with more bays"
                ]
            ];
        }

        // Form factor compatibility check
        $caddyRequired = false;
        $bayTypeMatch = false;

        foreach ($bayConfiguration as $bayConfig) {
            $bayType = $bayConfig['bay_type'] ?? '';
            $normalizedBayType = $this->normalizeFormFactor($bayType);

            // Direct match
            if ($normalizedStorageFF === $normalizedBayType) {
                $bayTypeMatch = true;
                break;
            }

            // 2.5" storage in 3.5" bay requires caddy
            if ($normalizedStorageFF === '2.5-inch' && $normalizedBayType === '3.5-inch') {
                $bayTypeMatch = true;
                $caddyRequired = true;
                break;
            }
        }

        if (!$bayTypeMatch) {
            return [
                'available' => false,
                'error' => [
                    'type' => 'form_factor_incompatible',
                    'message' => "Storage form factor $storageFormFactor not compatible with chassis bay types",
                    'resolution' => "Choose compatible storage OR replace chassis"
                ]
            ];
        }

        return [
            'available' => true,
            'caddy_required' => $caddyRequired,
            'available_bays' => $totalBays - $usedBays,
            'form_factor_lock' => $existingFormFactorLock,
            'form_factor_enforced' => (bool) $existingFormFactorLock
        ];
    }

    /**
     * CHECK 6: Port/Slot Availability Check
     */
    private function checkPortSlotAvailability($primaryPath, $existing, $storageSpecs) {
        switch ($primaryPath['type']) {
            case 'motherboard_sata':
                $totalPorts = $primaryPath['details']['total_sata_ports'];
                $usedPorts = 0;
                if (!empty($existing['storage']) && is_array($existing['storage'])) {
                    foreach ($existing['storage'] as $storage) {
                        $specs = $this->getStorageSpecs($storage['component_uuid']);
                        if ($specs && $this->extractProtocol($specs['interface'] ?? '') === 'sata') {
                            $usedPorts++;
                        }
                    }
                }
                if ($usedPorts >= $totalPorts) {
                    return [
                        'available' => false,
                        'error' => [
                            'type' => 'sata_ports_exhausted',
                            'message' => "Motherboard SATA ports exhausted ($totalPorts ports, $usedPorts used)",
                            'resolution' => "Add SATA HBA card OR use NVMe storage"
                        ]
                    ];
                }
                return ['available' => true];

            case 'motherboard_m2':
                $totalSlots = $primaryPath['details']['total_m2_slots'];
                $usedSlots = 0;
                if (!empty($existing['storage']) && is_array($existing['storage'])) {
                    foreach ($existing['storage'] as $storage) {
                        $specs = $this->getStorageSpecs($storage['component_uuid']);
                        if ($specs && (strpos(strtolower($specs['form_factor'] ?? ''), 'm.2') !== false)) {
                            $usedSlots++;
                        }
                    }
                }
                if ($usedSlots >= $totalSlots) {
                    return [
                        'available' => false,
                        'error' => [
                            'type' => 'm2_slots_exhausted',
                            'message' => "Motherboard M.2 slots exhausted ($totalSlots slots, $usedSlots used)",
                            'resolution' => "Add M.2 to PCIe adapter card"
                        ]
                    ];
                }
                return ['available' => true];

            case 'hba_card':
                $maxDevices = $primaryPath['details']['max_devices'] ?? 0;
                $usedDevices = 0;
                if (!empty($existing['storage']) && is_array($existing['storage'])) {
                    foreach ($existing['storage'] as $storage) {
                        // Count storage devices using this HBA
                        $usedDevices++;
                    }
                }
                if ($usedDevices >= $maxDevices) {
                    return [
                        'available' => false,
                        'error' => [
                            'type' => 'hba_ports_exhausted',
                            'message' => "HBA card device limit reached ($maxDevices max)",
                            'resolution' => "Add another HBA card"
                        ]
                    ];
                }
                return ['available' => true];

            default:
                return ['available' => true];
        }
    }

    /**
     * CHECK 7: PCIe Lane Budget Check
     *
     * NOTE: M.2 storage on dedicated motherboard M.2 slots do NOT consume
     * PCIe expansion slot lanes. They use dedicated chipset lanes.
     * This check only applies to storage on PCIe expansion slots.
     */
    private function checkPCIeLaneBudget($storageSpecs, $existing) {
        // Check if this storage connects via M.2 slot
        $storageInterface = strtolower($storageSpecs['interface'] ?? '');
        $storageFormFactor = strtolower($storageSpecs['form_factor'] ?? '');

        // M.2 drives on dedicated M.2 slots don't consume expansion slot lanes
        $isM2Drive = (strpos($storageFormFactor, 'm.2') !== false ||
                      strpos($storageFormFactor, 'm2') !== false);

        if ($isM2Drive) {
            // M.2 slots have dedicated chipset lanes - skip expansion lane check
            return ['sufficient' => true, 'uses_dedicated_m2_slot' => true];
        }

        // For non-M.2 storage (U.2, PCIe add-in cards, etc.)
        // Get CPU and motherboard PCIe expansion lanes
        $totalLanes = 0;
        if (!empty($existing['cpu']) && is_array($existing['cpu']) && isset($existing['cpu'][0]['component_uuid'])) {
            $cpuSpecs = $this->dataUtils->getCPUByUUID($existing['cpu'][0]['component_uuid']);
            $totalLanes += $cpuSpecs['pcie_lanes'] ?? 0;
        }
        if ($existing['motherboard'] && isset($existing['motherboard']['component_uuid'])) {
            $mbSpecs = $this->getMotherboardSpecs($existing['motherboard']['component_uuid']);
            $totalLanes += $mbSpecs['chipset_pcie_lanes'] ?? 0;
        }

        // Calculate used lanes (PCIe cards + NICs + non-M.2 storage)
        $usedLanes = 0;
        if (!empty($existing['pciecard']) && is_array($existing['pciecard'])) {
            foreach ($existing['pciecard'] as $card) {
                $cardSpecs = $this->getPCIeCardSpecs($card['component_uuid']);
                $interface = $cardSpecs['interface'] ?? '';
                preg_match('/x(\d+)/', $interface, $matches);
                $usedLanes += (int)($matches[1] ?? 4);
            }
        }

        // Only count storage that uses PCIe expansion slots (not M.2)
        if (!empty($existing['storage']) && is_array($existing['storage'])) {
            foreach ($existing['storage'] as $storage) {
                $specs = $this->getStorageSpecs($storage['component_uuid']);
                if ($specs) {
                    $existingFormFactor = strtolower($specs['form_factor'] ?? '');
                    $isExistingM2 = (strpos($existingFormFactor, 'm.2') !== false ||
                                    strpos($existingFormFactor, 'm2') !== false);

                    // Only count if NOT M.2 and is NVMe
                    if (!$isExistingM2 && strpos(strtolower($specs['interface'] ?? ''), 'nvme') !== false) {
                        $usedLanes += 4; // U.2 or PCIe add-in NVMe uses x4
                    }
                }
            }
        }

        $requiredLanes = 4; // This storage device (U.2 or PCIe add-in)
        $availableLanes = $totalLanes - $usedLanes;

        if ($availableLanes < $requiredLanes) {
            return [
                'sufficient' => false,
                'warning' => [
                    'type' => 'pcie_lanes_insufficient',
                    'message' => "Insufficient PCIe expansion lanes (need $requiredLanes, available $availableLanes/$totalLanes)",
                    'recommendation' => "Remove other PCIe devices OR upgrade CPU/motherboard"
                ]
            ];
        }

        return ['sufficient' => true, 'available_lanes' => $availableLanes];
    }

    /**
     * CHECK 8: PCIe Version Compatibility Check
     */
    private function checkPCIeVersionCompatibility($storageSpecs, $primaryPath, $existing) {
        // Extract PCIe version from storage interface
        $storageInterface = $storageSpecs['interface'] ?? '';
        preg_match('/PCIe\s*(\d+\.\d+)/', $storageInterface, $matches);
        $storageVersion = (float)($matches[1] ?? 3.0);

        $slotVersion = 3.0; // Default
        if ($primaryPath && $primaryPath['type'] === 'motherboard_m2') {
            $slotVersion = (float)($primaryPath['details']['pcie_generation'] ?? 3.0);
        }

        if ($storageVersion > $slotVersion) {
            $degradation = (($storageVersion - $slotVersion) / $storageVersion) * 100;
            return [
                'warning' => [
                    'type' => 'pcie_version_mismatch',
                    'message' => "PCIe version mismatch: Storage PCIe $storageVersion on slot PCIe $slotVersion",
                    'impact' => sprintf("%.0f%% bandwidth reduction", $degradation),
                    'recommendation' => "Use PCIe $storageVersion slot for full performance"
                ]
            ];
        }

        return [];
    }

    /**
     * CHECK 9: Bifurcation Requirement Check
     */
    private function checkBifurcationRequirement($primaryPath, $existing) {
        if (!isset($primaryPath['details']['requires_bifurcation']) || !$primaryPath['details']['requires_bifurcation']) {
            return [];
        }

        if (!$existing['motherboard'] || !isset($existing['motherboard']['component_uuid'])) {
            return [];
        }

        $mbSpecs = $this->getMotherboardSpecs($existing['motherboard']['component_uuid']);
        $bifurcationSupport = false;

        // Check if motherboard has PCIe bifurcation support
        if (isset($mbSpecs['expansion_slots']['pcie_slots'])) {
            foreach ($mbSpecs['expansion_slots']['pcie_slots'] as $slot) {
                if (isset($slot['bifurcation_support']) && $slot['bifurcation_support']) {
                    $bifurcationSupport = true;
                    break;
                }
            }
        }

        if (!$bifurcationSupport) {
            return [
                'error' => [
                    'type' => 'bifurcation_not_supported',
                    'message' => "Multi-slot M.2 adapter requires PCIe bifurcation (motherboard unsupported)",
                    'resolution' => "Replace motherboard with bifurcation support OR use single-slot M.2 adapter"
                ]
            ];
        }

        return [
            'warning' => [
                'type' => 'bifurcation_required',
                'message' => "Requires BIOS bifurcation configuration for multi-slot M.2 adapter",
                'recommendation' => "Enable PCIe bifurcation in BIOS settings"
            ]
        ];
    }

    /**
     * CHECK 10: Caddy Requirement Check
     */
    private function checkCaddyRequirement($storageFormFactor, $existing, $bayCheck) {
        // Check if caddy exists in configuration
        if (!empty($existing['caddy']) && is_array($existing['caddy'])) {
            foreach ($existing['caddy'] as $caddy) {
                $caddySpecs = $this->getCaddySpecs($caddy['component_uuid']);
                if ($caddySpecs) {
                    // Check if caddy is compatible (2.5" to 3.5" adapter)
                    return [
                        'available' => true,
                        'message' => "Using 2.5-inch caddy for 3.5-inch bay installation"
                    ];
                }
            }
        }

        return [
            'available' => false,
            'warning' => [
                'type' => 'caddy_recommended',
                'message' => "2.5-inch storage in 3.5-inch bay requires caddy adapter",
                'recommendation' => "Add 2.5-inch to 3.5-inch caddy for proper installation"
            ]
        ];
    }

    /**
     * Select primary connection path based on priority
     */
    private function selectPrimaryConnectionPath($connectionPaths) {
        if (empty($connectionPaths)) {
            return null;
        }

        usort($connectionPaths, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        return $connectionPaths[0];
    }

    /**
     * Generate actionable recommendations for connecting storage
     * Returns array of recommendation objects with priority
     */
    private function generateRecommendations($storageInterface, $storageFormFactor, $storageSubtype, $existing) {
        $protocol = $this->extractProtocol($storageInterface);
        $recommendations = [];

        if ($protocol === 'sas') {
            // SAS requires HBA or SAS chassis
            if (!$existing['chassis']) {
                $recommendations[] = [
                    'priority' => 1,
                    'component' => 'Chassis with SAS backplane',
                    'reason' => 'Provides hot-swap SAS storage bays',
                    'example' => 'Supermicro SC846 with SAS3 backplane'
                ];
            }
            $recommendations[] = [
                'priority' => 2,
                'component' => 'SAS HBA Card',
                'reason' => 'Required controller for SAS storage',
                'example' => 'LSI 9400-16i or 9400-8i'
            ];
        } elseif ($protocol === 'sata') {
            // SATA can use chassis, motherboard, or SATA HBA
            if (!$existing['chassis']) {
                $recommendations[] = [
                    'priority' => 1,
                    'component' => 'Chassis with SATA backplane',
                    'reason' => 'Provides hot-swap SATA storage bays',
                    'example' => 'Supermicro chassis with SATA backplane'
                ];
            }
            if (!$existing['motherboard']) {
                $recommendations[] = [
                    'priority' => 2,
                    'component' => 'Motherboard with SATA ports',
                    'reason' => 'Direct SATA connection to motherboard',
                    'example' => 'Motherboard with 8+ SATA ports'
                ];
            }
            $recommendations[] = [
                'priority' => 3,
                'component' => 'SATA HBA Card',
                'reason' => 'Expands SATA port capacity',
                'example' => 'SATA HBA controller'
            ];
        } elseif ($protocol === 'nvme') {
            // NVMe options depend on form factor
            if (strpos(strtolower($storageFormFactor), 'm.2') !== false || strpos(strtolower($storageSubtype), 'm.2') !== false) {
                // M.2 NVMe
                if (!$existing['motherboard']) {
                    $recommendations[] = [
                        'priority' => 1,
                        'component' => 'Motherboard with M.2 slots',
                        'reason' => 'Direct M.2 NVMe connection',
                        'example' => 'Motherboard with 4x M.2 PCIe slots'
                    ];
                }
                $recommendations[] = [
                    'priority' => 2,
                    'component' => 'M.2 to PCIe Adapter Card',
                    'reason' => 'Convert M.2 drives to PCIe slot',
                    'example' => 'Supermicro AOM-SNG-4M2P (Quad M.2 adapter)'
                ];
                if (!$existing['chassis']) {
                    $recommendations[] = [
                        'priority' => 3,
                        'component' => 'Chassis with NVMe backplane',
                        'reason' => 'Hot-swap NVMe storage bays',
                        'example' => 'Chassis with U.2/NVMe backplane'
                    ];
                }
            } else {
                // U.2/U.3 NVMe
                if (!$existing['chassis']) {
                    $recommendations[] = [
                        'priority' => 1,
                        'component' => 'Chassis with NVMe/U.2 backplane',
                        'reason' => 'Hot-swap U.2/U.3 NVMe bays',
                        'example' => 'Chassis with U.2 backplane'
                    ];
                }
                if (!$existing['motherboard']) {
                    $recommendations[] = [
                        'priority' => 2,
                        'component' => 'Motherboard with U.2 ports',
                        'reason' => 'Direct U.2 connection',
                        'example' => 'Motherboard with U.2 connectors'
                    ];
                }
                $recommendations[] = [
                    'priority' => 3,
                    'component' => 'U.2 to PCIe Adapter Card',
                    'reason' => 'Convert U.2 drives to PCIe slot',
                    'example' => 'U.2 to PCIe adapter'
                ];
            }
        }

        // Sort by priority
        usort($recommendations, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        return $recommendations;
    }

    /**
     * Extract protocol from storage interface string
     */
    private function extractProtocol($interface) {
        $interface = strtolower($interface);
        if (strpos($interface, 'sas') !== false) return 'sas';
        if (strpos($interface, 'sata') !== false) return 'sata';
        if (strpos($interface, 'nvme') !== false || strpos($interface, 'pcie') !== false) return 'nvme';
        return 'unknown';
    }

    /**
     * Normalize form factor for consistent comparison
     */
    private function normalizeFormFactor($formFactor) {
        return strtolower(str_replace(['_', ' '], '-', $formFactor));
    }

    /**
     * Get storage specifications from JSON
     */
    private function getStorageSpecs($storageUuid) {
        return $this->dataUtils->getStorageByUUID($storageUuid);
    }

    /**
     * Get chassis specifications from JSON
     */
    private function getChassisSpecs($chassisUuid) {
        return $this->dataUtils->getChassisSpecifications($chassisUuid);
    }

    /**
     * Get motherboard specifications from JSON
     */
    private function getMotherboardSpecs($motherboardUuid) {
        return $this->dataUtils->getMotherboardByUUID($motherboardUuid);
    }

    /**
     * Get PCIe card specifications from JSON
     */
    private function getPCIeCardSpecs($cardUuid) {
        return $this->dataUtils->getPCIeCardByUUID($cardUuid);
    }

    /**
     * Get caddy specifications from database
     */
    private function getCaddySpecs($caddyUuid) {
        $stmt = $this->pdo->prepare("SELECT * FROM caddyinventory WHERE UUID = ?");
        $stmt->execute([$caddyUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * PHASE 1: Get form factor lock from existing configuration
     * Returns the "locked" form factor (2.5" or 3.5") or null if no lock
     *
     * Priority:
     * 1. Chassis bay configuration (highest priority)
     * 2. Existing caddy sizes
     * 3. Existing storage sizes
     *
     * @param array $existingComponents
     * @return array ['locked' => bool, 'size' => '2.5-inch'|'3.5-inch'|null, 'reason' => string]
     */
    private function getFormFactorLock($existingComponents) {
        // Priority 1: Chassis bay size (if chassis exists)
        if (!empty($existingComponents['chassis']) && isset($existingComponents['chassis']['component_uuid'])) {
            $chassisSpecs = $this->getChassisSpecs($existingComponents['chassis']['component_uuid']);
            if ($chassisSpecs) {
                $bayConfig = $chassisSpecs['drive_bays']['bay_configuration'] ?? [];
                foreach ($bayConfig as $bay) {
                    $bayType = $bay['bay_type'] ?? '';
                    $normalized = $this->normalizeFormFactor($bayType);
                    if ($normalized === '2.5-inch' || $normalized === '3.5-inch') {
                        return [
                            'locked' => true,
                            'size' => $normalized,
                            'reason' => "chassis_bay_configuration"
                        ];
                    }
                }
            }
        }

        // Priority 2: Existing caddy sizes
        if (!empty($existingComponents['caddy']) && is_array($existingComponents['caddy'])) {
            foreach ($existingComponents['caddy'] as $caddy) {
                $caddySpecs = $this->componentDataService->getCaddyByUuid($caddy['component_uuid']);
                if ($caddySpecs) {
                    $caddySize = $caddySpecs['compatibility']['size'] ?? $caddySpecs['type'] ?? '';
                    $normalized = $this->normalizeFormFactor($caddySize);
                    if ($normalized === '2.5-inch' || $normalized === '3.5-inch') {
                        return [
                            'locked' => true,
                            'size' => $normalized,
                            'reason' => "existing_caddy"
                        ];
                    }
                }
            }
        }

        // Priority 3: Existing storage sizes
        if (!empty($existingComponents['storage']) && is_array($existingComponents['storage'])) {
            foreach ($existingComponents['storage'] as $storage) {
                $storageSpecs = $this->getStorageSpecs($storage['component_uuid']);
                if ($storageSpecs) {
                    $storageFF = $storageSpecs['form_factor'] ?? '';
                    $normalized = $this->normalizeFormFactor($storageFF);
                    if ($normalized === '2.5-inch' || $normalized === '3.5-inch') {
                        return [
                            'locked' => true,
                            'size' => $normalized,
                            'reason' => "existing_storage"
                        ];
                    }
                }
            }
        }

        // No lock exists
        return [
            'locked' => false,
            'size' => null,
            'reason' => "no_lock"
        ];
    }

    /**
     * PHASE 1: Validate form factor consistency for 2.5" and 3.5" storage
     *
     * @param string $incomingFormFactor Form factor of storage being added
     * @param array $existingComponents Existing configuration
     * @return array ['valid' => bool, 'error' => array|null, 'info' => array|null]
     */
    private function validateFormFactorConsistency($incomingFormFactor, $existingComponents) {
        $normalized = $this->normalizeFormFactor($incomingFormFactor);

        // Only validate 2.5" and 3.5" drives
        if ($normalized !== '2.5-inch' && $normalized !== '3.5-inch') {
            return ['valid' => true, 'error' => null, 'info' => null];
        }

        $lock = $this->getFormFactorLock($existingComponents);

        if (!$lock['locked']) {
            // No lock - this storage sets the lock
            return [
                'valid' => true,
                'error' => null,
                'info' => [
                    'type' => 'form_factor_lock_set',
                    'message' => "Form factor locked to $normalized - future storage must match"
                ]
            ];
        }

        // Lock exists - check if incoming storage matches
        if ($lock['size'] === $normalized) {
            return ['valid' => true, 'error' => null, 'info' => null];
        }

        // Mismatch - block with specific error
        $reasonText = $this->getFormFactorLockReasonText($lock['reason']);
        return [
            'valid' => false,
            'error' => [
                'type' => 'form_factor_mismatch',
                'message' => "Form factor locked to {$lock['size']} by $reasonText - cannot add $normalized storage",
                'locked_size' => $lock['size'],
                'incoming_size' => $normalized,
                'locked_by' => $lock['reason'],
                'resolution' => $this->getFormFactorResolution($lock['reason'], $lock['size'], $normalized)
            ],
            'info' => null
        ];
    }

    /**
     * Get human-readable text for form factor lock reason
     */
    private function getFormFactorLockReasonText($reason) {
        switch ($reason) {
            case 'chassis_bay_configuration':
                return 'chassis bay configuration';
            case 'existing_caddy':
                return 'existing caddy';
            case 'existing_storage':
                return 'existing storage';
            default:
                return 'configuration';
        }
    }

    /**
     * Get resolution suggestion for form factor mismatch
     */
    private function getFormFactorResolution($reason, $lockedSize, $incomingSize) {
        switch ($reason) {
            case 'chassis_bay_configuration':
                return "Replace chassis with $incomingSize bay configuration OR select $lockedSize storage";
            case 'existing_caddy':
                return "Remove $lockedSize caddy OR select $lockedSize storage";
            case 'existing_storage':
                return "Remove existing $lockedSize storage OR select $lockedSize storage";
            default:
                return "Select storage matching locked size: $lockedSize";
        }
    }

    /**
     * PHASE 1: Validate chassis against existing storage/caddy configuration
     * Called when chassis is added AFTER storage/caddies
     *
     * @param string $chassisUuid Chassis being added
     * @param array $existingComponents Existing configuration
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateChassisAgainstExistingConfig($chassisUuid, $existingComponents) {
        $errors = [];

        $chassisSpecs = $this->getChassisSpecs($chassisUuid);
        if (!$chassisSpecs) {
            return ['valid' => true, 'errors' => []]; // Can't validate without specs
        }

        // Get chassis bay size
        $bayConfig = $chassisSpecs['drive_bays']['bay_configuration'] ?? [];
        $chassisBaySize = null;
        foreach ($bayConfig as $bay) {
            $bayType = $bay['bay_type'] ?? '';
            $normalized = $this->normalizeFormFactor($bayType);
            if ($normalized === '2.5-inch' || $normalized === '3.5-inch') {
                $chassisBaySize = $normalized;
                break;
            }
        }

        if (!$chassisBaySize) {
            return ['valid' => true, 'errors' => []]; // Chassis doesn't have standard bays
        }

        // Check existing storage
        if (!empty($existingComponents['storage']) && is_array($existingComponents['storage'])) {
            foreach ($existingComponents['storage'] as $storage) {
                $storageSpecs = $this->getStorageSpecs($storage['component_uuid']);
                if ($storageSpecs) {
                    $storageFF = $storageSpecs['form_factor'] ?? '';
                    $normalized = $this->normalizeFormFactor($storageFF);
                    if (($normalized === '2.5-inch' || $normalized === '3.5-inch') && $normalized !== $chassisBaySize) {
                        $errors[] = [
                            'type' => 'chassis_storage_mismatch',
                            'message' => "Cannot add chassis with $chassisBaySize bays - configuration has $normalized storage",
                            'chassis_bay_size' => $chassisBaySize,
                            'existing_storage_size' => $normalized,
                            'resolution' => "Remove $normalized storage OR select chassis with $normalized bays"
                        ];
                    }
                }
            }
        }

        // Check existing caddies
        if (!empty($existingComponents['caddy']) && is_array($existingComponents['caddy'])) {
            foreach ($existingComponents['caddy'] as $caddy) {
                $caddySpecs = $this->componentDataService->getCaddyByUuid($caddy['component_uuid']);
                if ($caddySpecs) {
                    $caddySize = $caddySpecs['compatibility']['size'] ?? $caddySpecs['type'] ?? '';
                    $normalized = $this->normalizeFormFactor($caddySize);
                    if (($normalized === '2.5-inch' || $normalized === '3.5-inch') && $normalized !== $chassisBaySize) {
                        $errors[] = [
                            'type' => 'chassis_caddy_mismatch',
                            'message' => "Cannot add chassis with $chassisBaySize bays - configuration has $normalized caddy",
                            'chassis_bay_size' => $chassisBaySize,
                            'existing_caddy_size' => $normalized,
                            'resolution' => "Remove $normalized caddy OR select chassis with $normalized bays"
                        ];
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate caddy form factor against existing chassis bay configuration
     * Called when adding a caddy to ensure it matches chassis bay sizes
     * STRICT MATCHING: Caddy size must exactly match chassis bay type
     *
     * @param string $caddyUuid The UUID of the caddy being added
     * @param array $existingComponents Existing components in the configuration
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateCaddyAgainstExistingConfig($caddyUuid, $existingComponents) {
        $errors = [];

        // Get caddy specifications
        $caddySpecs = $this->componentDataService->getCaddyByUuid($caddyUuid);
        if (!$caddySpecs) {
            return [
                'valid' => false,
                'errors' => [[
                    'type' => 'caddy_not_found',
                    'message' => 'Caddy specifications not found in JSON',
                    'caddy_uuid' => $caddyUuid
                ]]
            ];
        }

        // Extract caddy size from compatibility.size or type field
        $caddySize = $caddySpecs['compatibility']['size'] ?? $caddySpecs['type'] ?? '';
        $normalizedCaddySize = $this->normalizeFormFactor($caddySize);

        // If no chassis in config, allow caddy (will be validated when chassis is added)
        if (empty($existingComponents['chassis']) || !is_array($existingComponents['chassis'])) {
            return ['valid' => true, 'errors' => []];
        }

        // Validate against each chassis in the configuration
        foreach ($existingComponents['chassis'] as $chassis) {
            $chassisUuid = $chassis['component_uuid'] ?? null;
            if (!$chassisUuid) continue;

            $chassisSpecs = $this->componentDataService->getChassisSpecifications($chassisUuid);
            if (!$chassisSpecs) continue;

            // Extract chassis bay configuration
            $bayConfig = $chassisSpecs['drive_bays']['bay_configuration'] ?? [];
            $hasMatchingBay = false;
            $availableBaySizes = [];

            foreach ($bayConfig as $bay) {
                $bayType = $bay['bay_type'] ?? '';
                $normalizedBayType = $this->normalizeFormFactor($bayType);
                $availableBaySizes[] = $normalizedBayType;

                // STRICT matching: caddy size must exactly match bay type
                if ($normalizedCaddySize === $normalizedBayType) {
                    $hasMatchingBay = true;
                    break;
                }
            }

            // Generate error if caddy size doesn't match any available bay type
            if (!$hasMatchingBay && ($normalizedCaddySize === '2.5-inch' || $normalizedCaddySize === '3.5-inch')) {
                $errors[] = [
                    'type' => 'caddy_chassis_mismatch',
                    'message' => "Cannot add $normalizedCaddySize caddy - chassis only has " . implode(', ', array_unique($availableBaySizes)) . " bays",
                    'caddy_size' => $normalizedCaddySize,
                    'chassis_uuid' => $chassisUuid,
                    'available_bay_sizes' => array_unique($availableBaySizes),
                    'resolution' => "Use a caddy matching chassis bay size OR remove current chassis and add one with $normalizedCaddySize bays"
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * P2.4: Detect form factor lock from existing storage
     * If all existing storage has the same form factor, that form factor is "locked"
     * and new storage must match it
     *
     * @param array $existingComponents Existing storage components
     * @return string|null Locked form factor (normalized) or null if no lock
     */
    private function detectFormFactorLock($existingComponents) {
        if (empty($existingComponents['storage']) || !is_array($existingComponents['storage'])) {
            return null; // No storage yet, no lock
        }

        $formFactors = [];
        foreach ($existingComponents['storage'] as $storage) {
            $specs = $this->getStorageSpecs($storage['component_uuid']);
            if ($specs && isset($specs['form_factor'])) {
                $normalized = $this->normalizeFormFactor($specs['form_factor']);
                $formFactors[$normalized] = true;
            }
        }

        // If all storage shares exactly one form factor, that's the lock
        if (count($formFactors) === 1) {
            return array_key_first($formFactors);
        }

        return null; // Multiple form factors, no lock
    }

    /**
     * P2.4: Convert normalized form factor back to display format
     * E.g., "2.5-inch" → "2.5-Inch" or "3.5-inch" → "3.5-Inch"
     *
     * @param string $normalizedFF Normalized form factor
     * @return string Display-friendly form factor
     */
    private function denormalizeFormFactor($normalizedFF) {
        $mapping = [
            '2.5-inch' => '2.5-Inch',
            '3.5-inch' => '3.5-Inch',
            'm.2' => 'M.2',
            'u.2' => 'U.2',
            'u.3' => 'U.3',
            'nvme' => 'NVMe',
            'sata' => 'SATA',
            'sas' => 'SAS'
        ];

        return $mapping[strtolower($normalizedFF)] ?? ucfirst($normalizedFF);
    }

    /**
     * LEGACY METHOD: Count total M.2 slots available in configuration
     * DEPRECATED: Use UnifiedSlotTracker::countTotalM2Slots() instead
     * Includes: motherboard direct M.2 slots + NVMe adapter M.2 slots
     *
     * @param array $existingComponents
     * @return int Total M.2 slots available
     */
    private function countTotalM2SlotsLegacy($existingComponents) {
        $totalSlots = 0;

        // Count motherboard M.2 slots
        if (!empty($existingComponents['motherboard']) && isset($existingComponents['motherboard']['component_uuid'])) {
            $mbSpecs = $this->getMotherboardSpecs($existingComponents['motherboard']['component_uuid']);
            if ($mbSpecs) {
                $m2Slots = $mbSpecs['storage']['nvme']['m2_slots'] ?? [];
                if (!empty($m2Slots) && isset($m2Slots[0]['count'])) {
                    $totalSlots += $m2Slots[0]['count'];
                }
            }
        }

        // Count NVMe adapter M.2 slots
        $nvmeAdapters = $this->componentDataService->filterNvmeAdapters($existingComponents['pciecard'] ?? []);
        foreach ($nvmeAdapters as $adapter) {
            $m2Slots = $adapter['specs']['m2_slots'] ?? 0;
            $quantity = $adapter['quantity'] ?? 1;
            $totalSlots += ($m2Slots * $quantity);
        }

        return $totalSlots;
    }

    /**
     * LEGACY METHOD: Count M.2 slots currently used by existing storage
     * DEPRECATED: Use UnifiedSlotTracker::countUsedM2Slots() instead
     *
     * @param array $existingComponents
     * @return int Number of M.2 slots in use
     */
    private function countUsedM2SlotsLegacy($existingComponents) {
        $usedSlots = 0;

        if (empty($existingComponents['storage']) || !is_array($existingComponents['storage'])) {
            return 0;
        }

        foreach ($existingComponents['storage'] as $storage) {
            $storageSpecs = $this->getStorageSpecs($storage['component_uuid']);
            if (!$storageSpecs) {
                continue;
            }

            $formFactor = strtolower($storageSpecs['form_factor'] ?? '');
            $subtype = strtolower($storageSpecs['subtype'] ?? '');

            // Check if this is M.2 storage
            if (strpos($formFactor, 'm.2') !== false || strpos($subtype, 'm.2') !== false) {
                $quantity = $storage['quantity'] ?? 1;
                $usedSlots += $quantity;
            }
        }

        return $usedSlots;
    }

    /**
     * PHASE 2: Count total U.2 slots available in configuration
     * Includes: motherboard direct U.2 slots + NVMe adapter U.2 slots
     *
     * @param array $existingComponents
     * @return int Total U.2 slots available
     */
    private function countTotalU2Slots($existingComponents) {
        $totalSlots = 0;

        // Count motherboard U.2 slots
        if (!empty($existingComponents['motherboard']) && isset($existingComponents['motherboard']['component_uuid'])) {
            $mbSpecs = $this->getMotherboardSpecs($existingComponents['motherboard']['component_uuid']);
            if ($mbSpecs) {
                $u2Slots = $mbSpecs['storage']['nvme']['u2_slots'] ?? [];
                if (isset($u2Slots['count'])) {
                    $totalSlots += $u2Slots['count'];
                }
            }
        }

        // Count NVMe adapter U.2 slots (some adapters support U.2)
        $nvmeAdapters = $this->componentDataService->filterNvmeAdapters($existingComponents['pciecard'] ?? []);
        foreach ($nvmeAdapters as $adapter) {
            $u2Slots = $adapter['specs']['u2_slots'] ?? 0;
            $quantity = $adapter['quantity'] ?? 1;
            $totalSlots += ($u2Slots * $quantity);
        }

        return $totalSlots;
    }

    /**
     * PHASE 2: Count U.2 slots currently used by existing storage
     *
     * @param array $existingComponents
     * @return int Number of U.2 slots in use
     */
    private function countUsedU2Slots($existingComponents) {
        $usedSlots = 0;

        if (empty($existingComponents['storage']) || !is_array($existingComponents['storage'])) {
            return 0;
        }

        foreach ($existingComponents['storage'] as $storage) {
            $storageSpecs = $this->getStorageSpecs($storage['component_uuid']);
            if (!$storageSpecs) {
                continue;
            }

            $formFactor = strtolower($storageSpecs['form_factor'] ?? '');
            $subtype = strtolower($storageSpecs['subtype'] ?? '');

            // Check if this is U.2 or U.3 storage
            if (strpos($formFactor, 'u.2') !== false || strpos($formFactor, 'u.3') !== false ||
                strpos($subtype, 'u.2') !== false || strpos($subtype, 'u.3') !== false) {
                $quantity = $storage['quantity'] ?? 1;
                $usedSlots += $quantity;
            }
        }

        return $usedSlots;
    }

    /**
     * Get detailed M.2/U.2 slot usage information
     * UNIFIED: Delegates to UnifiedSlotTracker
     *
     * @param string|array $configUuidOrComponents Either configUuid (string) or existingComponents array (legacy)
     * @return array Slot usage statistics
     */
    public function getNvmeSlotUsage($configUuidOrComponents) {
        // Check if it's a configUuid (string) or legacy existingComponents (array)
        if (is_string($configUuidOrComponents)) {
            // New unified approach - delegate to UnifiedSlotTracker
            return $this->slotTracker->getNvmeSlotUsage($configUuidOrComponents);
        } else {
            // Legacy fallback - keep old behavior for backward compatibility
            return [
                'm2' => [
                    'total' => $this->countTotalM2SlotsLegacy($configUuidOrComponents),
                    'used' => $this->countUsedM2SlotsLegacy($configUuidOrComponents),
                    'available' => max(0, $this->countTotalM2SlotsLegacy($configUuidOrComponents) - $this->countUsedM2SlotsLegacy($configUuidOrComponents))
                ],
                'u2' => [
                    'total' => $this->countTotalU2Slots($configUuidOrComponents),
                    'used' => $this->countUsedU2Slots($configUuidOrComponents),
                    'available' => max(0, $this->countTotalU2Slots($configUuidOrComponents) - $this->countUsedU2Slots($configUuidOrComponents))
                ]
            ];
        }
    }

    /**
     * PHASE 2: Validate motherboard against existing M.2/U.2 storage
     * Called when motherboard is added AFTER M.2/U.2 storage exists
     * UNIFIED: Delegates to UnifiedSlotTracker
     *
     * @param string $motherboardUuid Motherboard being added
     * @param array $existingComponents Existing configuration (deprecated - kept for backward compatibility)
     * @param string $configUuid Server configuration UUID (recommended)
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validateMotherboardForNvmeStorage($motherboardUuid, $existingComponents, $configUuid = null) {
        // If configUuid provided, use modern approach
        if ($configUuid) {
            $availability = $this->slotTracker->getM2SlotAvailability($configUuid);
            if (!$availability['success']) {
                return ['valid' => true, 'errors' => [], 'warnings' => []];
            }

            $errors = [];
            $warnings = [];

            // Get motherboard specs
            $mbSpecs = $this->getMotherboardSpecs($motherboardUuid);
            if (!$mbSpecs) {
                return ['valid' => true, 'errors' => [], 'warnings' => []];
            }

            $storage = $mbSpecs['storage'] ?? [];
            $m2Slots = $storage['nvme']['m2_slots'] ?? [];
            $motherboardM2Slots = (!empty($m2Slots) && isset($m2Slots[0]['count'])) ? $m2Slots[0]['count'] : 0;

            // Check if motherboard has enough M.2 slots
            $totalM2Used = $availability['motherboard_slots']['used'] + $availability['expansion_card_slots']['used'];
            $motherboardM2Used = $availability['motherboard_slots']['used'];
            $m2RequiringMotherboard = max(0, $totalM2Used - $availability['expansion_card_slots']['total']);

            if ($m2RequiringMotherboard > $motherboardM2Slots) {
                $errors[] = [
                    'type' => 'm2_insufficient_slots',
                    'message' => "Cannot add motherboard - insufficient M.2 slots for existing storage",
                    'existing_m2_storage' => $totalM2Used,
                    'motherboard_m2_slots' => $motherboardM2Slots,
                    'm2_requiring_motherboard' => $m2RequiringMotherboard,
                    'resolution' => "Select motherboard with at least $m2RequiringMotherboard M.2 slots OR remove M.2 storage"
                ];
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings
            ];
        }

        // Legacy approach - use existing components
        $errors = [];
        $warnings = [];

        $mbSpecs = $this->getMotherboardSpecs($motherboardUuid);
        if (!$mbSpecs) {
            return ['valid' => true, 'errors' => [], 'warnings' => []]; // Can't validate without specs
        }

        // Get motherboard slot counts
        $storage = $mbSpecs['storage'] ?? [];
        $m2Slots = $storage['nvme']['m2_slots'] ?? [];
        $motherboardM2Slots = (!empty($m2Slots) && isset($m2Slots[0]['count'])) ? $m2Slots[0]['count'] : 0;
        $u2Slots = $storage['nvme']['u2_slots'] ?? [];
        $motherboardU2Slots = isset($u2Slots['count']) ? $u2Slots['count'] : 0;

        // Count existing M.2 and U.2 storage
        $existingM2Count = $this->countUsedM2SlotsLegacy($existingComponents);
        $existingU2Count = $this->countUsedU2Slots($existingComponents);

        // Count slots provided by NVMe adapters
        $nvmeAdapters = $this->componentDataService->filterNvmeAdapters($existingComponents['pciecard'] ?? []);
        $adapterM2Slots = 0;
        $adapterU2Slots = 0;
        foreach ($nvmeAdapters as $adapter) {
            $adapterM2Slots += ($adapter['specs']['m2_slots'] ?? 0) * ($adapter['quantity'] ?? 1);
            $adapterU2Slots += ($adapter['specs']['u2_slots'] ?? 0) * ($adapter['quantity'] ?? 1);
        }

        // Validate M.2 storage
        if ($existingM2Count > 0) {
            // Storage that must be connected to motherboard (not adapters)
            $m2RequiringMotherboard = max(0, $existingM2Count - $adapterM2Slots);

            if ($m2RequiringMotherboard > $motherboardM2Slots) {
                $errors[] = [
                    'type' => 'm2_insufficient_slots',
                    'message' => "Cannot add motherboard - insufficient M.2 slots for existing storage",
                    'existing_m2_storage' => $existingM2Count,
                    'adapter_m2_slots' => $adapterM2Slots,
                    'motherboard_m2_slots' => $motherboardM2Slots,
                    'm2_requiring_motherboard' => $m2RequiringMotherboard,
                    'resolution' => "Select motherboard with at least $m2RequiringMotherboard M.2 slots OR add more NVMe adapters OR remove M.2 storage"
                ];
            } else if ($motherboardM2Slots === 0 && $adapterM2Slots < $existingM2Count) {
                $warnings[] = [
                    'type' => 'm2_no_motherboard_slots',
                    'message' => "Motherboard has no M.2 slots - all M.2 storage relies on NVMe adapters",
                    'existing_m2_storage' => $existingM2Count,
                    'adapter_m2_slots' => $adapterM2Slots,
                    'recommendation' => "Consider motherboard with M.2 support for better performance"
                ];
            }
        }

        // Validate U.2 storage
        if ($existingU2Count > 0) {
            // Storage that must be connected to motherboard (not adapters)
            $u2RequiringMotherboard = max(0, $existingU2Count - $adapterU2Slots);

            if ($u2RequiringMotherboard > $motherboardU2Slots) {
                $errors[] = [
                    'type' => 'u2_insufficient_slots',
                    'message' => "Cannot add motherboard - insufficient U.2 slots for existing storage",
                    'existing_u2_storage' => $existingU2Count,
                    'adapter_u2_slots' => $adapterU2Slots,
                    'motherboard_u2_slots' => $motherboardU2Slots,
                    'u2_requiring_motherboard' => $u2RequiringMotherboard,
                    'resolution' => "Select motherboard with at least $u2RequiringMotherboard U.2 slots OR add U.2-capable adapters OR remove U.2 storage"
                ];
            } else if ($motherboardU2Slots === 0 && $adapterU2Slots < $existingU2Count) {
                $warnings[] = [
                    'type' => 'u2_no_motherboard_slots',
                    'message' => "Motherboard has no U.2 slots - all U.2 storage relies on adapters",
                    'existing_u2_storage' => $existingU2Count,
                    'adapter_u2_slots' => $adapterU2Slots,
                    'recommendation' => "Consider motherboard with U.2 support"
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
}
