-- ============================================================
-- Seeder : 2026_07_13_000_consolidated-migration-schema
-- Date   : 2026-07-13
-- Purpose: CONSOLIDATED schema seeder — RUN THIS FIRST (numbered 000 so it
--          sorts before 005/006). Discovered 2026-07-13: production returned
--          "#1146 config_status_transitions doesn't exist" when running the
--          006 consolidated seeder, proving the six migration schema seeders
--          (2026_07_06_001..003, 2026_07_08_001, 2026_07_10_001..002) were
--          only ever applied to the scratch/test DB, never to production.
--          This file concatenates all six VERBATIM, in dependency order:
--            1. 2026_07_06_001 — config_components table
--            2. 2026_07_06_002 — config_resources table (FKs into 1)
--            3. 2026_07_06_003 — revision column + config_events table
--            4. 2026_07_08_001 — migration_backfill_state + backfill_quarantine
--            5. 2026_07_10_001 — status_v2 columns on server_configurations
--               + all 10 inventory tables, with one-time legacy backfill
--            6. 2026_07_10_002 — config/inventory transition tables + seed rows
-- Tables : config_components, config_resources, config_events (NEW),
--          migration_backfill_state, backfill_quarantine (NEW),
--          config_status_transitions, inventory_status_transitions (NEW),
--          server_configurations (+revision, +status_v2 columns),
--          all 10 {type}inventory tables (+status_v2 column)
-- Notes  : Fully idempotent (CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT
--          EXISTS / dynamic-SQL column guard / INSERT IGNORE / NULL-guarded
--          UPDATEs). Safe to re-run, and safe on a DB where some of the six
--          originals DID already run. Requires MariaDB 10.2.16+ for
--          ADD COLUMN IF NOT EXISTS (production is MariaDB — the originals
--          already rely on this). See each section's original seeder file in
--          ../seeders/ for full design rationale; content here is unmodified.
-- ============================================================

-- ------------------------------------------------------------
-- Section 1/6 — from 2026_07_06_001_create-config-components.sql (U-1.1)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `config_components` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_uuid` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> server_configurations.config_uuid',
  `component_type` ENUM('chassis','motherboard','cpu','ram','storage','nic','hbacard','pciecard','riser','caddy','sfp') NOT NULL,
  `inventory_table` VARCHAR(32) NOT NULL COMMENT 'e.g. raminventory — soft FK target table name (ten tables until unification, see notes)',
  `inventory_id` BIGINT UNSIGNED NOT NULL COMMENT 'Soft FK -> {inventory_table}.id (or equivalent PK)',
  `spec_uuid` CHAR(36) NOT NULL COMMENT 'Catalog UUID from ims-data/{type}/*.json (ComponentDataService)',
  `serial_number` VARCHAR(191) DEFAULT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'e.g. an SFP''s parent NIC row',
  `slot_ref` VARCHAR(64) DEFAULT NULL COMMENT 'PCIe slot / bay / port identifier this unit occupies',
  `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `added_by` INT(11) NOT NULL DEFAULT 0,
  `removed_at` DATETIME DEFAULT NULL COMMENT 'Soft-tombstone during the dual-write window ONLY; hard-deleted at U-D.3',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inventory_once` (`inventory_table`, `inventory_id`),
  UNIQUE KEY `uq_slot_occupancy` (`config_uuid`, `slot_ref`, `removed_at`),
  KEY `k_config` (`config_uuid`),
  KEY `k_parent` (`parent_id`),
  CONSTRAINT `fk_cc_config` FOREIGN KEY (`config_uuid`) REFERENCES `server_configurations` (`config_uuid`),
  CONSTRAINT `fk_cc_parent` FOREIGN KEY (`parent_id`) REFERENCES `config_components` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Row-per-physical-unit replacement for server_configurations'' JSON component columns (migration P1, U-1.1)';

-- ------------------------------------------------------------
-- Section 2/6 — from 2026_07_06_002_create-config-resources.sql (U-1.2)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `config_resources` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_uuid` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> server_configurations.config_uuid',
  `resource` ENUM('cpu_socket','dimm_slot','pcie_slot','pcie_lane','m2_slot','u2_slot',
                  'drive_bay_2_5','drive_bay_3_5','sfp_port','psu_watt','riser_slot') NOT NULL,
  `provider_id` BIGINT UNSIGNED NOT NULL COMMENT 'FK -> config_components.id (the component providing this resource)',
  `slot_ref` VARCHAR(64) DEFAULT NULL COMMENT 'Discrete slot identifier; NULL for scalar/pooled resources (pcie_lane, psu_watt)',
  `capacity` INT(11) NOT NULL COMMENT 'Provider row: units provided. Consumer row: units consumed.',
  `consumer_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> config_components.id (NULL on a provider row; set on a consumer row)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discrete` (`config_uuid`, `resource`, `slot_ref`),
  KEY `k_provider` (`provider_id`),
  KEY `k_consumer` (`consumer_id`),
  CONSTRAINT `fk_cr_config` FOREIGN KEY (`config_uuid`) REFERENCES `server_configurations` (`config_uuid`),
  CONSTRAINT `fk_cr_provider` FOREIGN KEY (`provider_id`) REFERENCES `config_components` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cr_consumer` FOREIGN KEY (`consumer_id`) REFERENCES `config_components` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Resource capacity/consumption ledger (migration P1, U-1.2)';

-- ------------------------------------------------------------
-- Section 3/6 — from 2026_07_06_003_create-config-events-and-revision.sql (U-1.3)
-- ------------------------------------------------------------

SET @revision_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'server_configurations' AND COLUMN_NAME = 'revision'
);
SET @add_revision_sql = IF(@revision_exists = 0,
  'ALTER TABLE `server_configurations` ADD COLUMN `revision` INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE add_revision_stmt FROM @add_revision_sql;
EXECUTE add_revision_stmt;
DEALLOCATE PREPARE add_revision_stmt;

CREATE TABLE IF NOT EXISTS `config_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_uuid` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> server_configurations.config_uuid',
  `revision` INT UNSIGNED NOT NULL COMMENT 'server_configurations.revision at the moment this event was appended',
  `event` ENUM('add','remove','replace','transition','backfill','delete') NOT NULL,
  `component_type` VARCHAR(16) DEFAULT NULL,
  `component_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK (soft) -> config_components.id, when applicable',
  `actor` INT(11) NOT NULL DEFAULT 0,
  `payload` JSON DEFAULT NULL COMMENT 'Free-form snapshot of what changed; shape defined by the writing unit',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_config_rev` (`config_uuid`, `revision`),
  CONSTRAINT `fk_ce_config` FOREIGN KEY (`config_uuid`) REFERENCES `server_configurations` (`config_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audit trail / optimistic-concurrency event log (migration P1, U-1.3)';

-- ------------------------------------------------------------
-- Section 4/6 — from 2026_07_08_001_create-backfill-tables.sql (U-B.1)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `migration_backfill_state` (
  `run_id` VARCHAR(64) NOT NULL,
  `config_uuid` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` ENUM('pending','done','quarantined','error') NOT NULL DEFAULT 'pending',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` TEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`run_id`, `config_uuid`),
  KEY `k_status` (`run_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Per-config backfill progress, keyed by run (migration P2, U-B.1)';

CREATE TABLE IF NOT EXISTS `backfill_quarantine` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` VARCHAR(64) NOT NULL,
  `config_uuid` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_json` JSON NOT NULL COMMENT 'Raw legacy {type, entry} this run could not confidently migrate',
  `reason` VARCHAR(191) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_run_config` (`run_id`, `config_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Legacy component entries the backfill Extractor could not migrate (migration P2, U-B.1)';

-- ------------------------------------------------------------
-- Section 5/6 — from 2026_07_10_001_add-status-v2-columns.sql (U-SM.1)
-- ------------------------------------------------------------

ALTER TABLE `server_configurations`
  ADD COLUMN IF NOT EXISTS `status_v2` ENUM('draft','building','validating','validated','finalized','deployed','maintenance','retired') NULL AFTER `configuration_status`;

ALTER TABLE `cpuinventory`         ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `raminventory`         ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `storageinventory`     ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `motherboardinventory` ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `chassisinventory`     ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `nicinventory`         ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `caddyinventory`       ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `pciecardinventory`    ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `hbacardinventory`     ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;
ALTER TABLE `sfpinventory`         ADD COLUMN IF NOT EXISTS `status_v2` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NULL AFTER `Status`;

-- Backfill server_configurations: 0 -> draft, 3 -> finalized;
-- 1/2 unreachable per audit, mapped defensively (1 -> validated, 2 -> building).
UPDATE `server_configurations` SET `status_v2` = 'draft'     WHERE `configuration_status` = 0 AND `status_v2` IS NULL;
UPDATE `server_configurations` SET `status_v2` = 'validated' WHERE `configuration_status` = 1 AND `status_v2` IS NULL;
UPDATE `server_configurations` SET `status_v2` = 'building'  WHERE `configuration_status` = 2 AND `status_v2` IS NULL;
UPDATE `server_configurations` SET `status_v2` = 'finalized' WHERE `configuration_status` = 3 AND `status_v2` IS NULL;

-- Backfill each inventory table: 0 -> failed, 1 -> available, 2 -> installed.
UPDATE `cpuinventory`         SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `raminventory`         SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `storageinventory`     SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `motherboardinventory` SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `chassisinventory`     SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `nicinventory`         SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `caddyinventory`       SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `pciecardinventory`    SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `hbacardinventory`     SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;
UPDATE `sfpinventory`         SET `status_v2` = CASE `Status` WHEN 0 THEN 'failed' WHEN 1 THEN 'available' WHEN 2 THEN 'installed' END WHERE `status_v2` IS NULL;

-- ------------------------------------------------------------
-- Section 6/6 — from 2026_07_10_002_create-status-transitions.sql (U-SM.2)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `config_status_transitions` (
  `from_status` ENUM('draft','building','validating','validated','finalized','deployed','maintenance','retired') NOT NULL,
  `to_status`   ENUM('draft','building','validating','validated','finalized','deployed','maintenance','retired') NOT NULL,
  `required_permission` VARCHAR(64) NOT NULL,
  `requires_validation` ENUM('none','full') NOT NULL,
  PRIMARY KEY (`from_status`, `to_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `inventory_status_transitions` (
  `from_status` ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NOT NULL,
  `to_status`   ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') NOT NULL,
  PRIMARY KEY (`from_status`, `to_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `config_status_transitions` (`from_status`, `to_status`, `required_permission`, `requires_validation`) VALUES
  ('draft',     'building',   'server.edit',       'none'),
  ('building',  'validating', 'server.edit',       'none'),
  ('validating','validated',  'SYSTEM',            'full'),
  ('validating','building',  'SYSTEM',            'none'),
  ('validated', 'building',   'server.edit',       'none'),
  ('validated', 'finalized',  'server.finalize',   'full'),
  ('finalized', 'deployed',   'server.deploy',     'none'),
  ('finalized', 'building',   'server.unfinalize', 'none'),
  ('deployed',  'maintenance','server.maintain',   'none'),
  ('maintenance','deployed',  'server.maintain',   'full'),
  ('deployed',  'retired',    'server.retire',     'none'),
  ('maintenance','retired',   'server.retire',     'none');

-- Sequential lifecycle (available -> reserved -> allocated -> installed -> active)
INSERT IGNORE INTO `inventory_status_transitions` (`from_status`, `to_status`) VALUES
  ('available', 'reserved'),
  ('reserved',  'allocated'),
  ('allocated', 'installed'),
  ('installed', 'active'),
-- Every state can enter maintenance except retired (terminal)
  ('available',  'maintenance'),
  ('reserved',   'maintenance'),
  ('allocated',  'maintenance'),
  ('installed',  'maintenance'),
  ('active',     'maintenance'),
  ('failed',     'maintenance'),
-- Release / removal paths back to available
  ('reserved',  'available'),
  ('allocated', 'available'),
  ('installed', 'available'),
  ('active',    'available'),
-- Maintenance resolves to available (repaired) or failed (diagnosed dead)
  ('maintenance', 'available'),
  ('maintenance', 'failed'),
-- failed -> retired only (NOT failed -> available: illegal resurrection)
  ('failed', 'retired');

-- ============================================================
-- Verification (run after the seeder):
--
--   SHOW TABLES LIKE 'config_%';
--   -- expect: config_components, config_events, config_resources,
--   --         config_status_transitions
--   SHOW TABLES LIKE '%backfill%';
--   -- expect: backfill_quarantine, migration_backfill_state
--   SELECT COUNT(*) FROM config_status_transitions;     -- expect 12 (14 after 006 runs)
--   SELECT COUNT(*) FROM inventory_status_transitions;  -- expect 17
--   SHOW COLUMNS FROM server_configurations LIKE 'revision';   -- expect 1 row
--   SHOW COLUMNS FROM server_configurations LIKE 'status_v2';  -- expect 1 row
--   SELECT configuration_status, status_v2, COUNT(*) FROM server_configurations GROUP BY 1,2;
--   -- expect: every row has status_v2 populated per the legacy mapping
--
-- Rollback: see ../seeders/rollback/ — each of the six original seeders has
-- (or documents) its paired rollback; the table drops are:
--   DROP TABLE IF EXISTS config_resources, config_events, config_components,
--     migration_backfill_state, backfill_quarantine,
--     config_status_transitions, inventory_status_transitions;
--   (config_resources/config_events before config_components — FK order.)
--   ALTER TABLE server_configurations DROP COLUMN revision, DROP COLUMN status_v2;
--   -- + DROP COLUMN status_v2 on each of the 10 inventory tables.
-- ============================================================
