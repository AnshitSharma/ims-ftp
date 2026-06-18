<?php
/**
 * pipeline-template-create.php
 * Action: pipeline-template-create
 * Permission: pipeline.template_manage | pipeline.manage
 *
 * Body params:
 * - name (required)
 * - description (optional)
 * - is_active (optional, default 1)
 * - stages (required): JSON array, each:
 *     { "name": "...", "assignee_type": "user"|"role", "assignee_id": 4, "instructions": "..." }
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineTemplateManager.php');
require_once(__DIR__ . '/../../../core/helpers/RequestHelper.php');

try {
    $_POST = RequestHelper::parseRequestData();

    if (!$acl->hasPermission($user_id, 'pipeline.template_manage')
        && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: pipeline.template_manage required", null);
        exit;
    }

    $stagesRaw = $_POST['stages'] ?? '[]';
    $stages = is_array($stagesRaw) ? $stagesRaw : json_decode($stagesRaw, true);
    if (!is_array($stages)) {
        send_json_response(false, true, 400, "stages must be a JSON array", null);
        exit;
    }

    $data = [
        'name' => $_POST['name'] ?? '',
        'description' => $_POST['description'] ?? null,
        'is_active' => $_POST['is_active'] ?? 1,
        'stages' => $stages
    ];

    $mgr = new PipelineTemplateManager($pdo);
    $result = $mgr->createTemplate($data, $user_id);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to create pipeline type", ['errors' => $result['errors']]);
        exit;
    }

    $template = $mgr->getTemplate($result['template_id']);
    send_json_response(true, true, 201, "Pipeline type created successfully", [
        'template_id' => $result['template_id'],
        'template' => $template
    ]);
} catch (Exception $e) {
    error_log("pipeline-template-create error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to create pipeline type");
}
