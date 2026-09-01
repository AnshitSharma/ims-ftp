<?php

/**
 * Immutable snapshot of a configuration's components + derived resource
 * ledger, for rules to evaluate against. Never touches the DB itself —
 * constructed only via TargetStateBuilder (U-V.2 pack).
 *
 * Component row shape (both sources normalized to the same tuple):
 *   id (int|string), component_type, spec_uuid, inventory_table, inventory_id,
 *   serial_number, parent_id, slot_ref, source ('rows'|'json'), status_v2
 *   (added U-R.7: {inventory_table}.status_v2, null for json-source rows and
 *   for rows whose inventory_table/inventory_id are unknown — see
 *   TargetStateBuilder's class docblock for the known json-fallback gap).
 *
 * Resource rows are ALWAYS recomputed from components() via ResourceCatalog
 * (never read from config_resources) — see the U-V.2 pack: "resource deltas
 * recomputed via catalog". This is what makes fromCurrent() give the same
 * answer whether it hit the rows path or the JSON fallback path: resource
 * math never depends on which source produced the component list.
 *
 * Provider resource row shape: resource, slot_ref (nullable), capacity,
 * owner_component_id. Discrete resources (pcie_slot, riser_slot) already
 * arrive from ResourceCatalog with one row per physical slot (slot_ref set).
 * sfp_port arrives from ResourceCatalog as one capacity-N row per NIC; this
 * class expands it into N per-NIC-scoped slot rows (slot_ref "port_1".."port_N",
 * scoped by owner_component_id) so NetSfpPortRule (U-R.6) can do the same
 * free-slot lookup pattern as pcie/riser slots.
 */
final class TargetState
{
    /** @var array[] */
    private $components;
    /** @var array[]|null lazily built */
    private $resourceRows;
    /** @var ResourceCatalog */
    private $catalog;
    /** @var int|string|null id of the component this operation is ABOUT, if any */
    private $subjectId;

    /**
     * @param array[] $components normalized component row tuples (see class docblock)
     * @param int|string|null $subjectId the component row this operation is about
     *        (TargetStateBuilder::withAdd/withReplace set it). Null means "no single
     *        subject": a fromCurrent() snapshot, a finalize-time VALIDATE, or a
     *        removal, whose post-state no longer contains the row that changed.
     */
    public function __construct(array $components, ?ResourceCatalog $catalog = null, $subjectId = null)
    {
        $this->components = array_values($components);
        $this->catalog = $catalog ?? new ResourceCatalog();
        $this->subjectId = $subjectId;
    }

    /** @return array[] */
    public function components(): array
    {
        return $this->components;
    }

    /** @return array[] components of a given component_type */
    public function byType(string $type): array
    {
        return array_values(array_filter($this->components, function ($c) use ($type) {
            return $c['component_type'] === $type;
        }));
    }

    /**
     * The component this operation is ABOUT, or null when there is no single one. [F-24]
     *
     * A TargetState is the POST-operation snapshot, which deliberately says nothing
     * about what changed -- and that is exactly what made every config-wide rule
     * behave as if the whole configuration were being re-submitted on every add. For
     * StorageInterfacePathRule that meant one drive with no data path failed EVERY
     * subsequent add to that config (fleet sweep 2026-07-28: 9 of 9 unexplained diffs,
     * config 05bcb95b, adds of ram/storage/nic alike), where legacy only ever judged
     * the item being added. Under ENGINE_MODE=enforce such a config would have been
     * uneditable.
     *
     * Deliberately NOT expressed through RuleInterface::scope(): that method is
     * declared but never consumed by ValidationEngine, so making it meaningful would
     * change the contract of all 16 rules at once. This is additive -- rules that want
     * the subject ask for it, everything else is untouched.
     *
     * @return array|null
     */
    public function subject(): ?array
    {
        if ($this->subjectId === null) {
            return null;
        }
        return $this->find($this->subjectId);
    }

    /** @return array|null the component row with the given id, or null */
    public function find($id): ?array
    {
        foreach ($this->components as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    /** @return array[] live children whose parent_id === $id */
    public function childrenOf($id): array
    {
        return array_values(array_filter($this->components, function ($c) use ($id) {
            return $c['parent_id'] === $id;
        }));
    }

    /**
     * Full derived resource ledger: one row per provider unit (or per
     * expanded discrete slot for capacity-based providers like sfp_port).
     *
     * @return array[] {resource, slot_ref, capacity, owner_component_id}
     */
    public function resources(): array
    {
        if ($this->resourceRows !== null) {
            return $this->resourceRows;
        }

        $rows = [];
        foreach ($this->components as $c) {
            if ($c['component_type'] === 'nic' && ResourceCatalog::isOnboardNicUuid((string)$c['spec_uuid'])) {
                // Synthetic onboard rows never resolve in the nic JSON — their
                // port provision comes from the parent board's spec instead
                // (mirrors NICPortTracker::resolveOnboardNicSpecs()).
                $provided = $this->catalog->providesOnboardNic(
                    (string)$c['spec_uuid'],
                    $this->onboardParentBoardSpecUuid($c)
                );
            } else {
                // The row id scopes a riser's provided PCIe slots to that riser — see
                // ResourceCatalog::providesRisercard().
                $provided = $this->catalog->provides($c['component_type'], $c['spec_uuid'], $c['id']);
            }
            foreach ($provided as $p) {
                if ($p['resource'] === 'sfp_port' && $p['slot_ref'] === null) {
                    for ($i = 1; $i <= (int)$p['capacity']; $i++) {
                        $rows[] = [
                            'resource' => 'sfp_port',
                            'slot_ref' => "port_{$i}",
                            'capacity' => 1,
                            'owner_component_id' => $c['id'],
                        ];
                    }
                    continue;
                }
                $rows[] = [
                    'resource' => $p['resource'],
                    'slot_ref' => $p['slot_ref'],
                    'capacity' => $p['capacity'],
                    'owner_component_id' => $c['id'],
                ];
            }
        }
        $this->resourceRows = $rows;
        return $rows;
    }

    /**
     * The spec_uuid of the motherboard an onboard NIC row belongs to:
     * rows-path rows carry parent_id -> the board row (ConfigComponentWriter::
     * resolveParentId()); json-fallback rows don't, so fall back to matching
     * the board-uuid prefix encoded in the synthetic uuid itself against the
     * motherboards present in this state. Null (fail-open, like legacy's
     * resolveOnboardNicSpecs()) when neither resolves.
     */
    private function onboardParentBoardSpecUuid(array $onboardNic): ?string
    {
        if ($onboardNic['parent_id'] !== null) {
            $parent = $this->find($onboardNic['parent_id']);
            if ($parent !== null && $parent['component_type'] === 'motherboard') {
                return $parent['spec_uuid'];
            }
        }
        $parsed = ResourceCatalog::parseOnboardNicUuid((string)$onboardNic['spec_uuid']);
        if ($parsed === null) {
            return null;
        }
        foreach ($this->byType('motherboard') as $mb) {
            if (strpos((string)$mb['spec_uuid'], $parsed['board_prefix']) === 0) {
                return $mb['spec_uuid'];
            }
        }
        return null;
    }

    /** @return array[] provider rows for one resource type */
    public function byResource(string $resource): array
    {
        return array_values(array_filter($this->resources(), function ($r) use ($resource) {
            return $r['resource'] === $resource;
        }));
    }

    /**
     * Discrete-slot free/used lookup: a resource row is "used" if some live
     * component's slot_ref equals its slot_ref (scoped to owner_component_id
     * when the row has one, e.g. sfp ports are per-NIC).
     *
     * @return array[] provider rows for $resource with no matching consumer
     */
    public function freeSlots(string $resource): array
    {
        $used = [];
        foreach ($this->components as $c) {
            if ($c['slot_ref'] === null) {
                continue;
            }
            $used[$c['parent_id'] . '|' . $c['slot_ref']] = true;
            // The UNSCOPED key is what makes a pcie_slot / riser_slot consumer occupy
            // its slot from the global pool: those consumers carry parent_id null, so
            // the scoped key above is already '|<slot_ref>'.
            //
            // It used to be registered for EVERY consumer, parented or not, which
            // cross-contaminated per-NIC resources: an SFP in port_1 of NIC A marked
            // port_1 of every other NIC as occupied, so a second NIC's ports read as
            // full and NetSfpPortRule refused modules that had somewhere to go. Only
            // register it when the parent has NOT resolved -- there the conservative
            // "occupies a port somewhere" reading is the right one.
            if ($c['parent_id'] === null) {
                $used['|' . $c['slot_ref']] = true;
            }
        }
        return array_values(array_filter($this->byResource($resource), function ($r) use ($used) {
            $scopedKey = $r['owner_component_id'] . '|' . $r['slot_ref'];
            $unscopedKey = '|' . $r['slot_ref'];
            return !isset($used[$scopedKey]) && !isset($used[$unscopedKey]);
        }));
    }

    /**
     * Sum of a pooled/scalar resource's total capacity (providers) minus
     * total consumed amount (consumers) across all live components.
     */
    public function poolBalance(string $resource): int
    {
        $capacity = 0;
        foreach ($this->byResource($resource) as $r) {
            $capacity += (int)$r['capacity'];
        }
        $consumed = 0;
        foreach ($this->components as $c) {
            foreach ($this->catalog->consumes($c['component_type'], $c['spec_uuid']) as $cons) {
                if ($cons['resource'] === $resource) {
                    $consumed += (int)$cons['amount'];
                }
            }
        }
        return $capacity - $consumed;
    }
}
