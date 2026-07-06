# U-R.3 — Slot placement rule (+ placement service)
RULE_MAP: pcie.slot_placement. Invariants: INV-2, INV-7, INV-11, PD-1.

## Inputs
ServerBuilder 4433–4580 (assignComponentSlot incl. dead $manualSlotPosition — audit A-7/A-8);
UnifiedSlotTracker 40–360 (assignment logic incl. riser-provided + size matching);
SlotAuthority.php (124 lines, full); tests/slot_storage_authority_unit.php.

## Files Created
rules/PcieSlotPlacementRule.php + core/models/validation/SlotPlanner.php + tests/unit/rules/slot_rules_test.php.
SlotPlanner: pure function over TargetState resources — free slots = provider pcie_slot rows with
consumer NULL; plan(cardWidth, manualSlotRef?): manualSlotRef given ⇒ validate exists+free+width≥card
(A-7 closes) else ERROR; auto ⇒ smallest-sufficient-width free slot (matches tracker's optimal rule);
unknown card width ⇒ ERROR pcie.unknown_width (A-8 closes). Rule evaluates plan feasibility; the
chosen slot_ref rides in RuleResult details (commands consume it in U-C.2 — until then legacy
assignComponentSlot still does the actual writing; shadow-only divergence expected + mapped).

## Tests / Checklist
Unit: manual-honored, manual-occupied-blocked, unknown-width-blocked, riser-provided-slots usable.
Parity expected diffs cite A-7/A-8. - [ ] Planner pure (no PDO) - [ ] Width parsing single impl (port extractPCIeSlotSize 4579 here; legacy keeps its copy until U-D.2)
