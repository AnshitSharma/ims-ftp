# U-C.3 — RemoveComponentCommand (+cascade)
Pins baseline: yes (shadow zero diffs vs legacy remove; dependency rule creates EXPECTED enforce
diffs, all pre-mapped). Invariants: INV-3, INV-5, INV-11.

## Inputs
BaseCommand; DependencyBlockedRemovalRule + TargetStateBuilder.withRemove (U-R.8);
ServerBuilder 932–1094 (legacy remove — persistence internals to reuse: updateServerConfigurationTable
'remove', updateComponentStatusAndServerUuid, recalculateFormFactorLock);
storage-path recompute: NONE needed (paths are derived post-U-R.5 — note in code why).

## Files Created (2) / Modified (1)
RemoveComponentCommand.php — accepts serial_number and cascade:boolean; buildTarget withRemove(id,cascade);
apply(): tombstone target (+subtree when cascade) via repository, mirror to legacy JSON via
library calls per removed row, inventory transitions installed→available via StateMachine.
MODIFY server_api handleRemoveComponent dispatch (shadow/enforce identical pattern to U-C.2).
tests/regression/remove_command_test.php — six §6 scenarios: blocked without cascade, full-subtree
with cascade (JSON, rows, ledger, inventory all consistent), NIC→SFP parity with legacy.

## Tests / Rollback / Checklist
As U-C.2. Enforce diffs (newly-blocked removals) must all match expected_diffs.json R-1 entries.
- [ ] cascade removes children in ONE tx - [ ] serial-targeted removal works (R-3 closes at U-A.2 API exposure)
