<?php
/**
 * pipeline-create.php
 * Action: pipeline-create
 * Permission: pipeline.create | pipeline.manage
 *
 * Body params:
 * - pipeline_template_id (required)
 * - title (required), description (required)
 * - priority (optional, default medium)
 * - target_server_uuid (optional)
 * - items (optional): JSON array of component items
 * - stage_overrides (optional): JSON object keyed by stage template id ->
 *     { "assignee_type": "user"|"role", "assignee_id": 4 }
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineManager.php');
require_once(__DIR__ . '/../../../core/helpers/RequestHelper.php');

try {
    $_POST = RequestHelper::parseRequestData();

    if (!$acl->hasPermission($user_id, 'pipeline.create')
        && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: pipeline.create required", null);
        exit;
    }

    $templateId = $_POST['pipeline_template_id'] ?? null;
    if (empty($templateId) || !is_numeric($templateId)) {
        send_json_response(false, true, 400, "pipeline_template_id is required and must be numeric", null);
        exit;
    }

    // Items (accept JSON string or array)
    $itemsRaw = $_POST['items'] ?? '[]';
    $items = is_array($itemsRaw) ? $itemsRaw : json_decode($itemsRaw, true);
    if (!is_array($items)) {
        send_json_response(false, true, 400, "items must be a JSON array", null);
        exit;
    }
    if (!empty($items) && !isset($items[0])) {
        $items = [$items];
    }

    // Stage overrides (accept JSON string or object)
    $overridesRaw = $_POST['stage_overrides'] ?? '{}';
    $overrides = is_array($overridesRaw) ? $overridesRaw : json_decode($overridesRaw, true);
    if (!is_array($overrides)) {
        $overrides = [];
    }

    $data = [
        'title' => $_POST['title'] ?? '',
        'description' => $_POST['description'] ?? '',
        'priority' => $_POST['priority'] ?? 'medium',
        'target_server_uuid' => $_POST['target_server_uuid'] ?? null,
        'items' => $items,
        'stage_overrides' => $overrides
    ];

    $mgr = new PipelineManager($pdo);
    $result = $mgr->createPipeline((int)$templateId, $data, $user_id);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to create pipeline", ['errors' => $result['errors']]);
        exit;
    }

    $pipeline = $mgr->getPipeline($result['ticket_id'], false);
    send_json_response(true, true, 201, "Pipeline created successfully", [
        'pipeline_id' => $result['ticket_id'],
        'ticket_number' => $result['ticket_number'],
        'pipeline' => $pipeline
    ]);
} catch (Exception $e) {
    error_log("pipeline-create error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to create pipeline");
}
