-- ============================================================
-- Seeder : 2026_06_15_001_add-faildate-column-to-all-inventory-tables
-- Date   : 2026-06-15
-- Purpose: Add a FailDate column to every component inventory table
--          so a component's failure date can be recorded alongside
--          the existing PurchaseDate / InstallationDate /
--          WarrantyEndDate fields. Captured in the UI only when a
--          component's Status is set to Failed (0).
-- Tables : caddyinventory, chassisinventory, cpuinventory,
--          hbacardinventory, motherboardinventory, nicinventory,
--          pciecardinventory, raminventory, sfpinventory,
--          storageinventory
-- Notes  : Idempotent (ADD COLUMN IF NOT EXISTS, MariaDB 10.0.2+).
--          Safe to re-run. Existing rows get FailDate = NULL.
-- Feature: Component Fail Date tracking.
-- ============================================================

ALTER TABLE `caddyinventory`       ADD COLUMN IF NOT EXISTS `FailDate` date DEFAULT NULL COMMENT 'When the component failed' AFTER `WarrantyEndDate`;
ALTER TABLE `chassisinventory`     ADD COLUMN IF NOT EXISTS `FailDate` date DEFAULT NULL COMMENT 'When the component failed' AFTER `WarrantyEndDate`;
ALTER TABLE `cpuinventory`         ADD COLUMN IF NOT EXISTS `FailDate` date DEFAULT NULL COMMENT 'When the component failed' AFTER `WarrantyEndDate`;
ALTER TABLE `hbacardinventory`     ADD COLUMN IF NOT EXISTS `FailDate` date DEFAULT NULL COMMENT 'When the component failed' AFTER `WarrantyEndDate`;
ALTER TABLE `motherboardinventory` ADD COLUMN IF NOT EXISTS `FailDate` date DEFAULT NULL COMMENT 'When the component failed' AFTER `WarrantyEndDate`;
ALTER TABLE `nicinventory`         ADD COLUMN IF NOT EXISTS `FailDate` date DEFAULT NULL COMMENT 'When the component failed' AFTER `WarrantyEndDate`;
ALTER TABLE `pciecardinventory`    ADD COLUMN IF NOT EXISTS `FailDate` date DEFAULT NULL COMMENT 'When the component failed' AFTER `WarrantyEndDate`;
ALTER TABLE `raminventory`         ADD COLUMN IF NOT EXISTS `FailDate` date DEFAULT NULL COMMENT 'When the component failed' AFTER `WarrantyEndDate`;
ALTER TABLE `sfpinventory`         ADD COLUMN IF NOT EXISTS `FailDate` date DEFAULT NULL COMMENT 'When the component failed' AFTER `WarrantyEndDate`;
ALTER TABLE `storageinventory`     ADD COLUMN IF NOT EXISTS `FailDate` date DEFAULT NULL COMMENT 'When the component failed' AFTER `WarrantyEndDate`;

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SHOW COLUMNS FROM cpuinventory LIKE 'FailDate';
--   SHOW COLUMNS FROM storageinventory LIKE 'FailDate';
--
-- Expected: one row per table, Type = date, Null = YES, Default = NULL.
-- ============================================================
