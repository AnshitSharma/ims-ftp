-- =====================================================================
-- Date:     2026-08-10
-- Purpose:  Bulk import of 69 physical SFP / SFP+ / QSFP+ transceiver
--           units from the datacenter stock spreadsheet.
-- Tables:   sfpinventory (INSERT + AssetTag backfill)
-- Feature:  SFP inventory onboarding
--
-- Prerequisite: ims-data/sfp/sfp-level-3.json must already contain the 12
-- newly added model specs (HP TR-PX85S-NHP / SFP-10G-SR85-HP, Dell
-- FTLF8529P4BCV-D2 / FTL8571D3BCL-FC, Avago AFBR-57F5MZ-ELX, D-Link
-- DEM-FQXT10Q-LR4, Syrotech GQRQ-1340G-10LR4, Cisco SFP-GE-T EXT / GLC-T /
-- GLC-TE, HTS-SFP-T, IBM 46C3448) — shipped in the same change. Without the
-- JSON, validateComponentUuid() will reject these units on every API read.
--
-- Notes on source data:
--   * Location is intentionally left NULL; every unit is Status = 1
--     (available), including the Cisco GLC-T flagged FAULTY on the sheet —
--     its flag is preserved in Notes only.
--   * 3 spreadsheet rows are skipped as duplicate serials: JUR1832GA35
--     (listed twice) and BT20230407159 (listed three times). Each is
--     inserted exactly once.
--   * The Syrotech QSFP+ has no serial on the sheet — inserted with
--     SerialNumber NULL, addressed by its AssetTag.
--   * Idempotent: INSERT IGNORE relies on the UNIQUE index on SerialNumber;
--     the serial-less row is guarded by an explicit NOT EXISTS.
-- =====================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------
-- Cisco SFP-10G-SR  (34 units)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sfpinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Notes`) VALUES
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'AVD2123AW1H',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'FNS14220FV3',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR1931GKX6',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR1831G678',  1, 'available', 'Inward in Yotta'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR19070VKA',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR1832GA35',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR1832G3K3',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR1832GELU',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR1829G5QR',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR19070W2D',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR1922G4GP',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR1922G0R5',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR1922G2R4',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR1919G3UJ',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR19070RAQ',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'SPC152806CA',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'SPC1541045A',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'SPC161000LZ',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'SPC151304YR',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'SPC151110655', 1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'SPC162004N4',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'SPC1511064U',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'SPC153401QZ',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'AVD2104A8RJ',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'FNS1708185D',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'AGD170446SU',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'AVD2104A8RL',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'AGD17064A4K',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'AVD2104A8S0',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'AVD2020A2TR',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'JUR1931GKWN',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'ACW25161P14',  1, 'available', 'Inward in CTRLS'),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'OPM23030K03',  1, 'available', NULL),
('32bc2712-98a6-421f-85f5-4efb68e4ee00', 'OPM23030K68',  1, 'available', NULL);

-- ---------------------------------------------------------------------
-- Cisco SFP-10G-LR  (4 units)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sfpinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Notes`) VALUES
('3f3b5ff5-f0ab-4992-bafa-ad05f485a354', 'AVD2110K6RN', 1, 'available', NULL),
('3f3b5ff5-f0ab-4992-bafa-ad05f485a354', 'AVD2110K7VK', 1, 'available', NULL),
('3f3b5ff5-f0ab-4992-bafa-ad05f485a354', 'AVD2110KJXU', 1, 'available', NULL),
('3f3b5ff5-f0ab-4992-bafa-ad05f485a354', 'AVD2110K7W0', 1, 'available', NULL);

-- ---------------------------------------------------------------------
-- Avago AFBR-57F5MZ-ELX — 16G FC SW  (5 units)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sfpinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Notes`) VALUES
('0446b687-c436-45d0-b741-5fba5a182dc5', 'AC2228J0972', 1, 'available', NULL),
('0446b687-c436-45d0-b741-5fba5a182dc5', 'AC1717J0HH3', 1, 'available', NULL),
('0446b687-c436-45d0-b741-5fba5a182dc5', 'AC2228J0AHF', 1, 'available', NULL),
('0446b687-c436-45d0-b741-5fba5a182dc5', 'AC1717J0HH8', 1, 'available', NULL),
('0446b687-c436-45d0-b741-5fba5a182dc5', 'AC2228J02C8', 1, 'available', NULL);

-- ---------------------------------------------------------------------
-- HP/HPE 10G  (3 units)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sfpinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Notes`) VALUES
('c66c773f-b2d8-46f0-8896-9ec89d0690e4', 'CN40GDB2PZ',   1, 'available', NULL),
('c66c773f-b2d8-46f0-8896-9ec89d0690e4', 'CN40GDB1T0',   1, 'available', NULL),
('b9019620-87ff-4219-b2fa-096c9e6e921f', 'FS240115018O', 1, 'available', NULL);

-- ---------------------------------------------------------------------
-- Dell FTLF8529P4BCV-D2 — 16G  (5 units)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sfpinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Notes`) VALUES
('63fe70de-c925-4002-bfc4-71dd25e2d404', 'P92APSB', 1, 'available', NULL),
('63fe70de-c925-4002-bfc4-71dd25e2d404', 'P92APTY', 1, 'available', NULL),
('63fe70de-c925-4002-bfc4-71dd25e2d404', 'P93AME5', 1, 'available', NULL),
('63fe70de-c925-4002-bfc4-71dd25e2d404', 'P92APUJ', 1, 'available', NULL),
('63fe70de-c925-4002-bfc4-71dd25e2d404', 'P93AM7T', 1, 'available', NULL);

-- ---------------------------------------------------------------------
-- Dell FTL8571D3BCL-FC — 10G  (8 units)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sfpinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Notes`) VALUES
('779abb27-a66e-4c41-8b8d-e9c423144256', 'AM71540', 1, 'available', NULL),
('779abb27-a66e-4c41-8b8d-e9c423144256', 'AS613AV', 1, 'available', NULL),
('779abb27-a66e-4c41-8b8d-e9c423144256', 'AS612WP', 1, 'available', NULL),
('779abb27-a66e-4c41-8b8d-e9c423144256', 'ARL08MK', 1, 'available', NULL),
('779abb27-a66e-4c41-8b8d-e9c423144256', 'AM708YU', 1, 'available', NULL),
('779abb27-a66e-4c41-8b8d-e9c423144256', 'ARL0571', 1, 'available', NULL),
('779abb27-a66e-4c41-8b8d-e9c423144256', 'AS612TX', 1, 'available', NULL),
('779abb27-a66e-4c41-8b8d-e9c423144256', 'ASF1F2D', 1, 'available', NULL);

-- ---------------------------------------------------------------------
-- QSFP+ 40G-LR4  (2 units + 1 serial-less, see below)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sfpinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Notes`) VALUES
('838db75f-af70-426b-9a9e-09852707dc56', 'RVQXL53000257', 1, 'available', NULL),
('838db75f-af70-426b-9a9e-09852707dc56', 'RVQXL53000272', 1, 'available', NULL);

-- ---------------------------------------------------------------------
-- 1G copper SFP  (5 units)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sfpinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Notes`) VALUES
('a026d235-bb0b-4401-b547-48e33d377205', 'MTC142107BM',   1, 'available', 'Inward in CTRLS'),
('a026d235-bb0b-4401-b547-48e33d377205', 'MTC142108UL',   1, 'available', NULL),
('a026d235-bb0b-4401-b547-48e33d377205', 'MTC142109BV',   1, 'available', 'Inward in CTRLS'),
('7e9ace23-1b0e-4908-9897-6426510ef9d1', 'MTC1605090M',   1, 'available', 'Marked FAULTY on intake sheet'),
('7e3cb127-5c25-4f47-9a52-09df17ce71c1', 'AVC19472118',   1, 'available', NULL),
('937a0df0-52b6-468c-8c85-db158d07b41e', 'BT20230407159', 1, 'available', NULL);

-- ---------------------------------------------------------------------
-- IBM 46C3448 — 10GBASE-SR  (1 unit)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `sfpinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Notes`) VALUES
('a494e545-1cad-4793-bfd3-515c84acb552', '46C3448Y251UC46GD84', 1, 'available', NULL);

-- ---------------------------------------------------------------------
-- Syrotech GQRQ-1340G-10LR4 — no serial on the intake sheet.
-- INSERT IGNORE cannot dedupe a NULL serial, so guard it explicitly.
-- ---------------------------------------------------------------------
INSERT INTO `sfpinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Notes`)
SELECT 'bd3852af-48ea-4736-83c6-ab5f364610f7', NULL, 1, 'available',
       'Serial not recorded on intake sheet; addressed by AssetTag'
  FROM DUAL
 WHERE NOT EXISTS (
       SELECT 1 FROM `sfpinventory`
        WHERE `UUID` = 'bd3852af-48ea-4736-83c6-ab5f364610f7'
          AND `SerialNumber` IS NULL
 );

-- ---------------------------------------------------------------------
-- AssetTag backfill — BDC-SFP-nnnnnn from the auto-increment ID,
-- matching formatAssetTag() in core/helpers/BaseFunctions.php.
-- ---------------------------------------------------------------------
UPDATE `sfpinventory`
   SET `AssetTag` = CONCAT('BDC-SFP-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL OR `AssetTag` = '';

COMMIT;

-- Verification:
--   SELECT UUID, COUNT(*) FROM sfpinventory GROUP BY UUID ORDER BY 2 DESC;
--   SELECT COUNT(*) FROM sfpinventory WHERE AssetTag IS NULL;  -- expect 0
