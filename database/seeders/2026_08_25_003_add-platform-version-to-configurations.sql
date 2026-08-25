-- =============================================================================
-- 2026_08_25_003_add-platform-version-to-configurations.sql
--
-- Date:     2026-08-25
-- Purpose:  Record WHICH VERSION of a compute platform a server was built from,
--           and clear the platform stamps left over from the retired model.
-- Tables:   server_configurations
-- Feature:  Server Compute Platform rebuild -- tasks/todo.md
--
-- Run AFTER 2026_08_25_002.
--
-- =============================================================================
-- WHY
--
--   `platform_uuid` / `platform_name` (seeder 2026_08_18_001) were added when a
--   platform was a GROUPING over the motherboard catalog: the stamp was a label,
--   applied to a build whose board had been picked from loose stock.
--
--   A platform is now a stocked box that ships in versions, and the version --
--   not the platform -- is what was physically installed. `platform_version_uuid`
--   is the load-bearing column: it names the exact SKU, it is what the board and
--   chassis specs are resolved from, and its presence is what makes those two
--   components LOCKED (the lock is derived, never stored).
--
-- =============================================================================
-- ON CLEARING THE EXISTING STAMPS (section 2)
--
--   Every current platform_uuid refers to the retired grouping model and has no
--   version behind it. Left in place it would render as "this build is an HPE
--   ProLiant DL360 Gen10" on a config whose board came off a shelf, with nothing
--   locked and no box consumed -- a claim the system can no longer stand behind.
--
--   Clearing it loses NOTHING else: every component stays installed, every
--   inventory row keeps its ServerUUID. Those builds simply become what they
--   always were -- custom builds, which remain fully supported.
--
--   Measure the blast radius before running:
--     SELECT COUNT(*) FROM server_configurations WHERE platform_uuid IS NOT NULL;
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. platform_version_uuid
--
--    Native IF NOT EXISTS (MariaDB 10.0.2+), deliberately NOT an
--    information_schema-guarded block: the application DB user on this host
--    cannot read that schema, and such a guard dies at PREPARE. Same reasoning
--    as seeder 2026_08_18_001.
-- -----------------------------------------------------------------------------
ALTER TABLE `server_configurations`
  ADD COLUMN IF NOT EXISTS `platform_version_uuid` varchar(36) DEFAULT NULL
    COMMENT 'Installed platform VERSION uuid = serverplatforminventory.UUID; its presence locks the board and chassis'
    AFTER `platform_uuid`;

ALTER TABLE `server_configurations`
  ADD INDEX IF NOT EXISTS `idx_server_configurations_platform_version` (`platform_version_uuid`);


-- -----------------------------------------------------------------------------
-- 2. Retire the stamps from the old grouping model.
--
--    Scoped to rows that have no version, so re-running this file after real
--    platforms have been installed cannot wipe them.
-- -----------------------------------------------------------------------------
UPDATE `server_configurations`
   SET `platform_uuid` = NULL,
       `platform_name` = NULL
 WHERE `platform_version_uuid` IS NULL
   AND (`platform_uuid` IS NOT NULL OR `platform_name` IS NOT NULL);


-- =============================================================================
-- Verification (run after the seeder):
--
--   SHOW COLUMNS FROM server_configurations LIKE 'platform\_%';
--   -- expected: platform_uuid, platform_version_uuid, platform_name
--
--   SELECT COUNT(*) AS stale_stamps
--     FROM server_configurations
--    WHERE platform_version_uuid IS NULL AND platform_uuid IS NOT NULL;
--   -- expected: 0
-- =============================================================================
