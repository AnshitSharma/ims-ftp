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
        
        // Payload with standard claims
        $now = time();
        $payload = array_merge($payload, [
            'iat' => $now,              // Issued at
            'exp' => $now + $expiresIn, // Expires at
            'iss' => 'bdc-ims',         // Issuer
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
     * Verify and decode JWT token
     */
    public static function verifyToken($token) {
        if (!self::$secret) {
            throw new Exception('JWT secret not initialized');
        }
        
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new Exception('Invalid token format');
        }
        
        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
        
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
        
        return $payload;
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
     * Generate refresh token
     */
    public static function generateRefreshToken() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Store refresh token in database
     */
    public static function storeRefreshToken($pdo, $userId, $refreshToken, $expiresIn = 2592000) {
        try {
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
            
            $stmt = $pdo->prepare("
                INSERT INTO auth_tokens (user_id, token, created_at, expires_at) 
                VALUES (?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE 
                token = VALUES(token), 
                created_at = NOW(), 
                expires_at = VALUES(expires_at)
            ");
            
            return $stmt->execute([$userId, $refreshToken, $expiresAt]);
        } catch (PDOException $e) {
            error_log("Error storing refresh token: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verify refresh token
     */
    public static function verifyRefreshToken($pdo, $refreshToken) {
        try {
            $stmt = $pdo->prepare("
                SELECT at.user_id, u.username, u.email, u.firstname, u.lastname
                FROM auth_tokens at
                JOIN users u ON at.user_id = u.id
                WHERE at.token = ? AND at.expires_at > NOW()
            ");
            $stmt->execute([$refreshToken]);
            
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