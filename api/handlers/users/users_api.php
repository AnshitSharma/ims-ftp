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
            if (!hasPermission($pdo, 'user.view', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user listing");
            }

            $users = getAllUsers($pdo);
            send_json_response(1, 1, 200, "Users retrieved successfully", ['users' => $users]);
            break;

        case 'create':
            if (!hasPermission($pdo, 'user.create', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions for user creation");
            }

            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname = trim($_POST['lastname'] ?? '');

            if (empty($username) || empty($email) || empty($password)) {
                send_json_response(0, 1, 400, "Username, email, and password are required");
            }

            $userId = createUser($pdo, $username, $email, $password, $firstname, $lastname);

            if ($userId) {
                send_json_response(1, 1, 201, "User created successfully", ['user_id' => $userId]);
            } else {
                send_json_response(0, 1, 400, "Failed to create user");
            }
            break;

        case 'update':
            if (!hasPermission($pdo, 'user.edit', $user['id'])) {
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
            if (!hasPermission($pdo, 'user.delete', $user['id'])) {
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
            if (!hasPermission($pdo, 'user.view', $user['id'])) {
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
