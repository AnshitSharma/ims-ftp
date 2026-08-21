-- =============================================================================
-- Rollback for: 2026_08_22_003_reword-access-request-copy.sql
-- Date:         2026-08-22
-- Tables:       pipeline_templates, pipeline_stages
--
-- Restores the description and step instructions to the text 2026_08_21_003 set,
-- word for word. Cosmetic both ways: nothing here changes what the type can grant
-- or what any existing request or grant does.
--
-- Only worth running if the redesigned create form has also been rolled back --
-- the old wording ("name it") describes a free-text UUID field that the new form
-- no longer has.
-- =============================================================================

START TRANSACTION;

UPDATE `pipeline_templates`
   SET `description` = 'Ask for permission you do not normally have -- building or changing a server, or adding and correcting inventory. Choose what you need and, if it is about one particular server, name it. An admin approves and the access lasts 24 hours, then expires by itself. Anything you create or change during the window stays.',
       `updated_at`  = NOW()
 WHERE `name` = 'Temporary Access Request';

UPDATE `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
   SET s.`instructions` = 'Check what was requested before approving -- the grant is exactly the access listed on the request. If a server is named, the server permissions apply to that configuration only. Access lasts 24 hours and then expires on its own. You cannot approve your own request.',
       s.`updated_at`   = NOW()
 WHERE t.`name` = 'Temporary Access Request'
   AND s.`effect_type` = 'grant_temporary_permission';

COMMIT;

-- ---------------------------------------------------------------------------
-- Verification
-- ---------------------------------------------------------------------------
SELECT t.id AS template_id, t.description, s.instructions
FROM `pipeline_templates` t
JOIN `pipeline_stages` s ON s.pipeline_template_id = t.id
WHERE t.`name` = 'Temporary Access Request'
  AND s.`effect_type` = 'grant_temporary_permission';
