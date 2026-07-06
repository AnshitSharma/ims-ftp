# U-C.6 — Transaction ownership consolidation
Concept: INV-3 becomes checkable. Pins baseline: yes (ZERO diffs — pure ownership refactor).
Invariants: INV-3, INV-10, INV-11. PRECONDITION: COMMAND_LAYER_ENABLED=enforce soaked 7 days.

## Inputs
grep map: `grep -n beginTransaction core/models/server/ServerBuilder.php core/models/compatibility/OnboardNICHandler.php`
(expect: addComponent, removeComponent, deleteConfiguration, finalizeConfiguration, OnboardNICHandler×2);
BaseCommand ownTransaction semantics.

## Files Modified (≤3)
ServerBuilder: the four methods keep their ownTransaction pattern but their PUBLIC entry when
enforce is via commands only — add a guard: `if (CommandGate::require()) throw` where CommandGate
checks COMMAND_LAYER_ENABLED=enforce AND caller is not a command (pass a $viaCommand flag param
default false; commands pass true). OnboardNICHandler.replaceOnboardNIC: begin-guarded (nestable)
— mechanical, no behavior change (it becomes command-internal in U-C.4's supersession).

## Tests
characterization ZERO diffs; INV-3 grep per invariant file passes with the documented allowlist;
all prior regression tests PASS.

## Rollback / Checklist
git revert. - [ ] No legacy public mutation path reachable at enforce - [ ] INV-3 CHECK command green
