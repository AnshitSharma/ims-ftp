-- =============================================================================
-- rollback/2026_09_05_001_noida-office-ram-intake_rollback.sql
--
-- Date:     2026-09-05
-- Purpose:  Remove the 45 Noida Office RAM units created by seeder
--           2026_09_05_001 -- for use if a model was matched to the wrong
--           catalog UUID and the batch has to be re-taken.
-- Tables:   raminventory
--
-- =============================================================================
-- This is REAL hardware, so undoing the intake means the modules are back to
-- being untracked. Only run it to redo the batch, not to "clean up".
--
-- A unit that has since been built into a server is NOT deleted: that would
-- leave the configuration pointing at a stick that no longer exists. Check
-- first, and pull those out through the UI before re-running:
--
--   SELECT ID, AssetTag, UUID, Status, ServerUUID
--     FROM raminventory
--    WHERE Notes LIKE 'INTAKE 2026\_09\_05\_001%' AND ServerUUID IS NOT NULL;
--
-- A unit whose real serial has since been recorded is also left alone -- once
-- someone has read a serial off the module, that row is worth more than the
-- batch it arrived in. Rescue those by hand if the batch is genuinely wrong.
-- =============================================================================

DELETE FROM `raminventory`
 WHERE `Notes` LIKE 'INTAKE 2026\_09\_05\_001%'
   AND `ServerUUID` IS NULL
   AND `SerialNumber` IS NULL
   AND `Status` = 1;


-- Verification:
--   SELECT COUNT(*) FROM raminventory WHERE Notes LIKE 'INTAKE 2026\_09\_05\_001%';
--   -- expected: 0, or exactly the units still installed / since serialised
