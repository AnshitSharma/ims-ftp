<?php

require_once __DIR__ . '/../../core/models/shared/DataExtractionUtilities.php';

/**
 * Extractor — turns one server_configurations row's legacy JSON columns into
 * config_components row plans, per migration/07-component-migration/execution-packs/U-B.2.md.
 *
 * Mirrors ServerBuilder::extractComponentsFromJson()'s per-column JSON shapes
 * (does NOT call it — backfill must not depend on ServerBuilder). Every entry
 * is resolved to exactly one physical inventory row before it becomes a plan;
 * anything that cannot be resolved with confidence is quarantined with a
 * distinct reason instead of guessed (INV-11 checklist: "No guessing").
 *
 * Plans are returned in parent-before-child order (motherboard/chassis, then
 * cpu/ram, then storage/caddy, then pciecard incl. riser retyping, then
 * hbacard, then nic incl. onboard, then sfp) so a caller inserting them in
 * order can resolve 'parent_ref' markers ('motherboard', 'riser', or
 * ['nic_spec_uuid' => uuid]) against ids already assigned to earlier plans.
 */
class Extractor
{
    private const INVENTORY_TABLES = [
        'chassis' => 'chassisinventory', 'cpu' => 'cpuinventory', 'ram' => 'raminventory',
        'storage' => 'storageinventory', 'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory', 'caddy' => 'caddyinventory', 'pciecard' => 'pciecardinventory',
        'hbacard' => 'hbacardinventory', 'sfp' => 'sfpinventory',
    ];

    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    /**
     * @return array{plans: array[], quarantine: array[]}
     */
    public function extract(PDO $pdo, array $configRow): array
    {
        $configUuid = $configRow['config_uuid'];
        $plans = [];
        $quarantine = [];

        if (!empty($configRow['motherboard_uuid'])) {
            $this->resolveEntry($pdo, $configUuid, 'motherboard', 'motherboard', ['uuid' => $configRow['motherboard_uuid']], null, $plans, $quarantine);
        }
        if (!empty($configRow['chassis_uuid'])) {
            $this->resolveEntry($pdo, $configUuid, 'chassis', 'chassis', ['uuid' => $configRow['chassis_uuid']], null, $plans, $quarantine);
        }

        if (!empty($configRow['cpu_configuration'])) {
            $cpuConfig = json_decode($configRow['cpu_configuration'], true);
            foreach ((array)($cpuConfig['cpus'] ?? []) as $cpu) {
                $this->resolveQuantity($pdo, $configUuid, 'cpu', $cpu, $plans, $quarantine);
            }
        }
        if (!empty($configRow['ram_configuration'])) {
            $ramConfigs = json_decode($configRow['ram_configuration'], true);
            foreach ((array)$ramConfigs as $ram) {
                $this->resolveQuantity($pdo, $configUuid, 'ram', $ram, $plans, $quarantine);
            }
        }

        if (!empty($configRow['storage_configuration'])) {
            $storageConfigs = json_decode($configRow['storage_configuration'], true);
            foreach ((array)$storageConfigs as $storage) {
                // BEST-EFFORT key name (not confirmed in this unit's read scope, mirrors
                // the RISER_SUBTYPE_KEYS caveat in equivalence_report.php): absence is
                // expected and NOT quarantined, per the pack ("skipped, reason logged
                // not quarantined") — a missing slot_ref only blocks ledger consumer
                // linking (already deferred, see RV-1/RV-2), not row insertion.
                $slotRef = $storage['connection']['bay'] ?? null;
                $this->resolveEntry($pdo, $configUuid, 'storage', 'storage', $storage, null, $plans, $quarantine, $slotRef);
            }
        }
        if (!empty($configRow['caddy_configuration'])) {
            $caddyConfigs = json_decode($configRow['caddy_configuration'], true);
            foreach ((array)$caddyConfigs as $caddy) {
                $this->resolveEntry($pdo, $configUuid, 'caddy', 'caddy', $caddy, null, $plans, $quarantine);
            }
        }

        $this->extractPciecards($pdo, $configUuid, $configRow, $plans, $quarantine);
        $this->extractHbacard($pdo, $configUuid, $configRow, $plans, $quarantine);
        $this->extractNics($pdo, $configUuid, $configRow, $plans, $quarantine);
        $this->extractSfps($pdo, $configUuid, $configRow, $plans, $quarantine);

        return ['plans' => $plans, 'quarantine' => $quarantine];
    }

    /**
     * pciecard_configurations: each entry is retyped 'riser' when its spec is
     * a Riser Card (component_subtype, per ResourceCatalog::providesPciecard())
     * or its uuid carries the 'riser-' prefix. Riser entries resolve first (so
     * a plain card can reference the single riser present, if unambiguous);
     * risers themselves parent to the motherboard, like cpu/ram.
     */
    private function extractPciecards(PDO $pdo, string $configUuid, array $configRow, array &$plans, array &$quarantine): void
    {
        if (empty($configRow['pciecard_configurations'])) {
            return;
        }
        $pcieConfigs = (array)json_decode($configRow['pciecard_configurations'], true);

        $riserEntries = [];
        $plainEntries = [];
        foreach ($pcieConfigs as $pcie) {
            if ($this->isRiser($pcie['uuid'] ?? null)) {
                $riserEntries[] = $pcie;
            } else {
                $plainEntries[] = $pcie;
            }
        }

        foreach ($riserEntries as $pcie) {
            $slotRef = $pcie['slot_position'] ?? null;
            $this->resolveEntry($pdo, $configUuid, 'riser', 'pciecard', $pcie, 'motherboard', $plans, $quarantine, $slotRef);
        }

        // Only link a plain card to 'riser' when exactly one riser is present in
        // this config — with >1 risers there is no confirmed way (within this
        // unit's read scope) to tell which riser a given slot_position belongs
        // to, so we fall back to the motherboard rather than guess (RV-2).
        $singleRiser = count($riserEntries) === 1;
        foreach ($plainEntries as $pcie) {
            $slotRef = $pcie['slot_position'] ?? null;
            $parentRef = ($singleRiser && $slotRef !== null && stripos($slotRef, 'riser') !== false)
                ? 'riser' : 'motherboard';
            $this->resolveEntry($pdo, $configUuid, 'pciecard', 'pciecard', $pcie, $parentRef, $plans, $quarantine, $slotRef);
        }
    }

    /**
     * hbacard triple format, precedence exactly as
     * ServerBuilder::updateHbaCardConfiguration()/equivalence_report.php's
     * canonicalizeJsonSide(): array > single-object > scalar hbacard_uuid
     * fallback (only when hbacard_config is empty/'[]') — this precedence is
     * what fixes the dedup gap flagged in U-B.1's handoff.
     */
    private function extractHbacard(PDO $pdo, string $configUuid, array $configRow, array &$plans, array &$quarantine): void
    {
        $configEmpty = empty($configRow['hbacard_config']) || $configRow['hbacard_config'] === '[]';
        if (!$configEmpty) {
            $decoded = json_decode($configRow['hbacard_config'], true);
            $hbaArray = isset($decoded['uuid']) ? [$decoded] : (is_array($decoded) ? $decoded : []);
            foreach ($hbaArray as $hba) {
                $slotRef = $hba['slot_position'] ?? null;
                $parentRef = $slotRef !== null && stripos($slotRef, 'riser') !== false ? 'riser' : 'motherboard';
                $this->resolveEntry($pdo, $configUuid, 'hbacard', 'hbacard', $hba, $parentRef, $plans, $quarantine, $slotRef);
            }
        } elseif (!empty($configRow['hbacard_uuid'])) {
            $this->resolveEntry($pdo, $configUuid, 'hbacard', 'hbacard', ['uuid' => $configRow['hbacard_uuid']], 'motherboard', $plans, $quarantine);
        }
    }

    /**
     * nic_config['nics']: onboard NICs (uuid prefix 'onboard-') live in the
     * same array as add-on NICs (OnboardNICHandler writes them there too) and
     * parent to the motherboard; regular NICs get no parent (matches
     * ConfigComponentWriter::resolveParentId(), which only resolves sfp/onboard-nic).
     */
    private function extractNics(PDO $pdo, string $configUuid, array $configRow, array &$plans, array &$quarantine): void
    {
        if (empty($configRow['nic_config'])) {
            return;
        }
        $nicConfig = json_decode($configRow['nic_config'], true);
        foreach ((array)($nicConfig['nics'] ?? []) as $nic) {
            $uuid = $nic['uuid'] ?? null;
            $isOnboard = $uuid !== null && strpos((string)$uuid, 'onboard-') === 0;
            // Add-on NICs carry slot_position in real data, same as pciecard/hbacard
            // (contrary to the U-L.5 handoff's assumption that this field doesn't
            // exist for nic_config — confirmed via a real production fixture during
            // this session's slot_report triage). Onboard NICs legitimately have no
            // discrete slot; slot_report.php already excludes them.
            $slotRef = $isOnboard ? null : ($nic['slot_position'] ?? null);
            $this->resolveEntry($pdo, $configUuid, 'nic', 'nic', $nic, $isOnboard ? 'motherboard' : null, $plans, $quarantine, $slotRef);
        }
    }

    /**
     * sfp_configuration: 'sfps' (assigned, carries parent_nic_uuid/port_index)
     * and 'unassigned_sfps' (neither). slot_ref is 'port_{index}' only when
     * assigned. parent_ref is resolved by the caller against the nic plan
     * sharing that spec_uuid (first match, same simplification
     * ConfigComponentWriter::resolveParentId() already makes for live dual-write).
     */
    private function extractSfps(PDO $pdo, string $configUuid, array $configRow, array &$plans, array &$quarantine): void
    {
        if (empty($configRow['sfp_configuration'])) {
            return;
        }
        $sfpConfig = json_decode($configRow['sfp_configuration'], true);
        $groups = array_merge((array)($sfpConfig['sfps'] ?? []), (array)($sfpConfig['unassigned_sfps'] ?? []));
        foreach ($groups as $sfp) {
            $portIndex = $sfp['port_index'] ?? null;
            $parentNicUuid = $sfp['parent_nic_uuid'] ?? null;
            $slotRef = $portIndex !== null ? 'port_' . $portIndex : null;
            $parentRef = $parentNicUuid !== null ? ['nic_spec_uuid' => $parentNicUuid] : null;
            $this->resolveEntry($pdo, $configUuid, 'sfp', 'sfp', $sfp, $parentRef, $plans, $quarantine, $slotRef);
        }
    }

    private function isRiser(?string $uuid): bool
    {
        if ($uuid === null) {
            return false;
        }
        if (strpos($uuid, 'riser-') === 0) {
            return true;
        }
        try {
            $spec = $this->dataUtils->getPCIeCardByUUID($uuid);
        } catch (\Throwable $e) {
            return false; // spec lookup failure -> not confidently a riser; the plain pciecard path resolves/quarantines this entry on its own terms
        }
        return is_array($spec) && ($spec['component_subtype'] ?? '') === 'Riser Card';
    }

    /**
     * cpu/ram quantity quirk: Q>1 expands to Q rows ONLY when Q distinct
     * inventory rows (same spec_uuid, ServerUUID = this config) exist;
     * otherwise quarantined ('quantity-without-serials') rather than guessed
     * which serial maps to which JSON entry.
     */
    private function resolveQuantity(PDO $pdo, string $configUuid, string $type, array $entry, array &$plans, array &$quarantine): void
    {
        $qty = (int)($entry['quantity'] ?? 1);
        if ($qty <= 1) {
            $this->resolveEntry($pdo, $configUuid, $type, $type, $entry, 'motherboard', $plans, $quarantine);
            return;
        }

        $specUuid = $entry['uuid'] ?? null;
        if ($specUuid === null) {
            $quarantine[] = ['type' => $type, 'entry' => $entry, 'reason' => 'missing-uuid'];
            return;
        }

        $table = self::INVENTORY_TABLES[$type];
        $stmt = $pdo->prepare("SELECT ID, SerialNumber FROM `$table` WHERE UUID = ? AND ServerUUID = ?");
        $stmt->execute([$specUuid, $configUuid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== $qty) {
            $quarantine[] = ['type' => $type, 'entry' => $entry, 'reason' => 'quantity-without-serials'];
            return;
        }

        foreach ($rows as $row) {
            $plans[] = [
                'component_type' => $type, 'spec_uuid' => $specUuid, 'serial_number' => $row['SerialNumber'],
                'inventory_table' => $table, 'inventory_id' => (int)$row['ID'], 'slot_ref' => null,
                'parent_ref' => 'motherboard',
            ];
        }
    }

    /**
     * Single-unit resolution shared by every component type: an explicit
     * serial resolves by (UUID, SerialNumber); a serial-less entry must
     * resolve to exactly one inventory row for (UUID, ServerUUID = this
     * config) — 0 or >1 candidates is 'ambiguous-serial', never guessed.
     *
     * $componentType is the config_components.component_type to record
     * (e.g. 'riser'); $physicalType picks the inventory table to query
     * (e.g. 'pciecard' for a riser, since the physical unit is still a card).
     */
    private function resolveEntry(
        PDO $pdo, string $configUuid, string $componentType, string $physicalType,
        array $entry, $parentRef, array &$plans, array &$quarantine, ?string $slotRef = null
    ): void {
        if ($slotRef === '') {
            // Real fleet data carries slot_position:"" for never-assigned slots
            // (e.g. 809d10c9's pciecards); '' is "no slot", not a slot named '',
            // and slot_report treats equal non-null slot_refs as duplicates.
            $slotRef = null;
        }
        $specUuid = $entry['uuid'] ?? null;
        if ($specUuid === null) {
            $quarantine[] = ['type' => $componentType, 'entry' => $entry, 'reason' => 'missing-uuid'];
            return;
        }

        $table = self::INVENTORY_TABLES[$physicalType] ?? null;
        if ($table === null) {
            $quarantine[] = ['type' => $componentType, 'entry' => $entry, 'reason' => 'unknown-component-type'];
            return;
        }

        $serial = $entry['serial_number'] ?? null;
        if ($serial !== null) {
            $stmt = $pdo->prepare("SELECT ID, SerialNumber FROM `$table` WHERE UUID = ? AND SerialNumber = ? LIMIT 1");
            $stmt->execute([$specUuid, $serial]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $quarantine[] = ['type' => $componentType, 'entry' => $entry, 'reason' => 'serial-not-found'];
                return;
            }
        } else {
            $stmt = $pdo->prepare("SELECT ID, SerialNumber FROM `$table` WHERE UUID = ? AND ServerUUID = ?");
            $stmt->execute([$specUuid, $configUuid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                $quarantine[] = ['type' => $componentType, 'entry' => $entry, 'reason' => 'ambiguous-serial'];
                return;
            }
            $row = $rows[0];
        }

        $plans[] = [
            'component_type' => $componentType, 'spec_uuid' => $specUuid, 'serial_number' => $row['SerialNumber'],
            'inventory_table' => $table, 'inventory_id' => (int)$row['ID'], 'slot_ref' => $slotRef,
            'parent_ref' => $parentRef,
        ];
    }

    /**
     * Persist one plan (config_components row + config_events 'backfill'
     * event, carrying run_id so --rollback-run can find it). Does NOT reuse
     * ConfigComponentRepository::insert() — that hardcodes event='add' with
     * no run_id, which would make backfilled rows indistinguishable from
     * live dual-write rows and un-rollback-able by run. Reuses its public
     * bumpRevision() (already generic over $event) instead of duplicating
     * revision/config_events bookkeeping.
     */
    public function persistPlan(PDO $pdo, ConfigComponentRepository $repo, string $configUuid, array $plan, ?int $parentId, string $runId, $actor): int
    {
        $stmt = $pdo->prepare('
            INSERT INTO config_components
                (config_uuid, component_type, inventory_table, inventory_id, spec_uuid,
                 serial_number, parent_id, slot_ref, added_at, added_by, removed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NULL)
            ON DUPLICATE KEY UPDATE
                config_uuid = VALUES(config_uuid), component_type = VALUES(component_type),
                spec_uuid = VALUES(spec_uuid), serial_number = VALUES(serial_number),
                parent_id = VALUES(parent_id), slot_ref = VALUES(slot_ref),
                added_at = VALUES(added_at), added_by = VALUES(added_by), removed_at = NULL
        ');
        $stmt->execute([
            $configUuid, $plan['component_type'], $plan['inventory_table'], $plan['inventory_id'],
            $plan['spec_uuid'], $plan['serial_number'], $parentId, $plan['slot_ref'], $actor,
        ]);

        $id = (int)$pdo->lastInsertId();
        if ($id === 0) {
            $lookup = $pdo->prepare('SELECT id FROM config_components WHERE inventory_table = ? AND inventory_id = ?');
            $lookup->execute([$plan['inventory_table'], $plan['inventory_id']]);
            $id = (int)$lookup->fetchColumn();
        }

        $repo->bumpRevision($configUuid, 'backfill', [
            'component_id' => $id, 'component_type' => $plan['component_type'], 'run_id' => $runId,
        ], $actor);

        return $id;
    }
}
