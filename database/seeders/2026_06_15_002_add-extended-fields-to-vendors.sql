-- ============================================================
-- Seeder : 2026_06_15_002_add-extended-fields-to-vendors
-- Date   : 2026-06-15
-- Purpose: Extend the vendors table with optional contact,
--          address, banking and "what they sell" fields surfaced
--          in the Add/Edit Vendor forms. All new columns are
--          nullable so existing vendors are unaffected.
-- Tables : vendors
-- Columns:
--   phone2       - secondary / additional phone number
--   address      - free-form postal address
--   bank_details - free-form banking information (account, IFSC, etc.)
--   sells        - CSV of component-type keys the vendor supplies
--                  (cpu,ram,storage,motherboard,nic,caddy,chassis,
--                   pciecard,hbacard,sfp)
-- Notes  : Idempotent (ADD COLUMN IF NOT EXISTS, MariaDB 10.0.2+).
--          Safe to re-run. Existing rows get NULL for each column.
-- Feature: Extended vendor profile.
-- ============================================================

ALTER TABLE `vendors` ADD COLUMN IF NOT EXISTS `phone2`       varchar(50) DEFAULT NULL COMMENT 'Additional phone number'              AFTER `phone`;
ALTER TABLE `vendors` ADD COLUMN IF NOT EXISTS `address`      text        DEFAULT NULL COMMENT 'Postal address'                        AFTER `phone2`;
ALTER TABLE `vendors` ADD COLUMN IF NOT EXISTS `bank_details` text        DEFAULT NULL COMMENT 'Banking information (free-form)'        AFTER `address`;
ALTER TABLE `vendors` ADD COLUMN IF NOT EXISTS `sells`        text        DEFAULT NULL COMMENT 'CSV of component types the vendor sells' AFTER `bank_details`;

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SHOW COLUMNS FROM vendors;
--
-- Expected: phone2 (varchar), address/bank_details/sells (text),
--           all Null = YES, Default = NULL.
-- ============================================================
