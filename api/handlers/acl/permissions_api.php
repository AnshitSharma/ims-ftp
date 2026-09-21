<?php
/**
 * Permissions Management API
 * File: api/acl/permissions_api.php
 */

// This file is included from api.php, so we don't need to include headers or dependencies again
// Get the ACL instance from global scope (set in api.php)
$acl = $GLOBALS['acl'] ?? null;

if (!$acl) {
    // Fallback: create new ACL instance if not set
    $acl = new ACL($pdo);
}

// The user is already authenticated in api.php
// $user variable is available from the parent scope

// Parse the operation from the original action
$originalAction = $_POST['action'] ?? $_GET['action'] ?? '';
$parts = explode('-', $originalAction, 2);
$operation = $parts[1] ?? 'list';

switch ($operation) {
    // E.3: the `list` alias of `get_all` is removed — the frontend calls permissions-get_all.
    case 'get_all':
        // Require permission to view roles (permissions are part of role management)
        if (!hasPermission($pdo, 'roles.view', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to view permissions");
        }
        
        try {
            $permissions = $acl->getAllPermissions();
            
            // Get total count
            $totalCount = 0;
            foreach ($permissions as $category => $perms) {
                $totalCount += count($perms);
            }
            
            send_json_response(1, 1, 200, "Permissions retrieved successfully", [
                'permissions' => $permissions,
                'total' => $totalCount,
                'categories' => array_keys($permissions)
            ]);
            
        } catch (Exception $e) {
            error_log("Error getting permissions: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to retrieve permissions");
        }
        break;
        
        // E.3 (audit §4.1): six permissions-* operations removed — get_by_category,
        // check_permission, get_role_permissions, get_categories, bulk_check,
        // get_component_permissions. Zero frontend occurrences, re-verified 2026-09-21.
        // get_all already returns the catalogue grouped by category, which is what the
        // settings screen renders, and roles-get returns a role's grants with a
        // `granted` flag per permission, which is what the role editor renders.
        //
        // KEPT: list / get_all (called), and get_user_permissions — uncalled but
        // DELIBERATE. It is the only publisher of effectiveCapabilities(), the single
        // evaluator F-13 introduced precisely because two evaluators disagreed and the
        // screen showed the narrower answer. Deleting it would re-open that.
        
    case 'get_user_permissions':
        // Users can view their own permissions, admins can view any user's permissions
        $requestedUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? $user['id'];
        
        // If requesting another user's permissions, need admin access
        if ($requestedUserId != $user['id']) {
            if (!hasPermission($pdo, 'users.view', $user['id'])) {
                send_json_response(0, 1, 403, "You don't have permission to view other users' permissions");
            }
        }
        
        try {
            // Get user permissions directly using ACL
            $userPermissions = [];
            $userRoles = $acl->getUserRoles($requestedUserId);
            
            // Get all permissions and check which ones the user has
            $allPermissions = $acl->getAllPermissions();
            foreach ($allPermissions as $category => $categoryPermissions) {
                foreach ($categoryPermissions as $permission) {
                    $hasPermission = $acl->hasPermission($requestedUserId, $permission['name']);
                    if ($hasPermission) {
                        $permission['granted'] = true;
                        $userPermissions[] = $permission;
                    }
                }
            }
            
            // Group permissions by category
            $groupedPermissions = [];
            foreach ($userPermissions as $permission) {
                $groupedPermissions[$permission['category']][] = $permission;
            }
            
            // The EFFECTIVE set, flat, from the one evaluator. [F-13 part 2]
            //
            // The grouped list above is built by asking about each catalogued
            // permission one at a time, which is the shape the settings screen
            // renders. `capabilities` is what a UI should actually gate on: the
            // same answer the backend will give when the button is pressed,
            // including the admin bypass, in one array it can test membership in.
            // Publishing it is what stops the frontend from re-deriving policy
            // and getting a different answer.
            $effective = effectiveCapabilities($pdo, $requestedUserId);

            send_json_response(1, 1, 200, "User permissions retrieved successfully", [
                'user_id' => $requestedUserId,
                'roles' => $userRoles,
                'permissions' => $groupedPermissions,
                'total_permissions' => count($userPermissions),
                'capabilities' => array_values($effective['permissions']),
                'is_admin' => (bool)$effective['is_admin'],
            ]);
            
        } catch (Exception $e) {
            error_log("Error getting user permissions: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to retrieve user permissions");
        }
        break;
        
    // E.3 (audit §4.1): permissions-create / -create_permission removed. Neither
    // is called, and a permission is not a runtime object: the catalogue is
    // seeded, which is what makes a new permission reviewable in a file rather
    // than appearing in production because someone POSTed it.
        
    default:
        send_json_response(0, 1, 400, "Invalid permissions operation: $operation");
        break;
}
?>