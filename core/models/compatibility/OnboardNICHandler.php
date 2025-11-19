<?php

/**
 * OnboardNICHandler - Manages onboard NICs from motherboards
 *
 * Handles automatic creation, tracking, and removal of onboard NICs
 * that are integrated into motherboards vs separate component NICs.
 */
class OnboardNICHandler {
    private $pdo;
    private $componentDataService;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        require_once __DIR__ . '/../components/ComponentDataService.php';
        $this->componentDataService = ComponentDataService::getInstance();
    }

    /**
     * Extract and add onboard NICs when motherboard is added to config
     *
     * @param string $configUuid Server configuration UUID
     * @param string $motherboardUuid Motherboard component UUID
     * @return array Result with count and NIC details
     */
    public function autoAddOnboardNICs($configUuid, $motherboardUuid) {
        try {
            error_log("=== AUTO-ADDING ONBOARD NICs ===");
            error_log("Config: $configUuid, Motherboard: $motherboardUuid");

            // Load motherboard specifications from JSON
            $mbSpecs = $this->getMotherboardSpecs($motherboardUuid);

            if (!$mbSpecs) {
                error_log("Could not load motherboard specifications");
                return ['count' => 0, 'nics' => [], 'message' => 'Motherboard specs not found'];
            }

            // Check if motherboard has onboard NICs
            if (!isset($mbSpecs['networking']['onboard_nics']) || empty($mbSpecs['networking']['onboard_nics'])) {
                error_log("No onboard NICs found in motherboard specs");
                return ['count' => 0, 'nics' => [], 'message' => 'No onboard NICs in motherboard'];
            }

            $onboardNICs = $mbSpecs['networking']['onboard_nics'];
            $addedNICs = [];

            error_log("Found " . count($onboardNICs) . " onboard NIC(s) in motherboard specs");

            // Check if onboard NICs already exist in nicinventory (including orphaned ones with NULL ServerUUID)
            $checkExistingStmt = $this->pdo->prepare("
                SELECT UUID, OnboardNICIndex, ServerUUID, Status FROM nicinventory
                WHERE ParentComponentUUID = ?
                AND SourceType = 'onboard'
                AND (ServerUUID = ? OR ServerUUID IS NULL)
            ");
            $checkExistingStmt->execute([$motherboardUuid, $configUuid]);
            $existingOnboardNICs = $checkExistingStmt->fetchAll(PDO::FETCH_ASSOC);

            error_log("Found " . count($existingOnboardNICs) . " existing onboard NIC(s) in nicinventory");

            // If onboard NICs exist, just make sure they're properly tracked in configuration
            if (!empty($existingOnboardNICs)) {
                error_log("Onboard NICs already exist - ensuring they're properly configured");

                foreach ($existingOnboardNICs as $existingNIC) {
                    // Fix ServerUUID and Status in nicinventory if they're wrong
                    if ($existingNIC['ServerUUID'] !== $configUuid || $existingNIC['Status'] != 2) {
                        $fixNICStmt = $this->pdo->prepare("
                            UPDATE nicinventory
                            SET ServerUUID = ?, Status = 2, UpdatedAt = NOW()
                            WHERE UUID = ?
                        ");
                        $fixNICStmt->execute([$configUuid, $existingNIC['UUID']]);
                        error_log("Fixed ServerUUID and Status for onboard NIC {$existingNIC['UUID']}");
                    }

                    $addedNICs[] = [
                        'uuid' => $existingNIC['UUID'],
                        'source_type' => 'onboard',
                        'parent_motherboard_uuid' => $motherboardUuid,
                        'onboard_index' => $existingNIC['OnboardNICIndex'],
                        'action' => 're-added'
                    ];
                }

                // Update nic_config JSON (this is the source of truth now)
                $this->updateNICConfigJSON($configUuid);

                return [
                    'count' => count($addedNICs),
                    'nics' => $addedNICs,
                    'message' => count($addedNICs) . ' onboard NIC(s) restored to configuration'
                ];
            }

            // If no existing onboard NICs, clean up any orphaned entries and create new ones
            $cleanupStmt = $this->pdo->prepare("
                DELETE FROM nicinventory
                WHERE ParentComponentUUID = ?
                AND SourceType = 'onboard'
                AND ServerUUID = ?
            ");
            $cleanupStmt->execute([$motherboardUuid, $configUuid]);
            $deletedCount = $cleanupStmt->rowCount();
            if ($deletedCount > 0) {
                error_log("Cleaned up $deletedCount orphaned onboard NIC(s) before creating new ones");
            }

            // Note: No need to clean up from server_configuration_components as it's deprecated
            // The nic_config JSON will be updated at the end to reflect current state

            foreach ($onboardNICs as $index => $nicSpec) {
                $nicIndex = $index + 1;
                // Create shorter UUID that fits in varchar(36): onboard-MB_SHORT-N
                // Format: onboard-{first_8_chars}-{nic_index} = max 18 chars
                $mbShort = substr($motherboardUuid, 0, 8);
                $syntheticUuid = "onboard-{$mbShort}-{$nicIndex}";

                error_log("Processing onboard NIC #{$nicIndex}: $syntheticUuid (full MB UUID: $motherboardUuid)");

                // Create descriptive notes
                $notes = sprintf(
                    "Onboard: %s %d-port %s %s",
                    $nicSpec['controller'] ?? 'Unknown Controller',
                    $nicSpec['ports'] ?? 0,
                    $nicSpec['speed'] ?? 'Unknown Speed',
                    $nicSpec['connector'] ?? 'Unknown Connector'
                );

                // Generate unique serial number for onboard NIC
                $serialNumber = sprintf('ONBOARD-%s-%d', substr($motherboardUuid, 0, 8), $nicIndex);

                // Insert into nicinventory with onboard tracking
                $stmt = $this->pdo->prepare("
                    INSERT INTO nicinventory
                    (UUID, SourceType, ParentComponentUUID, OnboardNICIndex, Status, ServerUUID, Notes, SerialNumber, Flag, CreatedAt, UpdatedAt)
                    VALUES (?, 'onboard', ?, ?, 2, ?, ?, ?, 'Onboard', NOW(), NOW())
                ");

                $result = $stmt->execute([
                    $syntheticUuid,
                    $motherboardUuid,
                    $nicIndex,
                    $configUuid,
                    $notes,
                    $serialNumber
                ]);

                if (!$result) {
                    error_log("Failed to insert onboard NIC into nicinventory: " . json_encode($stmt->errorInfo()));
                    continue;
                }

                error_log("Inserted onboard NIC into nicinventory");

                // Note: No need to add to server_configuration_components as it's deprecated
                // The nic_config JSON will be updated at the end to include this NIC

                $addedNICs[] = array_merge($nicSpec, [
                    'uuid' => $syntheticUuid,
                    'source_type' => 'onboard',
                    'parent_motherboard_uuid' => $motherboardUuid,
                    'onboard_index' => $nicIndex
                ]);
            }

            // Update nic_config JSON in server_build_templates
            $this->updateNICConfigJSON($configUuid);

            error_log("Successfully added " . count($addedNICs) . " onboard NIC(s)");

            return [
                'count' => count($addedNICs),
                'nics' => $addedNICs,
                'message' => count($addedNICs) . ' onboard NIC(s) automatically added'
            ];

        } catch (Exception $e) {
            error_log("Error in autoAddOnboardNICs: " . $e->getMessage());
            return [
                'count' => 0,
                'nics' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get motherboard specifications from JSON
     *
     * @param string $motherboardUuid
     * @return array|null Motherboard specs or null if not found
     */
    private function getMotherboardSpecs($motherboardUuid) {
        try {
            $mbSpecs = $this->componentDataService->findComponentByUuid('motherboard', $motherboardUuid);
            return $mbSpecs;
        } catch (Exception $e) {
            error_log("Error loading motherboard specs: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update nic_config JSON column with all NICs in configuration
     *
     * @param string $configUuid
     * @return bool Success status
     */
    public function updateNICConfigJSON($configUuid) {
        try {
            error_log("Updating nic_config JSON for config: $configUuid");

            // Get all NICs from nicinventory for this configuration
            $stmt = $this->pdo->prepare("
                SELECT
                    UUID as component_uuid,
                    SourceType,
                    ParentComponentUUID,
                    OnboardNICIndex,
                    SerialNumber,
                    Notes,
                    Status
                FROM nicinventory
                WHERE ServerUUID = ?
            ");
            $stmt->execute([$configUuid]);
            $nics = $stmt->fetchAll(PDO::FETCH_ASSOC);

            error_log("DEBUG: Found " . count($nics) . " NIC(s) in nicinventory for this configuration");
            error_log("DEBUG: NICs data: " . json_encode($nics));

            $nicConfigData = [
                'nics' => [],
                'summary' => [
                    'total_nics' => 0,
                    'onboard_nics' => 0,
                    'component_nics' => 0
                ],
                'last_updated' => date('Y-m-d H:i:s')
            ];

            foreach ($nics as $nic) {
                error_log("DEBUG: Processing NIC: " . $nic['component_uuid']);
                error_log("DEBUG: SourceType: " . ($nic['SourceType'] ?? 'NULL'));

                $isOnboard = $nic['SourceType'] === 'onboard';

                // Determine source type - default to 'component' if NULL (happens when NIC isn't in nicinventory)
                $sourceType = $nic['SourceType'] ?? 'component';

                $nicData = [
                    'uuid' => $nic['component_uuid'],
                    'source_type' => $sourceType,
                    'parent_motherboard_uuid' => $nic['ParentComponentUUID'],
                    'onboard_index' => $nic['OnboardNICIndex'],
                    'status' => $nic['Status'] == 2 ? 'in_use' : ($nic['Status'] == 1 ? 'available' : 'failed'),
                    'replaceable' => true
                ];

                // Get specs based on type
                if ($isOnboard) {
                    error_log("DEBUG: NIC is onboard - getting onboard specs");
                    $nicData['specifications'] = $this->getOnboardNICSpecs(
                        $nic['ParentComponentUUID'],
                        $nic['OnboardNICIndex']
                    );
                    $nicConfigData['summary']['onboard_nics']++;
                } else {
                    error_log("DEBUG: NIC is component - getting component specs");
                    // Component NIC - get specs from JSON
                    $specs = $this->getComponentNICSpecs($nic['component_uuid']);
                    error_log("DEBUG: Component specs retrieved: " . json_encode($specs));
                    $nicData['specifications'] = $specs;
                    $nicData['serial_number'] = $nic['SerialNumber'] ?? 'N/A';
                    $nicConfigData['summary']['component_nics']++;
                }

                $nicConfigData['nics'][] = $nicData;
                $nicConfigData['summary']['total_nics']++;
                error_log("DEBUG: Added NIC to array. Total NICs now: " . $nicConfigData['summary']['total_nics']);
            }

            error_log("DEBUG: Final summary - Total: {$nicConfigData['summary']['total_nics']}, Onboard: {$nicConfigData['summary']['onboard_nics']}, Component: {$nicConfigData['summary']['component_nics']}");

            // First, verify the config exists
            $checkStmt = $this->pdo->prepare("SELECT COUNT(*) FROM server_configurations WHERE config_uuid = ?");
            $checkStmt->execute([$configUuid]);
            $exists = $checkStmt->fetchColumn();

            error_log("Config UUID exists in server_configurations: " . ($exists ? 'YES' : 'NO'));

            if (!$exists) {
                error_log("ERROR: config_uuid '$configUuid' not found in server_configurations table");
                return false;
            }

            // Update server_configurations.nic_config
            $stmt = $this->pdo->prepare("
                UPDATE server_configurations
                SET nic_config = ?
                WHERE config_uuid = ?
            ");

            $jsonString = json_encode($nicConfigData, JSON_PRETTY_PRINT);
            error_log("Updating nic_config with JSON (length: " . strlen($jsonString) . "): " . substr($jsonString, 0, 200));

            try {
                $result = $stmt->execute([$jsonString, $configUuid]);
                $errorInfo = $stmt->errorInfo();

                error_log("Execute result: " . ($result ? 'true' : 'false'));
                error_log("Error info: " . json_encode($errorInfo));
                error_log("Rows affected: " . $stmt->rowCount());

                if (!$result) {
                    error_log("UPDATE failed with PDO error code: " . $errorInfo[0]);
                } else if ($stmt->rowCount() === 0) {
                    error_log("WARNING: UPDATE succeeded but affected 0 rows");
                } else {
                    error_log("nic_config JSON updated successfully");
                }

                return $result;
            } catch (PDOException $pdoEx) {
                error_log("PDOException during UPDATE: " . $pdoEx->getMessage());
                return false;
            }

        } catch (Exception $e) {
            error_log("Error updating nic_config JSON: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Get onboard NIC specifications from motherboard JSON
     *
     * @param string $motherboardUuid
     * @param int $nicIndex
     * @return array NIC specifications
     */
    private function getOnboardNICSpecs($motherboardUuid, $nicIndex) {
        $mbSpecs = $this->getMotherboardSpecs($motherboardUuid);

        if (!$mbSpecs || !isset($mbSpecs['networking']['onboard_nics'])) {
            return ['error' => 'Motherboard specs not found'];
        }

        $onboardNICs = $mbSpecs['networking']['onboard_nics'];
        $index = $nicIndex - 1; // Convert to 0-based index

        if (!isset($onboardNICs[$index])) {
            return ['error' => 'Onboard NIC index out of range'];
        }

        return $onboardNICs[$index];
    }

    /**
     * Get component NIC specifications from JSON
     *
     * @param string $nicUuid
     * @return array NIC specifications
     */
    private function getComponentNICSpecs($nicUuid) {
        try {
            $nicSpecs = $this->componentDataService->findComponentByUuid('nic', $nicUuid);

            if (!$nicSpecs) {
                return ['error' => 'NIC specs not found in JSON'];
            }

            return $nicSpecs;

        } catch (Exception $e) {
            error_log("Error loading component NIC specs: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Remove onboard NICs when motherboard is removed from configuration
     *
     * @param string $motherboardUuid
     * @param string $configUuid
     * @return array Result with count of removed NICs
     */
    public function removeOnboardNICs($motherboardUuid, $configUuid) {
        try {
            error_log("=== REMOVING ONBOARD NICs ===");
            error_log("Motherboard: $motherboardUuid, Config: $configUuid");

            // Get onboard NIC UUIDs before deletion for logging
            $stmt = $this->pdo->prepare("
                SELECT UUID FROM nicinventory
                WHERE ParentComponentUUID = ? AND SourceType = 'onboard' AND ServerUUID = ?
            ");
            $stmt->execute([$motherboardUuid, $configUuid]);
            $onboardNICs = $stmt->fetchAll(PDO::FETCH_COLUMN);

            error_log("Found " . count($onboardNICs) . " onboard NIC(s) to remove");

            // Note: No need to delete from server_configuration_components as it's deprecated
            // The nic_config JSON will be updated at the end to reflect current state

            // Delete from nicinventory
            $stmt = $this->pdo->prepare("
                DELETE FROM nicinventory
                WHERE ParentComponentUUID = ? AND SourceType = 'onboard' AND ServerUUID = ?
            ");
            $stmt->execute([$motherboardUuid, $configUuid]);
            $deletedFromInventory = $stmt->rowCount();

            error_log("Deleted $deletedFromInventory onboard NIC(s) from nicinventory");

            // Update nic_config JSON to reflect removal
            $this->updateNICConfigJSON($configUuid);

            return [
                'success' => true,
                'removed_count' => $deletedFromInventory,
                'removed_uuids' => $onboardNICs,
                'message' => $deletedFromInventory . ' onboard NIC(s) removed'
            ];

        } catch (Exception $e) {
            error_log("Error in removeOnboardNICs: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Replace an onboard NIC with a component NIC
     *
     * @param string $configUuid
     * @param string $onboardNICUuid
     * @param string $componentNICUuid
     * @return array Result of replacement operation
     */
    public function replaceOnboardNIC($configUuid, $onboardNICUuid, $componentNICUuid) {
        try {
            $this->pdo->beginTransaction();

            error_log("=== REPLACING ONBOARD NIC ===");
            error_log("Onboard NIC: $onboardNICUuid -> Component NIC: $componentNICUuid");

            // Verify onboard NIC exists and belongs to this config
            $stmt = $this->pdo->prepare("
                SELECT SourceType, ParentComponentUUID, OnboardNICIndex
                FROM nicinventory
                WHERE UUID = ? AND ServerUUID = ? AND SourceType = 'onboard'
            ");
            $stmt->execute([$onboardNICUuid, $configUuid]);
            $onboardNIC = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$onboardNIC) {
                throw new Exception("Onboard NIC not found or doesn't belong to this configuration");
            }

            // Verify component NIC exists and is available
            $stmt = $this->pdo->prepare("
                SELECT UUID, Status, SerialNumber
                FROM nicinventory
                WHERE UUID = ? AND SourceType = 'component'
            ");
            $stmt->execute([$componentNICUuid]);
            $componentNIC = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$componentNIC) {
                throw new Exception("Component NIC not found in inventory");
            }

            if ($componentNIC['Status'] != 1) {
                throw new Exception("Component NIC is not available (Status: {$componentNIC['Status']})");
            }

            // Note: No need to delete from server_configuration_components as it's deprecated

            // Remove onboard NIC from nicinventory
            $stmt = $this->pdo->prepare("
                DELETE FROM nicinventory
                WHERE UUID = ?
            ");
            $stmt->execute([$onboardNICUuid]);

            // Note: No need to add to server_configuration_components as it's deprecated
            // The nic_config JSON will be updated to reflect the replacement

            // Update component NIC status to In Use
            $stmt = $this->pdo->prepare("
                UPDATE nicinventory
                SET Status = 2, ServerUUID = ?, UpdatedAt = NOW()
                WHERE UUID = ?
            ");
            $stmt->execute([$configUuid, $componentNICUuid]);

            // Update nic_config JSON
            $this->updateNICConfigJSON($configUuid);

            $this->pdo->commit();

            error_log("Successfully replaced onboard NIC with component NIC");

            return [
                'success' => true,
                'message' => 'Onboard NIC successfully replaced with component NIC',
                'replaced_onboard_nic' => $onboardNICUuid,
                'new_component_nic' => $componentNICUuid
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error in replaceOnboardNIC: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
