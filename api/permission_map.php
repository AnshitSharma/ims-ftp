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
        'update-config' => 'server.edit',
        'get-components' => 'server.view',
        'export-config' => 'server.view',
        'get-config' => 'server.view',
        'get-logs' => 'server.view',
        'finalize-config' => 'server.create',
        'get-available-components' => 'server.view',
        'import-virtual' => 'server.create',
        'search-by-serial' => 'server.view',
        'update-location' => 'server.edit',
        'fix-onboard-nics' => 'server.edit',
        'debug-motherboard-nics' => 'server.view',
        'debug-migration-flags' => 'server.view', // TEMPORARY (U-B.4 soak diagnostic) -- also role-gated admin/super_admin in the handler
        'debug-config-dualwrite' => 'server.view', // TEMPORARY (U-B.4 soak diagnostic) -- also role-gated admin/super_admin in the handler
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
        'create' => 'rack.create',
        'update' => 'rack.edit',
        'delete' => 'rack.delete',
        'assign-server' => 'rack.assign',
        'unassign-server' => 'rack.assign',
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
