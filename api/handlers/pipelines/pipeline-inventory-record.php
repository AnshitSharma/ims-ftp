<?php
/**
 * pipeline-inventory-record.php
 * Action: pipeline-inventory-record
 * Permission: pipeline.create | pipeline.manage  (identical to pipeline-create)
 *
 * "Which record am I asking to be corrected, and what does it say right now?"
 *
 * WHY THIS EXISTS
 * ---------------
 * Update Inventory Record is raised by people who cannot edit inventory — that is
 * the whole reason they are raising a request. But a correction cannot be written
 * blind: the requester has to see the record's CURRENT values before they can say
 * which of them is wrong. The only other route to those values is {type}-get,
 * gated on {type}.view, which is exactly the permission this requester does not
 * hold. Without this endpoint the action can only ever be raised from the Edit
 * Component screen, by someone who can already read the record.
 *
 * Same bargain pipeline-component-location.php and pipeline-servers.php document:
 * if you may raise a request naming a component, you may be told about the
 * component you just named.
 *
 * TWO QUESTIONS, ONE ENDPOINT
 * ---------------------------
 * With a component_uuid it answers "which units of this model exist?" -- the
 * record picker. With an inventory_id it answers "what does that one record say?"
 * -- the form's prefill. Both are the same facts about the same rows, so
 * splitting them would mean two gates and two sets of field names for one lookup.
 *
 * WHY THE UNIT LIST IS NOT unitOptions()
 * --------------------------------------
 * LocationResolver::unitOptions() returns AVAILABLE, LOOSE stock, because its
 * caller is the handover form and a part inside a build cannot be carried off on
 * its own. The records that need correcting are the opposite: in-use and failed
 * units are precisely the ones with a wrong status, a wrong location or a missing
 * warranty date. So this lists every unit of the model
 * (unitsForModel(..., $availableOnly = false)) and reports each one's status, and
 * the picker says which is which.
 *
 * WHAT IT DELIBERATELY DOES NOT RETURN
 * ------------------------------------
 * Inventory columns only, for ONE model or ONE row the caller already named. No
 * specifications, no JSON blobs, no catalogue enumeration, no cross-type search.
 * Anyone wanting that still needs {type}.view.
 *
 * Params:
 * - component_type (required) cpu | ram | storage | ...
 * - component_uuid (optional) the MODEL uuid from ims-data -> list its units
 * - inventory_id   (optional) one {type}inventory row -> that record's fields
 *   (one of the two is required; inventory_id wins if both are sent)
 */

require_once(__DIR__ . '/../../../core/models/location/LocationResolver.php');

// The inventory columns an edit request may see and set.
//
// Mirrors the fields edit-form.js renderCommonFields() puts on screen — the form
// this feeds. Specification/JSON columns are absent on purpose, and so is
// AssetTag: it is system-issued and getBlockedComponentColumns() refuses to write
// it, so offering it would be offering a field that silently does nothing.
$recordFields = [
    'ID', 'UUID', 'SerialNumber', 'Status', 'ServerUUID', 'VendorID',
    'Location', 'location_uuid', 'StoreLocation', 'RackPosition',
    'PurchaseDate', 'InstallationDate', 'WarrantyEndDate', 'FailDate',
    'Flag', 'Notes',
];

try {
    if (!$acl->hasPermission($user_id, 'pipeline.create')
        && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: pipeline.create required", null);
        exit;
    }

    $componentType = strtolower(trim((string)($_POST['component_type'] ?? $_GET['component_type'] ?? '')));
    $componentUuid = trim((string)($_POST['component_uuid'] ?? $_GET['component_uuid'] ?? ''));
    $inventoryId   = trim((string)($_POST['inventory_id']   ?? $_GET['inventory_id']   ?? ''));

    if ($componentType === '') {
        send_json_response(false, true, 400, "component_type is required", null);
        exit;
    }
    if (!in_array($componentType, LocationResolver::COMPONENT_TYPES, true)) {
        send_json_response(false, true, 400, "Unknown component type", null);
        exit;
    }
    if ($componentUuid === '' && $inventoryId === '') {
        send_json_response(false, true, 400,
            "Either component_uuid or inventory_id is required", null);
        exit;
    }

    $table = $componentType . 'inventory';

    // --- one record: what the form prefills from --------------------------
    if ($inventoryId !== '') {
        if (!ctype_digit($inventoryId)) {
            send_json_response(false, true, 400, "inventory_id must be a number", null);
            exit;
        }

        // Built from the live table, not from a fixed list: several of these
        // columns arrived with a seeder that may not have been run yet here.
        $available = getInventoryTableColumns($pdo, $table);
        $select    = [];
        foreach ($recordFields as $field) {
            $lc = strtolower($field);
            if (isset($available[$lc])) {
                $select[] = '`' . $available[$lc] . '`';
            }
        }
        if (empty($select)) {
            send_json_response(false, true, 500, "That inventory table is not readable", null);
            exit;
        }

        $stmt = $pdo->prepare("SELECT " . implode(', ', $select) . " FROM `{$table}` WHERE ID = ?");
        $stmt->execute([(int)$inventoryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            send_json_response(false, true, 404, "That inventory record no longer exists", null);
            exit;
        }

        // The vendor NAME, because a requester without vendor.view gets an empty
        // vendor dropdown. Without the name the form would show "-- No Vendor --"
        // on a record that has one, and the change it submits would then read as
        // "clear the vendor" — a correction nobody asked for.
        $row['vendor_name'] = null;
        if (!empty($row['VendorID'])) {
            try {
                $v = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
                $v->execute([(int)$row['VendorID']]);
                $name = $v->fetchColumn();
                if ($name !== false) {
                    $row['vendor_name'] = $name;
                }
            } catch (Exception $e) {
                // Decoration. An unreadable vendors table must not make the
                // record unreadable; the dropdown then shows the id it holds.
                error_log("pipeline-inventory-record vendor lookup: " . $e->getMessage());
            }
        }

        send_json_response(true, true, 200, "Inventory record loaded", [
            'component_type' => $componentType,
            'record'         => $row,
        ]);
        exit;
    }

    // --- the model's units: what the record picker lists -------------------
    $units = [];
    foreach (LocationResolver::unitsForModel($pdo, $componentType, $componentUuid, false) as $u) {
        $units[] = [
            'inventory_id'  => isset($u['ID']) ? (int)$u['ID'] : null,
            'serial_number' => isset($u['SerialNumber']) ? $u['SerialNumber'] : null,
            'asset_tag'     => isset($u['AssetTag']) ? $u['AssetTag'] : null,
            // 0 failed / 1 available / 2 in use. Named in words by the picker,
            // because "which of these three identical drives" is answered by
            // where the unit is and what state it is in.
            'status'        => isset($u['Status']) ? (int)$u['Status'] : null,
            'server_uuid'   => isset($u['ServerUUID']) ? $u['ServerUUID'] : null,
            'server_name'   => isset($u['server_name']) ? $u['server_name'] : null,
            'location_name' => isset($u['location_name']) ? $u['location_name'] : null,
            'address_text'  => isset($u['address_text']) ? $u['address_text'] : null,
        ];
    }

    send_json_response(true, true, 200, "Inventory units loaded", [
        'component_type' => $componentType,
        'component_uuid' => $componentUuid,
        'units'          => $units,
    ]);
} catch (Exception $e) {
    error_log("pipeline-inventory-record error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to load that inventory record");
}
