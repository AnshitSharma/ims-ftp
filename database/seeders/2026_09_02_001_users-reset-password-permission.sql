-- ============================================================
-- Seeder : 2026_09_02_001_users-reset-password-permission
-- Date   : 2026-09-02
-- Purpose: Back the new `users-reset-password` action with a permission row of
--          its own, instead of letting it ride on `users.edit`.
--
--          An administrator can now set a new password for any other account
--          from ACL -> Users. That is a different kind of power from editing a
--          name or an email: whoever holds it can take over any account,
--          including another admin's. Riding on users.edit would mean that the
--          day someone grants "edit users" to a manager in the role editor, they
--          have silently granted account takeover too.
--
--          Enforcement note, so this row is not mistaken for the thing that
--          stops a manager: handlers/users/users_api.php gates the operation on
--          the admin/super_admin ROLE first, and hasPermission() returns true
--          unconditionally for those two roles (BaseFunctions.php:320). So the
--          role gate is what enforces today. This row is what makes the
--          capability visible and revocable in the role editor, what the
--          frontend reads to show the button, and what decides access if that
--          gate is ever widened.
--
-- Changes: 1. `users.reset_password` permission row.
--          2. Granted to exactly the roles that hold `users.edit` today.
--             Verified live before writing this: of roles 1-5, only super_admin
--             (1) and admin (2) hold users.edit -- manager, technician and
--             viewer hold users.view only.
--
-- Tables : permissions (1 row), role_permissions (rows mirroring users.edit)
--
-- Notes  : Idempotent. Safe to re-run.
--            * Permission row inserted only when missing (NOT EXISTS).
--            * Grants skip roles that already hold it.
--            * Plain DML against permissions / role_permissions only -- no
--              catalogue lookups, which the application DB user cannot read.
--          NOT required for the feature to work. Admins pass the role gate and
--          bypass the permission check, and the frontend gate matches the "*"
--          wildcard in an admin's session payload. Running this seeder is what
--          puts the checkbox in the role editor.
--
-- Feature: ACL -> Users tab -- "Reset password" action.
--          Backend : users-reset-password (api/handlers/users/users_api.php)
--          Frontend: acl-manager.js handleResetPassword / #resetPasswordModal
--
-- Mapping (new perm          <- mirrors existing perm):
--   users.reset_password      <- users.edit
-- ============================================================

-- ------------------------------------------------------------
-- 1. The permission row
-- ------------------------------------------------------------
INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (
    SELECT 'users.reset_password' AS `name`,
           'Reset User Password' AS `display_name`,
           'Set a new password for another user account from ACL -> Users. The acting administrator must re-enter their own password, and the target is signed out of every device.' AS `description`,
           'user_management' AS `category`,
           0 AS `is_basic`
) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'users.reset_password');

-- ------------------------------------------------------------
-- 2. Grant it to the roles that already hold users.edit
-- ------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `_users_reset_password_grants`;
CREATE TEMPORARY TABLE `_users_reset_password_grants` (`role_id` INT NOT NULL, `permission_id` INT NULL);

INSERT INTO `_users_reset_password_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`,
       (SELECT `id` FROM `permissions` WHERE `name` = 'users.reset_password' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1
  AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'users.edit');

-- Drop roles that already have the grant, and bail out cleanly if step 1 somehow
-- left no permission row to point at.
DELETE g FROM `_users_reset_password_grants` g
JOIN `role_permissions` e ON e.`role_id` = g.`role_id` AND e.`permission_id` = g.`permission_id`;
DELETE FROM `_users_reset_password_grants` WHERE `permission_id` IS NULL;

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT `role_id`, `permission_id`, 1 FROM `_users_reset_password_grants`;

DROP TEMPORARY TABLE IF EXISTS `_users_reset_password_grants`;

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SELECT id, name, category FROM permissions WHERE name = 'users.reset_password';
--   -- expect 1 row, category user_management
--
--   SELECT r.name AS role, p.name AS perm, rp.granted
--     FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name IN ('users.edit', 'users.reset_password')
--    ORDER BY p.name, r.name;
--   -- expect the SAME role set granted for both (production today:
--   -- super_admin, admin)
-- ============================================================
