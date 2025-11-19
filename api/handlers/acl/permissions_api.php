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
    case 'list':
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
        
    case 'get_by_category':
        // Require permission to view roles
        if (!hasPermission($pdo, 'roles.view', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to view permissions");
        }
        
        $category = $_GET['category'] ?? $_POST['category'] ?? '';
        
        if (empty($category)) {
            send_json_response(0, 1, 400, "Category is required");
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM permissions 
                WHERE category = ? 
                ORDER BY display_name
            ");
            $stmt->execute([$category]);
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            send_json_response(1, 1, 200, "Category permissions retrieved successfully", [
                'category' => $category,
                'permissions' => $permissions,
                'count' => count($permissions)
            ]);
            
        } catch (Exception $e) {
            error_log("Error getting category permissions: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to retrieve category permissions");
        }
        break;
        
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
            
            send_json_response(1, 1, 200, "User permissions retrieved successfully", [
                'user_id' => $requestedUserId,
                'roles' => $userRoles,
                'permissions' => $groupedPermissions,
                'total_permissions' => count($userPermissions)
            ]);
            
        } catch (Exception $e) {
            error_log("Error getting user permissions: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to retrieve user permissions");
        }
        break;
        
    case 'check_permission':
        // Allow users to check their own permissions
        $permission = $_GET['permission'] ?? $_POST['permission'] ?? '';
        $checkUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? $user['id'];
        
        if (empty($permission)) {
            send_json_response(0, 1, 400, "Permission name is required");
        }
        
        // If checking another user's permissions, need admin access
        if ($checkUserId != $user['id']) {
            if (!hasPermission($pdo, 'users.view', $user['id'])) {
                send_json_response(0, 1, 403, "You don't have permission to check other users' permissions");
            }
        }
        
        try {
            $hasPermission = hasPermission($pdo, $permission, $checkUserId);
            
            send_json_response(1, 1, 200, "Permission check completed", [
                'user_id' => $checkUserId,
                'permission' => $permission,
                'has_permission' => $hasPermission
            ]);
            
        } catch (Exception $e) {
            error_log("Error checking permission: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to check permission");
        }
        break;
        
    case 'get_role_permissions':
        // Require permission to view roles
        if (!hasPermission($pdo, 'roles.view', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to view role permissions");
        }
        
        $roleId = $_GET['role_id'] ?? $_POST['role_id'] ?? '';
        
        if (empty($roleId)) {
            send_json_response(0, 1, 400, "Role ID is required");
        }
        
        try {
            // Get role details
            $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
            $stmt->execute([$roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$role) {
                send_json_response(0, 1, 404, "Role not found");
            }
            
            $permissions = $acl->getRolePermissions($roleId);
            
            // Group permissions by category
            $groupedPermissions = [];
            foreach ($permissions as $permission) {
                $groupedPermissions[$permission['category']][] = $permission;
            }
            
            send_json_response(1, 1, 200, "Role permissions retrieved successfully", [
                'role' => $role,
                'permissions' => $groupedPermissions,
                'total_permissions' => count($permissions)
            ]);
            
        } catch (Exception $e) {
            error_log("Error getting role permissions: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to retrieve role permissions");
        }
        break;
        
    case 'get_categories':
        // Require permission to view roles
        if (!hasPermission($pdo, 'roles.view', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to view permission categories");
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT category, COUNT(*) as permission_count,
                       COUNT(CASE WHEN is_basic = 1 THEN 1 END) as basic_count
                FROM permissions 
                GROUP BY category 
                ORDER BY category
            ");
            $stmt->execute();
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            send_json_response(1, 1, 200, "Permission categories retrieved successfully", [
                'categories' => $categories,
                'total_categories' => count($categories)
            ]);
            
        } catch (Exception $e) {
            error_log("Error getting permission categories: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to retrieve permission categories");
        }
        break;
        
    case 'create_permission':
    case 'create':
        // Only super admins can create new permissions
        if (!$acl->hasRole($user['id'], ['super_admin'])) {
            send_json_response(0, 1, 403, "Only super administrators can create new permissions");
        }
        
        $name = trim($_POST['name'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $isBasic = isset($_POST['is_basic']) ? (bool)$_POST['is_basic'] : false;
        
        if (empty($name) || empty($displayName)) {
            send_json_response(0, 1, 400, "Permission name and display name are required");
        }
        
        // Validate permission name format
        if (!preg_match('/^[a-z0-9_\.]+$/', $name)) {
            send_json_response(0, 1, 400, "Permission name can only contain lowercase letters, numbers, dots, and underscores");
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO permissions (name, display_name, description, category, is_basic) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$name, $displayName, $description, $category, $isBasic ? 1 : 0])) {
                $permissionId = $pdo->lastInsertId();
                
                logActivity($pdo, $user['id'], 'create', 'permission', $permissionId, "Created permission: $displayName");
                
                send_json_response(1, 1, 201, "Permission created successfully", [
                    'permission_id' => $permissionId,
                    'name' => $name,
                    'display_name' => $displayName
                ]);
            } else {
                send_json_response(0, 1, 500, "Failed to create permission");
            }
            
        } catch (Exception $e) {
            error_log("Error creating permission: " . $e->getMessage());
            
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                send_json_response(0, 1, 409, "Permission name already exists");
            } else {
                send_json_response(0, 1, 500, "Failed to create permission");
            }
        }
        break;
        
    case 'bulk_check':
        // Allow users to check multiple permissions at once
        $permissions = $_POST['permissions'] ?? [];
        $checkUserId = $_POST['user_id'] ?? $user['id'];
        
        if (empty($permissions) || !is_array($permissions)) {
            send_json_response(0, 1, 400, "Permissions array is required");
        }
        
        // If checking another user's permissions, need admin access
        if ($checkUserId != $user['id']) {
            if (!hasPermission($pdo, 'users.view', $user['id'])) {
                send_json_response(0, 1, 403, "You don't have permission to check other users' permissions");
            }
        }
        
        try {
            $results = [];
            foreach ($permissions as $permission) {
                $results[$permission] = hasPermission($pdo, $permission, $checkUserId);
            }
            
            send_json_response(1, 1, 200, "Bulk permission check completed", [
                'user_id' => $checkUserId,
                'permissions' => $results,
                'total_checked' => count($permissions)
            ]);
            
        } catch (Exception $e) {
            error_log("Error in bulk permission check: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to check permissions");
        }
        break;
        
    case 'get_component_permissions':
        // Get permissions for a specific component type
        $componentType = $_GET['component_type'] ?? $_POST['component_type'] ?? '';
        
        if (empty($componentType)) {
            send_json_response(0, 1, 400, "Component type is required");
        }
        
        try {
            $componentPermissions = [
                'view' => hasPermission($pdo, "$componentType.view", $user['id']),
                'create' => hasPermission($pdo, "$componentType.create", $user['id']),
                'edit' => hasPermission($pdo, "$componentType.edit", $user['id']),
                'delete' => hasPermission($pdo, "$componentType.delete", $user['id'])
            ];
            
            send_json_response(1, 1, 200, "Component permissions retrieved successfully", [
                'component_type' => $componentType,
                'permissions' => $componentPermissions
            ]);
            
        } catch (Exception $e) {
            error_log("Error getting component permissions: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to get component permissions");
        }
        break;
        
    default:
        send_json_response(0, 1, 400, "Invalid permissions operation: $operation");
        break;
}
?>