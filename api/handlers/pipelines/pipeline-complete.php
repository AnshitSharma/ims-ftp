<?php
/**
 * pipeline-complete.php
 * Action: pipeline-complete
 * Permission: pipeline.act | pipeline.manage
 *
 * Complete the active stage and auto-advance to the next stage's owner. When
 * there is no next stage, the pipeline is marked completed.
 *
 * Body params: pipeline_id (alias ticket_id), stage_progress_id, notes (optional)
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineManager.php');
require_once(__DIR__ . '/../../../core/helpers/RequestHelper.php');

try {
    $_POST = RequestHelper::parseRequestData();

    $canManage = $acl->hasPermission($user_id, 'pipeline.manage');
    if (!$acl->hasPermission($user_id, 'pipeline.act') && !$canManage) {
        send_json_response(false, true, 403, "Permission denied: pipeline.act required", null);
        exit;
    }

    $pipelineId = $_POST['pipeline_id'] ?? $_POST['ticket_id'] ?? null;
    $stageId = $_POST['stage_progress_id'] ?? null;
    if (empty($pipelineId) || !is_numeric($pipelineId) || empty($stageId) || !is_numeric($stageId)) {
        send_json_response(false, true, 400, "pipeline_id and stage_progress_id are required and must be numeric", null);
        exit;
    }

    $notes = $_POST['notes'] ?? null;
    if ($notes !== null && mb_strlen($notes) > 2000) {
        send_json_response(false, true, 400, "notes must not exceed 2000 characters", null);
        exit;
    }

    $mgr = new PipelineManager($pdo);
    $result = $mgr->completeStage((int)$pipelineId, (int)$stageId, $user_id, $notes, $canManage);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to complete stage", ['errors' => $result['errors']]);
        exit;
    }

    $pipeline = $mgr->getPipeline((int)$pipelineId, true);
    $message = !empty($result['completed'])
        ? "Final stage completed — pipeline closed"
        : "Stage completed — advanced to '" . ($result['next_stage'] ?? 'next stage') . "'";

    send_json_response(true, true, 200, $message, [
        'completed' => !empty($result['completed']),
        'pipeline' => $pipeline
    ]);
} catch (Exception $e) {
    error_log("pipeline-complete error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to complete stage");
}
