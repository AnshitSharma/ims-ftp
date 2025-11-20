<?php
/**
 * ticket-get.php
 *
 * Get single ticket with full details
 *
 * Action: ticket-get
 * Method: GET
 * Permission: ticket.view_own (for own tickets) OR ticket.view_all OR ticket.manage
 *
 * Query Parameters:
 * - ticket_id (required): Ticket ID
 * - include_history (optional): Include history (true/false, default: false)
 *
 * Response:
 * {
 *   "success": true,
 *   "authenticated": true,
 *   "code": 200,
 *   "message": "Ticket retrieved successfully",
 *   "data": {
 *     "ticket": {
 *       "id": 1,
 *       "ticket_number": "TKT-20251118-0001",
 *       "title": "...",
 *       "description": "...",
 *       "status": "draft",
 *       "priority": "medium",
 *       "created_by": 1,
 *       "created_by_username": "john",
 *       "assigned_to": null,
 *       "created_at": "...",
 *       "updated_at": "...",
 *       "items": [ ... ],
 *       "history": [ ... ]  // If include_history=true
 *     }
 *   }
 * }
 *
 * @package BDC_IMS
 * @subpackage Ticketing
 */

require_once(__DIR__ . '/../../../core/models/tickets/TicketManager.php');

try {
    // Validate ticket_id parameter (accept both POST and GET)
    $ticketId = $_POST['ticket_id'] ?? $_GET['ticket_id'] ?? null;

    if (empty($ticketId) || !is_numeric($ticketId)) {
        send_json_response(false, true, 400, "ticket_id is required and must be numeric", null);
        exit;
    }

    $ticketId = (int)$ticketId;

    // Get ticket (accept both POST and GET for include_history)
    $includeHistoryParam = $_POST['include_history'] ?? $_GET['include_history'] ?? 'false';
    $includeHistory = ($includeHistoryParam === 'true' || $includeHistoryParam === '1' || $includeHistoryParam === 1);
    $ticketManager = new TicketManager($pdo);
    $ticket = $ticketManager->getTicketById($ticketId, true, $includeHistory);

    if (!$ticket) {
        send_json_response(false, true, 404, "Ticket not found", null);
        exit;
    }

    // Permission check
    $canViewAll = $acl->hasPermission($user_id, 'ticket.view_all');
    $canManage = $acl->hasPermission($user_id, 'ticket.manage');
    $isOwner = ($ticket['created_by'] == $user_id);
    $isAssigned = ($ticket['assigned_to'] == $user_id);
    $canViewOwn = $acl->hasPermission($user_id, 'ticket.view_own');
    $canViewAssigned = $acl->hasPermission($user_id, 'ticket.view_assigned');

    // Determine if user can view this ticket
    $canView = false;

    if ($canViewAll || $canManage) {
        // Can view any ticket
        $canView = true;
    } elseif ($isOwner && $canViewOwn) {
        // Can view own ticket
        $canView = true;
    } elseif ($isAssigned && $canViewAssigned) {
        // Can view assigned ticket
        $canView = true;
    }

    if (!$canView) {
        send_json_response(false, true, 403, "Permission denied: Cannot view this ticket", null);
        exit;
    }

    send_json_response(true, true, 200, "Ticket retrieved successfully", [
        'ticket' => $ticket
    ]);

} catch (Exception $e) {
    error_log("ticket-get error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    send_json_response(false, true, 500, "Failed to retrieve ticket", [
        'error' => $e->getMessage()
    ]);
}
