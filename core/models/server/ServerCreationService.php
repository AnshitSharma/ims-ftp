<?php
/**
 * ServerCreationService — the one way a server configuration comes into being.
 * File: core/models/server/ServerCreationService.php
 *
 * Three routes used to create a server, and each had its own idea of what a
 * server needed to have: the direct Create Server form, import-virtual, and an
 * approved `server.config.create` request. They disagreed about the serial
 * number, about the location, and about placement, which is how a physical
 * machine could be recorded with no site, no rack and no serial and still count
 * as created. This class is the single answer for all three.
 *
 * A PHYSICAL BUILD MUST BE PLACED. (F-01, decided 2026-09-13.)
 *   Site, rack and U are not optional extras on a real machine — a server the
 *   system cannot point at is not inventory, it is a rumour. Creation refuses
 *   without them. The escape hatches that made this look optional (the
 *   "-- Not racked --" choice, the required-marked fields the backend ignored)
 *   are gone from the form as well: an option the backend refuses is a trap,
 *   not a choice.
 *
 *   A VIRTUAL build is exempt in full. It has no box, no site and no serial to
 *   read, and demanding any of them would make a template impossible to create.
 *   A sandbox/compatibility-bench build is virtual by construction.
 *
 * IT ALL HAPPENS IN ONE TRANSACTION. (F-02.)
 *   Creation, location and placement commit together or not at all. Before
 *   this, the browser created the row, then placed it, then set its location in
 *   three separate calls, and a failure at step two left a real server row
 *   stranded with no place — reported to the operator as "Server created, but
 *   not placed in the rack" and left for someone to clean up by hand. There is
 *   no compensating delete here because there is nothing to compensate: a
 *   refused placement rolls the creation back with it.
 *
 *   The destination rack is locked FOR UPDATE before its occupancy is read, so
 *   two operators creating servers into the same U cannot both be told it is
 *   free.
 *
 * RETRY IDENTITY IS THE SERIAL NUMBER.
 *   A double-submitted create is refused by the serial's UNIQUE index rather
 *   than producing two servers — which is the other half of why the serial is
 *   mandatory on a physical build. Virtual builds carry no serial and need no
 *   such protection: nothing they touch is real.
 *
 * PLACEMENT GOES THROUGH ServerRelocation::move().
 *   Bounds, overlap, enclosure bays, the location cross-check, rack_position
 *   text, component propagation and the movement row are all its job already,
 *   and a second implementation here is exactly the divergence that let an
 *   approved request put a server somewhere the button would have refused.
 *   move() joins an open transaction rather than opening its own, so it
 *   composes inside this one.
 */

require_once __DIR__ . '/ServerConfiguration.php';
require_once __DIR__ . '/../rack/RackPlacement.php';
require_once __DIR__ . '/../rack/RackEnclosure.php';
require_once __DIR__ . '/../rack/ServerRelocation.php';
require_once __DIR__ . '/../location/LocationResolver.php';
require_once __DIR__ . '/../../helpers/SchemaHelper.php';

class ServerCreationService
{
    /**
     * Create a server configuration, and for a physical build place it too.
     *
     * @param array $input  server_name, description, is_virtual, is_sandbox,
     *                      serial_number, location_uuid, and a destination:
     *                      rack_uuid + start_u, or enclosure_uuid + slot_index.
     * @param array $ctx    ['user_id' => int, 'ticket_id' => ?int, 'reason' => ?string]
     *
     * @return array{success:bool, code:int, message:string, data:?array}
     *         Same shape ServerRelocation::move() returns, so a caller can
     *         forward a refusal verbatim without re-wording it.
     */
    public static function create($pdo, ServerBuilder $builder, array $input, array $ctx = [])
    {
        $serverName = trim((string)($input['server_name'] ?? ''));
        if ($serverName === '') {
            return self::fail(400, 'Server name is required');
        }

        // A sandbox build is virtual by construction — createConfiguration()
        // forces it too, so no path can create a bench build that reserves
        // real stock.
        $isSandbox = !empty($input['is_sandbox']) ? 1 : 0;
        $isVirtual = ($isSandbox || !empty($input['is_virtual'])) ? 1 : 0;

        $userId = isset($ctx['user_id']) ? $ctx['user_id'] : null;

        // ---- Virtual: no box, no site, no serial, no place ------------------
        if ($isVirtual) {
            try {
                $configUuid = $builder->createConfiguration($serverName, $userId, [
                    'description' => (string)($input['description'] ?? ''),
                    'location'    => '',
                    'is_virtual'  => 1,
                    'is_sandbox'  => $isSandbox,
                ]);
            } catch (Throwable $e) {
                error_log('ServerCreationService: virtual create failed: ' . $e->getMessage());
                return self::fail(500, 'Failed to create the server configuration');
            }

            return [
                'success' => true,
                'code'    => 200,
                'message' => 'Server configuration created successfully',
                'data'    => [
                    'config_uuid'   => $configUuid,
                    'server_name'   => $serverName,
                    'is_virtual'    => 1,
                    'is_sandbox'    => $isSandbox,
                    'serial_number' => null,
                    'address'       => null,
                    'placed'        => false,
                ],
            ];
        }

        // ---- Physical: identity, site and destination are all required ------
        $serial = self::checkSerial($pdo, $input);
        if (isset($serial['error'])) {
            return $serial['error'];
        }

        $locationUuid = trim((string)($input['location_uuid'] ?? ''));
        $locationCheck = self::checkLocation($pdo, $locationUuid);
        if ($locationCheck !== null) {
            return $locationCheck;
        }

        $destination = self::resolveDestination($pdo, $input);
        if (isset($destination['error'])) {
            return $destination['error'];
        }

        // ---- One transaction ------------------------------------------------
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // Lock the destination rack BEFORE move() reads its occupancy, so a
            // concurrent create cannot be told the same U is free. The lock is
            // held to commit.
            $lock = $pdo->prepare("SELECT rack_uuid FROM racks WHERE rack_uuid = ? FOR UPDATE");
            $lock->execute([$destination['rack_uuid']]);
            if (!$lock->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('destination rack disappeared');
            }

            $configUuid = $builder->createConfiguration($serverName, $userId, [
                'description'   => (string)($input['description'] ?? ''),
                'location'      => '',   // written from the rack by move()
                'is_virtual'    => 0,
                'is_sandbox'    => 0,
                'serial_number' => $serial['value'],
            ]);

            if (!is_string($configUuid) || $configUuid === '') {
                throw new RuntimeException('createConfiguration returned no uuid');
            }

            // The site is recorded up front so that a destination which somehow
            // disagrees with it is caught by move()'s own cross-check rather
            // than silently resolved. move() then rewrites it from the rack.
            self::writeLocation($pdo, $configUuid, $locationUuid);

            $move = ServerRelocation::move($pdo, $configUuid, [
                'rack_uuid'      => $destination['rack_uuid'],
                'start_u'        => $destination['start_u'],
                'enclosure_uuid' => $destination['enclosure_uuid'],
                'slot_index'     => $destination['slot_index'],
                'location_uuid'  => $locationUuid !== '' ? $locationUuid : null,
                // No chassis exists yet, so this is a pre-build RESERVATION and
                // nothing more (F-11). syncHeightFromChassis() replaces it when
                // the chassis is installed.
                'u_height'       => $destination['u_height'],
            ], [
                'user_id'   => $userId,
                'reason'    => isset($ctx['reason']) ? $ctx['reason'] : 'Server created',
                'ticket_id' => isset($ctx['ticket_id']) ? $ctx['ticket_id'] : null,
            ]);

            if (!$move['success']) {
                // The whole creation goes back. This is the point of the class:
                // there is no half-created server to apologise for.
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return self::fail($move['code'], $move['message']);
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('ServerCreationService::create failed: ' . $e->getMessage());

            // A duplicate serial that beat the pre-check to the index. The
            // UNIQUE constraint is the real guard; this turns it into a
            // sentence an operator can act on.
            if (self::isDuplicateSerial($e)) {
                return self::fail(409,
                    "Serial number '{$serial['value']}' is already recorded against another server.");
            }
            return self::fail(500, 'Failed to create the server configuration');
        }

        // resolveForConfig() can return null (the config vanished under us, or
        // the location seeders have not been run). formatAddress() takes an
        // array, so the address is only rendered when there is one.
        $address     = LocationResolver::resolveForConfig($pdo, $configUuid);
        $addressText = is_array($address) ? LocationResolver::formatAddress($address) : null;

        return [
            'success' => true,
            'code'    => 200,
            'message' => $addressText !== null
                ? 'Server created and placed at ' . $addressText
                : 'Server created and placed',
            'data'    => [
                'config_uuid'   => $configUuid,
                'server_name'   => $serverName,
                'is_virtual'    => 0,
                'is_sandbox'    => 0,
                'serial_number' => $serial['value'],
                'address'       => $address,
                'address_text'  => $addressText,
                'placed'        => true,
            ],
        ];
    }

    /**
     * The manufacturer serial, required on a physical build.
     *
     * Skipped entirely until seeder 2026_09_03_002 lands: code reaches
     * production ~20s after save and the seeder is applied by hand afterwards,
     * so refusing every creation for that window — over a value that could not
     * be stored anyway — would be a self-inflicted outage.
     *
     * @return array{value:?string}|array{error:array}
     */
    private static function checkSerial($pdo, array $input)
    {
        $serial = trim((string)($input['serial_number'] ?? ''));

        if (!ServerConfiguration::serialColumnExists($pdo)) {
            return ['value' => $serial !== '' ? $serial : null];
        }

        if ($serial === '') {
            return ['error' => self::fail(400,
                'A serial number is required — enter the serial printed on the physical server.')];
        }

        $invalid = ServerConfiguration::validateSerial($serial);
        if ($invalid !== null) {
            return ['error' => self::fail(400, $invalid)];
        }

        // Reported before the INSERT so the operator is told which server
        // already holds it, rather than getting a bare duplicate-key error.
        $existing = ServerConfiguration::findBySerial($pdo, $serial);
        if ($existing) {
            return ['error' => self::fail(409, "Serial number '{$serial}' is already recorded against the server '"
                . ($existing['server_name'] ?: 'Unnamed Server') . "'.")];
        }

        return ['value' => $serial];
    }

    /**
     * The site, required on a physical build and resolved server-side.
     *
     * The create form used to send a free-text location NAME and write the
     * uuid, if at all, in a follow-up call whose failure was a console warning
     * (F-03). A name is not an address: two sites can share one, and a typo
     * produced a server filed nowhere.
     *
     * @return array|null null when the location is usable; a refusal otherwise.
     */
    private static function checkLocation($pdo, $locationUuid)
    {
        if ($locationUuid === '') {
            return self::fail(400, 'A location is required — choose the site this server stands at.');
        }

        if (!SchemaHelper::hasTable($pdo, 'locations')) {
            return self::fail(503,
                'Locations are not available yet — the database migration for this feature has not been applied.');
        }

        $stmt = $pdo->prepare("SELECT name, is_active FROM locations WHERE location_uuid = ? LIMIT 1");
        $stmt->execute([$locationUuid]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$location) {
            return self::fail(404, 'Location not found');
        }
        if ((int)$location['is_active'] !== 1) {
            return self::fail(400, "\"{$location['name']}\" has been retired and cannot be used as a location");
        }

        return null;
    }

    /**
     * Where the server goes. Required, and one of exactly two shapes.
     *
     * A bay wins when both are sent: the enclosure owns a rack and a U range,
     * so a rack_uuid alongside it describes nothing the placement will use.
     *
     * @return array{rack_uuid:string, start_u:?int, enclosure_uuid:?string,
     *               slot_index:?int, u_height:?int}|array{error:array}
     */
    private static function resolveDestination($pdo, array $input)
    {
        $enclosureUuid = trim((string)($input['enclosure_uuid'] ?? ''));
        $slotIndex     = isset($input['slot_index']) && $input['slot_index'] !== ''
            ? (int)$input['slot_index'] : null;
        $rackUuid      = trim((string)($input['rack_uuid'] ?? ''));
        $startU        = isset($input['start_u']) && $input['start_u'] !== ''
            ? (int)$input['start_u'] : null;

        if ($enclosureUuid !== '') {
            if ($slotIndex === null || $slotIndex < 1) {
                return ['error' => self::fail(400,
                    'Choose which bay of the enclosure this server goes into.')];
            }
            $enclosure = RackEnclosure::get($pdo, $enclosureUuid);
            if (!$enclosure) {
                return ['error' => self::fail(404, 'Enclosure not found')];
            }
            return [
                'rack_uuid'      => $enclosure['rack_uuid'],
                'start_u'        => null,
                'enclosure_uuid' => $enclosureUuid,
                'slot_index'     => $slotIndex,
                'u_height'       => null,   // the enclosure's bay decides it
            ];
        }

        if ($rackUuid === '') {
            return ['error' => self::fail(400,
                'A rack is required — a physical server has to be installed somewhere.')];
        }
        if ($startU === null || $startU < 1) {
            return ['error' => self::fail(400,
                'A position is required — choose the U this server starts at.')];
        }

        $uHeight = isset($input['u_height']) && $input['u_height'] !== ''
            ? max(1, (int)$input['u_height']) : null;

        return [
            'rack_uuid'      => $rackUuid,
            'start_u'        => $startU,
            'enclosure_uuid' => null,
            'slot_index'     => null,
            'u_height'       => $uHeight,
        ];
    }

    /**
     * Record the site on the new row before it is placed.
     *
     * Both columns, because `location` (free text) is what older readers and
     * the servers list still show, and `location_uuid` is the real link.
     * Guarded on the column because it arrives with a hand-run seeder.
     */
    private static function writeLocation($pdo, $configUuid, $locationUuid)
    {
        $fields = ['location = ?'];
        $values = [LocationResolver::locationName($pdo, $locationUuid)];

        if (SchemaHelper::hasColumn($pdo, 'server_configurations', 'location_uuid')) {
            $fields[] = 'location_uuid = ?';
            $values[] = $locationUuid;
        }

        $values[] = $configUuid;
        $stmt = $pdo->prepare("UPDATE server_configurations SET " . implode(', ', $fields)
            . " WHERE config_uuid = ?");
        $stmt->execute($values);
    }

    /** Did this failure come from the serial's UNIQUE index? */
    private static function isDuplicateSerial(Throwable $e)
    {
        $message = $e->getMessage();
        return stripos($message, 'duplicate') !== false && stripos($message, 'serial') !== false;
    }

    /** A refusal, in ServerRelocation::move()'s shape. */
    private static function fail($code, $message)
    {
        return ['success' => false, 'code' => $code, 'message' => $message, 'data' => null];
    }
}
