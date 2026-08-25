-- =============================================================================
-- ROLLBACK for 2026_08_26_002_backfill-locations-from-text.sql
-- Date:     2026-08-26
-- Purpose:  Remove the location rows that the backfill created, leaving any
--           location added by hand since then untouched.
--
-- Tables:   locations (rows DELETEd)
--
-- ============================ READ THIS FIRST ================================
--
-- RUN 003's ROLLBACK FIRST. Seeder 003 pointed 14 tables at these rows. Deleting
--   them while those links exist leaves dangling location_uuids -- not an error
--   (there are no foreign keys), but every affected rack, server and component
--   will render "No location" with no way to tell what it used to say.
--
-- WHAT "the backfill created" MEANS
--   Seeder 002 stamped every row it created with a description beginning
--   'Imported 2026-08-26 from: '. That prefix is the ONLY marker distinguishing
--   an imported row from one a person typed on the Locations page, so this file
--   matches on it. If somebody edited an imported row's description in the UI,
--   the prefix is gone and this rollback will correctly leave that row alone --
--   it is no longer purely machine-generated.
--
-- IMPORTED ROWS ARE CHEAP TO RECREATE, which is why this one is not gated
--   behind a commented-out section: re-running seeder 002 rebuilds them from
--   the same free-text columns, as long as those columns still exist. Rows with
--   a hand-entered address or coordinates are NOT recreated -- but those rows
--   no longer carry the prefix and are therefore not deleted here either.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. KEEP THIS OUTPUT. Rows marked imported=1 are about to go.
-- ---------------------------------------------------------------------------
SELECT `id`, `location_uuid`, `name`, `description`, `address`, `latitude`, `longitude`,
       CASE WHEN `description` LIKE 'Imported 2026-08-26 from: %' THEN 1 ELSE 0 END AS imported
  FROM `locations`
 ORDER BY imported DESC, `name`;

-- How many rows still point at a location that is about to be deleted.
-- Anything above 0 means you should run 003's rollback first.
SELECT COUNT(*) AS racks_pointing_at_imported
  FROM `racks` r JOIN `locations` l ON l.`location_uuid` = r.`location_uuid`
 WHERE l.`description` LIKE 'Imported 2026-08-26 from: %';

SELECT COUNT(*) AS servers_pointing_at_imported
  FROM `server_configurations` s JOIN `locations` l ON l.`location_uuid` = s.`location_uuid`
 WHERE l.`description` LIKE 'Imported 2026-08-26 from: %';

-- ---------------------------------------------------------------------------
-- 1. Delete only the machine-generated rows.
-- ---------------------------------------------------------------------------
START TRANSACTION;

DELETE FROM `locations`
 WHERE `description` LIKE 'Imported 2026-08-26 from: %';

COMMIT;

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. No imported row remains. MUST return 0.
SELECT COUNT(*) AS imported_rows_left
  FROM `locations` WHERE `description` LIKE 'Imported 2026-08-26 from: %';

-- 2. What survived -- everything here was created or edited by a person.
SELECT `id`, `name`, `description` FROM `locations` ORDER BY `name`;
