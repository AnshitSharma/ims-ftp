<?php
/**
 * pipeline-create.php
 * Action: pipeline-create
 * Permission: pipeline.create | pipeline.manage
 *
 * Body params:
 * - pipeline_template_id (required)
 * - title (required), description (optional)
 * - priority (optional, default medium)
 * - target_server_uuid (optional): scopes any granted server access to this config
 * - requested_access (optional): JSON array of permission names being asked for
 * - parent_ticket_id (optional): raise this as a PREREQUISITE for that request,
 *     which stays frozen until this one is resolved. Validated in
 *     PipelineManager::validateParent() — the caller must already be involved in
 *     the parent (or hold pipeline.manage), the parent must be open, and the
 *     chain must stay within PipelineConfig::MAX_REQUEST_DEPTH.
 * - items (optional): JSON array of component items
 * - stage_overrides (optional): JSON object keyed by stage template id ->
 *     { "assignee_type": "user"|"role", "assignee_id": 4 }
 */

require_once(__DIR__ . '/../../../core/models/pipelines/PipelineManager.php');
require_once(__DIR__ . '/../../../core/helpers/RequestHelper.php');

try {
    $_POST = RequestHelper::parseRequestData();

    $canManage = $acl->hasPermission($user_id, 'pipeline.manage');
    if (!$acl->hasPermission($user_id, 'pipeline.create') && !$canManage) {
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

    // actions: the work this request performs once approved. A JSON array of
    // {action_type, payload}. PipelineManager shape-checks every entry against
    // RequestActionExecutor's registry and dry-runs the command-backed ones
    // through the real validation engine, so nothing is trusted here — an
    // unknown action_type or a smuggled parameter is rejected there.
    $actionsRaw = $_POST['actions'] ?? null;
    $actions = is_array($actionsRaw) ? $actionsRaw : json_decode((string)$actionsRaw, true);
    if (!is_array($actions)) {
        $actions = [];
    }

    // requested_access: RETIRED 2026-08-23. A request used to name the
    // permissions it wanted; approval now performs the work instead. Still
    // accepted so an older client cannot start failing mid-deploy, but it no
    // longer authorizes anything.
    $requestedRaw = $_POST['requested_access'] ?? null;
    $requestedAccess = is_array($requestedRaw) ? $requestedRaw : json_decode((string)$requestedRaw, true);
    if (!is_array($requestedAccess)) {
        $requestedAccess = [];
    }

    // The request this one is a prerequisite for. Left as-is when absent, so a
    // top-level request is exactly what it was before this parameter existed.
    $parentTicketId = $_POST['parent_ticket_id'] ?? null;
    if ($parentTicketId !== null && $parentTicketId !== '' && !is_numeric($parentTicketId)) {
        send_json_response(false, true, 400, "parent_ticket_id must be numeric", null);
        exit;
    }

    $data = [
        'title' => $_POST['title'] ?? '',
        'description' => $_POST['description'] ?? '',
        'priority' => $_POST['priority'] ?? 'medium',
        'target_server_uuid' => $_POST['target_server_uuid'] ?? null,
        'requested_access' => $requestedAccess,
        'actions' => $actions,
        'items' => $items,
        'stage_overrides' => $overrides,
        'parent_ticket_id' => ($parentTicketId === '' ? null : $parentTicketId)
    ];

    $mgr = new PipelineManager($pdo);
    $result = $mgr->createPipeline((int)$templateId, $data, $user_id, $canManage);

    if (!$result['success']) {
        send_json_response(false, true, 400, "Failed to create pipeline", ['errors' => $result['errors']]);
        exit;
    }

    $pipeline = $mgr->getPipeline($result['ticket_id'], false);
    send_json_response(true, true, 201, "Pipeline created successfully", [
        'pipeline_id' => $result['ticket_id'],
        'ticket_number' => $result['ticket_number'],
        // Parts this request names that no unit of exists in inventory yet. The
        // request WAS created -- this is not an error -- and the client offers to
        // raise the inventory record as a prerequisite. Absent/empty on the
        // normal path.
        'stock_missing' => $result['stock_missing'] ?? [],
        'pipeline' => $pipeline
    ]);
} catch (Exception $e) {
    error_log("pipeline-create error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to create pipeline");
}
