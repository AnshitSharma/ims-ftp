# U-C.4 — ReplaceComponentCommand (new capability)
Pins baseline: yes (no legacy counterpart ⇒ zero diffs by construction; reachable only from new API
action in U-A.2 — this unit ships the command + tests only). Invariants: INV-3, INV-5, INV-11.

## Inputs
BaseCommand; TargetStateBuilder.withReplace; Add/Remove commands (compose their apply helpers);
OnboardNICHandler 399–492 (replaceOnboardNIC — the validation-free path this SUPERSEDES; do not
modify it here; U-D.2 deletes it).

## Files Created (2)
ReplaceComponentCommand.php — one TargetState = current − old(+cascade only for same-type child
re-anchor rule below) + new; evaluate(REPLACE); apply = tombstone old + insert new + re-anchor
children whose parent was old (parent_id := new row id) + inventory transitions both units + slot
inheritance: new component takes old's slot_ref when SlotPlanner validates width, else planner
assigns fresh (details in verdict). RP-1's intermediate state never exists: single tx, single verdict.
tests/regression/replace_command_test.php — cpu A→B (RAM re-anchored + re-validated), board A→B
incompatible-B blocks WITH A STILL IN PLACE (the audit's stranding scenario now impossible),
chassis A→B bay revalidation, NIC A→B with SFPs re-anchored ports validated.

## Tests / Rollback / Checklist
Unit+regression on scratch; nothing user-reachable yet. Delete files to roll back.
- [ ] Failure leaves config byte-identical - [ ] Children re-anchored, not orphaned - [ ] No two-transaction path exists in the file
