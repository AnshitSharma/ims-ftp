-- ============================================================
-- Seeder : 2026_06_18_004_add-is-system-to-pipeline-templates
-- Date   : 2026-06-18
-- Purpose: Mark built-in request types (e.g. "General Request") so the
--          application can protect them: a system type cannot be deleted,
--          renamed, or archived. User-created types keep is_system = 0.
-- Tables : pipeline_templates (1 column ADDED)
-- Notes  : Idempotent / safe to re-run. The column is added only when
--          missing, via a dynamic-SQL guard (MariaDB 10.6 has no
--          `ADD COLUMN IF NOT EXISTS` for older clients).
-- Feature: Unify Tickets + Pipelines into "Requests".
-- ============================================================

SET @add_is_system := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pipeline_templates'
    AND COLUMN_NAME = 'is_system'
);
SET @sql_is_system := IF(@add_is_system = 0,
  'ALTER TABLE `pipeline_templates` ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = built-in type; cannot be deleted/renamed/archived'' AFTER `is_active`',
  'SELECT 1');
PREPARE stmt FROM @sql_is_system; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- Verification (optional):
--   SHOW COLUMNS FROM `pipeline_templates` LIKE 'is_system';
-- Expected: a TINYINT(1) NOT NULL DEFAULT 0 column.
-- ============================================================
