<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * RULE_MAP.md: storage.m2_capacity (E). Legacy:
 * ServerBuilder::getConfigurationWarnings() M.2 section (was lines
 * 1979-2026) -- a READ-TIME warning only (surfaced when the config is
 * fetched, never blocks an add). Intentional diff (A-10): promoted to a
 * blocking ERROR at ADD time, closing the gap where a config could be
 * over-populated with M.2 drives and only find out via a later read.
 * Capacity from ResourceCatalog's motherboard m2_slot provider (U-L.1,
 * already implemented -- sums ALL m2_slots entries per the P3.1 lesson).
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

        $capacity = 0;
        foreach ($state->byResource('m2_slot') as $row) {
            $capacity += (int)$row['capacity'];
        }

        // Mirrors legacy's exact guard ($m2TotalSlots > 0): a motherboard with
        // no declared M.2 slots at all is NOT flagged, same gap legacy has --
        // not widened here, only the existing condition's severity (A-10).
        if ($m2Count > $capacity && $capacity > 0) {
            return new RuleResult($this->id(), $this->severity(), false,
                "M.2 slots exceeded: using $m2Count slots but only $capacity available",
                ['count' => $m2Count, 'capacity' => $capacity]);
        }

        return new RuleResult($this->id(), $this->severity(), true, "M.2 count $m2Count within capacity $capacity");
    }
}
