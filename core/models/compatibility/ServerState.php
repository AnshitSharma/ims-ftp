<?php
/**
 * ServerState — single immutable read-model of one server configuration.
 *
 * M11 CONSOLIDATION, Phase 1 (see COMPATIBILITY_CONSOLIDATION_PLAN.md §2.1).
 *
 * WHY THIS EXISTS
 *   Today every validator constructs itself as `new X($pdo)` and independently
 *   re-queries `server_configurations` and `json_decode`s the raw columns — bypassing
 *   the guarding decoder (TP-5B), re-issuing N+1 reads (TP-5A), and re-deriving the
 *   singular/plural column mess differently each time (C5). ServerState is the ONE place
 *   a configuration is read and normalized, so every validator can stop re-deriving it.
 *
 * PHASE 1 CONTRACT (this file): build it and prove it equivalent to the existing reads.
 *   `getComponents()` returns the SAME flat component list as
 *   `ServerBuilder::extractComponentsFromJson()` (identity fields: type, uuid, quantity,
 *   and for SFP parent_nic_uuid/port_index), so it is a drop-in reader. It is wired to
 *   NOTHING yet — introducing this class changes no behavior. Validators are cut onto it
 *   one at a time in Phase 2, each proven byte-identical against the golden master.
 *
 * DESIGN
 *   - Immutable: constructed once from a config row (or in-flight `$configData`); never
 *     mutated. `withCandidate()` returns a NEW state for add-time "what if I add X".
 *   - Single safe decode per family column (mirrors ServerBuilder::safeJsonDecode).
 *   - C5: reads the real singular columns (`ram_configuration`, `storage_configuration`)
 *     with a defensive plural fallback, and the legacy scalar `hbacard_uuid` /
 *     `motherboard_uuid` / `chassis_uuid` alongside the JSON arrays — normalized here once.
 *   - Onboard NICs (M1) and staged/unassigned SFPs (TP-4A/4B) are surfaced, exactly as
 *     the canonical extractor does, so nothing is invisible.
 *
 * Deterministic by design: unlike the legacy extractor it does NOT stamp a wall-clock
 * `added_at` for entries missing one (it leaves the JSON value or null), so a state is a
 * pure function of its input.
 */
class ServerState
{
    /** @var array Raw `server_configurations` row. */
    private $configData;

    /** @var array Proposed not-yet-persisted additions (add-time `withCandidate`). */
    private $candidates;

    /** @var array|null Memoized flat component list. */
    private $componentsCache = null;

    private function __construct(array $configData, array $candidates = [])
    {
        $this->configData = $configData;
        $this->candidates = $candidates;
    }

    /**
     * Load a configuration by UUID, reading `server_configurations` exactly once.
     * Returns null when the configuration does not exist.
     */
    public static function fromConfigUuid(PDO $pdo, string $configUuid): ?ServerState
    {
        $stmt = $pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return new self($row);
    }

    /**
     * Build a state from an already-loaded config row / in-flight `$configData` array
     * (the add-time path already holds the locked row, so no re-query is needed).
     */
    public static function fromConfigData(array $configData): ServerState
    {
        return new self($configData);
    }

    /**
     * Return a NEW state that additionally contains a proposed component (add-time
     * "what if I add this"). The current state is left unchanged (immutability).
     *
     * @param string $type  component type, e.g. 'nic'
     * @param string $uuid  component UUID
     * @param int    $qty   quantity (>= 1)
     * @param array  $extra optional extra identity fields, e.g.
     *                      ['parent_nic_uuid' => ..., 'port_index' => ...] for SFP
     */
    public function withCandidate(string $type, string $uuid, int $qty = 1, array $extra = []): ServerState
    {
        $candidate = array_merge([
            'component_type' => $type,
            'component_uuid' => $uuid,
            'quantity'       => max(1, $qty),
        ], $extra);

        return new self($this->configData, array_merge($this->candidates, [$candidate]));
    }

    // -- Identity / scalar config -------------------------------------------------

    public function getConfigUuid(): ?string
    {
        return $this->configData['config_uuid'] ?? null;
    }

    public function getRawConfigData(): array
    {
        return $this->configData;
    }

    /**
     * Return a JSON family column decoded once through the guarded decoder, as the
     * associative array the legacy validators obtained via json_decode($col, true).
     * Empty / null / malformed → [] (logged), so a caller's existing
     * `if (!$x || !isset($x[...]))` guard behaves exactly as before. This is the Phase-2
     * cut point: a validator replaces its own `SELECT <col> ...; json_decode(...)` with
     * `ServerState::fromConfigUuid($pdo, $uuid)->getDecodedColumn('<col>')`.
     *
     * @param string $column e.g. 'nic_config', 'sfp_configuration', 'pciecard_configurations'
     */
    public function getDecodedColumn(string $column): array
    {
        return $this->decode($this->configData[$column] ?? null, $column);
    }

    public function getMotherboardUuid(): ?string
    {
        $uuid = $this->configData['motherboard_uuid'] ?? null;
        return !empty($uuid) ? $uuid : null;
    }

    public function getChassisUuid(): ?string
    {
        $uuid = $this->configData['chassis_uuid'] ?? null;
        return !empty($uuid) ? $uuid : null;
    }

    // -- Typed, quantity-aware accessors -----------------------------------------

    /** @return array|null the motherboard component entry, or null if none */
    public function getMotherboard(): ?array
    {
        return $this->firstOfType('motherboard');
    }

    /** @return array|null the chassis component entry, or null if none */
    public function getChassis(): ?array
    {
        return $this->firstOfType('chassis');
    }

    public function getCpus(): array        { return $this->ofType('cpu'); }
    public function getRam(): array         { return $this->ofType('ram'); }
    public function getStorage(): array     { return $this->ofType('storage'); }
    public function getCaddies(): array     { return $this->ofType('caddy'); }
    public function getNics(): array        { return $this->ofType('nic'); }
    public function getHbas(): array        { return $this->ofType('hbacard'); }
    public function getPcieCards(): array   { return $this->ofType('pciecard'); }
    public function getSfps(): array        { return $this->ofType('sfp'); }

    /**
     * The canonical flat component list — identity-equivalent to
     * ServerBuilder::extractComponentsFromJson(), plus any add-time candidates.
     */
    public function getComponents(): array
    {
        if ($this->componentsCache === null) {
            $this->componentsCache = $this->buildComponents();
        }
        // Candidates are appended (not cached) so withCandidate stays cheap & immutable.
        return $this->candidates
            ? array_merge($this->componentsCache, $this->candidates)
            : $this->componentsCache;
    }

    // -- internals ----------------------------------------------------------------

    private function ofType(string $type): array
    {
        $out = [];
        foreach ($this->getComponents() as $c) {
            if (($c['component_type'] ?? null) === $type) {
                $out[] = $c;
            }
        }
        return $out;
    }

    private function firstOfType(string $type): ?array
    {
        foreach ($this->getComponents() as $c) {
            if (($c['component_type'] ?? null) === $type) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Enumerate components from the config JSON, mirroring the field order and shapes of
     * ServerBuilder::extractComponentsFromJson() so this is a drop-in reader.
     */
    private function buildComponents(): array
    {
        $cd = $this->configData;
        $components = [];

        // CPU
        $cpuConfig = $this->decode($cd['cpu_configuration'] ?? null, 'cpu_configuration');
        if (isset($cpuConfig['cpus']) && is_array($cpuConfig['cpus'])) {
            foreach ($cpuConfig['cpus'] as $cpu) {
                $entry = [
                    'component_type' => 'cpu',
                    'component_uuid' => $cpu['uuid'] ?? null,
                    'quantity'       => $cpu['quantity'] ?? 1,
                    'added_at'       => $cpu['added_at'] ?? null,
                ];
                if (isset($cpu['serial_number'])) {
                    $entry['serial_number'] = $cpu['serial_number'];
                }
                $components[] = $entry;
            }
        }

        // RAM (C5: singular column, defensive plural fallback)
        $ramConfigs = $this->decode($cd['ram_configuration'] ?? ($cd['ram_configurations'] ?? null), 'ram_configuration');
        if (is_array($ramConfigs)) {
            foreach ($ramConfigs as $ram) {
                if (!is_array($ram)) { continue; }
                $components[] = [
                    'component_type' => 'ram',
                    'component_uuid' => $ram['uuid'] ?? null,
                    'quantity'       => $ram['quantity'] ?? 1,
                    'added_at'       => $ram['added_at'] ?? null,
                ];
            }
        }

        // Storage (C5: singular column, defensive plural fallback)
        $storageConfigs = $this->decode($cd['storage_configuration'] ?? ($cd['storage_configurations'] ?? null), 'storage_configuration');
        if (is_array($storageConfigs)) {
            foreach ($storageConfigs as $storage) {
                if (!is_array($storage)) { continue; }
                $components[] = [
                    'component_type' => 'storage',
                    'component_uuid' => $storage['uuid'] ?? null,
                    'quantity'       => $storage['quantity'] ?? 1,
                    'added_at'       => $storage['added_at'] ?? null,
                    'connection'     => $storage['connection'] ?? null,
                ];
            }
        }

        // Caddy
        $caddyConfigs = $this->decode($cd['caddy_configuration'] ?? null, 'caddy_configuration');
        if (is_array($caddyConfigs)) {
            foreach ($caddyConfigs as $caddy) {
                if (!is_array($caddy)) { continue; }
                $components[] = [
                    'component_type' => 'caddy',
                    'component_uuid' => $caddy['uuid'] ?? null,
                    'quantity'       => $caddy['quantity'] ?? 1,
                    'added_at'       => $caddy['added_at'] ?? null,
                ];
            }
        }

        // NIC (object with nics[]; includes onboard NICs — M1)
        $nicConfig = $this->decode($cd['nic_config'] ?? null, 'nic_config');
        if (isset($nicConfig['nics']) && is_array($nicConfig['nics'])) {
            foreach ($nicConfig['nics'] as $nic) {
                if (!is_array($nic)) { continue; }
                $components[] = [
                    'component_type' => 'nic',
                    'component_uuid' => $nic['uuid'] ?? null,
                    'quantity'       => 1,
                    'added_at'       => null,
                ];
            }
        }

        // HBA (JSON array, with single-object migration + legacy scalar fallback — C5)
        $hbaConfigs = $this->decode($cd['hbacard_config'] ?? null, 'hbacard_config');
        if (!empty($cd['hbacard_config']) && is_array($hbaConfigs)) {
            if (isset($hbaConfigs['uuid'])) {
                $hbaConfigs = [$hbaConfigs];
            }
            foreach ($hbaConfigs as $hba) {
                if (!is_array($hba)) { continue; }
                $components[] = [
                    'component_type' => 'hbacard',
                    'component_uuid' => $hba['uuid'] ?? null,
                    'quantity'       => 1,
                    'added_at'       => $hba['added_at'] ?? null,
                ];
            }
        } elseif (!empty($cd['hbacard_uuid'])) {
            $components[] = [
                'component_type' => 'hbacard',
                'component_uuid' => $cd['hbacard_uuid'],
                'quantity'       => 1,
                'added_at'       => null,
            ];
        }

        // Motherboard (scalar column)
        if (!empty($cd['motherboard_uuid'])) {
            $components[] = [
                'component_type' => 'motherboard',
                'component_uuid' => $cd['motherboard_uuid'],
                'quantity'       => 1,
                'added_at'       => null,
            ];
        }

        // Chassis (scalar column)
        if (!empty($cd['chassis_uuid'])) {
            $components[] = [
                'component_type' => 'chassis',
                'component_uuid' => $cd['chassis_uuid'],
                'quantity'       => 1,
                'added_at'       => null,
            ];
        }

        // PCIe cards
        $pcieConfigs = $this->decode($cd['pciecard_configurations'] ?? null, 'pciecard_configurations');
        if (is_array($pcieConfigs)) {
            foreach ($pcieConfigs as $pcie) {
                if (!is_array($pcie)) { continue; }
                $components[] = [
                    'component_type' => 'pciecard',
                    'component_uuid' => $pcie['uuid'] ?? null,
                    'quantity'       => $pcie['quantity'] ?? 1,
                    'added_at'       => $pcie['added_at'] ?? null,
                ];
            }
        }

        // SFP (object with sfps[] assigned, then unassigned_sfps[] staged — TP-4A/4B)
        $sfpConfig = $this->decode($cd['sfp_configuration'] ?? null, 'sfp_configuration');
        if (isset($sfpConfig['sfps']) && is_array($sfpConfig['sfps'])) {
            foreach ($sfpConfig['sfps'] as $sfp) {
                if (!is_array($sfp)) { continue; }
                $components[] = [
                    'component_type'  => 'sfp',
                    'component_uuid'  => $sfp['uuid'] ?? null,
                    'parent_nic_uuid' => $sfp['parent_nic_uuid'] ?? null,
                    'port_index'      => $sfp['port_index'] ?? null,
                    'quantity'        => 1,
                    'added_at'        => $sfp['added_at'] ?? null,
                ];
            }
        }
        if (isset($sfpConfig['unassigned_sfps']) && is_array($sfpConfig['unassigned_sfps'])) {
            foreach ($sfpConfig['unassigned_sfps'] as $sfp) {
                if (!is_array($sfp)) { continue; }
                $components[] = [
                    'component_type'  => 'sfp',
                    'component_uuid'  => $sfp['uuid'] ?? null,
                    'parent_nic_uuid' => null,
                    'port_index'      => null,
                    'quantity'        => 1,
                    'added_at'        => $sfp['added_at'] ?? null,
                    'status'          => 'unassigned',
                ];
            }
        }

        return $components;
    }

    /**
     * Safe JSON decode mirroring ServerBuilder::safeJsonDecode (associative): empty/null
     * input and malformed JSON both yield [] (logged), so a bad column never becomes a
     * fatal or a silent null.
     */
    private function decode($jsonString, string $fieldName): array
    {
        if (empty($jsonString)) {
            return [];
        }
        if (is_array($jsonString)) {
            return $jsonString; // already decoded (defensive)
        }
        $decoded = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ServerState JSON ERROR in $fieldName: " . json_last_error_msg()
                . " | Raw: " . substr((string)$jsonString, 0, 100));
            return [];
        }
        if ($decoded === null) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }
}
