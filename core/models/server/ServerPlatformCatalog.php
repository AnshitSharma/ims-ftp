<?php
/**
 * ServerPlatformCatalog — server compute platforms and the system boards they accept.
 *
 * A "platform" is a shipped server product (HPE ProLiant DL360 Gen10, Dell PowerEdge
 * R740), and one platform can be built around several different system boards. The
 * builder lets a user pick the platform first and then the board, instead of scrolling
 * a flat list of every motherboard in stock.
 *
 * This is a GROUPING OVER motherboard specs, not a component type of its own: platforms
 * have no inventory table, no ACL module and no UUID of their own in any inventory row.
 * Every `system_boards[].uuid` must resolve to a real model in
 * `ims-data/motherboard/motherboard-level-3.json` — a board that does not resolve is
 * reported as `spec_exists: false` rather than hidden, because a silently missing board
 * is a data error someone has to see.
 *
 * Source of truth: ims-data/serverplatform/server-platform-level-3.json
 */

require_once __DIR__ . '/../components/ComponentSpecPaths.php';

class ServerPlatformCatalog
{
    private $pdo;

    /** Request-level caches — these files are read once per request at most. */
    private static $platforms = null;
    private static $boardIndex = null;

    public function __construct($pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Every platform, each board annotated with whether its spec resolves and how many
     * units are on the shelf right now.
     *
     * @return array
     */
    public function listPlatforms(): array
    {
        $platforms = $this->loadPlatforms();
        if (empty($platforms)) {
            return [];
        }

        $boardIndex = $this->loadBoardIndex();
        $stock = $this->getAvailableUnitCounts();

        $result = [];
        foreach ($platforms as $platform) {
            $boards = [];
            foreach ($platform['system_boards'] ?? [] as $board) {
                $uuid = $board['uuid'] ?? '';
                $spec = $boardIndex[$uuid] ?? null;

                $boards[] = [
                    'uuid' => $uuid,
                    // The platform file names the board for display; the spec is
                    // authoritative when the two disagree.
                    'model' => $spec['model'] ?? ($board['model'] ?? 'Unknown board'),
                    'part_number' => $board['part_number'] ?? null,
                    'is_default' => !empty($board['is_default']),
                    'spec_exists' => $spec !== null,
                    'available_units' => (int)($stock[$uuid] ?? 0),
                    'specs' => $spec['specs'] ?? null
                ];
            }

            $result[] = [
                'platform_uuid' => $platform['platform_uuid'] ?? '',
                'brand' => $platform['brand'] ?? '',
                'family' => $platform['family'] ?? '',
                'platform' => $platform['platform'] ?? '',
                'generation' => $platform['generation'] ?? '',
                'form_factor' => $platform['form_factor'] ?? '',
                'system_boards' => $boards,
                'board_count' => count($boards),
                'available_units' => array_sum(array_column($boards, 'available_units'))
            ];
        }

        return $result;
    }

    /** One platform, raw (no stock annotation). */
    public function getPlatform(string $platformUuid): ?array
    {
        if ($platformUuid === '') {
            return null;
        }

        foreach ($this->loadPlatforms() as $platform) {
            if (($platform['platform_uuid'] ?? '') === $platformUuid) {
                return $platform;
            }
        }

        return null;
    }

    /** Does this platform actually accept this system board? */
    public function isBoardInPlatform(string $platformUuid, string $motherboardUuid): bool
    {
        $platform = $this->getPlatform($platformUuid);
        if ($platform === null) {
            return false;
        }

        foreach ($platform['system_boards'] ?? [] as $board) {
            if (($board['uuid'] ?? '') === $motherboardUuid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which platform an installed board belongs to. Lets a configuration built before
     * this feature existed (or one whose board was added through the normal component
     * picker) still show its platform. Display only — nothing is written back.
     *
     * A board shared by several platforms resolves to the first match; the platform
     * file is the place to disambiguate if that ever matters.
     */
    public function platformForBoard(string $motherboardUuid): ?array
    {
        if ($motherboardUuid === '') {
            return null;
        }

        foreach ($this->loadPlatforms() as $platform) {
            foreach ($platform['system_boards'] ?? [] as $board) {
                if (($board['uuid'] ?? '') === $motherboardUuid) {
                    return [
                        'platform_uuid' => $platform['platform_uuid'] ?? '',
                        'platform_name' => $this->displayName($platform)
                    ];
                }
            }
        }

        return null;
    }

    /** "HPE ProLiant DL360 Gen10" — what gets stamped on the configuration. */
    public function displayName(array $platform): string
    {
        $brand = trim($platform['brand'] ?? '');
        $name = trim($platform['platform'] ?? '');

        return trim($brand . ' ' . $name);
    }

    /**
     * Available units per motherboard spec UUID, in one grouped query.
     * Status = 1 is "available" (0 = failed, 2 = in use).
     */
    private function getAvailableUnitCounts(): array
    {
        if (!$this->pdo) {
            return [];
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT UUID, COUNT(*) AS unit_count
                 FROM motherboardinventory
                 WHERE Status = 1
                 GROUP BY UUID"
            );

            $counts = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row['UUID']] = (int)$row['unit_count'];
            }

            return $counts;
        } catch (Exception $e) {
            error_log('ServerPlatformCatalog: failed to count available boards - ' . $e->getMessage());
            return [];
        }
    }

    /** The platform file, decoded. An unreadable file yields an empty catalog, never a fatal. */
    private function loadPlatforms(): array
    {
        if (self::$platforms !== null) {
            return self::$platforms;
        }

        self::$platforms = [];

        try {
            $path = ComponentSpecPaths::getPlatformPath();
            if (!is_file($path)) {
                error_log('ServerPlatformCatalog: platform spec file not found');
                return self::$platforms;
            }

            $decoded = json_decode((string)file_get_contents($path), true);
            if (!is_array($decoded)) {
                error_log('ServerPlatformCatalog: platform spec file is not valid JSON - ' . json_last_error_msg());
                return self::$platforms;
            }

            self::$platforms = $decoded;
        } catch (Exception $e) {
            error_log('ServerPlatformCatalog: failed to load platform specs - ' . $e->getMessage());
        }

        return self::$platforms;
    }

    /**
     * uuid => board spec summary, built from the motherboard spec file in one pass.
     *
     * Deliberately not ComponentDataService::validateComponentUuid(): that is one call
     * per board with verbose error_log output on every hit, and it answers only
     * yes/no — the picker also needs sockets and memory slots to label each board.
     */
    private function loadBoardIndex(): array
    {
        if (self::$boardIndex !== null) {
            return self::$boardIndex;
        }

        self::$boardIndex = [];

        try {
            $path = ComponentSpecPaths::getPath('motherboard');
            if (!is_file($path)) {
                error_log('ServerPlatformCatalog: motherboard spec file not found');
                return self::$boardIndex;
            }

            $decoded = json_decode((string)file_get_contents($path), true);
            if (!is_array($decoded)) {
                error_log('ServerPlatformCatalog: motherboard spec file is not valid JSON');
                return self::$boardIndex;
            }

            foreach ($decoded as $group) {
                foreach ($group['models'] ?? [] as $model) {
                    $uuid = $model['uuid'] ?? $model['UUID'] ?? null;
                    if (!$uuid) {
                        continue;
                    }

                    $socket = $model['socket'] ?? [];
                    $memory = $model['memory'] ?? [];

                    self::$boardIndex[$uuid] = [
                        'model' => $model['model'] ?? '',
                        'specs' => [
                            'brand' => $group['brand'] ?? ($model['brand'] ?? ''),
                            'form_factor' => $model['form_factor'] ?? '',
                            'socket_type' => is_array($socket) ? ($socket['type'] ?? '') : $socket,
                            'socket_count' => is_array($socket) ? (int)($socket['count'] ?? 1) : 1,
                            'memory_type' => $memory['type'] ?? '',
                            'memory_slots' => (int)($memory['slots'] ?? 0),
                            'chipset' => $model['chipset'] ?? ''
                        ]
                    ];
                }
            }
        } catch (Exception $e) {
            error_log('ServerPlatformCatalog: failed to index motherboard specs - ' . $e->getMessage());
        }

        return self::$boardIndex;
    }
}
?>
