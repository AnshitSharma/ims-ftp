<?php

require_once __DIR__ . '/TargetState.php';
require_once __DIR__ . '/../config/ConfigComponentRepository.php';
require_once __DIR__ . '/../config/ResourceCatalog.php';

/**
 * Builds TargetState snapshots. Pure array math — never writes to the DB.
 * fromCurrent() reads config_components rows, and nothing else: a config with
 * no live rows has no components. U-D.3b removed the legacy-JSON fallback and
 * its 'source' => 'json' tuples along with the columns that fed them, so every
 * row this class emits is now 'rows' or 'pending'. The two KNOWN GAPs that
 * fallback carried (no slot_ref for pciecard/hbacard, no parent linkage beyond
 * sfp->nic, and status_v2 always null) are gone with it — those were properties
 * of the JSON blob, not of the rows store.
 *
 * 'status_v2' field (added U-R.7, migration/04-validation-engine): components
 * carry their {inventory_table}.status_v2 (U-SM.1's per-inventory-table
 * lifecycle enum), fetched in one batched SELECT per distinct inventory_table
 * (never per-row) so SystemInventoryStateRule can see live inventory state.
 */
final class TargetStateBuilder
{
    public static function fromCurrent(PDO $pdo, string $configUuid): TargetState
    {
        // U-D.3b: config_components is the only store. The json fallback that used to
        // sit here (and the whole jsonFallbackRows() synthesiser behind it) is gone with
        // the columns it read. It fired for exactly one config class — a build with no
        // rows at all — and for that class the read path had ALREADY returned nothing
        // since P9 made ConfigReadRouter unconditional, so the mutation path was the
        // last place still seeing components the reader denied existed. Empty here is
        // now the same answer both sides give.
        $repo = new ConfigComponentRepository($pdo);
        $rows = $repo->liveRows($configUuid);
        if (empty($rows)) {
            return new TargetState([]);
        }
        return new TargetState(self::normalizeRows($rows, self::fetchStatusV2($pdo, $rows)));
    }

    /** @return TargetState a new state with $row appended (id assigned if absent) */
    public static function withAdd(TargetState $state, array $row): TargetState
    {
        $normalized = array_merge([
            'id' => $row['id'] ?? self::syntheticId($state),
            'component_type' => null,
            'spec_uuid' => null,
            'inventory_table' => null,
            'inventory_id' => null,
            'serial_number' => null,
            'parent_id' => null,
            'slot_ref' => null,
            'source' => 'pending',
            'status_v2' => null,
        ], $row);

        // The appended row IS the subject of this operation. [F-24]
        // withReplace() routes through here, so a replace's subject is its new row.
        return new TargetState(
            array_merge($state->components(), [$normalized]),
            null,
            $normalized['id']
        );
    }

    /**
     * @return TargetState a new state with $componentRowId (and, if $cascade,
     *         its parent_id subtree) absent.
     */
    public static function withRemove(TargetState $state, $componentRowId, bool $cascade = false): TargetState
    {
        $toRemove = [$componentRowId => true];
        if ($cascade) {
            $frontier = [$componentRowId];
            while ($frontier) {
                $next = [];
                foreach ($state->childrenOf(array_pop($frontier)) as $child) {
                    if (!isset($toRemove[$child['id']])) {
                        $toRemove[$child['id']] = true;
                        $next[] = $child['id'];
                    }
                }
                $frontier = array_merge($frontier, $next);
            }
        }

        $remaining = array_values(array_filter($state->components(), function ($c) use ($toRemove) {
            return !isset($toRemove[$c['id']]);
        }));
        return new TargetState($remaining);
    }

    /**
     * Atomic replace: one resulting TargetState with $oldId absent and
     * $newRow present — never an intermediate state a rule could observe.
     */
    public static function withReplace(TargetState $state, $oldId, array $newRow): TargetState
    {
        $without = self::withRemove($state, $oldId, false);
        return self::withAdd($without, $newRow);
    }

    /**
     * U-R.8: the full set of live rows that depend on $rowId — a recursive
     * closure over BOTH parent_id children (like withRemove(cascade=true)'s
     * own frontier walk) AND resource-consumer links (any other live row
     * whose slot_ref matches a slot_ref $rowId's component itself PROVIDES,
     * e.g. a pciecard occupying a riser-provided pcie_slot). Pure PHP loop
     * over the in-memory state (no SQL) — a general-purpose primitive for
     * "what would removing this row take with it", reusable beyond this
     * unit's own rule (e.g. a future command layer's pre-removal UX, U-C.3).
     *
     * DependencyBlockedRemovalRule (this unit) does NOT call this method
     * directly — RuleInterface::evaluate() only ever sees ONE TargetState
     * (the already-post-removal one for a REMOVE/REPLACE trigger), so it
     * cannot ask "what did removing $rowId affect" after the fact. Instead
     * it detects the EQUIVALENT condition directly in the post-removal
     * state: any live row whose parent_id no longer resolves (dangling —
     * its parent was $rowId) or whose component_type structurally requires
     * a provider type that is now completely absent. For a single
     * cascade=false removal (the only case withRemove ever produces without
     * already having removed the whole subtree itself) the two mechanisms
     * flag the same rows; see the rule's own docblock for the full
     * reasoning.
     *
     * @return array[] live component rows that depend on $rowId, one level
     *         of parent_id/slot linkage at a time, transitively closed
     */
    public static function dependentsOf(TargetState $state, $rowId): array
    {
        $root = $state->find($rowId);
        if ($root === null) {
            return [];
        }

        $catalog = new ResourceCatalog();
        $found = [];
        $visited = [$rowId => true];
        $frontier = [$rowId];

        while (!empty($frontier)) {
            $next = [];
            $providedSlotRefs = [];
            foreach ($frontier as $id) {
                $node = $state->find($id);
                if ($node === null) {
                    continue;
                }
                foreach ($catalog->provides($node['component_type'], $node['spec_uuid']) as $p) {
                    if ($p['slot_ref'] !== null) {
                        $providedSlotRefs[$p['slot_ref']] = true;
                    }
                }
            }
            foreach ($state->components() as $c) {
                if (isset($visited[$c['id']])) {
                    continue;
                }
                $isParentLinked = in_array($c['parent_id'], $frontier, true);
                $isSlotLinked = $c['slot_ref'] !== null && isset($providedSlotRefs[$c['slot_ref']]);
                if ($isParentLinked || $isSlotLinked) {
                    $found[] = $c;
                    $visited[$c['id']] = true;
                    $next[] = $c['id'];
                }
            }
            $frontier = $next;
        }

        return $found;
    }

    private static function syntheticId(TargetState $state)
    {
        $min = 0;
        foreach ($state->components() as $c) {
            if (is_int($c['id']) && $c['id'] < $min) {
                $min = $c['id'];
            }
        }
        return $min - 1;
    }

    /** @return array[] source='rows' tuples from a ConfigComponentRepository::liveRows() result */
    private static function normalizeRows(array $rows, array $statusById = []): array
    {
        return array_map(function ($row) use ($statusById) {
            $statusKey = $row['inventory_table'] . ':' . $row['inventory_id'];
            return [
                'id' => (int)$row['id'],
                'component_type' => $row['component_type'],
                'spec_uuid' => $row['spec_uuid'],
                'inventory_table' => $row['inventory_table'],
                'inventory_id' => $row['inventory_id'] !== null ? (int)$row['inventory_id'] : null,
                'serial_number' => $row['serial_number'],
                'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
                'slot_ref' => $row['slot_ref'],
                'source' => 'rows',
                'status_v2' => $statusById[$statusKey] ?? null,
            ];
        }, $rows);
    }

    /**
     * One batched SELECT per distinct inventory_table (never per-row) fetching
     * {table}.status_v2 for every (inventory_table, inventory_id) pair among
     * $rows. inventory_table is a soft-FK table name written only by this
     * migration's own repositories (ConfigComponentRepository), never raw user
     * input, but is still validated against a strict identifier pattern before
     * being interpolated into the SQL (identifiers cannot be bound as PDO
     * params) — fails closed (throws) on anything unexpected rather than
     * silently skipping a table.
     *
     * @return array<string,?string> "{inventory_table}:{inventory_id}" -> status_v2
     */
    private static function fetchStatusV2(PDO $pdo, array $rows): array
    {
        $idsByTable = [];
        foreach ($rows as $row) {
            if ($row['inventory_table'] === null || $row['inventory_id'] === null) {
                continue;
            }
            $idsByTable[$row['inventory_table']][] = (int)$row['inventory_id'];
        }

        $statusById = [];
        foreach ($idsByTable as $table => $ids) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
                throw new RuntimeException("TargetStateBuilder::fetchStatusV2(): invalid inventory_table name '$table'");
            }
            $ids = array_values(array_unique($ids));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, status_v2 FROM `$table` WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $statusById["$table:{$r['id']}"] = $r['status_v2'];
            }
        }
        return $statusById;
    }

}
