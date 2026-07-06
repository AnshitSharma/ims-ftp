# U-C.2 — AddComponentCommand (strangler over legacy)
Pins baseline: yes (shadow zero diffs). Invariants: INV-3, INV-5, INV-8, INV-11, INV-12.

## Inputs
BaseCommand; ServerBuilder 440–930 SKIM MAP ONLY (you are not editing addComponent; you need its
OPTIONS vocabulary: serial_number, slot_position, parent_nic_uuid, port_index, override_used, notes);
ServerBuilder 2293–2360 (updateServerConfigurationTable — the legacy persistence you call as library);
ConfigComponentWriter (already dual-writes when that path runs);
SlotPlanner (U-R.3 — the command consumes the planned slot_ref).

## Files Created (2) / Modified (1)
core/models/commands/AddComponentCommand.php — buildTarget: resolve inventory row (reuse legacy
lockAndCheckComponent semantics via own helper: SELECT ... FOR UPDATE on inventory), withAdd(row with
slot_ref from SlotPlanner details). apply(): call ServerBuilder->updateServerConfigurationTable(...,'add',...)
+ updateComponentStatusAndServerUuid + onboard-NIC materialization for motherboards (MOVED
pre-commit: call OnboardNICHandler inside apply — closes A-11's first half; the handler is
tx-nestable per its own ownTransaction pattern, verified in Inputs read).
MODIFY server_api handleAddComponent: mode()==shadow ⇒ run command in a SAVEPOINT-rolled-back probe?
NO — shadow for commands = build+evaluate WITHOUT apply (dry verdict), compare to legacy outcome,
log JSONL, then legacy path runs as today. enforce ⇒ command replaces the legacy call entirely.
tests/regression/add_command_test.php — enforce on scratch: verdict parity with legacy on fixture
matrix; rows+JSON+ledger+events all written; onboard NICs same-tx.

## Tests
shadow: characterization ZERO diffs + shadow log rows. enforce (scratch): regression PASS;
equivalence --config green post-op; performance_report Δ within budget.

## Rollback
Flag off. 

## Checklist
- [ ] Shadow never applies - [ ] Onboard NICs pre-commit in enforce - [ ] Legacy persistence reused, not reimplemented
