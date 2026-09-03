-- =============================================================================
-- ROLLBACK for 2026_09_03_001_add-server-serial-number.sql
-- Date:     2026-09-03
--
-- Drops the serial_number column and its unique index from
-- server_configurations.
--
-- Safe to run with the PHP still deployed: every read and write of the column
-- goes through SchemaHelper::hasColumn(), so once the column is gone the code
-- simply stops minting and stops displaying a serial -- the same state as
-- before the feature shipped. Nothing 500s.
--
-- WARNING: the serials themselves are NOT recoverable as data, but they are
--          recomputable -- each one is BDC-SRV-<padded id> of the row that
--          holds it, so re-running the forward seeder reproduces exactly the
--          same value for every surviving physical configuration. Nothing else
--          references the column: no foreign key, no snapshot, no ticket field.
-- =============================================================================

ALTER TABLE `server_configurations`
    DROP INDEX IF EXISTS `idx_server_configurations_serial_number`;

ALTER TABLE `server_configurations`
    DROP COLUMN IF EXISTS `serial_number`;

SHOW COLUMNS FROM `server_configurations` LIKE 'serial_number';
