-- =============================================================================
-- Seeder: 2026_06_18_003_restrict-pipeline-to-super-admin.sql
-- Date:    2026-06-18
-- Purpose: Make the Pipeline feature accessible to super_admin ONLY.
--          Seeder 002 mirrored the pipeline.* permissions onto every role that
--          already held the equivalent ticket.* permission (manager, technician,
--          etc.). The ACL (core/auth/ACL.php::hasPermission) is role_permissions
--          driven, so those grants expose pipeline endpoints to non-super_admins.
--          This seeder revokes every pipeline.* grant from all roles except
--          super_admin (role id 1) and guarantees super_admin holds them. The
--          router (api/api.php::handlePipelineOperations) also enforces a
--          super_admin-only gate in code.
-- Affected tables: role_permissions, user_permissions
-- Related feature/task: lock the Pipeline module down to super_admin.
--
-- Notes:
--   * Run AFTER 2026_06_18_002_seed-pipeline-acl-permissions.sql.
--   * super_admin = role id 1.
--   * Idempotent. Safe to re-run.
-- =============================================================================

START TRANSACTION;

-- ----------------------------------------------------------------------------
-- 1. Revoke every pipeline.* permission from all roles except super_admin (1).
-- ----------------------------------------------------------------------------
DELETE FROM `role_permissions`
 WHERE `role_id` <> 1
   AND `permission_id` IN (
       SELECT `id` FROM `permissions` WHERE `name` LIKE 'pipeline.%'
   );

-- ----------------------------------------------------------------------------
-- 2. Revoke any individual (per-user) pipeline.* grants so no non-super_admin
--    user keeps access via user_permissions.
-- ----------------------------------------------------------------------------
DELETE FROM `user_permissions`
 WHERE `permission_id` IN (
       SELECT `id` FROM `permissions` WHERE `name` LIKE 'pipeline.%'
   );

-- ----------------------------------------------------------------------------
-- 3. Guarantee super_admin (role 1) holds all pipeline.* permissions.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT 1, `id`, 1
  FROM `permissions`
 WHERE `name` LIKE 'pipeline.%';

COMMIT;

-- =============================================================================
-- Verification (optional):
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name LIKE 'pipeline.%' ORDER BY r.name, p.name;
--   -- Expected: only role 'super_admin' rows appear.
-- =============================================================================
