-- ============================================================
-- Rollback for: 2026_07_10_001_add-status-v2-columns
-- Safe to run only while STATE_MACHINE_ENABLED=off (legacy int columns are
-- authoritative throughout Phase P3 — no data loss).
-- ============================================================

ALTER TABLE `server_configurations` DROP COLUMN IF EXISTS `status_v2`;

ALTER TABLE `cpuinventory`         DROP COLUMN IF EXISTS `status_v2`;
ALTER TABLE `raminventory`         DROP COLUMN IF EXISTS `status_v2`;
ALTER TABLE `storageinventory`     DROP COLUMN IF EXISTS `status_v2`;
ALTER TABLE `motherboardinventory` DROP COLUMN IF EXISTS `status_v2`;
ALTER TABLE `chassisinventory`     DROP COLUMN IF EXISTS `status_v2`;
ALTER TABLE `nicinventory`         DROP COLUMN IF EXISTS `status_v2`;
ALTER TABLE `caddyinventory`       DROP COLUMN IF EXISTS `status_v2`;
ALTER TABLE `pciecardinventory`    DROP COLUMN IF EXISTS `status_v2`;
ALTER TABLE `hbacardinventory`     DROP COLUMN IF EXISTS `status_v2`;
ALTER TABLE `sfpinventory`         DROP COLUMN IF EXISTS `status_v2`;
