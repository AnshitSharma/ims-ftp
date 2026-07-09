-- ============================================================
-- Seeder : 2026_06_18_001_create-pipeline-tables
-- Date   : 2026-06-18
-- Purpose: Turn the ticketing system into a configurable, multi-stage
--          pipeline. Admins define pipeline TYPES, each with an ordered
--          list of STAGES that carry a default owner (a user or a role).
--          A pipeline instance reuses the existing `tickets` row; its
--          live per-stage state lives in `ticket_stage_progress`.
-- Tables : pipeline_templates (NEW), pipeline_stages (NEW),
--          ticket_stage_progress (NEW), tickets (2 columns ADDED)
-- Notes  : Idempotent / safe to re-run.
--            * CREATE TABLE IF NOT EXISTS for the three new tables.
--            * The two `tickets` columns are added only when missing,
--              via a dynamic-SQL guard (works on MariaDB 10.6 which has
--              no `ADD COLUMN IF NOT EXISTS` for older clients).
--            * Logical FKs only (no hard constraints) to match the
--              existing schema style and keep the seeder re-runnable.
-- Feature: Pipeline / Workflow Ticketing.
-- ============================================================

-- ------------------------------------------------------------
-- 1. pipeline_templates — a named pipeline TYPE (e.g. "RAM Upgrade")
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pipeline_templates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL COMMENT 'Display name, e.g. RAM Upgrade',
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = archived, cannot start new pipelines',
  `created_by` INT(6) UNSIGNED DEFAULT NULL COMMENT 'User who created the type',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pipeline_templates_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Pipeline type definitions';

-- ------------------------------------------------------------
-- 2. pipeline_stages — ordered stage templates for a pipeline type.
--    Each stage has a default owner: exactly one of default_assignee_user_id
--    or default_assignee_role_id (enforced in the application layer).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pipeline_stages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pipeline_template_id` INT(11) NOT NULL COMMENT 'FK (logical) -> pipeline_templates.id',
  `name` VARCHAR(120) NOT NULL COMMENT 'Stage name, e.g. Approval / Install / Verify',
  `position` INT(11) NOT NULL COMMENT '1-based order within the template',
  `default_assignee_user_id` INT(6) UNSIGNED DEFAULT NULL COMMENT 'Default owner (user) -> users.id',
  `default_assignee_role_id` INT(11) DEFAULT NULL COMMENT 'Default owner (role) -> roles.id',
  `instructions` TEXT DEFAULT NULL COMMENT 'What the owner should do at this stage',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pipeline_stages_position` (`pipeline_template_id`, `position`),
  KEY `idx_pipeline_stages_template` (`pipeline_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Ordered stage templates per pipeline type';

-- ------------------------------------------------------------
-- 3. ticket_stage_progress — live per-stage state of a running pipeline.
--    Stages are SNAPSHOTTED here at creation (name/position/owner copied)
--    so later edits to the template never corrupt in-flight pipelines.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_stage_progress` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK (logical) -> tickets.id',
  `stage_template_id` INT(11) DEFAULT NULL COMMENT 'Source stage (NULL if template later deleted)',
  `name` VARCHAR(120) NOT NULL COMMENT 'Snapshot of stage name',
  `position` INT(11) NOT NULL COMMENT 'Snapshot of stage order',
  `status` ENUM('pending','active','completed','skipped','rejected') NOT NULL DEFAULT 'pending',
  `assigned_to_user_id` INT(6) UNSIGNED DEFAULT NULL COMMENT 'Owner (user) for this instance',
  `assigned_to_role_id` INT(11) DEFAULT NULL COMMENT 'Owner (role) for this instance',
  `claimed_by_user_id` INT(6) UNSIGNED DEFAULT NULL COMMENT 'Who accepted a role-owned stage',
  `claimed_at` TIMESTAMP NULL DEFAULT NULL,
  `started_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When the stage became active',
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `completed_by_user_id` INT(6) UNSIGNED DEFAULT NULL,
  `notes` TEXT DEFAULT NULL COMMENT 'What the actor did at this stage',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tsp_ticket_position` (`ticket_id`, `position`),
  KEY `idx_tsp_ticket` (`ticket_id`),
  KEY `idx_tsp_status` (`status`),
  KEY `idx_tsp_role` (`assigned_to_role_id`),
  KEY `idx_tsp_user` (`assigned_to_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Live per-stage state of running pipelines';

-- ------------------------------------------------------------
-- 4. tickets — add two columns that turn a ticket into a pipeline instance.
--    `pipeline_template_id`     : set => this ticket is a pipeline.
--    `current_stage_progress_id`: the active ticket_stage_progress row.
--    Added defensively (only if missing) so the seeder is re-runnable.
-- ------------------------------------------------------------
SET @add_tpl := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets'
    AND COLUMN_NAME = 'pipeline_template_id'
);
SET @sql_tpl := IF(@add_tpl = 0,
  'ALTER TABLE `tickets` ADD COLUMN `pipeline_template_id` INT(11) DEFAULT NULL COMMENT ''Set => ticket is a pipeline instance (logical FK -> pipeline_templates.id)'' AFTER `target_server_uuid`',
  'SELECT 1');
PREPARE stmt FROM @sql_tpl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_cur := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets'
    AND COLUMN_NAME = 'current_stage_progress_id'
);
SET @sql_cur := IF(@add_cur = 0,
  'ALTER TABLE `tickets` ADD COLUMN `current_stage_progress_id` INT(11) DEFAULT NULL COMMENT ''Active ticket_stage_progress row for this pipeline'' AFTER `pipeline_template_id`',
  'SELECT 1');
PREPARE stmt FROM @sql_cur; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add an index on the new template column (guarded for re-runs).
SET @add_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets'
    AND INDEX_NAME = 'idx_tickets_pipeline_template'
);
SET @sql_idx := IF(@add_idx = 0,
  'ALTER TABLE `tickets` ADD KEY `idx_tickets_pipeline_template` (`pipeline_template_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- Verification (optional, run after the seeder):
--
--   SHOW TABLES LIKE 'pipeline%';
--   SHOW TABLES LIKE 'ticket_stage_progress';
--   SHOW COLUMNS FROM `tickets` LIKE 'pipeline_template_id';
--   SHOW COLUMNS FROM `tickets` LIKE 'current_stage_progress_id';
--
-- Expected: pipeline_templates, pipeline_stages, ticket_stage_progress
-- tables exist; tickets has the two new nullable columns.
-- ============================================================
