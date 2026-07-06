# U-R.4 — Lane budget rule (single model)
RULE_MAP: pcie.lane_budget. Invariants: INV-2, INV-7, INV-11, PD-1.

## Inputs
PcieLaneBudgetValidator.php (359 lines, full — the newer model; port THIS math);
ServerBuilder 6752–6888 (trackPCIeLaneAvailability — the divergent model; read to enumerate
divergences into expected_diffs, do NOT port); tests/lane_authority_unit.php (port cases).

## Files Created
rules/PcieLaneBudgetRule.php + tests/unit/rules/lane_rule_test.php.
Budget from resources(pcie_lane) provider rows (CPUs); consumption from consumer rows + the delta
component's lanes via catalog consumes(). Severity E, triggers ADD+REPLACE+VALIDATE. NO env flag
inside the rule (INV-7) — ENGINE_MODE governs rollout globally.

## Tests / Checklist
Port lane_authority_unit cases; fixture where legacy warn-mode allowed over-subscription now blocks
(expected diff cite A-9). - [ ] getenv absent from rule - [ ] one model: VALIDATE trigger reuses the same evaluate
