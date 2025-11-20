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
    // === COMPREHENSIVE DEBUG LOGGING ===
    error_log("=== TICKET-UPDATE DEBUG START ===");
    error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
    error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'NOT SET'));
    error_log("Content-Length: " . ($_SERVER['CONTENT_LENGTH'] ?? 'NOT SET'));
    error_log("POST data: " . json_encode($_POST));
    error_log("GET data: " . json_encode($_GET));
    error_log("Raw input: " . file_get_contents('php://input'));
    error_log("POST keys: " . implode(', ', array_keys($_POST)));

    // Check specifically for rejection_reason in different casings
    foreach ($_POST as $key => $value) {
        if (stripos($key, 'rejection') !== false) {
            error_log("Found key containing 'rejection': '$key' = '$value' (length: " . strlen($value) . ")");
        }
    }
    error_log("=== TICKET-UPDATE DEBUG END ===");

    // === WORKAROUND: Manual POST parsing if $_POST is empty or incomplete ===
    // Some clients (including Postman) may send data that PHP doesn't automatically parse
    $rawInput = file_get_contents('php://input');

    if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
        error_log("WARNING: POST is empty but Content-Length > 0. Attempting manual parse...");

        // Check if it's JSON
        if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            error_log("Detected JSON content type, parsing as JSON");
            $jsonData = json_decode($rawInput, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($jsonData)) {
                error_log("Successfully parsed JSON input. Found keys: " . implode(', ', array_keys($jsonData)));
                $_POST = array_merge($_POST, $jsonData);
            } else {
                error_log("Failed to parse JSON: " . json_last_error_msg());
            }
        } else {
            // Try parsing as application/x-www-form-urlencoded
            parse_str($rawInput, $parsedData);
            if (!empty($parsedData)) {
                error_log("Successfully parsed form input. Found keys: " . implode(', ', array_keys($parsedData)));
                $_POST = array_merge($_POST, $parsedData);
            } else {
                error_log("Failed to parse raw input as form data");
            }
        }
    } elseif (!empty($rawInput) && (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false)) {
        // POST has data, but check if we should also merge JSON data
        error_log("Checking for additional JSON data in request body");
        $jsonData = json_decode($rawInput, true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($jsonData)) {
            error_log("Found valid JSON in body, merging with POST data");
            $_POST = array_merge($_POST, $jsonData);
        }
    }

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

    // ASSIGNMENT - only if non-empty value provided
    if (isset($_POST['assigned_to']) && $_POST['assigned_to'] !== '') {
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
