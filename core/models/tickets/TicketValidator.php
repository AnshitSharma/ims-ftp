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
require_once(__DIR__ . '/../../config/WorkflowConfig.php');

class TicketValidator
{
    private $pdo;
    private $componentDataService;
    private $compatibilityValidator;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->componentDataService = ComponentDataService::getInstance();
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
            if (!in_array($data['priority'], WorkflowConfig::getValidPriorities())) {
                $errors[] = "Invalid priority. Must be one of: " . implode(', ', WorkflowConfig::getValidPriorities());
            }
        }

        // Assignment validation - MANDATORY, mutually exclusive
        $hasAssignedTo = !empty($data['assigned_to']);
        $hasAssignedToRole = !empty($data['assigned_to_role']);

        if (!$hasAssignedTo && !$hasAssignedToRole) {
            $errors[] = "Assignment is required. Provide either 'assigned_to' (user ID) or 'assigned_to_role' (role ID)";
        }

        if ($hasAssignedTo && $hasAssignedToRole) {
            $errors[] = "Cannot assign to both user and role. Provide only one of 'assigned_to' or 'assigned_to_role'";
        }

        // Validate assigned user exists
        if ($hasAssignedTo) {
            if (!$this->userExists($data['assigned_to'])) {
                $errors[] = "Assigned user (ID: {$data['assigned_to']}) not found";
            }
        }

        // Validate assigned role exists
        if ($hasAssignedToRole) {
            if (!$this->roleExists($data['assigned_to_role'])) {
                $errors[] = "Assigned role (ID: {$data['assigned_to_role']}) not found";
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

        if (!is_array($items)) {
            return [
                'valid' => false,
                'errors' => ["Items must be an array"],
                'validated_items' => []
            ];
        }

        if (empty($items)) {
            return [
                'valid' => true,
                'errors' => [],
                'validated_items' => []
            ];
        }

        // Pre-load server config once for all items (fixes N+1)
        $serverComponents = null;
        if ($serverUuid) {
            $serverComponents = $this->loadServerComponents($serverUuid);
        }

        foreach ($items as $index => $item) {
            $itemErrors = $this->validateSingleItem($item, $index, $serverUuid, $serverComponents);
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
    private function validateSingleItem($item, $index, $serverUuid = null, $serverComponents = null)
    {
        $errors = [];
        $validatedItem = $item;

        $itemLabel = "Item #" . ((int)$index + 1);

        // Component type validation
        if (empty($item['component_type'])) {
            $errors[] = "$itemLabel: component_type is required";
        } elseif (!in_array($item['component_type'], WorkflowConfig::getValidComponentTypes())) {
            $errors[] = "$itemLabel: Invalid component_type. Must be one of: " . implode(', ', WorkflowConfig::getValidComponentTypes());
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
                try {
                    $isValid = $this->componentDataService->validateComponentUuid(
                        $item['component_type'],
                        $item['component_uuid']
                    );

                    if (!$isValid) {
                        $errors[] = "$itemLabel: Component UUID not found in specification files";
                        $validatedItem['is_validated'] = 0;
                    } else {
                        // UUID is valid - get component details
                        $validatedItem['is_validated'] = 1;
                        try {
                            $component = $this->componentDataService->findComponentByUuid(
                                $item['component_type'],
                                $item['component_uuid']
                            );
                            $brand = $component['brand'] ?? null;
                            $name = $component['name'] ?? $component['model'] ?? $component['model_name'] ?? $component['product_name'] ?? null;

                            // RAM: build "Brand Type CapacityGB Module"
                            if ($name === null && $item['component_type'] === 'ram') {
                                $parts = array_filter([$brand, $component['memory_type'] ?? null,
                                    isset($component['capacity_GB']) ? $component['capacity_GB'] . 'GB' : null,
                                    $component['module_type'] ?? null]);
                                $name = $parts ? implode(' ', $parts) : 'Unknown';
                                $brand = null; // already included in parts
                            }
                            // Storage: build "Brand Type CapacityGB"
                            if ($name === null && $item['component_type'] === 'storage') {
                                $cap = null;
                                if (isset($component['capacity_GB'])) {
                                    $cap = $component['capacity_GB'] >= 1000
                                        ? round($component['capacity_GB'] / 1000, 1) . 'TB'
                                        : $component['capacity_GB'] . 'GB';
                                }
                                $parts = array_filter([$brand, $component['storage_type'] ?? null, $cap]);
                                $name = $parts ? implode(' ', $parts) : 'Unknown';
                                $brand = null; // already included in parts
                            }

                            $name = $name ?? 'Unknown';
                            $validatedItem['component_name'] = $brand ? trim($brand . ' ' . $name) : $name;
                            $validatedItem['component_specs'] = json_encode($component);
                        } catch (Exception $e) {
                            $validatedItem['component_name'] = 'Unknown';
                            $validatedItem['component_specs'] = null;
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = "$itemLabel: UUID validation failed - " . $e->getMessage();
                    $validatedItem['is_validated'] = 0;
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
            if (!in_array($item['action'], WorkflowConfig::getValidActions())) {
                $errors[] = "$itemLabel: Invalid action. Must be one of: " . implode(', ', WorkflowConfig::getValidActions());
            }
        } else {
            $validatedItem['action'] = 'add'; // Default
        }

        // Compatibility checking (if server UUID provided and item is validated)
        if ($serverUuid && $serverComponents !== null && $validatedItem['is_validated'] == 1) {
            $compatibilityResult = $this->checkItemCompatibilityWithComponents(
                $validatedItem,
                $serverComponents
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
     * Load server components once for reuse across multiple item checks
     *
     * @param string $serverUuid Server UUID
     * @return array|null List of components or null if not found
     */
    private function loadServerComponents($serverUuid)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ? LIMIT 1");
            $stmt->execute([$serverUuid]);
            $server = $stmt->fetch(PDO::FETCH_ASSOC);
            return $server ? $this->getServerComponents($server) : null;
        } catch (Exception $e) {
            error_log("Server components load error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if item is compatible with pre-loaded server components
     *
     * @param array $item Validated item
     * @param array $existingComponents Pre-loaded server components
     * @return array ['compatible' => bool, 'notes' => string]
     */
    private function checkItemCompatibilityWithComponents($item, $existingComponents)
    {
        try {
            $newComponent = [
                'type' => $item['component_type'],
                'uuid' => $item['component_uuid']
            ];

            $issues = [];
            $warnings = [];

            foreach ($existingComponents as $existingComponent) {
                if ($existingComponent['type'] === $newComponent['type'] &&
                    $existingComponent['uuid'] === $newComponent['uuid']) {
                    continue;
                }

                if ($this->compatibilityValidator->canComponentTypesBeCompatible($newComponent['type'], $existingComponent['type'])) {
                    $result = $this->compatibilityValidator->checkComponentPairCompatibility($newComponent, $existingComponent);

                    if (!$result['compatible']) {
                        foreach ($result['issues'] as $issue) {
                            $issues[] = "Conflict with {$existingComponent['type']}: $issue";
                        }
                    }
                    if (!empty($result['warnings'])) {
                        foreach ($result['warnings'] as $warning) {
                            $warnings[] = "Warning with {$existingComponent['type']}: $warning";
                        }
                    }
                }
            }

            if (empty($issues)) {
                $notes = "Compatible";
                if (!empty($warnings)) {
                    $notes .= " (Warnings: " . implode('; ', $warnings) . ")";
                }
                return ['compatible' => true, 'notes' => $notes];
            }

            return ['compatible' => false, 'notes' => implode('; ', $issues)];
        } catch (Exception $e) {
            error_log("Compatibility check error: " . $e->getMessage());
            return ['compatible' => false, 'notes' => 'Compatibility check failed: ' . $e->getMessage()];
        }
    }

    /**
     * Extract all components from server configuration
     * 
     * @param array $server Server configuration row
     * @return array List of ['type' => string, 'uuid' => string]
     */
    private function getServerComponents($server)
    {
        $components = [];

        // 1. Motherboard
        if (!empty($server['motherboard_uuid'])) {
            $components[] = ['type' => 'motherboard', 'uuid' => $server['motherboard_uuid']];
        }

        // 2. Chassis
        if (!empty($server['chassis_uuid'])) {
            $components[] = ['type' => 'chassis', 'uuid' => $server['chassis_uuid']];
        }

        // 3. CPU (JSON object with 'cpus' array)
        if (!empty($server['cpu_configuration'])) {
            $cpuConfig = json_decode($server['cpu_configuration'], true);
            if (isset($cpuConfig['cpus']) && is_array($cpuConfig['cpus'])) {
                foreach ($cpuConfig['cpus'] as $cpu) {
                    if (!empty($cpu['uuid'])) {
                        $components[] = ['type' => 'cpu', 'uuid' => $cpu['uuid']];
                    }
                }
            }
        }

        // 4. RAM (JSON array)
        if (!empty($server['ram_configuration'])) {
            $ramConfig = json_decode($server['ram_configuration'], true);
            if (is_array($ramConfig)) {
                foreach ($ramConfig as $ram) {
                    if (!empty($ram['uuid'])) {
                        $components[] = ['type' => 'ram', 'uuid' => $ram['uuid']];
                    }
                }
            }
        }

        // 5. Storage (JSON array)
        if (!empty($server['storage_configuration'])) {
            $storageConfig = json_decode($server['storage_configuration'], true);
            if (is_array($storageConfig)) {
                foreach ($storageConfig as $storage) {
                    if (!empty($storage['uuid'])) {
                        $components[] = ['type' => 'storage', 'uuid' => $storage['uuid']];
                    }
                }
            }
        }

        // 6. NIC (JSON object with 'nics' array)
        if (!empty($server['nic_config'])) {
            $nicConfig = json_decode($server['nic_config'], true);
            if (isset($nicConfig['nics']) && is_array($nicConfig['nics'])) {
                foreach ($nicConfig['nics'] as $nic) {
                    if (!empty($nic['uuid'])) {
                        $components[] = ['type' => 'nic', 'uuid' => $nic['uuid']];
                    }
                }
            }
        }

        // 7. Caddy (JSON array)
        if (!empty($server['caddy_configuration'])) {
            $caddyConfig = json_decode($server['caddy_configuration'], true);
            if (is_array($caddyConfig)) {
                foreach ($caddyConfig as $caddy) {
                    if (!empty($caddy['uuid'])) {
                        $components[] = ['type' => 'caddy', 'uuid' => $caddy['uuid']];
                    }
                }
            }
        }

        // 8. HBA Card
        if (!empty($server['hbacard_uuid'])) {
            $components[] = ['type' => 'hbacard', 'uuid' => $server['hbacard_uuid']];
        }

        // 9. PCIe Cards (JSON array)
        if (!empty($server['pciecard_configurations'])) {
            $pcieConfig = json_decode($server['pciecard_configurations'], true);
            if (is_array($pcieConfig)) {
                foreach ($pcieConfig as $card) {
                    if (!empty($card['uuid'])) {
                        $components[] = ['type' => 'pciecard', 'uuid' => $card['uuid']];
                    }
                }
            }
        }

        return $components;
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
        if (!in_array($newStatus, WorkflowConfig::getValidStatuses())) {
            return [
                'valid' => false,
                'error' => "Invalid status: $newStatus"
            ];
        }

        // Check if transition is allowed
        $allowedTransitions = WorkflowConfig::getStatusTransitions()[$currentStatus] ?? [];

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
     * Check if a record exists in a table by column
     */
    private function recordExists($table, $column, $value)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
            $stmt->execute([$value]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            error_log("Existence check error ($table.$column): " . $e->getMessage());
            return false;
        }
    }

    private function serverExists($serverUuid)
    {
        return $this->recordExists('server_configurations', 'config_uuid', $serverUuid);
    }

    private function userExists($userId)
    {
        return $this->recordExists('users', 'id', $userId);
    }

    private function roleExists($roleId)
    {
        return $this->recordExists('roles', 'id', $roleId);
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
