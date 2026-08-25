-- =============================================================================
-- Date:     2026-08-25
-- Purpose:  Let a Request be raised INSIDE another Request as a prerequisite,
--           and freeze the parent until that prerequisite is resolved.
--
--           A tech receives a delivery and raises "Add Inventory Record", then
--           realises he needs physical access to the inventory room first. He
--           raises "Inventory Room Access" as a CHILD of that request. The
--           parent cannot advance until the child is resolved; when it is, the
--           parent's own approval (a separate human decision) performs the work.
--
-- Tables:   tickets (1 column ADDED, 1 index ADDED)
-- Feature:  Child Requests / prerequisite blocking
--
-- ============================ READ THIS FIRST ================================
--
-- NO information_schema ANYWHERE IN THIS FILE, DELIBERATELY.
--
--   The production DB user has no grant on information_schema -- reading it
--   fails outright with #1044, not with an empty result. Every earlier seeder
--   that guards DDL with a `SELECT ... FROM information_schema.COLUMNS` +
--   PREPARE dance (2026_06_18_001, 2026_08_20_002, 2026_08_22_006 and a dozen
--   more) therefore aborts on its first guard when run by the account that
--   actually applies it. Confirmed on 2026-08-25: this seeder's first version
--   used that pattern and died with #1044 on statement one.
--
--   And if the error is ever SKIPPED rather than fatal (mysql --force, or a
--   client that continues past errors), the guard FAILS OPEN: @var is NULL,
--   `NULL = 0` is NULL, and IF(NULL, ddl, 'SELECT 1') picks 'SELECT 1'. The
--   seeder then reports success and changes nothing -- the worse of the two
--   outcomes, because it looks applied.
--
--   This server is MariaDB 10.11, which has native ALTER TABLE ... IF NOT
--   EXISTS (10.0.2+). It needs no privilege beyond ALTER on this table, is
--   genuinely idempotent, and cannot fail open. Use this pattern for new
--   seeders instead.
--
-- "BLOCKED" IS DERIVED, NOT STORED. THAT IS THE WHOLE DESIGN.
--
--   There is no `is_blocked` column and no new `tickets.status` value. Being
--   blocked IS "having an open blocking child", so a stored flag would be a
--   second truth that can drift out of step with the child rows -- and a
--   `blocked` status would collide with every terminal-status check in
--   PipelineConfig, PipelineManager and the frontend, all of which enumerate
--   the lifecycle explicitly.
--
--   PipelineManager::blockingChildren() reads it live instead. A child in
--   status draft or in_progress blocks its parent; so does a REJECTED child,
--   because a refused prerequisite must never read as a met one. completed
--   (met) and cancelled (withdrawn) release the parent.
--
-- ONLY DIRECT CHILDREN ARE EVER QUERIED
--   Transitivity falls out for free: a blocked child cannot complete, so it
--   stays open, so it keeps its own parent blocked. Nothing here or in the PHP
--   walks the tree downward, and no recursive CTE is needed.
--
-- WHY A LOGICAL FK AND NOT A REAL ONE
--   Matches the rest of this schema (pipeline_template_id, created_by,
--   current_stage_progress_id are all logical FKs) and keeps the seeder
--   re-runnable. A real self-referencing FK would also make the admin "unlink"
--   escape hatch -- which sets this column back to NULL -- needlessly fussy.
--
-- Idempotent: native IF NOT EXISTS on both statements. Re-running is a no-op
--             that emits a note, not an error.
-- Rollback:   rollback/2026_08_25_007_request-parent-child_rollback.sql
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. Keep this output.
--    Expect: 0 rows from each SHOW if this has not been applied yet.
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `tickets` LIKE 'parent_ticket_id';
SHOW INDEX FROM `tickets` WHERE `Key_name` = 'idx_tickets_parent_status';

-- ---------------------------------------------------------------------------
-- 1. tickets.parent_ticket_id -- the request this one is a prerequisite for.
--
--    NULL (the default, and the value on every existing row) = top-level.
--    Typed INT(10) UNSIGNED to match tickets.id exactly.
-- ---------------------------------------------------------------------------
ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `parent_ticket_id` INT(10) UNSIGNED DEFAULT NULL
      COMMENT 'This request is a prerequisite OF that one (logical FK -> tickets.id). NULL = top-level.'
      AFTER `current_stage_progress_id`;

-- ---------------------------------------------------------------------------
-- 2. Composite index on (parent_ticket_id, status).
--
--    The hot query is the block probe, run on EVERY step completion:
--      SELECT ... WHERE parent_ticket_id = ? AND status IN ('draft','in_progress','rejected')
--    One composite index serves that and the children listing in getPipeline().
--    A single-column index on parent_ticket_id would be redundant with this
--    one (leftmost prefix), so it is deliberately not added.
-- ---------------------------------------------------------------------------
ALTER TABLE `tickets`
  ADD KEY IF NOT EXISTS `idx_tickets_parent_status` (`parent_ticket_id`, `status`);

-- ---------------------------------------------------------------------------
-- 3. After-state. THIS IS THE CHECK THAT MATTERS -- do not skip it.
--
--    Expected:
--      * one row from SHOW COLUMNS: Field=parent_ticket_id, Type=int(10) unsigned,
--        Null=YES, Default=NULL
--      * two rows from SHOW INDEX (Seq_in_index 1 = parent_ticket_id, 2 = status)
--      * total_requests = top_level, children = 0 on a first run: no existing
--        request is touched by this seeder.
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `tickets` LIKE 'parent_ticket_id';

SHOW INDEX FROM `tickets` WHERE `Key_name` = 'idx_tickets_parent_status';

SELECT COUNT(*)                                                     AS total_requests,
       SUM(CASE WHEN `parent_ticket_id` IS NULL     THEN 1 ELSE 0 END) AS top_level,
       SUM(CASE WHEN `parent_ticket_id` IS NOT NULL THEN 1 ELSE 0 END) AS children
  FROM `tickets`
 WHERE `pipeline_template_id` IS NOT NULL;
