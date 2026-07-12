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

    /**
     * @param array[] $components normalized component row tuples (see class docblock)
     */
    public function __construct(array $components, ?ResourceCatalog $catalog = null)
    {
        $this->components = array_values($components);
        $this->catalog = $catalog ?? new ResourceCatalog();
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
            $provided = $this->catalog->provides($c['component_type'], $c['spec_uuid']);
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
            if ($c['slot_ref'] !== null) {
                $used[$c['parent_id'] . '|' . $c['slot_ref']] = true;
                $used['|' . $c['slot_ref']] = true; // unscoped match (pcie_slot/riser_slot: global pool)
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
