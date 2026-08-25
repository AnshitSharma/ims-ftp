<?php
/**
 * Rack module handler — physical racks and server placement (Rack View).
 * File: api/handlers/rack/rack_api.php
 *
 * Included by api/api.php after JWT auth + ACL gate. The concrete operation
 * is passed via $GLOBALS['operation'] (e.g. 'assign-server' for rack-assign-server).
 *
 * Data model (see seeder 2026_06_17_001):
 *   racks         — one physical rack (name, location, total_u, numbering)
 *   rack_servers  — placement of a server_configuration at a U-position
 *
 * A server's u_height is derived from its chassis u_size at assignment time
 * and stored as a snapshot. U-range overlaps are enforced here in PHP.
 */

require_once __DIR__ . '/../../../core/config/app.php';
require_once __DIR__ . '/../../../core/helpers/BaseFunctions.php';
require_once __DIR__ . '/../../../core/models/chassis/ChassisManager.php';
require_once __DIR__ . '/../../../core/models/rack/RackPlacement.php';
require_once __DIR__ . '/../../../core/models/rack/ServerRelocation.php';
require_once __DIR__ . '/../../../core/models/location/LocationResolver.php';
require_once __DIR__ . '/../../../core/helpers/SchemaHelper.php';

header('Content-Type: application/json');

global $pdo, $user, $operation;

if (!$pdo) {
    send_json_response(0, 1, 500, "Database connection not available");
}

$action = $operation ?? '';

switch ($action) {
    case 'list':
        handleRackList($pdo, $user);
        break;
    case 'get':
        handleRackGet($pdo, $user);
        break;
    case 'create':
        handleRackCreate($pdo, $user);
        break;
    case 'update':
        handleRackUpdate($pdo, $user);
        break;
    case 'delete':
        handleRackDelete($pdo, $user);
        break;
    case 'assign-server':
        handleRackAssignServer($pdo, $user);
        break;
    case 'unassign-server':
        handleRackUnassignServer($pdo, $user);
        break;
    case 'unassigned-servers':
        handleRackUnassignedServers($pdo, $user);
        break;
    case 'placement':
        handleRackPlacement($pdo, $user);
        break;
    default:
        send_json_response(0, 1, 400, "Invalid rack operation: $action");
}

/* ============================================================
 * Helpers
 * ============================================================ */

/**
 * Human-readable label for a server configuration_status.
 */
function rackConfigStatusText($status) {
    $map = [0 => 'Draft', 1 => 'Validated', 2 => 'Built', 3 => 'Finalized'];
    return $map[(int)$status] ?? 'Unknown';
}

/**
 * Fetch a rack row by its uuid, or null.
 */
function rackFetchByUuid($pdo, $rackUuid) {
    $stmt = $pdo->prepare("SELECT * FROM racks WHERE rack_uuid = ? LIMIT 1");
    $stmt->execute([$rackUuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Derive the U-height a server occupies from its chassis spec.
 * Falls back to 1U when no chassis is set or the spec can't be resolved.
 * Blades / fractional U round up to a minimum of 1U.
 *
 * Lives in RackPlacement so ServerBuilder can re-derive the same height when a
 * chassis is added to an already-racked server.
 */
function rackDeriveUHeight($chassisUuid) {
    return RackPlacement::deriveUHeight($chassisUuid);
}

/**
 * Resolve a chassis display name for a server (best effort, for labels).
 */
function rackChassisName($chassisUuid) {
    return RackPlacement::chassisName($chassisUuid);
}

/**
 * A rack's location_uuid, or null while seeder 2026_08_26_003 has not been run.
 *
 * Wrapped rather than read inline because a rack row is `SELECT *` and the
 * column is simply absent pre-migration -- touching $rack['location_uuid']
 * directly would emit a notice on every rack, on every request.
 */
function rackLocationUuid($pdo, array $rack) {
    if (!SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid')) {
        return null;
    }
    return !empty($rack['location_uuid']) ? $rack['location_uuid'] : null;
}

/* ============================================================
 * Handlers
 * ============================================================ */

/**
 * List all racks with occupancy summary.
 */
function handleRackList($pdo, $user) {
    try {
        $racks = $pdo->query("SELECT * FROM racks ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        // Occupancy per rack in a single grouped query.
        $occ = [];
        $occStmt = $pdo->query("
            SELECT rack_uuid, COUNT(*) AS server_count, COALESCE(SUM(u_height), 0) AS used_u
            FROM rack_servers GROUP BY rack_uuid
        ");
        foreach ($occStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $occ[$row['rack_uuid']] = $row;
        }

        // location_uuid / floor arrive with seeder 2026_08_26_003 and the
        // locations table with _001. Both are probed rather than assumed: the
        // code deploys ~20s after save and the seeders are run by hand, so this
        // list has to keep working with neither of them present.
        $hasLocationUuid = SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid');
        $hasFloor        = SchemaHelper::hasColumn($pdo, 'racks', 'floor');

        $result = array_map(function ($r) use ($occ, $pdo, $hasLocationUuid, $hasFloor) {
            $o = $occ[$r['rack_uuid']] ?? ['server_count' => 0, 'used_u' => 0];
            $locationUuid = $hasLocationUuid ? ($r['location_uuid'] ?: null) : null;

            return [
                'rack_uuid' => $r['rack_uuid'],
                'name' => $r['name'],
                'location' => $r['location'],
                'location_uuid' => $locationUuid,
                // Resolved from `locations`, falling back to the legacy text so
                // a rack that has not been linked yet still shows a place.
                'location_name' => LocationResolver::locationName($pdo, $locationUuid) ?: $r['location'],
                'floor' => $hasFloor ? $r['floor'] : null,
                'total_u' => (int)$r['total_u'],
                'numbering_top_down' => (int)$r['numbering_top_down'],
                'notes' => $r['notes'],
                'server_count' => (int)$o['server_count'],
                'used_u' => (int)$o['used_u'],
                'free_u' => max(0, (int)$r['total_u'] - (int)$o['used_u']),
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
            ];
        }, $racks);

        send_json_response(1, 1, 200, "Racks retrieved successfully", ['racks' => $result]);
    } catch (Throwable $e) {
        error_log("handleRackList error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to list racks");
    }
}

/**
 * Get a single rack with its placed servers.
 */
function handleRackGet($pdo, $user) {
    $rackUuid = $_GET['rack_uuid'] ?? $_POST['rack_uuid'] ?? '';
    if (empty($rackUuid)) {
        send_json_response(0, 1, 400, "rack_uuid is required");
    }

    try {
        $rack = rackFetchByUuid($pdo, $rackUuid);
        if (!$rack) {
            send_json_response(0, 1, 404, "Rack not found");
        }

        $stmt = $pdo->prepare("
            SELECT rs.config_uuid, rs.start_u, rs.u_height,
                   sc.server_name, sc.configuration_status, sc.chassis_uuid, sc.location
            FROM rack_servers rs
            LEFT JOIN server_configurations sc ON sc.config_uuid = rs.config_uuid
            WHERE rs.rack_uuid = ?
            ORDER BY rs.start_u ASC
        ");
        $stmt->execute([$rackUuid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $servers = array_map(function ($s) {
            $startU = (int)$s['start_u'];
            $height = max(1, (int)$s['u_height']);
            return [
                'config_uuid' => $s['config_uuid'],
                'server_name' => $s['server_name'] ?? '(deleted server)',
                'configuration_status' => isset($s['configuration_status']) ? (int)$s['configuration_status'] : null,
                'status_text' => isset($s['configuration_status']) ? rackConfigStatusText($s['configuration_status']) : 'Unknown',
                'start_u' => $startU,
                'u_height' => $height,
                'end_u' => $startU + $height - 1,
                'chassis_name' => rackChassisName($s['chassis_uuid'] ?? null),
                'orphaned' => $s['server_name'] === null,
            ];
        }, $rows);

        $usedU = array_sum(array_map(fn($s) => $s['u_height'], $servers));

        send_json_response(1, 1, 200, "Rack retrieved successfully", [
            'rack' => [
                'rack_uuid' => $rack['rack_uuid'],
                'name' => $rack['name'],
                'location' => $rack['location'],
                'location_uuid' => rackLocationUuid($pdo, $rack),
                'location_name' => LocationResolver::locationName($pdo, rackLocationUuid($pdo, $rack)) ?: $rack['location'],
                'floor' => SchemaHelper::hasColumn($pdo, 'racks', 'floor') ? $rack['floor'] : null,
                'total_u' => (int)$rack['total_u'],
                'numbering_top_down' => (int)$rack['numbering_top_down'],
                'notes' => $rack['notes'],
                'used_u' => $usedU,
                'free_u' => max(0, (int)$rack['total_u'] - $usedU),
                'created_at' => $rack['created_at'],
                'updated_at' => $rack['updated_at'],
            ],
            'servers' => $servers,
        ]);
    } catch (Throwable $e) {
        error_log("handleRackGet error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to retrieve rack");
    }
}

/**
 * Create a new rack.
 */
function handleRackCreate($pdo, $user) {
    $name = trim($_POST['name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $totalU = (int)($_POST['total_u'] ?? 42);
    $numberingTopDown = filter_var($_POST['numbering_top_down'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');

    if ($name === '') {
        send_json_response(0, 1, 400, "Rack name is required");
    }
    if ($totalU < 1 || $totalU > 100) {
        send_json_response(0, 1, 400, "Rack height (total_u) must be between 1 and 100");
    }

    // A rack now belongs to a LOCATION, and the free-text location is derived
    // from it. The text column stays because a lot of existing code reads it,
    // but once a location_uuid is given it is the location's name that is
    // written there -- a rack cannot claim a site its location does not name.
    $locationUuid = trim($_POST['location_uuid'] ?? '');
    $floor = trim($_POST['floor'] ?? '');

    if ($locationUuid !== '') {
        $resolved = LocationResolver::locationName($pdo, $locationUuid);
        if ($resolved === null) {
            send_json_response(0, 1, 404, "The location you selected was not found");
        }
        $location = $resolved;
    }

    try {
        $rackUuid = generateUUID();

        $cols = ['rack_uuid', 'name', 'location', 'total_u', 'numbering_top_down', 'notes', 'created_by'];
        $vals = [
            $rackUuid,
            $name,
            $location !== '' ? $location : null,
            $totalU,
            $numberingTopDown,
            $notes !== '' ? $notes : null,
            $user['id'],
        ];

        if (SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid')) {
            $cols[] = 'location_uuid';
            $vals[] = $locationUuid !== '' ? $locationUuid : null;
        }
        if (SchemaHelper::hasColumn($pdo, 'racks', 'floor')) {
            $cols[] = 'floor';
            $vals[] = $floor !== '' ? $floor : null;
        }

        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $pdo->prepare("INSERT INTO racks (" . implode(', ', $cols)
            . ", created_at, updated_at) VALUES ({$placeholders}, NOW(), NOW())");
        $stmt->execute($vals);

        logActivity($pdo, $user['id'], 'Rack created', 'rack', null,
            "Created rack: $name ($totalU U)" . ($location !== '' ? " at $location" : ''));

        send_json_response(1, 1, 200, "Rack created successfully", [
            'rack_uuid' => $rackUuid,
            'name' => $name,
            'location' => $location,
            'location_uuid' => $locationUuid !== '' ? $locationUuid : null,
            'floor' => $floor !== '' ? $floor : null,
            'total_u' => $totalU,
            'numbering_top_down' => $numberingTopDown,
            'notes' => $notes,
        ]);
    } catch (Throwable $e) {
        error_log("handleRackCreate error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to create rack");
    }
}

/**
 * Update an existing rack. Shrinking total_u below an occupied U is rejected.
 */
function handleRackUpdate($pdo, $user) {
    $rackUuid = $_POST['rack_uuid'] ?? '';
    if (empty($rackUuid)) {
        send_json_response(0, 1, 400, "rack_uuid is required");
    }

    try {
        $rack = rackFetchByUuid($pdo, $rackUuid);
        if (!$rack) {
            send_json_response(0, 1, 404, "Rack not found");
        }

        $fields = [];
        $values = [];

        if (isset($_POST['name'])) {
            $name = trim($_POST['name']);
            if ($name === '') {
                send_json_response(0, 1, 400, "Rack name cannot be empty");
            }
            $fields[] = "name = ?";
            $values[] = $name;
        }
        // Whether the rack changed site or floor. Either one changes the answer
        // to "where is this component", for every component in every server in
        // this rack -- so the propagation below is not optional.
        $addressChanged = false;

        if (isset($_POST['location_uuid']) && SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid')) {
            $locationUuid = trim($_POST['location_uuid']);

            if ($locationUuid === '') {
                $fields[] = "location_uuid = ?";
                $values[] = null;
            } else {
                $resolvedName = LocationResolver::locationName($pdo, $locationUuid);
                if ($resolvedName === null) {
                    send_json_response(0, 1, 404, "The location you selected was not found");
                }
                $fields[] = "location_uuid = ?";
                $values[] = $locationUuid;
                // Keep the legacy text agreeing with the link, rather than
                // letting the two describe different places.
                $fields[] = "location = ?";
                $values[] = $resolvedName;
            }
            $addressChanged = $addressChanged || (rackLocationUuid($pdo, $rack) !== ($locationUuid !== '' ? $locationUuid : null));
        } elseif (isset($_POST['location'])) {
            // Pre-migration path, and the Rack View form until it is updated.
            $loc = trim($_POST['location']);
            $fields[] = "location = ?";
            $values[] = $loc !== '' ? $loc : null;
            $addressChanged = true;
        }

        if (isset($_POST['floor']) && SchemaHelper::hasColumn($pdo, 'racks', 'floor')) {
            $floor = trim($_POST['floor']);
            $fields[] = "floor = ?";
            $values[] = $floor !== '' ? $floor : null;
            $addressChanged = $addressChanged || (($rack['floor'] ?? null) !== ($floor !== '' ? $floor : null));
        }
        if (isset($_POST['notes'])) {
            $notes = trim($_POST['notes']);
            $fields[] = "notes = ?";
            $values[] = $notes !== '' ? $notes : null;
        }
        if (isset($_POST['numbering_top_down'])) {
            $fields[] = "numbering_top_down = ?";
            $values[] = filter_var($_POST['numbering_top_down'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (isset($_POST['total_u'])) {
            $totalU = (int)$_POST['total_u'];
            if ($totalU < 1 || $totalU > 100) {
                send_json_response(0, 1, 400, "Rack height (total_u) must be between 1 and 100");
            }
            // Don't let the rack shrink below the highest occupied U.
            $topStmt = $pdo->prepare("SELECT COALESCE(MAX(start_u + u_height - 1), 0) AS top_u FROM rack_servers WHERE rack_uuid = ?");
            $topStmt->execute([$rackUuid]);
            $topU = (int)($topStmt->fetch(PDO::FETCH_ASSOC)['top_u'] ?? 0);
            if ($totalU < $topU) {
                send_json_response(0, 1, 400, "Cannot shrink rack to {$totalU}U — a server occupies up to U{$topU}. Move it first.");
            }
            $fields[] = "total_u = ?";
            $values[] = $totalU;
        }

        if (empty($fields)) {
            send_json_response(1, 1, 200, "No changes provided", ['rack_uuid' => $rackUuid]);
        }

        $values[] = $rackUuid;
        $stmt = $pdo->prepare("UPDATE racks SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE rack_uuid = ?");
        $stmt->execute($values);

        // The racks did not move, but the answer to "where is this" did. Without
        // this, changing a rack's site would leave every server and every
        // component inside it still naming the old one.
        $resynced = ['configs' => 0, 'components' => 0];
        if ($addressChanged) {
            $resynced = LocationResolver::syncRack($pdo, $rackUuid);
        }

        logActivity($pdo, $user['id'], 'Rack updated', 'rack', null,
            "Updated rack: " . $rack['name']
            . ($addressChanged ? " (re-stamped {$resynced['configs']} server(s), {$resynced['components']} component(s))" : ''));

        send_json_response(1, 1, 200, "Rack updated successfully", [
            'rack' => rackFetchByUuid($pdo, $rackUuid),
            'resynced' => $resynced,
        ]);
    } catch (Throwable $e) {
        error_log("handleRackUpdate error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to update rack");
    }
}

/**
 * Delete a rack. Rejected while it still holds servers.
 */
function handleRackDelete($pdo, $user) {
    $rackUuid = $_POST['rack_uuid'] ?? '';
    if (empty($rackUuid)) {
        send_json_response(0, 1, 400, "rack_uuid is required");
    }

    try {
        $rack = rackFetchByUuid($pdo, $rackUuid);
        if (!$rack) {
            send_json_response(0, 1, 404, "Rack not found");
        }

        $cntStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM rack_servers WHERE rack_uuid = ?");
        $cntStmt->execute([$rackUuid]);
        $count = (int)($cntStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
        if ($count > 0) {
            send_json_response(0, 1, 400, "Cannot delete rack — it still has $count server(s) installed. Remove them first.");
        }

        $stmt = $pdo->prepare("DELETE FROM racks WHERE rack_uuid = ?");
        $stmt->execute([$rackUuid]);

        logActivity($pdo, $user['id'], 'Rack deleted', 'rack', null, "Deleted rack: " . ($rack['name']));

        send_json_response(1, 1, 200, "Rack deleted successfully", ['rack_uuid' => $rackUuid]);
    } catch (Throwable $e) {
        error_log("handleRackDelete error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to delete rack");
    }
}

/**
 * Place (or move) a server into a rack at a given start U.
 *
 * Every check and every write now lives in ServerRelocation::move(). This
 * handler is HTTP plumbing only, on purpose: Rack View's place control, the
 * "Move server" dialog on the server card and an approved Move Server request
 * all arrive here or at that same class, and each having its own copy of the
 * bounds/overlap/propagation logic is precisely how the components came to be
 * left behind on a move.
 *
 * `location_uuid` is optional and only ever a CROSS-CHECK: the rack already
 * determines the site. Sending one that disagrees with the rack is refused
 * rather than silently resolved, so the response can never describe a place the
 * caller did not choose.
 *
 * u_height stays overridable for Rack View, which sizes sleds explicitly;
 * omitted, it is re-derived from the chassis as before.
 */
function handleRackAssignServer($pdo, $user) {
    $rackUuid   = $_POST['rack_uuid'] ?? '';
    $configUuid = $_POST['config_uuid'] ?? '';
    $startU     = isset($_POST['start_u']) ? (int)$_POST['start_u'] : 0;

    if (empty($rackUuid) || empty($configUuid)) {
        send_json_response(0, 1, 400, "rack_uuid and config_uuid are required");
    }
    if ($startU < 1) {
        send_json_response(0, 1, 400, "start_u must be 1 or greater");
    }

    $locationUuid = trim($_POST['location_uuid'] ?? '');

    $result = ServerRelocation::move($pdo, $configUuid, [
        'rack_uuid'     => $rackUuid,
        'location_uuid' => $locationUuid !== '' ? $locationUuid : null,
        'start_u'       => $startU,
        'u_height'      => isset($_POST['u_height']) && $_POST['u_height'] !== '' ? (int)$_POST['u_height'] : null,
    ], [
        'user_id' => $user['id'],
        'reason'  => trim($_POST['reason'] ?? ''),
    ]);

    if (!$result['success']) {
        send_json_response(0, 1, $result['code'], $result['message']);
    }

    $to = $result['data']['to'];

    send_json_response(1, 1, 200, $result['message'], [
        // Shape preserved from before this refactor: the Rack View and the
        // server card both read data.placement.*, and they still can.
        'placement' => [
            'rack_uuid'     => $to['rack_uuid'],
            'config_uuid'   => $configUuid,
            'server_name'   => $to['server_name'],
            'start_u'       => $to['start_u'],
            'u_height'      => $to['u_height'],
            'end_u'         => $to['end_u'],
            'rack_name'     => $to['rack_name'],
            'floor'         => $to['floor'],
            'location_uuid' => $to['location_uuid'],
            'location_name' => $to['location_name'],
        ],
        'moved'              => $result['data']['moved'],
        'components_updated' => $result['data']['components_updated'],
        'from'               => $result['data']['from'],
        'to'                 => $to,
    ]);
}

/**
 * Remove a server from whatever rack it currently occupies.
 *
 * Delegates to ServerRelocation::unrack(), which clears the U position from
 * every component in the build as well as from the server. Before that this
 * handler deleted the placement row and left 14 components claiming a U in a
 * rack they were no longer in.
 *
 * The server keeps its location: it is out of the rack, not off the site.
 */
function handleRackUnassignServer($pdo, $user) {
    $configUuid = $_POST['config_uuid'] ?? '';
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "config_uuid is required");
    }

    $result = ServerRelocation::unrack($pdo, $configUuid, [
        'user_id' => $user['id'],
        'reason'  => trim($_POST['reason'] ?? ''),
    ]);

    if (!$result['success']) {
        send_json_response(0, 1, $result['code'], $result['message']);
    }

    send_json_response(1, 1, 200, $result['message'], $result['data']);
}

/**
 * List real servers that are not yet placed in any rack — used to populate
 * the placement picker. Includes a derived u_height per server.
 */
function handleRackUnassignedServers($pdo, $user) {
    try {
        $stmt = $pdo->query("
            SELECT sc.config_uuid, sc.server_name, sc.configuration_status, sc.chassis_uuid, sc.location
            FROM server_configurations sc
            WHERE sc.is_virtual = 0
              AND sc.config_uuid NOT IN (SELECT config_uuid FROM rack_servers)
            ORDER BY sc.server_name ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $servers = array_map(function ($s) {
            return [
                'config_uuid' => $s['config_uuid'],
                'server_name' => $s['server_name'],
                'configuration_status' => (int)$s['configuration_status'],
                'status_text' => rackConfigStatusText($s['configuration_status']),
                'location' => $s['location'],
                'u_height' => rackDeriveUHeight($s['chassis_uuid'] ?? null),
                'chassis_name' => rackChassisName($s['chassis_uuid'] ?? null),
            ];
        }, $rows);

        send_json_response(1, 1, 200, "Unassigned servers retrieved successfully", ['servers' => $servers]);
    } catch (Throwable $e) {
        error_log("handleRackUnassignedServers error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to list unassigned servers");
    }
}

/**
 * Current rack placement of one server configuration, plus the U-height it needs.
 *
 * Read side of "move this server": the servers list only carries the derived
 * rack_position text, so the placement dialog asks here for the authoritative
 * rack_uuid / start_u AND for the height the server occupies today (re-derived from
 * its chassis, which may have been added after it was racked). The position picker
 * offers only start-U values where that height actually fits.
 */
function handleRackPlacement($pdo, $user) {
    $configUuid = $_POST['config_uuid'] ?? $_GET['config_uuid'] ?? '';
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "config_uuid is required");
    }

    try {
        $stmt = $pdo->prepare("SELECT config_uuid, server_name, is_virtual, chassis_uuid FROM server_configurations WHERE config_uuid = ? LIMIT 1");
        $stmt->execute([$configUuid]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$server) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }

        $placement = null;
        $row = RackPlacement::getPlacement($pdo, $configUuid);
        if ($row) {
            $startU = (int)$row['start_u'];
            $height = max(1, (int)$row['u_height']);
            $rack = rackFetchByUuid($pdo, $row['rack_uuid']);
            $rackLocationUuid = $rack ? rackLocationUuid($pdo, $rack) : null;
            $placement = [
                'rack_uuid' => $row['rack_uuid'],
                'rack_name' => $rack['name'] ?? null,
                'total_u' => isset($rack['total_u']) ? (int)$rack['total_u'] : null,
                'start_u' => $startU,
                'u_height' => $height,
                'end_u' => $startU + $height - 1,
                // The Move dialog preselects the Location dropdown from these,
                // so it opens on where the server actually is rather than on the
                // first site in the list.
                'location_uuid' => $rackLocationUuid,
                'location_name' => LocationResolver::locationName($pdo, $rackLocationUuid)
                                   ?: ($rack['location'] ?? null),
                'floor' => ($rack && SchemaHelper::hasColumn($pdo, 'racks', 'floor')) ? $rack['floor'] : null,
            ];
        }

        // The full resolved address, including the unracked case where the
        // location comes from the config itself.
        $address = LocationResolver::resolveForConfig($pdo, $configUuid);

        send_json_response(1, 1, 200, "Placement retrieved successfully", [
            'config_uuid' => $configUuid,
            'server_name' => $server['server_name'],
            'is_virtual' => (int)$server['is_virtual'] === 1,
            'placement' => $placement,
            'required_u_height' => rackDeriveUHeight($server['chassis_uuid'] ?? null),
            'chassis_name' => rackChassisName($server['chassis_uuid'] ?? null),
            // Where it is now, and where it is if it is not in a rack at all.
            'address' => $address,
            'address_text' => $address ? LocationResolver::formatAddress($address) : null,
            'location_uuid' => $address ? $address['location_uuid'] : null,
            // Told to the mover BEFORE they commit: this is the number of
            // inventory rows the move will re-stamp, and the thing they cannot
            // see for themselves.
            'component_count' => LocationResolver::countComponents($pdo, $configUuid),
        ]);
    } catch (Throwable $e) {
        error_log("handleRackPlacement error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to retrieve rack placement");
    }
}
