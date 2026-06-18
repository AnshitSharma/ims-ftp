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
     * @return array ['success','ticket_id','ticket_number','errors']
     */
    public function createPipeline($templateId, $data, $userId)
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
        if ($description === '') {
            $errors[] = 'Description is required';
        } elseif (mb_strlen($description) > 5000) {
            $errors[] = 'Description must not exceed 5000 characters';
        }
        $priority = $data['priority'] ?? 'medium';
        if (!in_array($priority, WorkflowConfig::getValidPriorities(), true)) {
            $errors[] = 'Invalid priority';
        }

        $targetServer = !empty($data['target_server_uuid']) ? $data['target_server_uuid'] : null;

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
            ];
        }

        try {
            $this->pdo->beginTransaction();

            $ticketNumber = $this->generateTicketNumber();

            $stmt = $this->pdo->prepare("
                INSERT INTO tickets
                    (ticket_number, title, description, status, priority, target_server_uuid,
                     pipeline_template_id, created_by, created_at, submitted_at)
                VALUES (?, ?, ?, 'in_progress', ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $ticketNumber,
                htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($description, ENT_QUOTES, 'UTF-8'),
                $priority,
                $targetServer,
                $templateId,
                $userId
            ]);
            $ticketId = (int)$this->pdo->lastInsertId();

            // Items
            foreach ($itemsValidation['validated_items'] as $item) {
                $this->itemService->insertTicketItem($ticketId, $item);
            }

            // Snapshot stages
            $insertStage = $this->pdo->prepare("
                INSERT INTO ticket_stage_progress
                    (ticket_id, stage_template_id, name, position, status,
                     assigned_to_user_id, assigned_to_role_id, started_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $firstStageProgressId = null;
            foreach ($resolvedStages as $idx => $rs) {
                $isFirst = ($idx === 0);
                $insertStage->execute([
                    $ticketId,
                    $rs['stage_template_id'],
                    $rs['name'],
                    $rs['position'],
                    $isFirst ? 'active' : 'pending',
                    $rs['assigned_to_user_id'],
                    $rs['assigned_to_role_id'],
                    $isFirst ? date('Y-m-d H:i:s') : null
                ]);
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
                return ['success' => true, 'completed' => false, 'next_stage' => $next['name'], 'errors' => []];
            }

            // No more stages — pipeline complete
            $this->pdo->prepare("
                UPDATE tickets
                SET status = 'completed', current_stage_progress_id = NULL, completed_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ")->execute([$ticketId]);

            $this->historyService->logHistory($ticketId, 'pipeline_completed', null, 'completed', $userId, 'All stages completed — pipeline closed');

            $this->pdo->commit();
            return ['success' => true, 'completed' => true, 'errors' => []];
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
     * Get a pipeline with stages, items and history.
     */
    public function getPipeline($ticketId, $includeHistory = true)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    t.id, t.ticket_number, t.title, t.description, t.status, t.priority,
                    t.target_server_uuid, t.rejection_reason, t.pipeline_template_id,
                    t.current_stage_progress_id, t.created_at, t.updated_at, t.completed_at,
                    t.created_by, creator.username AS created_by_username,
                    pt.name AS pipeline_type_name
                FROM tickets t
                LEFT JOIN users creator ON t.created_by = creator.id
                LEFT JOIN pipeline_templates pt ON t.pipeline_template_id = pt.id
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
                'items' => $this->itemService->getTicketItems($ticketId)
            ];
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
            } elseif ($scope === 'my_queue' && $userId) {
                // Pipelines whose ACTIVE stage is owned by me or my team (or I claimed it)
                $clause = '(cur.assigned_to_user_id = ? OR cur.claimed_by_user_id = ?';
                $params[] = $userId;
                $params[] = $userId;
                if (!empty($roleIds)) {
                    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
                    $clause .= " OR cur.assigned_to_role_id IN ($placeholders)";
                    $params = array_merge($params, $roleIds);
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

            $whereClause = 'WHERE ' . implode(' AND ', $where);

            $countStmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM tickets t
                LEFT JOIN ticket_stage_progress cur ON t.current_stage_progress_id = cur.id
                $whereClause
            ");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $offset = (max(1, (int)$page) - 1) * (int)$limit;

            $stmt = $this->pdo->prepare("
                SELECT
                    t.id, t.ticket_number, t.title, t.status, t.priority,
                    t.created_at, t.updated_at,
                    t.created_by, creator.username AS created_by_username,
                    pt.name AS pipeline_type_name,
                    cur.id AS current_stage_id, cur.name AS current_stage_name,
                    cur.assigned_to_user_id AS cur_user_id, su.username AS cur_user_name,
                    cur.assigned_to_role_id AS cur_role_id, sr.display_name AS cur_role_name,
                    cur.claimed_by_user_id AS cur_claimed_by, cu.username AS cur_claimed_name,
                    (SELECT COUNT(*) FROM ticket_stage_progress sp WHERE sp.ticket_id = t.id) AS stage_total,
                    (SELECT COUNT(*) FROM ticket_stage_progress sp WHERE sp.ticket_id = t.id AND sp.status = 'completed') AS stage_done
                FROM tickets t
                LEFT JOIN users creator ON t.created_by = creator.id
                LEFT JOIN pipeline_templates pt ON t.pipeline_template_id = pt.id
                LEFT JOIN ticket_stage_progress cur ON t.current_stage_progress_id = cur.id
                LEFT JOIN users su ON cur.assigned_to_user_id = su.id
                LEFT JOIN roles sr ON cur.assigned_to_role_id = sr.id
                LEFT JOIN users cu ON cur.claimed_by_user_id = cu.id
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
     * Stage rows for a pipeline with owner/claimer names resolved.
     */
    private function getStageProgress($ticketId)
    {
        // Stage name/owner are snapshotted on the progress row; the human-readable
        // instructions are reference-only and read from the (current) template stage.
        $stmt = $this->pdo->prepare("
            SELECT
                sp.id, sp.name, sp.position, sp.status,
                sp.assigned_to_user_id, su.username AS user_name,
                sp.assigned_to_role_id, sr.display_name AS role_name,
                sp.claimed_by_user_id, cu.username AS claimed_name, sp.claimed_at,
                sp.started_at, sp.completed_at,
                sp.completed_by_user_id, compu.username AS completed_by_name,
                sp.notes, ps.instructions AS instructions
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
                'notes' => $row['notes']
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
