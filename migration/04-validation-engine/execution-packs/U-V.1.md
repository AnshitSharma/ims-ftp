# U-V.1 — Value objects + Rule interface
Concept: validation vocabulary. Pins baseline: no. Invariants: INV-7, INV-11.

## Inputs
04-validation-engine/README.md + RULE_MAP.md (vocabulary only).

## Files Created (5)
core/models/validation/{Severity.php (enum E/VF/W), Trigger.php (enum ADD,REMOVE,REPLACE,VALIDATE,FINALIZE),
RuleResult.php (ruleId, severity, passed bool, message, details array — immutable),
Verdict.php (results[], blocking(): any E failed, or VF failed when trigger∈{VALIDATE,FINALIZE}),
RuleInterface.php (id(), severity(), triggers(): array, scope(): PAIR|RESOURCE|CONFIG, evaluate(TargetState): RuleResult)}.
No behavior, no callers. PHP 8 enums; final classes.

## Tests
php -l each; tests/unit/verdict_test.php (blocking matrix: E blocks all triggers; VF blocks only VALIDATE/FINALIZE; W never).

## Rollback / Checklist
Delete files. - [ ] Blocking matrix exactly per severity table in target design §4 - [ ] Immutability (readonly props)
