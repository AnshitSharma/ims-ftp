-- =============================================================================
-- Seeder: 2026_06_21_001_grant-pipeline-permissions-to-admin.sql
-- Date:    2026-06-21
-- Purpose: Grant all pipeline.* permissions to the admin role so that admins
--          can access the Requests module and Request Types (Settings).
--          Seeder 2026_06_18_003 previously revoked these from all roles except
--          super_admin. The API gate in api.php::handlePipelineOperations() and
--          the sidebar UI gate in sidebar-manager.js are both updated to allow
--          admin alongside super_admin.
-- Affected tables: role_permissions
-- Related feature/task: admin role access to Requests module
--
-- Notes:
--   * Run AFTER 2026_06_18_003_restrict-pipeline-to-super-admin.sql.
--   * Uses role name lookup (not hardcoded id) — safe across environments.
--   * Idempotent (INSERT IGNORE). Safe to re-run.
-- =============================================================================

START TRANSACTION;

-- Grant all pipeline.* permissions to the admin role (by name lookup).
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT r.`id`, p.`id`, 1
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`name` = 'admin'
  AND p.`name` LIKE 'pipeline.%';

COMMIT;

-- =============================================================================
-- Verification (optional):
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name LIKE 'pipeline.%' ORDER BY r.name, p.name;
--   -- Expected: rows for both 'admin' and 'super_admin'.
-- =============================================================================
