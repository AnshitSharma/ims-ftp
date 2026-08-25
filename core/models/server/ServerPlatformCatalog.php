<?php
/**
 * ServerPlatformCatalog — server compute platforms, their versions, and what is in stock.
 *
 * A "platform" is a shipped server product (HPE ProLiant DL360 Gen9, Dell PowerEdge
 * R740). It is a PHYSICAL BOX WE STOCK, not a grouping over the motherboard catalog:
 * `serverplatforminventory` holds one row per box, exactly like every other component
 * type. The system board and the chassis live INSIDE that box — they are described in
 * the platform spec file and are NOT the loose `motherboard` / `chassis` spares of the
 * same model, which stay separately stocked for custom builds.
 *
 * A platform ships in VERSIONS: the same product built around a different chassis, and
 * therefore a different drive-bay layout (8 x 2.5" SFF vs 4 x 3.5" LFF). The version is
 * the stocked SKU — `serverplatforminventory.UUID` is a version UUID, never a platform
 * UUID. Stock is counted per version, and it is a version the user installs.
 *
 * A version is `selectable` only when everything it installs is on the shelf: the box
 * itself, and the `included_nic` card if it names one. A version that is not selectable
 * is still RETURNED, carrying `unavailable_reason` — a version that vanished would read
 * as "this platform has fewer versions", which is a different and wrong statement.
 *
 * Source of truth: ims-data/serverplatform/server-platform-level-3.json
 * (brand-group = platform, models[] entry = version; see ims-data/CLAUDE.md).
 */

require_once __DIR__ . '/../components/ComponentSpecPaths.php';
require_once __DIR__ . '/../components/ComponentDataService.php';

class ServerPlatformCatalog
{
    private $pdo;

    /** Request-level caches — the spec file is read once per request at most. */
    private static $platforms = null;
    private static $unitCounts = [];

    public function __construct($pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Every platform with its versions, each annotated with stock and selectability.
     *
     * @return array
     */
    public function listPlatforms()
    {
        $platforms = $this->loadPlatforms();
        $platformUnits = $this->availableUnits('serverplatform');
        $nicUnits = $this->availableUnits('nic');

        $out = [];
        foreach ($platforms as $platform) {
            $versions = [];
            foreach ($platform['models'] ?? [] as $version) {
                $versions[] = $this->describeVersion($version, $platformUnits, $nicUnits);
            }

            $out[] = [
                'platform_uuid'   => $platform['platform_uuid'] ?? null,
                'brand'           => $platform['brand'] ?? null,
                'family'          => $platform['series'] ?? null,
                'platform'        => $platform['family'] ?? null,
                'generation'      => $platform['generation'] ?? null,
                'form_factor'     => $platform['form_factor'] ?? null,
                'versions'        => $versions,
                'version_count'   => count($versions),
                'available_units' => array_sum(array_column($versions, 'available_units')),
            ];
        }

        return $out;
    }

    /**
     * One version by its UUID, with the platform it belongs to.
     *
     * @return array|null ['platform' => raw platform group, 'version' => raw version]
     */
    public function getVersion($versionUuid)
    {
        if (empty($versionUuid)) {
            return null;
        }

        foreach ($this->loadPlatforms() as $platform) {
            foreach ($platform['models'] ?? [] as $version) {
                if (($version['uuid'] ?? null) === $versionUuid) {
                    return ['platform' => $platform, 'version' => $version];
                }
            }
        }

        return null;
    }

    /**
     * The same shape listPlatforms() reports, for a single version.
     *
     * The install handler needs `selectable` and `unavailable_reason` computed the same
     * way the picker computed them — a second, subtly different rule here is how a
     * greyed-out version becomes installable through a hand-crafted request.
     *
     * @return array|null
     */
    public function describeVersionByUuid($versionUuid)
    {
        $found = $this->getVersion($versionUuid);
        if ($found === null) {
            return null;
        }

        return $this->describeVersion(
            $found['version'],
            $this->availableUnits('serverplatform'),
            $this->availableUnits('nic')
        );
    }

    /** "HPE ProLiant DL360 Gen9 - 8SFF", the label stamped on a configuration. */
    public function displayName(array $platform, array $version)
    {
        $parts = array_filter([
            $platform['brand'] ?? null,
            $platform['family'] ?? null,
        ]);
        $name = implode(' ', $parts);

        $versionName = $version['version_name'] ?? null;
        return $versionName ? trim($name . ' - ' . $versionName) : $name;
    }

    /** The board spec a version carries, as the compatibility engine will see it. */
    public function boardSpec(array $version)
    {
        return $version['system_board'] ?? null;
    }

    /** The chassis spec a version carries. */
    public function chassisSpec(array $version)
    {
        return $version['chassis'] ?? null;
    }

    // ---------------------------------------------------------------- internals

    /**
     * Flatten one version for the API: what it is, what it installs, whether it can be.
     */
    private function describeVersion(array $version, array $platformUnits, array $nicUnits)
    {
        $versionUuid = $version['uuid'] ?? null;
        $board = $version['system_board'] ?? [];
        $chassis = $version['chassis'] ?? [];
        $includedNic = $version['included_nic'] ?? null;

        $availableUnits = $versionUuid ? (int)($platformUnits[$versionUuid] ?? 0) : 0;

        $nic = null;
        if (is_array($includedNic) && !empty($includedNic['uuid'])) {
            $nic = [
                'uuid'            => $includedNic['uuid'],
                'model'           => $includedNic['model'] ?? null,
                'available_units' => (int)($nicUnits[$includedNic['uuid']] ?? 0),
            ];
        }

        // Everything the box brings must be on the shelf, or the install would half
        // succeed and leave a build that matches no catalogued product.
        $selectable = true;
        $reason = null;
        if ($versionUuid === null) {
            $selectable = false;
            $reason = 'This version has no UUID in the catalog';
        } elseif ($availableUnits < 1) {
            $selectable = false;
            $reason = 'Out of stock';
        } elseif ($nic !== null && $nic['available_units'] < 1) {
            $selectable = false;
            $reason = 'Included network card is out of stock';
        }

        return [
            'version_uuid'       => $versionUuid,
            'version_name'       => $version['version_name'] ?? null,
            'model'              => $version['model'] ?? null,
            'part_number'        => $version['part_number'] ?? null,
            'bay_summary'        => $version['bay_summary'] ?? $this->baySummary($chassis),
            'available_units'    => $availableUnits,
            'selectable'         => $selectable,
            'unavailable_reason' => $reason,
            'board' => [
                'uuid'         => $board['uuid'] ?? null,
                'model'        => $board['model'] ?? null,
                'socket_type'  => $board['socket']['type'] ?? null,
                'socket_count' => $board['socket']['count'] ?? null,
                'memory_type'  => $board['memory']['type'] ?? null,
                'memory_slots' => $board['memory']['slots'] ?? null,
                'chipset'      => $board['chipset'] ?? null,
                'onboard_nics' => count($board['networking']['onboard_nics'] ?? []),
            ],
            'chassis' => [
                'uuid'        => $chassis['uuid'] ?? null,
                'model'       => $chassis['model'] ?? null,
                'form_factor' => $chassis['form_factor'] ?? null,
                'total_bays'  => $chassis['drive_bays']['total_bays'] ?? null,
            ],
            'included_nic' => $nic,
        ];
    }

    /** '8 x 2.5"' — the fallback when a version carries no precomputed bay_summary. */
    private function baySummary(array $chassis)
    {
        $labels = ['2.5_inch' => '2.5"', '3.5_inch' => '3.5"'];
        $parts = [];

        foreach ($chassis['drive_bays']['bay_configuration'] ?? [] as $bay) {
            $count = $bay['count'] ?? null;
            if (!$count) {
                continue;
            }
            $type = $bay['bay_type'] ?? '';
            $parts[] = $count . ' x ' . ($labels[$type] ?? $type);
        }

        return implode(' + ', $parts);
    }

    /**
     * uuid => available unit count, one grouped query per type, cached per request.
     *
     * The table name is interpolated, so $componentType must come from this class's own
     * fixed call sites — never from the spec file or a request parameter.
     *
     * A type whose inventory table has not been created yet reports zeros rather than
     * throwing: code deploys ~20s after a save while seeders are applied by hand, so
     * serverplatforminventory is legitimately absent for a while.
     */
    private function availableUnits($componentType)
    {
        if (isset(self::$unitCounts[$componentType])) {
            return self::$unitCounts[$componentType];
        }

        $counts = [];
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->query(
                    "SELECT UUID, COUNT(*) AS unit_count
                       FROM {$componentType}inventory
                      WHERE Status = 1
                   GROUP BY UUID"
                );
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $counts[$row['UUID']] = (int)$row['unit_count'];
                }
            } catch (\Throwable $e) {
                error_log("ServerPlatformCatalog: stock lookup failed for {$componentType}: " . $e->getMessage());
            }
        }

        self::$unitCounts[$componentType] = $counts;
        return $counts;
    }

    /**
     * The raw platform catalog.
     *
     * Read through ComponentDataService so it shares the request cache and the spec
     * cache with every other component type — the platform file is a normal spec file
     * now, registered in ComponentSpecPaths::PATHS.
     */
    private function loadPlatforms()
    {
        if (self::$platforms !== null) {
            return self::$platforms;
        }

        try {
            $data = ComponentDataService::getInstance()->loadJsonData('serverplatform');
            self::$platforms = is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            error_log('ServerPlatformCatalog: failed to load platform catalog: ' . $e->getMessage());
            self::$platforms = [];
        }

        return self::$platforms;
    }
}
