<?php

require_once __DIR__ . '/TargetState.php';
require_once __DIR__ . '/../config/ConfigComponentRepository.php';
require_once __DIR__ . '/../config/ResourceCatalog.php';

/**
 * Builds TargetState snapshots. Pure array math — never writes to the DB.
 * fromCurrent() reads rows if config_components has any live rows for the
 * config (post-backfill / dual-write reality); otherwise it falls back to
 * mirroring server_configurations' legacy JSON columns via
 * ServerBuilder::extractComponentsFromJson() (today's actual production
 * reality, since DUAL_WRITE_ENABLED is off) — flagged 'source'=>'json' on
 * every row it produces so parity_report.php (U-V.4) can segment rows-path
 * evaluations from json-fallback-path evaluations separately.
 *
 * KNOWN GAP (documented, not a defect): the JSON fallback path cannot
 * recover slot_ref for pciecard/hbacard (legacy JSON never stored it) or
 * parent linkage for anything but sfp->nic (via parent_nic_uuid). Rules
 * that need slot_ref (U-R.3/U-R.6) degrade to "treat as unplaced" for
 * json-source rows, matching that no live slot bookkeeping exists pre-backfill
 * either. Once U-B.4 backfills, fromCurrent() takes the rows path for every
 * config and this gap disappears on its own.
 *
 * 'status_v2' field (added U-R.7, migration/04-validation-engine): rows-path
 * components carry their {inventory_table}.status_v2 (U-SM.1's per-inventory-table
 * lifecycle enum), fetched in one batched SELECT per distinct inventory_table
 * (never per-row) so SystemInventoryStateRule can see live inventory state.
 * KNOWN GAP: json-fallback rows always get status_v2 = null — the legacy JSON
 * blob never stored a per-serial inventory status (only optionally a
 * serial_number, confirmed by reading ServerBuilder::extractComponentsFromJson()
 * in full), so there is nothing to recover pre-backfill. Same class of gap as
 * the slot_ref/parent-linkage one above; self-resolves once U-B.4 backfills.
 */
final class TargetStateBuilder
{
    public static function fromCurrent(PDO $pdo, string $configUuid): TargetState
    {
        $repo = new ConfigComponentRepository($pdo);
        $rows = $repo->liveRows($configUuid);
        if (!empty($rows)) {
            return new TargetState(self::normalizeRows($rows, self::fetchStatusV2($pdo, $rows)));
        }

        $stmt = $pdo->prepare('SELECT * FROM server_configurations WHERE config_uuid = ?');
        $stmt->execute([$configUuid]);
        $configRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$configRow) {
            return new TargetState([]);
        }

        return new TargetState(self::jsonFallbackRows($pdo, $configRow));
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

        return new TargetState(array_merge($state->components(), [$normalized]));
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

    /** @return array[] source='json' tuples mirrored from the legacy JSON columns */
    private static function jsonFallbackRows(PDO $pdo, array $configRow): array
    {
        require_once __DIR__ . '/../server/ServerBuilder.php';
        $sb = new ServerBuilder($pdo);
        $extracted = $sb->extractComponentsFromJson($configRow);

        $rows = [];
        $syntheticId = -1;
        $nicRowIdByUuid = [];

        // nic rows first so sfp entries below can resolve parent_nic_uuid -> row id.
        foreach ($extracted as $entry) {
            if ($entry['component_type'] !== 'nic' || empty($entry['component_uuid'])) {
                continue;
            }
            $id = $syntheticId--;
            $rows[] = [
                'id' => $id,
                'component_type' => 'nic',
                'spec_uuid' => $entry['component_uuid'],
                'inventory_table' => null,
                'inventory_id' => null,
                'serial_number' => null,
                'parent_id' => null,
                'slot_ref' => null,
                'source' => 'json',
                'status_v2' => null,
            ];
            $nicRowIdByUuid[$entry['component_uuid']] = $id; // last-wins if duplicate specs (ambiguous by design, see class docblock)
        }

        foreach ($extracted as $entry) {
            if ($entry['component_type'] === 'nic') {
                continue; // already emitted above
            }
            if (empty($entry['component_uuid'])) {
                continue;
            }
            $quantity = max(1, (int)($entry['quantity'] ?? 1));
            for ($i = 0; $i < $quantity; $i++) {
                $row = [
                    'id' => $syntheticId--,
                    'component_type' => $entry['component_type'],
                    'spec_uuid' => $entry['component_uuid'],
                    'inventory_table' => null,
                    'inventory_id' => null,
                    'serial_number' => $entry['serial_number'] ?? null,
                    'parent_id' => null,
                    'slot_ref' => null,
                    'source' => 'json',
                    'status_v2' => null,
                ];
                if ($entry['component_type'] === 'sfp') {
                    $parentNicUuid = $entry['parent_nic_uuid'] ?? null;
                    $row['parent_id'] = $parentNicUuid !== null ? ($nicRowIdByUuid[$parentNicUuid] ?? null) : null;
                    $row['slot_ref'] = isset($entry['port_index']) && $entry['port_index'] !== null
                        ? 'port_' . $entry['port_index']
                        : null;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
