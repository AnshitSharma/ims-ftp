<?php
/**
 * TicketHistoryService.php
 *
 * Service for handling ticket history logging
 *
 * @package BDC_IMS
 * @subpackage Ticketing
 */

class TicketHistoryService
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
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
    public function logHistory($ticketId, $action, $oldValue, $newValue, $userId, $notes = null)
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
            error_log("TicketHistoryService::logHistory error: " . $e->getMessage());
            // Don't throw - history logging shouldn't break main operation
        }
    }

    /**
     * Get ticket history
     *
     * @param int $ticketId Ticket ID
     * @return array History entries
     */
    public function getTicketHistory($ticketId)
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
            error_log("TicketHistoryService::getTicketHistory error: " . $e->getMessage());
            return [];
        }
    }
}
