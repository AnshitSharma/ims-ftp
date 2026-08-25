-- =============================================================================
-- 2026_08_24_003_developer-role.sql
--
-- Date:     2026-08-24
-- Purpose:  Create the `developer` role and put the development team in it.
--           This is the owner of the "Catalogue Entry" step of the forthcoming
--           "Add New Component Model" request type (Phase 10), where a developer
--           completes the compatibility-critical specs a requester could not
--           supply, and completing that step is what creates the catalogue entry.
--
--           Assigned by ROLE rather than to a person on purpose: catalogue
--           requests must not stall when one developer is on leave, and a second
--           developer can be added here without editing the request type.
--
-- Tables:   roles, role_permissions, user_roles   (INSERTs only — nothing dropped,
--                                                  nothing revoked, no existing
--                                                  role membership altered)
-- Feature:  Requests-as-automation, Phase 10 (tasks/todo.md)
-- Related:  2026_06_18_002 (pipeline ACL permissions),
--           2026_08_20_003 (viewer read-only)
--
-- -----------------------------------------------------------------------------
-- READ THIS BEFORE EXPECTING THE ROLE TO WORK
-- -----------------------------------------------------------------------------
-- The ACL permissions granted below are NOT sufficient on their own. api.php,
-- in handlePipelineOperations(), gates every non-self-service pipeline operation
-- -- including `complete` and `claim` -- behind a hardcoded check for the
-- super_admin or admin ROLE NAME, on top of ACL:
--
--     $selfServiceOperations = ['create', 'list', 'get', 'template-list', 'servers'];
--     if (!in_array($operation, $selfServiceOperations, true)) {
--         if (!userHasRole(..., 'super_admin') && !userHasRole(..., 'admin')) { 403 }
--     }
--
-- So a user whose ONLY role is `developer` will be refused when completing the
-- Catalogue Entry step, no matter what this seeder grants. Narrowing that gate
-- is Phase 10 work and is deliberately NOT done here: the fix is to let
-- `complete`/`claim` through for the assigned owner of the stage (which
-- PipelineManager::assertCanAct() already verifies) while `reassign`, `cancel`
-- and the Request Type editor stay admin-only. Widening the role list instead
-- would let a developer act on EVERY step of EVERY request, which is not what
-- this role is for.
--
-- Running this seeder early is still correct and harmless: the role and its
-- membership are inert until Phase 10 ships, and nothing else reads them.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. The role
-- -----------------------------------------------------------------------------
-- is_system = 0: this is an operational role, not one of the five built-ins, so
-- it stays editable and deletable through the ACL screen.
-- is_default = 0: new users must never land in it automatically.
INSERT IGNORE INTO `roles` (`name`, `display_name`, `description`, `is_default`, `is_system`, `created_at`, `updated_at`)
VALUES (
    'developer',
    'Developer',
    'Development team. Owns the Catalogue Entry step: completes the hardware specifications a requester could not supply, which is what creates a new component model.',
    0,
    0,
    NOW(),
    NOW()
);


-- -----------------------------------------------------------------------------
-- 2. Permissions
-- -----------------------------------------------------------------------------
-- Exactly what owning a step requires, and nothing more:
--   pipeline.create    raise a request like anyone else
--   pipeline.view_own  see their own requests and those they are involved in
--                      (pipeline-get authorises on view_own + row-level
--                      involvement, and a stage owner counts as involved, so
--                      pipeline.view_all is NOT needed)
--   pipeline.claim     take the Catalogue Entry step
--   pipeline.act       complete it
--
-- Deliberately NOT granted: pipeline.manage (approve anything at all),
-- pipeline.reassign, pipeline.cancel, pipeline.template_manage. A developer
-- completes the step they own; they are not an approver.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`, `granted`, `created_at`)
SELECT r.`id`, p.`id`, 1, NOW()
  FROM `roles` r
  JOIN `permissions` p
    ON p.`name` IN ('pipeline.create', 'pipeline.view_own', 'pipeline.claim', 'pipeline.act')
 WHERE r.`name` = 'developer';


-- -----------------------------------------------------------------------------
-- 3. Membership
-- -----------------------------------------------------------------------------
-- Matched by USERNAME, never by a hardcoded id: ids are environment-specific and
-- the reference dump in this repo is a snapshot that may be stale. A username
-- that does not exist simply matches nothing -- no error, no partial state --
-- so section 4 below is how you confirm what actually happened.
--
-- >>> EDIT THIS LIST if a developer's account is named differently. <<<
-- 'Dev'        -> dev@bharatdatacenter.com, the shared development account
-- 'anshit_231' -> the only Anshit-like account in the reference dump
--
-- NOTE: usernames collate utf8mb4_general_ci, so matching is case-insensitive.
--
-- This ADDS a role. It does not remove or replace any role these users already
-- hold -- permissions are the union of all of a user's roles.
INSERT IGNORE INTO `user_roles` (`user_id`, `role_id`, `assigned_by`, `assigned_at`)
SELECT u.`id`, r.`id`, NULL, NOW()
  FROM `users` u
  JOIN `roles` r ON r.`name` = 'developer'
 WHERE u.`username` IN ('Dev', 'anshit_231');


-- -----------------------------------------------------------------------------
-- 4. Verification -- run these and read the output
-- -----------------------------------------------------------------------------

-- Expect exactly one row.
SELECT `id`, `name`, `display_name`, `is_system`, `is_default`
  FROM `roles`
 WHERE `name` = 'developer';

-- Expect 4 rows: pipeline.act, pipeline.claim, pipeline.create, pipeline.view_own.
SELECT p.`name` AS permission
  FROM `role_permissions` rp
  JOIN `roles` r       ON r.`id` = rp.`role_id`
  JOIN `permissions` p ON p.`id` = rp.`permission_id`
 WHERE r.`name` = 'developer' AND rp.`granted` = 1
 ORDER BY p.`name`;

-- WHO IS ACTUALLY IN THE ROLE. Confirm both intended people are here. If a name
-- is missing, that account is named something else -- correct the list in
-- section 3 and re-run this file (it is idempotent).
SELECT u.`id`, u.`username`, u.`email`, u.`status`
  FROM `user_roles` ur
  JOIN `roles` r ON r.`id` = ur.`role_id`
  JOIN `users` u ON u.`id` = ur.`user_id`
 WHERE r.`name` = 'developer'
 ORDER BY u.`username`;
