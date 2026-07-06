# U-C.5 — TransitionStatusCommand (finalize path)
Pins baseline: yes. Invariants: INV-3, INV-4, INV-5, INV-11.

## Inputs
BaseCommand; StateMachine (U-SM.3); ServerBuilder 3527–3600 (finalizeConfiguration to strangle);
server_api 1133–1190 (handleFinalizeConfiguration — the unlocked comprehensive pre-check to remove
at enforce); ValidationEngine (trigger FINALIZE).

## Files Created (1) / Modified (2)
TransitionStatusCommand.php — assertConfigTransition; requires_validation=full ⇒ evaluate(FINALIZE)
on fromCurrent state INSIDE the lock (V-1 closes structurally); apply = applyConfigTransition +
inventory allocated→installed promotions on finalized.
MODIFY finalizeConfiguration: enforce ⇒ delegate to command; MODIFY handleFinalizeConfiguration:
enforce ⇒ drop the API-layer comprehensive pre-run (shadow keeps both, logs divergence).
tests/regression/finalize_command_test.php — defective-inventory fixture blocks (V-2 via
SystemInventoryStateRule); concurrent-mutation-then-finalize race fixture blocks under lock.

## Tests / Rollback / Checklist
Shadow zero diffs; enforce diffs mapped (V-1/V-2). Flag off rollback.
- [ ] Full validation runs under the SAME lock as the status write - [ ] Legacy weak validateConfiguration untouched (U-D.2 deletes)
