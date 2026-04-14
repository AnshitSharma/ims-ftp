<?php
/**
 * JWT Helper Class
 * Create this as includes/JWTHelper.php
 */

class JWTHelper {
    private static $secret = null;
    private static $algorithm = 'HS256';
    
    /**
     * Initialize JWT with secret key
     */
    public static function init($secret) {
        self::$secret = $secret;
    }
    
    /**
     * Base64 URL encode
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     */
    private static function base64UrlDecode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
    
    /**
     * Generate JWT token
     */
    public static function generateToken($payload, $expiresIn = null) {
        if (!self::$secret) {
            throw new Exception('JWT secret not initialized');
        }

        // Use configured JWT expiry if not specified
        if ($expiresIn === null) {
            $expiresIn = JWT_EXPIRY_HOURS * 3600;
        }

        // Header
        $header = json_encode([
            'typ' => 'JWT',
            'alg' => self::$algorithm
        ]);

        // Payload with standard claims.
        // `jti` is a per-token unique id used by the revocation path
        // (handleLogout inserts it into revoked_tokens).
        $now = time();
        $issuer = defined('JWT_ISSUER') ? JWT_ISSUER : 'bdc-ims';
        $audience = defined('JWT_AUDIENCE') ? JWT_AUDIENCE : null;
        $payload = array_merge($payload, [
            'iat' => $now,              // Issued at
            'exp' => $now + $expiresIn, // Expires at
            'iss' => $issuer,           // Issuer
            'aud' => $audience,         // Audience
            'jti' => bin2hex(random_bytes(16)), // Unique token id for revocation
        ]);
        
        $payloadJson = json_encode($payload);
        
        // Encode header and payload
        $headerEncoded = self::base64UrlEncode($header);
        $payloadEncoded = self::base64UrlEncode($payloadJson);
        
        // Create signature
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, self::$secret, true);
        $signatureEncoded = self::base64UrlEncode($signature);
        
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
    
    /**
     * Verify and decode JWT token.
     *
     * When $pdo is supplied the token is additionally checked against the
     * revocation store (revoked_tokens table + users.password_changed_at
     * cutoff). Callers that already hold a $pdo (e.g. authenticateWithJWT)
     * MUST pass it; otherwise logout/password-reset revocation is bypassed.
     *
     * @param string   $token The JWT string
     * @param PDO|null $pdo   Optional PDO for revocation checks
     * @return array Decoded payload
     */
    public static function verifyToken($token, $pdo = null) {
        if (!self::$secret) {
            throw new Exception('JWT secret not initialized');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new Exception('Invalid token format');
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Verify header algorithm matches what we use to sign.
        // Prevents `alg:none` and algorithm-confusion attacks.
        $header = json_decode(self::base64UrlDecode($headerEncoded), true);
        if (!is_array($header) || !isset($header['alg']) || $header['alg'] !== self::$algorithm) {
            throw new Exception('Invalid token algorithm');
        }

        // Verify signature
        $signature = self::base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, self::$secret, true);

        if (!hash_equals($signature, $expectedSignature)) {
            throw new Exception('Invalid token signature');
        }

        // Decode payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);

        if (!$payload) {
            throw new Exception('Invalid token payload');
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new Exception('Token has expired');
        }

        // Verify issuer claim
        $expectedIssuer = defined('JWT_ISSUER') ? JWT_ISSUER : 'bdc-ims';
        if (!isset($payload['iss']) || $payload['iss'] !== $expectedIssuer) {
            throw new Exception('Invalid token issuer');
        }

        // Verify audience claim (only if JWT_AUDIENCE is configured)
        $expectedAudience = defined('JWT_AUDIENCE') ? JWT_AUDIENCE : null;
        if ($expectedAudience && (!isset($payload['aud']) || $payload['aud'] !== $expectedAudience)) {
            throw new Exception('Invalid token audience');
        }

        // Revocation checks require a database handle. Skipping them silently
        // would leave logout broken, so tokens verified without $pdo only
        // remain legal for code paths that don't hit protected resources.
        //
        // Error handling strategy: distinguish between "the revocation
        // migration hasn't been applied yet" (MySQL error 1146 table missing
        // / 1054 column missing) and any other DB error.
        //   - Schema-missing → log once, fail OPEN. Lets operators deploy
        //     the code before running the migration without locking users
        //     out.
        //   - Any other error → fail CLOSED. A real DB problem should not
        //     be silently ignored on an auth path.
        if ($pdo !== null) {
            // 1. Per-token blacklist (handleLogout path)
            if (!empty($payload['jti'])) {
                try {
                    $stmt = $pdo->prepare("SELECT 1 FROM revoked_tokens WHERE jti = ? LIMIT 1");
                    $stmt->execute([$payload['jti']]);
                    if ($stmt->fetchColumn()) {
                        throw new Exception('Token has been revoked');
                    }
                } catch (PDOException $e) {
                    if (self::isMissingSchemaError($e)) {
                        error_log("JWT revocation: revoked_tokens table missing — run migration 2026-04-11_jwt_revocation.sql");
                    } else {
                        error_log("JWT revocation check failed: " . $e->getMessage());
                        throw new Exception('Token revocation check unavailable');
                    }
                }
            }

            // 2. Global cutoff per user (handleResetPassword path).
            // Any token issued before users.password_changed_at is rejected.
            if (!empty($payload['user_id']) && !empty($payload['iat'])) {
                try {
                    $stmt = $pdo->prepare("SELECT password_changed_at FROM users WHERE id = ?");
                    $stmt->execute([$payload['user_id']]);
                    $changedAt = $stmt->fetchColumn();
                    if ($changedAt && strtotime($changedAt) > (int)$payload['iat']) {
                        throw new Exception('Token invalidated by password change');
                    }
                } catch (PDOException $e) {
                    if (self::isMissingSchemaError($e)) {
                        error_log("JWT revocation: users.password_changed_at missing — run migration 2026-04-11_jwt_revocation.sql");
                    } else {
                        error_log("JWT password-cutoff check failed: " . $e->getMessage());
                        throw new Exception('Token revocation check unavailable');
                    }
                }
            }
        }

        return $payload;
    }

    /**
     * Detect "table missing" (1146) / "column missing" (1054) PDO errors
     * so verifyToken() can fail open only when the revocation migration
     * hasn't been applied yet.
     */
    private static function isMissingSchemaError(PDOException $e) {
        // errorInfo[1] is the driver-specific code. For MySQL:
        //   1146 — base table or view not found
        //   1054 — unknown column
        $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
        if ($driverCode === 1146 || $driverCode === 1054) {
            return true;
        }
        // Fallback: some drivers only surface the message
        $msg = $e->getMessage();
        return stripos($msg, "doesn't exist") !== false
            || stripos($msg, "unknown column") !== false;
    }
    
    /**
     * Get token from Authorization header
     */
    public static function getTokenFromHeader() {
        $headers = getallheaders();

        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Get user ID from current request token
     */
    public static function getUserIdFromToken() {
        $token = self::getTokenFromHeader();
        if (!$token) {
            return null;
        }

        try {
            $payload = self::verifyToken($token);
            return $payload['user_id'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Refresh token - Generate new token from existing valid token
     */
    public static function refreshToken($token) {
        try {
            $payload = self::verifyToken($token);

            // Remove time-based claims to regenerate fresh ones
            unset($payload['iat'], $payload['exp'], $payload['iss']);

            // Generate new token with fresh timestamps
            return self::generateToken($payload);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Hash refresh token with SHA-256
     * Refresh tokens are high-entropy random bytes, so fast hash (not bcrypt) is appropriate
     */
    private static function hashRefreshToken($token) {
        return hash('sha256', $token);
    }

    /**
     * Generate refresh token
     */
    public static function generateRefreshToken() {
        return bin2hex(random_bytes(32));
    }

    /**
     * Store refresh token in database
     * Token is hashed before storage (cannot be recovered; only matched by hash)
     */
    public static function storeRefreshToken($pdo, $userId, $refreshToken, $expiresIn = 2592000) {
        try {
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
            $tokenHash = self::hashRefreshToken($refreshToken);

            $stmt = $pdo->prepare("
                INSERT INTO auth_tokens (user_id, token, created_at, expires_at)
                VALUES (?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                token = VALUES(token),
                created_at = NOW(),
                expires_at = VALUES(expires_at)
            ");

            return $stmt->execute([$userId, $tokenHash, $expiresAt]);
        } catch (PDOException $e) {
            error_log("Error storing refresh token: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify refresh token
     * Input token is hashed and compared against stored hash
     */
    public static function verifyRefreshToken($pdo, $refreshToken) {
        try {
            $tokenHash = self::hashRefreshToken($refreshToken);

            $stmt = $pdo->prepare("
                SELECT at.user_id, u.username, u.email, u.firstname, u.lastname
                FROM auth_tokens at
                JOIN users u ON at.user_id = u.id
                WHERE at.token = ? AND at.expires_at > NOW()
            ");
            $stmt->execute([$tokenHash]);

            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error verifying refresh token: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clean up expired tokens
     */
    public static function cleanupExpiredTokens($pdo) {
        try {
            $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE expires_at <= NOW()");
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error cleaning up expired tokens: " . $e->getMessage());
            return false;
        }
    }
}
?>