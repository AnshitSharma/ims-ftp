-- =============================================================================
-- base_schema.sql  —  LOCAL TEST FIXTURE (NOT a production seeder)
-- =============================================================================
-- Purpose : Create the BASE tables (`server_configurations` + the ten
--           `{type}inventory` tables) that the production dump normally
--           supplies, so the DB-backed suites under tests/ can run on a machine
--           that does NOT have a copy of the production dump.
--
-- Why this exists
--   tests/golden/setup_scratch_db.sql creates an empty database and expects the
--   caller to load `imsbdcmsbharatda_Ims_Production.sql` into it. That dump is
--   production data and is deliberately not in the repository, so on a clean
--   checkout every DB-backed suite fails with "Base table or view not found".
--   This file closes that gap for the suites that bring their OWN fixture rows
--   (engine_shadow, target_state, config_component_repository, the command-layer
--   regression tests). They assert against data they insert themselves, so they
--   need the SHAPE of these tables, not production's contents.
--
-- What this is NOT
--   * NOT a substitute for the production dump when running
--     tests/characterize_compatibility.php. The golden master characterises the
--     engine against REAL production configurations; an empty schema yields an
--     empty baseline, which proves nothing. Load the real dump for that.
--   * NOT a production seeder. It creates nothing new and must never be run
--     against production. Per CLAUDE.md, production schema changes ship as
--     dated files in database/seeders/ — this is a test fixture and lives here.
--
-- Provenance (every column below is traceable, none invented):
--   * server_configurations   — columns referenced by ServerConfiguration.php
--     (create/loadByUuid/update), the INSERT in api/handlers/server/server_api.php,
--     and every `UPDATE server_configurations SET <col>` in ServerBuilder.php.
--   * `revision` / `status_v2` — added by seeders 2026_07_06_003 and 2026_07_10_001
--     (and the consolidated 2026_07_13_000), so they are part of the current shape.
--   * inventory tables — mirrors the shape the repo's own fixture uses in
--     tests/regression/serial_less_unit_identity_test.php (storageinventory_sltest),
--     plus WarrantyEndDate/FailDate from seeder 2026_06_15_001 and the
--     SourceType/ParentComponentUUID/OnboardNICIndex columns that
--     ServerBuilder's onboard-NIC path selects from nicinventory.
--
-- Usage (from repo root):
--     mysql -u root < ims-ftp/tests/golden/setup_scratch_db.sql
--     mysql -u root ims_compat_golden < ims-ftp/tests/golden/base_schema.sql
--     mysql -u root ims_compat_golden < ims-ftp/database/consolidated/2026_07_13_000_consolidated-migration-schema.sql
--
--   Run base_schema.sql BEFORE the consolidated migration schema: config_components
--   has a FOREIGN KEY onto server_configurations.config_uuid and cannot be created
--   until that table exists.
--
-- Idempotent: every statement is CREATE TABLE IF NOT EXISTS.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- server_configurations
--
-- Component state lives in the JSON columns, not in child tables: the engine
-- reads cpu_configuration / ram_configuration / storage_configuration /
-- caddy_configuration / nic_config / hbacard_config / pciecard_configurations /
-- sfp_configuration plus the motherboard_uuid / chassis_uuid / hbacard_uuid
-- scalars. LONGTEXT rather than a JSON type on purpose -- production is MariaDB
-- and the code decodes these itself via ServerBuilder::safeJsonDecode(), which
-- must keep receiving a raw string (including a malformed one; the A-E2
-- corrupt-column behaviour is under test).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `server_configurations` (
  `id`                      int(11)      NOT NULL AUTO_INCREMENT,
  `config_uuid`             varchar(36)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `server_name`             varchar(255) DEFAULT NULL,
  `description`             text         DEFAULT NULL,
  `location`                varchar(100) DEFAULT NULL,
  `rack_position`           varchar(20)  DEFAULT NULL,

  -- Component state (JSON-encoded strings)
  `cpu_configuration`       longtext     DEFAULT NULL,
  `ram_configuration`       longtext     DEFAULT NULL,
  `storage_configuration`   longtext     DEFAULT NULL,
  `caddy_configuration`     longtext     DEFAULT NULL,
  `nic_config`              longtext     DEFAULT NULL,
  `hbacard_config`          longtext     DEFAULT NULL,
  `pciecard_configurations` longtext     DEFAULT NULL,
  `sfp_configuration`       longtext     DEFAULT NULL,

  -- Single-component scalars
  `motherboard_uuid`        varchar(50)  DEFAULT NULL,
  `chassis_uuid`            varchar(50)  DEFAULT NULL,
  `hbacard_uuid`            varchar(50)  DEFAULT NULL COMMENT 'Legacy pre-hbacard_config column; still read for backward compatibility',

  -- Status / validation
  `configuration_status`    tinyint(4)   NOT NULL DEFAULT 0,
  `status_v2`               ENUM('draft','building','validating','validated','finalized','deployed','maintenance','retired') DEFAULT NULL,
  `revision`                int(10) unsigned NOT NULL DEFAULT 0,
  `validation_results`      longtext     DEFAULT NULL,
  `validation_errors`       longtext     DEFAULT NULL,
  `compatibility_score`     decimal(5,2) DEFAULT NULL,
  `power_consumption`       int(11)      DEFAULT NULL,
  `notes`                   text         DEFAULT NULL,
  `is_virtual`              tinyint(1)   NOT NULL DEFAULT 0,

  -- Audit
  `created_by`              int(11)      DEFAULT NULL,
  `updated_by`              int(11)      DEFAULT NULL,
  `created_at`              timestamp    NOT NULL DEFAULT current_timestamp(),
  `updated_at`              timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_config_uuid` (`config_uuid`),
  KEY `k_status` (`configuration_status`),
  KEY `k_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- Inventory tables
--
-- All ten share one shape. `UUID` is the CATALOG uuid (the ims-data/{type}/*.json
-- spec), so it is deliberately NOT unique -- many physical units share a model.
-- `ID` is the per-unit identity the A-L5 work depends on. Status: 0=failed,
-- 1=available, 2=in_use.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `cpuinventory` (
  `ID`               int(11)      NOT NULL AUTO_INCREMENT,
  `UUID`             varchar(50)  NOT NULL COMMENT 'Catalog/spec UUID from ims-data -- NOT unique per physical unit',
  `SerialNumber`     varchar(50)  DEFAULT NULL,
  `AssetTag`         varchar(20)  DEFAULT NULL,
  `Status`           tinyint(1)   NOT NULL DEFAULT 1 COMMENT '0=failed, 1=available, 2=in_use',
  `status_v2`        ENUM('available','reserved','allocated','installed','active','maintenance','failed','retired') DEFAULT NULL,
  `ServerUUID`       varchar(36)  DEFAULT NULL,
  `Location`         varchar(100) DEFAULT NULL,
  `RackPosition`     varchar(20)  DEFAULT NULL,
  `PurchaseDate`     date         DEFAULT NULL,
  `InstallationDate` date         DEFAULT NULL,
  `WarrantyEndDate`  date         DEFAULT NULL,
  `FailDate`         date         DEFAULT NULL COMMENT 'Seeder 2026_06_15_001',
  `Flag`             varchar(50)  DEFAULT NULL,
  `Notes`            text         DEFAULT NULL,
  `CreatedAt`        timestamp    NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt`        timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID`),
  UNIQUE KEY `uq_asset_tag` (`AssetTag`),
  UNIQUE KEY `idx_serial_number` (`SerialNumber`),
  KEY `k_uuid` (`UUID`),
  KEY `k_status` (`Status`),
  KEY `k_server` (`ServerUUID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `raminventory`         LIKE `cpuinventory`;
CREATE TABLE IF NOT EXISTS `storageinventory`     LIKE `cpuinventory`;
CREATE TABLE IF NOT EXISTS `motherboardinventory` LIKE `cpuinventory`;
CREATE TABLE IF NOT EXISTS `chassisinventory`     LIKE `cpuinventory`;
CREATE TABLE IF NOT EXISTS `caddyinventory`       LIKE `cpuinventory`;
CREATE TABLE IF NOT EXISTS `pciecardinventory`    LIKE `cpuinventory`;
CREATE TABLE IF NOT EXISTS `hbacardinventory`     LIKE `cpuinventory`;
CREATE TABLE IF NOT EXISTS `sfpinventory`         LIKE `cpuinventory`;
CREATE TABLE IF NOT EXISTS `nicinventory`         LIKE `cpuinventory`;

-- nicinventory carries three extra columns the onboard-NIC path selects
-- (ServerBuilder: "SELECT SourceType, ParentComponentUUID, OnboardNICIndex
-- FROM nicinventory WHERE UUID = ?"). Onboard NICs are synthesised rows owned by
-- a motherboard rather than independently stocked units.
ALTER TABLE `nicinventory`
  ADD COLUMN IF NOT EXISTS `SourceType`            varchar(20) DEFAULT 'component' COMMENT 'component | onboard',
  ADD COLUMN IF NOT EXISTS `ParentComponentUUID`   varchar(50) DEFAULT NULL COMMENT 'Owning motherboard UUID for onboard NICs',
  ADD COLUMN IF NOT EXISTS `OnboardNICIndex`       int(11)     DEFAULT NULL;

-- =============================================================================
-- VERIFICATION (expects 11 base tables)
--
--   SELECT TABLE_NAME FROM information_schema.TABLES
--   WHERE TABLE_SCHEMA = DATABASE()
--     AND (TABLE_NAME = 'server_configurations' OR TABLE_NAME LIKE '%inventory')
--   ORDER BY TABLE_NAME;
-- =============================================================================
