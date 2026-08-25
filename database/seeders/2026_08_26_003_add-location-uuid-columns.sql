-- =============================================================================
-- Date:     2026-08-26
-- Purpose:  Replace the free-text location strings with a real link to
--           `locations`, and give a rack a floor.
--
--           After this file:
--             racks                  -> location_uuid, floor
--             server_configurations  -> location_uuid
--             {type}inventory (x12)  -> location_uuid, StoreLocation
--
--           A component's physical address is then answerable by JOIN:
--             {type}inventory.ServerUUID -> server_configurations.config_uuid
--             -> rack_servers -> racks -> locations
--           and for loose stock, directly from its own location_uuid +
--           StoreLocation shelf text.
--
-- Tables:   racks, server_configurations, and all 12 {type}inventory tables
-- Feature:  Location hierarchy + server relocation, part 3 of 5
-- Requires: 2026_08_26_001 (locations table) and 2026_08_26_002 (the rows this
--           file links to). Running 003 without 002 leaves every location_uuid
--           NULL -- not broken, but pointless. Run them in order.
--
-- THE OLD TEXT COLUMNS ARE KEPT, AND KEEP BEING WRITTEN
--   racks.location, server_configurations.location and {type}inventory.Location
--   are NOT dropped. Dozens of existing readers use them, and freezing them
--   read-only would just preserve today's stale-data bug under a new name.
--   From now on LocationResolver::syncConfig() rewrites them from the real
--   placement on every move, so the text is a synced CACHE of the join above
--   rather than an independently-authored value. Truth is the FK; the text
--   agrees with it.
--
-- WHY StoreLocation IS SEPARATE FROM RackPosition
--   RackPosition is varchar(20) and holds a U-range ("U16-U17") for a component
--   installed in a racked server. A component sitting on a shelf has no U, and
--   overloading the column would make "U16-U17" and "Shelf B3" indistinguishable
--   to every query that parses it. StoreLocation is where loose stock lives;
--   RackPosition stays exactly what it has always been.
--
-- WHY location_uuid IS DENORMALISED ONTO INVENTORY
--   The address of an INSTALLED component is derivable, so this column is
--   redundant for it -- and it is still written, kept in sync on every move,
--   because "show me everything at Jaipur" then reads one indexed column
--   instead of a four-table join across twelve tables. For LOOSE stock it is
--   not redundant at all: it is the only place the stock location can live.
--
-- Notes:    - MariaDB native `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT
--             EXISTS`. information_schema is NOT used anywhere: the production
--             DB user has no grant on it, so guarded DDL against it both fails
--             outright AND fails open (see 2026_08_25_007).
--           - All 14 tables are utf8mb4_general_ci, the same collation as
--             locations.name, so the backfill JOINs need no COLLATE clause.
--           - The backfill matches on TRIM(text) = locations.name, which is
--             exactly the rule seeder 002 used to create the rows, so every
--             non-empty value finds its row. Empty strings stay NULL.
--
-- Idempotent: every ALTER is IF NOT EXISTS; every UPDATE is an absolute
--             assignment matched on the text, never a toggle. Re-running
--             changes nothing.
-- Rollback:   rollback/2026_08_26_003_add-location-uuid-columns_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state -- what will be linked. Compare these counts with the
--    verification block at the bottom.
-- ---------------------------------------------------------------------------
SELECT 'racks'    AS tbl, COUNT(*) AS rows_with_location_text FROM `racks`
 WHERE `location` IS NOT NULL AND TRIM(`location`) <> ''
UNION ALL
SELECT 'server_configurations', COUNT(*) FROM `server_configurations`
 WHERE `location` IS NOT NULL AND TRIM(`location`) <> ''
UNION ALL
SELECT 'cpuinventory', COUNT(*) FROM `cpuinventory`
 WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';

-- ---------------------------------------------------------------------------
-- 1. racks -- the anchor of the hierarchy. floor lives here rather than as its
--    own entity: a floor has no attributes of its own worth managing, and every
--    query that wants it already has the rack in hand.
-- ---------------------------------------------------------------------------
ALTER TABLE `racks`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL
      COMMENT 'Logical FK -> locations.location_uuid. Supersedes the location text.' AFTER `location`,
  ADD COLUMN IF NOT EXISTS `floor` VARCHAR(50) DEFAULT NULL
      COMMENT 'Floor / room within the location, e.g. "2" or "DC-1"' AFTER `location_uuid`,
  ADD INDEX IF NOT EXISTS `idx_racks_location` (`location_uuid`);

-- ---------------------------------------------------------------------------
-- 2. server_configurations -- for a RACKED server this is kept equal to its
--    rack's location; for an unracked one it is the only thing that says where
--    the server is (staging room, bench, in transit).
-- ---------------------------------------------------------------------------
ALTER TABLE `server_configurations`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL
      COMMENT 'Logical FK -> locations.location_uuid. Derived from the rack when racked.' AFTER `location`,
  ADD INDEX IF NOT EXISTS `idx_server_configurations_location` (`location_uuid`);

-- ---------------------------------------------------------------------------
-- 3. All twelve inventory tables.
-- ---------------------------------------------------------------------------
ALTER TABLE `cpuinventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_cpuinventory_location` (`location_uuid`);

ALTER TABLE `raminventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_raminventory_location` (`location_uuid`);

ALTER TABLE `storageinventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_storageinventory_location` (`location_uuid`);

ALTER TABLE `motherboardinventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_motherboardinventory_location` (`location_uuid`);

ALTER TABLE `nicinventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_nicinventory_location` (`location_uuid`);

ALTER TABLE `caddyinventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_caddyinventory_location` (`location_uuid`);

ALTER TABLE `chassisinventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_chassisinventory_location` (`location_uuid`);

ALTER TABLE `pciecardinventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_pciecardinventory_location` (`location_uuid`);

ALTER TABLE `risercardinventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_risercardinventory_location` (`location_uuid`);

ALTER TABLE `hbacardinventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_hbacardinventory_location` (`location_uuid`);

ALTER TABLE `sfpinventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_sfpinventory_location` (`location_uuid`);

ALTER TABLE `serverplatforminventory`
  ADD COLUMN IF NOT EXISTS `location_uuid` CHAR(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid' AFTER `Location`,
  ADD COLUMN IF NOT EXISTS `StoreLocation` VARCHAR(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"' AFTER `RackPosition`,
  ADD INDEX IF NOT EXISTS `idx_serverplatforminventory_location` (`location_uuid`);

-- ---------------------------------------------------------------------------
-- 4. Backfill -- link every row to the location whose name matches the text it
--    already carries. Same TRIM rule seeder 002 used to create the rows.
--    Rows with an empty or unmatched string keep location_uuid NULL, which the
--    application renders as "No location" -- exactly today's behaviour.
-- ---------------------------------------------------------------------------
START TRANSACTION;

UPDATE `racks` r JOIN `locations` l ON l.`name` = TRIM(r.`location`)
   SET r.`location_uuid` = l.`location_uuid`
 WHERE r.`location` IS NOT NULL AND TRIM(r.`location`) <> '';

UPDATE `server_configurations` s JOIN `locations` l ON l.`name` = TRIM(s.`location`)
   SET s.`location_uuid` = l.`location_uuid`
 WHERE s.`location` IS NOT NULL AND TRIM(s.`location`) <> '';

UPDATE `cpuinventory`            c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';
UPDATE `raminventory`            c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';
UPDATE `storageinventory`        c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';
UPDATE `motherboardinventory`    c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';
UPDATE `nicinventory`            c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';
UPDATE `caddyinventory`          c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';
UPDATE `chassisinventory`        c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';
UPDATE `pciecardinventory`       c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';
UPDATE `risercardinventory`      c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';
UPDATE `hbacardinventory`        c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';
UPDATE `sfpinventory`            c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';
UPDATE `serverplatforminventory` c JOIN `locations` l ON l.`name` = TRIM(c.`Location`) SET c.`location_uuid` = l.`location_uuid` WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> '';

COMMIT;

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. Every rack that had location text now has a location_uuid.
--    MUST return 0 unlinked.
SELECT COUNT(*) AS racks_with_text_but_no_link
  FROM `racks`
 WHERE `location` IS NOT NULL AND TRIM(`location`) <> '' AND `location_uuid` IS NULL;

-- 2. The live racks, resolved. Expect both rows to name a location.
SELECT r.`name` AS rack, r.`floor`, r.`location` AS legacy_text, l.`name` AS location
  FROM `racks` r LEFT JOIN `locations` l ON l.`location_uuid` = r.`location_uuid`
 ORDER BY r.`name`;

-- 3. Same check for server configurations. MUST return 0.
SELECT COUNT(*) AS servers_with_text_but_no_link
  FROM `server_configurations`
 WHERE `location` IS NOT NULL AND TRIM(`location`) <> '' AND `location_uuid` IS NULL;

-- 4. Same for the inventory. MUST return 0 for every table listed.
SELECT 'cpuinventory' AS tbl, COUNT(*) AS unlinked FROM `cpuinventory` WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL
UNION ALL SELECT 'raminventory',            COUNT(*) FROM `raminventory`            WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL
UNION ALL SELECT 'storageinventory',        COUNT(*) FROM `storageinventory`        WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL
UNION ALL SELECT 'motherboardinventory',    COUNT(*) FROM `motherboardinventory`    WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL
UNION ALL SELECT 'nicinventory',            COUNT(*) FROM `nicinventory`            WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL
UNION ALL SELECT 'caddyinventory',          COUNT(*) FROM `caddyinventory`          WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL
UNION ALL SELECT 'chassisinventory',        COUNT(*) FROM `chassisinventory`        WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL
UNION ALL SELECT 'pciecardinventory',       COUNT(*) FROM `pciecardinventory`       WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL
UNION ALL SELECT 'risercardinventory',      COUNT(*) FROM `risercardinventory`      WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL
UNION ALL SELECT 'hbacardinventory',        COUNT(*) FROM `hbacardinventory`        WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL
UNION ALL SELECT 'sfpinventory',            COUNT(*) FROM `sfpinventory`            WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL
UNION ALL SELECT 'serverplatforminventory', COUNT(*) FROM `serverplatforminventory` WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '' AND `location_uuid` IS NULL;

-- 5. The new columns exist on a representative inventory table.
--    MUST list location_uuid and StoreLocation.
SHOW COLUMNS FROM `cpuinventory` LIKE '%ocation%';

-- 6. What each location now holds. This is the "Objects" column of the
--    Locations page, computed the same way.
SELECT l.`name` AS location,
       (SELECT COUNT(*) FROM `racks` r WHERE r.`location_uuid` = l.`location_uuid`) AS racks,
       (SELECT COUNT(*) FROM `server_configurations` s WHERE s.`location_uuid` = l.`location_uuid`) AS servers,
       (SELECT COUNT(*) FROM `cpuinventory` c WHERE c.`location_uuid` = l.`location_uuid`) AS cpus
  FROM `locations` l ORDER BY l.`name`;
