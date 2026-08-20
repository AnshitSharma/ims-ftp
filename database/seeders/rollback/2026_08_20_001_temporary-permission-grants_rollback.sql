-- =============================================================================
-- Rollback for: 2026_08_20_001_temporary-permission-grants.sql
-- Date:         2026-08-20
-- Tables:       user_permissions
--
-- WARNING: dropping expires_at turns every outstanding TEMPORARY grant into a
--          PERMANENT one, because the code's fallback path (no expires_at column)
--          treats every row as permanent. Delete the temporary rows FIRST -- the
--          statement below does exactly that, and is deliberately placed before
--          the ALTERs. It only removes rows this feature created (source_ticket_id
--          IS NOT NULL), never hand-made permanent grants.
-- =============================================================================

DELETE FROM `user_permissions`
WHERE `source_ticket_id` IS NOT NULL;

ALTER TABLE `user_permissions`
    DROP INDEX IF EXISTS `idx_user_permissions_source`;

ALTER TABLE `user_permissions`
    DROP INDEX IF EXISTS `idx_user_permissions_active`;

ALTER TABLE `user_permissions`
    DROP COLUMN IF EXISTS `source_ticket_id`;

ALTER TABLE `user_permissions`
    DROP COLUMN IF EXISTS `granted_by`;

ALTER TABLE `user_permissions`
    DROP COLUMN IF EXISTS `revoked_at`;

ALTER TABLE `user_permissions`
    DROP COLUMN IF EXISTS `expires_at`;

SHOW COLUMNS FROM `user_permissions`;
