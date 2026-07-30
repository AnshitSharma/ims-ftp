<?php
/**
 * Authentication handler — login, logout, token refresh/verify,
 * forgot/reset password, change own password.
 *
 * Included by api/api.php for the `auth` module. Auth operations do not
 * require a JWT — `change_password` is the exception and authenticates the
 * caller itself (see handleChangePassword). User creation is handled by the
 * `users` module (`users-create`), gated by the `users.create` permission.
 */

/**
 * Handle authentication operations (no login required)
 */
function handleAuthOperations($operation) {
    error_log("Auth operation: $operation");

    global $pdo;

    // Rate limit the password-bearing operations
    if (in_array($operation, ['login', 'forgot_password', 'reset_password', 'change_password'])) {
        require_once(__DIR__ . '/../../../core/helpers/RateLimiter.php');
        $rateLimiter = new RateLimiter();
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $limits = [
            'login' => [10, 60],            // 10 attempts per minute
            'forgot_password' => [3, 3600], // 3 attempts per hour
            'reset_password' => [5, 3600],  // 5 attempts per hour
            'change_password' => [5, 900],  // 5 attempts per 15 minutes
        ];
        [$maxAttempts, $window] = $limits[$operation];

        if (!$rateLimiter->attempt("$operation:$clientIp", $maxAttempts, $window)) {
            send_json_response(0, 0, 429, "Too many requests. Please try again later.");
        }
    }

    switch ($operation) {
        case 'login':
            handleLogin();
            break;

        case 'logout':
            handleLogout();
            break;

        case 'refresh':
            handleTokenRefresh();
            break;

        case 'verify_token':
            handleTokenVerification();
            break;

        case 'forgot_password':
            handleForgotPassword();
            break;

        case 'reset_password':
            handleResetPassword();
            break;

        case 'change_password':
            handleChangePassword();
            break;

        default:
            send_json_response(0, 0, 400, "Invalid authentication operation: $operation");
    }
}

/**
 * Handle login request
 */
function handleLogin() {
    global $pdo;

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = filter_var($_POST['remember_me'] ?? false, FILTER_VALIDATE_BOOLEAN);

    error_log("Login attempt - Username: '$username'");

    if (empty($username) || empty($password)) {
        send_json_response(0, 0, 400, "Username and password are required");
    }

    // Per-account throttle on top of the per-IP limit in handleAuthOperations:
    // 5 FAILED attempts per username in 15 minutes, regardless of source IP.
    // Counts failures for unknown usernames too, so the 429 doesn't leak
    // whether an account exists.
    require_once(__DIR__ . '/../../../core/helpers/RateLimiter.php');
    $loginLimiter = new RateLimiter();
    $failKey = 'login-fail:' . strtolower($username);
    $failWindow = 900;
    if ($loginLimiter->tooManyAttempts($failKey, 5, $failWindow)) {
        send_json_response(0, 0, 429, "Too many failed login attempts. Please try again later.");
    }

    try {
        // Authenticate user
        $user = authenticateUser($pdo, $username, $password);

        if (!$user) {
            $loginLimiter->hit($failKey, $failWindow);
            error_log("Authentication failed for: $username");
            send_json_response(0, 0, 401, "Invalid credentials");
        }

        // Successful login resets the failure counter
        $loginLimiter->clear($failKey);

        error_log("Login successful for user: $username (ID: " . $user['id'] . ")");

        // Generate JWT tokens
        $jwtExpiryHours = defined('JWT_EXPIRY_HOURS') ? JWT_EXPIRY_HOURS : 24;
        $accessTokenExpiry = $rememberMe ? 86400 : ($jwtExpiryHours * 3600); // 24h or configured hours
        $refreshTokenExpiry = $rememberMe ? 2592000 : 604800; // 30 days or 7 days

        $accessToken = JWTHelper::generateToken([
            'user_id' => $user['id'],
            'username' => $user['username']
        ], $accessTokenExpiry);

        $refreshToken = JWTHelper::generateRefreshToken();

        // Store refresh token
        JWTHelper::storeRefreshToken($pdo, $user['id'], $refreshToken, $refreshTokenExpiry);

        // Get user permissions
        $permissions = getUserPermissions($pdo, $user['id']);

        // Get user roles (used by frontend for UI-level role gating, e.g. Vendors menu)
        $roleStmt = $pdo->prepare("
            SELECT r.name FROM roles r
            JOIN user_roles ur ON r.id = ur.role_id
            WHERE ur.user_id = ?
        ");
        $roleStmt->execute([$user['id']]);
        $userRoleNames = $roleStmt->fetchAll(PDO::FETCH_COLUMN);

        send_json_response(1, 1, 200, "Login successful", [
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname'],
                'roles' => $userRoleNames,
                // Permission name list (or ['*'] for admins) so the frontend can
                // gate UI elements. Real enforcement is always server-side.
                'permissions' => $permissions
            ],
            'tokens' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => $accessTokenExpiry
            ]

        ]);

    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        send_json_response(0, 0, 500, "Login failed");
    }
}

/**
 * Handle logout request
 *
 * Revokes BOTH the refresh tokens (by deleting auth_tokens rows) AND the
 * current access token (by inserting its jti into revoked_tokens). Without
 * the jti blacklist the signed JWT would remain valid until its natural
 * `exp` even after the user logged out.
 *
 * verifyToken is called WITHOUT $pdo on purpose: if the token was already
 * revoked, we still want to be idempotent and return 200 rather than
 * refusing to let the client "log out again".
 */
function handleLogout() {
    global $pdo;

    try {
        $token = JWTHelper::getTokenFromHeader();

        if ($token) {
            $payload = JWTHelper::verifyToken($token);
            $userId = $payload['user_id'];

            // 1. Revoke all refresh tokens for this user
            $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);

            // 2. Blacklist THIS access token's jti so verifyToken() will
            //    reject it on subsequent requests until it expires naturally.
            if (!empty($payload['jti']) && !empty($payload['exp'])) {
                try {
                    $expiresAt = date('Y-m-d H:i:s', (int)$payload['exp']);
                    $stmt = $pdo->prepare(
                        "INSERT IGNORE INTO revoked_tokens (jti, user_id, expires_at) VALUES (?, ?, ?)"
                    );
                    $stmt->execute([$payload['jti'], $userId, $expiresAt]);
                } catch (PDOException $e) {
                    // Table missing = migration not applied. Log loudly
                    // but don't crash the logout request.
                    error_log("handleLogout: failed to insert revoked_tokens: " . $e->getMessage());
                }
            }
        }

        send_json_response(1, 1, 200, "Logged out successfully");

    } catch (Exception $e) {
        // Even if token verification fails, we consider logout successful
        send_json_response(1, 1, 200, "Logged out successfully");
    }
}

/**
 * Handle token refresh
 */
function handleTokenRefresh() {
    global $pdo;

    $refreshToken = $_POST['refresh_token'] ?? '';

    if (empty($refreshToken)) {
        send_json_response(0, 0, 400, "Refresh token is required");
    }

    try {
        // auth_tokens.token stores a SHA-256 hash (see storeRefreshToken),
        // so the lookup must go through verifyRefreshToken which hashes the
        // presented token first. Also enforces expiry and active user status.
        $user = JWTHelper::verifyRefreshToken($pdo, $refreshToken);

        if (!$user) {
            send_json_response(0, 0, 401, "Invalid or expired refresh token");
        }

        // Generate new access token
        $jwtExpiryHours = defined('JWT_EXPIRY_HOURS') ? JWT_EXPIRY_HOURS : 24;
        $tokenExpiry = $jwtExpiryHours * 3600;
        $accessToken = JWTHelper::generateToken([
            'user_id' => $user['id'],
            'username' => $user['username']
        ], $tokenExpiry);

        $permissions = getUserPermissions($pdo, $user['id']);

        send_json_response(1, 1, 200, "Token refreshed successfully", [
            'access_token' => $accessToken,
            'expires_in' => $tokenExpiry,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname'],
                'permissions' => $permissions
            ]
        ]);

    } catch (Exception $e) {
        error_log("Token refresh error: " . $e->getMessage());
        send_json_response(0, 0, 401, "Token refresh failed");
    }
}

/**
 * Handle token verification
 */
function handleTokenVerification() {
    global $pdo;

    try {
        $user = authenticateWithJWT($pdo);

        if (!$user) {
            send_json_response(0, 0, 401, "Invalid token");
        }

        send_json_response(1, 1, 200, "Token is valid", [
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname']
            ]
        ]);

    } catch (Exception $e) {
        error_log("Token verification error: " . $e->getMessage());
        send_json_response(0, 0, 401, "Token verification failed");
    }
}

/**
 * Handle forgot password
 */
function handleForgotPassword() {
    global $pdo;

    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        send_json_response(0, 0, 400, "Email is required");
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_json_response(0, 0, 400, "Invalid email format");
    }

    try {
        // Look up user by email
        $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = :email AND status = 'active'");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // If user exists, generate and send reset token
        if ($user) {
            // Generate secure token. Only its SHA-256 hash is stored, so a
            // leaked password_resets table can't be replayed; the plaintext
            // token exists only in the emailed link.
            $resetToken = bin2hex(random_bytes(32)); // 64-char hex string
            $resetTokenHash = hash('sha256', $resetToken);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Clean up old unused tokens for this user (prevent token spam)
            $stmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = :user_id AND used_at IS NULL");
            $stmt->execute(['user_id' => $user['id']]);

            // Store new token (hashed)
            $stmt = $pdo->prepare(
                "INSERT INTO password_resets (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)"
            );
            $stmt->execute([
                'user_id' => $user['id'],
                'token' => $resetTokenHash,
                'expires_at' => $expiresAt
            ]);

            // Construct reset link
            $frontendUrl = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
            $resetLink = $frontendUrl . '/reset-password?token=' . $resetToken;

            // Send email
            require_once __DIR__ . '/../../../core/helpers/EmailHelper.php';
            $emailSent = EmailHelper::sendPasswordResetEmail($user['email'], $user['username'], $resetLink);

            if (!$emailSent) {
                error_log("[handleForgotPassword] Failed to send reset email to {$user['email']}");
            }
        }

        // ALWAYS return success (security: don't leak user existence)
        send_json_response(1, 1, 200, "If an account with that email exists, a password reset link has been sent");

    } catch (PDOException $e) {
        error_log("[handleForgotPassword] Database error: " . $e->getMessage());
        send_json_response(0, 0, 500, "An error occurred. Please try again later");
    }
}

/**
 * Enforce password strength (minimum 8 characters + uppercase + number +
 * special char). Shared by reset_password and change_password so the two
 * paths can never drift apart. Responds and exits on the first violation.
 */
function assertPasswordStrength($password) {
    if (strlen($password) < 8) {
        send_json_response(0, 0, 400, "Password must be at least 8 characters long");
    }
    if (!preg_match('/[A-Z]/', $password)) {
        send_json_response(0, 0, 400, "Password must contain at least one uppercase letter");
    }
    if (!preg_match('/[0-9]/', $password)) {
        send_json_response(0, 0, 400, "Password must contain at least one number");
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        send_json_response(0, 0, 400, "Password must contain at least one special character");
    }
}

/**
 * Handle password reset
 */
function handleResetPassword() {
    global $pdo;

    $token = trim($_POST['token'] ?? $_GET['token'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');

    // Validate input
    if (empty($token)) {
        send_json_response(0, 0, 400, "Reset token is required");
    }

    if (empty($newPassword)) {
        send_json_response(0, 0, 400, "New password is required");
    }

    assertPasswordStrength($newPassword);

    try {
        // Tokens are stored hashed (see handleForgotPassword), so hash the
        // presented token before lookup.
        $tokenHash = hash('sha256', $token);

        // Read the token first, purely to tell the user WHY it failed. This read is
        // advisory: it is not what authorises the reset. The consuming UPDATE below
        // is the only thing that does.
        $stmt = $pdo->prepare(
            "SELECT user_id, expires_at, used_at FROM password_resets WHERE token = :token"
        );
        $stmt->execute(['token' => $tokenHash]);
        $resetRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$resetRecord) {
            send_json_response(0, 0, 400, "Invalid or expired reset token");
        }
        if ($resetRecord['used_at'] !== null) {
            send_json_response(0, 0, 400, "This reset link has already been used");
        }
        if (strtotime($resetRecord['expires_at']) < time()) {
            send_json_response(0, 0, 400, "This reset link has expired");
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // The whole consume-and-reset sequence is one transaction. Previously these
        // four statements ran unwrapped after a check-then-act on used_at, which was
        // wrong in two ways:
        //
        //   - Two concurrent requests bearing the same token both read used_at as
        //     NULL and both proceeded, so a single-use link was usable more than once
        //     inside the race window.
        //   - A failure part-way through left the password already changed with the
        //     token still marked unused, or the user's sessions deleted without the
        //     password having changed.
        $pdo->beginTransaction();
        try {
            // Consume the token FIRST, conditionally. This is the atomic gate: whoever
            // flips used_at from NULL wins, and everyone else affects zero rows and is
            // turned away. No lock or isolation-level assumption required -- the
            // WHERE clause does the arbitration.
            $consume = $pdo->prepare(
                "UPDATE password_resets SET used_at = NOW() WHERE token = :token AND used_at IS NULL"
            );
            $consume->execute(['token' => $tokenHash]);

            if ($consume->rowCount() !== 1) {
                $pdo->rollBack();
                send_json_response(0, 0, 400, "This reset link has already been used");
            }

            // Update password AND stamp password_changed_at. That timestamp is the
            // cutoff used by JWTHelper::verifyToken — every still-valid access token
            // issued before this instant becomes unusable, which is what we want
            // after a password reset.
            $stmt = $pdo->prepare("UPDATE users SET password = :password, password_changed_at = NOW() WHERE id = :user_id");
            $stmt->execute([
                'password' => $hashedPassword,
                'user_id' => $resetRecord['user_id']
            ]);

            // Invalidate all user's refresh tokens (force logout everywhere).
            // Access tokens are handled by the password_changed_at cutoff above.
            $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $resetRecord['user_id']]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        error_log("[handleResetPassword] Password successfully reset for user_id {$resetRecord['user_id']}");

        send_json_response(1, 1, 200, "Password has been reset successfully. Please login with your new password.");

    } catch (PDOException $e) {
        error_log("[handleResetPassword] Database error: " . $e->getMessage());
        send_json_response(0, 0, 500, "An error occurred. Please try again later");
    }
}

/**
 * Handle a logged-in user changing their own password.
 *
 * Unlike the rest of the `auth` module this operation requires a session, but
 * api.php routes the whole module before its JWT gate — so the token is
 * verified here. Knowledge of the current password is the authorisation; no
 * ACL check is applied, since a user who cannot change their own password
 * would have no way to recover from a leaked one.
 */
function handleChangePassword() {
    global $pdo;

    $token = JWTHelper::getTokenFromHeader();
    if (!$token) {
        send_json_response(0, 0, 401, "Valid JWT token required - please login");
    }

    try {
        // Pass $pdo so the revocation checks (blacklist + password cutoff) run.
        $payload = JWTHelper::verifyToken($token, $pdo);
    } catch (Exception $e) {
        send_json_response(0, 0, 401, "Valid JWT token required - please login");
    }

    $userId = $payload['user_id'] ?? null;
    if (empty($userId)) {
        send_json_response(0, 0, 401, "Valid JWT token required - please login");
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '') {
        send_json_response(0, 0, 400, "Current password is required");
    }
    if ($newPassword === '') {
        send_json_response(0, 0, 400, "New password is required");
    }
    if ($confirmPassword !== '' && $newPassword !== $confirmPassword) {
        send_json_response(0, 0, 400, "New passwords do not match");
    }
    if ($newPassword === $currentPassword) {
        send_json_response(0, 0, 400, "New password must be different from the current password");
    }

    assertPasswordStrength($newPassword);

    try {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $storedHash = $stmt->fetchColumn();

        if ($storedHash === false) {
            send_json_response(0, 0, 401, "Valid JWT token required - please login");
        }

        if (!password_verify($currentPassword, $storedHash)) {
            error_log("[handleChangePassword] Incorrect current password for user_id {$userId}");
            send_json_response(0, 0, 400, "Current password is incorrect");
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // Same invariant as handleResetPassword: the password change and the
        // session teardown either both happen or neither does.
        $pdo->beginTransaction();
        try {
            // password_changed_at is the cutoff JWTHelper::verifyToken() compares
            // against, so every access token issued before now — including the one
            // that authorised this request — stops working.
            $stmt = $pdo->prepare("UPDATE users SET password = :password, password_changed_at = NOW() WHERE id = :user_id");
            $stmt->execute([
                'password' => $hashedPassword,
                'user_id' => $userId
            ]);

            // Drop every refresh token so no other device can mint a new session.
            $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $userId]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        error_log("[handleChangePassword] Password changed for user_id {$userId}");

        send_json_response(1, 1, 200, "Password changed successfully. Please login again with your new password.");

    } catch (PDOException $e) {
        error_log("[handleChangePassword] Database error: " . $e->getMessage());
        send_json_response(0, 0, 500, "An error occurred. Please try again later");
    }
}
