<?php

require_once __DIR__ . '/StatusMap.php';

/**
 * StateMachine — the one place that knows whether a status_v2 transition is
 * legal (data-driven: reads config_status_transitions / inventory_status_transitions
 * from U-SM.2, never a hardcoded switch) and the one place that performs a
 * transition write (status_v2 + mapped legacy int, atomically, plus a
 * config_events('transition') row for the config side).
 *
 * Never opens/commits a transaction (spirit of INV-3) and never locks a row
 * itself — every method here requires the caller to already hold both.
 *
 * U-SM.3 wired the WRITE side (apply*) into ServerBuilder so status_v2 tracks
 * legacy status. The READ side (assert*) enforces through StateGuard and, for
 * transitions, BaseCommand — unconditionally since U-D.4 removed
 * STATE_MACHINE_ENABLED.
 */
class StateMachine
{
    /**
     * Is $to legal from the config's current status_v2, and does $userId hold
     * the permission the edge requires? Read-only.
     *
     * TWO QUESTIONS, NOT ONE. Legality ("is draft -> building an edge at all")
     * is a property of the CONFIGURATION and is never optional. Permission
     * ("may this person walk that edge") is a property of the ACTOR, and there
     * is one caller for which there is no actor: an approved Request, where the
     * engine performs the work and the requester deliberately never holds the
     * permission (see RequestActionExecutor's header). $systemAuthorized is
     * that caller saying so explicitly.
     *
     * It skips ONLY the ACL half. An illegal move is still refused, which is
     * what the request form promises the requester. The transition table's own
     * 'SYSTEM' sentinel says the same thing per-edge; this says it per-call,
     * because whether a Request is behind a transition is not knowable from the
     * edge.
     *
     * Defaults to false, so every ordinary caller (the transition endpoint,
     * ServerBuilder's finalize) is unchanged and still checks the actor.
     *
     * @return array{allowed: bool, requires_validation: bool, reason: string}
     */
    public static function assertConfigTransition(PDO $pdo, string $configUuid, string $to, int $userId, bool $systemAuthorized = false): array
    {
        $stmt = $pdo->prepare('SELECT status_v2 FROM server_configurations WHERE config_uuid = ?');
        $stmt->execute([$configUuid]);
        $from = $stmt->fetchColumn();

        if ($from === false) {
            return ['allowed' => false, 'requires_validation' => false, 'reason' => "config $configUuid not found"];
        }
        if ($from === null) {
            return ['allowed' => false, 'requires_validation' => false, 'reason' => 'status_v2 not yet populated for this config'];
        }

        $edgeStmt = $pdo->prepare(
            'SELECT required_permission, requires_validation FROM config_status_transitions WHERE from_status = ? AND to_status = ?'
        );
        $edgeStmt->execute([$from, $to]);
        $edge = $edgeStmt->fetch(PDO::FETCH_ASSOC);
        if ($edge === false) {
            return ['allowed' => false, 'requires_validation' => false, 'reason' => "no such transition: $from -> $to"];
        }

        $requiresValidation = $edge['requires_validation'] === 'full';
        if (!$systemAuthorized && $edge['required_permission'] !== 'SYSTEM') {
            if (!class_exists('ACL')) {
                require_once __DIR__ . '/../../auth/ACL.php';
            }
            $acl = new ACL($pdo);
            if (!$acl->hasPermission($userId, $edge['required_permission'])) {
                return ['allowed' => false, 'requires_validation' => $requiresValidation, 'reason' => "missing permission '{$edge['required_permission']}'"];
            }
        }

        return ['allowed' => true, 'requires_validation' => $requiresValidation, 'reason' => 'ok'];
    }

    /**
     * Write status_v2 = $to + mapped legacy configuration_status, then append
     * one config_events('transition') row via bumpRevision (INV-6). Does NOT
     * check legality and does NOT touch other columns (e.g. `notes`) —
     * callers persist those separately in the same transaction.
     * @return int the new revision number
     */
    public static function applyConfigTransition(PDO $pdo, string $configUuid, string $to, int $actor = 0): int
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('StateMachine::applyConfigTransition requires an active transaction');
        }
        if (!array_key_exists($to, StatusMap::CONFIG_V2_TO_LEGACY)) {
            throw new InvalidArgumentException("Unknown config status_v2 value: $to");
        }

        $legacy = StatusMap::CONFIG_V2_TO_LEGACY[$to];
        $stmt = $pdo->prepare('UPDATE server_configurations SET status_v2 = ?, configuration_status = ?, updated_at = NOW() WHERE config_uuid = ?');
        $stmt->execute([$to, $legacy, $configUuid]);

        require_once __DIR__ . '/../config/ConfigComponentRepository.php';
        $repo = new ConfigComponentRepository($pdo);
        return $repo->bumpRevision($configUuid, 'transition', ['to_status' => $to], $actor);
    }

    /**
     * Resolve the ONE inventory row a caller means, or refuse. [F-22]
     *
     * A component UUID names a MODEL in this system, not a unit: many rows of the
     * same {type}inventory table share it. SerialNumber is the only other identity
     * these two methods accepted, and it is legitimately NULL (worn label, white-box
     * part, pull -- AssetTag is the unit identity, see BaseFunctions::addComponent).
     * So for a serial-less unit the old `WHERE UUID = ?` addressed the whole MODEL:
     * assertInventoryTransition answered about whichever sibling LIMIT 1 returned,
     * and applyInventoryTransition's UPDATE (no LIMIT) rewrote status_v2 + Status on
     * EVERY unit of that model. Measured on the 2026-07-27 production dump: 15 model
     * UUIDs cover 83 serial-less units (71 ram, 9 pciecard, 3 storage), so finalizing
     * one config through TransitionStatusCommand could mark dozens of unrelated units
     * "installed" -- the F-1 bug (deleting one config freed every unit sharing a model
     * UUID) reappearing in the state machine.
     *
     * It was unreachable only by accident: assertConfigTransition() refuses every
     * config whose status_v2 is NULL (F-21), which was all five physical configs, so
     * TransitionStatusCommand::buildTarget() threw before apply() ever ran. Fixing
     * F-21 removes that shield, which is why this is fixed in the same pass.
     *
     * Preference order: explicit inventory row id (config_components carries it) >
     * UUID + serial > UUID alone, and UUID alone is accepted ONLY when it resolves to
     * exactly one row. Genuine ambiguity is refused, never resolved by guessing --
     * the same fail-closed posture as the legacy release path's ambiguity refusal.
     *
     * @return array{id: ?int, reason: ?string}
     */
    private static function resolveUnit(PDO $pdo, string $table, string $componentUuid, ?string $serialNumber, ?int $inventoryId): array
    {
        if ($inventoryId !== null) {
            $stmt = $pdo->prepare("SELECT ID FROM `$table` WHERE ID = ?");
            $stmt->execute([$inventoryId]);
            $id = $stmt->fetchColumn();
            if ($id === false) {
                return ['id' => null, 'reason' => "no $table row with ID $inventoryId"];
            }
            return ['id' => (int)$id, 'reason' => null];
        }

        $where = 'UUID = ?';
        $params = [$componentUuid];
        if ($serialNumber !== null) {
            $where .= ' AND SerialNumber = ?';
            $params[] = $serialNumber;
        }
        $stmt = $pdo->prepare("SELECT ID FROM `$table` WHERE $where");
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($ids) === 0) {
            return ['id' => null, 'reason' => "component not found in $table"];
        }
        if (count($ids) > 1) {
            return [
                'id' => null,
                'reason' => count($ids) . " units in $table share spec UUID $componentUuid and no unit identity "
                    . "(inventory id or serial) was supplied -- refusing to transition a model"
            ];
        }
        return ['id' => (int)$ids[0], 'reason' => null];
    }

    /**
     * Is $to legal from this unit's current status_v2? Read-only.
     * inventory_status_transitions carries no permission column, so there's
     * nothing to check beyond edge existence.
     *
     * $inventoryId is the unambiguous identity and is preferred when the caller has
     * it; it is optional and last so this file can deploy ahead of its callers. [F-22]
     * @return array{allowed: bool, reason: string}
     */
    public static function assertInventoryTransition(PDO $pdo, string $table, string $componentUuid, string $to, ?string $serialNumber = null, ?int $inventoryId = null): array
    {
        $unit = self::resolveUnit($pdo, $table, $componentUuid, $serialNumber, $inventoryId);
        if ($unit['id'] === null) {
            return ['allowed' => false, 'reason' => $unit['reason']];
        }

        $stmt = $pdo->prepare("SELECT status_v2 FROM `$table` WHERE ID = ?");
        $stmt->execute([$unit['id']]);
        $from = $stmt->fetchColumn();
        if ($from === false) {
            return ['allowed' => false, 'reason' => "component not found in $table"];
        }
        if ($from === null) {
            return ['allowed' => false, 'reason' => 'status_v2 not yet populated for this component'];
        }

        $edgeStmt = $pdo->prepare('SELECT 1 FROM inventory_status_transitions WHERE from_status = ? AND to_status = ?');
        $edgeStmt->execute([$from, $to]);
        if ($edgeStmt->fetchColumn() === false) {
            return ['allowed' => false, 'reason' => "no such transition: $from -> $to"];
        }

        return ['allowed' => true, 'reason' => 'ok'];
    }

    /**
     * Write a single unit's status_v2 = $to + mapped legacy Status, in one
     * UPDATE. Does NOT check legality and does NOT touch ServerUUID/Location/
     * RackPosition. General-purpose primitive for callers without their own
     * dynamic UPDATE already covering the legacy Status write — NOT used by
     * ServerBuilder::updateComponentStatusAndServerUuid, which appends
     * status_v2 into its own existing UPDATE instead (same statement, zero
     * risk of the two columns committing separately).
     */
    public static function applyInventoryTransition(PDO $pdo, string $table, string $componentUuid, string $to, ?string $serialNumber = null, ?int $inventoryId = null): void
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('StateMachine::applyInventoryTransition requires an active transaction');
        }
        if (!array_key_exists($to, StatusMap::INVENTORY_V2_TO_LEGACY)) {
            throw new InvalidArgumentException("Unknown inventory status_v2 value: $to");
        }

        // Address ONE unit, or refuse. See resolveUnit()'s docblock: this UPDATE used
        // to carry the caller's UUID straight into its WHERE clause with no LIMIT, so
        // a serial-less unit's transition rewrote every unit of that model. Throwing
        // rolls the caller's command back, which is the correct outcome for an
        // ambiguous write -- the same posture as the release path's ambiguity refusal.
        $unit = self::resolveUnit($pdo, $table, $componentUuid, $serialNumber, $inventoryId);
        if ($unit['id'] === null) {
            throw new RuntimeException("StateMachine::applyInventoryTransition: " . $unit['reason']);
        }

        $legacy = StatusMap::INVENTORY_V2_TO_LEGACY[$to];
        $pdo->prepare("UPDATE `$table` SET status_v2 = ?, Status = ? WHERE ID = ?")
            ->execute([$to, $legacy, $unit['id']]);
    }
}
