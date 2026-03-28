<?php
/**
 * RateLimiter.php
 *
 * Simple file-based rate limiter for auth endpoints.
 * Stores attempt counts in the logs/rate_limits/ directory.
 */

class RateLimiter {
    private string $storageDir;

    public function __construct() {
        $this->storageDir = __DIR__ . '/../../logs/rate_limits';
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Check if an action is rate-limited.
     *
     * @param string $key Unique key (e.g., IP address or "login:{ip}")
     * @param int $maxAttempts Max allowed attempts in the window
     * @param int $windowSeconds Time window in seconds
     * @return bool True if allowed, false if rate-limited
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): bool {
        $file = $this->getFilePath($key);
        $now = time();

        $attempts = $this->loadAttempts($file, $now, $windowSeconds);

        if (count($attempts) >= $maxAttempts) {
            return false;
        }

        $attempts[] = $now;
        $this->saveAttempts($file, $attempts);

        return true;
    }

    /**
     * Get remaining attempts for a key.
     */
    public function remaining(string $key, int $maxAttempts, int $windowSeconds): int {
        $file = $this->getFilePath($key);
        $attempts = $this->loadAttempts($file, time(), $windowSeconds);
        return max(0, $maxAttempts - count($attempts));
    }

    /**
     * Clean up expired rate limit files (call periodically).
     */
    public function cleanup(int $maxAgeSeconds = 3600): void {
        if (!is_dir($this->storageDir)) return;

        $files = glob($this->storageDir . '/*.json');
        $now = time();
        foreach ($files as $file) {
            if ($now - filemtime($file) > $maxAgeSeconds) {
                @unlink($file);
            }
        }
    }

    private function getFilePath(string $key): string {
        return $this->storageDir . '/' . md5($key) . '.json';
    }

    private function loadAttempts(string $file, int $now, int $windowSeconds): array {
        if (!file_exists($file)) {
            return [];
        }

        $data = @file_get_contents($file);
        if ($data === false) return [];

        $attempts = json_decode($data, true);
        if (!is_array($attempts)) return [];

        // Filter to only attempts within the window
        $cutoff = $now - $windowSeconds;
        return array_values(array_filter($attempts, fn($t) => $t > $cutoff));
    }

    private function saveAttempts(string $file, array $attempts): void {
        @file_put_contents($file, json_encode($attempts), LOCK_EX);
    }
}
