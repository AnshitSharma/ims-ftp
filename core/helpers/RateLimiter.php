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
        $allowed = false;

        // The count-check and the increment must be one atomic step. Splitting them
        // (load -> count -> save) let concurrent requests all read the same
        // pre-increment array, all pass the limit check, and then overwrite each
        // other's increments -- so a throttle of N admitted far more than N under
        // parallel load. Holding LOCK_EX across the whole read-modify-write is what
        // makes the limit a limit.
        $this->withExclusiveLock($file, function (array $attempts) use ($maxAttempts, $windowSeconds, $now, &$allowed) {
            $attempts = $this->pruneWindow($attempts, $now, $windowSeconds);

            if (count($attempts) >= $maxAttempts) {
                $allowed = false;
                return null; // over limit: nothing to persist
            }

            $attempts[] = $now;
            $allowed = true;
            return $attempts;
        });

        return $allowed;
    }

    /**
     * Check whether a key has exhausted its attempts WITHOUT recording one.
     * Use together with hit()/clear() when only failures should count
     * (e.g. per-username login throttling).
     */
    public function tooManyAttempts(string $key, int $maxAttempts, int $windowSeconds): bool {
        $file = $this->getFilePath($key);
        return count($this->loadAttempts($file, time(), $windowSeconds)) >= $maxAttempts;
    }

    /**
     * Record one attempt for a key (counterpart of tooManyAttempts).
     */
    public function hit(string $key, int $windowSeconds): void {
        $file = $this->getFilePath($key);
        $now = time();

        // Same atomicity requirement as attempt(): two concurrent failed logins must
        // record two hits, not one.
        $this->withExclusiveLock($file, function (array $attempts) use ($now, $windowSeconds) {
            $attempts = $this->pruneWindow($attempts, $now, $windowSeconds);
            $attempts[] = $now;
            return $attempts;
        });
    }

    /**
     * Reset the counter for a key (e.g. after a successful login).
     */
    public function clear(string $key): void {
        @unlink($this->getFilePath($key));
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

    /**
     * Read the counter under a SHARED lock.
     *
     * The shared lock matters as much as the exclusive one: a plain read racing a
     * writer could observe a partially-written file, json_decode would fail, and
     * the `!is_array` guard below would quietly return [] -- resetting the counter
     * to zero and handing the caller a clean slate. A silent throttle reset is a
     * worse failure than a slightly stale read.
     */
    private function loadAttempts(string $file, int $now, int $windowSeconds): array {
        if (!file_exists($file)) {
            return [];
        }

        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                error_log("RateLimiter: could not acquire shared lock on $file");
                return [];
            }
            $data = stream_get_contents($handle);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }

        if ($data === false || $data === '') {
            return [];
        }

        $attempts = json_decode($data, true);
        if (!is_array($attempts)) {
            return [];
        }

        return $this->pruneWindow($attempts, $now, $windowSeconds);
    }

    /**
     * Drop timestamps that have aged out of the window.
     */
    private function pruneWindow(array $attempts, int $now, int $windowSeconds): array {
        $cutoff = $now - $windowSeconds;
        $kept = [];
        foreach ($attempts as $t) {
            if (is_numeric($t) && (int)$t > $cutoff) {
                $kept[] = (int)$t;
            }
        }
        return $kept;
    }

    /**
     * Run $mutator against the counter while holding an exclusive lock for the whole
     * read-modify-write.
     *
     * $mutator receives the decoded (pre-prune) attempt list and returns either the
     * array to persist, or null to leave the file untouched.
     *
     * Opened with 'c+' so the file is created when absent but NEVER truncated at
     * open time. `file_put_contents(..., LOCK_EX)` -- which this replaces -- opens
     * with 'w', truncating before the lock is taken, which is exactly how a
     * concurrent reader could see an empty file.
     */
    private function withExclusiveLock(string $file, callable $mutator): void {
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            error_log("RateLimiter: could not open $file for update");
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                error_log("RateLimiter: could not acquire exclusive lock on $file");
                return;
            }

            $raw = stream_get_contents($handle);
            $attempts = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
            if (!is_array($attempts)) {
                $attempts = [];
            }

            $updated = $mutator($attempts);
            if (!is_array($updated)) {
                return; // mutator declined to write
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode(array_values($updated)));
            fflush($handle);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}
