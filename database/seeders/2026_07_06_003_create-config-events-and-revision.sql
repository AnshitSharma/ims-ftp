-- ============================================================
-- Seeder : 2026_07_06_003_create-config-events-and-revision
-- Date   : 2026-07-06
-- Purpose: Migration U-1.3 — audit trail + optimistic concurrency substrate
--          (INV-6: every mutation bumps server_configurations.revision and
--          appends exactly one config_events row, in the same transaction).
-- Tables : server_configurations (1 column ADDED: revision)
--          config_events (NEW)
-- Notes  :
--   * revision starts at 0 for every existing row (DEFAULT 0, NOT NULL) and
--     is NOT bumped by any code path yet — dual-write's revision bump lands
--     in U-1.5. INV-6's mechanical check (ARCHITECTURAL_INVARIANTS.md)
--     therefore trivially passes right now: every config has revision=0
--     and zero config_events rows, so MAX(revision) via the LEFT JOIN
--     coalesces to 0 for all of them — equal on both sides.
--   * config_uuid collation rule copied from U-1.1/U-1.2: CHARACTER SET
--     utf8mb4 COLLATE utf8mb4_unicode_ci, matching
--     server_configurations.config_uuid exactly (2026_06_17_002 history).
--   * uq_config_rev (config_uuid, revision) is a real, always-live unique
--     key (no NULL-distinctness caveat here — both columns are NOT NULL on
--     every row) enforcing "each revision number is used at most once per
--     config," which is exactly what optimistic concurrency needs: a
--     command that read revision N and tries to write event row
--     (config_uuid, N) after someone else already wrote it will get a
--     duplicate-key error instead of silently racing.
--   * payload is JSON (nullable) — a free-form snapshot of what changed,
--     shape defined by whichever unit first writes an event (U-1.5+).
--   * Idempotent guard: ALTER TABLE uses a dynamic-SQL "add column only if
--     missing" guard (MariaDB 10.6 here has no ADD COLUMN IF NOT EXISTS
--     for all client versions — same pattern as seeder 2026_06_18_001's
--     header describes for its `tickets` column additions). config_events
--     itself uses CREATE TABLE IF NOT EXISTS.
-- Feature: Schema migration (Phase P1 — schema introduction, DUAL_WRITE_ENABLED=off).
-- ============================================================

SET @revision_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'server_configurations' AND COLUMN_NAME = 'revision'
);
SET @add_revision_sql = IF(@revision_exists = 0,
  'ALTER TABLE `server_configurations` ADD COLUMN `revision` INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE add_revision_stmt FROM @add_revision_sql;
EXECUTE add_revision_stmt;
DEALLOCATE PREPARE add_revision_stmt;

CREATE TABLE IF NOT EXISTS `config_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_uuid` CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK -> server_configurations.config_uuid',
  `revision` INT UNSIGNED NOT NULL COMMENT 'server_configurations.revision at the moment this event was appended',
  `event` ENUM('add','remove','replace','transition','backfill','delete') NOT NULL,
  `component_type` VARCHAR(16) DEFAULT NULL,
  `component_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK (soft) -> config_components.id, when applicable',
  `actor` INT(11) NOT NULL DEFAULT 0,
  `payload` JSON DEFAULT NULL COMMENT 'Free-form snapshot of what changed; shape defined by the writing unit',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_config_rev` (`config_uuid`, `revision`),
  CONSTRAINT `fk_ce_config` FOREIGN KEY (`config_uuid`) REFERENCES `server_configurations` (`config_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audit trail / optimistic-concurrency event log (migration P1, U-1.3)';

-- ------------------------------------------------------------
-- Verification (optional):
--   SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
--    WHERE TABLE_NAME = 'server_configurations' AND COLUMN_NAME = 'revision';
--   -- expect: NOT NULL, DEFAULT 0
--
--   -- uq_config_rev proof: two INSERTs with the same (config_uuid, revision)
--   -- must fail on the second with a duplicate-key error.
-- ------------------------------------------------------------
