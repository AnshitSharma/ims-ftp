-- =============================================================================
-- 2026_09_05_001_noida-office-ram-intake.sql
--
-- Date:     2026-09-05
-- Purpose:  Take 45 physical RAM modules at Noida Office onto the books, from
--           the asset sheet (11 distinct models, quantities 1-9 each).
-- Tables:   raminventory (reads locations)
-- Feature:  Inventory intake -- ad hoc, no task file
--
-- =============================================================================
-- THIS IS REAL STOCK, NOT SEEDED TEST DATA.
--
--   Every UUID below was matched to an existing entry in ims-data/ram/
--   ram_detail.json by brand + module label, and each one was confirmed present
--   in the DEPLOYED catalog on 2026-09-05. No catalog entry needs to be added
--   and nothing needs uploading to /ims-data/ before this runs.
--
--   Sheet row -> catalog match (Size/Version columns agree in every case):
--     Samsung   32GB LRDIMM 4DRx4 PC4-2133P-LD0-10-DC0   x9  cad300bf-...
--     Samsung   32GB RDIMM  2Rx4  PC4-2133P-RA0-10-MC0   x1  eb9cbdd8-...
--     PaniPat   32GB RDIMM  2Rx8  PC4-3200AA-RE4-12      x2  f9af22eb-...
--     Micron    32GB RDIMM  2Rx4  PC4-2133P-RBB-10       x6  680804aa-...
--     Samsung   64GB RDIMM  2S2Rx4 PC4-2133P-RA1-10-DC0  x4  84688042-...
--     SK hynix   4GB RDIMM  1Rx8  PC4-2133P-RD0-10       x2  1e6e9de8-...
--     SK hynix (Korea) 32GB RDIMM  2Rx4 PC4-2400T-RB2-11 x3  5435d3eb-...
--     Kingston  32GB RDIMM  2Rx4  PC4-2400T-RB2-11       x6  2eb62360-...
--     SK hynix (Korea) 32GB LRDIMM 4Rx4 PCA-2133P-LD0-10 x5  40e7d2a3-...
--     SK hynix (China) 32GB RDIMM 2Rx4 PC4-2400T-RB1-11  x3  f3e7e56c-...
--     Samsung   32GB RDIMM  2Rx4  PCA-2400T-RA1-11-DC0   x4  365e9b22-...
--                                                       --- 45 units
--
-- =============================================================================
-- SERIAL NUMBERS
--
--   The sheet's Serial Number column is empty for all 11 rows, so every unit
--   lands with SerialNumber NULL and is addressed by its system-issued
--   AssetTag (BDC-RAM-nnnnnn) instead -- the serial-less-stock path the builder
--   and handover flows already support. Do NOT invent placeholder serials: the
--   column carries a UNIQUE index and a fake value there would later block the
--   real serial from being recorded.
--
--   As the real serials are read off the modules, set them one at a time with
--   the edit form (the AssetTag identifies which physical stick is which).
--
-- =============================================================================
-- IDEMPOTENCY
--
--   There is no natural unique key here (no serials), so the batch marks
--   itself in Notes and the INSERT is guarded by a probe for that marker: run
--   this file twice and the second run inserts 0 rows. The guard is a
--   materialized derived table (the LIMIT 1 blocks derived-table merge), which
--   is what keeps MariaDB from rejecting a read of the INSERT's own target.
--
--   AssetTag is set by the follow-up UPDATE rather than inside the INSERT,
--   because BaseFunctions::formatAssetTag() derives it from the row's own
--   AUTO_INCREMENT id (BDC-RAM-%06d) -- a value that does not exist until the
--   row does. Same two-step as seeder 2026_08_25_004.
--
--   location_uuid is resolved by name from `locations` rather than pasted, so
--   the file cannot staple stock to a stale site id. 'Noida Office' was
--   confirmed live and active on 2026-09-05. If that row ever went missing the
--   subquery yields NULL and the units still carry the Location text.
-- =============================================================================

INSERT INTO `raminventory`
  (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Location`, `location_uuid`, `Notes`)
SELECT
  sheet.uuid,
  NULL,
  1,
  'available',
  'Noida Office',
  (SELECT l.`location_uuid` FROM `locations` l WHERE l.`name` = 'Noida Office' LIMIT 1),
  CONCAT('INTAKE 2026_09_05_001 - Noida Office asset sheet - ', sheet.descr)
FROM (
            SELECT 'cad300bf-04cb-4486-9ba6-82fa1a02a051' AS uuid, 9 AS qty, 'Samsung 32GB LRDIMM 4DRx4 PC4-2133P-LD0-10-DC0'    AS descr
  UNION ALL SELECT 'eb9cbdd8-8a18-4f0f-b02f-b54df5214067',       1,       'Samsung 32GB RDIMM 2Rx4 PC4-2133P-RA0-10-MC0'
  UNION ALL SELECT 'f9af22eb-aa5e-4bad-878a-450121dad407',       2,       'DDR4(PaniPat) 32GB RDIMM 2Rx8 PC4-3200AA-RE4-12'
  UNION ALL SELECT '680804aa-c33e-42b0-8276-5e27df5a56fc',       6,       'Micron 32GB RDIMM 2Rx4 PC4-2133P-RBB-10'
  UNION ALL SELECT '84688042-ee7c-4201-a1b3-be91e3199ac8',       4,       'Samsung 64GB RDIMM 2S2Rx4 PC4-2133P-RA1-10-DC0'
  UNION ALL SELECT '1e6e9de8-c4e9-407b-b12e-eccfeb15cd52',       2,       'SK hynix 4GB RDIMM 1Rx8 PC4-2133P-RD0-10'
  UNION ALL SELECT '5435d3eb-46ad-4448-ba2b-1df1c7c2195c',       3,       'SK hynix (Korea) 32GB RDIMM 2Rx4 PC4-2400T-RB2-11'
  UNION ALL SELECT '2eb62360-5fd2-4e32-b05a-5c6c34c44c8c',       6,       'Kingston 32GB RDIMM 2Rx4 PC4-2400T-RB2-11'
  UNION ALL SELECT '40e7d2a3-5612-4aff-95a8-92fa83d50673',       5,       'SK hynix (Korea) 32GB LRDIMM 4Rx4 PCA-2133P-LD0-10'
  UNION ALL SELECT 'f3e7e56c-c22b-4ac2-a7e3-006249bcf4e4',       3,       'SK hynix (China) 32GB RDIMM 2Rx4 PC4-2400T-RB1-11'
  UNION ALL SELECT '365e9b22-7b17-4585-a291-901fef858c3a',       4,       'Samsung 32GB RDIMM 2Rx4 PCA-2400T-RA1-11-DC0'
) AS sheet
JOIN (
            SELECT 1 AS n
  UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
  UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
) AS seq
  ON seq.n <= sheet.qty
CROSS JOIN (
  SELECT COUNT(*) AS already_run FROM (
    SELECT 1 FROM `raminventory` WHERE `Notes` LIKE 'INTAKE 2026\_09\_05\_001%' LIMIT 1
  ) AS probe
) AS guard
WHERE guard.already_run = 0;


-- Asset tags, derived from each row's own id exactly as formatAssetTag() does.
UPDATE `raminventory`
   SET `AssetTag` = CONCAT('BDC-RAM-', LPAD(`ID`, 6, '0'))
 WHERE `Notes` LIKE 'INTAKE 2026\_09\_05\_001%'
   AND `AssetTag` IS NULL;


-- =============================================================================
-- Verification (run after the seeder):
--
--   SELECT COUNT(*) FROM raminventory WHERE Notes LIKE 'INTAKE 2026\_09\_05\_001%';
--   -- expected: 45
--
--   SELECT UUID, COUNT(*) AS units
--     FROM raminventory
--    WHERE Notes LIKE 'INTAKE 2026\_09\_05\_001%'
--    GROUP BY UUID ORDER BY units DESC;
--   -- expected: 11 rows -- 9,6,6,5,4,4,3,3,2,2,1
--
--   SELECT COUNT(*) FROM raminventory
--    WHERE Notes LIKE 'INTAKE 2026\_09\_05\_001%'
--      AND (AssetTag IS NULL OR location_uuid IS NULL OR Status <> 1);
--   -- expected: 0
--
--   SELECT ID, AssetTag, UUID, Status, status_v2, Location
--     FROM raminventory WHERE Notes LIKE 'INTAKE 2026\_09\_05\_001%' LIMIT 5;
-- =============================================================================
