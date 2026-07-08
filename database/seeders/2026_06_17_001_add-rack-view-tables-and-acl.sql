-- ============================================================
-- Seeder : 2026_06_17_001_add-rack-view-tables-and-acl
-- Date   : 2026-06-17
-- Purpose: Introduce a structured rack model for the new Rack View
--          feature. Adds two tables (`racks`, `rack_servers`) and the
--          ACL permissions that gate the rack module endpoints.
-- Tables : racks (NEW), rack_servers (NEW), permissions (rows),
--          role_permissions (rows)
-- Notes  : Idempotent. Safe to re-run.
--            * CREATE TABLE IF NOT EXISTS for both tables.
--            * Permission rows inserted only when missing (NOT EXISTS).
--            * Each rack.* permission is granted to exactly the roles
--              that already hold the matching server.* permission, so
--              rack access mirrors existing server access. super_admin
--              and admin roles bypass ACL in code and need no rows.
-- Feature: Rack View (visualise racks + installed servers in U-slots).
-- ============================================================

-- ------------------------------------------------------------
-- 1. racks — one physical rack (cabinet)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `racks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `rack_uuid` VARCHAR(36) NOT NULL COMMENT 'Stable public identifier',
  `name` VARCHAR(100) NOT NULL COMMENT 'Display name, e.g. RACK 683',
  `location` VARCHAR(100) DEFAULT NULL COMMENT 'Datacenter / room, e.g. Noida',
  `total_u` INT(11) NOT NULL DEFAULT 42 COMMENT 'Rack height in rack units (U)',
  `numbering_top_down` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = U1 at bottom, 1 = U1 at top',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(6) UNSIGNED DEFAULT NULL COMMENT 'User who created the rack',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_racks_rack_uuid` (`rack_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Physical racks for the Rack View feature';

-- ------------------------------------------------------------
-- 2. rack_servers — placement of a server configuration in a rack
--    A server lives in at most one rack at one position (UNIQUE config_uuid).
--    u_height is a snapshot derived from the server's chassis u_size at
--    assignment time; overlaps are prevented in the application layer.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rack_servers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `rack_uuid` VARCHAR(36) NOT NULL COMMENT 'FK (logical) -> racks.rack_uuid',
  `config_uuid` VARCHAR(36) NOT NULL COMMENT 'FK (logical) -> server_configurations.config_uuid',
  `start_u` INT(11) NOT NULL COMMENT 'Lowest U occupied (1-based)',
  `u_height` INT(11) NOT NULL DEFAULT 1 COMMENT 'Number of U occupied (>=1)',
  `created_by` INT(6) UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rack_servers_config` (`config_uuid`),
  KEY `idx_rack_servers_rack` (`rack_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Server placements within racks (U-position)';

-- ------------------------------------------------------------
-- 3. ACL permissions for the rack module
--    Grouped under the existing 'server_management' category so they
--    appear alongside server permissions in the ACL UI.
-- ------------------------------------------------------------
-- NOTE: every column in the derived table is aliased. Without aliases the
-- literal value becomes the column name, and MySQL/MariaDB column names are
-- case-insensitive — e.g. 'Delete Racks' and 'Delete racks' would collide
-- with error #1060 "Duplicate column name".
INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'rack.view' AS `name`, 'View Racks' AS `display_name`, 'View racks and the servers installed in them' AS `description`, 'server_management' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'rack.view');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'rack.create' AS `name`, 'Create Racks' AS `display_name`, 'Create new racks' AS `description`, 'server_management' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'rack.create');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'rack.edit' AS `name`, 'Edit Racks' AS `display_name`, 'Edit rack details' AS `description`, 'server_management' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'rack.edit');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'rack.delete' AS `name`, 'Delete Racks' AS `display_name`, 'Delete racks' AS `description`, 'server_management' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'rack.delete');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'rack.assign' AS `name`, 'Assign Servers to Racks' AS `display_name`, 'Place, move and remove servers in racks' AS `description`, 'server_management' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'rack.assign');

-- ------------------------------------------------------------
-- 4. Grant each rack.* permission to the roles that already hold the
--    equivalent server.* permission. Data-driven (no hardcoded role ids)
--    and idempotent. super_admin/admin bypass ACL in code, so they are
--    intentionally not required here.
--      rack.view   <- server.view
--      rack.create <- server.create
--      rack.edit   <- server.edit
--      rack.delete <- server.delete
--      rack.assign <- server.edit
--
--    Grants are staged in a TEMPORARY table first so the final INSERT
--    never reads the role_permissions target inside a subquery (avoids
--    MySQL/MariaDB error 1093 on stricter versions).
-- ------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `_rack_grants`;
CREATE TEMPORARY TABLE `_rack_grants` (`role_id` INT NOT NULL, `permission_id` INT NULL);

INSERT INTO `_rack_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'rack.view' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.view');

INSERT INTO `_rack_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'rack.create' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.create');

INSERT INTO `_rack_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'rack.edit' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.edit');

INSERT INTO `_rack_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'rack.delete' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.delete');

INSERT INTO `_rack_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'rack.assign' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.edit');

-- Drop grants that already exist (idempotency) and any that resolved to NULL.
DELETE g FROM `_rack_grants` g
JOIN `role_permissions` e ON e.`role_id` = g.`role_id` AND e.`permission_id` = g.`permission_id`;
DELETE FROM `_rack_grants` WHERE `permission_id` IS NULL;

-- Apply the remaining grants (source is the temp table only — no 1093 risk).
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT `role_id`, `permission_id`, 1 FROM `_rack_grants`;

DROP TEMPORARY TABLE IF EXISTS `_rack_grants`;

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SHOW TABLES LIKE 'rack%';
--   SELECT name, category FROM permissions WHERE name LIKE 'rack.%';
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name LIKE 'rack.%' ORDER BY r.name, p.name;
--
-- Expected: racks + rack_servers tables exist; 5 rack.* permissions;
-- rack grants mirror server grants for every non-admin role.
-- ============================================================
