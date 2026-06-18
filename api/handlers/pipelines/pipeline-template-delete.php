<?php
/**
 * pipeline-template-delete.php
 * Action: pipeline-template-delete
 * Permission: pipeline.template_manage | pipeline.manage
 *
 * Params: template_id (required)
 * Refuses if pipelines were created from this type (archive it instead).
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

    $templateId = $_POST['template_id'] ?? $_GET['template_id'] ?? null;
    if (empty($templateId) || !is_numeric($templateId)) {
        send_json_response(false, true, 400, "template_id is required and must be numeric", null);
        exit;
    }

    $mgr = new PipelineTemplateManager($pdo);
    $result = $mgr->deleteTemplate((int)$templateId);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to delete pipeline type", ['errors' => $result['errors']]);
        exit;
    }

    send_json_response(true, true, 200, "Pipeline type deleted successfully", null);
} catch (Exception $e) {
    error_log("pipeline-template-delete error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to delete pipeline type");
}
