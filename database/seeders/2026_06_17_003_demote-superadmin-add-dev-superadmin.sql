-- =============================================================================
-- Seeder: 2026_06_17_003_demote-superadmin-add-dev-superadmin.sql
-- Date:    2026-06-17
-- Purpose: 1) Demote the existing `superadmin` account (user id 38) to the
--             `admin` role only — swap its super_admin role for admin and
--             strip its 8 individual user_permissions so it is truly admin-only.
--          2) Create a new `Dev` account (password = "password") and grant it
--             the `super_admin` role, which already carries all 94 permissions.
-- Affected tables: users, user_roles, user_permissions
-- Related feature/task: superadmin demotion + Dev super-admin provisioning
--
-- Role ids: super_admin = 1, admin = 2
-- Notes:
--   * The `super_admin` role (id 1) is already fully populated in
--     role_permissions (94/94 permissions), so no permission rows are added.
--   * The Dev password hash below is the bcrypt of "password".
--   * Idempotent: relies on UNIQUE(username)/UNIQUE(email) on `users` and
--     UNIQUE(user_id, role_id) on `user_roles`.
-- =============================================================================

START TRANSACTION;

-- ----------------------------------------------------------------------------
-- 1. Demote `superadmin` (user id 38): super_admin (1) -> admin (2)
-- ----------------------------------------------------------------------------
UPDATE `user_roles`
   SET `role_id` = 2
 WHERE `user_id` = 38
   AND `role_id` = 1;

-- Remove the account's individual permission grants so it is admin-role-only.
DELETE FROM `user_permissions`
 WHERE `user_id` = 38;

-- ----------------------------------------------------------------------------
-- 2. Create the `Dev` account (password = "password")
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `users`
  (`firstname`, `lastname`, `username`, `password`, `email`, `status`)
VALUES
  ('Dev', '', 'Dev',
   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
   'dev@bharatdatacenter.com', 'active');

-- ----------------------------------------------------------------------------
-- 3. Grant the `Dev` account the super_admin role (id 1 = all permissions)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`, `assigned_by`)
SELECT `id`, 1, NULL
  FROM `users`
 WHERE `username` = 'Dev';

COMMIT;
