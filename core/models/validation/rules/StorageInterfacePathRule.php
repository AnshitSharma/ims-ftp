<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * RULE_MAP.md: storage.interface_path (E). Legacy:
 * StorageConnectionValidator::validate() (2009 lines total; only its first
 * ~200-line entry contract was read for this unit per the pack — "port
 * decisions, discard plumbing") + StorageConnectionAuthority.php (full,
 * 139 lines) + ComponentCompatibility chassis-bay decisions (was lines
 * 3076-3260).
 *
 * DELIBERATE SIMPLIFICATION (documented, not hidden): legacy's real path
 * search covers chassis bay / motherboard direct / HBA / PCIe adapter, and
 * only hard-BLOCKS when a SAS drive has neither an HBA nor a chassis with a
 * SAS backplane (`validate()`'s "SAS storage requires SAS HBA card OR
 * chassis with SAS backplane" branch, was lines 169-191) -- every other
 * protocol with no path is a WARNING only ("no_connection_path_yet"),
 * component-order-flexible by design, never a block. This rule ports
 * EXACTLY that blocking condition (bay/M.2/caddy capacity are separately
 * enforced by StorageBayCapacityRule/StorageM2CapacityRule/
 * StorageCaddyPairingRule).
 *
 * GAP CLOSED 2026-07-27 (F-11, was the "KNOWN GAP" this docblock previously
 * flagged): the original port checked only `!empty($state->byType('hbacard'))`
 * and never looked at the chassis, so a SAS drive in a chassis with a SAS
 * backplane was BLOCKED by the engine while legacy allowed it. Observed in
 * production shadow traffic 2026-07-26 21:59:16Z (config e7e50504, drive
 * 138e1181 "SAS 12Gb/s" into chassis 4981e5a2 whose spec declares
 * backplane.supports_sas = true, interface "SAS3"); both the engine stream
 * and the command stream recorded legacy_blocked=false / engine_blocked=true.
 * It also cascaded: because this rule was config-scoped, every SUBSEQUENT add
 * to that config (caddy, pciecard) was blocked by the same stale violation
 * until an HBA was added -- under ENGINE_MODE=enforce that would have locked
 * the operator out of the config entirely.
 *
 * CASCADE CLOSED IN GENERAL 2026-07-28 (F-24). F-11 removed one CAUSE of a
 * stale violation; the cascade itself survived, and a config holding a
 * genuinely unpathable drive still failed every add. Confirmed by fleet sweep:
 * 9 of 9 unexplained parity diffs, all config 05bcb95b, adds of ram/storage/nic
 * alike, against a config whose 3 unpathable drives are correct hardware truth
 * (NVMe U.2 + 2x SAS, chassis backplane supports_sas false, no HBA -- see
 * F-19/F-20). This rule now BLOCKS ONLY AT ITS LEGACY MOMENT: with no
 * TargetState::subject() (a snapshot or a finalize-time VALIDATE). On ADD/REPLACE
 * it passes and reports details['deferred_unpathed_storage'], because legacy's own
 * add path never consults validate() below
 * STORAGE_BAY_AUTHORITY_ENABLED=enforce -- see evaluate() for the evidence. Same
 * shape and same resolution as the owner's 2026-07-25 StorageBayCapacityRule
 * decision: match legacy now, keep the signal, tighten after cutover.
 *
 * Legacy only blocks SAS when its path list comes back EMPTY, and its path
 * search grants a chassis-bay path via
 * StorageConnectionValidator::checkChassisBackplaneCapability() (was ~245-342)
 * reading `chassis.backplane.supports_sas`. Both legacy paths that can satisfy
 * a SAS drive are now ported: a SAS-capable HBA (protocol contains "sas", as
 * legacy checks at ~499) OR a chassis whose backplane supports SAS, subject to
 * legacy's two form-factor bypasses (M.2, and pure U.2/U.3 with no 2.5"/3.5"
 * physical size, which do not use chassis bays at all).
 *
 * STILL NOT PORTED (deliberate, flagged not hidden): legacy's HBA branch also
 * blocks on port exhaustion (`hba_ports_exhausted`, fewer internal_ports than
 * the drives that would be attached). That is a DIFFERENT legacy error, not the
 * "requires HBA or SAS backplane" condition this rule owns; porting it here
 * would add a new blocking condition under this rule's id. Left for a
 * follow-up unit so it surfaces as an unexplained diff rather than silently
 * changing this rule's contract.
 */
final class StorageInterfacePathRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'storage.interface_path';
    }

    public function severity(): string
    {
        return Severity::ERROR;
    }

    public function triggers(): array
    {
        return [Trigger::ADD, Trigger::REPLACE, Trigger::VALIDATE];
    }

    public function scope(): string
    {
        return self::SCOPE_PAIR;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        // Both are config-wide facts, so resolve each once rather than per drive.
        $hasSasHba = $this->hasSasCapableHba($state);
        $sasBackplane = $this->chassisSasBackplane($state);

        // WHEN this rule is entitled to BLOCK. [F-24]
        //
        // Legacy reaches StorageConnectionValidator::validate() -- the origin of the
        // "SAS storage requires SAS HBA card OR chassis with SAS backplane" error this
        // rule ports -- from exactly two places: StorageConnectionAuthority, whose own
        // header states that validate() becomes the add-time authority only at
        // STORAGE_BAY_AUTHORITY_ENABLED=enforce, and three describe/validate-time call
        // sites in ServerBuilder. So at anything below enforce, LEGACY DOES NOT BLOCK
        // A STORAGE ADD ON INTERFACE PATH AT ALL; it simply declines to give the drive
        // a connection (connection_type 'not_connected') and surfaces the error at
        // validate-config / finalize. Confirmed in production on 2026-07-27: a SAS
        // drive added to a chassis with no SAS path was ACCEPTED and reported
        // not_connected, and only server-validate-config named the missing HBA.
        //
        // This rule blocked at ADD time, config-wide, which was harsher in two
        // independent ways -- exactly the shape of the 2026-07-25 bay_capacity finding
        // and settled the same way the owner settled that one: match legacy now, keep
        // the signal, tighten after cutover.
        //
        //   no subject  -> a fromCurrent() snapshot or a finalize-time VALIDATE: this
        //                  is legacy's own blocking moment, so judge every drive.
        //   a subject   -> an ADD/REPLACE: never block. Report how many drives lack a
        //                  path in details so the condition survives for the
        //                  post-cutover tightening pass, and let VALIDATE own it.
        $subject = $state->subject();
        if ($subject !== null) {
            $unpathed = $this->unpathedCount($state, $hasSasHba, $sasBackplane);
            return new RuleResult($this->id(), $this->severity(), true,
                'Storage connection paths are judged at validate/finalize, not per add (legacy parity)',
                $unpathed > 0
                    ? [
                        'deferred_unpathed_storage' => $unpathed,
                        'subject_type' => $subject['component_type'] ?? null,
                    ]
                    : []);
        }

        foreach ($state->byType('storage') as $storage) {
            $spec = $this->dataUtils->getStorageByUUID($storage['spec_uuid']);
            if (!is_array($spec)) {
                continue;
            }
            $interface = strtolower((string)($spec['interface'] ?? ''));
            // Mirrors legacy extractProtocol(): 'sas' is tested before 'sata',
            // and no interface string in ims-data contains both.
            if (strpos($interface, 'sas') === false) {
                continue;
            }

            if ($hasSasHba) {
                continue;
            }
            // A chassis bay only carries the drive if the drive actually uses
            // bays -- legacy's two bypasses, ported verbatim below.
            if ($sasBackplane !== null && $this->usesChassisBays((string)($spec['form_factor'] ?? ''))) {
                continue;
            }

            return new RuleResult($this->id(), $this->severity(), false,
                'SAS storage requires SAS HBA card OR chassis with SAS backplane',
                ['storage_id' => $storage['id'], 'interface' => $interface]);
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All storage has a viable connection path');
    }

    /**
     * How many drives in the state have no SAS path right now. [F-24]
     *
     * Reported in details when this evaluation declines to judge them, so the signal
     * is preserved rather than discarded -- the same posture StorageBayCapacityRule
     * took on 2026-07-25 with details['overflow'] when it stopped blocking on
     * oversubscription. A finalize-time VALIDATE (no subject) still judges them all.
     */
    private function unpathedCount(TargetState $state, bool $hasSasHba, ?string $sasBackplane): int
    {
        if ($hasSasHba) {
            return 0;
        }
        $count = 0;
        foreach ($state->byType('storage') as $storage) {
            $spec = $this->dataUtils->getStorageByUUID($storage['spec_uuid']);
            if (!is_array($spec)) {
                continue;
            }
            if (strpos(strtolower((string)($spec['interface'] ?? '')), 'sas') === false) {
                continue;
            }
            if ($sasBackplane !== null && $this->usesChassisBays((string)($spec['form_factor'] ?? ''))) {
                continue;
            }
            $count++;
        }
        return $count;
    }

    /**
     * Legacy checkHBACardPath() (~492-535) does not accept just any HBA for a
     * SAS drive -- it requires one whose spec `protocol` mentions SAS (e.g.
     * "SAS/SATA/NVMe Tri-Mode"). All 16 hbacard models in ims-data carry the
     * field, so a missing `protocol` means an unreadable spec, not a
     * non-SAS card; such a card is not counted as a path.
     */
    private function hasSasCapableHba(TargetState $state): bool
    {
        foreach ($state->byType('hbacard') as $hba) {
            $spec = $this->dataUtils->getHBACardByUUID($hba['spec_uuid']);
            if (!is_array($spec)) {
                continue;
            }
            if (stripos((string)($spec['protocol'] ?? ''), 'sas') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The chassis-bay path from legacy checkChassisBackplaneCapability():
     * `chassis.backplane.supports_sas`. Returns the chassis spec_uuid that
     * provides the path (for the RuleResult context), or null if there is no
     * such chassis. All 25 chassis entries in ims-data declare a `backplane`
     * object, so a missing one is an unreadable spec and grants no path.
     */
    private function chassisSasBackplane(TargetState $state): ?string
    {
        foreach ($state->byType('chassis') as $chassis) {
            $spec = $this->dataUtils->getChassisSpecifications($chassis['spec_uuid']);
            if (!is_array($spec)) {
                continue;
            }
            $backplane = $spec['backplane'] ?? [];
            if (is_array($backplane) && !empty($backplane['supports_sas'])) {
                return (string)$chassis['spec_uuid'];
            }
        }

        return null;
    }

    /**
     * Legacy checkChassisBackplaneCapability()'s two early bypasses: M.2 never
     * uses a chassis bay, and a PURE U.2/U.3 form factor (one that does not
     * also state a 2.5"/3.5" physical size) uses motherboard U.2 ports rather
     * than bays. "2.5-inch U.2" is a 2.5" drive and DOES use a bay.
     */
    private function usesChassisBays(string $formFactor): bool
    {
        $ff = strtolower($formFactor);

        if (strpos($ff, 'm.2') !== false) {
            return false;
        }

        $hasPhysicalSize = (strpos($ff, '2.5') !== false || strpos($ff, '3.5') !== false);
        if (!$hasPhysicalSize && (strpos($ff, 'u.2') !== false || strpos($ff, 'u.3') !== false)) {
            return false;
        }

        return true;
    }
}
