<?php
/**
 * ComponentRelocation — moving one part, on its own, between sites.
 * File: core/models/location/ComponentRelocation.php
 *
 * THE OTHER HALF OF ServerRelocation.
 *   ServerRelocation moves a machine and carries its contents. This moves a
 *   single loose unit that has no machine — the SSD sitting on a shelf in Noida
 *   that has to reach a server racked in Jaipur before it can be fitted. Until
 *   this existed there was no route for that at all: an admin edited the
 *   Location text by hand and the fact that a named person carried the part
 *   across the country was lost entirely.
 *
 * ONLY LOOSE STOCK. Fail-closed, and this is the load-bearing rule.
 *   An INSTALLED component's address is DERIVED from the server it is in
 *   (LocationResolver::resolveForConfig -> syncConfig). Writing a different
 *   location onto such a row would not move anything: the next syncConfig would
 *   overwrite it, and in the meantime the record would claim a drive is in
 *   Jaipur while the server holding it is demonstrably in Noida. If an installed
 *   part needs to be somewhere else, it comes out of the server first, or the
 *   server moves — both of which already have doors.
 *
 * WHY IT RETURNS A RESULT INSTEAD OF SENDING JSON
 *   Identical reasoning to ServerRelocation: send_json_response() exits, and
 *   RequestActionExecutor has to see a failure, roll its own transaction back
 *   and record the reason on the request.
 *
 * TRANSACTIONS
 *   Joins the caller's if one is open. The Request path wraps every action of an
 *   approval in one transaction, and a nested BEGIN would throw.
 *
 * DEPLOY ORDER
 *   Every column and table touched is probed through SchemaHelper first. Before
 *   2026_08_26_003 there is no location_uuid to write and before 2026_08_26_006
 *   there is no component_movements to record into; in that window a move writes
 *   only what exists and records nothing, rather than throwing.
 *
 * Used by core/models/pipelines/RequestActionExecutor.php
 * (`inventory.component.relocate`).
 */

require_once __DIR__ . '/LocationResolver.php';
require_once __DIR__ . '/../../helpers/SchemaHelper.php';

class ComponentRelocation
{
    /**
     * Move one physical unit to another location.
     *
     * @param PDO    $pdo
     * @param string $componentType one of LocationResolver::COMPONENT_TYPES
     * @param int    $inventoryId   the PHYSICAL UNIT — {type}inventory.ID, not a
     *                              model UUID. Two units of the same model can be
     *                              at two different sites; a UUID cannot say which
     *                              one is being carried.
     * @param array  $target ['location_uuid' => string,
     *                        'store_location' => ?string  shelf / bin at the destination]
     * @param array  $ctx    ['user_id' => ?int, 'reason' => ?string,
     *                        'ticket_id' => ?int, 'handover_user_id' => ?int]
     * @return array{success:bool, code:int, message:string, data:array}
     */
    public static function move($pdo, $componentType, $inventoryId, array $target, array $ctx = [])
    {
        $componentType = strtolower(trim((string)$componentType));
        $inventoryId   = (int)$inventoryId;

        $locationUuid = !empty($target['location_uuid']) ? $target['location_uuid'] : null;
        $storeGiven   = array_key_exists('store_location', $target);
        $storeLoc     = ($storeGiven && $target['store_location'] !== null && $target['store_location'] !== '')
            ? substr(trim((string)$target['store_location']), 0, 100)
            : null;

        $userId    = isset($ctx['user_id']) ? $ctx['user_id'] : null;
        $reason    = (isset($ctx['reason']) && $ctx['reason'] !== '')
            ? substr(trim((string)$ctx['reason']), 0, 255) : null;
        $ticketId  = (isset($ctx['ticket_id']) && $ctx['ticket_id']) ? (int)$ctx['ticket_id'] : null;
        $handover  = (isset($ctx['handover_user_id']) && $ctx['handover_user_id'])
            ? (int)$ctx['handover_user_id'] : null;

        if (!in_array($componentType, LocationResolver::COMPONENT_TYPES, true)) {
            return self::fail(400, 'Unknown component type');
        }
        if ($inventoryId <= 0) {
            return self::fail(400, 'inventory_id is required and must identify one physical unit');
        }
        if ($locationUuid === null) {
            return self::fail(400, 'A destination location is required');
        }

        $table = $componentType . 'inventory';
        if (!SchemaHelper::hasTable($pdo, $table)) {
            return self::fail(400, 'Unknown component type');
        }
        if (!SchemaHelper::hasColumn($pdo, $table, 'location_uuid')) {
            // Pre-seeder. There is nowhere to write the destination, so pretending
            // to have moved it would be a lie that survives the deploy window.
            return self::fail(503, 'Component locations are not available on this system yet');
        }
        if (!self::locationExists($pdo, $locationUuid)) {
            return self::fail(404, 'Location not found, or it has been retired');
        }

        // ---- The unit, locked, as it stands now -----------------------------
        $hasAssetTag = SchemaHelper::hasColumn($pdo, $table, 'AssetTag');
        $hasStore    = SchemaHelper::hasColumn($pdo, $table, 'StoreLocation');

        $select = ['ID', 'UUID', 'SerialNumber', 'Status', 'ServerUUID', 'Location', 'location_uuid'];
        if ($hasAssetTag) { $select[] = 'AssetTag'; }
        if ($hasStore)    { $select[] = 'StoreLocation'; }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // FOR UPDATE: between reading "loose stock" and writing the new
            // location, an approval on another request must not install it.
            $stmt = $pdo->prepare("SELECT " . implode(', ', $select) . " FROM `{$table}`
                                    WHERE ID = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$inventoryId]);
            $unit = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$unit) {
                return self::abort($pdo, $ownsTransaction, 404, 'That component unit no longer exists');
            }

            // THE RULE. See the class header.
            if ((int)$unit['Status'] !== 1 || !empty($unit['ServerUUID'])) {
                $why = !empty($unit['ServerUUID'])
                    ? 'it is installed in a server — move the server, or remove the part first'
                    : 'it is not available stock (it may be marked failed)';
                return self::abort($pdo, $ownsTransaction, 409,
                    'Only loose stock can be handed over: ' . $why);
            }

            $fromUuid  = !empty($unit['location_uuid']) ? $unit['location_uuid'] : null;
            $fromName  = LocationResolver::locationName($pdo, $fromUuid);
            $fromStore = $hasStore && !empty($unit['StoreLocation']) ? $unit['StoreLocation'] : null;

            // A no-op move must not write a movement row that records no
            // movement. Changing only the shelf within a site IS a move worth
            // recording, so that counts.
            $sameLocation = ($fromUuid === $locationUuid);
            $sameShelf    = !$storeGiven || ($fromStore === $storeLoc);
            if ($sameLocation && $sameShelf) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => true,
                    'code'    => 200,
                    'message' => 'That component is already there — nothing was changed',
                    'data'    => ['moved' => false, 'inventory_id' => $inventoryId],
                ];
            }

            $toName = LocationResolver::locationName($pdo, $locationUuid);

            // ---- Write -------------------------------------------------------
            $fields = ['location_uuid = ?'];
            $values = [$locationUuid];

            // Legacy text mirror, written only when there is a name to write and
            // never blanked — the policy stated in LocationResolver's docblock.
            if ($toName !== null && $toName !== '') {
                $fields[] = 'Location = ?';
                $values[] = $toName;
            }
            // The shelf is only rewritten when the caller said something about
            // it. Silently clearing "Shelf B3" because a form omitted the field
            // would lose the only note saying where to physically pick it up.
            if ($hasStore && $storeGiven) {
                $fields[] = 'StoreLocation = ?';
                $values[] = $storeLoc;
            }
            // Loose stock has no U position. If a stale one survived from a
            // previous install, it is a false statement about the new site.
            $fields[] = 'RackPosition = NULL';

            $values[] = $inventoryId;

            $upd = $pdo->prepare("UPDATE `{$table}` SET " . implode(', ', $fields)
                . ", UpdatedAt = NOW() WHERE ID = ?");
            $upd->execute($values);

            self::recordMovement($pdo, $componentType, $unit, [
                'from_location_uuid'  => $fromUuid,
                'from_location_name'  => $fromName,
                'from_store_location' => $fromStore,
                'to_location_uuid'    => $locationUuid,
                'to_location_name'    => $toName,
                'to_store_location'   => $hasStore ? ($storeGiven ? $storeLoc : $fromStore) : null,
                'reason'              => $reason,
                'ticket_id'           => $ticketId,
                'handover_user_id'    => $handover,
                'moved_by'            => $userId,
            ]);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("ComponentRelocation::move error on {$table}: " . $e->getMessage());
            return self::fail(500, 'The handover could not be recorded and was rolled back');
        }

        // Activity log outside the transaction: a logging failure must not undo
        // a completed move.
        self::logMove($pdo, $userId, $componentType, $unit, $fromName, $toName);

        $label = self::unitLabel($componentType, $unit);
        $msg   = $toName !== null
            ? "{$label} moved to {$toName}"
            : "{$label} moved";
        if ($fromName !== null && $fromName !== $toName) {
            $msg = "{$label} moved from {$fromName} to " . ($toName ?: 'its new location');
        }

        return [
            'success' => true,
            'code'    => 200,
            'message' => $msg,
            'data'    => [
                'moved'          => true,
                'component_type' => $componentType,
                'inventory_id'   => $inventoryId,
                'serial_number'  => isset($unit['SerialNumber']) ? $unit['SerialNumber'] : null,
                'from'           => ['location_uuid' => $fromUuid,      'location_name' => $fromName,  'store_location' => $fromStore],
                'to'             => ['location_uuid' => $locationUuid,  'location_name' => $toName,    'store_location' => $storeGiven ? $storeLoc : $fromStore],
            ],
        ];
    }

    /**
     * Movement history for one unit, newest first.
     */
    public static function history($pdo, $componentType, $inventoryId, $limit = 50)
    {
        if (!SchemaHelper::hasTable($pdo, 'component_movements')) {
            return [];
        }
        try {
            $limit = max(1, min(200, (int)$limit));
            $stmt = $pdo->prepare("SELECT m.*,
                                          u.username AS moved_by_username,
                                          h.username AS handover_username
                                     FROM component_movements m
                                     LEFT JOIN users u ON u.id = m.moved_by
                                     LEFT JOIN users h ON h.id = m.handover_user_id
                                    WHERE m.component_type = ? AND m.inventory_id = ?
                                    ORDER BY m.moved_at DESC, m.id DESC
                                    LIMIT {$limit}");
            $stmt->execute([strtolower(trim((string)$componentType)), (int)$inventoryId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("ComponentRelocation::history error: " . $e->getMessage());
            return [];
        }
    }

    /* ============================================================
     * Internals
     * ============================================================ */

    /**
     * Does this location exist and is it still in service? Retired sites are
     * refused as destinations — you can read history that names them, but you
     * cannot hand new hardware into one. Mirrors ServerRelocation::locationExists().
     */
    private static function locationExists($pdo, $locationUuid)
    {
        if (!SchemaHelper::hasTable($pdo, 'locations')) {
            return false;   // fail closed
        }
        try {
            $stmt = $pdo->prepare("SELECT is_active FROM locations WHERE location_uuid = ? LIMIT 1");
            $stmt->execute([$locationUuid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false && (int)$row['is_active'] === 1;
        } catch (Throwable $e) {
            error_log("ComponentRelocation::locationExists error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * The movement row. Names are snapshotted alongside the uuids so the record
     * still reads correctly after a rename or a location delete — see the header
     * of seeder 2026_08_26_006.
     *
     * Guarded by a table probe: the move itself is the important part, and until
     * the seeder is run by hand it simply records nothing.
     */
    private static function recordMovement($pdo, $componentType, array $unit, array $m)
    {
        if (!SchemaHelper::hasTable($pdo, 'component_movements')) {
            return;
        }

        $stmt = $pdo->prepare("INSERT INTO component_movements
            (component_type, inventory_id, component_uuid, component_name, serial_number, asset_tag,
             from_location_uuid, from_location_name, from_store_location,
             to_location_uuid,   to_location_name,   to_store_location,
             reason, ticket_id, handover_user_id, moved_by, moved_at)
            VALUES (?,?,?,?,?,?, ?,?,?, ?,?,?, ?,?,?,?, NOW())");

        $stmt->execute([
            $componentType,
            (int)$unit['ID'],
            isset($unit['UUID']) ? $unit['UUID'] : null,
            self::unitLabel($componentType, $unit),
            isset($unit['SerialNumber']) ? $unit['SerialNumber'] : null,
            isset($unit['AssetTag']) ? $unit['AssetTag'] : null,
            $m['from_location_uuid'], $m['from_location_name'], $m['from_store_location'],
            $m['to_location_uuid'],   $m['to_location_name'],   $m['to_store_location'],
            $m['reason'], $m['ticket_id'], $m['handover_user_id'], $m['moved_by'],
        ]);
    }

    /**
     * A short human name for the unit. The model's marketing name lives in
     * ims-data, not the DB, and loading a spec file to build a log line is not
     * worth the round trip — the type plus the serial identifies the physical
     * object unambiguously, which is what history needs.
     */
    private static function unitLabel($componentType, array $unit)
    {
        $label = strtoupper($componentType);
        if (!empty($unit['SerialNumber'])) {
            $label .= ' SN ' . $unit['SerialNumber'];
        } elseif (!empty($unit['AssetTag'])) {
            $label .= ' ' . $unit['AssetTag'];
        } else {
            $label .= ' #' . (int)$unit['ID'];
        }
        return $label;
    }

    private static function logMove($pdo, $userId, $componentType, array $unit, $fromName, $toName)
    {
        try {
            $label = self::unitLabel($componentType, $unit);
            logActivity($pdo, $userId, 'Component relocated', 'location', null,
                "{$label}: " . ($fromName ?: 'unknown') . ' -> ' . ($toName ?: 'unknown'));
        } catch (Throwable $e) {
            error_log("ComponentRelocation::logMove error: " . $e->getMessage());
        }
    }

    /**
     * Roll back (when we own the transaction) and report. Used for the refusals
     * discovered after the row is locked, which is where the important ones are.
     */
    private static function abort($pdo, $ownsTransaction, $code, $message)
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return self::fail($code, $message);
    }

    private static function fail($code, $message)
    {
        return ['success' => false, 'code' => $code, 'message' => $message, 'data' => []];
    }
}
