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
require_once(__DIR__ . '/../rack/ServerRelocation.php');
require_once(__DIR__ . '/../location/LocationResolver.php');
require_once(__DIR__ . '/../location/ComponentRelocation.php');

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
        // 2026-08-26. Moving a server is admin work -- api.php role-gates the
        // whole rack module -- so this is the only route for anyone else. The
        // requester never gains rack.assign; the approval performs the move.
        //
        // rack_uuid + start_u are OPTIONAL because "put it at this site but not
        // in a rack yet" is a real request. Given a rack, the U is required and
        // is checked against the rack's real occupancy at approval time.
        'server.relocate' => [
            'label'    => 'Move a server to another location / rack / U',
            'scope'    => 'server',
            'required' => ['config_uuid', 'location_uuid'],
            // location_name / rack_name are DISPLAY-ONLY snapshots the client
            // sends so the request list and the approver's confirmation can read
            // "move to Jaipur Office - RACK 12 - U8" without a join. They are
            // never read when performing the move -- the uuids are -- so a stale
            // or forged name misleads nobody about what actually happens.
            'optional' => ['rack_uuid', 'start_u', 'reason', 'location_name', 'rack_name'],
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
        // 2026-08-26. The Hardware Handover action: get a loose part from the
        // site it is at to the site it is needed at.
        //
        // Scope is 'inventory' rather than 'server' because it moves STOCK, not
        // a machine -- and because the Request Types editor renders exactly the
        // two groups, so an action belonging to neither would never appear as a
        // tickable ceiling.
        //
        // inventory_id, NOT component_uuid: a UUID names a MODEL, and the whole
        // problem is that two units of one model can be at two different sites.
        // Only an inventory row identifies the object somebody has to carry.
        //
        // handover_user_id names the person who will carry it. It is RECORDED,
        // and PipelineManager uses it to OWN the confirmation step -- it is
        // never an authorization input. Guard 2 stands: the work is performed on
        // behalf of tickets.created_by, never anyone named in a payload.
        //
        // The *_name keys are display-only snapshots the client sends so the
        // request list reads "Noida Yotta -> Jaipur Office" without a join,
        // exactly as server.relocate's location_name does. They are never read
        // when performing the move.
        'inventory.component.relocate' => [
            'label'    => 'Move a component to another location',
            'scope'    => 'inventory',
            'required' => ['component_type', 'inventory_id', 'location_uuid'],
            'optional' => ['store_location', 'reason', 'handover_user_id',
                           'component_name', 'serial_number',
                           'from_location_name', 'to_location_name'],
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
     *
     * rack_position was REMOVED on 2026-08-26. It is derived from the real
     * rack_servers placement (RackPlacement.php:9-13), so a value typed into a
     * request survived only until the next sync -- long enough for an approver to
     * believe it, not long enough to be true. Moving a server is what
     * `server.relocate` is for, and it moves the components too.
     */
    const UPDATABLE_CONFIG_FIELDS = ['server_name', 'description', 'location', 'notes'];

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
            case 'server.relocate':
                // Named, not uuid'd: an approver reading "move to Jaipur Office"
                // can sanity-check it; "move to a1b2c3d4" tells them nothing.
                return "Move server {$where} to " . self::relocateTargetLabel($payload);
            case 'inventory.component.add':
                return "Add a {$type} to inventory";
            case 'inventory.component.edit':
                return "Update {$type} inventory record #"
                    . (isset($payload['inventory_id']) ? $payload['inventory_id'] : '?');
            case 'inventory.component.relocate':
                // Named, not uuid'd, for the same reason server.relocate is: an
                // approver reading "Noida Yotta -> Jaipur Office" can sanity-check
                // it; a pair of short uuids tells them nothing.
                return self::handoverLabel($type, $payload);
        }
        return $spec['label'];
    }

    /**
     * A readable one-liner for a component handover.
     *
     * Falls back to the serial number, then the inventory id, because those are
     * what identify the physical object. The model name is only present when the
     * client sent it, and summarise() is static with no PDO to look one up.
     */
    private static function handoverLabel($type, array $payload)
    {
        $what = !empty($payload['component_name']) ? $payload['component_name'] : $type;
        if (!empty($payload['serial_number'])) {
            $what .= ' SN ' . $payload['serial_number'];
        } elseif (!empty($payload['inventory_id'])) {
            $what .= ' #' . (int)$payload['inventory_id'];
        }

        $to = !empty($payload['to_location_name'])
            ? $payload['to_location_name']
            : (!empty($payload['location_uuid']) ? 'location ' . self::shortUuid($payload['location_uuid']) : '?');

        return !empty($payload['from_location_name'])
            ? "Hand over {$what} from {$payload['from_location_name']} to {$to}"
            : "Hand over {$what} to {$to}";
    }

    /**
     * A readable destination for a relocate action.
     *
     * summarise() is static and has no PDO, so the names are read from the
     * payload when the client put them there (it does -- the Move dialog knows
     * them already) and fall back to a short uuid when it did not. Display only.
     */
    private static function relocateTargetLabel(array $payload)
    {
        $bits = [];

        if (!empty($payload['location_name'])) {
            $bits[] = $payload['location_name'];
        } elseif (!empty($payload['location_uuid'])) {
            $bits[] = 'location ' . self::shortUuid($payload['location_uuid']);
        }

        if (!empty($payload['rack_name'])) {
            $bits[] = $payload['rack_name'];
        } elseif (!empty($payload['rack_uuid'])) {
            $bits[] = 'rack ' . self::shortUuid($payload['rack_uuid']);
        }

        if (!empty($payload['start_u'])) {
            $bits[] = 'U' . (int)$payload['start_u'];
        }

        return empty($bits) ? '?' : implode(" \u{00B7} ", $bits);
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
     * @param int|null $ticketId    the request this action belongs to, threaded
     *               through so an action that keeps its own history (currently
     *               server.relocate -> server_movements.ticket_id) can say which
     *               request authorised it. Deliberately a PARAMETER and not a
     *               payload key: the payload is client-supplied, and a request
     *               must not be able to name a different request as its own
     *               authority.
     * @return array ['success' => bool, 'errors' => string[], 'result' => array|null]
     */
    public function execute($actionType, array $payload, $subjectUserId, $approverId, $ticketId = null)
    {
        $errors = $this->validateShape($actionType, $payload);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'result' => null];
        }

        // THE LOCATION GATE. Fitting a part into a server that is at another
        // site is not something an approval may do: the install would re-stamp
        // the part with the server's address, producing a record of hardware in
        // a rack nobody carried it to. Checked HERE and not in preflight()
        // because at submit time the mismatch is the EXPECTED state -- refusing
        // there would make the Hardware Handover that fixes it unreachable.
        //
        // Returns a plain failure, so completeStage() rolls the whole approval
        // back exactly as it does for a rack overlap refusal.
        $gate = $this->locationGate($actionType, $payload);
        if ($gate !== null) {
            return $gate;
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

                case 'server.relocate':
                    return $this->relocateServer($payload, $subjectUserId, $ticketId);

                case 'inventory.component.add':
                    return $this->addInventoryComponent($payload, $subjectUserId);

                case 'inventory.component.edit':
                    return $this->editInventoryComponent($payload, $subjectUserId);

                case 'inventory.component.relocate':
                    return $this->relocateComponent($payload, $subjectUserId, $ticketId);
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

        if ($actionType === 'inventory.component.relocate') {
            if (!ctype_digit((string)$payload['inventory_id'])) {
                $errors[] = 'inventory_id must be numeric -- a handover names one physical unit, not a model';
            }
            if (!preg_match('/^[0-9a-fA-F-]{32,36}$/', (string)$payload['location_uuid'])) {
                $errors[] = 'location_uuid does not look like a UUID';
            }
            if (isset($payload['handover_user_id']) && $payload['handover_user_id'] !== ''
                && !ctype_digit((string)$payload['handover_user_id'])) {
                $errors[] = 'handover_user_id must be numeric';
            }
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
    /**
     * Perform an approved relocation.
     *
     * Straight through to ServerRelocation::move(), the same call the "Move
     * server" dialog and Rack View make. Nothing about the move is
     * re-implemented here -- if it were, an approved request could put a server
     * somewhere the button would have refused, which is the exact class of
     * divergence that left components behind on a move in the first place.
     *
     * Runs inside completeStage()'s open transaction. move() notices that
     * (inTransaction) and does NOT open its own, so a refusal returns
     * success=false and the approval rolls back with it -- placement,
     * propagation and movement row together. An approval can never leave a
     * server half-moved.
     *
     * ticket_id is threaded through so the movement row records which request
     * authorised it, and $subjectUserId (the REQUESTER, not the approver)
     * becomes moved_by: the work is done on their behalf, matching every other
     * action here.
     */
    private function relocateServer(array $payload, $subjectUserId, $ticketId = null)
    {
        $result = ServerRelocation::move(
            $this->pdo,
            $payload['config_uuid'],
            [
                'location_uuid' => $payload['location_uuid'],
                'rack_uuid'     => isset($payload['rack_uuid']) ? $payload['rack_uuid'] : null,
                'start_u'       => isset($payload['start_u'])   ? $payload['start_u']   : null,
            ],
            [
                'user_id'   => $subjectUserId,
                'reason'    => isset($payload['reason']) ? $payload['reason'] : null,
                'ticket_id' => $ticketId,
            ]
        );

        if (!$result['success']) {
            return [
                'success' => false,
                'errors'  => [$result['message']],
                'result'  => ['error_code' => 'relocation_refused', 'message' => $result['message']],
            ];
        }

        $to = isset($result['data']['to']) ? $result['data']['to'] : [];

        return [
            'success' => true,
            'errors'  => [],
            'result'  => [
                'action'             => 'server.relocate',
                'config_uuid'        => $payload['config_uuid'],
                'location_name'      => isset($to['location_name']) ? $to['location_name'] : null,
                'rack_name'          => isset($to['rack_name'])     ? $to['rack_name']     : null,
                'start_u'            => isset($to['start_u'])       ? $to['start_u']       : null,
                'components_updated' => $result['data']['components_updated'],
                'message'            => $result['message'],
            ],
        ];
    }

    /**
     * Refuse an install whose part is demonstrably at another site.
     *
     * Applies to server.component.add and server.component.replace only -- the
     * two actions that put hardware INTO a machine. Removals, status changes,
     * config edits and every inventory-only action are untouched: they either
     * take hardware out (where the site cannot conflict) or never touch a server
     * at all. That is the "servers only" boundary the feature was asked for.
     *
     * THREE-VALUED, AND ONLY ONE VALUE BLOCKS. checkComponentForConfig() returns
     * match === null whenever it cannot tell -- the location seeders have not
     * been run, the server has no location, the part has none. Unknown is not a
     * mismatch, and blocking on it would break every existing request the day
     * this deploys.
     *
     * @return array|null null = proceed. An array is a refusal, in execute()'s
     *                    own return shape.
     */
    private function locationGate($actionType, array $payload)
    {
        if ($actionType !== 'server.component.add' && $actionType !== 'server.component.replace') {
            return null;
        }

        // A replace is judged on the part going IN. The part coming out is
        // leaving anyway, and wherever it came from it is physically present in
        // that server right now.
        $componentUuid = ($actionType === 'server.component.replace')
            ? (isset($payload['new_component_uuid']) ? $payload['new_component_uuid'] : null)
            : (isset($payload['component_uuid']) ? $payload['component_uuid'] : null);

        if (empty($componentUuid) || empty($payload['config_uuid']) || empty($payload['component_type'])) {
            return null;   // validateShape() has already spoken
        }

        try {
            $check = LocationResolver::checkComponentForConfig(
                $this->pdo,
                $payload['config_uuid'],
                $payload['component_type'],
                $componentUuid,
                isset($payload['serial_number']) ? $payload['serial_number'] : null
            );
        } catch (Throwable $e) {
            // A resolver failure must not block an approval. Logged loudly.
            error_log('RequestActionExecutor::locationGate error: ' . $e->getMessage());
            return null;
        }

        if (empty($check['supported']) || $check['match'] !== false) {
            return null;
        }

        $serverWhere = !empty($check['server']['location_name'])
            ? $check['server']['location_name']
            : 'another location';

        $partWhere = null;
        foreach ($check['units_elsewhere'] as $u) {
            if (!empty($u['location_name'])) {
                $partWhere = $u['location_name'];
                break;
            }
        }

        $message = 'This component is at ' . ($partWhere ?: 'a different site')
            . ', and the server is at ' . $serverWhere . '. '
            . 'Raise a Hardware Handover request to move the part first -- this request stays '
            . 'frozen until that one is complete. Nothing has been changed.';

        return [
            'success' => false,
            'errors'  => [$message],
            'result'  => [
                'error_code'      => 'location_mismatch',
                'message'         => $message,
                'server_location' => isset($check['server']['location_name']) ? $check['server']['location_name'] : null,
                'units_elsewhere' => $check['units_elsewhere'],
            ],
        ];
    }

    /**
     * Perform a component handover -- the child request an approver signs off so
     * a part can reach the server that needs it.
     *
     * Delegates to ComponentRelocation::move(), the single door for a
     * component-level move, for the same reason relocateServer() delegates to
     * ServerRelocation: a second implementation would let an approved request do
     * something the direct path would have refused.
     *
     * Runs inside completeStage()'s open transaction. move() notices that and
     * does not open its own, so a refusal ("it has been installed since this was
     * raised") returns success=false and the approval rolls back with it --
     * inventory write and movement row together.
     *
     * $subjectUserId (the REQUESTER, not the approver) becomes moved_by, matching
     * every other action here. handover_user_id is a different person entirely:
     * whoever is carrying the hardware.
     */
    private function relocateComponent(array $payload, $subjectUserId, $ticketId = null)
    {
        $target = ['location_uuid' => $payload['location_uuid']];
        // Only forwarded when the request actually said something about the
        // shelf -- ComponentRelocation keeps the existing note when the key is
        // absent, rather than blanking the only line saying where to pick it up.
        if (array_key_exists('store_location', $payload)) {
            $target['store_location'] = $payload['store_location'];
        }

        $result = ComponentRelocation::move(
            $this->pdo,
            $payload['component_type'],
            (int)$payload['inventory_id'],
            $target,
            [
                'user_id'          => $subjectUserId,
                'reason'           => isset($payload['reason']) ? $payload['reason'] : null,
                'ticket_id'        => $ticketId,
                'handover_user_id' => isset($payload['handover_user_id']) ? $payload['handover_user_id'] : null,
            ]
        );

        if (!$result['success']) {
            return [
                'success' => false,
                'errors'  => [$result['message']],
                'result'  => ['error_code' => 'handover_refused', 'message' => $result['message']],
            ];
        }

        return [
            'success' => true,
            'errors'  => [],
            'result'  => [
                'action'         => 'inventory.component.relocate',
                'component_type' => $payload['component_type'],
                'inventory_id'   => (int)$payload['inventory_id'],
                'moved'          => !empty($result['data']['moved']),
                'from'           => isset($result['data']['from']) ? $result['data']['from'] : null,
                'to'             => isset($result['data']['to'])   ? $result['data']['to']   : null,
                'message'        => $result['message'],
            ],
        ];
    }

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
