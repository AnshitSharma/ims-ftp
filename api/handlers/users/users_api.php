<?php
/**
 * Users handler — user CRUD operations.
 *
 * Included by api/api.php for the `users` module. Permissions are checked
 * per-operation here because they differ (user.view/create/edit/delete).
 */

function handleUserOperations($operation, $user) {
    global $pdo;

    switch ($operation) {
        case 'list':
            if (!hasPermission($pdo, 'users.view', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user listing");
            }

            $users = getAllUsers($pdo);
            send_json_response(1, 1, 200, "Users retrieved successfully", ['users' => $users]);
            break;

        case 'create':
            if (!hasPermission($pdo, 'users.create', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user creation");
            }

            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname = trim($_POST['lastname'] ?? '');
            // Optional: role/group to assign the new user to. Falls back to the
            // default role below when not supplied or invalid.
            $roleId = isset($_POST['role_id']) && $_POST['role_id'] !== '' ? (int)$_POST['role_id'] : null;

            if (empty($username) || empty($email) || empty($password)) {
                send_json_response(0, 1, 400, "Username, email, and password are required");
            }

            // Username + email + password validation (mirrors password policy
            // enforced elsewhere: forgot/reset password).
            if (strlen($username) < 3 || strlen($username) > 50) {
                send_json_response(0, 1, 400, "Username must be between 3 and 50 characters");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                send_json_response(0, 1, 400, "Invalid email format");
            }
            if (strlen($password) < 8) {
                send_json_response(0, 1, 400, "Password must be at least 8 characters");
            }
            if (!preg_match('/[A-Z]/', $password)) {
                send_json_response(0, 1, 400, "Password must contain at least one uppercase letter");
            }
            if (!preg_match('/[0-9]/', $password)) {
                send_json_response(0, 1, 400, "Password must contain at least one number");
            }
            if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                send_json_response(0, 1, 400, "Password must contain at least one special character");
            }

            // Resolve the role to assign: the submitted role_id if it is a real
            // role, otherwise the configured default role so the new user is
            // never left without a role.
            $resolvedRoleId = null;
            if ($roleId !== null) {
                $roleCheck = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
                $roleCheck->execute([$roleId]);
                if ($roleCheck->fetch()) {
                    $resolvedRoleId = $roleId;
                }
            }
            if ($resolvedRoleId === null) {
                $defaultStmt = $pdo->prepare("SELECT id FROM roles WHERE is_default = 1 LIMIT 1");
                $defaultStmt->execute();
                $defaultRole = $defaultStmt->fetch(PDO::FETCH_ASSOC);
                $resolvedRoleId = $defaultRole ? (int)$defaultRole['id'] : null;
            }

            $userId = createUser($pdo, $username, $email, $password, $firstname, $lastname);

            if ($userId) {
                if ($resolvedRoleId !== null) {
                    assignRoleToUser($pdo, $userId, $resolvedRoleId);
                }
                send_json_response(1, 1, 201, "User created successfully", [
                    'user_id' => (int)$userId,
                    'role_id' => $resolvedRoleId
                ]);
            } else {
                send_json_response(0, 1, 400, "Failed to create user. Username or email may already exist.");
            }
            break;

        case 'update':
            if (!hasPermission($pdo, 'users.edit', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user updates");
            }

            $targetUserId = $_POST['user_id'] ?? '';
            $updateData = [];

            if (isset($_POST['username'])) $updateData['username'] = trim($_POST['username']);
            if (isset($_POST['email'])) $updateData['email'] = trim($_POST['email']);
            if (isset($_POST['firstname'])) $updateData['firstname'] = trim($_POST['firstname']);
            if (isset($_POST['lastname'])) $updateData['lastname'] = trim($_POST['lastname']);
            if (isset($_POST['status'])) $updateData['status'] = $_POST['status'];

            if (empty($targetUserId) || empty($updateData)) {
                send_json_response(0, 1, 400, "User ID and at least one field to update are required");
            }

            $success = updateUser($pdo, $targetUserId, $updateData);

            if ($success) {
                send_json_response(1, 1, 200, "User updated successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to update user");
            }
            break;

        case 'delete':
            if (!hasPermission($pdo, 'users.delete', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user deletion");
            }

            $targetUserId = $_POST['user_id'] ?? '';

            if (empty($targetUserId)) {
                send_json_response(0, 1, 400, "User ID is required");
            }

            if ($targetUserId == $user['id']) {
                send_json_response(0, 1, 400, "Cannot delete your own account");
            }

            $success = deleteUser($pdo, $targetUserId);

            if ($success) {
                send_json_response(1, 1, 200, "User deleted successfully");
            } else {
                send_json_response(0, 1, 400, "Failed to delete user");
            }
            break;

        case 'get':
            if (!hasPermission($pdo, 'users.view', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user viewing");
            }

            $targetUserId = $_GET['user_id'] ?? $_POST['user_id'] ?? '';

            if (empty($targetUserId)) {
                send_json_response(0, 1, 400, "User ID is required");
            }

            $userData = getUserById($pdo, $targetUserId);

            if ($userData) {
                send_json_response(1, 1, 200, "User retrieved successfully", ['user' => $userData]);
            } else {
                send_json_response(0, 1, 404, "User not found");
            }
            break;

        default:
            send_json_response(0, 1, 400, "Invalid user operation: $operation");
    }
}
