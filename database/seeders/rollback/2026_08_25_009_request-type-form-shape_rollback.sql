-- =============================================================================
-- ROLLBACK for 2026_08_25_009_request-type-form-shape.sql
-- Date:     2026-08-25
-- Purpose:  Remove pipeline_templates.asks_for_server / .asks_for_components.
--
-- Tables:   pipeline_templates (2 columns DROPPED)
--
-- ============================ READ THIS FIRST ================================
--
-- THIS LOSES ONLY A PRESENTATION SETTING. No request, no step and no permission
-- depends on these columns: they decide which two optional questions a create
-- form asks. Dropping them puts every type back to asking both, which is what
-- the form did before 009.
--
-- THE CODE SURVIVES THE DROP, BY DESIGN.
--   PipelineTemplateManager reads them through SchemaHelper::hasColumn() (SHOW
--   COLUMNS -- no information_schema grant needed) and reports 1/1 when they are
--   absent. So the PHP does not have to be reverted first, the Request Types
--   editor simply stops showing the two checkboxes, and nothing 500s in between.
--
-- SECTION 1 IS THE SAFE STOP. To put every type back to asking both questions
--   WITHOUT losing the columns, run section 1 and stop. Section 2 is the
--   destructive half and is deliberately separate.
--
-- No information_schema here either: MariaDB 10.11's native DROP ... IF EXISTS
-- makes both statements idempotent without it.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 0. Before-state. THIS IS THE ONLY RECORD OF WHAT WAS SET -- keep the output.
--    Any type showing a 0 is about to go back to asking that question.
-- ---------------------------------------------------------------------------
SELECT `id`, `name`, `asks_for_server`, `asks_for_components`
  FROM `pipeline_templates`
 WHERE `asks_for_server` = 0 OR `asks_for_components` = 0
 ORDER BY `name`;

-- ---------------------------------------------------------------------------
-- 1. OPTIONAL SAFE STOP -- every type asks both questions again, columns kept.
--    Uncomment only if this is what you want; the SELECT above is then your
--    only copy of what was cleared.
-- ---------------------------------------------------------------------------
-- UPDATE `pipeline_templates`
--    SET `asks_for_server` = 1, `asks_for_components` = 1, `updated_at` = NOW()
--  WHERE `asks_for_server` = 0 OR `asks_for_components` = 0;

-- ---------------------------------------------------------------------------
-- 2. Drop both columns. No index is built on either, so order does not matter.
-- ---------------------------------------------------------------------------
ALTER TABLE `pipeline_templates` DROP COLUMN IF EXISTS `asks_for_components`;

ALTER TABLE `pipeline_templates` DROP COLUMN IF EXISTS `asks_for_server`;

-- ---------------------------------------------------------------------------
-- 3. After-state. Both SHOWs must return 0 rows.
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `pipeline_templates` LIKE 'asks_for_server';
SHOW COLUMNS FROM `pipeline_templates` LIKE 'asks_for_components';
