<?php
/**
 * TicketItemService.php
 *
 * Service for handling ticket items
 *
 * @package BDC_IMS
 * @subpackage Ticketing
 */

class TicketItemService
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Insert ticket item
     *
     * @param int $ticketId Ticket ID
     * @param array $item Item data
     * @return int Item ID
     */
    public function insertTicketItem($ticketId, $item)
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
     * Get ticket items
     *
     * @param int $ticketId Ticket ID
     * @return array Ticket items
     */
    public function getTicketItems($ticketId)
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
            error_log("TicketItemService::getTicketItems error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get ticket item count
     *
     * @param int $ticketId Ticket ID
     * @return int Item count
     */
    public function getTicketItemCount($ticketId)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ticket_items WHERE ticket_id = ?");
            $stmt->execute([$ticketId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("TicketItemService::getTicketItemCount error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Delete items for a ticket
     * 
     * @param int $ticketId
     */
    public function deleteTicketItems($ticketId)
    {
        $stmt = $this->pdo->prepare("DELETE FROM ticket_items WHERE ticket_id = ?");
        $stmt->execute([$ticketId]);
    }
}
