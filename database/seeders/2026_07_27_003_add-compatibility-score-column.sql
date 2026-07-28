-- =============================================================================
-- 2026_07_27_002_add-compatibility-score-column.sql
--
-- Date:     2026-07-27
-- Purpose:  Add the missing `server_configurations.compatibility_score` column.
-- Tables:   server_configurations
-- Feature:  F-17 (command-layer / validation-engine migration, audit fix A-L2)
--
-- WHY
--   Audit fix A-L2 removed a dead `class_exists('CompatibilityEngine')` gate and
--   corrected an arity bug so that ServerBuilder actually computes and persists a
--   compatibility score. But `server_configurations` never had a
--   `compatibility_score` column -- it exists only on `compatibility_log` and
--   `component_compatibility`. After A-L2 the score was always non-null, so
--   updateConfigurationCalculatedFields() always took the branch whose UPDATE
--   names that column, and the statement failed with:
--
--       SQLSTATE[42S22] 1054 Unknown column 'compatibility_score' in 'field list'
--
--   `power_consumption` is written by the SAME statement, so it silently stopped
--   being maintained on every add/remove. The exception was caught and logged
--   ("Error updating calculated fields"), so the API still returned success --
--   which is why this went unnoticed. Verified against the 2026-07-26 production
--   dump: the column is absent, and both errors reproduce on every add.
--
--   ServerBuilder also carries a defensive probe (F-17) so power stays correct
--   whether or not this seeder has been applied. Applying it additionally
--   restores score persistence, which is what A-L2 intended.
--
-- IDEMPOTENT: guarded ALTER -- safe to run more than once.
-- =============================================================================

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'server_configurations'
      AND COLUMN_NAME  = 'compatibility_score'
);

SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE `server_configurations`
       ADD COLUMN `compatibility_score` DECIMAL(3,2) DEFAULT NULL
       COMMENT ''Compatibility score (0.00-1.00), maintained by ServerBuilder (A-L2/F-17)''
       AFTER `power_consumption`',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verification (expect exactly one row):
-- SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
-- FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME = 'server_configurations'
--   AND COLUMN_NAME = 'compatibility_score';
