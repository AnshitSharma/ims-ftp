-- =============================================================================
-- ROLLBACK for 2026_08_23_003_request-actions-table.sql
-- Date:     2026-08-23
-- Purpose:  Drop the ticket_actions table.
--
-- Tables:   ticket_actions (DROP)
-- Feature:  Requests as automation (Phase 8)
--
-- DESTRUCTIVE. This discards the record of what every approved Request
-- performed -- which action ran, what it created, and the engine's error on any
-- that failed. That history exists nowhere else: ticket_history records THAT an
-- approval executed actions, not WHICH ones or what came back.
--
-- Run this only when reverting the Phase 8 code as well. With the code still in
-- place, PipelineManager reads the table behind SchemaHelper::hasColumn(), so
-- dropping it does not break anything -- approvals simply stop performing work,
-- silently, which is the worst of both models. Revert the code first.
--
-- Take a backup, or snapshot the table, before running:
--   CREATE TABLE ticket_actions_backup_20260823 AS SELECT * FROM ticket_actions;
-- =============================================================================

-- What is about to be lost. Read this before continuing.
SELECT COUNT(*) AS actions_total,
       SUM(`status` = 'executed') AS executed,
       SUM(`status` = 'failed')   AS failed,
       SUM(`status` = 'pending')  AS still_pending,
       COUNT(DISTINCT `ticket_id`) AS requests_affected
  FROM `ticket_actions`;

DROP TABLE IF EXISTS `ticket_actions`;

-- =============================================================================
-- Verification -- MUST return an empty result set.
-- =============================================================================
SHOW TABLES LIKE 'ticket_actions';
