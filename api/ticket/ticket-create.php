<?php
/**
 * ticket-create.php
 *
 * Create new ticket with component items
 *
 * Action: ticket-create
 * Method: POST
 * Permission: ticket.create
 *
 * Request Parameters:
 * - title (required): Ticket title
 * - description (required): Detailed description
 * - priority (optional): low, medium, high, urgent (default: medium)
 * - target_server_uuid (optional): Server configuration UUID
 * - items (required): JSON array of component items
 *   [
 *     {
 *       "component_type": "cpu",
 *       "component_uuid": "uuid-here",
 *       "quantity": 2,
 *       "action": "add"
 *     }
 *   ]
 *
 * Response:
 * {
 *   "success": true,
 *   "authenticated": true,
 *   "code": 201,
 *   "message": "Ticket created successfully",
 *   "data": {
 *     "ticket_id": 1,
 *     "ticket_number": "TKT-20251118-0001",
 *     "ticket": { ... }
 *   }
 * }
 *
 * @package BDC_IMS
 * @subpackage Ticketing
 */

require_once(__DIR__ . '/../../includes/models/TicketManager.php');

try {
    // Permission check
    if (!$acl->hasPermission($user_id, 'ticket.create')) {
        send_json_response(false, true, 403, "Permission denied: ticket.create required", null);
        exit;
    }

    // Validate required fields
    $title = $_POST['title'] ?? null;
    $description = $_POST['description'] ?? null;
    $itemsJson = $_POST['items'] ?? null;

    if (empty($title)) {
        send_json_response(false, true, 400, "title is required", null);
        exit;
    }

    if (empty($description)) {
        send_json_response(false, true, 400, "description is required", null);
        exit;
    }

    if (empty($itemsJson)) {
        send_json_response(false, true, 400, "items array is required", null);
        exit;
    }

    // Parse items JSON
    $items = json_decode($itemsJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        send_json_response(false, true, 400, "Invalid JSON format for items: " . json_last_error_msg(), null);
        exit;
    }

    // Prepare ticket data
    $ticketData = [
        'title' => trim($title),
        'description' => trim($description),
        'priority' => $_POST['priority'] ?? 'medium',
        'target_server_uuid' => $_POST['target_server_uuid'] ?? null,
        'items' => $items
    ];

    // Create ticket
    $ticketManager = new TicketManager($pdo);
    $result = $ticketManager->createTicket($ticketData, $user_id);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to create ticket", [
            'errors' => $result['errors']
        ]);
        exit;
    }

    // Get full ticket details
    $ticket = $ticketManager->getTicketById($result['ticket_id'], true, false);

    send_json_response(true, true, 201, "Ticket created successfully", [
        'ticket_id' => $result['ticket_id'],
        'ticket_number' => $result['ticket_number'],
        'ticket' => $ticket
    ]);

} catch (Exception $e) {
    error_log("ticket-create error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    send_json_response(false, true, 500, "Failed to create ticket", [
        'error' => $e->getMessage()
    ]);
}
