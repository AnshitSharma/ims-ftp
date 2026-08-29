<?php
/**
 * ServerState — single immutable read-model of one server configuration.
 *
 * M11 CONSOLIDATION (see COMPATIBILITY_CONSOLIDATION_PLAN.md §2.1).
 *
 * WHY THIS EXISTS
 *   Every validator used to construct itself as `new X($pdo)` and independently
 *   re-query `server_configurations` and `json_decode` the raw columns — re-issuing
 *   N+1 reads (TP-5A) and re-deriving the singular/plural column mess differently each
 *   time (C5). ServerState is the ONE place a configuration is read and normalized.
 *
 * WHAT IT READS (U-D.3b)
 *   config_components rows, through ConfigReadRouter — the same single authority
 *   getConfigurationDetails() and the command layer read. The nine legacy JSON columns
 *   this class used to decode are gone, and with them getDecodedColumn() (the Phase-2
 *   cut point, now that there is nothing left to cut) and the guarded decoder that
 *   backed it. The scalar motherboard_uuid / chassis_uuid columns survive and are still
 *   served from the config row by getMotherboardUuid() / getChassisUuid().
 *
 * DESIGN
 *   - Immutable: constructed once from a config row (or in-flight `$configData`); never
 *     mutated. `withCandidate()` returns a NEW state for add-time "what if I add X".
 *   - The component list is memoized, so the rows read happens at most once per state.
 *   - Onboard NICs (M1) and staged/unassigned SFPs (TP-4A/4B) are surfaced, so nothing
 *     is invisible; NIC entries carry `source_type` and slotted units `slot_position`.
 *
 * Deterministic by design: it never stamps a wall-clock `added_at` for an entry missing
 * one, so a state is a pure function of its input.
 */
class ServerState
{
    /** @var array Raw `server_configurations` row. */
    private $configData;

    /** @var array Proposed not-yet-persisted additions (add-time `withCandidate`). */
    private $candidates;

    /** @var array|null Memoized flat component list. */
    private $componentsCache = null;

    /**
     * U-D.3b: the connection the rows read needs. Null only for a caller that built a
     * state from a bare array and has none -- see buildComponents().
     * @var PDO|null
     */
    private $pdo;

    private function __construct(array $configData, array $candidates = [], ?PDO $pdo = null)
    {
        $this->configData = $configData;
        $this->candidates = $candidates;
        $this->pdo        = $pdo;
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
        return new self($row, [], $pdo);
    }

    /**
     * Build a state from an already-loaded config row / in-flight `$configData` array
     * (the add-time path already holds the locked row, so no re-query is needed).
     */
    public static function fromConfigData(array $configData, ?PDO $pdo = null): ServerState
    {
        return new self($configData, [], $pdo);
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

        return new self($this->configData, array_merge($this->candidates, [$candidate]), $this->pdo);
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
    public function getRiserCards(): array  { return $this->ofType('risercard'); }
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
     * U-D.3b: the component list comes from config_components rows, through the one
     * router every other reader uses. This method used to re-implement
     * ServerBuilder::extractComponentsFromJson() branch for branch against the nine
     * JSON columns -- a second decoder of a store that no longer exists.
     *
     * ConfigReadRouter emits the same output shape the old body did (component_type,
     * component_uuid, quantity, added_at, serial_number, and for sfp the
     * parent_nic_uuid / port_index / status='unassigned' triple), so every typed
     * accessor above and every caller below is unchanged.
     *
     * Without a PDO there is nothing to read: fromConfigData() may be handed a bare
     * array by a caller that has no connection, and an empty list is the honest answer
     * for "I cannot see this configuration's components" -- the same answer the old
     * body gave for a row whose columns were all null.
     */
    private function buildComponents(): array
    {
        if ($this->pdo === null || empty($this->configData['config_uuid'])) {
            return [];
        }
        require_once __DIR__ . '/../config/ConfigReadRouter.php';
        require_once __DIR__ . '/../server/ServerBuilder.php';
        return ConfigReadRouter::components(new ServerBuilder($this->pdo), $this->pdo, $this->configData);
    }

}
