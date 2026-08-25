<?php
/**
 * Location module handler — physical sites.
 * File: api/handlers/location/location_api.php
 *
 * Included by api/api.php after JWT auth + ACL gate. The concrete operation
 * arrives via $GLOBALS['operation'] (e.g. 'racks' for location-racks).
 *
 * Data model (seeders 2026_08_26_001 / _003):
 *   locations               — one physical site (name, description, address, lat/lng)
 *   racks.location_uuid     — which site a rack stands in; racks.floor within it
 *   server_configurations   — .location_uuid, authored only while UNRACKED
 *   {type}inventory         — .location_uuid (+ .StoreLocation shelf) for loose stock
 *
 * READS ARE BROADLY PERMISSIONED, WRITES ARE NOT.
 *   Unlike the rack module -- which api.php role-gates whole -- only create,
 *   update and delete here are restricted to admin/super_admin. list/get/racks
 *   need to stay open because the Add Component form, the Create Server form,
 *   the Bulk Update dialog and the location filter on every inventory page all
 *   render a location dropdown, and an ordinary user who cannot read it cannot
 *   file a component at all. Location names are not sensitive; the shape of the
 *   estate is already visible to anyone who can see a rack.
 *
 * DELETING IS REFUSED WHILE ANYTHING POINTS AT A LOCATION.
 *   There are no foreign keys (house convention), so a delete would succeed and
 *   leave dangling identifiers on racks, servers and components -- all of which
 *   would then render "No location" with no way to recover what they said.
 *   location-delete counts the references, names them in the refusal, and offers
 *   reassign_to as the way through.
 */

require_once __DIR__ . '/../../../core/config/app.php';
require_once __DIR__ . '/../../../core/helpers/BaseFunctions.php';
require_once __DIR__ . '/../../../core/helpers/SchemaHelper.php';
require_once __DIR__ . '/../../../core/models/location/LocationResolver.php';

header('Content-Type: application/json');

global $pdo, $user, $operation;

if (!$pdo) {
    send_json_response(0, 1, 500, "Database connection not available");
}

// The whole module depends on a table added by a hand-run seeder. Saying so is
// far more useful than the 500 a missing table would otherwise produce ~20s
// after the code deploys and before the seeder is applied.
if (!SchemaHelper::hasTable($pdo, 'locations')) {
    send_json_response(0, 1, 503,
        "Locations are not available yet — the database migration for this feature has not been applied.");
}

$action = $operation ?? '';

switch ($action) {
    case 'list':
        handleLocationList($pdo, $user);
        break;
    case 'get':
        handleLocationGet($pdo, $user);
        break;
    case 'racks':
        handleLocationRacks($pdo, $user);
        break;
    case 'create':
        handleLocationCreate($pdo, $user);
        break;
    case 'update':
        handleLocationUpdate($pdo, $user);
        break;
    case 'delete':
        handleLocationDelete($pdo, $user);
        break;
    default:
        send_json_response(0, 1, 400, "Invalid location operation: $action");
}

/* ============================================================
 * Helpers
 * ============================================================ */

/**
 * Fetch a location row by uuid, or null.
 */
function locationFetchByUuid($pdo, $locationUuid) {
    $stmt = $pdo->prepare("SELECT * FROM locations WHERE location_uuid = ? LIMIT 1");
    $stmt->execute([$locationUuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Shape one location row for the API. Coordinates are emitted as floats or
 * null, never as the "0.0000000" that a bare cast would produce -- the Locations
 * page has to be able to tell "not recorded" from "the Gulf of Guinea".
 */
function locationShape(array $r) {
    return [
        'location_uuid' => $r['location_uuid'],
        'name'          => $r['name'],
        'description'   => $r['description'],
        'address'       => $r['address'],
        'latitude'      => $r['latitude']  !== null ? (float)$r['latitude']  : null,
        'longitude'     => $r['longitude'] !== null ? (float)$r['longitude'] : null,
        'is_active'     => (int)$r['is_active'],
        'notes'         => $r['notes'],
        'created_at'    => $r['created_at'],
        'updated_at'    => $r['updated_at'],
    ];
}

/**
 * How many racks / servers / components sit at each location.
 *
 * One grouped query per table rather than per location, so the cost is a fixed
 * 14 queries whether there are two locations or two hundred. Every one is
 * probed first and failures are swallowed per table: a count is a nicety, and
 * an unmigrated inventory table must not take down the Locations page.
 *
 * @return array location_uuid => ['racks'=>int,'servers'=>int,'components'=>int]
 */
function locationObjectCounts($pdo) {
    $counts = [];

    $bump = function (&$counts, $uuid, $key, $n) {
        if (empty($uuid)) {
            return;
        }
        if (!isset($counts[$uuid])) {
            $counts[$uuid] = ['racks' => 0, 'servers' => 0, 'components' => 0];
        }
        $counts[$uuid][$key] += (int)$n;
    };

    if (SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid')) {
        try {
            $q = $pdo->query("SELECT location_uuid, COUNT(*) AS c FROM racks
                               WHERE location_uuid IS NOT NULL GROUP BY location_uuid");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bump($counts, $row['location_uuid'], 'racks', $row['c']);
            }
        } catch (Throwable $e) {
            error_log("locationObjectCounts racks error: " . $e->getMessage());
        }
    }

    if (SchemaHelper::hasColumn($pdo, 'server_configurations', 'location_uuid')) {
        try {
            $q = $pdo->query("SELECT location_uuid, COUNT(*) AS c FROM server_configurations
                               WHERE location_uuid IS NOT NULL GROUP BY location_uuid");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bump($counts, $row['location_uuid'], 'servers', $row['c']);
            }
        } catch (Throwable $e) {
            error_log("locationObjectCounts servers error: " . $e->getMessage());
        }
    }

    foreach (LocationResolver::COMPONENT_TYPES as $type) {
        $table = $type . 'inventory';
        if (!SchemaHelper::hasTable($pdo, $table) || !SchemaHelper::hasColumn($pdo, $table, 'location_uuid')) {
            continue;
        }
        try {
            $q = $pdo->query("SELECT location_uuid, COUNT(*) AS c FROM `{$table}`
                               WHERE location_uuid IS NOT NULL GROUP BY location_uuid");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bump($counts, $row['location_uuid'], 'components', $row['c']);
            }
        } catch (Throwable $e) {
            error_log("locationObjectCounts {$table} error: " . $e->getMessage());
        }
    }

    return $counts;
}

/**
 * Parse an optional coordinate. Returns [ok, value|message].
 *
 * An empty string clears the value -- the Locations form submits every field,
 * so "" has to mean "not recorded" rather than 0.
 */
function locationParseCoordinate($raw, $label, $limit) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [true, null];
    }
    if (!is_numeric($raw)) {
        return [false, "{$label} must be a number"];
    }
    $val = (float)$raw;
    if ($val < -$limit || $val > $limit) {
        return [false, "{$label} must be between -{$limit} and {$limit}"];
    }
    return [true, $val];
}

/* ============================================================
 * Handlers
 * ============================================================ */

/**
 * List locations. Pass include_counts=1 for the "Objects" column.
 */
function handleLocationList($pdo, $user) {
    $includeCounts = filter_var($_POST['include_counts'] ?? $_GET['include_counts'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $includeInactive = filter_var($_POST['include_inactive'] ?? $_GET['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);

    try {
        $sql = "SELECT * FROM locations";
        if (!$includeInactive) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY name ASC";

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $counts = $includeCounts ? locationObjectCounts($pdo) : [];

        $locations = array_map(function ($r) use ($includeCounts, $counts) {
            $shaped = locationShape($r);
            if ($includeCounts) {
                $c = $counts[$r['location_uuid']] ?? ['racks' => 0, 'servers' => 0, 'components' => 0];
                $shaped['racks']       = $c['racks'];
                $shaped['servers']     = $c['servers'];
                $shaped['components']  = $c['components'];
                $shaped['object_count'] = $c['racks'] + $c['servers'] + $c['components'];
            }
            return $shaped;
        }, $rows);

        send_json_response(1, 1, 200, "Locations retrieved successfully", ['locations' => $locations]);
    } catch (Throwable $e) {
        error_log("handleLocationList error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to list locations");
    }
}

/**
 * One location, with its racks and its object counts.
 */
function handleLocationGet($pdo, $user) {
    $locationUuid = $_POST['location_uuid'] ?? $_GET['location_uuid'] ?? '';
    if (empty($locationUuid)) {
        send_json_response(0, 1, 400, "location_uuid is required");
    }

    try {
        $row = locationFetchByUuid($pdo, $locationUuid);
        if (!$row) {
            send_json_response(0, 1, 404, "Location not found");
        }

        $counts = locationObjectCounts($pdo);
        $c = $counts[$locationUuid] ?? ['racks' => 0, 'servers' => 0, 'components' => 0];

        $location = locationShape($row);
        $location['racks_count']      = $c['racks'];
        $location['servers_count']    = $c['servers'];
        $location['components_count'] = $c['components'];

        send_json_response(1, 1, 200, "Location retrieved successfully", [
            'location' => $location,
            'racks'    => locationRacksFor($pdo, $locationUuid),
        ]);
    } catch (Throwable $e) {
        error_log("handleLocationGet error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to retrieve location");
    }
}

/**
 * The racks at one location, with occupancy — this is what makes the Move
 * dialog's Rack dropdown repopulate when the Location changes.
 */
function handleLocationRacks($pdo, $user) {
    $locationUuid = $_POST['location_uuid'] ?? $_GET['location_uuid'] ?? '';
    if (empty($locationUuid)) {
        send_json_response(0, 1, 400, "location_uuid is required");
    }

    try {
        send_json_response(1, 1, 200, "Racks retrieved successfully", [
            'location_uuid' => $locationUuid,
            'racks'         => locationRacksFor($pdo, $locationUuid),
        ]);
    } catch (Throwable $e) {
        error_log("handleLocationRacks error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to list racks for this location");
    }
}

/**
 * Racks at a location with used/free U, shaped like rack-list so the frontend
 * can render either through the same code path.
 */
function locationRacksFor($pdo, $locationUuid) {
    if (!SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid')) {
        return [];
    }

    $hasFloor = SchemaHelper::hasColumn($pdo, 'racks', 'floor');
    $floorSel = $hasFloor ? 'r.floor' : 'NULL AS floor';

    $stmt = $pdo->prepare("
        SELECT r.rack_uuid, r.name, r.location, r.location_uuid, {$floorSel},
               r.total_u, r.numbering_top_down, r.notes,
               COALESCE(o.server_count, 0) AS server_count,
               COALESCE(o.used_u, 0)       AS used_u
          FROM racks r
          LEFT JOIN (SELECT rack_uuid, COUNT(*) AS server_count,
                            COALESCE(SUM(u_height), 0) AS used_u
                       FROM rack_servers GROUP BY rack_uuid) o
                 ON o.rack_uuid = r.rack_uuid
         WHERE r.location_uuid = ?
         ORDER BY r.name ASC
    ");
    $stmt->execute([$locationUuid]);

    return array_map(function ($r) {
        return [
            'rack_uuid'          => $r['rack_uuid'],
            'name'               => $r['name'],
            'location'           => $r['location'],
            'location_uuid'      => $r['location_uuid'],
            'floor'              => $r['floor'],
            'total_u'            => (int)$r['total_u'],
            'numbering_top_down' => (int)$r['numbering_top_down'],
            'notes'              => $r['notes'],
            'server_count'       => (int)$r['server_count'],
            'used_u'             => (int)$r['used_u'],
            'free_u'             => max(0, (int)$r['total_u'] - (int)$r['used_u']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Create a location.
 */
function handleLocationCreate($pdo, $user) {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');

    if ($name === '') {
        send_json_response(0, 1, 400, "Location name is required");
    }
    if (mb_strlen($name) > 100) {
        send_json_response(0, 1, 400, "Location name must be 100 characters or fewer");
    }

    list($latOk, $lat) = locationParseCoordinate($_POST['latitude']  ?? '', 'Latitude',  90);
    if (!$latOk) { send_json_response(0, 1, 400, $lat); }
    list($lngOk, $lng) = locationParseCoordinate($_POST['longitude'] ?? '', 'Longitude', 180);
    if (!$lngOk) { send_json_response(0, 1, 400, $lng); }

    try {
        // The UNIQUE key would catch this, but a named refusal beats a 500 and
        // tells the user which existing site they meant.
        $dup = $pdo->prepare("SELECT location_uuid, is_active FROM locations WHERE name = ? LIMIT 1");
        $dup->execute([$name]);
        if ($existing = $dup->fetch(PDO::FETCH_ASSOC)) {
            $hint = (int)$existing['is_active'] === 1
                ? "A location named \"{$name}\" already exists."
                : "A retired location named \"{$name}\" already exists — reactivate it instead of creating a second one.";
            send_json_response(0, 1, 409, $hint, ['location_uuid' => $existing['location_uuid']]);
        }

        $locationUuid = generateUUID();
        $stmt = $pdo->prepare("
            INSERT INTO locations
                (location_uuid, name, description, address, latitude, longitude,
                 is_active, notes, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $locationUuid,
            $name,
            $description !== '' ? $description : null,
            $address     !== '' ? $address     : null,
            $lat,
            $lng,
            $notes       !== '' ? $notes       : null,
            $user['id'],
        ]);

        logActivity($pdo, $user['id'], 'Location created', 'location', null, "Created location: {$name}");

        send_json_response(1, 1, 200, "Location created successfully", [
            'location' => locationShape(locationFetchByUuid($pdo, $locationUuid)),
        ]);
    } catch (Throwable $e) {
        error_log("handleLocationCreate error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to create location");
    }
}

/**
 * Update a location.
 *
 * Renaming propagates: the legacy `location` / `Location` text columns on racks,
 * configs and inventory are a cache of this name, so every rack at this site is
 * re-synced afterwards. Without that, a rename would leave 200 components
 * quoting the old name back at you.
 */
function handleLocationUpdate($pdo, $user) {
    $locationUuid = $_POST['location_uuid'] ?? '';
    if (empty($locationUuid)) {
        send_json_response(0, 1, 400, "location_uuid is required");
    }

    try {
        $existing = locationFetchByUuid($pdo, $locationUuid);
        if (!$existing) {
            send_json_response(0, 1, 404, "Location not found");
        }

        $fields = [];
        $values = [];
        $renamed = false;

        if (isset($_POST['name'])) {
            $name = trim($_POST['name']);
            if ($name === '') {
                send_json_response(0, 1, 400, "Location name cannot be empty");
            }
            if (mb_strlen($name) > 100) {
                send_json_response(0, 1, 400, "Location name must be 100 characters or fewer");
            }
            if ($name !== $existing['name']) {
                $dup = $pdo->prepare("SELECT 1 FROM locations WHERE name = ? AND location_uuid <> ? LIMIT 1");
                $dup->execute([$name, $locationUuid]);
                if ($dup->fetch()) {
                    send_json_response(0, 1, 409, "Another location is already named \"{$name}\"");
                }
                $renamed = true;
            }
            $fields[] = 'name = ?';
            $values[] = $name;
        }

        foreach (['description' => 255, 'address' => 255] as $col => $max) {
            if (isset($_POST[$col])) {
                $val = trim($_POST[$col]);
                if (mb_strlen($val) > $max) {
                    send_json_response(0, 1, 400, ucfirst($col) . " must be {$max} characters or fewer");
                }
                $fields[] = "{$col} = ?";
                $values[] = $val !== '' ? $val : null;
            }
        }

        if (isset($_POST['notes'])) {
            $notes = trim($_POST['notes']);
            $fields[] = 'notes = ?';
            $values[] = $notes !== '' ? $notes : null;
        }

        if (isset($_POST['latitude'])) {
            list($ok, $lat) = locationParseCoordinate($_POST['latitude'], 'Latitude', 90);
            if (!$ok) { send_json_response(0, 1, 400, $lat); }
            $fields[] = 'latitude = ?';
            $values[] = $lat;
        }
        if (isset($_POST['longitude'])) {
            list($ok, $lng) = locationParseCoordinate($_POST['longitude'], 'Longitude', 180);
            if (!$ok) { send_json_response(0, 1, 400, $lng); }
            $fields[] = 'longitude = ?';
            $values[] = $lng;
        }
        if (isset($_POST['is_active'])) {
            $fields[] = 'is_active = ?';
            $values[] = filter_var($_POST['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        if (empty($fields)) {
            send_json_response(1, 1, 200, "No changes provided", ['location_uuid' => $locationUuid]);
        }

        $values[] = $locationUuid;
        $stmt = $pdo->prepare("UPDATE locations SET " . implode(', ', $fields)
            . ", updated_at = NOW() WHERE location_uuid = ?");
        $stmt->execute($values);

        // Re-stamp the cached name everywhere it was copied to.
        $resynced = ['configs' => 0, 'components' => 0];
        if ($renamed) {
            $resynced = locationResyncAfterRename($pdo, $locationUuid);
        }

        logActivity($pdo, $user['id'], 'Location updated', 'location', null,
            "Updated location: " . $existing['name']);

        send_json_response(1, 1, 200, "Location updated successfully", [
            'location' => locationShape(locationFetchByUuid($pdo, $locationUuid)),
            'resynced' => $resynced,
        ]);
    } catch (Throwable $e) {
        error_log("handleLocationUpdate error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to update location");
    }
}

/**
 * After a rename: rewrite the cached name on every rack at this location, then
 * on every server in those racks and every component in those servers.
 */
function locationResyncAfterRename($pdo, $locationUuid) {
    $totals = ['configs' => 0, 'components' => 0];

    if (!SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid')) {
        return $totals;
    }

    try {
        $name = LocationResolver::locationName($pdo, $locationUuid);

        // The rack's own legacy text column.
        $upd = $pdo->prepare("UPDATE racks SET location = ?, updated_at = NOW() WHERE location_uuid = ?");
        $upd->execute([$name, $locationUuid]);

        $stmt = $pdo->prepare("SELECT rack_uuid FROM racks WHERE location_uuid = ?");
        $stmt->execute([$locationUuid]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rackUuid) {
            $r = LocationResolver::syncRack($pdo, $rackUuid);
            $totals['configs']    += $r['configs'];
            $totals['components'] += $r['components'];
        }
    } catch (Throwable $e) {
        error_log("locationResyncAfterRename error: " . $e->getMessage());
    }

    return $totals;
}

/**
 * Delete a location. Refused while anything still points at it, unless
 * reassign_to names another location to move those references to first.
 */
function handleLocationDelete($pdo, $user) {
    $locationUuid = $_POST['location_uuid'] ?? '';
    $reassignTo   = trim($_POST['reassign_to'] ?? '');

    if (empty($locationUuid)) {
        send_json_response(0, 1, 400, "location_uuid is required");
    }

    try {
        $existing = locationFetchByUuid($pdo, $locationUuid);
        if (!$existing) {
            send_json_response(0, 1, 404, "Location not found");
        }

        if ($reassignTo !== '') {
            if ($reassignTo === $locationUuid) {
                send_json_response(0, 1, 400, "Cannot reassign a location to itself");
            }
            if (!locationFetchByUuid($pdo, $reassignTo)) {
                send_json_response(0, 1, 404, "The location to reassign to was not found");
            }
            locationReassignReferences($pdo, $locationUuid, $reassignTo);
        }

        $counts = locationObjectCounts($pdo);
        $c = $counts[$locationUuid] ?? ['racks' => 0, 'servers' => 0, 'components' => 0];
        $total = $c['racks'] + $c['servers'] + $c['components'];

        if ($total > 0) {
            $bits = [];
            if ($c['racks'])      { $bits[] = $c['racks']      . ' rack'      . ($c['racks'] === 1 ? '' : 's'); }
            if ($c['servers'])    { $bits[] = $c['servers']    . ' server'    . ($c['servers'] === 1 ? '' : 's'); }
            if ($c['components']) { $bits[] = $c['components'] . ' component' . ($c['components'] === 1 ? '' : 's'); }

            send_json_response(0, 1, 409,
                "Cannot delete \"{$existing['name']}\" — it still holds " . implode(', ', $bits)
                . ". Move them to another location first, or retire this one instead of deleting it.",
                ['racks' => $c['racks'], 'servers' => $c['servers'], 'components' => $c['components']]);
        }

        $stmt = $pdo->prepare("DELETE FROM locations WHERE location_uuid = ?");
        $stmt->execute([$locationUuid]);

        logActivity($pdo, $user['id'], 'Location deleted', 'location', null,
            "Deleted location: " . $existing['name']);

        send_json_response(1, 1, 200, "Location deleted successfully", ['location_uuid' => $locationUuid]);
    } catch (Throwable $e) {
        error_log("handleLocationDelete error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to delete location");
    }
}

/**
 * Point every rack, server and component at a different location.
 *
 * Racks move first, then each rack is re-synced so the servers and components
 * inside it inherit the new site through the normal derivation rather than a
 * separate UPDATE that could disagree with it. Loose stock -- which has no rack
 * to inherit from -- is repointed directly.
 */
function locationReassignReferences($pdo, $fromUuid, $toUuid) {
    $toName = LocationResolver::locationName($pdo, $toUuid);

    if (SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid')) {
        $stmt = $pdo->prepare("UPDATE racks SET location_uuid = ?, location = ?, updated_at = NOW()
                                WHERE location_uuid = ?");
        $stmt->execute([$toUuid, $toName, $fromUuid]);

        $racks = $pdo->prepare("SELECT rack_uuid FROM racks WHERE location_uuid = ?");
        $racks->execute([$toUuid]);
        foreach ($racks->fetchAll(PDO::FETCH_COLUMN) as $rackUuid) {
            LocationResolver::syncRack($pdo, $rackUuid);
        }
    }

    // Unracked servers, whose location is authored rather than derived.
    if (SchemaHelper::hasColumn($pdo, 'server_configurations', 'location_uuid')) {
        $stmt = $pdo->prepare("UPDATE server_configurations SET location_uuid = ?, location = ?
                                WHERE location_uuid = ?");
        $stmt->execute([$toUuid, $toName, $fromUuid]);
    }

    // Loose stock.
    foreach (LocationResolver::COMPONENT_TYPES as $type) {
        $table = $type . 'inventory';
        if (!SchemaHelper::hasTable($pdo, $table) || !SchemaHelper::hasColumn($pdo, $table, 'location_uuid')) {
            continue;
        }
        try {
            $stmt = $pdo->prepare("UPDATE `{$table}` SET location_uuid = ?, Location = ?, UpdatedAt = NOW()
                                    WHERE location_uuid = ?");
            $stmt->execute([$toUuid, $toName, $fromUuid]);
        } catch (Throwable $e) {
            error_log("locationReassignReferences error on {$table}: " . $e->getMessage());
        }
    }
}
