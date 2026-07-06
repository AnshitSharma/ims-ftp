# U-V.3 — Engine + registry + shadow runner
Concept: one evaluate() path; shadow first. Pins baseline: yes (shadow zero diffs).
Invariants: INV-2, INV-5, INV-12, INV-11.

## Inputs
U-V.1/U-V.2 outputs; 00-overview/FLAGS.md (ENGINE_MODE); ServerBuilder 3991–4010 (the single hook
site: top of validateComponentAddition); RULE_MAP.md header.

## Files Created (2) / Modified (1)
core/models/validation/ValidationEngine.php — registry: const RULES = [class-strings] (empty now;
U-R.* append); evaluate(TargetState,Trigger): Verdict — iterate rules whose triggers() match;
ANY rule exception ⇒ synthesized failed E RuleResult 'engine.rule_exception' (fail-closed, INV-5),
never swallowed. mode() per flag.
core/models/validation/ShadowRunner.php — record($configUuid,$op,$legacyBlocked,$legacyClass,Verdict)
→ append JSONL reports/shadow/engine-<Ymd>.jsonl.
MODIFY ServerBuilder::validateComponentAddition: FIRST lines — if mode()!=='off': build TargetState
(fromCurrent + withAdd), evaluate(ADD); shadow ⇒ ShadowRunner::record + continue legacy; enforce ⇒
return engine verdict mapped to legacy result shape (mapping helper in ShadowRunner) and SKIP the
rest of the method. One hook site total; remove/replace hooks arrive with U-C.3/C.4.

## Tests
flag off + shadow: characterization ZERO diffs; shadow JSONL rows appear on scratch scenario run;
induced rule exception (test rule fixture) yields blocking verdict in enforce and a logged row in shadow.

## Rollback
ENGINE_MODE=off; git revert.

## Checklist
- [ ] Exactly one hook site - [ ] Rule exceptions fail closed - [ ] Shadow writes only its JSONL
