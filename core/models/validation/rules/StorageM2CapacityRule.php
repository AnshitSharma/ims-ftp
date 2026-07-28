<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * RULE_MAP.md: storage.m2_capacity (E). Legacy has TWO M.2 paths and this rule
 * must mirror the one that BLOCKS:
 *
 *   1. ComponentValidator::validateMotherboardM2Storage() (~line 1043) -- the
 *      ADD-TIME gate that actually runs: `if ($m2Slots <= 0) compatible=false`.
 *      A board with ZERO M.2 slots rejects every M.2 drive.
 *   2. ServerBuilder::getConfigurationWarnings() M.2 section -- a READ-TIME
 *      warning guarded by ($m2TotalSlots > 0), which never blocks anything.
 *
 * F-25 (2026-07-28): this rule was ported from (2) and carried its `$capacity > 0`
 * guard across, so it FAILED OPEN exactly where legacy fails closed. Production
 * shadow row 2026-07-28T15:10:15Z, config 2fcea743, HP ProLiant DL360 Gen9
 * (storage.nvme.m2_slots: []): legacy blocked "No M.2 slots available", the engine
 * passed all 16 rules. Same wrong-source-of-truth class as F-20 (bay_capacity) and
 * F-24 (interface_path) -- ported from a warning/comment path rather than the
 * executing one. Sibling StorageBayCapacityRule already blocks at `$capacity === 0`;
 * this rule now agrees with it.
 *
 * Intentional diff (A-10) RETAINED: over-subscription ($m2Count > $capacity with
 * capacity > 0) is a blocking ERROR at ADD time where legacy only warned at read
 * time. That is the deliberate tightening. The zero-slot case below is NOT a
 * tightening -- it restores parity with the gate that runs today.
 *
 * Capacity from ResourceCatalog's motherboard m2_slot provider (U-L.1 -- sums ALL
 * m2_slots entries per the P3.1 lesson).
 */
final class StorageM2CapacityRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'storage.m2_capacity';
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
        return self::SCOPE_RESOURCE;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        if (empty($state->byType('motherboard'))) {
            return new RuleResult($this->id(), $this->severity(), true, 'No motherboard -- M.2 capacity check does not apply');
        }

        $m2Count = 0;
        foreach ($state->byType('storage') as $storage) {
            $spec = $this->dataUtils->getStorageByUUID($storage['spec_uuid']);
            $formFactor = strtolower((string)(is_array($spec) ? ($spec['form_factor'] ?? '') : ''));
            if (strpos($formFactor, 'm.2') !== false || strpos($formFactor, 'm2') !== false) {
                $m2Count++;
            }
        }

        // No M.2 drive in play: a board without M.2 slots is not a problem until
        // something actually needs one. Keeps every non-M.2 build untouched.
        if ($m2Count === 0) {
            return new RuleResult($this->id(), $this->severity(), true, 'No M.2 storage in configuration');
        }

        $capacity = 0;
        foreach ($state->byResource('m2_slot') as $row) {
            $capacity += (int)$row['capacity'];
        }

        if ($m2Count > $capacity) {
            // Zero-capacity wording matches ComponentValidator's blocking message
            // so engine and legacy read identically in the parity diff.
            $message = $capacity === 0
                ? 'No M.2 slots available (motherboard or NVMe adaptor) for M.2 storage'
                : "M.2 slots exceeded: using $m2Count slots but only $capacity available";

            return new RuleResult($this->id(), $this->severity(), false, $message,
                ['count' => $m2Count, 'capacity' => $capacity]);
        }

        return new RuleResult($this->id(), $this->severity(), true, "M.2 count $m2Count within capacity $capacity");
    }
}
