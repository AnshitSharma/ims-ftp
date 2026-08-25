<?php
/**
 * PipelineManager.php
 *
 * Engine for running pipelines. A pipeline is a ticket
 * (tickets.pipeline_template_id IS NOT NULL) whose stages are snapshotted
 * into ticket_stage_progress at creation. The engine:
 *   - creates a pipeline from a type (snapshots stages, activates stage 1),
 *   - lets the active stage's owner claim it (role-owned stages lock to one
 *     person), complete it (auto-advancing to the next stage's owner),
 *   - supports reassigning a stage owner and cancelling the pipeline.
 *
 * Row-level access for stage actions is enforced here (assigned user OR member
 * of assigned role OR the claimer OR pipeline.manage). Reuses the existing
 * TicketValidator / TicketItemService / TicketHistoryService.
 *
 * @package BDC_IMS
 * @subpackage Pipelines
 */

require_once(__DIR__ . '/PipelineTemplateManager.php');
require_once(__DIR__ . '/../tickets/TicketValidator.php');
require_once(__DIR__ . '/../tickets/TicketItemService.php');
require_once(__DIR__ . '/../tickets/TicketHistoryService.php');
require_once(__DIR__ . '/../../config/PipelineConfig.php');
require_once(__DIR__ . '/../../helpers/SchemaHelper.php');
require_once(__DIR__ . '/RequestActionExecutor.php');
require_once(__DIR__ . '/../../auth/TemporaryAccessManager.php');
require_once(__DIR__ . '/../state/StatusMap.php');

class PipelineManager
{
    private $pdo;
    private $templateManager;
    private $validator;
    private $itemService;
    private $historyService;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->templateManager = new PipelineTemplateManager($pdo);
        $this->validator = new TicketValidator($pdo);
        $this->itemService = new TicketItemService($pdo);
        $this->historyService = new TicketHistoryService($pdo);
    }

    /**
     * Create a pipeline from a type.
     *
     * @param int $templateId
     * @param array $data ['title','description','priority','target_server_uuid',
     *                     'items' => [...], 'stage_overrides' => [stageTemplateId => ['assignee_type','assignee_id']]]
     * @param int $userId
     * @param bool $hasManage caller holds pipeline.manage — lets them raise a
     *                        prerequisite on a request they are not involved in
     * @return array ['success','ticket_id','ticket_number','errors']
     */
    public function createPipeline($templateId, $data, $userId, $hasManage = false)
    {
        // Load + validate the type
        $template = $this->templateManager->getTemplate($templateId);
        if (!$template) {
            return ['success' => false, 'errors' => ['Pipeline type not found']];
        }
        if ((int)$template['is_active'] !== 1) {
            return ['success' => false, 'errors' => ['This pipeline type is archived and cannot be started']];
        }
        if (empty($template['stages'])) {
            return ['success' => false, 'errors' => ['This pipeline type has no stages']];
        }

        // Field validation (assignment comes from stages, not the ticket level)
        $errors = [];
        $title = trim($data['title'] ?? '');
        $description = trim($data['description'] ?? '');
        if ($title === '') {
            $errors[] = 'Title is required';
        } elseif (mb_strlen($title) > 255) {
            $errors[] = 'Title must be 255 characters or less';
        }
        // Description is optional: the title plus the type, access ask and item
        // list already say what a request is for, so an empty one is stored as ''
        // (the column is NOT NULL) rather than refused.
        if (mb_strlen($description) > 5000) {
            $errors[] = 'Description must not exceed 5000 characters';
        }
        $priority = $data['priority'] ?? 'medium';
        if (!in_array($priority, WorkflowConfig::getValidPriorities(), true)) {
            $errors[] = 'Invalid priority';
        }

        $targetServer = !empty($data['target_server_uuid']) ? trim((string)$data['target_server_uuid']) : null;

        // A named server is what any granted server permission gets SCOPED to, so
        // an unknown uuid is not a cosmetic problem: the grant would be attached
        // to a configuration that does not exist, hasScopedPermission() could
        // never match it, and the requester would be handed access that unlocks
        // nothing — with no error anywhere. Catch it at submit time instead.
        $serverCheck = $this->validator->validateTargetServer($targetServer);
        if (!$serverCheck['valid']) {
            $errors = array_merge($errors, $serverCheck['errors']);
        }

        // RETIRED 2026-08-23. A request used to name the PERMISSIONS it wanted,
        // and approval handed them over. Approval now performs the work instead
        // (see $actions below), so this is no longer an authorization input and
        // is no longer validated against any whitelist.
        //
        // Still normalised and capped rather than dropped outright: the column
        // holds real data for the requests raised before the change, and
        // getPipeline() keeps displaying it so their history stays readable.
        $requestedAccess = $this->normaliseRequestedAccess($data['requested_access'] ?? null);
        if (count($requestedAccess) > 40) {
            $errors[] = 'Too many permissions requested';
        }

        // What this request will PERFORM once approved. Shape-checked and
        // dry-run through the real validation engine here, so an impossible
        // request is refused while the requester is still looking at it rather
        // than after it has cost an admin an approval.
        $actions = $this->normaliseActions($data['actions'] ?? null);
        if ($actions === null) {
            $errors[] = 'actions must be a list of {action_type, payload} objects';
            $actions = [];
        }
        if (count($actions) > 50) {
            $errors[] = 'A request cannot carry more than 50 actions';
        }
        if (!empty($actions)) {
            // The ceiling is checked HERE as well as at approval, and not only
            // as a courtesy. Without it a request could be raised carrying an
            // action its own type is not allowed to perform, sit in the queue
            // looking ordinary, and then fail at the moment of approval — after
            // it had already cost an approver their decision. Refuse it while
            // the requester is still in front of the form.
            //
            // The approval-time check in applyStageEffect() remains the real
            // boundary: it reads the SNAPSHOT taken when the request was raised,
            // so editing the type afterwards cannot widen what it performs.
            $ceiling = $this->templateActionCeiling($template);

            $executor = new RequestActionExecutor($this->pdo);
            foreach ($actions as $index => $action) {
                $label = 'Action ' . ($index + 1);

                if (!in_array($action['action_type'], $ceiling, true)) {
                    $errors[] = "$label: this request type cannot perform '{$action['action_type']}'";
                    continue;
                }

                $check = $executor->preflight($action['action_type'], $action['payload']);
                if (!$check['valid']) {
                    foreach ($check['errors'] as $message) {
                        $errors[] = "$label: $message";
                    }
                }
            }
        }

        // The request this one is a PREREQUISITE for. Checked here so an
        // impossible link is refused while the requester is still looking at the
        // form; re-checked under a row lock inside the transaction below, which
        // is what actually makes "a parent is never approved while a blocking
        // child exists" true rather than merely likely.
        $parentTicketId = null;
        if (!empty($data['parent_ticket_id'])) {
            $parentCheck = $this->validateParent($data['parent_ticket_id'], $userId, $hasManage);
            if (!$parentCheck['valid']) {
                $errors = array_merge($errors, $parentCheck['errors']);
            } else {
                $parentTicketId = $parentCheck['parent_id'];
            }
        }

        // Validate items (reuse ticket item validation + UUID/compatibility checks)
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
        $itemsValidation = $this->validator->validateTicketItems($items, $targetServer);
        if (!$itemsValidation['valid']) {
            $errors = array_merge($errors, $itemsValidation['errors']);
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Resolve per-stage owners (template defaults, optionally overridden)
        $overrides = (isset($data['stage_overrides']) && is_array($data['stage_overrides'])) ? $data['stage_overrides'] : [];
        $resolvedStages = [];
        foreach ($template['stages'] as $stage) {
            $assigneeUserId = null;
            $assigneeRoleId = null;

            $override = $overrides[$stage['id']] ?? ($overrides[(string)$stage['id']] ?? null);
            if ($override && !empty($override['assignee_type']) && !empty($override['assignee_id'])) {
                if ($override['assignee_type'] === 'user' && $this->userExists((int)$override['assignee_id'])) {
                    $assigneeUserId = (int)$override['assignee_id'];
                } elseif ($override['assignee_type'] === 'role' && $this->roleExists((int)$override['assignee_id'])) {
                    $assigneeRoleId = (int)$override['assignee_id'];
                }
            }

            // Fall back to the template default owner
            if ($assigneeUserId === null && $assigneeRoleId === null && $stage['default_assignee']) {
                if ($stage['default_assignee']['type'] === 'user') {
                    $assigneeUserId = (int)$stage['default_assignee']['id'];
                } else {
                    $assigneeRoleId = (int)$stage['default_assignee']['id'];
                }
            }

            $resolvedStages[] = [
                'stage_template_id' => $stage['id'],
                'name' => $stage['name'],
                'position' => $stage['position'],
                'assigned_to_user_id' => $assigneeUserId,
                'assigned_to_role_id' => $assigneeRoleId,
                // Snapshotted, not joined live: editing a type does a full
                // DELETE + re-INSERT of pipeline_stages, so a live join would
                // dangle — and worse, it would let a type edit change what an
                // already-submitted request is about to grant.
                'effect_type' => $stage['effect_type'] ?? null,
                'effect_config' => $stage['effect_config'] ?? null,
            ];
        }

        try {
            $this->pdo->beginTransaction();

            // Lock the parent for the rest of this transaction. completeStage()
            // locks the same row before it probes for blocking children, so the
            // two serialise: a child can never be inserted in the window between
            // that probe finding nothing and the approval committing. Re-checked
            // under the lock because the parent may have been closed since the
            // pre-flight validation above.
            if ($parentTicketId !== null) {
                $stmt = $this->pdo->prepare(
                    "SELECT status FROM tickets WHERE id = ? AND pipeline_template_id IS NOT NULL FOR UPDATE"
                );
                $stmt->execute([$parentTicketId]);
                $parentRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$parentRow) {
                    $this->pdo->rollBack();
                    return ['success' => false, 'errors' => ['The request this would be a prerequisite for no longer exists']];
                }
                if (in_array($parentRow['status'], PipelineConfig::getTerminalStatuses(), true)) {
                    $this->pdo->rollBack();
                    return [
                        'success' => false,
                        'errors' => ['That request was just ' . $parentRow['status'] . ' — a prerequisite would have nothing left to unblock']
                    ];
                }
            }

            $ticketNumber = $this->generateTicketNumber();

            // requested_access arrives with 2026_08_21_002; write it only once
            // the column exists so the code is safe to deploy ahead of the seeder.
            $storeRequested = SchemaHelper::hasColumn($this->pdo, 'tickets', 'requested_access');
            // The type's NAME, copied onto the request. A request does not need
            // its type row to keep working - its steps were snapshotted above and
            // both read queries LEFT JOIN the type - but without this it would
            // lose the one thing the join was for. Arrives with 2026_08_22_006.
            $storeTypeName = SchemaHelper::hasColumn($this->pdo, 'tickets', 'pipeline_type_name');
            // parent_ticket_id arrives with 2026_08_25_007. validateParent()
            // has already REFUSED the request outright if a parent was asked
            // for and the column is missing, so reaching here with a parent
            // means the column exists.
            $storeParent = ($parentTicketId !== null);

            $stmt = $this->pdo->prepare("
                INSERT INTO tickets
                    (ticket_number, title, description, status, priority, target_server_uuid,
                     pipeline_template_id, created_by, created_at, submitted_at"
                     . ($storeRequested ? ", requested_access" : "")
                     . ($storeTypeName ? ", pipeline_type_name" : "")
                     . ($storeParent ? ", parent_ticket_id" : "") . ")
                VALUES (?, ?, ?, 'in_progress', ?, ?, ?, ?, NOW(), NOW()"
                     . ($storeRequested ? ", ?" : "")
                     . ($storeTypeName ? ", ?" : "")
                     . ($storeParent ? ", ?" : "") . ")
            ");
            $insertParams = [
                $ticketNumber,
                htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($description, ENT_QUOTES, 'UTF-8'),
                $priority,
                $targetServer,
                $templateId,
                $userId
            ];
            if ($storeRequested) {
                $insertParams[] = empty($requestedAccess) ? null : json_encode($requestedAccess);
            }
            if ($storeTypeName) {
                $insertParams[] = $template['name'];
            }
            if ($storeParent) {
                $insertParams[] = $parentTicketId;
            }
            $stmt->execute($insertParams);
            $ticketId = (int)$this->pdo->lastInsertId();

            // Items
            foreach ($itemsValidation['validated_items'] as $item) {
                $this->itemService->insertTicketItem($ticketId, $item);
            }

            // Actions — the work this request performs once approved. Written
            // only when 2026_08_23_003 has been applied; before that the request
            // is still created, and applyStageEffect() refuses to approve it
            // rather than approving a request whose work it cannot read.
            if (!empty($actions) && $this->supportsRequestActions()) {
                $insertAction = $this->pdo->prepare(
                    "INSERT INTO ticket_actions (ticket_id, position, action_type, payload, status, created_at)
                     VALUES (?, ?, ?, ?, 'pending', NOW())"
                );
                $position = 1;
                foreach ($actions as $action) {
                    $insertAction->execute([
                        $ticketId,
                        $position++,
                        $action['action_type'],
                        json_encode($action['payload'])
                    ]);
                }
            }

            // Snapshot stages
            $snapshotEffects = $this->supportsStageEffects();
            $insertStage = $snapshotEffects
                ? $this->pdo->prepare("
                    INSERT INTO ticket_stage_progress
                        (ticket_id, stage_template_id, name, position, status,
                         assigned_to_user_id, assigned_to_role_id, started_at,
                         effect_type, effect_config, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                  ")
                : $this->pdo->prepare("
                    INSERT INTO ticket_stage_progress
                        (ticket_id, stage_template_id, name, position, status,
                         assigned_to_user_id, assigned_to_role_id, started_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                  ");

            $firstStageProgressId = null;
            foreach ($resolvedStages as $idx => $rs) {
                $isFirst = ($idx === 0);
                $params = [
                    $ticketId,
                    $rs['stage_template_id'],
                    $rs['name'],
                    $rs['position'],
                    $isFirst ? 'active' : 'pending',
                    $rs['assigned_to_user_id'],
                    $rs['assigned_to_role_id'],
                    $isFirst ? date('Y-m-d H:i:s') : null
                ];
                if ($snapshotEffects) {
                    $params[] = $rs['effect_type'];
                    $params[] = $rs['effect_type'] === null ? null : $rs['effect_config'];
                }
                $insertStage->execute($params);
                if ($isFirst) {
                    $firstStageProgressId = (int)$this->pdo->lastInsertId();
                }
            }

            // Point the ticket at the active stage
            $this->pdo->prepare("UPDATE tickets SET current_stage_progress_id = ? WHERE id = ?")
                ->execute([$firstStageProgressId, $ticketId]);

            // History
            $this->historyService->logHistory($ticketId, 'pipeline_created', null, $template['name'], $userId, "Pipeline started from type '{$template['name']}'");
            $this->historyService->logHistory($ticketId, 'stage_activated', null, $resolvedStages[0]['name'], $userId, "Stage '{$resolvedStages[0]['name']}' activated");

            // Logged on BOTH timelines, because both are read by different
            // people for different reasons: the parent's approver needs to see
            // why it stopped moving, and the child's owner needs to see that
            // something else is waiting on their decision.
            if ($parentTicketId !== null) {
                $parentNumber = $this->ticketNumberOf($parentTicketId);

                $this->historyService->logHistory(
                    $ticketId,
                    'parent_linked',
                    null,
                    $parentNumber,
                    $userId,
                    "Raised as a prerequisite for #{$parentNumber}, which stays frozen until this is resolved"
                );
                $this->historyService->logHistory(
                    $parentTicketId,
                    'child_requested',
                    null,
                    $ticketNumber,
                    $userId,
                    "Frozen: waiting on prerequisite #{$ticketNumber} ('{$template['name']}')"
                );

                // So the frozen parent resurfaces in the list, which orders by
                // updated_at DESC. Without this it sinks while the thing it is
                // waiting for sits at the top, and its requester cannot tell the
                // two are connected.
                $this->pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")
                    ->execute([$parentTicketId]);
            }

            $this->pdo->commit();

            return [
                'success' => true,
                'ticket_id' => $ticketId,
                'ticket_number' => $ticketNumber,
                'errors' => []
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("PipelineManager::createPipeline error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Failed to create pipeline: ' . $e->getMessage()]];
        }
    }

    /**
     * Claim (accept) an active, role-owned stage. Locks it to the caller so two
     * team members don't do the same physical work.
     */
    public function claimStage($ticketId, $stageProgressId, $userId, $hasManage = false)
    {
        try {
            $this->pdo->beginTransaction();

            $stage = $this->lockStage($ticketId, $stageProgressId);
            if (!$stage) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Stage not found for this pipeline']];
            }
            if ($stage['status'] !== 'active') {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Only the active stage can be claimed']];
            }
            if (!empty($stage['claimed_by_user_id'])) {
                $this->pdo->rollBack();
                $who = $stage['claimed_by_user_id'] == $userId ? 'you' : 'another user';
                return ['success' => false, 'errors' => ["This stage is already claimed by $who"]];
            }

            // Must be entitled: assigned to this user, or member of the assigned role (or manage).
            if (!$hasManage && !$this->userOwnsStage($stage, $userId)) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['This stage is not assigned to you or your team']];
            }

            $this->pdo->prepare("
                UPDATE ticket_stage_progress
                SET claimed_by_user_id = ?, claimed_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute([$userId, $stageProgressId]);

            $this->historyService->logHistory($ticketId, 'stage_claimed', null, $stage['name'], $userId, "Claimed stage '{$stage['name']}'");

            $this->pdo->commit();
            return ['success' => true, 'errors' => []];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("PipelineManager::claimStage error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Failed to claim stage: ' . $e->getMessage()]];
        }
    }

    /**
     * Complete the active stage and auto-advance to the next stage's owner.
     * When there is no next stage, the pipeline is marked completed.
     */
    public function completeStage($ticketId, $stageProgressId, $userId, $notes = null, $hasManage = false)
    {
        try {
            $this->pdo->beginTransaction();

            $stage = $this->lockStage($ticketId, $stageProgressId);
            if (!$stage) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Stage not found for this pipeline']];
            }
            if ($stage['status'] !== 'active') {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Only the active stage can be completed']];
            }

            // Authorization to act on the stage.
            $authError = $this->assertCanAct($stage, $userId, $hasManage);
            if ($authError !== null) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => [$authError]];
            }

            // Is this request frozen behind a prerequisite? Checked here —
            // before anything is written and before any effect runs — so a
            // blocked approval leaves the step exactly as it found it.
            //
            // The lock is what makes this a guarantee: createPipeline() takes
            // the same row lock before inserting a child, so a child cannot
            // appear in the gap between this probe and the commit below.
            //
            // EVERY step is frozen, not just the one carrying the effect. The
            // prerequisite is a condition on the whole request: advancing its
            // paperwork while the thing it depends on is unresolved only moves
            // the stall further down the line.
            $this->pdo->prepare("SELECT id FROM tickets WHERE id = ? FOR UPDATE")->execute([$ticketId]);
            $blockers = $this->blockingChildren($ticketId);
            if (!empty($blockers)) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'errors' => [$this->describeBlockers($blockers)],
                    'blocked_by' => $blockers
                ];
            }

            // Complete the current stage
            $this->pdo->prepare("
                UPDATE ticket_stage_progress
                SET status = 'completed', completed_at = NOW(), completed_by_user_id = ?,
                    notes = ?, started_at = COALESCE(started_at, NOW()), updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $userId,
                ($notes !== null && $notes !== '') ? htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') : null,
                $stageProgressId
            ]);

            $this->historyService->logHistory($ticketId, 'stage_completed', $stage['name'], null, $userId, "Completed stage '{$stage['name']}'" . ($notes ? ": $notes" : ''));

            // Side effect, if this stage carries one. Deliberately inside the open
            // transaction: if the effect fails, the completion rolls back with it,
            // so a stage can never read "approved" without its grant existing.
            $effect = $this->applyStageEffect($ticketId, $stage, $userId);
            if (!$effect['success']) {
                $this->pdo->rollBack();
                // Carry the execution detail out past the rollback. It is the
                // only thing that can tell the approver WHICH action failed and
                // why — the rows recording it were just rolled back with
                // everything else, deliberately, so this is its only route.
                $failure = ['success' => false, 'errors' => $effect['errors']];
                if (!empty($effect['execution'])) {
                    $failure['execution'] = $effect['execution'];
                }

                // ...and record it, now that rollBack() has ended the
                // transaction and this connection is back in autocommit. The
                // response above dies with the page: the approver loses it on
                // reload and the REQUESTER never sees it at all, which leaves
                // them watching a request that was tried, failed, and looks
                // untouched. Only the attempt is recorded — the work itself
                // was rolled back and nothing was changed.
                $this->recordExecutionFailure($ticketId, $userId, $effect);

                return $failure;
            }

            // Find the next pending stage by position
            $next = $this->getNextStage($ticketId, (int)$stage['position']);

            if ($next) {
                $this->pdo->prepare("
                    UPDATE ticket_stage_progress
                    SET status = 'active', started_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$next['id']]);

                $this->pdo->prepare("UPDATE tickets SET current_stage_progress_id = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$next['id'], $ticketId]);

                $this->historyService->logHistory($ticketId, 'stage_activated', null, $next['name'], $userId, "Stage '{$next['name']}' activated");

                $this->pdo->commit();
                return [
                    'success' => true,
                    'completed' => false,
                    'next_stage' => $next['name'],
                    'effect' => $effect['applied'],
                    'errors' => []
                ];
            }

            // No more stages — pipeline complete
            $this->pdo->prepare("
                UPDATE tickets
                SET status = 'completed', current_stage_progress_id = NULL, completed_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute([$ticketId]);

            $this->historyService->logHistory($ticketId, 'pipeline_completed', null, 'completed', $userId, 'All stages completed — pipeline closed');

            // If this request was somebody's prerequisite, its parent has just
            // stopped waiting. Called after the status UPDATE so the remaining-
            // blocker count reads the new value.
            $this->notifyParent($ticketId, 'completed', $userId);

            $this->pdo->commit();
            return ['success' => true, 'completed' => true, 'effect' => $effect['applied'], 'errors' => []];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("PipelineManager::completeStage error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Failed to complete stage: ' . $e->getMessage()]];
        }
    }

    /**
     * Change the owner (user/role) of a not-yet-completed stage. Clears any
     * existing claim because the owner changed.
     */
    public function reassignStage($ticketId, $stageProgressId, $assigneeType, $assigneeId, $userId)
    {
        if (!in_array($assigneeType, PipelineConfig::getAssigneeTypes(), true)) {
            return ['success' => false, 'errors' => ["assignee_type must be 'user' or 'role'"]];
        }
        if (empty($assigneeId) || !is_numeric($assigneeId)) {
            return ['success' => false, 'errors' => ['assignee_id is required']];
        }
        if ($assigneeType === 'user' && !$this->userExists((int)$assigneeId)) {
            return ['success' => false, 'errors' => ['Assigned user not found']];
        }
        if ($assigneeType === 'role' && !$this->roleExists((int)$assigneeId)) {
            return ['success' => false, 'errors' => ['Assigned role not found']];
        }

        try {
            $this->pdo->beginTransaction();

            $stage = $this->lockStage($ticketId, $stageProgressId);
            if (!$stage) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Stage not found for this pipeline']];
            }
            if (in_array($stage['status'], ['completed', 'skipped', 'rejected'], true)) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Cannot reassign a finished stage']];
            }

            $userCol = $assigneeType === 'user' ? (int)$assigneeId : null;
            $roleCol = $assigneeType === 'role' ? (int)$assigneeId : null;

            $this->pdo->prepare("
                UPDATE ticket_stage_progress
                SET assigned_to_user_id = ?, assigned_to_role_id = ?,
                    claimed_by_user_id = NULL, claimed_at = NULL, updated_at = NOW()
                WHERE id = ?
            ")->execute([$userCol, $roleCol, $stageProgressId]);

            $this->historyService->logHistory($ticketId, 'stage_reassigned', $stage['name'], "$assigneeType:$assigneeId", $userId, "Reassigned stage '{$stage['name']}'");

            $this->pdo->commit();
            return ['success' => true, 'errors' => []];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("PipelineManager::reassignStage error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Failed to reassign stage: ' . $e->getMessage()]];
        }
    }

    /**
     * Cancel a pipeline (any non-terminal state).
     */
    public function cancelPipeline($ticketId, $userId, $reason = null)
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT id, status, pipeline_template_id FROM tickets WHERE id = ? FOR UPDATE");
            $stmt->execute([$ticketId]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ticket || empty($ticket['pipeline_template_id'])) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Pipeline not found']];
            }
            if (in_array($ticket['status'], PipelineConfig::getTerminalStatuses(), true)) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Pipeline is already ' . $ticket['status']]];
            }

            // Mark any active stage as skipped
            $this->pdo->prepare("
                UPDATE ticket_stage_progress SET status = 'skipped', updated_at = NOW()
                WHERE ticket_id = ? AND status IN ('active','pending')
            ")->execute([$ticketId]);

            $this->pdo->prepare("
                UPDATE tickets
                SET status = 'cancelled', current_stage_progress_id = NULL,
                    rejection_reason = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([
                ($reason !== null && $reason !== '') ? htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') : null,
                $ticketId
            ]);

            $this->historyService->logHistory($ticketId, 'pipeline_cancelled', $ticket['status'], 'cancelled', $userId, $reason ?: 'Pipeline cancelled');

            // A withdrawn prerequisite stops freezing its parent — there is
            // nothing left to wait for. Not a bypass: the parent still needs
            // its own approval, by somebody other than its creator.
            $this->notifyParent($ticketId, 'cancelled', $userId);

            $this->pdo->commit();
            return ['success' => true, 'errors' => []];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("PipelineManager::cancelPipeline error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Failed to cancel pipeline: ' . $e->getMessage()]];
        }
    }

    /**
     * Break the prerequisite link between a child request and its parent.
     *
     * THE ESCAPE HATCH FOR A REFUSED PREREQUISITE, and the reason
     * getParentBlockingStatuses() can safely include 'rejected'. A refused
     * prerequisite keeps its parent frozen — it must never read as a met one —
     * so without this the parent's only exits are rejection and cancellation.
     * That is too blunt for the ordinary case: the access was asked of the wrong
     * team, or for the wrong window. Unlink the refusal, raise a corrected one.
     *
     * Admin/super_admin only, gated in api.php by this operation NOT being
     * listed in $selfServiceOperations — the same mechanism as claim/complete/
     * reassign/cancel.
     *
     * Nothing about either request changes except the link. The child keeps its
     * own status, steps, actions and history; a rejected child stays rejected.
     * Both timelines record the detachment, because a parent that suddenly
     * unfreezes with no explanation is worse than one that stays stuck.
     *
     * @return array ['success','errors','parent_ticket_id','parent_still_blocked']
     */
    public function unlinkChild($childId, $userId)
    {
        if (!$this->supportsChildRequests()) {
            return [
                'success' => false,
                'errors' => ['Prerequisite requests are not available yet (seeder 2026_08_25_007 has not been applied)']
            ];
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                "SELECT id, ticket_number, status, parent_ticket_id
                 FROM tickets WHERE id = ? AND pipeline_template_id IS NOT NULL FOR UPDATE"
            );
            $stmt->execute([$childId]);
            $child = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$child) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Request not found']];
            }
            if (empty($child['parent_ticket_id'])) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['This request is not a prerequisite for anything']];
            }

            $parentId = (int)$child['parent_ticket_id'];
            $parentNumber = $this->ticketNumberOf($parentId);

            $this->pdo->prepare("UPDATE tickets SET parent_ticket_id = NULL, updated_at = NOW() WHERE id = ?")
                ->execute([$childId]);

            $this->historyService->logHistory(
                $childId,
                'parent_unlinked',
                $parentNumber,
                null,
                $userId,
                "No longer a prerequisite for #{$parentNumber}. This request is unchanged and still {$child['status']}."
            );
            $this->historyService->logHistory(
                $parentId,
                'child_unlinked',
                $child['ticket_number'],
                null,
                $userId,
                "Prerequisite #{$child['ticket_number']} ({$child['status']}) was detached — it no longer holds this request up"
            );

            $this->pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([$parentId]);

            // Read AFTER the unlink, so it reports what the parent is actually
            // waiting on now. More than one prerequisite is allowed.
            $stillBlocked = !empty($this->blockingChildren($parentId));

            $this->pdo->commit();
            return [
                'success' => true,
                'errors' => [],
                'parent_ticket_id' => $parentId,
                'parent_ticket_number' => $parentNumber,
                'parent_still_blocked' => $stillBlocked
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("PipelineManager::unlinkChild error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Failed to detach the prerequisite: ' . $e->getMessage()]];
        }
    }

    /**
     * Reject a request: the approver declines, and nothing is performed.
     *
     * Added 2026-08-23. Approve-or-reject is the entire verb set of the
     * automation model, and until now only half of it existed — the engine
     * could complete a step or cancel the whole request, but an approver had no
     * way to say no. Both 'rejected' values already existed in the schema
     * (ticket_stage_progress.status and tickets.status) and no code path had
     * ever written either one.
     *
     * Deliberately shaped like cancelPipeline(): same lock, same terminal
     * check, same reason column. The difference is who is refusing and why —
     * a cancellation withdraws a request, a rejection declines it — and that
     * distinction is carried by tickets.status, which the UI reads.
     *
     * @param string $reason required; a refusal without one is not useful to
     *                       the requester, who has to decide what to do next.
     * @return array ['success' => bool, 'errors' => array]
     */
    public function rejectStage($ticketId, $stageProgressId, $userId, $reason, $hasManage = false)
    {
        $reason = trim((string)$reason);
        if ($reason === '') {
            return ['success' => false, 'errors' => ['A reason is required when rejecting a request']];
        }

        try {
            $this->pdo->beginTransaction();

            $stage = $this->lockStage($ticketId, $stageProgressId);
            if (!$stage) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Step not found for this request']];
            }
            if ($stage['status'] !== 'active') {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Only the active step can be rejected']];
            }

            $authError = $this->assertCanAct($stage, $userId, $hasManage);
            if ($authError !== null) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => [$authError]];
            }

            $stmt = $this->pdo->prepare("SELECT status, created_by FROM tickets WHERE id = ? FOR UPDATE");
            $stmt->execute([$ticketId]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Request not found']];
            }
            if (in_array($ticket['status'], PipelineConfig::getTerminalStatuses(), true)) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Request is already ' . $ticket['status']]];
            }

            // Separation of duties applies to a refusal too: the same person
            // must not be both sides of the decision, whichever way it goes.
            $sod = $this->validator->validateSeparationOfDuties((int)$ticket['created_by'], (int)$userId);
            if (!$sod['valid']) {
                $this->pdo->rollBack();
                return ['success' => false, 'errors' => ['Cannot reject your own request (separation of duties)']];
            }

            $this->pdo->prepare("
                UPDATE ticket_stage_progress
                SET status = 'rejected', completed_at = NOW(), completed_by_user_id = ?,
                    notes = ?, started_at = COALESCE(started_at, NOW()), updated_at = NOW()
                WHERE id = ?
            ")->execute([$userId, htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'), $stageProgressId]);

            // Steps that never got their turn are skipped, not rejected — they
            // were not the decision. Mirrors cancelPipeline().
            $this->pdo->prepare("
                UPDATE ticket_stage_progress SET status = 'skipped', updated_at = NOW()
                WHERE ticket_id = ? AND status IN ('active','pending') AND id <> ?
            ")->execute([$ticketId, $stageProgressId]);

            $this->pdo->prepare("
                UPDATE tickets
                SET status = 'rejected', current_stage_progress_id = NULL,
                    rejection_reason = ?, completed_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute([htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'), $ticketId]);

            $this->historyService->logHistory(
                $ticketId,
                'pipeline_rejected',
                $ticket['status'],
                'rejected',
                $userId,
                "Rejected at step '{$stage['name']}': $reason"
            );

            // A REFUSED prerequisite keeps freezing its parent. It must never
            // read as a met one, so the parent does not quietly resume — see
            // PipelineConfig::getParentBlockingStatuses(). Somebody now rejects
            // or cancels the parent, or an admin unlinks this refusal so a
            // replacement prerequisite can be raised.
            $this->notifyParent($ticketId, 'rejected', $userId, $reason);

            $this->pdo->commit();
            return ['success' => true, 'errors' => []];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("PipelineManager::rejectStage error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['Failed to reject request: ' . $e->getMessage()]];
        }
    }

    /**
     * Get a pipeline with stages, items and history.
     */
    public function getPipeline($ticketId, $includeHistory = true)
    {
        try {
            // requested_access arrives with 2026_08_21_002.
            $requestedSelect = SchemaHelper::hasColumn($this->pdo, 'tickets', 'requested_access')
                ? ', t.requested_access'
                : '';
            // Falls back to the snapshot when the type itself has been deleted.
            $typeName = SchemaHelper::hasColumn($this->pdo, 'tickets', 'pipeline_type_name')
                ? 'COALESCE(pt.name, t.pipeline_type_name)'
                : 'pt.name';

            $stmt = $this->pdo->prepare("
                SELECT
                    t.id, t.ticket_number, t.title, t.description, t.status, t.priority,
                    t.target_server_uuid, t.rejection_reason, t.pipeline_template_id,
                    t.current_stage_progress_id, t.created_at, t.updated_at, t.completed_at,
                    t.created_by, creator.username AS created_by_username,
                    {$typeName} AS pipeline_type_name{$requestedSelect},
                    sc.config_uuid AS ts_uuid, sc.server_name AS ts_name,
                    sc.status_v2 AS ts_status_v2, sc.configuration_status AS ts_status_legacy,
                    sc.location AS ts_location, sc.rack_position AS ts_rack
                FROM tickets t
                LEFT JOIN users creator ON t.created_by = creator.id
                LEFT JOIN pipeline_templates pt ON t.pipeline_template_id = pt.id
                LEFT JOIN server_configurations sc ON sc.config_uuid = t.target_server_uuid
                WHERE t.id = ? AND t.pipeline_template_id IS NOT NULL
            ");
            $stmt->execute([$ticketId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            $pipeline = [
                'id' => (int)$row['id'],
                'ticket_number' => $row['ticket_number'],
                'title' => $row['title'],
                'description' => $row['description'],
                'status' => $row['status'],
                'priority' => $row['priority'],
                'target_server_uuid' => $row['target_server_uuid'],
                // Resolved so the approver reads a server NAME instead of a
                // 36-character uuid — they are deciding whether to unlock this
                // exact machine. 'exists' false means the configuration has been
                // deleted since the request was raised, which the UI must say out
                // loud: any server access granted now would be scoped to nothing.
                'target_server' => $this->describeTargetServer($row),
                // What the requester asked for. Not an authorisation — the
                // approval intersects it with the step ceiling and the whitelist.
                'requested_access' => !empty($row['requested_access'])
                    ? (json_decode($row['requested_access'], true) ?: [])
                    : [],
                'pipeline_type' => [
                    'id' => (int)$row['pipeline_template_id'],
                    'name' => $row['pipeline_type_name']
                ],
                'current_stage_progress_id' => $row['current_stage_progress_id'] ? (int)$row['current_stage_progress_id'] : null,
                'created_by' => $row['created_by'] ? [
                    'id' => (int)$row['created_by'],
                    'username' => $row['created_by_username']
                ] : null,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'completed_at' => $row['completed_at'],
                'stages' => $this->getStageProgress($ticketId),
                'items' => $this->itemService->getTicketItems($ticketId),
                'actions' => $this->getRequestActions($ticketId)
            ];

            // Prerequisites, in both directions. `blocked` is DERIVED from the
            // children on every read and never stored, so it cannot drift from
            // the rows it summarises — see PipelineConfig::getParentBlockingStatuses().
            $children = $this->getChildRequests($ticketId);
            $blocking = [];
            foreach ($children as $child) {
                if (!empty($child['blocks'])) {
                    $blocking[] = $child;
                }
            }
            $pipeline['parent'] = $this->getParentSummary($ticketId);
            $pipeline['children'] = $children;
            $pipeline['blocked_by'] = $blocking;
            $pipeline['blocked'] = !empty($blocking);
            if ($row['rejection_reason']) {
                $pipeline['cancel_reason'] = $row['rejection_reason'];
            }
            if ($includeHistory) {
                $pipeline['history'] = $this->historyService->getTicketHistorySimplified($ticketId);
            }

            return $pipeline;
        } catch (Exception $e) {
            error_log("PipelineManager::getPipeline error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Shape the target-server columns joined in getPipeline() into a small object.
     *
     * The uuid alone is not enough for the approver: they are deciding whether to
     * unlock one specific machine, and "e321313b-..." tells them nothing. When the
     * join found no row, 'exists' is false — the configuration was deleted after
     * the request was raised, and any server access granted now would be scoped to
     * a target that no longer exists.
     *
     * @param array $row a getPipeline() result row (ts_* aliases)
     * @return array|null null when the request names no server
     */
    private function describeTargetServer(array $row)
    {
        if (empty($row['target_server_uuid'])) {
            return null;
        }

        $exists = !empty($row['ts_uuid']);
        $status = null;
        if ($exists) {
            // StatusMap owns the status_v2 <-> legacy int pairing.
            $status = !empty($row['ts_status_v2'])
                ? $row['ts_status_v2']
                : (StatusMap::CONFIG_LEGACY_TO_V2[(int)$row['ts_status_legacy']] ?? null);
        }

        return [
            'uuid' => $row['target_server_uuid'],
            'exists' => $exists,
            'name' => $exists ? $row['ts_name'] : null,
            'status' => $status,
            'location' => $exists ? $row['ts_location'] : null,
            'rack_position' => $exists ? $row['ts_rack'] : null,
        ];
    }

    /**
     * List pipelines with scope/visibility filters.
     *
     * @param array $filters ['scope' => my_queue|created|all, 'status', 'priority',
     *                        'search', 'pipeline_template_id', 'user_id', 'user_role_ids']
     */
    public function listPipelines($filters = [], $page = 1, $limit = 20)
    {
        try {
            $where = ['t.pipeline_template_id IS NOT NULL'];
            $params = [];

            $scope = $filters['scope'] ?? 'all';
            $userId = $filters['user_id'] ?? null;
            $roleIds = $filters['user_role_ids'] ?? [];

            if ($scope === 'created' && $userId) {
                $where[] = 't.created_by = ?';
                $params[] = $userId;
            } elseif (($scope === 'my_queue' || $scope === 'mine') && $userId) {
                // my_queue: pipelines whose ACTIVE stage is owned by me or my team
                //           (or I claimed it) — the "what needs my action" view.
                // mine:     that, PLUS everything I created. This is what a
                //           non-privileged user is clamped to, because a request
                //           they raised is typically waiting on somebody ELSE's
                //           stage — under my_queue alone they would watch their
                //           own request disappear the moment they submitted it.
                $clause = '(cur.assigned_to_user_id = ? OR cur.claimed_by_user_id = ?';
                $params[] = $userId;
                $params[] = $userId;
                if (!empty($roleIds)) {
                    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
                    $clause .= " OR cur.assigned_to_role_id IN ($placeholders)";
                    $params = array_merge($params, $roleIds);
                }
                if ($scope === 'mine') {
                    $clause .= ' OR t.created_by = ?';
                    $params[] = $userId;
                }
                $clause .= ')';
                $where[] = $clause;
            }

            if (!empty($filters['status'])) {
                $where[] = 't.status = ?';
                $params[] = $filters['status'];
            }
            if (!empty($filters['priority'])) {
                $where[] = 't.priority = ?';
                $params[] = $filters['priority'];
            }
            if (!empty($filters['pipeline_template_id'])) {
                $where[] = 't.pipeline_template_id = ?';
                $params[] = (int)$filters['pipeline_template_id'];
            }
            if (!empty($filters['search'])) {
                $where[] = '(t.title LIKE ? OR t.description LIKE ? OR t.ticket_number LIKE ?)';
                $term = '%' . $filters['search'] . '%';
                $params[] = $term;
                $params[] = $term;
                $params[] = $term;
            }

            // Prerequisite filters (2026_08_25_007). Deliberately written
            // against t.parent_ticket_id rather than a joined alias, so the
            // COUNT query below needs no extra join to share this WHERE.
            $supportsChildren = $this->supportsChildRequests();
            if ($supportsChildren) {
                if (!empty($filters['parent_ticket_id'])) {
                    $where[] = 't.parent_ticket_id = ?';
                    $params[] = (int)$filters['parent_ticket_id'];
                } elseif (!empty($filters['top_level_only'])) {
                    $where[] = 't.parent_ticket_id IS NULL';
                }
            }

            $whereClause = 'WHERE ' . implode(' AND ', $where);

            $countStmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM tickets t
                LEFT JOIN ticket_stage_progress cur ON t.current_stage_progress_id = cur.id
                $whereClause
            ");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $offset = (max(1, (int)$page) - 1) * (int)$limit;

            // Same fallback as getPipeline(): a deleted type must not blank out
            // the type column on every request that used it.
            $typeName = SchemaHelper::hasColumn($this->pdo, 'tickets', 'pipeline_type_name')
                ? 'COALESCE(pt.name, t.pipeline_type_name)'
                : 'pt.name';

            // Blocked-ness is computed per row rather than stored, so the list
            // and the detail view can never disagree about it. The status list
            // is interpolated, not bound: these are PipelineConfig constants,
            // and the surrounding WHERE already owns every positional
            // placeholder in this statement.
            $parentSelect = '';
            $parentJoin = '';
            if ($supportsChildren) {
                $blockStatuses = implode(',', array_map(
                    array($this->pdo, 'quote'),
                    PipelineConfig::getParentBlockingStatuses()
                ));
                $parentSelect = ",
                    t.parent_ticket_id, parent.ticket_number AS parent_ticket_number,
                    EXISTS (SELECT 1 FROM tickets kid
                             WHERE kid.parent_ticket_id = t.id
                               AND kid.status IN ($blockStatuses)) AS is_blocked";
                $parentJoin = 'LEFT JOIN tickets parent ON parent.id = t.parent_ticket_id';
            }

            $stmt = $this->pdo->prepare("
                SELECT
                    t.id, t.ticket_number, t.title, t.status, t.priority,
                    t.created_at, t.updated_at,
                    t.created_by, creator.username AS created_by_username,
                    {$typeName} AS pipeline_type_name,
                    cur.id AS current_stage_id, cur.name AS current_stage_name,
                    cur.assigned_to_user_id AS cur_user_id, su.username AS cur_user_name,
                    cur.assigned_to_role_id AS cur_role_id, sr.display_name AS cur_role_name,
                    cur.claimed_by_user_id AS cur_claimed_by, cu.username AS cur_claimed_name,
                    (SELECT COUNT(*) FROM ticket_stage_progress sp WHERE sp.ticket_id = t.id) AS stage_total,
                    (SELECT COUNT(*) FROM ticket_stage_progress sp WHERE sp.ticket_id = t.id AND sp.status = 'completed') AS stage_done,
                    -- The LATEST execution event, not merely whether one ever
                    -- failed: a request that failed and was then approved
                    -- successfully must not keep wearing the marker. id DESC
                    -- breaks same-second ties deterministically.
                    (SELECT h.action FROM ticket_history h
                      WHERE h.ticket_id = t.id
                        AND h.action IN ('execution_failed', 'actions_executed')
                      ORDER BY h.created_at DESC, h.id DESC LIMIT 1) AS last_execution_event
                    {$parentSelect}
                FROM tickets t
                LEFT JOIN users creator ON t.created_by = creator.id
                LEFT JOIN pipeline_templates pt ON t.pipeline_template_id = pt.id
                LEFT JOIN ticket_stage_progress cur ON t.current_stage_progress_id = cur.id
                LEFT JOIN users su ON cur.assigned_to_user_id = su.id
                LEFT JOIN roles sr ON cur.assigned_to_role_id = sr.id
                LEFT JOIN users cu ON cur.claimed_by_user_id = cu.id
                {$parentJoin}
                $whereClause
                ORDER BY t.updated_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute(array_merge($params, [(int)$limit, (int)$offset]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pipelines = array_map(function ($row) {
                return [
                    'id' => (int)$row['id'],
                    'ticket_number' => $row['ticket_number'],
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'priority' => $row['priority'],
                    'pipeline_type' => $row['pipeline_type_name'],
                    'last_attempt_failed' => ($row['last_execution_event'] === 'execution_failed'),
                    // Absent, not false, before 2026_08_25_007 — the keys only
                    // exist when the column does.
                    'parent_ticket_id' => !empty($row['parent_ticket_id']) ? (int)$row['parent_ticket_id'] : null,
                    'parent_ticket_number' => isset($row['parent_ticket_number']) ? $row['parent_ticket_number'] : null,
                    'is_blocked' => !empty($row['is_blocked']),
                    'created_by' => $row['created_by'] ? [
                        'id' => (int)$row['created_by'],
                        'username' => $row['created_by_username']
                    ] : null,
                    'current_stage' => $row['current_stage_id'] ? [
                        'id' => (int)$row['current_stage_id'],
                        'name' => $row['current_stage_name'],
                        'owner' => $this->formatOwner($row['cur_user_id'], $row['cur_user_name'], $row['cur_role_id'], $row['cur_role_name']),
                        'claimed_by' => $row['cur_claimed_by'] ? [
                            'id' => (int)$row['cur_claimed_by'],
                            'username' => $row['cur_claimed_name']
                        ] : null
                    ] : null,
                    'progress' => [
                        'done' => (int)$row['stage_done'],
                        'total' => (int)$row['stage_total']
                    ],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at']
                ];
            }, $rows);

            return [
                'pipelines' => $pipelines,
                'total' => $total,
                'page' => (int)$page,
                'limit' => (int)$limit,
                'total_pages' => (int)ceil($total / max(1, (int)$limit))
            ];
        } catch (Exception $e) {
            error_log("PipelineManager::listPipelines error: " . $e->getMessage());
            return ['pipelines' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'total_pages' => 0];
        }
    }

    // ---------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------

    /**
     * Coerce a requested-access payload into a clean list of permission names.
     * Accepts a JSON string or an array, since the API layer may hand over either.
     */
    private function normaliseRequestedAccess($raw)
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $clean[] = trim($entry);
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * The work a request will perform, in execution order, with each action's
     * outcome once it has run.
     *
     * Decoded and given a human-readable summary here rather than in the
     * frontend, so the approver's screen and the audit history describe an
     * action in exactly the same words — one renderer, one vocabulary.
     *
     * @return array [] when the seeder has not been applied yet
     */
    private function getRequestActions($ticketId)
    {
        if (!$this->supportsRequestActions()) {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, position, action_type, payload, status, result, executed_at
                 FROM ticket_actions WHERE ticket_id = ? ORDER BY position ASC, id ASC"
            );
            $stmt->execute([$ticketId]);

            $actions = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $payload = json_decode($row['payload'], true);
                if (!is_array($payload)) {
                    $payload = [];
                }
                $actions[] = [
                    'id' => (int)$row['id'],
                    'position' => (int)$row['position'],
                    'action_type' => $row['action_type'],
                    'summary' => RequestActionExecutor::summarise($row['action_type'], $payload),
                    'payload' => $payload,
                    'status' => $row['status'],
                    'result' => $row['result'] ? json_decode($row['result'], true) : null,
                    'executed_at' => $row['executed_at'],
                ];
            }
            return $actions;
        } catch (Exception $e) {
            error_log("PipelineManager::getRequestActions error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Every action type a request type's steps are allowed to perform.
     *
     * The union across steps, because a multi-step type could in principle carry
     * an effect on more than one of them. Read from the LIVE template here, at
     * creation time, which is correct: the snapshot that governs approval is
     * taken from the same rows moments later.
     *
     * @return string[]
     */
    private function templateActionCeiling($template)
    {
        $ceiling = [];
        foreach (($template['stages'] ?? []) as $stage) {
            if (($stage['effect_type'] ?? null) !== PipelineConfig::EFFECT_EXECUTE_REQUEST) {
                continue;
            }
            $config = json_decode($stage['effect_config'] ?? '', true);
            if (is_array($config) && !empty($config['action_types']) && is_array($config['action_types'])) {
                $ceiling = array_merge($ceiling, $config['action_types']);
            }
        }
        return array_values(array_unique($ceiling));
    }

    /**
     * Normalise the submitted actions into [['action_type' => s, 'payload' => []], …].
     *
     * Returns null — distinct from an empty array — when the input is present
     * but malformed, so the caller can say so instead of silently accepting a
     * request that will perform nothing.
     *
     * @return array|null
     */
    private function normaliseActions($raw)
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return null;
        }

        $clean = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $entry = json_decode($entry, true);
            }
            if (!is_array($entry) || empty($entry['action_type']) || !is_string($entry['action_type'])) {
                return null;
            }

            $payload = isset($entry['payload']) ? $entry['payload'] : [];
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            if (!is_array($payload)) {
                return null;
            }

            $clean[] = ['action_type' => trim($entry['action_type']), 'payload' => $payload];
        }

        return $clean;
    }

    /**
     * Has 2026_08_23_003 been applied? A request carries actions only once it has.
     */
    private function supportsRequestActions()
    {
        return SchemaHelper::hasColumn($this->pdo, 'ticket_actions', 'action_type');
    }

    /**
     * Has 2026_08_20_002 been applied? Stages carry a side effect only once it has.
     */
    private function supportsStageEffects()
    {
        return SchemaHelper::hasColumn($this->pdo, 'ticket_stage_progress', 'effect_type');
    }

    // ---------------------------------------------------------------------
    // Prerequisites (child requests) — 2026_08_25_007
    //
    // A request raised inside another freezes it until resolved. There is no
    // stored "blocked" flag and no 'blocked' lifecycle status: blocked-ness IS
    // "has an open blocking child", so it is derived on every read from the
    // rows that define it and cannot drift from them.
    //
    // ONLY DIRECT CHILDREN ARE EVER QUERIED. Transitivity is free: a blocked
    // child cannot complete, so it stays open, so it keeps its own parent
    // frozen. Nothing here walks the tree downward.
    // ---------------------------------------------------------------------

    /**
     * Has 2026_08_25_007 been applied? Requests can nest only once it has.
     */
    private function supportsChildRequests()
    {
        return SchemaHelper::hasColumn($this->pdo, 'tickets', 'parent_ticket_id');
    }

    private function ticketNumberOf($ticketId)
    {
        $stmt = $this->pdo->prepare("SELECT ticket_number FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $number = $stmt->fetchColumn();
        return $number !== false ? $number : ('#' . (int)$ticketId);
    }

    /**
     * The direct children currently freezing $ticketId.
     *
     * @return array [['id','ticket_number','title','status','pipeline_type_name'], …]
     */
    private function blockingChildren($ticketId)
    {
        if (!$this->supportsChildRequests()) {
            return [];
        }

        $statuses = PipelineConfig::getParentBlockingStatuses();
        if (empty($statuses)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        // Uses idx_tickets_parent_status directly: (parent_ticket_id, status).
        $typeName = SchemaHelper::hasColumn($this->pdo, 'tickets', 'pipeline_type_name')
            ? 'pipeline_type_name'
            : 'NULL AS pipeline_type_name';

        $stmt = $this->pdo->prepare(
            "SELECT id, ticket_number, title, status, {$typeName}
             FROM tickets
             WHERE parent_ticket_id = ? AND status IN ($placeholders)
             ORDER BY id ASC"
        );
        $stmt->execute(array_merge([(int)$ticketId], $statuses));

        return array_map(function ($row) {
            return [
                'id' => (int)$row['id'],
                'ticket_number' => $row['ticket_number'],
                'title' => $row['title'],
                'status' => $row['status'],
                'pipeline_type_name' => $row['pipeline_type_name'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The refusal an approver sees when they try to advance a frozen request.
     *
     * It names the blocker and, for a rejected one, says outright that waiting
     * will not help — otherwise the approver retries the same click tomorrow.
     */
    private function describeBlockers(array $blockers)
    {
        $parts = [];
        $refused = false;
        foreach ($blockers as $blocker) {
            $parts[] = '#' . $blocker['ticket_number']
                . ' (' . ($blocker['pipeline_type_name'] ?: 'request') . ', ' . $blocker['status'] . ')';
            if ($blocker['status'] === 'rejected') {
                $refused = true;
            }
        }

        $message = 'Blocked by prerequisite ' . implode(', ', $parts) . '.';

        return $refused
            ? $message . ' A rejected prerequisite does not clear by itself: reject or cancel this request,'
                . ' or have an admin detach the refused one so a replacement can be raised.'
            : $message . ' It has to be resolved before this request can move.';
    }

    /**
     * Every direct child of $ticketId, each flagged with whether it is currently
     * freezing the parent. One query serves both the listing and blocked_by.
     */
    private function getChildRequests($ticketId)
    {
        if (!$this->supportsChildRequests()) {
            return [];
        }

        $blocking = PipelineConfig::getParentBlockingStatuses();
        $typeName = SchemaHelper::hasColumn($this->pdo, 'tickets', 'pipeline_type_name')
            ? 'COALESCE(pt.name, c.pipeline_type_name)'
            : 'pt.name';

        try {
            $stmt = $this->pdo->prepare("
                SELECT c.id, c.ticket_number, c.title, c.status, c.priority,
                       c.created_at, c.rejection_reason,
                       c.created_by, creator.username AS created_by_username,
                       {$typeName} AS pipeline_type_name
                FROM tickets c
                LEFT JOIN users creator ON c.created_by = creator.id
                LEFT JOIN pipeline_templates pt ON c.pipeline_template_id = pt.id
                WHERE c.parent_ticket_id = ?
                ORDER BY c.id ASC
            ");
            $stmt->execute([(int)$ticketId]);

            return array_map(function ($row) use ($blocking) {
                return [
                    'id' => (int)$row['id'],
                    'ticket_number' => $row['ticket_number'],
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'priority' => $row['priority'],
                    'pipeline_type_name' => $row['pipeline_type_name'],
                    'created_by' => $row['created_by'] ? [
                        'id' => (int)$row['created_by'],
                        'username' => $row['created_by_username']
                    ] : null,
                    'created_at' => $row['created_at'],
                    // Why it was refused. The parent's requester has to be able
                    // to read this without the row-level visibility on the child
                    // letting them open it.
                    'rejection_reason' => $row['rejection_reason'],
                    'blocks' => in_array($row['status'], $blocking, true),
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            error_log("PipelineManager::getChildRequests error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * The request this one is a prerequisite for, or null when top-level.
     */
    private function getParentSummary($ticketId)
    {
        if (!$this->supportsChildRequests()) {
            return null;
        }

        $typeName = SchemaHelper::hasColumn($this->pdo, 'tickets', 'pipeline_type_name')
            ? 'COALESCE(pt.name, p.pipeline_type_name)'
            : 'pt.name';

        try {
            $stmt = $this->pdo->prepare("
                SELECT p.id, p.ticket_number, p.title, p.status,
                       {$typeName} AS pipeline_type_name
                FROM tickets t
                JOIN tickets p ON p.id = t.parent_ticket_id
                LEFT JOIN pipeline_templates pt ON p.pipeline_template_id = pt.id
                WHERE t.id = ?
            ");
            $stmt->execute([(int)$ticketId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            return [
                'id' => (int)$row['id'],
                'ticket_number' => $row['ticket_number'],
                'title' => $row['title'],
                'status' => $row['status'],
                'pipeline_type_name' => $row['pipeline_type_name'],
            ];
        } catch (Exception $e) {
            error_log("PipelineManager::getParentSummary error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Can $userId hang a new prerequisite off request $parentId?
     *
     * @return array ['valid' => bool, 'errors' => string[], 'parent_id' => int|null]
     */
    private function validateParent($parentId, $userId, $hasManage)
    {
        $fail = function ($message) {
            return ['valid' => false, 'errors' => [$message], 'parent_id' => null];
        };

        if (!is_numeric($parentId) || (int)$parentId <= 0) {
            return $fail('parent_ticket_id must be a request id');
        }
        $parentId = (int)$parentId;

        // REFUSE rather than quietly create an unlinked request. Code reaches
        // production ~20s after a save and seeders are applied by hand, so this
        // window is real — and a prerequisite that silently fails to freeze
        // anything is the exact failure this feature exists to prevent.
        if (!$this->supportsChildRequests()) {
            return $fail('Prerequisite requests are not available yet (seeder 2026_08_25_007 has not been applied)');
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, status, created_by FROM tickets WHERE id = ? AND pipeline_template_id IS NOT NULL"
        );
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$parent) {
            return $fail('The request this would be a prerequisite for was not found');
        }
        if (in_array($parent['status'], PipelineConfig::getTerminalStatuses(), true)) {
            return $fail('That request is already ' . $parent['status'] . ' — a prerequisite would have nothing to unblock');
        }

        // Freezing somebody else's request is a real imposition, so it takes
        // more than being logged in: you must already be part of it, or hold
        // pipeline.manage.
        if (!$hasManage && !$this->userInvolvedInTicket($parentId, $userId)) {
            return $fail('You can only raise a prerequisite on a request you raised or are working on');
        }

        // A chain has to terminate for any of it to be approvable: every link
        // adds another human decision that must land first.
        $depth = $this->ancestorDepth($parentId) + 2; // the parent's own level, plus this new child
        if ($depth > PipelineConfig::MAX_REQUEST_DEPTH) {
            return $fail(
                'Requests can only nest ' . PipelineConfig::MAX_REQUEST_DEPTH
                . ' deep. Raise this prerequisite on the request at the top of the chain instead.'
            );
        }

        return ['valid' => true, 'errors' => [], 'parent_id' => $parentId];
    }

    /**
     * How many ancestors $ticketId has (0 = top-level).
     *
     * A cycle cannot be CREATED — the child row does not exist when its parent
     * is chosen, so it can never be its own ancestor. The iteration cap is
     * defensive only: it stops a loop introduced by hand in the database from
     * hanging every request that touches it.
     */
    private function ancestorDepth($ticketId)
    {
        $stmt = $this->pdo->prepare("SELECT parent_ticket_id FROM tickets WHERE id = ?");

        $depth = 0;
        $current = (int)$ticketId;
        $guard = PipelineConfig::MAX_REQUEST_DEPTH + 5;

        while ($guard-- > 0) {
            $stmt->execute([$current]);
            $parent = $stmt->fetchColumn();
            if (empty($parent)) {
                return $depth;
            }
            $depth++;
            $current = (int)$parent;
        }

        error_log("PipelineManager::ancestorDepth walked off the end from ticket $ticketId — possible parent loop");
        return $depth;
    }

    /**
     * Is $userId part of request $ticketId — its creator, the owner of one of
     * its steps, a member of a role that owns one, or the claimer of one?
     *
     * The same test pipeline-get.php applies for row-level visibility, run here
     * against the raw rows instead of an already-shaped response.
     */
    private function userInvolvedInTicket($ticketId, $userId)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM tickets WHERE id = ? AND created_by = ?");
        $stmt->execute([(int)$ticketId, (int)$userId]);
        if ($stmt->fetchColumn() > 0) {
            return true;
        }

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM ticket_stage_progress sp
            WHERE sp.ticket_id = ?
              AND (sp.assigned_to_user_id = ?
                   OR sp.claimed_by_user_id = ?
                   OR sp.assigned_to_role_id IN (SELECT role_id FROM user_roles WHERE user_id = ?))
        ");
        $stmt->execute([(int)$ticketId, (int)$userId, (int)$userId, (int)$userId]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Record on the PARENT's timeline that one of its prerequisites reached a
     * terminal state, and bump it so it resurfaces in the list.
     *
     * MUST be called after the child's own status UPDATE and inside the same
     * transaction: the remaining-blocker count below reads the new status, and
     * a note about an outcome that then rolls back would be a lie.
     *
     * @param string $outcome 'completed' | 'rejected' | 'cancelled'
     */
    private function notifyParent($ticketId, $outcome, $userId, $reason = null)
    {
        if (!$this->supportsChildRequests()) {
            return;
        }

        $stmt = $this->pdo->prepare("SELECT parent_ticket_id, ticket_number FROM tickets WHERE id = ?");
        $stmt->execute([(int)$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['parent_ticket_id'])) {
            return;
        }

        $parentId = (int)$row['parent_ticket_id'];
        $number = $row['ticket_number'];
        $remaining = $this->blockingChildren($parentId);

        if ($outcome === 'rejected') {
            // Still counted among $remaining — a refusal is not a resolution.
            $note = "Prerequisite #{$number} was REJECTED" . ($reason ? ": $reason" : '')
                . '. This request stays frozen: a refused prerequisite does not clear by itself.';
        } elseif ($outcome === 'cancelled') {
            $note = "Prerequisite #{$number} was withdrawn.";
        } else {
            $note = "Prerequisite #{$number} was completed.";
        }

        if ($outcome !== 'rejected') {
            $note .= empty($remaining)
                ? ' This request is no longer frozen and can be approved on its own merits.'
                : ' Still frozen, waiting on ' . count($remaining) . ' more.';
        }

        $this->historyService->logHistory(
            $parentId,
            'prerequisite_' . $outcome,
            null,
            $number,
            $userId,
            $note
        );

        $this->pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([$parentId]);
    }

    /**
     * Run the side effect attached to a stage that has just been completed.
     *
     * Called from inside completeStage()'s open transaction, so returning
     * success=false rolls the completion back too. A stage with no effect is the
     * overwhelmingly common case and returns immediately.
     *
     * THIS IS WHERE APPROVAL BECOMES WORK. Until 2026-08-23 the one effect
     * GRANTED the requester permissions for 24 hours so they could do the job
     * themselves; now the effect performs the job. Nothing is granted, so there
     * is nothing to scope, expire or revoke.
     *
     * @param array $stage the locked ticket_stage_progress row (SELECT *)
     * @param int   $userId the actor completing the stage
     * @return array ['success' => bool, 'errors' => [], 'applied' => array|null]
     */
    /**
     * Record a rolled-back approval on the request's timeline.
     *
     * MUST be called AFTER rollBack(), never before: that call ends the
     * transaction, so this INSERT autocommits on the same connection and
     * survives. Written inside the transaction it would roll back with the work
     * it describes — which is exactly what happens to the 'failed' marker on
     * ticket_actions (runTicketActions()), and why that marker cannot be the
     * record. No second connection is opened for this; nothing to configure.
     *
     * Only the ATTEMPT is recorded. The work was rolled back, the step is still
     * active and the request is still open and re-approvable.
     *
     * new_value is structured for the UI; notes is the human sentence. Both are
     * written because ticket_history is read by people as well as by code.
     */
    private function recordExecutionFailure($ticketId, $userId, array $effect)
    {
        $execution = !empty($effect['execution']) && is_array($effect['execution'])
            ? $effect['execution']
            : [];

        $message = isset($execution['message']) && $execution['message'] !== ''
            ? $execution['message']
            : (!empty($effect['errors']) ? implode('; ', $effect['errors']) : 'Action failed');

        $detail = [
            'position'    => isset($execution['position']) ? (int)$execution['position'] : null,
            'action_type' => isset($execution['action_type']) ? $execution['action_type'] : null,
            'error_code'  => isset($execution['error_code']) ? $execution['error_code'] : null,
            'message'     => $message,
        ];

        $where = $detail['position'] !== null
            ? "Action {$detail['position']}" . ($detail['action_type'] ? " ({$detail['action_type']})" : '')
            : 'An action';

        $this->historyService->logHistory(
            $ticketId,
            'execution_failed',
            null,
            json_encode($detail),
            $userId,
            "Approval rolled back — nothing was changed. $where failed: $message"
        );
    }

    private function applyStageEffect($ticketId, $stage, $userId)
    {
        $none = ['success' => true, 'errors' => [], 'applied' => null];

        $effectType = isset($stage['effect_type']) ? $stage['effect_type'] : null;
        if (empty($effectType)) {
            return $none;
        }

        // A snapshot from the retired temporary-grant model. NOT an error: the
        // effect no longer exists, so the step is pure status tracking. Failing
        // here would leave every request raised before the change permanently
        // impossible to complete -- stuck for having been raised on the wrong
        // day. Fail OPEN for completion, CLOSED for privilege: nothing is
        // granted either way.
        if (in_array($effectType, PipelineConfig::getRetiredEffectTypes(), true)) {
            error_log("PipelineManager: ignoring retired stage effect '$effectType' on stage {$stage['id']}");
            $this->historyService->logHistory(
                $ticketId,
                'effect_retired',
                $effectType,
                null,
                $userId,
                'This step used to grant temporary access. That model was retired; nothing was granted.'
            );
            return $none;
        }

        if ($effectType !== PipelineConfig::EFFECT_EXECUTE_REQUEST) {
            // An unknown effect must not silently succeed -- a step that
            // promises to do something and doesn't is worse than a failed
            // approval.
            error_log("PipelineManager: unknown stage effect '$effectType' on stage {$stage['id']}");
            return ['success' => false, 'errors' => ["Unknown step effect '$effectType'"], 'applied' => null];
        }

        $config = json_decode(isset($stage['effect_config']) ? $stage['effect_config'] : '', true);
        if (!is_array($config)) {
            return ['success' => false, 'errors' => ['This step\'s effect is misconfigured'], 'applied' => null];
        }

        // Guard 1 -- only an admin or super_admin may make the system act,
        // whoever happens to own the stage. Belt-and-braces on top of
        // assertCanAct().
        //
        // userHasRole() lives in BaseFunctions, which api.php always loads
        // before any handler reaches this class. If that ever stops being true,
        // refuse rather than fatal: an approval that cannot verify the
        // approver's role must not perform anything.
        if (!function_exists('userHasRole')) {
            error_log('PipelineManager: userHasRole() unavailable -- refusing to perform request actions');
            return ['success' => false, 'errors' => ['Cannot verify approver role'], 'applied' => null];
        }

        if (!userHasRole($this->pdo, $userId, 'admin') && !userHasRole($this->pdo, $userId, 'super_admin')) {
            return [
                'success' => false,
                'errors' => ['Only an admin or super admin can approve a request'],
                'applied' => null
            ];
        }

        // Guard 2 -- the work is performed on behalf of the request's CREATOR,
        // never anyone named in the request body. Anything created belongs to
        // them.
        $stmt = $this->pdo->prepare("SELECT created_by FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        $createdBy = isset($ticket['created_by']) ? $ticket['created_by'] : null;
        if (!$createdBy) {
            return ['success' => false, 'errors' => ['Cannot determine who this request belongs to'], 'applied' => null];
        }

        // Guard 3 -- nobody approves their own request. This mattered when
        // approval handed out access; it matters just as much now that approval
        // performs privileged work.
        $sod = $this->validator->validateSeparationOfDuties((int)$createdBy, (int)$userId);
        if (!$sod['valid']) {
            return [
                'success' => false,
                'errors' => ['Cannot approve your own request (separation of duties)'],
                'applied' => null
            ];
        }

        if (!$this->supportsRequestActions()) {
            // The seeder has not been applied yet. Code reaches production ~20s
            // after a save and seeders are run by hand, so this window is real.
            // Refuse rather than silently approve a request whose work cannot
            // be read: "approved but nothing happened" is the outcome this
            // whole design exists to prevent.
            return [
                'success' => false,
                'errors' => ['Request actions are not available yet (seeder 2026_08_23_003 has not been applied)'],
                'applied' => null
            ];
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, position, action_type, payload FROM ticket_actions
             WHERE ticket_id = ? ORDER BY position ASC, id ASC"
        );
        $stmt->execute([$ticketId]);
        $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($actions)) {
            return [
                'success' => false,
                'errors' => ['This request has nothing to perform'],
                'applied' => null
            ];
        }

        // The step's effect_config is the CEILING of what this request type may
        // ever perform. It was snapshotted when the request was raised, so a
        // later edit to the type cannot widen what an already-submitted request
        // does.
        $ceiling = (isset($config['action_types']) && is_array($config['action_types']))
            ? $config['action_types']
            : [];
        if (empty($ceiling)) {
            return ['success' => false, 'errors' => ['This step is not allowed to perform anything'], 'applied' => null];
        }

        $executor = new RequestActionExecutor($this->pdo);
        $applied = [];

        foreach ($actions as $action) {
            $actionType = $action['action_type'];

            if (!in_array($actionType, $ceiling, true)) {
                return [
                    'success' => false,
                    'errors' => ["This request type is not allowed to perform '$actionType'"],
                    'applied' => null
                ];
            }

            $payload = json_decode($action['payload'], true);
            if (!is_array($payload)) {
                return [
                    'success' => false,
                    'errors' => ["Action {$action['position']} has an unreadable payload"],
                    'applied' => null
                ];
            }

            // $ticketId is passed as an ARGUMENT, never merged into $payload: the
            // payload is client-supplied, and a request must not be able to name
            // a different request as the authority for its own work. Actions that
            // keep their own history (server.relocate -> server_movements) record
            // it so "who authorised this move" is answerable afterwards.
            $outcome = $executor->execute($actionType, $payload, (int)$createdBy, (int)$userId, (int)$ticketId);

            if (empty($outcome['success'])) {
                // Record the failure on the row, then fail the whole approval.
                // The UPDATE is inside the doomed transaction and rolls back
                // with everything else -- deliberately. A request that is still
                // open must not carry a 'failed' row from an approval that
                // never happened; the error travels to the approver in the
                // response instead.
                $this->pdo->prepare(
                    "UPDATE ticket_actions SET status = 'failed', result = ?, updated_at = NOW() WHERE id = ?"
                )->execute([json_encode($outcome['result']), $action['id']]);

                $message = !empty($outcome['errors'])
                    ? implode('; ', $outcome['errors'])
                    : 'Action failed';

                return [
                    'success' => false,
                    'errors' => ["Action {$action['position']} (" . RequestActionExecutor::summarise($actionType, $payload) . ") failed: $message"],
                    'applied' => null,
                    'execution' => [
                        'failed' => true,
                        'position' => (int)$action['position'],
                        'action_type' => $actionType,
                        'error_code' => isset($outcome['result']['error_code']) ? $outcome['result']['error_code'] : null,
                        'message' => $message,
                    ],
                ];
            }

            $this->pdo->prepare(
                "UPDATE ticket_actions SET status = 'executed', result = ?, executed_at = NOW(), updated_at = NOW() WHERE id = ?"
            )->execute([json_encode($outcome['result']), $action['id']]);

            $applied[] = [
                'position' => (int)$action['position'],
                'action_type' => $actionType,
                'summary' => RequestActionExecutor::summarise($actionType, $payload),
                'result' => $outcome['result'],
            ];
        }

        $summaries = [];
        foreach ($applied as $entry) {
            $summaries[] = $entry['summary'];
        }
        $this->historyService->logHistory(
            $ticketId,
            'actions_executed',
            null,
            (string)count($applied),
            $userId,
            'Performed on approval: ' . implode('; ', $summaries)
        );

        return [
            'success' => true,
            'errors' => [],
            'applied' => [
                'type' => $effectType,
                'actions' => $applied,
                'count' => count($applied),
                'user_id' => (int)$createdBy
            ]
        ];
    }

    /**
     * Stage rows for a pipeline with owner/claimer names resolved.
     */
    private function getStageProgress($ticketId)
    {
        // Stage name/owner are snapshotted on the progress row; the human-readable
        // instructions are reference-only and read from the (current) template stage.
        //
        // effect_type comes from the SNAPSHOT, not the template: it is how the UI
        // knows which step actually performs the request's work, and it has to be
        // the value this request was raised with — a later edit to the type must
        // not change what an open request says it will do.
        $effectSelect = $this->supportsStageEffects() ? ', sp.effect_type' : '';
        $stmt = $this->pdo->prepare("
            SELECT
                sp.id, sp.name, sp.position, sp.status,
                sp.assigned_to_user_id, su.username AS user_name,
                sp.assigned_to_role_id, sr.display_name AS role_name,
                sp.claimed_by_user_id, cu.username AS claimed_name, sp.claimed_at,
                sp.started_at, sp.completed_at,
                sp.completed_by_user_id, compu.username AS completed_by_name,
                sp.notes, ps.instructions AS instructions{$effectSelect}
            FROM ticket_stage_progress sp
            LEFT JOIN users su ON sp.assigned_to_user_id = su.id
            LEFT JOIN roles sr ON sp.assigned_to_role_id = sr.id
            LEFT JOIN users cu ON sp.claimed_by_user_id = cu.id
            LEFT JOIN users compu ON sp.completed_by_user_id = compu.id
            LEFT JOIN pipeline_stages ps ON sp.stage_template_id = ps.id
            WHERE sp.ticket_id = ?
            ORDER BY sp.position ASC
        ");
        $stmt->execute([$ticketId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            return [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'position' => (int)$row['position'],
                'status' => $row['status'],
                'instructions' => $row['instructions'],
                'owner' => $this->formatOwner($row['assigned_to_user_id'], $row['user_name'], $row['assigned_to_role_id'], $row['role_name']),
                'claimed_by' => $row['claimed_by_user_id'] ? [
                    'id' => (int)$row['claimed_by_user_id'],
                    'username' => $row['claimed_name']
                ] : null,
                'claimed_at' => $row['claimed_at'],
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at'],
                'completed_by' => $row['completed_by_user_id'] ? [
                    'id' => (int)$row['completed_by_user_id'],
                    'username' => $row['completed_by_name']
                ] : null,
                'notes' => $row['notes'],
                'effect_type' => isset($row['effect_type']) ? $row['effect_type'] : null
            ];
        }, $rows);
    }

    /**
     * Lock and fetch a stage row, ensuring it belongs to the pipeline.
     */
    private function lockStage($ticketId, $stageProgressId)
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM ticket_stage_progress WHERE id = ? AND ticket_id = ? FOR UPDATE
        ");
        $stmt->execute([$stageProgressId, $ticketId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getNextStage($ticketId, $currentPosition)
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM ticket_stage_progress
            WHERE ticket_id = ? AND position > ? AND status = 'pending'
            ORDER BY position ASC LIMIT 1
        ");
        $stmt->execute([$ticketId, $currentPosition]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Whether a user is entitled to a stage (assigned user, or member of the
     * assigned role). Used to gate claiming.
     */
    private function userOwnsStage($stage, $userId)
    {
        if (!empty($stage['assigned_to_user_id']) && (int)$stage['assigned_to_user_id'] === (int)$userId) {
            return true;
        }
        if (!empty($stage['assigned_to_role_id']) && $this->userInRole($userId, (int)$stage['assigned_to_role_id'])) {
            return true;
        }
        return false;
    }

    /**
     * Authorization to COMPLETE/act on a stage:
     *  - manage bypasses everything
     *  - user-owned stage: must be that user
     *  - role-owned stage: must be the claimer (claim-first enforced)
     * Returns null when allowed, or an error string.
     */
    private function assertCanAct($stage, $userId, $hasManage)
    {
        if ($hasManage) {
            return null;
        }

        // User-owned stage
        if (!empty($stage['assigned_to_user_id'])) {
            return ((int)$stage['assigned_to_user_id'] === (int)$userId)
                ? null
                : 'This stage is assigned to another user';
        }

        // Role-owned stage — must be claimed by the actor first
        if (!empty($stage['assigned_to_role_id'])) {
            if (empty($stage['claimed_by_user_id'])) {
                return 'Accept (claim) this stage before completing it';
            }
            return ((int)$stage['claimed_by_user_id'] === (int)$userId)
                ? null
                : 'This stage is claimed by another team member';
        }

        return 'This stage has no owner';
    }

    private function userInRole($userId, $roleId)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = ?");
        $stmt->execute([$userId, $roleId]);
        return $stmt->fetchColumn() > 0;
    }

    private function formatOwner($userId, $username, $roleId, $roleName)
    {
        if ($userId) {
            return ['type' => 'user', 'id' => (int)$userId, 'name' => $username];
        }
        if ($roleId) {
            return ['type' => 'role', 'id' => (int)$roleId, 'name' => $roleName];
        }
        return null;
    }

    private function userExists($userId)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() > 0;
    }

    private function roleExists($roleId)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM roles WHERE id = ?");
        $stmt->execute([$roleId]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Generate a unique ticket number (shared TKT-YYYYMMDD-XXXX sequence with
     * the rest of the tickets table).
     */
    private function generateTicketNumber()
    {
        $date = date('Ymd');
        $prefix = "TKT-{$date}-";
        $stmt = $this->pdo->prepare("
            SELECT MAX(CAST(SUBSTRING(ticket_number, -4) AS UNSIGNED)) AS max_seq
            FROM tickets WHERE ticket_number LIKE ?
        ");
        $stmt->execute([$prefix . '%']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextSeq = ($result['max_seq'] ?? 0) + 1;
        return $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
    }
}
