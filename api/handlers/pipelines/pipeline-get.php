<?php
/**
 * pipeline-get.php
 * Action: pipeline-get
 * Permission: pipeline.view_all | pipeline.manage (any pipeline) OR
 *             pipeline.view_own + row-level involvement (creator / stage owner /
 *             team member / claimer).
 *
 * Params: pipeline_id (required; ticket id). Alias: ticket_id.
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineManager.php');

try {
    $pipelineId = $_POST['pipeline_id'] ?? $_GET['pipeline_id'] ?? $_POST['ticket_id'] ?? $_GET['ticket_id'] ?? null;
    if (empty($pipelineId) || !is_numeric($pipelineId)) {
        send_json_response(false, true, 400, "pipeline_id is required and must be numeric", null);
        exit;
    }

    $canViewAll = $acl->hasPermission($user_id, 'pipeline.view_all');
    $canManage = $acl->hasPermission($user_id, 'pipeline.manage');
    $canViewOwn = $acl->hasPermission($user_id, 'pipeline.view_own');

    if (!$canViewAll && !$canManage && !$canViewOwn) {
        send_json_response(false, true, 403, "Permission denied: no pipeline view permission", null);
        exit;
    }

    $mgr = new PipelineManager($pdo);
    $pipeline = $mgr->getPipeline((int)$pipelineId, true);

    if (!$pipeline) {
        send_json_response(false, true, 404, "Pipeline not found", null);
        exit;
    }

    // Row-level visibility for non-privileged users.
    if (!$canViewAll && !$canManage) {
        $roleIds = array_map(function ($r) { return (int)$r['id']; }, $acl->getUserRoles($user_id));
        $involved = false;

        if (isset($pipeline['created_by']['id']) && (int)$pipeline['created_by']['id'] === (int)$user_id) {
            $involved = true;
        }

        if (!$involved) {
            foreach ($pipeline['stages'] as $stage) {
                $owner = $stage['owner'] ?? null;
                if ($owner && $owner['type'] === 'user' && (int)$owner['id'] === (int)$user_id) {
                    $involved = true;
                    break;
                }
                if ($owner && $owner['type'] === 'role' && in_array((int)$owner['id'], $roleIds, true)) {
                    $involved = true;
                    break;
                }
                if (!empty($stage['claimed_by']['id']) && (int)$stage['claimed_by']['id'] === (int)$user_id) {
                    $involved = true;
                    break;
                }
            }
        }

        if (!$involved) {
            send_json_response(false, true, 403, "Permission denied: you are not involved in this pipeline", null);
            exit;
        }
    }

    send_json_response(true, true, 200, "Pipeline retrieved successfully", ['pipeline' => $pipeline]);
} catch (Exception $e) {
    error_log("pipeline-get error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to retrieve pipeline");
}
