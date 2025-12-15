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
 * - assigned_to_role (optional): Filter by assigned role ID
 * - my_tickets (optional): Set to "true" to get tickets assigned to you OR your roles
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

require_once(__DIR__ . '/../../../core/models/tickets/TicketManager.php');

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

    // Build filters (accept both POST and GET)
    $filters = [];

    // Status filter
    $status = $_POST['status'] ?? $_GET['status'] ?? null;
    if (!empty($status)) {
        $filters['status'] = $status;
    }

    // Priority filter
    $priority = $_POST['priority'] ?? $_GET['priority'] ?? null;
    if (!empty($priority)) {
        $filters['priority'] = $priority;
    }

    // Created by filter
    $createdBy = $_POST['created_by'] ?? $_GET['created_by'] ?? null;
    if (!empty($createdBy)) {
        $filters['created_by'] = (int)$createdBy;
    }

    // Assigned to filter
    $assignedTo = $_POST['assigned_to'] ?? $_GET['assigned_to'] ?? null;
    if (!empty($assignedTo)) {
        $filters['assigned_to'] = (int)$assignedTo;
    }

    // Assigned to role filter
    $assignedToRole = $_POST['assigned_to_role'] ?? $_GET['assigned_to_role'] ?? null;
    if (!empty($assignedToRole)) {
        $filters['assigned_to_role'] = (int)$assignedToRole;
    }

    // My tickets filter (tickets assigned to current user OR their roles)
    $myTickets = $_POST['my_tickets'] ?? $_GET['my_tickets'] ?? null;
    if ($myTickets === 'true' || $myTickets === '1') {
        $filters['my_tickets_user_id'] = $user_id;
    }

    // Search filter
    $search = $_POST['search'] ?? $_GET['search'] ?? null;
    if (!empty($search)) {
        $filters['search'] = $search;
    }

    // Date range filters
    $createdFrom = $_POST['created_from'] ?? $_GET['created_from'] ?? null;
    if (!empty($createdFrom)) {
        $filters['created_from'] = $createdFrom;
    }

    $createdTo = $_POST['created_to'] ?? $_GET['created_to'] ?? null;
    if (!empty($createdTo)) {
        $filters['created_to'] = $createdTo;
    }

    // Apply permission-based filtering
    if (!$canViewAll && !$canManage) {
        // User can only view own tickets or assigned tickets (including role assignments)
        if ($canViewOwn && $canViewAssigned) {
            // Can view both own and assigned - use combined filter
            // Only apply if no specific user filter is requested
            if (!isset($filters['created_by']) && !isset($filters['assigned_to']) && !isset($filters['assigned_to_role']) && !isset($filters['my_tickets_user_id'])) {
                $filters['my_tickets_user_id'] = $user_id;
            }
        } elseif ($canViewOwn) {
            // Can only view own tickets
            $filters['created_by'] = $user_id;
        } elseif ($canViewAssigned) {
            // Can only view assigned tickets (user OR role)
            $filters['my_tickets_user_id'] = $user_id;
        }
    }

    // Pagination (accept both POST and GET)
    $pageParam = $_POST['page'] ?? $_GET['page'] ?? 1;
    $page = max(1, (int)$pageParam);

    $limitParam = $_POST['limit'] ?? $_GET['limit'] ?? 20;
    $limit = min(100, max(1, (int)$limitParam));

    // Sorting (accept both POST and GET)
    $orderBy = $_POST['order_by'] ?? $_GET['order_by'] ?? 'created_at';
    $orderDir = $_POST['order_dir'] ?? $_GET['order_dir'] ?? 'DESC';

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
