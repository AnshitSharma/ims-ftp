-- =============================================================================
-- ROLLBACK for 2026_08_18_003_add-sandbox-flag-to-configurations.sql
-- Date:     2026-08-18
--
-- Drops the is_sandbox column and its index from server_configurations.
--
-- WARNING: any bench build created through the Server Compatibility section
--          becomes an ordinary virtual config once this column is gone, which
--          means it reappears in the Import Template picker. Delete the bench
--          builds FIRST if that matters:
--
--            DELETE FROM `server_configurations`
--             WHERE `is_sandbox` = 1;
--
--          They hold no inventory (is_virtual = 1 reserves nothing), so
--          deleting them releases nothing and disturbs no real server.
-- =============================================================================

ALTER TABLE `server_configurations`
    DROP INDEX IF EXISTS `idx_server_configurations_is_sandbox`;

ALTER TABLE `server_configurations`
    DROP COLUMN IF EXISTS `is_sandbox`;

SHOW COLUMNS FROM `server_configurations` LIKE 'is\_%';
