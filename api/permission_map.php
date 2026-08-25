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
 * Modules NOT listed here (acl, dashboard, search, users, vendor, pipeline,
 * roles, permissions, auth) perform operation-specific checks inside their
 * handlers because the logic isn't a flat name lookup (admin-role gates,
 * self-delete guards, public auth endpoints). The legacy 'ticket' module was
 * retired — its work items now run on the 'pipeline' (Requests) engine.
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
        'replace-component' => 'server.replace', // U-A.2 -- mirrors add/remove's own edit-family gating
        'transition-status' => 'server.transition', // U-A.2 -- mirrors finalize-config's create-family gating
        'get-compatible' => 'server.view',
        'validate-config' => 'server.view',
        'save-config' => 'server.create',
        'load-config' => 'server.view',
        'list-configs' => 'server.view',
        'delete-config' => 'server.delete',
        'clone-config' => 'server.create',
        'get-statistics' => 'server.view_statistics',
        'update-config' => 'server.edit_details', // 2026-08-23 -- see the note under this array
        'get-components' => 'server.view',
        'export-config' => 'server.view',
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
        'fix-onboard-nics' => 'server.edit',
        'debug-motherboard-nics' => 'server.view',
        'debug-migration-flags' => 'server.view', // TEMPORARY (U-B.4 soak diagnostic) -- also role-gated admin/super_admin in the handler
        'debug-config-dualwrite' => 'server.view', // TEMPORARY (U-B.4 soak diagnostic) -- also role-gated admin/super_admin in the handler
        'debug-shadow-log' => 'server.view', // TEMPORARY (shadow-soak diagnostic) -- also role-gated admin/super_admin in the handler
        'debug-deadcode' => 'server.view', // TEMPORARY (U-D.1 deletion precondition) -- also role-gated admin/super_admin in the handler
    ],

    // Operations use underscores to match the cases in
    // handlers/server/compatibility_api.php.
    'compatibility' => [
        'check' => 'compatibility.check',
        'check_pair' => 'compatibility.check',
        'check_multiple' => 'compatibility.check',
        'get_compatible_for' => 'compatibility.check',
        'batch_check' => 'compatibility.check',
        'analyze_configuration' => 'compatibility.check',
        'check_storage_direct' => 'compatibility.check',
        'check_storage_recursive' => 'compatibility.check',
        'get_rules' => 'compatibility.view_statistics',
        'get_statistics' => 'compatibility.view_statistics',
        'benchmark_performance' => 'compatibility.view_statistics',
        'test_rule' => 'compatibility.manage_rules',
        'clear_cache' => 'compatibility.manage_rules',
        'export_rules' => 'compatibility.manage_rules',
        'import_rules' => 'compatibility.manage_rules',
    ],

    // Rack View — physical racks and server placement.
    'rack' => [
        'list' => 'rack.view',
        'get' => 'rack.view',
        'unassigned-servers' => 'rack.view',
        'placement' => 'rack.view',
        'create' => 'rack.create',
        'update' => 'rack.edit',
        'delete' => 'rack.delete',
        'assign-server' => 'rack.assign',
        'unassign-server' => 'rack.assign',
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

    // Shared template for the 10 component-type modules.
    'component' => [
        'list' => '{module}.view',
        'get' => '{module}.view',
        'add' => '{module}.create',
        'update' => '{module}.edit',
        'delete' => '{module}.delete',
        'bulk_update' => '{module}.edit',
        'bulk_delete' => '{module}.delete',
        'bulk-add' => '{module}.create',
        'bulk-delete' => '{module}.delete',
    ],
];
