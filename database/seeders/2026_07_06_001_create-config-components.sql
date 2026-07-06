-- ============================================================
-- Seeder : 2026_07_06_001_create-config-components
-- Date   : 2026-07-06
-- Purpose: Migration U-1.1 — relational replacement for the ten JSON
--          columns on server_configurations (cpu_configuration,
--          ram_configuration, storage_configuration, caddy_configuration,
--          nic_config, hbacard_config/hbacard_uuid, pciecard_configurations,
--          sfp_configuration, motherboard_uuid, chassis_uuid). One row per
--          physical unit placed in a config (INV-1: one physical unit =
--          one row, ever).
-- Tables : config_components (NEW)
-- Notes  :
--   * config_uuid is declared CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
--     to EXACTLY match server_configurations.config_uuid's collation.
--     Seeder 2026_06_17_002 already hit the "Illegal mix of collations"
--     (#1267) bug class from a table left on the utf8mb4_general_ci table
--     default instead of copying the FK target's collation explicitly —
--     this table copies it explicitly instead of relying on the schema
--     default (utf8mb4_general_ci here, deliberately overridden per-column).
--   * inventory_table/inventory_id form a SOFT FK (checked by orphan_report,
--     not a DB constraint) because ten separate {type}inventory tables
--     still exist pending their unification (out of this unit's scope;
--     tracked in migration/00-overview/PLAN_VERIFICATION_REVIEW.md finding
--     F-6). A hard FK lands once that unification exists.
--   * removed_at is a soft-tombstone used ONLY during the P1-P9 dual-write
--     window (legacy JSON + this table coexist); it is hard-deleted at
--     U-D.3 once the JSON columns are dropped and this table is the only
--     source of truth.
--   * MySQL/MariaDB treat NULL as distinct in unique keys, so a composite
--     unique key that includes removed_at does NOT prevent two live rows
--     (removed_at IS NULL) with the same (inventory_table, inventory_id):
--     both would have removed_at = NULL, and NULL <> NULL for uniqueness
--     purposes, so the key would silently NOT enforce "one physical unit,
--     one live placement." uq_inventory_once is therefore declared WITHOUT
--     removed_at — it directly enforces "this exact row (table, id) exists
--     at most once, period" (tombstoning happens via UPDATE ... SET
--     removed_at = NOW(), never via a second INSERT for the same
--     inventory_table + inventory_id, so no legitimate write path ever
--     needs two rows for the same physical unit to coexist). The slot
--     occupancy key keeps removed_at because a slot CAN be legitimately
--     reoccupied after a tombstoned removal — that key's NULL-distinctness
--     is intentional, not a gap.
--   * Idempotent guard: CREATE TABLE IF NOT EXISTS, safe to re-run.
-- Feature: Schema migration (Phase P1 — schema introduction, DUAL_WRITE_ENABLED=off).
-- ============================================================

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
-- Verification (optional):
--   SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_NAME = 'config_components' AND COLUMN_NAME = 'config_uuid';
--   -- expect: utf8mb4_unicode_ci (matches server_configurations.config_uuid)
-- ------------------------------------------------------------
