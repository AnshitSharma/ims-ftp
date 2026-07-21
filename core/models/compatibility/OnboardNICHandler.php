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
     * Extract and attach onboard NICs when a motherboard is added to a config
     *
     * An onboard NIC is a permanent physical feature of ONE physical board, so
     * its identity is keyed on that board's inventory row id -- NOT on the
     * motherboard spec/model uuid. Keying on the model made every physical
     * board of the same model compete for a single identity
     * ("ONBOARD-{mb8}-{index}"), and since nicinventory.SerialNumber is UNIQUE,
     * the 2nd+ board's INSERT died on a duplicate key that was swallowed here,
     * leaving that server silently without onboard NICs.
     *
     * @param string   $configUuid             Server configuration UUID
     * @param string   $motherboardUuid        Motherboard SPEC uuid (ims-data JSON)
     * @param int|null $motherboardInventoryId motherboardinventory.ID of the
     *                                         physical board being added
     * @return array Result with count and NIC details
     */
    public function autoAddOnboardNICs($configUuid, $motherboardUuid, $motherboardInventoryId = null) {
        // Load motherboard specifications from JSON (no DB ops, safe before transaction)
        $mbSpecs = $this->getMotherboardSpecs($motherboardUuid);

        if (!$mbSpecs) {
            return ['count' => 0, 'nics' => [], 'message' => 'Motherboard specs not found'];
        }

        if (!isset($mbSpecs['networking']['onboard_nics']) || empty($mbSpecs['networking']['onboard_nics'])) {
            return ['count' => 0, 'nics' => [], 'message' => 'No onboard NICs in motherboard'];
        }

        // Without the physical board's inventory id there is no unit-scoped
        // identity to mint. Virtual/test configs legitimately have no inventory
        // row (ServerBuilder skips locking for them) and must not materialize
        // real nicinventory rows -- they are reported as skipped, not failed.
        if ($motherboardInventoryId === null) {
            return [
                'count' => 0,
                'nics' => [],
                'message' => 'Skipped: no physical motherboard inventory row (virtual config)'
            ];
        }
        $motherboardInventoryId = (int)$motherboardInventoryId;

        $onboardNICs = $mbSpecs['networking']['onboard_nics'];
        $addedNICs = [];

        $ownTransaction = !$this->pdo->inTransaction();
        try {
            if ($ownTransaction) {
                $this->pdo->beginTransaction();
            }

            // Rows for THIS physical board, whatever config (if any) it was last
            // in -- an onboard NIC travels with the board between servers.
            $checkExistingStmt = $this->pdo->prepare("
                SELECT UUID, OnboardNICIndex, ServerUUID, Status, Flag FROM nicinventory
                WHERE ParentInventoryID = ?
                AND SourceType = 'onboard'
            ");
            $checkExistingStmt->execute([$motherboardInventoryId]);

            $existingByIndex = [];
            foreach ($checkExistingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existingByIndex[(int)$row['OnboardNICIndex']] = $row;
            }

            $mbShort = substr($motherboardUuid, 0, 8);

            foreach ($onboardNICs as $index => $nicSpec) {
                $nicIndex = $index + 1;

                // Identity is per PHYSICAL board: onboard-{mb8}-{inventoryId}-{n}
                $syntheticUuid = "onboard-{$mbShort}-{$motherboardInventoryId}-{$nicIndex}";
                $serialNumber = "ONBOARD-{$mbShort}-{$motherboardInventoryId}-{$nicIndex}";

                // Create descriptive notes
                $notes = sprintf(
                    "Onboard: %s %d-port %s %s",
                    $nicSpec['controller'] ?? 'Unknown Controller',
                    $nicSpec['ports'] ?? 0,
                    $nicSpec['speed'] ?? 'Unknown Speed',
                    $nicSpec['connector'] ?? 'Unknown Connector'
                );

                if (isset($existingByIndex[$nicIndex])) {
                    $existingNIC = $existingByIndex[$nicIndex];

                    // A NIC deliberately disabled by replaceOnboardNIC() (TP-4C:
                    // Status=0, Flag='replaced') stays disabled -- re-adding the
                    // board must not silently resurrect a port the user replaced.
                    if ($existingNIC['Flag'] === 'replaced') {
                        continue;
                    }

                    // Re-attach this board's existing NIC row to the current config
                    if ($existingNIC['ServerUUID'] !== $configUuid || $existingNIC['Status'] != 2) {
                        $fixNICStmt = $this->pdo->prepare("
                            UPDATE nicinventory
                            SET ServerUUID = ?, Status = 2, UpdatedAt = NOW()
                            WHERE UUID = ?
                        ");
                        $fixNICStmt->execute([$configUuid, $existingNIC['UUID']]);
                    }

                    $addedNICs[] = [
                        'uuid' => $existingNIC['UUID'],
                        'source_type' => 'onboard',
                        'parent_motherboard_uuid' => $motherboardUuid,
                        'parent_inventory_id' => $motherboardInventoryId,
                        'onboard_index' => $nicIndex,
                        'action' => 're-attached'
                    ];
                    continue;
                }

                // Insert into nicinventory with onboard tracking. No pre-INSERT
                // cleanup DELETE: identity is stable per board now, so there are
                // no orphans of a different shape to clear.
                $stmt = $this->pdo->prepare("
                    INSERT INTO nicinventory
                    (UUID, SourceType, ParentComponentUUID, ParentInventoryID, OnboardNICIndex, Status, ServerUUID, Notes, SerialNumber, Flag, CreatedAt, UpdatedAt)
                    VALUES (?, 'onboard', ?, ?, ?, 2, ?, ?, ?, 'Onboard', NOW(), NOW())
                ");

                $stmt->execute([
                    $syntheticUuid,
                    $motherboardUuid,
                    $motherboardInventoryId,
                    $nicIndex,
                    $configUuid,
                    $notes,
                    $serialNumber
                ]);

                $addedNICs[] = array_merge($nicSpec, [
                    'uuid' => $syntheticUuid,
                    'source_type' => 'onboard',
                    'parent_motherboard_uuid' => $motherboardUuid,
                    'parent_inventory_id' => $motherboardInventoryId,
                    'onboard_index' => $nicIndex,
                    'action' => 'created'
                ]);
            }

            // Update nic_config JSON (this is the source of truth for reads)
            $this->updateNICConfigJSON($configUuid);

            if ($ownTransaction) {
                $this->pdo->commit();
            }
            return [
                'count' => count($addedNICs),
                'nics' => $addedNICs,
                'message' => count($addedNICs) . ' onboard NIC(s) attached to configuration'
            ];

        } catch (Exception $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Never swallow silently: a failure here is why onboard NICs went
            // missing unnoticed for months. Callers surface this to the client.
            error_log("Error in autoAddOnboardNICs (config $configUuid, board inventory id $motherboardInventoryId): " . $e->getMessage());
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

                // Get specs based on type — only include fields needed by frontend
                if ($isOnboard) {
                    $nicData['specifications'] = $this->filterNICSpecs(
                        $this->getOnboardNICSpecs($nic['ParentComponentUUID'], $nic['OnboardNICIndex'])
                    );
                    $nicConfigData['summary']['onboard_nics']++;
                } else {
                    // Component NIC - get specs from JSON
                    $specs = $this->getComponentNICSpecs($nic['component_uuid']);
                    $nicData['specifications'] = $this->filterNICSpecs($specs);
                    $nicData['serial_number'] = $nic['SerialNumber'] ?? 'N/A';
                    $nicConfigData['summary']['component_nics']++;
                }

                $nicConfigData['nics'][] = $nicData;
                $nicConfigData['summary']['total_nics']++;
            }

            // First, verify the config exists
            $checkStmt = $this->pdo->prepare("SELECT COUNT(*) FROM server_configurations WHERE config_uuid = ?");
            $checkStmt->execute([$configUuid]);
            $exists = $checkStmt->fetchColumn();

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

            try {
                $result = $stmt->execute([$jsonString, $configUuid]);
                return $result;
            } catch (PDOException $pdoEx) {
                error_log("PDOException during UPDATE: " . $pdoEx->getMessage());
                return false;
            }

        } catch (Exception $e) {
            error_log("Error updating nic_config JSON: " . $e->getMessage());
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
     * Filter NIC specs to only include fields needed by the frontend
     */
    private function filterNICSpecs($specs) {
        if (!is_array($specs) || isset($specs['error'])) {
            return $specs;
        }
        $allowedKeys = ['controller', 'model', 'ports', 'port_type', 'speed', 'speeds', 'connector'];
        return array_intersect_key($specs, array_flip($allowedKeys));
    }

    /**
     * Detach onboard NICs when a motherboard is removed from a configuration
     *
     * An onboard NIC cannot be unplugged from its board, so removing the board
     * from a server does NOT destroy the NIC -- the row is detached
     * (ServerUUID=NULL, Status=available) and re-attaches when that same
     * physical board goes into another server. Deleting the row used to lose
     * the board linkage and any replaced/disabled state along with it, which is
     * the recreate-from-scratch cycle the TP-4C note in replaceOnboardNIC()
     * describes.
     *
     * @param string $motherboardUuid Motherboard SPEC uuid
     * @param string $configUuid
     * @return array Result with count of detached NICs
     */
    public function removeOnboardNICs($motherboardUuid, $configUuid) {
        try {
            // Get onboard NIC UUIDs before detaching
            $stmt = $this->pdo->prepare("
                SELECT UUID FROM nicinventory
                WHERE ParentComponentUUID = ? AND SourceType = 'onboard' AND ServerUUID = ?
            ");
            $stmt->execute([$motherboardUuid, $configUuid]);
            $onboardNICs = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Detach from this config. A row disabled by replaceOnboardNIC()
            // (Flag='replaced') keeps its Status=0 -- it leaves the server, but
            // it does not become an available port again.
            $stmt = $this->pdo->prepare("
                UPDATE nicinventory
                SET ServerUUID = NULL,
                    Status = CASE WHEN Flag = 'replaced' THEN Status ELSE 1 END,
                    UpdatedAt = NOW()
                WHERE ParentComponentUUID = ? AND SourceType = 'onboard' AND ServerUUID = ?
            ");
            $stmt->execute([$motherboardUuid, $configUuid]);
            $detachedCount = $stmt->rowCount();

            // Update nic_config JSON to reflect removal
            $this->updateNICConfigJSON($configUuid);

            return [
                'success' => true,
                'detached_count' => $detachedCount,
                'detached_uuids' => $onboardNICs,
                'message' => $detachedCount . ' onboard NIC(s) detached from configuration'
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

            // Mark the onboard NIC as DISABLED instead of deleting it.
            // BUGFIX (TP-4C): onboard NICs are physically part of the motherboard and
            // cannot be removed from inventory. Deleting the row lost the
            // disabled/replaced state (and the onboard linkage), so if the motherboard
            // was later removed and re-added, the synthetic onboard NIC was regenerated
            // as if it had never been replaced. We keep the row, preserving SourceType /
            // ParentComponentUUID / OnboardNICIndex, and flag it disabled (Status=0).
            $stmt = $this->pdo->prepare("
                UPDATE nicinventory
                SET Status = 0,
                    Flag = 'replaced',
                    Notes = CONCAT(COALESCE(Notes, ''), ' | Disabled: replaced by component NIC ', ?),
                    UpdatedAt = NOW()
                WHERE UUID = ?
            ");
            $stmt->execute([$componentNICUuid, $onboardNICUuid]);

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
