<?php
/**
 * PipelineConfig.php
 *
 * Configuration for the configurable, multi-stage pipeline ticketing system.
 * Centralises stage/lifecycle status values so handlers and models agree.
 *
 * A "pipeline" is a ticket (tickets.pipeline_template_id IS NOT NULL) whose
 * granular progress is tracked per-stage in ticket_stage_progress.
 *
 * @package BDC_IMS
 * @subpackage Pipelines
 */

class PipelineConfig
{
    /**
     * Per-stage statuses (ticket_stage_progress.status)
     */
    public static function getStageStatuses()
    {
        return ['pending', 'active', 'completed', 'skipped', 'rejected'];
    }

    /**
     * Pipeline lifecycle statuses (reuse a subset of the tickets.status enum).
     * The active stage tells you "where" the pipeline is; this is the overall state.
     */
    public static function getLifecycleStatuses()
    {
        return ['draft', 'in_progress', 'completed', 'rejected', 'cancelled'];
    }

    /**
     * Terminal lifecycle statuses — no further stage actions allowed.
     */
    public static function getTerminalStatuses()
    {
        return ['completed', 'rejected', 'cancelled'];
    }

    /**
     * Valid stage-owner types used by the API / overrides.
     */
    public static function getAssigneeTypes()
    {
        return ['user', 'role'];
    }

    /**
     * Side effect: completing this stage grants the REQUEST'S CREATOR a set of
     * permissions for a fixed window. effect_config shape:
     *   {"permissions": ["server.create", ...], "duration_hours": 24}
     */
    const EFFECT_GRANT_TEMPORARY_PERMISSION = 'grant_temporary_permission';

    /**
     * Effects a stage may carry (pipeline_stages.effect_type). A stage with no
     * effect_type is pure status tracking, which is what every stage was before
     * 2026_08_20_002 and what almost every stage still is.
     */
    public static function getStageEffectTypes()
    {
        return [self::EFFECT_GRANT_TEMPORARY_PERMISSION];
    }
}
