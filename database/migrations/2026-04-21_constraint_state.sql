-- =============================================================================
-- Migration: 2026-04-21_constraint_state.sql
-- Purpose:   Phase 0 of ServerConfigConstraintState rollout.
--
-- Adds a persistent JSON blob to server_configurations that will hold the
-- accumulated compatibility constraints (capabilities + consumption counters)
-- for each server build. Also creates a dual-run audit table used during
-- Phases 1 and 2 of the rollout to confirm the new path produces the same
-- verdicts as the legacy checks before cutting traffic over.
--
-- PRODUCTION SAFETY NOTES
--   * All added columns are NULLABLE with no default side-effects.
--   * Existing rows get constraint_state = NULL. Legacy code paths ignore
--     the new column entirely. No backfill job is required: the new code
--     lazily rebuilds constraint_state from the existing JSON line-item
--     columns the first time each configuration is touched after deploy.
--   * Idempotent: safe to re-run. Guards via INFORMATION_SCHEMA checks.
--   * Reversible: drop-column statements are provided in comments at the
--     bottom of the file. Leaving the column in place after a rollback is
--     also harmless.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Persistent constraint object per server configuration
-- -----------------------------------------------------------------------------
-- Using dynamic SQL so the migration is idempotent on MariaDB 10.6 (which
-- does not support "ADD COLUMN IF NOT EXISTS" reliably inside a transaction
-- when multiple columns are added).
-- -----------------------------------------------------------------------------

SET @db := DATABASE();

-- constraint_state
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME   = 'server_configurations'
      AND COLUMN_NAME  = 'constraint_state'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `server_configurations`
        ADD COLUMN `constraint_state` LONGTEXT
            CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
            COMMENT "Serialized ServerConfigConstraintState (schema v1)"
        AFTER `validation_results`',
    'SELECT "constraint_state column already present" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- constraint_state_version
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME   = 'server_configurations'
      AND COLUMN_NAME  = 'constraint_state_version'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `server_configurations`
        ADD COLUMN `constraint_state_version` TINYINT UNSIGNED DEFAULT NULL
            COMMENT "ServerConfigConstraintState::SCHEMA_VERSION at write"
        AFTER `constraint_state`',
    'SELECT "constraint_state_version column already present" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- constraint_state_updated_at
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME   = 'server_configurations'
      AND COLUMN_NAME  = 'constraint_state_updated_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `server_configurations`
        ADD COLUMN `constraint_state_updated_at` TIMESTAMP NULL DEFAULT NULL
            COMMENT "Wall clock of last constraint_state write"
        AFTER `constraint_state_version`',
    'SELECT "constraint_state_updated_at column already present" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- JSON validity CHECK constraint (separate ALTER because many MySQL/MariaDB
-- versions reject inline CHECK on LONGTEXT during ADD COLUMN). Failure here
-- must NOT abort the migration; legacy MySQL 5.7 silently parses but ignores.
SET @chk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'server_configurations'
      AND CONSTRAINT_NAME = 'chk_constraint_state_json'
);
SET @sql := IF(@chk_exists = 0,
    'ALTER TABLE `server_configurations`
        ADD CONSTRAINT `chk_constraint_state_json`
        CHECK (`constraint_state` IS NULL OR JSON_VALID(`constraint_state`))',
    'SELECT "chk_constraint_state_json already present" AS note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. Dual-run audit table (used during Phases 1 and 2 of rollout)
--    Drop in Phase 5 cleanup.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `compatibility_dualrun_log` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `config_uuid`         VARCHAR(36)  NOT NULL,
    `action`              VARCHAR(32)  NOT NULL COMMENT 'preview | add_precheck',
    `component_type`      VARCHAR(20)  NOT NULL,
    `component_uuid`      VARCHAR(50)  NOT NULL,
    `legacy_verdict`      TINYINT(1)   NOT NULL,
    `constraint_verdict`  TINYINT(1)   NOT NULL,
    `matched`             TINYINT(1)   NOT NULL,
    `legacy_reasons`      TEXT         DEFAULT NULL,
    `constraint_reasons`  TEXT         DEFAULT NULL,
    `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dualrun_config`   (`config_uuid`),
    KEY `idx_dualrun_mismatch` (`matched`, `created_at`),
    KEY `idx_dualrun_type`     (`component_type`, `matched`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Verification queries (run manually after the migration):
--
--   SHOW COLUMNS FROM server_configurations LIKE 'constraint_state%';
--   SHOW CREATE TABLE compatibility_dualrun_log;
--   SELECT COUNT(*) FROM server_configurations;              -- row count unchanged
--   SELECT COUNT(*) FROM server_configurations
--     WHERE constraint_state IS NOT NULL;                     -- expected: 0 right after deploy
--
-- -----------------------------------------------------------------------------
-- Rollback (run ONLY if the Phase 0 deploy must be reverted; safe to skip and
-- leave the column populated — legacy code never reads it):
--
--   ALTER TABLE server_configurations
--       DROP CONSTRAINT chk_constraint_state_json,
--       DROP COLUMN constraint_state_updated_at,
--       DROP COLUMN constraint_state_version,
--       DROP COLUMN constraint_state;
--   DROP TABLE IF EXISTS compatibility_dualrun_log;
-- -----------------------------------------------------------------------------
