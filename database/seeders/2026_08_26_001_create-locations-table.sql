-- =============================================================================
-- Date:     2026-08-26
-- Purpose:  Give the system a real notion of WHERE things are.
--
--           Until now "location" was three unrelated free-text fields --
--           racks.location, server_configurations.location and
--           {type}inventory.Location -- plus a six-option dropdown hardcoded in
--           three separate places in the frontend. Nothing linked them, so the
--           question "where is this component?" had no answer the system could
--           give, and a rack in "Noida" and a server in "Noida Yotta" looked
--           like different places to every query.
--
--           This file creates the entity those three fields were approximating,
--           and the ACL rows that gate it. It changes NO existing data and
--           breaks nothing: the free-text columns stay exactly as they are until
--           seeder 003 links them.
--
-- Tables:   locations (CREATE), permissions (4 rows), role_permissions (rows)
-- Feature:  Location hierarchy + server relocation, part 1 of 5
-- Requires: nothing. This is the first file of the set. Run 001 -> 005 in order.
--
-- WHY name IS UNIQUE
--   The whole point is that two spellings of the same site stop being two
--   places. A duplicate name would silently reintroduce exactly the split this
--   file exists to end, and seeder 002 relies on the constraint to dedupe the
--   text values it harvests.
--
-- WHY latitude/longitude ARE decimal(10,7)
--   Seven decimal places is ~1cm at the equator -- more than a datacenter needs
--   and small enough to compare exactly. FLOAT would make two readings of the
--   same site unequal.
--
-- WHY location.view IS is_basic = 1
--   Every component page, the Add Component form, the Create Server form and the
--   Bulk Update dialog all need to render a location dropdown. If viewing
--   locations were an elevated permission, those dropdowns would come back empty
--   for ordinary users and they could no longer file a component at all.
--   Location NAMES are not sensitive; creating and deleting them is, and those
--   three permissions are gated normally AND role-gated to admin/super_admin in
--   api.php on top.
--
-- Notes:    - No FOREIGN KEY. racks/rack_servers/pipeline_* are all logical FKs
--             by house convention (see 2026_06_17_001); locations follows suit.
--           - is_active lets a closed site be retired without deleting history
--             that points at it.
--           - Grants are data-driven -- no hardcoded role ids. location.view is
--             granted to every role that can view ANYTHING today, because every
--             one of those roles has a dropdown that needs it. The three write
--             permissions mirror rack.create/.edit/.delete exactly.
--           - super_admin and admin bypass ACL in code and need no rows.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS; permissions inserted only WHERE NOT
--             EXISTS; grants staged in a TEMPORARY table with already-present
--             rows deleted before the final INSERT. Re-running is a no-op.
-- Rollback:   rollback/2026_08_26_001_create-locations-table_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state -- the free-text values that exist today. These are what
--    seeder 002 will turn into rows. Nothing here is modified by THIS file.
-- ---------------------------------------------------------------------------
SELECT 'racks.location' AS source, `location` AS value, COUNT(*) AS rows_with_it
  FROM `racks`
 WHERE `location` IS NOT NULL AND TRIM(`location`) <> ''
 GROUP BY `location`
UNION ALL
SELECT 'server_configurations.location', `location`, COUNT(*)
  FROM `server_configurations`
 WHERE `location` IS NOT NULL AND TRIM(`location`) <> ''
 GROUP BY `location`
 ORDER BY 1, 2;

-- ---------------------------------------------------------------------------
-- 1. locations -- one physical site. Columns mirror what the Locations page
--    shows: Name, Objects (derived), Description, Address, Coordinates.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `locations` (
  `id`            INT(11)       NOT NULL AUTO_INCREMENT,
  `location_uuid` CHAR(36)      NOT NULL COMMENT 'Stable public identifier',
  `name`          VARCHAR(100)  NOT NULL COMMENT 'Display name, e.g. Yotta Noida',
  `description`   VARCHAR(255)  DEFAULT NULL COMMENT 'Short label, e.g. CtrlS',
  `address`       VARCHAR(255)  DEFAULT NULL COMMENT 'Postal address, free text',
  `latitude`      DECIMAL(10,7) DEFAULT NULL COMMENT 'WGS84, ~1cm precision',
  `longitude`     DECIMAL(10,7) DEFAULT NULL COMMENT 'WGS84, ~1cm precision',
  `is_active`     TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '0 = retired site, kept for history',
  `notes`         TEXT          DEFAULT NULL,
  `created_by`    INT(6) UNSIGNED DEFAULT NULL COMMENT 'Logical FK -> users.id',
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_locations_uuid` (`location_uuid`),
  UNIQUE KEY `uq_locations_name` (`name`),
  KEY `idx_locations_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Physical sites. A rack belongs to one; a component inherits its rack location.';

-- ---------------------------------------------------------------------------
-- 2. ACL permissions. Category matches rack.* so they group together in the
--    ACL UI. Every column in the derived table is ALIASED -- without aliases
--    the literal becomes the column name and the case-insensitive column names
--    collide with error #1060 (same trap documented in 2026_06_17_001).
-- ---------------------------------------------------------------------------
INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'location.view' AS `name`, 'View Locations' AS `display_name`, 'View locations and the racks, servers and components at them' AS `description`, 'server_management' AS `category`, 1 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'location.view');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'location.create' AS `name`, 'Create Locations' AS `display_name`, 'Add a new physical site' AS `description`, 'server_management' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'location.create');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'location.edit' AS `name`, 'Edit Locations' AS `display_name`, 'Change a location name, address or coordinates' AS `description`, 'server_management' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'location.edit');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'location.delete' AS `name`, 'Delete Locations' AS `display_name`, 'Remove a location that nothing references' AS `description`, 'server_management' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'location.delete');

-- ---------------------------------------------------------------------------
-- 3. Grants. Staged in a TEMPORARY table so the final INSERT never reads
--    role_permissions inside a subquery (MySQL/MariaDB error 1093).
--      location.view   <- ANY existing .view permission (dropdowns need it)
--      location.create <- rack.create
--      location.edit   <- rack.edit
--      location.delete <- rack.delete
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `_location_grants`;
CREATE TEMPORARY TABLE `_location_grants` (`role_id` INT NOT NULL, `permission_id` INT NULL);

INSERT INTO `_location_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'location.view' ORDER BY `id` LIMIT 1)
  FROM `role_permissions` rp
 WHERE rp.`granted` = 1
   AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` LIKE '%.view');

INSERT INTO `_location_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'location.create' ORDER BY `id` LIMIT 1)
  FROM `role_permissions` rp
 WHERE rp.`granted` = 1
   AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'rack.create');

INSERT INTO `_location_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'location.edit' ORDER BY `id` LIMIT 1)
  FROM `role_permissions` rp
 WHERE rp.`granted` = 1
   AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'rack.edit');

INSERT INTO `_location_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'location.delete' ORDER BY `id` LIMIT 1)
  FROM `role_permissions` rp
 WHERE rp.`granted` = 1
   AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'rack.delete');

-- Idempotency: drop grants that already exist, and any that resolved to NULL.
DELETE g FROM `_location_grants` g
JOIN `role_permissions` e ON e.`role_id` = g.`role_id` AND e.`permission_id` = g.`permission_id`;
DELETE FROM `_location_grants` WHERE `permission_id` IS NULL;

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT `role_id`, `permission_id`, 1 FROM `_location_grants`;

DROP TEMPORARY TABLE IF EXISTS `_location_grants`;

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. The table exists with all twelve columns.
SHOW COLUMNS FROM `locations`;

-- 2. Both UNIQUE keys are in place -- uq_locations_name is what makes seeder
--    002 dedupe correctly. MUST list uq_locations_uuid and uq_locations_name.
SHOW INDEX FROM `locations`;

-- 3. Empty at this point. Seeder 002 fills it. MUST return 0.
SELECT COUNT(*) AS location_rows FROM `locations`;

-- 4. Exactly four permissions, location.view basic and the rest not.
SELECT `name`, `display_name`, `category`, `is_basic`
  FROM `permissions` WHERE `name` LIKE 'location.%' ORDER BY `name`;

-- 5. Who got what. location.view should reach every role that can view
--    anything; the three writes should match rack.* exactly.
SELECT r.`name` AS role, p.`name` AS perm
  FROM `role_permissions` rp
  JOIN `roles` r       ON r.`id` = rp.`role_id`
  JOIN `permissions` p ON p.`id` = rp.`permission_id`
 WHERE p.`name` LIKE 'location.%' AND rp.`granted` = 1
 ORDER BY r.`name`, p.`name`;
