-- =============================================================================
-- Rollback for: 2026_08_22_004_split-access-request-types.sql
-- Date:         2026-08-22
-- Tables:       pipeline_templates
--
-- Puts the combined "Temporary Access Request" back the way 2026_08_21_003 left
-- it -- active and flagged built-in -- and archives the two split types.
--
-- Its step and its 27-permission effect_config were never touched by the
-- forward seeder, so it works again immediately.
--
-- The two split types are ARCHIVED, not deleted: requests may already have been
-- raised from them, and `tickets` rows point at them via pipeline_template_id.
-- Archiving takes them out of the create-request dropdown while leaving that
-- history readable. If nothing was ever raised from them and you want them
-- gone entirely, delete them from the Request Types page afterwards --
-- PipelineTemplateManager::deleteTemplate() refuses if any request exists.
--
-- Nothing else needs undoing: the forward seeder revoked no grants, modified no
-- tickets, and changed no permission the code is willing to grant.
-- =============================================================================

START TRANSACTION;

UPDATE `pipeline_templates`
   SET `is_active`  = 1,
       `is_system`  = 1,
       `updated_at` = NOW()
 WHERE `name` = 'Temporary Access Request';

UPDATE `pipeline_templates`
   SET `is_active`  = 0,
       `is_system`  = 0,
       `updated_at` = NOW()
 WHERE `name` IN ('Server Access Request', 'Inventory Access Request');

COMMIT;

SELECT
    t.id, t.name, t.is_active, t.is_system,
    s.name AS step,
    JSON_LENGTH(JSON_EXTRACT(s.effect_config, '$.permissions')) AS ceiling_size
FROM `pipeline_templates` t
LEFT JOIN `pipeline_stages` s ON s.pipeline_template_id = t.id
WHERE t.`name` IN ('Temporary Access Request', 'Server Access Request', 'Inventory Access Request')
ORDER BY t.`is_active` DESC, t.`name`;
