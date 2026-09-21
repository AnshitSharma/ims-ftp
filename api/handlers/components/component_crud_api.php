<?php
/**
 * Component CRUD handler — list/get/add/update/delete for all 10 inventory
 * component types (cpu, ram, storage, motherboard, nic, caddy, chassis,
 * pciecard, risercard, hbacard, sfp).
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
            // Pagination is CAPPED BY DEFAULT (audit JSON-004, 2026-09-16).
            //
            // This used to be opt-in: no limit param meant no LIMIT clause, and the
            // comment here named "server builder, scripts" as the callers relying on
            // that. Neither does any more -- getComponentsByType() has exactly one
            // caller in the backend (this line), and the one frontend caller
            // (dashboard.js loadComponentList) has always sent limit/offset. So the
            // return-everything path was serving nobody but was still one forgotten
            // param away from a fatal: ram-list measured 625 KB for 463 rows, which is
            // ~135 MB at 100K DIMMs -- over memory_limit during json_encode, before the
            // response reaches the wire.
            //
            // A caller that genuinely wants the whole table must now say so with all=1,
            // which is greppable; forgetting a param now costs you 100 rows, not the
            // table. The 500 ceiling on an explicit limit is unchanged.
            $limitParam = $_GET['limit'] ?? $_POST['limit'] ?? null;
            $wantAll = ($_GET['all'] ?? $_POST['all'] ?? '') === '1';
            if ($limitParam !== null && $limitParam !== '') {
                $limit = max(1, min((int)$limitParam, 500));
            } elseif ($wantAll) {
                $limit = null; // explicit, deliberate, unbounded
            } else {
                $limit = 100;
            }
            $offset = max(0, (int)($_GET['offset'] ?? $_POST['offset'] ?? 0));
            $search = trim($_GET['search'] ?? $_POST['search'] ?? '');
            // Optional site filter — "show me everything at Jaipur". Ignored
            // (not rejected) while seeder 2026_08_26_003 has not been applied.
            $locationUuid = trim($_GET['location_uuid'] ?? $_POST['location_uuid'] ?? '');
            // Optional inventory-status filter (0 failed, 1 available, 2 in_use).
            // Server-side like search and location_uuid: the dashboard applied it
            // in the browser over the loaded page only, so it both missed matching
            // rows on every other page and reported a filtered count against an
            // unfiltered total. An out-of-range value is ignored, not rejected.
            $status = trim($_GET['status'] ?? $_POST['status'] ?? '');
            $status = in_array($status, ['0', '1', '2'], true) ? $status : null;

            $components = getComponentsByType($pdo, $module, $limit, $offset, $search, $locationUuid !== '' ? $locationUuid : null, $status);

            // Resolve ModelName from JSON specs via UUID
            $componentService = null;
            try {
                require_once __DIR__ . '/../../../core/models/components/ComponentDataService.php';
                $componentService = ComponentDataService::getInstance();
            } catch (Exception $e) {
                error_log("[ModelName] ComponentDataService load failed: " . $e->getMessage());
            }

            // Name derivation now lives in ComponentNamer (audit JSON-007). This copy of
            // the chain did not know about onboard NICs, which is why 69 of 78 nic-list
            // rows came back with "ModelName": null.
            //
            // is_readable, not a bare require: a new file reaches production AFTER the file
            // that references it, so a hard require here would fatal the whole API for the
            // gap between the two uploads. Absent namer == exactly yesterday's output.
            $namer = __DIR__ . '/../../../core/helpers/ComponentNamer.php';
            $haveNamer = is_readable($namer);
            if ($haveNamer) {
                require_once $namer;
            }

            $onboardUtils = null;
            foreach ($components as &$comp) {
                $comp['ModelName'] = null;
                if ($haveNamer && !empty($comp['UUID'])) {
                    try {
                        if ($module === 'nic' && ComponentNamer::isOnboardNicUuid($comp['UUID'])) {
                            // Onboard NICs are synthesized rows whose UUID resolves to
                            // nothing in the catalogue; the name comes from the parent
                            // board's spec. Utils built once for the whole page, not per row.
                            if ($onboardUtils === null) {
                                $utilsPath = __DIR__ . '/../../../core/models/shared/DataExtractionUtilities.php';
                                if (is_readable($utilsPath)) {
                                    require_once $utilsPath;
                                    $onboardUtils = new DataExtractionUtilities();
                                } else {
                                    $onboardUtils = false;
                                }
                            }
                            $comp['ModelName'] = ComponentNamer::onboardNicName(
                                $pdo, $onboardUtils ?: null, $comp['UUID']
                            );
                        } elseif ($componentService !== null) {
                            $spec = $componentService->findComponentByUuid($module, $comp['UUID']);
                            $comp['ModelName'] = ComponentNamer::fromSpec($module, $spec);
                        }
                    } catch (Throwable $e) {
                        // Silent fail per component -- a name is decoration, never a 500.
                    }
                }
                // Notes fallback (JSON-018): kept ONLY for rows the namer cannot resolve.
                // Parsing free text as structured data is the thing this fallback exists to
                // apologise for; with onboard NICs handled above it should now fire for
                // nothing, and it can be deleted once a run confirms that.
                if ($comp['ModelName'] === null && !empty($comp['Notes'])) {
                    if (preg_match('/Brand:\s*([^,]+).*Model:\s*(.+?)(\r|\n|$)/i', $comp['Notes'], $matches)) {
                        $comp['ModelName'] = trim($matches[1]) . ' ' . trim($matches[2]);
                    }
                }
            }
            unset($comp);

            // Resolve VendorName from VendorID.
            //
            // H.6 (audit §7.6): this was one `SELECT name FROM vendors WHERE id = ?`
            // per DISTINCT vendor, cached per request. Cheap, but it is a query in a
            // loop, and the loop is on the list path. One IN() over the distinct ids
            // answers the whole page instead — the same collapse PERF-N1 and A-P2
            // already applied to handleListConfigurations and getCompatibleComponents.
            $vendorIds = [];
            foreach ($components as $comp) {
                if (!empty($comp['VendorID'])) {
                    $vendorIds[(int)$comp['VendorID']] = true;
                }
            }

            $vendorNames = [];
            if (!empty($vendorIds)) {
                $ids = array_keys($vendorIds);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $vstmt = $pdo->prepare("SELECT id, name FROM vendors WHERE id IN ($ph)");
                $vstmt->execute($ids);
                foreach ($vstmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
                    $vendorNames[(int)$v['id']] = $v['name'];
                }
            }

            foreach ($components as &$comp) {
                $comp['VendorName'] = !empty($comp['VendorID'])
                    ? ($vendorNames[(int)$comp['VendorID']] ?? null)
                    : null;
            }
            unset($comp);

            // Where each of these physically is: location, floor, rack and U for
            // an installed part, location and shelf for free stock. The rack is
            // the piece the inventory row cannot hold on its own -- it belongs to
            // the server the part is installed in -- so one grouped query adds it
            // for the whole page.
            try {
                require_once __DIR__ . '/../../../core/models/location/LocationResolver.php';
                LocationResolver::enrichComponentRows($pdo, $components);
            } catch (Throwable $locError) {
                // Decoration only. An inventory page must still render.
                error_log("[location] component list enrichment failed: " . $locError->getMessage());
            }

            // total_count = all matching rows (not page size) so the dashboard's
            // pagination UI (built from total_count) reflects the real total
            $totalCount = ($limit !== null)
                ? getComponentCountByType($pdo, $module, $search, $locationUuid !== '' ? $locationUuid : null, $status)
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
                // Same address the list view shows, so opening one unit answers
                // "where is this?" without going back to the list to read it.
                try {
                    require_once __DIR__ . '/../../../core/models/location/LocationResolver.php';
                    $one = [$component];
                    LocationResolver::enrichComponentRows($pdo, $one);
                    $component = $one[0];
                } catch (Throwable $locError) {
                    error_log("[location] component get enrichment failed: " . $locError->getMessage());
                }

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
            } catch (ComponentInUseException $e) {
                // A configuration still claims this unit. Not an error condition
                // to hide behind a 500 — the operator needs the config UUID to
                // act on it, so the guard's own message is the response.
                send_json_response(0, 1, 409, $e->getMessage());
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
                } catch (ComponentInUseException $e) {
                    // Per-item refusal, not a failure of the batch — partial-success
                    // semantics mean the other 99 still delete.
                    $entry['error'] = $e->getMessage();
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
