-- =============================================================================
-- Seeder:  2026_07_22_005_ram-inventory-add-asset-list-stock.sql
-- Date:    2026-07-22
-- Purpose: Add real inventory rows (one row per physical stick) for the 13-item
--          RAM section of the "ims Details.pdf" / asset-list spreadsheet.
--          Catalog entries for all 13 models were already added to
--          ims-data/ram/ram_detail.json on 2026-07-18 (see
--          tasks/component-catalog-additions.md, rows 24-36); quantities/inventory
--          rows were explicitly deferred at that time as a separate follow-up,
--          which this seeder now completes. No serial numbers were present on
--          the source sheet for any RAM row.
-- Affected tables: raminventory
-- Related feature: component-catalog-additions (2026-07-18/22)
--
-- Rules applied (consistent with 2026_07_19_001 / 2026_07_22_003):
--   - One row per physical stick; quantity is implicit in row count, never stored.
--   - Status=1 / status_v2='available' -- new stock, not installed. ServerUUID NULL.
--   - Location/RackPosition/PurchaseDate/WarrantyEndDate unknown from the source
--     sheet and left NULL. NO FABRICATED DATA.
--   - SerialNumber is NULL (not ''), since none were given -- '' would collide
--     under the SerialNumber UNIQUE index after the first row (2026_07_22_002).
--   - No natural key exists to dedupe serial-less rows, so each unit carries a
--     DISTINCT marker in Notes and is guarded by its own NOT EXISTS check (same
--     pattern as 2026_07_22_003), making this safe to re-run.
--   - Final step assigns AssetTag (BDC-RAM-nnnnnn) to any untagged row.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. Samsung 32GB 2Rx4 PC4-2133P-RA0-10-DC0 (RDIMM) -- uuid e6f7a8b9-c0d1-4e2f-9a3b-4c5d6e7f8a9b -- qty 8
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'e6f7a8b9-c0d1-4e2f-9a3b-4c5d6e7f8a9b', NULL, 1, 'available',
       CONCAT('Samsung-32GB-RA0-10-DC0 unit ', n, '/8 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('Samsung-32GB-RA0-10-DC0 unit ', seq.n, '/8 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 2. Samsung 32GB 4DRx4 PC4-2133P-LD0-10-DC0 (LRDIMM) -- uuid cad300bf-04cb-4486-9ba6-82fa1a02a051 -- qty 2
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'cad300bf-04cb-4486-9ba6-82fa1a02a051', NULL, 1, 'available',
       CONCAT('Samsung-32GB-LD0-10-DC0 unit ', n, '/2 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('Samsung-32GB-LD0-10-DC0 unit ', seq.n, '/2 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 3. Samsung 32GB 2Rx4 PC4-2133P-RA0-10-MC0 (RDIMM) -- uuid eb9cbdd8-8a18-4f0f-b02f-b54df5214067 -- qty 4
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'eb9cbdd8-8a18-4f0f-b02f-b54df5214067', NULL, 1, 'available',
       CONCAT('Samsung-32GB-RA0-10-MC0 unit ', n, '/4 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('Samsung-32GB-RA0-10-MC0 unit ', seq.n, '/4 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 4. DDR4(PaniPat) 32GB 2Rx8 PC4-3200AA-RE4-12 (RDIMM) -- uuid f9af22eb-aa5e-4bad-878a-450121dad407 -- qty 2
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'f9af22eb-aa5e-4bad-878a-450121dad407', NULL, 1, 'available',
       CONCAT('DDR4PaniPat-32GB-RE4-12 unit ', n, '/2 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('DDR4PaniPat-32GB-RE4-12 unit ', seq.n, '/2 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 5. Micron 32GB 2Rx4 PC4-2133P-RBB-10 (RDIMM) -- uuid 680804aa-c33e-42b0-8276-5e27df5a56fc -- qty 6
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT '680804aa-c33e-42b0-8276-5e27df5a56fc', NULL, 1, 'available',
       CONCAT('Micron-32GB-RBB-10 unit ', n, '/6 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('Micron-32GB-RBB-10 unit ', seq.n, '/6 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 6. Samsung 64GB 2S2Rx4 PC4-2133P-RA1-10-DC0 (RDIMM) -- uuid 84688042-ee7c-4201-a1b3-be91e3199ac8 -- qty 4
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT '84688042-ee7c-4201-a1b3-be91e3199ac8', NULL, 1, 'available',
       CONCAT('Samsung-64GB-RA1-10-DC0 unit ', n, '/4 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('Samsung-64GB-RA1-10-DC0 unit ', seq.n, '/4 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 7. SK hynix 4GB 1Rx8 PC4-2133P-RD0-10 (RDIMM) -- uuid 1e6e9de8-c4e9-407b-b12e-eccfeb15cd52 -- qty 2
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT '1e6e9de8-c4e9-407b-b12e-eccfeb15cd52', NULL, 1, 'available',
       CONCAT('SKhynix-4GB-RD0-10 unit ', n, '/2 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('SKhynix-4GB-RD0-10 unit ', seq.n, '/2 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 8. SK hynix (Korea) 32GB 2Rx4 PC4-2400T-RB2-11 (RDIMM) -- uuid 5435d3eb-46ad-4448-ba2b-1df1c7c2195c -- qty 17
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT '5435d3eb-46ad-4448-ba2b-1df1c7c2195c', NULL, 1, 'available',
       CONCAT('SKhynixKorea-32GB-RB2-11 unit ', n, '/17 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8
        UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15
        UNION SELECT 16 UNION SELECT 17) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('SKhynixKorea-32GB-RB2-11 unit ', seq.n, '/17 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 9. Samsung 32GB 4DRx4 PCA-2133P-LD0-10-MB1 (LRDIMM) -- uuid 8965973b-fde1-4a33-9d8a-c1b3f2b2497a -- qty 9
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT '8965973b-fde1-4a33-9d8a-c1b3f2b2497a', NULL, 1, 'available',
       CONCAT('Samsung-32GB-LD0-10-MB1 unit ', n, '/9 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8
        UNION SELECT 9) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('Samsung-32GB-LD0-10-MB1 unit ', seq.n, '/9 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 10. Kingston 32GB 2Rx4 PC4-2400T-RB2-11 (RDIMM) -- uuid 2eb62360-5fd2-4e32-b05a-5c6c34c44c8c -- qty 6
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT '2eb62360-5fd2-4e32-b05a-5c6c34c44c8c', NULL, 1, 'available',
       CONCAT('Kingston-32GB-RB2-11 unit ', n, '/6 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('Kingston-32GB-RB2-11 unit ', seq.n, '/6 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 11. SK hynix (Korea) 32GB 4Rx4 PCA-2133P-LD0-10 (LRDIMM) -- uuid 40e7d2a3-5612-4aff-95a8-92fa83d50673 -- qty 5
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT '40e7d2a3-5612-4aff-95a8-92fa83d50673', NULL, 1, 'available',
       CONCAT('SKhynixKorea-32GB-4Rx4-LD0-10 unit ', n, '/5 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('SKhynixKorea-32GB-4Rx4-LD0-10 unit ', seq.n, '/5 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 12. SK hynix (China) 32GB 2Rx4 PC4-2400T-RB1-11 (RDIMM) -- uuid f3e7e56c-c22b-4ac2-a7e3-006249bcf4e4 -- qty 3
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'f3e7e56c-c22b-4ac2-a7e3-006249bcf4e4', NULL, 1, 'available',
       CONCAT('SKhynixChina-32GB-RB1-11 unit ', n, '/3 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('SKhynixChina-32GB-RB1-11 unit ', seq.n, '/3 - no serial on source sheet (seeder 2026_07_22_005)'));

-- -----------------------------------------------------------------------------
-- 13. Samsung 32GB 2Rx4 PCA-2400T-RA1-11-DC0 (RDIMM) -- uuid 365e9b22-7b17-4585-a291-901fef858c3a -- qty 4
-- -----------------------------------------------------------------------------
INSERT INTO `raminventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT '365e9b22-7b17-4585-a291-901fef858c3a', NULL, 1, 'available',
       CONCAT('Samsung-32GB-RA1-11-DC0 unit ', n, '/4 - no serial on source sheet (seeder 2026_07_22_005)'), NOW(), NOW()
  FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4) seq
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `raminventory`) AS e
  WHERE e.`Notes` = CONCAT('Samsung-32GB-RA1-11-DC0 unit ', seq.n, '/4 - no serial on source sheet (seeder 2026_07_22_005)'));


-- -----------------------------------------------------------------------------
-- 14. Issue asset tags to the new rows (identical formula to 2026_07_22_001/003,
--     scoped to untagged rows only -- safe to re-run).
-- -----------------------------------------------------------------------------
UPDATE `raminventory`
   SET `AssetTag` = CONCAT('BDC-RAM-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;


-- =============================================================================
-- VERIFICATION
-- Expected total new rows = 72 (8+2+4+2+6+4+2+17+9+6+5+3+4), all with AssetTag set.
-- =============================================================================
SELECT `UUID`, COUNT(*) AS units, SUM(`AssetTag` IS NULL) AS untagged
  FROM `raminventory`
 WHERE `UUID` IN (
   'e6f7a8b9-c0d1-4e2f-9a3b-4c5d6e7f8a9b', 'cad300bf-04cb-4486-9ba6-82fa1a02a051',
   'eb9cbdd8-8a18-4f0f-b02f-b54df5214067', 'f9af22eb-aa5e-4bad-878a-450121dad407',
   '680804aa-c33e-42b0-8276-5e27df5a56fc', '84688042-ee7c-4201-a1b3-be91e3199ac8',
   '1e6e9de8-c4e9-407b-b12e-eccfeb15cd52', '5435d3eb-46ad-4448-ba2b-1df1c7c2195c',
   '8965973b-fde1-4a33-9d8a-c1b3f2b2497a', '2eb62360-5fd2-4e32-b05a-5c6c34c44c8c',
   '40e7d2a3-5612-4aff-95a8-92fa83d50673', 'f3e7e56c-c22b-4ac2-a7e3-006249bcf4e4',
   '365e9b22-7b17-4585-a291-901fef858c3a'
 )
 GROUP BY `UUID`;
