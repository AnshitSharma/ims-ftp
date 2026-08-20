-- =============================================================================
-- Date:     2026-08-21
-- Purpose:  Let a temporary grant apply to ONE specific server configuration
--           instead of to every configuration in the system.
--
--           Without this, approving "let me fix the RAM in server X" hands out
--           server.edit globally, and the requester can edit anybody's build.
--           With it, the grant carries the target's config_uuid and is only ever
--           honoured for that one configuration.
--
-- Tables:   user_permissions (2 new columns, 1 widened UNIQUE, 1 index)
-- Feature:  Temporary approval-gated access (Requests module), phase 2
--
-- REQUIRES: 2026_08_20_001_temporary-permission-grants.sql first.
--
-- Notes:    - scope_type / scope_id are NOT NULL with an EMPTY-STRING default,
--             deliberately -- not NULL. The UNIQUE key has to include them so a
--             user can hold server.edit on config A and config B at once, and in
--             MySQL/MariaDB NULLs compare as DISTINCT inside a UNIQUE index.
--             With NULLs, every re-grant of a GLOBAL permission would insert a
--             new row instead of updating the existing one, and the
--             ON DUPLICATE KEY UPDATE in TemporaryAccessManager::grant() would
--             silently stop deduplicating. '' = global.
--           - Existing rows take '' on both columns, i.e. every Phase 1 grant
--             stays global. Nothing needs backfilling.
--           - scope_type is a discriminator, currently only 'server_config'.
--             scope_id is VARCHAR(64) to hold a config_uuid (36 chars) with room
--             to spare for a future numeric or composite scope.
--           - The old UNIQUE `user_permission` is DROPPED and replaced. This is
--             the only destructive step in the file; it removes a constraint, not
--             data, and the replacement is strictly wider (same columns + 2).
--             The redundant KEY idx_user_permissions_user_perm is left alone.
--           - SCOPED ROWS ARE DELIBERATELY EXCLUDED from the flat permission list
--             that hasPermission() reads (see BaseFunctions::loadUserPermissionData
--             and ACL::loadUserPermissions, both filtered on scope_type = '').
--             A scoped grant can therefore never widen a global check; it is
--             consulted only when a specific configuration is named. That is what
--             keeps "edit server X" from becoming "create any server".
--           - Idempotent via native IF NOT EXISTS / IF EXISTS (MariaDB 10.0.2+).
--             Deliberately NOT information_schema guards -- the application DB
--             user has no access to that schema on this host.
--           - Rollback: rollback/2026_08_21_001_scoped-permission-grants_rollback.sql
-- =============================================================================

ALTER TABLE `user_permissions`
    ADD COLUMN IF NOT EXISTS `scope_type` VARCHAR(32) NOT NULL DEFAULT ''
        COMMENT "'' = global grant; 'server_config' = limited to one configuration"
        AFTER `permission_id`;

ALTER TABLE `user_permissions`
    ADD COLUMN IF NOT EXISTS `scope_id` VARCHAR(64) NOT NULL DEFAULT ''
        COMMENT "The scope target (a server_configurations.config_uuid); '' when global"
        AFTER `scope_type`;

-- Widen the uniqueness rule: one row per (user, permission, scope).
ALTER TABLE `user_permissions`
    DROP INDEX IF EXISTS `user_permission`;

ALTER TABLE `user_permissions`
    ADD UNIQUE KEY IF NOT EXISTS `uq_user_permission_scope`
        (`user_id`, `permission_id`, `scope_type`, `scope_id`);

-- "which live grants does this user hold for config X?" -- the lookup the
-- per-configuration ownership check makes.
ALTER TABLE `user_permissions`
    ADD INDEX IF NOT EXISTS `idx_user_permissions_scope` (`scope_type`, `scope_id`, `user_id`);

-- ---------------------------------------------------------------------------
-- Verification (SHOW needs no information_schema privilege)
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `user_permissions`;
SHOW INDEX FROM `user_permissions`;
