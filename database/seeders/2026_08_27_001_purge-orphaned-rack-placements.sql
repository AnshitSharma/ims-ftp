-- ============================================================
-- Seeder : 2026_08_27_001_purge-orphaned-rack-placements
-- Date   : 2026-08-27
-- Purpose: Clear rack placements whose server no longer exists.
--
--          `rack_servers.config_uuid` is a LOGICAL foreign key only -- the
--          table was created (seeder 2026_06_17_001) with just
--          UNIQUE(config_uuid), no real constraint against
--          server_configurations -- and until today's fix,
--          ServerBuilder::deleteConfiguration() deleted the config and its
--          dual-write children but never the placement.
--
--          So every server deleted while racked left its row behind. Rack View
--          LEFT JOINs the config (rack_api.php handleRackGet) and renders the
--          miss as "(deleted server)", and worse, keeps counting that U toward
--          used_u / free_u -- a slot nothing in the UI can free, because every
--          rack-unassign path is reached through a server that is gone.
--
--          This deletes those rows. It touches nothing that still resolves to a
--          real configuration, so it is safe to re-run and safe to run before
--          or after the code deploy.
--
-- Tables : rack_servers (rows deleted)
-- Feature: Rack View / server deletion
-- ============================================================

-- Inspect first (optional -- lists what the DELETE below will remove):
-- SELECT rs.id, rs.rack_uuid, rs.config_uuid, rs.start_u, rs.u_height
-- FROM rack_servers rs
-- LEFT JOIN server_configurations sc ON sc.config_uuid = rs.config_uuid
-- WHERE sc.config_uuid IS NULL;

DELETE rs
FROM rack_servers rs
LEFT JOIN server_configurations sc ON sc.config_uuid = rs.config_uuid
WHERE sc.config_uuid IS NULL;

-- Verify: expected 0 rows.
SELECT COUNT(*) AS remaining_orphans
FROM rack_servers rs
LEFT JOIN server_configurations sc ON sc.config_uuid = rs.config_uuid
WHERE sc.config_uuid IS NULL;
