<?php
/**
 * PlatformSpecIndex
 *
 * The board and chassis that come INSIDE a server compute platform are described in
 * ims-data/serverplatform/server-platform-level-3.json, not in motherboard-level-3.json
 * or chasis-level-3.json, and they have no inventory row of their own -- the stocked
 * physical unit is the platform box. They still have to resolve under the types
 * 'motherboard' and 'chassis', because that is what server_configurations stamps and what
 * the whole compatibility engine reads.
 *
 * WHY THIS IS ITS OWN CLASS (2026-08-25)
 *
 *   There are TWO independent component-spec resolvers in this codebase:
 *   ComponentDataService::findComponentByUuid() and the private
 *   DataExtractionUtilities::findComponentByUuid(), each with its own cache. The platform
 *   rebuild taught only the first one about platform-owned specs, and every validation
 *   rule resolves through the second (ResourceCatalog, CpuSocketMatchRule, ... all reach
 *   it via $this->dataUtils). The result was that adding ANY component to a platform build
 *   died with "Motherboard spec not found for UUID ..." while the same board model added
 *   as a loose spare validated fine.
 *
 *   Copying the index into the second resolver would have fixed that symptom and
 *   guaranteed the same divergence the next time either side changed. One implementation,
 *   two consumers, is the actual fix.
 *
 * Deliberately NOT merged into either resolver's per-type cache: ComponentDataService's
 * loadJsonData() round-trips its array through ComponentSpecCache, so a merge would
 * persist platform data into the motherboard/chassis file cache and leak it into every
 * later reader of those files.
 */

require_once __DIR__ . '/ComponentSpecPaths.php';

class PlatformSpecIndex
{
    /**
     * @var array{motherboard: array<string,array>, chassis: array<string,array>}|null
     *      Memoized for the life of the request. Both resolvers share it.
     */
    private static $index = null;

    /** Which key inside a version body holds the spec for each component type. */
    const SOURCE_KEYS = ['motherboard' => 'system_board', 'chassis' => 'chassis'];

    /**
     * The platform-owned spec for this UUID, or null when the UUID is not one (which is
     * the ordinary case -- every loose spare falls through here to its own catalog).
     *
     * @param string $componentType only 'motherboard' and 'chassis' can be platform-owned
     * @param string $uuid
     * @return array|null
     */
    public static function find($componentType, $uuid)
    {
        if ($componentType !== 'motherboard' && $componentType !== 'chassis') {
            return null;
        }
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $index = self::load();
        return $index[$componentType][$uuid] ?? null;
    }

    /**
     * @return array{motherboard: array<string,array>, chassis: array<string,array>}
     */
    public static function load()
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = ['motherboard' => [], 'chassis' => []];

        $platforms = self::readCatalog();
        if ($platforms === null) {
            // No readable platform catalog is a VALID state (fresh install, or the file
            // not yet deployed). Memoize the empty index so this is attempted once per
            // request rather than once per lookup, and so a platform build simply finds
            // nothing instead of throwing inside somebody's transaction.
            self::$index = $index;
            return $index;
        }

        foreach ((array)$platforms as $platform) {
            foreach ($platform['models'] ?? [] as $version) {
                foreach (self::SOURCE_KEYS as $type => $key) {
                    $spec = $version[$key] ?? null;
                    $specUuid = is_array($spec) ? ($spec['uuid'] ?? null) : null;
                    if ($specUuid === null) {
                        continue;
                    }
                    $index[$type][$specUuid] = array_merge($spec, [
                        'uuid' => $specUuid,
                        'component_type' => $type,
                        // Provenance: a caller that renders this spec should be able to
                        // say which product it came out of.
                        'platform_uuid' => $platform['platform_uuid'] ?? null,
                        'platform_name' => $platform['family'] ?? null,
                        'platform_version_uuid' => $version['uuid'] ?? null,
                        'platform_owned' => true,
                    ]);
                }
            }
        }

        self::$index = $index;
        return $index;
    }

    /**
     * Read the platform catalog straight off disk.
     *
     * Deliberately does NOT go through either resolver's loadJsonData(): this class is
     * consumed by both of them, and borrowing one's loader would make the other's
     * behaviour depend on which resolver happened to warm the cache first.
     *
     * @return array|null null when the catalog is absent or unreadable
     */
    private static function readCatalog()
    {
        try {
            $path = ComponentSpecPaths::getPath('serverplatform');
        } catch (\Throwable $e) {
            error_log('PlatformSpecIndex: no path for serverplatform: ' . $e->getMessage());
            return null;
        }

        if (!$path || !file_exists($path)) {
            error_log('PlatformSpecIndex: platform catalog not found at ' . (string)$path);
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            error_log('PlatformSpecIndex: platform catalog unreadable at ' . $path);
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            error_log('PlatformSpecIndex: platform catalog is not valid JSON: ' . json_last_error_msg());
            return null;
        }

        return $data;
    }

    /** Drop the memo. For tests and long-running scripts that rewrite the catalog. */
    public static function clearCache()
    {
        self::$index = null;
    }
}
