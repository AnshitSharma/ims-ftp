-- ============================================================
-- Rollback for: 2026_08_18_002_seed-platform-board-stock
--
-- Removes the 34 FICTIONAL motherboard units seeded so the platform picker had
-- selectable boards. Run this before the inventory is trusted as a record of real
-- hardware.
--
-- Refuses to delete a unit that has since been installed in a server
-- (Status = 2 / ServerUUID set) — if that happened, the unit is load-bearing for
-- some configuration and must be removed from that build first.
-- ============================================================

-- What would go (review before deleting):
SELECT `ID`, `AssetTag`, `SerialNumber`, `UUID`, `Status`, `ServerUUID`
  FROM `motherboardinventory`
 WHERE `Flag` = 'Seeded'
   AND `Notes` LIKE 'SEEDED 2026\_08\_18\_002%';

DELETE FROM `motherboardinventory`
 WHERE `Flag` = 'Seeded'
   AND `Notes` LIKE 'SEEDED 2026\_08\_18\_002%'
   AND `Status` = 1
   AND `ServerUUID` IS NULL;

-- Anything still listed by this query was in use and was deliberately NOT deleted:
SELECT `ID`, `AssetTag`, `SerialNumber`, `Status`, `ServerUUID`
  FROM `motherboardinventory`
 WHERE `Flag` = 'Seeded'
   AND `Notes` LIKE 'SEEDED 2026\_08\_18\_002%';
