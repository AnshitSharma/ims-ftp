<?php
/**
 * ticket-delete.php
 *
 * Delete ticket (soft delete by default, hard delete for admins)
 *
 * Action: ticket-delete
 * Method: POST/DELETE
 * Permission: ticket.delete
 *
 * Request Parameters:
 * - ticket_id (required): Ticket ID to delete
 * - hard_delete (optional): true for permanent deletion (admin only), false for soft delete (default: false)
 *
 * Soft Delete: Sets ticket status to 'cancelled'
 * Hard Delete: Permanently removes ticket and all related data (items, history)
 *
 * Response:
 * {
 *   "success": true,
 *   "authenticated": true,
 *   "code": 200,
 *   "message": "Ticket deleted successfully",
 *   "data": {
 *     "ticket_id": 1,
 *     "delete_type": "soft" or "hard"
 *   }
 * }
 *
 * @package BDC_IMS
 * @subpackage Ticketing
 */

require_once(__DIR__ . '/../../../core/models/tickets/TicketManager.php');

try {
    // Permission check
    if (!$acl->hasPermission($user_id, 'ticket.delete')) {
        send_json_response(false, true, 403, "Permission denied: ticket.delete required", null);
        exit;
    }

    // Validate ticket_id
    $ticketId = $_POST['ticket_id'] ?? $_GET['ticket_id'] ?? null;

    if (empty($ticketId) || !is_numeric($ticketId)) {
        send_json_response(false, true, 400, "ticket_id is required and must be numeric", null);
        exit;
    }

    $ticketId = (int)$ticketId;

    // Check hard delete request
    $hardDelete = isset($_POST['hard_delete']) && $_POST['hard_delete'] === 'true';

    // Hard delete requires manage permission
    if ($hardDelete && !$acl->hasPermission($user_id, 'ticket.manage')) {
        send_json_response(false, true, 403, "Permission denied: ticket.manage required for hard delete", null);
        exit;
    }

    // Delete ticket
    $ticketManager = new TicketManager($pdo);
    $result = $ticketManager->deleteTicket($ticketId, $user_id, $hardDelete);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to delete ticket", [
            'errors' => $result['errors']
        ]);
        exit;
    }

    $deleteType = $hardDelete ? 'hard' : 'soft';

    send_json_response(true, true, 200, "Ticket deleted successfully", [
        'ticket_id' => $ticketId,
        'delete_type' => $deleteType
    ]);

} catch (Exception $e) {
    error_log("ticket-delete error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    send_json_response(false, true, 500, "Failed to delete ticket", [
        'error' => $e->getMessage()
    ]);
}
