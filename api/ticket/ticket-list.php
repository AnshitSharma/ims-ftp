<?php
/**
 * ticket-list.php
 *
 * List tickets with filtering and pagination
 *
 * Action: ticket-list
 * Method: GET
 * Permission: ticket.view_own OR ticket.view_all OR ticket.view_assigned
 *
 * Query Parameters:
 * - status (optional): Filter by status (draft, pending, approved, etc.)
 * - priority (optional): Filter by priority (low, medium, high, urgent)
 * - created_by (optional): Filter by creator user ID
 * - assigned_to (optional): Filter by assigned user ID
 * - search (optional): Search in title, description, ticket_number
 * - created_from (optional): Filter by created date (YYYY-MM-DD)
 * - created_to (optional): Filter by created date (YYYY-MM-DD)
 * - page (optional): Page number (default: 1)
 * - limit (optional): Items per page (default: 20, max: 100)
 * - order_by (optional): Sort field (default: created_at)
 * - order_dir (optional): Sort direction ASC/DESC (default: DESC)
 *
 * Response:
 * {
 *   "success": true,
 *   "authenticated": true,
 *   "code": 200,
 *   "message": "Tickets retrieved successfully",
 *   "data": {
 *     "tickets": [ ... ],
 *     "total": 50,
 *     "page": 1,
 *     "limit": 20,
 *     "total_pages": 3
 *   }
 * }
 *
 * @package BDC_IMS
 * @subpackage Ticketing
 */

require_once(__DIR__ . '/../../includes/models/TicketManager.php');

try {
    // Permission check - user must have at least one view permission
    $canViewAll = $acl->hasPermission($user_id, 'ticket.view_all');
    $canViewOwn = $acl->hasPermission($user_id, 'ticket.view_own');
    $canViewAssigned = $acl->hasPermission($user_id, 'ticket.view_assigned');
    $canManage = $acl->hasPermission($user_id, 'ticket.manage');

    if (!$canViewAll && !$canViewOwn && !$canViewAssigned && !$canManage) {
        send_json_response(false, true, 403, "Permission denied: No ticket view permissions", null);
        exit;
    }

    // Build filters
    $filters = [];

    // Status filter
    if (isset($_GET['status']) && !empty($_GET['status'])) {
        $filters['status'] = $_GET['status'];
    }

    // Priority filter
    if (isset($_GET['priority']) && !empty($_GET['priority'])) {
        $filters['priority'] = $_GET['priority'];
    }

    // Created by filter
    if (isset($_GET['created_by']) && !empty($_GET['created_by'])) {
        $filters['created_by'] = (int)$_GET['created_by'];
    }

    // Assigned to filter
    if (isset($_GET['assigned_to']) && !empty($_GET['assigned_to'])) {
        $filters['assigned_to'] = (int)$_GET['assigned_to'];
    }

    // Search filter
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $filters['search'] = $_GET['search'];
    }

    // Date range filters
    if (isset($_GET['created_from']) && !empty($_GET['created_from'])) {
        $filters['created_from'] = $_GET['created_from'];
    }

    if (isset($_GET['created_to']) && !empty($_GET['created_to'])) {
        $filters['created_to'] = $_GET['created_to'];
    }

    // Apply permission-based filtering
    if (!$canViewAll && !$canManage) {
        // User can only view own tickets or assigned tickets
        if ($canViewOwn && $canViewAssigned) {
            // Can view both own and assigned - need custom query (handled in TicketManager)
            // For now, we'll apply created_by filter if no other user filter is set
            if (!isset($filters['created_by']) && !isset($filters['assigned_to'])) {
                $filters['created_by'] = $user_id;
            }
        } elseif ($canViewOwn) {
            // Can only view own tickets
            $filters['created_by'] = $user_id;
        } elseif ($canViewAssigned) {
            // Can only view assigned tickets
            $filters['assigned_to'] = $user_id;
        }
    }

    // Pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;

    // Sorting
    $orderBy = $_GET['order_by'] ?? 'created_at';
    $orderDir = $_GET['order_dir'] ?? 'DESC';

    // Get tickets
    $ticketManager = new TicketManager($pdo);
    $result = $ticketManager->listTickets($filters, $page, $limit, $orderBy, $orderDir);

    send_json_response(true, true, 200, "Tickets retrieved successfully", $result);

} catch (Exception $e) {
    error_log("ticket-list error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    send_json_response(false, true, 500, "Failed to retrieve tickets", [
        'error' => $e->getMessage()
    ]);
}
