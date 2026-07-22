-- =============================================================================
-- Seeder:  2026_07_22_004_settle-019eca1d-motherboard.sql
--
-- Date:    2026-07-22
-- Purpose: Settle the one open owner decision blocking the P2 gate — which
--          physical motherboard config 019eca1d holds — and rebuild the records
--          that F-1's delete-config bug destroyed. Takes the last two RED gate
--          reports (inventory, equivalence) to GREEN.
-- Tables:  motherboardinventory, nicinventory, config_components,
--          config_resources, server_configurations
-- Feature: P2 remediation (tasks/p2-verification-findings-20260721.md, F-1/F-2/F-3)
--
-- =============================================================================
-- THE DECISION AND ITS EVIDENCE
--
-- Seeder 2026_07_21_001 section 3 left this case deliberately unrepaired: it
-- read board 55 (SerialNumber '2') as "the only remaining free unit" while
-- noting the config's server_name contradicted that. Two facts now settle it,
-- and BOTH point at board 49 (DCMISGH631X7A5):
--
--   1. server_configurations.server_name for this config IS 'DCMISGH631X7A5',
--      which is board 49's SerialNumber.
--
--   2. config_components row 10016 — live, removed_at NULL — is the onboard NIC
--      'onboard-4c8f5e1b-49-1' (nicinventory 232, ParentInventoryID 49). That
--      identity was minted by seeder 2026_07_20_002 section 1, whose UPDATE
--      joins motherboardinventory to nicinventory ON m.ServerUUID = n.ServerUUID.
--      It could only have attributed NIC 232 to board 49 if board 49 was itself
--      bound to config 019eca1d at that moment — i.e. on 2026-07-20, BEFORE
--      F-1's delete freed boards 49/53/55 at 22:48:46 that same day. It is a
--      snapshot of the binding as it stood before the corruption, not a guess.
--      An onboard NIC cannot be in a server without the board it is soldered to.
--
-- The "board 55 is the only free unit" inference is void: F-1 freed 49 as well,
-- which is the only reason 49 reads free today. Board 55 has no record of any
-- kind tying it to this config.
--
-- =============================================================================
-- IDEMPOTENT: every statement is guarded on the exact expected prior state, so
--             a second run is a no-op. Section 3 uses INSERT ... SELECT with a
--             NOT EXISTS guard rather than INSERT IGNORE, so it cannot create a
--             duplicate row even though no UNIQUE key covers this shape.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 0. INSPECT FIRST (read-only). Expected BEFORE state:
--      board 49  -> Status 1, ServerUUID NULL     (freed by F-1)
--      nic  232  -> Status 1, ServerUUID NULL     (freed by F-1)
--      config_components: NO motherboard row for this config
--      nic_config JSON  -> nics[0].uuid = 'onboard-4c8f5e1b-1'  (stale, pre-rekey)
-- -----------------------------------------------------------------------------
--   SELECT ID, SerialNumber, Status, status_v2, ServerUUID
--     FROM motherboardinventory WHERE ID IN (49, 55);
--   SELECT ID, UUID, Status, status_v2, ServerUUID, ParentInventoryID
--     FROM nicinventory WHERE ID = 232;
--   SELECT id, component_type, inventory_id, parent_id FROM config_components
--    WHERE config_uuid = '019eca1d-069b-4ebe-91ba-6f856b1c99ef' AND removed_at IS NULL;
--   SELECT JSON_EXTRACT(nic_config, '$.nics[*].uuid') FROM server_configurations
--    WHERE config_uuid = '019eca1d-069b-4ebe-91ba-6f856b1c99ef';


-- -----------------------------------------------------------------------------
-- 1. Re-bind board 49 to the config. Restores what F-1 released.
--    InstallationDate is left NULL: F-1 destroyed the original value and the
--    true install date is not recoverable. Inventing NOW() would assert a fact
--    that is false.
-- -----------------------------------------------------------------------------
UPDATE `motherboardinventory`
   SET Status      = 2,
       status_v2   = 'installed',
       ServerUUID  = '019eca1d-069b-4ebe-91ba-6f856b1c99ef',
       UpdatedAt   = NOW()
 WHERE ID = 49
   AND Status = 1
   AND ServerUUID IS NULL;

-- -----------------------------------------------------------------------------
-- 2. Re-bind that board's onboard NIC. It is physically part of board 49, so it
--    follows the board by construction.
-- -----------------------------------------------------------------------------
UPDATE `nicinventory`
   SET Status      = 2,
       status_v2   = 'installed',
       ServerUUID  = '019eca1d-069b-4ebe-91ba-6f856b1c99ef',
       UpdatedAt   = NOW()
 WHERE ID = 232
   AND ParentInventoryID = 49
   AND Status = 1
   AND ServerUUID IS NULL;

-- -----------------------------------------------------------------------------
-- 3. Create the missing config_components motherboard row.
--    This config predates dual-write, so it never had one — that absence is why
--    equivalence_report reports the motherboard as only_in_json.
--    added_at mirrors the config's other backfilled rows; added_by = 0 is the
--    documented "no authenticated actor" value (ServerBuilder.php:894).
-- -----------------------------------------------------------------------------
INSERT INTO `config_components`
       (config_uuid, component_type, inventory_table, inventory_id,
        spec_uuid, serial_number, parent_id, slot_ref, added_at, added_by, removed_at)
SELECT '019eca1d-069b-4ebe-91ba-6f856b1c99ef',
       'motherboard',
       'motherboardinventory',
       49,
       '4c8f5e1b-2b4a-4c8d-b9e7-f6d2a3c1e9b8',
       'DCMISGH631X7A5',
       NULL,
       NULL,
       '2026-07-15 20:01:19',
       0,
       NULL
 WHERE NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM `config_components`) AS cc
         WHERE cc.config_uuid = '019eca1d-069b-4ebe-91ba-6f856b1c99ef'
           AND cc.component_type = 'motherboard'
           AND cc.removed_at IS NULL);

-- -----------------------------------------------------------------------------
-- 4. Parent the onboard NIC row to the board row, matching the shape every other
--    config uses (e.g. b01a5f51: nic row 10083 -> motherboard row 10069).
-- -----------------------------------------------------------------------------
UPDATE `config_components` nic
  JOIN `config_components` mb
    ON  mb.config_uuid    = nic.config_uuid
   AND  mb.component_type = 'motherboard'
   AND  mb.removed_at IS NULL
   SET  nic.parent_id = mb.id
 WHERE nic.config_uuid    = '019eca1d-069b-4ebe-91ba-6f856b1c99ef'
   AND nic.component_type = 'nic'
   AND nic.inventory_id   = 232
   AND nic.removed_at IS NULL
   AND nic.parent_id IS NULL;

-- -----------------------------------------------------------------------------
-- 5. Ledger provider rows for the board. Mirrors b01a5f51's rows for the SAME
--    board model (4c8f5e1b): cpu_socket 2, dimm_slot 24, riser_slot x3.
--    Without these the board would appear in config_components while providing
--    no resources, which is not the shape any other config has.
-- -----------------------------------------------------------------------------
INSERT INTO `config_resources` (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id)
SELECT '019eca1d-069b-4ebe-91ba-6f856b1c99ef', r.resource, mb.id, r.slot_ref, r.capacity, NULL
  FROM (SELECT * FROM `config_components`) AS mb
  JOIN (
        SELECT 'cpu_socket' AS resource, NULL        AS slot_ref,  2 AS capacity
  UNION SELECT 'dimm_slot',              NULL,                    24
  UNION SELECT 'riser_slot',             'riser_1_x16',            1
  UNION SELECT 'riser_slot',             'riser_2_x8',             1
  UNION SELECT 'riser_slot',             'riser_3_x8',             1
       ) AS r
 WHERE mb.config_uuid    = '019eca1d-069b-4ebe-91ba-6f856b1c99ef'
   AND mb.component_type = 'motherboard'
   AND mb.removed_at IS NULL
   AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM `config_resources`) AS cr
         WHERE cr.config_uuid = '019eca1d-069b-4ebe-91ba-6f856b1c99ef'
           AND cr.provider_id = mb.id
           AND cr.resource    = r.resource
           AND (cr.slot_ref <=> r.slot_ref));

-- -----------------------------------------------------------------------------
-- 6. F-3, deferred half: migrate this config's stale onboard-NIC JSON identity.
--    Seeder 2026_07_21_005 fixed the other three configs and deliberately skipped
--    this one until the board was settled. It is settled above, so it migrates now.
-- -----------------------------------------------------------------------------
UPDATE `server_configurations`
   SET nic_config = JSON_REPLACE(nic_config, '$.nics[0].uuid', 'onboard-4c8f5e1b-49-1')
 WHERE config_uuid = '019eca1d-069b-4ebe-91ba-6f856b1c99ef'
   AND JSON_UNQUOTE(JSON_EXTRACT(nic_config, '$.nics[0].uuid')) = 'onboard-4c8f5e1b-1';


-- =============================================================================
-- VERIFY — all three should return zero rows / the stated values.
--
--   -- (a) board 49 and its onboard NIC both bound to this config
--   SELECT ID, Status, status_v2, ServerUUID FROM motherboardinventory WHERE ID = 49;
--       -- expect 2 / installed / 019eca1d-069b-4ebe-91ba-6f856b1c99ef
--   SELECT ID, Status, status_v2, ServerUUID FROM nicinventory WHERE ID = 232;
--       -- expect 2 / installed / 019eca1d-069b-4ebe-91ba-6f856b1c99ef
--
--   -- (b) board row exists, onboard NIC is parented to it
--   SELECT id, component_type, inventory_id, parent_id FROM config_components
--    WHERE config_uuid = '019eca1d-069b-4ebe-91ba-6f856b1c99ef' AND removed_at IS NULL
--      AND component_type IN ('motherboard','nic');
--       -- expect the nic row's parent_id = the motherboard row's id
--
--   -- (c) JSON and rows agree on the onboard NIC identity
--   SELECT JSON_UNQUOTE(JSON_EXTRACT(nic_config, '$.nics[0].uuid')) FROM server_configurations
--    WHERE config_uuid = '019eca1d-069b-4ebe-91ba-6f856b1c99ef';
--       -- expect onboard-4c8f5e1b-49-1
--
-- After this seeder, all four P2 gate reports (equivalence, orphan, ledger,
-- inventory) are GREEN — verified on a scratch replica loaded from the
-- 2026-07-22 production dump.
-- =============================================================================
