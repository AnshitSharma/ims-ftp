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
 * Only evaluates components that still need placement: nic/pciecard/risercard/hbacard
 * rows with slot_ref === null, excluding onboard NICs (spec_uuid prefix
 * "onboard-", which legitimately never get a discrete slot — mirrors
 * slot_report.php's slotless_card check exclusion) and platform-owned
 * embedded parts (see isPlatformOwned(), added 2026-09-13) and cards whose spec
 * names a dedicated_slot (added 2026-10-07; see DedicatedSlotRule). Rows that already
 * carry a slot_ref (placed via a prior legitimate add) are not re-planned, but since
 * QA-06 (2026-10-10) their slot must exist and hold them alone.
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

        // Slots handed out EARLIER IN THIS PASS. TargetState::freeSlots() only knows
        // what is persisted, so without this every unplaced card was offered the same
        // free slot and N cards all "fit" a board with one slot -- the rule reported
        // feasible placements that cannot coexist. Combined with the empty-string
        // manual-slot defect (AddComponentCommand::planSlot) that left every card's
        // slot_ref NULL, PCIe slot capacity was not enforced at all.
        $planned = [];

        // PLACED cards are judged too (QA-06, 2026-10-10). Every row with a slot_ref
        // was skipped as "already placed", so two cards persisted in one slot, or a
        // card in a slot the build no longer has, validated clean. A placed card's
        // slot must exist among this build's providers and hold that card alone.
        $held = [];
        foreach (['nic', 'pciecard', 'risercard', 'hbacard'] as $type) {
            foreach ($state->byType($type) as $component) {
                if ($component['slot_ref'] === null || $this->occupiesNoSlot($type, $component)) {
                    continue;
                }
                $spec = $this->specFor($type, $component['spec_uuid']);
                if (!is_array($spec)) {
                    continue;
                }
                $resource = $this->resourceFor($type, $spec, $component);
                $slotRef = (string)$component['slot_ref'];
                $exists = false;
                foreach ($state->byResource($resource) as $row) {
                    if ($row['slot_ref'] === $slotRef) {
                        $exists = true;
                        break;
                    }
                }
                // Both faults are the rule's ERROR: swept across all 80 live builds on
                // 2026-10-10 first, none carries either.
                if (!$exists) {
                    return new RuleResult($this->id(), $this->severity(), false,
                        "A $type is recorded in $resource $slotRef, which this build does not have",
                        ['component_id' => $component['id'], 'component_type' => $type,
                            'resource' => $resource, 'slot_ref' => $slotRef,
                            'recommendation' => 'Re-seat the card in a slot this build provides, or restore the riser that provided that slot.']);
                }
                $key = $resource . '|' . $slotRef;
                if (isset($held[$key])) {
                    return new RuleResult($this->id(), $this->severity(), false,
                        "Two cards are recorded in $resource $slotRef",
                        ['component_ids' => [$held[$key], $component['id']], 'resource' => $resource, 'slot_ref' => $slotRef,
                            'recommendation' => 'Move one of the cards to a free slot.']);
                }
                $held[$key] = $component['id'];
            }
        }

        foreach (['nic', 'pciecard', 'risercard', 'hbacard'] as $type) {
            foreach ($state->byType($type) as $component) {
                if ($component['slot_ref'] !== null) {
                    continue; // already placed -- judged above
                }
                if ($this->occupiesNoSlot($type, $component)) {
                    continue;
                }

                $spec = $this->specFor($type, $component['spec_uuid']);
                if (!is_array($spec)) {
                    continue; // spec not found -- not this rule's concern (UUID validity is enforced elsewhere)
                }
                if (SlotPlanner::dedicatedSlotKind($spec) !== null) {
                    continue; // own connector (rNDC, FlexibleLOM, PERC Mini...) -- card.dedicated_slot owns it
                }

                $resource = $this->resourceFor($type, $spec, $component);
                $width = SlotPlanner::extractCardWidth($spec);

                $exclude = $planned[$resource] ?? [];
                $plan = SlotPlanner::plan($state, $resource, $width, null, $exclude);
                if (!$plan['ok']) {
                    return new RuleResult($this->id(), $this->severity(), false, $plan['error'],
                        ['component_id' => $component['id'], 'component_type' => $type,
                            'resource' => $resource, 'width' => $width, 'error_code' => $plan['error_code']]);
                }
                $planned[$resource][] = $plan['slot_ref'];
            }
        }

        return new RuleResult($this->id(), $this->severity(), true, 'All cards have a slot of their own');
    }

    /** Onboard NICs and platform-owned parts never take an expansion slot. */
    private function occupiesNoSlot(string $type, array $component): bool
    {
        return ($type === 'nic' && strpos((string)$component['spec_uuid'], 'onboard-') === 0)
            || $this->isPlatformOwned($component);
    }

    /**
     * riser_slot for a riser, pcie_slot for every other card. Type is authoritative
     * since the 2026-08-14 split; spec/UUID tests remain for legacy pciecard rows
     * still labelled as risers.
     */
    private function resourceFor(string $type, array $spec, array $component): string
    {
        $isRiser = $type === 'risercard'
            || ($spec['component_subtype'] ?? null) === 'Riser Card'
            || strpos((string)$component['spec_uuid'], 'riser-') === 0;
        return $isRiser ? 'riser_slot' : 'pcie_slot';
    }

    /**
     * Is this row a part of the platform box rather than a card installed in it?
     *
     * handleSetPlatform() mirrors a platform's EMBEDDED parts (a front PERC, for
     * example) against the serverplatforminventory unit itself, with a null
     * slot_ref, because they are bolted in and are never stocked as loose cards.
     * Charging them an expansion slot is wrong everywhere and actively breaks
     * DL380 Gen10-class platforms, which publish 0 direct PCIe slots, so the
     * embedded PERC consumed riser capacity that is physically free.
     *
     * Keyed on the inventory table the row was written against, which is an
     * explicit fact recorded at import time -- not on a spec_uuid prefix, which
     * an embedded part (a real catalog part with a real UUID) never carries.
     */
    private function isPlatformOwned(array $component): bool
    {
        return ($component['inventory_table'] ?? null) === 'serverplatforminventory';
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
            case 'risercard':
                $spec = $this->dataUtils->getRiserCardByUUID($specUuid);
                break;
            default:
                return null;
        }
        return is_array($spec) ? $spec : null;
    }
}
