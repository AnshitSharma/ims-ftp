<?php
/**
 * pipeline-template-get.php
 * Action: pipeline-template-get
 * Permission: pipeline.template_view | pipeline.create | pipeline.manage
 *
 * Params: template_id (required)
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineTemplateManager.php');
require_once(__DIR__ . '/../../../core/helpers/RequestHelper.php');

try {
    RequestHelper::requirePipelinePermission($acl, $user_id, ['pipeline.template_view', 'pipeline.create'], "Permission denied: cannot view pipeline types");

    $templateId = RequestHelper::requireNumeric('template_id', "template_id is required and must be numeric");

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
