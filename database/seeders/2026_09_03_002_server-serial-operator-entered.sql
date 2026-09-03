-- =============================================================================
-- Date:     2026-09-03
-- Purpose:  Make server_configurations.serial_number an OPERATOR-ENTERED
--           manufacturer serial instead of a system-minted tag.
--
--           SUPERSEDES 2026_09_03_001_add-server-serial-number.sql, which
--           introduced the same column for a system-issued BDC-SRV-nnnnnn value.
--           The decision changed before either file was applied: the serial is
--           the one printed on the physical server, so a person types it in on
--           the Create Server form. Nothing derives it, so nothing can mint it.
--
--           ==> RUN THIS FILE. DO NOT RUN 001. <==
--
--           This file is self-sufficient and idempotent in both worlds:
--             * 001 never ran  -> it creates the column and the index outright.
--             * 001 already ran -> it widens the column, replaces the comment,
--                                  and clears any BDC-SRV- value that file's
--                                  backfill stamped into existing rows.
--           Running 001 AFTER this one would re-stamp those tags, which is why
--           it must not be run at all.
--
-- Tables:   server_configurations (1 column, 1 unique index)
-- Feature:  Server serial number (Create Server form, edit dialog, server card,
--           builder header, server-search-by-serial)
--
-- Notes:    - VARCHAR(50), matching {type}inventory.SerialNumber exactly. These
--             are the same kind of value -- a manufacturer serial read off
--             hardware -- and 001's VARCHAR(20) was sized for a generated tag,
--             which would have truncated real vendor serials.
--
--           - UNIQUE, because two servers cannot carry the same serial. Typing
--             one that already exists is an operator mistake, and the API turns
--             the resulting duplicate-key error into a 400 naming the server
--             that already holds it rather than a bare 500.
--
--           - NULLable, and NULL is not required to be unique in MariaDB, so any
--             number of rows may have no serial yet. Three cases need that:
--             virtual configs and compatibility bench builds (no physical box to
--             read a serial off), configs created by server-import-virtual (no
--             operator present to type one -- it is filled in later through the
--             edit dialog), and every configuration that already existed before
--             this column did. The Create Server form is where it is mandatory.
--
--           - Unlike 001's tag, this value is EDITABLE: an operator-typed serial
--             gets mistyped, so serial_number is in the updatable-field list in
--             handleUpdateConfiguration() and can be corrected from the edit
--             dialog. It is deliberately NOT in
--             RequestActionExecutor::UPDATABLE_CONFIG_FIELDS -- correcting a
--             serial is a direct edit, not a Request action.
--
--           - Deliberately NOT the metadata-schema guard pattern: the
--             application DB user has no grant for that schema on this host, so
--             such seeders die at PREPARE before any ALTER runs -- and the guard
--             then fails open, reporting success while changing nothing. Verify
--             with SHOW COLUMNS / SHOW INDEX instead.
--
--           - No ACL rows and no new API action: the serial rides on the
--             existing server-create-start / server-update-config /
--             server-get-config / server-list-configs responses, and search
--             extends the already mapped server-search-by-serial (server.view).
--
--           - Deploy ordering: PHP reaches production ~20s after save, this
--             seeder is applied by hand afterwards. Every read and write of the
--             column is behind SchemaHelper::hasColumn(), so before this file is
--             run the Create Server form's serial field is simply not enforced
--             and not stored -- creating a server keeps working.
--
--           - Rollback: rollback/2026_09_03_002_server-serial-operator-entered_rollback.sql
-- =============================================================================

-- 1. The column. ADD covers "001 was never run"; MODIFY then makes the width and
--    comment correct whether or not it was. Both are safe to re-run.
ALTER TABLE `server_configurations`
    ADD COLUMN IF NOT EXISTS `serial_number` VARCHAR(50) DEFAULT NULL
        AFTER `server_name`;

ALTER TABLE `server_configurations`
    MODIFY COLUMN `serial_number` VARCHAR(50) DEFAULT NULL
        COMMENT 'Manufacturer serial of the physical server, typed in by the operator on create. UNIQUE. NULL for virtual/sandbox builds and for imported or pre-existing configs.';

-- 2. The unique index. Named as in 001 so a database that ran 001 already has it
--    and this is a no-op there.
ALTER TABLE `server_configurations`
    ADD UNIQUE INDEX IF NOT EXISTS `idx_server_configurations_serial_number` (`serial_number`);

-- 3. Undo 001's backfill if it ran. Those BDC-SRV- strings were generated, not
--    read off any hardware, so leaving them would put invented serials on real
--    servers -- worse than a blank, which at least reads as "not recorded yet".
--    An operator fills these in from the edit dialog.
--
--    Matches ONLY the generated shape, so a real vendor serial can never be
--    caught by it.
UPDATE `server_configurations`
   SET `serial_number` = NULL
 WHERE `serial_number` LIKE 'BDC-SRV-%';

-- Verification: the column is VARCHAR(50) with the new comment, the unique index
-- is present, and no generated tag survives.
SHOW COLUMNS FROM `server_configurations` LIKE 'serial_number';

SHOW INDEX FROM `server_configurations` WHERE `Key_name` = 'idx_server_configurations_serial_number';

SELECT COUNT(*) AS rows_total,
       COUNT(`serial_number`) AS rows_with_serial,
       SUM(CASE WHEN `serial_number` LIKE 'BDC-SRV-%' THEN 1 ELSE 0 END) AS generated_tags_left
  FROM `server_configurations`;
