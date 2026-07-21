-- =============================================================================
-- 2026_07_21_002_align-component-nic-parent-id.sql
--
-- Date:     2026-07-21
-- Purpose:  Give component (non-onboard) NIC rows in config_components the same
--           motherboard parent_id that every other board-hosted type already
--           carries. Finding F-5, tasks/p2-verification-findings-20260721.md.
-- Tables:   config_components
-- Feature:  P2/P6 remediation — cascade correctness before COMMAND_LAYER_ENABLED=enforce
--
-- BACKGROUND
--   RemoveComponentCommand's cascade walks the parent_id subtree. Component NICs
--   were left parent_id = NULL by BOTH writers:
--     - scripts/backfill/Extractor.php:180  ($isOnboard ? 'motherboard' : null)
--     - ConfigComponentWriter::resolveParentId() (only sfp + onboard nic)
--   while cpu/ram/pciecard/hbacard all parent to the motherboard. A component NIC
--   is a PCIe slot device carrying a slot_ref, handled identically to
--   pciecard/hbacard everywhere else (AddComponentCommand.php:82 groups exactly
--   those three), so the omission looks like an oversight rather than a decision.
--
--   Consequence: cascade-removing a motherboard left the component NIC behind,
--   whose only DEPENDS_ON providers (motherboard, riser) were then gone, so
--   DependencyBlockedRemovalRule blocked the removal. Legacy removeComponent()
--   only ever enforced the nic->sfp case, so legacy allowed it — a real
--   legacy-vs-command divergence on a common operation.
--
--   The live-path half of this was fixed the same day in
--   ConfigComponentWriter::resolveParentId() (BOARD_HOSTED_TYPES). This seeder
--   aligns the rows that already exist.
--
-- SCOPE: live rows only (removed_at IS NULL). Tombstoned rows are history and are
--   deliberately left byte-unchanged.
--
-- IDEMPOTENT: only touches rows that are still NULL, and only where a live
--   motherboard row exists in the same config to point at.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Component NICs -> their config's live motherboard row
--    Excludes onboard NICs (spec_uuid LIKE 'onboard-%'), which already carry a
--    parent_id from the 2026_07_20_002 backfill.
-- -----------------------------------------------------------------------------
UPDATE config_components AS nic
  JOIN config_components AS mb
    ON  mb.config_uuid COLLATE utf8mb4_general_ci
      = nic.config_uuid COLLATE utf8mb4_general_ci
   AND mb.component_type = 'motherboard'
   AND mb.removed_at IS NULL
   SET nic.parent_id = mb.id
 WHERE nic.component_type = 'nic'
   AND nic.removed_at IS NULL
   AND nic.parent_id IS NULL
   AND nic.spec_uuid NOT LIKE 'onboard-%';

-- =============================================================================
-- VERIFY
--
--   -- expect zero rows: any live board-hosted component with no parent, in a
--   -- config that does have a live motherboard
--   SELECT c.id, c.config_uuid, c.component_type, c.spec_uuid
--     FROM config_components c
--     JOIN config_components mb
--       ON  mb.config_uuid COLLATE utf8mb4_general_ci
--         = c.config_uuid COLLATE utf8mb4_general_ci
--      AND mb.component_type = 'motherboard'
--      AND mb.removed_at IS NULL
--    WHERE c.removed_at IS NULL
--      AND c.parent_id IS NULL
--      AND c.component_type IN ('cpu','ram','pciecard','hbacard','nic');
--
--   -- coverage summary (storage/caddy/chassis/motherboard legitimately stay NULL)
--   SELECT component_type, COUNT(*) total,
--          SUM(parent_id IS NOT NULL) with_parent,
--          SUM(parent_id IS NULL)     no_parent
--     FROM config_components WHERE removed_at IS NULL
--    GROUP BY component_type ORDER BY component_type;
--
-- Then re-run:  php tests/regression/remove_command_test.php
--               php scripts/verify/run_all.php --gate P2
-- =============================================================================
