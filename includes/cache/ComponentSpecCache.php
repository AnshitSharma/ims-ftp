<?php
require_once __DIR__ . '/CacheInterface.php';

/**
 * Component Spec Cache
 *
 * Caches component specifications loaded from JSON files.
 * Component specs NEVER change during runtime, so:
 * - TTL = 0 (no expiration)
 * - Cache cleared only on application restart
 *
 * Cache Key Format: "spec:{component_type}:{uuid}"
 * Cache Key Format: "spec:all:{component_type}" (all specs for type)
 *
 * Example Keys:
 * - spec:cpu:INTEL-XEON-GOLD-6338
 * - spec:ram:SAMSUNG-DDR4-32GB-3200
 * - spec:all:motherboard
 */
class ComponentSpecCache implements CacheInterface {

    /** @var array In-memory cache storage */
    private array $cache = [];

    /** @var array Cache statistics */
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0
    ];

    /**
     * Store component spec in cache
     *
     * LOGIC:
     * 1. Validate key format
     * 2. Serialize value
     * 3. Store in cache
     * 4. Update stats
     *
     * NOTE: No TTL for component specs (never expire)
     *
     * @param string $key Cache key
     * @param mixed $value Component spec data
     * @param int $ttl Ignored (specs never expire)
     * @return bool Always true
     */
    public function set(string $key, $value, int $ttl = 0): bool {
        $this->cache[$key] = serialize($value);
        $this->stats['sets']++;
        return true;
    }

    /**
     * Retrieve component spec from cache
     *
     * LOGIC:
     * 1. Check if key exists
     * 2. If exists: increment hits, unserialize, return
     * 3. If not exists: increment misses, return default
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed Cached value or default
     */
    public function get(string $key, $default = null) {
        if (!isset($this->cache[$key])) {
            $this->stats['misses']++;
            return $default;
        }

        $this->stats['hits']++;
        return unserialize($this->cache[$key]);
    }

    /**
     * Check if key exists
     *
     * @param string $key Cache key
     * @return bool True if exists
     */
    public function has(string $key): bool {
        return isset($this->cache[$key]);
    }

    /**
     * Delete specific key
     *
     * @param string $key Cache key
     * @return bool True if deleted, false if didn't exist
     */
    public function delete(string $key): bool {
        if (!isset($this->cache[$key])) {
            return false;
        }

        unset($this->cache[$key]);
        return true;
    }

    /**
     * Clear entire cache
     *
     * @return bool Always true
     */
    public function clear(): bool {
        $this->cache = [];
        return true;
    }

    /**
     * Delete keys matching pattern
     *
     * @param string $pattern Pattern with wildcards
     * @return int Number of keys deleted
     */
    public function deletePattern(string $pattern): int {
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
        $total = $this->stats['hits'] + $this->stats['misses'];
        $hitRate = $total > 0 ? ($this->stats['hits'] / $total) * 100 : 0.0;

        return [
            'hits' => $this->stats['hits'],
            'misses' => $this->stats['misses'],
            'sets' => $this->stats['sets'],
            'size' => count($this->cache),
            'hit_rate' => $hitRate
        ];
    }

    /**
     * Get component spec by type and UUID
     *
     * HELPER METHOD - DOMAIN-SPECIFIC
     *
     * @param string $componentType Component type (cpu, ram, storage, etc.)
     * @param string $uuid Component UUID
     * @return array|null Component spec or null if not found
     */
    public function getComponentSpec(string $componentType, string $uuid): ?array {
        return $this->get("spec:{$componentType}:{$uuid}");
    }

    /**
     * Store component spec by type and UUID
     *
     * HELPER METHOD - DOMAIN-SPECIFIC
     *
     * @param string $componentType Component type
     * @param string $uuid Component UUID
     * @param array $specData Component specification data
     * @return bool True if stored
     */
    public function setComponentSpec(string $componentType, string $uuid, array $specData): bool {
        return $this->set("spec:{$componentType}:{$uuid}", $specData);
    }

    /**
     * Get all specs for a component type
     *
     * HELPER METHOD - DOMAIN-SPECIFIC
     *
     * @param string $componentType Component type
     * @return array|null Array of specs or null if not found
     */
    public function getAllSpecsForType(string $componentType): ?array {
        return $this->get("spec:all:{$componentType}");
    }

    /**
     * Store all specs for a component type
     *
     * HELPER METHOD - DOMAIN-SPECIFIC
     *
     * @param string $componentType Component type
     * @param array $specs Array of all specs for type
     * @return bool True if stored
     */
    public function setAllSpecsForType(string $componentType, array $specs): bool {
        return $this->set("spec:all:{$componentType}", $specs);
    }

    /**
     * Invalidate all specs for a component type
     *
     * HELPER METHOD - DOMAIN-SPECIFIC
     * Called when JSON files are updated
     *
     * @param string $componentType Component type
     * @return void
     */
    public function invalidateComponentType(string $componentType): void {
        $this->deletePattern("spec:{$componentType}:*");
        $this->delete("spec:all:{$componentType}");
    }
}
