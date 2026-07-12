<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';

/**
 * RULE_MAP.md: pcie.lane_budget (E). Ports the math from
 * PcieLaneBudgetValidator (359 lines, full — the newer/authoritative model),
 * NOT ServerBuilder::trackPCIeLaneAvailability (the older, divergent model
 * — read only to enumerate its divergences below, never ported):
 *   - trackPCIeLaneAvailability sums lanes per-CPU differently and does not
 *     share PcieLaneBudgetValidator::extractLaneCount()'s parsing, so its
 *     "used" figure can disagree with the authoritative model on the same
 *     configuration; this is exactly the kind of drift RULE_MAP's "single
 *     model" instruction exists to end. No expected_diffs entry is needed
 *     for this divergence specifically since ONLY the authoritative model's
 *     legacy behavior (warn-default, never blocking) is what the shadow
 *     hook actually compares against (A-9 below).
 *
 * Uses TargetState::poolBalance('pcie_lane') (U-V.2), which is
 * ResourceCatalog-driven and already mirrors PcieLaneBudgetValidator's own
 * budget (CPU pcie_lanes) and consumption (nic/hbacard/pciecard +
 * non-M.2 NVMe storage, via ResourceCatalog::consumesPcieLanes()/consumesStorage())
 * — see core/models/config/ResourceCatalog.php's class docblock (U-L.4/U-L.5).
 *
 * INV-7: no env read here (unlike PcieLaneBudgetValidator's own
 * PCIE_LANE_CHECK_ENABLED) — ENGINE_MODE alone governs whether this rule
 * runs at all.
 */
final class PcieLaneBudgetRule implements RuleInterface
{
    public function id(): string
    {
        return 'pcie.lane_budget';
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
        $balance = $state->poolBalance('pcie_lane');

        if ($balance < 0) {
            $capacity = 0;
            foreach ($state->byResource('pcie_lane') as $row) {
                $capacity += (int)$row['capacity'];
            }
            $used = $capacity - $balance;
            return new RuleResult($this->id(), $this->severity(), false,
                "PCIe lane budget exceeded: $capacity total, $used allocated",
                ['budget' => $capacity, 'used' => $used, 'over_by' => -$balance]);
        }

        return new RuleResult($this->id(), $this->severity(), true, "PCIe lane budget OK (balance $balance)");
    }
}
