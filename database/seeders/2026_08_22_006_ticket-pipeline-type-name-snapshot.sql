-- =============================================================================
-- Date:     2026-08-22
-- Purpose:  Let a Request Type actually be DELETED, by keeping the type's NAME
--           on the requests that were raised from it.
--
--           Deleting a type was refused outright whenever any request referenced
--           it ("Archive it instead"). Nothing about a request depends on its
--           type row surviving:
--             * tickets.pipeline_template_id is a LOGICAL FK - no constraint
--               exists (see 2026_06_18_001 and the production dump), so the
--               DELETE never fails at the database level;
--             * a request's steps are SNAPSHOTTED into ticket_stage_progress
--               when it is created, so the engine never re-reads the type;
--             * both read queries in PipelineManager LEFT JOIN the type, so a
--               missing one is already tolerated.
--           The only thing a delete actually cost was the type's NAME on those
--           requests. This column keeps it, and the refusal has nothing left to
--           protect.
--
-- Tables:   tickets (1 column ADD, backfill UPDATE)
-- Feature:  Requests / Request Types (deletable types)
--
-- DEPLOY ORDER IS SAFE EITHER WAY. PipelineManager writes this column only when
-- it exists (TemporaryAccessManager::hasColumn), and reads it as
-- COALESCE(pt.name, t.pipeline_type_name). Until this seeder is applied,
-- deleteTemplate() still refuses a type with requests behind it - and says so:
-- there is nowhere to put the name yet.
--
-- Notes:    - Nullable and denormalised ON PURPOSE. It is a historical snapshot,
--             not a second source of truth: pt.name wins whenever the type still
--             exists, and a rename is picked up live. It is read only once the
--             type is gone.
--           - deleteTemplate() also stamps this column on any request still
--             missing it, immediately before deleting - the last moment the name
--             can be read. So requests created between this seeder and any later
--             delete are covered even if the backfill missed them.
--           - Built-in (is_system = 1) types remain undeletable. That is a
--             product rule about "General Request", not a data-integrity one.
--           - Idempotent: guarded ALTER, and the backfill only fills blanks.
--           - Rollback: rollback/2026_08_22_006_ticket-pipeline-type-name-snapshot_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. The column. Guarded, so the seeder is re-runnable.
-- ---------------------------------------------------------------------------
SET @add_ptn := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets'
    AND COLUMN_NAME = 'pipeline_type_name'
);
SET @sql_ptn := IF(@add_ptn = 0,
  'ALTER TABLE `tickets` ADD COLUMN `pipeline_type_name` VARCHAR(150) DEFAULT NULL COMMENT ''Snapshot of pipeline_templates.name, so a request stays readable after its type is deleted'' AFTER `pipeline_template_id`',
  'SELECT 1');
PREPARE stmt FROM @sql_ptn; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Backfill every existing request from the type it still points at.
--    Only fills blanks, so re-running never overwrites a snapshot.
-- ---------------------------------------------------------------------------
UPDATE `tickets` t
  JOIN `pipeline_templates` pt ON pt.id = t.`pipeline_template_id`
   SET t.`pipeline_type_name` = pt.`name`
 WHERE t.`pipeline_template_id` IS NOT NULL
   AND (t.`pipeline_type_name` IS NULL OR t.`pipeline_type_name` = '');

-- ---------------------------------------------------------------------------
-- Verification
--   requests_without_name should be 0 for every request whose type still
--   exists. A request whose type was already deleted (there should be none
--   yet) cannot be recovered by the backfill - only by a future delete's stamp.
-- ---------------------------------------------------------------------------
SELECT
    COUNT(*)                                                          AS requests_total,
    SUM(t.`pipeline_type_name` IS NOT NULL)                           AS requests_with_name,
    SUM(t.`pipeline_type_name` IS NULL AND pt.id IS NOT NULL)         AS requests_without_name,
    SUM(pt.id IS NULL)                                                AS requests_whose_type_is_gone
FROM `tickets` t
LEFT JOIN `pipeline_templates` pt ON pt.id = t.`pipeline_template_id`
WHERE t.`pipeline_template_id` IS NOT NULL;

SELECT
    t.`pipeline_type_name` AS type_name_snapshot,
    COUNT(*)               AS requests
FROM `tickets` t
WHERE t.`pipeline_template_id` IS NOT NULL
GROUP BY t.`pipeline_type_name`
ORDER BY requests DESC;
