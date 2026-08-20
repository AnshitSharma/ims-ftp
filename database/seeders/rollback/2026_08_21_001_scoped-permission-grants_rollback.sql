-- =============================================================================
-- Rollback for: 2026_08_21_001_scoped-permission-grants.sql
-- Date:         2026-08-21
-- Tables:       user_permissions
--
-- WARNING: dropping scope_type/scope_id would turn every SCOPED grant into a
--          GLOBAL one -- "edit server X" would silently become "edit every
--          server". The DELETE below removes scoped rows FIRST and is placed
--          before the ALTERs for that reason. Global grants (scope_type = '')
--          are untouched.
--
-- Also note the widened UNIQUE is replaced by the original narrow one, which
-- cannot hold two rows for the same (user, permission) -- another reason the
-- scoped rows must go first.
-- =============================================================================

DELETE FROM `user_permissions`
WHERE `scope_type` <> '';

ALTER TABLE `user_permissions`
    DROP INDEX IF EXISTS `idx_user_permissions_scope`;

ALTER TABLE `user_permissions`
    DROP INDEX IF EXISTS `uq_user_permission_scope`;

ALTER TABLE `user_permissions`
    ADD UNIQUE KEY IF NOT EXISTS `user_permission` (`user_id`, `permission_id`);

ALTER TABLE `user_permissions`
    DROP COLUMN IF EXISTS `scope_id`;

ALTER TABLE `user_permissions`
    DROP COLUMN IF EXISTS `scope_type`;

SHOW COLUMNS FROM `user_permissions`;
SHOW INDEX FROM `user_permissions`;
