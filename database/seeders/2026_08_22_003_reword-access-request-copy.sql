-- =============================================================================
-- Date:     2026-08-22
-- Purpose:  Reword the built-in "Temporary Access Request" type to match the
--           redesigned create form. COSMETIC ONLY -- no schema, no ACL rows, no
--           change to what can be granted.
--
--           The old copy said "if it is about one particular server, name it",
--           which described the free-text UUID box that used to be the only way
--           to target a server. That box is gone: the form now shows a searchable
--           list of server configurations inside the Servers group of the access
--           picker, and asks a scope question (one specific server / any server)
--           before it asks which permissions.
--
--           The approver instructions gain the other half of that: when NO server
--           is listed on the request, the server permissions are granted for every
--           configuration in the system. That was always true and was never said
--           out loud, because leaving the UUID box empty was the silent default.
--
-- Tables:   pipeline_templates (1 row UPDATE), pipeline_stages (1 row UPDATE)
-- Feature:  Temporary approval-gated access (Requests module), phase 3
--
-- REQUIRES: 2026_08_21_003_flexible-access-request-type.sql (creates the type).
--           If that has not been applied, both UPDATEs match 0 rows and this file
--           is a harmless no-op.
--
-- Notes:    - Idempotent: re-running rewrites the same text.
--           - effect_config is deliberately NOT touched. The 27-permission
--             ceiling and the 24-hour duration are unchanged.
--           - ASCII only, matching the other seeders in this series (the app
--             writes this text out through htmlspecialchars on other paths).
--           - Rollback: rollback/2026_08_22_003_reword-access-request-copy_rollback.sql
-- =============================================================================

START TRANSACTION;

UPDATE `pipeline_templates`
   SET `description` = 'Ask for permission you do not normally have -- building or changing a server, or adding and correcting inventory. Choose what you need, and when it is about one particular server, pick that server from the list. An admin approves and the access lasts 24 hours, then expires by itself. Anything you create or change during the window stays.',
       `updated_at`  = NOW()
 WHERE `name` = 'Temporary Access Request';

UPDATE `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
   SET s.`instructions` = 'Check what was requested before approving -- the grant is exactly the access listed on the request. If a server is listed, the server permissions apply to that one configuration and no other. If NO server is listed, they apply to every configuration in the system, so read that case carefully. Inventory permissions are never limited to a server. Access lasts 24 hours and then expires on its own. You cannot approve your own request.',
       s.`updated_at`   = NOW()
 WHERE t.`name` = 'Temporary Access Request'
   AND s.`effect_type` = 'grant_temporary_permission';

COMMIT;

-- ---------------------------------------------------------------------------
-- Verification -- expect the new wording, and an untouched 27-permission ceiling.
-- ---------------------------------------------------------------------------
SELECT t.id AS template_id, t.name AS request_type, t.description,
       s.name AS step, s.instructions,
       JSON_LENGTH(JSON_EXTRACT(s.effect_config, '$.permissions')) AS ceiling_size,
       JSON_EXTRACT(s.effect_config, '$.duration_hours') AS duration_hours
FROM `pipeline_templates` t
JOIN `pipeline_stages` s ON s.pipeline_template_id = t.id
WHERE t.`name` = 'Temporary Access Request';
