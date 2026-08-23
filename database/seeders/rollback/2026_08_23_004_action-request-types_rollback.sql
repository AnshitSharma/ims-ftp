-- =============================================================================
-- ROLLBACK for 2026_08_23_004_action-request-types.sql
-- Date:     2026-08-23
-- Purpose:  Re-activate the four access-shaped Request Types, re-arm their grant
--           effects, and archive the eight action-shaped ones.
--
-- Tables:   pipeline_templates (4 re-activated, 8 archived)
--           pipeline_stages    (4 effects restored)
-- Feature:  Requests as automation (Phase 8)
--
-- ONLY MEANINGFUL IF THE PHASE 8 CODE IS ALSO REVERTED.
--   Re-arming 'grant_temporary_permission' against the current code does NOT
--   restore grants: applyStageEffect() treats that effect as retired and
--   performs nothing, deliberately, so old requests stay completable. The
--   result would be four request types that promise access nobody receives --
--   the worst of both models. Revert the code first, then run this.
--
--   It is also NOT sufficient on its own: 2026_08_23_005 DELETES the live grant
--   rows from user_permissions. Restoring the types does not restore anyone's
--   access; that needs 005's rollback as well.
--
-- The ceilings below are reproduced verbatim from 2026_08_22_005 (Add / Edit /
-- Remove Hardware) and 2026_08_23_001 as amended by 2026_08_23_002, which
-- swapped server.edit for server.edit_details in the Server Changes ceiling.
-- =============================================================================

START TRANSACTION;

-- 1. Archive the action-shaped types.
UPDATE `pipeline_templates`
   SET `is_active` = 0, `updated_at` = NOW()
 WHERE `name` IN ('Install Hardware','Return Hardware to Stock','Swap Hardware','New Server',
                  'Update Server Details','Change Server Status','Add Inventory Record',
                  'Update Inventory Record');

-- 2. Re-activate the access-shaped types.
UPDATE `pipeline_templates`
   SET `is_active` = 1, `updated_at` = NOW()
 WHERE `name` IN ('Add Hardware', 'Edit Hardware', 'Remove Hardware', 'Server Changes');

-- 3. Re-arm their approval steps.
UPDATE `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
   SET s.`effect_type` = 'grant_temporary_permission',
       s.`effect_config` = '{"permissions":["server.create","server.view","cpu.create","ram.create","storage.create","motherboard.create","nic.create","caddy.create","chassis.create","pciecard.create","risercard.create","hbacard.create","sfp.create"],"duration_hours":24}',
       s.`updated_at` = NOW()
 WHERE t.`name` = 'Add Hardware' AND s.`position` = 1;

UPDATE `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
   SET s.`effect_type` = 'grant_temporary_permission',
       s.`effect_config` = '{"permissions":["server.edit","server.replace","server.view","cpu.edit","ram.edit","storage.edit","motherboard.edit","nic.edit","caddy.edit","chassis.edit","pciecard.edit","risercard.edit","hbacard.edit","sfp.edit"],"duration_hours":24}',
       s.`updated_at` = NOW()
 WHERE t.`name` = 'Edit Hardware' AND s.`position` = 1;

UPDATE `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
   SET s.`effect_type` = 'grant_temporary_permission',
       s.`effect_config` = '{"permissions":["server.edit","server.view"],"duration_hours":24}',
       s.`updated_at` = NOW()
 WHERE t.`name` = 'Remove Hardware' AND s.`position` = 1;

UPDATE `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
   SET s.`effect_type` = 'grant_temporary_permission',
       s.`effect_config` = '{"permissions":["server.create","server.edit_details","server.transition","server.view"],"duration_hours":24}',
       s.`updated_at` = NOW()
 WHERE t.`name` = 'Server Changes' AND s.`position` = 1;

COMMIT;

-- =============================================================================
-- Verification
-- =============================================================================

-- The four access types are active and armed again. MUST return 4 rows.
SELECT t.`name`, t.`is_active`, s.`effect_type`, s.`effect_config`
  FROM `pipeline_templates` t
  JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
 WHERE t.`name` IN ('Add Hardware','Edit Hardware','Remove Hardware','Server Changes')
 ORDER BY t.`name`;

-- The action-shaped types are archived. MUST return 0.
SELECT COUNT(*) AS action_types_still_active
  FROM `pipeline_templates`
 WHERE `is_active` = 1
   AND `name` IN ('Install Hardware','Return Hardware to Stock','Swap Hardware','New Server',
                  'Update Server Details','Change Server Status','Add Inventory Record',
                  'Update Inventory Record');
