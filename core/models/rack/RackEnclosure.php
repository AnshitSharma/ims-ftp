<?php
/**
 * RackEnclosure — a blade/modular chassis that occupies U space and holds servers.
 * File: core/models/rack/RackEnclosure.php
 *
 * THE THING THIS EXISTS FOR
 *   A Dell PowerEdge FX2s is 2U and holds four half-width FC630 sleds. Before
 *   this, `rack_servers` could only say "server X occupies U20-U21", and
 *   ServerRelocation refused the second sled at U20 with a 409. There was no
 *   object between "rack" and "server" to be the 2U box, and nowhere to record
 *   its service tag.
 *
 *   So an enclosure is racked, and servers are slotted into IT:
 *
 *     rack  --< rack_enclosures --< rack_servers (slotted)
 *           --< rack_servers (direct)
 *
 * WHY A SLED STILL CARRIES start_u AND u_height
 *   It mirrors the enclosure's. LocationResolver stamps the rack address onto
 *   the server AND onto every component installed in it from those two columns;
 *   so do syncPositionText, rack-get and the servers list. Mirroring keeps all
 *   of that correct with no change, where NULLing would blank the location on
 *   ~14 inventory rows per blade. restampSleds() below is the ONLY writer of
 *   the mirror, and every path that moves an enclosure goes through it.
 *
 * GEOMETRY IS A SNAPSHOT
 *   slot_rows/slot_cols/u_height are copied out of the chassis spec's
 *   `enclosure` block at creation time, for the same reason rack_servers.u_height
 *   always has been: the elevation must keep rendering the box that is actually
 *   bolted in, even if the spec file is later edited or the model retired.
 *
 * SCHEMA GUARD
 *   Everything here is inert until seeder 2026_09_03_003 is applied by hand —
 *   code reaches production ~20s after save, the seeder does not. Callers check
 *   RackPlacement::enclosuresAvailable() first; the methods here check again
 *   rather than trust them.
 */

require_once __DIR__ . '/RackPlacement.php';
require_once __DIR__ . '/../location/LocationResolver.php';
require_once __DIR__ . '/../chassis/ChassisManager.php';
require_once __DIR__ . '/../../helpers/SchemaHelper.php';

class RackEnclosure
{
    /* ============================================================
     * Geometry, read from the chassis spec
     * ============================================================ */

    /**
     * The enclosure geometry a chassis spec declares, or null when that spec is
     * not an enclosure at all.
     *
     * A model qualifies only by carrying `enclosure.is_enclosure: true` — never
     * by u_size or a name match. That flag is also what keeps an FX2s out of the
     * server builder's chassis picker: you cannot build a server whose chassis
     * IS the enclosure.
     *
     * @return array{model:string,u_height:int,slot_rows:int,slot_cols:int,
     *               slot_count:int,node_form_factors:array}|null
     */
    public static function geometryFromChassis($chassisUuid)
    {
        if (empty($chassisUuid)) {
            return null;
        }
        try {
            $specs = self::chassisManager()->loadChassisSpecsByUUID($chassisUuid);
            if (empty($specs['found'])) {
                return null;
            }
            $spec = $specs['specifications'];
            $encl = isset($spec['enclosure']) && is_array($spec['enclosure']) ? $spec['enclosure'] : null;
            if ($encl === null || empty($encl['is_enclosure'])) {
                return null;
            }

            $rows = isset($encl['slot_rows']) ? max(1, (int)$encl['slot_rows']) : 1;
            $cols = isset($encl['slot_cols']) ? max(1, (int)$encl['slot_cols']) : 1;
            // slot_count is declared as well as derivable; the grid wins, because
            // the grid is what the elevation draws and a disagreement would leave
            // a bay that can be filled but not rendered.
            $count = $rows * $cols;

            $uHeight = isset($spec['u_size']) ? (int)ceil((float)$spec['u_size']) : 1;

            return [
                'model'             => isset($spec['model']) ? $spec['model'] : 'Enclosure',
                'u_height'          => $uHeight >= 1 ? $uHeight : 1,
                'slot_rows'         => $rows,
                'slot_cols'         => $cols,
                'slot_count'        => $count,
                'node_form_factors' => isset($encl['node_form_factors']) && is_array($encl['node_form_factors'])
                    ? $encl['node_form_factors'] : [],
            ];
        } catch (Throwable $e) {
            error_log("RackEnclosure::geometryFromChassis error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Is this chassis spec an enclosure? Used by the builder's chassis picker to
     * exclude enclosures from the list of chassis a SERVER can be built on.
     */
    public static function isEnclosureChassis($chassisUuid)
    {
        return self::geometryFromChassis($chassisUuid) !== null;
    }

    /**
     * Every enclosure model in the catalog, for the "add enclosure" picker.
     *
     * Walks the chassis uuids and keeps the ones carrying an `enclosure` block.
     * Spec loads are request-cached inside ChassisManager, so this is one file
     * read however many models there are.
     *
     * Empty until ims-data/chassis/chasis-level-3.json carrying the FX2s has
     * been uploaded — that directory has no deploy watcher.
     */
    public static function availableModels()
    {
        $out = [];
        try {
            foreach (self::chassisManager()->getAllChassisUUIDs() as $uuid) {
                $geo = self::geometryFromChassis($uuid);
                if ($geo === null) {
                    continue;
                }
                $out[] = [
                    'chassis_uuid' => $uuid,
                    'model'        => $geo['model'],
                    'u_height'     => $geo['u_height'],
                    'slot_rows'    => $geo['slot_rows'],
                    'slot_cols'    => $geo['slot_cols'],
                    'slot_count'   => $geo['slot_count'],
                    'node_form_factors' => $geo['node_form_factors'],
                ];
            }
        } catch (Throwable $e) {
            error_log("RackEnclosure::availableModels error: " . $e->getMessage());
        }

        usort($out, function ($a, $b) { return strcmp($a['model'], $b['model']); });
        return $out;
    }

    /**
     * Where a bay sits inside the enclosure's own box, as fractions of it.
     *
     * 1-based, row-major: for an FX2s (2x2) slot 1 is top-left, 2 top-right,
     * 3 bottom-left, 4 bottom-right — matching Dell's bay labelling. This is the
     * single implementation; Rack View renders from the values it returns rather
     * than recomputing the grid in JavaScript.
     *
     * @return array{row:int,col:int,u_offset:float,u_span:float}|null
     */
    public static function slotGeometry($slotIndex, $rows, $cols, $uHeight)
    {
        $rows   = max(1, (int)$rows);
        $cols   = max(1, (int)$cols);
        $slotIndex = (int)$slotIndex;
        if ($slotIndex < 1 || $slotIndex > $rows * $cols) {
            return null;
        }

        $row = intdiv($slotIndex - 1, $cols);
        $col = ($slotIndex - 1) % $cols;
        // Cast: PHP's / yields int when the division is exact (2U over 2 rows) and
        // float when it is not (2U over 3). Pinning both to float keeps the JSON
        // type of u_span/u_offset stable whatever the grid happens to be.
        $span = (float)(max(1, (int)$uHeight) / $rows);

        return [
            'row'      => $row,
            'col'      => $col,
            'u_offset' => (float)($row * $span),   // U rows down from the top of the enclosure
            'u_span'   => $span,
        ];
    }

    /* ============================================================
     * Reads
     * ============================================================ */

    /** One enclosure row, or null. */
    public static function get($pdo, $enclosureUuid)
    {
        if (!RackPlacement::enclosuresAvailable($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM rack_enclosures WHERE enclosure_uuid = ? LIMIT 1");
        $stmt->execute([$enclosureUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Every enclosure in a rack, each with its bays resolved.
     *
     * Shape mirrors the `servers` array rack-get already returns, so Rack View
     * draws enclosures with the same start_u / u_height / end_u arithmetic it
     * uses for a direct sled.
     */
    public static function listForRack($pdo, $rackUuid)
    {
        if (!RackPlacement::enclosuresAvailable($pdo)) {
            return [];
        }

        $stmt = $pdo->prepare("SELECT * FROM rack_enclosures WHERE rack_uuid = ? ORDER BY start_u ASC");
        $stmt->execute([$rackUuid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return [];
        }

        // One query for every sled in every enclosure of this rack, rather than
        // one per enclosure.
        $sledStmt = $pdo->prepare("
            SELECT rs.enclosure_uuid, rs.slot_index, rs.config_uuid,
                   sc.server_name, sc.configuration_status, sc.chassis_uuid
              FROM rack_servers rs
              LEFT JOIN server_configurations sc ON sc.config_uuid = rs.config_uuid
             WHERE rs.rack_uuid = ? AND rs.enclosure_uuid IS NOT NULL
             ORDER BY rs.slot_index ASC
        ");
        $sledStmt->execute([$rackUuid]);

        $byEnclosure = [];
        foreach ($sledStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $byEnclosure[$s['enclosure_uuid']][(int)$s['slot_index']] = $s;
        }

        $out = [];
        foreach ($rows as $r) {
            $uuid    = $r['enclosure_uuid'];
            $rowsN   = max(1, (int)$r['slot_rows']);
            $colsN   = max(1, (int)$r['slot_cols']);
            $uHeight = max(1, (int)$r['u_height']);
            $startU  = (int)$r['start_u'];
            $filled  = isset($byEnclosure[$uuid]) ? $byEnclosure[$uuid] : [];

            $slots = [];
            for ($i = 1; $i <= $rowsN * $colsN; $i++) {
                $geo  = self::slotGeometry($i, $rowsN, $colsN, $uHeight);
                $sled = isset($filled[$i]) ? $filled[$i] : null;
                $slots[] = [
                    'slot_index' => $i,
                    'row'        => $geo['row'],
                    'col'        => $geo['col'],
                    'u_offset'   => $geo['u_offset'],
                    'u_span'     => $geo['u_span'],
                    'occupied'   => $sled !== null,
                    'config_uuid' => $sled ? $sled['config_uuid'] : null,
                    'server_name' => $sled ? ($sled['server_name'] ?? '(deleted server)') : null,
                    'configuration_status' => ($sled && $sled['configuration_status'] !== null)
                        ? (int)$sled['configuration_status'] : null,
                    'chassis_name' => $sled ? RackPlacement::chassisName($sled['chassis_uuid'] ?? null) : null,
                    'orphaned'     => $sled ? ($sled['server_name'] === null) : false,
                ];
            }

            $out[] = [
                'enclosure_uuid' => $uuid,
                'name'           => $r['name'],
                'model'          => $r['model'],
                'chassis_uuid'   => $r['chassis_uuid'],
                'serial_number'  => $r['serial_number'],
                'start_u'        => $startU,
                'u_height'       => $uHeight,
                'end_u'          => $startU + $uHeight - 1,
                'slot_rows'      => $rowsN,
                'slot_cols'      => $colsN,
                'slot_count'     => $rowsN * $colsN,
                'slots_used'     => count($filled),
                'notes'          => $r['notes'],
                'slots'          => $slots,
            ];
        }

        return $out;
    }

    /** slot_index => config_uuid for one enclosure. */
    public static function occupiedSlots($pdo, $enclosureUuid, $excludeConfigUuid = null)
    {
        if (!RackPlacement::enclosuresAvailable($pdo)) {
            return [];
        }
        $sql = "SELECT slot_index, config_uuid FROM rack_servers WHERE enclosure_uuid = ?";
        $params = [$enclosureUuid];
        if ($excludeConfigUuid !== null) {
            $sql .= " AND config_uuid <> ?";
            $params[] = $excludeConfigUuid;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['slot_index']] = $r['config_uuid'];
        }
        return $out;
    }

    /* ============================================================
     * Slot validation — used by ServerRelocation on the slotted path
     * ============================================================ */

    /**
     * Can this server go in this bay?
     *
     * Three refusals, in the order an operator would hit them: the bay does not
     * exist, the bay is taken, and the server is the wrong shape for it.
     *
     * A server with NO chassis yet is ALLOWED. Builds are routinely racked
     * before the chassis is picked — that is the whole reason
     * RackPlacement::syncHeightFromChassis exists — so only a POSITIVE mismatch
     * is refused, never a blank.
     *
     * @return array{success:bool, code:int, message:string}
     */
    public static function validateSlotFit($pdo, array $enclosure, $slotIndex, array $server, $excludeConfigUuid = null)
    {
        $rowsN = max(1, (int)$enclosure['slot_rows']);
        $colsN = max(1, (int)$enclosure['slot_cols']);
        $count = $rowsN * $colsN;
        $slotIndex = (int)$slotIndex;

        if ($slotIndex < 1 || $slotIndex > $count) {
            return self::fail(400, "\"{$enclosure['name']}\" has {$count} bay" . ($count === 1 ? '' : 's')
                . " (1-{$count}); bay {$slotIndex} does not exist");
        }

        $taken = self::occupiedSlots($pdo, $enclosure['enclosure_uuid'], $excludeConfigUuid);
        if (isset($taken[$slotIndex])) {
            $name = self::serverName($pdo, $taken[$slotIndex]);
            return self::fail(409, "Bay {$slotIndex} of \"{$enclosure['name']}\" is already occupied by "
                . ($name !== null ? "\"{$name}\"" : 'another server'));
        }

        $chassisUuid = isset($server['chassis_uuid']) ? $server['chassis_uuid'] : null;
        if (!empty($chassisUuid)) {
            // An enclosure is not a node. Slotting one into another is a
            // mis-click worth naming precisely.
            $asEnclosure = self::geometryFromChassis($chassisUuid);
            if ($asEnclosure !== null) {
                return self::fail(400, "\"{$asEnclosure['model']}\" is itself an enclosure and cannot be "
                    . "installed in a bay of \"{$enclosure['name']}\". Place it in the rack directly.");
            }

            $accepted = self::acceptedFormFactors($enclosure);
            if (!empty($accepted)) {
                $formFactor = self::chassisFormFactor($chassisUuid);
                if ($formFactor !== null && !in_array($formFactor, $accepted, true)) {
                    return self::fail(400, "\"{$enclosure['name']}\" takes " . implode(' / ', $accepted)
                        . " nodes; this server's chassis is {$formFactor}");
                }
            }
        }

        return ['success' => true, 'code' => 200, 'message' => 'ok'];
    }

    /**
     * Node form factors the enclosure accepts, re-read from the spec.
     *
     * NOT snapshotted onto the row, unlike the grid: the grid decides what the
     * elevation draws and must not change under a bolted-in box, but "what fits"
     * is a compatibility question that should follow the catalog. An enclosure
     * whose spec has since been retired accepts anything rather than nothing —
     * refusing every sled because a JSON file changed would be the worse failure.
     */
    private static function acceptedFormFactors(array $enclosure)
    {
        $geo = self::geometryFromChassis($enclosure['chassis_uuid'] ?? null);
        return $geo !== null ? $geo['node_form_factors'] : [];
    }

    private static function chassisFormFactor($chassisUuid)
    {
        try {
            $specs = self::chassisManager()->loadChassisSpecsByUUID($chassisUuid);
            if (!empty($specs['found']) && isset($specs['specifications']['form_factor'])) {
                return $specs['specifications']['form_factor'];
            }
        } catch (Throwable $e) {
            // best effort — an unresolvable spec must not block a placement
        }
        return null;
    }

    /* ============================================================
     * Writes
     * ============================================================ */

    /**
     * Bolt an enclosure into a rack.
     *
     * @param array $data ['name', 'chassis_uuid', 'start_u', 'serial_number', 'notes']
     * @return array{success:bool, code:int, message:string, data:array}
     */
    public static function create($pdo, $rackUuid, array $data, $userId = null)
    {
        if (!RackPlacement::enclosuresAvailable($pdo)) {
            return self::fail(503, 'Enclosure support is not available on this database yet');
        }

        $name        = trim((string)($data['name'] ?? ''));
        $chassisUuid = trim((string)($data['chassis_uuid'] ?? ''));
        $startU      = isset($data['start_u']) && $data['start_u'] !== '' ? (int)$data['start_u'] : 0;
        $serial      = trim((string)($data['serial_number'] ?? ''));
        $notes       = trim((string)($data['notes'] ?? ''));

        if ($name === '') {
            return self::fail(400, 'An enclosure name is required');
        }
        if (mb_strlen($name) > 100) {
            return self::fail(400, 'Enclosure name must be 100 characters or fewer');
        }
        if ($chassisUuid === '') {
            return self::fail(400, 'Choose the enclosure model');
        }
        if ($startU < 1) {
            return self::fail(400, 'start_u must be 1 or greater');
        }

        $rack = self::fetchRack($pdo, $rackUuid);
        if (!$rack) {
            return self::fail(404, 'Rack not found');
        }

        $geo = self::geometryFromChassis($chassisUuid);
        if ($geo === null) {
            return self::fail(400, 'That chassis model is not an enclosure. Only models that declare bays '
                . '(such as the PowerEdge FX2s) can hold servers.');
        }

        $fit = self::validateRackFit($pdo, $rack, $startU, $geo['u_height'], null);
        if (!$fit['success']) {
            return $fit;
        }

        if (self::nameTaken($pdo, $rackUuid, $name, null)) {
            return self::fail(409, "\"{$rack['name']}\" already has an enclosure called \"{$name}\"");
        }

        $enclosureUuid = self::newUuid();

        try {
            $stmt = $pdo->prepare("INSERT INTO rack_enclosures
                    (enclosure_uuid, rack_uuid, name, chassis_uuid, model, serial_number,
                     start_u, u_height, slot_rows, slot_cols, notes, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([
                $enclosureUuid, $rackUuid, $name, $chassisUuid, $geo['model'],
                $serial !== '' ? $serial : null,
                $startU, $geo['u_height'], $geo['slot_rows'], $geo['slot_cols'],
                $notes !== '' ? $notes : null, $userId,
            ]);
        } catch (Throwable $e) {
            error_log("RackEnclosure::create error: " . $e->getMessage());
            return self::fail(500, 'The enclosure could not be created');
        }

        return [
            'success' => true,
            'code'    => 200,
            'message' => "\"{$name}\" ({$geo['model']}) installed at "
                . RackPlacement::positionText($startU, $geo['u_height'])
                . " with {$geo['slot_count']} bays",
            'data'    => ['enclosure' => self::get($pdo, $enclosureUuid)],
        ];
    }

    /**
     * Rename, re-tag, or MOVE an enclosure.
     *
     * A start_u change carries every sled with it — physically it must, the
     * servers are bolted inside the box. So the U range is re-validated and
     * restampSleds() re-stamps the mirror and re-propagates each sled's address
     * down to its components, in one transaction with the move itself.
     *
     * The model is NOT changeable. Swapping an FX2s for a 4-bay box of another
     * shape would silently invalidate every bay number already assigned; remove
     * the enclosure and add the real one instead.
     */
    public static function update($pdo, $enclosureUuid, array $data, $userId = null)
    {
        if (!RackPlacement::enclosuresAvailable($pdo)) {
            return self::fail(503, 'Enclosure support is not available on this database yet');
        }

        $enclosure = self::get($pdo, $enclosureUuid);
        if (!$enclosure) {
            return self::fail(404, 'Enclosure not found');
        }
        $rack = self::fetchRack($pdo, $enclosure['rack_uuid']);
        if (!$rack) {
            return self::fail(404, 'The rack this enclosure is in no longer exists');
        }

        $fields = [];
        $values = [];
        $moved  = false;
        $newStartU = (int)$enclosure['start_u'];

        if (array_key_exists('name', $data)) {
            $name = trim((string)$data['name']);
            if ($name === '') {
                return self::fail(400, 'An enclosure name is required');
            }
            if (mb_strlen($name) > 100) {
                return self::fail(400, 'Enclosure name must be 100 characters or fewer');
            }
            if (self::nameTaken($pdo, $enclosure['rack_uuid'], $name, $enclosureUuid)) {
                return self::fail(409, "\"{$rack['name']}\" already has an enclosure called \"{$name}\"");
            }
            $fields[] = 'name = ?';
            $values[] = $name;
        }

        if (array_key_exists('serial_number', $data)) {
            $serial = trim((string)$data['serial_number']);
            $fields[] = 'serial_number = ?';
            $values[] = $serial !== '' ? $serial : null;
        }

        if (array_key_exists('notes', $data)) {
            $notes = trim((string)$data['notes']);
            $fields[] = 'notes = ?';
            $values[] = $notes !== '' ? $notes : null;
        }

        if (isset($data['start_u']) && $data['start_u'] !== '') {
            $newStartU = (int)$data['start_u'];
            if ($newStartU < 1) {
                return self::fail(400, 'start_u must be 1 or greater');
            }
            if ($newStartU !== (int)$enclosure['start_u']) {
                $fit = self::validateRackFit($pdo, $rack, $newStartU, (int)$enclosure['u_height'], $enclosureUuid);
                if (!$fit['success']) {
                    return $fit;
                }
                $fields[] = 'start_u = ?';
                $values[] = $newStartU;
                $moved = true;
            }
        }

        if (empty($fields)) {
            return [
                'success' => true, 'code' => 200,
                'message' => 'Nothing to change',
                'data'    => ['enclosure' => $enclosure, 'sleds_restamped' => 0],
            ];
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $values[] = $enclosureUuid;
            $stmt = $pdo->prepare("UPDATE rack_enclosures SET " . implode(', ', $fields)
                . ", updated_at = NOW() WHERE enclosure_uuid = ?");
            $stmt->execute($values);

            $restamped = $moved ? self::restampSleds($pdo, $enclosureUuid) : 0;

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("RackEnclosure::update error: " . $e->getMessage());
            return self::fail(500, 'The enclosure could not be updated and nothing was changed');
        }

        $msg = $moved
            ? "Enclosure moved to " . RackPlacement::positionText($newStartU, (int)$enclosure['u_height'])
                . ($restamped > 0 ? " \u{00B7} {$restamped} server(s) moved with it" : '')
            : 'Enclosure updated';

        return [
            'success' => true, 'code' => 200, 'message' => $msg,
            'data'    => ['enclosure' => self::get($pdo, $enclosureUuid), 'sleds_restamped' => $restamped],
        ];
    }

    /**
     * Unbolt an enclosure. Refused while it still holds servers — pulling the box
     * would leave its sleds claiming a U range nothing occupies, which is exactly
     * the class of drift ServerRelocation was written to end.
     */
    public static function remove($pdo, $enclosureUuid)
    {
        if (!RackPlacement::enclosuresAvailable($pdo)) {
            return self::fail(503, 'Enclosure support is not available on this database yet');
        }

        $enclosure = self::get($pdo, $enclosureUuid);
        if (!$enclosure) {
            return self::fail(404, 'Enclosure not found');
        }

        $used = self::occupiedSlots($pdo, $enclosureUuid);
        if (!empty($used)) {
            $n = count($used);
            return self::fail(400, "Cannot remove \"{$enclosure['name']}\" — it still holds {$n} server"
                . ($n === 1 ? '' : 's') . '. Move them out of its bays first.');
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM rack_enclosures WHERE enclosure_uuid = ?");
            $stmt->execute([$enclosureUuid]);
        } catch (Throwable $e) {
            error_log("RackEnclosure::remove error: " . $e->getMessage());
            return self::fail(500, 'The enclosure could not be removed');
        }

        return [
            'success' => true, 'code' => 200,
            'message' => "\"{$enclosure['name']}\" removed from the rack",
            'data'    => ['enclosure_uuid' => $enclosureUuid],
        ];
    }

    /**
     * Re-stamp every sled with its enclosure's rack and U range, then push each
     * one's address down to its components.
     *
     * THE ONLY WRITER OF THE MIRROR. If a second one ever appears, the sleds and
     * the box they are bolted into will disagree about where they are — see the
     * header of ServerRelocation for how that went last time.
     *
     * @return int number of sleds re-stamped
     */
    public static function restampSleds($pdo, $enclosureUuid)
    {
        $enclosure = self::get($pdo, $enclosureUuid);
        if (!$enclosure) {
            return 0;
        }

        $stmt = $pdo->prepare("UPDATE rack_servers
                                  SET rack_uuid = ?, start_u = ?, u_height = ?, updated_at = NOW()
                                WHERE enclosure_uuid = ?");
        $stmt->execute([
            $enclosure['rack_uuid'], (int)$enclosure['start_u'], (int)$enclosure['u_height'], $enclosureUuid,
        ]);

        $sleds = $pdo->prepare("SELECT config_uuid FROM rack_servers WHERE enclosure_uuid = ?");
        $sleds->execute([$enclosureUuid]);

        $n = 0;
        foreach ($sleds->fetchAll(PDO::FETCH_ASSOC) as $row) {
            RackPlacement::syncPositionText($pdo, $row['config_uuid']);
            LocationResolver::syncConfig($pdo, $row['config_uuid']);
            $n++;
        }
        return $n;
    }

    /* ============================================================
     * Internals
     * ============================================================ */

    /**
     * Rack bounds and U-range overlap for the enclosure itself, against the one
     * shared occupancy map — so an enclosure and a directly-racked server can
     * never both think they own U20.
     */
    private static function validateRackFit($pdo, array $rack, $startU, $uHeight, $excludeEnclosureUuid)
    {
        $endU = $startU + max(1, (int)$uHeight) - 1;

        if ($endU > (int)$rack['total_u']) {
            return self::fail(400, "A {$uHeight}U enclosure starting at U{$startU} would run past the top of "
                . "\"{$rack['name']}\" ({$rack['total_u']}U)");
        }

        $occupancy = RackPlacement::occupancy($pdo, $rack['rack_uuid'], [
            'enclosure_uuid' => $excludeEnclosureUuid,
        ]);
        $hit = RackPlacement::findCollision($occupancy, $startU, $endU);
        if ($hit !== null) {
            return self::fail(409, "U{$startU}-U{$endU} overlaps {$hit['label']}, already installed at "
                . "U{$hit['start_u']}-U{$hit['end_u']} in \"{$rack['name']}\"");
        }

        return ['success' => true, 'code' => 200, 'message' => 'ok', 'data' => []];
    }

    private static function nameTaken($pdo, $rackUuid, $name, $excludeEnclosureUuid)
    {
        $sql = "SELECT 1 FROM rack_enclosures WHERE rack_uuid = ? AND name = ?";
        $params = [$rackUuid, $name];
        if ($excludeEnclosureUuid !== null) {
            $sql .= " AND enclosure_uuid <> ?";
            $params[] = $excludeEnclosureUuid;
        }
        $sql .= " LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    private static function fetchRack($pdo, $rackUuid)
    {
        $stmt = $pdo->prepare("SELECT * FROM racks WHERE rack_uuid = ? LIMIT 1");
        $stmt->execute([$rackUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function serverName($pdo, $configUuid)
    {
        $stmt = $pdo->prepare("SELECT server_name FROM server_configurations WHERE config_uuid = ? LIMIT 1");
        $stmt->execute([$configUuid]);
        $name = $stmt->fetchColumn();
        return $name !== false ? $name : null;
    }

    private static function newUuid()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function fail($code, $message)
    {
        return ['success' => false, 'code' => $code, 'message' => $message, 'data' => []];
    }

    /** Shared ChassisManager (spec loads are request-cached inside it). */
    private static function chassisManager()
    {
        static $manager = null;
        if ($manager === null) {
            $manager = new ChassisManager();
        }
        return $manager;
    }
}
