<?php
/**
 * pipeline-unlink-child.php
 * Action: pipeline-unlink-child
 * Permission: pipeline.manage (plus the admin/super_admin role gate in api.php,
 *             which applies because this operation is deliberately NOT listed in
 *             handlePipelineOperations()'s $selfServiceOperations)
 *
 * Detach a prerequisite request from the request it was freezing.
 *
 * THE ESCAPE HATCH FOR A REFUSED PREREQUISITE. A rejected prerequisite keeps its
 * parent frozen on purpose -- a refusal must never read as a resolution -- so
 * without this the parent's only exits are rejection and cancellation. That is
 * too blunt when the access was simply asked of the wrong team: detach the
 * refusal and raise a corrected one.
 *
 * Neither request is otherwise touched. The child keeps its status, steps,
 * actions and history; a rejected child stays rejected. Both timelines record
 * the detachment.
 *
 * Body params: child_id (required; alias pipeline_id / ticket_id)
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineManager.php');
require_once(__DIR__ . '/../../../core/helpers/RequestHelper.php');

try {
    $_POST = RequestHelper::parseRequestData();

    if (!$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: pipeline.manage required", null);
        exit;
    }

    $childId = $_POST['child_id'] ?? $_POST['pipeline_id'] ?? $_POST['ticket_id'] ?? null;
    if (empty($childId) || !is_numeric($childId)) {
        send_json_response(false, true, 400, "child_id is required and must be numeric", null);
        exit;
    }

    $mgr = new PipelineManager($pdo);
    $result = $mgr->unlinkChild((int)$childId, $user_id);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to detach the prerequisite", ['errors' => $result['errors']]);
        exit;
    }

    // Say whether the parent actually came unstuck. More than one prerequisite
    // is allowed, so detaching one does not necessarily free it -- and an
    // approver who assumes it did will just hit the block again.
    $message = !empty($result['parent_still_blocked'])
        ? "Prerequisite detached — #{$result['parent_ticket_number']} is still frozen behind another one"
        : "Prerequisite detached — #{$result['parent_ticket_number']} is no longer frozen";

    send_json_response(true, true, 200, $message, [
        'parent' => $mgr->getPipeline((int)$result['parent_ticket_id'], true),
        'parent_still_blocked' => !empty($result['parent_still_blocked'])
    ]);
} catch (Exception $e) {
    error_log("pipeline-unlink-child error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to detach the prerequisite");
}
