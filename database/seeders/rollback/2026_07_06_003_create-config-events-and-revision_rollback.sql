-- ============================================================
-- Rollback for: 2026_07_06_003_create-config-events-and-revision
-- ============================================================
-- Safe: no code path writes `revision` or `config_events` yet (dual-write's
-- bump lands in U-1.5), so dropping both is a pure no-op on legacy behavior.
-- ============================================================

DROP TABLE IF EXISTS `config_events`;

SET @revision_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'server_configurations' AND COLUMN_NAME = 'revision'
);
SET @drop_revision_sql = IF(@revision_exists > 0,
  'ALTER TABLE `server_configurations` DROP COLUMN `revision`',
  'SELECT 1'
);
PREPARE drop_revision_stmt FROM @drop_revision_sql;
EXECUTE drop_revision_stmt;
DEALLOCATE PREPARE drop_revision_stmt;
