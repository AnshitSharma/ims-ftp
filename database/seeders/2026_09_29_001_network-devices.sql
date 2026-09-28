-- =============================================================================
-- 2026_09_29_001_network-devices.sql
--
-- Date:     2026-09-29
-- Purpose:  Add the 13th component type, `networkdevice` (routers, switches, MUXes):
--           a stocked unit that can be racked beside servers and traced by site.
-- Tables:   networkdeviceinventory (NEW), rack_network_devices (NEW),
--           ticket_items (component_type ENUM widened), permissions, role_permissions
-- Feature:  Network Devices -- tasks/network-devices.md
--
-- =============================================================================
-- BEFORE YOU RUN
--
--   Run this first and read the result:
--
--     SHOW COLUMNS FROM `ticket_items` LIKE 'component_type';
--
--   The ALTER in section 4 REDEFINES the whole ENUM. If the live definition holds a
--   value that is not in the list written there, add it to that list first --
--   MariaDB is not in strict mode here, so a dropped value silently turns every row
--   that used it into ''.
--
-- RUN ORDER
--
--   Backend code auto-deploys on save; this file does not. Until it is run,
--   `networkdevice` has no table. That is a handled state: inventoryTableExists()
--   makes the fleet-wide loops skip it, and every rack-device endpoint answers 503.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. networkdeviceinventory -- mirror of serverplatforminventory, plus the
--    location_uuid / StoreLocation pair every other inventory table got from
--    seeder 2026_08_26_003. UUID is the MODEL uuid from
--    ims-data/networkdevice/network-device-level-3.json. ServerUUID stays for
--    shape uniformity with the other twelve; a network device is never installed
--    inside a server, so it is always NULL.
--
--    Status: 0 failed, 1 available (loose stock), 2 in_use (racked).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `networkdeviceinventory` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `UUID` varchar(50) NOT NULL COMMENT 'Model uuid -- models[].uuid in networkdevice/network-device-level-3.json',
  `AssetTag` varchar(20) DEFAULT NULL COMMENT 'System-issued unique unit identifier (BDC-NET-nnnnnn)',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial / service tag',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use (racked)',
  `status_v2` enum('available','reserved','allocated','installed','active','maintenance','failed','retired') DEFAULT NULL,
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'Always NULL: a network device is not installed in a server',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `location_uuid` char(36) DEFAULT NULL COMMENT 'Logical FK -> locations.location_uuid',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'U range when racked, e.g. U40 or U40-U41',
  `StoreLocation` varchar(100) DEFAULT NULL COMMENT 'Shelf / bin for loose stock, e.g. "Shelf B3"',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL,
  `WarrantyEndDate` date DEFAULT NULL,
  `FailDate` date DEFAULT NULL COMMENT 'When the component failed',
  `Flag` varchar(50) DEFAULT NULL COMMENT 'Quick status flag or category',
  `Notes` text DEFAULT NULL COMMENT 'Any additional info or history',
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `VendorID` int(11) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_asset_tag` (`AssetTag`),
  UNIQUE KEY `idx_serial_number` (`SerialNumber`),
  KEY `idx_networkdevice_status` (`Status`),
  KEY `idx_uuid` (`UUID`),
  KEY `idx_server_uuid` (`ServerUUID`),
  KEY `idx_networkdeviceinventory_location` (`location_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- 2. rack_network_devices -- where a device sits in a rack. The counterpart of
--    rack_servers, keyed on the physical unit. inventory_id is UNIQUE, so one
--    unit is in exactly one place. u_height is a snapshot of the spec's u_size
--    taken at placement, so the elevation keeps drawing the box that is bolted
--    in even if the spec is later edited.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rack_network_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rack_uuid` varchar(36) NOT NULL COMMENT 'FK (logical) -> racks.rack_uuid',
  `inventory_id` int(11) NOT NULL COMMENT 'FK (logical) -> networkdeviceinventory.ID',
  `start_u` int(11) NOT NULL COMMENT 'Lowest U occupied (1-based)',
  `u_height` int(11) NOT NULL DEFAULT 1 COMMENT 'Number of U occupied (>=1), snapshot of the spec u_size',
  `created_by` int(6) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rack_network_devices_unit` (`inventory_id`),
  KEY `idx_rack_network_devices_rack` (`rack_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Network device placements within racks (U-position)';

-- -----------------------------------------------------------------------------
-- 3. ACL -- networkdevice.{view,create,edit,delete}
--
--    Granted to exactly the roles that already hold the matching chassis.*
--    permission (the serverplatform precedent). Racking a device reuses
--    rack.assign, so there is no new rack permission.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`) VALUES
  ('networkdevice.view',   'View Network Devices',   'View network device inventory (routers, switches, MUXes)', 'inventory', 1),
  ('networkdevice.create', 'Create Network Devices', 'Add network devices to inventory',                        'inventory', 0),
  ('networkdevice.edit',   'Edit Network Devices',   'Edit network device inventory',                           'inventory', 0),
  ('networkdevice.delete', 'Delete Network Devices', 'Delete network device inventory',                         'inventory', 0);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT rp.`role_id`, new_p.`id`, rp.`granted`
  FROM `role_permissions` rp
  JOIN `permissions` old_p ON old_p.`id` = rp.`permission_id`
  JOIN `permissions` new_p ON new_p.`name` = REPLACE(old_p.`name`, 'chassis.', 'networkdevice.')
 WHERE old_p.`name` IN ('chassis.view', 'chassis.create', 'chassis.edit', 'chassis.delete');

-- -----------------------------------------------------------------------------
-- 4. ticket_items.component_type -- widen to all 13 types.
--
--    A Request line item writes its type into this ENUM. Seeder 2026_08_24_001
--    left it at 11 values, so an item naming `serverplatform` (and now
--    `networkdevice`) is stored as '' with only a warning. This is a full column
--    redefinition, so re-running it is a no-op. See "BEFORE YOU RUN" above.
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
        'networkdevice'
    ) NOT NULL;

-- =============================================================================
-- Verification (run after the seeder):
--
--   SHOW COLUMNS FROM networkdeviceinventory;
--   SHOW COLUMNS FROM rack_network_devices;
--   SELECT COUNT(*) FROM networkdeviceinventory;                        -- 0
--   SELECT name FROM permissions WHERE name LIKE 'networkdevice.%';     -- 4 rows
--   SHOW COLUMNS FROM ticket_items LIKE 'component_type';               -- 13 values
--   SELECT COUNT(*) FROM ticket_items WHERE component_type = '';        -- rows already blanked
-- =============================================================================
