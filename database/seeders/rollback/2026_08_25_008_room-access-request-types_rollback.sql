-- =============================================================================
-- ROLLBACK for 2026_08_25_008_room-access-request-types.sql
-- Date:     2026-08-25
-- Purpose:  Withdraw the "Inventory Room Access" and "Server Room Access"
--           Request Types.
--
-- Tables:   pipeline_templates (archived, or deleted if never used)
--           pipeline_stages    (deleted only when the type is deleted)
--
-- ARCHIVE FIRST, DELETE ONLY IF UNUSED.
--   tickets.pipeline_template_id points at the type. Deleting a type that has
--   requests raised against it leaves those requests without a type to resolve
--   -- the same reasoning as 2026_08_25_001, 2026_08_23_004 and 2026_08_22_004.
--   Section 1 archives unconditionally, which is enough to take both types out
--   of circulation: an inactive type is not offered by the create form and
--   cannot be used for a new request. Section 2 is OPTIONAL and self-guarding.
--
-- THIS DOES NOT UNFREEZE ANY PARENT REQUEST.
--   Archiving a type has no effect on requests already raised from it. A parent
--   frozen behind an open "Server Room Access" child stays frozen -- the child
--   still exists and is still open. Resolve the child (approve, reject or
--   cancel it) or detach it via the pipeline-unlink-child action. To remove the
--   freezing MECHANISM instead, that is
--   rollback/2026_08_25_007_request-parent-child_rollback.sql.
--
-- THIS GRANTS AND REVOKES NOTHING. Any physical access an admin arranged while
--   acting on one of these requests is a door, a badge or a key. It is not in
--   this database and is unaffected here. Reverse it in the real world if that
--   is what you meant to do.
--
-- Section 0's blocking_a_parent column reads tickets.parent_ticket_id and so
-- needs 2026_08_25_007 applied. If it is not, drop that one sub-select.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. Note requests_raised per type -- it decides whether
--    section 2 does anything, and blocking_a_parent warns about frozen parents.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`,
       (SELECT COUNT(*) FROM `tickets` k
         WHERE k.`pipeline_template_id` = t.`id`) AS requests_raised,
       (SELECT COUNT(*) FROM `tickets` k
         WHERE k.`pipeline_template_id` = t.`id`
           AND k.`parent_ticket_id` IS NOT NULL
           AND k.`status` IN ('draft', 'in_progress', 'rejected')) AS blocking_a_parent
  FROM `pipeline_templates` t
 WHERE t.`name` IN ('Inventory Room Access', 'Server Room Access')
 ORDER BY t.`name`;

-- ---------------------------------------------------------------------------
-- 1. Archive both. Always safe.
-- ---------------------------------------------------------------------------
UPDATE `pipeline_templates`
   SET `is_active`  = 0,
       `is_system`  = 0,
       `updated_at` = NOW()
 WHERE `name` IN ('Inventory Room Access', 'Server Room Access');

-- ---------------------------------------------------------------------------
-- 2. OPTIONAL hard delete -- fires per type, only where no request was ever
--    raised from it. Both statements are self-guarding, so running them when
--    requests DO exist changes nothing.
-- ---------------------------------------------------------------------------
DELETE s FROM `pipeline_stages` s
  JOIN `pipeline_templates` t ON t.`id` = s.`pipeline_template_id`
 WHERE t.`name` IN ('Inventory Room Access', 'Server Room Access')
   AND NOT EXISTS (SELECT 1 FROM `tickets` k WHERE k.`pipeline_template_id` = t.`id`);

DELETE t FROM `pipeline_templates` t
 WHERE t.`name` IN ('Inventory Room Access', 'Server Room Access')
   AND NOT EXISTS (SELECT 1 FROM `tickets` k WHERE k.`pipeline_template_id` = t.`id`);

-- ---------------------------------------------------------------------------
-- 3. After-state. Expect two rows with is_active = 0, fewer rows, or none.
-- ---------------------------------------------------------------------------
SELECT t.`id`, t.`name`, t.`is_active`,
       (SELECT COUNT(*) FROM `pipeline_stages` s WHERE s.`pipeline_template_id` = t.`id`) AS stages
  FROM `pipeline_templates` t
 WHERE t.`name` IN ('Inventory Room Access', 'Server Room Access')
 ORDER BY t.`name`;
