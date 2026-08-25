<?php
/**
 * pipeline-component-location.php
 * Action: pipeline-component-location
 * Permission: pipeline.create | pipeline.manage  (identical to pipeline-create)
 *
 * "Is the part I just picked actually where this server is?"
 *
 * WHY THIS EXISTS
 * ---------------
 * The create-request form asks for a component MODEL, because that is what a
 * requester knows -- "a Kingston KC600 512GB". The thing that has a location is a
 * UNIT: one row in {type}inventory with its own serial number. Two units of one
 * model can sit at two different sites, and fitting the Noida one into a server
 * racked in Jaipur is not a thing anybody can physically do.
 *
 * Before this existed, nothing noticed. The approval simply re-stamped the drive
 * with the server's address, producing a record of hardware in a rack nobody ever
 * carried it to. The executor now REFUSES that at approval time; this endpoint is
 * what lets the form say so while the requester is still looking at it, and offer
 * the Hardware Handover that fixes it.
 *
 * WHY THE GATE IS pipeline.create AND NOT {type}.view
 * ---------------------------------------------------
 * Same bargain pipeline-servers.php documents: if you may raise a request naming
 * a component, you may be told where the component you just named is. The typical
 * requester holds no inventory permissions at all -- which is the whole reason
 * they are raising a request -- so gating on {type}.view would make the warning
 * invisible to exactly the people who need it.
 *
 * WHAT IT DELIBERATELY DOES NOT RETURN
 * ------------------------------------
 * Identity and address only, for units of ONE model the caller already named, and
 * only units that are available stock. No specs, no purchase data, no notes, no
 * enumeration of the catalogue. Anyone wanting that still needs {type}.view.
 *
 * INERT BEFORE ITS SEEDERS. Until 2026_08_26_001/_003 are applied the inventory
 * tables have no location_uuid, checkComponentForConfig() reports
 * supported = false, and the UI renders no warning at all.
 *
 * TWO QUESTIONS, ONE ENDPOINT
 * ---------------------------
 * With a config_uuid it answers "is this model's stock where that server is?".
 * Without one it answers only "where are this model's units?" -- which is what
 * the Hardware Handover form needs to let someone pick the exact unit they are
 * carrying. Both answers are the same facts about the same units, so splitting
 * them into two endpoints would mean two gates and two sets of field names for
 * one lookup. `match` is simply null in the second case, which is already its
 * "cannot tell" value.
 *
 * Params:
 * - component_type (required) cpu | ram | storage | ...
 * - component_uuid (required) the MODEL uuid from ims-data
 * - config_uuid    (optional) the server the part would go into; without it no
 *                  comparison is made and `match` is null
 * - serial_number  (optional) when the requester already picked one exact unit
 */

require_once(__DIR__ . '/../../../core/models/location/LocationResolver.php');

try {
    if (!$acl->hasPermission($user_id, 'pipeline.create')
        && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: pipeline.create required", null);
        exit;
    }

    $configUuid    = trim((string)($_POST['config_uuid']    ?? $_GET['config_uuid']    ?? ''));
    $componentType = strtolower(trim((string)($_POST['component_type'] ?? $_GET['component_type'] ?? '')));
    $componentUuid = trim((string)($_POST['component_uuid'] ?? $_GET['component_uuid'] ?? ''));
    $serialNumber  = trim((string)($_POST['serial_number']  ?? $_GET['serial_number']  ?? ''));

    if ($componentType === '' || $componentUuid === '') {
        send_json_response(false, true, 400,
            "component_type and component_uuid are both required", null);
        exit;
    }
    if (!in_array($componentType, LocationResolver::COMPONENT_TYPES, true)) {
        send_json_response(false, true, 400, "Unknown component type", null);
        exit;
    }

    // Every available unit of the model, with its address. Returned in both
    // modes: it is what the handover form picks from, and what makes a mismatch
    // warning specific enough to act on.
    $units = LocationResolver::unitOptions($pdo, $componentType, $componentUuid);

    $check = $configUuid !== ''
        ? LocationResolver::checkComponentForConfig(
            $pdo,
            $configUuid,
            $componentType,
            $componentUuid,
            $serialNumber !== '' ? $serialNumber : null
        )
        // No server named: nothing to compare against, so nothing is claimed.
        : ['supported' => true, 'server' => null, 'match' => null,
           'units_here' => 0, 'units_elsewhere' => [], 'unit' => null,
           'reason' => 'no_server_named'];

    // The server's address is flattened to the two fields the banner needs. The
    // full resolution carries rack uuids and U ranges that a requester without
    // rack.view has no business receiving from this endpoint.
    $server = $check['server'];

    send_json_response(true, true, 200, "Location check complete", [
        'supported'       => (bool)$check['supported'],
        // true / false / null. NULL MEANS "CANNOT TELL" AND MUST NOT WARN --
        // the seeders may be unrun, the server may be unplaced, the stock may
        // have no location yet. Only false is a mismatch.
        'match'           => $check['match'],
        'reason'          => $check['reason'],
        'server'          => $server === null ? null : [
            'config_uuid'   => $server['config_uuid'],
            'server_name'   => $server['server_name'],
            'location_uuid' => $server['location_uuid'],
            'location_name' => $server['location_name'],
            'address_text'  => LocationResolver::formatAddress($server),
        ],
        'units_here'      => (int)$check['units_here'],
        'units_elsewhere' => $check['units_elsewhere'],
        'units'           => $units,
    ]);
} catch (Exception $e) {
    error_log("pipeline-component-location error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to check the component's location");
}
