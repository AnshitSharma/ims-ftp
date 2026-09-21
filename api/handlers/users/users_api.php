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
                    // Role ceiling (C-01/F-14). Existence was the ONLY test here, so
                    // users.create plus role_id=<super_admin> minted an account more
                    // privileged than its creator.
                    requireGrantPolicy();
                    GrantPolicy::assertMayAssignRole($pdo, $user, $roleId);
                    $resolvedRoleId = $roleId;
                }
            }
            if ($resolvedRoleId === null) {
                $defaultStmt = $pdo->prepare("SELECT id FROM roles WHERE is_default = 1 LIMIT 1");
                $defaultStmt->execute();
                $defaultRole = $defaultStmt->fetch(PDO::FETCH_ASSOC);
                $resolvedRoleId = $defaultRole ? (int)$defaultRole['id'] : null;
            }

            // B.1 (audit §9.3): the row and its role are ONE fact. These used to be
            // two unrelated statements, and the handler's own success text admitted
            // it — "User created, but role assignment failed — assign a role
            // manually". A user with no role has no permissions and cannot be
            // recovered by whoever created them without a second, separate action.
            //
            // Refusing up front when no role resolves is the other half: the default
            // role is the fallback, so reaching here with none means the roles table
            // has no default at all, and creating an unusable account would be worse
            // than saying so.
            if ($resolvedRoleId === null) {
                send_json_response(0, 1, 409,
                    "No role could be assigned: supply a valid role_id, or set a default role.");
            }

            // send_json_response() exits, so nothing inside the transaction may call
            // it (audit §11.2) — the outcome is decided first, committed or rolled
            // back, and only then answered.
            $userId = false;
            $createError = null;
            $pdo->beginTransaction();
            try {
                $userId = createUser($pdo, $username, $email, $password, $firstname, $lastname);
                if (!$userId) {
                    throw new RuntimeException('create_failed');
                }
                if (!assignRoleToUser($pdo, $userId, $resolvedRoleId)) {
                    throw new RuntimeException('role_failed');
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $userId = false;
                $createError = $e->getMessage();
            }

            if ($userId) {
                send_json_response(1, 1, 201, "User created successfully", [
                    'user_id' => (int)$userId,
                    'role_id' => $resolvedRoleId
                ]);
            }
            if ($createError === 'role_failed') {
                error_log("users-create: role $resolvedRoleId could not be assigned; user row rolled back");
                send_json_response(0, 1, 500, "Failed to create user: the role could not be assigned.");
            }
            send_json_response(0, 1, 400, "Failed to create user. Username or email may already exist.");
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

            // Protected-target policy (F-15). users.edit alone used to be enough to
            // rewrite ANY account's email -- which hands over its password-reset
            // channel -- or flip its status.
            requireGrantPolicy();
            GrantPolicy::assertMayEditUser($pdo, $user, $targetUserId, $updateData);

            $deactivating = isset($updateData['status']) && $updateData['status'] !== 'active';

            $success = updateUser($pdo, $targetUserId, $updateData);

            if ($success) {
                if ($deactivating) {
                    // Refresh and reset tokens are credentials of their own and
                    // outlived deactivation until 2026-09-13.
                    requireGrantPolicy();
                    GrantPolicy::revokeCredentials($pdo, $targetUserId);
                }
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

            // deleteUser() reports WHICH outcome happened: a clean delete, or a
            // retire when audit rows (Requests, request history) reference the
            // account and a hard delete would destroy the trail. Both are a
            // success for the admin — the account is gone as far as access goes.
            $result = deleteUser($pdo, $targetUserId);

            if (!empty($result['ok'])) {
                send_json_response(1, 1, 200, $result['message'], [
                    'user_id' => (int)$targetUserId,
                    'mode'    => $result['mode']
                ]);
            } elseif (($result['mode'] ?? '') === 'missing') {
                send_json_response(0, 1, 404, $result['message']);
            } else {
                send_json_response(0, 1, 400, $result['message']);
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

            // B.5 (audit §12.7): every other password-bearing operation is throttled
            // in auth_api.php; this one was not, so an attacker holding a stolen
            // admin session could brute-force the actor's OWN password through the
            // re-authentication below without limit.
            //
            // Only FAILURES count. An admin legitimately resetting several accounts
            // in a row gets their counter cleared on each success, so the limit bites
            // guessing and nothing else. Keyed on the actor, not the IP — the session
            // is what is being abused here.
            require_once(__DIR__ . '/../../../core/helpers/RateLimiter.php');
            $resetLimiter = new RateLimiter();
            $resetKey = 'users-reset-password:' . $user['id'];
            if ($resetLimiter->tooManyAttempts($resetKey, 5, 900)) {
                send_json_response(0, 1, 429, "Too many failed confirmations. Please try again later.");
            }

            // Re-authenticate the ACTOR against their stored hash.
            $actorStmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $actorStmt->execute([$user['id']]);
            $actorHash = $actorStmt->fetchColumn();

            if ($actorHash === false || !password_verify($adminPassword, $actorHash)) {
                $resetLimiter->hit($resetKey, 900);
                error_log("[users-reset-password] Re-authentication failed for user_id {$user['id']}");
                send_json_response(0, 1, 400, "Your password is incorrect");
            }
            $resetLimiter->clear($resetKey);

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
