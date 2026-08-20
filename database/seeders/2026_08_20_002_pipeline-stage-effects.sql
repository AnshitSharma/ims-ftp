-- =============================================================================
-- Date:     2026-08-20
-- Purpose:  Let a Request Step carry a SIDE EFFECT that fires when the step is
--           completed. Until now completing a step was pure status tracking --
--           PipelineManager::completeStage() wrote the step row, a history row
--           and the next step, and nothing else in the system reacted.
--
--           The first (and currently only) effect is
--           'grant_temporary_permission', which is what makes an "approval"
--           actually grant something.
--
-- Tables:   pipeline_stages          (2 new columns) -- the blueprint
--           ticket_stage_progress    (2 new columns) -- the per-request snapshot
-- Feature:  Temporary approval-gated server-build access (Requests module)
--
-- Notes:    - BOTH tables, on purpose. The engine SNAPSHOTS a step's definition
--             into ticket_stage_progress at creation time, and
--             PipelineTemplateManager::updateTemplate() does a full DELETE +
--             re-INSERT of pipeline_stages whenever a Request Type's steps are
--             edited -- which orphans stage_template_id. Reading the effect live
--             through that join would therefore be both unreliable AND unsafe:
--             editing a Request Type could silently change what an
--             already-submitted request is about to grant. The snapshot wins.
--           - effect_config is TEXT holding JSON, matching how the rest of this
--             schema stores structured blobs (ticket_items.component_specs).
--             For 'grant_temporary_permission' the shape is:
--               {"permissions":["server.create","server.view","server.edit"],
--                "duration_hours":24}
--             The permission list is additionally checked against a hard
--             whitelist in TemporaryAccessManager before anything is granted --
--             the JSON is never trusted on its own.
--           - NULL effect_type (the default, and every existing row) means
--             "no side effect", i.e. exactly today's behaviour. Both existing
--             Request Types are unaffected.
--           - No index: the columns are only ever read from a row already
--             fetched by primary key during completeStage().
--           - Idempotent via native ADD COLUMN IF NOT EXISTS (MariaDB 10.0.2+).
--             Deliberately NOT information_schema guards -- the application DB
--             user has no access to that schema on this host.
--           - Rollback: rollback/2026_08_20_002_pipeline-stage-effects_rollback.sql
-- =============================================================================

-- The blueprint: what a Request Type's step is configured to do.
ALTER TABLE `pipeline_stages`
    ADD COLUMN IF NOT EXISTS `effect_type` VARCHAR(40) NULL DEFAULT NULL
        COMMENT 'NULL = status tracking only. Known: grant_temporary_permission'
        AFTER `instructions`;

ALTER TABLE `pipeline_stages`
    ADD COLUMN IF NOT EXISTS `effect_config` TEXT NULL DEFAULT NULL
        COMMENT 'JSON parameters for effect_type; ignored when effect_type IS NULL'
        AFTER `effect_type`;

-- The snapshot: what THIS request's step will actually do, frozen at creation.
ALTER TABLE `ticket_stage_progress`
    ADD COLUMN IF NOT EXISTS `effect_type` VARCHAR(40) NULL DEFAULT NULL
        COMMENT 'Snapshot of pipeline_stages.effect_type taken when the request was created'
        AFTER `notes`;

ALTER TABLE `ticket_stage_progress`
    ADD COLUMN IF NOT EXISTS `effect_config` TEXT NULL DEFAULT NULL
        COMMENT 'Snapshot of pipeline_stages.effect_config taken when the request was created'
        AFTER `effect_type`;

-- ---------------------------------------------------------------------------
-- Verification (SHOW needs no information_schema privilege)
-- ---------------------------------------------------------------------------
SHOW COLUMNS FROM `pipeline_stages` LIKE 'effect\_%';
SHOW COLUMNS FROM `ticket_stage_progress` LIKE 'effect\_%';
