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
     * Valid component types
     */
    public static function getValidComponentTypes()
    {
        return ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'chassis', 'pciecard', 'hbacard'];
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
