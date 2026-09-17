<?php
/**
 * pipeline-claim.php
 * Action: pipeline-claim
 * Permission: pipeline.claim | pipeline.manage
 *
 * Accept (claim) the active, role-owned stage — locks it to the caller.
 *
 * Body params: pipeline_id (alias ticket_id), stage_progress_id
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineManager.php');
require_once(__DIR__ . '/../../../core/helpers/RequestHelper.php');

try {
    $_POST = RequestHelper::parseRequestData();

    $canManage = RequestHelper::requirePipelinePermission($acl, $user_id, ['pipeline.claim'], "Permission denied: pipeline.claim required");

    $pipelineId = RequestHelper::pipelineId("pipeline_id and stage_progress_id are required and must be numeric");
    $stageId = RequestHelper::requireNumeric('stage_progress_id', "pipeline_id and stage_progress_id are required and must be numeric");

    $mgr = new PipelineManager($pdo);
    $result = $mgr->claimStage((int)$pipelineId, (int)$stageId, $user_id, $canManage);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to claim stage", ['errors' => $result['errors']]);
        exit;
    }

    $pipeline = $mgr->getPipeline((int)$pipelineId, true);
    send_json_response(true, true, 200, "Stage claimed successfully", ['pipeline' => $pipeline]);
} catch (Exception $e) {
    error_log("pipeline-claim error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to claim stage");
}
