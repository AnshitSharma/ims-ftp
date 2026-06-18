<?php
/**
 * pipeline-template-update.php
 * Action: pipeline-template-update
 * Permission: pipeline.template_manage | pipeline.manage
 *
 * Body params:
 * - template_id (required)
 * - name, description, is_active (optional)
 * - stages (optional): JSON array — when supplied, fully REPLACES the stage set.
 *   Running pipelines are unaffected (they snapshot their stages).
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

    $templateId = $_POST['template_id'] ?? null;
    if (empty($templateId) || !is_numeric($templateId)) {
        send_json_response(false, true, 400, "template_id is required and must be numeric", null);
        exit;
    }

    $data = [];
    if (array_key_exists('name', $_POST)) {
        $data['name'] = $_POST['name'];
    }
    if (array_key_exists('description', $_POST)) {
        $data['description'] = $_POST['description'];
    }
    if (array_key_exists('is_active', $_POST)) {
        $data['is_active'] = $_POST['is_active'];
    }
    if (array_key_exists('stages', $_POST)) {
        $stagesRaw = $_POST['stages'];
        $stages = is_array($stagesRaw) ? $stagesRaw : json_decode($stagesRaw, true);
        if (!is_array($stages)) {
            send_json_response(false, true, 400, "stages must be a JSON array", null);
            exit;
        }
        $data['stages'] = $stages;
    }

    if (empty($data)) {
        send_json_response(false, true, 400, "No updates provided", null);
        exit;
    }

    $mgr = new PipelineTemplateManager($pdo);
    $result = $mgr->updateTemplate((int)$templateId, $data, $user_id);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to update pipeline type", ['errors' => $result['errors']]);
        exit;
    }

    $template = $mgr->getTemplate((int)$templateId);
    send_json_response(true, true, 200, "Pipeline type updated successfully", ['template' => $template]);
} catch (Exception $e) {
    error_log("pipeline-template-update error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to update pipeline type");
}
