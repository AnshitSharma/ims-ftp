-- =============================================================================
-- 2026_08_24_002_backfill-nic-slot-ref-config-235.sql
--
-- Date:     2026-08-24
-- Purpose:  Repair one `slotless_card` row-store defect found by
--           scripts/verify/slot_report.php (check 4): the discrete NIC
--           b32ff113-a672-4f13-a45b-a6704cea61eb (nicinventory.ID 245,
--           serial IL272002UA) in config 4dee234b-d4ab-447a-95cd-e321313b1af8
--           was written to config_components on 2026-08-14 with slot_ref NULL,
--           even though the card is physically seated in a PCIe slot.
--
--           Cause (code, NOT repaired here): the legacy add path
--           ServerBuilder::assignComponentSlot() derives the card width via
--           ServerBuilder::extractPCIeSlotSize(), which probes the keys
--           `interface` / `slot_type` / `pcie_interface`. It is fed the
--           ComponentDataService-NORMALISED nic spec, and
--           ComponentDataService::extractNicSpecs() (ComponentDataService.php:612)
--           renames ims-data's `interface` to `interface_type`. The width
--           therefore parses as NULL, assignComponentSlot() takes its
--           fail-open "added without slot assignment" branch, and
--           $options['slot_position'] is never set -- so the dual-write hook
--           ConfigComponentWriter::afterLegacyAdd() persists slot_ref = NULL.
--           That legacy path was live because COMMAND_LAYER_ENABLED was still
--           `shadow` on 2026-08-14 (promoted to `enforce` 2026-08-21, see
--           reports/cutover-signoff-20260822.md).
--
--           Value chosen: `pcie_3_x8`. This is the LEDGER slot namespace
--           (config_resources.slot_ref), which is what TargetState::freeSlots()
--           and SlotPlanner match against -- NOT the legacy UnifiedSlotTracker
--           namespace `pcie_x8_slot_1` that server_configurations.nic_config
--           carries. Measured: SlotPlanner::plan() over this config's live
--           TargetState returns exactly `pcie_3_x8` for this x8 card (smallest
--           compatible free slot), i.e. this backfill writes precisely what the
--           current COMMAND_LAYER_ENABLED=enforce add path would write today.
--
-- Affected tables:  config_components  (1 row UPDATE, no schema change)
-- Related feature:  11-verification slot_report.php check 4 (audit A-8,
--                   "slotless card"); resource ledger (U-L.2 / U-B.4).
--
-- Idempotent: the UPDATE is fully guarded and matches 0 rows on re-run
--             (slot_ref is no longer NULL). Safe to paste twice.
--
-- NOT repaired by this seeder (deliberate -- code defect, no data fix):
--   ledger_report.php check 4 reports `lane_model_mismatch` on this same
--   config (ledger_used = 8, legacy_used = 0). The ledger's 8 is correct;
--   PcieLaneBudgetValidator::computeLanesUsed() returns 0 because of the SAME
--   `interface` -> `interface_type` rename. There is no stored value to
--   repair -- both numbers are computed at report time -- so that one needs a
--   code change and is left for the owner.
-- =============================================================================

UPDATE config_components cc
  JOIN config_resources cr
    ON  cr.config_uuid = cc.config_uuid
    AND cr.resource    = 'pcie_slot'
    AND cr.slot_ref    = 'pcie_3_x8'
    AND cr.consumer_id IS NULL
SET cc.slot_ref = 'pcie_3_x8'
WHERE cc.config_uuid     = '4dee234b-d4ab-447a-95cd-e321313b1af8'
  AND cc.component_type  = 'nic'
  AND cc.inventory_table = 'nicinventory'
  AND cc.inventory_id    = 245
  AND cc.spec_uuid       = 'b32ff113-a672-4f13-a45b-a6704cea61eb'
  AND cc.removed_at IS NULL
  AND cc.slot_ref   IS NULL
  -- never double-book: refuse if any other live row already holds that slot.
  AND NOT EXISTS (
        SELECT 1 FROM (
          SELECT id FROM config_components
          WHERE config_uuid = '4dee234b-d4ab-447a-95cd-e321313b1af8'
            AND removed_at IS NULL
            AND slot_ref = 'pcie_3_x8'
        ) AS occupied
      );

-- Verification (expect exactly one row, slot_ref = 'pcie_3_x8'):
SELECT id, component_type, spec_uuid, inventory_id, slot_ref
FROM config_components
WHERE config_uuid = '4dee234b-d4ab-447a-95cd-e321313b1af8'
  AND component_type = 'nic'
  AND spec_uuid = 'b32ff113-a672-4f13-a45b-a6704cea61eb'
  AND removed_at IS NULL;
