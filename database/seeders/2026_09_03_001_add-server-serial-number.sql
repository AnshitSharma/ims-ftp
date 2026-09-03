-- =============================================================================
-- Date:     2026-09-03
-- Purpose:  Give every PHYSICAL server configuration a serial number of its own.
--
--           Until now a built server had no serial. The only serials in the
--           system belonged to the parts inside it -- {type}inventory.SerialNumber
--           -- and to the platform box it was built on
--           (serverplatforminventory.SerialNumber, "manufacturer serial /
--           service tag of the box"). Neither names the BUILD, so there was no
--           single identifier to put on a server or to search it by.
--
--           The value is SYSTEM-ISSUED, never operator-typed:
--
--               BDC-SRV-<zero-padded server_configurations.id>
--               e.g. id 123 -> BDC-SRV-000123
--
--           This is exactly the formatAssetTag() pattern the 12 inventory tables
--           already use for AssetTag (BaseFunctions.php:989-1004): deriving the
--           identity from the row's own auto-increment primary key makes it
--           unique BY CONSTRUCTION -- no counter table, no contention, no
--           collision, and nothing for an operator to mistype.
--
--           IDs past 999999 simply produce a longer string; the column has room
--           to 20 chars, matching the inventory AssetTag columns.
--
-- Tables:   server_configurations (1 new column + 1 unique index + backfill)
-- Feature:  Server serial number (server card, builder header,
--           server-search-by-serial)
--
-- Notes:    - VIRTUAL AND SANDBOX CONFIGS GET NO SERIAL, BY DESIGN. A serial
--             names a physical box; a virtual config (is_virtual = 1) and a
--             compatibility bench build (is_sandbox = 1, always also virtual)
--             have no box. Leaving them NULL keeps invented serials out of the
--             estate. This is why the index is UNIQUE but the column is
--             NULLable: MariaDB lets any number of rows share NULL under a
--             unique index, so every virtual build can stay blank while every
--             physical one is guaranteed distinct.
--
--           - THE BACKFILL EXPRESSION AND formatServerSerial() IN
--             BaseFunctions.php MUST STAY IN LOCK-STEP. They are two spellings
--             of one format: CONCAT('BDC-SRV-', LPAD(id, 6, '0')) here,
--             sprintf('BDC-SRV-%06d', $id) there. Changing one without the
--             other splits the column across two formats -- the same warning
--             getComponentAssetTagCode() carries about seeder 2026_07_22_001.
--
--           - The serial is IMMUTABLE. It is absent from the updatable-field
--             whitelist in handleUpdateConfiguration() and from
--             RequestActionExecutor::UPDATABLE_CONFIG_FIELDS, so no API path can
--             change it. A config that is deleted and recreated takes a new id
--             and therefore a new serial; serials are never reused.
--
--           - Idempotent via native ADD COLUMN / ADD INDEX IF NOT EXISTS
--             (MariaDB 10.0.2+) and a NULL-guarded UPDATE. Deliberately NOT the
--             metadata-schema guard pattern: the application DB user has no
--             grant for that schema on this host, so such seeders die at
--             PREPARE before any ALTER runs -- and the guard then fails open,
--             reporting success while changing nothing. Verify with SHOW
--             COLUMNS / SHOW INDEX instead.
--
--           - No ACL rows and no new API action: the serial rides on the
--             existing server-create-start / server-get-config /
--             server-list-configs responses, and search extends the already
--             mapped server-search-by-serial (server.view).
--
--           - Deploy ordering: PHP reaches production ~20s after save, this
--             seeder is applied by hand afterwards. Every read and write of the
--             column is behind SchemaHelper::hasColumn(), so the window before
--             this file is run is harmless -- no serial is minted and none is
--             displayed. The feature switches on when this lands.
--
--           - Rollback: rollback/2026_09_03_001_add-server-serial-number_rollback.sql
-- =============================================================================

ALTER TABLE `server_configurations`
    ADD COLUMN IF NOT EXISTS `serial_number` VARCHAR(20) DEFAULT NULL
        COMMENT 'System-issued serial of the BUILD: BDC-SRV-nnnnnn from this row id. NULL for virtual/sandbox configs. Immutable.'
        AFTER `server_name`;

-- UNIQUE is safe because the value is derived from the primary key, so two rows
-- cannot produce the same string. It is here to make that guarantee structural
-- rather than a property of the code that happens to write it.
ALTER TABLE `server_configurations`
    ADD UNIQUE INDEX IF NOT EXISTS `idx_server_configurations_serial_number` (`serial_number`);

-- Backfill every existing PHYSICAL configuration. Guarded on IS NULL so a
-- re-run cannot disturb a row that already has its serial, and restricted to
-- is_virtual = 0 for the reason in the notes above.
--
-- Keep this expression identical to formatServerSerial().
UPDATE `server_configurations`
   SET `serial_number` = CONCAT('BDC-SRV-', LPAD(`id`, 6, '0'))
 WHERE `is_virtual` = 0
   AND `serial_number` IS NULL;

-- Verification: the column and its index exist, physical rows all carry a
-- BDC-SRV- serial, virtual rows none.
SHOW COLUMNS FROM `server_configurations` LIKE 'serial_number';

SHOW INDEX FROM `server_configurations` WHERE `Key_name` = 'idx_server_configurations_serial_number';

SELECT `is_virtual`,
       COUNT(*)                AS rows_total,
       COUNT(`serial_number`)  AS rows_with_serial
  FROM `server_configurations`
 GROUP BY `is_virtual`;
