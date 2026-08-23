<?php
/**
 * RequestActionExecutor.php — performs the work an approved Request asked for.
 *
 * THE MODEL THIS REPLACES
 * ----------------------
 * Until 2026-08-23 an approval GRANTED the requester a permission for 24 hours
 * and let them do the job themselves. Every phase of that design was spent
 * fencing the grant: per-configuration scoping, a blocked delete, a permission
 * split to stop a hardware grant renaming a build. Handing out authority is not
 * the same as doing the job, and the fences kept leaking.
 *
 * Now the approval does the job. The requester never gains a permission — not
 * for 24 hours, not for a second. There is nothing to scope, nothing to expire
 * and nothing to revoke, because no authority ever moves.
 *
 * WHERE IT RUNS
 * -------------
 * From PipelineManager::applyStageEffect(), which is called INSIDE
 * completeStage()'s open transaction. That placement is the whole failure
 * story: BaseCommand::execute() joins an open transaction rather than nesting,
 * and when it does not own that transaction it throws CommandFailed WITHOUT
 * rolling back — leaving the decision to us. So a failed action returns
 * success=false, completeStage() rolls the approval back with it, and the
 * request stays open showing the engine's own error. An approval can never read
 * "done" while the work it promised did not happen.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * No deletes, of any kind. That is the existing policy, and it is not merely
 * inherited: BaseFunctions::deleteComponent() is still a bare DELETE with no
 * in-use or configuration-reference guard, so it can destroy an inventory row a
 * live server depends on. Automating that would be worse than granting it.
 *
 * @package BDC_IMS
 * @subpackage Pipelines
 */

require_once(__DIR__ . '/../commands/AddComponentCommand.php');
require_once(__DIR__ . '/../commands/RemoveComponentCommand.php');
require_once(__DIR__ . '/../commands/ReplaceComponentCommand.php');
require_once(__DIR__ . '/../commands/TransitionStatusCommand.php');
require_once(__DIR__ . '/../state/StatusMap.php');
require_once(__DIR__ . '/../../config/WorkflowConfig.php');

class RequestActionExecutor
{
    /** @var PDO */
    private $pdo;

    /**
     * The registry. An action_type absent from this map cannot be authored,
     * cannot be stored and cannot be run — see validateShape().
     *
     * 'scope' is which half of the product the action belongs to; the Request
     * Types editor groups the ceiling checkboxes by it.
     */
    const ACTION_TYPES = [
        'server.component.add' => [
            'label'    => 'Add a component to a server',
            'scope'    => 'server',
            'required' => ['config_uuid', 'component_type', 'component_uuid'],
            'optional' => ['serial_number', 'slot_position', 'parent_nic_uuid', 'port_index', 'notes'],
        ],
        'server.component.remove' => [
            'label'    => 'Remove a component from a server',
            'scope'    => 'server',
            'required' => ['config_uuid', 'component_type', 'component_uuid'],
            'optional' => ['serial_number', 'cascade'],
        ],
        'server.component.replace' => [
            'label'    => 'Replace a component in a server',
            'scope'    => 'server',
            'required' => ['config_uuid', 'component_type', 'old_component_uuid', 'new_component_uuid'],
            'optional' => ['old_serial_number', 'serial_number', 'slot_position', 'notes'],
        ],
        'server.config.create' => [
            'label'    => 'Create a new server configuration',
            'scope'    => 'server',
            'required' => ['server_name'],
            'optional' => ['description', 'location', 'rack_position', 'is_virtual', 'is_sandbox'],
        ],
        'server.config.update' => [
            'label'    => 'Update a server\'s details',
            'scope'    => 'server',
            'required' => ['config_uuid', 'fields'],
            'optional' => [],
        ],
        'server.config.transition' => [
            'label'    => 'Change a server\'s status',
            'scope'    => 'server',
            'required' => ['config_uuid', 'to_status'],
            'optional' => ['notes'],
        ],
        'inventory.component.add' => [
            'label'    => 'Add a component to inventory',
            'scope'    => 'inventory',
            'required' => ['component_type', 'data'],
            'optional' => [],
        ],
        'inventory.component.edit' => [
            'label'    => 'Update an inventory record',
            'scope'    => 'inventory',
            'required' => ['component_type', 'inventory_id', 'data'],
            'optional' => [],
        ],
    ];

    /**
     * server.config.update writes these and only these.
     *
     * configuration_status is DELIBERATELY ABSENT. Finalizing runs comprehensive
     * validation and refuses virtual configs in server-finalize-config, and every
     * other lifecycle move is TransitionStatusCommand's job — which is why
     * server.config.transition exists as a separate action. One door per state
     * change, the same rule handleUpdateConfiguration() enforces at
     * server_api.php:216.
     */
    const UPDATABLE_CONFIG_FIELDS = ['server_name', 'description', 'location', 'rack_position', 'notes'];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return string[] every action type, for validation and the editor UI. */
    public static function actionTypes()
    {
        return array_keys(self::ACTION_TYPES);
    }

    /** @return array|null label/scope/payload-shape for one action type. */
    public static function describe($actionType)
    {
        return isset(self::ACTION_TYPES[$actionType]) ? self::ACTION_TYPES[$actionType] : null;
    }

    /**
     * A one-line, human-readable rendering of a stored action, for the request
     * list and the approver's confirmation. Display only — never an input to
     * any authorization decision.
     */
    public static function summarise($actionType, array $payload)
    {
        $spec = self::describe($actionType);
        if ($spec === null) {
            return 'Unknown action';
        }

        $type = isset($payload['component_type']) ? $payload['component_type'] : '?';
        $where = self::shortUuid(isset($payload['config_uuid']) ? $payload['config_uuid'] : '');

        switch ($actionType) {
            case 'server.component.add':
                return "Add {$type} to server {$where}";
            case 'server.component.remove':
                return "Remove {$type} from server {$where}";
            case 'server.component.replace':
                return "Replace {$type} in server {$where}";
            case 'server.config.create':
                return 'Create server "' . (isset($payload['server_name']) ? $payload['server_name'] : '?') . '"';
            case 'server.config.update':
                return "Update details of server {$where}";
            case 'server.config.transition':
                return "Set server {$where} to " . (isset($payload['to_status']) ? $payload['to_status'] : '?');
            case 'inventory.component.add':
                return "Add a {$type} to inventory";
            case 'inventory.component.edit':
                return "Update {$type} inventory record #"
                    . (isset($payload['inventory_id']) ? $payload['inventory_id'] : '?');
        }
        return $spec['label'];
    }

    private static function shortUuid($uuid)
    {
        $uuid = (string)$uuid;
        return $uuid === '' ? '?' : substr($uuid, 0, 8);
    }

    /**
     * Shape-check one action without changing anything, plus a dryRun() through
     * the real ValidationEngine where the action is command-backed.
     *
     * Called at SUBMIT time so an impossible request is refused while the
     * requester is still looking at it, rather than after it has cost an admin
     * an approval. dryRun() locks and rolls back, so it is a pure read.
     *
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function preflight($actionType, array $payload)
    {
        $errors = $this->validateShape($actionType, $payload);
        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // A dry run needs the configuration to exist already, so it applies to
        // the actions that name one. server.config.create has nothing to dry-run
        // against, by definition.
        try {
            $command = $this->buildCommand($actionType, $payload, 0);
            if ($command !== null) {
                $verdict = $command->dryRun();
                if ($verdict->blocking()) {
                    return [
                        'valid'  => false,
                        'errors' => ['This would be rejected: ' . $this->verdictSummary($verdict)],
                    ];
                }
            }
        } catch (CommandFailed $e) {
            return ['valid' => false, 'errors' => [$e->getMessage()]];
        } catch (Exception $e) {
            error_log('RequestActionExecutor::preflight error: ' . $e->getMessage());
            return ['valid' => false, 'errors' => ['Could not check this action: ' . $e->getMessage()]];
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * Perform one action.
     *
     * @param string $actionType
     * @param array  $payload
     * @param int    $subjectUserId the REQUESTER. The work is done on their
     *               behalf and anything created belongs to them — mirroring the
     *               retired grant effect, whose recipient was always
     *               tickets.created_by and never anything from the request body.
     * @param int    $approverId    recorded for audit; never the owner.
     * @return array ['success' => bool, 'errors' => string[], 'result' => array|null]
     */
    public function execute($actionType, array $payload, $subjectUserId, $approverId)
    {
        $errors = $this->validateShape($actionType, $payload);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'result' => null];
        }

        try {
            switch ($actionType) {
                case 'server.component.add':
                case 'server.component.remove':
                case 'server.component.replace':
                case 'server.config.transition':
                    return $this->runCommand($actionType, $payload, $subjectUserId);

                case 'server.config.create':
                    return $this->createConfiguration($payload, $subjectUserId);

                case 'server.config.update':
                    return $this->updateConfiguration($payload);

                case 'inventory.component.add':
                    return $this->addInventoryComponent($payload, $subjectUserId);

                case 'inventory.component.edit':
                    return $this->editInventoryComponent($payload, $subjectUserId);
            }

            // Unreachable: validateShape() rejects anything unknown.
            return ['success' => false, 'errors' => ["Unsupported action '$actionType'"], 'result' => null];
        } catch (CommandFailed $e) {
            // The engine's own refusal — surface it verbatim. It is the most
            // useful thing an approver can be told, and it names the real cause
            // (component_unavailable, validation_blocked, revision_mismatch, …).
            return [
                'success' => false,
                'errors'  => [$e->getMessage()],
                'result'  => ['error_code' => $e->errorType, 'message' => $e->getMessage()],
            ];
        } catch (Exception $e) {
            error_log("RequestActionExecutor::execute($actionType) error: " . $e->getMessage());
            return [
                'success' => false,
                'errors'  => ['Could not perform this action: ' . $e->getMessage()],
                'result'  => ['error_code' => 'executor_exception', 'message' => $e->getMessage()],
            ];
        }
    }

    // ---------------------------------------------------------------- validation

    /**
     * Fail closed on anything unrecognised: an unknown action type, a missing
     * required key, or a key the action does not declare. A request that
     * promises to do something and quietly does something else is the failure
     * mode this whole class exists to avoid.
     *
     * @return string[] errors; empty when the payload is well formed
     */
    private function validateShape($actionType, array $payload)
    {
        $spec = self::describe($actionType);
        if ($spec === null) {
            return ["Unknown action type '$actionType'"];
        }

        $errors = [];
        foreach ($spec['required'] as $key) {
            if (!array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                $errors[] = "$actionType: '$key' is required";
            }
        }

        $allowed = array_merge($spec['required'], $spec['optional']);
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors[] = "$actionType: unexpected parameter '$key'";
            }
        }
        if (!empty($errors)) {
            return $errors;
        }

        $types = WorkflowConfig::getValidComponentTypes();
        if (isset($payload['component_type']) && !in_array($payload['component_type'], $types, true)) {
            $errors[] = "Unknown component type '{$payload['component_type']}'";
        }

        foreach (['config_uuid', 'component_uuid', 'old_component_uuid', 'new_component_uuid'] as $key) {
            if (!empty($payload[$key]) && !preg_match('/^[0-9a-fA-F-]{32,36}$/', (string)$payload[$key])) {
                $errors[] = "$key does not look like a UUID";
            }
        }

        if ($actionType === 'server.config.transition') {
            $valid = array_keys(StatusMap::CONFIG_V2_TO_LEGACY);
            if (!in_array($payload['to_status'], $valid, true)) {
                $errors[] = "Unknown target status '{$payload['to_status']}'. One of: " . implode(', ', $valid);
            }
        }

        if ($actionType === 'server.config.update') {
            if (!is_array($payload['fields']) || empty($payload['fields'])) {
                $errors[] = 'server.config.update: fields must be a non-empty object';
            } else {
                foreach (array_keys($payload['fields']) as $field) {
                    if (!in_array($field, self::UPDATABLE_CONFIG_FIELDS, true)) {
                        $errors[] = "server.config.update cannot change '$field'"
                            . ($field === 'configuration_status'
                                ? ' — a status change is a separate action (server.config.transition)'
                                : '');
                    }
                }
                if (array_key_exists('server_name', $payload['fields'])
                    && trim((string)$payload['fields']['server_name']) === '') {
                    $errors[] = 'Server name cannot be empty';
                }
            }
        }

        if (in_array($actionType, ['inventory.component.add', 'inventory.component.edit'], true)) {
            if (!is_array($payload['data']) || empty($payload['data'])) {
                $errors[] = "$actionType: data must be a non-empty object";
            }
        }

        if ($actionType === 'inventory.component.edit' && !ctype_digit((string)$payload['inventory_id'])) {
            $errors[] = 'inventory_id must be numeric';
        }

        return $errors;
    }

    // ---------------------------------------------------------------- commands

    /**
     * Build the command for a command-backed action, or null when the action is
     * not command-backed. $actor is threaded through so that dryRun() and
     * execute() build the identical object.
     *
     * @return BaseCommand|null
     */
    private function buildCommand($actionType, array $payload, $actor)
    {
        switch ($actionType) {
            case 'server.component.add':
                return new AddComponentCommand(
                    $this->pdo,
                    $payload['config_uuid'],
                    $payload['component_type'],
                    $payload['component_uuid'],
                    $this->addOptions($payload),
                    $actor
                );

            case 'server.component.remove':
                return new RemoveComponentCommand(
                    $this->pdo,
                    $payload['config_uuid'],
                    $payload['component_type'],
                    $payload['component_uuid'],
                    $this->optionalString($payload, 'serial_number'),
                    !empty($payload['cascade']),
                    $actor
                );

            case 'server.component.replace':
                return new ReplaceComponentCommand(
                    $this->pdo,
                    $payload['config_uuid'],
                    $payload['component_type'],
                    $payload['old_component_uuid'],
                    $this->optionalString($payload, 'old_serial_number'),
                    $payload['new_component_uuid'],
                    $this->addOptions($payload),
                    $actor
                );

            case 'server.config.transition':
                return new TransitionStatusCommand(
                    $this->pdo,
                    $payload['config_uuid'],
                    $payload['to_status'],
                    (string)(isset($payload['notes']) ? $payload['notes'] : 'Applied by an approved Request'),
                    (int)$actor
                );
        }
        return null;
    }

    private function optionalString(array $payload, $key)
    {
        return (isset($payload[$key]) && $payload[$key] !== '') ? (string)$payload[$key] : null;
    }

    /** The ServerBuilder options vocabulary, built from whatever the payload carries. */
    private function addOptions(array $payload)
    {
        $options = [];
        foreach (['serial_number', 'slot_position', 'parent_nic_uuid', 'port_index', 'notes'] as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                $options[$key] = $payload[$key];
            }
        }
        return $options;
    }

    private function runCommand($actionType, array $payload, $subjectUserId)
    {
        if (CommandLayer::mode() === 'off') {
            // Refuse rather than silently fall back to the legacy path: an
            // approval must do exactly what the request said, through the same
            // engine every other write goes through.
            return [
                'success' => false,
                'errors'  => ['The command layer is disabled on this server, so approvals cannot perform hardware changes'],
                'result'  => null,
            ];
        }

        $command = $this->buildCommand($actionType, $payload, $subjectUserId);
        $result = $command->execute();

        return [
            'success' => true,
            'errors'  => [],
            'result'  => [
                'action'      => $actionType,
                'config_uuid' => $payload['config_uuid'],
                'revision'    => $result->revision,
            ],
        ];
    }

    private function verdictSummary($verdict)
    {
        $messages = [];
        foreach ($verdict->failures() as $failure) {
            $messages[] = method_exists($failure, 'message') ? $failure->message() : (string)$failure;
        }
        return !empty($messages) ? implode('; ', array_slice($messages, 0, 3)) : 'validation failed';
    }

    // ---------------------------------------------------------------- servers

    private function createConfiguration(array $payload, $subjectUserId)
    {
        $builder = new ServerBuilder($this->pdo);

        $options = [];
        foreach (['description', 'location', 'rack_position', 'is_virtual', 'is_sandbox'] as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                $options[$key] = $payload[$key];
            }
        }

        // created_by is the REQUESTER: the build is theirs, and
        // userCanActOnConfig() lets an owner act on their own configuration —
        // which is what makes an approved "new server" actually usable to them.
        $result = $builder->createConfiguration(trim((string)$payload['server_name']), $subjectUserId, $options);

        if (empty($result['success'])) {
            return [
                'success' => false,
                'errors'  => [!empty($result['message']) ? $result['message'] : 'Could not create the server configuration'],
                'result'  => null,
            ];
        }

        $configUuid = null;
        foreach (['config_uuid', 'configUuid', 'uuid'] as $key) {
            if (!empty($result[$key])) {
                $configUuid = $result[$key];
                break;
            }
        }

        return [
            'success' => true,
            'errors'  => [],
            'result'  => [
                'action'      => 'server.config.create',
                'config_uuid' => $configUuid,
                'server_name' => $payload['server_name'],
                'owner'       => (int)$subjectUserId,
            ],
        ];
    }

    /**
     * Attribute-only update, guarded the way handleUpdateConfiguration() guards
     * it: a finalized build is not editable through this door.
     */
    private function updateConfiguration(array $payload)
    {
        $stmt = $this->pdo->prepare(
            'SELECT config_uuid, configuration_status FROM server_configurations WHERE config_uuid = ? FOR UPDATE'
        );
        $stmt->execute([$payload['config_uuid']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            return ['success' => false, 'errors' => ['Server configuration not found'], 'result' => null];
        }
        if ((int)$config['configuration_status'] === 3) {
            return [
                'success' => false,
                'errors'  => ['That configuration is finalized, so its details cannot be changed'],
                'result'  => null,
            ];
        }

        $sets = [];
        $params = [];
        $changed = [];
        foreach (self::UPDATABLE_CONFIG_FIELDS as $field) {
            if (array_key_exists($field, $payload['fields'])) {
                $sets[] = "`$field` = ?";
                $params[] = trim((string)$payload['fields'][$field]);
                $changed[] = $field;
            }
        }
        if (empty($sets)) {
            return ['success' => false, 'errors' => ['Nothing to update'], 'result' => null];
        }

        $params[] = $payload['config_uuid'];
        $this->pdo->prepare(
            'UPDATE server_configurations SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE config_uuid = ?'
        )->execute($params);

        return [
            'success' => true,
            'errors'  => [],
            'result'  => [
                'action'      => 'server.config.update',
                'config_uuid' => $payload['config_uuid'],
                'fields'      => $changed,
            ],
        ];
    }

    // ---------------------------------------------------------------- inventory

    /**
     * BaseFunctions is loaded by api.php before any handler runs, so these use
     * function_exists() rather than require — requiring it here would drag in
     * JWTHelper::init(), which throws when JWT_SECRET is unset. That is the same
     * guard PipelineManager already applies around userHasRole().
     */
    private function addInventoryComponent(array $payload, $subjectUserId)
    {
        if (!function_exists('addComponent')) {
            error_log('RequestActionExecutor: addComponent() unavailable');
            return ['success' => false, 'errors' => ['Inventory functions are unavailable'], 'result' => null];
        }

        // UUID validation against the ims-data JSON happens inside addComponent()
        // and is never bypassed — including here.
        $result = addComponent($this->pdo, $payload['component_type'], $payload['data'], $subjectUserId);

        if (empty($result) || empty($result['id'])) {
            $message = (is_array($result) && !empty($result['message']))
                ? $result['message']
                : 'Could not add the component to inventory';
            return ['success' => false, 'errors' => [$message], 'result' => null];
        }

        return [
            'success' => true,
            'errors'  => [],
            'result'  => [
                'action'         => 'inventory.component.add',
                'component_type' => $payload['component_type'],
                'inventory_id'   => $result['id'],
                'asset_tag'      => isset($result['asset_tag']) ? $result['asset_tag'] : null,
                'uuid'           => isset($result['uuid']) ? $result['uuid'] : null,
            ],
        ];
    }

    private function editInventoryComponent(array $payload, $subjectUserId)
    {
        if (!function_exists('updateComponent')) {
            error_log('RequestActionExecutor: updateComponent() unavailable');
            return ['success' => false, 'errors' => ['Inventory functions are unavailable'], 'result' => null];
        }

        $ok = updateComponent(
            $this->pdo,
            $payload['component_type'],
            (int)$payload['inventory_id'],
            $payload['data'],
            $subjectUserId
        );

        if (!$ok) {
            return ['success' => false, 'errors' => ['Could not update that inventory record'], 'result' => null];
        }

        return [
            'success' => true,
            'errors'  => [],
            'result'  => [
                'action'         => 'inventory.component.edit',
                'component_type' => $payload['component_type'],
                'inventory_id'   => (int)$payload['inventory_id'],
                'fields'         => array_keys($payload['data']),
            ],
        ];
    }
}
