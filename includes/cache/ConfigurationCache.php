<?php
require_once __DIR__ . '/CacheInterface.php';

/**
 * Configuration Cache
 *
 * Caches server configuration data loaded from database.
 * Eliminates 7-15 repeated queries per validation request.
 *
 * Cache Key Format: "config:{config_uuid}"
 * Cache Key Format: "config:full:{config_uuid}" (full configuration)
 *
 * TTL: 300 seconds (5 minutes) by default
 */
class ConfigurationCache implements CacheInterface {

    /** @var array In-memory cache storage */
    private array $cache = [];

    /** @var array Cache statistics */
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0
    ];

    /** @var int Default TTL in seconds */
    private int $defaultTtl = 300; // 5 minutes

    /** @var array TTL tracking [key => expiration_timestamp] */
    private array $expirations = [];

    /**
     * Constructor
     *
     * @param int $defaultTtl Default TTL for cached items (seconds)
     */
    public function __construct(int $defaultTtl = 300) {
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * Store configuration in cache
     *
     * LOGIC:
     * 1. Clean expired entries before storing
     * 2. Serialize value for storage
     * 3. Calculate expiration timestamp
     * 4. Store in cache array
     * 5. Store expiration in expirations array
     * 6. Increment stats
     *
     * @param string $key Cache key
     * @param mixed $value Configuration data
     * @param int $ttl Time to live (0 = use default)
     * @return bool Always true
     */
    public function set(string $key, $value, int $ttl = 0): bool {
        // Clean expired entries
        $this->cleanExpired();

        // Use default TTL if not specified
        if ($ttl === 0) {
            $ttl = $this->defaultTtl;
        }

        // Store serialized value
        $this->cache[$key] = serialize($value);

        // Calculate expiration timestamp
        $this->expirations[$key] = time() + $ttl;

        // Update stats
        $this->stats['sets']++;

        return true;
    }

    /**
     * Retrieve configuration from cache
     *
     * LOGIC:
     * 1. Clean expired entries first
     * 2. Check if key exists
     * 3. Check if key has expired
     * 4. If expired: delete and return default
     * 5. If exists: increment hits, unserialize, return
     * 6. If not exists: increment misses, return default
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed Cached value or default
     */
    public function get(string $key, $default = null) {
        // Clean expired entries
        $this->cleanExpired();

        // Check if key exists
        if (!isset($this->cache[$key])) {
            $this->stats['misses']++;
            return $default;
        }

        // Check if expired
        if (isset($this->expirations[$key]) && $this->expirations[$key] <= time()) {
            $this->delete($key);
            $this->stats['misses']++;
            return $default;
        }

        // Cache hit
        $this->stats['hits']++;
        return unserialize($this->cache[$key]);
    }

    /**
     * Check if key exists and is not expired
     *
     * @param string $key Cache key
     * @return bool True if exists and valid
     */
    public function has(string $key): bool {
        $this->cleanExpired();

        if (!isset($this->cache[$key])) {
            return false;
        }

        // Check expiration
        if (isset($this->expirations[$key]) && $this->expirations[$key] <= time()) {
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * Delete specific key from cache
     *
     * @param string $key Cache key
     * @return bool True if deleted, false if didn't exist
     */
    public function delete(string $key): bool {
        if (!isset($this->cache[$key])) {
            return false;
        }

        unset($this->cache[$key]);
        unset($this->expirations[$key]);
        $this->stats['deletes']++;

        return true;
    }

    /**
     * Clear entire cache
     *
     * @return bool Always true
     */
    public function clear(): bool {
        $this->cache = [];
        $this->expirations = [];
        return true;
    }

    /**
     * Delete keys matching pattern
     *
     * LOGIC:
     * 1. Convert pattern to regex (config:* → /^config:.*$/)
     * 2. Iterate all keys
     * 3. Match against regex
     * 4. Delete matching keys
     * 5. Return count of deleted keys
     *
     * @param string $pattern Pattern with wildcards (*, ?)
     * @return int Number of keys deleted
     */
    public function deletePattern(string $pattern): int {
        // Convert wildcard pattern to regex
        $regex = '/^' . str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';

        $count = 0;
        foreach (array_keys($this->cache) as $key) {
            if (preg_match($regex, $key)) {
                $this->delete($key);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics array
     */
    public function getStats(): array {
        return [
            'hits' => $this->stats['hits'],
            'misses' => $this->stats['misses'],
            'sets' => $this->stats['sets'],
            'deletes' => $this->stats['deletes'],
            'size' => count($this->cache),
            'hit_rate' => $this->calculateHitRate()
        ];
    }

    /**
     * Clean expired entries from cache
     *
     * PRIVATE HELPER METHOD
     *
     * LOGIC:
     * 1. Get current timestamp
     * 2. Iterate all expirations
     * 3. If expiration <= now: delete key
     *
     * @return void
     */
    private function cleanExpired(): void {
        $now = time();

        foreach ($this->expirations as $key => $expiration) {
            if ($expiration <= $now) {
                $this->delete($key);
            }
        }
    }

    /**
     * Calculate cache hit rate percentage
     *
     * PRIVATE HELPER METHOD
     *
     * @return float Hit rate percentage (0-100)
     */
    private function calculateHitRate(): float {
        $total = $this->stats['hits'] + $this->stats['misses'];

        if ($total === 0) {
            return 0.0;
        }

        return ($this->stats['hits'] / $total) * 100;
    }

    /**
     * Get configuration for specific config UUID
     *
     * HELPER METHOD - DOMAIN-SPECIFIC
     *
     * @param string $configUuid Configuration UUID
     * @return array|null Configuration data or null if not found
     */
    public function getConfiguration(string $configUuid): ?array {
        return $this->get("config:full:{$configUuid}");
    }

    /**
     * Store configuration for specific config UUID
     *
     * HELPER METHOD - DOMAIN-SPECIFIC
     *
     * @param string $configUuid Configuration UUID
     * @param array $configData Configuration data
     * @param int $ttl Time to live (0 = use default)
     * @return bool True if stored
     */
    public function setConfiguration(string $configUuid, array $configData, int $ttl = 0): bool {
        return $this->set("config:full:{$configUuid}", $configData, $ttl);
    }

    /**
     * Invalidate configuration cache for specific UUID
     *
     * HELPER METHOD - DOMAIN-SPECIFIC
     * Called when configuration is modified
     *
     * @param string $configUuid Configuration UUID
     * @return void
     */
    public function invalidateConfiguration(string $configUuid): void {
        $this->deletePattern("config:*:{$configUuid}");
        $this->deletePattern("config:{$configUuid}*");
    }
}
