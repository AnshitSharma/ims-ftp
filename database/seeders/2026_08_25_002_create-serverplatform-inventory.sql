-- =============================================================================
-- 2026_08_25_002_create-serverplatform-inventory.sql
--
-- Date:     2026-08-25
-- Purpose:  Promote the server compute platform from a grouping over the
--           motherboard catalog into the 12th component type: a physical box we
--           stock, with its own inventory table and ACL module.
-- Tables:   serverplatforminventory (NEW), permissions, role_permissions
-- Feature:  Server Compute Platform rebuild -- tasks/todo.md
--
-- =============================================================================
-- WHY
--
--   A platform (HPE ProLiant DL360 Gen9, Dell PowerEdge R740) is a box we buy.
--   Its system board and its chassis are INSIDE that box; they are not the loose
--   motherboard / chassis spares of the same model, which stay separately
--   stocked for custom builds. So the box itself is what needs an inventory row,
--   and the board and chassis inside it need none at all -- their specs live in
--   ims-data/serverplatform/server-platform-level-3.json.
--
--   The STOCKED SKU IS THE VERSION, not the platform. One platform ships as an
--   8 x 2.5" SFF build and a 4 x 3.5" LFF build with different chassis and
--   different bay counts, and those are different things to have on a shelf.
--   `serverplatforminventory`.`UUID` therefore holds a VERSION uuid (a
--   `models[].uuid` in the spec file), never a `platform_uuid`.
--
-- =============================================================================
-- RUN ORDER
--
--   Backend code auto-deploys on save; this file does not. Until it is run,
--   `serverplatform` is registered in VALID_COMPONENT_TYPES but has no table.
--   That is a handled state, not a breakage: inventoryTableExists() makes the
--   fleet-wide loops (dashboard counts, global search, vendor rollups) skip the
--   type, ServerPlatformCatalog reports zero stock so every version renders
--   "Out of stock" and unselectable, and releaseAllComponents() skips the table.
--
--   Run this file, then 2026_08_25_003, then (optionally) 2026_08_25_004.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. serverplatforminventory -- column-for-column mirror of chassisinventory,
--    plus FailDate per the contract set by seeder 2026_06_15_001.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `serverplatforminventory` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `UUID` varchar(50) NOT NULL COMMENT 'Platform VERSION uuid -- models[].uuid in serverplatform/server-platform-level-3.json',
  `AssetTag` varchar(20) DEFAULT NULL COMMENT 'System-issued unique unit identifier (BDC-SPF-nnnnnn)',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial / service tag of the box',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `status_v2` enum('available','reserved','allocated','installed','active','maintenance','failed','retired') DEFAULT NULL,
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of the server configuration this box is built into, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When built into its current server',
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
  KEY `idx_serverplatform_status` (`Status`),
  KEY `idx_uuid` (`UUID`),
  KEY `idx_server_uuid` (`ServerUUID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 2. ACL -- serverplatform.{view,create,edit,delete}
--
--    Granted to exactly the roles that already hold the matching chassis.*
--    permission. Anyone who could manage chassis stock can manage platform
--    stock; nobody gains access to anything they could not already reach.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`) VALUES
  ('serverplatform.view',   'View Server Compute Platforms',   'View server compute platform inventory',    'inventory', 1),
  ('serverplatform.create', 'Create Server Compute Platforms', 'Add server compute platforms to inventory', 'inventory', 0),
  ('serverplatform.edit',   'Edit Server Compute Platforms',   'Edit server compute platform inventory',    'inventory', 0),
  ('serverplatform.delete', 'Delete Server Compute Platforms', 'Delete server compute platform inventory',  'inventory', 0);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT rp.`role_id`, new_p.`id`, rp.`granted`
  FROM `role_permissions` rp
  JOIN `permissions` old_p ON old_p.`id` = rp.`permission_id`
  JOIN `permissions` new_p ON new_p.`name` = REPLACE(old_p.`name`, 'chassis.', 'serverplatform.')
 WHERE old_p.`name` IN ('chassis.view', 'chassis.create', 'chassis.edit', 'chassis.delete');


-- =============================================================================
-- Verification (run after the seeder):
--
--   SHOW COLUMNS FROM serverplatforminventory;
--   SELECT COUNT(*) FROM serverplatforminventory;                     -- 0
--   SELECT name FROM permissions WHERE name LIKE 'serverplatform.%';  -- 4 rows
--
--   SELECT r.name, p.name
--     FROM role_permissions rp
--     JOIN roles r       ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name LIKE 'serverplatform.%'
--    ORDER BY r.name, p.name;
--   -- expected: mirrors the chassis.* grants exactly
-- =============================================================================
