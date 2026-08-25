-- =============================================================================
-- 2026_08_25_006_repair-orphaned-onboard-nics.sql
--
-- Date:     2026-08-25
-- Purpose:  Repair onboard NICs stranded by a motherboard removal, which left
--           their configurations permanently undeletable.
-- Tables:   server_configurations (nic_config), nicinventory
-- Feature:  Onboard-NIC orphan fix -- tasks/todo.md section 9
--
-- Run any time after the code fix in RemoveComponentCommand deploys. Independent
-- of the compute-platform seeders (002-005); order between them does not matter.
--
-- =============================================================================
-- WHY
--
--   Removing a motherboard through the command layer (production runs
--   COMMAND_LAYER_ENABLED=enforce) detached nothing from nic_config. The board's
--   synthetic "onboard-<board>-<unit>-<n>" entries survived it, pointing at a
--   parent_motherboard_uuid that no longer exists.
--
--   The consequence was not cosmetic: summarizeInstalledComponents() reads that
--   blob, so server-delete-config counted a phantom "1 network card" forever and
--   refused to delete the server -- while the network view, which resolves the
--   parent board, correctly reported zero NICs. There was no way to clear it from
--   the UI: the component the user was told to remove was not visible anywhere.
--
--   RemoveComponentCommand now detaches them the way the legacy path always did.
--   This file repairs the configurations that were stranded before that fix.
--
-- =============================================================================
-- SCOPE / SAFETY
--
--   Both statements are restricted to configurations with NO motherboard
--   (motherboard_uuid IS NULL). A config that still has its board is never
--   touched, so a healthy build's onboard ports cannot be stripped by this file.
--
--   That restriction is also what makes it correct to clear nic_config wholesale
--   below rather than surgically editing JSON: a configuration with no board has
--   no PCIe slots either, so it can hold no component NIC. Removing a board is
--   BLOCKED while component NICs are installed (ServerBuilder's
--   motherboard_has_dependents check), so the only NIC entries that can survive a
--   board removal are the onboard ones this file is here to clear. The LIKE guard
--   keeps the UPDATE off any row that does not actually carry one.
--
--   Idempotent: re-running matches nothing once repaired.
--
--   Measure the blast radius before running:
--     SELECT config_uuid, server_name
--       FROM server_configurations
--      WHERE motherboard_uuid IS NULL
--        AND nic_config IS NOT NULL
--        AND nic_config LIKE '%onboard-%';
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. Release the physical ports back to stock.
--
--    Mirrors OnboardNICHandler::removeOnboardNICs() exactly, including its
--    treatment of a port disabled by replaceOnboardNIC(): Flag='replaced' keeps
--    its Status, because it left the server without becoming available again
--    (and status_v2 moves with Status -- fix F-14).
-- -----------------------------------------------------------------------------
UPDATE `nicinventory` n
  JOIN `server_configurations` sc
    ON sc.`config_uuid` = n.`ServerUUID`
   SET n.`ServerUUID` = NULL,
       n.`Status`     = CASE WHEN n.`Flag` = 'replaced' THEN n.`Status`    ELSE 1           END,
       n.`status_v2`  = CASE WHEN n.`Flag` = 'replaced' THEN n.`status_v2` ELSE 'available' END,
       n.`UpdatedAt`  = NOW()
 WHERE n.`SourceType` = 'onboard'
   AND sc.`motherboard_uuid` IS NULL;


-- -----------------------------------------------------------------------------
-- 2. Clear the stale blob that was blocking deletion.
-- -----------------------------------------------------------------------------
UPDATE `server_configurations`
   SET `nic_config`  = NULL,
       `updated_at`  = NOW()
 WHERE `motherboard_uuid` IS NULL
   AND `nic_config` IS NOT NULL
   AND `nic_config` LIKE '%onboard-%';


-- -----------------------------------------------------------------------------
-- 3. Drop the matching rows-store entries, so the two stores agree.
--
--    Deletes only childless rows: an SFP row still parented to one of these NICs
--    would mean a port that is genuinely still in use, which is not the stranded
--    state this file repairs and is left alone for a human to look at.
-- -----------------------------------------------------------------------------
DELETE cc
  FROM `config_components` cc
  JOIN `server_configurations` sc
    ON sc.`config_uuid` = cc.`config_uuid`
  LEFT JOIN `config_components` child
    ON child.`parent_id` = cc.`id`
 WHERE cc.`component_type` = 'nic'
   AND sc.`motherboard_uuid` IS NULL
   AND child.`id` IS NULL;


-- =============================================================================
-- Verification (run after the seeder):
--
--   SELECT COUNT(*) AS still_stranded
--     FROM server_configurations
--    WHERE motherboard_uuid IS NULL AND nic_config LIKE '%onboard-%';
--   -- expected: 0
--
--   SELECT COUNT(*) AS still_bound
--     FROM nicinventory n
--     JOIN server_configurations sc ON sc.config_uuid = n.ServerUUID
--    WHERE n.SourceType = 'onboard' AND sc.motherboard_uuid IS NULL;
--   -- expected: 0
--
--   -- The config stranded during verification should now delete cleanly:
--   --   CTRL-NOPLATFORM-01 = 4cd12004-5913-4751-bffa-60fd11ae70d5
-- =============================================================================
