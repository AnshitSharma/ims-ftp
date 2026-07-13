-- ============================================================
-- Seeder : 2026_07_13_005_consolidated-server-command-permissions
-- Date   : 2026-07-13
-- Purpose: CONSOLIDATED seeder (owner request) — combines the full ACL/
--          permission content of four previously-written, never-run seeders
--          into one file so the production DB update is a single run:
--            * 2026_07_12_001 (server.replace + server.transition permissions,
--              grants mirroring server.edit / server.create)
--            * 2026_07_13_002 (server.finalize permission, grants mirroring
--              server.edit)
--            * 2026_07_13_003 (owner TRIM decision: revoke viewer's
--              server.edit and server.finalize grants)
--            * 2026_07_13_004 (documentation-only correction of 002's
--              expectation — made moot here; this file's own Verification
--              footer states the corrected expectation directly)
--          Those four files are marked SUPERSEDED via header comment and must
--          NOT be run if this file is run. Running both is still harmless
--          (everything is idempotent and this file's trim step wins), but
--          pick one path: THIS file alone is the intended one.
--
--          Statement order inside this file is deliberate and makes the
--          outcome deterministic (unlike the 002/003 either-order dance):
--            1. Revoke viewer's server.edit FIRST, so the grant-mirroring
--               steps below never pick viewer up.
--            2. Insert the three new permission rows (server.replace,
--               server.transition, server.finalize) — only when missing.
--            3. Mirror grants: server.replace + server.finalize to roles
--               holding server.edit (now excludes viewer); server.transition
--               to roles holding server.create. No hardcoded role ids.
--            4. Defensive final revoke of viewer's server.finalize, in case
--               a partial earlier run of the superseded 2026_07_13_002 had
--               already granted it.
-- Tables : permissions (3 rows), role_permissions (grants + 0-2 deletes)
-- Notes  : Idempotent. Safe to re-run. super_admin/admin do NOT bypass
--          ACL::hasPermission() in code (pure role_permissions JOIN) — they
--          need these rows like any other role.
-- Feature: Command-layer API adapters (U-A.2) + state-machine finalize
--          permission gap + viewer over-grant TRIM (owner decisions recorded
--          in migration/handoffs/SESSION-20260712-FINDINGS-ABC.md).
-- Mapping (new perm       <- mirrors existing perm):
--   server.replace         <- server.edit   (post-trim: admin, super_admin)
--   server.transition      <- server.create
--   server.finalize        <- server.edit   (post-trim: admin, super_admin)
-- ============================================================

-- ---- Step 1: TRIM — revoke viewer's server.edit (owner decision) ----------

DELETE rp FROM `role_permissions` rp
JOIN `roles` r ON r.`id` = rp.`role_id` AND r.`name` = 'viewer'
JOIN `permissions` p ON p.`id` = rp.`permission_id` AND p.`name` = 'server.edit';

-- ---- Step 2: new permission rows (insert-if-missing) ----------------------

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'server.replace' AS `name`, 'Replace Server Component' AS `display_name`, 'Replace one physical component with another via the command layer (ReplaceComponentCommand)' AS `description`, 'server' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'server.replace');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'server.transition' AS `name`, 'Transition Server Status' AS `display_name`, 'Move a server configuration through its status_v2 lifecycle via the command layer (TransitionStatusCommand)' AS `description`, 'server' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'server.transition');

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'server.finalize' AS `name`, 'Finalize Server Configuration' AS `display_name`, 'Transition a server configuration to finalized via the state machine (StateMachine::assertConfigTransition / TransitionStatusCommand)' AS `description`, 'server_management' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'server.finalize');

-- ---- Step 3: mirror grants (skip rows that already exist) -----------------

DROP TEMPORARY TABLE IF EXISTS `_server_cmd_grants`;
CREATE TEMPORARY TABLE `_server_cmd_grants` (`role_id` INT NOT NULL, `permission_id` INT NULL);

INSERT INTO `_server_cmd_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'server.replace' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.edit');

INSERT INTO `_server_cmd_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'server.finalize' ORDER BY `id` LIMIT 1)
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

-- ---- Step 4: defensive TRIM — viewer must not hold server.finalize --------

DELETE rp FROM `role_permissions` rp
JOIN `roles` r ON r.`id` = rp.`role_id` AND r.`name` = 'viewer'
JOIN `permissions` p ON p.`id` = rp.`permission_id` AND p.`name` = 'server.finalize';

-- ============================================================
-- Verification (run after the seeder):
--
--   SELECT name, category FROM permissions
--    WHERE name IN ('server.replace', 'server.transition', 'server.finalize');
--   -- expect 3 rows
--
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name IN ('server.edit', 'server.replace', 'server.transition', 'server.finalize')
--    ORDER BY p.name, r.name;
--   -- expect: viewer appears in NONE of these; server.edit / server.replace /
--   -- server.finalize held by admin + super_admin only; server.transition
--   -- mirrors whichever roles hold server.create.
--
-- Rollback (manual, if ever needed):
--   DELETE rp FROM role_permissions rp
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name IN ('server.replace','server.transition','server.finalize');
--   DELETE FROM permissions
--    WHERE name IN ('server.replace','server.transition','server.finalize');
--   -- viewer's server.edit revoke: re-grant manually only if that owner
--   -- decision is itself reversed:
--   -- INSERT INTO role_permissions (role_id, permission_id, granted)
--   --   SELECT r.id, p.id, 1 FROM roles r, permissions p
--   --    WHERE r.name='viewer' AND p.name='server.edit';
-- ============================================================
