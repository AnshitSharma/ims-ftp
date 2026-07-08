-- ============================================================
-- Seeder : 2026_07_10_001_add-status-v2-columns
-- Unit   : U-SM.1 (migration/03-state-machines)
-- Purpose: Introduce status_v2 lifecycle columns on server_configurations and
--          all ten *inventory tables, dual-written alongside the legacy int
--          statuses. Legacy columns remain authoritative until STATE_MACHINE_ENABLED
--          reaches enforce (U-SM.4); this seeder only adds the substrate + a
--          one-time backfill from current legacy values.
-- Tables : server_configurations, cpuinventory, raminventory, storageinventory,
--          motherboardinventory, chassisinventory, nicinventory, caddyinventory,
--          pciecardinventory, hbacardinventory, sfpinventory
-- Notes  : Idempotent (ADD COLUMN IF NOT EXISTS, MariaDB 10.0.2+). Safe to re-run.
--          NULL allowed during the dual-write window; U-SM.3 keeps status_v2 synced
--          on every future write.
-- ============================================================

ALTER TABLE `server_configurations`
  ADD COLUMN IF NOT EXISTS `status_v2` ENUM('draft','building','validating','validated','finalized','deployed','maintenance','retired') NULL AFTER `configuration_status`;

ALTER TABLE `cpuinventory`         ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `raminventory`         ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `storageinventory`     ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `motherboardinventory` ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `chassisinventory`     ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `nicinventory`         ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `caddyinventory`       ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `pciecardinventory`    ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `hbacardinventory`     ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `sfpinventory`         ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;

-- Backfill server_configurations: 0 -> draft, 3 -> finalized;
-- 1/2 unreachable per audit, mapped defensively (1 -> validated, 2 -> building).
UPDATE `server_configurations` SET `status_v2` = 'draft'     WHERE `configuration_status` = 0 AND `status_v2` IS NULL;
UPDATE `server_configurations` SET `status_v2` = 'validated' WHERE `configuration_status` = 1 AND `status_v2` IS NULL;
UPDATE `server_configurations` SET `status_v2` = 'building'  WHERE `configuration_status` = 2 AND `status_v2` IS NULL;
UPDATE `server_configurations` SET `status_v2` = 'finalized' WHERE `configuration_status` = 3 AND `status_v2` IS NULL;

-- Backfill each inventory table: 0 -> failed, 1 -> available, 2 -> installed.
UPDATE `cpuinventory`         SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `raminventory`         SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `storageinventory`     SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `motherboardinventory` SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `chassisinventory`     SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `nicinventory`         SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `caddyinventory`       SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `pciecardinventory`    SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `hbacardinventory`     SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `sfpinventory`         SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SHOW COLUMNS FROM server_configurations LIKE 'status_v2';
--   SELECT configuration_status, status_v2, COUNT(*) FROM server_configurations GROUP BY 1,2;
--   SELECT Status, status_v2, COUNT(*) FROM cpuinventory GROUP BY 1,2;
--
-- Expected: every row has status_v2 populated per the mapping above (counts per
-- mapped status_v2 value equal counts per corresponding legacy int).
-- ============================================================
