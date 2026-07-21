-- =============================================================================
-- Seeder:  2026_07_20_001_nicinventory-parent-inventory-id.sql
-- Date:    2026-07-20
-- Purpose: Add ParentInventoryID to nicinventory so a synthetic onboard NIC is
--          identified by the PHYSICAL motherboard unit (motherboardinventory.ID)
--          instead of the shared motherboard spec/model UUID.
--
--          Root cause being fixed: OnboardNICHandler mints identity from the
--          model UUID only --
--              UUID         = "onboard-{mb8}-{index}"
--              SerialNumber = "ONBOARD-{mb8}-{index}"   <- UNIQUE key
--          so every physical board of the same model competes for one identity.
--          The first board added wins; every later one raises a duplicate-key
--          PDOException that is swallowed whole, leaving that server with no
--          onboard NICs and nic_config NULL. Confirmed live: 3 physical HPE
--          DL360 Gen9 boards (motherboardinventory ID 49 / 53 / 55, all spec
--          4c8f5e1b-2b4a-4c8d-b9e7-f6d2a3c1e9b8), only ID 49's onboard NIC row
--          exists (nicinventory ID 232).
--
-- Affected tables: nicinventory (ADD COLUMN + ADD INDEX only)
-- Related feature: onboard NIC unit identity (tasks/onboard-nic-unit-identity.md)
--
-- ORDER OF OPERATIONS -- important:
--   1. Run THIS seeder BEFORE the OnboardNICHandler code change is deployed.
--      The new column is nullable and unused until the code lands, so running
--      it early is safe and changes no behaviour on its own.
--   2. Deploy the code.
--   3. Run 2026_07_20_002, which migrates existing rows to the unit-scoped
--      identity, backfills the missing onboard NICs, and only THEN adds
--      UNIQUE(ParentInventoryID, OnboardNICIndex).
--
--   The UNIQUE key is deliberately NOT created here: existing rows do not yet
--   carry ParentInventoryID, and the constraint must not be added until they
--   conform.
--
-- Idempotent: yes (IF NOT EXISTS on both statements).
-- =============================================================================

ALTER TABLE `nicinventory`
  ADD COLUMN IF NOT EXISTS `ParentInventoryID` INT(11) DEFAULT NULL
    COMMENT 'motherboardinventory.ID of the physical board this onboard NIC belongs to; NULL for component NICs'
    AFTER `ParentComponentUUID`;

ALTER TABLE `nicinventory`
  ADD INDEX IF NOT EXISTS `idx_parent_inventory` (`ParentInventoryID`);

-- -----------------------------------------------------------------------------
-- Verification (expect: column present, all values NULL, 4 onboard rows total)
-- -----------------------------------------------------------------------------
-- SHOW COLUMNS FROM `nicinventory` LIKE 'ParentInventoryID';
--
-- SELECT ID, UUID, SerialNumber, ParentComponentUUID, ParentInventoryID,
--        OnboardNICIndex, ServerUUID, Status
--   FROM `nicinventory`
--  WHERE SourceType = 'onboard'
--  ORDER BY ID;
