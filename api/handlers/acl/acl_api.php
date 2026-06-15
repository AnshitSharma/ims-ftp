<?php
/**
 * ACL handler — user permission/role assignment operations.
 *
 * Included by api/api.php for the `acl` module. All operations require the
 * acl.manage permission (checked here, since every operation shares it).
 */

function handleACLOperations($operation, $user) {
    global $pdo;

    error_log("ACL operation: $operation");

    // Check if user has ACL management permissions
    if (!hasPermission($pdo, 'acl.manage', $user['id'])) {
        send_json_response(0, 1, 403, "Insufficient permissions for ACL operations");
    }

    switch ($operation) {
        case 'get_user_permissions':
            $targetUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? '';
            if (empty($targetUserId)) {
                send_json_response(0, 1, 400, "User ID is required");
            }

            $permissions = getUserPermissions($pdo, $targetUserId);
            $roles = getUserRoles($pdo, $targetUserId);

            send_json_response(1, 1, 200, "User permissions retrieved", [
                'user_id' => (int)$targetUserId,
                'permissions' => $permissions,
                'roles' => $roles
            ]);
            break;

        case 'assign_permission':
            $targetUserId = $_POST['user_id'] ?? '';
            $permission = $_POST['permission'] ?? '';

            if (empty($targetUserId) || empty($permission)) {
                send_json_response(0, 1, 400, "User ID and permission are required");
            }

            $success = assignPermissionToUser($pdo, $targetUserId, $permission);

            if ($success) {
                send_json_response(1, 1, 200, "Permission assigned successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to assign permission");
            }
            break;

        case 'revoke_permission':
            $targetUserId = $_POST['user_id'] ?? '';
            $permission = $_POST['permission'] ?? '';

            if (empty($targetUserId) || empty($permission)) {
                send_json_response(0, 1, 400, "User ID and permission are required");
            }

            $success = revokePermissionFromUser($pdo, $targetUserId, $permission);

            if ($success) {
                send_json_response(1, 1, 200, "Permission revoked successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to revoke permission");
            }
            break;

        case 'assign_role':
            $targetUserId = $_POST['user_id'] ?? '';
            $roleId = $_POST['role_id'] ?? '';

            if (empty($targetUserId) || empty($roleId)) {
                send_json_response(0, 1, 400, "User ID and role ID are required");
            }

            $success = assignRoleToUser($pdo, $targetUserId, $roleId);

            if ($success) {
                send_json_response(1, 1, 200, "Role assigned successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to assign role");
            }
            break;

        case 'revoke_role':
            $targetUserId = $_POST['user_id'] ?? '';
            $roleId = $_POST['role_id'] ?? '';

            if (empty($targetUserId) || empty($roleId)) {
                send_json_response(0, 1, 400, "User ID and role ID are required");
            }

            $success = revokeRoleFromUser($pdo, $targetUserId, $roleId);

            if ($success) {
                send_json_response(1, 1, 200, "Role revoked successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to revoke role");
            }
            break;

        case 'get_all_roles':
            $roles = getAllRoles($pdo);
            send_json_response(1, 1, 200, "Roles retrieved successfully", ['roles' => $roles]);
            break;

        case 'get_all_permissions':
            $permissions = getAllPermissions($pdo);
            send_json_response(1, 1, 200, "Permissions retrieved successfully", ['permissions' => $permissions]);
            break;

        case 'check_permission':
            $targetUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? $user['id'];
            $permission = $_GET['permission'] ?? $_POST['permission'] ?? '';

            if (empty($permission)) {
                send_json_response(0, 1, 400, "Permission is required");
            }

            $hasPermission = hasPermission($pdo, $permission, $targetUserId);

            send_json_response(1, 1, 200, "Permission check completed", [
                'user_id' => (int)$targetUserId,
                'permission' => $permission,
                'has_permission' => $hasPermission
            ]);
            break;

        default:
            send_json_response(0, 1, 400, "Invalid ACL operation: $operation");
    }
}
