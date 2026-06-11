-- ============================================================
-- Seeder : 2026_06_11_001_inventory-performance-indexes
-- Date   : 2026-06-11
-- Purpose: Close index gaps on the 10 component inventory tables
--          found during the performance review. Supports the live
--          query patterns: status filters / status counts on the
--          dashboard, server-configuration component lookups by
--          ServerUUID, and serial-number lookups. The pagination,
--          search, and ACL fast-path fixes shipped alongside this
--          are code-only and need no schema change.
-- Tables : caddyinventory, chassisinventory, cpuinventory,
--          hbacardinventory, motherboardinventory, nicinventory,
--          pciecardinventory, raminventory, sfpinventory,
--          storageinventory
-- Notes  : Idempotent (ADD INDEX IF NOT EXISTS, MariaDB 10.0.2+).
--          Safe to re-run. Existing indexes are left untouched.
-- ============================================================

-- ------------------------------------------------------------
-- 1. ServerUUID index
--    Used by server-configuration lookups (components installed
--    in a server). Already present on hbacardinventory and
--    sfpinventory; missing on the other 8 tables.
-- ------------------------------------------------------------

ALTER TABLE `caddyinventory`       ADD INDEX IF NOT EXISTS `idx_server_uuid` (`ServerUUID`);
ALTER TABLE `chassisinventory`     ADD INDEX IF NOT EXISTS `idx_server_uuid` (`ServerUUID`);
ALTER TABLE `cpuinventory`         ADD INDEX IF NOT EXISTS `idx_server_uuid` (`ServerUUID`);
ALTER TABLE `motherboardinventory` ADD INDEX IF NOT EXISTS `idx_server_uuid` (`ServerUUID`);
ALTER TABLE `nicinventory`         ADD INDEX IF NOT EXISTS `idx_server_uuid` (`ServerUUID`);
ALTER TABLE `pciecardinventory`    ADD INDEX IF NOT EXISTS `idx_server_uuid` (`ServerUUID`);
ALTER TABLE `raminventory`         ADD INDEX IF NOT EXISTS `idx_server_uuid` (`ServerUUID`);
ALTER TABLE `storageinventory`     ADD INDEX IF NOT EXISTS `idx_server_uuid` (`ServerUUID`);

-- ------------------------------------------------------------
-- 2. Status index on chassisinventory
--    Every other inventory table already has one. Used by the
--    dashboard status counts and status filters.
-- ------------------------------------------------------------

ALTER TABLE `chassisinventory` ADD INDEX IF NOT EXISTS `idx_chassis_status` (`Status`);

-- ------------------------------------------------------------
-- 3. SerialNumber index on chassisinventory and hbacardinventory
--    Every other inventory table has a UNIQUE SerialNumber index.
--    Added here as a plain (non-unique) index so the seeder cannot
--    fail on pre-existing duplicate or NULL serials in these two
--    tables. Used by serial-number lookups and exact searches.
-- ------------------------------------------------------------

ALTER TABLE `chassisinventory` ADD INDEX IF NOT EXISTS `idx_serial_number` (`SerialNumber`);
ALTER TABLE `hbacardinventory` ADD INDEX IF NOT EXISTS `idx_serial_number` (`SerialNumber`);

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SHOW INDEX FROM chassisinventory;
--   SHOW INDEX FROM cpuinventory;
--
-- Expected: idx_server_uuid on the 8 tables above,
--           idx_chassis_status + idx_serial_number on chassisinventory,
--           idx_serial_number on hbacardinventory.
-- ============================================================
