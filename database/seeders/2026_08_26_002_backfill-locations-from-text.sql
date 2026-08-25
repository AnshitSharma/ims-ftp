-- =============================================================================
-- Date:     2026-08-26
-- Purpose:  Turn every free-text location string that exists today into a real
--           `locations` row, so nothing is lost when seeder 003 replaces the
--           text with a foreign key.
--
--           Four sources are harvested:
--             1. racks.location                  -- live: 'Noida', 'Noida Yotta'
--             2. server_configurations.location  -- live: 'Noida Ctrls', ...
--             3. {type}inventory.Location        -- all 12 inventory tables
--             4. the six sites hardcoded in the frontend dropdowns
--                (dashboard.js Create Server + Bulk Update,
--                 pages/forms/add-component.html) -- these are the options
--                users have been picking from, so they must exist as rows or
--                the new dropdown would be missing choices people already use.
--
-- Tables:   locations (INSERT only)
-- Feature:  Location hierarchy + server relocation, part 2 of 5
-- Requires: 2026_08_26_001 (the locations table + its UNIQUE name key).
--
-- NOTHING IS RENAMED OR MERGED HERE, ON PURPOSE.
--   'Noida', 'Noida Yotta' and 'Noida Ctrls' may well be the same building, or
--   three different ones. This file cannot know, and guessing wrong would
--   silently relocate real hardware. Every distinct string becomes its own row;
--   merging duplicates is a two-click job on the new Locations page, done by
--   someone who knows the sites. Over-creating is reversible. Merging wrongly
--   is not.
--
-- WHY A TEMPORARY TABLE
--   The names come from 14 different tables. Collecting them first means the
--   dedupe happens once, in one place, and the final INSERT reads only the temp
--   table -- never `locations` inside a subquery against itself (error 1093).
--
-- Notes:    - Comparison is case-insensitive and whitespace-trimmed because the
--             column collation is utf8mb4_general_ci; 'noida' and 'Noida  ' are
--             therefore one row, not three.
--           - UUID() is evaluated per row by MariaDB, so each location gets its
--             own identifier.
--           - description records where the name was found, so the operator can
--             see why a row exists while tidying up. Safe to overwrite in the UI.
--
-- Idempotent: names already present are removed from the staging table before
--             the INSERT, and INSERT IGNORE guards the UNIQUE key as well.
--             Re-running creates nothing and changes nothing.
-- Rollback:   rollback/2026_08_26_002_backfill-locations-from-text_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state -- how many locations exist now (expected 0 on first run).
-- ---------------------------------------------------------------------------
SELECT COUNT(*) AS locations_before FROM `locations`;

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. Collect every distinct non-empty location string in the system.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `_loc_names`;
CREATE TEMPORARY TABLE `_loc_names` (
  `name`   VARCHAR(100) NOT NULL,
  `source` VARCHAR(64)  NOT NULL
) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 1a. racks
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`location`), 'racks'
  FROM `racks` WHERE `location` IS NOT NULL AND TRIM(`location`) <> '';

-- 1b. server configurations
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`location`), 'servers'
  FROM `server_configurations` WHERE `location` IS NOT NULL AND TRIM(`location`) <> '';

-- 1c. all twelve inventory tables
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `cpuinventory`             WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `raminventory`             WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `storageinventory`         WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `motherboardinventory`     WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `nicinventory`             WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `caddyinventory`           WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `chassisinventory`         WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `pciecardinventory`        WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `risercardinventory`       WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `hbacardinventory`         WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `sfpinventory`             WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';
INSERT INTO `_loc_names` (`name`, `source`)
SELECT DISTINCT TRIM(`Location`), 'inventory' FROM `serverplatforminventory`  WHERE `Location` IS NOT NULL AND TRIM(`Location`) <> '';

-- 1d. the six sites the frontend has been offering. Listed explicitly because
--     an option nobody has picked yet exists in no table, and dropping it would
--     silently remove a choice users expect to see.
INSERT INTO `_loc_names` (`name`, `source`) VALUES
  ('Noida Yotta',    'frontend dropdown'),
  ('Noida Ctrls',    'frontend dropdown'),
  ('Noida Office',   'frontend dropdown'),
  ('Jaipur Office',  'frontend dropdown'),
  ('Indore Office',  'frontend dropdown'),
  ('Sonipat Office', 'frontend dropdown');

-- ---------------------------------------------------------------------------
-- 2. Show the operator exactly what was harvested, and from where, BEFORE any
--    row is created. Names that appear in several sources are listed once.
-- ---------------------------------------------------------------------------
SELECT `name`,
       GROUP_CONCAT(DISTINCT `source` ORDER BY `source` SEPARATOR ', ') AS found_in
  FROM `_loc_names`
 GROUP BY `name`
 ORDER BY `name`;

-- ---------------------------------------------------------------------------
-- 3. Drop names that already have a row, then create the rest.
-- ---------------------------------------------------------------------------
DELETE n FROM `_loc_names` n
JOIN `locations` l ON l.`name` = n.`name`;

INSERT IGNORE INTO `locations` (`location_uuid`, `name`, `description`, `is_active`, `created_at`, `updated_at`)
SELECT UUID(),
       `name`,
       CONCAT('Imported 2026-08-26 from: ',
              GROUP_CONCAT(DISTINCT `source` ORDER BY `source` SEPARATOR ', ')),
       1,
       NOW(),
       NOW()
  FROM `_loc_names`
 GROUP BY `name`;

DROP TEMPORARY TABLE IF EXISTS `_loc_names`;

COMMIT;

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. Every location now on file. Expect at least the six frontend sites plus
--    'Noida' and 'Noida Yotta' from the live racks, deduped by name.
SELECT `id`, `location_uuid`, `name`, `description`, `is_active`
  FROM `locations` ORDER BY `name`;

-- 2. No rack location string is left without a matching row. MUST return 0.
SELECT COUNT(*) AS unmatched_rack_locations
  FROM `racks` r
 WHERE r.`location` IS NOT NULL AND TRIM(r.`location`) <> ''
   AND NOT EXISTS (SELECT 1 FROM `locations` l WHERE l.`name` = TRIM(r.`location`));

-- 3. Same for server configurations. MUST return 0.
SELECT COUNT(*) AS unmatched_server_locations
  FROM `server_configurations` s
 WHERE s.`location` IS NOT NULL AND TRIM(s.`location`) <> ''
   AND NOT EXISTS (SELECT 1 FROM `locations` l WHERE l.`name` = TRIM(s.`location`));

-- 4. Same for the largest inventory table. MUST return 0.
SELECT COUNT(*) AS unmatched_cpu_locations
  FROM `cpuinventory` c
 WHERE c.`Location` IS NOT NULL AND TRIM(c.`Location`) <> ''
   AND NOT EXISTS (SELECT 1 FROM `locations` l WHERE l.`name` = TRIM(c.`Location`));
