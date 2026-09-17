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

    RequestHelper::requirePipelinePermission($acl, $user_id, ['pipeline.reassign'], "Permission denied: pipeline.reassign required");

    $pipelineId = RequestHelper::pipelineId("pipeline_id and stage_progress_id are required and must be numeric");
    $stageId = RequestHelper::requireNumeric('stage_progress_id', "pipeline_id and stage_progress_id are required and must be numeric");
    $assigneeType = $_POST['assignee_type'] ?? null;
    $assigneeId = $_POST['assignee_id'] ?? null;

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
