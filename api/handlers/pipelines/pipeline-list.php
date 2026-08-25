<?php
/**
 * pipeline-list.php
 * Action: pipeline-list
 * Permission: pipeline.view_own | pipeline.view_all | pipeline.manage
 *
 * Params:
 * - scope: my_queue | created | all   (non-privileged users are forced off 'all')
 * - status, priority, search, pipeline_template_id
 * - parent_ticket_id: only prerequisites raised on that request
 * - top_level_only: exclude prerequisites (ignored when parent_ticket_id is set)
 * - page, limit
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineManager.php');

try {
    $canViewAll = $acl->hasPermission($user_id, 'pipeline.view_all');
    $canManage = $acl->hasPermission($user_id, 'pipeline.manage');
    $canViewOwn = $acl->hasPermission($user_id, 'pipeline.view_own');

    if (!$canViewAll && !$canManage && !$canViewOwn) {
        send_json_response(false, true, 403, "Permission denied: no pipeline view permission", null);
        exit;
    }

    $scope = $_POST['scope'] ?? $_GET['scope'] ?? 'all';
    $scope = in_array($scope, ['my_queue', 'mine', 'created', 'all'], true) ? $scope : 'all';

    // Non-privileged users cannot browse everything — clamp to their own view.
    // 'mine', not 'my_queue': a request you raised is normally waiting on someone
    // else's step, so my_queue would hide it from you the moment you submitted it.
    if ($scope === 'all' && !$canViewAll && !$canManage) {
        $scope = 'mine';
    }

    // Resolve the caller's role ids for the my_queue (team) scope.
    $roleIds = array_map(function ($r) { return (int)$r['id']; }, $acl->getUserRoles($user_id));

    $filters = [
        'scope' => $scope,
        'user_id' => $user_id,
        'user_role_ids' => $roleIds,
        'status' => $_POST['status'] ?? $_GET['status'] ?? null,
        'priority' => $_POST['priority'] ?? $_GET['priority'] ?? null,
        'search' => $_POST['search'] ?? $_GET['search'] ?? null,
        'pipeline_template_id' => $_POST['pipeline_template_id'] ?? $_GET['pipeline_template_id'] ?? null,
    ];

    // Prerequisite filters (2026_08_25_007). Mutually exclusive in the manager,
    // which prefers parent_ticket_id when both arrive. Both are ignored outright
    // while tickets.parent_ticket_id is absent, so an un-seeded server lists
    // everything rather than erroring.
    $parentFilter = $_POST['parent_ticket_id'] ?? $_GET['parent_ticket_id'] ?? null;
    if ($parentFilter !== null && $parentFilter !== '' && is_numeric($parentFilter)) {
        $filters['parent_ticket_id'] = (int)$parentFilter;
    }

    $topOnly = $_POST['top_level_only'] ?? $_GET['top_level_only'] ?? null;
    if ($topOnly !== null && filter_var($topOnly, FILTER_VALIDATE_BOOLEAN)) {
        $filters['top_level_only'] = true;
    }

    $page = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_POST['limit'] ?? $_GET['limit'] ?? 20)));

    $mgr = new PipelineManager($pdo);
    $result = $mgr->listPipelines($filters, $page, $limit);

    send_json_response(true, true, 200, "Pipelines retrieved successfully", $result);
} catch (Exception $e) {
    error_log("pipeline-list error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to retrieve pipelines");
}
