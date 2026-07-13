-- ============================================================
-- Seeder : 2026_07_13_002_add-server-finalize-permission
-- Date   : 2026-07-13
-- Purpose: `server.finalize` -- the permission required by EVERY edge into
--          'finalized' in `config_status_transitions` (the pre-existing
--          validated->finalized edge from U-SM.2, AND the two draft/building
--          ->finalized edges Finding 2 added in seeder 2026_07_13_001) --
--          does not exist as a row in the `permissions` table at all, and no
--          role has it granted. Confirmed by direct query against the
--          scratch DB: 0 matching rows. This means every finalize-bound
--          transition is currently permission-unsatisfiable for ANY actor,
--          including super_admin/admin (ACL::hasPermission() is a pure
--          role_permissions lookup -- no role name ever bypasses it in code).
--          Surfaced while writing finalize_command_test.php's Finding 2
--          scenario (2026-07-13, eighth session) and independently
--          re-confirmed by that session's own verify pass. Owner-authorized
--          this session to close it the same way U-A.2's server.replace /
--          server.transition permissions were added (seeder 2026_07_12_001):
--          grant to exactly the roles that already hold `server.edit` today,
--          no hardcoded role ids.
-- Tables : permissions (1 row), role_permissions (rows mirroring server.edit)
-- Notes  : Idempotent. Safe to re-run.
--            * Permission row inserted only when missing (NOT EXISTS).
--            * Granted to exactly the roles that already hold server.edit
--              (StateMachine's own transition table already requires
--              server.edit for the two lifecycle edges immediately before
--              finalize -- draft->building, building->validating -- so
--              mirroring that same role set for the final edge is the
--              narrowest closure of the gap, not a broader grant).
--            * super_admin/admin do not bypass ACL::hasPermission() in code
--              (confirmed: it is a pure role_permissions JOIN, no role-name
--              special case) -- they need the row like any other role.
-- Feature: State machine finalize edges (U-SM.2 + Finding 2,
--          migration/03-state-machines).
-- Mapping (new perm       <- mirrors existing perm):
--   server.finalize        <- server.edit
-- ============================================================

INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (SELECT 'server.finalize' AS `name`, 'Finalize Server Configuration' AS `display_name`, 'Transition a server configuration to finalized via the state machine (StateMachine::assertConfigTransition / TransitionStatusCommand)' AS `description`, 'server_management' AS `category`, 0 AS `is_basic`) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'server.finalize');

DROP TEMPORARY TABLE IF EXISTS `_server_finalize_grants`;
CREATE TEMPORARY TABLE `_server_finalize_grants` (`role_id` INT NOT NULL, `permission_id` INT NULL);

INSERT INTO `_server_finalize_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`, (SELECT `id` FROM `permissions` WHERE `name` = 'server.finalize' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1 AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.edit');

DELETE g FROM `_server_finalize_grants` g
JOIN `role_permissions` e ON e.`role_id` = g.`role_id` AND e.`permission_id` = g.`permission_id`;
DELETE FROM `_server_finalize_grants` WHERE `permission_id` IS NULL;

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT `role_id`, `permission_id`, 1 FROM `_server_finalize_grants`;

DROP TEMPORARY TABLE IF EXISTS `_server_finalize_grants`;

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SELECT id, name, category FROM permissions WHERE name = 'server.finalize';
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name = 'server.finalize' ORDER BY r.name;
--
-- Expected: 1 new permission; grants mirror server.edit's current role set
-- exactly (as of this session's scratch DB: admin, super_admin, viewer).
-- ============================================================
