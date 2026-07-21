-- =============================================================================
-- 2026_07_21_005_migrate-stale-onboard-nic-json.sql
--
-- Date:     2026-07-21
-- Purpose:  Bring server_configurations.nic_config into agreement with
--           config_components for onboard NICs. Finding F-3,
--           tasks/p2-verification-findings-20260721.md.
-- Tables:   server_configurations
-- Feature:  P2 remediation — equivalence_report must be GREEN before the gate
--
-- BACKGROUND
--   Seeder 2026_07_20_002 re-keyed onboard NICs from a MODEL-scoped identity to
--   a UNIT-scoped one, so config_components now holds
--       onboard-{board-model-prefix}-{motherboardinventory.ID}-{index}
--   while the legacy config JSON was never migrated and still holds the old
--   model-scoped
--       onboard-{board-model-prefix}-{index}
--   equivalence_report compares the two sides component-by-component, so every
--   affected config reports one only_in_json plus one only_in_rows entry.
--
-- SCOPE — 3 of the 4 configs equivalence flags. Deliberately NOT included:
--
--   019eca1d-069b-4ebe-91ba-6f856b1c99ef
--     Its row says onboard-4c8f5e1b-49-1, i.e. motherboard unit #49. But #49 is
--     Status = 1 (available, ServerUUID NULL) and the config has no live
--     motherboard row at all — this is the open owner decision recorded in
--     seeder 2026_07_21_001 section 3 (#49 DCMISGH631X7A5 matches the config's
--     server_name; #55 is the only free unit). Writing "49" into the JSON here
--     would silently ratify one side of a decision that has not been made.
--     Migrate this config only AFTER its board is settled.
--
-- THE THIRD CASE IS NOT A RENAME
--   06ea5abb has NO onboard NIC in its JSON at all, while the rows have one.
--   Its board (c7d8e9f0, motherboardinventory #35, correctly bound Status = 2)
--   declares networking.onboard_nics = [Intel I350-BT2, 2 ports, 1GbE, RJ45] in
--   ims-data/motherboard/motherboard-level-3.json. So the ROWS are right and the
--   JSON is incomplete: this is an INSERT, and summary counts move with it.
--
-- NOTE ON FORMATTING
--   MariaDB's JSON functions re-serialise the whole document, so the two
--   pretty-printed rows come back compact. Semantically identical; every reader
--   goes through json_decode(). Called out so the diff is not a surprise.
--
-- IDEMPOTENT: each statement matches only on the OLD value, so a second run is
--   a no-op.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 0. INSPECT FIRST (read-only)
-- -----------------------------------------------------------------------------
--   SELECT config_uuid,
--          JSON_EXTRACT(nic_config, '$.nics[*].uuid')     AS json_nic_uuids,
--          JSON_EXTRACT(nic_config, '$.summary')          AS json_summary
--     FROM server_configurations
--    WHERE config_uuid IN ('809d10c9-cff2-4e49-88a5-83dccab09f8f',
--                          '9dbc63fa-900c-4c4d-bd28-7bde703b34ad',
--                          '06ea5abb-ddb0-4945-ba88-7eba61ba3905');
--
--   SELECT config_uuid, spec_uuid FROM config_components
--    WHERE component_type = 'nic' AND spec_uuid LIKE 'onboard-%'
--      AND removed_at IS NULL;

-- -----------------------------------------------------------------------------
-- 1. 809d10c9 — onboard-f4a5b6c7-1 -> onboard-f4a5b6c7-36-1
--    motherboardinventory #36, serial 3X8H3W2, Status 2, bound to this config.
-- -----------------------------------------------------------------------------
UPDATE server_configurations
   SET nic_config = JSON_REPLACE(nic_config, '$.nics[0].uuid', 'onboard-f4a5b6c7-36-1')
 WHERE config_uuid = '809d10c9-cff2-4e49-88a5-83dccab09f8f'
   AND JSON_UNQUOTE(JSON_EXTRACT(nic_config, '$.nics[0].uuid')) = 'onboard-f4a5b6c7-1';

-- -----------------------------------------------------------------------------
-- 2. 9dbc63fa — onboard-c1d2e3f4-1 -> onboard-c1d2e3f4-39-1
--    motherboardinventory #39, serial QTFCR29370299, Status 2, bound here.
--    (#43 and #44 share the model and are free — exactly why the unit-scoped
--     identity exists.)
-- -----------------------------------------------------------------------------
UPDATE server_configurations
   SET nic_config = JSON_REPLACE(nic_config, '$.nics[0].uuid', 'onboard-c1d2e3f4-39-1')
 WHERE config_uuid = '9dbc63fa-900c-4c4d-bd28-7bde703b34ad'
   AND JSON_UNQUOTE(JSON_EXTRACT(nic_config, '$.nics[0].uuid')) = 'onboard-c1d2e3f4-1';

-- -----------------------------------------------------------------------------
-- 3. 06ea5abb — add the missing onboard NIC, then fix the summary counts.
--    Shape copied from the onboard entries in the configs above; specifications
--    copied from the board's ims-data networking.onboard_nics[0].
-- -----------------------------------------------------------------------------
UPDATE server_configurations
--    NOTE on the two MariaDB quirks this line works around:
--      - default sql_mode treats `||` as logical OR, not concat, so the object
--        cannot be assembled across lines with `||`;
--      - MariaDB has no CAST(... AS JSON). JSON_EXTRACT(<literal>, '$') is the
--        supported way to turn a literal into a JSON value, and unlike passing
--        the raw string (which would be appended as a quoted scalar) it inserts
--        a real object and keeps `replaceable` a true boolean rather than 1.
   SET nic_config = JSON_ARRAY_APPEND(nic_config, '$.nics', JSON_EXTRACT('{"uuid":"onboard-c7d8e9f0-35-1","source_type":"onboard","parent_motherboard_uuid":"c7d8e9f0-a1b2-4c3d-ae5f-6a7b8c9d0e1f","onboard_index":1,"status":"in_use","replaceable":true,"specifications":{"controller":"Intel I350-BT2","ports":2,"speed":"1GbE","connector":"RJ45"}}', '$'))
 WHERE config_uuid = '06ea5abb-ddb0-4945-ba88-7eba61ba3905'
   AND JSON_SEARCH(nic_config, 'one', 'onboard-c7d8e9f0-35-1') IS NULL;

UPDATE server_configurations
   SET nic_config = JSON_SET(nic_config,
         '$.summary.total_nics',   JSON_LENGTH(nic_config, '$.nics'),
         '$.summary.onboard_nics', 1)
 WHERE config_uuid = '06ea5abb-ddb0-4945-ba88-7eba61ba3905'
   AND JSON_SEARCH(nic_config, 'one', 'onboard-c7d8e9f0-35-1') IS NOT NULL
   AND JSON_EXTRACT(nic_config, '$.summary.onboard_nics') = 0;

-- =============================================================================
-- VERIFY
--
--   -- expect each config's JSON nic uuid to equal its config_components spec_uuid
--   SELECT sc.config_uuid,
--          JSON_EXTRACT(sc.nic_config, '$.nics[*].uuid') AS json_uuids,
--          GROUP_CONCAT(cc.spec_uuid)                    AS row_uuids
--     FROM server_configurations sc
--     LEFT JOIN config_components cc
--       ON cc.config_uuid COLLATE utf8mb4_general_ci
--        = sc.config_uuid COLLATE utf8mb4_general_ci
--      AND cc.component_type = 'nic' AND cc.removed_at IS NULL
--    WHERE sc.config_uuid IN ('809d10c9-cff2-4e49-88a5-83dccab09f8f',
--                             '9dbc63fa-900c-4c4d-bd28-7bde703b34ad',
--                             '06ea5abb-ddb0-4945-ba88-7eba61ba3905')
--    GROUP BY sc.config_uuid;
--
--   -- 06ea5abb summary must read total 2 / onboard 1 / component 1
--   SELECT JSON_EXTRACT(nic_config, '$.summary') FROM server_configurations
--    WHERE config_uuid = '06ea5abb-ddb0-4945-ba88-7eba61ba3905';
--
-- Then re-run:  php scripts/verify/run_all.php --gate P2
--   equivalence diff_count should fall 5 -> 2, both remaining on 019eca1d
--   (its board) and 2d66d58f (its missing motherboard, finding F-4).
-- =============================================================================
