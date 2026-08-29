-- ============================================================
-- Seeder : 2026_08_30_003_drop-legacy-json-columns
-- Date   : 2026-08-30
-- Purpose: U-D.3c — drop the nine legacy JSON component columns from
--          server_configurations. config_components is the only store.
--
-- Tables : server_configurations (nine columns dropped)
-- Feature: migration U-D.3, the final step of the component-storage migration
--
-- ============================================================
--   *** IRREVERSIBLE. READ THE PRECONDITIONS BEFORE RUNNING. ***
-- ============================================================
--
-- 1. RUN 2026_08_30_002 FIRST and verify it. That seeder copies all nine columns into
--    server_configurations_json_archive. Without it this drop destroys the only copy of
--    config 3918a957's eight components (a virtual build, which cannot be represented in
--    config_components at all — see 002's header). This file REFUSES to run without it;
--    see the guard below.
--
-- 2. A restore-tested logical backup of server_configurations, same day. This is the one
--    precondition the 2026-08-22 owner decision explicitly did NOT waive when it waived
--    the soak windows. The archive in (1) covers these nine columns; a backup covers the
--    mistake this seeder has not thought of.
--
-- 3. An owner GO.
--
-- 4. The code must already be deployed. It is: U-D.3a/U-D.3b landed 2026-08-30 and a
--    repo-wide grep over api/ and core/ for all nine names returns no live reference —
--    only comments. Running this against a backend that still writes them would fail
--    every add with "Unknown column".
--
--    This is the reverse of the usual ordering hazard. Normally code ships first and
--    must tolerate a column that does not exist yet; here the code shipped first and
--    must tolerate a column that no longer does, which it achieves by never naming one.
--
-- Rollback: procedure document, not a script —
--   database/seeders/rollback/2026_08_30_003_drop-legacy-json-columns_rollback.sql
-- The U-D.3 pack permits procedure-form rollback for this unit alone (INV-9 special).
--
-- ============================================================
-- WHY THIS SEEDER GUARDS ITSELF, WHEN THE OTHERS MUST NOT
--
--   The house rule is that a guarded-DDL block is forbidden: the app DB user cannot read
--   the schema catalogue, so the guard errors, the variable stays NULL, the DDL branch is
--   never taken, and the seeder reports success while changing nothing. It FAILS OPEN.
--
--   That rule is about guards on CREATE/ADD, where "branch not taken" means the change
--   silently did not happen. This seeder DROPS, so the polarity is inverted: "branch not
--   taken" means the columns are still there, which is the safe outcome. The same
--   mechanism that fails open for an ADD fails CLOSED for a DROP.
--
--   And this guard reads no catalogue at all — only COUNT(*) on two ordinary tables the
--   app user owns. If server_configurations_json_archive does not exist, the SET errors,
--   @archive_ok stays NULL, IF(NULL, ...) takes the else branch, and the drop is
--   replaced by a refusal message. Exactly the behaviour wanted.
--
-- ============================================================
-- IDEMPOTENCY
--
--   MariaDB native DROP COLUMN IF EXISTS (10.0.2+). Safe to re-run; the second run is a
--   no-op with warnings. One ALTER, nine clauses: a single table rebuild rather than
--   nine.
-- ============================================================


-- ------------------------------------------------------------------
-- GUARD + DROP. Read the result row: it says either what was dropped, or why not.
-- ------------------------------------------------------------------

SET @configs := (SELECT COUNT(*) FROM `server_configurations` WHERE `config_uuid` IS NOT NULL);
SET @archived := (SELECT COUNT(*) FROM `server_configurations_json_archive`);
SET @archive_ok := (@archived >= @configs);

SET @drop_sql := IF(
    @archive_ok,
    'ALTER TABLE `server_configurations`
       DROP COLUMN IF EXISTS `cpu_configuration`,
       DROP COLUMN IF EXISTS `ram_configuration`,
       DROP COLUMN IF EXISTS `storage_configuration`,
       DROP COLUMN IF EXISTS `caddy_configuration`,
       DROP COLUMN IF EXISTS `nic_config`,
       DROP COLUMN IF EXISTS `sfp_configuration`,
       DROP COLUMN IF EXISTS `pciecard_configurations`,
       DROP COLUMN IF EXISTS `hbacard_config`,
       DROP COLUMN IF EXISTS `hbacard_uuid`',
    'SELECT ''REFUSED: archive is incomplete or missing. Run 2026_08_30_002 first, verify it, then re-run this seeder. Nothing was dropped.'' AS result'
);

PREPARE drop_legacy_json FROM @drop_sql;
EXECUTE drop_legacy_json;
DEALLOCATE PREPARE drop_legacy_json;

SELECT IF(@archive_ok,
          CONCAT('DROPPED. ', @archived, ' configuration(s) archived, ', @configs, ' in the table.'),
          CONCAT('REFUSED. archived=', COALESCE(@archived, 'no archive table'), ' configs=', @configs))
       AS outcome;


-- ============================================================
-- NOT DROPPED, and why
--
--   motherboard_uuid, chassis_uuid — plain scalars, read far more widely than the JSON
--   set (ServerState::getMotherboardUuid(), the platform-lock path,
--   ServerBuilder::updateServerConfigurationTable() which still writes them). The U-D.3
--   pack rules them dropped; that was re-examined and rejected as a scope decision, not
--   assumed. See tasks/u-d3-json-column-retirement.md.
--
-- ============================================================
-- Verification (run after the seeder):
--
--   SHOW COLUMNS FROM server_configurations;
--   -- expect: none of the nine names present; motherboard_uuid and chassis_uuid still
--   -- present.
--
--   -- The archive still has everything:
--   SELECT COUNT(*) FROM server_configurations_json_archive;
--
--   -- And the application still reads builds correctly (this is the check that
--   -- matters — run it against the live API, not just the DB):
--   --   POST action=auth-login, then
--   --   POST action=server-get-config&config_uuid=1f61541b-db3e-4541-83eb-da0c78ffa1d8
--   --   expect: 200, components non-empty
-- ============================================================