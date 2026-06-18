<?php
/**
 * pipeline-cancel.php
 * Action: pipeline-cancel
 * Permission: pipeline.cancel | pipeline.manage
 *
 * Cancel a pipeline (any non-terminal state).
 *
 * Body params: pipeline_id (alias ticket_id), reason (optional)
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineManager.php');
require_once(__DIR__ . '/../../../core/helpers/RequestHelper.php');

try {
    $_POST = RequestHelper::parseRequestData();

    if (!$acl->hasPermission($user_id, 'pipeline.cancel') && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: pipeline.cancel required", null);
        exit;
    }

    $pipelineId = $_POST['pipeline_id'] ?? $_POST['ticket_id'] ?? null;
    if (empty($pipelineId) || !is_numeric($pipelineId)) {
        send_json_response(false, true, 400, "pipeline_id is required and must be numeric", null);
        exit;
    }

    $reason = $_POST['reason'] ?? null;
    if ($reason !== null && mb_strlen($reason) > 1000) {
        send_json_response(false, true, 400, "reason must not exceed 1000 characters", null);
        exit;
    }

    $mgr = new PipelineManager($pdo);
    $result = $mgr->cancelPipeline((int)$pipelineId, $user_id, $reason);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to cancel pipeline", ['errors' => $result['errors']]);
        exit;
    }

    $pipeline = $mgr->getPipeline((int)$pipelineId, true);
    send_json_response(true, true, 200, "Pipeline cancelled successfully", ['pipeline' => $pipeline]);
} catch (Exception $e) {
    error_log("pipeline-cancel error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to cancel pipeline");
}
