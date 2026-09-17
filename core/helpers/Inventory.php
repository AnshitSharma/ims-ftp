<?php
/**
 * Inventory.php — split out of BaseFunctions.php (audit Phase 4, roadmap item 23).
 *
 * Component type validation and inventory CRUD — one file per component type's table, the
 * shared search/whitelist helpers behind add/update/delete, and the type-metadata functions
 * (table names, asset tag codes, the buildable-types map). Mechanical split: function bodies
 * unchanged, still global functions.
 */

function validateComponentType($type) {
    if (!in_array($type, VALID_COMPONENT_TYPES, true)) {
        throw new InvalidArgumentException("Invalid component type: $type");
    }
}

/**
 * Component Inventory Functions
 */

/**
 * Map component type to actual table name
 */
function getComponentTableName($type) {
    validateComponentType($type);
    return $type . 'inventory';
}

/**
 * The ELEVEN buildable component types, mapped to their inventory tables.
 *
 * This is a DELIBERATE SUBSET of VALID_COMPONENT_TYPES, not drift -- see the manifest
 * docblock below on why subsets must not be replaced by the twelve-type list. A compute
 * platform is a stocked unit with its own inventory table, but it is the box a server is
 * built IN, not a part you may add-component into a slot. ServerBuilder::isValidComponentType()
 * is the gate that enforces that, and it reads this map.
 *
 * Widening this to twelve would make 'serverplatform' an addable component in every build
 * path at once. If you need all twelve tables -- releasing a whole server back to stock,
 * or searching every shelf for a serial -- append 'serverplatforminventory' explicitly at
 * the call site, the way releaseAllComponents() and searchBySerial() both do, so the
 * widening stays visible where it happens.
 *
 * Consolidated 2026-09-16: this literal used to be written out three more times, in
 * server_api.php, ServerBuilder.php and ComponentDataLoader.php.
 *
 * ORDER IS LOAD-BEARING, so chassis leads. ServerBuilder::summarizeInstalledComponents()
 * renders its confirmation sentence by iterating this map, so the key order is the clause
 * order the user reads ("1 chassis, 2 CPUs, ..."). That was ServerBuilder's order before
 * the three literals were folded together and is kept exactly; the other two call sites
 * only ever index by type and are indifferent to it. Membership is still derived from
 * VALID_COMPONENT_TYPES so a thirteenth type cannot be forgotten here.
 *
 * @return array<string,string> type => table name
 */
function getBuildableComponentTables() {
    static $map = null;
    if ($map === null) {
        $map = ['chassis' => 'chassisinventory'];
        foreach (VALID_COMPONENT_TYPES as $type) {
            if ($type === 'serverplatform' || $type === 'chassis') {
                continue;
            }
            $map[$type] = $type . 'inventory';
        }
    }
    return $map;
}

/**
 * The twelve component types, described once, for both stacks. (audit JSON-009)
 *
 * WHY
 *   The type vocabulary is restated in seventeen places across the two repos and it has
 *   drifted. Two of those copies were wrong on 2026-09-16 and both were user-visible:
 *     - WorkflowConfig::getValidComponentTypes() omitted 'serverplatform', so a Request line
 *       item naming one was rejected as "Unknown component type" despite 67 units in stock.
 *     - dashboard.js::updateSidebarCounts omitted 'sfp', so the sidebar showed no count for
 *       46 stocked SFPs while the dashboard tile beside it showed them.
 *   Both are fixed; this manifest is what stops the next copy from drifting, by giving the
 *   frontend something to read instead of a list to retype.
 *
 * WHAT IS NOT IN HERE
 *   Deliberate SUBSETS of the vocabulary are not drift and must not be replaced by this:
 *   SystemRequiredSetRule::REQUIRED_TYPES is the six types a valid system needs, and
 *   BuildAffordances::BASE_TYPES is the five a server is built out of. Both say so in their
 *   own docblocks. Only lists that mean "every component type" belong on this manifest.
 *
 * spec_url is a RELATIVE, web-facing path -- the same one the browser already fetches from
 * the domain root. No filesystem path is exposed here.
 *
 * `nesting` describes where model objects sit inside each file, surveyed across all 12 files
 * on 2026-09-16. It is documentation, not something to parse: SpecProjector walks the files
 * generically rather than following these strings.
 */
function getComponentTypeManifest() {
    // Labels and nesting are per type; everything else is derived.
    $meta = [
        'cpu'            => ['label' => 'CPU',             'nesting' => 'brand[].models[]'],
        'ram'            => ['label' => 'Memory',          'nesting' => 'brand[].models[]'],
        'storage'        => ['label' => 'Storage',         'nesting' => 'brand[].models[]'],
        'motherboard'    => ['label' => 'Motherboard',     'nesting' => 'brand[].models[]'],
        'nic'            => ['label' => 'Network Card',    'nesting' => 'brand[].series[].models[]'],
        'caddy'          => ['label' => 'Caddy',           'nesting' => 'caddies[]'],
        'chassis'        => ['label' => 'Chassis',         'nesting' => 'chassis_specifications.manufacturers[].series[].models[]'],
        'pciecard'       => ['label' => 'PCIe Card',       'nesting' => 'brand[].models[]'],
        'risercard'      => ['label' => 'Riser Card',      'nesting' => 'brand[].models[]'],
        'hbacard'        => ['label' => 'HBA Card',        'nesting' => 'brand[].models[]'],
        'sfp'            => ['label' => 'SFP Module',      'nesting' => 'brand[].series[].models[]'],
        'serverplatform' => ['label' => 'Server Platform', 'nesting' => 'brand[].models[]'],
    ];

    $manifest = [];

    foreach (VALID_COMPONENT_TYPES as $type) {
        $entry = [
            'type'  => $type,
            'label' => $meta[$type]['label'] ?? ucfirst($type),
            'table' => $type . 'inventory',
        ];

        // Guarded: ComponentSpecPaths throws when it cannot resolve the data directory, and a
        // vocabulary listing must not 500 because the spec tree moved.
        //
        // It also is not loaded on this request path -- there is no autoloader in this
        // project -- so it is required here rather than assumed. is_readable first, and
        // method_exists after: getRelativePath() is newer than the class, so a deployed-but-
        // stale copy of the file would have the class without the method.
        try {
            if (!class_exists('ComponentSpecPaths', false)) {
                $specPathsFile = __DIR__ . '/../models/components/ComponentSpecPaths.php';
                if (is_readable($specPathsFile)) {
                    require_once $specPathsFile;
                }
            }

            if (method_exists('ComponentSpecPaths', 'getRelativePath')) {
                $entry['spec_url'] = 'ims-data/' . ComponentSpecPaths::getRelativePath($type);
            }
        } catch (Throwable $e) {
            // no spec_url for this type; the rest of the entry is still useful
        }

        if (isset($meta[$type]['nesting'])) {
            $entry['nesting'] = $meta[$type]['nesting'];
        }

        if (function_exists('getComponentAssetTagCode')) {
            try {
                $entry['asset_tag_code'] = getComponentAssetTagCode($type);
            } catch (Throwable $e) {
                // type not in the asset-tag map yet
            }
        }

        $manifest[] = $entry;
    }

    return $manifest;
}

/**
 * Does this component type's inventory table exist yet?
 *
 * Needed because code and schema deploy on DIFFERENT clocks in this project:
 * saving a PHP file auto-uploads it to production in ~20s, while seeders are
 * applied by hand. A type can therefore be live in VALID_COMPONENT_TYPES for a
 * while before its {type}inventory table exists (this is exactly what happened
 * when 'risercard' was registered on 2026-08-14, before seeder 2026_08_14_001
 * had been run).
 *
 * Single-type endpoints don't need this — they already fail per type. It is the
 * FLEET-WIDE loops (dashboard counts, global search, vendor rollups) that do:
 * without a guard, one not-yet-migrated type takes down an aggregate covering
 * every other type. Those loops skip missing tables; everything else still
 * throws, so a real DB outage stays a 500.
 *
 * Result is cached per request — one INFORMATION_SCHEMA query, not one per type.
 */
function inventoryTableExists($pdo, $type) {
    static $existing = null;

    if ($existing === null) {
        $existing = [];
        $stmt = $pdo->query(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%inventory'"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tableName) {
            $existing[strtolower($tableName)] = true;
        }
    }

    return isset($existing[strtolower($type . 'inventory')]);
}

/**
 * Three-letter asset-tag code for a component type.
 *
 * MUST stay in lock-step with seeder 2026_07_22_001, which backfilled every
 * pre-existing inventory row using these exact codes. Changing one here without
 * a migration would split a table's tags across two formats.
 */
function getComponentAssetTagCode($type) {
    validateComponentType($type);

    $codes = [
        'cpu'         => 'CPU',
        'ram'         => 'RAM',
        'storage'     => 'STO',
        'motherboard' => 'MBD',
        'nic'         => 'NIC',
        'caddy'       => 'CAD',
        'chassis'     => 'CHS',
        'pciecard'    => 'PCI',
        'risercard'   => 'RSR',
        'hbacard'     => 'HBA',
        'sfp'         => 'SFP',
        'serverplatform' => 'SPF',
    ];

    if (!isset($codes[$type])) {
        throw new InvalidArgumentException("No asset tag code defined for component type: $type");
    }

    return $codes[$type];
}

/**
 * Build the asset tag for a unit from its own inventory primary key.
 *
 * The tag is the system-issued identity a technician can physically sticker on
 * hardware whose manufacturer serial is missing or unreadable. Deriving it from
 * the row's own auto-increment ID makes it unique BY CONSTRUCTION — no counter
 * table, no contention, no collision — and matches the backfill formula in
 * seeder 2026_07_22_001 exactly.
 *
 * IDs past 999999 simply produce a longer tag; the column has room to 20 chars.
 */
function formatAssetTag($type, $inventoryId) {
    return sprintf('BDC-%s-%06d', getComponentAssetTagCode($type), (int)$inventoryId);
}


/**
 * Turn a duplicate-key PDOException into an operator-readable message.
 *
 * Inventory rows carry UNIQUE indexes on SerialNumber and AssetTag. Violating
 * one is an operator mistake (typing a serial that already exists), not a
 * server fault, so it must surface as a 400 naming the unit that already holds
 * the value — the AssetTag is what the operator can actually go and find on a
 * shelf. Returns InvalidArgumentException, which the component handlers already
 * map to 400; any non-duplicate PDOException is handed straight back so real
 * database faults keep their 500.
 *
 * @param  string $tableName caller-supplied, already resolved via getComponentTableName()
 * @return Exception the exception the caller should throw
 */
function describeDuplicateComponentKey(PDO $pdo, $tableName, $type, array $safeData, PDOException $e) {
    // 23000 = integrity constraint violation; driver code 1062 = duplicate entry.
    $isDuplicate = ($e->getCode() === '23000')
        && isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062;
    if (!$isDuplicate) {
        return $e;
    }

    $serial = isset($safeData['SerialNumber']) ? trim((string)$safeData['SerialNumber']) : '';
    if ($serial === '') {
        // Duplicate on some other unique key (AssetTag, or a type-specific one).
        // Say so plainly rather than guessing at a column.
        return new InvalidArgumentException(
            "This " . $type . " conflicts with an existing inventory record."
        );
    }

    // Name the offending unit. Wrapped because this runs on the heels of a
    // failed write — if the lookup itself fails we still owe the caller the
    // duplicate-serial message, just without the pointer.
    $existing = null;
    try {
        $lookup = $pdo->prepare("SELECT ID, AssetTag FROM `$tableName` WHERE SerialNumber = ? LIMIT 1");
        $lookup->execute([$serial]);
        $existing = $lookup->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $ignored) {
        error_log("Duplicate-key lookup failed on $tableName: " . $ignored->getMessage());
    }

    if ($existing && !empty($existing['AssetTag'])) {
        return new InvalidArgumentException(
            "Serial number '$serial' is already registered to " . $existing['AssetTag']
            . ". If this is a different unit, leave the serial blank — it will be "
            . "identified by its own asset tag."
        );
    }

    return new InvalidArgumentException(
        "Serial number '$serial' is already registered to another " . $type . " unit."
    );
}

/**
 * Convert CamelCase to snake_case
 */
function convertCamelToSnake($input) {
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $input));
}

/**
 * Get field mapping for component type (snake_case to CamelCase)
 */
function getComponentFieldMap($type) {
    // Common fields for all components
    $commonFields = [
        'uuid' => 'UUID',
        'serial_number' => 'SerialNumber',
        'status' => 'Status',
        'server_uuid' => 'ServerUUID',
        'location' => 'Location',
        'rack_position' => 'RackPosition',
        'purchase_date' => 'PurchaseDate',
        'installation_date' => 'InstallationDate',
        'warranty_end_date' => 'WarrantyEndDate',
        'fail_date' => 'FailDate',
        'flag' => 'Flag',
        'notes' => 'Notes',
        'vendor_id' => 'VendorID'
    ];

    // Component-specific fields
    $specificFields = [
        'nic' => [
            'mac_address' => 'MacAddress',
            'ip_address' => 'IPAddress',
            'network_name' => 'NetworkName'
        ],
        'pciecard' => [
            'card_type' => 'CardType',
            'attachables' => 'Attachables'
        ]
    ];

    return array_merge($commonFields, $specificFields[$type] ?? []);
}

/**
 * Build the WHERE clause + params for a component inventory search term.
 * Shared by getComponentsByType / getComponentCountByType so the row query
 * and the count query can never disagree on what matches.
 */
function buildComponentSearchWhere($search, &$params, $locationUuid = null, $pdo = null, $table = null, $status = null) {
    $clauses = [];

    if ($search !== '') {
        $term = '%' . addcslashes($search, '%_\\') . '%';
        $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
        $clauses[] = "(AssetTag LIKE ? OR SerialNumber LIKE ? OR UUID LIKE ? OR Notes LIKE ? OR Location LIKE ? OR RackPosition LIKE ?)";
    }

    // Filter by physical site. Reads the denormalised location_uuid rather than
    // joining out to racks and locations: that column is kept in sync on every
    // move (LocationResolver::syncConfig) precisely so this filter is one
    // indexed predicate instead of a four-table join repeated across 12 tables.
    //
    // Guarded, because the column arrives with seeder 2026_08_26_003 and this
    // code deploys ~20s after save. Without the column the filter is IGNORED
    // rather than applied wrongly -- the caller still gets components, just
    // unfiltered, which is what it got yesterday.
    if ($locationUuid !== null && $locationUuid !== '' && $pdo !== null && $table !== null) {
        if (SchemaHelper::hasColumn($pdo, $table, 'location_uuid')) {
            $clauses[] = "location_uuid = ?";
            $params[] = $locationUuid;
        }
    }

    // Filter by inventory status (0 failed, 1 available, 2 in_use). The
    // dashboard used to apply this in the browser over the CURRENT PAGE ONLY,
    // while search and location_uuid went server-side and pagination was
    // server-side too. "Show Failed" on page 1 of 40 therefore searched 50 rows
    // and the footer read "Showing 3 of 100" against a real total of 4,000.
    // Applied here, it filters the whole table and the count query agrees with
    // the row query by construction.
    //
    // Only the three defined values are honoured; anything else is IGNORED
    // rather than matched, so a malformed param cannot silently empty the page.
    if ($status !== null && $status !== '' && in_array((string)$status, ['0', '1', '2'], true)) {
        $clauses[] = "Status = ?";
        $params[] = (int)$status;
    }

    return empty($clauses) ? '' : 'WHERE ' . implode(' AND ', $clauses);
}

/**
 * Get components by type.
 * $limit === null preserves the original return-everything behavior.
 */
function getComponentsByType($pdo, $type, $limit = null, $offset = 0, $search = '', $locationUuid = null, $status = null) {
    $tableName = getComponentTableName($type);

    try {
        $params = [];
        $where = buildComponentSearchWhere($search, $params, $locationUuid, $pdo, $tableName, $status);

        $sql = "SELECT * FROM $tableName $where ORDER BY id DESC";
        if ($limit !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = (int)$limit;
            $params[] = max(0, (int)$offset);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting $type components from table $tableName: " . $e->getMessage());
        return [];
    }
}

/**
 * Count components of a type matching an optional search term.
 */
function getComponentCountByType($pdo, $type, $search = '', $locationUuid = null, $status = null) {
    $tableName = getComponentTableName($type);

    try {
        $params = [];
        $where = buildComponentSearchWhere($search, $params, $locationUuid, $pdo, $tableName, $status);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $tableName $where");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error counting $type components in table $tableName: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get component by ID
 */
function getComponentById($pdo, $type, $id) {
    $tableName = getComponentTableName($type);

    try {
        $stmt = $pdo->prepare("SELECT * FROM $tableName WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting $type component by ID from table $tableName: " . $e->getMessage());
        return null;
    }
}

/**
 * Return the real column list for an inventory table, keyed by lower-case
 * name for O(1) lookup. Used by addComponent/updateComponent to whitelist
 * incoming fields — anything the caller sends that isn't a real column in
 * the target table is dropped (not passed to PDO).
 *
 * Cached per-request in a static so we only hit INFORMATION_SCHEMA once
 * per table per request.
 */
function getInventoryTableColumns($pdo, $tableName) {
    static $cache = [];
    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$tableName]);
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Map lower-case → canonical. Callers filter case-insensitively and
    // then use the canonical casing in the generated SQL.
    $byLower = [];
    foreach ($columns as $col) {
        $byLower[strtolower($col)] = $col;
    }
    $cache[$tableName] = $byLower;
    return $byLower;
}

/**
 * Columns that must NEVER be settable through the component CRUD endpoint,
 * even if the caller has a matching form field. Primary keys and audit
 * timestamps belong to the system, not the client.
 *
 * KEPT, but no longer the boundary. [H-03 / F-10, 2026-09-13]
 *
 * A blacklist answers "what did we think of?" and this one had thought of
 * primary keys and timestamps. It had not thought of the columns that record
 * a unit's PLACE IN THE WORLD, so on 2026-09-13 a plain `cpu-add` carrying
 * `Status=2` and `ServerUUID=00000000-dead-beef-...` was accepted verbatim
 * (component 236, since removed) and a following `cpu-update` moved it to
 * `Status=0` while `status_v2` stayed `installed` — a unit that is
 * simultaneously failed, installed, and claimed by a configuration that does
 * not exist. Nothing downstream can reconcile that: the availability gate in
 * BaseCommand reads Status, the state machine reads status_v2, and the delete
 * guard reads ServerUUID.
 *
 * The boundary is now getEditableComponentColumns() below — an ALLOWLIST, so a
 * column added by a future seeder is un-writable by clients until someone
 * names it here on purpose.
 */
function getBlockedComponentColumns() {
    return [
        'id',               // primary key
        'assettag',         // system-issued unit identity — never client-settable
        'asset_tag',
        'created_at',
        'updated_at',
        'createdat',
        'updatedat',
    ];
}

/**
 * The columns a CLIENT may write on an inventory unit, per component type.
 *
 * Everything here is metadata an operator holding the unit actually knows:
 * what it is, what is written on it, where it is kept, what it cost, what we
 * think of it. Names are matched case-insensitively and intersected with the
 * table's real columns, so listing one a type does not have is harmless —
 * that is how `FailDate` can sit in the common set while only
 * serverplatforminventory carries it.
 *
 * Deliberately ABSENT, and why each belongs to the command layer instead:
 *
 *   ServerUUID            which configuration claims this unit. Written by
 *                         AddComponentCommand / ReplaceComponentCommand /
 *                         RemoveComponentCommand, inside the transaction that
 *                         locks the config. A client that can set it can hand
 *                         a unit to a server that never asked for it, or hide
 *                         a real claim from the delete guard.
 *   status_v2             derived from Status, always, in the same statement —
 *                         see applyComponentStatusPair().
 *   RackPosition          re-stamped from the real rack placement on every
 *                         move (ServerRelocation). The forms send it back
 *                         read-only; dropping it silently is exactly right.
 *   AssetTag, ID          system-issued identity.
 *   CreatedAt/UpdatedAt   audit.
 *   SourceType,           nicinventory's provenance for auto-imported onboard
 *   ParentComponentUUID,  NICs — set by the importer that created the row.
 *   ParentInventoryID,
 *   OnboardNICIndex
 *   ParentNICUUID,        sfpinventory's port binding — set by SFP assignment,
 *   PortIndex             which knows the NIC's real port count.
 *
 * UUID is absent too, but is handled separately: addComponent() accepts it once
 * (validated against the ims-data spec) and updateComponent() refuses it.
 *
 * @param string $type one of the 12 canonical component types
 * @return string[] lower-cased column names
 */
function getEditableComponentColumns($type) {
    $common = [
        'serialnumber',
        'status',            // constrained to {0,1} — see applyComponentStatusPair()
        'vendorid',
        'location',
        'location_uuid',
        'storelocation',
        'purchasedate',
        'installationdate',
        'warrantyenddate',
        'faildate',
        'flag',
        'notes',
    ];

    // Room for genuinely type-specific operator metadata. Empty today; the
    // point is that adding one is a decision made here rather than a column
    // that silently became writable because a seeder created it.
    $perType = [];

    $extra = $perType[strtolower((string)$type)] ?? [];
    return array_values(array_unique(array_merge($common, $extra)));
}

/**
 * Validate a client-supplied Status and derive status_v2 from it, in the one
 * statement that writes the row. [H-03 / F-10]
 *
 * Status is operator metadata for exactly two of its three values:
 *
 *   1 available — "this unit is on the shelf and fit to use"
 *   0 failed    — "this unit is dead"
 *
 * The third, 2 = in_use, is not an opinion an operator can hold; it is a
 * statement that some configuration has claimed this unit, and only
 * AddComponentCommand / ReplaceComponentCommand can make it true. Accepting it
 * from a form produced a unit that reports itself installed with no config
 * behind it, which every availability check then honours by refusing the unit
 * to the config that really wants it.
 *
 * The same reasoning runs the other way: a unit that IS claimed cannot be
 * edited back to available, because the claim would survive in
 * config_components while the inventory row advertised itself as free. Remove
 * the component from its configuration instead — that path releases both.
 *
 * @param array  $safeData    by reference; Status is validated, status_v2 added
 * @param array  $allowedCols lower => canonical, from getInventoryTableColumns()
 * @param array|null $existing current row (update only); null on insert
 * @throws InvalidArgumentException on a status the client may not set
 */
function applyComponentStatusPair(array &$safeData, array $allowedCols, ?array $existing = null) {
    require_once(__DIR__ . '/../models/state/StatusMap.php');

    $statusCol   = $allowedCols['status'] ?? null;
    $statusV2Col = $allowedCols['status_v2'] ?? null;

    $given = ($statusCol !== null && array_key_exists($statusCol, $safeData))
        ? (int)$safeData[$statusCol]
        : null;

    if ($given !== null) {
        if ($given === 2) {
            throw new InvalidArgumentException(
                "A unit becomes In Use by being installed in a server configuration, "
                . "not by being edited. Add it to the configuration instead."
            );
        }
        if ($given !== 0 && $given !== 1) {
            throw new InvalidArgumentException("Unknown status: $given");
        }

        $claimedBy = $existing === null ? null : trim((string)($existing['ServerUUID'] ?? ''));
        $wasInUse  = $existing !== null && (int)($existing['Status'] ?? -1) === 2;
        if ($wasInUse && $claimedBy !== '') {
            throw new InvalidArgumentException(
                "This unit is installed in configuration $claimedBy. Remove it from that "
                . "configuration to change its status."
            );
        }
    }

    if ($statusV2Col === null) {
        return;
    }

    // The pair rides in ONE statement or not at all. On insert with no Status
    // given, the column default (1) is what will land, so derive from that.
    $effective = $given;
    if ($effective === null) {
        if ($existing === null) {
            $effective = 1;
        } else {
            return; // update that does not touch Status leaves the pair alone
        }
    }
    if (array_key_exists($effective, StatusMap::INVENTORY_LEGACY_TO_V2)) {
        $safeData[$statusV2Col] = StatusMap::INVENTORY_LEGACY_TO_V2[$effective];
    }
}

/**
 * Add component
 *
 * Hardened against mass-assignment and UUID spoofing:
 *   1. INFORMATION_SCHEMA whitelist — only real columns of the target
 *      inventory table are accepted; extras are silently dropped.
 *   2. Blocked columns (id, created_at, ...) are stripped even if present.
 *   3. If a UUID is supplied it MUST exist in the ims-data JSON spec for
 *      this component type. No UUID? We generate one (legacy behaviour for
 *      virtual / custom records).
 */
function addComponent($pdo, $type, $data, $userId) {
    try {
        // Get the correct table name
        $tableName = getComponentTableName($type);

        // Get dynamic field mapping for this component type (snake_case →
        // CamelCase). Fields not in the map pass through untouched and
        // rely on the column whitelist below.
        $fieldMap = getComponentFieldMap($type);

        // Convert field names to match database columns
        $convertedData = [];
        foreach ($data as $key => $value) {
            $dbColumn = $fieldMap[$key] ?? $key;
            $convertedData[$dbColumn] = $value;
        }

        // A UUID is REQUIRED, and it is always checked against the catalog. [M-03]
        //
        // This used to generate one when the caller sent none. A UUID on an
        // inventory row is not that unit's identity — AssetTag is — it is WHICH
        // CATALOGUE PART the unit is, the key every compatibility rule resolves
        // specs through. A generated one names a part that does not exist, so the
        // unit could never be validated against anything, could never be matched
        // to a socket or a DIMM slot, and could not be explained to the person
        // holding it. The root-level contract says this check is never bypassed;
        // omitting the field was the bypass.
        //
        // Applies identically to the direct endpoint, bulk-add, and an approved
        // inventory.component.add Request — all three call this function, which
        // is what makes it ONE schema rather than three. [F-20]
        if (!isset($convertedData['UUID']) || !is_string($convertedData['UUID'])
            || trim($convertedData['UUID']) === '') {
            throw new InvalidArgumentException(
                "Choose which $type model this unit is — a catalog model is required."
            );
        }
        $convertedData['UUID'] = trim($convertedData['UUID']);

        // SECURITY: the UUID must reference a real component spec in ims-data/.
        require_once(__DIR__ . '/../models/components/ComponentDataService.php');
        $componentService = ComponentDataService::getInstance();
        if (!$componentService->validateComponentUuid($type, $convertedData['UUID'])) {
            throw new InvalidArgumentException(
                "Component UUID not found in $type specifications"
            );
        }

        // Serial policy, declared rather than assumed. [F-20]
        //
        // A unit with no readable serial is NORMAL here and always has been — a
        // worn label, a white-box part, a pull — and such a unit stays addressable
        // by its AssetTag, so a blanket serial requirement would refuse legitimate
        // stock. The types below are the ones whose serial is treated as
        // mandatory. The set is EMPTY on purpose: no such policy exists in this
        // system yet, and inventing one here would reject parts the owner can
        // actually hold in their hand. Name a type here when that policy is
        // decided, and this enforces it everywhere at once.
        $serialRequiredTypes = [];
        if (in_array(strtolower((string)$type), $serialRequiredTypes, true)
            && trim((string)($convertedData['SerialNumber'] ?? '')) === '') {
            throw new InvalidArgumentException(
                "A serial number is required for every $type unit."
            );
        }

        // Whitelist against real table columns, then against the columns a
        // client is allowed to write at all. [H-03 / F-10]
        $allowedCols = getInventoryTableColumns($pdo, $tableName);
        $blocked     = array_flip(getBlockedComponentColumns());
        $editable    = array_flip(getEditableComponentColumns($type));

        $safeData = [];
        foreach ($convertedData as $col => $value) {
            $lc = strtolower($col);
            if (isset($blocked[$lc]) || !isset($editable[$lc])) {
                continue;
            }
            if (!isset($allowedCols[$lc])) {
                // Unknown column — silently drop rather than error so legacy
                // clients sending extra fields don't break.
                continue;
            }
            // Use the canonical casing from the DB schema
            $safeData[$allowedCols[$lc]] = $value;
        }

        // UUID is not in the editable set — it is not metadata, it is which
        // catalog part this unit IS — but insert is the one moment it is
        // legitimately client-supplied, and it has already been generated or
        // validated against the ims-data spec above.
        if (isset($allowedCols['uuid'])) {
            $safeData[$allowedCols['uuid']] = $convertedData['UUID'];
        }

        // A unit with no readable manufacturer serial is normal (worn label,
        // white-box part, pull) — store that absence as NULL, never ''.
        // SerialNumber carries a UNIQUE index: MySQL permits many NULLs but only
        // ONE ''. An empty string from the form would therefore let the first
        // serial-less unit save and make the *second* one die on a duplicate-key
        // error. The unit stays addressable either way via its AssetTag.
        if (array_key_exists('SerialNumber', $safeData)
            && trim((string)$safeData['SerialNumber']) === '') {
            $safeData['SerialNumber'] = null;
        }

        // status_v2 must be BORN paired with Status. [F-21]
        //
        // This function builds its INSERT purely from caller-supplied, whitelisted
        // columns, and no caller has ever supplied status_v2 -- so every unit added
        // since seeder 2026_07_10_001 created the column landed with status_v2 NULL
        // while Status took its own column default of 1. Production held 22 such
        // rows on 2026-07-27 (21 cpuinventory, 1 pciecardinventory), one more per
        // component the owner adds.
        //
        // It stayed invisible because inventory_report's status_v2/Status agreement
        // check deliberately inspects only rows WHERE status_v2 IS NOT NULL, so an
        // unmigrated row was excused rather than flagged; and NULL is not a member
        // of StatusMap::INVENTORY_V2_TO_LEGACY, so the state machine cannot read
        // such a unit's state at all once it stops deferring to legacy Status.
        //
        // Same defect class as F-14 (OnboardNICHandler wrote Status with raw UPDATEs
        // and never touched status_v2) and the same fix: the pair rides in ONE
        // statement, so no window exists in which a row has one without the other.
        // Derivation AND the {0,1} constraint now live in one shared helper so
        // update cannot drift from insert the way it had. [H-03 / F-10]
        applyComponentStatusPair($safeData, $allowedCols, null);

        if (empty($safeData)) {
            throw new InvalidArgumentException("No valid fields provided for $type component");
        }

        // A unit with no location is a unit nobody can go and find. Every way a
        // component enters inventory lands here -- the Add Component form,
        // bulk-add, and an approved inventory.component.add request -- so this
        // function is the one place the rule has to exist.
        //
        // Either column satisfies it. The Location dropdown posts the site NAME
        // and its location_uuid together, so an operator sees a single field;
        // accepting the name on its own keeps a caller that knows only the
        // legacy free-text column working.
        $locationNameCol = $allowedCols['location'] ?? null;
        $locationUuidCol = $allowedCols['location_uuid'] ?? null;
        $hasLocation =
            ($locationUuidCol !== null && trim((string)($safeData[$locationUuidCol] ?? '')) !== '')
            || ($locationNameCol !== null && trim((string)($safeData[$locationNameCol] ?? '')) !== '');
        if (!$hasLocation) {
            throw new InvalidArgumentException(
                "A location is required — select the site this component is at."
            );
        }

        // Defence in depth: even though $safeData keys come from
        // INFORMATION_SCHEMA we keep the identifier regex as a belt-and-
        // braces check before the column names hit the SQL string.
        $columns = array_keys($safeData);
        foreach ($columns as $col) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                throw new InvalidArgumentException("Invalid column name: $col");
            }
        }
        $placeholders = array_fill(0, count($columns), '?');
        $values = array_values($safeData);

        $sql = "INSERT INTO $tableName (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        error_log("Inserting $type data into $tableName: " . json_encode(array_keys($safeData)));

        // The row and its asset tag must land together: a row that committed
        // without a tag would be a unit the system cannot name. The tag is
        // derived from the auto-increment ID, so it can only be written after
        // the INSERT — hence insert + tag in one transaction.
        //
        // Only own the transaction if no caller already opened one (import and
        // build flows call this inside their own); committing someone else's
        // transaction here would publish their half-finished work.
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare($sql);

            if (!$stmt->execute($values)) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return false;
            }

            $newId = (int)$pdo->lastInsertId();
            $assetTag = formatAssetTag($type, $newId);

            $tagStmt = $pdo->prepare("UPDATE `$tableName` SET AssetTag = ? WHERE ID = ?");
            $tagStmt->execute([$assetTag, $newId]);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'id' => $newId,
                'uuid' => $safeData['UUID'] ?? ($convertedData['UUID'] ?? null),
                'asset_tag' => $assetTag
            ];

        } catch (PDOException $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // A duplicate serial is a data-entry mistake, not a server fault:
            // report it as a 400 naming the unit already holding that serial so
            // the operator can go look at it, instead of the bare 500 the raw
            // PDOException used to produce. Anything else is a real error and
            // keeps propagating.
            throw describeDuplicateComponentKey($pdo, $tableName, $type, $safeData, $e);
        } catch (Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

    } catch (InvalidArgumentException $e) {
        throw $e;
    } catch (Exception $e) {
        error_log("Error adding $type component: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Update component
 *
 * Same whitelist rules as addComponent. Additionally forbids changing the
 * UUID — once an inventory record is tied to a spec it should not be
 * retargeted via a PATCH.
 */
function updateComponent($pdo, $type, $id, $data, $userId) {
    try {
        // Get the correct table name
        $tableName = getComponentTableName($type);

        // Get dynamic field mapping for this component type
        $fieldMap = getComponentFieldMap($type);

        // Convert field names to match database columns
        $convertedData = [];
        foreach ($data as $key => $value) {
            $dbColumn = $fieldMap[$key] ?? $key;
            $convertedData[$dbColumn] = $value;
        }

        // Whitelist against real table columns, then against the client-editable
        // set. [H-03 / F-10] UUID is fine to set on insert but must not change
        // on update, and it is not in the editable set either way.
        $allowedCols = getInventoryTableColumns($pdo, $tableName);
        $blocked     = array_flip(getBlockedComponentColumns());
        $blocked['uuid'] = true;
        $editable    = array_flip(getEditableComponentColumns($type));

        $safeData = [];
        foreach ($convertedData as $col => $value) {
            $lc = strtolower($col);
            if (isset($blocked[$lc]) || !isset($editable[$lc])) {
                continue;
            }
            if (!isset($allowedCols[$lc])) {
                continue;
            }
            $safeData[$allowedCols[$lc]] = $value;
        }

        // Same NULL-not-'' rule as addComponent: clearing the serial on an edit
        // must store NULL. SerialNumber is UNIQUE and MySQL allows many NULLs but
        // only one '', so without this the first unit edited to a blank serial
        // succeeds and every later one dies on a duplicate key.
        if (array_key_exists('SerialNumber', $safeData)
            && trim((string)$safeData['SerialNumber']) === '') {
            $safeData['SerialNumber'] = null;
        }

        if (empty($safeData)) {
            throw new InvalidArgumentException("No valid fields provided for $type update");
        }

        // The status decision needs the row's CURRENT claim, and that claim can
        // change under us — a build can install this unit between the read and
        // the write — so read it locked and hold the lock through the UPDATE.
        // [H-03 / F-10]
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $existingStmt = $pdo->prepare("SELECT * FROM $tableName WHERE ID = ? FOR UPDATE");
            $existingStmt->execute([$id]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new InvalidArgumentException("Component not found");
            }

            applyComponentStatusPair($safeData, $allowedCols, $existing);

            $columns = array_keys($safeData);
            foreach ($columns as $col) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                    throw new InvalidArgumentException("Invalid column name: $col");
                }
            }
            $setClause = implode(' = ?, ', $columns) . ' = ?';
            $values = array_values($safeData);
            $values[] = $id; // Add ID for WHERE clause

            $sql = "UPDATE $tableName SET $setClause WHERE ID = ?";

            $stmt = $pdo->prepare($sql);
            $ok = $stmt->execute($values);

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $ok;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

    } catch (InvalidArgumentException $e) {
        throw $e;
    } catch (PDOException $e) {
        error_log("Error updating $type component: " . $e->getMessage());
        // $safeData is unset if the failure came from the column lookup, before
        // it was built — a duplicate key cannot be the cause in that case.
        throw describeDuplicateComponentKey($pdo, $tableName, $type, isset($safeData) ? $safeData : [], $e);
    } catch (Exception $e) {
        error_log("Error updating $type component: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Thrown when a delete is refused because a configuration still depends on the
 * unit. Its message is safe to show a caller — it names configurations, never
 * SQL, paths or credentials.
 *
 * NOT a PDOException subclass on purpose: PDOException also extends
 * RuntimeException, so a handler catching RuntimeException to surface this
 * message would otherwise leak driver text on an unrelated DB error.
 */
class ComponentInUseException extends RuntimeException {}

/**
 * Delete component.
 *
 * GUARDED, and the guard is the point. A bare DELETE here is how BACKLOG §B-16
 * happened: an inventory row was destroyed while a live config_components row
 * still claimed it, leaving configuration 1f61541b displaying an SFP that no
 * longer exists. Since U-D.3 dropped the legacy JSON columns, config_components
 * is the ONLY record of what a configuration contains — that row *is* the
 * dependency, and nothing else records it.
 *
 * Fail-closed (INV-5): if the claim cannot be read, the delete is refused. A
 * unit that might be in use is never destroyed on the strength of a query that
 * did not answer.
 *
 * Matched on (inventory_table, inventory_id), never on component_type: one
 * serverplatform unit is claimed by BOTH a motherboard row and a chassis row,
 * so only the table identifies the physical unit.
 *
 * Deliberately NOT keyed on Status or ServerUUID. Those drift — BACKLOG §B-9
 * has 28 status mismatches and 4 units sitting at Status=2 with no server — so
 * keying on them would refuse deletes whose real problem is a stale status
 * column. A live config_components row is unambiguous: a configuration depends
 * on this unit.
 */
function deleteComponent($pdo, $type, $id, $userId) {
    $tableName = getComponentTableName($type);
    $id = (int)$id;

    // The check and the delete are ONE decision, so they are one transaction. [M-04]
    //
    // They used to be two unlocked statements with a gap between them, and an
    // install fits in that gap: read "nobody claims this unit", a build claims
    // it, DELETE. The inventory row is gone and config_components still points at
    // it — an orphan claim, which until today validated as deployable (see
    // SystemInventoryStateRule / M-13) and now blocks the build it poisoned.
    //
    // LOCK ORDER matches AddComponentCommand's: it takes the config lock, then
    // the inventory row (lockAndCheckComponent). So an add cannot write a
    // config_components row for this unit without first waiting on the inventory
    // row lock taken below — which means that once we hold it, the claim read is
    // stable and needs no second lock, and there is no cycle to deadlock on.
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $lock = $pdo->prepare("SELECT ID FROM $tableName WHERE ID = ? FOR UPDATE");
        $lock->execute([$id]);
        if ($lock->fetchColumn() === false) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return false;   // already gone; nothing to delete and nothing to report
        }

        try {
            $claim = $pdo->prepare(
                "SELECT DISTINCT config_uuid
                   FROM config_components
                  WHERE inventory_table = ? AND inventory_id = ? AND removed_at IS NULL
                  ORDER BY config_uuid"
            );
            $claim->execute([$tableName, $id]);
            $claimedBy = $claim->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            error_log("Error reading configuration claims before deleting $type #$id: " . $e->getMessage());
            throw new ComponentInUseException(
                "Cannot verify whether this $type is installed in a server configuration. Delete refused."
            );
        }

        if (!empty($claimedBy)) {
            $noun = count($claimedBy) === 1 ? 'configuration' : 'configurations';
            throw new ComponentInUseException(
                "This $type is installed in server $noun " . implode(', ', $claimedBy)
                . ". Remove it from the $noun before deleting it."
            );
        }

        $stmt = $pdo->prepare("DELETE FROM $tableName WHERE ID = ?");
        $ok = $stmt->execute([$id]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $ok;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!($e instanceof ComponentInUseException)) {
            error_log("Error deleting $type component from table $tableName: " . $e->getMessage());
        }
        throw $e;
    }
}
