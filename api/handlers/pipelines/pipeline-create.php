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

    $canManage = RequestHelper::requirePipelinePermission($acl, $user_id, ['pipeline.create'], "Permission denied: pipeline.create required");

    $templateId = RequestHelper::positiveInt($_POST['pipeline_template_id'] ?? null);
    if ($templateId === null) {
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
    //
    // ONLY FROM SOMEONE WHO MAY MANAGE REQUESTS. [M-07 / F-18]
    //
    // A step's default owner is who the request type says must sign that step
    // off — a supervisor's review, a second pair of eyes. This input replaced
    // that owner with any user the caller named, so an ordinary requester could
    // nominate THEMSELVES as their own reviewer and then complete the step. The
    // separate reassign operation has always been restricted; creating the
    // request with the assignment already changed went around it.
    //
    // Nothing in the product sends this field — the Raise a Request form does
    // not offer it — so gating it takes no feature away. The one legitimate
    // reassignment, the Hardware Handover carrier, is NOT this: PipelineManager
    // derives it server-side from the validated action data, after this point.
    $overridesRaw = $_POST['stage_overrides'] ?? '{}';
    $overrides = is_array($overridesRaw) ? $overridesRaw : json_decode($overridesRaw, true);
    if (!is_array($overrides)) {
        $overrides = [];
    }
    if (!empty($overrides) && !$canManage) {
        send_json_response(false, true, 403,
            "Step owners are set by the request type and cannot be chosen when raising a request", null);
        exit;
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
    if ($parentTicketId !== null && $parentTicketId !== '' && RequestHelper::positiveInt($parentTicketId) === null) {
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

    // idempotency_key (optional): one per opened New Request form, sent with
    // every submit of it, so the same form arriving twice — a resend after a
    // lost response — makes one request, not two. Same table and the same
    // (user, module, key) scoping as bulk-add; see seeder 2026_09_21_004.
    //
    // Unlike bulk-add, only a CREATED request is recorded against the key. A
    // refused one releases it: the requester fixes the field and submits the
    // same form again, and replaying the old refusal would make it unfixable.
    //
    // No key, or no table yet, is exactly the behaviour before this existed.
    $idempotencyKey = trim((string)($_POST['idempotency_key'] ?? ''));
    $keyClaimed = false;
    if ($idempotencyKey !== '' && SchemaHelper::hasTable($pdo, 'bulk_operation_keys')) {
        if (strlen($idempotencyKey) > 100) {
            send_json_response(false, true, 400, "idempotency_key must be 100 characters or fewer", null);
            exit;
        }

        $claim = $pdo->prepare(
            "INSERT IGNORE INTO bulk_operation_keys (user_id, module, idempotency_key, operation)
             VALUES (?, 'pipeline', ?, 'pipeline-create')"
        );
        $claim->execute([$user_id, $idempotencyKey]);

        if ($claim->rowCount() === 0) {
            $prior = $pdo->prepare(
                "SELECT http_code, response_json FROM bulk_operation_keys
                  WHERE user_id = ? AND module = 'pipeline' AND idempotency_key = ?"
            );
            $prior->execute([$user_id, $idempotencyKey]);
            $row = $prior->fetch(PDO::FETCH_ASSOC);

            if ($row && $row['response_json'] !== null) {
                $replay = json_decode($row['response_json'], true);
                send_json_response(true, true, (int)$row['http_code'], "Pipeline created successfully",
                    is_array($replay) ? $replay : []);
                exit;
            }

            // Claimed and not finished: the first submit may still be running.
            send_json_response(false, true, 409, "This request is already being created", null);
            exit;
        }
        $keyClaimed = true;
    }

    $releaseKey = function () use ($pdo, $user_id, $idempotencyKey) {
        try {
            $pdo->prepare(
                "DELETE FROM bulk_operation_keys
                  WHERE user_id = ? AND module = 'pipeline' AND idempotency_key = ? AND response_json IS NULL"
            )->execute([$user_id, $idempotencyKey]);
        } catch (Throwable $e) {
            error_log("pipeline-create: could not release idempotency key: " . $e->getMessage());
        }
    };

    $mgr = new PipelineManager($pdo);
    $result = $mgr->createPipeline((int)$templateId, $data, $user_id, $canManage);

    if (!$result['success']) {
        if ($keyClaimed) {
            $releaseKey();
        }
        send_json_response(false, true, 400, "Failed to create pipeline", ['errors' => $result['errors']]);
        exit;
    }

    $pipeline = $mgr->getPipeline($result['ticket_id'], false);
    $payload = [
        'pipeline_id' => $result['ticket_id'],
        'ticket_number' => $result['ticket_number'],
        // Parts this request names that no unit of exists in inventory yet. The
        // request WAS created -- this is not an error -- and the client offers to
        // raise the inventory record as a prerequisite. Absent/empty on the
        // normal path.
        'stock_missing' => $result['stock_missing'] ?? [],
        'pipeline' => $pipeline
    ];

    // Best-effort, as in bulk-add: the request is committed either way, and a
    // receipt that failed to write leaves a resend with the safe 409.
    if ($keyClaimed) {
        try {
            $pdo->prepare(
                "UPDATE bulk_operation_keys SET http_code = 201, response_json = ?, completed_at = NOW()
                  WHERE user_id = ? AND module = 'pipeline' AND idempotency_key = ?"
            )->execute([json_encode($payload), $user_id, $idempotencyKey]);
        } catch (Throwable $e) {
            error_log("pipeline-create: could not record idempotency result: " . $e->getMessage());
        }
    }

    send_json_response(true, true, 201, "Pipeline created successfully", $payload);
} catch (Exception $e) {
    if (!empty($keyClaimed) && isset($releaseKey)) {
        $releaseKey();
    }
    error_log("pipeline-create error: " . $e->getMessage());
    send_json_response(false, true, 500, "Failed to create pipeline");
}
