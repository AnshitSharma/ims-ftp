-- =============================================================================
-- 2026_09_29_002_bench-nullable-config-components-inventory.sql
--
-- Date:     2026-09-29
-- Purpose:  Let a Compatibility Bench (sandbox / virtual) build hold component
--           rows that reserve NO physical stock, by allowing
--           config_components.inventory_table / inventory_id to be NULL.
-- Tables:   config_components (column nullability only; no data change)
-- Feature:  Server Compatibility bench -- tasks/bench-ignores-inventory.md.
--           Re-applies what 2026_09_01_001 was meant to do. That file left the
--           tree in an earlier cleanup, and production still answers every add
--           to a test build with 503 "Sandbox builds cannot hold components
--           until seeder 2026_09_01_001 ... has been run" (probed 2026-09-29),
--           so the columns are evidently still NOT NULL there.
--
-- Run at any time. The code that writes NULLs probes SHOW COLUMNS first and
-- refuses with that 503 while the columns are NOT NULL, so nothing breaks in
-- the meantime -- test builds simply stay unable to hold parts until this runs.
--
-- =============================================================================
-- WHY THIS DOES NOT WEAKEN THE INVARIANT
--
--   uq_inventory_once (inventory_table, inventory_id, component_type) is
--   untouched. MariaDB treats NULL as distinct in a unique key, so:
--
--     * every REAL placement still carries a concrete (table, id, type) triple
--       and still collides with any second placement of the same unit -- the
--       "one physical unit, one live placement" invariant is unchanged;
--     * a bench build's rows carry (NULL, NULL, type), or ('serverplatforminventory',
--       NULL, type) for the parts of a compute platform, and never contend
--       with anything -- which is the point: they name a MODEL, not a unit.
--
--   Every reader is already written for the shape: RemoveComponentCommand
--   guards its inventory release on inventory_table !== null,
--   TargetStateBuilder::fetchStatusV2() skips rows with no unit,
--   ConfigReadRouter only emits inventory_id when it is non-null, and
--   SystemInventoryStateRule treats a row with no unit as claiming no hardware.
--
-- =============================================================================
-- IDEMPOTENCY
--
--   MODIFY COLUMN restates the whole column definition, so re-running sets the
--   same definition again and changes nothing. No catalog-table guard is used:
--   the application DB user cannot read that catalog, and such a guard fails
--   open at PREPARE, reporting success while changing nothing. Widening
--   NOT NULL to NULL never rejects an existing row, so this cannot fail on data.
-- =============================================================================

ALTER TABLE `config_components`
  MODIFY COLUMN `inventory_table` VARCHAR(32) NULL DEFAULT NULL
    COMMENT 'e.g. raminventory -- soft FK target table name. NULL = a bench build row that reserves no physical unit';

ALTER TABLE `config_components`
  MODIFY COLUMN `inventory_id` BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Soft FK -> {inventory_table}.ID. NULL = a bench build row that reserves no physical unit';

-- =============================================================================
-- Verification (run after the seeder):
--
--   SHOW COLUMNS FROM config_components LIKE 'inventory\_%';
--   -- expect Null = YES for both inventory_table and inventory_id
--
--   -- No REAL unit may be installed in two configurations at once (unchanged):
--   SELECT inventory_table, inventory_id, component_type,
--          COUNT(DISTINCT config_uuid) AS configs
--     FROM config_components
--    WHERE removed_at IS NULL AND inventory_table IS NOT NULL AND inventory_id IS NOT NULL
--    GROUP BY inventory_table, inventory_id, component_type
--   HAVING configs > 1;
--   -- expect: empty
-- =============================================================================
