-- =============================================================================
-- Rollback for: 2026_08_20_002_pipeline-stage-effects.sql
-- Date:         2026-08-20
-- Tables:       pipeline_stages, ticket_stage_progress
--
-- Dropping these columns returns step completion to pure status tracking. Any
-- grants already issued survive in user_permissions and still expire normally --
-- rolling this back stops NEW grants, it does not revoke existing ones. To also
-- clear those, run 2026_08_20_001_..._rollback.sql (or just DELETE the rows with
-- source_ticket_id IS NOT NULL).
--
-- Run 2026_08_20_004_..._rollback.sql FIRST if the built-in "Temporary Server
-- Creation Access" request type is still present -- without its effect columns it
-- becomes a request type that approves but grants nothing, which is worse than
-- not having it.
-- =============================================================================

ALTER TABLE `ticket_stage_progress`
    DROP COLUMN IF EXISTS `effect_config`;

ALTER TABLE `ticket_stage_progress`
    DROP COLUMN IF EXISTS `effect_type`;

ALTER TABLE `pipeline_stages`
    DROP COLUMN IF EXISTS `effect_config`;

ALTER TABLE `pipeline_stages`
    DROP COLUMN IF EXISTS `effect_type`;

SHOW COLUMNS FROM `pipeline_stages`;
SHOW COLUMNS FROM `ticket_stage_progress`;
