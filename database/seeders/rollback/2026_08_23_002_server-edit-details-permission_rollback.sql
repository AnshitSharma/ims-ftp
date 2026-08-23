-- =============================================================================
-- Rollback for 2026_08_23_002_server-edit-details-permission.sql
-- Date: 2026-08-23
--
-- Puts server.edit back in charge of server-update-config / -update-location and
-- removes server.edit_details entirely.
--
-- REVERT THE CODE FIRST, or the rollback breaks the action it is restoring:
--   api/permission_map.php            'update-config'   => 'server.edit'
--                                     'update-location' => 'server.edit'
--   core/auth/TemporaryAccessManager  drop 'server.edit_details' from
--                                     GRANTABLE_PERMISSIONS and SCOPABLE_PERMISSIONS
-- (server_api.php's status gates can stay -- they are correct independently of
-- the permission split, and reverting them would reopen the finalize back door.)
--
-- Running this restores the reported defect: an Edit or Remove Hardware grant
-- can again rename the build it names. Only run it if the split itself is wrong.
-- =============================================================================

START TRANSACTION;

-- 1. Server Changes goes back to the 2026_08_23_001 ceiling.
UPDATE `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.id = s.`pipeline_template_id`
   SET s.`effect_config` = '{"permissions":["server.create","server.edit","server.transition","server.view"],"duration_hours":24}',
       s.`updated_at`    = NOW()
 WHERE t.`name` = 'Server Changes'
   AND s.`effect_type` = 'grant_temporary_permission';

-- 2. Revoke any live temporary grant of the permission before it disappears,
--    so no user_permissions row is left pointing at a deleted permission id.
DELETE FROM `user_permissions`
 WHERE `permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.edit_details');

-- 3. Drop the role grants, then the permission.
DELETE FROM `role_permissions`
 WHERE `permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.edit_details');

DELETE FROM `permissions` WHERE `name` = 'server.edit_details';

COMMIT;

-- Verification: both queries must return zero rows.
SELECT `id`, `name` FROM `permissions` WHERE `name` = 'server.edit_details';

SELECT t.`name`, JSON_EXTRACT(s.`effect_config`, '$.permissions') AS ceiling
  FROM `pipeline_templates` t
  JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.id
 WHERE t.`name` = 'Server Changes'
   AND JSON_SEARCH(s.`effect_config`, 'one', 'server.edit_details') IS NOT NULL;
