-- =============================================================================
-- Rollback for: 2026_09_03_003_rack-enclosures.sql
-- Date:         2026-09-03
--
-- Undoes the blade-enclosure schema: drops rack_enclosures and the two
-- horizontal-axis columns on rack_servers.
--
-- DESTRUCTIVE, AND NOT SYMMETRIC. The forward seeder adds capacity and touches
-- no existing data, so it is safe to re-run. This is not: any server that has
-- been SLOTTED into an enclosure loses the fact that it was, and every
-- enclosure record -- name, service tag, position -- is gone for good. The
-- sleds themselves survive as DIRECT placements at the U range they mirrored
-- from their enclosure, which means several of them will then share a U range
-- that the application layer would refuse to create. Rack View will render
-- them overlapping until they are moved apart by hand.
--
-- So: run this only if the feature is being abandoned before any enclosure has
-- been created. Check first, and unrack anything it finds:
--
--   SELECT COUNT(*) FROM rack_enclosures;
--   SELECT config_uuid, enclosure_uuid, slot_index, start_u, u_height
--     FROM rack_servers WHERE enclosure_uuid IS NOT NULL;
--
-- No ACL rows to remove -- the forward seeder created none, reusing rack.edit
-- and rack.assign.
-- =============================================================================

ALTER TABLE `rack_servers` DROP INDEX IF EXISTS `uq_rack_servers_slot`;

ALTER TABLE `rack_servers` DROP COLUMN IF EXISTS `slot_index`;
ALTER TABLE `rack_servers` DROP COLUMN IF EXISTS `enclosure_uuid`;

DROP TABLE IF EXISTS `rack_enclosures`;

-- Verification: both columns and the table are gone.
SHOW COLUMNS FROM `rack_servers`;
SHOW TABLES LIKE 'rack_enclosures';
