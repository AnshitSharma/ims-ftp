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
 * U-SM.3 wires only the WRITE side (apply*) into ServerBuilder, unconditionally,
 * so status_v2 starts tracking legacy status immediately. The READ side
 * (assert*) has no enforcing caller yet — that's U-SM.4 (StateGuard), gated
 * behind STATE_MACHINE_ENABLED. Until then this class changes zero
 * user-visible behavior: same legacy ints, same JSON responses.
 */
class StateMachine
{
    /**
     * Is $to legal from the config's current status_v2, and does $userId hold
     * the permission the edge requires? Read-only.
     * @return array{allowed: bool, requires_validation: bool, reason: string}
     */
    public static function assertConfigTransition(PDO $pdo, string $configUuid, string $to, int $userId): array
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
        if ($edge['required_permission'] !== 'SYSTEM') {
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
     * Is $to legal from this unit's current status_v2? Read-only.
     * inventory_status_transitions carries no permission column, so there's
     * nothing to check beyond edge existence.
     * @return array{allowed: bool, reason: string}
     */
    public static function assertInventoryTransition(PDO $pdo, string $table, string $componentUuid, string $to, ?string $serialNumber = null): array
    {
        $where = 'UUID = ?';
        $params = [$componentUuid];
        if ($serialNumber !== null) {
            $where .= ' AND SerialNumber = ?';
            $params[] = $serialNumber;
        }

        $stmt = $pdo->prepare("SELECT status_v2 FROM `$table` WHERE $where LIMIT 1");
        $stmt->execute($params);
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
    public static function applyInventoryTransition(PDO $pdo, string $table, string $componentUuid, string $to, ?string $serialNumber = null): void
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('StateMachine::applyInventoryTransition requires an active transaction');
        }
        if (!array_key_exists($to, StatusMap::INVENTORY_V2_TO_LEGACY)) {
            throw new InvalidArgumentException("Unknown inventory status_v2 value: $to");
        }

        $legacy = StatusMap::INVENTORY_V2_TO_LEGACY[$to];
        $where = 'UUID = ?';
        $params = [$to, $legacy, $componentUuid];
        if ($serialNumber !== null) {
            $where .= ' AND SerialNumber = ?';
            $params[] = $serialNumber;
        }

        $pdo->prepare("UPDATE `$table` SET status_v2 = ?, Status = ? WHERE $where")->execute($params);
    }
}
