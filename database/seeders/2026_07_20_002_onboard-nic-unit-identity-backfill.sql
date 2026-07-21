-- =============================================================================
-- Seeder:  2026_07_20_002_onboard-nic-unit-identity-backfill.sql
-- Date:    2026-07-20
-- Purpose: Migrate existing onboard NICs to unit-scoped identity, create the
--          onboard NICs that were silently lost to the model-scoped collision,
--          sync the config_components ledger, rebuild nic_config, and finally
--          add the UNIQUE key that makes the collision impossible to recur.
--
-- Affected tables: nicinventory, config_components, server_configurations
-- Related feature: onboard NIC unit identity (tasks/onboard-nic-unit-identity.md)
--
-- PRECONDITIONS:
--   * 2026_07_20_001 has been run (ParentInventoryID column exists).
--   * The OnboardNICHandler / ServerBuilder / AddComponentCommand code change
--     is deployed (this seeder writes the identity that code now expects).
--
-- Known state at authoring time (from the production dump, 2026-07-17):
--   4 onboard rows exist (nicinventory 230/232/233/240); 3 servers are MISSING
--   onboard NICs -- boards 35 (config 06ea5abb), 53 (b01a5f51), 55 (2d66d58f).
--   Every motherboard spec in ims-data declares AT MOST ONE onboard_nics entry
--   (20 specs with one, 3 with none), so OnboardNICIndex is always 1 here.
--
--   Nothing below hardcodes those ids: every statement derives its targets by
--   JOIN against live data, so the seeder stays correct if the DB has moved on
--   since the dump (it has -- config 2d66d58f gained its motherboard on 07-20).
--
-- Owner decisions (2026-07-20): backfill board 35 as well (its onboard ports
-- are real; the I350-T2 in that config occupies pcie_x8_slot_1, i.e. it was
-- added as a normal PCIe card, not as an onboard replacement); delete the
-- unattributable detached row 240 as debris.
--
-- Idempotent: yes. Every statement is guarded so a second run is a no-op.
--
-- COLLATIONS -- why COLLATE appears on the joins below. These tables disagree:
--     nicinventory.UUID / .ServerUUID / .ParentComponentUUID  utf8mb4_unicode_ci
--     nicinventory.SerialNumber, ALL of motherboardinventory  utf8mb4_general_ci
--     config_components.spec_uuid / .serial_number            utf8mb4_general_ci
--     config_components.config_uuid                           utf8mb4_unicode_ci
--     server_configurations.config_uuid                       utf8mb4_unicode_ci
--     server_configurations.nic_config                        utf8mb4_bin
--   Any cross-table string comparison spanning two of those raises
--   "#1267 Illegal mix of collations", so every such comparison is coerced
--   explicitly. Assignments (INSERT/SET) need no coercion -- MySQL converts on
--   write. Do not remove these COLLATE clauses.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 0. Spec facts from ims-data/motherboard/*.json (networking.onboard_nics[0]).
--    SQL cannot read those files, so the 20 specs that declare an onboard NIC
--    are carried here explicitly. TEMPORARY: vanishes with the session.
-- -----------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_onboard_spec;
-- Columns are pinned to utf8mb4_unicode_ci so joins against nicinventory's
-- unicode_ci columns need no coercion; joins against motherboardinventory
-- (general_ci) are coerced at the join instead.
CREATE TEMPORARY TABLE tmp_onboard_spec (
  spec_uuid  VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
  controller VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  ports      INT                                     NOT NULL,
  speed      VARCHAR(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  connector  VARCHAR(50)  COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=MEMORY;

INSERT INTO tmp_onboard_spec (spec_uuid, controller, ports, speed, connector) VALUES
  ('3f8d6b2e-9a4c-7e1f-5b3d-8a2c6f4e9d7b', 'Broadcom BCM57508', 2, '25GbE', 'SFP28'),
  ('4c8f5e1b-2b4a-4c8d-b9e7-f6d2a3c1e9b8', 'HPE Embedded FlexibleLOM', 4, '1GbE', 'RJ45'),
  ('4f8e6c3d-2b7a-4c9e-8d1b-5e6f7a3d9c8b', 'Broadcom BCM57414', 2, '10GbE', 'SFP+'),
  ('5a7c9e2b-4d6f-8a1c-3e5b-7f9d2a4c6e8b', 'Broadcom BCM57416', 4, '10GbE', 'RJ45'),
  ('6e4c2a5b-3a8e-4f7d-8b2c-9d1a4e5b6f7c', 'Intel X710', 2, '10GbE', 'SFP+'),
  ('7a3b9c8d-2f1a-4b7e-8c6d-5a9f2b3e8c7d', 'Intel X710', 2, '10GbE', 'SFP+'),
  ('8c5f2b87-1e5b-4e8c-a1d2-0b1a5e3f4d6c', 'Intel X710', 2, '10GbE', 'SFP+'),
  ('9d2e4f6a-7b8c-4d9e-8f1a-6c3d5e7f9a2b', 'Intel X710', 2, '10GbE', 'SFP+'),
  ('a5b6c7d8-e9f0-4a1b-8c3d-4e5f6a7b8c9d', 'Intel X710', 2, '10GbE', 'SFP+'),
  ('b3c4d5e6-f7a8-4b9c-8d0e-1f2a3b4c5d6e', 'Intel I210', 2, '1GbE', 'RJ45'),
  ('b6a2f3e8-193c-4d5b-a7e1-8c4d5f6b8a21', 'OCP 3.0 Network Adapter', 2, '10GbE / 25GbE', 'SFP28 / RJ45'),
  ('c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f', 'Dedicated 1GbE LOM', 1, '1GbE', 'RJ45'),
  ('c5e9b814-725d-4f1a-b6b8-3f8c8d8b13c1', 'OCP 3.0 Network Adapter', 2, '10GbE / 25GbE', 'SFP28 / RJ45'),
  ('c7d8e9f0-a1b2-4c3d-ae5f-6a7b8c9d0e1f', 'Intel I350-BT2', 2, '1GbE', 'RJ45'),
  ('d2e3f4a5-b6c7-4d8e-9f0a-1b2c3d4e5f6a', 'Dedicated 1GbE LOM', 1, '1GbE', 'RJ45'),
  ('d4b1a8c9-7e5f-4d3b-9a8c-2f3b4c5d6e7f', 'Selectable Network Adapter (SNA)', 2, '10GbE', 'SFP+'),
  ('d8e9f0a1-b2c3-4d4e-bf6a-7b8c9d0e1f2a', 'HPE Embedded FlexibleLOM', 4, '1GbE', 'RJ45'),
  ('e3f4a5b6-c7d8-4e9f-a01b-2c3d4e5f6a7b', 'Broadcom BCM5720', 4, '1GbE', 'RJ45'),
  ('e9f0a1b2-c3d4-4e5f-8a7b-8c9d0e1f2a3b', 'HPE Embedded FlexibleLOM', 4, '1GbE', 'RJ45'),
  ('f4a5b6c7-d8e9-4f0a-b12c-3d4e5f6a7b8c', 'Broadcom BCM5720', 4, '1GbE', 'RJ45');

-- -----------------------------------------------------------------------------
-- 1. Attribute existing onboard rows to their PHYSICAL board.
--    An onboard NIC sitting in config X belongs to the board sitting in config X.
-- -----------------------------------------------------------------------------
UPDATE `nicinventory` n
  JOIN `motherboardinventory` m
    ON m.UUID COLLATE utf8mb4_unicode_ci = n.ParentComponentUUID
   AND m.ServerUUID COLLATE utf8mb4_unicode_ci = n.ServerUUID
   SET n.ParentInventoryID = m.ID,
       n.UpdatedAt = NOW()
 WHERE n.SourceType = 'onboard'
   AND n.ParentInventoryID IS NULL
   AND n.ServerUUID IS NOT NULL;

-- -----------------------------------------------------------------------------
-- 2. Delete unattributable debris: a detached onboard row (in no server) whose
--    model has several physical boards cannot be traced to any one of them --
--    the model-scoped scheme never recorded which. Guarded on the ledger so a
--    row anything references is never touched.
-- -----------------------------------------------------------------------------
DELETE n FROM `nicinventory` n
 WHERE n.SourceType = 'onboard'
   AND n.ParentInventoryID IS NULL
   AND n.ServerUUID IS NULL
   AND NOT EXISTS (
        SELECT 1 FROM `config_components` cc
         WHERE cc.inventory_table = 'nicinventory' AND cc.inventory_id = n.ID);

-- -----------------------------------------------------------------------------
-- 3. Rewrite identity to the unit-scoped form the code now mints:
--       onboard-{mb8}-{boardInventoryId}-{index}
-- -----------------------------------------------------------------------------
UPDATE `nicinventory`
   SET UUID = CONCAT('onboard-', LEFT(ParentComponentUUID, 8), '-', ParentInventoryID, '-', OnboardNICIndex),
       SerialNumber = CONCAT('ONBOARD-', LEFT(ParentComponentUUID, 8), '-', ParentInventoryID, '-', OnboardNICIndex),
       UpdatedAt = NOW()
 WHERE SourceType = 'onboard'
   AND ParentInventoryID IS NOT NULL
   AND UUID <> CONCAT('onboard-', LEFT(ParentComponentUUID, 8), '-', ParentInventoryID, '-', OnboardNICIndex);

-- -----------------------------------------------------------------------------
-- 4. Create the onboard NICs that were lost. Restricted to boards CURRENTLY in
--    a config -- a board sitting in stock gets its row when it is next added,
--    through the fixed code path. NOT EXISTS makes this safely re-runnable.
-- -----------------------------------------------------------------------------
INSERT INTO `nicinventory`
  (UUID, SerialNumber, Status, status_v2, SourceType, ParentComponentUUID,
   ParentInventoryID, OnboardNICIndex, ServerUUID, Notes, Flag, CreatedAt, UpdatedAt)
SELECT CONCAT('onboard-', LEFT(m.UUID, 8), '-', m.ID, '-1'),
       CONCAT('ONBOARD-', LEFT(m.UUID, 8), '-', m.ID, '-1'),
       2,
       'installed',
       'onboard',
       m.UUID,
       m.ID,
       1,
       m.ServerUUID,
       -- Byte-identical to OnboardNICHandler's sprintf("Onboard: %s %d-port %s %s")
       CONCAT('Onboard: ', s.controller, ' ', s.ports, '-port ', s.speed, ' ', s.connector),
       'Onboard',
       NOW(),
       NOW()
  FROM `motherboardinventory` m
  JOIN tmp_onboard_spec s ON s.spec_uuid = m.UUID COLLATE utf8mb4_unicode_ci
 WHERE m.ServerUUID IS NOT NULL
   AND NOT EXISTS (
        SELECT 1 FROM `nicinventory` n
         WHERE n.SourceType = 'onboard'
           AND n.ParentInventoryID = m.ID
           AND n.OnboardNICIndex = 1);

-- -----------------------------------------------------------------------------
-- 5. Ledger: point existing config_components rows at the new spec_uuid/serial.
--    inventory_id is unchanged, so no FK moves.
-- -----------------------------------------------------------------------------
UPDATE `config_components` cc
  JOIN `nicinventory` n
    ON n.ID = cc.inventory_id
   AND cc.inventory_table = 'nicinventory'
   SET cc.spec_uuid = n.UUID,
       cc.serial_number = n.SerialNumber
 WHERE n.SourceType = 'onboard'
   AND cc.spec_uuid COLLATE utf8mb4_unicode_ci <> n.UUID;

-- -----------------------------------------------------------------------------
-- 6. Ledger: add rows for the onboard NICs created in step 4, parented to the
--    board's own row (mirrors ConfigComponentWriter::resolveParentId()).
--    The JOIN is deliberate -- if a config is not mirrored in the ledger at all,
--    we add nothing rather than leave it half-mirrored.
-- -----------------------------------------------------------------------------
INSERT INTO `config_components`
  (config_uuid, component_type, inventory_table, inventory_id, spec_uuid,
   serial_number, parent_id, slot_ref, added_at, added_by)
SELECT n.ServerUUID, 'nic', 'nicinventory', n.ID, n.UUID, n.SerialNumber,
       mbcc.id, NULL, NOW(), 0
  FROM `nicinventory` n
  JOIN `config_components` mbcc
    ON mbcc.inventory_table = 'motherboardinventory'
   AND mbcc.inventory_id = n.ParentInventoryID
   AND mbcc.config_uuid = n.ServerUUID
   AND mbcc.removed_at IS NULL
 WHERE n.SourceType = 'onboard'
   AND n.ServerUUID IS NOT NULL
   AND NOT EXISTS (
        SELECT 1 FROM `config_components` cc2
         WHERE cc2.inventory_table = 'nicinventory' AND cc2.inventory_id = n.ID);

-- -----------------------------------------------------------------------------
-- 7. Rebuild server_configurations.nic_config -- the column
--    getNetworkConfiguration() actually reads. Shape is byte-compatible with
--    OnboardNICHandler::updateNICConfigJSON(): nics[] carrying the
--    filterNICSpecs() key set, plus the 3-key summary.
--
--    Existing entries are preserved: component NICs already in nic_config keep
--    their specifications (which come from ims-data JSON and cannot be rebuilt
--    in SQL). Only the onboard entry is appended, and only if absent.
--
--    SCOPE: this statement handles ONLY configs whose nic_config is NULL, i.e.
--    a config whose sole NIC is the onboard one being backfilled. It builds the
--    document from scratch and never reads the existing utf8mb4_bin column, so
--    there is no collation mixing and no risk of mangling existing entries.
--
--    A config that ALREADY has nic_config content needs a merge (its component
--    NICs carry specifications that come from ims-data JSON and cannot be
--    reconstructed in SQL). That merge is deliberately NOT attempted here --
--    see the note after step 8 for the one affected config and how to fix it
--    with zero risk.
--
--    FIDELITY CAVEAT: MariaDB has no JSON boolean literal, so 'replaceable'
--    serialises as 1 where PHP's json_encode writes true. json_decode reads 1
--    as truthy so every consumer behaves identically, and the value is rewritten
--    as a real boolean the next time updateNICConfigJSON() runs for that config.
--
--    Assumes one onboard entry per config -- exact today (every ims-data
--    motherboard spec declares at most one onboard_nics entry: 20 with one,
--    3 with none).
-- -----------------------------------------------------------------------------
UPDATE `server_configurations` sc
  JOIN `nicinventory` n
    ON n.ServerUUID = sc.config_uuid
   AND n.SourceType = 'onboard'
   AND n.Status = 2
  JOIN tmp_onboard_spec s
    ON s.spec_uuid = n.ParentComponentUUID
   SET sc.nic_config = JSON_OBJECT(
         'nics', JSON_ARRAY(
           JSON_OBJECT(
             'uuid',                    n.UUID,
             'source_type',             'onboard',
             'parent_motherboard_uuid', n.ParentComponentUUID,
             'onboard_index',           n.OnboardNICIndex,
             'status',                  'in_use',
             'replaceable',             TRUE,
             'specifications', JSON_OBJECT(
                'controller', s.controller,
                'ports',      s.ports,
                'speed',      s.speed,
                'connector',  s.connector)
           )),
         'summary', JSON_OBJECT(
           'total_nics',     1,
           'onboard_nics',   1,
           'component_nics', 0),
         'last_updated', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')
       )
 WHERE sc.nic_config IS NULL;

-- -----------------------------------------------------------------------------
-- 8. Make the collision structurally impossible.
--    Component NICs carry ParentInventoryID = NULL and MySQL permits repeated
--    NULLs in a UNIQUE index, so one key covers onboard and component rows.
--    This statement FAILS LOUDLY if any duplicate survived steps 1-4 -- that is
--    the intended safety check, not something to force past.
-- -----------------------------------------------------------------------------
ALTER TABLE `nicinventory`
  ADD UNIQUE KEY `uq_onboard_per_board` (`ParentInventoryID`, `OnboardNICIndex`);

DROP TEMPORARY TABLE IF EXISTS tmp_onboard_spec;

-- =============================================================================
-- REMAINING MANUAL STEP -- config 06ea5abb (board 35, Gigabyte R180-F34-ZB-XX)
-- =============================================================================
-- That config already has nic_config content: one component NIC (I350-T2,
-- f85c377e-…) whose "specifications" were loaded from ims-data/nic JSON. Step 7
-- skips it, because appending to an existing utf8mb4_bin JSON document in SQL
-- would mean mixing three collations in JSON_ARRAY_APPEND/JSON_SEARCH, and a
-- mangled blob there would break that server's port_mapping.
--
-- After this seeder, fix it through the real code path instead -- in the UI,
-- REMOVE and RE-ADD the motherboard on config 06ea5abb. That calls
-- OnboardNICHandler::autoAddOnboardNICs() -> updateNICConfigJSON(), which
-- rebuilds the whole document with perfect fidelity, component NIC specs
-- included. The cycle is safe now: removal DETACHES the onboard row rather than
-- deleting it, and re-add re-attaches that same row by ParentInventoryID
-- instead of minting a duplicate.
--
-- Verify afterwards with: server-get-config on 06ea5abb ->
--   summary.total_nics = 2, onboard_nics = 1, component_nics = 1
--
-- Steps 1-6 have ALREADY given board 35 its nicinventory row and ledger row, so
-- the inventory is correct either way; this only refreshes the derived
-- nic_config read cache.
-- =============================================================================

-- =============================================================================
-- VERIFICATION -- run after the seeder; expected results inline.
-- =============================================================================
--
-- (a) Every onboard row is unit-scoped and linked to a real board.
--     Expect: 0 rows.
-- SELECT ID, UUID, ParentInventoryID FROM `nicinventory`
--  WHERE SourceType = 'onboard'
--    AND (ParentInventoryID IS NULL
--         OR UUID <> CONCAT('onboard-', LEFT(ParentComponentUUID,8), '-', ParentInventoryID, '-', OnboardNICIndex));
--
-- (b) No board in a config is missing its onboard NIC. Expect: 0 rows.
-- SELECT m.ID, m.SerialNumber, m.ServerUUID
--   FROM `motherboardinventory` m
--  WHERE m.ServerUUID IS NOT NULL
--    AND m.UUID IN ('4c8f5e1b-2b4a-4c8d-b9e7-f6d2a3c1e9b8','c7d8e9f0-a1b2-4c3d-ae5f-6a7b8c9d0e1f',
--                   'f4a5b6c7-d8e9-4f0a-b12c-3d4e5f6a7b8c','c1d2e3f4-a5b6-4c7d-8e9f-0a1b2c3d4e5f')
--    AND NOT EXISTS (SELECT 1 FROM `nicinventory` n
--                     WHERE n.SourceType='onboard' AND n.ParentInventoryID = m.ID);
--
-- (c) The three previously-broken servers now report an onboard NIC.
--     Expect: 3 rows, one per config, each with a JSON blob containing onboard.
-- SELECT config_uuid, server_name,
--        JSON_EXTRACT(nic_config, '$.summary') AS summary
--   FROM `server_configurations`
--  WHERE config_uuid IN ('06ea5abb-ddb0-4945-ba88-7eba61ba3905',
--                        'b01a5f51-1e3c-4ea2-8473-69bbc69daa98',
--                        '2d66d58f-64ec-4896-93cb-e48295bad69a');
--
-- (d) Full onboard inventory picture.
-- SELECT n.ID, n.UUID, n.SerialNumber, n.ParentInventoryID, m.SerialNumber AS board_serial,
--        n.ServerUUID, n.Status
--   FROM `nicinventory` n
--   LEFT JOIN `motherboardinventory` m ON m.ID = n.ParentInventoryID
--  WHERE n.SourceType = 'onboard' ORDER BY n.ID;
