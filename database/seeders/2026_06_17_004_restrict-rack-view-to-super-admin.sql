-- =============================================================================
-- Seeder: 2026_06_17_004_restrict-rack-view-to-super-admin.sql
-- Date:    2026-06-17
-- Purpose: Make the Rack View feature accessible to super_admin ONLY.
--          Seeder 001 mirrored the rack.* permissions onto every role that
--          already held the equivalent server.* permission (admin, manager,
--          technician, etc.). The ACL (core/auth/ACL.php::hasPermission) is
--          purely role_permissions-driven with NO admin/super_admin bypass,
--          so those grants actually expose rack endpoints to admins.
--          This seeder revokes every rack.* grant from all roles except
--          super_admin (role id 1) and guarantees super_admin holds them.
-- Affected tables: role_permissions, user_permissions
-- Related feature/task: lock Rack View down to the Dev (super_admin) account
--
-- Notes:
--   * Run AFTER 2026_06_17_001_add-rack-view-tables-and-acl.sql.
--   * super_admin = role id 1.
--   * Idempotent. Safe to re-run.
-- =============================================================================

START TRANSACTION;

-- ----------------------------------------------------------------------------
-- 1. Revoke every rack.* permission from all roles except super_admin (1).
-- ----------------------------------------------------------------------------
DELETE FROM `role_permissions`
 WHERE `role_id` <> 1
   AND `permission_id` IN (
       SELECT `id` FROM `permissions` WHERE `name` LIKE 'rack.%'
   );

-- ----------------------------------------------------------------------------
-- 2. Revoke any individual (per-user) rack.* grants so no non-super_admin
--    user keeps access via user_permissions.
-- ----------------------------------------------------------------------------
DELETE FROM `user_permissions`
 WHERE `permission_id` IN (
       SELECT `id` FROM `permissions` WHERE `name` LIKE 'rack.%'
   );

-- ----------------------------------------------------------------------------
-- 3. Guarantee super_admin (role 1) holds all rack.* permissions.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT 1, `id`, 1
  FROM `permissions`
 WHERE `name` LIKE 'rack.%';

COMMIT;

-- =============================================================================
-- Verification (optional):
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name LIKE 'rack.%' ORDER BY r.name, p.name;
--   -- Expected: only role 'super_admin' rows appear.
-- =============================================================================
