<?php

/**
 * ConfigurationReverseLookup
 *
 * Given a component UUID (and optional serial number), finds every
 * server_configurations row whose JSON columns reference that component.
 *
 * This is the integrity guard used by every inventory delete/RMA handler
 * to prevent orphaned references: if a component is referenced by any
 * configuration, the delete is blocked (or cascaded when force=true).
 *
 * Uses MariaDB 10.11's JSON_CONTAINS for precise matching. For scalar
 * columns (motherboard_uuid, chassis_uuid, legacy hbacard_uuid) a plain
 * equality comparison is used.
 */
class ConfigurationReverseLookup
{
    /** @var PDO */
    private $pdo;

    /**
     * Map of component type -> list of column-level probes.
     * Each probe is either:
     *   - ['kind' => 'json', 'column' => '...', 'path' => '$.cpus' | null]
     *   - ['kind' => 'scalar', 'column' => '...']
     */
    private const COLUMN_MAP = [
        'cpu'         => [['kind' => 'json',   'column' => 'cpu_configuration',       'path' => '$.cpus']],
        'ram'         => [['kind' => 'json',   'column' => 'ram_configuration',       'path' => null]],
        'storage'     => [['kind' => 'json',   'column' => 'storage_configuration',   'path' => null]],
        'caddy'       => [['kind' => 'json',   'column' => 'caddy_configuration',     'path' => null]],
        'motherboard' => [['kind' => 'scalar', 'column' => 'motherboard_uuid']],
        'chassis'     => [['kind' => 'scalar', 'column' => 'chassis_uuid']],
        'nic'         => [['kind' => 'json',   'column' => 'nic_config',              'path' => '$.nics']],
        'hbacard'     => [
            ['kind' => 'json',   'column' => 'hbacard_config', 'path' => null],
            ['kind' => 'scalar', 'column' => 'hbacard_uuid'],
        ],
        'pciecard'    => [['kind' => 'json',   'column' => 'pciecard_configurations', 'path' => null]],
        'sfp'         => [['kind' => 'json',   'column' => 'sfp_configuration',       'path' => null]],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Find all configurations that reference a given component.
     *
     * @param string      $componentType  One of the keys in COLUMN_MAP
     * @param string      $componentUuid  Component UUID to look up
     * @param string|null $serialNumber   Optional serial number. When provided,
     *                                    JSON matches must also carry this serial.
     *                                    Scalar columns ignore the serial.
     * @param bool        $activeOnly     If true, exclude rows with configuration_status = 0 (draft/archived).
     *                                    Default true — callers usually want to know about live refs only.
     * @return array List of [config_uuid, server_name, configuration_status, matched_in_column]
     */
    public function findConfigsUsingComponent(
        $componentType,
        $componentUuid,
        $serialNumber = null,
        $activeOnly = true
    ) {
        if (!isset(self::COLUMN_MAP[$componentType])) {
            throw new InvalidArgumentException("Unknown component type: $componentType");
        }

        $results = [];
        $seenConfigs = [];

        foreach (self::COLUMN_MAP[$componentType] as $probe) {
            $rows = $this->runProbe($probe, $componentUuid, $serialNumber, $activeOnly);
            foreach ($rows as $row) {
                $key = $row['config_uuid'] . '|' . $probe['column'];
                if (isset($seenConfigs[$key])) {
                    continue;
                }
                $seenConfigs[$key] = true;
                $results[] = [
                    'config_uuid'          => $row['config_uuid'],
                    'server_name'          => $row['server_name'],
                    'configuration_status' => (int)$row['configuration_status'],
                    'matched_in_column'    => $probe['column'],
                ];
            }
        }

        return $results;
    }

    /**
     * Convenience: true if the component is referenced by at least one active
     * configuration (configuration_status != 0).
     */
    public function isComponentReferenced($componentType, $componentUuid, $serialNumber = null)
    {
        return !empty($this->findConfigsUsingComponent($componentType, $componentUuid, $serialNumber, true));
    }

    /**
     * Execute a single column probe.
     */
    private function runProbe(array $probe, $componentUuid, $serialNumber, $activeOnly)
    {
        $statusClause = $activeOnly ? 'AND configuration_status != 0' : '';

        if ($probe['kind'] === 'scalar') {
            // Scalar column — plain equality. Serial number not applicable.
            $sql = "
                SELECT config_uuid, server_name, configuration_status
                FROM server_configurations
                WHERE `{$probe['column']}` = ?
                $statusClause
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$componentUuid]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // JSON probe. Build a candidate object to search for. If a serial is
        // given, match both uuid AND serial_number; otherwise match uuid only.
        $needle = ($serialNumber !== null)
            ? json_encode(['uuid' => $componentUuid, 'serial_number' => $serialNumber])
            : json_encode(['uuid' => $componentUuid]);

        if (!empty($probe['path'])) {
            // Nested path — e.g. cpu_configuration.$.cpus, nic_config.$.nics
            $sql = "
                SELECT config_uuid, server_name, configuration_status
                FROM server_configurations
                WHERE `{$probe['column']}` IS NOT NULL
                  AND JSON_CONTAINS(`{$probe['column']}`, ?, ?)
                  $statusClause
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$needle, $probe['path']]);
        } else {
            // Flat array — top-level JSON_CONTAINS.
            $sql = "
                SELECT config_uuid, server_name, configuration_status
                FROM server_configurations
                WHERE `{$probe['column']}` IS NOT NULL
                  AND JSON_CONTAINS(`{$probe['column']}`, ?)
                  $statusClause
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$needle]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
