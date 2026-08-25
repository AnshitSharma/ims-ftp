-- =============================================================================
-- 2026_08_25_004_seed-serverplatform-stock.sql
--
-- Date:     2026-08-25
-- Purpose:  Put FICTIONAL server compute platform units on the shelf so the
--           builder's platform picker can be exercised end to end.
-- Tables:   serverplatforminventory
-- Feature:  Server Compute Platform rebuild -- tasks/todo.md
--
-- Run AFTER 2026_08_25_002 and 2026_08_25_003. OPTIONAL.
--
-- =============================================================================
-- !! THIS SEEDER INVENTS INVENTORY THAT DOES NOT PHYSICALLY EXIST !!
--
--   Every version in the catalog starts with zero stock, so every version in the
--   picker renders "Out of stock" and cannot be selected -- correct, but it
--   leaves the whole feature untestable. This file adds 2 units per version so
--   the flow can be walked through.
--
--   The units are marked so they can never be mistaken for real stock:
--     SerialNumber  starts with 'SEED-SPF-'
--     Flag          = 'Seeded'
--     Notes         start with 'SEEDED 2026_08_25_004'
--
--   RUN THE ROLLBACK (rollback/2026_08_25_004_seed-serverplatform-stock_rollback.sql)
--   BEFORE THIS SYSTEM IS TRUSTED AS A RECORD OF REAL INVENTORY. The rollback
--   refuses to remove a seeded unit that has since been built into a server.
--
-- =============================================================================
-- IDEMPOTENCY
--
--   SerialNumber is UNIQUE, so INSERT IGNORE makes a re-run a no-op.
--
--   AssetTag is set by the follow-up UPDATE rather than in the INSERT, because
--   BaseFunctions::formatAssetTag() derives it from the row's own AUTO_INCREMENT
--   id (BDC-SPF-%06d) -- a value that does not exist until the row does. Same
--   two-step as seeder 2026_08_18_002.
-- =============================================================================

INSERT IGNORE INTO `serverplatforminventory`
  (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Flag`, `Notes`)
VALUES
  ('1cf42330-b3d7-5cb9-8aae-6fb864742c45', 'SEED-SPF-1CF42330-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for HPE ProLiant DL360 Gen9 8SFF'),
  ('1cf42330-b3d7-5cb9-8aae-6fb864742c45', 'SEED-SPF-1CF42330-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for HPE ProLiant DL360 Gen9 8SFF'),
  ('786ebd26-34f1-54cd-87a4-577829a2d745', 'SEED-SPF-786EBD26-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for HPE ProLiant DL360 Gen9 4LFF'),
  ('786ebd26-34f1-54cd-87a4-577829a2d745', 'SEED-SPF-786EBD26-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for HPE ProLiant DL360 Gen9 4LFF'),
  ('1107e45b-c08d-5a01-9e57-2e1aeaaf4a35', 'SEED-SPF-1107E45B-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for HPE ProLiant DL380 Gen10 8SFF'),
  ('1107e45b-c08d-5a01-9e57-2e1aeaaf4a35', 'SEED-SPF-1107E45B-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for HPE ProLiant DL380 Gen10 8SFF'),
  ('61e6cc4b-2860-5039-9ade-ea9222ccbc9a', 'SEED-SPF-61E6CC4B-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for HPE ProLiant DL380 Gen10 12LFF'),
  ('61e6cc4b-2860-5039-9ade-ea9222ccbc9a', 'SEED-SPF-61E6CC4B-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for HPE ProLiant DL380 Gen10 12LFF'),
  ('21d0203b-5e64-5255-ad47-f168d886ce12', 'SEED-SPF-21D0203B-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for HPE ProLiant DL325 Gen10 Plus v2 8SFF'),
  ('21d0203b-5e64-5255-ad47-f168d886ce12', 'SEED-SPF-21D0203B-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for HPE ProLiant DL325 Gen10 Plus v2 8SFF'),
  ('e865ea68-bf85-5afd-9574-2bd07f8f40f2', 'SEED-SPF-E865EA68-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R630 10SFF'),
  ('e865ea68-bf85-5afd-9574-2bd07f8f40f2', 'SEED-SPF-E865EA68-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R630 10SFF'),
  ('d97eb828-c0c0-5373-824e-6940b1cd37d0', 'SEED-SPF-D97EB828-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R740 16SFF'),
  ('d97eb828-c0c0-5373-824e-6940b1cd37d0', 'SEED-SPF-D97EB828-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R740 16SFF'),
  ('04258d2c-3dcd-5723-afd5-0ad10e86e162', 'SEED-SPF-04258D2C-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R740 xd 24SFF + 8LFF'),
  ('04258d2c-3dcd-5723-afd5-0ad10e86e162', 'SEED-SPF-04258D2C-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R740 xd 24SFF + 8LFF'),
  ('a9dd6097-a858-57a3-9573-bf9f3afcb54f', 'SEED-SPF-A9DD6097-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R740 xd 12LFF + 4SFF'),
  ('a9dd6097-a858-57a3-9573-bf9f3afcb54f', 'SEED-SPF-A9DD6097-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R740 xd 12LFF + 4SFF'),
  ('84752653-dd46-59d8-baa1-bbe84f74256d', 'SEED-SPF-84752653-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R6525 10SFF'),
  ('84752653-dd46-59d8-baa1-bbe84f74256d', 'SEED-SPF-84752653-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R6525 10SFF'),
  ('d071b1c6-4d6b-51d1-b01c-9296cb2ba668', 'SEED-SPF-D071B1C6-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R6525 10SFF (1QZDX53)'),
  ('d071b1c6-4d6b-51d1-b01c-9296cb2ba668', 'SEED-SPF-D071B1C6-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge R6525 10SFF (1QZDX53)'),
  ('21d4dc70-6759-532a-bde1-717b9b9ac55f', 'SEED-SPF-21D4DC70-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge FC630 2SFF'),
  ('21d4dc70-6759-532a-bde1-717b9b9ac55f', 'SEED-SPF-21D4DC70-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for Dell PowerEdge FC630 2SFF'),
  ('11c87e97-59b4-50a5-afd9-1ea56329997f', 'SEED-SPF-11C87E97-1', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for GIGABYTE R180-F34 4LFF'),
  ('11c87e97-59b4-50a5-afd9-1ea56329997f', 'SEED-SPF-11C87E97-2', 1, 'available', 'Seeded', 'SEEDED 2026_08_25_004 - fictional stock for GIGABYTE R180-F34 4LFF');

-- Asset tags, derived from each row's own id exactly as formatAssetTag() does.
UPDATE `serverplatforminventory`
   SET `AssetTag` = CONCAT('BDC-SPF-', LPAD(`ID`, 6, '0'))
 WHERE `SerialNumber` LIKE 'SEED-SPF-%'
   AND `AssetTag` IS NULL;


-- =============================================================================
-- Verification (run after the seeder):
--
--   SELECT COUNT(*) FROM serverplatforminventory WHERE Flag = 'Seeded';   -- 26
--
--   SELECT UUID, COUNT(*) AS units
--     FROM serverplatforminventory
--    WHERE Status = 1
--    GROUP BY UUID;
--   -- expected: 13 rows, 2 units each -- one row per catalogued version
--
--   SELECT ID, AssetTag, SerialNumber, Status FROM serverplatforminventory LIMIT 5;
-- =============================================================================
