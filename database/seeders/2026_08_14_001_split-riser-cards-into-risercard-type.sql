-- =============================================================================
-- 2026_08_14_001_split-riser-cards-into-risercard-type.sql
--
-- Date:     2026-08-14
-- Purpose:  Promote Riser Cards from a component_subtype of 'pciecard' to a
--           first-class 11th component type, 'risercard'.
-- Tables:   risercardinventory (NEW), pciecardinventory, config_components,
--           permissions, role_permissions
-- Feature:  Riser/PCIe entity split -- tasks/riser-card-separation.md
--
-- =============================================================================
-- WHY
--
--   Risers and PCIe cards were one entity (one spec file, one inventory table,
--   one ACL module) distinguished only by ims-data's component_subtype field,
--   even though the engine has always treated them as different things: a riser
--   occupies a riser bay and PROVIDES pcie_slots, while a PCIe card CONSUMES
--   one. config_components' component_type ENUM already carried a separate
--   'riser' value for exactly this reason.
--
--   This seeder finishes the separation: 'risercard' becomes the single name for
--   the type across ims-data, the inventory table, the ACL, and the row store.
--
-- =============================================================================
-- SCOPE MEASURED AGAINST LIVE PRODUCTION (2026-08-14, via pciecard-list)
--
--     pciecardinventory     26 rows -- ALL 26 are NVMe Adaptors, ZERO risers
--     config_components      0 rows of type 'pciecard' or 'riser'
--
--   So the data-moving sections below are expected to migrate ZERO rows today.
--   They are written to be correct for any row count anyway, because rows can be
--   added between this file being written and it being run. Every section is
--   idempotent and safe to re-run.
--
-- =============================================================================
-- IMPORTANT -- RUN ORDER
--
--   ims-data/*.json auto-deploys on save; this file does NOT. The riser groups
--   are therefore still present in BOTH ims-data/risercard/riser-level-3.json
--   and ims-data/pciecard/pci-level-3.json right now, on purpose, so that UUID
--   validation keeps succeeding no matter which side of this migration a given
--   row is on.
--
--     1. (done) add ims-data/risercard/riser-level-3.json
--     2. (done) deploy backend + frontend code
--   > 3. RUN THIS FILE <
--     4. only then remove the Riser Card groups from pci-level-3.json
--
--   Running step 4 before step 3 breaks validateComponentUuid() for any stocked
--   riser. Do not reorder.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. risercardinventory -- column-for-column mirror of pciecardinventory
--
--    NOTE: `FailDate` is included here per the schema contract established by
--    seeder 2026_06_15_001 ("add FailDate to all inventory tables"), even though
--    live pciecardinventory does NOT currently have that column -- that seeder
--    appears not to have been applied to pciecardinventory. The new table is
--    built to the documented contract rather than replicating that gap; the
--    column is nullable and nothing depends on its absence.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `risercardinventory` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `UUID` varchar(50) NOT NULL COMMENT 'Links to detailed specs in JSON',
  `AssetTag` varchar(20) DEFAULT NULL COMMENT 'System-issued unique unit identifier (BDC-RSR-nnnnnn)',
  `SerialNumber` varchar(50) DEFAULT NULL COMMENT 'Manufacturer serial number',
  `Status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=Failed/Decommissioned, 1=Available, 2=In Use',
  `status_v2` enum('available','reserved','allocated','installed','active','maintenance','failed','retired') DEFAULT NULL,
  `ServerUUID` varchar(36) DEFAULT NULL COMMENT 'UUID of server where riser card is installed, if any',
  `Location` varchar(100) DEFAULT NULL COMMENT 'Physical location like datacenter, warehouse',
  `RackPosition` varchar(20) DEFAULT NULL COMMENT 'Specific rack/shelf position',
  `PurchaseDate` date DEFAULT NULL,
  `InstallationDate` date DEFAULT NULL COMMENT 'When installed in current server',
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
  KEY `idx_risercard_status` (`Status`),
  KEY `idx_uuid` (`UUID`),
  KEY `idx_server_uuid` (`ServerUUID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------------------------------------------------------
-- 2. The 20 riser spec UUIDs (ims-data/risercard/riser-level-3.json)
--
--    Held in a temp table so every later section selects the same set and they
--    can never drift apart mid-run.
-- -----------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_riser_spec_uuids`;
CREATE TEMPORARY TABLE `tmp_riser_spec_uuids` (
  `UUID` varchar(50) NOT NULL,
  PRIMARY KEY (`UUID`)
) ENGINE=MEMORY;

INSERT INTO `tmp_riser_spec_uuids` (`UUID`) VALUES
  ('3b36bc7b-9fc9-4744-acf0-d2637a12c01f'),  -- Supermicro RSC-R1UU-E8R
  ('07613503-1278-48c4-81ec-2348d3bf9c6f'),  -- Supermicro RSC-R1UU-2E8R
  ('2f18b4d6-abc9-4893-977a-e2a1abe83737'),  -- Supermicro RSC-R2UU-E16R
  ('0c71f9d8-c434-4bdf-970f-c091f7c5e535'),  -- Supermicro RSC-R2UU-2E16R
  ('8a9dbddc-fcc2-4795-939e-d05d5dddff7e'),  -- Supermicro RSC-R2UU-3E8R
  ('6d7ba895-eae3-46a5-b50a-5a4947e61f63'),  -- Dell PowerEdge R750 Riser 1
  ('16d67986-edca-45c0-8a5a-4dd858eb0f3d'),  -- Dell PowerEdge R750 Riser 2
  ('f8979637-29c4-4eff-b101-9794ec9d3615'),  -- HPE DL380 Gen10+ Primary Riser
  ('8fd1c776-ff7c-4bd4-8294-1d2dfb81305b'),  -- HPE DL380 Gen10+ Secondary Riser
  ('0b2516ea-6a4c-44e6-9e7f-8ad93cd1dcea'),  -- HPE DL380 Gen10+ Tertiary Riser
  ('d9844fdd-d0ed-49dd-8154-50b27411fd9c'),  -- Supermicro RSC-W4-66G4
  ('fc155119-48b6-4186-9b9d-52dfc98355ef'),  -- Supermicro RSC-W-68G4
  ('cf0ecb44-585e-4c2f-9eea-f64d371884d1'),  -- Supermicro RSC-W2-66
  ('7eb3e0bc-e2fc-4342-b83a-6a72abb2c423'),  -- ASUS PIKE II 3108
  ('446ca54a-a7d3-475b-bddb-5538ee2ce9cf'),  -- ASUS HYPER DUAL Riser
  ('caeb288b-2571-4340-96fa-cdf3a5fe3041'),  -- Lenovo SR650 V2 PCIe FH/FL Riser 1
  ('cd597f2c-63a4-46cd-8d57-8ab3771bebe7'),  -- Lenovo SR650 V2 PCIe FH/FL Riser 2
  ('40738d1c-dbfe-4e28-9169-03429727f9b7'),  -- Lenovo SR650 V2 PCIe LP Riser
  ('4e0714eb-f6fa-4211-a683-7e83ce16a0fc'),  -- Cisco UCS C240 M6 PCIe Riser 1
  ('e5a9da56-05c4-4407-bba5-1380e85ecb80');  -- Cisco UCS C240 M6 PCIe Riser 2


-- -----------------------------------------------------------------------------
-- 3. PRE-FLIGHT -- what is about to move (read this before continuing)
-- -----------------------------------------------------------------------------
SELECT 'PRE-FLIGHT: riser units in pciecardinventory' AS check_name,
       COUNT(*) AS row_count,
       SUM(`Status` = 2) AS in_use_count,
       SUM(`ServerUUID` IS NOT NULL) AS attached_to_server_count
  FROM `pciecardinventory`
 WHERE `UUID` IN (SELECT `UUID` FROM `tmp_riser_spec_uuids`);

SELECT 'PRE-FLIGHT: config_components rows to retype' AS check_name,
       `component_type`, COUNT(*) AS row_count
  FROM `config_components`
 WHERE `removed_at` IS NULL
   AND (`component_type` = 'riser'
        OR (`component_type` = 'pciecard'
            AND `spec_uuid` IN (SELECT `UUID` FROM `tmp_riser_spec_uuids`)))
 GROUP BY `component_type`;


-- -----------------------------------------------------------------------------
-- 4. Move the physical units, PRESERVING THE ORIGINAL `ID`
--
--    Preserving ID is what keeps this migration safe: config_components stores
--    (inventory_table, inventory_id) as a soft FK, so leaving inventory_id
--    untouched means section 5 only has to rewrite two string columns. Nothing
--    has to be remapped, and config_resources.provider_id / consumer_id /
--    parent_id (which reference config_components.id, not inventory) are not
--    touched at all.
--
--    risercardinventory is brand new and empty, so the original IDs cannot
--    collide with anything.
--
--    AssetTag is REISSUED as BDC-RSR-nnnnnn (same LPAD(ID,6,'0') formula as
--    seeder 2026_07_22_001) because the tag encodes the type. The previous tag is
--    appended to Notes so a sticker already on the hardware stays traceable.
-- -----------------------------------------------------------------------------
INSERT INTO `risercardinventory`
  (`ID`, `UUID`, `AssetTag`, `SerialNumber`, `Status`, `status_v2`, `ServerUUID`,
   `Location`, `RackPosition`, `PurchaseDate`, `InstallationDate`, `WarrantyEndDate`,
   `Flag`, `Notes`, `CreatedAt`, `UpdatedAt`, `VendorID`)
SELECT
   p.`ID`,
   p.`UUID`,
   CONCAT('BDC-RSR-', LPAD(p.`ID`, 6, '0')),
   p.`SerialNumber`,
   p.`Status`,
   p.`status_v2`,
   p.`ServerUUID`,
   p.`Location`,
   p.`RackPosition`,
   p.`PurchaseDate`,
   p.`InstallationDate`,
   p.`WarrantyEndDate`,
   p.`Flag`,
   TRIM(CONCAT(
     COALESCE(p.`Notes`, ''),
     CASE WHEN COALESCE(p.`Notes`, '') = '' THEN '' ELSE ' | ' END,
     'Migrated from pciecardinventory by seeder 2026_08_14_001 (riser type split). Previous asset tag: ',
     COALESCE(p.`AssetTag`, '(none)')
   )),
   p.`CreatedAt`,
   p.`UpdatedAt`,
   p.`VendorID`
  FROM `pciecardinventory` p
 WHERE p.`UUID` IN (SELECT `UUID` FROM `tmp_riser_spec_uuids`)
   AND NOT EXISTS (SELECT 1 FROM `risercardinventory` r WHERE r.`ID` = p.`ID`);

-- Keep AUTO_INCREMENT clear of the IDs just carried over.
SET @next_id := (SELECT COALESCE(MAX(`ID`), 0) + 1 FROM `risercardinventory`);
SET @sql := CONCAT('ALTER TABLE `risercardinventory` AUTO_INCREMENT = ', @next_id);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- -----------------------------------------------------------------------------
-- 5. config_components -- one name for the type
--
--    'risercard' must exist in the ENUM before anything can be updated to it,
--    and 'riser' can only be dropped once nothing references it.
-- -----------------------------------------------------------------------------
ALTER TABLE `config_components`
  MODIFY `component_type`
  ENUM('chassis','motherboard','cpu','ram','storage','nic','hbacard','pciecard','riser','risercard','caddy','sfp')
  NOT NULL;

-- 5a. legacy 'riser' rows (the ENUM value nothing ever populated) -> 'risercard'
UPDATE `config_components`
   SET `component_type` = 'risercard',
       `inventory_table` = 'risercardinventory'
 WHERE `component_type` = 'riser';

-- 5b. pciecard rows whose spec is actually a riser -> 'risercard'
UPDATE `config_components`
   SET `component_type` = 'risercard',
       `inventory_table` = 'risercardinventory'
 WHERE `component_type` = 'pciecard'
   AND `spec_uuid` IN (SELECT `UUID` FROM `tmp_riser_spec_uuids`);

-- 5c. retire the old ENUM value now that nothing uses it
ALTER TABLE `config_components`
  MODIFY `component_type`
  ENUM('chassis','motherboard','cpu','ram','storage','nic','hbacard','pciecard','risercard','caddy','sfp')
  NOT NULL;


-- -----------------------------------------------------------------------------
-- 6. ACL -- risercard.{view,create,edit,delete}
--
--    Granted to exactly the roles that already hold the matching pciecard.*
--    permission, so nobody gains or loses access as a result of the split.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`) VALUES
  ('risercard.view',   'View Riser Card Components',   'View riser card inventory',   'inventory', 1),
  ('risercard.create', 'Create Riser Card Components', 'Add riser cards to inventory','inventory', 0),
  ('risercard.edit',   'Edit Riser Card Components',   'Edit riser card inventory',   'inventory', 0),
  ('risercard.delete', 'Delete Riser Card Components', 'Delete riser card inventory', 'inventory', 0);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT rp.`role_id`, new_p.`id`, rp.`granted`
  FROM `role_permissions` rp
  JOIN `permissions` old_p ON old_p.`id` = rp.`permission_id`
  JOIN `permissions` new_p ON new_p.`name` = REPLACE(old_p.`name`, 'pciecard.', 'risercard.')
 WHERE old_p.`name` IN ('pciecard.view', 'pciecard.create', 'pciecard.edit', 'pciecard.delete');


-- -----------------------------------------------------------------------------
-- 7. VERIFY BEFORE THE DELETE
--
--    Expected: moved_count = original_count, and orphan_count = 0.
--    If either fails, STOP -- do not run section 8.
-- -----------------------------------------------------------------------------
SELECT 'VERIFY: unit counts match' AS check_name,
       (SELECT COUNT(*) FROM `pciecardinventory`
         WHERE `UUID` IN (SELECT `UUID` FROM `tmp_riser_spec_uuids`)) AS still_in_pciecard,
       (SELECT COUNT(*) FROM `risercardinventory`)                    AS now_in_risercard;

SELECT 'VERIFY: no config row points at a missing riser unit' AS check_name,
       COUNT(*) AS orphan_count
  FROM `config_components` cc
  LEFT JOIN `risercardinventory` r ON r.`ID` = cc.`inventory_id`
 WHERE cc.`removed_at` IS NULL
   AND cc.`component_type` = 'risercard'
   AND r.`ID` IS NULL;

SELECT 'VERIFY: acl grants mirrored' AS check_name,
       (SELECT COUNT(*) FROM `role_permissions` rp
          JOIN `permissions` p ON p.`id` = rp.`permission_id`
         WHERE p.`name` LIKE 'pciecard.%')  AS pciecard_grants,
       (SELECT COUNT(*) FROM `role_permissions` rp
          JOIN `permissions` p ON p.`id` = rp.`permission_id`
         WHERE p.`name` LIKE 'risercard.%') AS risercard_grants;


-- -----------------------------------------------------------------------------
-- 8. Remove the migrated units from pciecardinventory
--
--    LAST statement that changes data, and deliberately separated from section 4
--    so the verification in section 7 sits between the copy and the delete.
--    Deletes only rows that are provably present in the new table.
-- -----------------------------------------------------------------------------
DELETE p
  FROM `pciecardinventory` p
  JOIN `risercardinventory` r ON r.`ID` = p.`ID`
 WHERE p.`UUID` IN (SELECT `UUID` FROM `tmp_riser_spec_uuids`);


-- -----------------------------------------------------------------------------
-- 9. FINAL STATE
-- -----------------------------------------------------------------------------
SELECT 'FINAL: pciecardinventory holds no risers' AS check_name,
       COUNT(*) AS should_be_zero
  FROM `pciecardinventory`
 WHERE `UUID` IN (SELECT `UUID` FROM `tmp_riser_spec_uuids`);

SELECT 'FINAL: risercardinventory' AS check_name,
       COUNT(*) AS units,
       SUM(`Status` = 1) AS available,
       SUM(`Status` = 2) AS in_use,
       SUM(`AssetTag` LIKE 'BDC-RSR-%') AS correctly_tagged
  FROM `risercardinventory`;

SELECT 'FINAL: config_components by type' AS check_name,
       `component_type`, COUNT(*) AS live_rows
  FROM `config_components`
 WHERE `removed_at` IS NULL
 GROUP BY `component_type`
 ORDER BY `component_type`;

DROP TEMPORARY TABLE IF EXISTS `tmp_riser_spec_uuids`;

-- =============================================================================
-- AFTER RUNNING THIS FILE
--   Remove the 7 "Riser Card" groups from ims-data/pciecard/pci-level-3.json
--   (step 4 of the run order at the top), then re-run the verify scripts:
--     php scripts/verify/inventory_report.php
--     php scripts/verify/slot_report.php
--     php scripts/verify/equivalence_report.php
-- =============================================================================
