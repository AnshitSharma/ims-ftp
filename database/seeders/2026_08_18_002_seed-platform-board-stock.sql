-- =============================================================================
-- Date:     2026-08-18
-- Purpose:  Give every server compute platform at least one selectable system
--           board, so the builder's platform picker can be exercised end to end.
--           17 of the 23 boards named in the platform spec had ZERO available
--           units, which renders them greyed out and unselectable in the picker.
--
-- Tables:   motherboardinventory (INSERT only)
-- Feature:  Server compute platform selection
--
-- !! THESE ARE FICTIONAL UNITS. !!
--           They do not correspond to hardware anyone can go and touch. Every row
--           is marked Flag='Seeded', SerialNumber prefixed 'SEED-', and Notes
--           beginning 'SEEDED 2026_08_18_002' so they are trivially greppable and
--           removable. Run the rollback before this system is trusted as a record
--           of real inventory.
--
-- Notes:    - 2 units per board, Status=1 (available), status_v2='available'.
--           - AssetTag is NOT supplied in the INSERT: BaseFunctions::formatAssetTag()
--             derives it from the row ID (BDC-MBD-%06d), so it can only be set once
--             AUTO_INCREMENT has assigned the IDs. The UPDATE below does exactly that
--             and touches only rows this seeder created.
--           - Idempotent: SerialNumber carries a UNIQUE index, so INSERT IGNORE makes
--             a re-run a no-op.
--           - Rollback: rollback/2026_08_18_002_seed-platform-board-stock_rollback.sql
-- =============================================================================

INSERT IGNORE INTO `motherboardinventory`
    (`UUID`, `SerialNumber`, `Status`, `status_v2`, `Location`, `Flag`, `Notes`)
VALUES
    ('e9f0a1b2-c3d4-4e5f-8a7b-8c9d0e1f2a3b', 'SEED-DL360GEN10-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: HPE, Model: ProLiant DL360 Gen10 System Board'),
    ('e9f0a1b2-c3d4-4e5f-8a7b-8c9d0e1f2a3b', 'SEED-DL360GEN10-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: HPE, Model: ProLiant DL360 Gen10 System Board'),
    ('c5e9b814-725d-4f1a-b6b8-3f8c8d8b13c1', 'SEED-DL325GEN10PL-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: HPE, Model: ProLiant DL325 Gen10 Plus v2 System Board'),
    ('c5e9b814-725d-4f1a-b6b8-3f8c8d8b13c1', 'SEED-DL325GEN10PL-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: HPE, Model: ProLiant DL325 Gen10 Plus v2 System Board'),
    ('3f8d6b2e-9a4c-7e1f-5b3d-8a2c6f4e9d7b', 'SEED-DL385GEN11-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: HPE, Model: ProLiant DL385 Gen11'),
    ('3f8d6b2e-9a4c-7e1f-5b3d-8a2c6f4e9d7b', 'SEED-DL385GEN11-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: HPE, Model: ProLiant DL385 Gen11'),
    ('5a7c9e2b-4d6f-8a1c-3e5b-7f9d2a4c6e8b', 'SEED-R760-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Dell, Model: PowerEdge R760'),
    ('5a7c9e2b-4d6f-8a1c-3e5b-7f9d2a4c6e8b', 'SEED-R760-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Dell, Model: PowerEdge R760'),
    ('b6a2f3e8-193c-4d5b-a7e1-8c4d5f6b8a21', 'SEED-R6525-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Dell, Model: PowerEdge R6525 System Board'),
    ('b6a2f3e8-193c-4d5b-a7e1-8c4d5f6b8a21', 'SEED-R6525-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Dell, Model: PowerEdge R6525 System Board'),
    ('d4b1a8c9-7e5f-4d3b-9a8c-2f3b4c5d6e7f', 'SEED-FC630-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Dell, Model: PowerEdge FC630 System Board'),
    ('d4b1a8c9-7e5f-4d3b-9a8c-2f3b4c5d6e7f', 'SEED-FC630-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Dell, Model: PowerEdge FC630 System Board'),
    ('8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c', 'SEED-X13DRGH-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Supermicro, Model: X13DRG-H'),
    ('8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c', 'SEED-X13DRGH-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Supermicro, Model: X13DRG-H'),
    ('7a3b9c8d-2f1a-4b7e-8c6d-5a9f2b3e8c7d', 'SEED-X13DRIN-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Supermicro, Model: X13DRi-N'),
    ('7a3b9c8d-2f1a-4b7e-8c6d-5a9f2b3e8c7d', 'SEED-X13DRIN-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Supermicro, Model: X13DRi-N'),
    ('9d2e4f6a-7b8c-4d9e-8f1a-6c3d5e7f9a2b', 'SEED-SYS1029UTR4-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Supermicro, Model: SYS-1029U-TR4'),
    ('9d2e4f6a-7b8c-4d9e-8f1a-6c3d5e7f9a2b', 'SEED-SYS1029UTR4-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Supermicro, Model: SYS-1029U-TR4'),
    ('a5b6c7d8-e9f0-4a1b-8c3d-4e5f6a7b8c9d', 'SEED-SA5212H5-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Inspur, Model: SA5212H5 System Board'),
    ('a5b6c7d8-e9f0-4a1b-8c3d-4e5f6a7b8c9d', 'SEED-SA5212H5-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: Inspur, Model: SA5212H5 System Board'),
    ('b3c4d5e6-f7a8-4b9c-8d0e-1f2a3b4c5d6e', 'SEED-R180F34-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: R180-F34'),
    ('b3c4d5e6-f7a8-4b9c-8d0e-1f2a3b4c5d6e', 'SEED-R180F34-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: R180-F34'),
    ('c7d8e9f0-a1b2-4c3d-ae5f-6a7b8c9d0e1f', 'SEED-MD90FS0ZBXX-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: MD90-FS0-ZB-XX'),
    ('c7d8e9f0-a1b2-4c3d-ae5f-6a7b8c9d0e1f', 'SEED-MD90FS0ZBXX-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: MD90-FS0-ZB-XX'),
    ('6e4c2a5b-3a8e-4f7d-8b2c-9d1a4e5b6f7c', 'SEED-MZ93FS0-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: MZ93-FS0'),
    ('6e4c2a5b-3a8e-4f7d-8b2c-9d1a4e5b6f7c', 'SEED-MZ93FS0-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: MZ93-FS0'),
    ('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', 'SEED-B660MDS3H-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: B660M DS3H'),
    ('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', 'SEED-B660MDS3H-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: B660M DS3H'),
    ('b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e', 'SEED-B550MDS3H-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: B550M DS3H'),
    ('b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e', 'SEED-B550MDS3H-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: B550M DS3H'),
    ('b6c7d8e9-f0a1-4b2c-9d4e-5f6a7b8c9d0e', 'SEED-B550MDS3HACR-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: B550M DS3H AC R2'),
    ('b6c7d8e9-f0a1-4b2c-9d4e-5f6a7b8c9d0e', 'SEED-B550MDS3HACR-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: GIGABYTE, Model: B550M DS3H AC R2'),
    ('4f8e6c3d-2b7a-4c9e-8d1b-5e6f7a3d9c8b', 'SEED-ROMED89001-01', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: ASRock Rack, Model: ROMED8-9001'),
    ('4f8e6c3d-2b7a-4c9e-8d1b-5e6f7a3d9c8b', 'SEED-ROMED89001-02', 1, 'available', 'Noida Office', 'Seeded', 'SEEDED 2026_08_18_002 (platform picker test stock) - Brand: ASRock Rack, Model: ROMED8-9001');

-- AssetTag must equal BDC-MBD-<zero-padded row ID> (formatAssetTag convention).
UPDATE `motherboardinventory`
   SET `AssetTag` = CONCAT('BDC-MBD-', LPAD(`ID`, 6, '0'))
 WHERE `AssetTag` IS NULL
   AND `Flag` = 'Seeded'
   AND `Notes` LIKE 'SEEDED 2026\_08\_18\_002%';

-- ---------------------------------------------------------------------------
-- Verification: available units per board that a platform names
-- ---------------------------------------------------------------------------
SELECT `UUID`, COUNT(*) AS available_units
  FROM `motherboardinventory`
 WHERE `Status` = 1
 GROUP BY `UUID`
 ORDER BY available_units DESC;
