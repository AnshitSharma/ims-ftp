-- =============================================================================
-- rollback/2026_08_25_004_seed-serverplatform-stock_rollback.sql
--
-- Date:     2026-08-25
-- Purpose:  Remove the FICTIONAL platform units created by seeder
--           2026_08_25_004, so the inventory becomes a record of real hardware.
-- Tables:   serverplatforminventory
--
-- =============================================================================
-- A seeded unit that has since been built into a server is NOT deleted: doing so
-- would leave that configuration pointing at a box that no longer exists, and
-- the fact that someone built on it is information worth keeping. Check first:
--
--   SELECT ID, AssetTag, SerialNumber, Status, ServerUUID
--     FROM serverplatforminventory
--    WHERE SerialNumber LIKE 'SEED-SPF-%' AND ServerUUID IS NOT NULL;
--
--   Remove those servers' platforms through the UI (or server-remove-platform)
--   first, then re-run this file.
-- =============================================================================

DELETE FROM `serverplatforminventory`
 WHERE `SerialNumber` LIKE 'SEED-SPF-%'
   AND `Flag` = 'Seeded'
   AND `ServerUUID` IS NULL
   AND `Status` = 1;


-- Verification:
--   SELECT COUNT(*) FROM serverplatforminventory WHERE Flag = 'Seeded';
--   -- expected: 0, or exactly the units still installed in a server
