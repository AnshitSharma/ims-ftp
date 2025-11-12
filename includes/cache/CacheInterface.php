<?php
/**
 * Cache Interface
 *
 * Defines contract for caching implementations.
 * All cache classes must implement this interface.
 */
interface CacheInterface {

    /**
     * Store a value in cache with optional TTL
     *
     * @param string $key Cache key (must be unique)
     * @param mixed $value Value to cache (will be serialized)
     * @param int $ttl Time to live in seconds (0 = no expiration)
     * @return bool True if stored successfully, false otherwise
     */
    public function set(string $key, $value, int $ttl = 0): bool;

    /**
     * Retrieve a value from cache
     *
     * @param string $key Cache key
     * @param mixed $default Default value if key not found
     * @return mixed Cached value or $default if not found
     */
    public function get(string $key, $default = null);

    /**
     * Check if key exists in cache
     *
     * @param string $key Cache key
     * @return bool True if key exists, false otherwise
     */
    public function has(string $key): bool;

    /**
     * Delete a specific key from cache
     *
     * @param string $key Cache key to delete
     * @return bool True if deleted, false if key didn't exist
     */
    public function delete(string $key): bool;

    /**
     * Clear all cached values
     *
     * @return bool True if cleared successfully
     */
    public function clear(): bool;

    /**
     * Delete multiple keys matching pattern
     *
     * @param string $pattern Pattern to match (e.g., "config:*")
     * @return int Number of keys deleted
     */
    public function deletePattern(string $pattern): int;

    /**
     * Get cache statistics
     *
     * @return array ['hits' => int, 'misses' => int, 'size' => int]
     */
    public function getStats(): array;
}
