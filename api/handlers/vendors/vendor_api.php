<?php
/**
 * Vendor handler — vendor CRUD and vendor-component listing.
 *
 * Included by api/api.php for the `vendor` module.
 */

/**
 * Normalize the `sells` input into a clean CSV of valid component types.
 * Accepts a CSV string or an array; drops anything not in VALID_COMPONENT_TYPES
 * and de-duplicates while preserving order. Returns null when empty.
 */
function normalizeVendorSells($raw) {
    if (is_array($raw)) {
        $items = $raw;
    } else {
        $items = explode(',', (string)$raw);
    }
    $valid = [];
    foreach ($items as $item) {
        $item = strtolower(trim($item));
        if ($item !== '' && in_array($item, VALID_COMPONENT_TYPES, true) && !in_array($item, $valid, true)) {
            $valid[] = $item;
        }
    }
    return empty($valid) ? null : implode(',', $valid);
}

function handleVendorOperations($operation, $user) {
    global $pdo;

    // E.5 (audit §12.5): the admin/super_admin role gate that used to stand here
    // is gone. api.php now resolves this module through permission_map.php like
    // every other CRUD module, so the four vendor.* permissions finally decide
    // access instead of merely appearing in the role editor. See the map for the
    // wildcard claim this gate rested on, and why it was false.

    switch ($operation) {
        case 'list':
            try {
                $stmt = $pdo->prepare("SELECT * FROM vendors ORDER BY name ASC");
                $stmt->execute();
                $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
                send_json_response(1, 1, 200, "Vendors retrieved", [
                    'vendors' => $vendors,
                    'total_count' => count($vendors)
                ]);
            } catch (Exception $e) {
                error_log("Error listing vendors: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve vendors");
            }
            break;

        case 'get':
            $vendorId = $_GET['id'] ?? $_POST['id'] ?? '';
            if (empty($vendorId)) {
                send_json_response(0, 1, 400, "Vendor ID is required");
            }
            try {
                $stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
                $stmt->execute([(int)$vendorId]);
                $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($vendor) {
                    send_json_response(1, 1, 200, "Vendor retrieved", ['vendor' => $vendor]);
                } else {
                    send_json_response(0, 1, 404, "Vendor not found");
                }
            } catch (Exception $e) {
                error_log("Error getting vendor: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve vendor");
            }
            break;

        case 'add':
            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                send_json_response(0, 1, 400, "Vendor name is required");
            }
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO vendors (name, email, phone, phone2, address, bank_details, sells, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $name,
                    trim($_POST['email'] ?? '') ?: null,
                    trim($_POST['phone'] ?? '') ?: null,
                    trim($_POST['phone2'] ?? '') ?: null,
                    trim($_POST['address'] ?? '') ?: null,
                    trim($_POST['bank_details'] ?? '') ?: null,
                    normalizeVendorSells($_POST['sells'] ?? ''),
                    trim($_POST['notes'] ?? '') ?: null
                ]);
                $vendorId = (int)$pdo->lastInsertId();

                logActivity($pdo, $user['id'], 'Vendor created', 'vendor', $vendorId,
                    "Created vendor: $name");

                send_json_response(1, 1, 201, "Vendor added successfully", [
                    'vendor_id' => $vendorId
                ]);
            } catch (Exception $e) {
                error_log("Error adding vendor: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to add vendor");
            }
            break;

        case 'update':
            $vendorId = $_POST['id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            if (empty($vendorId) || empty($name)) {
                send_json_response(0, 1, 400, "Vendor ID and name are required");
            }
            try {
                $stmt = $pdo->prepare(
                    "UPDATE vendors SET name = ?, email = ?, phone = ?, phone2 = ?, address = ?, bank_details = ?, sells = ?, notes = ? WHERE id = ?"
                );
                $stmt->execute([
                    $name,
                    trim($_POST['email'] ?? '') ?: null,
                    trim($_POST['phone'] ?? '') ?: null,
                    trim($_POST['phone2'] ?? '') ?: null,
                    trim($_POST['address'] ?? '') ?: null,
                    trim($_POST['bank_details'] ?? '') ?: null,
                    normalizeVendorSells($_POST['sells'] ?? ''),
                    trim($_POST['notes'] ?? '') ?: null,
                    (int)$vendorId
                ]);

                if ($stmt->rowCount() > 0) {
                    logActivity($pdo, $user['id'], 'Vendor updated', 'vendor', (int)$vendorId,
                        "Updated vendor: $name");
                    send_json_response(1, 1, 200, "Vendor updated successfully");
                } else {
                    send_json_response(0, 1, 404, "Vendor not found or no changes made");
                }
            } catch (Exception $e) {
                error_log("Error updating vendor: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to update vendor");
            }
            break;

        case 'delete':
            $vendorId = $_POST['id'] ?? '';
            if (empty($vendorId)) {
                send_json_response(0, 1, 400, "Vendor ID is required");
            }
            try {
                $pdo->beginTransaction();

                // Nullify VendorID references in all inventory tables.
                // A type whose table is not migrated yet has no rows to nullify --
                // see inventoryTableExists().
                foreach (VALID_COMPONENT_TYPES as $type) {
                    if (!inventoryTableExists($pdo, $type)) {
                        continue;
                    }
                    $table = getComponentTableName($type);
                    $pdo->prepare("UPDATE $table SET VendorID = NULL WHERE VendorID = ?")->execute([(int)$vendorId]);
                }

                $stmt = $pdo->prepare("DELETE FROM vendors WHERE id = ?");
                $stmt->execute([(int)$vendorId]);

                if ($stmt->rowCount() > 0) {
                    $pdo->commit();
                    logActivity($pdo, $user['id'], 'Vendor deleted', 'vendor', (int)$vendorId,
                        "Deleted vendor ID: $vendorId");
                    send_json_response(1, 1, 200, "Vendor deleted successfully");
                } else {
                    $pdo->rollBack();
                    send_json_response(0, 1, 404, "Vendor not found");
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error deleting vendor: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to delete vendor");
            }
            break;

        case 'components':
            $vendorId = $_GET['id'] ?? $_POST['id'] ?? '';
            if (empty($vendorId)) {
                send_json_response(0, 1, 400, "Vendor ID is required");
            }
            // H.2 (audit §7.4): this ran twelve `SELECT *` with NO LIMIT at all.
            // Today that is bounded only by the 1,030 rows in stock. The component
            // list endpoint documents the same failure mode for itself — "ram-list
            // measured 625 KB for 463 rows, which is ~135 MB at 100K DIMMs, over
            // memory_limit during json_encode" — and caps itself at 100. This had
            // no such cap, and VendorID is very likely unindexed.
            //
            // Capped, and told the truth about it: total_count is the real number,
            // `truncated` says whether the array is all of it. Named columns rather
            // than SELECT *, matching the projection performGlobalSearch used.
            $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 200);
            $limit = max(1, min(500, $limit));

            try {
                $allComponents = [];
                $totalCount = 0;
                $cols = 'ID, UUID, SerialNumber, AssetTag, Status, ServerUUID, Location,
                         RackPosition, Notes, VendorID, CreatedAt, UpdatedAt';
                foreach (VALID_COMPONENT_TYPES as $type) {
                    if (!inventoryTableExists($pdo, $type)) {
                        continue; // not migrated yet -- see inventoryTableExists()
                    }
                    $table = getComponentTableName($type);

                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE VendorID = ?");
                    $countStmt->execute([(int)$vendorId]);
                    $totalCount += (int)$countStmt->fetchColumn();

                    $remaining = $limit - count($allComponents);
                    if ($remaining <= 0) {
                        continue;
                    }

                    $stmt = $pdo->prepare(
                        "SELECT $cols, ? AS component_type FROM $table
                          WHERE VendorID = ? ORDER BY ID LIMIT " . (int)$remaining
                    );
                    $stmt->execute([$type, (int)$vendorId]);
                    $allComponents = array_merge($allComponents, $stmt->fetchAll(PDO::FETCH_ASSOC));
                }
                send_json_response(1, 1, 200, "Vendor components retrieved", [
                    'components'  => $allComponents,
                    'total_count' => $totalCount,
                    'returned'    => count($allComponents),
                    'limit'       => $limit,
                    'truncated'   => count($allComponents) < $totalCount,
                ]);
            } catch (Exception $e) {
                error_log("Error getting vendor components: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to retrieve vendor components");
            }
            break;

        default:
            send_json_response(0, 1, 400, "Invalid vendor operation: $operation");
    }
}
