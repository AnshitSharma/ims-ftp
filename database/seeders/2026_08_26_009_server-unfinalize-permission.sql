-- ============================================================
-- Seeder : 2026_08_26_009_server-unfinalize-permission
-- Date   : 2026-08-26
-- Purpose: Make "un-finalize a server" possible at all.
--
--          `config_status_transitions` has carried the edge
--          finalized -> building since U-SM.2 (seeder 2026_07_10_002), and it
--          requires the permission `server.unfinalize`. That permission has
--          NEVER existed as a row in `permissions` -- confirmed against the
--          production dump (the server.* rows there are view / create / edit /
--          delete / view_all / delete_all / view_statistics / edit_all /
--          replace / transition / finalize / edit_details, and nothing else).
--
--          ACL::hasPermission() is a pure role_permissions + user_permissions
--          lookup with NO role-name bypass, so StateMachine::
--          assertConfigTransition() refused that edge for EVERY actor,
--          super_admin included. With STATE_MACHINE_ENABLED=enforce a finalized
--          config is immutable (StateGuard allows only draft/building/
--          maintenance), and the one edge back out of it was unsatisfiable --
--          which is exactly the reported symptom: finalize by mistake and the
--          server is stuck finalized forever.
--
--          This is the same gap, with the same cause and the same fix, that
--          seeder 2026_07_13_002 closed for `server.finalize`.
--
-- Changes: 1. `server.unfinalize` permission row.
--          2. Granted to exactly the roles that hold `server.transition` today.
--             server-transition-status is gated on server.transition BEFORE the
--             per-edge permission is ever consulted (api/permission_map.php), so
--             any role without it could not reach the edge regardless -- this is
--             the narrowest grant that actually works, not a broader one.
--          3. New edge finalized -> draft, same permission and
--             requires_validation as finalized -> building. The graph had no way
--             back to draft from anywhere; both targets are now offered because
--             both were asked for, and 'none' is correct for either: nothing is
--             being certified by walking backwards out of finalized.
--          4. Backfill `status_v2` from `configuration_status` for any
--             server_configurations row still NULL. assertConfigTransition
--             refuses a NULL status_v2 outright (F-21), so such a row can be
--             finalized-looking and still have no legal move at all. Uses the
--             same pairing as StatusMap::CONFIG_LEGACY_TO_V2 (0 draft,
--             1 validated, 2 building, 3 finalized), which is the map U-SM.1's
--             own backfill used.
--
-- Tables : permissions (1 row), role_permissions (rows mirroring
--          server.transition), config_status_transitions (1 row),
--          server_configurations (status_v2 backfill only)
--
-- Notes  : Idempotent. Safe to re-run.
--            * Permission row inserted only when missing (NOT EXISTS).
--            * Grants skip roles that already hold it.
--            * Edge is INSERT IGNORE against the (from_status, to_status) PK.
--            * Backfill touches only rows WHERE status_v2 IS NULL.
--
-- Feature: Server card -- edit details + change status (incl. un-finalize).
--          Frontend: dashboard.js showServerEditModal / changeServerStatus.
--          Backend : server-allowed-transitions, server-transition-status.
--
-- Mapping (new perm        <- mirrors existing perm):
--   server.unfinalize       <- server.transition
-- ============================================================

-- ------------------------------------------------------------
-- 1. The permission row
-- ------------------------------------------------------------
INSERT INTO `permissions` (`name`, `display_name`, `description`, `category`, `is_basic`)
SELECT * FROM (
    SELECT 'server.unfinalize' AS `name`,
           'Un-finalize Server Configuration' AS `display_name`,
           'Move a finalized server configuration back to a mutable status (finalized -> building / draft) via the state machine. Required by every edge OUT of finalized in config_status_transitions.' AS `description`,
           'server_management' AS `category`,
           0 AS `is_basic`
) t
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `name` = 'server.unfinalize');

-- ------------------------------------------------------------
-- 2. Grant it to the roles that already hold server.transition
-- ------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `_server_unfinalize_grants`;
CREATE TEMPORARY TABLE `_server_unfinalize_grants` (`role_id` INT NOT NULL, `permission_id` INT NULL);

INSERT INTO `_server_unfinalize_grants` (`role_id`, `permission_id`)
SELECT DISTINCT rp.`role_id`,
       (SELECT `id` FROM `permissions` WHERE `name` = 'server.unfinalize' ORDER BY `id` LIMIT 1)
FROM `role_permissions` rp
WHERE rp.`granted` = 1
  AND rp.`permission_id` IN (SELECT `id` FROM `permissions` WHERE `name` = 'server.transition');

-- Drop roles that already have the grant, and bail out cleanly if step 1 somehow
-- left no permission row to point at.
DELETE g FROM `_server_unfinalize_grants` g
JOIN `role_permissions` e ON e.`role_id` = g.`role_id` AND e.`permission_id` = g.`permission_id`;
DELETE FROM `_server_unfinalize_grants` WHERE `permission_id` IS NULL;

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `granted`)
SELECT `role_id`, `permission_id`, 1 FROM `_server_unfinalize_grants`;

DROP TEMPORARY TABLE IF EXISTS `_server_unfinalize_grants`;

-- ------------------------------------------------------------
-- 3. finalized -> draft edge
-- ------------------------------------------------------------
INSERT IGNORE INTO `config_status_transitions`
    (`from_status`, `to_status`, `required_permission`, `requires_validation`)
VALUES
    ('finalized', 'draft', 'server.unfinalize', 'none');

-- ------------------------------------------------------------
-- 4. status_v2 backfill for rows that never got one
-- ------------------------------------------------------------
UPDATE `server_configurations`
   SET `status_v2` = CASE `configuration_status`
                         WHEN 0 THEN 'draft'
                         WHEN 1 THEN 'validated'
                         WHEN 2 THEN 'building'
                         WHEN 3 THEN 'finalized'
                         ELSE 'draft'
                     END
 WHERE `status_v2` IS NULL;

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SELECT id, name, category FROM permissions WHERE name = 'server.unfinalize';
--   -- expect 1 row
--
--   SELECT r.name AS role, p.name AS perm
--     FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE p.name IN ('server.transition', 'server.unfinalize')
--    ORDER BY p.name, r.name;
--   -- expect the SAME role set for both (production today: super_admin,
--   -- admin, manager)
--
--   SELECT * FROM config_status_transitions WHERE from_status = 'finalized';
--   -- expect 3 rows: -> building, -> draft (both server.unfinalize/none),
--   -- -> deployed (server.deploy/none)
--
--   SELECT COUNT(*) FROM server_configurations WHERE status_v2 IS NULL;
--   -- expect 0
--
--   SELECT configuration_status, status_v2, COUNT(*)
--     FROM server_configurations GROUP BY 1, 2;
--   -- every pair must agree with StatusMap::CONFIG_V2_TO_LEGACY
--
-- Note: server.deploy, server.maintain and server.retire are still missing from
-- `permissions` in the same way server.unfinalize was, so the finalized ->
-- deployed / deployed -> maintenance / -> retired edges remain unreachable.
-- Deliberately left alone -- nobody has asked for that part of the lifecycle
-- yet, and server-allowed-transitions simply reports them as not allowed.
-- ============================================================
