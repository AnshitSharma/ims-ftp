<?php
/**
 * Central action → ACL permission map.
 *
 * Consumed by api.php::requireModulePermission(). Every operation a module
 * supports MUST be listed here — an unmapped operation is rejected with 400
 * before any handler code runs. There is deliberately no default/fallback
 * permission: a new endpoint must be added to this map consciously, with the
 * right permission, instead of silently inheriting a view-level check.
 *
 * The '{module}' placeholder is replaced with the concrete component type
 * (cpu, ram, ...) for the shared 'component' template.
 *
 * Modules NOT listed here (acl, dashboard, search, users, pipeline, roles,
 * permissions, auth) perform operation-specific checks inside their handlers
 * because the logic isn't a flat name lookup (admin-role gates, self-delete
 * guards, public auth endpoints).
 *
 * The legacy 'ticket' module was retired — its work items now run on the
 * 'pipeline' (Requests) engine.
 *
 * `vendor` JOINED THIS MAP on 2026-09-21 (E.5). It was on the excluded list only
 * because it carried a hard-coded admin-role gate; removing that gate made it a
 * flat name lookup like every other CRUD module, which is the whole reason the
 * map exists.
 *
 * server.edit vs server.edit_details (2026-08-23)
 * -----------------------------------------------
 * server.edit gates work on the PARTS in a build (remove-component,
 * remove-platform). server.edit_details gates writes to the build's OWN attributes —
 * name, description, location, rack position, notes, status.
 *
 * They were one permission until a live test showed the consequence: Add / Edit
 * / Remove Hardware must grant server.edit so a request-holder can take a part
 * out, and that also handed them server-update-config — renaming someone's build
 * and setting it Finalized, which then locked them out of it. Component
 * narrowing (BaseFunctions::requestScopedComponentPermission) cannot catch that:
 * update-config names no component_type. Splitting the permission is what makes
 * seeder 2026_08_23_001's promise true — "a hardware grant cannot roam the
 * build". Server Changes is the request type that grants edit_details.
 */
return [
    'server' => [
        'create-start' => 'server.create',
        'add-component' => 'server.create',
        'remove-component' => 'server.edit',
        // 'replace-component' removed 2026-09-21 with its handler (no caller in
        // either stack). The command it drove still runs from a Request step.
        'transition-status' => 'server.transition', // U-A.2 -- mirrors finalize-config's create-family gating
        // Read-only: which lifecycle moves this user could make on one config.
        // Gated on view, not transition -- it ANSWERS whether they may transition.
        'allowed-transitions' => 'server.view',
        'get-compatible' => 'server.view',
        'validate-config' => 'server.view',
        'list-configs' => 'server.view',
        'delete-config' => 'server.delete',
        'update-config' => 'server.edit_details', // 2026-08-23 -- see the note under this array
        'get-config' => 'server.view',
        'get-logs' => 'server.view',
        'finalize-config' => 'server.create',
        'get-available-components' => 'server.view',
        'import-virtual' => 'server.create',
        'search-by-serial' => 'server.view',
        'list-platforms' => 'server.view',
        // set-platform installs a compute platform: it CONSUMES a stocked unit and
        // releases whatever was in the build, so it is a create-family action, not an
        // edit. remove-platform only releases, which is the edit family (same as
        // remove-component).
        'set-platform' => 'server.create',
        'remove-platform' => 'server.edit',
        // 2026-08-26: update-location finally has a handler. It sets the location
        // of an UNRACKED server (staging room, bench, in transit) and propagates
        // it to every component inside. A racked server's location comes from its
        // rack, so the handler refuses one -- moving it is rack-assign-server.
        'update-location' => 'server.edit_details',
        'movements' => 'server.view', // 2026-08-26 -- relocation history for one config
        'update-ips' => 'server.edit_details', // 2026-09-24 -- record-only public/private IPs
        // Removed 2026-08-31: the three TEMPORARY debug-* diagnostics
        // (config-dualwrite, shadow-log, deadcode) went with the migration
        // scaffolding they served -- see the note at their old dispatch site in
        // handlers/server/server_api.php. 'debug-motherboard-nics' went with
        // them: it was mapped here but had no dispatch case anywhere in the
        // tree, so it could only ever have returned "Invalid action specified".
        // Removed 2026-08-31 (P2 cleanup): 'save-config', 'load-config',
        // 'clone-config', 'get-statistics', 'get-components', 'export-config'
        // and 'fix-onboard-nics' -- same shape, mapped here with no dispatch
        // case anywhere in server_api.php. No frontend caller used any of them.
    ],

    // Rack View — physical racks and server placement.
    'rack' => [
        'list' => 'rack.view',
        'get' => 'rack.view',
        'unassigned-servers' => 'rack.view',
        // Every physical server plus where it is now — the bay picker's source.
        // A read, so rack.view like its unassigned-only sibling.
        'placeable-servers' => 'rack.view',
        'placement' => 'rack.view',
        'create' => 'rack.create',
        'update' => 'rack.edit',
        'delete' => 'rack.delete',
        'assign-server' => 'rack.assign',
        'unassign-server' => 'rack.assign',
        // Blade enclosures (seeder 2026_09_03_003). No new permissions: an
        // enclosure is rack furniture, so installing, moving and removing one
        // is the same authority as editing the rack it goes in, and the model
        // list is a read. Slotting a SERVER into a bay is assign-server above.
        'enclosure-models' => 'rack.view',
        'enclosure-add' => 'rack.edit',
        'enclosure-update' => 'rack.edit',
        'enclosure-remove' => 'rack.edit',
    ],

    // Locations — the physical sites racks stand in. Reads are deliberately
    // broad (every component form renders a location dropdown); the writes are
    // additionally role-gated to admin/super_admin in api.php.
    'location' => [
        'list' => 'location.view',
        'get' => 'location.view',
        'racks' => 'location.view',
        'create' => 'location.create',
        'update' => 'location.edit',
        'delete' => 'location.delete',
    ],

    // E.5 (audit §12.5): these four permissions existed in the catalogue and
    // were grantable in the role editor, but the vendor module consulted NONE of
    // them — it required the admin or super_admin role instead. Granting
    // vendor.edit to a manager did nothing, which is a permission system that
    // lies about itself.
    //
    // The code's stated reason ("the ACL wildcard pattern '*.view' would
    // otherwise grant vendor.view to manager/viewer too") does not hold: there
    // are ZERO permission rows containing a wildcard, checked live 2026-09-21
    // across all 130. hasPermission() matches exact names, and its only bypass
    // is the is_admin short-circuit.
    //
    // CONSEQUENCE, decided with the owner: manager and technician already hold
    // all four grants, so they gain real vendor access — including delete. That
    // is now visible and revocable in the role editor, which it was not before.
    // viewer, developer and hardware_carrier hold none and are unaffected;
    // admin and super_admin are unaffected because hasPermission() bypasses.
    'vendor' => [
        'list'       => 'vendor.view',
        'get'        => 'vendor.view',
        'components' => 'vendor.view',
        'add'        => 'vendor.create',
        'update'     => 'vendor.edit',
        'delete'     => 'vendor.delete',
    ],

    // Shared template for the 10 component-type modules.
    'component' => [
        'list' => '{module}.view',
        'get' => '{module}.view',
        'add' => '{module}.create',
        'update' => '{module}.edit',
        'delete' => '{module}.delete',
        // bulk_update / bulk_delete (underscored) were mapped here but
        // component_crud_api.php only ever dispatched the hyphenated pair, so
        // they passed the ACL gate and fell through to "Invalid cpu operation".
        // Same class as the seven server-module entries removed 2026-08-31.
        'bulk-add' => '{module}.create',
        'bulk-delete' => '{module}.delete',
    ],
];
