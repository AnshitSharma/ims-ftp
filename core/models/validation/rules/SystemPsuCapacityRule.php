<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';

/**
 * RULE_MAP.md: system.psu_capacity (E). Legacy's checkPowerCompatibilityDetailed()
 * (was ~5706) budgets estimated draw against the chassis PSU's nameplate
 * wattage at an 85% continuous ceiling but only ever feeds a compatibility
 * SCORE (calculateHardwareCompatibilityScore -> validateConfigurationComprehensive),
 * never a block (audit V-4: "scoring only"). RULE_MAP: scoring -> E.
 *
 * DOCUMENTED DEVIATION (see ResourceCatalog's class docblock, "cpu/storage/
 * nic/hbacard/pciecard CONSUME psu_watt" note, for the full reasoning): this
 * rule's wattage sum comes from ResourceCatalog::consumes(), which reads each
 * component's OWN structured ims-data power field. Legacy's estimate instead
 * regexes the free-text `Notes` column on each PHYSICAL UNIT (2.5W/core cpu,
 * 1W/4GB ram, flat 8W/12W SSD/HDD) — data TargetState/ResourceCatalog cannot
 * see (spec_uuid-only design, INV-1). This rule is therefore NOT expected to
 * numerically match legacy's estimate on the same fixture; it reuses the same
 * 85%-continuous-ceiling threshold formula on a catalog-native number instead.
 * Flagged for human review in the U-R.7 handoff, same posture as
 * storage.interface_path's SAS-backplane gap and net.nic_requirements' SR-IOV
 * gap from the prior session.
 */
final class SystemPsuCapacityRule implements RuleInterface
{
    /** Matches checkPowerCompatibilityDetailed()'s 85% continuous-ceiling budget. */
    const USABLE_FRACTION = 0.85;

    public function id(): string
    {
        return 'system.psu_capacity';
    }

    public function severity(): string
    {
        return Severity::ERROR;
    }

    public function triggers(): array
    {
        return [Trigger::VALIDATE, Trigger::FINALIZE];
    }

    public function scope(): string
    {
        return self::SCOPE_RESOURCE;
    }

    public function evaluate(TargetState $state): RuleResult
    {
        $psuRows = $state->byResource('psu_watt');
        if (empty($psuRows)) {
            return new RuleResult($this->id(), $this->severity(), true, 'No chassis PSU wattage to budget against');
        }

        $capacity = 0;
        foreach ($psuRows as $row) {
            $capacity += (int)$row['capacity'];
        }
        $usable = (int)round($capacity * self::USABLE_FRACTION);
        // TargetState::poolBalance() = capacity - consumed, so consumed = capacity - balance.
        $consumed = $capacity - $state->poolBalance('psu_watt');

        if ($consumed > $usable) {
            return new RuleResult($this->id(), $this->severity(), false,
                "Estimated power draw ({$consumed}W) exceeds the chassis PSU usable capacity ({$usable}W of {$capacity}W rated, 85% continuous ceiling)",
                ['rated_watts' => $capacity, 'usable_watts' => $usable, 'consumed_watts' => $consumed, 'over_by' => $consumed - $usable]);
        }

        return new RuleResult($this->id(), $this->severity(), true,
            "PSU capacity OK ({$consumed}W of {$usable}W usable, {$capacity}W rated)",
            ['rated_watts' => $capacity, 'usable_watts' => $usable, 'consumed_watts' => $consumed]);
    }
}
