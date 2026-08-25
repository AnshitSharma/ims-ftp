-- =============================================================================
-- ROLLBACK for 2026_08_26_001_create-locations-table.sql
-- Date:     2026-08-26
-- Purpose:  Remove the `locations` table and the four location.* permissions.
--
-- Tables:   locations (DROPPED), permissions (4 rows), role_permissions (rows)
--
-- ============================ READ THIS FIRST ================================
--
-- RUN THE ROLLBACKS IN REVERSE ORDER: 005, 004, 003, 002, then this one.
--   Seeder 003 put location_uuid columns on 14 tables that point AT this table.
--   Dropping locations first does not error -- there are no foreign keys -- it
--   just turns every one of those columns into a dangling identifier. Run
--   003's rollback first, or accept that.
--
-- THIS DESTROYS THE LOCATION LIST. Every site name, address and coordinate
--   entered on the Locations page is in this table and nowhere else. Seeder 002
--   can recreate the NAMES from the surviving free-text columns, but addresses,
--   coordinates, descriptions and notes are gone for good. Export first:
--     SELECT * FROM `locations`;
--
-- THE CODE SURVIVES THE DROP. LocationResolver probes every table and column
--   through SchemaHelper::hasColumn() before touching it, so the API keeps
--   answering with location fields absent rather than 500ing. The Locations
--   page will show an error toast; nothing else breaks.
--
-- SECTION 1 IS THE SAFE STOP -- it removes the permissions only, which hides
--   the feature without losing a single row of data. Section 2 is the
--   destructive half and is deliberately separate.
--
-- No information_schema: MariaDB 10.11 native DROP ... IF EXISTS throughout.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. KEEP THIS OUTPUT -- it is the only record of what is lost.
-- ---------------------------------------------------------------------------
SELECT * FROM `locations` ORDER BY `name`;

SELECT COUNT(*) AS racks_pointing_here     FROM `racks`                 WHERE `location_uuid` IS NOT NULL;
SELECT COUNT(*) AS servers_pointing_here   FROM `server_configurations` WHERE `location_uuid` IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 1. SAFE STOP -- revoke and remove the permissions. The table and all its data
--    survive; the module simply becomes unreachable through the API.
-- ---------------------------------------------------------------------------
DELETE rp FROM `role_permissions` rp
JOIN `permissions` p ON p.`id` = rp.`permission_id`
WHERE p.`name` IN ('location.view', 'location.create', 'location.edit', 'location.delete');

DELETE FROM `permissions`
 WHERE `name` IN ('location.view', 'location.create', 'location.edit', 'location.delete');

-- ---------------------------------------------------------------------------
-- 2. DESTRUCTIVE -- drop the table. Uncomment only when you are certain, and
--    only after running 003's rollback.
-- ---------------------------------------------------------------------------
-- DROP TABLE IF EXISTS `locations`;

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. No location.* permission remains. MUST return 0.
SELECT COUNT(*) AS location_permissions_left FROM `permissions` WHERE `name` LIKE 'location.%';

-- 2. And no grant survives them. MUST return 0.
SELECT COUNT(*) AS location_grants_left
  FROM `role_permissions` rp
  JOIN `permissions` p ON p.`id` = rp.`permission_id`
 WHERE p.`name` LIKE 'location.%';

-- 3. Only if section 2 was run: the table is gone. MUST return nothing.
SHOW TABLES LIKE 'locations';
