-- =============================================================================
-- ROLLBACK for 2026_08_26_007_hardware-handover-request-type.sql
-- Date:     2026-08-26
-- Purpose:  Withdraw the Hardware Handover request type.
--
-- Tables:   pipeline_templates (1 row), pipeline_stages (2 rows)
--
-- ============================ READ THIS FIRST ================================
--
-- WITHDRAWING THIS TYPE DOES NOT UNDO ANY MOVE IT PERFORMED. Every handover
--   already approved has already changed where the system believes that part
--   is, and has written a component_movements row. Those stay. This file only
--   stops NEW handover requests from being raised.
--
-- IT ALSO STRANDS ANY OPEN INSTALL REQUEST THAT WAS WAITING ON A HANDOVER.
--   A parent request is frozen while a child is draft or in_progress
--   (PipelineManager::blockingChildren()). Deleting the child's TYPE does not
--   delete the child, so the freeze remains -- correctly. But if you delete the
--   children too (section 2), the parents unfreeze and can be approved with the
--   part still at the wrong site. Section 0 lists exactly which requests those
--   are. Deal with them before section 2, not after.
--
-- SECTION 1 IS THE SAFE STOP -- deactivate. The type vanishes from the create
--   form, every request already raised from it keeps working to completion, and
--   history stays readable. Prefer it. Section 2 is the destructive half.
--
-- The `inventory.component.relocate` action itself lives in the CODE
--   (RequestActionExecutor::ACTION_TYPES) and is unaffected by this file. An
--   admin can still build another type that uses it.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. KEEP THIS OUTPUT.
-- ---------------------------------------------------------------------------

-- 0a. The type and its steps.
SELECT t.`id`, t.`name`, t.`is_active`, s.`position`, s.`name` AS step, s.`effect_type`
  FROM `pipeline_templates` t
  LEFT JOIN `pipeline_stages` s ON s.`pipeline_template_id` = t.`id`
 WHERE t.`name` = 'Hardware Handover'
 ORDER BY s.`position`;

-- 0b. Every request ever raised from it, and its status.
SELECT k.`id`, k.`ticket_number`, k.`status`, k.`parent_ticket_id`, k.`created_by`, k.`created_at`
  FROM `tickets` k
  JOIN `pipeline_templates` t ON t.`id` = k.`pipeline_template_id`
 WHERE t.`name` = 'Hardware Handover'
 ORDER BY k.`id`;

-- 0c. THE ONE THAT MATTERS: parents that are currently frozen behind an open
--     handover. Deleting those children in section 2 unfreezes these.
SELECT p.`id` AS parent_id, p.`ticket_number` AS parent_number, p.`status` AS parent_status,
       c.`id` AS child_id, c.`ticket_number` AS child_number, c.`status` AS child_status
  FROM `tickets` c
  JOIN `pipeline_templates` t ON t.`id` = c.`pipeline_template_id`
  JOIN `tickets` p            ON p.`id` = c.`parent_ticket_id`
 WHERE t.`name` = 'Hardware Handover'
   AND c.`status` IN ('draft', 'in_progress', 'rejected');

-- 0d. Moves already performed through this type. These are NOT undone.
SELECT m.`id`, m.`component_type`, m.`inventory_id`, m.`serial_number`,
       m.`from_location_name`, m.`to_location_name`, m.`moved_at`, m.`ticket_id`
  FROM `component_movements` m
  JOIN `tickets` k            ON k.`id` = m.`ticket_id`
  JOIN `pipeline_templates` t ON t.`id` = k.`pipeline_template_id`
 WHERE t.`name` = 'Hardware Handover'
 ORDER BY m.`moved_at`;

-- ---------------------------------------------------------------------------
-- 1. SAFE STOP -- deactivate. No new handovers can be raised; everything
--    already in flight finishes normally. Reversible with is_active = 1.
-- ---------------------------------------------------------------------------
-- UPDATE `pipeline_templates`
--    SET `is_active` = 0, `updated_at` = NOW()
--  WHERE `name` = 'Hardware Handover';

-- ---------------------------------------------------------------------------
-- 2. DESTRUCTIVE -- delete the type outright.
--    Run 2a ONLY if section 0b returned no rows, or you have dealt with every
--    request it listed. Deleting a type that has requests is refused by the FK
--    if one exists, and otherwise leaves those requests pointing at nothing.
--    Read section 0c again before uncommenting anything here.
-- ---------------------------------------------------------------------------
-- 2a. The steps first.
-- DELETE s FROM `pipeline_stages` s
--   JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
--  WHERE t.`name` = 'Hardware Handover';

-- 2b. Then the type.
-- DELETE FROM `pipeline_templates` WHERE `name` = 'Hardware Handover';

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. After section 1: one row, is_active = 0.
-- 2. After section 2: no rows at all.
SELECT `id`, `name`, `is_active` FROM `pipeline_templates` WHERE `name` = 'Hardware Handover';

-- 3. After section 2: no orphaned steps.
SELECT COUNT(*) AS remaining_steps
  FROM `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
 WHERE t.`name` = 'Hardware Handover';
