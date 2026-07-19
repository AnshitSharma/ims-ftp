-- Seeder: 2026_07_18_001_storage-inventory-add-asset-list-serials.sql
-- Date: 2026-07-18
-- Purpose: Add real inventory rows (one row per physical serialized unit) for the
--          16-item STORAGE section of the "ims Details.pdf" asset list. Component
--          spec catalog entries for these models were already added/corrected in
--          ims-data/storage/storage-level-3.json (see tasks/component-catalog-additions.md).
-- Affected tables: storageinventory
-- Related feature: component-catalog-additions (2026-07-18)
--
-- Rules applied:
--   - One row per serial number (quantity is implicit in row count, not stored).
--   - Rows are inserted as Status=1 / status_v2='available' (new stock, not yet
--     installed in any server) — ServerUUID left NULL.
--   - Location/RackPosition/PurchaseDate/WarrantyEndDate are unknown from the
--     source sheet and intentionally left NULL (no fabricated data).
--   - KINGSTON KC600 (qty 3) had NO serial numbers on the source sheet, per
--     instruction items with no serial number are SKIPPED ENTIRELY — not seeded.
--   - INSERT IGNORE makes this idempotent against the SerialNumber UNIQUE key,
--     safe to re-run.

-- 1. MiPhi MP311100T (1TB SATA SSD) — uuid 4d5e6f7a-8b9c-4d0e-9f1a-2b3c4d5e6f7a — qty 1
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('4d5e6f7a-8b9c-4d0e-9f1a-2b3c4d5e6f7a', '60009MA4A000058', 1, 'available', NOW(), NOW());

-- 2. HPE VK003840GWSRV (3.84TB SATA SSD) — uuid f726a2b0-064e-4c6f-8a92-9736842cbade — qty 1
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('f726a2b0-064e-4c6f-8a92-9736842cbade', 'S4NDY0R101093', 1, 'available', NOW(), NOW());

-- 3. Dell EMC MZ-7LH1T9A (1.92TB SATA SSD) — uuid d69a865c-048c-4df0-822c-3357c8d633f7 — qty 3
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('d69a865c-048c-4df0-822c-3357c8d633f7', 'S450NY0M705830', 1, 'available', NOW(), NOW()),
('d69a865c-048c-4df0-822c-3357c8d633f7', 'S450NY0M705921', 1, 'available', NOW(), NOW()),
('d69a865c-048c-4df0-822c-3357c8d633f7', 'S5CRNY0MA05682', 1, 'available', NOW(), NOW());

-- 4. Dell 10E2400 (1.2TB SAS HDD) — uuid 138e1181-f1bb-4c2e-a487-15afbe7098d6 — qty 3
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('138e1181-f1bb-4c2e-a487-15afbe7098d6', 'WFK34XMW', 1, 'available', NOW(), NOW()),
('138e1181-f1bb-4c2e-a487-15afbe7098d6', 'WFK3387M', 1, 'available', NOW(), NOW()),
('138e1181-f1bb-4c2e-a487-15afbe7098d6', 'WFK3215T', 1, 'available', NOW(), NOW());

-- 5. Dell 7E2000 (2TB SAS HDD) — uuid 7a8b9c0d-1e2f-4a3b-8c4d-5e6f7a8b9c0d — qty 2
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('7a8b9c0d-1e2f-4a3b-8c4d-5e6f7a8b9c0d', 'W461FWVH', 1, 'available', NOW(), NOW()),
('7a8b9c0d-1e2f-4a3b-8c4d-5e6f7a8b9c0d', 'W461FWZ6', 1, 'available', NOW(), NOW());

-- 6. HP HRLP0400S5xnNMLC (400GB SAS SSD) — uuid 07bead07-a1aa-4119-b56d-cd027bc04523 — qty 2
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('07bead07-a1aa-4119-b56d-cd027bc04523', 'XYVME1EA', 1, 'available', NOW(), NOW()),
('07bead07-a1aa-4119-b56d-cd027bc04523', 'XYVME4PA', 1, 'available', NOW(), NOW());

-- 7. Dell EMC HFS480G3H2X069N (480GB SATA SSD) — uuid b36fd635-89b3-4247-a7be-87d9d9930f78 — qty 2
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('b36fd635-89b3-4247-a7be-87d9d9930f78', 'BJABN53071370BE5G', 1, 'available', NOW(), NOW()),
('b36fd635-89b3-4247-a7be-87d9d9930f78', 'BJABN53071370BE0W', 1, 'available', NOW(), NOW());

-- 8. HPE MO0400JEFPA (400GB SAS SSD) — uuid 319944c5-f35e-4298-ad75-b6afd243a31b — qty 1
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('319944c5-f35e-4298-ad75-b6afd243a31b', '0QY1PXRA', 1, 'available', NOW(), NOW());

-- 9. HGST SXHLLL (1.92TB SAS SSD) — uuid b1a9a8a0-6bf7-4fac-bc4c-2a26cad14b0a — qty 2
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('b1a9a8a0-6bf7-4fac-bc4c-2a26cad14b0a', 'A045B085', 1, 'available', NOW(), NOW()),
('b1a9a8a0-6bf7-4fac-bc4c-2a26cad14b0a', 'A0464987', 1, 'available', NOW(), NOW());

-- 10. Samsung (RU) MZ-7LH9600 (960GB SATA SSD) — uuid 78f43371-4287-46d2-840f-6458694e5efb — qty 2
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('78f43371-4287-46d2-840f-6458694e5efb', 'S45NNA0TA30178', 1, 'available', NOW(), NOW()),
('78f43371-4287-46d2-840f-6458694e5efb', 'S45NNA0TA30175', 1, 'available', NOW(), NOW());

-- 11. KINGSTON KC600 (2TB SATA SSD, qty 3) — SKIPPED: no serial numbers given on source sheet.

-- 12. EVM EVM25 (2TB SATA SSD) — uuid 3c4d5e6f-7a8b-4c9d-8e0f-1a2b3c4d5e6f — qty 5
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('3c4d5e6f-7a8b-4c9d-8e0f-1a2b3c4d5e6f', 'ESEWK092300446', 1, 'available', NOW(), NOW()),
('3c4d5e6f-7a8b-4c9d-8e0f-1a2b3c4d5e6f', 'ESEWK092300447', 1, 'available', NOW(), NOW()),
('3c4d5e6f-7a8b-4c9d-8e0f-1a2b3c4d5e6f', 'ESESI092301444', 1, 'available', NOW(), NOW()),
('3c4d5e6f-7a8b-4c9d-8e0f-1a2b3c4d5e6f', 'ESEWK092300448', 1, 'available', NOW(), NOW()),
('3c4d5e6f-7a8b-4c9d-8e0f-1a2b3c4d5e6f', 'ESEWK092300445', 1, 'available', NOW(), NOW());

-- 13. Samsung (RU) MZ-ILS3T8A (3.84TB SAS SSD) — uuid e80e7e34-6ab7-4125-aa85-7a0a33cb3f75 — qty 4
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('e80e7e34-6ab7-4125-aa85-7a0a33cb3f75', 'S2JVNCAH600122', 1, 'available', NOW(), NOW()),
('e80e7e34-6ab7-4125-aa85-7a0a33cb3f75', 'S2JVNCAH600105', 1, 'available', NOW(), NOW()),
('e80e7e34-6ab7-4125-aa85-7a0a33cb3f75', 'S2JVNAAH602744', 1, 'available', NOW(), NOW()),
('e80e7e34-6ab7-4125-aa85-7a0a33cb3f75', 'S2JVNAAH602791', 1, 'available', NOW(), NOW());

-- 14. Samsung (RU) MZ-WLR1T9C (1.92TB NVMe SSD) — uuid d55d78c2-cdb6-47da-bd96-0e7e8cbdb201 — qty 3
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('d55d78c2-cdb6-47da-bd96-0e7e8cbdb201', 'S6UHNA0W202296', 1, 'available', NOW(), NOW()),
('d55d78c2-cdb6-47da-bd96-0e7e8cbdb201', 'S6UHNA0W202299', 1, 'available', NOW(), NOW()),
('d55d78c2-cdb6-47da-bd96-0e7e8cbdb201', 'S6UHNC0W401251', 1, 'available', NOW(), NOW());

-- 15. MiPhi MP700G4 (= catalog model MP5018200T, 2TB NVMe) — uuid c9281a4b-ef54-47b2-a6de-7b83f4a2110c — qty 2
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('c9281a4b-ef54-47b2-a6de-7b83f4a2110c', '60016M45J000093', 1, 'available', NOW(), NOW()),
('c9281a4b-ef54-47b2-a6de-7b83f4a2110c', '60016M45J000125', 1, 'available', NOW(), NOW());

-- 16. MiPhi MP500G4 (catalog model MP5021100T, 1TB NVMe) — uuid f05a63ad-51db-4f4f-b770-68cc98a58c71 — qty 6
INSERT IGNORE INTO `storageinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('f05a63ad-51db-4f4f-b770-68cc98a58c71', '60018M35J001254', 1, 'available', NOW(), NOW()),
('f05a63ad-51db-4f4f-b770-68cc98a58c71', '60018M35J001259', 1, 'available', NOW(), NOW()),
('f05a63ad-51db-4f4f-b770-68cc98a58c71', '60018M35J001255', 1, 'available', NOW(), NOW()),
('f05a63ad-51db-4f4f-b770-68cc98a58c71', '60018M35J001256', 1, 'available', NOW(), NOW()),
('f05a63ad-51db-4f4f-b770-68cc98a58c71', '60018M45J000114', 1, 'available', NOW(), NOW()),
('f05a63ad-51db-4f4f-b770-68cc98a58c71', '60018M45J000113', 1, 'available', NOW(), NOW());
