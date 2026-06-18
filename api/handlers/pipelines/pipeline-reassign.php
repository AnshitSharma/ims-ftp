<?php
/**
 * pipeline-reassign.php
 * Action: pipeline-reassign
 * Permission: pipeline.reassign | pipeline.manage
 *
 * Change the owner (user/role) of a not-yet-completed stage. Clears any claim.
 *
 * Body params: pipeline_id (alias ticket_id), stage_progress_id,
 *              assignee_type ("user"|"role"), assignee_id
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineManager.php');
require_once(__DIR__ . '/../../../core/helpers/RequestHelper.php');

try {
    $_POST = RequestHelper::parseRequestData();

    if (!$acl->hasPermission($user_id, 'pipeline.reassign') && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: pipeline.reassign required", null);
        exit;
    }

    $pipelineId = $_POST['pipeline_id'] ?? $_POST['ticket_id'] ?? null;
    $stageId = $_POST['stage_progress_id'] ?? null;
    $assigneeType = $_POST['assignee_type'] ?? null;
    $assigneeId = $_POST['assignee_id'] ?? null;

    if (empty($pipelineId) || !is_numeric($pipelineId) || empty($stageId) || !is_numeric($stageId)) {
        send_json_response(false, true, 400, "pipeline_id and stage_progress_id are required and must be numeric", null);
        exit;
    }

    $mgr = new PipelineManager($pdo);
    $result = $mgr->reassignStage((int)$pipelineId, (int)$stageId, $assigneeType, $assigneeId, $user_id);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to reassign stage", ['errors' => $result['errors']]);
        exit;
    }

    $pipeline = $mgr->getPipeline((int)$pipelineId, true);
    send_json_response(true, true, 200, "Stage reassigned successfully", ['pipeline' => $pipeline]);
} catch (Exception $e) {
    error_log("pipeline-reassign error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to reassign stage");
}
