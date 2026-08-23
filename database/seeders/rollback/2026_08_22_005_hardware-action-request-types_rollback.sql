-- =============================================================================
-- Rollback for: 2026_08_22_005_hardware-action-request-types.sql
-- Date:         2026-08-22
-- Tables:       pipeline_templates
--
-- Archives the three action-shaped types and brings back the place-shaped pair
-- from 2026_08_22_004 (Server Access Request / Inventory Access Request). Their
-- steps and 5- and 22-permission ceilings were never touched by the forward
-- seeder, so they work again immediately.
--
-- If 004 was never run, those two rows do not exist and the first UPDATE is a
-- no-op -- run 2026_08_22_004 to get them, or leave the requests screen with
-- General Request only.
--
-- 'Temporary Access Request' is deliberately NOT restored: 004 retired it for
-- its own reasons and this rollback only undoes 005.
--
-- The action-shaped types are ARCHIVED, not deleted: requests may already have
-- been raised from them and `tickets` rows point at them via
-- pipeline_template_id. Archiving takes them out of the create-request dropdown
-- while leaving that history readable.
--
-- Nothing else needs undoing: the forward seeder revoked no grants, modified no
-- tickets, and added no permission the code is willing to grant.
-- =============================================================================

START TRANSACTION;

UPDATE `pipeline_templates`
   SET `is_active`  = 1,
       `is_system`  = 0,
       `updated_at` = NOW()
 WHERE `name` IN ('Server Access Request', 'Inventory Access Request');

UPDATE `pipeline_templates`
   SET `is_active`  = 0,
       `is_system`  = 0,
       `updated_at` = NOW()
 WHERE `name` IN ('Add Hardware', 'Edit Hardware', 'Remove Hardware');

COMMIT;

SELECT
    t.id, t.name, t.is_active, t.is_system,
    s.name AS step,
    JSON_LENGTH(JSON_EXTRACT(s.effect_config, '$.permissions')) AS ceiling_size
FROM `pipeline_templates` t
LEFT JOIN `pipeline_stages` s ON s.pipeline_template_id = t.id
WHERE t.`name` IN (
    'Add Hardware', 'Edit Hardware', 'Remove Hardware',
    'Server Access Request', 'Inventory Access Request'
)
ORDER BY t.`is_active` DESC, t.`name`;
