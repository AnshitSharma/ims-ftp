-- ============================================================================
-- Date:     2026-09-21
-- Purpose:  Index the three tables PipelineManager::listPipelines() drives four
--           correlated subqueries into, once per row. At limit=20 that is up to
--           80 subquery executions per page of the Requests list, on top of
--           seven LEFT JOINs.
--
--           The first of these is also load-bearing for the visibility fix that
--           shipped with it: 'mine' now tests involvement with an EXISTS over
--           ALL of a Request's ticket_stage_progress rows rather than only the
--           current one, so that a user who owns step 3 of a five-step Request
--           can find it in a list instead of only being able to open it by id.
--           That subquery is hotter than the one it replaced and wants the key.
-- Tables:   ticket_stage_progress, ticket_history, tickets
-- Feature:  Backend & database audit 2026-09-21, §8.2 #4/#5/#6 and §10.1 (Phase
--           C.1/C.2, Phase H.1).
-- ============================================================================
--
-- WHY EACH ONE
--   ticket_stage_progress (ticket_id, status)
--       listPipelines()'s stage_total and stage_done subqueries both count rows
--       for one ticket; getStageProgress() reads the same pair; and the new
--       involvement EXISTS filters on ticket_id before testing the assignee
--       columns. Leading column is ticket_id because every one of those starts
--       by naming a ticket.
--
--   ticket_history (ticket_id, action, created_at)
--       The last_execution_event subquery filters on exactly ticket_id + action
--       and orders by created_at. Column order matches: two equalities, then the
--       sort. This table is append-only and only grows.
--
--   tickets (parent_ticket_id)
--       The is_blocked EXISTS looks for children of each listed row. Runs once
--       per row and has no index today. parent_ticket_id arrived with seeder
--       2026_08_25_007; if that seeder has NOT been applied to this database the
--       third statement will fail on an unknown column, and the first two will
--       already have committed — they are independent, so just drop the third.
--
-- Idempotent via MariaDB's native ADD KEY IF NOT EXISTS. Safe to paste twice.
-- Verify with SHOW INDEX FROM ticket_stage_progress; and friends.

ALTER TABLE ticket_stage_progress
    ADD KEY IF NOT EXISTS ix_tsp_ticket_status (ticket_id, status);

ALTER TABLE ticket_history
    ADD KEY IF NOT EXISTS ix_th_ticket_action_created (ticket_id, action, created_at);

ALTER TABLE tickets
    ADD KEY IF NOT EXISTS ix_tickets_parent (parent_ticket_id);
