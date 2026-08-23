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
     * Side effect: completing this stage PERFORMS the work the request asked
     * for, through RequestActionExecutor. effect_config shape:
     *   {"action_types": ["server.component.add", ...]}
     *
     * The list is a CEILING -- what this request type is ever allowed to do --
     * and it is snapshotted onto ticket_stage_progress when a request is
     * raised, so editing the type later cannot change what an in-flight request
     * will perform.
     */
    const EFFECT_EXECUTE_REQUEST = 'execute_request';

    /**
     * RETIRED 2026-08-23. Completing the stage used to GRANT the requester a
     * set of permissions for 24 hours so they could do the job themselves;
     * approval now does the job instead, and no authority moves.
     *
     * The constant survives its own retirement on purpose. Requests raised
     * before the change carry this value in their ticket_stage_progress
     * snapshot, and applyStageEffect() has to be able to RECOGNISE it in order
     * to treat it as a no-op. Without the name, a legacy request would hit the
     * unknown-effect branch and could never be completed at all -- stuck
     * forever, for having been raised on the wrong day.
     *
     * It is deliberately absent from getStageEffectTypes(), so nothing new can
     * ever be authored with it.
     */
    const EFFECT_GRANT_TEMPORARY_PERMISSION = 'grant_temporary_permission';

    /**
     * Effects a stage may carry (pipeline_stages.effect_type). A stage with no
     * effect_type is pure status tracking, which is what every stage was before
     * 2026_08_20_002 and what most stages still are.
     */
    public static function getStageEffectTypes()
    {
        return [self::EFFECT_EXECUTE_REQUEST];
    }

    /**
     * Effect types that still exist in stored snapshots but can no longer be
     * authored or performed. Completing such a stage does nothing and says so,
     * rather than failing -- fail OPEN for completion, CLOSED for privilege:
     * nothing is granted either way.
     */
    public static function getRetiredEffectTypes()
    {
        return [self::EFFECT_GRANT_TEMPORARY_PERMISSION];
    }
}
