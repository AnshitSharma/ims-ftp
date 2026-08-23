<?php
/**
 * pipeline-reject.php
 * Action: pipeline-reject
 * Permission: pipeline.act | pipeline.manage
 *
 * Decline a request at its active step. Nothing is performed, the request ends
 * as 'rejected', and the reason is stored so the requester knows what to do
 * next.
 *
 * WHY THIS EXISTS
 * ---------------
 * Approve-or-reject is the whole verb set of the automation model, and until
 * 2026-08-23 only half of it existed. An approver could complete a step
 * (pipeline-complete) or cancel the entire request (pipeline-cancel), but had no
 * way to simply say no — even though 'rejected' was a legal value in both
 * ticket_stage_progress.status and tickets.status, written by nothing.
 *
 * Gated exactly like pipeline-complete: the same permission pair, and the same
 * admin/super_admin role gate in api.php. Refusing work is the same authority as
 * performing it, so it must not be the easier door.
 *
 * Body params: pipeline_id (alias ticket_id), stage_progress_id, reason (required)
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

    // Required, unlike pipeline-complete's optional notes. A refusal with no
    // reason leaves the requester with nothing to act on.
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($reason === '') {
        send_json_response(false, true, 400, "A reason is required when rejecting a request", null);
        exit;
    }
    if (mb_strlen($reason) > 2000) {
        send_json_response(false, true, 400, "reason must not exceed 2000 characters", null);
        exit;
    }

    $mgr = new PipelineManager($pdo);
    $result = $mgr->rejectStage((int)$pipelineId, (int)$stageId, $user_id, $reason, $canManage);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to reject request", ['errors' => $result['errors']]);
        exit;
    }

    $pipeline = $mgr->getPipeline((int)$pipelineId, true);
    send_json_response(true, true, 200, "Request rejected — nothing was performed", [
        'pipeline' => $pipeline
    ]);
} catch (Exception $e) {
    error_log("pipeline-reject error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to reject request");
}
