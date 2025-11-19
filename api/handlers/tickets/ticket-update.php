<?php
/**
 * ticket-update.php
 *
 * Unified update endpoint - handles status changes, field updates, assignments
 * with dynamic permission checking based on the type of update
 *
 * Action: ticket-update
 * Method: POST
 * Permission: Dynamic based on update type
 *
 * Request Parameters:
 * - ticket_id (required): Ticket ID to update
 *
 * Optional parameters (at least one required):
 * - status: New status (draft, pending, approved, in_progress, deployed, completed, rejected, cancelled)
 * - title: New title (only for draft tickets)
 * - description: New description (only for draft tickets)
 * - priority: New priority (low, medium, high, urgent)
 * - assigned_to: User ID to assign ticket to
 * - rejection_reason: Required when status=rejected
 * - deployment_notes: Notes when deploying
 * - completion_notes: Notes when completing
 *
 * Status Transitions & Required Permissions:
 * - draft → pending: ticket.create
 * - pending → approved: ticket.approve (cannot approve own)
 * - pending → rejected: ticket.reject (rejection_reason required)
 * - approved → in_progress: ticket.deploy
 * - in_progress → deployed: ticket.deploy
 * - deployed → completed: ticket.complete
 * - any → cancelled: ticket.cancel
 *
 * Response:
 * {
 *   "success": true,
 *   "authenticated": true,
 *   "code": 200,
 *   "message": "Ticket updated successfully",
 *   "data": {
 *     "ticket": { ... }
 *   }
 * }
 *
 * @package BDC_IMS
 * @subpackage Ticketing
 */

require_once(__DIR__ . '/../../../core/models/tickets/TicketManager.php');
require_once(__DIR__ . '/../../../core/models/tickets/TicketValidator.php');

try {
    // Validate ticket_id
    $ticketId = $_POST['ticket_id'] ?? null;

    if (empty($ticketId) || !is_numeric($ticketId)) {
        send_json_response(false, true, 400, "ticket_id is required and must be numeric", null);
        exit;
    }

    $ticketId = (int)$ticketId;

    // Load ticket
    $ticketManager = new TicketManager($pdo);
    $ticket = $ticketManager->getTicketById($ticketId, false, false);

    if (!$ticket) {
        send_json_response(false, true, 404, "Ticket not found", null);
        exit;
    }

    // Determine what's being updated
    $updates = [];
    $extraData = [];
    $permissionNeeded = null;
    $validationErrors = [];

    // STATUS CHANGE
    if (isset($_POST['status']) && $_POST['status'] !== $ticket['status']) {
        $oldStatus = $ticket['status'];
        $newStatus = $_POST['status'];

        // Validate status transition
        $validator = new TicketValidator($pdo);
        $transitionValidation = $validator->validateStatusTransition($oldStatus, $newStatus);

        if (!$transitionValidation['valid']) {
            send_json_response(false, true, 400, $transitionValidation['error'], null);
            exit;
        }

        // Determine permission based on transition
        switch ($newStatus) {
            case 'pending':
                // draft → pending (submitting)
                $permissionNeeded = 'ticket.create';
                break;

            case 'approved':
                $permissionNeeded = 'ticket.approve';

                // Cannot approve own ticket (separation of duties)
                if ($ticket['created_by'] == $user_id) {
                    $validationErrors[] = "Cannot approve your own ticket (separation of duties)";
                }

                // Must be pending to approve
                if ($oldStatus !== 'pending') {
                    $validationErrors[] = "Can only approve pending tickets";
                }
                break;

            case 'rejected':
                $permissionNeeded = 'ticket.reject';

                // Rejection reason required
                if (empty($_POST['rejection_reason'])) {
                    $validationErrors[] = "rejection_reason is required when rejecting a ticket";
                } else {
                    $extraData['rejection_reason'] = $_POST['rejection_reason'];
                }
                break;

            case 'in_progress':
                $permissionNeeded = 'ticket.deploy';

                // Must be approved
                if ($oldStatus !== 'approved') {
                    $validationErrors[] = "Can only start work on approved tickets";
                }
                break;

            case 'deployed':
                $permissionNeeded = 'ticket.deploy';

                // Must be approved or in_progress
                if (!in_array($oldStatus, ['approved', 'in_progress'])) {
                    $validationErrors[] = "Can only deploy approved or in-progress tickets";
                }

                // Optional deployment notes
                if (!empty($_POST['deployment_notes'])) {
                    $extraData['deployment_notes'] = $_POST['deployment_notes'];
                }
                break;

            case 'completed':
                $permissionNeeded = 'ticket.complete';

                // Must be deployed
                if ($oldStatus !== 'deployed') {
                    $validationErrors[] = "Can only complete deployed tickets";
                }

                // Optional completion notes
                if (!empty($_POST['completion_notes'])) {
                    $extraData['completion_notes'] = $_POST['completion_notes'];
                }
                break;

            case 'cancelled':
                $permissionNeeded = 'ticket.cancel';
                break;

            default:
                $validationErrors[] = "Invalid status: $newStatus";
        }

        $updates['status'] = $newStatus;
    }

    // FIELD UPDATES (title, description, priority)
    if (isset($_POST['title']) || isset($_POST['description']) || isset($_POST['priority'])) {
        // Can only edit fields in draft status
        if ($ticket['status'] !== 'draft' && !$acl->hasPermission($user_id, 'ticket.manage')) {
            $validationErrors[] = "Can only edit title/description/priority when ticket is in draft status";
        }

        // Must own ticket or have manage permission
        if ($ticket['created_by'] != $user_id && !$acl->hasPermission($user_id, 'ticket.manage')) {
            $validationErrors[] = "Can only edit own tickets";
        }

        $permissionNeeded = $permissionNeeded ?? 'ticket.edit_own';

        if (isset($_POST['title'])) {
            $updates['title'] = $_POST['title'];
        }

        if (isset($_POST['description'])) {
            $updates['description'] = $_POST['description'];
        }

        if (isset($_POST['priority'])) {
            $updates['priority'] = $_POST['priority'];
        }
    }

    // ASSIGNMENT
    if (isset($_POST['assigned_to'])) {
        $permissionNeeded = $permissionNeeded ?? 'ticket.assign';

        // Check if user has assignment permission
        if (!$acl->hasPermission($user_id, 'ticket.assign') && !$acl->hasPermission($user_id, 'ticket.manage')) {
            $validationErrors[] = "You don't have permission to assign tickets";
        } else {
            $updates['assigned_to'] = $_POST['assigned_to'] ? (int)$_POST['assigned_to'] : null;
        }
    }

    // Check for validation errors
    if (!empty($validationErrors)) {
        send_json_response(false, true, 400, "Validation failed", [
            'errors' => $validationErrors
        ]);
        exit;
    }

    // Check if any updates were provided
    if (empty($updates) && empty($extraData)) {
        send_json_response(false, true, 400, "No updates provided", null);
        exit;
    }

    // Check permission (unless user has manage permission)
    if ($permissionNeeded && !$acl->hasPermission($user_id, 'ticket.manage')) {
        if (!$acl->hasPermission($user_id, $permissionNeeded)) {
            send_json_response(false, true, 403, "Permission denied", [
                'required_permission' => $permissionNeeded
            ]);
            exit;
        }
    }

    // Perform update
    $result = $ticketManager->updateTicket($ticketId, $user_id, $updates, $extraData);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to update ticket", [
            'errors' => $result['errors']
        ]);
        exit;
    }

    send_json_response(true, true, 200, "Ticket updated successfully", [
        'ticket' => $result['ticket']
    ]);

} catch (Exception $e) {
    error_log("ticket-update error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    send_json_response(false, true, 500, "Failed to update ticket", [
        'error' => $e->getMessage()
    ]);
}
