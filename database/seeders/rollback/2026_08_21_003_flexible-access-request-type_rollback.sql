-- =============================================================================
-- Rollback for: 2026_08_21_003_flexible-access-request-type.sql
-- Date:         2026-08-21
-- Tables:       pipeline_templates, pipeline_stages
--
-- Removes the built-in "Temporary Access Request" type and its approval step.
--
-- SAFETY: refuses to delete if any Request was ever raised from it -- deleting
-- then would orphan those tickets' pipeline_template_id. Mirrors the rule
-- PipelineTemplateManager::deleteTemplate() enforces through the API. If
-- requests exist and you still want it gone, ARCHIVE it instead (commented
-- UPDATE at the bottom) rather than break history.
--
-- Grants already issued are unaffected and still expire on schedule. To clear
-- them: DELETE FROM user_permissions WHERE source_ticket_id IS NOT NULL.
-- =============================================================================

START TRANSACTION;

DELETE s FROM `pipeline_stages` s
JOIN `pipeline_templates` t ON t.id = s.pipeline_template_id
WHERE t.`name` = 'Temporary Access Request'
  AND NOT EXISTS (SELECT 1 FROM `tickets` k WHERE k.`pipeline_template_id` = t.id);

DELETE t FROM `pipeline_templates` t
WHERE t.`name` = 'Temporary Access Request'
  AND NOT EXISTS (SELECT 1 FROM `tickets` k WHERE k.`pipeline_template_id` = t.id);

COMMIT;

-- If the DELETE was a no-op because requests exist, archive it instead:
-- UPDATE `pipeline_templates` SET `is_system` = 0, `is_active` = 0, `updated_at` = NOW()
--  WHERE `name` = 'Temporary Access Request';

SELECT t.id, t.name, t.is_active,
       (SELECT COUNT(*) FROM `tickets` k WHERE k.`pipeline_template_id` = t.id) AS requests_raised
FROM `pipeline_templates` t
WHERE t.`name` = 'Temporary Access Request';
