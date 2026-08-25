-- =============================================================================
-- Date:     2026-08-26
-- Purpose:  Give the people who physically carry hardware the four permissions
--           they need to confirm a handover -- and nothing else.
--
--           A Hardware Handover request (2026_08_26_007) names a carrier, and
--           only that named person can complete the Handover Confirmation step
--           (PipelineManager::assertCanAct()). But being named is not enough:
--           pipeline-complete.php still requires `pipeline.act`, and the
--           requests page itself requires `pipeline.view_own` before the
--           request is even visible. Without this file the carrier opens the
--           app, sees nothing, and the child request can never close -- which
--           freezes the parent install forever.
--
-- Tables:   role_permissions (INSERT IGNORE), optionally roles + user_roles
-- Feature:  Location-aware Requests + Hardware Handover, part 3 of 3
-- Requires: nothing beyond the base ACL tables. Safe to run before or after
--           2026_08_26_007.
--
-- WHY THESE FOUR AND NO MORE
--   pipeline.create    raise a handover request like anyone else
--   pipeline.view_own  see their own requests and those they are involved in.
--                      pipeline-get authorises on view_own PLUS row-level
--                      involvement, and a stage assignee counts as involved,
--                      so pipeline.view_all is NOT needed and is NOT granted.
--   pipeline.claim     take a role-owned step (needed only if a future type
--                      owns a carrier step by role rather than by name)
--   pipeline.act       complete the step they own
--
--   Deliberately NOT granted: pipeline.manage, pipeline.reassign,
--   pipeline.cancel, pipeline.template_manage. A carrier signs for hardware;
--   they are not an approver. This mirrors 2026_08_24_003's developer role
--   exactly, and for the same reason.
--
-- WHY THIS CANNOT LET A CARRIER PERFORM THE MOVE
--   applyStageEffect() Guard 1 refuses any execute_request effect unless the
--   completer is admin or super_admin. The Handover Confirmation step carries
--   no effect at all, and the Admin Approval step that does is owned by the
--   admin role. A carrier who somehow reached an effect-bearing step is refused
--   and the completion rolls back whole.
--
-- Notes:    - Roles are matched by NAME, never by id: ids are environment-
--             specific and the reference dump in this repo may be stale. A role
--             name that does not exist simply matches nothing -- no error, no
--             partial state -- so section 3 is how you confirm what happened.
--           - This ADDS permissions. It removes nothing, and a user's effective
--             permissions are the union of all their roles.
--
-- Idempotent: INSERT IGNORE throughout. Re-running is a no-op.
-- Rollback:   rollback/2026_08_26_008_pipeline-act-for-handover-carriers_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state -- which roles can already act on a step.
-- ---------------------------------------------------------------------------
SELECT r.`name` AS role_name,
       GROUP_CONCAT(p.`name` ORDER BY p.`name` SEPARATOR ', ') AS pipeline_permissions
  FROM `roles` r
  JOIN `role_permissions` rp ON rp.`role_id` = r.`id` AND rp.`granted` = 1
  JOIN `permissions` p       ON p.`id` = rp.`permission_id`
 WHERE p.`name` LIKE 'pipeline.%'
 GROUP BY r.`name`
 ORDER BY r.`name`;


-- ---------------------------------------------------------------------------
-- 1. The carrier role.
--
--    Most sites already have a role whose members do this work -- technician,
--    field engineer, ops. If one of yours does, DELETE this section entirely
--    and just list that role's name in section 2. This creates a dedicated one
--    only so that a site with no such role still has something to grant to.
--
--    is_system = 0: operational, so it stays editable and deletable from the
--    ACL screen. is_default = 0: nobody lands in it automatically.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `roles` (`name`, `display_name`, `description`, `is_default`, `is_system`, `created_at`, `updated_at`)
VALUES (
    'hardware_carrier',
    'Hardware Carrier',
    'Physically transports hardware between sites. Owns the Handover Confirmation step: signs that a part has actually arrived at its destination, which closes the handover request and releases the install waiting on it. Not an approver.',
    0,
    0,
    NOW(),
    NOW()
);


-- ---------------------------------------------------------------------------
-- 2. The grants.
--
-- >>> EDIT THIS LIST to match the roles whose members actually carry hardware
-- >>> at your site. Add your existing technician / field-engineer role here
-- >>> rather than moving everyone into a new one.
--
--    A role name that does not exist matches nothing and is silently skipped,
--    so leaving an inapplicable name in the list is harmless -- but section 3
--    is the only thing that tells you which ones took effect.
--
--    NOTE: role names collate utf8mb4_general_ci, so matching is
--    case-insensitive.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`, `created_at`)
SELECT r.`id`, p.`id`, 1, NOW()
  FROM `roles` r
  JOIN `permissions` p
    ON p.`name` IN ('pipeline.create', 'pipeline.view_own', 'pipeline.claim', 'pipeline.act')
 WHERE r.`name` IN ('hardware_carrier');
--                  ^^^^^^^^^^^^^^^^ >>> EDIT THIS LIST <<<


-- ---------------------------------------------------------------------------
-- 3. Verification -- run these and read the output.
-- ---------------------------------------------------------------------------

-- 3a. The role exists. Expect one row per name you listed above.
SELECT `id`, `name`, `display_name`, `is_system`, `is_default`
  FROM `roles`
 WHERE `name` IN ('hardware_carrier');

-- 3b. THE ONE THAT MATTERS: each listed role now holds exactly these four.
--     A role missing from this output is a role name that did not match.
SELECT r.`name` AS role_name,
       GROUP_CONCAT(p.`name` ORDER BY p.`name` SEPARATOR ', ') AS granted_now,
       COUNT(*) AS grant_count
  FROM `roles` r
  JOIN `role_permissions` rp ON rp.`role_id` = r.`id` AND rp.`granted` = 1
  JOIN `permissions` p       ON p.`id` = rp.`permission_id`
 WHERE r.`name` IN ('hardware_carrier')
   AND p.`name` IN ('pipeline.create', 'pipeline.view_own', 'pipeline.claim', 'pipeline.act')
 GROUP BY r.`name`;

-- 3c. Confirm NOTHING dangerous leaked in. MUST return zero rows.
SELECT r.`name` AS role_name, p.`name` AS unexpected_permission
  FROM `roles` r
  JOIN `role_permissions` rp ON rp.`role_id` = r.`id` AND rp.`granted` = 1
  JOIN `permissions` p       ON p.`id` = rp.`permission_id`
 WHERE r.`name` = 'hardware_carrier'
   AND p.`name` NOT IN ('pipeline.create', 'pipeline.view_own', 'pipeline.claim', 'pipeline.act');

-- 3d. Who is actually in the role. Empty is expected on a fresh run -- assign
--     members from the ACL screen, or add an INSERT IGNORE into `user_roles`
--     matched by USERNAME (never by id), as 2026_08_24_003 section 3 does.
SELECT u.`id`, u.`username`
  FROM `users` u
  JOIN `user_roles` ur ON ur.`user_id` = u.`id`
  JOIN `roles` r       ON r.`id` = ur.`role_id`
 WHERE r.`name` = 'hardware_carrier'
 ORDER BY u.`username`;
