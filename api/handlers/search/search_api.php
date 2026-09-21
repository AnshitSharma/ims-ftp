<?php
/**
 * Search handler — global inventory search.
 *
 * Included by api/api.php for the `search` module.
 */

function handleSearchOperations($operation, $user) {
    global $pdo;

    if (!hasPermission($pdo, 'search.use', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for search operations");
    }

    switch ($operation) {
        // `global` lived here: a 12-table × 4-column leading-wildcard scan, merged
        // and sorted in PHP. Removed 2026-09-21 with performGlobalSearch() — the
        // client half was retired earlier and no caller remained in either stack.
        // `models` below is what the UI actually uses, and it resolves through the
        // indexed catalogue instead of scanning every inventory table.

        // JSON-010: type-ahead over MODELS, so the browser stops downloading and parsing the
        // whole 536 KB spec catalogue to populate a picker. Gated on the same search.use
        // check performed above -- it exposes catalogue data plus unit counts, both of which
        // the existing list endpoints already return to a search.use holder, so it needs no
        // permission of its own and no ACL seeder.
        case 'models':
            $query = $_GET['q'] ?? $_POST['q'] ?? '';
            $type = $_GET['type'] ?? $_POST['type'] ?? null;
            $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 20);

            if (trim($query) === '') {
                send_json_response(0, 1, 400, "Search query is required");
            }

            if ($type !== null && $type !== '' && !in_array($type, VALID_COMPONENT_TYPES, true)) {
                send_json_response(0, 1, 400, "Unknown component type: $type");
            }

            // Guarded require: this file deploys independently of the handler, and code
            // reaches production before anything that references it is guaranteed to be
            // there. A hard require would fatal the whole API for the window in between.
            $modelSearchFile = __DIR__ . '/../../../core/models/components/ModelSearch.php';
            if (!is_readable($modelSearchFile)) {
                send_json_response(0, 1, 503, "Model search is not available on this deployment");
            }
            require_once $modelSearchFile;

            try {
                $results = ModelSearch::search($pdo, $query, ($type !== '' ? $type : null), $limit);
            } catch (Throwable $e) {
                error_log("Model search failed: " . $e->getMessage());
                send_json_response(0, 1, 500, "Model search failed");
            }
            send_json_response(1, 1, 200, "Model search completed", $results);
            break;

        // One model by its spec uuid, with the full spec body. Replaces add-form.js
        // stringifying entire model objects into a DOM dataset attribute.
        case 'model':
            $specUuid = $_GET['uuid'] ?? $_POST['uuid'] ?? '';
            $type = $_GET['type'] ?? $_POST['type'] ?? null;

            if (trim($specUuid) === '') {
                send_json_response(0, 1, 400, "uuid is required");
            }

            $modelSearchFile = __DIR__ . '/../../../core/models/components/ModelSearch.php';
            if (!is_readable($modelSearchFile)) {
                send_json_response(0, 1, 503, "Model search is not available on this deployment");
            }
            require_once $modelSearchFile;

            try {
                $model = ModelSearch::get($pdo, $specUuid, ($type !== '' ? $type : null));
            } catch (Throwable $e) {
                error_log("Model fetch failed: " . $e->getMessage());
                send_json_response(0, 1, 500, "Model fetch failed");
            }

            if ($model === null) {
                send_json_response(0, 1, 404, "No model with that uuid");
            }
            send_json_response(1, 1, 200, "Model retrieved", $model);
            break;

        default:
            send_json_response(0, 1, 400, "Invalid search operation: $operation");
    }
}
