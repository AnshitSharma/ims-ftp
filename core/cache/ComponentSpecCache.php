<?php
/**
 * Cross-request cache for the ims-data spec catalogue.  (JSON-001..022 audit, JSON-002)
 *
 * WHY THIS FILE EXISTS AT THIS PATH
 *   ComponentDataService::__construct() has always probed core/cache/ComponentSpecCache.php
 *   and fallen back to `$this->specCache = null` when it was missing.  The class it wanted
 *   lived at includes/cache/ComponentSpecCache.php and was deleted in b706f50
 *   ("Remove unused validation and cache layers", 2025-11-14) -- the probe path and the real
 *   path never agreed, so the cache has been permanently null ever since and every fresh PHP
 *   request re-read and re-decoded all twelve catalogue files.  Measured 2026-09-15: 11.3 ms
 *   and 2.2 MB of PHP memory per request, from 536 KB on disk.
 *
 *   This file satisfies that existing probe.  Nothing else had to change.
 *
 * INTERFACE -- exactly the two methods ComponentDataService calls, nothing more:
 *   getAllSpecsForType(string $type): ?array   cached decoded catalogue, or null to go to disk
 *   setAllSpecsForType(string $type, array $d) best-effort store
 *
 * INVARIANT: RAW FILE CONTENTS ONLY.
 *   ComponentDataService::loadJsonData() round-trips its array through this cache, and both
 *   that class and PlatformSpecIndex document that platform-owned board/chassis specs are
 *   deliberately kept in a SEPARATE index rather than merged into $jsonCache[$type], precisely
 *   so a merge cannot be persisted here and leak into every later reader of the motherboard
 *   and chassis files.  Keep it that way: what goes in must be exactly what json_decode
 *   returned for one file.
 *
 * INVALIDATION IS BY SOURCE STAMP, NOT BY TTL.
 *   ims-data has no deploy watcher -- spec files are uploaded to /ims-data/ by hand.  A TTL
 *   alone would serve a stale catalogue for up to its length after such an upload, which is
 *   exactly the kind of surprise this system does not need.  The cache key carries the source
 *   file's mtime and size, so a hand-uploaded file misses the cache on the very next request
 *   and no manual flush is ever required.  The TTL is only a floor sweep for entries whose
 *   source has since been renamed away.
 *
 * WHY serialize() AND NOT GENERATED PHP ARRAYS.
 *   The obvious "opcache-friendly" answer is to var_export() the catalogue into PHP files and
 *   include() them.  Measured on this catalogue (best of 5, warm OS cache, PHP 7.4 CLI,
 *   opcache off -- 2026-09-16):
 *
 *       raw json_decode of all 12 files   10.8 ms   536 KB on disk
 *       unserialize of all 12              3.9 ms   415 KB
 *       json_decode of minified JSON       6.9 ms   300 KB
 *       include of var_export'd PHP       12.6 ms   635 KB   <-- SLOWER than parsing
 *
 *   Without opcache the generated-PHP route is the worst of the four, and whether opcache is
 *   on for this SAPI is not something this class can know.  serialize() wins unconditionally,
 *   so that is what the file tier uses.  Re-measure before changing this.
 *
 * FAILS OPEN, ALWAYS.
 *   Every path returns null rather than throwing.  A missing APCu, an unwritable logs/, a
 *   truncated cache file or a corrupt payload must all degrade to "parse the JSON like we did
 *   yesterday" -- never to a broken API.  Same posture as the JWKS file cache in
 *   MicrosoftOAuth::loadJwks(), which this is modelled on.
 */

require_once __DIR__ . '/../models/components/ComponentSpecPaths.php';

class ComponentSpecCache
{
    /** Floor sweep for orphaned entries; the source stamp is the real invalidator. */
    private const TTL = 86400;

    /** Bumped only if the stored payload shape changes, so old files are ignored, not read. */
    private const FORMAT = 1;

    /** @var string Directory for the file tier. Shares logs/ with the JWKS cache. */
    private $dir;

    /** @var bool Whether the APCu tier is usable in this SAPI. */
    private $apcu;

    public function __construct()
    {
        $this->dir = __DIR__ . '/../../logs';
        $this->apcu = function_exists('apcu_enabled') && @apcu_enabled();
    }

    /**
     * @return array|null The decoded catalogue for $componentType, or null to load from disk.
     */
    public function getAllSpecsForType($componentType)
    {
        $stamp = $this->sourceStamp($componentType);
        if ($stamp === null) {
            return null;
        }

        if ($this->apcu) {
            $hit = @apcu_fetch($this->apcuKey($componentType, $stamp), $ok);
            if ($ok && is_array($hit)) {
                return $hit;
            }
        }

        $file = $this->cacheFile($componentType);
        if (!is_readable($file)) {
            return null;
        }

        $age = time() - (int)@filemtime($file);
        if ($age < 0 || $age >= self::TTL) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        // unserialize() of our own payload; allowed_classes:false keeps it to plain arrays
        // and scalars even if the file is ever tampered with.
        $payload = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($payload)
            || ($payload['format'] ?? null) !== self::FORMAT
            || ($payload['stamp'] ?? null) !== $stamp
            || !isset($payload['data']) || !is_array($payload['data'])) {
            return null;
        }

        // Promote into APCu so the rest of this worker's requests skip the file read too.
        if ($this->apcu) {
            @apcu_store($this->apcuKey($componentType, $stamp), $payload['data'], self::TTL);
        }

        return $payload['data'];
    }

    /**
     * Best-effort store. Never throws, never reports failure -- a cache that cannot be
     * written is a slow request, not a failed one.
     *
     * @param string $componentType
     * @param array  $data Exactly what json_decode() returned for this type's file.
     */
    public function setAllSpecsForType($componentType, $data)
    {
        if (!is_array($data) || $data === []) {
            return;
        }

        $stamp = $this->sourceStamp($componentType);
        if ($stamp === null) {
            return;
        }

        if ($this->apcu) {
            @apcu_store($this->apcuKey($componentType, $stamp), $data, self::TTL);
        }

        if (!is_dir($this->dir) || !is_writable($this->dir)) {
            return;
        }

        $payload = serialize([
            'format' => self::FORMAT,
            'stamp'  => $stamp,
            'data'   => $data,
        ]);

        // Write to a per-process temp file and rename, so a concurrent reader can never see
        // a half-written payload. LOCK_EX alone does not give that on every filesystem.
        $file = $this->cacheFile($componentType);
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    /**
     * mtime+size of the source JSON, or null if the type or file cannot be resolved.
     * Two files differing in neither are the same catalogue for our purposes.
     */
    private function sourceStamp($componentType)
    {
        if (!is_string($componentType) || $componentType === '') {
            return null;
        }

        try {
            $path = ComponentSpecPaths::getPath($componentType);
        } catch (Throwable $e) {
            // Unsupported type, or ims-data not resolvable. Caller's own error handling owns
            // that; the cache just declines.
            return null;
        }

        if (!is_readable($path)) {
            return null;
        }

        $mtime = @filemtime($path);
        $size  = @filesize($path);
        if ($mtime === false || $size === false) {
            return null;
        }

        return $mtime . '-' . $size;
    }

    private function apcuKey($componentType, $stamp)
    {
        return 'ims_spec_' . $componentType . '_' . self::FORMAT . '_' . $stamp;
    }

    private function cacheFile($componentType)
    {
        // Type names come from a fixed whitelist in ComponentSpecPaths, but this is a
        // filename -- pin it to [a-z0-9] rather than trusting the caller.
        $safe = preg_replace('/[^a-z0-9]/', '', strtolower((string)$componentType));
        return $this->dir . '/.spec_cache_' . $safe . '.ser';
    }
}
