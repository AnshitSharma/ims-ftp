-- =============================================================================
-- 2026_08_25_005_widen-inventory-once-key.sql
--
-- Date:     2026-08-25
-- Purpose:  Let ONE server compute platform unit back BOTH of the component rows
--           it legitimately fills -- its system board and its chassis.
-- Tables:   config_components (unique key only; no data change)
-- Feature:  Server Compute Platform rebuild -- tasks/todo.md section 8
--
-- Run AFTER 2026_08_25_002/003/004. Required before platform installs can write
-- their board and chassis into config_components.
--
-- =============================================================================
-- WHY
--
--   A compute platform is one physical box. Inside it are a system board and a
--   chassis, which are NOT separately stocked -- they have no inventory row of
--   their own, because the thing we bought and count is the box. So both of the
--   config_components rows they produce must point at the SAME unit:
--
--     ('serverplatforminventory', 42, 'motherboard')
--     ('serverplatforminventory', 42, 'chassis')
--
--   uq_inventory_once (inventory_table, inventory_id) forbids exactly that.
--
-- =============================================================================
-- WHY THIS DOES NOT WEAKEN THE INVARIANT
--
--   The invariant uq_inventory_once enforces is "a physical unit is installed in
--   at most one place at a time". For all 11 pre-existing component types the
--   inventory table ALREADY implies exactly one component_type -- raminventory
--   only ever yields 'ram' rows, cpuinventory only 'cpu', and so on. Adding
--   component_type to the key is therefore a NO-OP for every one of them: the
--   same set of rows collides after this change as before it.
--
--   Only serverplatforminventory -- whose unit genuinely fills two roles -- gains
--   room, and it gains exactly two slots, not unlimited ones.
--
--   Not relaxed, and still doing the real work:
--     * uq_slot_occupancy (config_uuid, slot_ref, removed_at) is untouched. Both
--       platform rows carry slot_ref = NULL and NULLs stay distinct in a MySQL
--       unique key, so they never contend for a slot.
--     * serverplatforminventory.ServerUUID + Status, claimed FOR UPDATE by
--       handleSetPlatform(), remain the primary guard against one box being
--       installed into two configurations.
--
--   ConfigComponentRepository::insert() is updated in the same change to carry
--   component_type in both of its prior-row lookups, so ON DUPLICATE KEY UPDATE
--   and the lastInsertId() fallback resolve the intended one of a platform unit's
--   two rows rather than an arbitrary one.
--
-- =============================================================================
-- IDEMPOTENCY
--
--   Native IF EXISTS / IF NOT EXISTS (MariaDB 10.0.2+), deliberately NOT an
--   information_schema-guarded block: the application DB user on this host cannot
--   read that schema and such a guard dies at PREPARE. Same reasoning as seeders
--   2026_08_18_001 and 2026_08_25_003.
--
--   Safe to re-run. Dropping a unique key never destroys data; the recreate below
--   would fail loudly (not silently) if duplicate rows somehow existed.
-- =============================================================================


ALTER TABLE `config_components`
  DROP INDEX IF EXISTS `uq_inventory_once`;

ALTER TABLE `config_components`
  ADD UNIQUE KEY IF NOT EXISTS `uq_inventory_once`
    (`inventory_table`, `inventory_id`, `component_type`);


-- =============================================================================
-- Verification (run after the seeder):
--
--   SHOW INDEX FROM config_components WHERE Key_name = 'uq_inventory_once';
--   -- expect 3 rows: inventory_table (1), inventory_id (2), component_type (3)
--
--   -- No unit should be installed in two configurations at once:
--   SELECT inventory_table, inventory_id, COUNT(DISTINCT config_uuid) AS configs
--     FROM config_components
--    WHERE removed_at IS NULL
--    GROUP BY inventory_table, inventory_id
--   HAVING configs > 1;
--   -- expect: empty
-- =============================================================================
