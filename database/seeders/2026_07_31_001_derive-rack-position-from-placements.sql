-- =============================================================================
-- 2026_07_31_001_derive-rack-position-from-placements.sql
--
-- Date:     2026-07-31
-- Purpose:  server_configurations.rack_position used to be free text typed into
--           the Create Server form, unrelated to any real rack placement. It is
--           now DERIVED from rack_servers (RackPlacement::syncPositionText, called
--           on rack-assign-server / rack-unassign-server). This backfills the
--           existing rows so the stored text matches reality:
--             - server placed in a rack -> 'U12' (or 'U12-U13' for multi-U)
--             - server not in any rack   -> NULL (drops stale hand-typed values)
--
-- Tables:   server_configurations (UPDATE), rack_servers (read)
-- Feature:  Rack + position dropdowns in the Create Server form
--
-- Idempotent: yes — re-running recomputes the same values.
-- Both config_uuid columns are utf8mb4_unicode_ci, so the join needs no COLLATE.
-- =============================================================================

-- What will change (run first if you want to eyeball it):
-- SELECT sc.config_uuid, sc.server_name, sc.rack_position AS old_value,
--        CASE WHEN rs.config_uuid IS NULL THEN NULL
--             WHEN rs.u_height > 1 THEN CONCAT('U', rs.start_u, '-U', rs.start_u + rs.u_height - 1)
--             ELSE CONCAT('U', rs.start_u) END AS new_value
-- FROM server_configurations sc
-- LEFT JOIN rack_servers rs ON rs.config_uuid = sc.config_uuid
-- WHERE NOT (sc.rack_position <=> CASE WHEN rs.config_uuid IS NULL THEN NULL
--             WHEN rs.u_height > 1 THEN CONCAT('U', rs.start_u, '-U', rs.start_u + rs.u_height - 1)
--             ELSE CONCAT('U', rs.start_u) END);

UPDATE server_configurations sc
LEFT JOIN rack_servers rs ON rs.config_uuid = sc.config_uuid
SET sc.rack_position = CASE
        WHEN rs.config_uuid IS NULL THEN NULL
        WHEN rs.u_height > 1 THEN CONCAT('U', rs.start_u, '-U', rs.start_u + rs.u_height - 1)
        ELSE CONCAT('U', rs.start_u)
    END;
