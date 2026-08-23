-- =============================================================================
-- Date:     2026-08-23
-- Purpose:  Split the overloaded `server.edit` permission in two, so a hardware
--           Request cannot roam the build it was granted access to.
--
--             server.edit          -> work on the PARTS in a build
--                                     (server-remove-component, server-set-platform)
--             server.edit_details  -> write the build's OWN attributes
--                                     (server-update-config: name, description,
--                                      location, rack_position, notes, status)
--
--           WHY: a live test on 2026-08-23 with a real viewer account showed that
--           an approved "Edit Hardware" request let its holder call
--           server-update-config on the named configuration -- rename it and set
--           configuration_status = 3 (Finalized), which then locked the holder out
--           of their own change. Add / Edit / Remove Hardware MUST grant
--           server.edit, because that is what gates server-remove-component; with
--           one permission covering both jobs there was no way to grant the parts
--           half alone. Component narrowing cannot catch it either --
--           server-update-config names no component_type.
--
--           Seeder 2026_08_23_001 already states the intended contract: "a Server
--           Changes grant cannot touch parts, and a hardware grant cannot roam the
--           build. The two halves are enforced, not just described." This seeder
--           plus the permission_map.php change is what makes the second half true.
--
-- Tables:   permissions        (1 row INSERT)
--           role_permissions   (rows INSERT -- mirrors server.edit's roles)
--           pipeline_stages    (1 row UPDATE -- Server Changes ceiling)
-- Feature:  Temporary approval-gated access (Requests module), phase 7
--
-- REQUIRES: 2026_08_23_001 (the Server Changes request type) applied FIRST --
--           section 3 updates the stage row it wrote. It has been.
--
-- PAIRS WITH code already deployed:
--           api/permission_map.php            update-config / update-location
--                                             -> server.edit_details
--           core/auth/TemporaryAccessManager  server.edit_details added to
--                                             GRANTABLE_PERMISSIONS and
--                                             SCOPABLE_PERMISSIONS
--           handlers/server/server_api.php    configuration_status = 3 refused
--                                             through update-config (finalizing
--                                             goes through server-finalize-config);
--                                             any other status move needs
--                                             server.transition
--
--           Until this seeder runs, server-update-config 403s for every
--           non-admin because server.edit_details does not exist yet. That is
--           harmless -- server-update-config and server-update-location have no
--           caller in either stack -- but do not leave the gap open for long.
--
-- NOTES   - Idempotent. Safe to re-run.
--         - No hardcoded role ids: the grant mirrors whoever holds server.edit
--           today (super_admin, admin, manager, technician), so NO permanent
--           user's access changes. super_admin/admin bypass ACL in code anyway.
--         - The three hardware ceilings (13 / 14 / 2) are deliberately NOT
--           touched -- they keep server.edit, which is what removing a part needs.
--         - Rollback: rollback/2026_08_23_002_server-edit-details-permission_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state -- who holds server.edit today, and what Server Changes grants.
-- ---------------------------------------------------------------------------
SELECT r.`name` AS role, p.`name` AS perm
  FROM `role_permissions` rp
  JOIN `roles` r       ON r.id = rp.`role_id`
  JOIN `permissions` p ON p.id = rp.`permission_id`
 WHERE p.`name` IN ('server.edit', 'server.edit_details')
   AND rp.`granted` = 1
 ORDER BY p.`name`, r.`name`;

SELECT t.`name` AS request_type,
       JSON_EXTRACT(s.`effect_config`, '$.permissions') AS ceiling
  FROM `pipeline_templates` t
  JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.id
 WHERE t.`name` = 'Server Changes';

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. The permission itself.
-- ---------------------------------------------------------------------------
INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (
    SELECT 'server.edit_details'                AS `name`,
           'Edit Server Configuration Details'  AS `display_name`,
           'Change a server configuration''s own attributes -- name, description, location, rack position, notes and status (server-update-config). Does NOT allow adding, removing or swapping parts; that is server.edit / server.create / server.replace.' AS `description`,
           'server'                             AS `category`,
           0                                    AS `is_basic`
) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'server.edit_details');

-- ---------------------------------------------------------------------------
-- 2. Grant it to exactly the roles that already hold server.edit.
--    Same pattern as 2026_07_12_001 / 2026_07_13_005: mirror the parent
--    permission rather than naming role ids, so nobody's access changes.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `_server_edit_details_grants`;
CREATE TEMPORARY TABLE `_server_edit_details_grants` (`role_id` INT NOT NULL, `permission_id` INT NULL);

INSERT INTO `_server_edit_details_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`,
       (SELECT `id` FROM `permissions` WHERE `name` = 'server.edit_details' ORDER BY `id` LIMIT 1)
  FROM `role_permissions` rp
 WHERE rp.`granted` = 1
   AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.edit');

-- Drop the ones already present, and the null-permission row that a missing
-- INSERT in section 1 would have produced.
DELETE g FROM `_server_edit_details_grants` g
JOIN `role_permissions` e ON e.`role_id` = g.`role_id` AND e.`permission_id` = g.`permission_id`;
DELETE FROM `_server_edit_details_grants` WHERE `permission_id` IS NULL;

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT `role_id`, `permission_id`, 1 FROM `_server_edit_details_grants`;

DROP TEMPORARY TABLE IF EXISTS `_server_edit_details_grants`;

-- ---------------------------------------------------------------------------
-- 3. Server Changes swaps server.edit for server.edit_details.
--
--    server.edit drops out because, after the map change, it buys that type only
--    server-remove-component (already refused -- Server Changes grants no
--    component permission, and the API narrows add/remove/replace to the types a
--    request actually asked for) and server-set-platform (which can only
--    re-stamp a platform the fitted board already belongs to). What the type
--    advertises -- name, description, location, rack position, notes, status --
--    is exactly server.edit_details plus server.transition.
--
--    Ceiling size stays 4.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.id = s.`pipeline_template_id`
   SET s.`effect_config` = '{"permissions":["server.create","server.edit_details","server.transition","server.view"],"duration_hours":24}',
       s.`updated_at`    = NOW()
 WHERE t.`name` = 'Server Changes'
   AND s.`effect_type` = 'grant_temporary_permission';

COMMIT;

-- =============================================================================
-- Verification (run after the seeder).
-- =============================================================================

-- 1. The permission exists, in the 'server' category.
SELECT `id`, `name`, `display_name`, `category`
  FROM `permissions`
 WHERE `name` = 'server.edit_details';

-- 2. Its roles match server.edit's EXACTLY. Both columns must list the same
--    roles -- expected: admin, manager, super_admin, technician.
SELECT p.`name` AS perm, GROUP_CONCAT(r.`name` ORDER BY r.`name`) AS roles
  FROM `role_permissions` rp
  JOIN `roles` r       ON r.id = rp.`role_id`
  JOIN `permissions` p ON p.id = rp.`permission_id`
 WHERE p.`name` IN ('server.edit', 'server.edit_details')
   AND rp.`granted` = 1
 GROUP BY p.`name`;

-- 3. Four live access types, ceilings 13 (Add) / 14 (Edit) / 2 (Remove) /
--    4 (Server Changes), all 24 hours. Only Server Changes' contents changed.
SELECT t.`name` AS request_type,
       JSON_LENGTH(JSON_EXTRACT(s.`effect_config`, '$.permissions')) AS ceiling_size,
       JSON_EXTRACT(s.`effect_config`, '$.permissions')              AS ceiling
  FROM `pipeline_templates` t
  JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.id
 WHERE t.`name` IN ('Add Hardware', 'Edit Hardware', 'Remove Hardware', 'Server Changes')
 ORDER BY t.`name`;

-- 4. No hardware ceiling mentions server.edit_details, and Server Changes no
--    longer mentions server.edit. Expected: zero rows.
SELECT t.`name` AS request_type, JSON_EXTRACT(s.`effect_config`, '$.permissions') AS ceiling
  FROM `pipeline_templates` t
  JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.id
 WHERE (t.`name` IN ('Add Hardware', 'Edit Hardware', 'Remove Hardware')
        AND JSON_SEARCH(s.`effect_config`, 'one', 'server.edit_details') IS NOT NULL)
    OR (t.`name` = 'Server Changes'
        AND JSON_SEARCH(s.`effect_config`, 'one', 'server.edit') IS NOT NULL);
