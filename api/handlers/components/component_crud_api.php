<?php
/**
 * Component CRUD handler — list/get/add/update/delete for all 10 inventory
 * component types (cpu, ram, storage, motherboard, nic, caddy, chassis,
 * pciecard, hbacard, sfp).
 *
 * Included by api/api.php, which has already verified the ACL permission for
 * the operation via the central permission map (api/permission_map.php).
 * The data-layer functions (addComponent, updateComponent, ...) live in
 * core/helpers/BaseFunctions.php.
 */

function handleComponentOperations($module, $operation, $user) {
    global $pdo;

    switch ($operation) {
        case 'list':
            // Pagination/search are opt-in: requests without a limit param keep
            // the original return-everything behavior (server builder, scripts).
            // The dashboard sends limit/offset/search (dashboard.js loadComponentList).
            $limitParam = $_GET['limit'] ?? $_POST['limit'] ?? null;
            $limit = ($limitParam !== null && $limitParam !== '') ? max(1, min((int)$limitParam, 500)) : null;
            $offset = max(0, (int)($_GET['offset'] ?? $_POST['offset'] ?? 0));
            $search = trim($_GET['search'] ?? $_POST['search'] ?? '');

            $components = getComponentsByType($pdo, $module, $limit, $offset, $search);

            // Resolve ModelName from JSON specs via UUID
            $componentService = null;
            try {
                require_once __DIR__ . '/../../../core/models/components/ComponentDataService.php';
                $componentService = ComponentDataService::getInstance();
            } catch (Exception $e) {
                error_log("[ModelName] ComponentDataService load failed: " . $e->getMessage());
            }

            foreach ($components as &$comp) {
                $comp['ModelName'] = null;
                if ($componentService !== null && !empty($comp['UUID'])) {
                    try {
                        $spec = $componentService->findComponentByUuid($module, $comp['UUID']);
                        if ($spec !== null) {
                            $brand = $spec['brand'] ?? null;
                            $model = $spec['model'] ?? $spec['name'] ?? $spec['model_name'] ?? $spec['product_name'] ?? null;

                            // RAM: build "Brand Type CapacityGB Module"
                            if ($model === null && $module === 'ram') {
                                $parts = array_filter([$brand, $spec['memory_type'] ?? null,
                                    isset($spec['capacity_GB']) ? $spec['capacity_GB'] . 'GB' : null,
                                    $spec['module_type'] ?? null]);
                                $comp['ModelName'] = $parts ? implode(' ', $parts) : null;
                            }
                            // Storage: build "Brand Type CapacityGB"
                            elseif ($model === null && $module === 'storage') {
                                $cap = null;
                                if (isset($spec['capacity_GB'])) {
                                    $cap = $spec['capacity_GB'] >= 1000
                                        ? round($spec['capacity_GB'] / 1000, 1) . 'TB'
                                        : $spec['capacity_GB'] . 'GB';
                                }
                                $parts = array_filter([$brand, $spec['storage_type'] ?? null, $cap]);
                                $comp['ModelName'] = $parts ? implode(' ', $parts) : null;
                            }
                            elseif ($brand && $model) {
                                $comp['ModelName'] = $brand . ' ' . $model;
                            } elseif ($model) {
                                $comp['ModelName'] = $model;
                            }
                        }
                    } catch (Exception $e) {
                        // Silent fail per component
                    }
                }
                // Fallback: extract from Notes field
                if ($comp['ModelName'] === null && !empty($comp['Notes'])) {
                    if (preg_match('/Brand:\s*([^,]+).*Model:\s*(.+?)(\r|\n|$)/i', $comp['Notes'], $matches)) {
                        $comp['ModelName'] = trim($matches[1]) . ' ' . trim($matches[2]);
                    }
                }
            }
            unset($comp);

            // Resolve VendorName from VendorID
            $vendorCache = [];
            foreach ($components as &$comp) {
                $comp['VendorName'] = null;
                if (!empty($comp['VendorID'])) {
                    $vid = (int)$comp['VendorID'];
                    if (!isset($vendorCache[$vid])) {
                        $vstmt = $pdo->prepare("SELECT name FROM vendors WHERE id = ?");
                        $vstmt->execute([$vid]);
                        $vendorCache[$vid] = $vstmt->fetchColumn() ?: null;
                    }
                    $comp['VendorName'] = $vendorCache[$vid];
                }
            }
            unset($comp);

            // total_count = all matching rows (not page size) so the dashboard's
            // pagination UI (built from total_count) reflects the real total
            $totalCount = ($limit !== null)
                ? getComponentCountByType($pdo, $module, $search)
                : count($components);

            $responseData = [
                'components' => $components,
                'total_count' => $totalCount
            ];
            if ($limit !== null) {
                $responseData['limit'] = $limit;
                $responseData['offset'] = $offset;
            }

            send_json_response(1, 1, 200, ucfirst($module) . " components retrieved", $responseData);
            break;

        case 'get':
            $componentId = $_GET['id'] ?? $_POST['id'] ?? '';

            if (empty($componentId)) {
                send_json_response(0, 1, 400, "Component ID is required");
            }

            $component = getComponentById($pdo, $module, $componentId);

            if ($component) {
                send_json_response(1, 1, 200, "Component retrieved successfully", ['component' => $component]);
            } else {
                send_json_response(0, 1, 404, "Component not found");
            }
            break;

        case 'add':
            // SECURITY: mass-assignment defense lives in addComponent()
            // (BaseFunctions.php): INFORMATION_SCHEMA column whitelist,
            // blocked system columns, and UUID-vs-JSON-spec validation.
            // Do not re-implement that here.
            $componentData = $_POST;
            unset($componentData['action']);

            if (empty($componentData)) {
                send_json_response(0, 1, 400, "Component data is required");
            }

            try {
                $result = addComponent($pdo, $module, $componentData, $user['id']);

                if ($result) {
                    error_log("Successfully added $module component with ID: " . $result['id']
                        . " (asset tag " . $result['asset_tag'] . ")");
                    send_json_response(1, 1, 201, ucfirst($module) . " component added successfully", [
                        'component_id' => $result['id'],
                        'uuid' => $result['uuid'],
                        // The tag a technician writes on the physical unit — the
                        // caller needs it back, especially when no serial was given.
                        'asset_tag' => $result['asset_tag']
                    ]);
                } else {
                    send_json_response(0, 1, 400, "Failed to add " . $module . " component");
                }
            } catch (InvalidArgumentException $e) {
                // Column whitelist / UUID validation rejection — show the
                // specific reason (it's already our own text, not PDO output).
                error_log("Validation error adding $module component: " . $e->getMessage());
                send_json_response(0, 1, 400, $e->getMessage());
            } catch (Exception $e) {
                error_log("Error adding $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to add component");
            }
            break;

        case 'update':
            $componentId = $_POST['id'] ?? '';
            $updateData = $_POST;
            unset($updateData['action'], $updateData['id']);

            if (empty($componentId) || empty($updateData)) {
                send_json_response(0, 1, 400, "Component ID and update data are required");
            }

            try {
                $success = updateComponent($pdo, $module, $componentId, $updateData, $user['id']);

                if ($success) {
                    send_json_response(1, 1, 200, ucfirst($module) . " component updated successfully");
                } else {
                    send_json_response(0, 1, 400, "Failed to update " . $module . " component");
                }
            } catch (InvalidArgumentException $e) {
                error_log("Validation error updating $module component: " . $e->getMessage());
                send_json_response(0, 1, 400, $e->getMessage());
            } catch (Exception $e) {
                error_log("Error updating $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to update component");
            }
            break;

        case 'delete':
            $componentId = $_POST['id'] ?? '';

            if (empty($componentId)) {
                send_json_response(0, 1, 400, "Component ID is required");
            }

            try {
                $success = deleteComponent($pdo, $module, $componentId, $user['id']);

                if ($success) {
                    send_json_response(1, 1, 200, ucfirst($module) . " component deleted successfully");
                } else {
                    send_json_response(0, 1, 400, "Failed to delete " . $module . " component");
                }
            } catch (Exception $e) {
                error_log("Error deleting $module component: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to delete component");
            }
            break;

        case 'bulk-add':
            // Same mass-assignment defenses as single 'add': each item goes
            // through addComponent() (column whitelist, blocked columns,
            // UUID-vs-JSON-spec validation). Partial-success semantics: items
            // are processed independently; nothing is rolled back.
            $componentsJson = $_POST['components'] ?? '';
            $items = json_decode($componentsJson, true);

            if (!is_array($items) || empty($items)) {
                send_json_response(0, 1, 400, "A non-empty JSON array of components is required in 'components'");
            }
            if (count($items) > 100) {
                send_json_response(0, 1, 400, "Bulk add is limited to 100 components per request");
            }

            $results = [];
            $succeeded = 0;
            foreach (array_values($items) as $i => $itemData) {
                $entry = ['index' => $i, 'success' => false];
                if (!is_array($itemData) || empty($itemData)) {
                    $entry['error'] = "Item must be a non-empty object";
                    $results[] = $entry;
                    continue;
                }
                unset($itemData['action']);
                try {
                    $result = addComponent($pdo, $module, $itemData, $user['id']);
                    if ($result) {
                        $entry['success'] = true;
                        $entry['component_id'] = $result['id'];
                        $entry['uuid'] = $result['uuid'];
                        $entry['asset_tag'] = $result['asset_tag'];
                        $succeeded++;
                    } else {
                        $entry['error'] = "Failed to add component";
                    }
                } catch (InvalidArgumentException $e) {
                    $entry['error'] = $e->getMessage();
                } catch (Exception $e) {
                    error_log("Error bulk-adding $module component (index $i): " . $e->getMessage());
                    $entry['error'] = "Failed to add component";
                }
                $results[] = $entry;
            }

            $total = count($results);
            $failed = $total - $succeeded;
            $code = $failed === 0 ? 201 : ($succeeded > 0 ? 200 : 400);
            send_json_response($succeeded > 0 ? 1 : 0, 1, $code,
                ucfirst($module) . " bulk add: $succeeded of $total added", [
                    'total' => $total,
                    'succeeded' => $succeeded,
                    'failed' => $failed,
                    'results' => $results
                ]);
            break;

        case 'bulk-delete':
            // Partial-success semantics, mirroring bulk-add. Accepts 'ids' as
            // a JSON array or a comma-separated string.
            $idsParam = $_POST['ids'] ?? '';
            $ids = json_decode($idsParam, true);
            if (!is_array($ids)) {
                $ids = array_filter(array_map('trim', explode(',', $idsParam)), 'strlen');
            }

            if (empty($ids)) {
                send_json_response(0, 1, 400, "A non-empty list of component IDs is required in 'ids'");
            }
            if (count($ids) > 100) {
                send_json_response(0, 1, 400, "Bulk delete is limited to 100 components per request");
            }

            $results = [];
            $succeeded = 0;
            foreach (array_values($ids) as $id) {
                $componentId = (int)$id;
                $entry = ['id' => $componentId, 'success' => false];
                if ($componentId <= 0) {
                    $entry['error'] = "Invalid component ID";
                    $results[] = $entry;
                    continue;
                }
                try {
                    if (deleteComponent($pdo, $module, $componentId, $user['id'])) {
                        $entry['success'] = true;
                        $succeeded++;
                    } else {
                        $entry['error'] = "Failed to delete component";
                    }
                } catch (Exception $e) {
                    error_log("Error bulk-deleting $module component ID $componentId: " . $e->getMessage());
                    $entry['error'] = "Failed to delete component";
                }
                $results[] = $entry;
            }

            $total = count($results);
            $failed = $total - $succeeded;
            $code = $failed === 0 ? 200 : ($succeeded > 0 ? 200 : 400);
            send_json_response($succeeded > 0 ? 1 : 0, 1, $code,
                ucfirst($module) . " bulk delete: $succeeded of $total deleted", [
                    'total' => $total,
                    'succeeded' => $succeeded,
                    'failed' => $failed,
                    'results' => $results
                ]);
            break;

        default:
            send_json_response(0, 1, 400, "Invalid $module operation: $operation");
    }
}
