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
 * data. This class does no ims-data spec loading of its own (CLAUDE.md:
 * specs come from ComponentDataService only) — ResourceCatalog (U-L.1) is
 * the one exception, since it exists specifically to own that parsing.
 *
 * U-L.2 (ledger dual-writer): afterLegacyAdd() also inserts config_resources
 * provider rows for whatever ResourceCatalog::provides() returns, and one
 * consumption row per ResourceCatalog::consumes() entry (scalar resources
 * only, e.g. pcie_lane — see RV-1/RV-2 below for what's deliberately NOT
 * covered). A CatalogException from either call propagates exactly like a
 * repository failure: this whole method never catches, so it rolls back the
 * legacy write it followed too (fail-closed, INV-5) — this only happens
 * when the flag is already 'on' (mode() returns early above otherwise).
 *
 * DEFERRED CONSUMPTION (F-PSU fix, 2026-07-15): a consumption entry whose
 * resource has NO provider row yet in this config is NOT an error — legacy
 * imposes no build order (power/lanes are only checked at validate/finalize
 * time), so e.g. adding a CPU (consumes psu_watt) before the chassis (the
 * only psu_watt provider) must succeed exactly as it always has. Such an
 * entry is skipped with an error_log() note, and retro-attached later by
 * attachDeferredConsumers() the moment a provider of that resource is added.
 * Removing a provider already detaches its attached consumption rows
 * (cleanupLedgerForRemove() deletes by provider_id), so the two directions
 * stay symmetric. Malformed-spec CatalogExceptions still propagate
 * fail-closed as before — only provider absence is downgraded.
 *
 * RV-1 (carried from U-1.2's pack): DIMM slot consumer-linking is not
 * possible because RAM's legacy slot_ref is unknown at this layer.
 * RV-2 (new, this unit): discrete PCIe/riser slot consumer-linking (e.g. a
 * pciecard occupying a motherboard-provided pcie_slot) is NOT implemented.
 * ResourceCatalog's slot_ref naming (pcie_{n}_{width}, assigned in JSON-array
 * encounter order) has no relationship to the legacy slot-assignment
 * system's slot IDs (e.g. UnifiedSlotTracker::loadMotherboardPCIeSlots()'s
 * "pcie_{width}_slot_{n}"), so a direct slot_ref string match would either
 * never link anything or link the wrong slot. Every discrete-resource
 * provider row's consumer_id stays NULL from this unit. A follow-up unit
 * should either reconcile the two naming schemes or add a translation layer
 * before slot-level consumer linking can be implemented correctly.
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
        if ($slotRef === '') {
            // '' is "no slot assigned" in legacy POST data, not a slot named ''
            // — same normalization as Extractor::resolveEntry() (slot_report
            // treats equal non-null slot_refs as duplicates).
            $slotRef = null;
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

        $componentId = $repo->insert($configUuid, [
            'component_type'  => $type,
            'inventory_table' => $inventoryTable,
            'inventory_id'    => $inventoryId,
            'spec_uuid'       => $specUuid,
            'serial_number'   => $serial,
            'parent_id'       => $parentId,
            'slot_ref'        => $slotRef,
        ], $actor);

        self::writeLedgerForAdd($pdo, $configUuid, $type, $specUuid, $componentId);
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
        self::cleanupLedgerForRemove($pdo, $live['id']);
    }

    /**
     * Insert config_resources provider rows (from ResourceCatalog::provides())
     * and scalar consumption rows (from ResourceCatalog::consumes()) for a
     * newly-inserted config_components row. See class docblock for RV-1/RV-2.
     */
    private static function writeLedgerForAdd(PDO $pdo, $configUuid, $type, $specUuid, $componentId)
    {
        require_once __DIR__ . '/ResourceCatalog.php';
        $catalog = new ResourceCatalog();

        $providerStmt = $pdo->prepare(
            'INSERT INTO config_resources (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id)
             VALUES (?, ?, ?, ?, ?, NULL)'
        );
        $providedResources = [];
        foreach ($catalog->provides($type, $specUuid) as $row) {
            $providerStmt->execute([$configUuid, $row['resource'], $componentId, $row['slot_ref'], $row['capacity']]);
            $providedResources[$row['resource']] = true;
        }

        foreach ($catalog->consumes($type, $specUuid) as $consumed) {
            self::attachConsumption($pdo, $configUuid, $componentId, $consumed);
        }

        if (!empty($providedResources)) {
            self::attachDeferredConsumers($pdo, $catalog, $configUuid, array_keys($providedResources), $componentId);
        }
    }

    /**
     * Insert one consumption row, attached to any live provider of the
     * resource. Provider absence is a deferred state, not an error (see the
     * class docblock's DEFERRED CONSUMPTION note): the row is skipped and
     * attachDeferredConsumers() creates it when a provider is added.
     */
    private static function attachConsumption(PDO $pdo, $configUuid, $componentId, array $consumed)
    {
        $findProvider = $pdo->prepare(
            'SELECT provider_id FROM config_resources
             WHERE config_uuid = ? AND resource = ? AND consumer_id IS NULL
             LIMIT 1'
        );
        $findProvider->execute([$configUuid, $consumed['resource']]);
        $providerId = $findProvider->fetchColumn();
        if ($providerId === false) {
            error_log(
                "ConfigComponentWriter: deferred consumption of '{$consumed['resource']}' by component " .
                "id $componentId in config $configUuid — no provider present yet; will attach when one is added"
            );
            return;
        }

        $pdo->prepare(
            'INSERT INTO config_resources (config_uuid, resource, provider_id, slot_ref, capacity, consumer_id)
             VALUES (?, ?, ?, NULL, ?, ?)'
        )->execute([$configUuid, $consumed['resource'], $providerId, $consumed['amount'], $componentId]);
    }

    /**
     * After a component providing $resources is inserted, attach every live
     * component in the config whose catalog consumption of one of those
     * resources was previously deferred (has no consumption row yet). 'riser'
     * rows are queried against the catalog as 'pciecard', same normalization
     * as backfill.php's backfillLedgerForConfig().
     */
    private static function attachDeferredConsumers(PDO $pdo, ResourceCatalog $catalog, $configUuid, array $resources, $newComponentId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, component_type, spec_uuid FROM config_components
             WHERE config_uuid = ? AND removed_at IS NULL AND id <> ?'
        );
        $stmt->execute([$configUuid, $newComponentId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cc) {
            $physicalType = $cc['component_type'] === 'riser' ? 'pciecard' : $cc['component_type'];
            foreach ($catalog->consumes($physicalType, $cc['spec_uuid']) as $consumed) {
                if (!in_array($consumed['resource'], $resources, true)) {
                    continue;
                }
                $exists = $pdo->prepare(
                    'SELECT 1 FROM config_resources WHERE config_uuid = ? AND resource = ? AND consumer_id = ? LIMIT 1'
                );
                $exists->execute([$configUuid, $consumed['resource'], (int)$cc['id']]);
                if ($exists->fetchColumn()) {
                    continue;
                }
                self::attachConsumption($pdo, $configUuid, (int)$cc['id'], $consumed);
            }
        }
    }

    /**
     * Remove all ledger rows tied to a tombstoned component: rows where it
     * was the CONSUMER (its own resource consumption), and rows where it was
     * the PROVIDER (its own advertised capacity, and any consumption rows
     * attached to it as provider) — ON DELETE CASCADE only fires on a hard
     * delete of config_components, never on the soft tombstone (removed_at
     * UPDATE) ConfigComponentRepository::tombstone() performs, so this must
     * be done explicitly during the tombstone window.
     */
    private static function cleanupLedgerForRemove(PDO $pdo, $componentId)
    {
        $pdo->prepare('DELETE FROM config_resources WHERE consumer_id = ?')->execute([$componentId]);
        $pdo->prepare('DELETE FROM config_resources WHERE provider_id = ?')->execute([$componentId]);
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
