-- =============================================================================
-- ROLLBACK for 2026_08_26_005_move-server-request-type.sql
-- Date:     2026-08-26
-- Purpose:  Withdraw the "Move Server" Request Type.
--
-- Tables:   pipeline_templates (1 row ARCHIVED), pipeline_stages (1 row)
--
-- ============================ READ THIS FIRST ================================
--
-- ARCHIVE, DO NOT DELETE. `tickets` rows point at this template via
--   pipeline_template_id, and pipeline_templates has no foreign key to stop a
--   delete from orphaning them. Deleting the row would leave every move request
--   ever raised unable to name its own type -- the same reasoning that made
--   2026_08_22_005 archive the four access types rather than remove them.
--   is_active is read only when a request is CREATED, so archiving stops new
--   ones while every in-flight request still advances and still performs its
--   move on approval.
--
-- THIS ROLLBACK REVOKES NOTHING, because the type never granted anything. It
--   performs work on approval (effect_type = execute_request); no requester
--   ever held rack.assign. Archiving simply removes the route.
--
-- MOVES ALREADY PERFORMED ARE NOT UNDONE. A server relocated through an
--   approved request stays where it was moved to, and its server_movements row
--   stays on file. If a specific move needs reversing, move the server back --
--   that is a second move, correctly recorded as one.
--
-- SECTION 2 IS THE DESTRUCTIVE HALF and is only safe when no request was ever
--   raised from this type. Section 0 tells you whether that is true.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. requests_raised > 0 means section 2 is NOT safe.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`, t.`is_system`,
       (SELECT COUNT(*) FROM `tickets` k
         WHERE k.`pipeline_template_id` = t.`id`) AS requests_raised,
       (SELECT COUNT(*) FROM `tickets` k
         WHERE k.`pipeline_template_id` = t.`id`
           AND k.`status` IN ('draft', 'in_progress')) AS requests_still_open
  FROM `pipeline_templates` t
 WHERE t.`name` = 'Move Server';

-- Moves that were actually performed through this route. These stay.
SELECT COUNT(*) AS moves_performed_via_requests
  FROM `server_movements` WHERE `ticket_id` IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 1. Archive. No new move request can be raised; open ones keep working.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_templates`
   SET `is_active`  = 0,
       `is_system`  = 0,
       `updated_at` = NOW()
 WHERE `name` = 'Move Server';

-- ---------------------------------------------------------------------------
-- 2. DESTRUCTIVE -- remove the type outright. ONLY when section 0 showed
--    requests_raised = 0. Uncomment both statements together; the stage must go
--    first or it is left parented to nothing.
-- ---------------------------------------------------------------------------
-- DELETE s FROM `pipeline_stages` s
-- JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
-- WHERE t.`name` = 'Move Server';
--
-- DELETE FROM `pipeline_templates`
--  WHERE `name` = 'Move Server'
--    AND NOT EXISTS (SELECT 1 FROM `tickets` k
--                     WHERE k.`pipeline_template_id` = `pipeline_templates`.`id`);

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. After section 1: the type is archived, not gone. Expect is_active = 0.
SELECT `id`, `name`, `is_active`, `is_system` FROM `pipeline_templates`
 WHERE `name` = 'Move Server';

-- 2. It no longer appears among the types a request can be raised from.
SELECT `name` FROM `pipeline_templates` WHERE `is_active` = 1 ORDER BY `name`;

-- 3. No request was orphaned -- every ticket still resolves its type name.
--    MUST return 0.
SELECT COUNT(*) AS orphaned_requests
  FROM `tickets` k
 WHERE k.`pipeline_template_id` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `pipeline_templates` t WHERE t.`id` = k.`pipeline_template_id`);
