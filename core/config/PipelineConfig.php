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
}
