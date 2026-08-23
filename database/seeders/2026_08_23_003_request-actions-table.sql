-- =============================================================================
-- Date:     2026-08-23
-- Purpose:  Give a Request an ordered list of ACTIONS to perform on approval.
--
--           This is the storage half of the model change: a Request stops asking
--           for a PERMISSION and starts describing WORK. When an admin approves,
--           PipelineManager::applyStageEffect() hands these rows to
--           RequestActionExecutor, which performs them through the same command
--           layer every other write goes through. The requester never gains a
--           permission.
--
-- Tables:   ticket_actions (CREATE)
-- Feature:  Requests as automation (Phase 8)
--
-- WHY A NEW TABLE AND NOT ticket_items
--   ticket_items already stores component_type / component_uuid / quantity /
--   action(add|remove|replace), which looks like a fit, and it is not:
--     * its component_type ENUM carries only 9 of the 11 types -- `risercard`
--       and `sfp` are missing, so a request naming either is rejected by MySQL
--       today. Inheriting that enum would inherit the bug.
--     * it has no serial/unit column, and
--       ServerBuilder::updateComponentStatusAndServerUuid() FAILS CLOSED when
--       given a bare UUID for a model with more than one unit in stock.
--     * half the new actions are not component-shaped at all (rename a server,
--       change its status, create a configuration).
--     * its 26 live rows mean "components mentioned on a request", display-only.
--       Repurposing them would silently reinterpret existing history as work to
--       be performed.
--   A JSON payload sidesteps all four. The action type is a VARCHAR rather than
--   an ENUM for the same reason: the registry lives in
--   RequestActionExecutor::ACTION_TYPES, which fails closed on anything it does
--   not recognise, so the database does not need a second copy that can drift.
--
-- DEPLOY ORDER IS SAFE EITHER WAY. Every read of this table sits behind
-- SchemaHelper::hasColumn(), so the code deployed ~20s after save simply sees no
-- actions until this seeder is applied by hand -- an approval then behaves
-- exactly as it did before, performing nothing.
--
-- Notes:    - No FOREIGN KEY on ticket_id, matching pipeline_templates /
--             ticket_stage_progress, which are logical FKs by house convention
--             (see 2026_06_18_001). ticket_items DOES have one; the pipeline
--             tables deliberately do not, because a request outlives its type.
--           - `position` is 1-based and UNIQUE per ticket: actions run in order,
--             inside one transaction, and a gap or a duplicate would make
--             "which ran first" unanswerable after the fact.
--           - `result` holds JSON on success (what was created, the new
--             revision) and the engine's own error_code + message on failure.
--             It is the approver's record of what actually happened.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS. Re-running is a no-op.
-- Rollback:   rollback/2026_08_23_003_request-actions-table_rollback.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS `ticket_actions` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   int(10) UNSIGNED NOT NULL COMMENT 'Logical FK -> tickets.id (no constraint, by convention)',
  `position`    int(11)          NOT NULL COMMENT '1-based execution order',
  `action_type` varchar(48)      NOT NULL COMMENT 'RequestActionExecutor::ACTION_TYPES key',
  `payload`     text             NOT NULL COMMENT 'JSON parameters for the action',
  `status`      enum('pending','executed','failed') NOT NULL DEFAULT 'pending',
  `result`      text                      DEFAULT NULL COMMENT 'JSON: what happened, or the engine error',
  `executed_at` datetime                  DEFAULT NULL,
  `created_at`  timestamp        NOT NULL DEFAULT current_timestamp(),
  `updated_at`  timestamp        NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_actions_position` (`ticket_id`, `position`),
  KEY `idx_ticket_actions_ticket` (`ticket_id`),
  KEY `idx_ticket_actions_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Work an approved Request performs automatically';

-- =============================================================================
-- Verification
-- =============================================================================

-- 1. The table exists with all ten columns.
SHOW COLUMNS FROM `ticket_actions`;

-- 2. Both indexes and the uniqueness guarantee are in place.
SHOW INDEX FROM `ticket_actions`;

-- 3. Empty on a fresh install. MUST return 0.
SELECT COUNT(*) AS action_rows FROM `ticket_actions`;
