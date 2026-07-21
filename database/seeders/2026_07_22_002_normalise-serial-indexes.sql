-- =============================================================================
-- Seeder:  2026_07_22_002_normalise-serial-indexes.sql
-- Date:    2026-07-22
-- Purpose: Make SerialNumber uniqueness consistent across all 10 inventory
--          tables, and remove the redundant double-indexing of that column.
--
--          Today the constraint is uneven, so three tables can silently hold two
--          rows claiming the same physical unit:
--
--            chassisinventory   plain KEY only   -> duplicates ALLOWED
--            hbacardinventory   plain KEY only   -> duplicates ALLOWED
--            sfpinventory       NO index at all  -> duplicates ALLOWED
--
--          The other seven are UNIQUE, and six of those carry the SAME column
--          indexed TWICE (`SerialNumber` and `idx_serial_number`), which costs a
--          second B-tree write on every insert/update for no benefit.
--
-- Affected tables: chassisinventory, hbacardinventory, sfpinventory (add UNIQUE)
--                  cpuinventory, raminventory, storageinventory,
--                  motherboardinventory, nicinventory, caddyinventory
--                  (drop redundant duplicate index)
--                  pciecardinventory (rename index for consistency)
-- Related feature: asset tag unit identity (tasks/asset-tag-unit-identity.md)
--
-- =============================================================================
-- !! ORDER OF OPERATIONS -- READ BEFORE RUNNING !!
--
--   Run this ONLY AFTER the Phase 2 backend code is deployed and verified.
--
--   Reason: until Phase 2 is live, the add form submits '' (empty string) for an
--   untouched serial field. MySQL UNIQUE permits many NULLs but only ONE '' --
--   so adding a UNIQUE index to chassis/hbacard/sfp BEFORE the '' -> NULL
--   coercion is deployed would take three tables that currently accept
--   serial-less adds freely and make the SECOND such add fail with a duplicate
--   key. That failure mode was confirmed live on storageinventory (row 112) on
--   2026-07-22. Deploy Phase 2 first and this seeder is purely protective.
-- =============================================================================
--
-- PRE-CHECK ALREADY PERFORMED (via production API, 2026-07-22) -- all clear:
--   chassisinventory: 4 rows,  0 duplicate serials, 0 blank
--   hbacardinventory: 16 rows, 0 duplicate serials, 2 blank -- BOTH ARE NULL
--                     (IDs 1 and 2, tags BDC-HBA-000001 / BDC-HBA-000002),
--                     and multiple NULLs are legal under a UNIQUE index
--   sfpinventory:     0 rows
-- Re-run the verification block at the foot of this file before the ALTERs if
-- any time has passed -- new rows may have landed since.
--
-- Idempotent: IF EXISTS / IF NOT EXISTS on every index operation (MariaDB).
--
-- !! phpMyAdmin note: select your IMS database in the left sidebar before
--    running. If information_schema is the active database, the unqualified
--    table names below resolve against it and you get
--    "#1109 - Unknown table 'chassisinventory' in information_schema".
-- =============================================================================


-- =============================================================================
-- SECTION 0 -- SAFETY CHECK. RUN THIS FIRST, ON ITS OWN, BEFORE THE ALTERs.
--
-- Every row must return 0 in both numeric columns.
--
--   dup_serials  > 0  ->  STOP. Two rows claim one physical unit; the ALTER
--                         would fail partway through. Resolve the duplicates.
--   empty_string > 0  ->  STOP. Phase 2 is not deployed (or a path was missed);
--                         these must become NULL before a UNIQUE index exists,
--                         or the next serial-less add fails on a duplicate key.
--
-- NULLs are fine and are deliberately not counted: COUNT(column) ignores them,
-- and a UNIQUE index permits any number of NULLs. hbacardinventory legitimately
-- holds two (IDs 1 and 2) -- real units that never had a readable serial.
-- =============================================================================
SELECT 'chassisinventory' AS table_name,
       COUNT(`SerialNumber`) - COUNT(DISTINCT `SerialNumber`) AS dup_serials,
       COALESCE(SUM(`SerialNumber` = ''), 0)                  AS empty_string,
       COALESCE(SUM(`SerialNumber` IS NULL), 0)               AS null_serials
  FROM `chassisinventory`
UNION ALL
SELECT 'hbacardinventory',
       COUNT(`SerialNumber`) - COUNT(DISTINCT `SerialNumber`),
       COALESCE(SUM(`SerialNumber` = ''), 0),
       COALESCE(SUM(`SerialNumber` IS NULL), 0)
  FROM `hbacardinventory`
UNION ALL
SELECT 'sfpinventory',
       COUNT(`SerialNumber`) - COUNT(DISTINCT `SerialNumber`),
       COALESCE(SUM(`SerialNumber` = ''), 0),
       COALESCE(SUM(`SerialNumber` IS NULL), 0)
  FROM `sfpinventory`;

-- Expected as of the 2026-07-22 pre-check:
--   chassisinventory  dup 0  empty 0  null 0
--   hbacardinventory  dup 0  empty 0  null 2
--   sfpinventory      dup 0  empty 0  null 0


-- -----------------------------------------------------------------------------
-- SECTION 1 -- Close the three unconstrained tables.
--
-- Each replaces the non-unique index with a UNIQUE one of the same name, so the
-- naming ends up consistent with the other seven tables. Done as a single ALTER
-- per table: there is no window in which the column sits unindexed.
-- -----------------------------------------------------------------------------

ALTER TABLE `chassisinventory`
  DROP INDEX IF EXISTS `idx_serial_number`,
  ADD UNIQUE KEY IF NOT EXISTS `idx_serial_number` (`SerialNumber`);

ALTER TABLE `hbacardinventory`
  DROP INDEX IF EXISTS `idx_serial_number`,
  ADD UNIQUE KEY IF NOT EXISTS `idx_serial_number` (`SerialNumber`);

ALTER TABLE `sfpinventory`
  ADD UNIQUE KEY IF NOT EXISTS `idx_serial_number` (`SerialNumber`);


-- -----------------------------------------------------------------------------
-- SECTION 2 -- Drop the redundant duplicate index.
--
-- These six tables index SerialNumber twice over. `idx_serial_number` is kept
-- (explicit name, matches Section 1); the auto-named `SerialNumber` index is
-- dropped. Uniqueness is NEVER lost -- the surviving index is itself UNIQUE, so
-- the constraint holds continuously through the drop.
--
-- This section is optional in the sense that skipping it costs only a little
-- write overhead. It is NOT optional to skip Section 1.
-- -----------------------------------------------------------------------------

ALTER TABLE `cpuinventory`         DROP INDEX IF EXISTS `SerialNumber`;
ALTER TABLE `raminventory`         DROP INDEX IF EXISTS `SerialNumber`;
ALTER TABLE `storageinventory`     DROP INDEX IF EXISTS `SerialNumber`;
ALTER TABLE `motherboardinventory` DROP INDEX IF EXISTS `SerialNumber`;
ALTER TABLE `nicinventory`         DROP INDEX IF EXISTS `SerialNumber`;
ALTER TABLE `caddyinventory`       DROP INDEX IF EXISTS `SerialNumber`;

-- pciecardinventory has ONLY the auto-named index -- rename it rather than drop
-- it, so every table lands on the same index name. Single statement, so the
-- UNIQUE constraint is never absent.
ALTER TABLE `pciecardinventory`
  DROP INDEX IF EXISTS `SerialNumber`,
  ADD UNIQUE KEY IF NOT EXISTS `idx_serial_number` (`SerialNumber`);


-- =============================================================================
-- VERIFICATION
--
-- Expected after running: exactly ONE row per inventory table, every one showing
-- index_name = 'idx_serial_number' and non_unique = 0.
-- =============================================================================
SELECT `TABLE_NAME`  AS table_name,
       `INDEX_NAME`  AS index_name,
       `NON_UNIQUE`  AS non_unique,
       `SEQ_IN_INDEX` AS seq
  FROM `INFORMATION_SCHEMA`.`STATISTICS`
 WHERE `TABLE_SCHEMA` = DATABASE()
   AND `COLUMN_NAME`  = 'SerialNumber'
 ORDER BY `TABLE_NAME`, `INDEX_NAME`;


-- The safety check now lives in SECTION 0 at the top of this file, where it is
-- run BEFORE the ALTERs rather than after them.
