-- ============================================================
-- Seeder : 2026_06_18_005_seed-general-request-type
-- Date   : 2026-06-18
-- Purpose: Create the built-in "General Request" request type — the
--          default blueprint that reproduces the old linear ticket flow
--          as three ordered steps: Approval -> Execution -> Deployment.
--          Every step is owned by the super_admin role during the
--          super_admin-gated rollout (editable later in Request Types).
-- Tables : pipeline_templates (1 row), pipeline_stages (3 rows)
-- Depends: 2026_06_18_001 (pipeline tables), 2026_06_18_004 (is_system)
-- Notes  : Idempotent / safe to re-run. Guarded with NOT EXISTS on the
--          unique type name and the (template, position) of each step.
-- Feature: Unify Tickets + Pipelines into "Requests".
-- ============================================================

-- ------------------------------------------------------------
-- 1. The built-in type (is_system = 1 => protected from delete/rename/archive)
-- ------------------------------------------------------------
INSERT INTO `pipeline_templates` (`name`, `description`, `is_active`, `is_system`, `created_by`, `created_at`)
SELECT
  'General Request',
  'Built-in request type. Linear flow: Approval -> Execution -> Deployment. Cannot be deleted, renamed, or archived.',
  1, 1, NULL, NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `pipeline_templates` WHERE `name` = 'General Request'
);

-- Resolve the ids we need (re-run safe).
SET @tpl := (SELECT `id` FROM `pipeline_templates` WHERE `name` = 'General Request' LIMIT 1);
SET @owner_role := (SELECT `id` FROM `roles` WHERE `name` = 'super_admin' LIMIT 1);

-- ------------------------------------------------------------
-- 2. The three ordered steps, each owned by the super_admin role.
-- ------------------------------------------------------------
INSERT INTO `pipeline_stages`
  (`pipeline_template_id`, `name`, `position`, `default_assignee_role_id`, `instructions`, `created_at`)
SELECT @tpl, 'Approval', 1, @owner_role, 'Review the request and approve it to proceed.', NOW()
FROM DUAL
WHERE @tpl IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM `pipeline_stages` WHERE `pipeline_template_id` = @tpl AND `position` = 1
);

INSERT INTO `pipeline_stages`
  (`pipeline_template_id`, `name`, `position`, `default_assignee_role_id`, `instructions`, `created_at`)
SELECT @tpl, 'Execution', 2, @owner_role, 'Carry out the approved work.', NOW()
FROM DUAL
WHERE @tpl IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM `pipeline_stages` WHERE `pipeline_template_id` = @tpl AND `position` = 2
);

INSERT INTO `pipeline_stages`
  (`pipeline_template_id`, `name`, `position`, `default_assignee_role_id`, `instructions`, `created_at`)
SELECT @tpl, 'Deployment', 3, @owner_role, 'Deploy/finalize and close the request.', NOW()
FROM DUAL
WHERE @tpl IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM `pipeline_stages` WHERE `pipeline_template_id` = @tpl AND `position` = 3
);

-- ============================================================
-- Verification (optional):
--   SELECT id, name, is_system, is_active FROM pipeline_templates WHERE name = 'General Request';
--   SELECT name, position FROM pipeline_stages
--     WHERE pipeline_template_id = (SELECT id FROM pipeline_templates WHERE name='General Request')
--     ORDER BY position;
-- Expected: 1 type (is_system=1) + 3 steps (Approval/Execution/Deployment).
-- ============================================================
