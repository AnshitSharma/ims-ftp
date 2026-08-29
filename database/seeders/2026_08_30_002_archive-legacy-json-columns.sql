-- ============================================================
-- Seeder : 2026_08_30_002_archive-legacy-json-columns
-- Date   : 2026-08-30
-- Purpose: Take a permanent, in-database copy of the nine legacy JSON columns before
--          seeder 2026_08_30_003 drops them.
--
-- Tables : server_configurations_json_archive (created), server_configurations (read only)
-- Feature: migration U-D.3c — makes the irreversible step reversible in practice
--
-- RUN THIS FIRST. 2026_08_30_003 drops the columns this reads; run them the other way
-- round and the archive is empty and the data is gone. 003 opens with a check for
-- exactly that.
--
-- ============================================================
-- WHY AN ARCHIVE AND NOT A BACKFILL
--
--   U-D.3's stated precondition was "backfill config_components for configs with JSON
--   and no rows". Exactly one config is in that state, and it cannot be backfilled:
--   3918a957 is the only is_virtual=1 build in the system, its eight components have no
--   inventory units at all (all eleven *inventory tables return zero rows for its
--   ServerUUID), and config_components.inventory_id is NOT NULL. There is nothing for a
--   row to point at. Virtual builds are excluded from the rows store by design —
--   ConfigComponentWriter::afterLegacyAdd() says so in its own guard.
--
--   So for that one config the JSON columns really are the only copy of the data, which
--   is precisely the objection that stalled U-D.3. Archiving answers it directly: after
--   this seeder the drop destroys nothing, because every byte still exists in a table
--   nothing writes to.
--
--   Worth being clear about what is and is not lost for that config. Its component list
--   ALREADY reads as empty today — P9 made ConfigReadRouter the only read path and that
--   path answers from rows, so `server-get-config` has been returning
--   `components: []` and `total_components: 0` for it since before this work started.
--   The drop takes away a copy nothing reads; the archive keeps even that.
--
-- ============================================================
-- SCOPE
--
--   Every configuration, not only the affected one. An archive that covers a subset is
--   an archive you have to reason about before trusting; this one you do not. 18 rows
--   at the time of writing.
--
--   The two SCALAR columns motherboard_uuid and chassis_uuid are deliberately NOT
--   archived: they are not dropped. See the scope decision in
--   tasks/u-d3-json-column-retirement.md — they are read far more widely than the JSON
--   set (ServerState::getMotherboardUuid(), the whole platform-lock path) and nothing in
--   P9 moved them.
--
-- ============================================================
-- IDEMPOTENCY
--
--   CREATE TABLE IF NOT EXISTS + INSERT IGNORE on a config_uuid primary key. Re-running
--   adds any configuration that appeared since and leaves existing rows untouched.
--
--   Leaving them untouched is correct rather than lazy: the writers that maintained
--   these columns were deleted in U-D.3a and are already deployed, so the values are
--   frozen. A second run cannot have newer data to offer, and refreshing would risk
--   overwriting a good archive row with a column some later change had blanked.
--
--   Plain DDL/DML only — no schema catalogue lookups, which the app DB user cannot read
--   and which fail open when they error (see 2026_08_25_005 and the note in CLAUDE.md).
-- ============================================================


CREATE TABLE IF NOT EXISTS `server_configurations_json_archive` (
  -- COLLATIONS ARE COPIED FROM THE SOURCE, NOT INHERITED FROM THE TABLE DEFAULT.
  -- server_configurations.config_uuid is utf8mb4_unicode_ci while hbacard_uuid is
  -- utf8mb4_general_ci; a table-default archive makes every JOIN and <=> comparison
  -- against the source die with #1267 illegal-mix -- including the ones in this file's
  -- own verification block and in the rollback procedure, which is exactly when you
  -- least want a surprise.
  `config_uuid`             varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'server_configurations.config_uuid at archive time',
  `archived_at`             datetime    NOT NULL DEFAULT current_timestamp(),
  `cpu_configuration`       longtext    DEFAULT NULL,
  `ram_configuration`       longtext    DEFAULT NULL,
  `storage_configuration`   longtext    DEFAULT NULL,
  `caddy_configuration`     longtext    DEFAULT NULL,
  `nic_config`              longtext    DEFAULT NULL,
  `sfp_configuration`       longtext    DEFAULT NULL,
  `pciecard_configurations` longtext    DEFAULT NULL,
  `hbacard_config`          longtext    DEFAULT NULL,
  `hbacard_uuid`            varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`config_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='U-D.3c: frozen copy of the nine legacy JSON columns dropped from server_configurations. Written once by seeder 2026_08_30_002; no application code reads or writes this table.';


-- No FK to server_configurations on purpose: an archive must survive the deletion of
-- the row it describes, which is the one moment it is most likely to be wanted.
INSERT IGNORE INTO `server_configurations_json_archive`
    (`config_uuid`, `cpu_configuration`, `ram_configuration`, `storage_configuration`,
     `caddy_configuration`, `nic_config`, `sfp_configuration`,
     `pciecard_configurations`, `hbacard_config`, `hbacard_uuid`)
SELECT `config_uuid`, `cpu_configuration`, `ram_configuration`, `storage_configuration`,
       `caddy_configuration`, `nic_config`, `sfp_configuration`,
       `pciecard_configurations`, `hbacard_config`, `hbacard_uuid`
  FROM `server_configurations`
 WHERE `config_uuid` IS NOT NULL;


-- ============================================================
-- Verification (run after the seeder, BEFORE 2026_08_30_003):
--
--   -- Every configuration is archived:
--   SELECT (SELECT COUNT(*) FROM server_configurations WHERE config_uuid IS NOT NULL)
--            AS configs,
--          (SELECT COUNT(*) FROM server_configurations_json_archive) AS archived;
--   -- expect: the two numbers equal
--
--   -- and every value matches, column for column (0 rows = perfect copy):
--   SELECT sc.config_uuid
--     FROM server_configurations sc
--     JOIN server_configurations_json_archive a ON a.config_uuid = sc.config_uuid
--    WHERE NOT (sc.cpu_configuration       <=> a.cpu_configuration)
--       OR NOT (sc.ram_configuration       <=> a.ram_configuration)
--       OR NOT (sc.storage_configuration   <=> a.storage_configuration)
--       OR NOT (sc.caddy_configuration     <=> a.caddy_configuration)
--       OR NOT (sc.nic_config              <=> a.nic_config)
--       OR NOT (sc.sfp_configuration       <=> a.sfp_configuration)
--       OR NOT (sc.pciecard_configurations <=> a.pciecard_configurations)
--       OR NOT (sc.hbacard_config          <=> a.hbacard_config)
--       OR NOT (sc.hbacard_uuid            <=> a.hbacard_uuid);
--   -- expect: empty
--
--   -- The one config whose JSON is its only copy is in there with its 8 components:
--   SELECT config_uuid, CHAR_LENGTH(cpu_configuration) AS cpu_len,
--          CHAR_LENGTH(ram_configuration) AS ram_len
--     FROM server_configurations_json_archive
--    WHERE config_uuid = '3918a957-e56e-42ca-99a1-ea4965cbeb55';
--   -- expect: one row, both lengths non-zero
-- ============================================================
