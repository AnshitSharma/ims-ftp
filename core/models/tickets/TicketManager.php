<?php
/**
 * TicketManager.php
 *
 * Main business logic for ticketing system
 * - Create, read, update, delete tickets
 * - Manage ticket items
 * - Track history/audit trail
 * - Handle status transitions
 * - Generate ticket numbers
 *
 * @package BDC_IMS
 * @subpackage Ticketing
 * @version 2.1
 * @date 2025-11-18
 */

require_once(__DIR__ . '/TicketValidator.php');

class TicketManager
{
    private $pdo;
    private $validator;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->validator = new TicketValidator($pdo);
    }

    /**
     * Create a new ticket
     *
     * @param array $data Ticket data (title, description, priority, target_server_uuid, items)
     * @param int $userId User ID creating the ticket
     * @return array ['success' => bool, 'ticket_id' => int, 'ticket_number' => string, 'errors' => array]
     */
    public function createTicket($data, $userId)
    {
        try {
            $this->pdo->beginTransaction();

            // Validate ticket data
            $validation = $this->validator->validateTicketCreate($data);
            if (!$validation['valid']) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Validate ticket items
            $itemsData = isset($data['items']) ? $data['items'] : [];
            $itemsValidation = $this->validator->validateTicketItems(
                $itemsData,
                $data['target_server_uuid'] ?? null
            );

            if (!$itemsValidation['valid']) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'errors' => $itemsValidation['errors']
                ];
            }

            // Generate unique ticket number
            $ticketNumber = $this->generateTicketNumber();

            // Insert ticket
            $stmt = $this->pdo->prepare("
                INSERT INTO tickets (
                    ticket_number,
                    title,
                    description,
                    status,
                    priority,
                    target_server_uuid,
                    created_by,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $ticketNumber,
                $data['title'],
                $data['description'],
                'draft', // Always start as draft
                $data['priority'] ?? 'medium',
                $data['target_server_uuid'] ?? null,
                $userId
            ]);

            $ticketId = $this->pdo->lastInsertId();

            // Insert ticket items
            foreach ($itemsValidation['validated_items'] as $item) {
                $this->insertTicketItem($ticketId, $item);
            }

            // Log creation in history
            $this->logHistory(
                $ticketId,
                'created',
                null,
                'draft',
                $userId,
                'Ticket created'
            );

            $this->pdo->commit();

            return [
                'success' => true,
                'ticket_id' => $ticketId,
                'ticket_number' => $ticketNumber,
                'errors' => []
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("TicketManager::createTicket error: " . $e->getMessage());
            return [
                'success' => false,
                'errors' => ['Failed to create ticket: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Get ticket by ID with full details
     *
     * @param int $ticketId Ticket ID
     * @param bool $includeItems Include ticket items
     * @param bool $includeHistory Include history
     * @return array|null Ticket data or null if not found
     */
    public function getTicketById($ticketId, $includeItems = true, $includeHistory = false)
    {
        try {
            // Get ticket
            $stmt = $this->pdo->prepare("
                SELECT
                    t.*,
                    creator.username as created_by_username,
                    creator.email as created_by_email,
                    assignee.username as assigned_to_username,
                    assignee.email as assigned_to_email
                FROM tickets t
                LEFT JOIN users creator ON t.created_by = creator.id
                LEFT JOIN users assignee ON t.assigned_to = assignee.id
                WHERE t.id = ?
            ");
            $stmt->execute([$ticketId]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ticket) {
                return null;
            }

            // Get items
            if ($includeItems) {
                $ticket['items'] = $this->getTicketItems($ticketId);
            }

            // Get history
            if ($includeHistory) {
                $ticket['history'] = $this->getTicketHistory($ticketId);
            }

            return $ticket;

        } catch (Exception $e) {
            error_log("TicketManager::getTicketById error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get ticket by ticket number
     *
     * @param string $ticketNumber Ticket number
     * @return array|null Ticket data or null if not found
     */
    public function getTicketByNumber($ticketNumber)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM tickets WHERE ticket_number = ?");
            $stmt->execute([$ticketNumber]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return $this->getTicketById($result['id']);
            }

            return null;
        } catch (Exception $e) {
            error_log("TicketManager::getTicketByNumber error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * List tickets with filters and pagination
     *
     * @param array $filters Filters (status, priority, created_by, assigned_to, search)
     * @param int $page Page number (1-indexed)
     * @param int $limit Items per page
     * @param string $orderBy Order by field
     * @param string $orderDir Order direction (ASC/DESC)
     * @return array ['tickets' => array, 'total' => int, 'page' => int, 'limit' => int]
     */
    public function listTickets($filters = [], $page = 1, $limit = 20, $orderBy = 'created_at', $orderDir = 'DESC')
    {
        try {
            $where = [];
            $params = [];

            // Status filter
            if (!empty($filters['status'])) {
                $where[] = "t.status = ?";
                $params[] = $filters['status'];
            }

            // Priority filter
            if (!empty($filters['priority'])) {
                $where[] = "t.priority = ?";
                $params[] = $filters['priority'];
            }

            // Created by filter
            if (!empty($filters['created_by'])) {
                $where[] = "t.created_by = ?";
                $params[] = $filters['created_by'];
            }

            // Assigned to filter
            if (!empty($filters['assigned_to'])) {
                $where[] = "t.assigned_to = ?";
                $params[] = $filters['assigned_to'];
            }

            // Search filter (title, description, ticket_number)
            if (!empty($filters['search'])) {
                $where[] = "(t.title LIKE ? OR t.description LIKE ? OR t.ticket_number LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            // Date range filters
            if (!empty($filters['created_from'])) {
                $where[] = "t.created_at >= ?";
                $params[] = $filters['created_from'];
            }

            if (!empty($filters['created_to'])) {
                $where[] = "t.created_at <= ?";
                $params[] = $filters['created_to'];
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Get total count
            $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM tickets t $whereClause");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Validate order by
            $validOrderBy = ['id', 'ticket_number', 'title', 'status', 'priority', 'created_at', 'updated_at'];
            if (!in_array($orderBy, $validOrderBy)) {
                $orderBy = 'created_at';
            }

            $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

            // Calculate offset
            $offset = ($page - 1) * $limit;

            // Get tickets
            $stmt = $this->pdo->prepare("
                SELECT
                    t.*,
                    creator.username as created_by_username,
                    assignee.username as assigned_to_username
                FROM tickets t
                LEFT JOIN users creator ON t.created_by = creator.id
                LEFT JOIN users assignee ON t.assigned_to = assignee.id
                $whereClause
                ORDER BY t.$orderBy $orderDir
                LIMIT ? OFFSET ?
            ");

            $stmt->execute(array_merge($params, [$limit, $offset]));
            $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get item counts for each ticket
            foreach ($tickets as &$ticket) {
                $ticket['item_count'] = $this->getTicketItemCount($ticket['id']);
            }

            return [
                'tickets' => $tickets,
                'total' => (int)$total,
                'page' => (int)$page,
                'limit' => (int)$limit,
                'total_pages' => ceil($total / $limit)
            ];

        } catch (Exception $e) {
            error_log("TicketManager::listTickets error: " . $e->getMessage());
            return [
                'tickets' => [],
                'total' => 0,
                'page' => 1,
                'limit' => $limit,
                'total_pages' => 0
            ];
        }
    }

    /**
     * Update ticket
     *
     * @param int $ticketId Ticket ID
     * @param int $userId User ID performing update
     * @param array $updates Updates to apply
     * @param array $extraData Extra data (rejection_reason, deployment_notes, etc.)
     * @return array ['success' => bool, 'errors' => array, 'ticket' => array]
     */
    public function updateTicket($ticketId, $userId, $updates, $extraData = [])
    {
        try {
            $this->pdo->beginTransaction();

            // Get current ticket
            $ticket = $this->getTicketById($ticketId, false, false);
            if (!$ticket) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'errors' => ['Ticket not found']
                ];
            }

            // Validate updates
            $validation = $this->validator->validateTicketUpdate($ticket, $updates);
            if (!$validation['valid']) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Build UPDATE query
            $setFields = [];
            $params = [];

            // Handle status change
            if (isset($updates['status']) && $updates['status'] !== $ticket['status']) {
                $setFields[] = "status = ?";
                $params[] = $updates['status'];

                // Set timestamp fields based on new status
                switch ($updates['status']) {
                    case 'pending':
                        $setFields[] = "submitted_at = NOW()";
                        break;
                    case 'approved':
                        $setFields[] = "approved_at = NOW()";
                        break;
                    case 'deployed':
                        $setFields[] = "deployed_at = NOW()";
                        break;
                    case 'completed':
                        $setFields[] = "completed_at = NOW()";
                        break;
                }

                // Log status change
                $this->logHistory(
                    $ticketId,
                    'status_changed',
                    $ticket['status'],
                    $updates['status'],
                    $userId,
                    "Status changed from {$ticket['status']} to {$updates['status']}"
                );
            }

            // Handle field updates
            if (isset($updates['title'])) {
                $setFields[] = "title = ?";
                $params[] = $updates['title'];
                $this->logHistory($ticketId, 'title_changed', $ticket['title'], $updates['title'], $userId);
            }

            if (isset($updates['description'])) {
                $setFields[] = "description = ?";
                $params[] = $updates['description'];
                $this->logHistory($ticketId, 'description_changed', null, null, $userId, 'Description updated');
            }

            if (isset($updates['priority'])) {
                $setFields[] = "priority = ?";
                $params[] = $updates['priority'];
                $this->logHistory($ticketId, 'priority_changed', $ticket['priority'], $updates['priority'], $userId);
            }

            if (isset($updates['assigned_to'])) {
                $setFields[] = "assigned_to = ?";
                $params[] = $updates['assigned_to'];
                $this->logHistory($ticketId, 'assigned', $ticket['assigned_to'], $updates['assigned_to'], $userId, 'Ticket assigned');
            }

            // Handle extra data fields
            if (!empty($extraData['rejection_reason'])) {
                $setFields[] = "rejection_reason = ?";
                $params[] = $extraData['rejection_reason'];
            }

            if (!empty($extraData['deployment_notes'])) {
                $setFields[] = "deployment_notes = ?";
                $params[] = $extraData['deployment_notes'];
            }

            if (!empty($extraData['completion_notes'])) {
                $setFields[] = "completion_notes = ?";
                $params[] = $extraData['completion_notes'];
            }

            // Always update updated_at
            $setFields[] = "updated_at = NOW()";

            if (!empty($setFields)) {
                $params[] = $ticketId;
                $sql = "UPDATE tickets SET " . implode(', ', $setFields) . " WHERE id = ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            }

            $this->pdo->commit();

            // Return updated ticket
            $updatedTicket = $this->getTicketById($ticketId);

            return [
                'success' => true,
                'errors' => [],
                'ticket' => $updatedTicket
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("TicketManager::updateTicket error: " . $e->getMessage());
            return [
                'success' => false,
                'errors' => ['Failed to update ticket: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Delete ticket (soft or hard delete)
     *
     * @param int $ticketId Ticket ID
     * @param int $userId User ID performing deletion
     * @param bool $hardDelete True for hard delete, false for soft delete
     * @return array ['success' => bool, 'errors' => array]
     */
    public function deleteTicket($ticketId, $userId, $hardDelete = false)
    {
        try {
            $this->pdo->beginTransaction();

            // Get ticket
            $ticket = $this->getTicketById($ticketId, false, false);
            if (!$ticket) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'errors' => ['Ticket not found']
                ];
            }

            if ($hardDelete) {
                // Hard delete - remove from database
                // History and items will be cascade deleted
                $stmt = $this->pdo->prepare("DELETE FROM tickets WHERE id = ?");
                $stmt->execute([$ticketId]);
            } else {
                // Soft delete - mark as cancelled
                $stmt = $this->pdo->prepare("
                    UPDATE tickets
                    SET status = 'cancelled', updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$ticketId]);

                $this->logHistory($ticketId, 'deleted', $ticket['status'], 'cancelled', $userId, 'Ticket cancelled/deleted');
            }

            $this->pdo->commit();

            return [
                'success' => true,
                'errors' => []
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("TicketManager::deleteTicket error: " . $e->getMessage());
            return [
                'success' => false,
                'errors' => ['Failed to delete ticket: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Get ticket items
     *
     * @param int $ticketId Ticket ID
     * @return array Ticket items
     */
    private function getTicketItems($ticketId)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM ticket_items
                WHERE ticket_id = ?
                ORDER BY id ASC
            ");
            $stmt->execute([$ticketId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("TicketManager::getTicketItems error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get ticket item count
     *
     * @param int $ticketId Ticket ID
     * @return int Item count
     */
    private function getTicketItemCount($ticketId)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ticket_items WHERE ticket_id = ?");
            $stmt->execute([$ticketId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("TicketManager::getTicketItemCount error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get ticket history
     *
     * @param int $ticketId Ticket ID
     * @return array History entries
     */
    private function getTicketHistory($ticketId)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    h.*,
                    u.username as changed_by_username,
                    u.email as changed_by_email
                FROM ticket_history h
                LEFT JOIN users u ON h.changed_by = u.id
                WHERE h.ticket_id = ?
                ORDER BY h.created_at DESC
            ");
            $stmt->execute([$ticketId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("TicketManager::getTicketHistory error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Insert ticket item
     *
     * @param int $ticketId Ticket ID
     * @param array $item Item data
     * @return int Item ID
     */
    private function insertTicketItem($ticketId, $item)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ticket_items (
                ticket_id,
                component_type,
                component_uuid,
                quantity,
                action,
                component_name,
                component_specs,
                is_validated,
                is_compatible,
                compatibility_notes,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $ticketId,
            $item['component_type'],
            $item['component_uuid'],
            $item['quantity'] ?? 1,
            $item['action'] ?? 'add',
            $item['component_name'] ?? 'Unknown',
            $item['component_specs'] ?? null,
            $item['is_validated'] ?? 0,
            $item['is_compatible'] ?? 0,
            $item['compatibility_notes'] ?? null
        ]);

        return $this->pdo->lastInsertId();
    }

    /**
     * Log history entry
     *
     * @param int $ticketId Ticket ID
     * @param string $action Action performed
     * @param mixed $oldValue Old value
     * @param mixed $newValue New value
     * @param int $userId User ID
     * @param string|null $notes Additional notes
     */
    private function logHistory($ticketId, $action, $oldValue, $newValue, $userId, $notes = null)
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO ticket_history (
                    ticket_id,
                    action,
                    old_value,
                    new_value,
                    changed_by,
                    notes,
                    ip_address,
                    user_agent,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $ticketId,
                $action,
                $oldValue,
                $newValue,
                $userId,
                $notes,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("TicketManager::logHistory error: " . $e->getMessage());
            // Don't throw - history logging shouldn't break main operation
        }
    }

    /**
     * Generate unique ticket number
     * Format: TKT-YYYYMMDD-XXXX
     *
     * @return string Ticket number
     */
    private function generateTicketNumber()
    {
        $date = date('Ymd');
        $prefix = "TKT-{$date}-";

        // Get the count of tickets created today
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM tickets
            WHERE DATE(created_at) = CURDATE()
        ");
        $stmt->execute();
        $count = (int)$stmt->fetchColumn();

        // Increment and pad to 4 digits
        $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);

        return $prefix . $sequence;
    }
}
