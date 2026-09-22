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
require_once(__DIR__ . '/../../config/WorkflowConfig.php');

class TicketValidator
{
    private $pdo;
    private $componentDataService;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->componentDataService = ComponentDataService::getInstance();
    }

    /**
     * Validate a target server reference on its own.
     *
     * Public and standalone because the pipeline (Requests) engine needs THIS rule
     * without the rest of validateTicketBusinessRules(): that method also demands
     * ticket-level assignment fields, which a pipeline instance does not have —
     * its owners live on the snapshotted steps. So PipelineManager::createPipeline()
     * could not call it, and for a long time validated the target server nowhere
     * at all. An unchecked uuid is worse here than on a legacy ticket: the
     * approval scopes the temporary grant to it, and a uuid matching no row
     * produces a grant that unlocks nothing, silently.
     *
     * An empty value is VALID — naming a server is optional; it means the request
     * is not about one particular configuration.
     *
     * @param string|null $serverUuid
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateTargetServer($serverUuid)
    {
        $errors = [];

        if ($serverUuid !== null && trim((string)$serverUuid) !== '') {
            $serverUuid = trim((string)$serverUuid);
            if (!$this->isValidUuid($serverUuid)) {
                $errors[] = "Invalid target_server_uuid format";
            } elseif (!$this->serverExists($serverUuid)) {
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

        // Resolve the target once for all items. A uuid naming no configuration gets no
        // compatibility check -- validateTargetServer() is what reports that.
        $targetServer = null;
        if ($serverUuid) {
            $targetServer = $this->loadTargetServer($serverUuid);
        }

        foreach ($items as $index => $item) {
            $itemErrors = $this->validateSingleItem($item, $index, $serverUuid, $targetServer);
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
    private function validateSingleItem($item, $index, $serverUuid = null, $targetServer = null)
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
        if ($serverUuid && $targetServer !== null && $validatedItem['is_validated'] == 1) {
            $compatibilityResult = $this->checkItemCompatibility($validatedItem, $targetServer);
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
     * The target configuration's uuid if it exists, else null.
     *
     * @param string $serverUuid Server UUID
     * @return string|null
     */
    private function loadTargetServer($serverUuid)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT config_uuid FROM server_configurations WHERE config_uuid = ? LIMIT 1");
            $stmt->execute([$serverUuid]);
            $uuid = $stmt->fetchColumn();
            return $uuid === false ? null : (string)$uuid;
        } catch (Exception $e) {
            error_log("Target server load error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Would this item be accepted on the target configuration?
     *
     * F.1 (2026-09-21 audit): asked of ValidationEngine through ServerBuilder::
     * evaluateSpecCompatibility() -- the same answer the compatible-parts listing and the
     * add itself give, so a Request cannot promise a part the step will then refuse. It
     * used to be the legacy pairwise engine (ComponentCompatibility), which checked each
     * existing component against the item two at a time and so could never count: it
     * passed a third CPU on a two-socket board, a 17th DIMM in 16 slots, and a card with
     * no free slot. Replayed over all 74 configurations x 193 stocked models before the
     * switch (engine-compare, recorded in tasks/audit-2026-09-21-implementation.md).
     *
     * Only an ADD is judged here. A remove takes nothing new in, and a replace is judged
     * by ReplaceComponentCommand against the unit it replaces when the step runs --
     * evaluating either as an add would refuse, say, a like-for-like CPU swap in a full
     * two-socket build. A serverplatform item is judged by set-platform, which replaces
     * the board and chassis wholesale; ValidationEngine's rules do not take that type.
     *
     * @param array  $item       Validated item
     * @param string $serverUuid Target configuration
     * @return array ['compatible' => bool, 'notes' => string]
     */
    private function checkItemCompatibility($item, $serverUuid)
    {
        $action = $item['action'] ?? 'add';
        if ($action !== 'add') {
            return ['compatible' => true, 'notes' => "Not checked at request time: a $action is validated when its step runs"];
        }
        if ($item['component_type'] === 'serverplatform') {
            return ['compatible' => true, 'notes' => 'Not checked at request time: a platform is validated when it is set'];
        }

        try {
            require_once __DIR__ . '/../server/ServerBuilder.php';
            $builder = new ServerBuilder($this->pdo);
            $verdict = $builder->evaluateSpecCompatibility($serverUuid, $item['component_type'], $item['component_uuid']);
        } catch (Throwable $e) {
            error_log("Compatibility check error: " . $e->getMessage());
            return ['compatible' => false, 'notes' => 'Compatibility could not be determined'];
        }

        if (empty($verdict['compatible'])) {
            return ['compatible' => false, 'notes' => $verdict['reason'] ?? 'Incompatible'];
        }
        $notes = 'Compatible';
        if (!empty($verdict['warnings'])) {
            $notes .= ' (Warnings: ' . implode('; ', array_unique($verdict['warnings'])) . ')';
        }
        return ['compatible' => true, 'notes' => $notes];
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
