-- =============================================================================
-- ROLLBACK for 2026_08_26_003_add-location-uuid-columns.sql
-- Date:     2026-08-26
-- Purpose:  Remove location_uuid / floor / StoreLocation from the 14 tables that
--           gained them, putting the schema back to free-text-only locations.
--
-- Tables:   racks (2 columns), server_configurations (1), and all 12
--           {type}inventory tables (2 each) -- 27 columns DROPPED in total
--
-- ============================ READ THIS FIRST ================================
--
-- THIS IS THE FIRST ROLLBACK TO RUN in the set (005, 004, then THIS, then 002,
--   then 001). It is the file that unhooks everything else; running 001's or
--   002's rollback before it leaves these columns pointing at rows that no
--   longer exist.
--
-- WHAT IS ACTUALLY LOST
--   * `floor` -- gone for good. It was never mirrored anywhere. Export it:
--       SELECT name, floor FROM racks WHERE floor IS NOT NULL;
--   * `StoreLocation` -- gone for good. The shelf/bin text for loose stock has
--       no other home. Export it (repeat per inventory table):
--       SELECT ID, AssetTag, StoreLocation FROM cpuinventory WHERE StoreLocation IS NOT NULL;
--   * `location_uuid` -- recoverable. The free-text `location` / `Location`
--       columns were never dropped and are kept in sync by
--       LocationResolver::syncConfig(), so re-running seeder 003 rebuilds every
--       link from the text. This is exactly why the text columns were kept.
--
-- THE CODE SURVIVES THE DROP, BY DESIGN. Every read and write of these columns
--   goes through SchemaHelper::hasColumn() first, so with them absent
--   LocationResolver falls back to writing only the legacy text columns and the
--   API answers with the location fields omitted. The PHP does NOT have to be
--   reverted first and nothing 500s in between -- the system behaves as it did
--   before this feature shipped.
--
-- SECTION 1 IS THE SAFE STOP -- it clears the links without dropping the
--   columns, so the feature goes quiet and everything is one UPDATE from coming
--   back. Section 2 is the destructive half and is deliberately separate.
--
-- No information_schema: MariaDB 10.11 native DROP COLUMN IF EXISTS throughout.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. KEEP THIS OUTPUT -- floor and StoreLocation exist nowhere
--    else once section 2 runs.
-- ---------------------------------------------------------------------------
SELECT `name` AS rack, `location`, `location_uuid`, `floor` FROM `racks` ORDER BY `name`;

SELECT 'racks'                 AS tbl, COUNT(*) AS linked FROM `racks`                 WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'server_configurations',   COUNT(*) FROM `server_configurations`   WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'cpuinventory',            COUNT(*) FROM `cpuinventory`            WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'raminventory',            COUNT(*) FROM `raminventory`            WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'storageinventory',        COUNT(*) FROM `storageinventory`        WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'motherboardinventory',    COUNT(*) FROM `motherboardinventory`    WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'nicinventory',            COUNT(*) FROM `nicinventory`            WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'caddyinventory',          COUNT(*) FROM `caddyinventory`          WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'chassisinventory',        COUNT(*) FROM `chassisinventory`        WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'pciecardinventory',       COUNT(*) FROM `pciecardinventory`       WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'risercardinventory',      COUNT(*) FROM `risercardinventory`      WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'hbacardinventory',        COUNT(*) FROM `hbacardinventory`        WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'sfpinventory',            COUNT(*) FROM `sfpinventory`            WHERE `location_uuid` IS NOT NULL
UNION ALL SELECT 'serverplatforminventory', COUNT(*) FROM `serverplatforminventory` WHERE `location_uuid` IS NOT NULL;

-- Everything that would lose its shelf text.
SELECT 'cpuinventory' AS tbl, COUNT(*) AS with_store_location FROM `cpuinventory` WHERE `StoreLocation` IS NOT NULL
UNION ALL SELECT 'raminventory',            COUNT(*) FROM `raminventory`            WHERE `StoreLocation` IS NOT NULL
UNION ALL SELECT 'storageinventory',        COUNT(*) FROM `storageinventory`        WHERE `StoreLocation` IS NOT NULL
UNION ALL SELECT 'motherboardinventory',    COUNT(*) FROM `motherboardinventory`    WHERE `StoreLocation` IS NOT NULL
UNION ALL SELECT 'nicinventory',            COUNT(*) FROM `nicinventory`            WHERE `StoreLocation` IS NOT NULL
UNION ALL SELECT 'caddyinventory',          COUNT(*) FROM `caddyinventory`          WHERE `StoreLocation` IS NOT NULL
UNION ALL SELECT 'chassisinventory',        COUNT(*) FROM `chassisinventory`        WHERE `StoreLocation` IS NOT NULL
UNION ALL SELECT 'pciecardinventory',       COUNT(*) FROM `pciecardinventory`       WHERE `StoreLocation` IS NOT NULL
UNION ALL SELECT 'risercardinventory',      COUNT(*) FROM `risercardinventory`      WHERE `StoreLocation` IS NOT NULL
UNION ALL SELECT 'hbacardinventory',        COUNT(*) FROM `hbacardinventory`        WHERE `StoreLocation` IS NOT NULL
UNION ALL SELECT 'sfpinventory',            COUNT(*) FROM `sfpinventory`            WHERE `StoreLocation` IS NOT NULL
UNION ALL SELECT 'serverplatforminventory', COUNT(*) FROM `serverplatforminventory` WHERE `StoreLocation` IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 1. SAFE STOP -- clear the links, keep the columns. Re-running seeder 003
--    restores every one of them from the surviving text. Uncomment to use.
-- ---------------------------------------------------------------------------
-- UPDATE `racks`                 SET `location_uuid` = NULL, `floor` = NULL;
-- UPDATE `server_configurations` SET `location_uuid` = NULL;
-- UPDATE `cpuinventory`            SET `location_uuid` = NULL;
-- UPDATE `raminventory`            SET `location_uuid` = NULL;
-- UPDATE `storageinventory`        SET `location_uuid` = NULL;
-- UPDATE `motherboardinventory`    SET `location_uuid` = NULL;
-- UPDATE `nicinventory`            SET `location_uuid` = NULL;
-- UPDATE `caddyinventory`          SET `location_uuid` = NULL;
-- UPDATE `chassisinventory`        SET `location_uuid` = NULL;
-- UPDATE `pciecardinventory`       SET `location_uuid` = NULL;
-- UPDATE `risercardinventory`      SET `location_uuid` = NULL;
-- UPDATE `hbacardinventory`        SET `location_uuid` = NULL;
-- UPDATE `sfpinventory`            SET `location_uuid` = NULL;
-- UPDATE `serverplatforminventory` SET `location_uuid` = NULL;

-- ---------------------------------------------------------------------------
-- 2. DESTRUCTIVE -- drop the columns. Indexes go with them automatically.
--    Uncomment the whole block only when you have exported section 0's output.
-- ---------------------------------------------------------------------------
-- ALTER TABLE `racks`
--   DROP COLUMN IF EXISTS `location_uuid`,
--   DROP COLUMN IF EXISTS `floor`;
--
-- ALTER TABLE `server_configurations`
--   DROP COLUMN IF EXISTS `location_uuid`;
--
-- ALTER TABLE `cpuinventory`            DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;
-- ALTER TABLE `raminventory`            DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;
-- ALTER TABLE `storageinventory`        DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;
-- ALTER TABLE `motherboardinventory`    DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;
-- ALTER TABLE `nicinventory`            DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;
-- ALTER TABLE `caddyinventory`          DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;
-- ALTER TABLE `chassisinventory`        DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;
-- ALTER TABLE `pciecardinventory`       DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;
-- ALTER TABLE `risercardinventory`      DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;
-- ALTER TABLE `hbacardinventory`        DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;
-- ALTER TABLE `sfpinventory`            DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;
-- ALTER TABLE `serverplatforminventory` DROP COLUMN IF EXISTS `location_uuid`, DROP COLUMN IF EXISTS `StoreLocation`;

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. After section 2: neither column remains. MUST return nothing.
SHOW COLUMNS FROM `racks`        LIKE 'location_uuid';
SHOW COLUMNS FROM `racks`        LIKE 'floor';
SHOW COLUMNS FROM `cpuinventory` LIKE 'location_uuid';
SHOW COLUMNS FROM `cpuinventory` LIKE 'StoreLocation';

-- 2. The free-text columns are untouched either way -- this is what the system
--    falls back to. Expect the same values as before the rollback.
SELECT `name` AS rack, `location` FROM `racks` ORDER BY `name`;
