-- =============================================================================
-- 2026_09_30_001_cable-inventory.sql
--
-- Date:     2026-09-30
-- Purpose:  Add the 14th component type, `cable` (fibre and copper patch cords):
--           a stocked, asset-tagged unit (BDC-CBL-nnnnnn) that is never installed
--           in a server. unit_uuid is the per-unit key the NMS links to ports.
-- Tables:   cableinventory (NEW), ticket_items (component_type ENUM widened),
--           permissions, role_permissions
-- Feature:  IMS 1.1 cable inventory -- NMS plan section 6.1
--
-- BEFORE YOU RUN
--   SHOW COLUMNS FROM `ticket_items` LIKE 'component_type';
--   Section 3 REDEFINES the whole ENUM. If the live definition holds a value that
--   is not in the list there, add it first -- MariaDB is not in strict mode here,
--   so a dropped value silently turns every row that used it into ''.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. cableinventory -- networkdeviceinventory's columns plus unit_uuid.
--    UUID is the catalogue SKU (models[].uuid in cable/cable-level-3.json), shared
--    by identical cables. AssetTag is the printed label; addComponent() writes it
--    in an UPDATE right after the INSERT, so it MUST stay NULLable.
--    unit_uuid is filled by the database (MariaDB >= 10.2.1). No code writes it,
--    and getEditableComponentColumns() keeps clients from changing it.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cableinventory` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `UUID` varchar(50) NOT NULL COMMENT 'Catalogue SKU -- models[].uuid in cable/cable-level-3.json',
  `unit_uuid` char(36) NOT NULL DEFAULT uuid() COMMENT 'Per-unit key shared with the NMS',
  `AssetTag` varchar(20) DEFAULT NULL COMMENT 'System-issued label (BDC-CBL-nnnnnn)',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Maker serial, only when the cable has one',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed, 1=Available, 2=In Use',
  `status_v2` enum('available','reserved','allocated','installed','active','maintenance','failed','retired') DEFAULT NULL,
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'Always NULL: a cable is not installed in a server',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Site name',
  `location_uuid` char(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid',
  `RackPosition` varchar(20) DEFAULT NULL,
  `StoreLocation` varchar(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL,
  `WarrantyEndDate` date DEFAULT NULL,
  `FailDate` date DEFAULT NULL,
  `Flag` varchar(50) DEFAULT NULL,
  `Notes` text DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `VendorID` int(11) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_asset_tag` (`AssetTag`),
  UNIQUE KEY `idx_serial_number` (`SerialNumber`),
  UNIQUE KEY `uq_cableinventory_unit_uuid` (`unit_uuid`),
  KEY `idx_cable_status` (`Status`),
  KEY `idx_uuid` (`UUID`),
  KEY `idx_server_uuid` (`ServerUUID`),
  KEY `idx_cableinventory_location` (`location_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 2. ACL -- cable.{view,create,edit,delete}, granted to exactly the roles that
--    hold the matching chassis.* permission (the serverplatform and networkdevice
--    precedent; chassis.* rows are known to exist).
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`) VALUES
  ('cable.view',   'View Cables',   'View cable inventory (fibre and copper patch cords)', 'inventory', 1),
  ('cable.create', 'Create Cables', 'Add cables to inventory',                             'inventory', 0),
  ('cable.edit',   'Edit Cables',   'Edit cable inventory',                                'inventory', 0),
  ('cable.delete', 'Delete Cables', 'Delete cable inventory',                              'inventory', 0);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT rp.`role_id`, new_p.`id`, rp.`granted`
  FROM `role_permissions` rp
  JOIN `permissions` old_p ON old_p.`id` = rp.`permission_id`
  JOIN `permissions` new_p ON new_p.`name` = REPLACE(old_p.`name`, 'chassis.', 'cable.')
 WHERE old_p.`name` IN ('chassis.view', 'chassis.create', 'chassis.edit', 'chassis.delete');

-- -----------------------------------------------------------------------------
-- 3. ticket_items.component_type -- widen to all 14 types. Keeps networkdevice
--    whether or not seeder 2026_09_29_001 has run. Re-running is a no-op.
-- -----------------------------------------------------------------------------
ALTER TABLE `ticket_items`
    MODIFY COLUMN `component_type` ENUM(
        'chassis',
        'motherboard',
        'cpu',
        'ram',
        'storage',
        'nic',
        'hbacard',
        'pciecard',
        'risercard',
        'caddy',
        'sfp',
        'serverplatform',
        'networkdevice',
        'cable'
    ) NOT NULL;

-- =============================================================================
-- Verification (run after the seeder):
--
--   SHOW COLUMNS FROM cableinventory;                                  -- unit_uuid default uuid()
--   SELECT name FROM permissions WHERE name LIKE 'cable.%';            -- 4 rows
--   SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name LIKE 'cable.%';                                      -- same as the next line
--   SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name LIKE 'chassis.%';
--   SHOW COLUMNS FROM ticket_items LIKE 'component_type';              -- 14 values
-- =============================================================================
