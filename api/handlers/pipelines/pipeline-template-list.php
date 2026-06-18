<?php
/**
 * pipeline-template-list.php
 * Action: pipeline-template-list
 * Permission: pipeline.template_view | pipeline.create | pipeline.manage
 *
 * Params: include_inactive (bool), include_stages (bool)
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineTemplateManager.php');

try {
    if (!$acl->hasPermission($user_id, 'pipeline.template_view')
        && !$acl->hasPermission($user_id, 'pipeline.create')
        && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        send_json_response(false, true, 403, "Permission denied: cannot view pipeline types", null);
        exit;
    }

    $truthy = ['1', 'true', 'yes'];
    $includeInactive = in_array(strtolower((string)($_POST['include_inactive'] ?? $_GET['include_inactive'] ?? '')), $truthy, true);
    $includeStages = in_array(strtolower((string)($_POST['include_stages'] ?? $_GET['include_stages'] ?? '')), $truthy, true);

    // Only managers may see archived types.
    if ($includeInactive
        && !$acl->hasPermission($user_id, 'pipeline.template_manage')
        && !$acl->hasPermission($user_id, 'pipeline.manage')) {
        $includeInactive = false;
    }

    $mgr = new PipelineTemplateManager($pdo);
    $templates = $mgr->listTemplates($includeInactive, $includeStages);

    send_json_response(true, true, 200, "Pipeline types retrieved successfully", ['templates' => $templates]);
} catch (Exception $e) {
    error_log("pipeline-template-list error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to retrieve pipeline types");
}
