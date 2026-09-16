<?php
/**
 * WorkflowConfig.php
 *
 * Configuration for ticketing workflow rules
 * Externalizes status transitions and valid values for flexibility
 */

class WorkflowConfig
{
    /**
     * Valid ticket statuses
     */
    public static function getValidStatuses()
    {
        return ['draft', 'pending', 'approved', 'in_progress', 'deployed', 'completed', 'rejected', 'cancelled'];
    }

    /**
     * Valid priorities
     */
    public static function getValidPriorities()
    {
        return ['low', 'medium', 'high', 'urgent'];
    }

    /**
     * Valid component types.
     *
     * JSON-009: this used to be a hand-copied list, and it had DRIFTED -- it was missing
     * 'serverplatform', which has been a full component type since 2026-08-25 and has 67
     * stocked units. Three call sites gate on this list (RequestActionExecutor::validateShape,
     * ::stockCounts and TicketValidator), so a Request line item naming a serverplatform was
     * rejected as "Unknown component type" and its stock could not be counted. All three build
     * `{type}inventory` generically, so nothing else had to change for it to start working.
     *
     * Now delegates to the canonical constant in BaseFunctions rather than restating it.
     * defined() rather than a require: this class is loaded from contexts that have already
     * bootstrapped the helpers, and a hard require here would be a second definition. The
     * literal stays as the fallback so a context that somehow lacks the constant keeps today's
     * behaviour instead of returning an empty whitelist -- which would reject EVERY type and,
     * in stockCounts(), silently return null for all of them.
     */
    public static function getValidComponentTypes()
    {
        if (defined('VALID_COMPONENT_TYPES')) {
            return VALID_COMPONENT_TYPES;
        }

        return ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'chassis', 'pciecard', 'risercard', 'hbacard', 'sfp', 'serverplatform'];
    }

    /**
     * Valid item actions
     */
    public static function getValidActions()
    {
        return ['add', 'remove', 'replace'];
    }

    /**
     * Status transition rules
     * Format: current_status => [allowed_next_statuses]
     */
    public static function getStatusTransitions()
    {
        return [
            'draft' => ['pending', 'cancelled'],
            'pending' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['in_progress', 'cancelled'],
            'in_progress' => ['deployed', 'cancelled'],
            'deployed' => ['completed', 'cancelled'], // Deployed tickets should be verified then completed
            'completed' => [], // Terminal status
            'rejected' => ['draft'], // Can be reopened/edited
            'cancelled' => ['draft'] // Can be reopened/edited
        ];
    }
}
