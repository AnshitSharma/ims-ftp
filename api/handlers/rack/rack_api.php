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
 */
function rackDeriveUHeight($chassisUuid) {
    if (empty($chassisUuid)) {
        return 1;
    }
    try {
        static $chassisManager = null;
        if ($chassisManager === null) {
            $chassisManager = new ChassisManager();
        }
        $specs = $chassisManager->loadChassisSpecsByUUID($chassisUuid);
        if (!empty($specs['found']) && isset($specs['specifications']['u_size'])) {
            $u = (float)$specs['specifications']['u_size'];
            $u = (int)ceil($u);
            return $u >= 1 ? $u : 1;
        }
    } catch (Throwable $e) {
        error_log("rackDeriveUHeight error: " . $e->getMessage());
    }
    return 1;
}

/**
 * Resolve a chassis display name for a server (best effort, for labels).
 */
function rackChassisName($chassisUuid) {
    if (empty($chassisUuid)) {
        return null;
    }
    try {
        static $chassisManager = null;
        if ($chassisManager === null) {
            $chassisManager = new ChassisManager();
        }
        $specs = $chassisManager->loadChassisSpecsByUUID($chassisUuid);
        if (!empty($specs['found'])) {
            return $specs['specifications']['model'] ?? null;
        }
    } catch (Throwable $e) {
        // best effort only
    }
    return null;
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

        $result = array_map(function ($r) use ($occ) {
            $o = $occ[$r['rack_uuid']] ?? ['server_count' => 0, 'used_u' => 0];
            return [
                'rack_uuid' => $r['rack_uuid'],
                'name' => $r['name'],
                'location' => $r['location'],
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

    try {
        $rackUuid = generateUUID();
        $stmt = $pdo->prepare("
            INSERT INTO racks (rack_uuid, name, location, total_u, numbering_top_down, notes, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $rackUuid,
            $name,
            $location !== '' ? $location : null,
            $totalU,
            $numberingTopDown,
            $notes !== '' ? $notes : null,
            $user['id'],
        ]);

        logActivity($pdo, $user['id'], 'Rack created', 'rack', null, "Created rack: $name ($totalU U)");

        send_json_response(1, 1, 200, "Rack created successfully", [
            'rack_uuid' => $rackUuid,
            'name' => $name,
            'location' => $location,
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
        if (isset($_POST['location'])) {
            $loc = trim($_POST['location']);
            $fields[] = "location = ?";
            $values[] = $loc !== '' ? $loc : null;
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

        logActivity($pdo, $user['id'], 'Rack updated', 'rack', null, "Updated rack: " . ($rack['name']));

        send_json_response(1, 1, 200, "Rack updated successfully", [
            'rack' => rackFetchByUuid($pdo, $rackUuid),
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
 * u_height is derived from the server's chassis unless explicitly overridden.
 */
function handleRackAssignServer($pdo, $user) {
    $rackUuid = $_POST['rack_uuid'] ?? '';
    $configUuid = $_POST['config_uuid'] ?? '';
    $startU = isset($_POST['start_u']) ? (int)$_POST['start_u'] : 0;
    $heightOverride = isset($_POST['u_height']) && $_POST['u_height'] !== '' ? (int)$_POST['u_height'] : null;

    if (empty($rackUuid) || empty($configUuid)) {
        send_json_response(0, 1, 400, "rack_uuid and config_uuid are required");
    }
    if ($startU < 1) {
        send_json_response(0, 1, 400, "start_u must be 1 or greater");
    }

    try {
        $rack = rackFetchByUuid($pdo, $rackUuid);
        if (!$rack) {
            send_json_response(0, 1, 404, "Rack not found");
        }

        // The server must exist and be a real (non-virtual) configuration.
        $scStmt = $pdo->prepare("SELECT config_uuid, server_name, is_virtual, chassis_uuid FROM server_configurations WHERE config_uuid = ? LIMIT 1");
        $scStmt->execute([$configUuid]);
        $server = $scStmt->fetch(PDO::FETCH_ASSOC);
        if (!$server) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        if ((int)$server['is_virtual'] === 1) {
            send_json_response(0, 1, 400, "Virtual/test configurations cannot be placed in a rack");
        }

        $height = $heightOverride !== null ? max(1, $heightOverride) : rackDeriveUHeight($server['chassis_uuid'] ?? null);
        $endU = $startU + $height - 1;

        // Must fit within the rack.
        if ($endU > (int)$rack['total_u']) {
            send_json_response(0, 1, 400, "Server ({$height}U) starting at U{$startU} would exceed the rack height of {$rack['total_u']}U");
        }

        // Overlap check against other servers in this rack (exclude this server when moving).
        $existingStmt = $pdo->prepare("SELECT config_uuid, start_u, u_height FROM rack_servers WHERE rack_uuid = ? AND config_uuid <> ?");
        $existingStmt->execute([$rackUuid, $configUuid]);
        foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $ex) {
            $exStart = (int)$ex['start_u'];
            $exEnd = $exStart + max(1, (int)$ex['u_height']) - 1;
            if ($startU <= $exEnd && $endU >= $exStart) {
                send_json_response(0, 1, 409, "U{$startU}–U{$endU} overlaps a server already installed at U{$exStart}–U{$exEnd}");
            }
        }

        // Is this server already placed somewhere? If so, this is a move.
        $curStmt = $pdo->prepare("SELECT id, rack_uuid FROM rack_servers WHERE config_uuid = ? LIMIT 1");
        $curStmt->execute([$configUuid]);
        $current = $curStmt->fetch(PDO::FETCH_ASSOC);
        $moved = false;

        if ($current) {
            $stmt = $pdo->prepare("UPDATE rack_servers SET rack_uuid = ?, start_u = ?, u_height = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([$rackUuid, $startU, $height, $configUuid]);
            $moved = true;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO rack_servers (rack_uuid, config_uuid, start_u, u_height, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$rackUuid, $configUuid, $startU, $height, $user['id']]);
        }

        logActivity($pdo, $user['id'], $moved ? 'Server moved in rack' : 'Server placed in rack', 'rack', null,
            "{$server['server_name']} -> {$rack['name']} U{$startU} ({$height}U)");

        send_json_response(1, 1, 200, $moved ? "Server moved successfully" : "Server placed successfully", [
            'placement' => [
                'rack_uuid' => $rackUuid,
                'config_uuid' => $configUuid,
                'server_name' => $server['server_name'],
                'start_u' => $startU,
                'u_height' => $height,
                'end_u' => $endU,
            ],
            'moved' => $moved,
        ]);
    } catch (Throwable $e) {
        error_log("handleRackAssignServer error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to place server in rack");
    }
}

/**
 * Remove a server from whatever rack it currently occupies.
 */
function handleRackUnassignServer($pdo, $user) {
    $configUuid = $_POST['config_uuid'] ?? '';
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "config_uuid is required");
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM rack_servers WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);

        if ($stmt->rowCount() === 0) {
            send_json_response(0, 1, 404, "Server is not currently installed in any rack");
        }

        logActivity($pdo, $user['id'], 'Server removed from rack', 'rack', null, "Removed server $configUuid from rack");

        send_json_response(1, 1, 200, "Server removed from rack", ['config_uuid' => $configUuid]);
    } catch (Throwable $e) {
        error_log("handleRackUnassignServer error: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to remove server from rack");
    }
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
