-- =============================================================================
-- 2026_07_21_001_repair-delete-config-collateral.sql
--
-- Date:     2026-07-21
-- Purpose:  Repair inventory rows wrongly released by the deleteConfiguration()
--           model-vs-unit defect (finding F-1, tasks/p2-verification-findings-20260721.md).
--           Code fix deployed the same day in
--           core/models/server/ServerBuilder.php::deleteConfiguration().
-- Tables:   motherboardinventory, nicinventory
-- Feature:  P2 gate remediation (migration/phase-status.json)
--
-- BACKGROUND
--   deleteConfiguration() released components with
--   updateComponentStatusAndServerUuid(..., $serialNumber = null), collapsing that
--   method's WHERE to `UUID = ?` alone -- which matches EVERY physical unit sharing
--   the model UUID. One delete on 2026-07-20 22:48:46 therefore set
--   Status=1 / ServerUUID=NULL on motherboards 49, 53 and 55 (all model
--   4c8f5e1b-2b4a-4c8d-b9e7-f6d2a3c1e9b8) across three different configurations.
--
--   Their onboard NICs were NOT freed: the 2026-07-20 unit-identity work gave onboard
--   NICs per-unit UUIDs, so `WHERE UUID = ?` matched exactly one row each. That left
--   NIC rows claiming a config while their parent board read "available" -- the
--   inconsistency inventory_report.php flagged.
--
-- AUTHORITY FOR THE REPAIR
--   config_components (the U-1.5 dual-write table) records which physical unit belongs
--   to which config, by (inventory_table, inventory_id), with removed_at marking
--   genuine removals. It is used here as the source of truth:
--     - b01a5f51 -> motherboardinventory 53, removed_at IS NULL  => still live, restore
--     - 2d66d58f -> motherboardinventory 49, removed_at SET      => genuinely removed,
--                                                                   leave released
--
-- IDEMPOTENT: every statement is a guarded UPDATE keyed on the exact expected wrong
-- state, so re-running is a no-op.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Restore motherboard 53 (SGH732T6MY) to config b01a5f51
--    Wrongly freed as collateral. config_components still shows it live
--    (removed_at IS NULL), and its onboard NIC 257 never stopped pointing at
--    this config (Status=2, ServerUUID=b01a5f51).
-- -----------------------------------------------------------------------------
UPDATE motherboardinventory
   SET Status     = 2,
       status_v2  = 'installed',
       ServerUUID = 'b01a5f51-1e3c-4ea2-8473-69bbc69daa98',
       UpdatedAt  = NOW()
 WHERE ID = 53
   AND SerialNumber = 'SGH732T6MY'
   AND Status = 1
   AND ServerUUID IS NULL;

-- -----------------------------------------------------------------------------
-- 2. Fix onboard NIC 256 (onboard-4c8f5e1b-55-1) status_v2 drift
--    Parent board 55 is unallocated and the NIC's own ServerUUID is NULL, so
--    status_v2 'installed' contradicts both. Legacy Status=1 is correct; bring
--    status_v2 into line with it (StatusMap::INVENTORY_LEGACY_TO_V2[1]='available').
--    This clears inventory_report's status_v2_legacy_mismatch violation.
-- -----------------------------------------------------------------------------
UPDATE nicinventory
   SET status_v2 = 'available',
       UpdatedAt = NOW()
 WHERE ID = 256
   AND UUID = 'onboard-4c8f5e1b-55-1'
   AND Status = 1
   AND ServerUUID IS NULL
   AND status_v2 = 'installed';

-- -----------------------------------------------------------------------------
-- 3. NOT REPAIRED HERE -- needs an owner decision (deliberately left alone)
--
--    Config 019eca1d-069b-4ebe-91ba-6f856b1c99ef references motherboard model
--    4c8f5e1b in its JSON and still holds 2 CPUs, 8 RAM, 2 storage and 1 chassis,
--    but has NO physical board allocated and NO config_components row for a
--    motherboard (it predates dual-write), so there is no authoritative record of
--    WHICH physical board it owned.
--
--    Board 55 (SerialNumber '2') is the only remaining free unit of that model and
--    is the likely candidate -- but note config 019eca1d's server_name is
--    'DCMISGH631X7A5', which is board 49's serial, so the naming actively conflicts
--    with that inference. Guessing here would silently assign the wrong physical
--    board to a real server record.
--
--    ==> Confirm which board 019eca1d physically holds, then run ONE of:
--
--    -- if it is board 55:
--    -- UPDATE motherboardinventory SET Status=2, status_v2='installed',
--    --        ServerUUID='019eca1d-069b-4ebe-91ba-6f856b1c99ef', UpdatedAt=NOW()
--    --  WHERE ID=55 AND Status=1 AND ServerUUID IS NULL;
--    -- UPDATE nicinventory SET Status=2, status_v2='installed',
--    --        ServerUUID='019eca1d-069b-4ebe-91ba-6f856b1c99ef', UpdatedAt=NOW()
--    --  WHERE ID=256 AND Status=1 AND ServerUUID IS NULL;   -- supersedes step 2
--
--    -- if the config no longer has a board at all, clear the stale JSON reference
--    -- through the UI (remove the motherboard) rather than by SQL.
-- -----------------------------------------------------------------------------

-- -----------------------------------------------------------------------------
-- 4. ALSO NOT REPAIRED HERE -- config 2d66d58f ('Anshit server') has
--    motherboard_uuid = NULL after a remove/re-add cycle on 2026-07-20 17:18 that
--    was left half-finished (finding F-4). Its board 49 is correctly released.
--    Fix by RE-ADDING a motherboard in the UI -- that rebuilds nic_config through
--    OnboardNICHandler with full fidelity, which SQL JSON surgery cannot do safely
--    (see 2026_07_20_002's own note on mixed collations in JSON functions).
-- -----------------------------------------------------------------------------

-- =============================================================================
-- VERIFY (expect zero rows from each):
--
--   -- no unit referenced by a live config while marked available
--   SELECT m.ID, m.SerialNumber, m.Status, m.ServerUUID
--     FROM config_components cc
--     JOIN motherboardinventory m ON m.ID = cc.inventory_id
--    WHERE cc.component_type = 'motherboard'
--      AND cc.removed_at IS NULL
--      AND (m.Status <> 2 OR m.ServerUUID IS NULL);
--
--   -- no status_v2 / legacy Status disagreement on NICs
--   SELECT ID, UUID, Status, status_v2 FROM nicinventory
--    WHERE (status_v2 = 'installed' AND Status <> 2)
--       OR (status_v2 = 'available' AND Status <> 1);
--
-- Then re-run:  php scripts/verify/run_all.php --gate P2
-- =============================================================================
