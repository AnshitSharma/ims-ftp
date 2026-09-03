<?php
/**
 * ServerRelocation — moving a server, and everything inside it.
 * File: core/models/rack/ServerRelocation.php
 *
 * ONE DOOR, DELIBERATELY.
 *   Three callers move servers: the "Move server" dialog on the server card
 *   (rack-assign-server), Rack View's own place/move control (the same action),
 *   and an approved Move Server Request (RequestActionExecutor's
 *   `server.relocate`). All three come through move() here. The alternative --
 *   each doing its own placement write -- is how the components got left behind
 *   in the first place: rack-assign-server updated rack_servers and the
 *   server's rack_position text, and no other path knew the components needed
 *   re-stamping. A second implementation would drift again within a release.
 *
 * WHAT A MOVE IS
 *   A change to any part of the address: location, rack, start U, or -- since
 *   blade enclosures -- the enclosure bay. Pulling a server out of a rack while
 *   it stays on site is a move too, which is why rack_uuid is nullable on both
 *   sides. The from/to shape is uniform so an unrack, a rerack, a bay change and
 *   a site transfer all record identically.
 *
 * TWO DESTINATIONS, ONE DOOR
 *   A server goes either DIRECTLY into a rack at a U (validateDirectTarget) or
 *   into a BAY of an enclosure already in a rack (validateSlotTarget). The
 *   slotted case takes its rack and U range FROM the enclosure -- a sled cannot
 *   choose a U, it is bolted into a box that already has one. Both paths end at
 *   the same upsertPlacement, which is why moving a server out of a bay and
 *   into the rack proper clears enclosure_uuid rather than leaving a sled that
 *   claims a bay it has left.
 *
 * VALIDATION HAPPENS AT MOVE TIME, NOT REQUEST TIME
 *   A U-slot that was free when a request was raised may be occupied when it is
 *   approved days later. The bounds and overlap checks below therefore run on
 *   every call, including the Request path, and a failure rolls the whole thing
 *   back -- placement, propagation and movement row together. A half-applied
 *   move is worse than a refused one.
 *
 * WHY IT RETURNS A RESULT INSTEAD OF SENDING JSON
 *   send_json_response() exits. RequestActionExecutor has to be able to see a
 *   failure, roll its own transaction back and record the reason on the request,
 *   so this returns {success, code, message, data} and lets the caller decide.
 *
 * Depends on RackPlacement (heights + the derived rack_position text) and
 * LocationResolver (the address, and the propagation to components).
 */

require_once __DIR__ . '/RackPlacement.php';
require_once __DIR__ . '/RackEnclosure.php';
require_once __DIR__ . '/../location/LocationResolver.php';
require_once __DIR__ . '/../../helpers/SchemaHelper.php';

class ServerRelocation
{
    /**
     * Move a server to a location / rack / U.
     *
     * @param PDO    $pdo
     * @param string $configUuid
     * @param array  $target ['location_uuid' => ?string, 'rack_uuid' => ?string,
     *                        'start_u' => ?int, 'u_height' => ?int,
     *                        'enclosure_uuid' => ?string, 'slot_index' => ?int]
     *                       rack_uuid null (with a location) = keep it at the
     *                       site but out of any rack.
     *                       enclosure_uuid set = a bay; the rack and U range
     *                       come from the enclosure and start_u is ignored.
     * @param array  $ctx    ['user_id' => ?int, 'reason' => ?string,
     *                        'ticket_id' => ?int]
     * @return array{success:bool, code:int, message:string, data:array}
     */
    public static function move($pdo, $configUuid, array $target, array $ctx = [])
    {
        $rackUuid      = !empty($target['rack_uuid'])      ? $target['rack_uuid']      : null;
        $locationUuid  = !empty($target['location_uuid'])  ? $target['location_uuid']  : null;
        $enclosureUuid = !empty($target['enclosure_uuid']) ? $target['enclosure_uuid'] : null;
        $startU        = isset($target['start_u'])   && $target['start_u']   !== '' ? (int)$target['start_u']   : null;
        $heightGiven   = isset($target['u_height'])  && $target['u_height']  !== '' ? (int)$target['u_height']  : null;
        $slotIndex     = isset($target['slot_index']) && $target['slot_index'] !== '' ? (int)$target['slot_index'] : null;

        $userId   = isset($ctx['user_id'])   ? $ctx['user_id']   : null;
        $reason   = isset($ctx['reason'])    && $ctx['reason'] !== '' ? substr(trim($ctx['reason']), 0, 255) : null;
        $ticketId = isset($ctx['ticket_id']) && $ctx['ticket_id'] ? (int)$ctx['ticket_id'] : null;

        if (empty($configUuid)) {
            return self::fail(400, 'config_uuid is required');
        }
        if ($rackUuid === null && $locationUuid === null && $enclosureUuid === null) {
            return self::fail(400, 'A destination is required: give a rack, an enclosure bay, a location, or both');
        }

        // ---- The server ----------------------------------------------------
        try {
            $stmt = $pdo->prepare("SELECT config_uuid, server_name, is_virtual, chassis_uuid
                                     FROM server_configurations WHERE config_uuid = ? LIMIT 1");
            $stmt->execute([$configUuid]);
            $server = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("ServerRelocation::move load error: " . $e->getMessage());
            return self::fail(500, 'Failed to load the server configuration');
        }

        if (!$server) {
            return self::fail(404, 'Server configuration not found');
        }
        if ((int)$server['is_virtual'] === 1) {
            return self::fail(400, 'Virtual/test configurations have no physical location and cannot be moved');
        }

        // ---- Where it is now, captured before anything changes -------------
        $from = LocationResolver::resolveForConfig($pdo, $configUuid);
        if ($from === null) {
            return self::fail(404, 'Server configuration not found');
        }

        // ---- The destination ------------------------------------------------
        $rack   = null;
        $height = null;

        if ($enclosureUuid !== null) {
            // A bay. The enclosure supplies the rack and the U range, so
            // anything the caller sent for those is overwritten rather than
            // reconciled: a sled has no say in where its box is bolted.
            $check = self::validateSlotTarget($pdo, $enclosureUuid, $slotIndex, $locationUuid, $configUuid, $server);
            if (!$check['success']) {
                return $check;
            }
            $rack         = $check['data']['rack'];
            $rackUuid     = $check['data']['rack']['rack_uuid'];
            $height       = $check['data']['height'];
            $startU       = $check['data']['start_u'];
            $slotIndex    = $check['data']['slot_index'];
            $locationUuid = $check['data']['location_uuid'];
        } elseif ($rackUuid !== null) {
            $check = self::validateDirectTarget($pdo, $rackUuid, $locationUuid, $configUuid, $startU, $heightGiven, $server);
            if (!$check['success']) {
                return $check;
            }
            $rack         = $check['data']['rack'];
            $height       = $check['data']['height'];
            $startU       = $check['data']['start_u'];
            // A rack decides the location. If the caller named one, it has
            // already been checked to agree; if it did not, we take the rack's.
            $locationUuid = $check['data']['location_uuid'];
            // Placing directly in the rack LEAVES any bay the server was in.
            $slotIndex    = null;
        } elseif (!self::locationExists($pdo, $locationUuid)) {
            return self::fail(404, 'Location not found, or it has been retired');
        }

        // Nothing to do? Say so rather than writing a movement row that records
        // no movement.
        if (self::isSamePlace($from, $locationUuid, $rackUuid, $startU, $enclosureUuid, $slotIndex)) {
            return [
                'success' => true,
                'code'    => 200,
                'message' => 'The server is already there — nothing was changed',
                'data'    => ['moved' => false, 'from' => $from, 'to' => $from, 'components_updated' => 0],
            ];
        }

        // ---- Apply ----------------------------------------------------------
        // Only open a transaction if the caller has not already: the Request
        // path wraps every action of an approval in one, and a nested BEGIN
        // would throw.
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            if ($rackUuid !== null) {
                self::upsertPlacement($pdo, $configUuid, $rackUuid, $startU, $height, $userId, $enclosureUuid, $slotIndex);
            } else {
                // Out of the rack, still on site.
                $del = $pdo->prepare("DELETE FROM rack_servers WHERE config_uuid = ?");
                $del->execute([$configUuid]);
                self::setConfigLocation($pdo, $configUuid, $locationUuid);
            }

            // rack_position text first (RackPlacement owns it), then the full
            // address onto the config and every component.
            RackPlacement::syncPositionText($pdo, $configUuid);
            $componentsMoved = LocationResolver::syncConfig($pdo, $configUuid);

            $to = LocationResolver::resolveForConfig($pdo, $configUuid);

            self::recordMovement($pdo, $configUuid, $from, $to, $componentsMoved, $reason, $ticketId, $userId);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("ServerRelocation::move apply error: " . $e->getMessage());
            return self::fail(500, 'The move could not be completed and was rolled back');
        }

        // Activity log is outside the transaction on purpose: it is a record of
        // what happened, and a logging failure must not undo a completed move.
        self::logMove($pdo, $userId, $server['server_name'], $from, $to, $componentsMoved);

        return [
            'success' => true,
            'code'    => 200,
            'message' => self::successMessage($to, $componentsMoved),
            'data'    => [
                'moved'              => true,
                'from'               => $from,
                'to'                 => $to,
                'components_updated' => $componentsMoved,
            ],
        ];
    }

    /**
     * Take a server out of whatever rack it is in, leaving it at the same site.
     *
     * A separate method rather than move() with a null rack, because there is no
     * destination to name: the server keeps the location it already had. Forcing
     * the caller to supply one would make "remove from rack" ask a question it
     * has no business asking.
     *
     * It still goes through the same propagation and records the same movement
     * row, so a component does not keep claiming a U it no longer occupies --
     * which is the bug this whole feature exists to close, in its simplest form.
     *
     * @return array{success:bool, code:int, message:string, data:array}
     */
    public static function unrack($pdo, $configUuid, array $ctx = [])
    {
        if (empty($configUuid)) {
            return self::fail(400, 'config_uuid is required');
        }

        $userId   = isset($ctx['user_id'])   ? $ctx['user_id']   : null;
        $reason   = isset($ctx['reason'])    && $ctx['reason'] !== '' ? substr(trim($ctx['reason']), 0, 255) : null;
        $ticketId = isset($ctx['ticket_id']) && $ctx['ticket_id'] ? (int)$ctx['ticket_id'] : null;

        $from = LocationResolver::resolveForConfig($pdo, $configUuid);
        if ($from === null) {
            return self::fail(404, 'Server configuration not found');
        }
        if (!$from['is_racked']) {
            return self::fail(404, 'Server is not currently installed in any rack');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $del = $pdo->prepare("DELETE FROM rack_servers WHERE config_uuid = ?");
            $del->execute([$configUuid]);

            // The location the rack gave it becomes the location it keeps --
            // the hardware is still in the building, just not in a rack.
            if ($from['location_uuid'] !== null) {
                self::setConfigLocation($pdo, $configUuid, $from['location_uuid']);
            }

            RackPlacement::syncPositionText($pdo, $configUuid);
            $componentsMoved = LocationResolver::syncConfig($pdo, $configUuid);

            $to = LocationResolver::resolveForConfig($pdo, $configUuid);
            self::recordMovement($pdo, $configUuid, $from, $to, $componentsMoved, $reason, $ticketId, $userId);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("ServerRelocation::unrack error: " . $e->getMessage());
            return self::fail(500, 'The server could not be removed from the rack; nothing was changed');
        }

        self::logMove($pdo, $userId, $from['server_name'], $from, $to, $componentsMoved);

        $msg = 'Server removed from rack';
        if ($componentsMoved > 0) {
            $msg .= " \u{00B7} {$componentsMoved} installed component"
                . ($componentsMoved === 1 ? '' : 's') . ' no longer report a U position';
        }

        return [
            'success' => true,
            'code'    => 200,
            'message' => $msg,
            'data'    => [
                'config_uuid'        => $configUuid,
                'from'               => $from,
                'to'                 => $to,
                'components_updated' => $componentsMoved,
            ],
        ];
    }

    /* ============================================================
     * Destination validation
     * ============================================================ */

    /**
     * A BAY of an enclosure: the enclosure must exist, be in a rack at the site
     * the caller named, and have that bay free and the right shape.
     *
     * Everything positional is TAKEN from the enclosure, never from the caller.
     * start_u and u_height are its own, which is what lets four FC630 sleds all
     * report U20-U21 without any of them overlapping: the U range is claimed
     * once, by the box.
     */
    private static function validateSlotTarget($pdo, $enclosureUuid, $slotIndex, $locationUuid, $configUuid, array $server)
    {
        if (!RackPlacement::enclosuresAvailable($pdo)) {
            return self::fail(503, 'Enclosure support is not available on this database yet');
        }
        if ($slotIndex === null || $slotIndex < 1) {
            return self::fail(400, 'Choose which bay of the enclosure to install the server in');
        }

        $enclosure = RackEnclosure::get($pdo, $enclosureUuid);
        if (!$enclosure) {
            return self::fail(404, 'Enclosure not found');
        }

        $stmt = $pdo->prepare("SELECT * FROM racks WHERE rack_uuid = ? LIMIT 1");
        $stmt->execute([$enclosure['rack_uuid']]);
        $rack = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rack) {
            return self::fail(404, 'The rack this enclosure is in no longer exists');
        }

        $rackLocationUuid = SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid')
            ? ($rack['location_uuid'] ?: null)
            : null;

        // Same rule as the direct path: a named location that disagrees with
        // where the hardware actually is gets refused, not silently resolved.
        if ($locationUuid !== null && $rackLocationUuid !== null && $locationUuid !== $rackLocationUuid) {
            return self::fail(400, 'That enclosure is not at the location you selected');
        }

        $fit = RackEnclosure::validateSlotFit($pdo, $enclosure, $slotIndex, $server, $configUuid);
        if (!$fit['success']) {
            return $fit;
        }

        return [
            'success' => true,
            'code'    => 200,
            'message' => 'ok',
            'data'    => [
                'rack'          => $rack,
                'enclosure'     => $enclosure,
                'slot_index'    => (int)$slotIndex,
                'height'        => max(1, (int)$enclosure['u_height']),
                'start_u'       => (int)$enclosure['start_u'],
                'location_uuid' => $locationUuid !== null ? $locationUuid : $rackLocationUuid,
            ],
        ];
    }

    /**
     * Rack bounds, rack/location agreement and U-range overlap.
     *
     * These are the checks handleRackAssignServer has always made; they live
     * here now so the Request path and Rack View cannot diverge from the card.
     *
     * The overlap test runs against RackPlacement::occupancy(), which counts
     * direct servers AND enclosures but not sleds -- so this refuses a 2U server
     * dropped on top of an FX2s, and does not refuse one merely because four
     * sleds inside that FX2s all report the same U range.
     */
    private static function validateDirectTarget($pdo, $rackUuid, $locationUuid, $configUuid, $startU, $heightGiven, array $server)
    {
        if ($startU === null || $startU < 1) {
            return self::fail(400, 'start_u must be 1 or greater');
        }

        $stmt = $pdo->prepare("SELECT * FROM racks WHERE rack_uuid = ? LIMIT 1");
        $stmt->execute([$rackUuid]);
        $rack = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rack) {
            return self::fail(404, 'Rack not found');
        }

        $rackLocationUuid = SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid')
            ? ($rack['location_uuid'] ?: null)
            : null;

        // A rack belongs to one location. If the caller named a different one,
        // refuse rather than quietly moving the server somewhere it did not ask
        // for -- a hand-built POST is the only way to reach this, and silently
        // preferring one of the two would make the response a lie.
        if ($locationUuid !== null && $rackLocationUuid !== null && $locationUuid !== $rackLocationUuid) {
            return self::fail(400, 'That rack is not at the location you selected');
        }

        $height = $heightGiven !== null
            ? max(1, $heightGiven)
            : RackPlacement::deriveUHeight($server['chassis_uuid'] ?? null);
        $endU = $startU + $height - 1;

        if ($endU > (int)$rack['total_u']) {
            return self::fail(400, "The server ({$height}U) starting at U{$startU} would run past the top of "
                . "\"{$rack['name']}\" ({$rack['total_u']}U)");
        }

        $occupancy = RackPlacement::occupancy($pdo, $rackUuid, ['config_uuid' => $configUuid]);
        $hit = RackPlacement::findCollision($occupancy, $startU, $endU);
        if ($hit !== null) {
            $what = $hit['kind'] === 'enclosure'
                ? "enclosure {$hit['label']}"
                : "\"{$hit['label']}\"";
            return self::fail(409, "U{$startU}-U{$endU} overlaps {$what}, already installed at "
                . "U{$hit['start_u']}-U{$hit['end_u']} in \"{$rack['name']}\""
                . ($hit['kind'] === 'enclosure'
                    ? '. To put this server inside it, choose one of its bays instead.' : ''));
        }

        return [
            'success' => true,
            'code'    => 200,
            'message' => 'ok',
            'data'    => [
                'rack'          => $rack,
                'height'        => $height,
                'start_u'       => $startU,
                'location_uuid' => $locationUuid !== null ? $locationUuid : $rackLocationUuid,
            ],
        ];
    }

    /**
     * Does this location exist and is it still in service? Retired sites are
     * refused as destinations -- you can read history that names them, but you
     * cannot move new hardware into one.
     */
    private static function locationExists($pdo, $locationUuid)
    {
        if (!SchemaHelper::hasTable($pdo, 'locations')) {
            // Pre-seeder: there is no location table to validate against, so a
            // rack-only move is the only kind available and this branch is
            // unreachable from the UI. Fail closed.
            return false;
        }
        try {
            $stmt = $pdo->prepare("SELECT is_active FROM locations WHERE location_uuid = ? LIMIT 1");
            $stmt->execute([$locationUuid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false && (int)$row['is_active'] === 1;
        } catch (Throwable $e) {
            error_log("ServerRelocation::locationExists error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Is the destination the place the server is already in?
     *
     * The bay is part of the address: moving a sled from bay 1 to bay 3 of the
     * same FX2s keeps the location, the rack AND the U range, and is still a
     * real move that must be written and recorded.
     */
    private static function isSamePlace(array $from, $locationUuid, $rackUuid, $startU, $enclosureUuid = null, $slotIndex = null)
    {
        $fromSlot = isset($from['slot_index']) && $from['slot_index'] !== null ? (int)$from['slot_index'] : null;
        $toSlot   = $slotIndex !== null ? (int)$slotIndex : null;

        return ($from['location_uuid'] ?? null)  === $locationUuid
            && ($from['rack_uuid'] ?? null)      === $rackUuid
            && (int)($from['start_u'] ?? 0)      === (int)$startU
            && ($from['enclosure_uuid'] ?? null) === $enclosureUuid
            && $fromSlot === $toSlot;
    }

    /* ============================================================
     * Writes
     * ============================================================ */

    /**
     * Place or move the rack_servers row. UNIQUE(config_uuid) means a server is
     * in at most one rack, so this is an update-or-insert, never two rows.
     *
     * enclosure_uuid / slot_index are written on EVERY path, including as NULL:
     * a server moved from a bay into the rack proper must stop claiming that
     * bay, and the UNIQUE(enclosure_uuid, slot_index) index would otherwise keep
     * the bay reserved by a server that has left it.
     */
    private static function upsertPlacement($pdo, $configUuid, $rackUuid, $startU, $height, $userId,
                                            $enclosureUuid = null, $slotIndex = null)
    {
        $withSlots = RackPlacement::enclosuresAvailable($pdo);

        $cur = $pdo->prepare("SELECT id FROM rack_servers WHERE config_uuid = ? LIMIT 1");
        $cur->execute([$configUuid]);

        if ($cur->fetch(PDO::FETCH_ASSOC)) {
            $set    = "rack_uuid = ?, start_u = ?, u_height = ?";
            $params = [$rackUuid, $startU, $height];
            if ($withSlots) {
                $set .= ", enclosure_uuid = ?, slot_index = ?";
                $params[] = $enclosureUuid;
                $params[] = $slotIndex;
            }
            $params[] = $configUuid;

            $stmt = $pdo->prepare("UPDATE rack_servers SET {$set}, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute($params);
        } else {
            $cols   = "rack_uuid, config_uuid, start_u, u_height";
            $marks  = "?, ?, ?, ?";
            $params = [$rackUuid, $configUuid, $startU, $height];
            if ($withSlots) {
                $cols  .= ", enclosure_uuid, slot_index";
                $marks .= ", ?, ?";
                $params[] = $enclosureUuid;
                $params[] = $slotIndex;
            }
            $cols  .= ", created_by, created_at, updated_at";
            $marks .= ", ?, NOW(), NOW()";
            $params[] = $userId;

            $stmt = $pdo->prepare("INSERT INTO rack_servers ({$cols}) VALUES ({$marks})");
            $stmt->execute($params);
        }
    }

    /**
     * An unracked server's location is authored, not derived, so it is written
     * here rather than by LocationResolver::syncConfig().
     */
    private static function setConfigLocation($pdo, $configUuid, $locationUuid)
    {
        $fields = [];
        $values = [];

        if (SchemaHelper::hasColumn($pdo, 'server_configurations', 'location_uuid')) {
            $fields[] = 'location_uuid = ?';
            $values[] = $locationUuid;
        }
        $name = LocationResolver::locationName($pdo, $locationUuid);
        if ($name !== null) {
            $fields[] = 'location = ?';
            $values[] = $name;
        }
        if (empty($fields)) {
            return;
        }

        $values[] = $configUuid;
        $stmt = $pdo->prepare("UPDATE server_configurations SET " . implode(', ', $fields)
            . " WHERE config_uuid = ?");
        $stmt->execute($values);
    }

    /**
     * The movement row. Names are snapshotted alongside the uuids so the record
     * still reads correctly after a rename, a rack decommission or a location
     * delete -- see the header of seeder 2026_08_26_004.
     *
     * Guarded by a table probe: the move itself is the important part, and until
     * the seeder is run by hand it simply records nothing.
     */
    private static function recordMovement($pdo, $configUuid, array $from, array $to, $componentsMoved, $reason, $ticketId, $userId)
    {
        if (!SchemaHelper::hasTable($pdo, 'server_movements')) {
            return;
        }

        $stmt = $pdo->prepare("INSERT INTO server_movements
            (config_uuid,
             from_location_uuid, from_location_name, from_rack_uuid, from_rack_name, from_floor, from_start_u, from_u_height,
             to_location_uuid,   to_location_name,   to_rack_uuid,   to_rack_name,   to_floor,   to_start_u,   to_u_height,
             components_moved, reason, ticket_id, moved_by, moved_at)
            VALUES (?, ?,?,?,?,?,?,?, ?,?,?,?,?,?,?, ?, ?, ?, ?, NOW())");

        $stmt->execute([
            $configUuid,
            $from['location_uuid'], $from['location_name'], $from['rack_uuid'], $from['rack_name'],
            $from['floor'], $from['start_u'], $from['u_height'],
            $to['location_uuid'],   $to['location_name'],   $to['rack_uuid'],   $to['rack_name'],
            $to['floor'],   $to['start_u'],   $to['u_height'],
            $componentsMoved, $reason, $ticketId, $userId,
        ]);
    }

    /**
     * Movement history for one server, newest first.
     */
    public static function history($pdo, $configUuid, $limit = 50)
    {
        if (!SchemaHelper::hasTable($pdo, 'server_movements')) {
            return [];
        }
        try {
            $limit = max(1, min(200, (int)$limit));
            $stmt = $pdo->prepare("SELECT m.*, u.username AS moved_by_username
                                     FROM server_movements m
                                     LEFT JOIN users u ON u.id = m.moved_by
                                    WHERE m.config_uuid = ?
                                    ORDER BY m.moved_at DESC, m.id DESC
                                    LIMIT {$limit}");
            $stmt->execute([$configUuid]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("ServerRelocation::history error: " . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
     * Reporting
     * ============================================================ */

    private static function logMove($pdo, $userId, $serverName, array $from, array $to, $componentsMoved)
    {
        try {
            $fromText = LocationResolver::formatAddress($from) ?: 'unplaced';
            $toText   = LocationResolver::formatAddress($to)   ?: 'unplaced';
            logActivity($pdo, $userId, 'Server relocated', 'rack', null,
                "{$serverName}: {$fromText} -> {$toText} ({$componentsMoved} component(s) moved)");
        } catch (Throwable $e) {
            error_log("ServerRelocation::logMove error: " . $e->getMessage());
        }
    }

    /**
     * The component count is in the message on purpose: it is the part of the
     * move a user cannot see for themselves, and the whole reason this feature
     * exists.
     */
    private static function successMessage(array $to, $componentsMoved)
    {
        $where = LocationResolver::formatAddress($to);
        $msg = $where !== null ? "Server moved to {$where}" : 'Server moved';

        if ($componentsMoved > 0) {
            $msg .= " \u{00B7} {$componentsMoved} installed component"
                . ($componentsMoved === 1 ? '' : 's') . ' moved with it';
        }
        return $msg;
    }

    private static function fail($code, $message)
    {
        return ['success' => false, 'code' => $code, 'message' => $message, 'data' => []];
    }
}
