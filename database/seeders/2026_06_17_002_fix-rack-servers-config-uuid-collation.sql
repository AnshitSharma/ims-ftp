-- ============================================================
-- Seeder : 2026_06_17_002_fix-rack-servers-config-uuid-collation
-- Date   : 2026-06-17
-- Purpose: Fix collation mismatch on rack_servers.config_uuid.
--          rack_servers was created with the table default
--          utf8mb4_general_ci, but server_configurations.config_uuid
--          is utf8mb4_unicode_ci. Any join/comparison between the two
--          (rack-get LEFT JOIN, rack-unassigned-servers NOT IN) fails
--          with MySQL error #1267 "Illegal mix of collations".
-- Tables : rack_servers (column collation change)
-- Notes  : rack_servers holds no rows at this point, so the column
--          change is safe. Re-running is harmless (idempotent in effect
--          — applying the same collation again is a no-op).
-- Feature: Rack View (follow-up to 2026_06_17_001).
-- ============================================================

ALTER TABLE `rack_servers`
  MODIFY `config_uuid` VARCHAR(36)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    NOT NULL COMMENT 'FK (logical) -> server_configurations.config_uuid';

-- ------------------------------------------------------------
-- Verification (optional):
--   SELECT COLUMN_NAME, COLLATION_NAME
--     FROM information_schema.COLUMNS
--    WHERE TABLE_NAME = 'rack_servers' AND COLUMN_NAME = 'config_uuid';
--   -- expect: utf8mb4_unicode_ci (matches server_configurations.config_uuid)
-- ------------------------------------------------------------
