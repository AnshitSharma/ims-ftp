-- =============================================================================
-- Seeder:  2026_07_22_001_add-asset-tag-columns.sql
-- Date:    2026-07-22
-- Purpose: Introduce AssetTag -- a system-issued, unique, always-populated
--          identifier for each PHYSICAL unit -- across all 10 inventory tables,
--          so that a component with no readable manufacturer serial can still
--          be represented and addressed unambiguously.
--
--          Today SerialNumber does two jobs: it is the unit's identity AND its
--          only human-readable label. The identity job is already served by the
--          table's own auto-increment ID (config_components keys on it via
--          uq_inventory_once). Only the label job actually needs SerialNumber.
--          AssetTag takes over the label job; SerialNumber is then free to be
--          optional and hold genuine manufacturer serials only.
--
--          Concrete cost of not having this: seeder 2026_07_18_001 SKIPPED the
--          Kingston KC600 x3 entirely for want of serials (see its line 70) --
--          three real drives absent from the system. hbacardinventory rows
--          already carry invented serials (BC9400-16E-001, ...), i.e. the
--          workaround is in production but undocumented.
--
-- Affected tables (ADD COLUMN + backfill + ADD UNIQUE KEY, no data destroyed):
--          cpuinventory, raminventory, storageinventory, motherboardinventory,
--          nicinventory, caddyinventory, chassisinventory, pciecardinventory,
--          hbacardinventory, sfpinventory
--
-- Related feature: asset tag unit identity (tasks/asset-tag-unit-identity.md)
--                  Follows the same model-vs-unit lesson as 2026_07_20_001 and
--                  2026_07_21_001 (F-1).
--
-- Tag format: BDC-{TYPE3}-{ID padded to 6}   e.g. BDC-STO-000063
--          Derived from the row's own primary key, so it is unique BY
--          CONSTRUCTION -- no counter table, no race, no collision possible.
--          The application layer uses this identical formula when issuing a tag
--          to a newly inserted row.
--
-- ORDER OF OPERATIONS -- important:
--   1. Run THIS seeder FIRST. The column is additive and unused until the code
--      lands, so running it early is safe and changes no behaviour on its own.
--   2. THEN deploy the Phase 2 code (BaseFunctions::addComponent issuing tags).
--      Deploying that code BEFORE this seeder runs would make every component
--      add fail on an unknown column -- do not reverse these two steps.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS / ADD UNIQUE KEY IF NOT EXISTS (MariaDB)
--          and the backfill is scoped to `WHERE AssetTag IS NULL`. Safe to
--          re-run in full.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. cpuinventory  ->  BDC-CPU-nnnnnn
-- -----------------------------------------------------------------------------
ALTER TABLE `cpuinventory`
  ADD COLUMN IF NOT EXISTS `AssetTag` VARCHAR(20) NULL
  COMMENT 'System-issued unique unit identifier (BDC-CPU-nnnnnn)' AFTER `UUID`;

UPDATE `cpuinventory`
   SET `AssetTag` = CONCAT('BDC-CPU-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;

ALTER TABLE `cpuinventory`
  ADD UNIQUE KEY IF NOT EXISTS `uq_asset_tag` (`AssetTag`);


-- -----------------------------------------------------------------------------
-- 2. raminventory  ->  BDC-RAM-nnnnnn
-- -----------------------------------------------------------------------------
ALTER TABLE `raminventory`
  ADD COLUMN IF NOT EXISTS `AssetTag` VARCHAR(20) NULL
  COMMENT 'System-issued unique unit identifier (BDC-RAM-nnnnnn)' AFTER `UUID`;

UPDATE `raminventory`
   SET `AssetTag` = CONCAT('BDC-RAM-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;

ALTER TABLE `raminventory`
  ADD UNIQUE KEY IF NOT EXISTS `uq_asset_tag` (`AssetTag`);


-- -----------------------------------------------------------------------------
-- 3. storageinventory  ->  BDC-STO-nnnnnn
-- -----------------------------------------------------------------------------
ALTER TABLE `storageinventory`
  ADD COLUMN IF NOT EXISTS `AssetTag` VARCHAR(20) NULL
  COMMENT 'System-issued unique unit identifier (BDC-STO-nnnnnn)' AFTER `UUID`;

UPDATE `storageinventory`
   SET `AssetTag` = CONCAT('BDC-STO-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;

ALTER TABLE `storageinventory`
  ADD UNIQUE KEY IF NOT EXISTS `uq_asset_tag` (`AssetTag`);


-- -----------------------------------------------------------------------------
-- 4. motherboardinventory  ->  BDC-MBD-nnnnnn
-- -----------------------------------------------------------------------------
ALTER TABLE `motherboardinventory`
  ADD COLUMN IF NOT EXISTS `AssetTag` VARCHAR(20) NULL
  COMMENT 'System-issued unique unit identifier (BDC-MBD-nnnnnn)' AFTER `UUID`;

UPDATE `motherboardinventory`
   SET `AssetTag` = CONCAT('BDC-MBD-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;

ALTER TABLE `motherboardinventory`
  ADD UNIQUE KEY IF NOT EXISTS `uq_asset_tag` (`AssetTag`);


-- -----------------------------------------------------------------------------
-- 5. nicinventory  ->  BDC-NIC-nnnnnn
--    NOTE: includes the synthetic onboard-NIC rows created by OnboardNICHandler
--    (2026_07_20_002). They are legitimate units -- one per physical board port
--    group -- and get tags like any other row.
-- -----------------------------------------------------------------------------
ALTER TABLE `nicinventory`
  ADD COLUMN IF NOT EXISTS `AssetTag` VARCHAR(20) NULL
  COMMENT 'System-issued unique unit identifier (BDC-NIC-nnnnnn)' AFTER `UUID`;

UPDATE `nicinventory`
   SET `AssetTag` = CONCAT('BDC-NIC-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;

ALTER TABLE `nicinventory`
  ADD UNIQUE KEY IF NOT EXISTS `uq_asset_tag` (`AssetTag`);


-- -----------------------------------------------------------------------------
-- 6. caddyinventory  ->  BDC-CAD-nnnnnn
-- -----------------------------------------------------------------------------
ALTER TABLE `caddyinventory`
  ADD COLUMN IF NOT EXISTS `AssetTag` VARCHAR(20) NULL
  COMMENT 'System-issued unique unit identifier (BDC-CAD-nnnnnn)' AFTER `UUID`;

UPDATE `caddyinventory`
   SET `AssetTag` = CONCAT('BDC-CAD-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;

ALTER TABLE `caddyinventory`
  ADD UNIQUE KEY IF NOT EXISTS `uq_asset_tag` (`AssetTag`);


-- -----------------------------------------------------------------------------
-- 7. chassisinventory  ->  BDC-CHS-nnnnnn
-- -----------------------------------------------------------------------------
ALTER TABLE `chassisinventory`
  ADD COLUMN IF NOT EXISTS `AssetTag` VARCHAR(20) NULL
  COMMENT 'System-issued unique unit identifier (BDC-CHS-nnnnnn)' AFTER `UUID`;

UPDATE `chassisinventory`
   SET `AssetTag` = CONCAT('BDC-CHS-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;

ALTER TABLE `chassisinventory`
  ADD UNIQUE KEY IF NOT EXISTS `uq_asset_tag` (`AssetTag`);


-- -----------------------------------------------------------------------------
-- 8. pciecardinventory  ->  BDC-PCI-nnnnnn
-- -----------------------------------------------------------------------------
ALTER TABLE `pciecardinventory`
  ADD COLUMN IF NOT EXISTS `AssetTag` VARCHAR(20) NULL
  COMMENT 'System-issued unique unit identifier (BDC-PCI-nnnnnn)' AFTER `UUID`;

UPDATE `pciecardinventory`
   SET `AssetTag` = CONCAT('BDC-PCI-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;

ALTER TABLE `pciecardinventory`
  ADD UNIQUE KEY IF NOT EXISTS `uq_asset_tag` (`AssetTag`);


-- -----------------------------------------------------------------------------
-- 9. hbacardinventory  ->  BDC-HBA-nnnnnn
-- -----------------------------------------------------------------------------
ALTER TABLE `hbacardinventory`
  ADD COLUMN IF NOT EXISTS `AssetTag` VARCHAR(20) NULL
  COMMENT 'System-issued unique unit identifier (BDC-HBA-nnnnnn)' AFTER `UUID`;

UPDATE `hbacardinventory`
   SET `AssetTag` = CONCAT('BDC-HBA-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;

ALTER TABLE `hbacardinventory`
  ADD UNIQUE KEY IF NOT EXISTS `uq_asset_tag` (`AssetTag`);


-- -----------------------------------------------------------------------------
-- 10. sfpinventory  ->  BDC-SFP-nnnnnn
-- -----------------------------------------------------------------------------
ALTER TABLE `sfpinventory`
  ADD COLUMN IF NOT EXISTS `AssetTag` VARCHAR(20) NULL
  COMMENT 'System-issued unique unit identifier (BDC-SFP-nnnnnn)' AFTER `UUID`;

UPDATE `sfpinventory`
   SET `AssetTag` = CONCAT('BDC-SFP-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL;

ALTER TABLE `sfpinventory`
  ADD UNIQUE KEY IF NOT EXISTS `uq_asset_tag` (`AssetTag`);


-- =============================================================================
-- VERIFICATION -- run after the statements above. Every row of the result set
-- must read missing_tag = 0 AND duplicate_tags = 0. Any other value means the
-- backfill did not complete; do NOT deploy the Phase 2 code until it is clean.
-- =============================================================================
SELECT 'cpuinventory'         AS table_name, COUNT(*) AS total,
       SUM(`AssetTag` IS NULL) AS missing_tag,
       COUNT(*) - COUNT(DISTINCT `AssetTag`) AS duplicate_tags FROM `cpuinventory`
UNION ALL
SELECT 'raminventory',         COUNT(*), SUM(`AssetTag` IS NULL),
       COUNT(*) - COUNT(DISTINCT `AssetTag`) FROM `raminventory`
UNION ALL
SELECT 'storageinventory',     COUNT(*), SUM(`AssetTag` IS NULL),
       COUNT(*) - COUNT(DISTINCT `AssetTag`) FROM `storageinventory`
UNION ALL
SELECT 'motherboardinventory', COUNT(*), SUM(`AssetTag` IS NULL),
       COUNT(*) - COUNT(DISTINCT `AssetTag`) FROM `motherboardinventory`
UNION ALL
SELECT 'nicinventory',         COUNT(*), SUM(`AssetTag` IS NULL),
       COUNT(*) - COUNT(DISTINCT `AssetTag`) FROM `nicinventory`
UNION ALL
SELECT 'caddyinventory',       COUNT(*), SUM(`AssetTag` IS NULL),
       COUNT(*) - COUNT(DISTINCT `AssetTag`) FROM `caddyinventory`
UNION ALL
SELECT 'chassisinventory',     COUNT(*), SUM(`AssetTag` IS NULL),
       COUNT(*) - COUNT(DISTINCT `AssetTag`) FROM `chassisinventory`
UNION ALL
SELECT 'pciecardinventory',    COUNT(*), SUM(`AssetTag` IS NULL),
       COUNT(*) - COUNT(DISTINCT `AssetTag`) FROM `pciecardinventory`
UNION ALL
SELECT 'hbacardinventory',     COUNT(*), SUM(`AssetTag` IS NULL),
       COUNT(*) - COUNT(DISTINCT `AssetTag`) FROM `hbacardinventory`
UNION ALL
SELECT 'sfpinventory',         COUNT(*), SUM(`AssetTag` IS NULL),
       COUNT(*) - COUNT(DISTINCT `AssetTag`) FROM `sfpinventory`;


-- =============================================================================
-- Spot-check a few known rows (from the 2026-07-21 production dump) so the
-- format can be eyeballed before the code starts relying on it:
--   storageinventory ID 57  -> BDC-STO-000057   (SGH732T6MY)
--   storageinventory ID 63  -> BDC-STO-000063   (MIG-019eca1d-STORAGE-1 placeholder)
--   motherboardinventory 49 -> BDC-MBD-000049   (one of the 3 HPE DL360 Gen9 boards)
--   hbacardinventory ID 19  -> BDC-HBA-000019   (BC9400-16E-001, invented serial)
-- =============================================================================
SELECT 'storage 57' AS row_ref, `ID`, `AssetTag`, `SerialNumber` FROM `storageinventory`     WHERE `ID` = 57
UNION ALL
SELECT 'storage 63',            `ID`, `AssetTag`, `SerialNumber` FROM `storageinventory`     WHERE `ID` = 63
UNION ALL
SELECT 'board 49',              `ID`, `AssetTag`, `SerialNumber` FROM `motherboardinventory` WHERE `ID` = 49
UNION ALL
SELECT 'hba 19',                `ID`, `AssetTag`, `SerialNumber` FROM `hbacardinventory`     WHERE `ID` = 19;
