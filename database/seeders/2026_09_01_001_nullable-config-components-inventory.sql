-- =============================================================================
-- 2026_09_01_001_nullable-config-components-inventory.sql
--
-- Date:     2026-09-01
-- Purpose:  Let a VIRTUAL (sandbox / Compatibility Bench) configuration hold
--           component rows that reserve NO physical stock, by allowing
--           config_components.inventory_table / inventory_id to be NULL.
-- Tables:   config_components (column nullability only; no data change)
-- Feature:  Compatibility audit remediation, P0-1 (virtual builds must not
--           consume or steal real inventory).
--
-- Run before, or at the same time as, the AddComponentCommand change that
-- writes NULLs. The code probes SHOW COLUMNS first and refuses a virtual add
-- with a 503 while these columns are still NOT NULL, so deploying the code
-- ahead of this file is safe -- it just leaves virtual builds unusable until
-- this runs.
--
-- =============================================================================
-- WHY
--
--   A virtual configuration is a what-if build. It is supposed to reserve
--   nothing: ServerBuilder::createConfiguration()'s own comment still says
--   "is_virtual is what actually makes it reserve nothing -- see the $isVirtual
--   guards in addComponent()". That method, and those guards, were deleted in
--   migration P9 and never replaced in the command layer.
--
--   Since then AddComponentCommand::apply() has written the real unit's
--   (inventory_table, inventory_id) into config_components and flipped that
--   unit to Status=2 / ServerUUID=<virtual config> for EVERY add, virtual or
--   not. Worse, because uq_inventory_once is keyed on the physical unit,
--   ConfigComponentRepository::insert()'s ON DUPLICATE KEY UPDATE rewrites
--   config_uuid in place -- so adding a part to a sandbox build MOVES that part
--   out of whatever real server was holding it.
--
--   The row store has no way to say "this build contains a 32GB RDIMM but no
--   particular one", because both identity columns are NOT NULL. This seeder
--   gives it one.
--
-- =============================================================================
-- WHY THIS DOES NOT WEAKEN THE INVARIANT
--
--   uq_inventory_once (inventory_table, inventory_id, component_type) is
--   untouched. MySQL/MariaDB treat NULL as distinct in a unique key, so:
--
--     * every REAL placement still carries a concrete (table, id, type) triple
--       and still collides with any second placement of the same unit -- the
--       "one physical unit, one live placement" invariant is unchanged;
--     * virtual rows carry (NULL, NULL, type) and never contend with anything,
--       which is the entire point.
--
--   The soft FK the columns implement (checked by orphan_report, not by the DB)
--   already tolerates absence: RemoveComponentCommand::apply() guards its
--   inventory release on `if ($row['inventory_table'] !== null)`,
--   TargetStateBuilder::fetchStatusV2() skips rows with no unit, and
--   ConfigReadRouter::rowsToLegacyShape() only emits 'inventory_id' when the
--   column is non-null. Every reader was already written for this shape.
--
-- =============================================================================
-- IDEMPOTENCY
--
--   MODIFY COLUMN restates the column definition, so re-running is a no-op that
--   sets the same definition again. No catalog-table guard is used or needed:
--   the application DB user on this host cannot read that catalog, and such a
--   guard dies at PREPARE, reporting success while changing nothing (same
--   reasoning as seeders 2026_08_18_001, 2026_08_25_003, 2026_08_25_005).
--
--   Widening NOT NULL to NULL never rejects an existing row, so this cannot
--   fail on data.
-- =============================================================================


ALTER TABLE `config_components`
  MODIFY COLUMN `inventory_table` VARCHAR(32) NULL DEFAULT NULL
    COMMENT 'e.g. raminventory -- soft FK target table name. NULL = virtual build, reserves no physical unit (2026-09-01)';

ALTER TABLE `config_components`
  MODIFY COLUMN `inventory_id` BIGINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Soft FK -> {inventory_table}.ID. NULL = virtual build, reserves no physical unit (2026-09-01)';


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
--    WHERE removed_at IS NULL AND inventory_table IS NOT NULL
--    GROUP BY inventory_table, inventory_id, component_type
--   HAVING configs > 1;
--   -- expect: empty
-- =============================================================================
