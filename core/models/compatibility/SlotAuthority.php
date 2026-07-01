<?php
/**
 * SlotAuthority — M11 Phase 3 / Phase 5 standalone class.
 *
 * Reconciles the add-time size-bucket PCIe slot model (ComponentCompatibility)
 * with the finalize-time slot-ID model (UnifiedSlotTracker). Extracted from the
 * inline gate in ServerBuilder::validateComponentAddition() in Phase 5.
 *
 * Only acts when the motherboard spec has expansion_slots.pcie_slots with real
 * slot IDs (tracker returns success:true); if the key is absent the tracker returns
 * success:false and this authority is a silent no-op (no false divergences).
 *
 * Flag: SLOT_AUTHORITY_ENABLED
 *   off     = legacy size-bucket model at add-time (default; golden-master byte-identical)
 *   shadow  = also run UnifiedSlotTracker, log divergence when tracker has real slot data
 *   enforce = UnifiedSlotTracker is the authority when success:true
 *
 * Phase 5 wiring note:
 *   ServerBuilder::validateComponentAddition() delegates the inline gate to this class.
 *   ValidationPipeline::run() will call evaluate() directly once VALIDATION_PIPELINE_ENABLED
 *   moves to enforce, at which point the inline delegate block in ServerBuilder is removed.
 *
 * @see UnifiedSlotTracker — provides getSlotAvailability(), the finalize-time model
 * @see ServerBuilder::validateComponentAddition() — current call site
 */
class SlotAuthority
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Mode reader — returns 'off' | 'shadow' | 'enforce'.
     * Reads SLOT_AUTHORITY_ENABLED env var; any unrecognised value maps to 'off'.
     */
    public static function mode(): string
    {
        $m = getenv('SLOT_AUTHORITY_ENABLED');
        if (!is_string($m) || $m === '') {
            $m = $_ENV['SLOT_AUTHORITY_ENABLED'] ?? 'off';
        }
        $m = strtolower(trim((string)$m));
        return in_array($m, ['off', 'shadow', 'enforce'], true) ? $m : 'off';
    }

    /**
     * Evaluate PCIe slot availability for a PCIe slot consumer at add-time.
     *
     * Reads its own SLOT_AUTHORITY_ENABLED flag. Returns null immediately when off
     * or when the motherboard spec lacks slot-ID data (no false divergence). Returns
     * an override compatibility result only when enforce AND the tracker has real
     * slot data AND reports zero available slots.
     *
     * @param string     $configUuid     Server configuration UUID
     * @param string     $componentType  One of: nic | pciecard | hbacard (caller must gate)
     * @param string     $componentUuid  UUID of the component being added
     * @param array|null $legacyResult   Current $compatibilityResult from the legacy check
     * @return array|null  Override compatibility result on enforce+no-slots; null otherwise
     */
    public function evaluate(
        string $configUuid,
        string $componentType,
        string $componentUuid,
        ?array $legacyResult
    ): ?array {
        $mode = self::mode();
        if ($mode === 'off') {
            return null;
        }

        try {
            require_once __DIR__ . '/UnifiedSlotTracker.php';
            $tracker   = new UnifiedSlotTracker($this->pdo);
            $slotAvail = $tracker->getSlotAvailability($configUuid);

            if (!$slotAvail['success']) {
                // No slot-ID data in MB spec → fall back to legacy silently
                return null;
            }

            $availableCount = 0;
            foreach ($slotAvail['available_slots'] as $slotIds) {
                $availableCount += count((array)$slotIds);
            }
            $trackerHasSlot  = $availableCount > 0;
            $legacyCompatible = ($legacyResult === null || (bool)($legacyResult['compatible'] ?? true));

            if ($mode === 'shadow') {
                if ($legacyCompatible && !$trackerHasSlot) {
                    error_log(sprintf(
                        'SlotAuthority shadow divergence (add-time): config=%s type=%s uuid=%s' .
                        ' legacy.compatible=true tracker.available_slots=0' .
                        ' tracker.used=%s tracker.total=%s',
                        $configUuid, $componentType, $componentUuid,
                        json_encode(array_keys($slotAvail['used_slots'] ?? [])),
                        json_encode(array_keys($slotAvail['total_slots'] ?? []))
                    ));
                }
                return null; // shadow never overrides
            }

            // enforce: UnifiedSlotTracker is the authority when it has real slot data
            if ($legacyCompatible && !$trackerHasSlot) {
                return [
                    'compatible'           => false,
                    'issues'               => ['No PCIe slots available (UnifiedSlotTracker reports all slots occupied)'],
                    'warnings'             => [],
                    'recommendations'      => ['Remove an existing PCIe/NIC/HBA card to free a slot'],
                    'details'              => ['SlotAuthority: tracker has data and reports 0 available slots'],
                    'compatibility_summary' => 'INCOMPATIBLE: No PCIe slot available',
                ];
            }

            return null; // slots available or legacy already rejected — no override needed

        } catch (\Throwable $e) {
            error_log('SlotAuthority error (fail-open to legacy): ' . $e->getMessage());
            return null;
        }
    }
}
