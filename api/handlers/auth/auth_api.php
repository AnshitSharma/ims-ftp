<?php
/**
 * Authentication handler — login, logout, token refresh/verify, registration,
 * forgot/reset password.
 *
 * Included by api/api.php for the `auth` module. Auth operations do not
 * require a JWT (except `register`, which authenticates internally).
 */

/**
 * Handle authentication operations (no login required)
 */
function handleAuthOperations($operation) {
    error_log("Auth operation: $operation");

    global $pdo;

    // Rate limit login, forgot_password, and reset_password
    if (in_array($operation, ['login', 'forgot_password', 'reset_password'])) {
        require_once(__DIR__ . '/../../../core/helpers/RateLimiter.php');
        $rateLimiter = new RateLimiter();
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $limits = [
            'login' => [10, 60],            // 10 attempts per minute
            'forgot_password' => [3, 3600], // 3 attempts per hour
            'reset_password' => [5, 3600],  // 5 attempts per hour
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

        case 'register':
            // Registration requires authentication and user.create permission
            $regUser = authenticateWithJWT($pdo);
            if (!$regUser) {
                send_json_response(0, 0, 401, "Authentication required for registration");
            }
            if (!hasPermission($pdo, 'user.create', $regUser['id'])) {
                send_json_response(0, 1, 403, "Permission denied: user.create required");
            }
            handleRegistration();
            break;

        case 'forgot_password':
            handleForgotPassword();
            break;

        case 'reset_password':
            handleResetPassword();
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
                'roles' => $userRoleNames
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

        send_json_response(1, 1, 200, "Token refreshed successfully", [
            'access_token' => $accessToken,
            'expires_in' => $tokenExpiry,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname']
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
 * Handle user registration (if enabled)
 */
function handleRegistration() {
    global $pdo;

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');

    if (empty($username) || empty($email) || empty($password)) {
        send_json_response(0, 0, 400, "Username, email, and password are required");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_json_response(0, 0, 400, "Invalid email format");
    }

    if (strlen($password) < 8) {
        send_json_response(0, 0, 400, "Password must be at least 8 characters");
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

    if (strlen($username) < 3 || strlen($username) > 50) {
        send_json_response(0, 0, 400, "Username must be between 3 and 50 characters");
    }

    try {
        // Check if username/email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            send_json_response(0, 0, 409, "Username or email already exists");
        }

        // Create user
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, firstname, lastname, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$username, $email, $hashedPassword, $firstname, $lastname]);

        $userId = $pdo->lastInsertId();

        // Assign default role (read from DB instead of hardcoding)
        $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE is_default = 1 LIMIT 1");
        $roleStmt->execute();
        $defaultRole = $roleStmt->fetch(PDO::FETCH_ASSOC);
        $defaultRoleId = $defaultRole ? $defaultRole['id'] : 2; // Fallback to 2 if no default set
        assignRoleToUser($pdo, $userId, $defaultRoleId);

        send_json_response(1, 1, 201, "Registration successful", [
            'user_id' => (int)$userId,
            'username' => $username,
            'message' => "Please login with your credentials"
        ]);

    } catch (Exception $e) {
        error_log("Registration error: " . $e->getMessage());
        send_json_response(0, 0, 500, "Registration failed");
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

    // Enforce password strength (minimum 8 characters + uppercase + number + special char)
    if (strlen($newPassword) < 8) {
        send_json_response(0, 0, 400, "Password must be at least 8 characters long");
    }
    if (!preg_match('/[A-Z]/', $newPassword)) {
        send_json_response(0, 0, 400, "Password must contain at least one uppercase letter");
    }
    if (!preg_match('/[0-9]/', $newPassword)) {
        send_json_response(0, 0, 400, "Password must contain at least one number");
    }
    if (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        send_json_response(0, 0, 400, "Password must contain at least one special character");
    }

    try {
        // Tokens are stored hashed (see handleForgotPassword), so hash the
        // presented token before lookup.
        $tokenHash = hash('sha256', $token);

        // Look up token
        $stmt = $pdo->prepare(
            "SELECT user_id, expires_at, used_at FROM password_resets WHERE token = :token"
        );
        $stmt->execute(['token' => $tokenHash]);
        $resetRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        // Validate token exists
        if (!$resetRecord) {
            send_json_response(0, 0, 400, "Invalid or expired reset token");
        }

        // Check if already used
        if ($resetRecord['used_at'] !== null) {
            send_json_response(0, 0, 400, "This reset link has already been used");
        }

        // Check if expired
        if (strtotime($resetRecord['expires_at']) < time()) {
            send_json_response(0, 0, 400, "This reset link has expired");
        }

        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update user password AND stamp password_changed_at. That timestamp
        // is the cutoff used by JWTHelper::verifyToken — every still-valid
        // access token issued before this instant becomes unusable, which is
        // what we want after a password reset.
        $stmt = $pdo->prepare("UPDATE users SET password = :password, password_changed_at = NOW() WHERE id = :user_id");
        $stmt->execute([
            'password' => $hashedPassword,
            'user_id' => $resetRecord['user_id']
        ]);

        // Mark token as used
        $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token = :token");
        $stmt->execute(['token' => $tokenHash]);

        // Invalidate all user's refresh tokens (force logout everywhere).
        // Access tokens are handled by the password_changed_at cutoff above.
        $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $resetRecord['user_id']]);

        error_log("[handleResetPassword] Password successfully reset for user_id {$resetRecord['user_id']}");

        send_json_response(1, 1, 200, "Password has been reset successfully. Please login with your new password.");

    } catch (PDOException $e) {
        error_log("[handleResetPassword] Database error: " . $e->getMessage());
        send_json_response(0, 0, 500, "An error occurred. Please try again later");
    }
}
