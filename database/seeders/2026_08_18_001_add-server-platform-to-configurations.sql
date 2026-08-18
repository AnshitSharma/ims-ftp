-- =============================================================================
-- Date:     2026-08-18
-- Purpose:  Record which server compute platform a configuration is built on.
--           The builder's platform picker (HPE ProLiant DL360 Gen10 -> one of its
--           system boards) stamps these two columns via `server-set-platform`
--           after the board has been added through `server-add-component`.
--
-- Tables:   server_configurations (2 new columns + 1 index)
-- Feature:  Server compute platform selection (replaces the Import Template
--           entry point in the Server Builder)
--
-- Notes:    - Idempotent via native ADD COLUMN / ADD INDEX IF NOT EXISTS
--             (MariaDB 10.0.2+), same style as 2026_06_11_001. Deliberately NOT
--             information_schema guards: the application DB user has no access to
--             that schema on this host, so guarded seeders die at PREPARE before
--             any ALTER runs.
--           - platform_uuid references ims-data/serverplatform/server-platform-level-3.json;
--             platforms are spec data, not DB rows, so there is no FK and no lookup table.
--           - platform_name is denormalised on purpose: it is the label as stamped at
--             selection time, so renaming a platform in the spec file never rewrites
--             the history of configurations already built.
--           - Existing rows stay NULL. The API infers the platform from the installed
--             motherboard UUID for display, so nothing needs backfilling.
-- =============================================================================

ALTER TABLE `server_configurations`
    ADD COLUMN IF NOT EXISTS `platform_uuid` VARCHAR(36) NULL DEFAULT NULL
        COMMENT 'Server compute platform UUID (ims-data/serverplatform spec)'
        AFTER `chassis_uuid`,
    ADD COLUMN IF NOT EXISTS `platform_name` VARCHAR(150) NULL DEFAULT NULL
        COMMENT 'Platform label as stamped at selection time, e.g. HPE ProLiant DL360 Gen10'
        AFTER `platform_uuid`;

-- "every configuration on platform X" is the expected read
ALTER TABLE `server_configurations`
    ADD INDEX IF NOT EXISTS `idx_server_configurations_platform_uuid` (`platform_uuid`);

-- ---------------------------------------------------------------------------
-- Verification (SHOW needs no information_schema privilege)
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `server_configurations` LIKE 'platform\_%';
