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

    // Vendor management is restricted to admin and superadmin only.
    // A role check is used instead of a permission check because the ACL wildcard
    // pattern '*.view' would otherwise grant vendor.view to manager/viewer roles too.
    $roleStmt = $pdo->prepare("
        SELECT COUNT(*) FROM user_roles ur
        JOIN roles r ON ur.role_id = r.id
        WHERE ur.user_id = ? AND r.name IN ('admin', 'super_admin')
    ");
    $roleStmt->execute([$user['id']]);
    if ($roleStmt->fetchColumn() == 0) {
        send_json_response(0, 1, 403, "Vendor management requires administrator access");
    }

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

                // Nullify VendorID references in all inventory tables
                foreach (VALID_COMPONENT_TYPES as $type) {
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
            try {
                $allComponents = [];
                foreach (VALID_COMPONENT_TYPES as $type) {
                    $table = getComponentTableName($type);
                    $stmt = $pdo->prepare("SELECT *, ? AS component_type FROM $table WHERE VendorID = ?");
                    $stmt->execute([$type, (int)$vendorId]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $allComponents = array_merge($allComponents, $results);
                }
                send_json_response(1, 1, 200, "Vendor components retrieved", [
                    'components' => $allComponents,
                    'total_count' => count($allComponents)
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
