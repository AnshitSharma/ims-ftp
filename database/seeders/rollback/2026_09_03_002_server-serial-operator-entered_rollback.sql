-- =============================================================================
-- ROLLBACK for 2026_09_03_002_server-serial-operator-entered.sql
-- Date:     2026-09-03
--
-- Drops the serial_number column and its unique index from
-- server_configurations. Also the rollback for 2026_09_03_001, which introduced
-- the same column and must not be run at all.
--
-- Safe to run with the PHP still deployed: every read and write of the column
-- goes through SchemaHelper::hasColumn(), so once the column is gone the Create
-- Server form stops requiring a serial and nothing displays one. Nothing 500s.
--
-- WARNING: THIS DESTROYS DATA THAT CANNOT BE RECOMPUTED. Unlike the generated
--          BDC-SRV- tags of 001, these serials were read off physical hardware
--          and typed in by a person. There is no formula to regenerate them and
--          no other column holds a copy. Export them first if they matter:
--
--            SELECT `config_uuid`, `server_name`, `serial_number`
--              FROM `server_configurations`
--             WHERE `serial_number` IS NOT NULL;
-- =============================================================================

ALTER TABLE `server_configurations`
    DROP INDEX IF EXISTS `idx_server_configurations_serial_number`;

ALTER TABLE `server_configurations`
    DROP COLUMN IF EXISTS `serial_number`;

SHOW COLUMNS FROM `server_configurations` LIKE 'serial_number';
