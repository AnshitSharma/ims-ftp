<?php
/**
 * _http_harness.php — shared scratch-only HTTP harness connect helper for
 * the tests/api/*_test.php DB-backed acceptance criteria (golden response-
 * shape, real 409, serial-targeted remove, replace/transition happy+blocked
 * over real HTTP). Not a production file (tests/ is never deployed, per
 * CLAUDE.md) and not itself a test.
 *
 * The harness process itself (PHP's built-in server, `php -S ... -t
 * <scratch tree root>`) must be started SEPARATELY, by hand, rooted at a
 * SCRATCH tree copy only (e.g. C:\tmp\ims-ftp-scratch) with
 * COMMAND_LAYER_ENABLED set as a process environment variable for that one
 * server process ONLY — never written into any .env file, scratch or
 * production. This file only talks to whatever URL IMS_HTTP_HARNESS_URL
 * points at; it never starts, stops, or otherwise manages that process, and
 * it has no opinion about what tree the URL is rooted at (do not point this
 * at a production URL — nothing here stops you, the same way scratch_db_
 * connect() doesn't stop you pointing GOLDEN_DB_HOST at production).
 *
 * Same self-skip convention as _scratch_db.php: returns null (never throws)
 * when IMS_HTTP_HARNESS_URL is unset or the harness isn't reachable/login
 * fails, so callers print their own SKIPPED lines and exit 0 rather than
 * fail the whole suite.
 */

final class HttpHarness
{
    private string $baseUrl;
    private string $token;

    private function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = $baseUrl;
        $this->token = $token;
    }

    public static function connect(): ?self
    {
        $url = getenv('IMS_HTTP_HARNESS_URL');
        if (!is_string($url) || $url === '') {
            return null;
        }
        $username = getenv('IMS_HTTP_HARNESS_USER') ?: 'superadmin';
        $password = getenv('IMS_HTTP_HARNESS_PASS') ?: 'password';

        [$code, , $body] = self::rawPost($url, ['action' => 'auth-login', 'username' => $username, 'password' => $password], null);
        if ($code !== 200 || !is_array($body) || ($body['success'] ?? false) !== true) {
            return null;
        }
        $token = $body['data']['tokens']['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }
        return new self($url, $token);
    }

    /**
     * @return array{0:int,1:?array} [http status code, decoded JSON body or null]
     */
    public function post(string $action, array $params = [], bool $auth = true): array
    {
        [$code, , $body] = self::rawPost($this->baseUrl, array_merge(['action' => $action], $params), $auth ? $this->token : null);
        return [$code, $body];
    }

    /**
     * Same as post() but also returns raw response headers (lowercased
     * header-name => value), for asserting on things like X-IMS-Deprecation
     * that never appear in the JSON body.
     * @return array{0:int,1:array<string,string>,2:?array}
     */
    public function postWithHeaders(string $action, array $params = [], bool $auth = true): array
    {
        return self::rawPost($this->baseUrl, array_merge(['action' => $action], $params), $auth ? $this->token : null);
    }

    /**
     * @return array{0:int,1:array<string,string>,2:?array}
     */
    private static function rawPost(string $url, array $fields, ?string $token): array
    {
        $ch = curl_init($url);
        $headers = [];
        if ($token !== null) {
            $headers[] = "Authorization: Bearer $token";
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$respHeaders) {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return [$code, $respHeaders, is_array($decoded) ? $decoded : null];
    }
}
