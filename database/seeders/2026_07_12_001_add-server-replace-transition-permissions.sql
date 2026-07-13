-- SUPERSEDED (2026-07-13, owner request): this seeder was never run against
--   production. Its full content is folded into the consolidated seeder
--   2026_07_13_005_consolidated-server-command-permissions.sql — run THAT
--   file instead of this one. Left in place per this repo's "never edit/
--   delete a seeder" rule; do not run it.
--
-- ============================================================
-- Seeder : 2026_07_12_001_add-server-replace-transition-permissions
-- Date   : 2026-07-12
-- Purpose: ACL permissions for U-A.2's new additive actions
--          (server-replace-component, server-transition-status),
--          which the command layer (ReplaceComponentCommand /
--          TransitionStatusCommand) exposes for the first time.
-- Tables : permissions (rows), role_permissions (rows)
-- Notes  : Idempotent. Safe to re-run.
--            * Permission rows inserted only when missing (NOT EXISTS).
--            * Each new permission is granted to exactly the roles that
--              already hold server.edit (replace) / server.create
--              (transition, mirrors finalize-config's own gating) --
--              no hardcoded role ids. super_admin/admin bypass ACL in
--              code and need no rows.
-- Feature: Command-layer API adapters (U-A.2, migration/08-api-adapters).
-- Mapping (new perm       <- mirrors existing perm):
--   server.replace         <- server.edit
--   server.transition      <- server.create
-- ============================================================

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'server.replace' AS `name`, 'Replace Server Component' AS `display_name`, 'Replace one physical component with another via the command layer (ReplaceComponentCommand)' AS `description`, 'server' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'server.replace');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'server.transition' AS `name`, 'Transition Server Status' AS `display_name`, 'Move a server configuration through its status_v2 lifecycle via the command layer (TransitionStatusCommand)' AS `description`, 'server' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'server.transition');

DROP TEMPORARY TABLE IF EXISTS `_server_cmd_grants`;
CREATE TEMPORARY TABLE `_server_cmd_grants` (`role_id` INT NOT NULL, `permission_id` INT NULL);

INSERT INTO `_server_cmd_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'server.replace' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.edit');

INSERT INTO `_server_cmd_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'server.transition' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.create');

DELETE g FROM `_server_cmd_grants` g
JOIN `role_permissions` e ON e.`role_id` = g.`role_id` AND e.`permission_id` = g.`permission_id`;
DELETE FROM `_server_cmd_grants` WHERE `permission_id` IS NULL;

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT `role_id`, `permission_id`, 1 FROM `_server_cmd_grants`;

DROP TEMPORARY TABLE IF EXISTS `_server_cmd_grants`;

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SELECT name, category FROM permissions WHERE name IN ('server.replace', 'server.transition');
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name IN ('server.replace', 'server.transition') ORDER BY r.name, p.name;
--
-- Expected: 2 new permissions; grants mirror server.edit (replace) /
-- server.create (transition) for every non-admin role.
-- ============================================================
