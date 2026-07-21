-- =============================================================================
-- Seeder:  2026_07_22_003_seed-serial-less-asset-list-stock.sql
-- Date:    2026-07-22
-- Purpose: Add the physical units that earlier asset-list seeders SKIPPED
--          ENTIRELY because the source sheet gave no manufacturer serial. With
--          AssetTag in place a unit no longer needs a serial to be represented,
--          so this stock can finally enter inventory.
--
--          12 real units, absent from the system since 2026-07-18:
--
--            3 x Kingston KC600 2TB SATA SSD
--                -> storageinventory, uuid 58f9bfb0-dfe8-4833-ac47-9222de9cbcc4
--                -> skipped by 2026_07_18_001 (see its line 70)
--
--            9 x Generic PCIe x16 Quad M.2 NVMe Adapter (4-Port)
--                -> pciecardinventory, uuid d6a67469-e3e5-4741-9824-3e9092136a65
--                -> skipped by 2026_07_19_001 (see its line 24)
--
-- Affected tables: storageinventory, pciecardinventory
-- Related feature: asset tag unit identity (tasks/asset-tag-unit-identity.md)
--
-- =============================================================================
-- !! PREREQUISITE !!
--   Run AFTER 2026_07_22_001 (AssetTag columns) -- this seeder's final step
--   assigns tags to the new rows using that column.
--
--   Phase 2 backend code does NOT need to be deployed for this seeder: it writes
--   SerialNumber as a real SQL NULL directly, never ''. (Phase 2 matters for
--   rows created through the UI, where the form submits ''.)
-- =============================================================================
--
-- Rules applied (consistent with 2026_07_18_001 / 2026_07_19_001):
--   - One row per physical unit; quantity is implicit in row count, never stored.
--   - Status=1 / status_v2='available' -- new stock, not installed. ServerUUID NULL.
--   - Location/RackPosition/PurchaseDate/WarrantyEndDate unknown from the source
--     sheet and left NULL. NO FABRICATED DATA.
--   - SerialNumber is NULL, not ''. MySQL UNIQUE permits many NULLs but only one
--     '', so '' would let unit 1 insert and make unit 2 fail on a duplicate key.
--     Each unit is instead addressable by the AssetTag assigned at the foot.
--
-- Idempotency: INSERT IGNORE cannot help here -- it dedupes against a UNIQUE key,
--   and these rows have no serial to key on. Each unit therefore carries a
--   DISTINCT marker in Notes and is guarded by its own NOT EXISTS check, so
--   re-running inserts nothing. (The inner SELECT is wrapped in a derived table
--   because MySQL forbids referencing the INSERT target directly in a subquery.)
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. Kingston KC600 2TB SATA SSD -- 3 units
-- -----------------------------------------------------------------------------

INSERT INTO `storageinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT '58f9bfb0-dfe8-4833-ac47-9222de9cbcc4', NULL, 1, 'available',
       'KC600-2TB unit 1/3 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `storageinventory`) AS e
   WHERE e.`Notes` = 'KC600-2TB unit 1/3 - no serial on source sheet (seeder 2026_07_22_003)');

INSERT INTO `storageinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT '58f9bfb0-dfe8-4833-ac47-9222de9cbcc4', NULL, 1, 'available',
       'KC600-2TB unit 2/3 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `storageinventory`) AS e
   WHERE e.`Notes` = 'KC600-2TB unit 2/3 - no serial on source sheet (seeder 2026_07_22_003)');

INSERT INTO `storageinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT '58f9bfb0-dfe8-4833-ac47-9222de9cbcc4', NULL, 1, 'available',
       'KC600-2TB unit 3/3 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `storageinventory`) AS e
   WHERE e.`Notes` = 'KC600-2TB unit 3/3 - no serial on source sheet (seeder 2026_07_22_003)');


-- -----------------------------------------------------------------------------
-- 2. Generic PCIe x16 Quad M.2 NVMe Adapter (4-Port) -- 9 units
--
--    NOTE for later: this card requires a PCIe x16 slot with 4x4x4x4
--    bifurcation support (per its ims-data spec). Once these units start being
--    placed into configurations, that constraint is the compatibility engine's
--    to enforce -- it is not this seeder's business, but it is the reason nine
--    of these existing suddenly matters.
-- -----------------------------------------------------------------------------

INSERT INTO `pciecardinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'd6a67469-e3e5-4741-9824-3e9092136a65', NULL, 1, 'available',
       'QuadM2-4Port unit 1/9 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `pciecardinventory`) AS e
   WHERE e.`Notes` = 'QuadM2-4Port unit 1/9 - no serial on source sheet (seeder 2026_07_22_003)');

INSERT INTO `pciecardinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'd6a67469-e3e5-4741-9824-3e9092136a65', NULL, 1, 'available',
       'QuadM2-4Port unit 2/9 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `pciecardinventory`) AS e
   WHERE e.`Notes` = 'QuadM2-4Port unit 2/9 - no serial on source sheet (seeder 2026_07_22_003)');

INSERT INTO `pciecardinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'd6a67469-e3e5-4741-9824-3e9092136a65', NULL, 1, 'available',
       'QuadM2-4Port unit 3/9 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `pciecardinventory`) AS e
   WHERE e.`Notes` = 'QuadM2-4Port unit 3/9 - no serial on source sheet (seeder 2026_07_22_003)');

INSERT INTO `pciecardinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'd6a67469-e3e5-4741-9824-3e9092136a65', NULL, 1, 'available',
       'QuadM2-4Port unit 4/9 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `pciecardinventory`) AS e
   WHERE e.`Notes` = 'QuadM2-4Port unit 4/9 - no serial on source sheet (seeder 2026_07_22_003)');

INSERT INTO `pciecardinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'd6a67469-e3e5-4741-9824-3e9092136a65', NULL, 1, 'available',
       'QuadM2-4Port unit 5/9 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `pciecardinventory`) AS e
   WHERE e.`Notes` = 'QuadM2-4Port unit 5/9 - no serial on source sheet (seeder 2026_07_22_003)');

INSERT INTO `pciecardinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'd6a67469-e3e5-4741-9824-3e9092136a65', NULL, 1, 'available',
       'QuadM2-4Port unit 6/9 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `pciecardinventory`) AS e
   WHERE e.`Notes` = 'QuadM2-4Port unit 6/9 - no serial on source sheet (seeder 2026_07_22_003)');

INSERT INTO `pciecardinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'd6a67469-e3e5-4741-9824-3e9092136a65', NULL, 1, 'available',
       'QuadM2-4Port unit 7/9 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `pciecardinventory`) AS e
   WHERE e.`Notes` = 'QuadM2-4Port unit 7/9 - no serial on source sheet (seeder 2026_07_22_003)');

INSERT INTO `pciecardinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'd6a67469-e3e5-4741-9824-3e9092136a65', NULL, 1, 'available',
       'QuadM2-4Port unit 8/9 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `pciecardinventory`) AS e
   WHERE e.`Notes` = 'QuadM2-4Port unit 8/9 - no serial on source sheet (seeder 2026_07_22_003)');

INSERT INTO `pciecardinventory` (`UUID`,`SerialNumber`,`Status`,`status_v2`,`Notes`,`CreatedAt`,`UpdatedAt`)
SELECT 'd6a67469-e3e5-4741-9824-3e9092136a65', NULL, 1, 'available',
       'QuadM2-4Port unit 9/9 - no serial on source sheet (seeder 2026_07_22_003)', NOW(), NOW()
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT `Notes` FROM `pciecardinventory`) AS e
   WHERE e.`Notes` = 'QuadM2-4Port unit 9/9 - no serial on source sheet (seeder 2026_07_22_003)');


-- -----------------------------------------------------------------------------
-- 3. Issue asset tags to the new rows.
--
--    Identical formula to 2026_07_22_001, scoped to rows that have no tag yet,
--    so it is safe to re-run and safe if some rows were already tagged.
-- -----------------------------------------------------------------------------

UPDATE `storageinventory`
   SET `AssetTag` = CONCAT('BDC-STO-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;

UPDATE `pciecardinventory`
   SET `AssetTag` = CONCAT('BDC-PCI-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;


-- =============================================================================
-- VERIFICATION
--
-- Expected: kc600_units = 3, quad_m2_units = 9, and untagged = 0 on both rows.
-- These 12 tags are what gets written on the physical stickers.
-- =============================================================================
SELECT 'storageinventory' AS table_name,
       SUM(`UUID` = '58f9bfb0-dfe8-4833-ac47-9222de9cbcc4') AS kc600_units,
       NULL                                                 AS quad_m2_units,
       SUM(`AssetTag` IS NULL)                              AS untagged
  FROM `storageinventory`
UNION ALL
SELECT 'pciecardinventory',
       NULL,
       SUM(`UUID` = 'd6a67469-e3e5-4741-9824-3e9092136a65'),
       SUM(`AssetTag` IS NULL)
  FROM `pciecardinventory`;

-- The tags to sticker onto the twelve units:
SELECT 'KC600 2TB' AS model, `ID`, `AssetTag`, `Notes`
  FROM `storageinventory`
 WHERE `UUID` = '58f9bfb0-dfe8-4833-ac47-9222de9cbcc4'
UNION ALL
SELECT 'Quad M.2 4-Port', `ID`, `AssetTag`, `Notes`
  FROM `pciecardinventory`
 WHERE `UUID` = 'd6a67469-e3e5-4741-9824-3e9092136a65'
 ORDER BY 1, 2;
