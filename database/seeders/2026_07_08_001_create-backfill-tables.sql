-- ============================================================
-- Seeder : 2026_07_08_001_create-backfill-tables
-- Date   : 2026-07-08
-- Purpose: Migration U-B.1 — bookkeeping substrate for the JSON->rows
--          backfill machinery (scripts/backfill/backfill.php). Tracks
--          per-config progress for a given run (idempotent/resumable) and
--          quarantines any legacy component entry the Extractor cannot
--          confidently migrate (never guessed — see 07-component-migration/README.md).
-- Tables : migration_backfill_state (NEW), backfill_quarantine (NEW)
-- Notes  :
--   * config_uuid collation rule copied from U-1.1/U-1.2/U-1.3: CHARACTER SET
--     utf8mb4 COLLATE utf8mb4_unicode_ci, matching
--     server_configurations.config_uuid exactly (2026_06_17_002 history).
--   * migration_backfill_state's PK is the (run_id, config_uuid) pair itself
--     (not a surrogate id) — this directly enforces "at most one state row
--     per config per run," which is exactly what resumability needs: a
--     resumed run reads/updates the SAME row it left behind, never inserts
--     a second one for the same config.
--   * backfill_quarantine uses a surrogate id because a single config can
--     legitimately produce MANY quarantine rows in one run (one per
--     unmigrable legacy component entry).
--   * Neither table has a hard FK to server_configurations: a rollback-run
--     (scripts/backfill/backfill.php --rollback-run) intentionally deletes
--     these bookkeeping rows without touching server_configurations at all,
--     and a config could theoretically be deleted between backfill runs —
--     this bookkeeping data outliving its config is not a correctness
--     problem (it is a scan-only artifact), just an audit trail.
--   * Idempotent guard: CREATE TABLE IF NOT EXISTS, safe to re-run.
-- Feature: Schema migration (Phase P2 — backfill machinery, no rows moved yet).
-- ============================================================

CREATE TABLE IF NOT EXISTS `migration_backfill_state` (
  `run_id` VARCHAR(64) NOT NULL,
  `config_uuid` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` ENUM('pending','done','quarantined','error') NOT NULL DEFAULT 'pending',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` TEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`run_id`, `config_uuid`),
  KEY `k_status` (`run_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Per-config backfill progress, keyed by run (migration P2, U-B.1)';

CREATE TABLE IF NOT EXISTS `backfill_quarantine` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` VARCHAR(64) NOT NULL,
  `config_uuid` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_json` JSON NOT NULL COMMENT 'Raw legacy {type, entry} this run could not confidently migrate',
  `reason` VARCHAR(191) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_run_config` (`run_id`, `config_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Legacy component entries the backfill Extractor could not migrate (migration P2, U-B.1)';

-- ------------------------------------------------------------
-- Verification (optional):
--   SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_NAME = 'migration_backfill_state' AND COLUMN_NAME = 'config_uuid';
--   -- expect: utf8mb4_unicode_ci (matches server_configurations.config_uuid)
-- ------------------------------------------------------------
