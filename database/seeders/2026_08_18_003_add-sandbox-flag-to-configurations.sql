-- =============================================================================
-- Date:     2026-08-18
-- Purpose:  Mark a configuration as a COMPATIBILITY BENCH build (Server
--           Compatibility section): a throwaway server assembled purely to ask
--           the compatibility engine "would these parts work together?".
--
--           A bench build is always ALSO is_virtual = 1, which is what actually
--           guarantees it reserves nothing: ServerBuilder::addComponent() skips
--           the inventory row lock, the availability check, the duplicate check
--           and -- the point of the whole feature -- the status flip from
--           available (1) to in_use (2) whenever is_virtual is set.
--
--           is_sandbox exists ON TOP of that only to keep bench builds OUT of
--           places that legitimately list virtual configs. Without it a
--           throwaway "test cpu socket" build would appear in every server's
--           Import Template picker, which filters on is_virtual = 1.
--
-- Tables:   server_configurations (1 new column + 1 index)
-- Feature:  Server Compatibility bench (sidebar section)
--
-- Notes:    - Idempotent via native ADD COLUMN / ADD INDEX IF NOT EXISTS
--             (MariaDB 10.0.2+), same style as 2026_08_18_001. Deliberately NOT
--             information_schema guards: the application DB user has no access
--             to that schema on this host, so guarded seeders die at PREPARE
--             before any ALTER runs.
--           - Mirrors the existing is_virtual column exactly (tinyint(1), its
--             own index) because the two are read together in the same WHERE
--             clause by server-list-configs.
--           - Existing rows default to 0. Nothing needs backfilling: every
--             configuration that exists today is either a real server or a
--             saved template, and neither is a bench build.
--           - No ACL rows: the bench reuses server.create / server.view /
--             server.delete and adds no new API action.
--           - Rollback: rollback/2026_08_18_003_add-sandbox-flag-to-configurations_rollback.sql
-- =============================================================================

ALTER TABLE `server_configurations`
    ADD COLUMN IF NOT EXISTS `is_sandbox` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '0=Normal config, 1=Compatibility bench build (always is_virtual=1; reserves nothing)'
        AFTER `is_virtual`;

-- "list only the bench builds" / "list everything except bench builds" are the
-- two expected reads, both alongside is_virtual.
ALTER TABLE `server_configurations`
    ADD INDEX IF NOT EXISTS `idx_server_configurations_is_sandbox` (`is_sandbox`);

-- ---------------------------------------------------------------------------
-- Verification (SHOW needs no information_schema privilege)
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `server_configurations` LIKE 'is\_%';
