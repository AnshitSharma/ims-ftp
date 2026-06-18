<?php
/**
 * pipeline-template-get.php
 * Action: pipeline-template-get
 * Permission: pipeline.template_view | pipeline.create | pipeline.manage
 *
 * Params: template_id (required)
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineTemplateManager.php');

try {
    if (!$acl->hasPermission($user_id, 'pipeline.template_view')
        && !$acl->hasPermission($user_id, 'pipeline.create')
        && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: cannot view pipeline types", null);
        exit;
    }

    $templateId = $_POST['template_id'] ?? $_GET['template_id'] ?? null;
    if (empty($templateId) || !is_numeric($templateId)) {
        send_json_response(false, true, 400, "template_id is required and must be numeric", null);
        exit;
    }

    $mgr = new PipelineTemplateManager($pdo);
    $template = $mgr->getTemplate((int)$templateId);

    if (!$template) {
        send_json_response(false, true, 404, "Pipeline type not found", null);
        exit;
    }

    send_json_response(true, true, 200, "Pipeline type retrieved successfully", ['template' => $template]);
} catch (Exception $e) {
    error_log("pipeline-template-get error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to retrieve pipeline type");
}
