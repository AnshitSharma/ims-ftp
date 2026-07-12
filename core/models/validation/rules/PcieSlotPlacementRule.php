<?php

require_once __DIR__ . '/../RuleInterface.php';
require_once __DIR__ . '/../RuleResult.php';
require_once __DIR__ . '/../Severity.php';
require_once __DIR__ . '/../Trigger.php';
require_once __DIR__ . '/../SlotPlanner.php';
require_once __DIR__ . '/../../shared/DataExtractionUtilities.php';

/**
 * RULE_MAP.md: pcie.slot_placement (E). Legacy:
 * ServerBuilder::assignComponentSlot (was lines 4642-4782). See
 * core/models/validation/SlotPlanner.php for the ported placement algorithm
 * and intentional diffs (A-7 manual slot honored, A-8 unknown width blocks).
 *
 * Only evaluates components that still need placement: nic/pciecard/hbacard
 * rows with slot_ref === null, excluding onboard NICs (spec_uuid prefix
 * "onboard-", which legitimately never get a discrete slot — mirrors
 * slot_report.php's slotless_card check exclusion). Rows that already carry
 * a slot_ref (placed via a prior legitimate add) are not re-planned.
 *
 * Divergence note: this rule judges PLACEMENT FEASIBILITY only — the chosen
 * slot_ref rides in RuleResult::details() for a future command layer (U-C.2)
 * to actually write; legacy's assignComponentSlot() still does the real
 * slot writing today, so shadow-mode divergence in the CHOSEN slot_ref
 * (not just pass/fail) is possible even when both sides agree a slot
 * exists, and is expected/out of scope for the blocked/not-blocked parity
 * comparison parity_report.php performs.
 */
final class PcieSlotPlacementRule implements RuleInterface
{
    /** @var DataExtractionUtilities */
    private $dataUtils;

    public function __construct(?DataExtractionUtilities $dataUtils = null)
    {
        $this->dataUtils = $dataUtils ?? new DataExtractionUtilities();
    }

    public function id(): string
    {
        return 'pcie.slot_placement';
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
            return new RuleResult($this->id(), $this->severity(), true, 'No motherboard -- slot assignment skipped');
        }

        foreach (['nic', 'pciecard', 'hbacard'] as $type) {
            foreach ($state->byType($type) as $component) {
                if ($component['slot_ref'] !== null) {
                    continue; // already placed
                }
                if ($type === 'nic' && strpos((string)$component['spec_uuid'], 'onboard-') === 0) {
                    continue; // onboard NICs never get a discrete slot
                }

                $spec = $this->specFor($type, $component['spec_uuid']);
                if (!is_array($spec)) {
                    continue; // spec not found -- not this rule's concern (UUID validity is enforced elsewhere)
                }

                $isRiser = ($spec['component_subtype'] ?? null) === 'Riser Card'
                    || strpos((string)$component['spec_uuid'], 'riser-') === 0;
                $resource = $isRiser ? 'riser_slot' : 'pcie_slot';
                $width = SlotPlanner::extractCardWidth($spec);

                $plan = SlotPlanner::plan($state, $resource, $width, null);
                if (!$plan['ok']) {
                    return new RuleResult($this->id(), $this->severity(), false, $plan['error'],
                        ['component_id' => $component['id'], 'component_type' => $type,
                            'resource' => $resource, 'width' => $width, 'error_code' => $plan['error_code']]);
                }
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All unplaced cards have a feasible slot');
    }

    private function specFor(string $type, string $specUuid): ?array
    {
        switch ($type) {
            case 'nic':
                $spec = $this->dataUtils->getNICByUUID($specUuid);
                break;
            case 'hbacard':
                $spec = $this->dataUtils->getHBACardByUUID($specUuid);
                break;
            case 'pciecard':
                $spec = $this->dataUtils->getPCIeCardByUUID($specUuid);
                break;
            default:
                return null;
        }
        return is_array($spec) ? $spec : null;
    }
}
