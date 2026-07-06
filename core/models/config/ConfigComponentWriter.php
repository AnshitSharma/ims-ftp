<?php

/**
 * ConfigComponentWriter
 *
 * U-1.5 dual-write hook. When DUAL_WRITE_ENABLED=on, every legacy JSON
 * mutation in ServerBuilder also writes through ConfigComponentRepository,
 * in the SAME transaction as the legacy write. Default off => legacy
 * behavior is byte-identical (this class is not even required by
 * ServerBuilder's call sites unless the flag is on... actually it is always
 * required but mode() short-circuits before touching the repository).
 *
 * Fail-closed (INV-5): neither method catches exceptions. A repository
 * failure (e.g. a FK violation) propagates to the caller's existing
 * try/catch, which rolls back the whole transaction — the legacy write
 * this call followed is undone too, so the two stores never diverge.
 *
 * parent_id resolution is BEST-EFFORT NULL: only the two trivially-known
 * cases are resolved here (sfp -> its parent NIC's row, onboard nic -> the
 * config's motherboard row). Every other component_type gets parent_id =
 * NULL; the real backfill (U-B.2) owns filling in the rest from historical
 * data. This class does no ims-data spec loading (CLAUDE.md: specs come
 * from ComponentDataService only, and this hook has no need for specs).
 */
class ConfigComponentWriter
{
    /**
     * Current rollout mode. Reads env; falls back to "off" per FLAGS.md.
     *
     * @return string one of "on", "off"
     */
    public static function mode(): string
    {
        $mode = getenv('DUAL_WRITE_ENABLED');
        if (!is_string($mode) || $mode === '') {
            $mode = $_ENV['DUAL_WRITE_ENABLED'] ?? 'off';
        }
        $mode = strtolower(trim((string)$mode));
        if (!in_array($mode, ['on', 'off'], true)) {
            return 'off';
        }
        return $mode;
    }

    /**
     * Call after ServerBuilder's legacy add write succeeds, still inside the
     * same transaction. No-op when the flag is off.
     *
     * $parentSpecUuid is the catalog UUID of a trivially-known parent (the
     * NIC a SFP is being inserted into); pass null when there is none / it
     * isn't known at the call site. Onboard-NIC -> motherboard parentage
     * needs no hint from the caller; it's resolved here from $configUuid.
     */
    public static function afterLegacyAdd(
        PDO $pdo,
        $configUuid,
        $type,
        $specUuid,
        $serial,
        $slotRef,
        $inventoryTable,
        $inventoryId,
        $actor,
        $parentSpecUuid = null
    ) {
        if (self::mode() !== 'on') {
            return;
        }
        if ($inventoryTable === null || $inventoryId === null) {
            // Nothing to key config_components' unique row on (e.g. virtual
            // configs never reach here — callers guard that — but stay
            // fail-closed if a future caller forgets to).
            throw new InvalidArgumentException(
                'ConfigComponentWriter::afterLegacyAdd requires a real inventory_table/inventory_id'
            );
        }

        require_once __DIR__ . '/ConfigComponentRepository.php';
        $repo = new ConfigComponentRepository($pdo);

        $parentId = self::resolveParentId($pdo, $repo, $configUuid, $type, $specUuid, $parentSpecUuid);

        $repo->insert($configUuid, [
            'component_type'  => $type,
            'inventory_table' => $inventoryTable,
            'inventory_id'    => $inventoryId,
            'spec_uuid'       => $specUuid,
            'serial_number'   => $serial,
            'parent_id'       => $parentId,
            'slot_ref'        => $slotRef,
        ], $actor);
    }

    /**
     * Call after ServerBuilder's legacy remove write succeeds, still inside
     * the same transaction. No-op when the flag is off, or when there is no
     * live config_components row to tombstone (e.g. the row predates the
     * dual-write window).
     */
    public static function afterLegacyRemove(PDO $pdo, $configUuid, $type, $specUuid, $serial, $actor)
    {
        if (self::mode() !== 'on') {
            return;
        }

        require_once __DIR__ . '/ConfigComponentRepository.php';
        $repo = new ConfigComponentRepository($pdo);

        $live = $repo->findLive($configUuid, $type, $specUuid, $serial);
        if ($live === null) {
            return;
        }
        $repo->tombstone($live['id'], $actor);
    }

    private static function resolveParentId(PDO $pdo, ConfigComponentRepository $repo, $configUuid, $type, $specUuid, $parentSpecUuid)
    {
        if ($type === 'sfp' && $parentSpecUuid !== null) {
            $nic = $repo->findLive($configUuid, 'nic', $parentSpecUuid, null);
            return $nic['id'] ?? null;
        }

        if ($type === 'nic' && strpos((string)$specUuid, 'onboard-') === 0) {
            $stmt = $pdo->prepare('SELECT motherboard_uuid FROM server_configurations WHERE config_uuid = ?');
            $stmt->execute([$configUuid]);
            $motherboardUuid = $stmt->fetchColumn();
            if ($motherboardUuid) {
                $motherboard = $repo->findLive($configUuid, 'motherboard', $motherboardUuid, null);
                return $motherboard['id'] ?? null;
            }
        }

        return null;
    }
}
