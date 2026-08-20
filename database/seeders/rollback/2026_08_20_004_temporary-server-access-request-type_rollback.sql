-- =============================================================================
-- Rollback for: 2026_08_20_004_temporary-server-access-request-type.sql
-- Date:         2026-08-20
-- Tables:       pipeline_templates, pipeline_stages
--
-- Removes the built-in "Temporary Server Creation Access" Request Type and its
-- approval step.
--
-- SAFETY: refuses to delete the type if any Request was ever raised from it --
-- deleting it then would orphan those tickets' pipeline_template_id. This mirrors
-- PipelineTemplateManager::deleteTemplate() L308-315, which enforces the same
-- rule through the API. If requests exist and you want the type gone anyway,
-- the honest move is to ARCHIVE it (the UPDATE at the bottom, commented out)
-- rather than break history.
--
-- Grants already issued are NOT affected: they live in user_permissions and still
-- expire on schedule. To clear them too, run
-- rollback/2026_08_20_001_temporary-permission-grants_rollback.sql, or just
-- DELETE FROM user_permissions WHERE source_ticket_id IS NOT NULL.
-- =============================================================================

START TRANSACTION;

-- Step first (FK-free schema, but keep the order sane).
DELETE s FROM `pipeline_stages` s
JOIN `pipeline_templates` t ON t.id = s.pipeline_template_id
WHERE t.`name` = 'Temporary Server Creation Access'
  AND NOT EXISTS (
      SELECT 1 FROM `tickets` k WHERE k.`pipeline_template_id` = t.id
  );

DELETE t FROM `pipeline_templates` t
WHERE t.`name` = 'Temporary Server Creation Access'
  AND NOT EXISTS (
      SELECT 1 FROM `tickets` k WHERE k.`pipeline_template_id` = t.id
  );

COMMIT;

-- If the DELETE above was a no-op because requests exist, archive it instead by
-- running this line manually. is_system = 0 first, or the API will refuse.
-- UPDATE `pipeline_templates` SET `is_system` = 0, `is_active` = 0, `updated_at` = NOW()
--  WHERE `name` = 'Temporary Server Creation Access';

-- Verification -- expect 0 rows once removed, or 1 row with a request count if
-- it was kept because requests reference it.
SELECT
    t.id,
    t.name,
    t.is_active,
    (SELECT COUNT(*) FROM `tickets` k WHERE k.`pipeline_template_id` = t.id) AS requests_raised
FROM `pipeline_templates` t
WHERE t.`name` = 'Temporary Server Creation Access';
