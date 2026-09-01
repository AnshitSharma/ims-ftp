-- =============================================================================
-- 2026_09_01_002_slot-ref-ledger-namespace.sql
--
-- Date:     2026-09-01
-- Purpose:  Rewrite every legacy-namespace slot_ref in config_components into
--           the LEDGER namespace, so there is exactly one slot vocabulary.
-- Tables:   config_components (data only)
-- Feature:  Compatibility audit remediation, P0-4 (one slot-ID namespace).
--
-- Run AFTER the code change that stops ServerBuilder::migrateNICSlotPositions()
-- writing legacy ids (that method is deleted in the same change). Running it
-- first is harmless but the old read path could re-introduce a legacy value on
-- the next server-get-config.
--
-- =============================================================================
-- WHY
--
--   Two different pieces of code mint slot identifiers and both write the same
--   column:
--
--     LEDGER  pcie_1_x16  riser_1_x16   ResourceCatalog + SlotPlanner, written
--                                       by AddComponentCommand. This is what
--                                       TargetState::freeSlots() matches on.
--     LEGACY  pcie_x8_slot_1            UnifiedSlotTracker, injected into
--                                       config_components.slot_ref by
--                                       ServerBuilder::migrateNICSlotPositions()
--                                       on every server-get-config.
--
--   A row carrying a legacy id matches no provider row, so the validation engine
--   believes that slot is free and will place a second card in it. Seeder
--   2026_08_24_002 already named this divergence and chose the ledger namespace
--   for the one row it repaired; this file finishes the job for the rest.
--
-- =============================================================================
-- SCOPE (probed live 2026-09-01)
--
--   Exactly ONE live row carries a legacy-namespace slot_ref:
--
--     config  4dee234b-d4ab-447a-95cd-e321313b1af8
--     nic     b32ff113-a672-4f13-a45b-a6704cea61eb (nicinventory.ID 245,
--             serial IL272002UA), currently slot_ref = 'pcie_x8_slot_1'
--
--   Its board is c7d8e9f0-a1b2-4c3d-ae5f-6a7b8c9d0e1f (GIGABYTE MD90-FS0-ZB-XX),
--   whose ResourceCatalog provider rows are pcie_1_x16, pcie_2_x16, pcie_3_x8.
--   The card's spec interface is "PCIe 3.0 x8", so the only correct ledger slot
--   for it is pcie_3_x8 -- the same value seeder 2026_08_24_002 computed. That
--   seeder's WHERE required slot_ref IS NULL and no longer matches, because
--   migrateNICSlotPositions() has since stamped the legacy id over the NULL.
--
--   The generic statement below is written to catch any other legacy-form row
--   that appears between now and the run, rather than hardcoding the one id.
--   Rows it cannot map (no matching free ledger slot of the right width) are
--   left alone and reported by the verification query, NOT guessed at.
--
-- =============================================================================
-- IDEMPOTENCY
--
--   Both statements only ever match rows still holding a legacy-form slot_ref,
--   so a second run matches nothing. Safe to re-run.
-- =============================================================================


-- The one known row, mapped to its computed ledger slot. Refuses to double-book:
-- it will not fire if some other live row in that config already holds pcie_3_x8.
UPDATE `config_components`
   SET `slot_ref` = 'pcie_3_x8'
 WHERE `config_uuid`     = '4dee234b-d4ab-447a-95cd-e321313b1af8'
   AND `component_type`  = 'nic'
   AND `inventory_table` = 'nicinventory'
   AND `inventory_id`    = 245
   AND `spec_uuid`       = 'b32ff113-a672-4f13-a45b-a6704cea61eb'
   AND `removed_at` IS NULL
   AND `slot_ref` = 'pcie_x8_slot_1'
   AND NOT EXISTS (
         SELECT 1 FROM (
           SELECT `id` FROM `config_components`
            WHERE `config_uuid` = '4dee234b-d4ab-447a-95cd-e321313b1af8'
              AND `removed_at` IS NULL
              AND `slot_ref` = 'pcie_3_x8'
         ) AS occupied
       );


-- Anything else still in the legacy namespace is DETACHED to NULL rather than
-- guessed at. NULL is the honest "not placed" state: PcieSlotPlacementRule
-- re-plans a NULL slot_ref on the next evaluation and AddComponentCommand
-- persists the plan, so a detached card is re-seated correctly by the engine
-- instead of being pinned to a slot this seeder invented.
UPDATE `config_components`
   SET `slot_ref` = NULL
 WHERE `removed_at` IS NULL
   AND `slot_ref` IS NOT NULL
   AND (`slot_ref` REGEXP '^pcie_x[0-9]+_slot_[0-9]+$'
     OR `slot_ref` REGEXP '^riser_x[0-9]+_slot_[0-9]+$');


-- =============================================================================
-- Verification (run after the seeder):
--
--   -- No legacy-namespace value may remain:
--   SELECT id, config_uuid, component_type, spec_uuid, slot_ref
--     FROM config_components
--    WHERE removed_at IS NULL
--      AND (slot_ref REGEXP '^pcie_x[0-9]+_slot_[0-9]+$'
--        OR slot_ref REGEXP '^riser_x[0-9]+_slot_[0-9]+$');
--   -- expect: empty
--
--   -- The repaired row:
--   SELECT id, component_type, spec_uuid, inventory_id, slot_ref
--     FROM config_components
--    WHERE config_uuid = '4dee234b-d4ab-447a-95cd-e321313b1af8'
--      AND spec_uuid   = 'b32ff113-a672-4f13-a45b-a6704cea61eb'
--      AND removed_at IS NULL;
--   -- expect: slot_ref = pcie_3_x8
--
--   -- No slot double-booked anywhere:
--   SELECT config_uuid, slot_ref, COUNT(*) AS occupants
--     FROM config_components
--    WHERE removed_at IS NULL AND slot_ref IS NOT NULL
--    GROUP BY config_uuid, slot_ref
--   HAVING occupants > 1;
--   -- expect: empty
-- =============================================================================
