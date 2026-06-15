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
                    error_log("Successfully added $module component with ID: " . $result['id']);
                    send_json_response(1, 1, 201, ucfirst($module) . " component added successfully", [
                        'component_id' => $result['id'],
                        'uuid' => $result['uuid']
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

        default:
            send_json_response(0, 1, 400, "Invalid $module operation: $operation");
    }
}
