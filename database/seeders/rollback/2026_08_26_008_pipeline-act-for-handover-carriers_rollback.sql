-- =============================================================================
-- ROLLBACK for 2026_08_26_008_pipeline-act-for-handover-carriers.sql
-- Date:     2026-08-26
-- Purpose:  Remove the carrier role and its four pipeline permissions.
--
-- Tables:   role_permissions (DELETE), user_roles (DELETE), roles (DELETE)
--
-- ============================ READ THIS FIRST ================================
--
-- REVOKING pipeline.act STRANDS EVERY OPEN HANDOVER. The named carrier can no
--   longer complete their step, so the child request never closes, so the
--   parent install stays frozen -- permanently, with no error message that
--   explains why. Section 0b lists exactly which requests that would be. An
--   admin holding pipeline.manage can still force those steps through
--   (assertCanAct() exempts pipeline.manage), so the situation is recoverable,
--   but it will not recover on its own.
--
-- ONLY REVOKE WHAT THIS SEEDER GRANTED. If you added your existing technician
--   role to section 2's list, that role may hold these permissions for other
--   reasons entirely -- another request type, another workflow. Section 2 here
--   is written against 'hardware_carrier' alone for exactly that reason. Widen
--   it only after checking what else would break.
--
-- SECTION 1 IS THE SAFE STOP -- empty the role's membership. Nobody gains the
--   permissions any more, the role and its grants stay intact, and adding a
--   member back restores it. Prefer it.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. KEEP THIS OUTPUT.
-- ---------------------------------------------------------------------------

-- 0a. Current members.
SELECT u.`id`, u.`username`
  FROM `users` u
  JOIN `user_roles` ur ON ur.`user_id` = u.`id`
  JOIN `roles` r       ON r.`id` = ur.`role_id`
 WHERE r.`name` = 'hardware_carrier'
 ORDER BY u.`username`;

-- 0b. THE ONE THAT MATTERS: open handover steps assigned to a member of this
--     role. Every row here becomes unfinishable by its owner.
SELECT k.`id` AS request_id, k.`ticket_number`, k.`status`,
       sp.`name` AS stage_name, sp.`status` AS step_status, u.`username` AS assigned_to,
       k.`parent_ticket_id`
  FROM `ticket_stage_progress` sp
  JOIN `tickets` k     ON k.`id` = sp.`ticket_id`
  JOIN `users` u       ON u.`id` = sp.`assigned_to_user_id`
  JOIN `user_roles` ur ON ur.`user_id` = u.`id`
  JOIN `roles` r       ON r.`id` = ur.`role_id`
 WHERE r.`name` = 'hardware_carrier'
   AND sp.`status` NOT IN ('completed', 'skipped')
   AND k.`status` IN ('draft', 'in_progress');

-- 0c. The grants themselves.
SELECT r.`name` AS role_name, p.`name` AS permission
  FROM `roles` r
  JOIN `role_permissions` rp ON rp.`role_id` = r.`id`
  JOIN `permissions` p       ON p.`id` = rp.`permission_id`
 WHERE r.`name` = 'hardware_carrier'
 ORDER BY p.`name`;

-- ---------------------------------------------------------------------------
-- 1. SAFE STOP -- remove the members, keep the role and its grants.
-- ---------------------------------------------------------------------------
-- DELETE ur FROM `user_roles` ur
--   JOIN `roles` r ON r.`id` = ur.`role_id`
--  WHERE r.`name` = 'hardware_carrier';

-- ---------------------------------------------------------------------------
-- 2. DESTRUCTIVE -- revoke the grants and delete the role.
--    Read section 0b again before uncommenting. Run 2a, then 2b, then 2c.
-- ---------------------------------------------------------------------------
-- 2a. The four permissions, and only those four.
-- DELETE rp FROM `role_permissions` rp
--   JOIN `roles` r       ON r.`id` = rp.`role_id`
--   JOIN `permissions` p ON p.`id` = rp.`permission_id`
--  WHERE r.`name` = 'hardware_carrier'
--    AND p.`name` IN ('pipeline.create', 'pipeline.view_own', 'pipeline.claim', 'pipeline.act');

-- 2b. Membership (if section 1 was skipped).
-- DELETE ur FROM `user_roles` ur
--   JOIN `roles` r ON r.`id` = ur.`role_id`
--  WHERE r.`name` = 'hardware_carrier';

-- 2c. The role.
-- DELETE FROM `roles` WHERE `name` = 'hardware_carrier' AND `is_system` = 0;

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. After section 1: the role still exists, with no members.
-- 2. After section 2: no rows at all.
SELECT `id`, `name`, `is_system` FROM `roles` WHERE `name` = 'hardware_carrier';

SELECT COUNT(*) AS remaining_members
  FROM `user_roles` ur
  JOIN `roles` r ON r.`id` = ur.`role_id`
 WHERE r.`name` = 'hardware_carrier';

SELECT COUNT(*) AS remaining_grants
  FROM `role_permissions` rp
  JOIN `roles` r ON r.`id` = rp.`role_id`
 WHERE r.`name` = 'hardware_carrier';
