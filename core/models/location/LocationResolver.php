<?php
/**
 * LocationResolver — the single answer to "where is this?".
 * File: core/models/location/LocationResolver.php
 *
 * THE MODEL
 *   locations --< racks --< rack_servers --< server_configurations --< {type}inventory
 *
 *   For a component INSTALLED in a racked server, its physical address is
 *   derivable and therefore has exactly one truth:
 *     {type}inventory.ServerUUID -> server_configurations.config_uuid
 *       -> rack_servers (start_u, u_height) -> racks (floor) -> locations (name)
 *
 *   For LOOSE stock there is nothing to derive from, so the inventory row owns
 *   its own location_uuid plus a StoreLocation shelf note.
 *
 * WHY THIS CLASS EXISTS
 *   ServerBuilder::updateComponentStatusAndServerUuid() stamps Location and
 *   RackPosition onto an inventory row ONCE, when the component is added. A
 *   later rack move updated rack_servers and the server's own rack_position
 *   text, and nothing else — so a server moved from Noida U21 to Jaipur U8 left
 *   every CPU, DIMM and disk inside it still claiming Noida U21. The stamps were
 *   a snapshot pretending to be a fact.
 *
 *   syncConfig() is the fix: it recomputes the address from the placement and
 *   rewrites it onto the config and every component in it, in the caller's
 *   transaction. The legacy text columns become a CACHE that agrees with the
 *   join above, instead of an independently-authored value that drifts.
 *
 * DEPLOY ORDER
 *   Code reaches production ~20s after a save; the seeders that add `locations`,
 *   racks.location_uuid, racks.floor and the per-inventory location_uuid are run
 *   by hand afterwards. Every table and column touched here is probed through
 *   SchemaHelper first, so in that window this class writes only what exists and
 *   the system behaves exactly as it did before the feature shipped. It must
 *   never throw for a missing column.
 *
 * WHAT THIS CLASS WILL NOT DO
 *   It never CLEARS a location it could not resolve. A NULL resolution means
 *   "unknown", not "nowhere", and blanking a real value on the strength of an
 *   unrun seeder would destroy the only record of where something is. The one
 *   exception is RackPosition, which is cleared when a server leaves its rack —
 *   there genuinely is no U position then, and that is the pre-existing
 *   behaviour of the release path in ServerBuilder.
 *
 * Used by api/handlers/rack/rack_api.php, api/handlers/location/location_api.php,
 * core/models/rack/ServerRelocation.php and api/handlers/server/server_api.php.
 */

require_once __DIR__ . '/../../helpers/SchemaHelper.php';

class LocationResolver
{
    /**
     * Inventory tables a server's components can live in. Mirrors
     * VALID_COMPONENT_TYPES (BaseFunctions.php:30) — serverplatform included,
     * because a platform-owned build holds its platform unit the same way it
     * holds a CPU, and it moves with the server like everything else.
     */
    const COMPONENT_TYPES = [
        'cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy',
        'chassis', 'pciecard', 'risercard', 'hbacard', 'sfp', 'serverplatform',
    ];

    /* ============================================================
     * Reading
     * ============================================================ */

    /**
     * The physical address of a server configuration.
     *
     * Racked   -> everything comes from the rack: the rack decides the location,
     *             not the config, so a move needs one write and cannot disagree
     *             with itself.
     * Unracked -> falls back to the config's own location_uuid (a staging room,
     *             a bench, in transit), with no rack and no U.
     *
     * @return array|null null only when the config does not exist.
     */
    public static function resolveForConfig($pdo, $configUuid)
    {
        $hasLocations   = SchemaHelper::hasTable($pdo, 'locations');
        $rackHasLoc     = SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid');
        $rackHasFloor   = SchemaHelper::hasColumn($pdo, 'racks', 'floor');
        $configHasLoc   = SchemaHelper::hasColumn($pdo, 'server_configurations', 'location_uuid');

        // Built up rather than written out, because each piece depends on a
        // column that may not exist yet. Every fragment is a literal — no
        // request input reaches the SQL text.
        $select = [
            'sc.config_uuid',
            'sc.server_name',
            'sc.location AS legacy_location',
            'rs.rack_uuid',
            'rs.start_u',
            'rs.u_height',
            'r.name AS rack_name',
            'r.total_u',
        ];
        $select[] = $rackHasFloor ? 'r.floor AS floor' : 'NULL AS floor';
        $select[] = $rackHasLoc   ? 'r.location_uuid AS rack_location_uuid' : 'NULL AS rack_location_uuid';
        $select[] = $configHasLoc ? 'sc.location_uuid AS config_location_uuid' : 'NULL AS config_location_uuid';

        $sql = "SELECT " . implode(', ', $select) . "
                  FROM server_configurations sc
                  LEFT JOIN rack_servers rs ON rs.config_uuid = sc.config_uuid
                  LEFT JOIN racks r         ON r.rack_uuid    = rs.rack_uuid
                 WHERE sc.config_uuid = ? LIMIT 1";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$configUuid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("LocationResolver::resolveForConfig error: " . $e->getMessage());
            return null;
        }

        if (!$row) {
            return null;
        }

        $isRacked = !empty($row['rack_uuid']);

        // The rack wins when there is one. This is the whole point: a racked
        // server cannot be at a different site from its rack.
        $locationUuid = $isRacked
            ? ($row['rack_location_uuid'] ?: null)
            : ($row['config_location_uuid'] ?: null);

        $startU = $isRacked ? (int)$row['start_u'] : null;
        $height = $isRacked ? max(1, (int)$row['u_height']) : null;

        return [
            'config_uuid'   => $row['config_uuid'],
            'server_name'   => $row['server_name'],
            'is_racked'     => $isRacked,
            'location_uuid' => $locationUuid,
            'location_name' => $hasLocations ? self::locationName($pdo, $locationUuid) : null,
            'rack_uuid'     => $isRacked ? $row['rack_uuid'] : null,
            'rack_name'     => $isRacked ? $row['rack_name'] : null,
            'floor'         => $isRacked ? $row['floor'] : null,
            'total_u'       => $isRacked ? (int)$row['total_u'] : null,
            'start_u'       => $startU,
            'u_height'      => $height,
            'end_u'         => $isRacked ? $startU + $height - 1 : null,
            'u_text'        => self::uText($startU, $height),
            // Kept so a caller can tell "resolved nothing" from "was never set",
            // and so the pre-seeder fallback has something to display.
            'legacy_location' => $row['legacy_location'],
        ];
    }

    /**
     * The U-range text stored in server_configurations.rack_position and
     * {type}inventory.RackPosition. Both are varchar(20), which is why this is a
     * bare range and never embeds the rack name.
     */
    public static function uText($startU, $height)
    {
        if (!$startU) {
            return null;
        }
        $height = max(1, (int)$height);
        return $height > 1 ? "U{$startU}-U" . ($startU + $height - 1) : "U{$startU}";
    }

    /**
     * One-line address for display. Omits the parts that do not apply, so an
     * unracked server reads "Yotta Noida" rather than "Yotta Noida · — · —".
     */
    public static function formatAddress(array $addr)
    {
        $parts = array_filter([
            $addr['location_name'] ?? null,
            !empty($addr['floor']) ? 'Floor ' . $addr['floor'] : null,
            $addr['rack_name'] ?? null,
            $addr['u_text'] ?? null,
        ], function ($p) { return $p !== null && $p !== ''; });

        return empty($parts) ? null : implode(" \u{00B7} ", $parts);
    }

    /**
     * Name of one location, or null. Cached per request — the servers list and
     * the component list both resolve the same handful of locations repeatedly.
     */
    public static function locationName($pdo, $locationUuid)
    {
        if (empty($locationUuid) || !SchemaHelper::hasTable($pdo, 'locations')) {
            return null;
        }

        static $cache = [];
        if (array_key_exists($locationUuid, $cache)) {
            return $cache[$locationUuid];
        }

        try {
            $stmt = $pdo->prepare("SELECT name FROM locations WHERE location_uuid = ? LIMIT 1");
            $stmt->execute([$locationUuid]);
            $name = $stmt->fetchColumn();
            $cache[$locationUuid] = ($name !== false) ? $name : null;
        } catch (Throwable $e) {
            error_log("LocationResolver::locationName error: " . $e->getMessage());
            $cache[$locationUuid] = null;
        }

        return $cache[$locationUuid];
    }

    /**
     * Add the resolved physical address to a page of inventory rows.
     *
     * This is what makes "search for a component and find out where it is" work.
     * The inventory row already carries the synced Location name and the U text;
     * what it cannot carry is WHICH RACK, because that is a property of the
     * server it is installed in. One grouped query over the whole page supplies
     * it -- not one query per row, which on a 100-row CPU page would be 100
     * round trips for a single column.
     *
     * Rows are enriched in place with:
     *   location_name  resolved, falling back to the row's own synced text
     *   rack_name      null for loose stock -- there is no rack
     *   floor          the rack's floor
     *   server_name    which build it is installed in
     *   address_text   the one-line answer, e.g. "Yotta Noida - RACK 682 - U21"
     *                  or "Yotta Noida - Shelf B3" for free stock
     *
     * @param array $rows passed by reference; keys are added, none are removed.
     */
    public static function enrichComponentRows($pdo, array &$rows)
    {
        if (empty($rows)) {
            return;
        }

        // --- where each server is, in one query -----------------------------
        $serverUuids = [];
        foreach ($rows as $row) {
            if (!empty($row['ServerUUID'])) {
                $serverUuids[$row['ServerUUID']] = true;
            }
        }

        $byServer = [];
        if (!empty($serverUuids)) {
            $uuids = array_keys($serverUuids);
            $in = implode(',', array_fill(0, count($uuids), '?'));

            $rackHasFloor = SchemaHelper::hasColumn($pdo, 'racks', 'floor');
            $rackHasLoc   = SchemaHelper::hasColumn($pdo, 'racks', 'location_uuid');
            $hasLocations = SchemaHelper::hasTable($pdo, 'locations');

            $floorSel = $rackHasFloor ? 'r.floor' : 'NULL AS floor';
            $nameSel  = ($rackHasLoc && $hasLocations) ? 'l.name AS location_name' : 'NULL AS location_name';
            $locJoin  = ($rackHasLoc && $hasLocations)
                ? 'LEFT JOIN locations l ON l.location_uuid = r.location_uuid' : '';

            try {
                $stmt = $pdo->prepare("
                    SELECT sc.config_uuid, sc.server_name,
                           rs.start_u, rs.u_height,
                           r.name AS rack_name, {$floorSel}, {$nameSel}
                      FROM server_configurations sc
                      LEFT JOIN rack_servers rs ON rs.config_uuid = sc.config_uuid
                      LEFT JOIN racks r         ON r.rack_uuid    = rs.rack_uuid
                      {$locJoin}
                     WHERE sc.config_uuid IN ({$in})
                ");
                $stmt->execute($uuids);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byServer[$r['config_uuid']] = $r;
                }
            } catch (Throwable $e) {
                // Decoration. A failure here must not take an inventory page
                // down -- the rows still render with their own synced text.
                error_log("LocationResolver::enrichComponentRows error: " . $e->getMessage());
            }
        }

        // --- fill in ---------------------------------------------------------
        foreach ($rows as &$row) {
            $server = (!empty($row['ServerUUID']) && isset($byServer[$row['ServerUUID']]))
                ? $byServer[$row['ServerUUID']]
                : null;

            $row['server_name'] = $server ? $server['server_name'] : null;
            $row['rack_name']   = $server ? $server['rack_name']   : null;
            $row['floor']       = $server ? $server['floor']       : null;

            // Prefer the live resolution; fall back to the name synced onto the
            // row, so a component whose location has not been linked yet still
            // shows the place it has always shown.
            $resolved = $server && !empty($server['location_name']) ? $server['location_name'] : null;
            if ($resolved === null && !empty($row['location_uuid'])) {
                $resolved = self::locationName($pdo, $row['location_uuid']);
            }
            $row['location_name'] = $resolved ?: (!empty($row['Location']) ? $row['Location'] : null);

            $uText = $server && !empty($server['start_u'])
                ? self::uText($server['start_u'], $server['u_height'])
                : (!empty($row['RackPosition']) ? $row['RackPosition'] : null);

            // Loose stock has a shelf instead of a U. Both are "the last part of
            // the address", so they occupy the same slot in the line.
            $tail = $uText !== null
                ? $uText
                : (!empty($row['StoreLocation']) ? $row['StoreLocation'] : null);

            $row['address_text'] = self::formatAddress([
                'location_name' => $row['location_name'],
                'floor'         => $row['floor'],
                'rack_name'     => $row['rack_name'],
                'u_text'        => $tail,
            ]);
        }
        unset($row);
    }

    /* ============================================================
     * Propagating
     * ============================================================ */

    /**
     * Re-stamp a config and every component installed in it with the address
     * derived from its current placement. THIS is what makes a move carry its
     * contents.
     *
     * Runs in the caller's transaction on purpose: a move that relocated the
     * server but only half its components would be worse than one that failed.
     *
     * @return int number of inventory rows updated.
     */
    public static function syncConfig($pdo, $configUuid)
    {
        $addr = self::resolveForConfig($pdo, $configUuid);
        if ($addr === null) {
            return 0;
        }

        self::syncConfigRow($pdo, $configUuid, $addr);
        return self::syncComponentRows($pdo, $configUuid, $addr);
    }

    /**
     * The server_configurations row itself.
     *
     * Only written when the server is RACKED. An unracked server's location is
     * authored (set through server-update-location) rather than derived, and
     * overwriting it here would erase it the moment the server left a rack.
     * rack_position is left to RackPlacement::syncPositionText(), which owns it.
     */
    private static function syncConfigRow($pdo, $configUuid, array $addr)
    {
        if (!$addr['is_racked'] || empty($addr['location_uuid'])) {
            return;
        }

        $fields = [];
        $values = [];

        if (SchemaHelper::hasColumn($pdo, 'server_configurations', 'location_uuid')) {
            $fields[] = 'location_uuid = ?';
            $values[] = $addr['location_uuid'];
        }
        // Legacy text mirror. Written only when we have a name to write, never
        // blanked -- see the class docblock.
        if (!empty($addr['location_name'])) {
            $fields[] = 'location = ?';
            $values[] = $addr['location_name'];
        }

        if (empty($fields)) {
            return;
        }

        $values[] = $configUuid;
        try {
            $stmt = $pdo->prepare("UPDATE server_configurations SET " . implode(', ', $fields)
                . " WHERE config_uuid = ?");
            $stmt->execute($values);
        } catch (Throwable $e) {
            error_log("LocationResolver::syncConfigRow error: " . $e->getMessage());
        }
    }

    /**
     * Every inventory row bound to this config, across all 12 tables.
     *
     * RackPosition is the one field that IS cleared when unresolved: a server
     * out of its rack has no U position, and leaving a stale one would be a
     * false statement rather than a missing one. It matches what the release
     * path in ServerBuilder already does.
     *
     * @return int rows updated.
     */
    private static function syncComponentRows($pdo, $configUuid, array $addr)
    {
        $touched = 0;

        foreach (self::COMPONENT_TYPES as $type) {
            $table = $type . 'inventory';

            if (!SchemaHelper::hasTable($pdo, $table)) {
                continue;
            }

            $fields = ['RackPosition = ?'];
            $values = [$addr['u_text']];   // null when not racked -- deliberate

            if (SchemaHelper::hasColumn($pdo, $table, 'location_uuid') && !empty($addr['location_uuid'])) {
                $fields[] = 'location_uuid = ?';
                $values[] = $addr['location_uuid'];
            }
            if (!empty($addr['location_name'])) {
                $fields[] = 'Location = ?';
                $values[] = $addr['location_name'];
            }

            $values[] = $configUuid;

            try {
                $stmt = $pdo->prepare("UPDATE `{$table}` SET " . implode(', ', $fields)
                    . ", UpdatedAt = NOW() WHERE ServerUUID = ?");
                $stmt->execute($values);
                $touched += $stmt->rowCount();
            } catch (Throwable $e) {
                // One unmigrated table must not abandon the other eleven
                // mid-move. Logged loudly; the move still completes.
                error_log("LocationResolver::syncComponentRows error on {$table}: " . $e->getMessage());
            }
        }

        return $touched;
    }

    /**
     * Re-stamp every server in a rack, and their components.
     *
     * Called when a rack's own location or floor changes: the racks did not
     * move, but the answer to "where are they" did, and 200 components must not
     * be left describing the old site.
     *
     * @return array{configs:int, components:int}
     */
    public static function syncRack($pdo, $rackUuid)
    {
        $configs = 0;
        $components = 0;

        try {
            $stmt = $pdo->prepare("SELECT config_uuid FROM rack_servers WHERE rack_uuid = ?");
            $stmt->execute([$rackUuid]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $configUuid) {
                $components += self::syncConfig($pdo, $configUuid);
                $configs++;
            }
        } catch (Throwable $e) {
            error_log("LocationResolver::syncRack error: " . $e->getMessage());
        }

        return ['configs' => $configs, 'components' => $components];
    }

    /**
     * How many inventory rows are installed in this config right now.
     *
     * Used to tell the mover "14 components will move with this server" BEFORE
     * they commit to it, and to sanity-check what syncConfig reported after.
     */
    public static function countComponents($pdo, $configUuid)
    {
        $total = 0;

        foreach (self::COMPONENT_TYPES as $type) {
            $table = $type . 'inventory';
            if (!SchemaHelper::hasTable($pdo, $table)) {
                continue;
            }
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE ServerUUID = ?");
                $stmt->execute([$configUuid]);
                $total += (int)$stmt->fetchColumn();
            } catch (Throwable $e) {
                error_log("LocationResolver::countComponents error on {$table}: " . $e->getMessage());
            }
        }

        return $total;
    }
}
