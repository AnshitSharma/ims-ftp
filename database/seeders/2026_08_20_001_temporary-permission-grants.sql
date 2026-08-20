-- =============================================================================
-- Date:     2026-08-20
-- Purpose:  Give `user_permissions` an EXPIRY, so a permission can be granted to
--           one user for a fixed window and then lapse on its own.
--
--           `user_permissions` already exists and is already unioned with role
--           permissions by loadUserPermissionData() (core/helpers/BaseFunctions.php),
--           so a row here is a real, enforced grant today -- it simply has no way
--           to end. These four columns are what turn it into a temporary grant.
--
--           Because permissions are looked up from the DB on EVERY request (the
--           JWT carries only user_id + username), both the grant and its later
--           expiry take effect on the user's very next API call. No re-login, no
--           token refresh, no background job.
--
-- Tables:   user_permissions (4 new columns + 1 index)
-- Feature:  Temporary approval-gated server-build access (Requests module)
--
-- Notes:    - Idempotent via native ADD COLUMN / ADD INDEX IF NOT EXISTS
--             (MariaDB 10.0.2+), same style as 2026_08_18_001/003. Deliberately
--             NOT information_schema guards: the application DB user has no
--             access to that schema on this host, so guarded seeders die at
--             PREPARE before any ALTER runs.
--           - expires_at NULL means PERMANENT. Every existing row therefore keeps
--             exactly its current meaning, and the four rows an admin grants by
--             hand through acl-assign_permission stay permanent as before.
--           - The existing UNIQUE (user_id, permission_id) is KEPT. Re-granting
--             the same permission to the same user reuses the row via
--             ON DUPLICATE KEY UPDATE rather than inserting a second one, so no
--             destructive constraint change is needed here. The audit trail of
--             who was granted what and when lives in ticket_history, keyed back
--             through source_ticket_id.
--           - granted_by is INT(6) UNSIGNED to match users.id. Note that the
--             pre-existing user_permissions.user_id is INT(11) SIGNED -- a
--             mismatch with users.id that predates this change and is left alone
--             (no FK exists on that table, and altering the column type of a
--             live keyed column is not worth the risk for this feature).
--           - No ACL rows and no new API action: granting happens inside the
--             pipeline approval path, gated by roles that already exist.
--           - Rollback: rollback/2026_08_20_001_temporary-permission-grants_rollback.sql
-- =============================================================================

ALTER TABLE `user_permissions`
    ADD COLUMN IF NOT EXISTS `expires_at` DATETIME NULL DEFAULT NULL
        COMMENT 'NULL = permanent grant; otherwise the grant is ignored from this moment on'
        AFTER `permission_id`;

ALTER TABLE `user_permissions`
    ADD COLUMN IF NOT EXISTS `revoked_at` DATETIME NULL DEFAULT NULL
        COMMENT 'Set to revoke a grant before its natural expiry; NULL = not revoked'
        AFTER `expires_at`;

ALTER TABLE `user_permissions`
    ADD COLUMN IF NOT EXISTS `granted_by` INT(6) UNSIGNED NULL DEFAULT NULL
        COMMENT 'users.id of the approver who created this grant'
        AFTER `revoked_at`;

ALTER TABLE `user_permissions`
    ADD COLUMN IF NOT EXISTS `source_ticket_id` INT(10) UNSIGNED NULL DEFAULT NULL
        COMMENT 'tickets.id of the Request whose approval produced this grant'
        AFTER `granted_by`;

-- The only hot read is "all live grants for this user", issued once per request
-- from loadUserPermissionData().
ALTER TABLE `user_permissions`
    ADD INDEX IF NOT EXISTS `idx_user_permissions_active` (`user_id`, `expires_at`);

-- Lets an admin answer "what did approving request N actually grant?" without a
-- full scan.
ALTER TABLE `user_permissions`
    ADD INDEX IF NOT EXISTS `idx_user_permissions_source` (`source_ticket_id`);

-- ---------------------------------------------------------------------------
-- Verification (SHOW needs no information_schema privilege)
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `user_permissions`;
SHOW INDEX FROM `user_permissions`;
