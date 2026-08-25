-- =============================================================================
-- ROLLBACK for 2026_08_25_007_request-parent-child.sql
-- Date:     2026-08-25
-- Purpose:  Remove tickets.parent_ticket_id and its index.
--
-- Tables:   tickets (1 index DROPPED, 1 column DROPPED)
--
-- ============================ READ THIS FIRST ================================
--
-- DROPPING THE COLUMN DESTROYS EVERY PARENT/CHILD LINK. It cannot be recovered
-- from anywhere else: the link lives only in this column. The requests
-- themselves survive intact -- they simply become unrelated top-level requests
-- again, and every parent that was frozen unfreezes.
--
-- THE CODE SURVIVES THE DROP, BY DESIGN.
--   PipelineManager reads this column through SchemaHelper::hasColumn(), which
--   uses SHOW COLUMNS and needs no information_schema grant. With the column
--   gone it reports parent = null / children = [] / blocked = false and never
--   blocks anything, and raising a child is REFUSED with a clear message rather
--   than silently creating an unlinked request. So the PHP does not have to be
--   reverted first, and the site does not 500 between the two.
--
-- SECTION 1 IS THE SAFE STOP. If you only want to stop parents being frozen,
--   run section 1 and stop there -- the links are preserved and the feature is
--   inert. Section 2 is the destructive half and is deliberately separate.
--
-- No information_schema here either: MariaDB 10.11's native DROP ... IF EXISTS
-- makes both statements idempotent without it.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. THIS IS THE ONLY RECORD OF THE LINKS -- keep the output.
--    If child_links is greater than 0, section 2 is destroying real data.
-- ---------------------------------------------------------------------------
SELECT COUNT(*) AS child_links
  FROM `tickets`
 WHERE `parent_ticket_id` IS NOT NULL;

SELECT c.`id`            AS child_id,
       c.`ticket_number` AS child_number,
       c.`status`        AS child_status,
       p.`id`            AS parent_id,
       p.`ticket_number` AS parent_number,
       p.`status`        AS parent_status
  FROM `tickets` c
  JOIN `tickets` p ON p.`id` = c.`parent_ticket_id`
 ORDER BY p.`id`, c.`id`;

-- ---------------------------------------------------------------------------
-- 1. OPTIONAL SAFE STOP -- unfreeze every parent without losing the column.
--
--    Clearing the VALUES (rather than dropping the column) leaves the feature
--    installed and inert. Uncomment only if this is what you want; the
--    before-state SELECT above is then your only copy of what was cleared.
-- ---------------------------------------------------------------------------
-- UPDATE `tickets` SET `parent_ticket_id` = NULL, `updated_at` = NOW()
--  WHERE `parent_ticket_id` IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 2. Drop the index, then the column. Order matters: the index is built on the
--    column.
-- ---------------------------------------------------------------------------
ALTER TABLE `tickets` DROP KEY IF EXISTS `idx_tickets_parent_status`;

ALTER TABLE `tickets` DROP COLUMN IF EXISTS `parent_ticket_id`;

-- ---------------------------------------------------------------------------
-- 3. After-state. Both SHOWs must return 0 rows.
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `tickets` LIKE 'parent_ticket_id';
SHOW INDEX FROM `tickets` WHERE `Key_name` = 'idx_tickets_parent_status';
