<?php

require_once __DIR__ . '/../components/ComponentSpecPaths.php';
require_once __DIR__ . '/../compatibility/UnifiedSlotTracker.php';
require_once __DIR__ . '/../compatibility/CpuIdentityMatcher.php';
require_once __DIR__ . '/../chassis/ChassisManager.php';
require_once __DIR__ . '/../components/ComponentDataService.php';
require_once __DIR__ . '/ServerConfiguration.php';
require_once __DIR__ . '/../config/ConfigReadRouter.php';

class ServerBuilder {

    private $pdo;
    private $componentTables;
    private $dataUtils;
    private $configCache;
    private $activeLocks = [];  // P4.1: Track acquired locks for deterministic ordering

    /**
     * Upper bound on units accepted in a single add-component call (A-E7).
     * Comfortably above any real board's DIMM/bay/slot count, so it rejects
     * malformed input without constraining legitimate builds.
     */
    /**
     * When true, safeJsonDecode() throws instead of degrading a malformed persisted
     * column to an empty array (A-E2). Enabled only around mutations and finalize --
     * read/display paths keep the graceful degradation.
     */
    private $strictJsonDecode = false;


    const MAX_ADD_QUANTITY = 128;

    /**
     * Cap on inventory rows scanned by getCompatibleComponents() (A-P2).
     * The response reports `results_truncated` when the cap is hit.
     */
    const COMPATIBLE_SCAN_LIMIT = 200;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->componentTables = [
            'chassis' => 'chassisinventory',
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory',
            'pciecard' => 'pciecardinventory',
            'risercard' => 'risercardinventory',
            'hbacard' => 'hbacardinventory',
            'sfp' => 'sfpinventory'
        ];

        // Initialize DataExtractionUtilities for JSON spec lookups
        require_once __DIR__ . '/../shared/DataExtractionUtilities.php';
        $this->dataUtils = new DataExtractionUtilities($pdo);

        // ConfigurationCache was deleted 2025-11-14 from the old includes/cache/
        // path and never existed at core/cache/ -- there is nothing left to probe
        // for. $configCache stays null; the null-guarded call sites below are
        // dormant until a cache implementation exists again.
        $this->configCache = null;
    }
    
    /**
     * Get the inventory table name for a given component type
     */
    /** U-C.2/U-C.3: exposed as a library call for the command layer (INV-2's persistence reuse, zero behavior change). */
    public function getComponentInventoryTable($componentType) {
        return $this->componentTables[$componentType] ?? null;
    }

    /**
     * U-D.3b: the rows-side read, replacing every in-file
     * extractComponentsFromJson() call.
     *
     * Takes an already-fetched server_configurations row exactly as the extractor
     * did, so each call site changes by one line and nothing above it moves. The
     * row is still needed: ConfigReadRouter keys the rows lookup off its
     * config_uuid, and the scalar motherboard_uuid / chassis_uuid columns (which
     * U-D.3 does NOT drop) are read from it.
     *
     * @param array $configData server_configurations row
     * @param bool  $minimalOutput component_type + component_uuid only
     */
    private function componentsFromRows($configData, $minimalOutput = false) {
        require_once __DIR__ . '/../config/ConfigReadRouter.php';
        return ConfigReadRouter::components($this, $this->pdo, is_array($configData) ? $configData : [], $minimalOutput);
    }

    /**
     * Get a human-readable component name from JSON spec files.
     * Returns model/name from the spec, or null if not found.
     */
    private function getComponentNameFromSpec($componentType, $componentUuid) {
        try {
            // Handle onboard NICs - they don't exist in the NIC JSON spec file
            if ($componentType === 'nic' && strpos($componentUuid, 'onboard-') === 0) {
                return $this->getOnboardNICName($componentUuid);
            }

            $spec = $this->dataUtils->getComponentSpecifications($componentType, $componentUuid);
            if (!$spec || empty($spec['found'])) {
                return null;
            }
            $s = $spec['specifications'];
            $brand = $s['brand'] ?? null;
            // Try common name fields in priority order
            foreach (['model', 'name', 'model_name', 'product_name'] as $field) {
                if (!empty($s[$field])) {
                    return $brand ? trim($brand . ' ' . $s[$field]) : $s[$field];
                }
            }
            // For RAM: build "Brand Type CapacityGB Module"
            if ($componentType === 'ram') {
                $parts = array_filter([$s['brand'] ?? null, $s['memory_type'] ?? null,
                    isset($s['capacity_GB']) ? $s['capacity_GB'] . 'GB' : null,
                    $s['module_type'] ?? null]);
                if ($parts) return implode(' ', $parts);
            }
            // For Storage: build "Brand Type CapacityGB"
            if ($componentType === 'storage') {
                $cap = null;
                if (isset($s['capacity_GB'])) {
                    $cap = $s['capacity_GB'] >= 1000
                        ? round($s['capacity_GB'] / 1000, 1) . 'TB'
                        : $s['capacity_GB'] . 'GB';
                }
                $parts = array_filter([$s['brand'] ?? null, $s['storage_type'] ?? null, $cap]);
                if ($parts) return implode(' ', $parts);
            }
            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get onboard NIC name from motherboard specs via nicinventory
     */
    private function getOnboardNICName($onboardNicUuid) {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT ParentComponentUUID, OnboardNICIndex FROM nicinventory WHERE UUID = ? AND SourceType = 'onboard'"
            );
            $stmt->execute([$onboardNicUuid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || empty($row['ParentComponentUUID'])) {
                return 'Onboard NIC';
            }

            $mbSpecs = $this->dataUtils->getMotherboardByUUID($row['ParentComponentUUID']);
            if (!$mbSpecs || !isset($mbSpecs['networking']['onboard_nics'])) {
                return 'Onboard NIC';
            }

            $index = ($row['OnboardNICIndex'] ?? 1) - 1;
            $onboardNICs = $mbSpecs['networking']['onboard_nics'];
            if (!isset($onboardNICs[$index])) {
                return 'Onboard NIC';
            }

            $nic = $onboardNICs[$index];
            return sprintf('%s %dp %s %s',
                $nic['controller'] ?? 'Onboard',
                $nic['ports'] ?? 0,
                $nic['speed'] ?? '',
                $nic['connector'] ?? ''
            );
        } catch (Exception $e) {
            return 'Onboard NIC';
        }
    }

    /**
     * Get component serial number and other details from inventory table
     */
    private function getComponentDetails($componentType, $componentUuid, $serverUuid = null, $excludeSerials = []) {
        try {
            $table = $this->getComponentInventoryTable($componentType);
            if (!$table) {
                return null;
            }

            $params = [$componentUuid];
            $sql = "SELECT SerialNumber, Status FROM `$table` WHERE UUID = ?";
            
            if (!empty($excludeSerials)) {
                $placeholders = str_repeat('?,', count($excludeSerials) - 1) . '?';
                $sql .= " AND SerialNumber NOT IN ($placeholders)";
                foreach ($excludeSerials as $serial) {
                    $params[] = $serial;
                }
            }
            
            if ($serverUuid !== null) {
                // STRICT, not a preference. This used to be
                //   ORDER BY CASE WHEN ServerUUID = ? THEN 1 ELSE 0 END DESC ... LIMIT 1
                // which merely SORTED by ownership: with no unit of this model bound to
                // this config (or the bound one excluded as already-assigned), it still
                // returned a row -- a free unit, or one installed in somebody else's
                // build -- and the caller published that serial as this build's.
                //
                // Observed live 2026-08-25 on config 1f61541b: the response advertised
                // CPU 2W505038A2140, a unit belonging to config 4dee234b. Two costs, both
                // real: the UI attributes another build's hardware to this one, and a
                // remove posted with that serial matches no stored row and 404s -- the
                // same "response names a serial the engine does not hold" failure as the
                // 'Not Found' placeholder removed from getServerConfiguration().
                //
                // Ownership is the whole question being asked, so it belongs in WHERE.
                // No bound unit now yields null, which the caller already handles.
                $sql .= " AND ServerUUID = ?";
                $params[] = $serverUuid;
            }

            $sql .= " LIMIT 1";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result;
        } catch (Exception $e) {
            error_log("Error getting component details: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new server configuration
     */
    public function createConfiguration($serverName, $createdBy, $options = []) {
        try {
            $configUuid = $this->generateUuid();
            $description = $options['description'] ?? '';
            $location = $options['location'] ?? '';
            $rackPosition = $options['rack_position'] ?? '';
            $isVirtual = $options['is_virtual'] ?? 0;

            // A compatibility-bench build is ALWAYS virtual. is_sandbox only keeps it out
            // of the places that legitimately list virtual configs (the Import Template
            // picker); is_virtual is what actually makes it reserve nothing -- see the
            // $isVirtual branches in AddComponentCommand and ReplaceComponentCommand.
            // Deriving it here rather than trusting the caller means no path can create
            // a sandbox that reserves real stock.
            //
            // That claim was FALSE between P9 and 2026-09-01: this comment pointed at
            // "the $isVirtual guards in addComponent()", a method P9 had deleted, and
            // nothing replaced them in the command layer. Every sandbox add claimed a
            // real unit and moved it out of whatever server held it. Restored in
            // AddComponentCommand::resolveVirtualComponent(); the row store's NULLable
            // identity columns come from seeder 2026_09_01_001.
            $isSandbox = !empty($options['is_sandbox']) ? 1 : 0;
            if ($isSandbox) {
                $isVirtual = 1;
            }

            // is_sandbox arrives with seeder 2026_08_18_003, but code reaches production
            // ~20s after save while seeders are applied BY HAND -- so there is always a
            // window where the column does not exist yet. Naming it unconditionally here
            // took server creation (and the Servers list) down for that entire window.
            // Build the statement around the schema that is actually present.
            // handleCreateStart() refuses a sandbox request outright when the column is
            // missing, so a bench build can never silently downgrade into a real config.
            $hasSandboxColumn = self::sandboxColumnExists($this->pdo);

            // status_v2 is written in the SAME statement as configuration_status. [F-21]
            //
            // It was omitted here, so every configuration created since seeder
            // 2026_07_10_001 added the column was born with status_v2 NULL -- 8 of the
            // 12 configurations in production on 2026-07-27, INCLUDING ALL FIVE
            // physical ones. StateMachine::assertConfigTransition() fails closed on
            // NULL ("status_v2 not yet populated for this config"), so the entire real
            // fleet was untransitionable through the state machine and would have
            // stayed so through P3's soak and any enforce cutover.
            //
            // StatusMap::CONFIG_LEGACY_TO_V2[0] is the mapping for the literal 0 this
            // INSERT writes into configuration_status; they must not be able to drift.
            require_once(__DIR__ . '/../state/StatusMap.php');

            $columns = "config_uuid, server_name, description, location, rack_position, created_by, created_at, updated_at, configuration_status, status_v2, is_virtual";
            $placeholders = "?, ?, ?, ?, ?, ?, NOW(), NOW(), 0, ?, ?";
            $params = [
                $configUuid, $serverName, $description, $location, $rackPosition, $createdBy,
                StatusMap::CONFIG_LEGACY_TO_V2[0],
                $isVirtual
            ];

            if ($hasSandboxColumn) {
                $columns .= ", is_sandbox";
                $placeholders .= ", ?";
                $params[] = $isSandbox;
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO server_configurations
                ($columns)
                VALUES ($placeholders)
            ");

            $stmt->execute($params);

            return $configUuid;

        } catch (Exception $e) {
            error_log("Error creating server configuration: " . $e->getMessage());
            throw new Exception("Failed to create server configuration: " . $e->getMessage());
        }
    }

    /**
     * Acquire a SELECT ... FOR UPDATE lock on a server_configurations row and
     * return its current state. Single entry point for locking a configuration
     * row before any mutation. The lock is held until the caller's transaction
     * commits or rolls back, so every subsequent read-modify-write of JSON
     * columns in the same transaction is protected from lost-update races.
     *
     * LOCK ORDER RULE: always lock server_configurations FIRST, then any
     * {type}inventory rows. Reversing the order risks deadlocks between
     * concurrent add/remove flows.
     *
     * @param string $configUuid Configuration UUID
     * @return array|null Full row data, or null if the config does not exist
     * @throws RuntimeException If called outside an active transaction
     */
    private function lockAndLoadConfigRow($configUuid) {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('lockAndLoadConfigRow() must be called inside an active transaction');
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM server_configurations WHERE config_uuid = ? FOR UPDATE'
        );
        $stmt->execute([$configUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    
    /**
     * Remove component from server configuration
     * UPDATED: Now reads from JSON columns and updates JSON instead of relational table
     */
    public function removeComponent($configUuid, $componentType, $componentUuid, $serialNumber = null) {
        // RACE CONDITION FIX: Initialize transaction control early
        $ownTransaction = false;

        try {
            $ownTransaction = !$this->pdo->inTransaction();
            if ($ownTransaction) {
                $this->pdo->beginTransaction();
            }

            // RACE CONDITION FIX (Phase 1): Lock the server_configurations row
            // before reading. Held until commit/rollback; protects the JSON
            // read-modify-write done by updateServerConfigurationTable() below
            // against concurrent add/remove flows on the same config.
            $config = $this->lockAndLoadConfigRow($configUuid);

            if (!$config) {
                if ($ownTransaction && $this->pdo->inTransaction()) { $this->pdo->rollback(); }
                return [
                    'success' => false,
                    'message' => "Configuration not found"
                ];
            }

            // A-E2: mutation path -- refuse to act on an unparseable column.
            $this->strictJsonDecode = true;

            // U-SM.4 / U-D.4: StateGuard is the mutation gate, unconditionally.
            // TEMP-GUARD(U-0.2) sat in the else branch here and went with the flag;
            // StateGuard's own NULL-status_v2 fallback checks the same condition.
            require_once __DIR__ . '/../state/StateGuard.php';
            $stateGuardVerdict = StateGuard::checkMutation($this->pdo, $config);
            if ($stateGuardVerdict !== null) {
                if ($ownTransaction && $this->pdo->inTransaction()) { $this->pdo->rollback(); }
                return $stateGuardVerdict;
            }

            // Check if component exists in configuration by extracting from JSON
            $components = $this->componentsFromRows($config);
            $componentFound = false;
            $componentSerialNumber = null;
            // Inventory row id recorded on the matched entry (A-L5). Authoritative when
            // present -- it identifies the physical unit even for serial-less stock.
            $entryInventoryId = null;
            // Every unit a quantity>1 entry claims (A-L3), so the release below frees
            // exactly what the add reserved.
            $entryReservedUnits = [];

            foreach ($components as $comp) {
                if (($comp['component_type'] ?? null) !== $componentType
                    || ($comp['component_uuid'] ?? null) !== $componentUuid) {
                    continue;
                }
                // When the caller named a serial, only an entry carrying that serial
                // matches; otherwise the first entry of this model is the target.
                if ($serialNumber !== null && isset($comp['serial_number'])
                    && $comp['serial_number'] !== $serialNumber) {
                    continue;
                }

                $componentFound = true;
                $componentSerialNumber = $comp['serial_number'] ?? $serialNumber;
                $entryInventoryId = isset($comp['inventory_id']) ? (int)$comp['inventory_id'] : null;
                $entryReservedUnits = (isset($comp['reserved_units']) && is_array($comp['reserved_units']))
                    ? array_map('intval', $comp['reserved_units'])
                    : [];
                break;
            }

            if (!$componentFound) {
                if ($ownTransaction && $this->pdo->inTransaction()) { $this->pdo->rollback(); }
                $serialInfo = $serialNumber ? " with SerialNumber '$serialNumber'" : "";
                return [
                    'success' => false,
                    'message' => "Component not found in configuration$serialInfo"
                ];
            }

            // Fall back to the inventory row's ServerUUID to identify the PHYSICAL
            // unit when the config JSON carries no serial for it.
            //
            // motherboard, chassis and hbacard live in scalar columns
            // (motherboard_uuid, chassis_uuid, hbacard_uuid) that store only the
            // model UUID, so the loop above leaves $componentSerialNumber null for
            // them -- and no caller supplies one either (the frontend's
            // removeComponentFromServer() posts no serial_number, and
            // handleRemoveComponent() reads none). The release below then hits
            // updateComponentStatusAndServerUuid()'s ambiguity refusal whenever the
            // model has more than one physical unit, returns false, and the unit is
            // left Status=2 with a stale ServerUUID while this method still reports
            // success. Observed live 2026-07-21: motherboardinventory #45 stayed
            // installed in a config whose motherboard_uuid was already NULL.
            //
            // ServerUUID is the authoritative record of which unit is in this
            // config, so it resolves the serial exactly -- same reasoning as
            // deleteConfiguration()'s release sweep.
            //
            // The count also tells us whether there is anything to release at all.
            // Zero is a real state, not an error: F-1 released units while leaving
            // them listed in the config JSON, so a config can name a component whose
            // inventory row is already free. Removing that entry is exactly the
            // cleanup such a config needs, so it must not be blocked.
            // Read the row ID as well as the serial: a unit with no manufacturer serial
            // (SerialNumber NULL, addressed by its AssetTag) cannot be identified by
            // serial at all, and three such units of one model -- e.g. the KC600 drives
            // seeded 2026-07-22 -- are indistinguishable without the ID.
            $boundUnits = [];
            if (isset($this->componentTables[$componentType])) {
                $table = $this->componentTables[$componentType];
                $unitStmt = $this->pdo->prepare(
                    "SELECT ID, SerialNumber FROM `$table` WHERE UUID = ? AND ServerUUID = ?"
                );
                $unitStmt->execute([$componentUuid, $configUuid]);
                $boundUnits = $unitStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $boundInventoryId = null;
            $boundById = [];
            foreach ($boundUnits as $unit) {
                $boundById[(int)$unit['ID']] = $unit;
            }

            // A-L5: the config entry's own inventory_id wins when it is present and the
            // unit really is bound here. It is exact for serial-less stock, where neither
            // the caller's serial nor the bound-unit count can disambiguate.
            if ($entryInventoryId !== null && isset($boundById[$entryInventoryId])) {
                $boundInventoryId = $entryInventoryId;
                if ($componentSerialNumber === null) {
                    $componentSerialNumber = $boundById[$entryInventoryId]['SerialNumber'];
                }
            } elseif (count($boundUnits) === 1) {
                // Exactly one unit of this model is bound to this config, so it is the
                // one being removed regardless of what the caller supplied.
                $boundInventoryId = (int)$boundUnits[0]['ID'];
                if ($componentSerialNumber === null) {
                    $componentSerialNumber = $boundUnits[0]['SerialNumber'];
                }
            } elseif ($componentSerialNumber !== null) {
                // Several units of this model in one config: the caller's serial picks one.
                foreach ($boundUnits as $unit) {
                    if ($unit['SerialNumber'] === $componentSerialNumber) {
                        $boundInventoryId = (int)$unit['ID'];
                        break;
                    }
                }
            }

            // A-L3: a quantity>1 entry reserved several units. Release every one it
            // recorded (restricted to units actually bound to this config), not just the
            // first -- otherwise the surplus stays Status=2 against a config that no
            // longer lists it.
            $releaseIds = [];
            foreach ($entryReservedUnits as $reservedId) {
                if (isset($boundById[$reservedId])) {
                    $releaseIds[] = $reservedId;
                }
            }
            if (empty($releaseIds) && $boundInventoryId !== null) {
                $releaseIds[] = $boundInventoryId;
            }

            // Phase 3: NIC removal validation - Check if any SFPs are installed on ports
            if ($componentType === 'nic') {
                {
                    // U-D.3b: the SFPs seated on this NIC, from config_components rows
                    // (parent_id -> this NIC's row, slot_ref -> the port).
                    $occupiedPorts = [];
                    foreach ($this->componentsFromRows($config) as $entry) {
                        if (($entry['component_type'] ?? null) !== 'sfp') { continue; }
                        if (($entry['parent_nic_uuid'] ?? null) !== $componentUuid) { continue; }
                        $occupiedPorts[] = [
                            'port_index' => $entry['port_index'] ?? null,
                            'sfp_uuid'   => $entry['component_uuid'] ?? null,
                        ];
                    }

                    if (!empty($occupiedPorts)) {
                        if ($ownTransaction && $this->pdo->inTransaction()) { $this->pdo->rollback(); }
                        return [
                            'success' => false,
                            'message' => "Cannot remove NIC - " . count($occupiedPorts) . " SFP module(s) installed on ports",
                            'nic_uuid' => $componentUuid,
                            'occupied_ports' => $occupiedPorts,
                            'hint' => 'Remove all SFP modules from this NIC before removing the NIC itself'
                        ];
                    }
                }
            }

            // Riser removal: a riser supplies the PCIe slots its dependents sit in, so
            // pulling it strands every card in those slots at a slot id that no longer
            // exists -- precisely the state validateRiserSlotIntegrity() reports as
            // "references non-existent riser". That check existed and was correct;
            // nothing ever called it. Block instead of creating the broken state.
            if ($componentType === 'risercard' || $componentType === 'pciecard') {
                // Type is authoritative since the 2026-08-14 split; the spec/UUID tests
                // stay so a pciecard row still labelled 'Riser Card' is caught too.
                $isRiser = ($componentType === 'risercard');
                if (!$isRiser) {
                    $riserSpecs = $this->dataUtils->getPCIeCardByUUID($componentUuid);
                    $isRiser = ($riserSpecs['component_subtype'] ?? null) === 'Riser Card'
                        || stripos((string)$componentUuid, 'riser-') === 0;
                }

                if ($isRiser) {
                    $slotTracker = new UnifiedSlotTracker($this->pdo);
                    $riserCheck = $slotTracker->validateRiserRemoval($configUuid, $componentUuid);

                    if (empty($riserCheck['can_remove'])) {
                        if ($ownTransaction && $this->pdo->inTransaction()) { $this->pdo->rollback(); }
                        return [
                            'success' => false,
                            'error_type' => 'riser_has_dependents',
                            'message' => $riserCheck['message']
                                ?? 'Cannot remove riser while cards are installed in its slots',
                            'riser_uuid' => $componentUuid,
                            'dependent_components' => $riserCheck['dependent_components'] ?? [],
                            'hint' => 'Remove the cards installed in this riser first'
                        ];
                    }
                }
            }

            // SPECIAL HANDLING: If removing a motherboard, also remove its onboard NICs.
            //
            // BUGFIX (A-E6): a failed detach used to be logged as a warning and the
            // removal continued, nulling motherboard_uuid while the nicinventory rows kept
            // ParentComponentUUID pointing at the departed board and ServerUUID at this
            // config -- orphans no code path can reach. This method already rolls back on a
            // failed component release below; apply the same discipline here.
            if ($componentType === 'motherboard') {
                // The board supplies every CPU socket, DIMM slot and PCIe slot in the
                // configuration. Removing it while components still occupy those left
                // them referencing sockets and slots that no longer exist -- an
                // unreachable state the engine has no way to validate or repair.
                // Only the onboard NICs were handled; everything else was orphaned.
                //
                // Blocking rather than cascading is deliberate: a cascade would release
                // most of a build's inventory from a single call, which is the class of
                // defect the model-vs-unit work spent its time removing. Swapping a
                // board is what the replace path is for.
                $dependentTypes = ['cpu', 'ram', 'pciecard', 'risercard', 'nic', 'hbacard', 'storage'];
                // Full (non-minimal) form: minimalOutput drops 'quantity', which would
                // report an 8-DIMM entry as "1 ram".
                $remaining = $this->componentsFromRows($config);

                $blockers = [];
                foreach ($remaining as $entry) {
                    $entryType = $entry['component_type'] ?? null;
                    if (empty($entry['component_uuid'])
                        || !in_array($entryType, $dependentTypes, true)) {
                        continue;
                    }
                    // Onboard NICs are detached automatically just below; they are a
                    // property of the board, not an independent occupant.
                    if ($entryType === 'nic'
                        && strpos((string)$entry['component_uuid'], 'onboard-') === 0) {
                        continue;
                    }
                    $blockers[$entryType] = ($blockers[$entryType] ?? 0)
                        + max(1, (int)($entry['quantity'] ?? 1));
                }

                if (!empty($blockers)) {
                    if ($ownTransaction && $this->pdo->inTransaction()) { $this->pdo->rollback(); }
                    $summary = [];
                    foreach ($blockers as $type => $count) {
                        $summary[] = "$count $type";
                    }
                    return [
                        'success' => false,
                        'error_type' => 'motherboard_has_dependents',
                        'message' => 'Cannot remove motherboard while components occupy its sockets and slots: '
                            . implode(', ', $summary) . '.',
                        'dependent_counts' => $blockers,
                        'hint' => 'Remove these components first, or replace the motherboard instead of removing it'
                    ];
                }

                require_once __DIR__ . '/../compatibility/OnboardNICHandler.php';
                $nicHandler = new OnboardNICHandler($this->pdo);
                $removeResult = $nicHandler->removeOnboardNICs($componentUuid, $configUuid);

                if (!$removeResult['success']) {
                    if ($ownTransaction && $this->pdo->inTransaction()) { $this->pdo->rollback(); }
                    error_log("Removal aborted: failed to detach onboard NICs for motherboard $componentUuid "
                        . "from config $configUuid: " . ($removeResult['error'] ?? 'Unknown error'));
                    return [
                        'success' => false,
                        'error_type' => 'onboard_nic_detach_failed',
                        'message' => 'Could not detach this motherboard\'s onboard NICs. '
                                   . 'The component was not removed.'
                    ];
                }

                // F-13 (2026-07-27): mirror the detach into the rows store. The
                // children are tombstoned BEFORE the motherboard's own hook below
                // runs -- a soft tombstone does not cascade to parent_id children
                // (see ConfigComponentWriter::cleanupLedgerForRemove's note on
                // ON DELETE CASCADE not firing for the removed_at UPDATE), so
                // without this the nic rows would outlive the board that hosted
                // them. Same is_virtual guard and same transaction as that hook.
                if (!(bool)($config['is_virtual'] ?? 0)) {
                    require_once __DIR__ . '/../config/ConfigComponentWriter.php';
                    foreach (($removeResult['detached_rows'] ?? []) as $detachedNic) {
                        ConfigComponentWriter::afterLegacyRemove(
                            $this->pdo,
                            $configUuid,
                            'nic',
                            $detachedNic['UUID'],
                            $detachedNic['SerialNumber'],
                            0
                        );
                    }
                }
            }

            // P4.3 FIX: Update JSON BEFORE status (safer transaction order)
            // This ensures JSON is persisted even if status update fails

            // Update the main server_configurations table (FIRST).
            // The inventory row id targets the exact entry to drop (A-L4/A-L5); the serial
            // is the fallback for legacy entries that predate it.
            $this->updateServerConfigurationTable(
                $configUuid, $componentType, $componentUuid, 0, 'remove', $componentSerialNumber,
                ['inventory_id' => $boundInventoryId]
            );

            // U-1.5: dual-write hook (flag off by default per FLAGS.md; no-op unless
            // the flag is 'on'). Same transaction as the legacy write above; any
            // failure here propagates and rolls back both stores (fail-closed, INV-5).
            // Virtual configs are never dual-written on add, so there is nothing to
            // tombstone here either.
            // is_virtual comes from the config row locked above -- no re-query (A-P1).
            if (!(bool)($config['is_virtual'] ?? 0)) {
                require_once __DIR__ . '/../config/ConfigComponentWriter.php';
                ConfigComponentWriter::afterLegacyRemove(
                    $this->pdo,
                    $configUuid,
                    $componentType,
                    $componentUuid,
                    $componentSerialNumber,
                    0 // actor: see matching note in addComponent()'s hook call
                );
            }

            // Update component status back to "Available" and clear ServerUUID, installation date, and rack position (SECOND)
            // CRITICAL: Pass serial number to update only the specific physical component
            // Nothing bound to this config means there is nothing to release; the
            // JSON entry was already drift (see $boundUnits above). Skip the release
            // rather than calling it with a null serial, which would either refuse
            // (blocking the cleanup) or, for a single-unit model, free a unit that
            // belongs to some other config.
            $released = true;
            if (empty($boundUnits)) {
                error_log(
                    "Removed $componentType $componentUuid from config $configUuid with no inventory "
                    . "row bound to it (stale config entry — nothing to release)"
                );
            } elseif (empty($releaseIds)) {
                // Units are bound but none could be pinned to this entry -- fail closed
                // rather than guess, exactly as the ambiguity guard would.
                $released = false;
            } else {
                foreach ($releaseIds as $releaseId) {
                    $unitSerial = $boundById[$releaseId]['SerialNumber'] ?? $componentSerialNumber;
                    if (!$this->updateComponentStatusAndServerUuid(
                        $componentType, $componentUuid, 1, null,
                        "Removed from configuration $configUuid",
                        null, null, $unitSerial, $releaseId
                    )) {
                        $released = false;
                        break;
                    }
                }
            }

            // A refused/failed release used to be ignored, which orphaned the unit:
            // dropped from the config JSON but still Status=2 with this config's
            // ServerUUID, and reported to the user as a successful removal. Roll the
            // whole removal back instead -- leaving the component in the config is
            // recoverable, silently leaking a physical unit is not.
            if (!$released) {
                if ($ownTransaction && $this->pdo->inTransaction()) { $this->pdo->rollback(); }
                error_log(
                    "Removal aborted: could not release $componentType $componentUuid "
                    . "(serial: " . ($componentSerialNumber ?? 'unresolved') . ") from config $configUuid"
                );
                return [
                    'success' => false,
                    'message' => "Could not identify which physical unit to release from this server. "
                        . "The component was not removed."
                ];
            }

            // P3.4 FIX: Recalculate form factor lock on chassis/storage removal
            if ($componentType === 'chassis' || $componentType === 'storage') {
                $this->recalculateFormFactorLock($configUuid);
            }

            // Chassis gone -> the placement falls back to 1U. Shrinking can never
            // collide, so this only ever succeeds.
            if ($componentType === 'chassis') {
                require_once __DIR__ . '/../rack/RackPlacement.php';
                RackPlacement::syncHeightFromChassis($this->pdo, $configUuid);
            }

            // U-D.3a: the post-removal nic_config rebuild that stood here is gone with
            // the column. It carried its own hazard -- the rebuild read inventory
            // reservations, so on a virtual config (which has none) it blanked the blob
            // and took every REMAINING NIC with it, which is why it was flag-guarded.
            // Removing a row cannot have that failure mode: it removes one row.

            // Update calculated fields (after the side effect, so NIC changes are counted)
            $this->updateConfigurationMetrics($configUuid);

            // Log the action
            $this->logConfigurationAction($configUuid, 'remove_component', $componentType, $componentUuid);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            // Invalidate the cache LAST -- after every write is durable.
            if ($this->configCache !== null) {
                $this->configCache->invalidateConfiguration($configUuid);
            }

            return [
                'success' => true,
                'message' => "Component removed successfully"
            ];

        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollback();
            }
            error_log("Error removing component from configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to remove component: " . $e->getMessage()
            ];
        } finally {
            $this->strictJsonDecode = false;
        }
    }

    /**
     * Decide, for each candidate spec, whether adding it to $configUuid would be allowed --
     * using the SAME authority the add button uses (ValidationEngine at Trigger::ADD).
     *
     * WHY THIS EXISTS (2026-08-26)
     *
     *   Production runs ENGINE_MODE=enforce, so an add is decided by ValidationEngine,
     *   while this listing still asked the legacy ComponentCompatibility universe. Two
     *   implementations of one question, free to drift -- and they had: a platform-imported
     *   R740xd offered ZERO compatible drives while its own component_options said storage
     *   could be added. Asking the engine makes list and add unable to disagree.
     *
     *   Three deliberate details:
     *   - BASELINE DIFF. Failures already present in the current state are pre-existing and
     *     are never attributed to a candidate; without this one broken existing component
     *     would blank the entire list.
     *   - DEDUPE BY SPEC. Compatibility is a property of the model, not the physical unit,
     *     so N units of one model cost one evaluation.
     *   - CANDIDATE ROW MIRRORS AddComponentCommand::buildTarget(). Same slot planning,
     *     same sfp parent resolution -- anything else would be a third answer.
     *
     * @param array  $candidates rows from the inventory scan (UUID, SerialNumber, ...)
     * @return array<string,array{compatible:bool,reason:string,warnings:string[]}>|null
     *         keyed by spec UUID; null when the engine is off or unusable (caller falls
     *         back to the legacy branches).
     */
    private function evaluateCandidatesWithEngine($configUuid, $componentType, array $candidates, $parentNicUuid = null) {
        require_once __DIR__ . '/../validation/ValidationEngine.php';
        require_once __DIR__ . '/../validation/TargetStateBuilder.php';
        require_once __DIR__ . '/../validation/Trigger.php';
        require_once __DIR__ . '/../validation/SlotPlanner.php';

        try {
            $current = TargetStateBuilder::fromCurrent($this->pdo, $configUuid);
            $baseline = (new ValidationEngine())->evaluate($current, Trigger::ADD);
        } catch (\Throwable $e) {
            // The listing must never 500 because the engine could not build state.
            error_log("getCompatibleComponents: engine baseline failed for $configUuid: " . $e->getMessage());
            return null;
        }

        // Keyed on rule id PLUS severity (2026-09-01), not rule id alone.
        //
        // Suppressing by rule id let a PRE-EXISTING WARNING mask a candidate's ERROR
        // under the same rule. A config already carrying, say, a cpu.mixed_models
        // WARNING (a suffix variant, which never blocks) hid every candidate CPU that
        // failed cpu.mixed_models as an ERROR -- and the listing showed those parts as
        // "Compatible - validated by the same rules applied when adding", which the add
        // would then refuse. The listing must disagree with the add path as rarely as
        // possible; this was a guaranteed disagreement.
        //
        // A finding is only "pre-existing, not this candidate's fault" when it is the
        // same rule AT THE SAME SEVERITY. A severity the baseline never produced is new
        // information about the candidate.
        $baselineFailed = [];
        foreach ($baseline->failures() as $failure) {
            $baselineFailed[$failure->ruleId() . '|' . $failure->severity()] = true;
        }

        $engine = new ValidationEngine();
        $results = [];

        foreach ($candidates as $candidate) {
            $specUuid = $candidate['UUID'] ?? null;
            if ($specUuid === null || isset($results[$specUuid])) {
                continue; // dedupe by spec
            }

            try {
                $results[$specUuid] = $this->evaluateOneCandidate(
                    $engine, $current, $baselineFailed, $componentType, $specUuid, $candidate, $parentNicUuid
                );
            } catch (\Throwable $e) {
                error_log("getCompatibleComponents: engine evaluation failed for $specUuid: " . $e->getMessage());
                $results[$specUuid] = [
                    'compatible' => false,
                    'reason' => 'Compatibility could not be determined',
                    'warnings' => []
                ];
            }
        }

        return $results;
    }

    /**
     * One candidate spec, evaluated against $current and diffed against $baselineFailed.
     * @return array{compatible:bool,reason:string,warnings:string[]}
     */
    private function evaluateOneCandidate($engine, $current, array $baselineFailed, $componentType, $specUuid, array $candidate, $parentNicUuid) {
        $row = [
            'component_type' => $componentType,
            'spec_uuid' => $specUuid,
            'serial_number' => $candidate['SerialNumber'] ?? null,
            'parent_id' => null,
            'slot_ref' => null,
        ];

        // Slotted types: plan a slot the way the add path does, so PcieSlotPlacementRule
        // judges the same placement. A failed plan leaves slot_ref null and the rule
        // decides -- identical to AddComponentCommand.
        if (in_array($componentType, ['nic', 'pciecard', 'hbacard', 'risercard'], true)
            && strpos($specUuid, 'onboard-') !== 0
        ) {
            $plan = $this->planCandidateSlot($current, $componentType, $specUuid);
            if (!empty($plan['ok'])) {
                $row['slot_ref'] = $plan['slot_ref'];
            }
        }

        // SFP: NetSfpPortRule treats parent_id === null as "staged, allowed" (TP-4A), so a
        // parentless probe would call EVERY transceiver compatible. Anchor it to a real NIC:
        // the caller's if given, otherwise the first installed NIC that yields a pass.
        if ($componentType === 'sfp') {
            return $this->evaluateSfpCandidate($engine, $current, $baselineFailed, $row, $parentNicUuid);
        }

        $verdict = $engine->evaluate(TargetStateBuilder::withAdd($current, $row), Trigger::ADD);
        return $this->verdictToListingResult($verdict, $baselineFailed);
    }

    /** Try each candidate parent NIC; first pass wins, else report the last failure. */
    private function evaluateSfpCandidate($engine, $current, array $baselineFailed, array $row, $parentNicUuid) {
        $nics = $current->byType('nic');

        $parentIds = [];
        foreach ($nics as $nic) {
            if ($parentNicUuid !== null && $parentNicUuid !== '' && ($nic['spec_uuid'] ?? null) !== $parentNicUuid) {
                continue;
            }
            $parentIds[] = $nic['id'];
        }

        if (empty($parentIds)) {
            // No NIC to anchor to -- the staged-add case the rule explicitly allows.
            $verdict = $engine->evaluate(TargetStateBuilder::withAdd($current, $row), Trigger::ADD);
            return $this->verdictToListingResult($verdict, $baselineFailed);
        }

        $last = null;
        foreach ($parentIds as $parentId) {
            $row['parent_id'] = $parentId;
            $verdict = $engine->evaluate(TargetStateBuilder::withAdd($current, $row), Trigger::ADD);
            $last = $this->verdictToListingResult($verdict, $baselineFailed);
            if ($last['compatible']) {
                return $last;
            }
        }

        return $last;
    }

    /** SlotPlanner plan for a candidate, mirroring AddComponentCommand::planSlot(). */
    private function planCandidateSlot($current, $componentType, $specUuid) {
        switch ($componentType) {
            case 'nic':       $spec = $this->dataUtils->getNICByUUID($specUuid); break;
            case 'hbacard':   $spec = $this->dataUtils->getHBACardByUUID($specUuid); break;
            case 'pciecard':  $spec = $this->dataUtils->getPCIeCardByUUID($specUuid); break;
            case 'risercard': $spec = $this->dataUtils->getRiserCardByUUID($specUuid); break;
            default:          return ['ok' => false, 'slot_ref' => null];
        }
        if (!is_array($spec)) {
            return ['ok' => false, 'slot_ref' => null];
        }

        $isRiser = $componentType === 'risercard' || ($spec['component_subtype'] ?? null) === 'Riser Card';
        $resource = $isRiser ? 'riser_slot' : 'pcie_slot';

        return SlotPlanner::plan($current, $resource, SlotPlanner::extractCardWidth($spec), null);
    }

    /**
     * Verdict -> listing shape, counting only failures NEW relative to the baseline.
     * Blocking severity follows Verdict::blocking() semantics: at ADD only ERROR blocks,
     * so a VALIDATION_FAILURE (e.g. storage.caddy_pairing) surfaces as a warning here --
     * exactly as the add button already accepts it, with the block landing at finalize.
     * @return array{compatible:bool,reason:string,warnings:string[]}
     */
    private function verdictToListingResult($verdict, array $baselineFailed) {
        $blocking = [];
        $warnings = [];

        foreach ($verdict->failures() as $failure) {
            if (isset($baselineFailed[$failure->ruleId() . '|' . $failure->severity()])) {
                continue; // same rule AND same severity in the baseline: not this candidate's fault
            }
            if ($failure->severity() === Severity::ERROR) {
                $blocking[] = $failure->message();
            } else {
                $warnings[] = $failure->message();
            }
        }

        if (!empty($blocking)) {
            return ['compatible' => false, 'reason' => $blocking[0], 'warnings' => $warnings];
        }

        return [
            'compatible' => true,
            'reason' => 'Compatible - validated by the same rules applied when adding',
            'warnings' => $warnings
        ];
    }

    /**
     * Phase 4: Get compatible components for a given component type
     * Consolidated from handleGetCompatible() in server_api.php
     * Enables all code paths (HTTP, batch, CLI) to query compatible components
     */
    public function getCompatibleComponents($configUuid, $componentType, $options = []) {
        try {
            // Extract options
            $availableOnly = $options['available_only'] ?? true;
            $includeDebug = $options['include_debug'] ?? false;

            // Step 1: Validate configuration exists
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);
            if (!$config) {
                return [
                    'success' => false,
                    'message' => 'Server configuration not found'
                ];
            }

            // For virtual configs, show ALL components regardless of availability
            if ($config->get('is_virtual')) {
                $availableOnly = false;
            }

            // Step 2: Get existing components in configuration.
            //
            // Through ConfigReadRouter, not extractComponentsFromJson() directly: at
            // READ_FROM_ROWS=on config identity lives in config_components, and this was
            // the last reader still going straight to the JSON columns.
            require_once __DIR__ . '/../config/ConfigReadRouter.php';
            require_once __DIR__ . '/../config/ConfigComponentRepository.php';
            $existingComponents = ConfigReadRouter::components($this, $this->pdo, $config->getData(), true);

            // Process existing components for compatibility checking.
            //
            // A-P2: this issued one `SELECT * ... WHERE UUID = ? LIMIT 1` PER existing
            // component (N+1 on a hot, user-facing endpoint). Batched to one query per
            // TYPE. The rows are still keyed by model UUID -- as before -- because the
            // compatibility checks below only read model-level spec fields.
            //
            // The table is taken from config_components.inventory_table where a row
            // exists, NOT assumed to be `{type}inventory`: a platform-imported build's
            // board and chassis are backed by serverplatforminventory (the stocked unit
            // is the box), so the old assumption found no row and DROPPED them from
            // $existingComponentsData -- which is why every drive came back "No chassis
            // bays available" on a platform config.
            $tableByTypeUuid = [];
            try {
                foreach ((new ConfigComponentRepository($this->pdo))->liveRows($configUuid) as $ccRow) {
                    $ccType = $ccRow['component_type'] ?? null;
                    $ccSpec = $ccRow['spec_uuid'] ?? null;
                    $ccTable = $ccRow['inventory_table'] ?? null;
                    if ($ccType !== null && $ccSpec !== null && !empty($ccTable)) {
                        $tableByTypeUuid[$ccType][$ccSpec] = $ccTable;
                    }
                }
            } catch (\Throwable $e) {
                // No rows side available (pre-backfill config, or the table absent):
                // fall through to the per-type default below, which is the old behaviour.
                error_log('getCompatibleComponents: config_components lookup failed for ' . $configUuid . ': ' . $e->getMessage());
            }

            // Group the wanted UUIDs by the table they actually live in.
            $uuidsByTable = [];
            $tableForTypeUuid = [];
            foreach ($existingComponents as $existing) {
                $type = $existing['component_type'] ?? null;
                $uuid = $existing['component_uuid'] ?? null;
                if ($type === null || $uuid === null) {
                    continue;
                }
                $table = $tableByTypeUuid[$type][$uuid] ?? $this->getComponentInventoryTable($type);
                if (!$table) {
                    continue;
                }
                $uuidsByTable[$table][$uuid] = true;
                $tableForTypeUuid[$type][$uuid] = $table;
            }

            $rowsByTableUuid = [];
            foreach ($uuidsByTable as $table => $uuidSet) {
                $uuids = array_keys($uuidSet);
                $placeholders = implode(',', array_fill(0, count($uuids), '?'));
                try {
                    $stmt = $this->pdo->prepare("SELECT * FROM `$table` WHERE UUID IN ($placeholders)");
                    $stmt->execute($uuids);
                } catch (\Throwable $e) {
                    error_log("getCompatibleComponents: inventory lookup failed for table $table: " . $e->getMessage());
                    continue;
                }
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    // First row per UUID wins, matching the previous LIMIT 1 behaviour.
                    if (!isset($rowsByTableUuid[$table][$row['UUID']])) {
                        $rowsByTableUuid[$table][$row['UUID']] = $row;
                    }
                }
            }

            $existingComponentsData = [];
            foreach ($existingComponents as $existing) {
                $type = $existing['component_type'] ?? null;
                $uuid = $existing['component_uuid'] ?? null;
                $table = $tableForTypeUuid[$type][$uuid] ?? null;
                if ($table === null) {
                    continue;
                }
                // A platform-owned board/chassis is described by the platform catalog and
                // may have no row of its own even in serverplatforminventory (the row is
                // keyed by the platform's UUID). Its IDENTITY still belongs in the list --
                // dropping it is what emptied $storageRequirements. Carry a minimal
                // descriptor when no inventory row is found.
                $existingComponentsData[] = [
                    'type' => $type,
                    'uuid' => $uuid,
                    'data' => $rowsByTableUuid[$table][$uuid] ?? ['UUID' => $uuid, 'Status' => 2]
                ];
            }

            // Step 3: Get all components of requested type with availability filtering
            $table = $this->getComponentInventoryTable($componentType);
            if (!$table) {
                return [
                    'success' => false,
                    'message' => 'Invalid component type'
                ];
            }

            // Build WHERE clause based on available_only parameter
            if ($availableOnly) {
                $whereClause = "WHERE Status = 1"; // Only available components
            } else {
                $whereClause = "WHERE Status IN (0, 1, 2)"; // All statuses
            }

            // Get components (limit to 200 for performance)
            $stmt = $this->pdo->prepare("
                SELECT UUID, SerialNumber, Status, Location, Notes, ServerUUID
                FROM $table
                $whereClause
                ORDER BY (Status = 1) DESC, SerialNumber ASC
                LIMIT " . (self::COMPATIBLE_SCAN_LIMIT + 1) . "
            ");
            $stmt->execute();
            $allComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // A-P2: the scan is capped for performance, but the cap used to be silent --
            // callers had no way to tell a genuinely short list from a truncated one.
            // One extra row is fetched purely to detect the overflow, then dropped.
            $resultsTruncated = count($allComponents) > self::COMPATIBLE_SCAN_LIMIT;
            if ($resultsTruncated) {
                $allComponents = array_slice($allComponents, 0, self::COMPATIBLE_SCAN_LIMIT);
            }

            // Debug info. A-P2: only assembled when the caller actually asked for it --
            // this array previously accumulated a record per scanned component (plus full
            // nested result arrays for HBA) on EVERY call, and was then discarded unless
            // $includeDebug was set.
            $debugInfo = [];
            if ($includeDebug) {
                $debugInfo = [
                    'query_table' => $table,
                    'where_clause' => $whereClause,
                    'total_found_in_db' => count($allComponents),
                    'available_only' => $availableOnly,
                    'results_truncated' => $resultsTruncated
                ];
            }

            // Step 4: Run compatibility checks
            $compatibleComponents = [];

            require_once __DIR__ . '/../compatibility/ComponentCompatibility.php';
            require_once __DIR__ . '/../components/ComponentDataService.php';
            require_once __DIR__ . '/../compatibility/NICPortTracker.php';

            if (class_exists('ComponentCompatibility')) {
                $compatibility = new ComponentCompatibility($this->pdo);
                $componentDataService = ComponentDataService::getInstance();

                // Pre-filter: Only include components that exist in JSON
                $componentsWithJSON = [];
                $componentsWithoutJSON = [];
                $jsonValidationDetails = [];

                foreach ($allComponents as $component) {
                    $hasJSON = $compatibility->validateComponentExistsInJSON($componentType, $component['UUID']);

                    if ($hasJSON) {
                        $componentsWithJSON[] = $component;
                    } else {
                        $componentsWithoutJSON[] = $component['UUID'];
                    }

                    if ($includeDebug) {
                        $jsonValidationDetails[] = [
                            'uuid' => $component['UUID'],
                            'serial_number' => $component['SerialNumber'],
                            'has_json' => $hasJSON,
                            'status' => $component['Status'],
                            'result' => $hasJSON ? 'included' : 'excluded - no JSON spec found'
                        ];
                    }
                }

                // Replace allComponents with filtered list
                $totalBeforeFiltering = count($allComponents);
                $allComponents = $componentsWithJSON;

                // Add to debug info
                if ($includeDebug) {
                $debugInfo['total_before_json_filter'] = $totalBeforeFiltering;
                $debugInfo['total_with_json'] = count($allComponents);
                $debugInfo['components_without_json'] = $componentsWithoutJSON;
                $debugInfo['json_validation_details'] = $jsonValidationDetails;

                // Add detailed component listing to debug
                $debugInfo['components_to_check'] = array_map(function($c) {
                    return [
                        'uuid' => $c['UUID'],
                        'serial' => $c['SerialNumber'],
                        'status' => $c['Status']
                    ];
                }, $allComponents);
                } // end if ($includeDebug)

                // The engine answers the listing whenever it answers the add (ENGINE_MODE
                // != off), so the two cannot disagree. Null => engine off/unusable, and
                // the legacy branches below remain the authority.
                $engineVerdicts = $this->evaluateCandidatesWithEngine(
                    $configUuid, $componentType, $allComponents, $options['parent_nic_uuid'] ?? null
                );
                if ($includeDebug) {
                    $debugInfo['verdict_source'] = $engineVerdicts === null ? 'legacy' : 'validation_engine';
                }

                // Run compatibility checks for each component
                foreach ($allComponents as $component) {
                    $isCompatible = true;
                    $compatibilityReasons = [];
                    $fullChassisResult = null;
                    $engineWarnings = [];

                    if ($engineVerdicts !== null) {
                        $verdictForSpec = $engineVerdicts[$component['UUID']] ?? null;
                        if ($verdictForSpec === null) {
                            $isCompatible = false;
                            $compatibilityReasons = ['Compatibility could not be determined'];
                        } else {
                            $isCompatible = $verdictForSpec['compatible'];
                            $compatibilityReasons = [$verdictForSpec['reason']];
                            $engineWarnings = $verdictForSpec['warnings'];
                        }
                    }
                    // If no existing components, all components are compatible
                    elseif (empty($existingComponentsData)) {
                        $isCompatible = true;
                        $compatibilityReasons[] = "No existing components - all components available";
                    } else {
                        // Component-type-specific compatibility checking
                        if ($componentType === 'ram') {
                            $ramCompatResult = $compatibility->checkRAMDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $ramCompatResult['compatible'];
                            $compatibilityReasons = array_merge(
                                $ramCompatResult['details'] ?? [],
                                $ramCompatResult['warnings'] ?? [],
                                $ramCompatResult['recommendations'] ?? []
                            );
                        } elseif ($componentType === 'cpu') {
                            $cpuCompatResult = $compatibility->checkCPUDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $cpuCompatResult['compatible'];
                            $compatibilityReasons = [$cpuCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                        } elseif ($componentType === 'motherboard') {
                            $motherboardCompatResult = $compatibility->checkMotherboardDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $motherboardCompatResult['compatible'];
                            $compatibilityReasons = [$motherboardCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                        } elseif ($componentType === 'storage') {
                            $storageCompatResult = $compatibility->checkStorageDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $storageCompatResult['compatible'];
                            $compatibilityReasons = [$storageCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                        } elseif ($componentType === 'chassis') {
                            $chassisCompatResult = $compatibility->checkChassisDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $chassisCompatResult['compatible'];
                            $compatibilityReasons = [$chassisCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                            $fullChassisResult = $chassisCompatResult;

                            if (isset($chassisCompatResult['details'])) {
                                $compatibilityReasons[] = 'DEBUG_DETAILS: ' . json_encode($chassisCompatResult['details']);
                            }
                        } elseif ($componentType === 'pciecard') {
                            $pcieCompatResult = $compatibility->checkPCIeDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $pcieCompatResult['compatible'];
                            $compatibilityReasons = [$pcieCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                        } elseif ($componentType === 'nic') {
                            $nicCompatResult = $compatibility->checkPCIeDecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData, 'nic'
                            );
                            $isCompatible = $nicCompatResult['compatible'];
                            $compatibilityReasons = [$nicCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                        } elseif ($componentType === 'hbacard') {
                            $hbaCompatResult = $compatibility->checkHBADecentralizedCompatibility(
                                ['uuid' => $component['UUID']], $existingComponentsData
                            );
                            $isCompatible = $hbaCompatResult['compatible'];
                            $compatibilityReasons = [$hbaCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];

                            // Add debug info for HBA samples
                            // A-P2: full nested result arrays -- only retained on request.
                            if ($includeDebug && !isset($debugInfo['hba_compat_samples'])) {
                                $debugInfo['hba_compat_samples'] = [];
                            }
                            if ($includeDebug)
                            if (count($debugInfo['hba_compat_samples']) < 3) {
                                $debugInfo['hba_compat_samples'][] = [
                                    'uuid' => $component['UUID'],
                                    'serial' => $component['SerialNumber'],
                                    'result' => $hbaCompatResult
                                ];
                            }
                        } elseif ($componentType === 'sfp') {
                            // SFP compatibility checking based on NIC port types
                            $nicPortTypes = [];
                            $nicDetails = [];
                            foreach ($existingComponentsData as $existingComp) {
                                if ($existingComp['type'] === 'nic') {
                                    $nicSpecs = $componentDataService->getComponentSpecifications('nic', $existingComp['uuid']);
                                    if ($nicSpecs && isset($nicSpecs['port_type'])) {
                                        $portType = $nicSpecs['port_type'];
                                        $nicPortTypes[] = $portType;
                                        $nicDetails[] = [
                                            'uuid' => $existingComp['uuid'],
                                            'model' => $nicSpecs['model'] ?? 'Unknown',
                                            'port_type' => $portType
                                        ];
                                    }
                                }
                            }

                            if (empty($nicPortTypes)) {
                                // No NICs in configuration - ALL SFPs are compatible
                                $isCompatible = true;
                                $compatibilityReasons = ['SFP can be added now - will be assigned when compatible NIC is added'];
                            } else {
                                // Get SFP type from specs
                                $sfpSpecs = $componentDataService->getComponentSpecifications('sfp', $component['UUID']);
                                $sfpType = $sfpSpecs['type'] ?? null;

                                if (!$sfpType) {
                                    $isCompatible = false;
                                    $compatibilityReasons = ['SFP type information missing in specifications'];
                                } else {
                                    // Check if SFP type is compatible with at least one NIC port type
                                    $isCompatible = false;
                                    $compatibleWith = [];

                                    foreach ($nicDetails as $nicDetail) {
                                        if (NICPortTracker::isCompatible($nicDetail['port_type'], $sfpType)) {
                                            $isCompatible = true;
                                            $compatibleWith[] = "{$nicDetail['model']} ({$nicDetail['port_type']} port)";
                                        }
                                    }

                                    if ($isCompatible) {
                                        $compatibilityReasons = [
                                            "SFP type '{$sfpType}' compatible with: " . implode(', ', $compatibleWith)
                                        ];
                                    } else {
                                        $availablePortTypes = array_unique(array_column($nicDetails, 'port_type'));
                                        $compatibilityReasons = [
                                            "SFP type '{$sfpType}' incompatible with available NIC port types: " . implode(', ', $availablePortTypes)
                                        ];
                                    }
                                }
                            }
                        } elseif ($componentType === 'caddy') {
                            $newComponent = ['type' => 'caddy', 'uuid' => $component['UUID']];
                            $compatResult = $compatibility->checkCaddyDecentralizedCompatibility($newComponent, $existingComponentsData);
                            if (!$compatResult['compatible']) {
                                $isCompatible = false;
                                $compatibilityReasons = array_merge($compatibilityReasons, $compatResult['issues'] ?? []);
                            } else {
                                $compatibilityReasons[] = $compatResult['compatibility_summary'] ?? 'Compatible';
                            }
                        } else {
                            // Check compatibility with each existing component for other types
                            foreach ($existingComponentsData as $existingComp) {
                                $newComponent = ['type' => $componentType, 'uuid' => $component['UUID']];
                                $existingComponent = ['type' => $existingComp['type'], 'uuid' => $existingComp['uuid']];

                                $compatResult = $compatibility->checkComponentPairCompatibility($newComponent, $existingComponent);

                                if (!$compatResult['compatible']) {
                                    $isCompatible = false;
                                    $compatibilityReasons[] = "Incompatible with " . $existingComp['type'] . ": " .
                                                             implode(', ', $compatResult['issues'] ?? []);
                                    break;
                                } else {
                                    $compatibilityReasons[] = "Compatible with " . $existingComp['type'];
                                }
                            }
                        }
                    }

                    // CPU-to-CPU SKU pairing. The generic pair check above resolves through
                    // checkComponentPairCompatibility(), which has no cpu-cpu handler -- so an
                    // unpairable second CPU would be offered here and only rejected later at
                    // add-time. Decide it up front, using the same authority as the add path.
                    // Skipped under the engine: CpuMixedModelsRule already decides pairing
                    // there, and running this too would apply two authorities to one type.
                    $cpuPairingWarnings = [];
                    if ($engineVerdicts === null && $componentType === 'cpu' && $isCompatible) {
                        $cpuMatcher = new CpuIdentityMatcher($this->dataUtils);
                        foreach ($existingComponentsData as $existingComp) {
                            if (($existingComp['type'] ?? '') !== 'cpu') {
                                continue;
                            }
                            $pairing = $cpuMatcher->compareByUuid($existingComp['uuid'], $component['UUID']);
                            if (!$pairing['compatible']) {
                                $isCompatible = false;
                                $compatibilityReasons[] = $pairing['error'];
                                break;
                            }
                            if (!empty($pairing['warning'])) {
                                $cpuPairingWarnings[] = $pairing['warning'];
                            }
                        }
                    }

                    // Build component result
                    $componentStatus = (int)$component['Status'];
                    $statusLabels = [0 => 'failed', 1 => 'available', 2 => 'in_use'];

                    $compatibleComponent = [
                        'uuid' => $component['UUID'],
                        'component_name' => $this->getComponentNameFromSpec($componentType, $component['UUID']),
                        'serial_number' => $component['SerialNumber'],
                        'status' => $componentStatus,
                        'status_label' => $statusLabels[$componentStatus] ?? 'unknown',
                        'available_for_use' => ($componentStatus === 1),
                        'server_uuid' => $component['ServerUUID'] ?? null,
                        'location' => $component['Location'],
                        'notes' => $component['Notes'],
                        'compatibility_reason' => implode('; ', $compatibilityReasons),
                        'is_compatible' => $isCompatible
                    ];

                    // Add chassis-specific fields
                    if ($componentType === 'chassis' && isset($fullChassisResult['score_breakdown'])) {
                        $compatibleComponent['score_breakdown'] = $fullChassisResult['score_breakdown'];
                    }
                    if ($componentType === 'chassis' && isset($fullChassisResult['warnings']) && !empty($fullChassisResult['warnings'])) {
                        $compatibleComponent['warnings'] = $fullChassisResult['warnings'];
                    }

                    // Engine warnings (non-blocking failures new to this candidate) so the
                    // operator sees them before the add -- e.g. an uncaddied drive, which
                    // is addable now and blocks only at finalize.
                    if (!empty($engineWarnings)) {
                        $compatibleComponent['warnings'] = array_values(array_unique($engineWarnings));
                    }

                    // SKU-variant pairing warnings, so the operator sees them before the add
                    if ($componentType === 'cpu' && !empty($cpuPairingWarnings)) {
                        $compatibleComponent['warnings'] = array_values(array_unique($cpuPairingWarnings));
                    }

                    $compatibleComponents[] = $compatibleComponent;
                }
            } else {
                // Fallback if ComponentCompatibility not available
                error_log("WARNING: ComponentCompatibility class not available, using simplified fallback");

                foreach ($allComponents as $component) {
                    $componentStatus = (int)$component['Status'];
                    $statusLabels = [0 => 'failed', 1 => 'available', 2 => 'in_use'];

                    $compatibleComponent = [
                        'uuid' => $component['UUID'],
                        'component_name' => $this->getComponentNameFromSpec($componentType, $component['UUID']),
                        'serial_number' => $component['SerialNumber'],
                        'status' => $componentStatus,
                        'status_label' => $statusLabels[$componentStatus] ?? 'unknown',
                        'available_for_use' => ($componentStatus === 1),
                        'server_uuid' => $component['ServerUUID'] ?? null,
                        'location' => $component['Location'],
                        'notes' => $component['Notes'],
                        'compatibility_reason' => empty($existingComponentsData) ? "No existing components - all components available" : "Basic compatibility check passed",
                        'is_compatible' => true
                    ];

                    $compatibleComponents[] = $compatibleComponent;
                }
            }

            // Step 5: Build response
            $compatibleAndAvailable = array_filter($compatibleComponents, function($comp) {
                return $comp['is_compatible'] && $comp['available_for_use'];
            });
            $compatibleButNotAvailable = array_filter($compatibleComponents, function($comp) {
                return $comp['is_compatible'] && !$comp['available_for_use'];
            });
            $incompatibleOnly = array_filter($compatibleComponents, function($comp) {
                return !$comp['is_compatible'];
            });

            // Respect available_only parameter
            if ($availableOnly) {
                $allCompatibleComponents = array_values($compatibleAndAvailable);
            } else {
                $allCompatibleComponents = array_merge(
                    array_values($compatibleAndAvailable),
                    array_values($compatibleButNotAvailable)
                );
            }

            // A-P2: $responseData used to be built in full and then discarded except for
            // three sub-keys re-read at the return. Only those are assembled now.
            $incompatibleOnly = array_values($incompatibleOnly);

            $filtersApplied = [
                'available_only' => $availableOnly,
                'component_type' => $componentType,
                'note' => $availableOnly
                    ? 'Only available components shown (Status=1 and not assigned to another server).'
                    : 'All physical components shown. Check available_for_use flag to see which can be added.'
            ];

            $compatibilitySummary = [
                'has_compatible' => count($allCompatibleComponents) > 0,
                'has_incompatible' => count($incompatibleOnly) > 0,
                'main_issues' => count($incompatibleOnly) > 0 ?
                    array_slice(array_unique(array_column($incompatibleOnly, 'compatibility_reason')), 0, 3) : []
            ];

            // Add inventory summary.
            //
            // A-P2: this had no WHERE clause -- it grouped the ENTIRE inventory table and
            // returned a row for every UUID in it, including thousands never referenced in
            // this response, all of which were then serialised into the payload. (The
            // `HAVING COUNT(*) > 0` was a tautology after GROUP BY.) Scoped to the UUIDs
            // actually being returned.
            $summaryUuids = array_values(array_unique(array_merge(
                array_column($allCompatibleComponents, 'uuid'),
                array_column($incompatibleOnly, 'uuid')
            )));

            $uuidInventorySummary = [];
            if (!empty($summaryUuids)) {
                $placeholders = implode(',', array_fill(0, count($summaryUuids), '?'));
                $stmt = $this->pdo->prepare("
                    SELECT UUID, COUNT(*) as total_count,
                           SUM(CASE WHEN Status = 1 THEN 1 ELSE 0 END) as available_count,
                           SUM(CASE WHEN Status = 2 THEN 1 ELSE 0 END) as in_use_count,
                           SUM(CASE WHEN Status = 0 THEN 1 ELSE 0 END) as failed_count
                    FROM `$table`
                    WHERE UUID IN ($placeholders)
                    GROUP BY UUID
                ");
                $stmt->execute($summaryUuids);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
                    $uuidInventorySummary[$inv['UUID']] = [
                        'total' => (int)$inv['total_count'],
                        'available' => (int)$inv['available_count'],
                        'in_use' => (int)$inv['in_use_count'],
                        'failed' => (int)$inv['failed_count']
                    ];
                }
            }

            return [
                'success' => true,
                'message' => count($allCompatibleComponents) > 0 ? "Compatible components found" : "No compatible components found",
                'compatible_components' => $allCompatibleComponents,
                'incompatible_components' => $incompatibleOnly,
                'totals' => [
                    'compatible_and_available' => count($compatibleAndAvailable),
                    'compatible_but_unavailable' => count($compatibleButNotAvailable),
                    'incompatible' => count($incompatibleOnly),
                    'total_found' => count($compatibleComponents)
                ],
                // A-P2: tell the caller when the inventory scan hit its cap, instead of
                // silently returning a truncated list.
                'results_truncated' => $resultsTruncated,
                'scan_limit' => self::COMPATIBLE_SCAN_LIMIT,
                'filters_applied' => $filtersApplied,
                'debug_info' => $debugInfo,
                'inventory_summary' => [
                    'by_uuid' => $uuidInventorySummary,
                    'note' => 'Multiple physical components can share the same UUID (representing the same model). Use serial_number to identify individual components.'
                ],
                'compatibility_summary' => $compatibilitySummary
            ];

        } catch (Exception $e) {
            error_log("Error getting compatible components: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to get compatible components: " . $e->getMessage()
            ];
        }
    }

    /**
     * The resolved spec of the board installed in this configuration, or null when
     * the build has no board (or its spec is unreadable).
     *
     * WHY THIS IS ON THE API AT ALL
     *
     *   The builder UI used to fetch ims-data/motherboard/motherboard-level-3.json
     *   over HTTP and search it by UUID itself -- a THIRD spec resolver alongside
     *   ComponentDataService and DataExtractionUtilities. A board that comes inside a
     *   server compute platform is described in serverplatform/, not in the
     *   motherboard catalog, so that search silently found nothing and every board
     *   figure in the hardware panel fell back to a hardcoded literal: 4 DIMM slots
     *   on a 24-slot R630, 1 CPU socket on a 2-socket board, "288-pin DIMM" for
     *   DDR4 ECC. Silently, because the live copy of that function had dropped the
     *   "UUID not found" warning its earlier version carried.
     *
     *   PlatformSpecIndex was written for exactly this divergence between the two
     *   backend resolvers, and its docblock's conclusion applies here unchanged:
     *   one implementation, N consumers. Teaching the frontend to ALSO read the
     *   platform catalog would have made a fourth place to keep in sync. This
     *   resolves the board once, on the side that already does it correctly, and
     *   publishes the answer.
     *
     * getMotherboardByUUID() consults PlatformSpecIndex before the catalog, so a
     * platform-owned board and a loose spare both resolve through this one call.
     *
     * @param string $configUuid
     * @return array|null
     */
    public function getMotherboardSpecForConfig($configUuid) {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT motherboard_uuid FROM server_configurations WHERE config_uuid = ?"
            );
            $stmt->execute([$configUuid]);
            $motherboardUuid = $stmt->fetchColumn();

            if (empty($motherboardUuid)) {
                return null;
            }

            $spec = $this->dataUtils->getMotherboardByUUID($motherboardUuid);

            return is_array($spec) ? $spec : null;
        } catch (Exception $e) {
            // Presentation data only -- a build must still load without it, falling
            // back to the UI's own defaults exactly as it did before this existed.
            error_log("Error resolving motherboard spec for config $configUuid: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get unified slot tracking for a server configuration
     * Consolidates PCIe, riser, and M.2 slot information from UnifiedSlotTracker
     *
     * @param string $configUuid Server configuration UUID
     * @return array Unified slot tracking data
     */
    public function getSlotTracking($configUuid) {
        try {
            $slotTracker = new UnifiedSlotTracker($this->pdo);

            // Get PCIe slot availability (includes riser-provided slots)
            $pcieAvailability = $slotTracker->getSlotAvailability($configUuid);

            // Get riser slot availability
            $riserAvailability = $slotTracker->getRiserSlotAvailability($configUuid);

            // Get M.2 slot availability
            $m2Availability = $slotTracker->getM2SlotAvailability($configUuid);

            // Build unified slot tracking response
            $result = [
                'pcie' => [
                    'success' => $pcieAvailability['success'],
                    // Why the tracker failed, not just that it did: "no PCIe slots
                    // defined on this board" is a legitimate zero, while "specs not
                    // found" is a real fault. BuildAffordances has to tell them apart
                    // to decide between hiding the option and failing open.
                    'error' => $pcieAvailability['error'] ?? null,
                    'total_slots' => $pcieAvailability['total_slots'] ?? [],
                    'used_slots' => $pcieAvailability['used_slots'] ?? [],
                    'available_slots' => $pcieAvailability['available_slots'] ?? [],
                    'total_count' => 0,
                    'used_count' => 0,
                    'available_count' => 0
                ],
                'riser' => [
                    'success' => $riserAvailability['success'],
                    'error' => $riserAvailability['error'] ?? null,
                    'total_slots' => $riserAvailability['total_slots'] ?? [],
                    'used_slots' => $riserAvailability['used_slots'] ?? [],
                    'available_slots' => $riserAvailability['available_slots'] ?? [],
                    'total_count' => 0,
                    'used_count' => 0,
                    'available_count' => 0
                ],
                'm2' => [
                    'success' => $m2Availability['success'],
                    'motherboard_slots' => $m2Availability['motherboard_slots'] ?? [
                        'total' => 0,
                        'used' => 0,
                        'available' => 0
                    ],
                    'expansion_card_slots' => $m2Availability['expansion_card_slots'] ?? [
                        'total' => 0,
                        'used' => 0,
                        'available' => 0,
                        'providers' => []
                    ],
                    'total_count' => 0,
                    'used_count' => 0,
                    'available_count' => 0
                ]
            ];

            // Calculate PCIe slot counts
            foreach ($result['pcie']['total_slots'] as $slotType => $slotIds) {
                $result['pcie']['total_count'] += count($slotIds);
            }
            $result['pcie']['used_count'] = count($result['pcie']['used_slots']);
            $result['pcie']['available_count'] = $result['pcie']['total_count'] - $result['pcie']['used_count'];

            // Calculate riser slot counts
            foreach ($result['riser']['total_slots'] as $slotType => $slotIds) {
                $result['riser']['total_count'] += count($slotIds);
            }
            $result['riser']['used_count'] = count($result['riser']['used_slots']);
            $result['riser']['available_count'] = $result['riser']['total_count'] - $result['riser']['used_count'];

            // Calculate M.2 slot counts
            $result['m2']['total_count'] =
                $result['m2']['motherboard_slots']['total'] +
                $result['m2']['expansion_card_slots']['total'];
            $result['m2']['used_count'] =
                $result['m2']['motherboard_slots']['used'] +
                $result['m2']['expansion_card_slots']['used'];
            $result['m2']['available_count'] =
                $result['m2']['motherboard_slots']['available'] +
                $result['m2']['expansion_card_slots']['available'];

            return $result;

        } catch (Exception $e) {
            error_log("Error getting slot tracking: " . $e->getMessage());
            return [
                'error' => 'Failed to get slot tracking: ' . $e->getMessage(),
                'pcie' => ['success' => false, 'total_count' => 0, 'used_count' => 0, 'available_count' => 0],
                'riser' => ['success' => false, 'total_count' => 0, 'used_count' => 0, 'available_count' => 0],
                'm2' => ['success' => false, 'total_count' => 0, 'used_count' => 0, 'available_count' => 0]
            ];
        }
    }

    /**
     * Get storage connectivity tracking for a server configuration
     */
    public function getStorageConnectivity($configUuid, $components) {
        try {
            $stmt = $this->pdo->prepare("SELECT chassis_uuid FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $chassisUuid = $stmt->fetchColumn();

            $totalBays = 0;
            if ($chassisUuid) {
                $chassisManager = new ChassisManager();
                $chassisResult = $chassisManager->loadChassisSpecsByUUID($chassisUuid);
                if ($chassisResult['found']) {
                    $totalBays = $chassisResult['specifications']['drive_bays']['total_bays'] ?? 0;
                }
            }

            $connections = [];
            $usedBays = 0;

            $storageComponents = $components['storage'] ?? [];
            foreach ($storageComponents as $storage) {
                $conn = $storage['connection'] ?? null;
                if ($conn && ($conn['type'] ?? '') === 'chassis_bay') {
                    $usedBays++;
                }
                $connections[] = [
                    'storage_uuid' => $storage['uuid'],
                    'storage_name' => $storage['component_name'] ?? 'Unknown',
                    'serial_number' => $storage['serial_number'] ?? 'Unknown',
                    'connection_type' => $conn['type'] ?? 'not_connected',
                    'bay_number' => $conn['bay_number'] ?? null,
                    'backplane_interface' => $conn['backplane_interface'] ?? null,
                    'storage_interface' => $conn['storage_interface'] ?? null,
                    'compatibility' => $conn['compatibility_type'] ?? null,
                    'description' => $conn['description'] ?? null
                ];
            }

            return [
                'drive_bays' => [
                    'total' => $totalBays,
                    'used' => $usedBays,
                    'available' => max(0, $totalBays - $usedBays)
                ],
                'connections' => $connections
            ];
        } catch (Exception $e) {
            error_log("Error getting storage connectivity: " . $e->getMessage());
            return ['drive_bays' => ['total' => 0, 'used' => 0, 'available' => 0], 'connections' => []];
        }
    }

    /**
     * Get unified network configuration for a server
     * Consolidates NIC data from multiple sources (onboard, component, port tracking)
     *
     * @param string $configUuid Server configuration UUID
     * @return array Unified network configuration data
     */
    public function getNetworkConfiguration($configUuid) {
        try {
            $result = [
                'summary' => [
                    'total_ports' => 0,
                    'onboard_ports' => 0,
                    'component_ports' => 0,
                    'total_nics' => 0,
                    'onboard_nics' => 0,
                    'component_nics' => 0
                ],
                'nics' => [],
                'success' => true
            ];

            // U-D.3b: NICs and SFPs come from config_components rows, not from the
            // nic_config / sfp_configuration blobs.
            //
            // nic_config was a DERIVED cache -- OnboardNICHandler::updateNICConfigJSON()
            // rebuilt it wholesale from nicinventory on every change, specs and summary
            // included. Rows carry identity; the specs half is re-resolved here through
            // OnboardNICHandler::resolveNICSpecs(), which is that same rebuild's spec
            // lookup extracted rather than reimplemented, so the block the builder UI
            // reads keeps its shape and its key set.
            //
            // The summary is now COUNTED rather than trusted. It used to be whatever the
            // blob's 'summary' said, which was only as fresh as the last rebuild.
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $configRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$configRow) {
                return $result;
            }

            require_once __DIR__ . '/../compatibility/OnboardNICHandler.php';
            $nicSpecResolver = new OnboardNICHandler($this->pdo);

            $sfps = [];
            foreach ($this->componentsFromRows($configRow) as $entry) {
                $type = $entry['component_type'] ?? null;
                if ($type === 'sfp') {
                    $sfps[] = [
                        'uuid'            => $entry['component_uuid'] ?? null,
                        'parent_nic_uuid' => $entry['parent_nic_uuid'] ?? null,
                        'port_index'      => $entry['port_index'] ?? null,
                        'serial_number'   => $entry['serial_number'] ?? null,
                    ];
                    continue;
                }
                if ($type !== 'nic' || empty($entry['component_uuid'])) {
                    continue;
                }

                $nicUuid   = (string)$entry['component_uuid'];
                $isOnboard = ($entry['source_type'] ?? 'component') === 'onboard';
                $specs     = $nicSpecResolver->resolveNICSpecs($nicUuid);

                $nicEntry = [
                    'uuid'          => $nicUuid,
                    'source_type'   => $isOnboard ? 'onboard' : 'component',
                    'status'        => 'in_use',
                    'replaceable'   => true,
                    'specifications' => $specs,
                ];
                if (!$isOnboard) {
                    $nicEntry['serial_number'] = $entry['serial_number'] ?? 'N/A';
                }
                if (($entry['slot_position'] ?? null) !== null) {
                    $nicEntry['slot_position'] = $entry['slot_position'];
                }
                $result['nics'][] = $nicEntry;

                $ports = (int)($specs['ports'] ?? 0);
                $result['summary']['total_nics']++;
                $result['summary']['total_ports'] += $ports;
                if ($isOnboard) {
                    $result['summary']['onboard_nics']++;
                    $result['summary']['onboard_ports'] += $ports;
                } else {
                    $result['summary']['component_nics']++;
                    $result['summary']['component_ports'] += $ports;
                }
            }

            foreach ($result['nics'] as &$nic) {
                $nicUuid = $nic['uuid'];
                $portCount = $nic['specifications']['ports'] ?? 0;
                $portMapping = [];

                for ($i = 1; $i <= $portCount; $i++) {
                    $portMapping[$i] = ['status' => 'empty', 'sfp' => null];
                }

                foreach ($sfps as $sfp) {
                    if (($sfp['parent_nic_uuid'] ?? null) === $nicUuid && isset($sfp['port_index'])) {
                        $portMapping[$sfp['port_index']] = [
                            'status' => 'occupied',
                            'sfp_uuid' => $sfp['uuid'],
                            'serial_number' => $sfp['serial_number'] ?? null
                        ];
                    }
                }

                $nic['port_mapping'] = $portMapping;
            }
            unset($nic); // break reference

            return $result;

        } catch (Exception $e) {
            error_log("Error getting network configuration: " . $e->getMessage());
            return [
                'summary' => [
                    'total_ports' => 0,
                    'onboard_ports' => 0,
                    'component_ports' => 0,
                    'total_nics' => 0,
                    'onboard_nics' => 0,
                    'component_nics' => 0
                ],
                'nics' => [],
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }


    /**
     * Auto-assign an available SFP port on a NIC
     * Determines port count from NIC specs and returns first unoccupied port
     *
     * @param string $nicUuid       NIC UUID to assign port on
     * @param string $configUuid    Server configuration UUID (used to read installed SFPs)
     * @return int|null             First available port index (1-based), or null if all occupied
     */
    public function autoAssignSFPPort($nicUuid, $configUuid) {
        try {
            // Step 1: Determine the total port count for this NIC
            $portCount = 0;

            $stmt = $this->pdo->prepare("SELECT SourceType, ParentComponentUUID, OnboardNICIndex FROM nicinventory WHERE UUID = ? LIMIT 1");
            $stmt->execute([$nicUuid]);
            $nicRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($nicRow && ($nicRow['SourceType'] === 'onboard') && !empty($nicRow['ParentComponentUUID'])) {
                // Onboard NIC: get port count from parent motherboard JSON
                $dataService = ComponentDataService::getInstance();
                $mbSpecs = $dataService->findComponentByUuid('motherboard', $nicRow['ParentComponentUUID']);
                $onboardIndex = (int)($nicRow['OnboardNICIndex'] ?? 1);
                $onboardNics = $mbSpecs['networking']['onboard_nics'] ?? [];
                $nicSpec = $onboardNics[$onboardIndex - 1] ?? null;
                $portCount = (int)($nicSpec['ports'] ?? 0);
            } else {
                // Regular component NIC: get port count from NIC JSON
                $dataService = ComponentDataService::getInstance();
                $nicSpecs = $dataService->getComponentSpecifications('nic', $nicUuid);
                $portCount = (int)($nicSpecs['ports'] ?? 0);
            }

            if ($portCount < 1) {
                error_log("autoAssignSFPPort: could not determine port count for NIC $nicUuid");
                return null;
            }

            // Step 2: Find which ports are already occupied on this NIC.
            // U-D.3b: from config_components rows. An SFP row's slot_ref IS its port
            // ("port_3"), so occupancy is read from the same field the writer sets.
            //
            // NOT relying on uq_slot_occupancy to enforce this. That index is
            // (config_uuid, slot_ref, removed_at) and every LIVE row has removed_at
            // NULL, which MariaDB treats as distinct -- so it accepts two live rows in
            // one slot and only ever constrains tombstones sharing a timestamp. Probed
            // 2026-08-30; it does not do what its name says. This loop is the check.
            $occupiedPorts = [];
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                foreach ($this->componentsFromRows($row) as $entry) {
                    if (($entry['component_type'] ?? null) !== 'sfp') { continue; }
                    if (($entry['parent_nic_uuid'] ?? null) !== $nicUuid) { continue; }
                    if (($entry['port_index'] ?? null) === null) { continue; }
                    $occupiedPorts[] = (int)$entry['port_index'];
                }
            }

            // Step 3: Return first port not in occupied list
            for ($port = 1; $port <= $portCount; $port++) {
                if (!in_array($port, $occupiedPorts)) {
                    return $port;
                }
            }

            error_log("autoAssignSFPPort: all $portCount port(s) occupied on NIC $nicUuid");
            return null;

        } catch (Exception $e) {
            error_log("autoAssignSFPPort error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get complete configuration details with proper component handling
     * Now reads components from JSON columns in server_configurations table
     */
    public function getConfigurationDetails($configUuid) {
        try {
            // Try cache first if available
            if ($this->configCache !== null) {
                $cached = $this->configCache->getConfiguration($configUuid);
                if ($cached !== null) {
                    return $cached;
                }
            }

            // Get base configuration
            $stmt = $this->pdo->prepare("
                SELECT sc.*, u.username as created_by_username
                FROM server_configurations sc
                LEFT JOIN users u ON sc.created_by = u.id
                WHERE sc.config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $configData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$configData) {
                return [
                    'config_uuid' => $configUuid,
                    'error' => 'Configuration not found'
                ];
            }

            // U-X.1: the ONE routed read entrypoint. READ_FROM_ROWS decides the
            // source (off = this JSON extraction verbatim, sample = compare both and
            // still return legacy, on = config_components rows mapped to this shape).
            // The cache check above short-circuits before this line, so a cached read
            // never reaches the router and no mode can poison a cache entry.
            //
            // MUTATION-PATH CALLERS THAT STAY DIRECT (until U-D.3 drops the JSON
            // columns) -- 13 in this file, verified by grep at the time of writing:
            // 1313, 1665, 3892, 4438, 4608, 5092, 5110, 5123, 5305, 5663, 6133, 7605,
            // 8900, plus api/handlers/server/server_api.php:1313,
            // and the analysis-only readers (TargetStateBuilder, fleet_parity_sweep,
            // characterize_compatibility, serverstate_equivalence, Extractor,
            // audit-orphans). Those are add/remove/validate/finalize paths and
            // harnesses; routing them is a separate unit's decision, not a side effect
            // of this one. getConfigComponents() (5871) is deliberately NOT routed --
            // U-X.1-PLAN-20260712.md §2 established it is a second, independent
            // JSON decoder used only by the virtual-config import mutation, with a
            // different output shape ('uuid' not 'component_uuid'), no name
            // enrichment, and it silently drops onboard NICs and SFPs.
            $components = ConfigReadRouter::components($this, $this->pdo, $configData);

            // Build simplified component information
            $componentDetails = [];
            $componentCounts = [];
            $totalComponents = 0;
            $assignedSerials = []; // Track to prevent duplicates

            foreach ($components as $component) {
                $type = $component['component_type'];
                $uuid = $component['component_uuid'];

                if (!isset($componentDetails[$type])) {
                    $componentDetails[$type] = [];
                    $componentCounts[$type] = 0;
                }

                // Get serial number from inventory table (fallback only), excluding already assigned ones
                $excludeSerials = $assignedSerials[$type] ?? [];
                $inventoryDetails = $this->getComponentDetails($type, $uuid, $configUuid, $excludeSerials);

                // CRITICAL: Use serial_number from JSON first (already stored when component was added)
                // Only fall back to inventory query if not present in JSON
                //
                // A unit with no manufacturer serial stays null here. This used to
                // substitute the display string 'Not Found', which the frontend read back
                // as a real serial (its `comp.serial_number || null` guard only catches
                // falsy values) and posted to server-remove-component, where it matched no
                // row -- "Component not found in configuration with SerialNumber
                // 'Not Found'". Display copy belongs in the renderer, not in a data field.
                $serialNumber = $component['serial_number'] ?? $inventoryDetails['SerialNumber'] ?? null;

                // Track assigned serial to avoid duplicates when multiple identical components exist
                if ($serialNumber !== null && strpos($serialNumber, 'VIRTUAL-') !== 0) {
                    if (!isset($assignedSerials[$type])) {
                        $assignedSerials[$type] = [];
                    }
                    $assignedSerials[$type][] = $serialNumber;
                }

                $simplifiedComponent = [
                    'uuid' => $uuid,
                    'serial_number' => $serialNumber,
                    'component_name' => $this->getComponentNameFromSpec($type, $uuid),
                    'quantity' => $component['quantity'],
                    'added_at' => $component['added_at']
                ];

                // Include connection data for storage components (with lazy migration)
                if ($type === 'storage') {
                    $storedConnection = $component['connection'] ?? null;
                    $storedType = $storedConnection['type'] ?? 'not_connected';
                    if (!empty($storedConnection) && $storedType !== 'not_connected') {
                        $simplifiedComponent['connection'] = $storedConnection;
                    } else {
                        // Recompute: either missing or stored as not_connected (lazy migration for
                        // storage added before chassis). Bay number is position-based so each
                        // storage gets a distinct sequential bay slot.
                        $bayNumber = count($componentDetails[$type] ?? []) + 1;
                        $simplifiedComponent['connection'] = $this->computeStorageConnectionPath($configUuid, $uuid, $bayNumber);
                    }
                }

                // Include parent NIC mapping for SFP components
                if ($type === 'sfp') {
                    if (!empty($component['parent_nic_uuid'])) {
                        $simplifiedComponent['parent_nic_uuid'] = $component['parent_nic_uuid'];
                    }
                    if (isset($component['port_index'])) {
                        $simplifiedComponent['port_index'] = $component['port_index'];
                    }
                }

                $componentDetails[$type][] = $simplifiedComponent;
                $componentCounts[$type] += $component['quantity'];
                $totalComponents += $component['quantity'];
            }

            // Use stored power consumption from database
            $totalPowerConsumptionWithOverhead = $configData['power_consumption'] ?? 0;
            $configData['power_consumption'] = round($totalPowerConsumptionWithOverhead, 2);

            // Parse validation_results from JSON if it exists
            if (!empty($configData['validation_results'])) {
                $configData['validation_results'] = json_decode($configData['validation_results'], true);
            }

            // Build result
            $result = [
                'configuration' => $configData,
                'components' => $componentDetails,
                'component_counts' => $componentCounts,
                'total_components' => $totalComponents,
                'power_consumption' => [
                    'total_watts' => round($totalPowerConsumptionWithOverhead / 1.2, 2),
                    'total_with_overhead_watts' => round($totalPowerConsumptionWithOverhead, 2),
                    'overhead_percentage' => 20
                ],
                'configuration_status' => $configData['configuration_status'],
                'server_name' => $configData['server_name'],
                'created_at' => $configData['created_at'],
                'updated_at' => $configData['updated_at']
            ];

            // Store in cache before returning (if available)
            if ($this->configCache !== null) {
                $this->configCache->setConfiguration($configUuid, $result);
            }

            return $result;

        } catch (Exception $e) {
            error_log("Error getting configuration details: " . $e->getMessage());
            return [
                'config_uuid' => $configUuid,
                'error' => 'Failed to load configuration details: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Persist the SCALAR component columns on server_configurations.
     *
     * U-D.3a: this used to fan out to eight JSON-column updaters as well
     * (updateCpuConfiguration and its siblings). Those columns are retired and the
     * updaters are deleted; config_components is the record, written by
     * ConfigComponentRepository in the same transaction as this call.
     *
     * What survives is motherboard_uuid, chassis_uuid, and the platform fields that
     * hang off the board -- plain scalars the pack rules OUT of the drop (see the scope
     * decision in tasks/u-d3-json-column-retirement.md) because they are read far more
     * widely than the JSON set and nothing in P9 moved them.
     *
     * Every other component type is now a no-op here. That is the point, not an
     * omission: for those types the row IS the record.
     */
    /** U-C.2/U-C.3: exposed as a library call for the command layer (reuse legacy persistence, don't reimplement). */
    public function updateServerConfigurationTable($configUuid, $componentType, $componentUuid, $quantity, $action, $serialNumber = null, $options = []) {
        try {
            $updateFields = [];
            $updateValues = [];

            switch ($componentType) {
                case 'chassis':
                    // Chassis is stored in chassis_uuid column (similar to motherboard)
                    if ($action === 'add') {
                        $updateFields[] = "chassis_uuid = ?";
                        $updateValues[] = $componentUuid;
                    } elseif ($action === 'remove') {
                        $updateFields[] = "chassis_uuid = NULL";
                    }
                    break;

                case 'motherboard':
                    if ($action === 'add') {
                        $updateFields[] = "motherboard_uuid = ?";
                        $updateValues[] = $componentUuid;

                        // Onboard NICs are materialized by OnboardNICHandler at the
                        // add-component call sites (ServerBuilder::addComponent and
                        // AddComponentCommand::apply), which have the locked physical
                        // inventory row this table-update method does not.
                    } elseif ($action === 'remove') {
                        $updateFields[] = "motherboard_uuid = NULL";
                        // The compute platform is a property of the installed system
                        // board, so it cannot outlive it — otherwise a boardless config
                        // keeps reporting a platform it no longer has.
                        $updateFields[] = "platform_uuid = NULL";
                        $updateFields[] = "platform_name = NULL";
                    }
                    break;

            }

            if (!empty($updateFields)) {
                $sql = "UPDATE server_configurations SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE config_uuid = ?";
                $updateValues[] = $configUuid;
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($updateValues);
            }
            
        } catch (\Throwable $e) {
            error_log("Error updating server configuration table: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Compute storage connection path using StorageConnectionValidator
     */
    private function computeStorageConnectionPath($configUuid, $storageUuid, $bayNumber = null) {
        try {
            require_once __DIR__ . '/../compatibility/StorageConnectionValidator.php';
            $storageValidator = new StorageConnectionValidator($this->pdo);
            $existingComponents = $this->getExistingComponentsForValidation($configUuid);

            $validation = $storageValidator->validate(
                $configUuid,
                $storageUuid,
                $this->existingComponentsExcludingStorage($existingComponents, $storageUuid)
            );

            if ($validation['valid'] && isset($validation['primary_path'])) {
                $path = $validation['primary_path'];
                $details = $path['details'] ?? [];

                // Use caller-supplied bay number (position-based, avoids duplication when recomputing
                // for existing storage). Fall back to count+1 for the add-component flow where
                // the new storage is not yet in existingComponents.
                if ($bayNumber === null) {
                    $existingStorageCount = count($existingComponents['storage'] ?? []);
                    $bayNumber = $existingStorageCount + 1;
                }

                return [
                    'type' => $path['type'],
                    'bay_number' => $bayNumber,
                    'controller_uuid' => $details['chassis_uuid'] ?? $details['hba_uuid'] ?? null,
                    'backplane_interface' => $details['backplane_interface'] ?? null,
                    'storage_interface' => $details['storage_interface'] ?? null,
                    'compatibility_type' => $details['compatibility_type'] ?? null,
                    'description' => $path['description'] ?? null
                ];
            }

            return ['type' => 'not_connected'];
        } catch (Exception $e) {
            error_log("Error computing storage connection path: " . $e->getMessage());
            return ['type' => 'not_connected'];
        }
    }

    /**
     * Update configuration metrics (power, compatibility, validation)
     */
    private function updateConfigurationMetrics($configUuid) {
        try {
            $details = $this->getConfigurationSummary($configUuid);

            $totalPower = 0;
            foreach ($details['components'] ?? [] as $type => $components) {
                foreach ($components as $component) {
                    // Fetch component specs from JSON using UUID
                    $componentUuid = $component['uuid'] ?? null;
                    if (!$componentUuid) {
                        continue;
                    }

                    $power = $this->calculateComponentPowerFromJSON($type, $componentUuid);
                    $totalPower += $power * ($component['quantity'] ?? 1);
                }
            }

            $totalPowerWithOverhead = $totalPower * 1.2;

            // Update the configuration. Power only: the hardware compatibility score
            // was removed on 2026-08-23 (owner decision). Compatibility is decided by
            // the ValidationEngine registry, which returns per-rule verdicts rather
            // than one blended number, so the score had no consumer: nothing read
            // server_configurations.compatibility_score and no API returned it. The
            // column is left in place and its existing values are historical.
            $this->updateConfigurationCalculatedFields($configUuid, $totalPowerWithOverhead);

        } catch (Exception $e) {
            error_log("Error updating configuration metrics: " . $e->getMessage());
        }
    }
    
    /**
     * Update calculated fields in configuration
     */
    private function updateConfigurationCalculatedFields($configUuid, $powerConsumption) {
        try {
            // Power only. The compatibility score this method used to persist was
            // removed on 2026-08-23 (owner decision), and with it the F-17 column
            // probe: that probe existed solely because compatibility_score rode in
            // the SAME statement as power_consumption, so a missing column made the
            // UPDATE fail with 1054 and silently stopped power being written too.
            // With one column left there is nothing to guard.
            $sql = "UPDATE server_configurations SET power_consumption = ?, updated_at = NOW() WHERE config_uuid = ?";
            $params = [$powerConsumption, $configUuid];

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

        } catch (Exception $e) {
            error_log("Error updating calculated fields: " . $e->getMessage());
        }
    }

    /**
     * Get configuration summary - backwards compatibility
     */
    public function getConfigurationSummary($configUuid) {
        $details = $this->getConfigurationDetails($configUuid);
        
        // Return summary format for backwards compatibility
        return [
            'config_uuid' => $configUuid,
            'components' => $details['components'] ?? [],
            'component_counts' => $details['component_counts'] ?? [],
            'total_components' => $details['total_components'] ?? 0,
            'server_name' => $details['server_name'] ?? '',
            'configuration_status' => $details['configuration_status'] ?? 0,
            'error' => $details['error'] ?? null
        ];
    }
    




    /**
     * Finalize configuration
     */
    public function finalizeConfiguration($configUuid, $notes = '', $userId = 0) {
        // A compatibility bench build is an experiment, not a server. Finalizing marks
        // components in_use and locks the config as a real deployment, which is exactly
        // what the bench promises never to do -- refuse ahead of BOTH the command-layer
        // and legacy paths below so no flag state can route around it.
        if ($this->isSandboxConfig($configUuid)) {
            return [
                'success' => false,
                'error_type' => 'sandbox_config',
                'message' => 'This is a compatibility bench build and cannot be finalized. '
                    . 'Bench builds reserve no hardware by design — rebuild it as a real server to deploy it.'
            ];
        }

        // U-C.5 / P9: finalize delegates entirely to TransitionStatusCommand,
        // which runs the full Trigger::FINALIZE evaluation under the SAME lock as
        // the status write (closing V-1 structurally). The legacy body that used
        // to follow -- its own transaction, its StateGuard gate, its weak
        // validateConfiguration() check and the comprehensive re-check layered on
        // top -- was reachable only at COMMAND_LAYER_ENABLED=off/shadow and went
        // with the flag.
        require_once __DIR__ . '/../commands/BaseCommand.php';
        require_once __DIR__ . '/../commands/TransitionStatusCommand.php';
        try {
            $command = new TransitionStatusCommand($this->pdo, $configUuid, 'finalized', $notes, $userId);
            $command->execute();
            return [
                'success' => true,
                'message' => 'Configuration finalized successfully',
                'finalization_timestamp' => date('Y-m-d H:i:s'),
            ];
        } catch (CommandFailed $e) {
            return ['success' => false, 'error_type' => $e->errorType, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Release every physical unit bound to a configuration back to available stock.
     *
     * Driven by the inventory rows' own ServerUUID, NOT by the config JSON.
     * extractComponentsFromJson() does not carry serial_number for every type
     * (motherboard and chassis among those it omits), so a JSON-driven release passed
     * $serialNumber = null for those types, collapsing
     * updateComponentStatusAndServerUuid()'s WHERE to `UUID = ?` alone -- which frees
     * EVERY physical unit sharing that model UUID, in every other configuration. That is
     * how motherboards 49/53/55 (model 4c8f5e1b, three different configs) were all
     * released by a single delete at 2026-07-20 22:48:46.
     *
     * ServerUUID is the authoritative record of which PHYSICAL unit belongs to this
     * config, so releasing by it is unit-precise by construction, and also covers
     * quantity>1 entries and units missing from the JSON.
     *
     * A-P4: ONE statement per table rather than a SELECT plus a per-unit SELECT+UPDATE.
     * A 30-component server cost ~80 round-trips; it now costs one per table. The
     * release is unconditional and identical for every bound unit, and ServerUUID is
     * already the exact per-unit predicate, so nothing is lost by doing it in bulk --
     * including the ambiguity guard, which only ever mattered for UUID-keyed writes.
     *
     * serverplatforminventory is included even though it is not in $componentTables:
     * the compute platform is a stocked box like any other unit and must go back on the
     * shelf, but it is deliberately NOT a buildable component type (nothing may
     * add-component it). A table that does not exist yet is skipped rather than fatal --
     * code deploys ~20s after a save while seeders are run by hand.
     *
     * MUST be called inside a transaction.
     *
     * @return int units released
     */
    public function releaseAllComponents($configUuid) {
        require_once __DIR__ . '/../state/StatusMap.php';
        $statusV2 = StatusMap::INVENTORY_LEGACY_TO_V2[1] ?? null;

        $tables = array_values($this->componentTables);
        $tables[] = 'serverplatforminventory';

        $released = 0;
        foreach ($tables as $table) {
            $setV2 = ($statusV2 !== null) ? ", status_v2 = ?" : "";
            $sql = "UPDATE `$table`
                    SET Status = 1{$setV2}, ServerUUID = NULL, InstallationDate = NULL,
                        RackPosition = NULL, UpdatedAt = NOW()
                    WHERE ServerUUID = ?";
            $params = ($statusV2 !== null) ? [$statusV2, $configUuid] : [$configUuid];

            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $released += $stmt->rowCount();
            } catch (\Throwable $e) {
                if ($table === 'serverplatforminventory') {
                    // Seeder 2026_08_25_002 not applied yet on this database.
                    error_log('releaseAllComponents: skipping ' . $table . ' - ' . $e->getMessage());
                    continue;
                }
                throw $e;
            }
        }

        return $released;
    }

    /**
     * Empty a configuration: release every unit AND clear what the config records.
     *
     * This is the shared primitive behind both ways a compute platform can change --
     * removing it, and installing a different one over an existing build. The user was
     * explicit that either one releases the WHOLE build, not just the platform's own
     * parts: a board and chassis that came out of a different product cannot be trusted
     * to fit the CPUs, DIMMs and drives that were chosen around them.
     *
     * config_events is deliberately NOT deleted -- it is the audit trail of what
     * happened to this configuration, and the configuration still exists.
     *
     * MUST be called inside a transaction.
     *
     * @return int units released
     */
    public function clearConfigurationComponents($configUuid) {
        $released = $this->releaseAllComponents($configUuid);

        $stmt = $this->pdo->prepare("DELETE FROM config_resources WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        $this->purgeConfigComponentRows($configUuid);

        // U-D.3a: the nine JSON columns are gone from this reset with the writers that
        // filled them. purgeConfigComponentRows() above is what actually empties the
        // build now. The scalars stay -- they are not part of the drop.
        $stmt = $this->pdo->prepare("
            UPDATE server_configurations
               SET motherboard_uuid = NULL,
                   chassis_uuid = NULL,
                   power_consumption = NULL,
                   compatibility_score = NULL,
                   validation_results = NULL,
                   updated_at = NOW()
             WHERE config_uuid = ?
        ");
        $stmt->execute([$configUuid]);

        return $released;
    }

    /**
     * Delete configuration
     *
     * Refuses to delete a server that still has components installed: pulling
     * the config out from under them is a bulk inventory mutation disguised as
     * a delete, and the user gets no say in which units are freed. They must
     * remove the components first, which is the path that runs the normal
     * per-component validation and logging.
     *
     * $force bypasses that guard and restores the old release-everything
     * behaviour. It exists so a config whose components cannot be removed for
     * some other reason can never become undeletable; nothing in the UI sets it.
     */
    public function deleteConfiguration($configUuid, $force = false) {
        // RACE CONDITION FIX: Initialize transaction control early
        $ownTransaction = false;
        $releasedCount = 0;

        try {
            $ownTransaction = !$this->pdo->inTransaction();
            if ($ownTransaction) {
                $this->pdo->beginTransaction();
            }

            // RACE CONDITION FIX (Phase 1): Lock the configuration row before
            // reading its components list. Prevents a concurrent add/remove
            // from mutating the JSON between the read below and the final
            // DELETE, which would otherwise leak an inventory row (component
            // added mid-delete stays flagged as in_use forever).
            $configData = $this->lockAndLoadConfigRow($configUuid);

            if ($configData) {
                $installed = $this->summarizeInstalledComponents($configUuid, $configData);

                if ($installed['total'] > 0 && !$force) {
                    if ($ownTransaction && $this->pdo->inTransaction()) {
                        $this->pdo->rollback();
                    }
                    return [
                        'success' => false,
                        'reason' => 'components_installed',
                        'message' => 'This server still has ' . $installed['total'] . ' component'
                            . ($installed['total'] === 1 ? '' : 's') . ' installed ('
                            . $installed['summary'] . '). Remove all components from the server '
                            . 'before deleting it.',
                        'installed_total' => $installed['total'],
                        'installed_components' => $installed['by_type']
                    ];
                }

                // Release every bound unit back to available stock. The rationale for
                // driving this off ServerUUID rather than the config JSON, and for doing
                // it in one statement per table, lives on releaseAllComponents().
                $releasedCount += $this->releaseAllComponents($configUuid);
            }

            // Note: legacy component data lives in JSON columns, no separate table
            // to delete for that. BUT U-1.5/U-L.2's dual-write tables (config_events,
            // config_components, config_resources) are real FK children of
            // server_configurations now that DUAL_WRITE_ENABLED can be 'on' -- this
            // delete predates that schema and never cleaned them up, which throws an
            // FK violation on any config that actually picked up dual-write rows.
            // Order matters: config_resources first (fk_cr_consumer is ON DELETE
            // RESTRICT against config_components), then config_events and
            // config_components (both have a real, non-cascading FK to
            // server_configurations.config_uuid), then the parent row. Harmless
            // no-ops when the flag was never on for this config.
            $stmt = $this->pdo->prepare("DELETE FROM config_resources WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $stmt = $this->pdo->prepare("DELETE FROM config_events WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $this->purgeConfigComponentRows($configUuid);

            // Delete configuration history if exists
            try {
                $stmt = $this->pdo->prepare("DELETE FROM server_configuration_history WHERE config_uuid = ?");
                $stmt->execute([$configUuid]);
            } catch (Exception $historyError) {
                error_log("Could not delete history (table might not exist): " . $historyError->getMessage());
            }

            // The rack placement is a logical FK only (rack_servers has no real
            // constraint against server_configurations), so nothing cascades and
            // deleting the config used to leave the placement behind. Rack View
            // then rendered that orphan as "(deleted server)" and, worse, kept
            // counting its U as occupied -- a slot no one could free from the UI.
            // Same transaction as the config delete: the two are one fact.
            $stmt = $this->pdo->prepare("DELETE FROM rack_servers WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);

            // Delete configuration
            $stmt = $this->pdo->prepare("DELETE FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }

            return [
                'success' => true,
                'message' => "Configuration deleted successfully",
                'components_released' => $releasedCount
            ];

        } catch (Exception $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollback();
            }
            // Never hand the raw driver message back: it carries the production
            // database name and constraint internals (hard rule #8).
            error_log("Error deleting configuration $configUuid: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to delete configuration. The error has been logged."
            ];
        }
    }

    /**
     * What is still physically installed in a config, for the delete guard.
     *
     * Counts BOTH sources and takes the larger per type, because they can
     * disagree and either one alone would under-report: the inventory rows'
     * ServerUUID is what the release path acts on, while the config JSON is
     * what the builder UI renders. A unit whose ServerUUID drifted (F-1
     * collateral) shows only in the JSON; one missing from the JSON shows only
     * in inventory. Blocking on the larger count is the fail-safe direction --
     * worst case the user is asked to remove something already gone, which is
     * visible and recoverable, rather than silently losing units to a delete.
     *
     * @return array{total:int, by_type:array<string,int>, summary:string}
     */
    public function summarizeInstalledComponents($configUuid, $configData) {
        $fromInventory = [];
        foreach ($this->componentTables as $componentType => $table) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE ServerUUID = ?");
            $stmt->execute([$configUuid]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                $fromInventory[$componentType] = $count;
            }
        }

        $fromJson = [];
        foreach ($this->componentsFromRows($configData) as $component) {
            $type = $component['component_type'] ?? null;
            if ($type === null || empty($component['component_uuid'])) {
                continue;
            }
            $quantity = (int)($component['quantity'] ?? 1);
            $fromJson[$type] = ($fromJson[$type] ?? 0) + max(1, $quantity);
        }

        $labels = [
            'cpu' => 'CPU', 'ram' => 'RAM', 'storage' => 'storage', 'motherboard' => 'motherboard',
            'nic' => 'network card', 'caddy' => 'caddy', 'chassis' => 'chassis',
            'pciecard' => 'PCIe card', 'risercard' => 'riser card', 'hbacard' => 'HBA card', 'sfp' => 'SFP module'
        ];

        $byType = [];
        $parts = [];
        $total = 0;
        foreach ($this->componentTables as $componentType => $unusedTable) {
            $count = max($fromInventory[$componentType] ?? 0, $fromJson[$componentType] ?? 0);
            if ($count === 0) {
                continue;
            }
            $byType[$componentType] = $count;
            $total += $count;
            $parts[] = $count . ' ' . ($labels[$componentType] ?? $componentType) . ($count === 1 ? '' : 's');
        }

        return [
            'total'    => $total,
            'by_type'  => $byType,
            'summary'  => implode(', ', $parts)
        ];
    }

    /**
     * Delete a config's config_components rows in an FK-safe order.
     *
     * Two things make the obvious `DELETE ... WHERE config_uuid = ?` unsafe:
     *
     * 1. parent_id is self-referential and fk_cc_parent has no ON DELETE clause
     *    (RESTRICT). A single statement gives MySQL no child-before-parent
     *    ordering, so it fails or succeeds depending on the order rows happen to
     *    be visited -- the same defect already fixed in
     *    scripts/backfill/backfill.php (see its rollbackRun() comment).
     *
     * 2. Rows can be referenced from OTHER configs. uq_inventory_once is keyed on
     *    the physical unit rather than the placement, so a unit moving between
     *    configs keeps its row id and takes it to the new config, stranding the
     *    old config's children on a pointer into a config they don't belong to.
     *    That is how deleting a server showing ZERO components still threw
     *    fk_cc_parent. The stale config_resources rows behind fk_cr_consumer
     *    (RESTRICT -- blocks) and fk_cr_provider (CASCADE -- silently eats
     *    another config's ledger) are the same problem one table over, so they
     *    are cleared explicitly instead of left to the constraints.
     *
     * ConfigComponentRepository::insert() now prevents new strandings; this
     * handles rows stranded before that fix, and the intra-config ordering.
     */
    private function purgeConfigComponentRows($configUuid) {
        $stmt = $this->pdo->prepare("SELECT id FROM config_components WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!$ids) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->pdo->prepare(
            "DELETE FROM config_resources
              WHERE provider_id IN ($placeholders) OR consumer_id IN ($placeholders)"
        );
        $stmt->execute(array_merge($ids, $ids));

        require_once __DIR__ . '/../config/ConfigComponentRepository.php';
        $repo = new ConfigComponentRepository($this->pdo);
        $repo->repointChildrenAwayFrom($ids, $configUuid);

        // Peel off leaves until nothing is left. The parent chain here is at most
        // motherboard -> nic -> sfp, so this converges in a couple of passes; the
        // bound is only there so a cycle in the data cannot spin forever.
        $deleteLeaves = $this->pdo->prepare(
            "DELETE target FROM config_components target
               LEFT JOIN config_components child ON child.parent_id = target.id
              WHERE target.config_uuid = ? AND child.id IS NULL"
        );
        $deleted = 0;
        for ($pass = 0; $pass < 10; $pass++) {
            $deleteLeaves->execute([$configUuid]);
            $removed = $deleteLeaves->rowCount();
            if ($removed === 0) {
                break;
            }
            $deleted += $removed;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM config_components WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        $remaining = (int)$stmt->fetchColumn();
        if ($remaining > 0) {
            // Something outside this config still depends on these rows in a way
            // the repair above did not cover. Fail loudly inside the transaction
            // rather than leave a half-deleted config behind.
            throw new RuntimeException(
                "Cannot delete config $configUuid: $remaining component row(s) are still "
                . "referenced by another configuration"
            );
        }

        return $deleted;
    }

    // Private helper methods
    
    /**
     * Check if component UUID already exists in configuration
     * RACE CONDITION FIX: Now locks configuration row with FOR UPDATE
     *
     * IMPORTANT: This method MUST be called within a transaction (started in addComponent)
     * The FOR UPDATE lock prevents concurrent modifications to the configuration JSON
     *
     * @param string $configUuid Configuration UUID
     * @param string $componentUuid Component UUID to check
     * @param string|null $serialNumber Optional serial number for physical component identification
     * @return bool True if duplicate found, false otherwise
     */
    

    
    /**
     * Comprehensive component validation before adding - consolidates all validation logic
     * Phase 2 Consolidation: Moves SFP, riser, singleton, and compatibility validation from handler
     */




    /**
     * Generate UUID for configuration
     */
    private function generateUuid() {
        // A-L14: mt_rand() is a seeded Mersenne Twister, not a CSPRNG. config_uuid is
        // the primary external handle for a configuration and travels in API
        // parameters, so it must not be predictable from observed values.
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    /**
     * Check if component type is valid
     * U-C.2: exposed as a library call for the command layer.
     */
    public function isValidComponentType($componentType) {
        return isset($this->componentTables[$componentType]);
    }
    
    /**
     * Check if component can only have single instance in configuration
     */
    private function isSingleInstanceComponent($componentType) {
        return in_array($componentType, ['chassis', 'motherboard']);
    }

    
    /**
     * Get component by UUID with improved error handling
     */
    private function getComponentByUuid($componentType, $componentUuid) {
        if (!isset($this->componentTables[$componentType])) {
            error_log("Invalid component type: $componentType");
            return null;
        }

        try {
            $table = $this->componentTables[$componentType];

            // CRITICAL FIX: Prioritize available components (Status=1) when multiple components share same UUID
            // This ensures we select an available component instead of a random one

            // Step 1: Try to get an available component (Status=1) first
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ? AND Status = 1 LIMIT 1");
            $stmt->execute([$componentUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return $result;
            }

            // Step 2: If no available component, try case-insensitive match with Status=1
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE TRIM(UPPER(UUID)) = UPPER(TRIM(?)) AND Status = 1 LIMIT 1");
            $stmt->execute([$componentUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return $result;
            }

            // Step 3: Fallback - get any component with this UUID for validation/error messages
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ? LIMIT 1");
            $stmt->execute([$componentUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return $result;
            }

            // Step 4: Final fallback - case-insensitive any status
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE TRIM(UPPER(UUID)) = UPPER(TRIM(?)) LIMIT 1");
            $stmt->execute([$componentUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result;

        } catch (Exception $e) {
            error_log("Error getting component by UUID from {$this->componentTables[$componentType]}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Does server_configurations.is_sandbox exist yet?
     *
     * Seeders are applied by hand on this deployment, while code auto-uploads in ~20s,
     * so every schema-adding feature has a live window where the new column is absent.
     * Callers use this to degrade to pre-seeder behaviour instead of 500ing.
     *
     * Cached per request: one SHOW COLUMNS, not one per config row. information_schema
     * is deliberately not used -- the application DB user cannot read it on this host.
     *
     * Fails to FALSE (column absent) so an unreadable schema degrades to the old,
     * known-good behaviour rather than to a broken query.
     */
    public static function sandboxColumnExists(PDO $pdo) {
        static $exists = null;

        if ($exists === null) {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM `server_configurations` LIKE 'is_sandbox'");
                $exists = ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) ? true : false;
            } catch (Exception $e) {
                error_log("sandboxColumnExists check failed: " . $e->getMessage());
                $exists = false;
            }
        }

        return $exists;
    }

    /**
     * Check if a server configuration is a compatibility bench build.
     *
     * Sandbox implies virtual (createConfiguration() forces it), so this is a strictly
     * narrower question than isVirtualConfig(): saved templates are virtual but NOT
     * sandboxes, and must keep behaving exactly as they always have.
     *
     * Fails CLOSED on error -- an unreadable flag must not be read as "safe to finalize".
     */
    private function isSandboxConfig($configUuid) {
        // No column means seeder 2026_08_18_003 has not been applied, which means no
        // sandbox has ever been created -- so "not a sandbox" is the accurate answer,
        // not a guess. Failing closed here would have blocked finalizing EVERY real
        // server for as long as the seeder went unapplied.
        if (!self::sandboxColumnExists($this->pdo)) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT is_sandbox FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (bool)$result['is_sandbox'] : false;
        } catch (Exception $e) {
            error_log("Error checking is_sandbox: " . $e->getMessage());
            return true;
        }
    }

    /**
     * Check if a server configuration is virtual/test mode
     */
    private function isVirtualConfig($configUuid) {
        try {
            $stmt = $this->pdo->prepare("SELECT is_virtual FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (bool)$result['is_virtual'] : false;
        } catch (Exception $e) {
            error_log("Error checking is_virtual: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all components from a server configuration (all JSON columns)
     * Used for importing virtual configs to real configs
     */
    public function getConfigComponents($configUuid) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                return null;
            }

            // U-D.3b: from config_components rows. This was the LAST of the three
            // independent JSON decoders (U-X.1-PLAN-20260712.md §2 catalogued it as a
            // second decoder deliberately left unrouted); it is routed now because the
            // columns it decoded are going away.
            //
            // Its two deliberate omissions are preserved, because this feeds the
            // virtual-template import and both are things the import must NOT copy:
            //   - onboard NICs, which OnboardNICHandler materialises from whatever board
            //     the new build actually gets, and
            //   - SFPs, which are seated into a NIC port that does not exist yet.
            // Its output keys ('uuid', not 'component_uuid') are preserved too, so
            // handleImportVirtual() is untouched.
            $components = [];
            foreach ($this->componentsFromRows($config) as $entry) {
                $type = $entry['component_type'] ?? null;
                if ($type === null || empty($entry['component_uuid'])) {
                    continue;
                }
                if ($type === 'sfp') {
                    continue;
                }
                if ($type === 'nic' && ($entry['source_type'] ?? 'component') !== 'component') {
                    continue;
                }

                $component = [
                    'component_type' => $type,
                    'uuid'           => $entry['component_uuid'],
                    'quantity'       => $entry['quantity'] ?? 1,
                ];
                // The old body emitted these two for some types and not others. Emitting
                // them wherever the row HAS them is a superset, and the importer reads
                // both with ?? null, so nothing downstream changes shape.
                if (array_key_exists('serial_number', $entry)) {
                    $component['serial_number'] = $entry['serial_number'];
                }
                if (array_key_exists('slot_position', $entry)) {
                    $component['slot_position'] = $entry['slot_position'];
                }
                $components[] = $component;
            }

            return $components;

        } catch (Exception $e) {
            error_log("Error getting config components: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find first available component with matching UUID (Status=1)
     * Returns component details or null if not found
     */
    public function findAvailableComponent($componentType, $uuid) {
        try {
            $tableName = $this->getComponentInventoryTable($componentType);
            if (!$tableName) {
                return null;
            }

            $stmt = $this->pdo->prepare("
                SELECT * FROM $tableName
                WHERE UUID = ? AND Status = 1
                LIMIT 1
            ");
            $stmt->execute([$uuid]);
            $component = $stmt->fetch(PDO::FETCH_ASSOC);

            return $component ?: null;

        } catch (Exception $e) {
            error_log("Error finding available component: " . $e->getMessage());
            return null;
        }
    }

    /**
     * FIXED: Check component availability with ServerUUID context
     * UPDATED: Bypass availability check for virtual configs
     */
    private function checkComponentAvailability($componentDetails, $configUuid, $options = []) {
        // Virtual configs don't need availability checks
        if ($this->isVirtualConfig($configUuid)) {
            return [
                'available' => true,
                'status' => $componentDetails['Status'] ?? null,
                'server_uuid' => $componentDetails['ServerUUID'] ?? null,
                'message' => 'Virtual configuration - availability checks bypassed',
                'can_override' => false,
                'is_virtual' => true
            ];
        }

        $status = (int)$componentDetails['Status'];
        $serverUuid = $componentDetails['ServerUUID'] ?? null;
        
        $result = [
            'available' => false,
            'status' => $status,
            'server_uuid' => $serverUuid,
            'message' => '',
            'can_override' => false
        ];
        
        switch ($status) {
            case 0:
                $result['message'] = "Component is marked as Failed/Defective";
                $result['can_override'] = false;
                break;
            case 1:
                $result['available'] = true;
                $result['message'] = "Component is Available";
                break;
            case 2:
                if ($serverUuid === $configUuid) {
                    $result['available'] = true;
                    $result['message'] = "Component is already assigned to this configuration";
                } elseif ($serverUuid) {
                    $result['message'] = "Component is currently in use in configuration: $serverUuid";
                    $result['can_override'] = true;
                } else {
                    $result['message'] = "Component is currently In Use";
                    $result['can_override'] = true;
                }
                break;
            default:
                $result['message'] = "Component has unknown status: $status";
                $result['can_override'] = false;
        }
        
        return $result;
    }
    
    /**
     * Get configuration component by type from JSON columns
     */
    private function getConfigurationComponent($configUuid, $componentType) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $configData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$configData) {
                return null;
            }

            // Extract components and find the one matching the type
            $components = $this->componentsFromRows($configData);
            foreach ($components as $component) {
                if ($component['component_type'] === $componentType) {
                    return $component;
                }
            }

            return null;
        } catch (Exception $e) {
            error_log("Error getting configuration component: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update component status, ServerUUID, location, rack position, and installation date
     * CRITICAL: Now requires $serialNumber to update only the specific physical component
     * U-C.2/U-C.3/U-C.5: exposed as a library call for the command layer.
     */
    public function updateComponentStatusAndServerUuid($componentType, $componentUuid, $newStatus, $serverUuid, $reason = '', $serverLocation = null, $serverRackPosition = null, $serialNumber = null, $inventoryId = null) {
        if (!isset($this->componentTables[$componentType])) {
            error_log("Cannot update status - invalid component type: $componentType");
            return false;
        }

        try {
            $table = $this->componentTables[$componentType];

            // Identify the target row. Three ways, in descending order of precision:
            //
            //   1. $inventoryId  -- the row's own primary key. Exact by definition, so
            //      no ambiguity check is needed or wanted. PREFER THIS. Every caller
            //      that has already located the physical unit (by locking it, or by
            //      reading it back via ServerUUID) has the ID in hand and should pass it.
            //
            //   2. UUID + SerialNumber -- exact only while every unit carries a serial.
            //      Since 2026-07-22 units may legitimately have SerialNumber NULL (see
            //      AssetTag, seeder 2026_07_22_001): a NULL serial cannot be matched with
            //      `= ?` and silently falls through to case 3.
            //
            //   3. UUID alone -- the MODEL, matching every physical unit of it. Never
            //      precise; guarded below.
            if ($inventoryId !== null) {
                $whereClause = "WHERE ID = ?";
                $whereParams = [(int)$inventoryId];
            } else {
                $whereClause = "WHERE UUID = ?";
                $whereParams = [$componentUuid];

                if ($serialNumber !== null) {
                    $whereClause .= " AND SerialNumber = ?";
                    $whereParams[] = $serialNumber;
                }

                // Fail closed on an AMBIGUOUS update. Without a serial the WHERE above is
                // `UUID = ?` alone, which matches every physical unit of that model -- so a
                // single caller omitting the serial silently rewrites Status/ServerUUID for
                // other servers' components (the deleteConfiguration() defect fixed
                // 2026-07-21; see its note). Refusing only when the model genuinely has more
                // than one unit keeps the unambiguous single-unit case working for any
                // caller that legitimately has no serial to hand.
                //
                // NOTE: serial-less stock makes this branch fire far more often than it did
                // when every unit had a serial -- three KC600 drives sharing one model UUID
                // and no serials are indistinguishable here. That is why callers must pass
                // $inventoryId; reaching this guard now usually means a caller was missed.
                if ($serialNumber === null) {
                    $ambiguityStmt = $this->pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE UUID = ?");
                    $ambiguityStmt->execute([$componentUuid]);
                    if ((int)$ambiguityStmt->fetchColumn() > 1) {
                        error_log(
                            "REFUSED ambiguous inventory update: $componentType $componentUuid has multiple "
                            . "physical units and neither SerialNumber nor inventoryId was supplied "
                            . "(reason: $reason)"
                        );
                        return false;
                    }
                }
            }

            // Get current status first for logging
            $stmt = $this->pdo->prepare("SELECT Status, ServerUUID, Location, RackPosition, InstallationDate, SerialNumber FROM $table $whereClause");
            $stmt->execute($whereParams);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($current === false) {
                $serialInfo = $serialNumber ? " with SerialNumber '$serialNumber'" : "";
                error_log("Cannot update status - component not found: $componentUuid$serialInfo in $table");
                return false;
            }

            // Prepare update fields and values
            $updateFields = ["Status = ?", "ServerUUID = ?", "UpdatedAt = NOW()"];
            $updateValues = [$newStatus, $serverUuid];

            // U-SM.3: sync status_v2 in the SAME statement as the legacy Status
            // write below (StatusMap::INVENTORY_LEGACY_TO_V2 is the forward
            // direction of the lossy map StateMachine uses in reverse
            // elsewhere). No assertion/enforcement here yet (U-SM.4) — sync-only.
            require_once __DIR__ . '/../state/StatusMap.php';
            if (array_key_exists($newStatus, StatusMap::INVENTORY_LEGACY_TO_V2)) {
                $updateFields[] = "status_v2 = ?";
                $updateValues[] = StatusMap::INVENTORY_LEGACY_TO_V2[$newStatus];
            }

            // Handle installation date
            if ($newStatus == 2 && $serverUuid !== null) {
                // Component is being assigned to a server - set installation date to current timestamp
                $updateFields[] = "InstallationDate = CURDATE()";
            } elseif ($newStatus == 1 && $serverUuid === null) {
                // Component is being released from server - clear installation date
                $updateFields[] = "InstallationDate = NULL";
            }

            // Handle location and rack position updates
            if ($newStatus == 2 && $serverUuid !== null) {
                // Component is being assigned to a server - always update location and rack position
                $updateFields[] = "Location = ?";
                $updateValues[] = $serverLocation; // This can be null if server has no location

                $updateFields[] = "RackPosition = ?";
                $updateValues[] = $serverRackPosition; // This can be null if server has no rack position

            } elseif ($newStatus == 1 && $serverUuid === null) {
                // Component is being released from server - clear rack position but keep location
                $updateFields[] = "RackPosition = NULL";
                // We don't clear location as component still exists in physical location
            }

            // Add WHERE parameters to update values
            $updateValues = array_merge($updateValues, $whereParams);

            // Execute update with SerialNumber constraint
            $sql = "UPDATE $table SET " . implode(', ', $updateFields) . " $whereClause";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($updateValues);

            return $result;

        } catch (Exception $e) {
            error_log("Error updating component assignment: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * RACE CONDITION FIX: Lock component row and retrieve details atomically
     * Uses SELECT ... FOR UPDATE to prevent race conditions during component addition
     *
     * @param string $componentType Component type (cpu, motherboard, etc.)
     * @param string $componentUuid Component UUID
     * @param string|null $serialNumber Optional serial number for multi-component UUIDs
     * @return array ['found' => bool, 'data' => array|null, 'error' => string|null]
     */
    private function lockAndCheckComponent($componentType, $componentUuid, $serialNumber = null, $configUuid = null) {
        try {
            $table = $this->getComponentInventoryTable($componentType);
            if (!$table) {
                return [
                    'found' => false,
                    'data' => null,
                    'error' => "Invalid component type: $componentType"
                ];
            }

            // CRITICAL: Use FOR UPDATE to lock the row and prevent race conditions
            if ($serialNumber !== null) {
                // Lock specific physical component by UUID + SerialNumber
                $stmt = $this->pdo->prepare("
                    SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition
                    FROM `$table`
                    WHERE UUID = ? AND SerialNumber = ?
                    FOR UPDATE
                ");
                $stmt->execute([$componentUuid, $serialNumber]);
            } else {
                // Lock by UUID only, preferring available (Status=1) rows.
                //
                // BUGFIX (A-L1): this was `ORDER BY Status ASC` with no LIMIT. Status
                // is 0=failed / 1=available / 2=in_use, so ASC returned the FAILED unit
                // first -- the exact opposite of the stated intent. Any add without an
                // explicit serial, for a model with one RMA'd unit, then hit
                // checkComponentAvailability()'s "Failed/Defective" branch and was
                // rejected while good stock sat available. One retired unit poisoned
                // its whole model permanently.
                //
                // Ordering on `Status = 1` explicitly puts available rows first
                // regardless of how the other status codes sort. The unit already
                // assigned to THIS config stays reachable (checkComponentAvailability()
                // treats ServerUUID = configUuid as available) but never outranks a
                // genuinely free unit.
                //
                // LIMIT 1 also bounds the lock: without it FOR UPDATE locked every
                // physical unit of the model for the transaction's lifetime, which is a
                // deadlock generator against any concurrent add of the same model.
                //
                // LOCATION PREFERENCE (2026-08-26): the ordering above is blind
                // to geography. With one unit of a model in Noida and one in
                // Jaipur, an install into a Jaipur server could lock the Noida
                // unit purely because it had the lower ID. Preferring the
                // server's own site fixes that everywhere the picker runs, not
                // only in requests. It is a PREFERENCE, not a filter: if the
                // only free unit is at the other site it is still found, and
                // this path deliberately does NOT refuse -- an admin with the
                // part in their hand is the authority on where it is. Requests
                // are the path that refuses (RequestActionExecutor's location
                // gate), because nobody is holding anything there.
                //
                // Null (pre-seeder, or an unplaced server) leaves the SQL
                // byte-identical to what it was.
                require_once __DIR__ . '/../location/LocationResolver.php';
                $preferLocation = LocationResolver::preferredUnitLocation($this->pdo, $table, $configUuid);
                $locationOrder  = $preferLocation !== null ? '(location_uuid = ?) DESC, ' : '';
                $params         = $preferLocation !== null
                    ? [$componentUuid, $preferLocation]
                    : [$componentUuid];

                $stmt = $this->pdo->prepare("
                    SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition
                    FROM `$table`
                    WHERE UUID = ?
                    ORDER BY (Status = 1) DESC, (Status = 2) DESC, {$locationOrder}ID ASC
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmt->execute($params);
            }

            $component = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$component) {
                $serialInfo = $serialNumber ? " with SerialNumber '$serialNumber'" : "";
                return [
                    'found' => false,
                    'data' => null,
                    'error' => "Component not found in inventory: $componentUuid$serialInfo"
                ];
            }

            return [
                'found' => true,
                'data' => $component,
                'error' => null
            ];

        } catch (PDOException $e) {
            error_log("Error locking component: " . $e->getMessage());
            return [
                'found' => false,
                'data' => null,
                'error' => "Database error: " . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate component power consumption from JSON specifications
     */
    private function calculateComponentPowerFromJSON($componentType, $componentUuid) {
        // Default power estimates by component type (watts)
        $defaultPower = [
            'cpu' => 150,
            'ram' => 8,
            'storage' => 15,
            'motherboard' => 50,
            'nic' => 25,
            'caddy' => 5,
            'pciecard' => 30,
            'risercard' => 30, // unchanged from what risers got as pciecards -- see calculateComponentPowerFromJSON()'s risercard case
            'hbacard' => 20,
            'chassis' => 0,  // Chassis doesn't consume power directly
            'sfp' => 2  // SFP modules: typically 1-3W
        ];

        try {
            // Fetch component specs from JSON files
            $specs = null;
            switch ($componentType) {
                case 'cpu':
                    $specs = $this->dataUtils->getCPUByUUID($componentUuid);
                    if ($specs && isset($specs['tdp_W'])) {
                        return (int)$specs['tdp_W'];
                    }
                    break;

                case 'ram':
                    $specs = $this->dataUtils->getRAMByUUID($componentUuid);
                    // RAM power consumption is typically 3-8W per module
                    // DDR5 consumes more than DDR4
                    if ($specs) {
                        $type = strtolower($specs['memory_type'] ?? '');
                        if (strpos($type, 'ddr5') !== false) {
                            return 8; // DDR5: ~8W per module
                        } elseif (strpos($type, 'ddr4') !== false) {
                            return 5; // DDR4: ~5W per module
                        } elseif (strpos($type, 'ddr3') !== false) {
                            return 4; // DDR3: ~4W per module
                        }
                    }
                    return 8; // Default DDR5

                case 'storage':
                    $specs = $this->dataUtils->getStorageByUUID($componentUuid);
                    if ($specs) {
                        $interface = strtolower($specs['interface'] ?? '');
                        $formFactor = strtolower($specs['form_factor'] ?? '');

                        // NVMe M.2: 5-10W active, 3-5W idle (average 7W)
                        if (strpos($interface, 'nvme') !== false && strpos($formFactor, 'm.2') !== false) {
                            return 7;
                        }
                        // NVMe U.2: 10-15W active, 5-8W idle (average 10W)
                        elseif (strpos($interface, 'nvme') !== false && strpos($formFactor, 'u.2') !== false) {
                            return 10;
                        }
                        // SAS HDD: 10-12W active, 6-8W idle (average 10W)
                        elseif (strpos($interface, 'sas') !== false) {
                            return 10;
                        }
                        // SATA SSD: 2-5W active, 1-2W idle (average 3W)
                        elseif (strpos($interface, 'sata') !== false && strpos($formFactor, 'ssd') !== false) {
                            return 3;
                        }
                        // SATA HDD: 6-10W active, 4-6W idle (average 8W)
                        elseif (strpos($interface, 'sata') !== false) {
                            return 8;
                        }
                    }
                    return 15; // Default

                case 'motherboard':
                    // Motherboards typically consume 40-80W depending on complexity
                    return 60; // Average estimate

                case 'nic':
                    $specs = $this->dataUtils->getNICByUUID($componentUuid);
                    if ($specs) {
                        // JSON has power as string like "8W", parse numeric value
                        if (isset($specs['power']) && is_string($specs['power'])) {
                            return (int)$specs['power'];
                        }
                        // Fallback: estimate from speeds array
                        $speeds = $specs['speeds'] ?? [];
                        $speedStr = implode(' ', $speeds);
                        if (strpos($speedStr, '25GbE') !== false || strpos($speedStr, '25G') !== false) {
                            return 30;
                        } elseif (strpos($speedStr, '10GbE') !== false || strpos($speedStr, '10G') !== false) {
                            return 25;
                        } elseif (strpos($speedStr, '1GbE') !== false || strpos($speedStr, '1G') !== false) {
                            return 8;
                        }
                    }
                    return 25; // Default

                case 'pciecard':
                    $specs = $this->dataUtils->getPCIeCardByUUID($componentUuid);
                    if ($specs && isset($specs['power_consumption']['typical_W'])) {
                        return (int)$specs['power_consumption']['typical_W'];
                    }
                    // Estimate based on card type
                    $cardType = strtolower($specs['type'] ?? '');
                    if (strpos($cardType, 'gpu') !== false) {
                        return 75; // Mid-range GPU
                    } elseif (strpos($cardType, 'raid') !== false) {
                        return 25; // RAID controllers
                    }
                    return 30; // Default PCIe card

                case 'risercard':
                    // Same arithmetic risers already got while they were typed
                    // 'pciecard' (no power_consumption field in ims-data, no 'type'
                    // field -> the 30W default). Kept identical on purpose: this split
                    // must not move any power number. Revisit only as a deliberate
                    // change, with the golden baseline re-blessed.
                    $specs = $this->dataUtils->getRiserCardByUUID($componentUuid);
                    if ($specs && isset($specs['power_consumption']['typical_W'])) {
                        return (int)$specs['power_consumption']['typical_W'];
                    }
                    return 30;

                case 'hbacard':
                    $specs = $this->dataUtils->getHBACardByUUID($componentUuid);
                    if ($specs && isset($specs['power_consumption']['typical_W'])) {
                        return (int)$specs['power_consumption']['typical_W'];
                    }
                    return 20; // Default HBA card power

                case 'sfp':
                    $specs = $this->dataUtils->getSFPByUUID($componentUuid);
                    if ($specs && isset($specs['power_consumption']) && is_string($specs['power_consumption'])) {
                        return (int)$specs['power_consumption']; // e.g. "1.5W" -> 1
                    }
                    return 2; // Default SFP power

                case 'caddy':
                    return 0; // Caddies don't consume power

                case 'chassis':
                    return 0; // Chassis doesn't consume power (fans calculated separately)
            }

        } catch (Exception $e) {
            error_log("Error calculating power for $componentType ($componentUuid): " . $e->getMessage());
        }

        // Return default if unable to calculate
        return $defaultPower[$componentType] ?? 50;
    }

    
    
    
    

    
    /**
     * Extract socket type from component notes with enhanced component knowledge base
     */
    private function extractSocketType($notes) {
        $notes = strtolower($notes);
        
        // Component knowledge base for common server components
        $componentSocketMap = [
            // Intel Xeon CPUs
            'platinum 8480+' => 'lga4677',
            'platinum 8480' => 'lga4677',
            'platinum 8470' => 'lga4677',
            'platinum 8460' => 'lga4677',
            'platinum 8450' => 'lga4677',
            'gold 6430' => 'lga4677',
            'gold 6420' => 'lga4677',
            'gold 6410' => 'lga4677',
            'silver 4410' => 'lga4677',
            'bronze 3408' => 'lga4677',
            'xeon 8' => 'lga4677', // Generic 4th gen Xeon pattern
            
            // AMD EPYC CPUs
            'epyc 9534' => 'sp5',
            'epyc 9554' => 'sp5',
            'epyc 9634' => 'sp5',
            'epyc 9654' => 'sp5',
            'epyc 64-core' => 'sp5', // Generic EPYC pattern
            
            // Motherboard models
            'x13dri-n' => 'lga4677',
            'x13dpi-n' => 'lga4677',
            'x12dpi-nt6' => 'lga4189',
            'x12dpi-n6' => 'lga4189',
            'h12dsi-n6' => 'sp3',
            'h12ssl-i' => 'sp3',
            'mz93-fs0' => 'sp5',
            'z790 godlike' => 'lga1700',
            'z790' => 'lga1700',
            'b650' => 'am5',
        ];
        
        // Check component knowledge base first
        foreach ($componentSocketMap as $component => $socket) {
            if (strpos($notes, $component) !== false) {
                return $socket;
            }
        }
        
        // Fallback to socket pattern matching
        $commonSockets = [
            'lga4677', 'lga4189', 'lga3647', 'lga2066', 'lga2011',
            'lga1700', 'lga1200', 'lga1151', 'lga1150', 'lga1155', 'lga1156',
            'sp5', 'sp3', 'sp4', 'am5', 'am4', 'tr4', 'strx4',
            'socket 4677', 'socket 4189', 'socket 3647', 'socket 2066', 'socket 2011',
            'socket 1700', 'socket 1200', 'socket 1151', 'socket 1150',
            'socket am5', 'socket am4', 'socket sp5', 'socket sp3'
        ];
        
        foreach ($commonSockets as $socket) {
            if (strpos($notes, $socket) !== false) {
                // Normalize socket name
                $socket = str_replace('socket ', '', $socket);
                return $socket;
            }
        }
        
        return null;
    }
    
    /**
     * Extract memory types from motherboard notes
     */
    private function extractMemoryTypes($notes) {
        $types = [];
        
        if (strpos($notes, 'ddr5') !== false) {
            $types[] = 'ddr5';
        }
        if (strpos($notes, 'ddr4') !== false) {
            $types[] = 'ddr4';
        }
        if (strpos($notes, 'ddr3') !== false) {
            $types[] = 'ddr3';
        }
        
        return $types;
    }
    
    /**
     * Extract memory type from RAM notes
     */
    private function extractMemoryType($notes) {
        if (strpos($notes, 'ddr5') !== false) {
            return 'ddr5';
        }
        if (strpos($notes, 'ddr4') !== false) {
            return 'ddr4';
        }
        if (strpos($notes, 'ddr3') !== false) {
            return 'ddr3';
        }
        
        return null;
    }
    
    /**
     * Log configuration action
     */
    private function logConfigurationAction($configUuid, $action, $componentType = null, $componentUuid = null, $metadata = null) {
        try {
            // Check if history table exists
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'server_configuration_history'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                // Create history table if it doesn't exist
                $this->createHistoryTable();
            } else {
                // Table exists, ensure it has all required columns
                $this->ensureHistoryTableColumns();
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO server_configuration_history 
                (config_uuid, action, component_type, component_uuid, metadata, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $configUuid, 
                $action, 
                $componentType, 
                $componentUuid, 
                json_encode($metadata)
            ]);
        } catch (Exception $e) {
            error_log("Error logging configuration action: " . $e->getMessage());
        }
    }
    
    /**
     * Create history table if it doesn't exist
     */
    private function createHistoryTable() {
        try {
            $sql = "
                CREATE TABLE IF NOT EXISTS server_configuration_history (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    config_uuid varchar(36) NOT NULL,
                    action varchar(50) NOT NULL COMMENT 'created, updated, component_added, component_removed, validated, etc.',
                    component_type varchar(20) DEFAULT NULL,
                    component_uuid varchar(36) DEFAULT NULL,
                    metadata text DEFAULT NULL COMMENT 'JSON metadata for the action',
                    created_at timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (id),
                    KEY idx_config_uuid (config_uuid),
                    KEY idx_component_uuid (component_uuid),
                    KEY idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $this->pdo->exec($sql);
            error_log("Created server_configuration_history table");
        } catch (Exception $e) {
            error_log("Error creating history table: " . $e->getMessage());
        }
    }

    /**
     * Ensure server_configuration_history table has all required columns
     */
    private function ensureHistoryTableColumns() {
        try {
            // Check if component_type column exists
            $stmt = $this->pdo->query("SHOW COLUMNS FROM server_configuration_history LIKE 'component_type'");
            if (!$stmt->fetch()) {
                $this->pdo->exec("ALTER TABLE server_configuration_history ADD COLUMN component_type varchar(20) DEFAULT NULL AFTER action");
                error_log("Added component_type column to server_configuration_history");
            }

            // Check if component_uuid column exists
            $stmt = $this->pdo->query("SHOW COLUMNS FROM server_configuration_history LIKE 'component_uuid'");
            if (!$stmt->fetch()) {
                $this->pdo->exec("ALTER TABLE server_configuration_history ADD COLUMN component_uuid varchar(36) DEFAULT NULL AFTER component_type");
                error_log("Added component_uuid column to server_configuration_history");
            }
        } catch (Exception $e) {
            error_log("Error ensuring history table columns: " . $e->getMessage());
        }
    }



    /**
     * Resolve a motherboard's maximum memory capacity in GB from its JSON spec.
     *
     * Handles the real key names used across ims-data/motherboard:
     *   - max_capacity_TB (server boards, e.g. 8 -> 8192 GB)
     *   - max_capacity_GB (desktop boards, e.g. 64)
     * Uses float math so fractional TB values (1.5 TB) become 1536 GB instead of
     * truncating to 1 TB. Returns null when the board declares no capacity limit,
     * so callers skip the ceiling check rather than inventing a 128 GB default.
     * [Fixes TP-2A: lowercase max_capacity_gb never matched -> universal 128 GB cap]
     *
     * @param array $mbSpecs Raw motherboard JSON spec
     * @return int|null Maximum capacity in GB, or null if undeclared
     */
    private function getMotherboardMaxMemoryGb($mbSpecs) {
        $memory = (is_array($mbSpecs) && isset($mbSpecs['memory']) && is_array($mbSpecs['memory']))
            ? $mbSpecs['memory'] : [];

        if (isset($memory['max_capacity_TB']) && is_numeric($memory['max_capacity_TB'])) {
            return (int)round(((float)$memory['max_capacity_TB']) * 1024);
        }
        if (isset($memory['max_capacity_GB']) && is_numeric($memory['max_capacity_GB'])) {
            return (int)$memory['max_capacity_GB'];
        }
        // Legacy/lowercase fallback (rare); kept for forward compatibility.
        if (isset($memory['max_capacity_gb']) && is_numeric($memory['max_capacity_gb'])) {
            return (int)$memory['max_capacity_gb'];
        }
        return null;
    }


    /**
     * Get existing components formatted for validation
     */
    /**
     * Existing components as StorageConnectionValidator::validate() expects them when the
     * drive in question is ALREADY installed: with one entry of that drive removed.
     *
     * validate() answers "can this drive be ADDED to this configuration?", so the candidate
     * is by definition not part of $existing. Describe-time callers (the drive-bay display and
     * the finalize-time connection report) ask that same question about a drive that IS already
     * in the config, so passing the config unchanged made checkBayAvailability() count the
     * candidate twice: an exactly-full chassis reported "N in use, cannot add 1 more",
     * validate() returned valid=false, and computeStorageConnectionPath() degraded the drive to
     * 'not_connected' — which is why installed drives never appeared in a bay. [F-19]
     *
     * Removes exactly ONE entry (or decrements one unit of a quantity-N entry), so a second
     * identical drive still occupies its own bay.
     *
     * @param array  $existing    Output of getExistingComponentsForValidation()
     * @param string $storageUuid The drive being described
     * @return array
     */
    private function existingComponentsExcludingStorage(array $existing, $storageUuid) {
        if (empty($existing['storage']) || !is_array($existing['storage'])) {
            return $existing;
        }

        $kept = [];
        $removed = false;
        foreach ($existing['storage'] as $entry) {
            if (!$removed && is_array($entry) && ($entry['component_uuid'] ?? null) === $storageUuid) {
                $qty = max(1, (int)($entry['quantity'] ?? 1));
                if ($qty > 1) {
                    $entry['quantity'] = $qty - 1;
                    $kept[] = $entry;
                }
                $removed = true;
                continue;
            }
            $kept[] = $entry;
        }

        $existing['storage'] = $kept;
        return $existing;
    }

    private function getExistingComponentsForValidation($configUuid) {
        $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        $configData = $stmt->fetch(PDO::FETCH_ASSOC);

        $components = [];
        if ($configData) {
            $components = $this->componentsFromRows($configData);
        }

        $formatted = [
            'chassis' => null,
            'motherboard' => null,
            'cpu' => [],
            'ram' => [],
            'storage' => [],
            'nic' => [],
            'pciecard' => [],
            'risercard' => [],
            'hbacard' => [],
            'caddy' => []
        ];

        foreach ($components as $component) {
            $type = $component['component_type'];
            if ($type === 'chassis' || $type === 'motherboard') {
                $formatted[$type] = $component;
            } else {
                $formatted[$type][] = $component;
            }
        }

        return $formatted;
    }

    /**
     * P5.2: Safely parse JSON with error handling
     * Prevents fatal errors from malformed JSON in database columns
     *
     * @param string $jsonString JSON string to parse
     * @param bool $associative Return associative array (default true)
     * @param string $fieldName Field name for error logging
     * @return array Parsed data or empty array on error
     */
    private function safeJsonDecode($jsonString, $associative = true, $fieldName = 'unknown') {
        if (empty($jsonString)) {
            return $associative ? [] : new stdClass();
        }

        try {
            $decoded = json_decode($jsonString, $associative);

            // P5.2: Check for JSON parse errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMsg = json_last_error_msg();
                error_log("P5.2 JSON ERROR in $fieldName: " . $errorMsg . " | Raw: " . substr($jsonString, 0, 100));

                // A-E2: malformed JSON in a PERSISTED column is data corruption, not an
                // empty set. Degrading to [] made every component in that column vanish
                // from extractComponentsFromJson() -- so a corrupt ram_configuration
                // presented as "this server has no RAM", validateConfiguration() agreed,
                // and finalizeConfiguration() locked the config as valid. Read-only
                // display paths still degrade gracefully; anything that mutates or
                // finalizes a configuration must refuse to act on a column it cannot read.
                if ($this->strictJsonDecode) {
                    throw new RuntimeException(
                        "Configuration column '$fieldName' contains malformed JSON and cannot be modified safely"
                    );
                }

                return $associative ? [] : new stdClass();
            }

            // Handle null result (valid JSON null, but we treat as empty)
            if ($decoded === null && $jsonString !== 'null') {
                error_log("P5.2 JSON NULL in $fieldName: JSON decoded to null unexpectedly | Raw: " . substr($jsonString, 0, 100));
                return $associative ? [] : new stdClass();
            }

            return $decoded;

        } catch (Exception $e) {
            error_log("P5.2 JSON EXCEPTION in $fieldName: " . $e->getMessage());
            return $associative ? [] : new stdClass();
        }
    }

    /**
     * Total units claimed by a flat list of component JSON entries.
     *
     * A-L8: capacity budgets counted ENTRIES, so one entry of quantity=4 consumed a
     * single slot in the arithmetic. Each entry claims its own `quantity` (>= 1).
     *
     * @param array $entries decoded JSON entries
     * @return int
     */
    private function sumEntryQuantities($entries) {
        if (!is_array($entries)) {
            return 0;
        }
        $total = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $total += max(1, (int)($entry['quantity'] ?? 1));
        }
        return $total;
    }


    // NOTE (2026-07-21): fixOrphanedServerUUIDs() was removed here. It was dead
    // code -- zero callers fleet-wide -- and carried the same model-vs-unit defect
    // fixed in deleteConfiguration() this session: it cleared ServerUUID with
    // `UPDATE $table ... WHERE UUID = ?`, unscoped by SerialNumber AND unscoped by
    // the config it was called for, so a single "autofix" would have detached every
    // physical unit of that model across the whole fleet. Deleted rather than fixed;
    // scripts/verify/orphan_report.php is the supported way to detect orphans.

    /**
     * P4.1: Get deterministic lock order for multiple resources
     * Prevents deadlocks by always locking in same order (alphabetical)
     *
     * @param array $resourceIds Resource identifiers to lock
     * @return array Sorted resource IDs
     */
    private function getDeterministicLockOrder($resourceIds) {
        // P4.1: Always sort to ensure consistent lock order
        sort($resourceIds);
        return $resourceIds;
    }

    /**
     * P3.4: Recalculate form factor lock when chassis or storage is removed
     * If only one storage form factor remains, set lock. If no storage, clear lock.
     *
     * @param string $configUuid Server configuration UUID
     * @return void
     * U-C.3: exposed as a library call for the command layer.
     */
    public function recalculateFormFactorLock($configUuid) {
        try {
            require_once __DIR__ . '/../shared/DataExtractionUtilities.php';
            $dataUtils = new DataExtractionUtilities();

            // Get current configuration
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) {
                return;
            }

            // Extract storage components from JSON
            $storageComponents = [];
            {
                // U-D.3b: storage from config_components rows, re-shaped to the legacy
                // entry keys the loop below indexes by.
                $storageConfigs = [];
                foreach ($this->componentsFromRows($config) as $entry) {
                    if (($entry['component_type'] ?? null) !== 'storage') { continue; }
                    $storageConfigs[] = [
                        'uuid'          => $entry['component_uuid'] ?? null,
                        'quantity'      => $entry['quantity'] ?? 1,
                        'slot_position' => $entry['slot_position'] ?? null,
                    ];
                }
                if (is_array($storageConfigs)) {
                    $storageComponents = $storageConfigs;
                }
            }

            // Determine new form factor lock
            $formFactors = [];
            foreach ($storageComponents as $storage) {
                $storageUuid = $storage['uuid'] ?? null;
                if ($storageUuid) {
                    $storageSpecs = $dataUtils->getStorageByUUID($storageUuid);
                    if ($storageSpecs) {
                        $formFactor = strtolower($storageSpecs['form_factor'] ?? '');
                        // Normalize form factor
                        if (strpos($formFactor, '2.5') !== false) {
                            $formFactor = '2.5-inch';
                        } elseif (strpos($formFactor, '3.5') !== false) {
                            $formFactor = '3.5-inch';
                        } elseif (strpos($formFactor, 'm.') !== false || strpos($formFactor, 'm2') !== false) {
                            $formFactor = 'm.2';
                        }
                        $formFactors[$formFactor] = true;
                    }
                }
            }

            // Form factor lock is informational only (no DB update needed here)

        } catch (Exception $e) {
            error_log("Error recalculating form factor lock: " . $e->getMessage());
        }
    }
}
