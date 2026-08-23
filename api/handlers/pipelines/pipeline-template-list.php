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

    // The catalogue of work an approval can perform, sent alongside the types so
    // the Request Types editor can offer it without keeping its own copy. One
    // source of truth: RequestActionExecutor::ACTION_TYPES. A hardcoded list in
    // the frontend would drift the moment an action is added or renamed, and the
    // drift would be silent — the editor would offer something the executor
    // rejects, or hide something it supports.
    $actionTypes = [];
    foreach (RequestActionExecutor::actionTypes() as $type) {
        $spec = RequestActionExecutor::describe($type);
        $actionTypes[] = [
            'action_type' => $type,
            'label'       => $spec['label'],
            'scope'       => $spec['scope'],
        ];
    }

    send_json_response(true, true, 200, "Pipeline types retrieved successfully", [
        'templates'    => $templates,
        'action_types' => $actionTypes,
    ]);
} catch (Exception $e) {
    error_log("pipeline-template-list error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to retrieve pipeline types");
}
