-- ============================================================
-- Seeder : 2026_07_06_002_create-config-resources
-- Date   : 2026-07-06
-- Purpose: Migration U-1.2 — the resource ledger substrate. Capacity/
--          consumption rows for every slotted or scalar resource a
--          component can provide or consume: CPU sockets, DIMM slots,
--          PCIe slots/lanes, M.2/U.2 bays, 2.5"/3.5" drive bays, SFP
--          ports, PSU watts, riser slots.
-- Tables : config_resources (NEW)
-- Notes  :
--   * config_uuid collation rule copied from U-1.1
--     (2026_07_06_001_create-config-components.sql): CHARACTER SET utf8mb4
--     COLLATE utf8mb4_unicode_ci, to exactly match
--     server_configurations.config_uuid (see that seeder's header for the
--     #1267 "Illegal mix of collations" history — seeder 2026_06_17_002).
--   * Modeling a resource ledger row is either a PROVIDER or a CONSUMER:
--       - Provider row: provider_id = the component that provides this
--         resource (e.g. a motherboard providing dimm_slot capacity),
--         capacity = N units provided, consumer_id = NULL.
--       - Consumer row: provider_id = the SAME provider row's owning
--         component this consumption counts against, consumer_id = the
--         component consuming it (e.g. a RAM stick consuming 1 dimm_slot),
--         capacity = N units consumed (a positive amount, never negative
--         — ledger_report, created in U-L.3, sums provided vs consumed
--         separately per (config_uuid, resource) rather than netting via
--         signed capacity).
--     Slotted resources (dimm_slot, pcie_slot, drive_bay_*, sfp_port,
--     riser_slot, m2_slot, u2_slot, cpu_socket) additionally set slot_ref
--     to the specific physical slot identifier, giving uq_discrete a real
--     per-slot uniqueness guard. Scalar resources (pcie_lane, psu_watt)
--     leave slot_ref NULL — there is no discrete slot to collide on, only
--     a capacity pool, so multiple consumer rows with slot_ref NULL for
--     the same (config_uuid, resource) are expected and NOT a uq_discrete
--     violation (NULL is distinct from NULL in a unique key here, which is
--     exactly the behavior scalar resources need — see U-1.1's seeder for
--     the general NULL-distinctness note; here it works IN OUR FAVOR
--     rather than against us, unlike U-1.1's uq_inventory_once case).
--   * provider_id / consumer_id are REAL foreign keys into
--     config_components(id) (unlike U-1.1's inventory_table/inventory_id,
--     which is a soft FK because ten inventory tables still exist —
--     config_components itself is already the single unified table this
--     table's FKs point into, so a hard FK is safe here).
--   * ON DELETE CASCADE on provider_id: if a provider component row is
--     hard-deleted (U-D.3, post-JSON-cutover cleanup), its own ledger rows
--     go with it. ON DELETE RESTRICT on consumer_id: a provider row cannot
--     be hard-deleted while something still consumes it — the DB refuses,
--     forcing the caller to release the consumer first (INV-1 family:
--     protects against orphaning a consumption record whose provider no
--     longer exists).
--   * Idempotent guard: CREATE TABLE IF NOT EXISTS, safe to re-run.
-- Feature: Schema migration (Phase P1 — schema introduction, DUAL_WRITE_ENABLED=off).
-- ============================================================

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
-- Verification (optional):
--   SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_NAME = 'config_resources' AND COLUMN_NAME = 'config_uuid';
--   -- expect: utf8mb4_unicode_ci (matches server_configurations.config_uuid)
--
--   -- RESTRICT guard: attempting to delete a config_components row that is
--   -- still referenced as a consumer_id must fail with an FK error.
-- ------------------------------------------------------------
