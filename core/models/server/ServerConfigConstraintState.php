<?php
/**
 * ServerConfigConstraintState
 * File: core/models/server/ServerConfigConstraintState.php
 *
 * Accumulated compatibility state for a single server_configurations row.
 *
 * Two field categories live on the object:
 *   - CAPABILITIES (locked once the motherboard/chassis/first-CPU are added):
 *     socket type, RAM slot count, supported memory types/speeds, PCIe slot
 *     layout, storage bays, form factor, PSU budget, etc.
 *   - CONSUMPTION COUNTERS (mutate on every add/remove):
 *     occupied CPU sockets, RAM slots, storage bays, PCIe slots, NIC ports,
 *     TDP used, power used.
 *
 * Both preview (`get-compatible`) and insert (`add-component`) consult the
 * same object via canAddComponent(), which eliminates the drift the legacy
 * code suffers from where previews don't count slots but inserts do.
 *
 * PHASE 0 STATUS: this class is standalone; nothing in the live request path
 * constructs or calls it yet. It is wired in during Phase 1 (shadow reads)
 * and Phase 2 (shadow writes). See tasks/todo.md.
 */

require_once __DIR__ . '/ConstraintDecision.php';

class ServerConfigConstraintState
{
    public const SCHEMA_VERSION = 1;

    // ------------------------------------------------------------------ //
    // Category 1: CAPABILITIES (locked by motherboard/chassis/CPU)       //
    // ------------------------------------------------------------------ //
    public ?string $socketType = null;
    public int $totalCpuSockets = 0;
    public int $totalRamSlots   = 0;

    /** @var string[] DDR4 / DDR5 (intersection of MB and CPU). */
    public array $supportedMemoryTypes = [];
    /** @var string[] RDIMM / LRDIMM / UDIMM. */
    public array $supportedMemoryModuleTypes = [];
    public ?string $memoryFormFactor = null;     // DIMM / SO-DIMM
    public ?int $memoryMaxSpeedMhz = null;       // min(MB, CPU)
    public ?int $memoryChannels = null;
    public ?int $memoryMaxModulesPerChannel = null;
    public ?int $memoryMaxCapacityGb = null;
    public ?bool $memoryEccRequired = null;

    public ?int $pcieGeneration = null;
    /** @var array<string,int>  {"x16":6,"x8":4,...} */
    public array $pcieSlotsBySize = [];
    /** @var array<string,int> */
    public array $riserSlotsByType = [];
    /** @var array<string,int>  {"OCP 3.0":1} */
    public array $specialtySlots = [];

    public int $totalStorageBays = 0;
    /** @var array<string,int>  {"3.5-inch":24,"2.5-inch":0} */
    public array $storageBaysByFormFactor = [];
    public int $sataPorts = 0;
    public int $sasPorts = 0;
    public int $m2SlotsTotal = 0;
    public int $u2SlotsTotal = 0;
    public ?string $backplaneInterface = null;
    public int $onboardNicPorts = 0;

    public ?int $chassisUSize = null;
    public ?string $chassisFormFactor = null;
    public ?string $formFactorLocked = null;
    public ?int $psuWattage = null;
    public ?bool $psuRedundant = null;

    // ------------------------------------------------------------------ //
    // Category 2: CONSUMPTION COUNTERS (mutate on apply/remove)          //
    // ------------------------------------------------------------------ //
    public int $occupiedCpuSockets = 0;
    public int $occupiedRamSlots   = 0;
    /** @var array<string,int> */
    public array $occupiedStorageBays = [];
    public int $occupiedSataPorts = 0;
    public int $occupiedSasPorts  = 0;
    public int $occupiedM2Slots   = 0;
    public int $occupiedU2Slots   = 0;
    /** @var array<string,int> */
    public array $occupiedPcieSlotsBySize = [];
    /** @var array<string,int> */
    public array $occupiedRiserSlotsByType = [];
    /** @var array<string,int> */
    public array $occupiedSpecialtySlots = [];
    /** @var array<string,int>  {nic_uuid: occupied_port_count} */
    public array $occupiedNicPorts = [];
    public int $tdpUsedW = 0;
    public int $powerConsumptionW = 0;

    // ------------------------------------------------------------------ //
    // Category 3: SINGLETONS                                             //
    // ------------------------------------------------------------------ //
    public bool $hasMotherboard = false;
    public bool $hasChassis = false;
    public ?string $motherboardUuid = null;
    public ?string $chassisUuid = null;

    /**
     * UUIDs of CPUs currently installed, in order of addition.
     * Needed so mixed-CPU checks can be performed against the first CPU.
     * @var string[]
     */
    public array $cpuUuids = [];

    // ------------------------------------------------------------------ //
    // Category 4: METADATA                                               //
    // ------------------------------------------------------------------ //
    public int $schemaVersion = self::SCHEMA_VERSION;
    public ?string $constraintsHash = null;
    public ?string $lastAppliedAt = null;
    public ?int $lastAppliedEventId = null;

    // ================================================================== //
    // Factories & persistence                                            //
    // ================================================================== //

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @throws InvalidArgumentException on malformed / incompatible-version JSON.
     */
    public static function hydrate(string $json): self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('constraint_state JSON is not an object');
        }
        $version = (int)($decoded['schemaVersion'] ?? 0);
        if ($version > self::SCHEMA_VERSION) {
            throw new InvalidArgumentException(
                "constraint_state schema v{$version} is newer than this code (v"
                . self::SCHEMA_VERSION . ')'
            );
        }
        $s = new self();
        foreach ($decoded as $k => $v) {
            if (property_exists($s, $k)) {
                $s->$k = $v;
            }
        }
        return $s;
    }

    public function serialize(): string
    {
        // get_object_vars returns all public props in declaration order.
        return json_encode(get_object_vars($this), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Rebuild state by replaying the existing JSON line-item columns on a
     * server_configurations row. Used for:
     *   - lazy backfill when constraint_state is NULL on an existing config
     *   - dual-run consistency check (rebuild must match the persisted blob)
     *
     * @param array              $configRow   Raw row from server_configurations.
     * @param ComponentDataService $dataService Used to load each component's spec.
     */
    public static function rebuildFromLineItems(array $configRow, $dataService): self
    {
        $state = new self();

        // ---- 1. Motherboard (capability source) ----
        if (!empty($configRow['motherboard_uuid'])) {
            $spec = $dataService->getComponentSpecifications('motherboard', $configRow['motherboard_uuid']);
            if ($spec) {
                $state->applyMotherboard($configRow['motherboard_uuid'], $spec);
            }
        }

        // ---- 2. Chassis (adds bay/PSU capabilities, may intersect form factor) ----
        if (!empty($configRow['chassis_uuid'])) {
            $spec = $dataService->getComponentSpecifications('chassis', $configRow['chassis_uuid']);
            if ($spec) {
                $state->applyChassis($configRow['chassis_uuid'], $spec);
            }
        }

        // ---- 3. CPUs (narrow memory caps, increment socket counter) ----
        if (!empty($configRow['cpu_configuration'])) {
            $cpuConfig = json_decode($configRow['cpu_configuration'], true);
            if (isset($cpuConfig['cpus']) && is_array($cpuConfig['cpus'])) {
                foreach ($cpuConfig['cpus'] as $cpu) {
                    if (empty($cpu['uuid'])) continue;
                    $qty  = max(1, (int)($cpu['quantity'] ?? 1));
                    $spec = $dataService->getComponentSpecifications('cpu', $cpu['uuid']);
                    if (!$spec) continue;
                    for ($i = 0; $i < $qty; $i++) {
                        $state->applyCpu($cpu['uuid'], $spec);
                    }
                }
            }
        }

        // ---- 4. RAM ----
        if (!empty($configRow['ram_configuration'])) {
            $rams = json_decode($configRow['ram_configuration'], true);
            if (is_array($rams)) {
                foreach ($rams as $ram) {
                    if (empty($ram['uuid'])) continue;
                    $qty  = max(1, (int)($ram['quantity'] ?? 1));
                    $spec = $dataService->getComponentSpecifications('ram', $ram['uuid']);
                    if (!$spec) continue;
                    $state->applyRam($ram['uuid'], $spec, $qty);
                }
            }
        }

        // ---- 5. Storage ----
        if (!empty($configRow['storage_configuration'])) {
            $items = json_decode($configRow['storage_configuration'], true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (empty($item['uuid'])) continue;
                    $qty  = max(1, (int)($item['quantity'] ?? 1));
                    $spec = $dataService->getComponentSpecifications('storage', $item['uuid']);
                    if (!$spec) continue;
                    for ($i = 0; $i < $qty; $i++) {
                        $state->applyStorage($item['uuid'], $spec, $item['connection'] ?? null);
                    }
                }
            }
        }

        // ---- 6. Caddies ----
        if (!empty($configRow['caddy_configuration'])) {
            $items = json_decode($configRow['caddy_configuration'], true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (empty($item['uuid'])) continue;
                    $qty = max(1, (int)($item['quantity'] ?? 1));
                    for ($i = 0; $i < $qty; $i++) {
                        $state->applyCaddy($item['uuid']);
                    }
                }
            }
        }

        // ---- 7. NICs ----
        if (!empty($configRow['nic_config'])) {
            $nicConfig = json_decode($configRow['nic_config'], true);
            if (isset($nicConfig['nics']) && is_array($nicConfig['nics'])) {
                foreach ($nicConfig['nics'] as $nic) {
                    if (empty($nic['uuid'])) continue;
                    $spec = $dataService->getComponentSpecifications('nic', $nic['uuid']);
                    if (!$spec) continue;
                    $state->applyNic($nic['uuid'], $spec);
                }
            }
        }

        // ---- 8. HBA cards (current + legacy singleton) ----
        if (!empty($configRow['hbacard_config'])) {
            $items = json_decode($configRow['hbacard_config'], true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (empty($item['uuid'])) continue;
                    $spec = $dataService->getComponentSpecifications('hbacard', $item['uuid']);
                    if (!$spec) continue;
                    $state->applyHbacard($item['uuid'], $spec);
                }
            }
        } elseif (!empty($configRow['hbacard_uuid'])) {
            $spec = $dataService->getComponentSpecifications('hbacard', $configRow['hbacard_uuid']);
            if ($spec) {
                $state->applyHbacard($configRow['hbacard_uuid'], $spec);
            }
        }

        // ---- 9. PCIe cards ----
        if (!empty($configRow['pciecard_configurations'])) {
            $items = json_decode($configRow['pciecard_configurations'], true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (empty($item['uuid'])) continue;
                    $qty  = max(1, (int)($item['quantity'] ?? 1));
                    $spec = $dataService->getComponentSpecifications('pciecard', $item['uuid']);
                    if (!$spec) continue;
                    for ($i = 0; $i < $qty; $i++) {
                        $state->applyPciecard($item['uuid'], $spec);
                    }
                }
            }
        }

        // ---- 10. SFPs ----
        if (!empty($configRow['sfp_configuration'])) {
            $sfpConfig = json_decode($configRow['sfp_configuration'], true);
            if (isset($sfpConfig['sfps']) && is_array($sfpConfig['sfps'])) {
                foreach ($sfpConfig['sfps'] as $sfp) {
                    if (empty($sfp['uuid'])) continue;
                    $state->applySfp(
                        $sfp['uuid'],
                        $sfp['nic_uuid']   ?? null,
                        $sfp['port_index'] ?? null
                    );
                }
            }
        }

        $state->recomputeConstraintsHash();
        $state->lastAppliedAt = gmdate('Y-m-d H:i:s');
        return $state;
    }

    public function recomputeConstraintsHash(): void
    {
        $capability = [
            'socketType'                   => $this->socketType,
            'totalCpuSockets'              => $this->totalCpuSockets,
            'totalRamSlots'                => $this->totalRamSlots,
            'supportedMemoryTypes'         => $this->supportedMemoryTypes,
            'supportedMemoryModuleTypes'   => $this->supportedMemoryModuleTypes,
            'memoryFormFactor'             => $this->memoryFormFactor,
            'memoryMaxSpeedMhz'            => $this->memoryMaxSpeedMhz,
            'memoryChannels'               => $this->memoryChannels,
            'memoryMaxModulesPerChannel'   => $this->memoryMaxModulesPerChannel,
            'memoryMaxCapacityGb'          => $this->memoryMaxCapacityGb,
            'memoryEccRequired'            => $this->memoryEccRequired,
            'pcieGeneration'               => $this->pcieGeneration,
            'pcieSlotsBySize'              => $this->pcieSlotsBySize,
            'riserSlotsByType'             => $this->riserSlotsByType,
            'specialtySlots'               => $this->specialtySlots,
            'totalStorageBays'             => $this->totalStorageBays,
            'storageBaysByFormFactor'      => $this->storageBaysByFormFactor,
            'sataPorts'                    => $this->sataPorts,
            'sasPorts'                     => $this->sasPorts,
            'm2SlotsTotal'                 => $this->m2SlotsTotal,
            'u2SlotsTotal'                 => $this->u2SlotsTotal,
            'backplaneInterface'           => $this->backplaneInterface,
            'onboardNicPorts'              => $this->onboardNicPorts,
            'chassisUSize'                 => $this->chassisUSize,
            'chassisFormFactor'            => $this->chassisFormFactor,
            'formFactorLocked'             => $this->formFactorLocked,
            'psuWattage'                   => $this->psuWattage,
            'psuRedundant'                 => $this->psuRedundant,
        ];
        $this->constraintsHash = hash('sha256', json_encode($capability));
    }

    public function summarize(): array
    {
        return [
            'capabilities' => [
                'socket_type'                => $this->socketType,
                'cpu_sockets'                => $this->totalCpuSockets,
                'ram_slots'                  => $this->totalRamSlots,
                'memory_types'               => $this->supportedMemoryTypes,
                'memory_max_speed_mhz'       => $this->memoryMaxSpeedMhz,
                'memory_channels'            => $this->memoryChannels,
                'memory_max_capacity_gb'     => $this->memoryMaxCapacityGb,
                'pcie_generation'            => $this->pcieGeneration,
                'pcie_slots'                 => $this->pcieSlotsBySize,
                'storage_bays'               => $this->storageBaysByFormFactor,
                'sata_ports'                 => $this->sataPorts,
                'sas_ports'                  => $this->sasPorts,
                'm2_slots'                   => $this->m2SlotsTotal,
                'u2_slots'                   => $this->u2SlotsTotal,
                'form_factor'                => $this->formFactorLocked,
                'psu_watts'                  => $this->psuWattage,
            ],
            'consumption' => [
                'cpu_sockets'                => "$this->occupiedCpuSockets/$this->totalCpuSockets",
                'ram_slots'                  => "$this->occupiedRamSlots/$this->totalRamSlots",
                'storage_bays_used'          => $this->occupiedStorageBays,
                'sata_ports_used'            => $this->occupiedSataPorts,
                'sas_ports_used'             => $this->occupiedSasPorts,
                'm2_slots_used'              => $this->occupiedM2Slots,
                'u2_slots_used'              => $this->occupiedU2Slots,
                'pcie_slots_used'            => $this->occupiedPcieSlotsBySize,
                'tdp_used_w'                 => $this->tdpUsedW,
                'power_consumption_w'        => $this->powerConsumptionW,
            ],
            'singletons' => [
                'has_motherboard'            => $this->hasMotherboard,
                'has_chassis'                => $this->hasChassis,
                'cpu_uuids'                  => $this->cpuUuids,
            ],
            'meta' => [
                'schema_version'             => $this->schemaVersion,
                'constraints_hash'           => $this->constraintsHash,
                'last_applied_at'            => $this->lastAppliedAt,
            ],
        ];
    }

    // ================================================================== //
    // Read API — used by both preview and insert paths                   //
    // ================================================================== //

    /**
     * Decide whether a single unit of ($type, $uuid) can be added right now.
     *
     * Only checks capability + counter constraints owned by this object; it
     * does NOT duplicate pairwise checks that live in ComponentValidator
     * (mixed-CPU identity, storage-connection routing, SFP-NIC speed match).
     * The adapter in Phase 1 delegates those to existing validators and
     * merges their verdicts in via $decision->merge().
     *
     * @param array $candidateSpec Full spec array from ComponentDataService.
     *                             May be empty if caller only has the UUID;
     *                             in that case spec-dependent checks are skipped.
     */
    public function canAddComponent(string $type, string $uuid, array $candidateSpec = [], array $options = []): ConstraintDecision
    {
        switch ($type) {
            case 'motherboard': return $this->canAddMotherboard($uuid);
            case 'chassis':     return $this->canAddChassis($uuid);
            case 'cpu':         return $this->canAddCpu($uuid, $candidateSpec);
            case 'ram':         return $this->canAddRam($uuid, $candidateSpec, 1);
            case 'storage':     return $this->canAddStorage($uuid, $candidateSpec, $options);
            case 'caddy':       return $this->canAddCaddy($uuid);
            case 'nic':         return $this->canAddNic($uuid, $candidateSpec);
            case 'hbacard':     return $this->canAddHbacard($uuid, $candidateSpec);
            case 'pciecard':    return $this->canAddPciecard($uuid, $candidateSpec);
            case 'sfp':         return $this->canAddSfp($uuid, $candidateSpec, $options);
            default:
                return ConstraintDecision::deny("Unknown component type: $type");
        }
    }

    public function canFitMultiple(string $type, string $uuid, array $candidateSpec, int $quantity): ConstraintDecision
    {
        if ($quantity < 1) {
            return ConstraintDecision::deny("Quantity must be >= 1 (got $quantity)");
        }
        if ($type === 'ram') {
            return $this->canAddRam($uuid, $candidateSpec, $quantity);
        }
        // Default: just loop single-add checks on a throwaway copy.
        $simulated = clone $this;
        $merged = ConstraintDecision::allow();
        for ($i = 0; $i < $quantity; $i++) {
            $d = $simulated->canAddComponent($type, $uuid, $candidateSpec);
            $merged->merge($d);
            if (!$d->allowed) break;
            $simulated->applyByType($type, $uuid, $candidateSpec);
        }
        return $merged;
    }

    // ------------------------- per-type checks ------------------------- //

    private function canAddMotherboard(string $uuid): ConstraintDecision
    {
        if ($this->hasMotherboard) {
            return ConstraintDecision::deny(
                'Configuration already has a motherboard',
                ['current_motherboard_uuid' => $this->motherboardUuid]
            );
        }
        return ConstraintDecision::allow();
    }

    private function canAddChassis(string $uuid): ConstraintDecision
    {
        if ($this->hasChassis) {
            return ConstraintDecision::deny(
                'Configuration already has a chassis',
                ['current_chassis_uuid' => $this->chassisUuid]
            );
        }
        return ConstraintDecision::allow();
    }

    private function canAddCpu(string $uuid, array $spec): ConstraintDecision
    {
        $d = ConstraintDecision::allow([
            'cpu_sockets_used'  => $this->occupiedCpuSockets,
            'cpu_sockets_total' => $this->totalCpuSockets,
        ]);
        if (!$this->hasMotherboard) {
            return $d->addWarning('Motherboard not yet added — socket/type checks deferred.');
        }
        if ($this->occupiedCpuSockets >= $this->totalCpuSockets) {
            return $d->addIssue(
                "All CPU sockets are occupied ({$this->occupiedCpuSockets}/{$this->totalCpuSockets})"
            );
        }
        if ($spec) {
            $candidateSocket = $this->extractCpuSocket($spec);
            if ($this->socketType && $candidateSocket && strcasecmp($this->socketType, $candidateSocket) !== 0) {
                $d->addIssue("CPU socket $candidateSocket does not match motherboard socket $this->socketType");
            }
        }
        return $d;
    }

    private function canAddRam(string $uuid, array $spec, int $quantity): ConstraintDecision
    {
        $d = ConstraintDecision::allow([
            'ram_slots_used'  => $this->occupiedRamSlots,
            'ram_slots_total' => $this->totalRamSlots,
            'requested_qty'   => $quantity,
        ]);
        if (!$this->hasMotherboard) {
            return $d->addWarning('Motherboard not yet added — RAM compatibility checks deferred.');
        }
        if ($this->totalRamSlots > 0 && $this->occupiedRamSlots + $quantity > $this->totalRamSlots) {
            $d->addIssue(sprintf(
                'RAM slot limit reached (requested %d, %d/%d used)',
                $quantity, $this->occupiedRamSlots, $this->totalRamSlots
            ));
        }
        if ($spec) {
            $ramType   = $this->extractRamType($spec);            // "DDR4" / "DDR5"
            $ramSpeed  = $this->extractRamSpeedMhz($spec);
            $ramFF     = $this->extractRamFormFactor($spec);       // "DIMM" / "SO-DIMM"
            $ramModule = $this->extractRamModuleType($spec);       // "RDIMM" / ...
            $ramEcc    = $this->extractRamEcc($spec);

            if ($ramType && !empty($this->supportedMemoryTypes)
                && !$this->inArrayCI($ramType, $this->supportedMemoryTypes)) {
                $d->addIssue("RAM type $ramType not supported (expected: "
                    . implode('/', $this->supportedMemoryTypes) . ')');
            }
            if ($ramFF && $this->memoryFormFactor
                && strcasecmp($ramFF, $this->memoryFormFactor) !== 0) {
                $d->addIssue("RAM form factor $ramFF does not match $this->memoryFormFactor");
            }
            if ($ramModule && !empty($this->supportedMemoryModuleTypes)
                && !$this->inArrayCI($ramModule, $this->supportedMemoryModuleTypes)) {
                $d->addIssue("RAM module type $ramModule not supported (expected: "
                    . implode('/', $this->supportedMemoryModuleTypes) . ')');
            }
            if ($ramSpeed && $this->memoryMaxSpeedMhz && $ramSpeed > $this->memoryMaxSpeedMhz) {
                $d->addWarning(
                    "RAM rated $ramSpeed MHz will be down-clocked to {$this->memoryMaxSpeedMhz} MHz"
                );
            }
            if ($this->memoryEccRequired === true && $ramEcc === false) {
                $d->addIssue('ECC memory is required by this platform');
            }
        }
        return $d;
    }

    private function canAddStorage(string $uuid, array $spec, array $options): ConstraintDecision
    {
        $d = ConstraintDecision::allow([
            'storage_bays_used'  => $this->occupiedStorageBays,
            'storage_bays_total' => $this->storageBaysByFormFactor,
        ]);
        $ff         = $this->extractStorageFormFactor($spec); // "2.5-inch" / "3.5-inch" / "M.2" / "U.2"
        $connection = $options['connection'] ?? $this->extractStorageInterface($spec);

        if ($ff && in_array($ff, ['2.5-inch', '3.5-inch'], true) && $this->hasChassis) {
            $available = ($this->storageBaysByFormFactor[$ff] ?? 0)
                - ($this->occupiedStorageBays[$ff] ?? 0);
            if ($available <= 0) {
                $d->addIssue("No $ff bays available on chassis");
            }
        } elseif ($ff === 'M.2') {
            if ($this->m2SlotsTotal > 0 && $this->occupiedM2Slots >= $this->m2SlotsTotal) {
                $d->addIssue("All M.2 slots occupied ({$this->occupiedM2Slots}/{$this->m2SlotsTotal})");
            }
        } elseif ($ff === 'U.2') {
            if ($this->u2SlotsTotal > 0 && $this->occupiedU2Slots >= $this->u2SlotsTotal) {
                $d->addIssue("All U.2 slots occupied ({$this->occupiedU2Slots}/{$this->u2SlotsTotal})");
            }
        }

        if ($connection) {
            $connLower = strtolower($connection);
            if ($connLower === 'sata'
                && $this->sataPorts > 0
                && $this->occupiedSataPorts >= $this->sataPorts) {
                $d->addIssue("All SATA ports occupied ({$this->occupiedSataPorts}/{$this->sataPorts})");
            }
            if ($connLower === 'sas'
                && $this->sasPorts > 0
                && $this->occupiedSasPorts >= $this->sasPorts) {
                $d->addIssue("All SAS ports occupied ({$this->occupiedSasPorts}/{$this->sasPorts})");
            }
        }
        return $d;
    }

    private function canAddCaddy(string $uuid): ConstraintDecision
    {
        // Caddies are loosely constrained at this layer; pair-matching against
        // chassis bay form factor is delegated to ComponentValidator.
        return ConstraintDecision::allow();
    }

    private function canAddNic(string $uuid, array $spec): ConstraintDecision
    {
        return $this->checkPcieCardSlotFit('NIC', $spec);
    }

    private function canAddHbacard(string $uuid, array $spec): ConstraintDecision
    {
        return $this->checkPcieCardSlotFit('HBA card', $spec);
    }

    private function canAddPciecard(string $uuid, array $spec): ConstraintDecision
    {
        return $this->checkPcieCardSlotFit('PCIe card', $spec);
    }

    private function canAddSfp(string $uuid, array $spec, array $options): ConstraintDecision
    {
        // SFP-NIC port compatibility is a pairwise check and lives in
        // SFPCompatibilityResolver; this layer only tracks consumption once
        // applySfp() runs. Assigned vs unassigned is handled by the caller.
        return ConstraintDecision::allow();
    }

    private function checkPcieCardSlotFit(string $label, array $spec): ConstraintDecision
    {
        $d = ConstraintDecision::allow([
            'pcie_slots_total' => $this->pcieSlotsBySize,
            'pcie_slots_used'  => $this->occupiedPcieSlotsBySize,
        ]);
        if (!$this->hasMotherboard) {
            return $d->addWarning("Motherboard not yet added — $label slot check deferred.");
        }
        $needed = $this->extractPcieSlotSize($spec); // "x16" / "x8" / "x4" / "x1"
        if ($needed) {
            $total = $this->pcieSlotsBySize[$needed] ?? 0;
            $used  = $this->occupiedPcieSlotsBySize[$needed] ?? 0;
            // Larger slots can host smaller cards; fall back to any larger free slot.
            if ($total === 0 || $used >= $total) {
                if (!$this->hasAnyLargerFreePcieSlot($needed)) {
                    $d->addIssue("No compatible PCIe $needed slot available for $label");
                }
            }
        }
        return $d;
    }

    // ================================================================== //
    // Write API — mutates counters                                       //
    // ================================================================== //

    public function applyByType(string $type, string $uuid, array $spec = [], array $options = []): void
    {
        switch ($type) {
            case 'motherboard': $this->applyMotherboard($uuid, $spec); break;
            case 'chassis':     $this->applyChassis($uuid, $spec); break;
            case 'cpu':         $this->applyCpu($uuid, $spec); break;
            case 'ram':         $this->applyRam($uuid, $spec, max(1, (int)($options['quantity'] ?? 1))); break;
            case 'storage':     $this->applyStorage($uuid, $spec, $options['connection'] ?? null); break;
            case 'caddy':       $this->applyCaddy($uuid); break;
            case 'nic':         $this->applyNic($uuid, $spec); break;
            case 'hbacard':     $this->applyHbacard($uuid, $spec); break;
            case 'pciecard':    $this->applyPciecard($uuid, $spec); break;
            case 'sfp':         $this->applySfp($uuid, $options['nic_uuid'] ?? null, $options['port_index'] ?? null); break;
        }
        $this->lastAppliedAt = gmdate('Y-m-d H:i:s');
    }

    public function removeByType(string $type, string $uuid, array $spec = [], array $options = []): void
    {
        switch ($type) {
            case 'motherboard': $this->removeMotherboard(); break;
            case 'chassis':     $this->removeChassis(); break;
            case 'cpu':         $this->removeCpu($uuid, $spec); break;
            case 'ram':         $this->removeRam($spec, max(1, (int)($options['quantity'] ?? 1))); break;
            case 'storage':     $this->removeStorage($spec, $options['connection'] ?? null); break;
            case 'caddy':       /* no-op */ break;
            case 'nic':         $this->removeNic($uuid, $spec); break;
            case 'hbacard':     $this->removeHbacard($spec); break;
            case 'pciecard':    $this->removePciecard($spec); break;
            case 'sfp':         $this->removeSfp($options['nic_uuid'] ?? null); break;
        }
        $this->lastAppliedAt = gmdate('Y-m-d H:i:s');
    }

    // -------------------- capability-setting applies -------------------- //

    public function applyMotherboard(string $uuid, array $spec): void
    {
        $this->hasMotherboard  = true;
        $this->motherboardUuid = $uuid;

        $socket = $this->specGet($spec, ['socket.type', 'socket']);
        if (is_string($socket)) $this->socketType = $socket;
        $socketCount = $this->specGet($spec, ['socket.count', 'socket_count']);
        if ($socketCount) $this->totalCpuSockets = (int)$socketCount;
        // Default to 1 socket if MB JSON omits count (most desktop boards).
        if ($this->totalCpuSockets === 0) $this->totalCpuSockets = 1;

        $ramSlots = $this->specGet($spec, ['memory.slots', 'memory_slots']);
        if ($ramSlots) $this->totalRamSlots = (int)$ramSlots;

        $memType = $this->specGet($spec, ['memory.type', 'memory_type']);
        if ($memType) $this->supportedMemoryTypes = $this->toArrayCI($memType);
        $modTypes = $this->specGet($spec, ['memory.module_types']);
        if ($modTypes) $this->supportedMemoryModuleTypes = $this->toArrayCI($modTypes);

        $ff = $this->specGet($spec, ['memory.form_factor']);
        if ($ff) $this->memoryFormFactor = (string)$ff;

        $maxFreq = $this->specGet($spec, ['memory.max_frequency_MHz', 'memory.max_frequency_mhz', 'memory.max_speed_MHz']);
        if ($maxFreq) $this->memoryMaxSpeedMhz = (int)$maxFreq;

        $channels = $this->specGet($spec, ['memory.channels']);
        if ($channels) $this->memoryChannels = (int)$channels;

        $modPerCh = $this->specGet($spec, ['memory.max_modules_per_channel']);
        if ($modPerCh) $this->memoryMaxModulesPerChannel = (int)$modPerCh;

        $maxCapTb = $this->specGet($spec, ['memory.max_capacity_TB', 'memory.max_capacity_tb']);
        if ($maxCapTb) $this->memoryMaxCapacityGb = (int)round(((float)$maxCapTb) * 1024);

        $ecc = $this->specGet($spec, ['memory.ecc_support', 'memory.ecc']);
        if ($ecc !== null) $this->memoryEccRequired = (bool)$ecc;

        // PCIe slot geometry
        $pcieSlots = $this->specGet($spec, ['expansion_slots.pcie_slots']);
        if (is_array($pcieSlots)) {
            foreach ($pcieSlots as $slot) {
                $type = $slot['type'] ?? ($slot['size'] ?? null);
                $cnt  = (int)($slot['count'] ?? 1);
                if ($type) {
                    $this->pcieSlotsBySize[$type] = ($this->pcieSlotsBySize[$type] ?? 0) + $cnt;
                }
                if (!empty($slot['generation']) && $this->pcieGeneration === null) {
                    $this->pcieGeneration = (int)preg_replace('/[^0-9]/', '', (string)$slot['generation']);
                }
            }
        }
        $risers = $this->specGet($spec, ['expansion_slots.riser_slots']);
        if (is_array($risers)) {
            foreach ($risers as $r) {
                $type = $r['type'] ?? null;
                $cnt  = (int)($r['count'] ?? 1);
                if ($type) $this->riserSlotsByType[$type] = ($this->riserSlotsByType[$type] ?? 0) + $cnt;
            }
        }
        $specialty = $this->specGet($spec, ['expansion_slots.specialty_slots']);
        if (is_array($specialty)) {
            foreach ($specialty as $s) {
                $type = $s['type'] ?? null;
                $cnt  = (int)($s['count'] ?? 1);
                if ($type) $this->specialtySlots[$type] = ($this->specialtySlots[$type] ?? 0) + $cnt;
            }
        }

        // Storage IO from motherboard
        $sata = $this->specGet($spec, ['storage.sata.ports']);
        if ($sata) $this->sataPorts = (int)$sata;
        $sas  = $this->specGet($spec, ['storage.sas.ports']);
        if ($sas)  $this->sasPorts  = (int)$sas;

        $m2 = $this->specGet($spec, ['storage.nvme.m2_slots']);
        if (is_array($m2)) {
            foreach ($m2 as $s) $this->m2SlotsTotal += (int)($s['count'] ?? 0);
        }
        $u2 = $this->specGet($spec, ['storage.nvme.u2_slots.count']);
        if ($u2) $this->u2SlotsTotal += (int)$u2;

        // Onboard NIC ports
        $onboard = $this->specGet($spec, ['networking.onboard_nics']);
        if (is_array($onboard)) {
            foreach ($onboard as $n) {
                $this->onboardNicPorts += (int)($n['ports'] ?? 0);
            }
        }

        // Form factor intersection
        $mbFf = $this->specGet($spec, ['form_factor']);
        if (is_string($mbFf)) {
            $this->formFactorLocked = $this->intersectFormFactor($this->formFactorLocked, $mbFf);
        }

        $this->recomputeConstraintsHash();
    }

    public function applyChassis(string $uuid, array $spec): void
    {
        $this->hasChassis  = true;
        $this->chassisUuid = $uuid;

        $u = $this->specGet($spec, ['u_size', 'chassis_u_size']);
        if ($u) $this->chassisUSize = (int)$u;

        $cff = $this->specGet($spec, ['form_factor']);
        if ($cff) {
            $this->chassisFormFactor = (string)$cff;
            $this->formFactorLocked  = $this->intersectFormFactor($this->formFactorLocked, $cff);
        }

        $bays = $this->specGet($spec, ['drive_bays.total_bays']);
        if ($bays) $this->totalStorageBays = (int)$bays;

        $bayConfigs = $this->specGet($spec, ['drive_bays.bay_configuration']);
        if (is_array($bayConfigs)) {
            foreach ($bayConfigs as $b) {
                $ff = $b['bay_type'] ?? null;
                $cnt = (int)($b['count'] ?? 0);
                if ($ff) $this->storageBaysByFormFactor[$ff] = ($this->storageBaysByFormFactor[$ff] ?? 0) + $cnt;
            }
        }

        $bp = $this->specGet($spec, ['backplane.interface']);
        if ($bp) $this->backplaneInterface = (string)$bp;

        $psu = $this->specGet($spec, ['power_supply.wattage']);
        if ($psu) $this->psuWattage = (int)$psu;

        $red = $this->specGet($spec, ['power_supply.redundant']);
        if ($red !== null) $this->psuRedundant = (bool)$red;

        $this->recomputeConstraintsHash();
    }

    public function applyCpu(string $uuid, array $spec): void
    {
        $this->occupiedCpuSockets++;
        $this->cpuUuids[] = $uuid;

        $tdp = $this->specGet($spec, ['tdp_W', 'tdp_w', 'tdp']);
        if ($tdp) $this->tdpUsedW += (int)$tdp;

        // First-CPU constraint narrowing. Second CPU in a dual-socket build
        // must match the first (enforced by ComponentValidator), so we only
        // narrow caps on the first application.
        if (count($this->cpuUuids) === 1) {
            $cpuMemTypes = $this->specGet($spec, ['memory_types']);
            if (is_array($cpuMemTypes)) {
                // Values look like "DDR5-4800"; extract family + speed ceiling.
                $families = [];
                $speedCeiling = null;
                foreach ($cpuMemTypes as $mt) {
                    if (preg_match('/^(DDR\d)(?:-(\d+))?/i', (string)$mt, $m)) {
                        $families[] = strtoupper($m[1]);
                        if (!empty($m[2])) {
                            $mhz = (int)$m[2];
                            $speedCeiling = $speedCeiling === null ? $mhz : min($speedCeiling, $mhz);
                        }
                    }
                }
                if (!empty($families)) {
                    $this->supportedMemoryTypes = empty($this->supportedMemoryTypes)
                        ? array_values(array_unique($families))
                        : array_values(array_intersect(
                            array_map('strtoupper', $this->supportedMemoryTypes),
                            $families
                        ));
                }
                if ($speedCeiling !== null) {
                    $this->memoryMaxSpeedMhz = $this->memoryMaxSpeedMhz === null
                        ? $speedCeiling
                        : min($this->memoryMaxSpeedMhz, $speedCeiling);
                }
            }
            $cpuChannels = $this->specGet($spec, ['memory_channels']);
            if ($cpuChannels) {
                $this->memoryChannels = $this->memoryChannels === null
                    ? (int)$cpuChannels
                    : min($this->memoryChannels, (int)$cpuChannels);
            }
            $cpuMaxCapTb = $this->specGet($spec, ['max_memory_capacity_TB', 'max_memory_capacity_tb']);
            if ($cpuMaxCapTb) {
                $gb = (int)round(((float)$cpuMaxCapTb) * 1024);
                $this->memoryMaxCapacityGb = $this->memoryMaxCapacityGb === null
                    ? $gb : min($this->memoryMaxCapacityGb, $gb);
            }
        }

        $this->recomputeConstraintsHash();
    }

    public function applyRam(string $uuid, array $spec, int $qty = 1): void
    {
        $this->occupiedRamSlots += $qty;
        $watts = (int)($this->specGet($spec, ['power_watts', 'wattage']) ?? 0);
        if ($watts) $this->powerConsumptionW += $watts * $qty;
    }

    public function applyStorage(string $uuid, array $spec, ?string $connection = null): void
    {
        $ff = $this->extractStorageFormFactor($spec);
        if ($ff && in_array($ff, ['2.5-inch', '3.5-inch'], true)) {
            $this->occupiedStorageBays[$ff] = ($this->occupiedStorageBays[$ff] ?? 0) + 1;
        } elseif ($ff === 'M.2') {
            $this->occupiedM2Slots++;
        } elseif ($ff === 'U.2') {
            $this->occupiedU2Slots++;
        }

        $connLower = $connection ? strtolower($connection) : strtolower($this->extractStorageInterface($spec) ?? '');
        if ($connLower === 'sata') $this->occupiedSataPorts++;
        if ($connLower === 'sas')  $this->occupiedSasPorts++;
    }

    public function applyCaddy(string $uuid): void
    {
        // No counter change — caddy consumption is tied to storage bay tracking.
    }

    public function applyNic(string $uuid, array $spec): void
    {
        $size = $this->extractPcieSlotSize($spec);
        if ($size) {
            $this->occupiedPcieSlotsBySize[$size] =
                ($this->occupiedPcieSlotsBySize[$size] ?? 0) + 1;
        }
        $this->occupiedNicPorts[$uuid] = 0;
    }

    public function applyHbacard(string $uuid, array $spec): void
    {
        $size = $this->extractPcieSlotSize($spec);
        if ($size) {
            $this->occupiedPcieSlotsBySize[$size] =
                ($this->occupiedPcieSlotsBySize[$size] ?? 0) + 1;
        }
    }

    public function applyPciecard(string $uuid, array $spec): void
    {
        $size = $this->extractPcieSlotSize($spec);
        if ($size) {
            $this->occupiedPcieSlotsBySize[$size] =
                ($this->occupiedPcieSlotsBySize[$size] ?? 0) + 1;
        }
    }

    public function applySfp(string $uuid, ?string $nicUuid = null, $portIndex = null): void
    {
        if ($nicUuid !== null) {
            $this->occupiedNicPorts[$nicUuid] = ($this->occupiedNicPorts[$nicUuid] ?? 0) + 1;
        }
    }

    // ---------------------------- removes ------------------------------ //

    public function removeMotherboard(): void
    {
        // Refuse to clear if dependents remain.
        if (!empty($this->cpuUuids)
            || $this->occupiedRamSlots > 0
            || !empty($this->occupiedPcieSlotsBySize)
            || !empty($this->occupiedNicPorts)) {
            throw new RuntimeException(
                'Cannot remove motherboard: dependent components are still installed'
            );
        }
        $this->hasMotherboard = false;
        $this->motherboardUuid = null;
        $this->socketType = null;
        $this->totalCpuSockets = 0;
        $this->totalRamSlots = 0;
        $this->supportedMemoryTypes = [];
        $this->supportedMemoryModuleTypes = [];
        $this->memoryFormFactor = null;
        $this->memoryMaxSpeedMhz = null;
        $this->memoryChannels = null;
        $this->memoryMaxModulesPerChannel = null;
        $this->memoryMaxCapacityGb = null;
        $this->memoryEccRequired = null;
        $this->pcieGeneration = null;
        $this->pcieSlotsBySize = [];
        $this->riserSlotsByType = [];
        $this->specialtySlots = [];
        $this->sataPorts = 0;
        $this->sasPorts = 0;
        $this->m2SlotsTotal = 0;
        $this->u2SlotsTotal = 0;
        $this->onboardNicPorts = 0;
        if ($this->chassisFormFactor) {
            $this->formFactorLocked = $this->chassisFormFactor;
        } else {
            $this->formFactorLocked = null;
        }
        $this->recomputeConstraintsHash();
    }

    public function removeChassis(): void
    {
        if (array_sum($this->occupiedStorageBays) > 0) {
            throw new RuntimeException(
                'Cannot remove chassis: storage bays are still occupied'
            );
        }
        $this->hasChassis = false;
        $this->chassisUuid = null;
        $this->chassisUSize = null;
        $this->chassisFormFactor = null;
        $this->totalStorageBays = 0;
        $this->storageBaysByFormFactor = [];
        $this->backplaneInterface = null;
        $this->psuWattage = null;
        $this->psuRedundant = null;
        if ($this->hasMotherboard) {
            // motherboardFormFactor remains; keep lock if it was MB-driven.
        } else {
            $this->formFactorLocked = null;
        }
        $this->recomputeConstraintsHash();
    }

    public function removeCpu(string $uuid, array $spec = []): void
    {
        $idx = array_search($uuid, $this->cpuUuids, true);
        if ($idx === false) return;
        array_splice($this->cpuUuids, $idx, 1);
        if ($this->occupiedCpuSockets > 0) $this->occupiedCpuSockets--;
        $tdp = (int)($this->specGet($spec, ['tdp_W', 'tdp_w', 'tdp']) ?? 0);
        if ($tdp) $this->tdpUsedW = max(0, $this->tdpUsedW - $tdp);

        // If we've removed the last CPU, reset the CPU-driven narrowings that
        // only apply when a CPU is installed. Motherboard-derived values stay.
        if (empty($this->cpuUuids) && $this->hasMotherboard) {
            // Re-derive by re-applying just the motherboard narrowings is costly
            // here, but safe: the Repository's hydrate/rebuild path refreshes
            // this when a new CPU is added. For now, leave fields untouched and
            // rely on the shadow rebuild to catch any drift (Phase 1/2).
        }
    }

    public function removeRam(array $spec, int $qty = 1): void
    {
        $this->occupiedRamSlots = max(0, $this->occupiedRamSlots - $qty);
        $watts = (int)($this->specGet($spec, ['power_watts', 'wattage']) ?? 0);
        if ($watts) $this->powerConsumptionW = max(0, $this->powerConsumptionW - $watts * $qty);
    }

    public function removeStorage(array $spec, ?string $connection = null): void
    {
        $ff = $this->extractStorageFormFactor($spec);
        if ($ff && in_array($ff, ['2.5-inch', '3.5-inch'], true)) {
            if (!empty($this->occupiedStorageBays[$ff])) {
                $this->occupiedStorageBays[$ff] = max(0, $this->occupiedStorageBays[$ff] - 1);
            }
        } elseif ($ff === 'M.2') {
            $this->occupiedM2Slots = max(0, $this->occupiedM2Slots - 1);
        } elseif ($ff === 'U.2') {
            $this->occupiedU2Slots = max(0, $this->occupiedU2Slots - 1);
        }
        $connLower = $connection ? strtolower($connection) : strtolower($this->extractStorageInterface($spec) ?? '');
        if ($connLower === 'sata') $this->occupiedSataPorts = max(0, $this->occupiedSataPorts - 1);
        if ($connLower === 'sas')  $this->occupiedSasPorts  = max(0, $this->occupiedSasPorts  - 1);
    }

    public function removeNic(string $uuid, array $spec = []): void
    {
        $size = $this->extractPcieSlotSize($spec);
        if ($size && !empty($this->occupiedPcieSlotsBySize[$size])) {
            $this->occupiedPcieSlotsBySize[$size] = max(0, $this->occupiedPcieSlotsBySize[$size] - 1);
        }
        unset($this->occupiedNicPorts[$uuid]);
    }

    public function removeHbacard(array $spec = []): void
    {
        $size = $this->extractPcieSlotSize($spec);
        if ($size && !empty($this->occupiedPcieSlotsBySize[$size])) {
            $this->occupiedPcieSlotsBySize[$size] = max(0, $this->occupiedPcieSlotsBySize[$size] - 1);
        }
    }

    public function removePciecard(array $spec = []): void
    {
        $size = $this->extractPcieSlotSize($spec);
        if ($size && !empty($this->occupiedPcieSlotsBySize[$size])) {
            $this->occupiedPcieSlotsBySize[$size] = max(0, $this->occupiedPcieSlotsBySize[$size] - 1);
        }
    }

    public function removeSfp(?string $nicUuid): void
    {
        if ($nicUuid !== null && !empty($this->occupiedNicPorts[$nicUuid])) {
            $this->occupiedNicPorts[$nicUuid] = max(0, $this->occupiedNicPorts[$nicUuid] - 1);
        }
    }

    // ================================================================== //
    // Spec-extraction helpers (tolerant: unknown fields → null)          //
    // ================================================================== //

    /**
     * Dotted-path lookup across the specification array. Tries each path in
     * order and returns the first non-null value. Also searches under
     * models[0].{path} which is where many ims-data JSON files nest specs.
     */
    private function specGet(array $spec, array $paths)
    {
        $roots = [$spec];
        if (isset($spec['models'][0]) && is_array($spec['models'][0])) {
            $roots[] = $spec['models'][0];
        }
        if (isset($spec['specifications']) && is_array($spec['specifications'])) {
            $roots[] = $spec['specifications'];
        }
        foreach ($paths as $path) {
            foreach ($roots as $root) {
                $v = $this->dotGet($root, $path);
                if ($v !== null) return $v;
            }
        }
        return null;
    }

    private function dotGet(array $a, string $path)
    {
        $cur = $a;
        foreach (explode('.', $path) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) return null;
            $cur = $cur[$k];
        }
        return $cur;
    }

    private function toArrayCI($v): array
    {
        if (is_array($v)) return array_values(array_unique(array_map('strval', $v)));
        return [(string)$v];
    }

    private function inArrayCI(string $needle, array $haystack): bool
    {
        foreach ($haystack as $h) {
            if (strcasecmp($needle, (string)$h) === 0) return true;
        }
        return false;
    }

    private function extractCpuSocket(array $spec): ?string
    {
        $s = $this->specGet($spec, ['socket', 'socket.type']);
        return is_string($s) ? $s : null;
    }

    private function extractRamType(array $spec): ?string
    {
        $t = $this->specGet($spec, ['memory_type', 'type']);
        return is_string($t) ? strtoupper($t) : null;
    }

    private function extractRamSpeedMhz(array $spec): ?int
    {
        $s = $this->specGet($spec, ['speed_MHz', 'speed_mhz', 'speed', 'frequency_MHz']);
        if (is_numeric($s)) return (int)$s;
        if (is_string($s) && preg_match('/(\d+)/', $s, $m)) return (int)$m[1];
        return null;
    }

    private function extractRamFormFactor(array $spec): ?string
    {
        $ff = $this->specGet($spec, ['form_factor']);
        return is_string($ff) ? $ff : null;
    }

    private function extractRamModuleType(array $spec): ?string
    {
        $mt = $this->specGet($spec, ['module_type', 'rank_type']);
        return is_string($mt) ? $mt : null;
    }

    private function extractRamEcc(array $spec): ?bool
    {
        $v = $this->specGet($spec, ['ecc', 'ecc_support']);
        if (is_bool($v)) return $v;
        if (is_string($v)) return strcasecmp($v, 'yes') === 0 || strcasecmp($v, 'true') === 0;
        if (is_numeric($v)) return (bool)(int)$v;
        return null;
    }

    private function extractStorageFormFactor(array $spec): ?string
    {
        $ff = $this->specGet($spec, ['form_factor']);
        return is_string($ff) ? $ff : null;
    }

    private function extractStorageInterface(array $spec): ?string
    {
        $i = $this->specGet($spec, ['interface', 'connection']);
        return is_string($i) ? $i : null;
    }

    private function extractPcieSlotSize(array $spec): ?string
    {
        $s = $this->specGet($spec, ['pcie_slot', 'slot_type', 'required_slot']);
        return is_string($s) ? $s : null;
    }

    private function hasAnyLargerFreePcieSlot(string $needed): bool
    {
        $order = ['x1' => 1, 'x4' => 4, 'x8' => 8, 'x16' => 16];
        $want = $order[strtolower($needed)] ?? null;
        if ($want === null) return false;
        foreach ($this->pcieSlotsBySize as $size => $total) {
            $n = $order[strtolower($size)] ?? null;
            if ($n !== null && $n >= $want) {
                $used = $this->occupiedPcieSlotsBySize[$size] ?? 0;
                if ($used < $total) return true;
            }
        }
        return false;
    }

    private function intersectFormFactor(?string $a, ?string $b): ?string
    {
        if (!$a) return $b;
        if (!$b) return $a;
        return strcasecmp($a, $b) === 0 ? $a : $a . '|' . $b;
    }
}
