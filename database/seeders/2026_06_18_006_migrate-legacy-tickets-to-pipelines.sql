-- ============================================================
-- Seeder : 2026_06_18_006_migrate-legacy-tickets-to-pipelines
-- Date   : 2026-06-18
-- Purpose: Migrate every pre-existing linear ticket (pipeline_template_id IS
--          NULL) onto the unified "Requests" model. Each legacy ticket is
--          attached to the built-in "General Request" type and gets a
--          snapshot of its three steps (Approval -> Execution -> Deployment)
--          in ticket_stage_progress, with step states derived from the old
--          ticket status. The ticket's own status is normalized to a valid
--          pipeline lifecycle value (draft/pending/approved/deployed are not
--          lifecycle statuses in the unified engine).
--
--          Old status            -> lifecycle  | step states (1=Approval,2=Execution,3=Deployment)
--          --------------------- | ----------- | -------------------------------------------------
--          draft / pending       -> in_progress| 1 active,    2 pending,   3 pending
--          approved / in_progress-> in_progress| 1 completed, 2 active,    3 pending
--          deployed              -> in_progress| 1 completed, 2 completed, 3 active
--          completed             -> completed  | 1-3 completed
--          rejected              -> rejected   | 1 rejected,  2 pending,   3 pending
--          cancelled             -> cancelled  | 1-3 skipped
--
-- Tables : ticket_stage_progress (INSERT), tickets (UPDATE)
-- Depends: 2026_06_18_001 (pipeline tables), 2026_06_18_004 (is_system),
--          2026_06_18_005 (General Request type + steps)
-- Notes  : Idempotent / safe to re-run. Only touches tickets that have no
--          template yet AND no existing stage-progress rows, so re-running
--          is a no-op. Step ownership is the super_admin role (matches the
--          super_admin-gated rollout); per-stage owners can be reassigned
--          later. Best-effort interpretation of a handful of legacy rows.
-- Feature: Unify Tickets + Pipelines into "Requests".
-- ============================================================

-- Resolve the General Request type id (set by seeder 005).
SET @tpl := (SELECT `id` FROM `pipeline_templates` WHERE `name` = 'General Request' AND `is_system` = 1 LIMIT 1);

-- ------------------------------------------------------------
-- 1. Snapshot the General Request steps onto each legacy ticket.
--    Step state is derived from the old ticket status (see table above).
-- ------------------------------------------------------------
INSERT INTO `ticket_stage_progress`
  (`ticket_id`, `stage_template_id`, `name`, `position`, `status`,
   `assigned_to_role_id`, `created_at`)
SELECT
  t.`id`,
  s.`id`,
  s.`name`,
  s.`position`,
  CASE
    WHEN t.`status` IN ('draft','pending') THEN
         CASE WHEN s.`position` = 1 THEN 'active' ELSE 'pending' END
    WHEN t.`status` IN ('approved','in_progress') THEN
         CASE WHEN s.`position` = 1 THEN 'completed'
              WHEN s.`position` = 2 THEN 'active'
              ELSE 'pending' END
    WHEN t.`status` = 'deployed' THEN
         CASE WHEN s.`position` = 3 THEN 'active' ELSE 'completed' END
    WHEN t.`status` = 'completed' THEN 'completed'
    WHEN t.`status` = 'rejected' THEN
         CASE WHEN s.`position` = 1 THEN 'rejected' ELSE 'pending' END
    WHEN t.`status` = 'cancelled' THEN 'skipped'
    ELSE CASE WHEN s.`position` = 1 THEN 'active' ELSE 'pending' END
  END,
  s.`default_assignee_role_id`,
  NOW()
FROM `tickets` t
JOIN `pipeline_stages` s ON s.`pipeline_template_id` = @tpl
WHERE @tpl IS NOT NULL
  AND t.`pipeline_template_id` IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM `ticket_stage_progress` sp WHERE sp.`ticket_id` = t.`id`
  );

-- ------------------------------------------------------------
-- 2. Backfill step timestamps on the rows just created (legacy tickets only,
--    i.e. those still without a template at this point in the run).
-- ------------------------------------------------------------
UPDATE `ticket_stage_progress` sp
JOIN `tickets` t ON t.`id` = sp.`ticket_id`
SET sp.`started_at` = t.`created_at`
WHERE t.`pipeline_template_id` IS NULL
  AND sp.`status` IN ('active','completed','rejected')
  AND sp.`started_at` IS NULL;

UPDATE `ticket_stage_progress` sp
JOIN `tickets` t ON t.`id` = sp.`ticket_id`
SET sp.`completed_at` = t.`updated_at`
WHERE t.`pipeline_template_id` IS NULL
  AND sp.`status` = 'completed'
  AND sp.`completed_at` IS NULL;

-- ------------------------------------------------------------
-- 3. Flip each legacy ticket into a pipeline instance: attach the type,
--    point at the active step, and normalize the lifecycle status.
--    (status is assigned LAST so every CASE reads the original value.)
-- ------------------------------------------------------------
UPDATE `tickets` t
LEFT JOIN `ticket_stage_progress` active
       ON active.`ticket_id` = t.`id` AND active.`status` = 'active'
SET
  t.`current_stage_progress_id` = active.`id`,
  t.`pipeline_template_id` = @tpl,
  t.`completed_at` = CASE
      WHEN t.`status` = 'completed' THEN COALESCE(t.`completed_at`, t.`updated_at`)
      ELSE t.`completed_at`
    END,
  t.`status` = CASE
      WHEN t.`status` IN ('draft','pending','approved','in_progress','deployed') THEN 'in_progress'
      ELSE t.`status`   -- completed / rejected / cancelled are already valid lifecycle values
    END
WHERE @tpl IS NOT NULL
  AND t.`pipeline_template_id` IS NULL;

-- ============================================================
-- Verification (optional):
--   SELECT id, ticket_number, status, pipeline_template_id, current_stage_progress_id
--     FROM tickets ORDER BY id;
--   SELECT ticket_id, position, name, status FROM ticket_stage_progress ORDER BY ticket_id, position;
-- Expected: no ticket has a NULL pipeline_template_id; every non-terminal
-- ticket points current_stage_progress_id at its 'active' step.
-- ============================================================
