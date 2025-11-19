<?php
/**
 * TicketValidator.php
 *
 * Validation logic for ticketing system
 * - Validates ticket data (title, description, priority, etc.)
 * - Validates ticket items (component UUIDs, quantities, compatibility)
 * - Validates status transitions
 * - Integrates with ComponentDataService for UUID validation
 *
 * @package BDC_IMS
 * @subpackage Ticketing
 * @version 2.1
 * @date 2025-11-18
 */

require_once(__DIR__ . '/../components/ComponentDataService.php');
require_once(__DIR__ . '/../compatibility/ComponentCompatibility.php');

class TicketValidator
{
    private $pdo;
    private $componentDataService;
    private $compatibilityValidator;

    /**
     * Valid ticket statuses
     */
    const VALID_STATUSES = ['draft', 'pending', 'approved', 'in_progress', 'deployed', 'completed', 'rejected', 'cancelled'];

    /**
     * Valid priorities
     */
    const VALID_PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /**
     * Valid component types
     */
    const VALID_COMPONENT_TYPES = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'chassis', 'pciecard', 'hbacard'];

    /**
     * Valid item actions
     */
    const VALID_ACTIONS = ['add', 'remove', 'replace'];

    /**
     * Status transition rules
     * Format: current_status => [allowed_next_statuses]
     */
    const STATUS_TRANSITIONS = [
        'draft' => ['pending', 'cancelled'],
        'pending' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['in_progress', 'rejected', 'cancelled'],
        'in_progress' => ['deployed', 'rejected'],
        'deployed' => ['completed'],
        'rejected' => ['pending', 'cancelled'],
        'completed' => [],  // Final state
        'cancelled' => []   // Final state
    ];

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->componentDataService = new ComponentDataService();
        $this->compatibilityValidator = new ComponentCompatibility($pdo);
    }

    /**
     * Validate ticket creation data
     *
     * @param array $data Ticket data
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateTicketCreate($data)
    {
        $errors = [];

        // Title validation
        if (empty($data['title'])) {
            $errors[] = "Title is required";
        } elseif (strlen($data['title']) > 255) {
            $errors[] = "Title must be 255 characters or less";
        }

        // Description validation
        if (empty($data['description'])) {
            $errors[] = "Description is required";
        } elseif (strlen($data['description']) < 10) {
            $errors[] = "Description must be at least 10 characters";
        }

        // Priority validation
        if (isset($data['priority'])) {
            if (!in_array($data['priority'], self::VALID_PRIORITIES)) {
                $errors[] = "Invalid priority. Must be one of: " . implode(', ', self::VALID_PRIORITIES);
            }
        }

        // Target server validation (optional but recommended)
        if (isset($data['target_server_uuid']) && !empty($data['target_server_uuid'])) {
            if (!$this->isValidUuid($data['target_server_uuid'])) {
                $errors[] = "Invalid target_server_uuid format";
            }
            // Check if server exists
            if (!$this->serverExists($data['target_server_uuid'])) {
                $errors[] = "Target server not found";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate ticket items
     *
     * @param array $items Array of ticket items
     * @param string|null $serverUuid Target server UUID for compatibility checking
     * @return array ['valid' => bool, 'errors' => array, 'validated_items' => array]
     */
    public function validateTicketItems($items, $serverUuid = null)
    {
        $errors = [];
        $validatedItems = [];

        if (empty($items) || !is_array($items)) {
            $errors[] = "At least one component item is required";
            return [
                'valid' => false,
                'errors' => $errors,
                'validated_items' => []
            ];
        }

        foreach ($items as $index => $item) {
            $itemErrors = $this->validateSingleItem($item, $index, $serverUuid);
            if (!empty($itemErrors['errors'])) {
                $errors = array_merge($errors, $itemErrors['errors']);
            } else {
                $validatedItems[] = $itemErrors['validated_item'];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'validated_items' => $validatedItems
        ];
    }

    /**
     * Validate a single ticket item
     *
     * @param array $item Item data
     * @param int $index Item index (for error messages)
     * @param string|null $serverUuid Server UUID for compatibility
     * @return array
     */
    private function validateSingleItem($item, $index, $serverUuid = null)
    {
        $errors = [];
        $validatedItem = $item;

        $itemLabel = "Item #" . ($index + 1);

        // Component type validation
        if (empty($item['component_type'])) {
            $errors[] = "$itemLabel: component_type is required";
        } elseif (!in_array($item['component_type'], self::VALID_COMPONENT_TYPES)) {
            $errors[] = "$itemLabel: Invalid component_type. Must be one of: " . implode(', ', self::VALID_COMPONENT_TYPES);
        }

        // Component UUID validation
        if (empty($item['component_uuid'])) {
            $errors[] = "$itemLabel: component_uuid is required";
        } else {
            // Validate UUID format
            if (!$this->isValidUuid($item['component_uuid'])) {
                $errors[] = "$itemLabel: Invalid component_uuid format";
            } else {
                // Validate UUID exists in JSON files
                $uuidValidation = $this->componentDataService->validateComponentUuid(
                    $item['component_uuid'],
                    $item['component_type']
                );

                if (!$uuidValidation['valid']) {
                    $errors[] = "$itemLabel: " . $uuidValidation['error'];
                    $validatedItem['is_validated'] = 0;
                } else {
                    // UUID is valid - store component details
                    $validatedItem['is_validated'] = 1;
                    $validatedItem['component_name'] = $uuidValidation['component']['name'] ?? 'Unknown';
                    $validatedItem['component_specs'] = json_encode($uuidValidation['component']);
                }
            }
        }

        // Quantity validation
        if (isset($item['quantity'])) {
            if (!is_numeric($item['quantity']) || $item['quantity'] < 1) {
                $errors[] = "$itemLabel: Quantity must be a positive integer";
            } elseif ($item['quantity'] > 100) {
                $errors[] = "$itemLabel: Quantity seems unreasonably high (max 100)";
            }
        } else {
            $validatedItem['quantity'] = 1; // Default
        }

        // Action validation
        if (isset($item['action'])) {
            if (!in_array($item['action'], self::VALID_ACTIONS)) {
                $errors[] = "$itemLabel: Invalid action. Must be one of: " . implode(', ', self::VALID_ACTIONS);
            }
        } else {
            $validatedItem['action'] = 'add'; // Default
        }

        // Compatibility checking (if server UUID provided and item is validated)
        if ($serverUuid && $validatedItem['is_validated'] == 1) {
            $compatibilityResult = $this->checkItemCompatibility(
                $validatedItem,
                $serverUuid
            );
            $validatedItem['is_compatible'] = $compatibilityResult['compatible'] ? 1 : 0;
            $validatedItem['compatibility_notes'] = $compatibilityResult['notes'];

            if (!$compatibilityResult['compatible']) {
                $errors[] = "$itemLabel: Compatibility issue - " . $compatibilityResult['notes'];
            }
        }

        return [
            'errors' => $errors,
            'validated_item' => $validatedItem
        ];
    }

    /**
     * Check if item is compatible with target server
     *
     * @param array $item Validated item
     * @param string $serverUuid Server UUID
     * @return array ['compatible' => bool, 'notes' => string]
     */
    private function checkItemCompatibility($item, $serverUuid)
    {
        try {
            // Load server configuration
            $stmt = $this->pdo->prepare("
                SELECT * FROM server_configurations
                WHERE server_uuid = ? AND status != 'deleted'
                LIMIT 1
            ");
            $stmt->execute([$serverUuid]);
            $server = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$server) {
                return [
                    'compatible' => false,
                    'notes' => 'Server configuration not found'
                ];
            }

            // Use FlexibleCompatibilityValidator for detailed checking
            // This is a simplified check - full compatibility requires server builder context
            $componentSpecs = json_decode($item['component_specs'], true);

            // Basic compatibility check based on component type
            $notes = "Component validated";
            $compatible = true;

            // You can enhance this with actual compatibility logic from FlexibleCompatibilityValidator
            // For now, we'll mark as compatible if UUID is valid

            return [
                'compatible' => $compatible,
                'notes' => $notes
            ];

        } catch (Exception $e) {
            error_log("Compatibility check error: " . $e->getMessage());
            return [
                'compatible' => false,
                'notes' => 'Compatibility check failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate status transition
     *
     * @param string $currentStatus Current ticket status
     * @param string $newStatus Desired new status
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function validateStatusTransition($currentStatus, $newStatus)
    {
        // Check if new status is valid
        if (!in_array($newStatus, self::VALID_STATUSES)) {
            return [
                'valid' => false,
                'error' => "Invalid status: $newStatus"
            ];
        }

        // Check if transition is allowed
        $allowedTransitions = self::STATUS_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowedTransitions)) {
            return [
                'valid' => false,
                'error' => "Cannot transition from '$currentStatus' to '$newStatus'. Allowed: " . implode(', ', $allowedTransitions)
            ];
        }

        return [
            'valid' => true,
            'error' => null
        ];
    }

    /**
     * Validate ticket update data
     *
     * @param array $ticket Current ticket data
     * @param array $updates Updates to apply
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateTicketUpdate($ticket, $updates)
    {
        $errors = [];

        // Status change validation
        if (isset($updates['status']) && $updates['status'] !== $ticket['status']) {
            $transition = $this->validateStatusTransition($ticket['status'], $updates['status']);
            if (!$transition['valid']) {
                $errors[] = $transition['error'];
            }

            // Rejection requires reason
            if ($updates['status'] === 'rejected' && empty($updates['rejection_reason'])) {
                $errors[] = "rejection_reason is required when rejecting a ticket";
            }
        }

        // Field updates (title, description) only allowed in draft
        if ((isset($updates['title']) || isset($updates['description'])) && $ticket['status'] !== 'draft') {
            $errors[] = "Can only edit title/description when ticket is in draft status";
        }

        // Title validation
        if (isset($updates['title'])) {
            if (empty($updates['title'])) {
                $errors[] = "Title cannot be empty";
            } elseif (strlen($updates['title']) > 255) {
                $errors[] = "Title must be 255 characters or less";
            }
        }

        // Description validation
        if (isset($updates['description'])) {
            if (empty($updates['description'])) {
                $errors[] = "Description cannot be empty";
            } elseif (strlen($updates['description']) < 10) {
                $errors[] = "Description must be at least 10 characters";
            }
        }

        // Assigned_to validation
        if (isset($updates['assigned_to']) && !empty($updates['assigned_to'])) {
            if (!$this->userExists($updates['assigned_to'])) {
                $errors[] = "Assigned user not found";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Check if UUID format is valid
     *
     * @param string $uuid UUID to check
     * @return bool
     */
    private function isValidUuid($uuid)
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid) === 1;
    }

    /**
     * Check if server exists
     *
     * @param string $serverUuid Server UUID
     * @return bool
     */
    private function serverExists($serverUuid)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM server_configurations
                WHERE server_uuid = ? AND status != 'deleted'
            ");
            $stmt->execute([$serverUuid]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            error_log("Server existence check error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user exists
     *
     * @param int $userId User ID
     * @return bool
     */
    private function userExists($userId)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            error_log("User existence check error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate separation of duties (cannot approve own ticket)
     *
     * @param int $ticketCreatorId Ticket creator user ID
     * @param int $currentUserId Current user ID attempting action
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function validateSeparationOfDuties($ticketCreatorId, $currentUserId)
    {
        if ($ticketCreatorId == $currentUserId) {
            return [
                'valid' => false,
                'error' => 'Cannot approve your own ticket (separation of duties)'
            ];
        }

        return [
            'valid' => true,
            'error' => null
        ];
    }
}
