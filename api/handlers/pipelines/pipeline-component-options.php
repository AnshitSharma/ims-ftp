<?php
/**
 * pipeline-component-options.php
 * Action: pipeline-component-options
 * Permission: pipeline.create | pipeline.manage  (identical to pipeline-create)
 *
 * "Which models can I actually name here?"
 *
 * WHY THIS EXISTS
 * ---------------
 * The create-request form's model dropdowns were filled from the ims-data spec
 * CATALOGUE -- every model that has ever been described, whether or not we own
 * one. That is the right vocabulary for exactly one action (recording a brand
 * new inventory record) and wrong for every other, in two opposite directions:
 *
 *   "Model to put in"   offered hardware we do not stock, so the request was
 *                       raised, queued, reviewed and only then refused.
 *   "Model to take out" offered every model in existence rather than the parts
 *                       actually installed in the server being changed -- a
 *                       question the form could not answer, because it never
 *                       asked which server first.
 *
 * So this endpoint answers the two questions a request form actually has, and
 * the pair of them is why it is ONE endpoint: same gate, same field names, same
 * facts about the same inventory, differing only in which slice is wanted.
 *
 * WHY THE GATE IS pipeline.create AND NOT server.view / {type}.view
 * ----------------------------------------------------------------
 * The same bargain pipeline-servers.php, pipeline-component-location.php and
 * pipeline-inventory-record.php each document: if you may raise a request naming
 * a component, you may be told about the component you just named. The typical
 * requester holds no inventory or server permissions at all -- that is the whole
 * reason they are raising a request rather than doing the work -- so gating on
 * server.view would make these dropdowns empty for exactly the people who need
 * them. server-get-config, server-get-compatible and
 * server-get-available-components are all gated that way, which is why none of
 * them could be reused here.
 *
 * WHY 'installed' READS ConfigReadRouter AND NOT server-get-config
 * ---------------------------------------------------------------
 * Two reasons, and both matter.
 *
 * 1. ConfigReadRouter::components() is the SAME authority that
 *    ServerBuilder::getCompatibleComponents() reads and that
 *    TargetStateBuilder::fromCurrent() -- hence ReplaceComponentCommand --
 *    resolves against. A picker built on any other source could offer a unit
 *    that buildTarget() then fails to find, which is a UI that lies. Sharing the
 *    authority makes that structurally impossible rather than merely unlikely.
 * 2. getConfigurationDetails() DROPS inventory_id when it builds its
 *    per-component display shape, and inventory_id is the only identifier that
 *    separates two units of one model when both serial numbers are NULL.
 *
 * ONE OPTION PER ENTRY, NOT PER UNIT
 * ----------------------------------
 * The legacy JSON side may record several identical parts as a single entry with
 * quantity > 1, carrying no per-unit serial. Expanding that into N rows would
 * fabricate identities the source does not have, so an entry stays one option
 * and reports its own quantity. The rows side is always one-per-unit, so on a
 * backfilled config the two coincide.
 *
 * WHAT IT DELIBERATELY DOES NOT RETURN
 * ------------------------------------
 * Identity and availability, for ONE component type the caller already named.
 * No specifications, no purchase data, no vendor, no notes, no compatibility
 * verdicts, no cross-type enumeration. Anyone wanting that still needs
 * {type}.view or server.view and the real endpoints.
 *
 * Params:
 * - component_type (required) cpu | ram | storage | ...
 * - source         (required) 'stock'     -> models with free units to fit
 *                            'records'    -> models we hold ANY unit of, any status
 *                            'installed'  -> what is in this server right now
 * - config_uuid    (required for 'installed'; optional for 'stock', where it
 *                  annotates each model with how much of its stock is at the
 *                  server's own site)
 */

require_once(__DIR__ . '/../../../core/models/location/LocationResolver.php');
require_once(__DIR__ . '/../../../core/models/components/ComponentDataService.php');

try {
    if (!$acl->hasPermission($user_id, 'pipeline.create')
        && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: pipeline.create required", null);
        exit;
    }

    $componentType = strtolower(trim((string)($_POST['component_type'] ?? $_GET['component_type'] ?? '')));
    $source        = strtolower(trim((string)($_POST['source']         ?? $_GET['source']         ?? '')));
    $configUuid    = trim((string)($_POST['config_uuid']    ?? $_GET['config_uuid']    ?? ''));

    if ($componentType === '') {
        send_json_response(false, true, 400, "component_type is required", null);
        exit;
    }
    if (!in_array($componentType, LocationResolver::COMPONENT_TYPES, true)) {
        send_json_response(false, true, 400, "Unknown component type", null);
        exit;
    }
    if (!in_array($source, ['stock', 'records', 'installed'], true)) {
        send_json_response(false, true, 400, "source must be 'stock', 'records' or 'installed'", null);
        exit;
    }
    // A 400, not an empty list: "nothing is installed" and "you forgot to say
    // which server" must not look the same to the form.
    if ($source === 'installed' && $configUuid === '') {
        send_json_response(false, true, 400, "config_uuid is required when source is 'installed'", null);
        exit;
    }

    $specs = ComponentDataService::getInstance();

    /* ------------------------------------------------------------------
     * installed -- what is in this server right now
     * ------------------------------------------------------------------ */
    if ($source === 'installed') {
        require_once(__DIR__ . '/../../../core/models/server/ServerBuilder.php');
        require_once(__DIR__ . '/../../../core/models/config/ConfigReadRouter.php');

        $stmt = $pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        $configRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$configRow) {
            send_json_response(false, true, 404, "That server configuration no longer exists", null);
            exit;
        }

        // $minimalOutput = false: the minimal shape drops serial_number and
        // inventory_id, which are the whole point of asking.
        $all = ConfigReadRouter::components(new ServerBuilder($pdo), $pdo, $configRow, false);

        $units = [];
        foreach ($all as $c) {
            if (!isset($c['component_type']) || $c['component_type'] !== $componentType) {
                continue;
            }
            $uuid = isset($c['component_uuid']) ? (string)$c['component_uuid'] : '';
            if ($uuid === '') {
                continue;
            }

            // An onboard NIC is materialised by OnboardNICHandler under a
            // synthetic uuid that is in no spec file, so modelLabel() correctly
            // returns null for it. It STAYS IN THE LIST: gating governs what you
            // can add, never what you can see, and hiding installed hardware is
            // the mistake tasks/dynamic-component-affordances.md made and fixed.
            $isOnboard = ($componentType === 'nic' && strpos($uuid, 'onboard-') === 0);

            $units[] = [
                'component_uuid' => $uuid,
                'model_label'    => $specs->modelLabel($componentType, $uuid),
                'serial_number'  => (isset($c['serial_number']) && $c['serial_number'] !== '')
                    ? $c['serial_number'] : null,
                'inventory_id'   => isset($c['inventory_id']) ? (int)$c['inventory_id'] : null,
                'slot_position'  => (isset($c['slot_position']) && $c['slot_position'] !== '')
                    ? $c['slot_position'] : null,
                // 1 on the rows side always. Greater than 1 only where the legacy
                // JSON recorded several identical parts as one entry with no
                // per-unit serial -- the form says so rather than pretending to
                // know which one is meant.
                'quantity'       => isset($c['quantity']) ? (int)$c['quantity'] : 1,
                'is_onboard'     => $isOnboard,
            ];
        }

        send_json_response(true, true, 200, "Installed components loaded", [
            'component_type' => $componentType,
            'source'         => 'installed',
            'config_uuid'    => $configUuid,
            'units'          => $units,
        ]);
        exit;
    }

    /* ------------------------------------------------------------------
     * stock / records -- models we hold units of
     *
     * The only difference is the WHERE. 'stock' asks what can be fitted, so it
     * wants free units. 'records' asks what can be CORRECTED, so it wants every
     * unit whatever its state -- an in-use or failed row is precisely the one
     * with a wrong status or a missing warranty date, and filtering those out
     * would hide the work being requested. Same reasoning
     * pipeline-inventory-record.php documents for its own unit list.
     * ------------------------------------------------------------------ */
    $anyStatus = ($source === 'records');
    $table = $componentType . 'inventory';
    if (!SchemaHelper::hasTable($pdo, $table)) {
        // Zero, not a fault. A type whose table has not been seeded yet reports
        // an empty shelf; the form says "no free stock", which is accurate.
        send_json_response(true, true, 200, "No inventory available", [
            'component_type' => $componentType,
            'source'         => $source,
            'location_aware' => false,
            'models'         => [],
        ]);
        exit;
    }

    // Where the server is, so "in stock, but at the wrong site" is visible while
    // the requester is still choosing rather than only in the amber banner
    // afterwards. Null whenever the location column or the placement is unknown,
    // which is the normal state before the location seeders are run -- and then
    // the whole annotation is simply absent.
    // Only meaningful for 'stock': "is what I could fit at the right site?".
    // A correction is about a row, not about carrying anything anywhere.
    $preferredLocation = (!$anyStatus && $configUuid !== '')
        ? LocationResolver::preferredUnitLocation($pdo, $table, $configUuid)
        : null;

    // "Available" here means exactly what LocationResolver::unitsForModel() means
    // by it -- Status = 1 AND loose. Status alone is not enough: a unit sitting
    // in another build cannot be fitted into this one, and if the two definitions
    // could drift, this dropdown and the cross-site location banner beside it
    // would be able to disagree about the same shelf.
    //
    // Two spellings of one query rather than a concatenated fragment, so the
    // location-aware column only exists where preferredUnitLocation() has
    // already confirmed the column does.
    if ($preferredLocation !== null) {
        $sql = "SELECT UUID, COUNT(*) AS available_count,
                       SUM(CASE WHEN location_uuid = ? THEN 1 ELSE 0 END) AS here_count
                  FROM `{$table}`
                 WHERE Status = 1 AND (ServerUUID IS NULL OR ServerUUID = '')
                 GROUP BY UUID";
        $params = [$preferredLocation];
    } elseif ($anyStatus) {
        $sql = "SELECT UUID, COUNT(*) AS available_count
                  FROM `{$table}`
                 GROUP BY UUID";
        $params = [];
    } else {
        $sql = "SELECT UUID, COUNT(*) AS available_count
                  FROM `{$table}`
                 WHERE Status = 1 AND (ServerUUID IS NULL OR ServerUUID = '')
                 GROUP BY UUID";
        $params = [];
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("pipeline-component-options stock lookup failed on {$table}: " . $e->getMessage());
        send_json_response(false, true, 500, "Could not read stock for that component type", null);
        exit;
    }

    $models = [];
    foreach ($rows as $row) {
        $uuid = isset($row['UUID']) ? (string)$row['UUID'] : '';
        if ($uuid === '') {
            continue;
        }
        $entry = [
            'component_uuid'  => $uuid,
            'model_label'     => $specs->modelLabel($componentType, $uuid),
            'available_count' => (int)$row['available_count'],
        ];
        if ($preferredLocation !== null) {
            $entry['here_count'] = (int)$row['here_count'];
        }
        $models[] = $entry;
    }

    // Named models first, alphabetically; the unnameable ones last rather than
    // dropped. An inventory row whose UUID is in no spec file is a data problem
    // worth seeing in the open -- silently hiding it would make a part that
    // physically exists unrequestable, with no explanation anywhere.
    usort($models, function ($a, $b) {
        $al = $a['model_label'];
        $bl = $b['model_label'];
        if (($al === null) !== ($bl === null)) {
            return $al === null ? 1 : -1;
        }
        if ($al === null) {
            return strcmp($a['component_uuid'], $b['component_uuid']);
        }
        return strcasecmp($al, $bl);
    });

    send_json_response(true, true, 200, $anyStatus ? "Inventory models loaded" : "Available stock loaded", [
        'component_type' => $componentType,
        'source'         => $source,
        // Whether here_count is present on each model. The form must not read a
        // missing annotation as "none of it is at the right site".
        'location_aware' => $preferredLocation !== null,
        'models'         => $models,
    ]);
} catch (Exception $e) {
    error_log("pipeline-component-options error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to load component options");
}
