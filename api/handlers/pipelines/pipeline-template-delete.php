<?php
/**
 * pipeline-template-delete.php
 * Action: pipeline-template-delete
 * Permission: pipeline.template_manage | pipeline.manage
 *
 * Params: template_id (required), force (optional)
 *
 * A type with requests behind it is refused ONCE, with the request count in
 * `data.request_count`, so the caller can ask a sharper question before sending
 * force=1. Built-in types are never deletable.
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

    // Anything but an explicit truthy value is "no", so a stray parameter can
    // never turn the confirmation step off.
    $forceRaw = $_POST['force'] ?? $_GET['force'] ?? null;
    $force = in_array((string)$forceRaw, ['1', 'true', 'yes'], true);

    $mgr = new PipelineTemplateManager($pdo);
    $result = $mgr->deleteTemplate((int)$templateId, $force);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to delete pipeline type", [
            'errors' => $result['errors'],
            'request_count' => $result['request_count'] ?? 0,
        ]);
        exit;
    }

    send_json_response(true, true, 200, "Pipeline type deleted successfully", null);
} catch (Exception $e) {
    error_log("pipeline-template-delete error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to delete pipeline type");
}
