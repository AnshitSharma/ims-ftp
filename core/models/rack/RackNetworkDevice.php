<?php
/**
 * RackNetworkDevice — where a router, switch or MUX sits in a rack.
 * File: core/models/rack/RackNetworkDevice.php
 *
 * THE MODEL
 *   rack --< rack_network_devices >-- networkdeviceinventory (the physical unit)
 *
 *   `rack_network_devices` is the single source of truth for a placement, exactly
 *   as `rack_servers` is for a server. The unit's own Status / Location /
 *   location_uuid / RackPosition are a CACHE derived from it, written here and
 *   only here, in the same transaction as the placement:
 *
 *     racked  -> Status 2, the rack's site, RackPosition "U40" / "U40-U41"
 *     unrack  -> Status 1, site kept (it is out of the rack, not off the site),
 *                RackPosition cleared
 *
 *   That is what lets the existing guards hold with no special case:
 *   ComponentRelocation refuses to hand over anything that is not Status 1, so a
 *   racked switch cannot be "moved" on paper while it stays bolted in, and
 *   updateComponent() / deleteComponent() refuse to edit or destroy a racked one.
 *
 * ONE DOOR
 *   rack-device-assign, rack-device-unassign and the two Request actions
 *   (inventory.device.rack / .unrack) all call place() / unrack() here, so an
 *   approved Request can never do what the direct path would have refused. Both
 *   return a result array rather than sending JSON, because send_json_response()
 *   exits and the Request path has to see a failure and roll its own transaction
 *   back.
 *
 * COLLISIONS
 *   RackPlacement::occupancy() is the one answer to "what is in the way" and it
 *   counts devices, so a switch and a server can never both own U40. The rack row
 *   is locked FOR UPDATE before occupancy is read, the same mutex
 *   ServerRelocation and RackEnclosure use.
 *
 * SIZE
 *   u_height is a snapshot of the model spec's `u_size`, taken at placement. A
 *   model without a usable u_size is REFUSED rather than guessed at 1U: a wrong
 *   guess is how a device gets placed in a gap it does not fit.
 *
 * DEPLOY ORDER
 *   Inert until seeder 2026_09_29_001 has been run by hand; every entry point
 *   answers 503 rather than throwing. This file itself deploys after the file that
 *   requires it, so callers require it behind is_readable().
 */

require_once __DIR__ . '/RackPlacement.php';
require_once __DIR__ . '/../location/LocationResolver.php';
require_once __DIR__ . '/../components/ComponentDataService.php';
require_once __DIR__ . '/../state/StatusMap.php';
require_once __DIR__ . '/../../helpers/SchemaHelper.php';

class RackNetworkDevice
{
    const TYPE  = 'networkdevice';
    const TABLE = 'networkdeviceinventory';

    /* ============================================================
     * Reading
     * ============================================================ */

    public static function available($pdo)
    {
        return SchemaHelper::hasTable($pdo, 'rack_network_devices')
            && SchemaHelper::hasTable($pdo, self::TABLE);
    }

    /**
     * What the catalogue says about a device model, or null when the uuid is not
     * in it. `u_height` is null when the spec carries no usable u_size.
     *
     * @return array{brand:?string,model:?string,model_name:string,device_type:?string,u_height:?int}|null
     */
    public static function modelInfo($modelUuid)
    {
        static $cache = [];
        if (empty($modelUuid)) {
            return null;
        }
        if (array_key_exists($modelUuid, $cache)) {
            return $cache[$modelUuid];
        }

        $info = null;
        try {
            $spec = ComponentDataService::getInstance()->findComponentByUuid(self::TYPE, $modelUuid);
            if (is_array($spec)) {
                $brand = isset($spec['brand']) && $spec['brand'] !== '' ? $spec['brand'] : null;
                $model = isset($spec['model']) && $spec['model'] !== '' ? $spec['model'] : null;
                $u = null;
                if (isset($spec['u_size']) && is_numeric($spec['u_size'])) {
                    $u = (int)ceil((float)$spec['u_size']);
                    $u = $u >= 1 ? $u : null;
                }
                $info = [
                    'brand'       => $brand,
                    'model'       => $model,
                    'model_name'  => trim(($brand ? $brand . ' ' : '') . ($model ?: '')) ?: 'Network device',
                    'device_type' => isset($spec['device_type']) ? $spec['device_type'] : null,
                    'u_height'    => $u,
                ];
            }
        } catch (Throwable $e) {
            // Decoration and sizing only; place() refuses on a null u_height.
            error_log("RackNetworkDevice::modelInfo error: " . $e->getMessage());
        }

        $cache[$modelUuid] = $info;
        return $info;
    }

    /**
     * The placement of one unit, with its rack, or null when it is loose stock.
     */
    public static function placementFor($pdo, $inventoryId)
    {
        if (!self::available($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT rn.rack_uuid, rn.inventory_id, rn.start_u, rn.u_height, r.name AS rack_name
               FROM rack_network_devices rn
               LEFT JOIN racks r ON r.rack_uuid = rn.rack_uuid
              WHERE rn.inventory_id = ? LIMIT 1"
        );
        $stmt->execute([(int)$inventoryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Every device racked in one rack, lowest U first, for rack-get and the
     * elevation. Names come from the catalogue and never fail the read.
     */
    public static function listForRack($pdo, $rackUuid)
    {
        if (!self::available($pdo)) {
            return [];
        }
        $stmt = $pdo->prepare(
            "SELECT rn.inventory_id, rn.start_u, rn.u_height,
                    d.UUID, d.AssetTag, d.SerialNumber, d.Status, d.Notes
               FROM rack_network_devices rn
               LEFT JOIN " . self::TABLE . " d ON d.ID = rn.inventory_id
              WHERE rn.rack_uuid = ?
              ORDER BY rn.start_u ASC"
        );
        $stmt->execute([$rackUuid]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $info   = self::modelInfo($row['UUID']);
            $start  = (int)$row['start_u'];
            $height = max(1, (int)$row['u_height']);
            $out[] = [
                'inventory_id'  => (int)$row['inventory_id'],
                'asset_tag'     => $row['AssetTag'],
                'serial_number' => $row['SerialNumber'],
                'model_name'    => $info ? $info['model_name'] : 'Network device',
                'brand'         => $info ? $info['brand'] : null,
                'device_type'   => $info ? $info['device_type'] : null,
                'start_u'       => $start,
                'u_height'      => $height,
                'end_u'         => $start + $height - 1,
                'status'        => isset($row['Status']) ? (int)$row['Status'] : null,
                'notes'         => $row['Notes'],
                'orphaned'      => $row['UUID'] === null,
            ];
        }
        return $out;
    }

    /**
     * Units that can be racked, at any site. Racking one from a different site is
     * allowed and is recorded as a move. Units at the rack's own site sort first.
     *
     * $scope picks WHICH units, because the three callers want different lists:
     *   'available'  loose stock (Status 1) -- the Place dialog          (default)
     *   'racked'     units currently in a rack -- Request unrack form
     *   'all'        both -- Request rack form, since placing a racked unit is a move
     * A racked unit carries rack_uuid / rack_name / start_u / end_u.
     *
     * @param string|null $rackUuid when given, marks each unit `same_site`
     * @param string      $scope    'available' | 'racked' | 'all'
     */
    public static function placeableUnits($pdo, $rackUuid = null, $scope = 'available')
    {
        if (!self::available($pdo)) {
            return [];
        }
        $scope = in_array($scope, ['racked', 'all'], true) ? $scope : 'available';

        $rackLocation = null;
        if ($rackUuid !== null && SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid')) {
            $stmt = $pdo->prepare("SELECT location_uuid FROM racks WHERE rack_uuid = ? LIMIT 1");
            $stmt->execute([$rackUuid]);
            $v = $stmt->fetchColumn();
            $rackLocation = ($v !== false && $v !== '') ? $v : null;
        }

        // The placement row, not Status, says whether a unit is racked.
        $hasLoc = SchemaHelper::hasColumn($pdo, self::TABLE, 'location_uuid');
        $where = $scope === 'available' ? 'rn.inventory_id IS NULL AND d.Status = 1'
               : ($scope === 'racked'   ? 'rn.inventory_id IS NOT NULL'
               :                          '(rn.inventory_id IS NOT NULL OR d.Status = 1)');
        $sql = "SELECT d.ID, d.UUID, d.AssetTag, d.SerialNumber, d.Location, d.StoreLocation,"
             . ($hasLoc ? " d.location_uuid" : " NULL AS location_uuid")
             . ", rn.rack_uuid, rn.start_u, rn.u_height, r.name AS rack_name"
             . " FROM " . self::TABLE . " d"
             . " LEFT JOIN rack_network_devices rn ON rn.inventory_id = d.ID"
             . " LEFT JOIN racks r ON r.rack_uuid = rn.rack_uuid"
             . " WHERE {$where} ORDER BY d.ID ASC LIMIT 500";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $info = self::modelInfo($row['UUID']);
            $racked = $row['rack_uuid'] !== null;
            $out[] = [
                'racked'         => $racked,
                'rack_uuid'      => $racked ? $row['rack_uuid'] : null,
                'rack_name'      => $racked ? $row['rack_name'] : null,
                'start_u'        => $racked ? (int)$row['start_u'] : null,
                'end_u'          => $racked ? (int)$row['start_u'] + max(1, (int)$row['u_height']) - 1 : null,
                'inventory_id'   => (int)$row['ID'],
                'asset_tag'      => $row['AssetTag'],
                'serial_number'  => $row['SerialNumber'],
                'model_name'     => $info ? $info['model_name'] : 'Network device',
                'device_type'    => $info ? $info['device_type'] : null,
                // null = the spec has no u_size, and place() would refuse it.
                'u_height'       => $info ? $info['u_height'] : null,
                'location_uuid'  => $row['location_uuid'],
                'location_name'  => LocationResolver::locationName($pdo, $row['location_uuid']) ?: $row['Location'],
                'store_location' => $row['StoreLocation'],
                'same_site'      => $rackLocation !== null && $row['location_uuid'] === $rackLocation,
            ];
        }
        usort($out, function ($a, $b) {
            if ($a['same_site'] !== $b['same_site']) {
                return $a['same_site'] ? -1 : 1;
            }
            return $a['inventory_id'] - $b['inventory_id'];
        });
        return $out;
    }

    /* ============================================================
     * Writing
     * ============================================================ */

    /**
     * Rack a device, or move one that is already racked.
     *
     * @param array $ctx ['user_id' => ?int, 'reason' => ?string, 'ticket_id' => ?int]
     * @return array{success:bool, code:int, message:string, data:array}
     */
    public static function place($pdo, $inventoryId, $rackUuid, $startU, array $ctx = [])
    {
        $inventoryId = (int)$inventoryId;
        $startU      = (int)$startU;
        $rackUuid    = trim((string)$rackUuid);
        $userId      = isset($ctx['user_id']) ? $ctx['user_id'] : null;
        $reason      = isset($ctx['reason']) && trim((string)$ctx['reason']) !== '' ? trim((string)$ctx['reason']) : null;
        $ticketId    = !empty($ctx['ticket_id']) ? (int)$ctx['ticket_id'] : null;

        if (!self::available($pdo)) {
            return self::fail(503, 'Network devices are not available yet — the database migration for this feature has not been applied.');
        }
        if ($inventoryId <= 0) {
            return self::fail(400, 'inventory_id is required and must identify one physical device');
        }
        if ($rackUuid === '') {
            return self::fail(400, 'rack_uuid is required');
        }
        if ($startU < 1) {
            return self::fail(400, 'start_u must be 1 or greater');
        }

        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        try {
            // Rack first, then the unit: the same order every other rack writer
            // takes, so two placements cannot deadlock on each other.
            $stmt = $pdo->prepare("SELECT * FROM racks WHERE rack_uuid = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$rackUuid]);
            $rack = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rack) {
                return self::abort($pdo, $ownsTx, 404, 'Rack not found');
            }

            $stmt = $pdo->prepare("SELECT * FROM " . self::TABLE . " WHERE ID = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$inventoryId]);
            $unit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$unit) {
                return self::abort($pdo, $ownsTx, 404, 'That network device no longer exists');
            }

            $current = self::placementFor($pdo, $inventoryId);

            // The placement row is the truth about whether it is racked; Status is
            // its cache. Loose stock must actually be available.
            if ($current === null && (int)$unit['Status'] !== 1) {
                $why = (int)$unit['Status'] === 0
                    ? 'it is marked failed'
                    : 'it is marked in use but is not racked anywhere — fix its status first';
                return self::abort($pdo, $ownsTx, 409, 'That device cannot be racked: ' . $why);
            }

            $info = self::modelInfo($unit['UUID']);
            if ($info === null || $info['u_height'] === null) {
                return self::abort($pdo, $ownsTx, 400,
                    'This device\'s model has no rack height (u_size) in the catalogue, so it cannot be placed. '
                    . 'Add u_size to the model in ims-data first.');
            }
            $height = $info['u_height'];
            $endU   = $startU + $height - 1;

            if ($endU > (int)$rack['total_u']) {
                return self::abort($pdo, $ownsTx, 400,
                    "The device ({$height}U) starting at U{$startU} would run past the top of "
                    . "\"{$rack['name']}\" ({$rack['total_u']}U)");
            }

            $occupancy = RackPlacement::occupancy($pdo, $rackUuid, ['network_device_id' => $inventoryId]);
            $hit = RackPlacement::findCollision($occupancy, $startU, $endU);
            if ($hit !== null) {
                $what = $hit['kind'] === 'enclosure' ? "enclosure {$hit['label']}" : "\"{$hit['label']}\"";
                return self::abort($pdo, $ownsTx, 409,
                    "U{$startU}-U{$endU} overlaps {$what}, already installed at "
                    . "U{$hit['start_u']}-U{$hit['end_u']} in \"{$rack['name']}\"");
            }

            // Nothing to do: same rack, same U, same size.
            if ($current !== null && $current['rack_uuid'] === $rackUuid
                && (int)$current['start_u'] === $startU && (int)$current['u_height'] === $height) {
                if ($ownsTx) {
                    $pdo->rollBack();
                }
                return [
                    'success' => true, 'code' => 200,
                    'message' => 'That device is already there — nothing was changed',
                    'data'    => ['moved' => false, 'inventory_id' => $inventoryId],
                ];
            }

            $rackLocationUuid = SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid') && !empty($rack['location_uuid'])
                ? $rack['location_uuid'] : null;
            $rackLocationName = LocationResolver::locationName($pdo, $rackLocationUuid)
                ?: (!empty($rack['location']) ? $rack['location'] : null);
            $floor = SchemaHelper::hasColumn($pdo, 'racks', 'floor') && !empty($rack['floor']) ? $rack['floor'] : null;

            $from = [
                'location_uuid'  => !empty($unit['location_uuid']) ? $unit['location_uuid'] : null,
                'location_name'  => LocationResolver::locationName($pdo, isset($unit['location_uuid']) ? $unit['location_uuid'] : null)
                                    ?: (!empty($unit['Location']) ? $unit['Location'] : null),
                'store_location' => !empty($unit['StoreLocation']) ? $unit['StoreLocation'] : null,
                'rack_name'      => $current ? $current['rack_name'] : null,
                'start_u'        => $current ? (int)$current['start_u'] : null,
            ];

            // ---- write the placement ----------------------------------------
            if ($current === null) {
                $ins = $pdo->prepare("INSERT INTO rack_network_devices
                        (rack_uuid, inventory_id, start_u, u_height, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                $ins->execute([$rackUuid, $inventoryId, $startU, $height, $userId]);
            } else {
                $upd = $pdo->prepare("UPDATE rack_network_devices
                        SET rack_uuid = ?, start_u = ?, u_height = ?, updated_at = NOW()
                      WHERE inventory_id = ?");
                $upd->execute([$rackUuid, $startU, $height, $inventoryId]);
            }

            // ---- re-stamp the unit's cache ----------------------------------
            $uText = LocationResolver::uText($startU, $height);
            $sets   = ['Status = ?', 'RackPosition = ?'];
            $values = [2, $uText];
            if (SchemaHelper::hasColumn($pdo, self::TABLE, 'status_v2')) {
                $sets[]   = 'status_v2 = ?';
                $values[] = StatusMap::INVENTORY_LEGACY_TO_V2[2];
            }
            if ($rackLocationUuid !== null && SchemaHelper::hasColumn($pdo, self::TABLE, 'location_uuid')) {
                $sets[]   = 'location_uuid = ?';
                $values[] = $rackLocationUuid;
            }
            // Text mirror: written when there is a name, never blanked.
            if ($rackLocationName !== null && $rackLocationName !== '') {
                $sets[]   = 'Location = ?';
                $values[] = $rackLocationName;
            }
            $values[] = $inventoryId;
            $pdo->prepare("UPDATE " . self::TABLE . " SET " . implode(', ', $sets)
                . ", UpdatedAt = NOW() WHERE ID = ?")->execute($values);

            $to = [
                'rack_uuid'     => $rackUuid,
                'rack_name'     => $rack['name'],
                'floor'         => $floor,
                'start_u'       => $startU,
                'u_height'      => $height,
                'end_u'         => $endU,
                'location_uuid' => $rackLocationUuid,
                'location_name' => $rackLocationName,
            ];

            $where = "\"{$rack['name']}\" " . RackPlacement::positionText($startU, $height);
            self::recordMovement($pdo, $unit, [
                'from_location_uuid'  => $from['location_uuid'],
                'from_location_name'  => $from['location_name'],
                'from_store_location' => $from['store_location'],
                'to_location_uuid'    => $rackLocationUuid,
                'to_location_name'    => $rackLocationName,
                'to_store_location'   => null,
                'reason'              => $reason !== null ? $reason : ($current === null ? "Racked at {$where}" : "Moved within racks to {$where}"),
                'ticket_id'           => $ticketId,
                'moved_by'            => $userId,
            ]);

            if ($ownsTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("RackNetworkDevice::place error: " . $e->getMessage());
            return self::fail(500, 'The device could not be racked and nothing was changed');
        }

        $label = self::unitLabel($unit);
        self::logActivity($pdo, $userId, $current === null ? 'Network device racked' : 'Network device moved',
            "{$label} -> {$where}");

        return [
            'success' => true,
            'code'    => 200,
            'message' => "{$label} " . ($current === null ? 'racked at ' : 'moved to ') . $where,
            'data'    => [
                'moved'        => true,
                'inventory_id' => $inventoryId,
                'from'         => $from,
                'to'           => $to,
            ],
        ];
    }

    /**
     * Take a device out of its rack. It keeps its site and returns to available
     * stock; `store_location` (optional) records the shelf it is put on.
     *
     * @param array $ctx ['user_id', 'reason', 'ticket_id', 'store_location']
     * @return array{success:bool, code:int, message:string, data:array}
     */
    public static function unrack($pdo, $inventoryId, array $ctx = [])
    {
        $inventoryId = (int)$inventoryId;
        $userId      = isset($ctx['user_id']) ? $ctx['user_id'] : null;
        $reason      = isset($ctx['reason']) && trim((string)$ctx['reason']) !== '' ? trim((string)$ctx['reason']) : null;
        $ticketId    = !empty($ctx['ticket_id']) ? (int)$ctx['ticket_id'] : null;
        $storeGiven  = array_key_exists('store_location', $ctx);
        $store       = $storeGiven && trim((string)$ctx['store_location']) !== '' ? trim((string)$ctx['store_location']) : null;

        if (!self::available($pdo)) {
            return self::fail(503, 'Network devices are not available yet — the database migration for this feature has not been applied.');
        }
        if ($inventoryId <= 0) {
            return self::fail(400, 'inventory_id is required and must identify one physical device');
        }
        if ($store !== null && mb_strlen($store) > 100) {
            return self::fail(400, 'Shelf or bin must be 100 characters or fewer');
        }

        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM " . self::TABLE . " WHERE ID = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$inventoryId]);
            $unit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$unit) {
                return self::abort($pdo, $ownsTx, 404, 'That network device no longer exists');
            }

            $current = self::placementFor($pdo, $inventoryId);
            if ($current === null) {
                return self::abort($pdo, $ownsTx, 409, 'That device is not racked, so there is nothing to remove');
            }

            $pdo->prepare("DELETE FROM rack_network_devices WHERE inventory_id = ?")->execute([$inventoryId]);

            $sets   = ['RackPosition = NULL'];
            $values = [];
            // A failed unit that was racked stays failed; everything else returns to stock.
            if ((int)$unit['Status'] !== 0) {
                $sets[]   = 'Status = ?';
                $values[] = 1;
                if (SchemaHelper::hasColumn($pdo, self::TABLE, 'status_v2')) {
                    $sets[]   = 'status_v2 = ?';
                    $values[] = StatusMap::INVENTORY_LEGACY_TO_V2[1];
                }
            }
            $hasStore = SchemaHelper::hasColumn($pdo, self::TABLE, 'StoreLocation');
            if ($hasStore && $storeGiven) {
                $sets[]   = 'StoreLocation = ?';
                $values[] = $store;
            }
            $values[] = $inventoryId;
            $pdo->prepare("UPDATE " . self::TABLE . " SET " . implode(', ', $sets)
                . ", UpdatedAt = NOW() WHERE ID = ?")->execute($values);

            $locUuid = !empty($unit['location_uuid']) ? $unit['location_uuid'] : null;
            $locName = LocationResolver::locationName($pdo, $locUuid) ?: (!empty($unit['Location']) ? $unit['Location'] : null);
            $was     = "\"{$current['rack_name']}\" " . RackPlacement::positionText((int)$current['start_u'], (int)$current['u_height']);
            $shelf   = $hasStore ? ($storeGiven ? $store : (!empty($unit['StoreLocation']) ? $unit['StoreLocation'] : null)) : null;

            self::recordMovement($pdo, $unit, [
                'from_location_uuid'  => $locUuid,
                'from_location_name'  => $locName,
                'from_store_location' => !empty($unit['StoreLocation']) ? $unit['StoreLocation'] : null,
                'to_location_uuid'    => $locUuid,
                'to_location_name'    => $locName,
                'to_store_location'   => $shelf,
                'reason'              => $reason !== null ? $reason : "Unracked from {$was}",
                'ticket_id'           => $ticketId,
                'moved_by'            => $userId,
            ]);

            if ($ownsTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("RackNetworkDevice::unrack error: " . $e->getMessage());
            return self::fail(500, 'The device could not be removed from its rack and nothing was changed');
        }

        $label = self::unitLabel($unit);
        self::logActivity($pdo, $userId, 'Network device unracked', "{$label} <- {$was}");

        return [
            'success' => true,
            'code'    => 200,
            'message' => "{$label} removed from {$was}",
            'data'    => [
                'moved'         => true,
                'inventory_id'  => $inventoryId,
                'from'          => ['rack_uuid' => $current['rack_uuid'], 'rack_name' => $current['rack_name'],
                                    'start_u' => (int)$current['start_u'], 'u_height' => (int)$current['u_height']],
                'location_uuid' => $locUuid,
                'location_name' => $locName,
            ],
        ];
    }

    /* ============================================================
     * Internals
     * ============================================================ */

    /**
     * The movement row, so the unit's history reads "racked / unracked" beside
     * its site-to-site handovers. Guarded on the table: the placement is the
     * important part.
     */
    private static function recordMovement($pdo, array $unit, array $m)
    {
        if (!SchemaHelper::hasTable($pdo, 'component_movements')) {
            return;
        }
        $stmt = $pdo->prepare("INSERT INTO component_movements
            (component_type, inventory_id, component_uuid, component_name, serial_number, asset_tag,
             from_location_uuid, from_location_name, from_store_location,
             to_location_uuid,   to_location_name,   to_store_location,
             reason, ticket_id, handover_user_id, moved_by, moved_at)
            VALUES (?,?,?,?,?,?, ?,?,?, ?,?,?, ?,?,NULL,?, NOW())");
        $stmt->execute([
            self::TYPE,
            (int)$unit['ID'],
            isset($unit['UUID']) ? $unit['UUID'] : null,
            self::unitLabel($unit),
            isset($unit['SerialNumber']) ? $unit['SerialNumber'] : null,
            isset($unit['AssetTag']) ? $unit['AssetTag'] : null,
            $m['from_location_uuid'], $m['from_location_name'], $m['from_store_location'],
            $m['to_location_uuid'],   $m['to_location_name'],   $m['to_store_location'],
            $m['reason'], $m['ticket_id'], $m['moved_by'],
        ]);
    }

    private static function unitLabel(array $unit)
    {
        $label = 'NETWORKDEVICE';
        if (!empty($unit['SerialNumber'])) {
            return $label . ' SN ' . $unit['SerialNumber'];
        }
        if (!empty($unit['AssetTag'])) {
            return $label . ' ' . $unit['AssetTag'];
        }
        return $label . ' #' . (int)$unit['ID'];
    }

    /** Outside the transaction's success path: a logging failure must not undo a rack move. */
    private static function logActivity($pdo, $userId, $action, $detail)
    {
        try {
            logActivity($pdo, $userId, $action, 'rack', null, $detail);
        } catch (Throwable $e) {
            error_log("RackNetworkDevice::logActivity error: " . $e->getMessage());
        }
    }

    private static function abort($pdo, $ownsTx, $code, $message)
    {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return self::fail($code, $message);
    }

    private static function fail($code, $message)
    {
        return ['success' => false, 'code' => $code, 'message' => $message, 'data' => []];
    }
}
