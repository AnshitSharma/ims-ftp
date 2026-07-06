<?php

/**
 * ConfigComponentRepository
 *
 * The single class that reads and writes config_components, and appends the
 * paired config_events row / bumps server_configurations.revision that every
 * write must carry (INV-6). Nothing calls this class yet (U-1.4) — it exists
 * so later units (dual-write, command layer) have one place to change.
 *
 * Every write method REQUIRES an already-open PDO transaction and THROWS if
 * none is active: this repository never owns transactions itself (a
 * pre-echo of INV-3 — commands will be the only transaction owners).
 *
 * NOTE on insert()'s ON DUPLICATE KEY UPDATE: config_components.uq_inventory_once
 * is (inventory_table, inventory_id) with NO removed_at column (see
 * database/seeders/2026_07_06_001_create-config-components.sql and
 * migration/handoffs/U-1.1-20260706.md for the full reasoning — MySQL/MariaDB
 * treat NULL as distinct in unique keys, so a 3-column key including
 * removed_at would not actually stop two simultaneously-live rows for the
 * same physical unit, which is what INV-1's mechanical check requires).
 * That means a physical unit placed, then removed, already HAS a row
 * (tombstoned: removed_at NOT NULL) — insert() must reactivate that row
 * rather than attempt a second INSERT for the same (inventory_table,
 * inventory_id), or it would hit a duplicate-key error on every remove-then-
 * re-add. Callers see one insert(...) call either way; which SQL path ran
 * is an internal detail.
 */
class ConfigComponentRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    private function requireTransaction($method)
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException("ConfigComponentRepository::$method() requires an active transaction");
        }
    }

    /**
     * Live (not tombstoned) component rows for a config.
     *
     * @return array[]
     */
    public function liveRows($configUuid)
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM config_components WHERE config_uuid = ? AND removed_at IS NULL'
        );
        $stmt->execute([$configUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Place a physical unit into a config. Bumps revision + appends an 'add'
     * event atomically with the row write.
     *
     * $row keys: component_type, inventory_table, inventory_id, spec_uuid
     * (required); serial_number, parent_id, slot_ref, added_by (optional;
     * added_by defaults to $actor).
     *
     * @return int config_components.id — the existing row's id if this call
     *             reactivated a previously-tombstoned physical unit (see the
     *             class docblock), otherwise a freshly generated id.
     */
    public function insert($configUuid, array $row, $actor)
    {
        $this->requireTransaction('insert');

        $componentType  = $row['component_type'];
        $inventoryTable = $row['inventory_table'];
        $inventoryId    = $row['inventory_id'];
        $specUuid       = $row['spec_uuid'];
        $serialNumber   = $row['serial_number'] ?? null;
        $parentId       = $row['parent_id'] ?? null;
        $slotRef        = $row['slot_ref'] ?? null;
        $addedBy        = $row['added_by'] ?? $actor;

        $stmt = $this->pdo->prepare('
            INSERT INTO config_components
                (config_uuid, component_type, inventory_table, inventory_id, spec_uuid,
                 serial_number, parent_id, slot_ref, added_at, added_by, removed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NULL)
            ON DUPLICATE KEY UPDATE
                config_uuid = VALUES(config_uuid),
                component_type = VALUES(component_type),
                spec_uuid = VALUES(spec_uuid),
                serial_number = VALUES(serial_number),
                parent_id = VALUES(parent_id),
                slot_ref = VALUES(slot_ref),
                added_at = VALUES(added_at),
                added_by = VALUES(added_by),
                removed_at = NULL
        ');
        $stmt->execute([
            $configUuid, $componentType, $inventoryTable, $inventoryId, $specUuid,
            $serialNumber, $parentId, $slotRef, $addedBy,
        ]);

        // lastInsertId() is unreliable on the UPDATE branch of ON DUPLICATE KEY
        // UPDATE (driver-dependent) — resolve the real id explicitly either way.
        $id = (int)$this->pdo->lastInsertId();
        if ($id === 0) {
            $lookup = $this->pdo->prepare(
                'SELECT id FROM config_components WHERE inventory_table = ? AND inventory_id = ?'
            );
            $lookup->execute([$inventoryTable, $inventoryId]);
            $id = (int)$lookup->fetchColumn();
        }

        $this->bumpRevision($configUuid, 'add', [
            'component_id'   => $id,
            'component_type' => $componentType,
        ], $actor);

        return $id;
    }

    /**
     * Remove a physical unit from its config (soft-tombstone). Bumps
     * revision + appends a 'remove' event atomically with the row write.
     */
    public function tombstone($id, $actor)
    {
        $this->requireTransaction('tombstone');

        $current = $this->pdo->prepare(
            'SELECT config_uuid, component_type FROM config_components WHERE id = ?'
        );
        $current->execute([$id]);
        $row = $current->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException("config_components.id=$id not found");
        }

        $stmt = $this->pdo->prepare('UPDATE config_components SET removed_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);

        $this->bumpRevision($row['config_uuid'], 'remove', [
            'component_id'   => $id,
            'component_type' => $row['component_type'],
        ], $actor);
    }

    /**
     * Find a specific live placement by (config, type, spec, serial).
     *
     * @return array|null
     */
    public function findLive($configUuid, $type, $specUuid, $serial)
    {
        $where = 'config_uuid = ? AND component_type = ? AND spec_uuid = ? AND removed_at IS NULL';
        $params = [$configUuid, $type, $specUuid];
        if ($serial !== null) {
            $where .= ' AND serial_number = ?';
            $params[] = $serial;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM config_components WHERE $where LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Bump server_configurations.revision and append one config_events row,
     * atomically with whatever config_components write triggered it. Shared
     * by insert()/tombstone(); callers doing a bare revision bump with no row
     * change (e.g. 'transition') may call this directly.
     *
     * $payload, if given, may set 'component_type' / 'component_id' keys —
     * those populate config_events' dedicated columns (in addition to the
     * full array being stored as the JSON payload).
     *
     * @return int the new revision number
     */
    public function bumpRevision($configUuid, $event, ?array $payload = null, $actor = 0)
    {
        $this->requireTransaction('bumpRevision');

        $stmt = $this->pdo->prepare(
            'UPDATE server_configurations SET revision = revision + 1 WHERE config_uuid = ?'
        );
        $stmt->execute([$configUuid]);

        $revStmt = $this->pdo->prepare('SELECT revision FROM server_configurations WHERE config_uuid = ?');
        $revStmt->execute([$configUuid]);
        $revision = (int)$revStmt->fetchColumn();

        $componentType = $payload['component_type'] ?? null;
        $componentId   = $payload['component_id'] ?? null;

        $eventStmt = $this->pdo->prepare('
            INSERT INTO config_events (config_uuid, revision, event, component_type, component_id, actor, payload, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $eventStmt->execute([
            $configUuid, $revision, $event, $componentType, $componentId, $actor,
            $payload !== null ? json_encode($payload) : null,
        ]);

        return $revision;
    }
}
