-- =============================================================================
-- Rollback for: 2026_08_22_006_ticket-pipeline-type-name-snapshot.sql
-- Date:         2026-08-22
-- Tables:       tickets
--
-- Drops the snapshot column. Guarded, so it is safe to run twice.
--
-- READ THIS FIRST. If any Request Type has already been DELETED while this
-- column existed, the names of the requests raised from it live ONLY here -
-- their type row is gone and pt.name has nothing to fall back to. Dropping the
-- column loses those names permanently. Check before running:
--
--   SELECT t.pipeline_type_name, COUNT(*)
--     FROM tickets t
--     LEFT JOIN pipeline_templates pt ON pt.id = t.pipeline_template_id
--    WHERE t.pipeline_template_id IS NOT NULL AND pt.id IS NULL
--    GROUP BY t.pipeline_type_name;
--
-- An empty result means nothing is lost by dropping it.
--
-- The code tolerates the column's absence (hasColumn probes on both the read and
-- the write path), so no code change is needed to roll back. deleteTemplate()
-- simply goes back to refusing a type that requests were created from.
-- =============================================================================

SET @drop_ptn := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets'
    AND COLUMN_NAME = 'pipeline_type_name'
);
SET @sql_ptn := IF(@drop_ptn = 1,
  'ALTER TABLE `tickets` DROP COLUMN `pipeline_type_name`',
  'SELECT 1');
PREPARE stmt FROM @sql_ptn; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SHOW COLUMNS FROM `tickets` LIKE 'pipeline_type_name';
