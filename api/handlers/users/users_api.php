<?php
/**
 * Users handler — user CRUD operations.
 *
 * Included by api/api.php for the `users` module. Permissions are checked
 * per-operation here because they differ (user.view/create/edit/delete).
 */

/**
 * Enforce the password policy for user-management writes.
 *
 * Deliberately NOT auth_api.php::assertPasswordStrength(): that file is only
 * included for the `auth` module (api.php routes auth before the JWT gate and
 * exits), and it answers with authenticated = 0 — wrong for a request that
 * arrived with a valid admin session. Same four rules, correct response shape.
 */
function assertUserPasswordPolicy($password) {
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
}

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
            assertUserPasswordPolicy($password);

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
                $roleAssigned = false;
                if ($resolvedRoleId !== null) {
                    $roleAssigned = assignRoleToUser($pdo, $userId, $resolvedRoleId);
                    if (!$roleAssigned) {
                        error_log("User $userId created but role assignment to role $resolvedRoleId failed");
                    }
                }

                if ($roleAssigned) {
                    send_json_response(1, 1, 201, "User created successfully", [
                        'user_id' => (int)$userId,
                        'role_id' => $resolvedRoleId
                    ]);
                } else {
                    send_json_response(1, 1, 201, "User created, but role assignment failed — assign a role manually", [
                        'user_id' => (int)$userId,
                        'role_id' => null
                    ]);
                }
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

        // Administrative password reset: an admin sets a new password FOR ANOTHER
        // ACCOUNT. This is not the self-service path — that is
        // auth-change_password, gated on knowing the current password. Here the
        // acting admin re-authenticates with their OWN password instead, so a
        // borrowed or stolen session cannot seize accounts on its own.
        //
        // The underscore spelling is accepted because a browser can hold a
        // 5-minute-cached api.js whose old (never-implemented) stub called
        // `users-reset_password`.
        case 'reset-password':
        case 'reset_password':
            // The ROLE GATE is what actually keeps non-admins out. hasPermission()
            // returns true unconditionally for admin/super_admin
            // (BaseFunctions.php:320), so the users.reset_password check below can
            // only ever bite a role that already failed the gate. It is kept
            // because it is what makes this capability visible and revocable in
            // the role editor, what the UI reads, and what would decide access if
            // the gate is ever widened.
            if (!userHasRole($pdo, $user['id'], 'super_admin') && !userHasRole($pdo, $user['id'], 'admin')) {
                send_json_response(0, 1, 403, "Insufficient permissions: admin or super_admin role required");
            }
            if (!hasPermission($pdo, 'users.reset_password', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions to reset a user's password");
            }

            $targetUserId = $_POST['user_id'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            $adminPassword = $_POST['admin_password'] ?? '';

            if ($targetUserId === '' || $newPassword === '') {
                send_json_response(0, 1, 400, "User ID and new password are required");
            }
            if ($adminPassword === '') {
                send_json_response(0, 1, 400, "Confirm the change with your own password");
            }
            if ((string)$targetUserId === (string)$user['id']) {
                send_json_response(0, 1, 400, "Use Change Password in the account menu to change your own password");
            }
            if ($confirmPassword !== '' && $newPassword !== $confirmPassword) {
                send_json_response(0, 1, 400, "New passwords do not match");
            }

            $targetUser = getUserById($pdo, $targetUserId);
            if (!$targetUser) {
                send_json_response(0, 1, 404, "User not found");
            }

            // Re-authenticate the ACTOR against their stored hash.
            $actorStmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $actorStmt->execute([$user['id']]);
            $actorHash = $actorStmt->fetchColumn();

            if ($actorHash === false || !password_verify($adminPassword, $actorHash)) {
                error_log("[users-reset-password] Re-authentication failed for user_id {$user['id']}");
                send_json_response(0, 1, 400, "Your password is incorrect");
            }

            assertUserPasswordPolicy($newPassword);

            // Same invariant as auth handleResetPassword(): the new hash, the
            // session cutoff and the refresh-token teardown all land together or
            // not at all. password_changed_at is the cutoff
            // JWTHelper::verifyToken() compares against, so every access token the
            // target still holds stops working; dropping auth_tokens stops any
            // device minting a new one.
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("UPDATE users SET password = :password, password_changed_at = NOW() WHERE id = :user_id");
                $stmt->execute([
                    'password' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'user_id' => $targetUser['id']
                ]);

                $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = :user_id");
                $stmt->execute(['user_id' => $targetUser['id']]);

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("[users-reset-password] Failed for user_id {$targetUser['id']}: " . $e->getMessage());
                send_json_response(0, 1, 500, "Failed to reset the password");
            }

            // Audit trail. The password itself is never logged, here or anywhere.
            logActivity(
                $pdo,
                $user['id'],
                'reset_password',
                'user',
                (int)$targetUser['id'],
                "Password reset for {$targetUser['username']} by {$user['username']}"
            );

            send_json_response(1, 1, 200, "Password updated for {$targetUser['username']}. They have been signed out everywhere and must log in with the new password.");
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
