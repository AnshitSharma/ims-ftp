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
 * - assigned_to: User ID to assign ticket to (clears assigned_to_role)
 * - assigned_to_role: Role ID to assign ticket to (clears assigned_to)
 * - rejection_reason: Required when status=rejected
 * - deployment_notes: Notes when deploying
 * - completion_notes: Notes when completing
 *
 * Note: assigned_to and assigned_to_role are mutually exclusive
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
require_once(__DIR__ . '/../../../core/helpers/RequestHelper.php');

try {
    // === COMPREHENSIVE DEBUG LOGGING ===
    error_log("=== TICKET-UPDATE DEBUG START ===");
    error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
    error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'NOT SET'));
    error_log("Content-Length: " . ($_SERVER['CONTENT_LENGTH'] ?? 'NOT SET'));
    error_log("POST data: " . json_encode($_POST));
    error_log("GET data: " . json_encode($_GET));
    error_log("Raw input: " . file_get_contents('php://input'));
    error_log("POST keys: " . implode(', ', array_keys($_POST)));

    // Use RequestHelper to parse input
    $_POST = RequestHelper::parseRequestData();

    // Validate ticket_id (accept both POST and GET)
    $ticketId = $_POST['ticket_id'] ?? $_GET['ticket_id'] ?? null;

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

                // Cannot approve own ticket (separation of duties) - unless user has manage permission
                if ($ticket['created_by'] == $user_id && !$acl->hasPermission($user_id, 'ticket.manage')) {
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
                error_log("=== REJECTION REASON VALIDATION ===");
                error_log("isset(\$_POST['rejection_reason']): " . (isset($_POST['rejection_reason']) ? 'TRUE' : 'FALSE'));
                error_log("empty(\$_POST['rejection_reason']): " . (empty($_POST['rejection_reason']) ? 'TRUE' : 'FALSE'));

                if (isset($_POST['rejection_reason'])) {
                    $reason = $_POST['rejection_reason'];
                    error_log("rejection_reason VALUE: '" . $reason . "'");
                    error_log("rejection_reason LENGTH: " . strlen($reason));
                    error_log("rejection_reason TYPE: " . gettype($reason));
                    error_log("rejection_reason === '': " . ($reason === '' ? 'TRUE' : 'FALSE'));
                    error_log("rejection_reason === '0': " . ($reason === '0' ? 'TRUE' : 'FALSE'));
                    error_log("rejection_reason == false: " . ($reason == false ? 'TRUE' : 'FALSE'));
                } else {
                    error_log("rejection_reason is NOT SET in \$_POST");
                }

                if (empty($_POST['rejection_reason'])) {
                    error_log("VALIDATION FAILED: rejection_reason is empty");
                    $validationErrors[] = "rejection_reason is required when rejecting a ticket";
                } else {
                    error_log("VALIDATION PASSED: rejection_reason = '" . $_POST['rejection_reason'] . "'");
                    // Put in BOTH updates (for validator) and extraData (for DB write)
                    $updates['rejection_reason'] = $_POST['rejection_reason'];
                    $extraData['rejection_reason'] = $_POST['rejection_reason'];
                }
                error_log("=== END REJECTION REASON VALIDATION ===");
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
                    $updates['deployment_notes'] = $_POST['deployment_notes'];
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
                    $updates['completion_notes'] = $_POST['completion_notes'];
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

    // FIELD UPDATES (title, description, priority) - only if non-empty values provided
    $hasFieldUpdates = false;
    error_log("Checking field updates - title: " . (isset($_POST['title']) ? "'{$_POST['title']}'" : 'NOT SET') .
             ", description: " . (isset($_POST['description']) ? "'{$_POST['description']}'" : 'NOT SET') .
             ", priority: " . (isset($_POST['priority']) ? "'{$_POST['priority']}'" : 'NOT SET'));

    if (isset($_POST['title']) && $_POST['title'] !== '') {
        $hasFieldUpdates = true;
        $updates['title'] = $_POST['title'];
        error_log("Will update title to: " . $_POST['title']);
    }
    if (isset($_POST['description']) && $_POST['description'] !== '') {
        $hasFieldUpdates = true;
        $updates['description'] = $_POST['description'];
        error_log("Will update description to: " . $_POST['description']);
    }
    if (isset($_POST['priority']) && $_POST['priority'] !== '') {
        $hasFieldUpdates = true;
        $updates['priority'] = $_POST['priority'];
        error_log("Will update priority to: " . $_POST['priority']);
    }

    error_log("hasFieldUpdates = " . ($hasFieldUpdates ? 'true' : 'false'));

    if ($hasFieldUpdates) {
        // Can only edit fields in draft status
        if ($ticket['status'] !== 'draft' && !$acl->hasPermission($user_id, 'ticket.manage')) {
            $validationErrors[] = "Can only edit title/description/priority when ticket is in draft status";
        }

        // Must own ticket or have manage permission
        if ($ticket['created_by'] != $user_id && !$acl->hasPermission($user_id, 'ticket.manage')) {
            $validationErrors[] = "Can only edit own tickets";
        }

        $permissionNeeded = $permissionNeeded ?? 'ticket.edit_own';
    }

    // ASSIGNMENT TO USER - only if non-empty value provided
    if (isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '') {
        $permissionNeeded = $permissionNeeded ?? 'ticket.assign';

        // Check if user has assignment permission
        if (!$acl->hasPermission($user_id, 'ticket.assign') && !$acl->hasPermission($user_id, 'ticket.manage')) {
            $validationErrors[] = "You don't have permission to assign tickets";
        } else {
            $updates['assigned_to'] = $_POST['assigned_to'] ? (int)$_POST['assigned_to'] : null;
            // Clear role assignment when assigning to user (mutually exclusive)
            $updates['assigned_to_role'] = null;
        }
    }

    // ASSIGNMENT TO ROLE - only if non-empty value provided
    if (isset($_POST['assigned_to_role']) && $_POST['assigned_to_role'] !== '') {
        $permissionNeeded = $permissionNeeded ?? 'ticket.assign';

        // Check if user has assignment permission
        if (!$acl->hasPermission($user_id, 'ticket.assign') && !$acl->hasPermission($user_id, 'ticket.manage')) {
            $validationErrors[] = "You don't have permission to assign tickets";
        } else {
            $updates['assigned_to_role'] = $_POST['assigned_to_role'] ? (int)$_POST['assigned_to_role'] : null;
            // Clear user assignment when assigning to role (mutually exclusive)
            $updates['assigned_to'] = null;
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
