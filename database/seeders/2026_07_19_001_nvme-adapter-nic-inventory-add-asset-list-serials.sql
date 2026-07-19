-- Seeder: 2026_07_19_001_nvme-adapter-nic-inventory-add-asset-list-serials.sql
-- Date: 2026-07-19
-- Purpose: Add real inventory rows (one row per physical serialized unit) for the
--          NVMe Adapter (2 items) and NIC Card (5 items) sections of the "ims Details.pdf"
--          asset list. Component spec catalog entries for these models were already
--          added in ims-data/pciecard/pci-level-3.json and ims-data/nic/nic-level-3.json
--          (see tasks/component-catalog-additions.md, rows 17-23).
-- Affected tables: pciecardinventory, nicinventory
-- Related feature: component-catalog-additions (2026-07-18/19)
--
-- Rules applied (same as 2026_07_18_001_storage-inventory-add-asset-list-serials.sql):
--   - One row per serial number (quantity is implicit in row count, not stored).
--   - Rows inserted as Status=1 / status_v2='available' (new stock, not yet installed
--     in any server) — ServerUUID left NULL.
--   - Location/RackPosition/PurchaseDate/WarrantyEndDate unknown from the source sheet,
--     intentionally left NULL (no fabricated data).
--   - Generic PCI-E x16 Quad M.2 NVMe Adapter (4-port), qty 9, had NO serial numbers on
--     the source sheet — per instruction, SKIPPED ENTIRELY, not seeded.
--   - INSERT IGNORE makes this idempotent against the SerialNumber UNIQUE key, safe to
--     re-run.
    
-- ===================== NVMe ADAPTER (pciecardinventory) =====================

-- 17. Generic PCIe x16 Quad M.2 NVMe Adapter (4-Port) (qty 9) — SKIPPED: no serial numbers given on source sheet.

-- 18. Generic M-Key M.2 NVMe Adapter (Half-Length) — uuid e20b7772-bdb9-454d-941e-4e43fd1e5fba — qty 2
INSERT IGNORE INTO `pciecardinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('e20b7772-bdb9-454d-941e-4e43fd1e5fba', 'BN26160154814', 1, 'available', NOW(), NOW()),
('e20b7772-bdb9-454d-941e-4e43fd1e5fba', 'BN26160154813', 1, 'available', NOW(), NOW());

-- ===================== NIC CARD (nicinventory) =====================

-- 19. HPE EDC:0F-5349 (10G, unverified model) — uuid a405061d-c5d3-4fa5-a352-56859bb179d6 — qty 1
INSERT IGNORE INTO `nicinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('a405061d-c5d3-4fa5-a352-56859bb179d6', 'CK53BP5203', 1, 'available', NOW(), NOW());

-- 20. HPE HSTNS-BN80 (544FLR-QSFP FlexibleLOM, 40G) — uuid b32ff113-a672-4f13-a45b-a6704cea61eb — qty 1
INSERT IGNORE INTO `nicinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('b32ff113-a672-4f13-a45b-a6704cea61eb', 'IL272002UA', 1, 'available', NOW(), NOW());

-- 21. Unverified brand/model, 16G (flagged — likely Fibre Channel, not Ethernet) — uuid 94670400-b74c-4382-bcef-b1ef06ff98f3 — qty 2
INSERT IGNORE INTO `nicinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('94670400-b74c-4382-bcef-b1ef06ff98f3', 'RFD2147V33219', 1, 'available', NOW(), NOW()),
('94670400-b74c-4382-bcef-b1ef06ff98f3', 'RFD2147V33876', 1, 'available', NOW(), NOW());

-- 22. HPE, unverified model, 10G — uuid e5592301-c38f-4a4f-be21-ad3dced7408d — qty 2
INSERT IGNORE INTO `nicinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('e5592301-c38f-4a4f-be21-ad3dced7408d', 'MY194508K5', 1, 'available', NOW(), NOW()),
('e5592301-c38f-4a4f-be21-ad3dced7408d', 'MY19450868', 1, 'available', NOW(), NOW());

-- 23. Intel, unverified model, 1G — uuid 83f7ddb2-ef97-4d42-ac66-9b78f571f763 — qty 1
INSERT IGNORE INTO `nicinventory` (`UUID`, `SerialNumber`, `Status`, `status_v2`, `CreatedAt`, `UpdatedAt`) VALUES
('83f7ddb2-ef97-4d42-ac66-9b78f571f763', '00JY856', 1, 'available', NOW(), NOW());
