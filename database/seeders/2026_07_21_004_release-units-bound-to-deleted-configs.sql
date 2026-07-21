-- =============================================================================
-- 2026_07_21_004_release-units-bound-to-deleted-configs.sql
--
-- Date:     2026-07-21
-- Purpose:  Release inventory units whose ServerUUID points at a server
--           configuration that no longer exists. They are stuck Status = 2
--           (in_use) and can never be selected for a build again.
-- Tables:   all ten {type}inventory tables
-- Feature:  delete-config guard follow-up (tasks/todo.md, 2026-07-21)
--
-- BACKGROUND
--   Found while verifying the delete-config guard against production. Five HBA
--   card units are bound to three configs that are gone:
--
--     hbacardinventory #1  (no serial)          -> 89d8bbc0-…
--     hbacardinventory #2  (no serial)          -> 40934b44-…
--     hbacardinventory #23 BC9300-16I-004       -> 40934b44-…
--     hbacardinventory #24 BC9300-8I-005        -> 40934b44-…
--     hbacardinventory #21 BC9500-16I-001       -> 214100e3-…
--
--   Pre-existing, and not caused by the current work: `deleteConfiguration()`
--   used to release components by decoding the config JSON, and hbacard entries
--   there carried no serial, so `updateComponentStatusAndServerUuid()` could not
--   identify the physical unit. The release path is ServerUUID-driven now, so
--   new deletes do not leak — but these five predate that and nothing frees them.
--
--   The matching live-path defect (removeComponent() silently not releasing
--   motherboard/chassis/hbacard, because no caller supplied a serial and those
--   types carry none in the config JSON) is fixed in ServerBuilder::removeComponent()
--   the same day, via the same ServerUUID fallback this seeder relies on.
--
-- SCOPE: only units whose ServerUUID names a config_uuid absent from
--   server_configurations. A unit bound to a config that still exists is left
--   alone even if that config no longer lists it — that direction needs a human
--   decision about what is physically in the machine, not a blanket UPDATE.
--
-- IDEMPOTENT: re-running matches nothing, since ServerUUID is NULL afterwards.
--
-- COLLATIONS: ServerUUID is not one type across the ten tables —
--   chassisinventory        latin1  / latin1_swedish_ci
--   nicinventory, sfpinventory       utf8mb4 / utf8mb4_unicode_ci
--   the other seven                  utf8mb4 / utf8mb4_general_ci
--   server_configurations.config_uuid utf8mb4 / utf8mb4_unicode_ci
-- Comparing them raw throws #1267, and a bare COLLATE throws #1253 on the latin1
-- column. CONVERT(... USING utf8mb4) COLLATE utf8mb4_unicode_ci is the one form
-- that works for all three — both errors were hit for real writing this file.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 0. INSPECT FIRST (read-only) — expect the five rows listed above
-- -----------------------------------------------------------------------------
--   SELECT 'hbacard' AS type, i.ID, i.SerialNumber, i.Status, i.ServerUUID
--     FROM hbacardinventory i
--    WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
--      AND NOT EXISTS (SELECT 1 FROM server_configurations sc
--                       WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci)
--   UNION ALL SELECT 'motherboard', i.ID, i.SerialNumber, i.Status, i.ServerUUID
--     FROM motherboardinventory i
--    WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
--      AND NOT EXISTS (SELECT 1 FROM server_configurations sc
--                       WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci);
--   -- (repeat per table as needed; the UPDATEs below cover all ten)

-- -----------------------------------------------------------------------------
-- 1. Release orphaned units, one statement per inventory table
--    Status 1 = available; status_v2 mirrors it per
--    StatusMap::INVENTORY_LEGACY_TO_V2 (1 => 'available').
-- -----------------------------------------------------------------------------
UPDATE cpuinventory i
   SET i.Status = 1, i.status_v2 = 'available', i.ServerUUID = NULL,
       i.InstallationDate = NULL, i.UpdatedAt = NOW()
 WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
   AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci);

UPDATE raminventory i
   SET i.Status = 1, i.status_v2 = 'available', i.ServerUUID = NULL,
       i.InstallationDate = NULL, i.UpdatedAt = NOW()
 WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
   AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci);

UPDATE storageinventory i
   SET i.Status = 1, i.status_v2 = 'available', i.ServerUUID = NULL,
       i.InstallationDate = NULL, i.UpdatedAt = NOW()
 WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
   AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci);

UPDATE motherboardinventory i
   SET i.Status = 1, i.status_v2 = 'available', i.ServerUUID = NULL,
       i.InstallationDate = NULL, i.UpdatedAt = NOW()
 WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
   AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci);

UPDATE nicinventory i
   SET i.Status = 1, i.status_v2 = 'available', i.ServerUUID = NULL,
       i.InstallationDate = NULL, i.UpdatedAt = NOW()
 WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
   AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci);

UPDATE caddyinventory i
   SET i.Status = 1, i.status_v2 = 'available', i.ServerUUID = NULL,
       i.InstallationDate = NULL, i.UpdatedAt = NOW()
 WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
   AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci);

UPDATE chassisinventory i
   SET i.Status = 1, i.status_v2 = 'available', i.ServerUUID = NULL,
       i.InstallationDate = NULL, i.UpdatedAt = NOW()
 WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
   AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci);

UPDATE pciecardinventory i
   SET i.Status = 1, i.status_v2 = 'available', i.ServerUUID = NULL,
       i.InstallationDate = NULL, i.UpdatedAt = NOW()
 WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
   AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci);

UPDATE hbacardinventory i
   SET i.Status = 1, i.status_v2 = 'available', i.ServerUUID = NULL,
       i.InstallationDate = NULL, i.UpdatedAt = NOW()
 WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
   AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci);

UPDATE sfpinventory i
   SET i.Status = 1, i.status_v2 = 'available', i.ServerUUID = NULL,
       i.InstallationDate = NULL, i.UpdatedAt = NOW()
 WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID <> '' AND i.ServerUUID <> 'null'
   AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci);

-- =============================================================================
-- VERIFY
--
--   -- expect 0 across every table: no unit bound to a config that doesn't exist
--   SELECT SUM(n) AS orphaned_units FROM (
--     SELECT COUNT(*) n FROM cpuinventory         i WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID NOT IN ('', 'null') AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci)
--     UNION ALL SELECT COUNT(*) FROM raminventory        i WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID NOT IN ('', 'null') AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci)
--     UNION ALL SELECT COUNT(*) FROM storageinventory     i WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID NOT IN ('', 'null') AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci)
--     UNION ALL SELECT COUNT(*) FROM motherboardinventory i WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID NOT IN ('', 'null') AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci)
--     UNION ALL SELECT COUNT(*) FROM nicinventory         i WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID NOT IN ('', 'null') AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci)
--     UNION ALL SELECT COUNT(*) FROM caddyinventory       i WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID NOT IN ('', 'null') AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci)
--     UNION ALL SELECT COUNT(*) FROM chassisinventory     i WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID NOT IN ('', 'null') AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci)
--     UNION ALL SELECT COUNT(*) FROM pciecardinventory    i WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID NOT IN ('', 'null') AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci)
--     UNION ALL SELECT COUNT(*) FROM hbacardinventory     i WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID NOT IN ('', 'null') AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci)
--     UNION ALL SELECT COUNT(*) FROM sfpinventory         i WHERE i.ServerUUID IS NOT NULL AND i.ServerUUID NOT IN ('', 'null') AND NOT EXISTS (SELECT 1 FROM server_configurations sc WHERE sc.config_uuid = CONVERT(i.ServerUUID USING utf8mb4) COLLATE utf8mb4_unicode_ci)
--   ) t;
--
--   -- the five HBA cards should now read Status = 1, ServerUUID NULL
--   SELECT ID, SerialNumber, Status, status_v2, ServerUUID FROM hbacardinventory WHERE ID IN (1, 2, 21, 23, 24);
-- =============================================================================
