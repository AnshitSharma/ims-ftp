# U-R.1 — CPU rule family
Pins baseline: no (shadow). Invariants: INV-2, INV-7, INV-11. RULE_MAP rows: cpu.*

## Inputs
RULE_MAP.md (cpu rows); ServerBuilder 3751–3815 (validateCPUAddition);
ComponentValidator 265–440 (socket/count/mixed legacy logic); U-V.1/U-V.2 interfaces;
ResourceCatalog (socket capacity source).

## Files Created (5: 4 rules + 1 test)
core/models/validation/rules/{CpuSocketMatchRule, CpuSocketCountRule, CpuMixedModelsRule,
CpuRequiresBoardRule}.php + tests/unit/rules/cpu_rules_test.php.
Port the DECISION LOGIC from legacy (read spec via ComponentDataService like legacy does), but:
counts = TargetState row counts (never 'quantity'); socket capacity from state->resources(cpu_socket);
severities/triggers per RULE_MAP (RequiresBoard: VF, triggers ADD+VALIDATE; others E, ADD+REPLACE+VALIDATE;
mixed: W, VALIDATE only). Register all four in ValidationEngine::RULES (Files Modified: 1 —
ValidationEngine.php; total box 6 files ⇒ SPLIT RULE: if any rule exceeds 80 LOC, move its test into
the same session's second commit but same unit is still one concept — box counts FILES=6 > 5 ⇒
registry modification is exempted from the box for U-R.* units, recorded as plan deviation PD-1 in
PLAN_VERIFICATION_REVIEW).

## Tests
unit tests: quantity-bypass fixture (audit A-2) now blocked; socket mismatch blocked; mixed models W;
requires-board VF not E. Then scratch scenario run + parity_report: diffs only those mapped in
expected_diffs.json (add entries citing A-2, A-12, mixed-models-now-fires).

## Rollback
Unregister from RULES (verdicts revert instantly in shadow); git revert.

## Checklist
- [ ] No 'quantity' token in rules (INV-1 grep) - [ ] expected_diffs.json entries cite audit ids - [ ] parity green after
