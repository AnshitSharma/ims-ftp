<?php
/**
 * MicrosoftOAuth.php
 *
 * Microsoft Entra ID (Azure AD) authorization-code + PKCE flow.
 *
 * This class owns ONLY the conversation with Microsoft: building the
 * authorization URL, redeeming the code, and proving the returned id_token is
 * genuine. It knows nothing about IMS users — the mapping from a verified
 * Microsoft identity to a `users` row lives in
 * api/handlers/auth/microsoft_auth.php, which is what makes this file reusable
 * by the other org panels.
 *
 * Confidential client: the code is redeemed server-side with MS_CLIENT_SECRET.
 * PKCE is used ON TOP of the secret, not instead of it, so a code intercepted
 * in the browser's address bar is useless without the verifier that never left
 * this server.
 *
 * The id_token is verified in full — signature (RS256 against the tenant's
 * published JWKS), issuer, audience, tenant, nonce and expiry. An unverified
 * id_token is just an attacker-supplied JSON blob; nothing here trusts one.
 *
 * @package BDC_IMS
 * @subpackage Auth
 */

class MicrosoftOAuth
{
    /** Seconds of clock skew tolerated on exp/nbf/iat. */
    const CLOCK_SKEW = 120;

    /** How long a fetched JWKS document is reused before re-fetching. */
    const JWKS_CACHE_TTL = 21600; // 6 hours

    /** Network timeout for both Microsoft calls. */
    const HTTP_TIMEOUT = 10;

    /**
     * Is the feature switched on? Every entry point checks this first, so an
     * unconfigured deployment behaves exactly as it did before the feature
     * existed.
     */
    public static function isConfigured()
    {
        return defined('MS_TENANT_ID') && MS_TENANT_ID !== ''
            && defined('MS_CLIENT_ID') && MS_CLIENT_ID !== ''
            && defined('MS_CLIENT_SECRET') && MS_CLIENT_SECRET !== ''
            && defined('MS_REDIRECT_URI') && MS_REDIRECT_URI !== ''
            && function_exists('curl_init')
            && function_exists('openssl_verify');
    }

    /**
     * Which prerequisite is missing, for the error log only. Never returned to
     * a client — it would tell an anonymous caller how the server is set up.
     */
    public static function configurationProblem()
    {
        if (!function_exists('curl_init')) {
            return 'php curl extension missing';
        }
        if (!function_exists('openssl_verify')) {
            return 'php openssl extension missing';
        }
        $missing = [];
        foreach (['MS_TENANT_ID', 'MS_CLIENT_ID', 'MS_CLIENT_SECRET', 'MS_REDIRECT_URI'] as $key) {
            if (!defined($key) || constant($key) === '') {
                $missing[] = $key;
            }
        }
        return $missing ? ('unset in .env: ' . implode(', ', $missing)) : '';
    }

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    private static function authority()
    {
        return 'https://login.microsoftonline.com/' . rawurlencode(MS_TENANT_ID);
    }

    private static function authorizeEndpoint()
    {
        return self::authority() . '/oauth2/v2.0/authorize';
    }

    private static function tokenEndpoint()
    {
        return self::authority() . '/oauth2/v2.0/token';
    }

    private static function jwksEndpoint()
    {
        return self::authority() . '/discovery/v2.0/keys';
    }

    /**
     * The two issuer spellings Entra uses for the v2.0 endpoint. `tid` is
     * checked separately, so this is belt and braces.
     */
    private static function acceptedIssuers($tenantIdFromToken)
    {
        return [
            'https://login.microsoftonline.com/' . $tenantIdFromToken . '/v2.0',
            'https://sts.windows.net/' . $tenantIdFromToken . '/',
        ];
    }

    // -------------------------------------------------------------------------
    // Step 1 — the authorization request
    // -------------------------------------------------------------------------

    /**
     * Random URL-safe string for state / nonce / PKCE verifier.
     */
    public static function randomString($bytes = 32)
    {
        return self::base64UrlEncode(random_bytes($bytes));
    }

    /**
     * S256 challenge for a verifier.
     */
    public static function codeChallenge($verifier)
    {
        return self::base64UrlEncode(hash('sha256', $verifier, true));
    }

    /**
     * Where to send the browser.
     *
     * `prompt=select_account` is deliberate: without it a machine already
     * signed in to another Microsoft account silently reuses it, which reads to
     * the user as "the login button logged me in as the wrong person".
     */
    public static function buildAuthorizationUrl($state, $nonce, $codeChallenge)
    {
        $params = [
            'client_id' => MS_CLIENT_ID,
            'response_type' => 'code',
            'redirect_uri' => MS_REDIRECT_URI,
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ];

        return self::authorizeEndpoint() . '?' . http_build_query($params);
    }

    // -------------------------------------------------------------------------
    // Step 2 — redeeming the code
    // -------------------------------------------------------------------------

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array|null The decoded token response, or null on any failure.
     *                    Microsoft's own error text goes to the error log only:
     *                    it can name the tenant and the client id.
     */
    public static function exchangeCode($code, $codeVerifier)
    {
        $body = http_build_query([
            'client_id' => MS_CLIENT_ID,
            'client_secret' => MS_CLIENT_SECRET,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => MS_REDIRECT_URI,
            'code_verifier' => $codeVerifier,
            'scope' => 'openid profile email',
        ]);

        $response = self::httpPost(self::tokenEndpoint(), $body);

        if ($response === null) {
            return null;
        }

        list($status, $payload) = $response;
        $decoded = json_decode($payload, true);

        if ($status !== 200 || !is_array($decoded) || empty($decoded['id_token'])) {
            $reason = is_array($decoded) && isset($decoded['error'])
                ? $decoded['error'] . ': ' . ($decoded['error_description'] ?? '')
                : ('HTTP ' . $status);
            error_log('MicrosoftOAuth::exchangeCode failed — ' . substr($reason, 0, 300));
            return null;
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // Step 3 — proving the id_token is genuine
    // -------------------------------------------------------------------------

    /**
     * Verify an id_token end to end and return its claims.
     *
     * Order matters: the signature is checked BEFORE any claim is read for a
     * decision, so nothing downstream ever acts on an unsigned payload.
     *
     * @return array|null Claims on success, null on any failure (logged).
     */
    public static function verifyIdToken($idToken, $expectedNonce)
    {
        $parts = explode('.', (string)$idToken);
        if (count($parts) !== 3) {
            error_log('MicrosoftOAuth: id_token is not a three-part JWT');
            return null;
        }

        list($encodedHeader, $encodedPayload, $encodedSignature) = $parts;

        $header = json_decode(self::base64UrlDecode($encodedHeader), true);
        $claims = json_decode(self::base64UrlDecode($encodedPayload), true);
        $signature = self::base64UrlDecode($encodedSignature);

        if (!is_array($header) || !is_array($claims) || $signature === '') {
            error_log('MicrosoftOAuth: id_token header/payload not decodable');
            return null;
        }

        // Only RS256. Accepting `alg` from the token is how "alg: none" and
        // HS256-with-the-public-key forgeries work.
        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            error_log('MicrosoftOAuth: unexpected id_token alg/kid');
            return null;
        }

        $publicKey = self::publicKeyForKid($header['kid']);
        if ($publicKey === null) {
            error_log('MicrosoftOAuth: no JWKS key matched kid ' . $header['kid']);
            return null;
        }

        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        if ($verified !== 1) {
            error_log('MicrosoftOAuth: id_token signature verification failed');
            return null;
        }

        // ---- claim checks (signature already proven) ----

        $now = time();

        if (!isset($claims['exp']) || $now >= ((int)$claims['exp'] + self::CLOCK_SKEW)) {
            error_log('MicrosoftOAuth: id_token expired');
            return null;
        }
        if (isset($claims['nbf']) && $now < ((int)$claims['nbf'] - self::CLOCK_SKEW)) {
            error_log('MicrosoftOAuth: id_token not yet valid');
            return null;
        }
        if (($claims['aud'] ?? '') !== MS_CLIENT_ID) {
            error_log('MicrosoftOAuth: id_token audience mismatch');
            return null;
        }

        // Single-tenant: an account from any other directory is refused even if
        // its email happens to match an IMS user.
        $tid = $claims['tid'] ?? '';
        if ($tid === '' || !hash_equals(MS_TENANT_ID, $tid)) {
            error_log('MicrosoftOAuth: id_token tenant mismatch');
            return null;
        }

        if (!in_array($claims['iss'] ?? '', self::acceptedIssuers($tid), true)) {
            error_log('MicrosoftOAuth: id_token issuer mismatch');
            return null;
        }

        // The nonce ties this token to the authorization request WE started,
        // which is what stops a token minted for another session being replayed
        // into this one.
        if (!isset($claims['nonce']) || !hash_equals((string)$expectedNonce, (string)$claims['nonce'])) {
            error_log('MicrosoftOAuth: id_token nonce mismatch');
            return null;
        }

        if (empty($claims['oid'])) {
            error_log('MicrosoftOAuth: id_token carries no oid claim');
            return null;
        }

        return $claims;
    }

    /**
     * The email address to match an IMS user on.
     *
     * `email` is an optional claim and is absent unless it has been added in
     * the app registration's token configuration, so fall back to
     * `preferred_username`, which for a work account is the UPN and is in
     * practice the sign-in address. `upn` is the last resort.
     */
    public static function emailFromClaims(array $claims)
    {
        foreach (['email', 'preferred_username', 'upn'] as $claim) {
            $value = trim((string)($claims[$claim] ?? ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // JWKS
    // -------------------------------------------------------------------------

    /**
     * Resolve a `kid` to an OpenSSL public key resource.
     *
     * Served from a file cache; on a miss (a key rotation, typically) the JWKS
     * is re-fetched once and the cache rewritten, so a rotation self-heals
     * without a deploy.
     */
    private static function publicKeyForKid($kid)
    {
        $keys = self::loadJwks(false);
        $key = self::matchKid($keys, $kid);

        if ($key === null) {
            $keys = self::loadJwks(true); // force refresh, then try once more
            $key = self::matchKid($keys, $kid);
        }

        return $key;
    }

    private static function matchKid($keys, $kid)
    {
        if (!is_array($keys)) {
            return null;
        }

        foreach ($keys as $entry) {
            if (($entry['kid'] ?? '') !== $kid) {
                continue;
            }

            // Prefer the x5c certificate — PHP can read it directly. Fall back
            // to building a key from the raw modulus/exponent for the day
            // Microsoft stops publishing certificates.
            if (!empty($entry['x5c'][0])) {
                $pem = "-----BEGIN CERTIFICATE-----\n"
                    . chunk_split($entry['x5c'][0], 64, "\n")
                    . "-----END CERTIFICATE-----\n";
                $publicKey = openssl_pkey_get_public($pem);
                if ($publicKey !== false) {
                    return $publicKey;
                }
            }

            if (!empty($entry['n']) && !empty($entry['e'])) {
                $pem = self::rsaPemFromModulusExponent(
                    self::base64UrlDecode($entry['n']),
                    self::base64UrlDecode($entry['e'])
                );
                $publicKey = $pem !== null ? openssl_pkey_get_public($pem) : false;
                if ($publicKey !== false) {
                    return $publicKey;
                }
            }
        }

        return null;
    }

    /**
     * @return array|null The `keys` array from the JWKS document.
     */
    private static function loadJwks($forceRefresh)
    {
        $cacheFile = __DIR__ . '/../../logs/.ms_jwks_cache.json';

        if (!$forceRefresh && is_readable($cacheFile)) {
            $age = time() - (int)@filemtime($cacheFile);
            if ($age >= 0 && $age < self::JWKS_CACHE_TTL) {
                $cached = json_decode((string)@file_get_contents($cacheFile), true);
                if (isset($cached['keys']) && is_array($cached['keys'])) {
                    return $cached['keys'];
                }
            }
        }

        $response = self::httpGet(self::jwksEndpoint());
        if ($response !== null) {
            list($status, $payload) = $response;
            $decoded = json_decode($payload, true);
            if ($status === 200 && isset($decoded['keys']) && is_array($decoded['keys'])) {
                // Cache write is best-effort: an unwritable logs/ must slow the
                // flow down, never break it.
                @file_put_contents($cacheFile, $payload, LOCK_EX);
                return $decoded['keys'];
            }
            error_log('MicrosoftOAuth: JWKS fetch returned HTTP ' . $status);
        }

        // Network trouble — a stale cache is far better than refusing every
        // login, and the signature check is just as strong with an old key.
        if (is_readable($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (isset($cached['keys']) && is_array($cached['keys'])) {
                error_log('MicrosoftOAuth: falling back to stale JWKS cache');
                return $cached['keys'];
            }
        }

        return null;
    }

    /**
     * Build a PEM public key from a raw RSA modulus and exponent.
     * Only used if a JWKS entry ever arrives without an x5c certificate.
     */
    private static function rsaPemFromModulusExponent($modulus, $exponent)
    {
        if ($modulus === '' || $exponent === '') {
            return null;
        }

        $components =
            self::derSequence(
                self::derInteger($modulus) . self::derInteger($exponent)
            );

        // SubjectPublicKeyInfo wrapper: AlgorithmIdentifier(rsaEncryption, NULL)
        // followed by the BIT STRING holding the RSAPublicKey above.
        $algorithmIdentifier = self::derSequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"
        );
        $bitString = self::derLengthWrap("\x03", "\x00" . $components);
        $der = self::derSequence($algorithmIdentifier . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger($bytes)
    {
        // A leading bit of 1 would read as a negative integer; pad it.
        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }
        return self::derLengthWrap("\x02", $bytes);
    }

    private static function derSequence($contents)
    {
        return self::derLengthWrap("\x30", $contents);
    }

    private static function derLengthWrap($tag, $contents)
    {
        $length = strlen($contents);

        if ($length < 0x80) {
            $encodedLength = chr($length);
        } else {
            $bytes = ltrim(pack('N', $length), "\x00");
            $encodedLength = chr(0x80 | strlen($bytes)) . $bytes;
        }

        return $tag . $encodedLength . $contents;
    }

    // -------------------------------------------------------------------------
    // Plumbing
    // -------------------------------------------------------------------------

    /**
     * @return array|null [httpStatus, body], or null if the request could not
     *                    be made at all.
     */
    private static function httpPost($url, $body)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
            // Non-negotiable: this channel carries the client secret.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return self::executeCurl($ch, $url);
    }

    private static function httpGet($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return self::executeCurl($ch, $url);
    }

    private static function executeCurl($ch, $url)
    {
        $payload = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($payload === false) {
            // Host only — the token endpoint URL carries the tenant id.
            error_log('MicrosoftOAuth: request to ' . parse_url($url, PHP_URL_HOST)
                . ' failed: ' . $error);
            return null;
        }

        return [$status, $payload];
    }

    public static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode($data)
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}

