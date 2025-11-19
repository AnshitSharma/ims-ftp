<?php
/**
 * Roles Management API
 * File: api/acl/roles_api.php
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
        // Require permission to view roles
        if (!hasPermission($pdo, 'roles.view', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to view roles");
        }
        
        try {
            $roles = $acl->getAllRoles();
            
            send_json_response(1, 1, 200, "Roles retrieved successfully", [
                'roles' => $roles,
                'total' => count($roles)
            ]);
        } catch (Exception $e) {
            error_log("Error getting roles: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to retrieve roles");
        }
        break;
        
    case 'get':
        // Require permission to view roles
        if (!hasPermission($pdo, 'roles.view', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to view roles");
        }
        
        $roleId = $_GET['id'] ?? $_POST['id'] ?? '';
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
            
            // Get role permissions
            $permissions = $acl->getRolePermissions($roleId);
            
            // Get users with this role
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.email, u.firstname, u.lastname, ur.assigned_at
                FROM users u
                JOIN user_roles ur ON u.id = ur.user_id
                WHERE ur.role_id = ?
                ORDER BY u.username
            ");
            $stmt->execute([$roleId]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            send_json_response(1, 1, 200, "Role details retrieved successfully", [
                'role' => $role,
                'permissions' => $permissions,
                'users' => $users
            ]);
            
        } catch (Exception $e) {
            error_log("Error getting role details: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to retrieve role details");
        }
        break;
        
    case 'create':
        // Require permission to create roles
        if (!hasPermission($pdo, 'roles.create', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to create roles");
        }
        
        $name = trim($_POST['name'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $basicPermissions = isset($_POST['basic_permissions']) ? (bool)$_POST['basic_permissions'] : true;
        
        if (empty($name) || empty($displayName)) {
            send_json_response(0, 1, 400, "Role name and display name are required");
        }
        
        // Validate role name format
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            send_json_response(0, 1, 400, "Role name can only contain lowercase letters, numbers, and underscores");
        }
        
        try {
            $roleId = $acl->createRole($name, $displayName, $description, $basicPermissions);
            
            if ($roleId) {
                logActivity($pdo, $user['id'], 'create', 'role', $roleId, "Created role: $displayName");
                
                send_json_response(1, 1, 201, "Role created successfully", [
                    'role_id' => $roleId,
                    'name' => $name,
                    'display_name' => $displayName
                ]);
            } else {
                send_json_response(0, 1, 500, "Failed to create role");
            }
            
        } catch (Exception $e) {
            error_log("Error creating role: " . $e->getMessage());
            
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                send_json_response(0, 1, 409, "Role name already exists");
            } else {
                send_json_response(0, 1, 500, "Failed to create role");
            }
        }
        break;
        
    case 'update':
        // Require permission to edit roles
        if (!hasPermission($pdo, 'roles.edit', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to edit roles");
        }
        
        $roleId = $_POST['id'] ?? '';
        $displayName = trim($_POST['display_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($roleId) || empty($displayName)) {
            send_json_response(0, 1, 400, "Role ID and display name are required");
        }
        
        try {
            // Check if role exists and is not system role for certain operations
            $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
            $stmt->execute([$roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$role) {
                send_json_response(0, 1, 404, "Role not found");
            }
            
            // Update role details
            $stmt = $pdo->prepare("
                UPDATE roles 
                SET display_name = ?, description = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            
            if ($stmt->execute([$displayName, $description, $roleId])) {
                logActivity($pdo, $user['id'], 'update', 'role', $roleId, "Updated role: $displayName");
                
                send_json_response(1, 1, 200, "Role updated successfully");
            } else {
                send_json_response(0, 1, 500, "Failed to update role");
            }
            
        } catch (Exception $e) {
            error_log("Error updating role: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to update role");
        }
        break;
        
    case 'update_permissions':
        // Require permission to edit roles
        if (!hasPermission($pdo, 'roles.edit', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to edit role permissions");
        }
        
        $roleId = $_POST['role_id'] ?? '';
        $permissions = $_POST['permissions'] ?? [];
        
        if (empty($roleId)) {
            send_json_response(0, 1, 400, "Role ID is required");
        }
        
        if (!is_array($permissions)) {
            send_json_response(0, 1, 400, "Permissions must be an array");
        }
        
        try {
            // Check if role exists
            $stmt = $pdo->prepare("SELECT name, is_system FROM roles WHERE id = ?");
            $stmt->execute([$roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$role) {
                send_json_response(0, 1, 404, "Role not found");
            }
            
            // Prevent modification of super_admin role by non-super-admins
            if ($role['name'] === 'super_admin' && !$acl->hasRole($user['id'], ['super_admin'])) {
                send_json_response(0, 1, 403, "Only super administrators can modify super admin role");
            }
            
            if ($acl->updateRolePermissions($roleId, $permissions)) {
                logActivity($pdo, $user['id'], 'update_permissions', 'role', $roleId, "Updated permissions for role: " . $role['name']);
                
                send_json_response(1, 1, 200, "Role permissions updated successfully");
            } else {
                send_json_response(0, 1, 500, "Failed to update role permissions");
            }
            
        } catch (Exception $e) {
            error_log("Error updating role permissions: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to update role permissions");
        }
        break;
        
    case 'delete':
        // Require permission to delete roles
        if (!hasPermission($pdo, 'roles.delete', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to delete roles");
        }
        
        $roleId = $_POST['id'] ?? $_GET['id'] ?? '';
        
        if (empty($roleId)) {
            send_json_response(0, 1, 400, "Role ID is required");
        }
        
        try {
            // Get role details before deletion
            $stmt = $pdo->prepare("SELECT name, is_system FROM roles WHERE id = ?");
            $stmt->execute([$roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$role) {
                send_json_response(0, 1, 404, "Role not found");
            }
            
            if ($role['is_system']) {
                send_json_response(0, 1, 403, "Cannot delete system role");
            }
            
            // Check if role has users assigned
            $stmt = $pdo->prepare("SELECT COUNT(*) as user_count FROM user_roles WHERE role_id = ?");
            $stmt->execute([$roleId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['user_count'] > 0) {
                send_json_response(0, 1, 409, "Cannot delete role that has users assigned to it");
            }
            
            if ($acl->deleteRole($roleId)) {
                logActivity($pdo, $user['id'], 'delete', 'role', $roleId, "Deleted role: " . $role['name']);
                
                send_json_response(1, 1, 200, "Role deleted successfully");
            } else {
                send_json_response(0, 1, 500, "Failed to delete role");
            }
            
        } catch (Exception $e) {
            error_log("Error deleting role: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to delete role");
        }
        break;
        
    case 'assign_user':
    case 'assign':
        // Require permission to manage user roles
        if (!hasPermission($pdo, 'users.manage_roles', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to assign roles to users");
        }
        
        $userId = $_POST['user_id'] ?? '';
        $roleId = $_POST['role_id'] ?? '';
        
        if (empty($userId) || empty($roleId)) {
            send_json_response(0, 1, 400, "User ID and Role ID are required");
        }
        
        try {
            if ($acl->assignRole($userId, $roleId, $user['id'])) {
                logActivity($pdo, $user['id'], 'assign_role', 'user', $userId, "Assigned role ID: $roleId");
                
                send_json_response(1, 1, 200, "Role assigned successfully");
            } else {
                send_json_response(0, 1, 500, "Failed to assign role");
            }
            
        } catch (Exception $e) {
            error_log("Error assigning role: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to assign role");
        }
        break;
        
    case 'remove_user':
    case 'remove':
        // Require permission to manage user roles
        if (!hasPermission($pdo, 'users.manage_roles', $user['id'])) {
            send_json_response(0, 1, 403, "You don't have permission to remove roles from users");
        }
        
        $userId = $_POST['user_id'] ?? '';
        $roleId = $_POST['role_id'] ?? '';
        
        if (empty($userId) || empty($roleId)) {
            send_json_response(0, 1, 400, "User ID and Role ID are required");
        }
        
        try {
            // Prevent removing the last role from a user
            $stmt = $pdo->prepare("SELECT COUNT(*) as role_count FROM user_roles WHERE user_id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['role_count'] <= 1) {
                send_json_response(0, 1, 409, "Cannot remove the last role from a user");
            }
            
            if ($acl->removeRole($userId, $roleId)) {
                logActivity($pdo, $user['id'], 'remove_role', 'user', $userId, "Removed role ID: $roleId");
                
                send_json_response(1, 1, 200, "Role removed successfully");
            } else {
                send_json_response(0, 1, 500, "Failed to remove role");
            }
            
        } catch (Exception $e) {
            error_log("Error removing role: " . $e->getMessage());
            send_json_response(0, 1, 500, "Failed to remove role");
        }
        break;
        
    default:
        send_json_response(0, 1, 400, "Invalid roles operation: $operation");
        break;
}
?>