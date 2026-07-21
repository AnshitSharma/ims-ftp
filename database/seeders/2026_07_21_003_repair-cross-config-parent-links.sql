-- =============================================================================
-- 2026_07_21_003_repair-cross-config-parent-links.sql
--
-- Date:     2026-07-21
-- Purpose:  Repair config_components rows whose parent_id points at a row that
--           now belongs to a DIFFERENT config, and the stale config_resources
--           rows created the same way. These dangling links make
--           `delete-config` fail with fk_cc_parent on servers that show zero
--           components in the UI.
-- Tables:   config_components, config_resources
-- Feature:  delete-config FK failure (tasks/todo.md, 2026-07-21)
--
-- BACKGROUND
--   config_components.uq_inventory_once is (inventory_table, inventory_id) —
--   keyed on the PHYSICAL UNIT, not on the placement. So
--   ConfigComponentRepository::insert()'s ON DUPLICATE KEY UPDATE reuses the
--   existing row: same id, rewritten config_uuid. When a unit is placed into a
--   different config it therefore TAKES ITS ROW ID WITH IT, and any children
--   left behind in the old config keep pointing at an id that is now owned by
--   another config.
--
--   Observed case (production dump, 2026-07-21):
--     - config_components.id = 10001 = motherboardinventory #49
--     - config_events #10001 records it backfilled into config 019eca1d…
--     - rows 10003–10012 and 10016 (config 019eca1d…) still have parent_id = 10001
--     - row 10001 today: config_uuid = 2d66d58f…, added 2026-07-20 17:18:39,
--       tombstoned 17:18:46
--   Deleting config 2d66d58f… (which correctly shows NO components — its only
--   row is a tombstone) deletes row 10001, and fk_cc_parent (RESTRICT, no
--   ON DELETE clause) rejects it because of children in 019eca1d….
--
--   The live path is fixed in ConfigComponentRepository::insert(), which now
--   calls repointChildrenAwayFrom() whenever the upsert moves a row across
--   configs. ServerBuilder::purgeConfigComponentRows() repairs on the way out
--   as well. This seeder cleans the rows that were stranded before either.
--
-- REPAIR RULE (identical to ConfigComponentWriter::resolveParentId())
--   live board-hosted child (cpu/ram/pciecard/hbacard/nic) -> its OWN config's
--   live motherboard row; everything else -> NULL. sfp is excluded from step 1
--   because its parent is a NIC, not the board. Tombstoned children are
--   included: fk_cc_parent holds regardless of removed_at.
--
-- IDEMPOTENT: both statements match only rows that still cross a config
--   boundary, so a second run is a no-op.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 0. INSPECT FIRST (read-only) — what this seeder is about to touch
-- -----------------------------------------------------------------------------
--   SELECT child.id, child.config_uuid AS child_config, child.component_type,
--          child.removed_at, child.parent_id,
--          stale.config_uuid AS parent_now_in_config
--     FROM config_components child
--     JOIN config_components stale ON stale.id = child.parent_id
--    WHERE stale.config_uuid <> child.config_uuid;

-- -----------------------------------------------------------------------------
-- 1. Live board-hosted children -> their own config's live motherboard
-- -----------------------------------------------------------------------------
UPDATE config_components AS child
  JOIN config_components AS stale_parent
    ON stale_parent.id = child.parent_id
   AND stale_parent.config_uuid <> child.config_uuid
  JOIN config_components AS own_mb
    ON own_mb.config_uuid = child.config_uuid
   AND own_mb.component_type = 'motherboard'
   AND own_mb.removed_at IS NULL
   SET child.parent_id = own_mb.id
 WHERE child.removed_at IS NULL
   AND child.component_type IN ('cpu', 'ram', 'pciecard', 'hbacard', 'nic');

-- -----------------------------------------------------------------------------
-- 2. Everything still crossing a config boundary -> detached
--    (sfp whose NIC left, tombstoned history rows, configs with no live
--     motherboard to re-parent to)
-- -----------------------------------------------------------------------------
UPDATE config_components AS child
  JOIN config_components AS stale_parent
    ON stale_parent.id = child.parent_id
   AND stale_parent.config_uuid <> child.config_uuid
   SET child.parent_id = NULL;

-- -----------------------------------------------------------------------------
-- 3. Stale ledger rows: config_resources entries describing a unit that has
--    since moved to another config. fk_cr_consumer (RESTRICT) blocks the delete
--    outright; fk_cr_provider (ON DELETE CASCADE) is worse — it would silently
--    take the other config's ledger rows with it. Both are accounting for
--    hardware that is no longer in that config, so they are removed.
--
--    INSPECT FIRST:
--      SELECT cr.* FROM config_resources cr
--        JOIN config_components p ON p.id = cr.provider_id
--       WHERE p.config_uuid <> cr.config_uuid;
--      SELECT cr.* FROM config_resources cr
--        JOIN config_components c ON c.id = cr.consumer_id
--       WHERE c.config_uuid <> cr.config_uuid;
-- -----------------------------------------------------------------------------
DELETE cr FROM config_resources AS cr
  JOIN config_components AS p ON p.id = cr.provider_id
 WHERE p.config_uuid <> cr.config_uuid;

DELETE cr FROM config_resources AS cr
  JOIN config_components AS c ON c.id = cr.consumer_id
 WHERE c.config_uuid <> cr.config_uuid;

-- =============================================================================
-- VERIFY
--
--   -- expect zero rows: no parent link may cross a config boundary
--   SELECT COUNT(*) AS cross_config_parents
--     FROM config_components child
--     JOIN config_components parent ON parent.id = child.parent_id
--    WHERE parent.config_uuid <> child.config_uuid;
--
--   -- expect zero rows: no ledger row may reference another config's component
--   SELECT COUNT(*) AS cross_config_ledger
--     FROM config_resources cr
--     JOIN config_components cc
--       ON cc.id = cr.provider_id OR cc.id = cr.consumer_id
--    WHERE cc.config_uuid <> cr.config_uuid;
--
--   -- the reported case: config 2d66d58f… must now be deletable
--   SELECT id, config_uuid, component_type, parent_id, removed_at
--     FROM config_components
--    WHERE config_uuid = '2d66d58f-64ec-4896-93cb-e48295bad69a';
--
-- Then re-run:  php scripts/verify/run_all.php --gate P2
-- =============================================================================
