-- ============================================================
-- Rollback PROCEDURE : 2026_08_30_003_drop-legacy-json-columns
-- Date   : 2026-08-30
-- Feature: migration U-D.3c
--
-- This file is a PROCEDURE DOCUMENT, not a script to paste blind. The U-D.3 pack
-- permits procedure-form rollback for this unit alone (INV-9 special), because
-- restoring dropped columns is a judgement call about which data is authoritative,
-- and that judgement cannot be encoded in an ALTER statement.
--
-- The statements below are real and runnable, in order. Read the section that applies
-- to your situation before running any of them.
--
-- ============================================================
-- FIRST: DECIDE WHETHER YOU ACTUALLY WANT THIS
--
-- Restoring the columns does NOT restore the behaviour. The code that read and wrote
-- them was deleted in U-D.3a/U-D.3b and is deployed. Recreated columns would sit empty
-- and stay empty — every add and remove writes config_components only.
--
-- So there are exactly two reasons to run this, and they need different things:
--
--   (A) You need to READ the old values — an audit, a dispute about what a build used
--       to contain, or reconstructing a configuration by hand.
--       => You do NOT need this file. The values are already in
--          server_configurations_json_archive, which the drop did not touch. Query it.
--          Go no further.
--
--   (B) You are reverting the CODE to a pre-U-D.3 revision, so the old readers and
--          writers come back.
--       => You need Step 1 and Step 2 below, and you must do them BEFORE the code
--          revert reaches production, not after. A backend that reads these columns
--          while they are missing fails every configuration read with "Unknown column".
--          Deploy-on-save means "before" is a matter of minutes, so stage the revert
--          rather than saving the files first.
--
-- ============================================================
-- STEP 1 — recreate the nine columns
--
-- Types are the originals, verified against the production dump on 2026-08-30. All nine
-- are nullable with no default, so this is a pure add: no existing row changes.
-- ============================================================

ALTER TABLE `server_configurations`
  ADD COLUMN IF NOT EXISTS `cpu_configuration`       longtext    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ram_configuration`       longtext    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `storage_configuration`   longtext    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `caddy_configuration`     longtext    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `nic_config`              longtext    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `sfp_configuration`       longtext    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `pciecard_configurations` longtext    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `hbacard_config`          longtext    DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `hbacard_uuid`            varchar(36) DEFAULT NULL;

-- The original schema carried a non-unique index on hbacard_uuid. Restore it only if
-- you are going back to code that queries by it; it is not needed to read the column.
--
--   ALTER TABLE `server_configurations` ADD KEY IF NOT EXISTS `hbacard_uuid` (`hbacard_uuid`);


-- ============================================================
-- STEP 2 — refill them from the archive
--
-- This restores the values AS OF THE DROP. Anything that happened to a configuration
-- after the drop is in config_components and is NOT reflected here — see Step 3.
-- ============================================================

UPDATE `server_configurations` sc
  JOIN `server_configurations_json_archive` a ON a.`config_uuid` = sc.`config_uuid`
   SET sc.`cpu_configuration`       = a.`cpu_configuration`,
       sc.`ram_configuration`       = a.`ram_configuration`,
       sc.`storage_configuration`   = a.`storage_configuration`,
       sc.`caddy_configuration`     = a.`caddy_configuration`,
       sc.`nic_config`              = a.`nic_config`,
       sc.`sfp_configuration`       = a.`sfp_configuration`,
       sc.`pciecard_configurations` = a.`pciecard_configurations`,
       sc.`hbacard_config`          = a.`hbacard_config`,
       sc.`hbacard_uuid`            = a.`hbacard_uuid`,
       sc.`updated_at`              = sc.`updated_at`;

-- updated_at is pinned to itself on purpose: a restore is not a modification of the
-- configuration, and letting it move would misdate every build in the system.


-- ============================================================
-- STEP 3 — THE PART THAT IS NOT AUTOMATABLE
--
-- The archive is a snapshot taken at the drop. Every add, remove and replace since then
-- went to config_components ONLY. So after Step 2 the restored columns are STALE by
-- exactly the amount of work done in between, and reverted code would read that stale
-- picture as current — showing components that have been pulled and hiding ones that
-- have been fitted.
--
-- Find out how much drift you are dealing with before trusting anything:
--
--   -- configurations changed since the archive was taken
--   SELECT cc.config_uuid,
--          MIN(a.archived_at)                                  AS archived_at,
--          SUM(cc.added_at   > a.archived_at)                  AS added_since,
--          SUM(cc.removed_at > a.archived_at)                  AS removed_since
--     FROM config_components cc
--     JOIN server_configurations_json_archive a ON a.config_uuid = cc.config_uuid
--    GROUP BY cc.config_uuid
--   HAVING added_since > 0 OR removed_since > 0;
--
-- If that returns nothing, Step 2 is a faithful restore and you are done.
--
-- If it returns rows, the archive is NOT authoritative for those configurations and
-- config_components is. You have two honest options, and no third:
--
--   (a) Reconstruct the JSON for those configurations from their config_components rows
--       — the same mapping ConfigReadRouter::rowsToLegacyShape() performs, run in
--       reverse and per column. Do this per configuration, by hand, checking each
--       against the row set. There is no bulk statement for it and writing one under
--       time pressure is how a rollback becomes an outage.
--
--   (b) Accept the stale snapshot and record which configurations are known-stale, so
--       nobody treats them as current.
--
-- ============================================================
-- STEP 4 — verify before letting the reverted code see it
--
--   SHOW COLUMNS FROM server_configurations;   -- all nine back
--
--   -- nothing silently missed the refill:
--   SELECT COUNT(*) FROM server_configurations sc
--     JOIN server_configurations_json_archive a ON a.config_uuid = sc.config_uuid
--    WHERE NOT (sc.cpu_configuration <=> a.cpu_configuration)
--       OR NOT (sc.nic_config        <=> a.nic_config);
--   -- expect: 0
--
--   Then, with the reverted code deployed, read a build end to end through the live API
--   and confirm its component list matches what config_components says it should be.
--   A clean ALTER is not evidence that anything works.
-- ============================================================
