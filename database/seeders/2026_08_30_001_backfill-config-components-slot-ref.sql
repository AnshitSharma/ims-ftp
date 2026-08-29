-- ============================================================
-- Seeder : 2026_08_30_001_backfill-config-components-slot-ref
-- Date   : 2026-08-30
-- Purpose: Copy the two slot assignments that exist ONLY in the legacy JSON columns
--          into config_components.slot_ref, before U-D.3c drops those columns.
--
-- Tables : config_components (two rows, column slot_ref)
-- Feature: migration U-D.3 — the real data precondition for the drop
--
-- ============================================================
-- WHY THIS EXISTS, AND WHY IT IS NOT THE SEEDER THAT WAS ASKED FOR
--
--   The plan called for a seeder "backfilling config_components for configs with JSON
--   and no rows". That seeder cannot be written. The single config in that state is the
--   only is_virtual=1 build in the system, and a virtual build reserves no stock: all
--   eleven *inventory tables return zero rows for its ServerUUID. config_components
--   requires a NOT NULL inventory_id keyed UNIQUE(inventory_table, inventory_id,
--   component_type), so there is nothing for a row to point at. That exclusion is by
--   design — ConfigComponentWriter::afterLegacyAdd() states it in its own guard — and
--   U-D.3c handles it by ARCHIVING rather than backfilling (seeder 2026_08_30_002).
--
--   What the U-D.3b reader sweep DID turn up is a real, writable gap, and it is this
--   one: two live config_components rows whose slot_ref is NULL while the legacy JSON
--   for the same unit names a slot. Drop the columns without this and those two
--   placements are gone.
--
--   Measured against PRODUCTION on 2026-08-30 (via server-debug-config-dualwrite over
--   all 18 configs, cross-checked against the same query on the production dump):
--   105 live rows examined, exactly 2 drifting, both in the same direction —
--   JSON has a slot, the row has NULL. No row disagrees about WHICH slot.
--
--     row 10291  config 4dee234b…  nic  b32ff113…  json 'pcie_x8_slot_1'  rows NULL
--     row 10423  config 0b434826…  sfp  32bc2712…  json port_index 1      rows NULL
--
--   HOW THEY DRIFTED. Both predate the writers that keep the two stores in step.
--   The NIC's slot was written by ServerBuilder::migrateNICSlotPositions(), a lazy
--   migration that ran on every get-config and wrote nic_config only — it had no notion
--   of a rows store. (It is repointed at slot_ref in the same change as this seeder, so
--   the drift cannot recur; this seeder repairs what it already missed.) The SFP's port
--   is the residue of the slot_ref shape defect that seeder 2026_08_22_001 fixed:
--   that one normalised the rows it found, and this row had none to normalise.
--
-- ============================================================
-- IDEMPOTENCY / SAFETY
--
--   Each UPDATE is pinned to one row id AND guarded on the row still being the unit
--   expected (config, type, spec_uuid) AND on slot_ref still being NULL. So it is:
--     * a no-op on re-run,
--     * a no-op if the row was already repaired by hand,
--     * a no-op if the component has since been removed or replaced,
--     * incapable of overwriting a slot_ref that is already set.
--
--   Plain UPDATEs only — no schema catalogue lookups, which the app DB user cannot read
--   and which fail open when they error (see 2026_08_25_005 and the note in CLAUDE.md).
--
--   Not wrapped in a transaction: two independent single-row UPDATEs, each safe alone.
--
--   COLLISION CHECK. Neither slot is claimed by another live row in the same config —
--   verified before writing this file. Do NOT assume the unique key would catch it if
--   that changed: uq_slot_occupancy is (config_uuid, slot_ref, removed_at) and every
--   LIVE row has removed_at NULL, which MariaDB treats as distinct, so the index accepts
--   two live rows in one slot and only ever constrains tombstones sharing a timestamp.
--   Probed 2026-08-30. The verification query at the foot of this file is the check.
-- ============================================================


-- EXPECT 1 ROW AFFECTED, NOT 2 (checked against production 2026-08-30 22:30 UTC).
--   Statement 1 has most likely ALREADY been applied by the code. migrateNICSlotPositions()
--   runs on every server-get-config and, since U-D.3b, repairs slot_ref itself -- so the NIC
--   row was repaired the first time anyone opened that build after the deploy. Verified:
--   row 10291 now reads 'pcie_x8_slot_1' in production, row 10423 still reads NULL.
--   Statement 1 is then a guarded no-op, which is the designed behaviour, not a failure.
--   Nothing repairs the SFP row automatically, which is why this file still has to run.


-- 1. The component NIC in config 4dee234b, seated in pcie_x8_slot_1.
UPDATE config_components
   SET slot_ref = 'pcie_x8_slot_1'
 WHERE id             = 10291
   AND config_uuid    = '4dee234b-d4ab-447a-95cd-e321313b1af8'
   AND component_type = 'nic'
   AND spec_uuid      = 'b32ff113-a672-4f13-a45b-a6704cea61eb'
   AND removed_at IS NULL
   AND slot_ref IS NULL;


-- 2. The SFP module in config 0b434826, seated in port 1 of its parent NIC.
--    'port_{N}' is the canonical slot_ref shape for an SFP — the same one
--    ConfigReadRouter::portIndexFromSlotRef() reads back and seeder 2026_08_22_001
--    normalised the other rows to.
UPDATE config_components
   SET slot_ref = 'port_1'
 WHERE id             = 10423
   AND config_uuid    = '0b434826-4aad-4842-a150-cc4d2084e469'
   AND component_type = 'sfp'
   AND spec_uuid      = '32bc2712-98a6-421f-85f5-4efb68e4ee00'
   AND removed_at IS NULL
   AND slot_ref IS NULL;


-- ============================================================
-- Verification (run after the seeder):
--
--   -- Both rows now carry their slot:
--   SELECT id, config_uuid, component_type, spec_uuid, slot_ref
--     FROM config_components
--    WHERE id IN (10291, 10423);
--   -- expect: 'pcie_x8_slot_1' and 'port_1'
--
--   -- And no two live rows share a slot in any configuration (the invariant
--   -- uq_slot_occupancy is NAMED for but does not actually enforce — see above):
--   SELECT config_uuid, slot_ref, COUNT(*) AS occupants
--     FROM config_components
--    WHERE removed_at IS NULL AND slot_ref IS NOT NULL AND slot_ref <> ''
--    GROUP BY config_uuid, slot_ref
--   HAVING occupants > 1;
--   -- expect: empty
-- ============================================================
