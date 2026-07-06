# U-D.4 — Delete flags + temp guards + legacy env flags
Pins baseline: yes (all flags at terminal values ⇒ deletion is identity). Invariants: INV-12, INV-10, INV-11.

## Targets
The five migration flags (readers become constants: dual-write gone with U-D.3, others 'enforce'/'on'
hard-coded then inlined away); TEMP-GUARD(U-0.2) blocks (search marker); legacy flags
PCIE_LANE_CHECK_ENABLED, VALIDATION_PIPELINE_ENABLED, SLOT_AUTHORITY_ENABLED,
STORAGE_CONNECTION_AUTHORITY_ENABLED (their consumer classes died in U-D.2 — this unit greps residue);
legacy int status columns configuration_status + inventory Status? RETAINED (external consumers
unknown) — demoted to generated columns mirroring status_v2 in a FOLLOW-UP seeder listed in
12-post-cutover backlog, NOT here.

## Tests
grep per FLAGS.md table = only FLAGS.md history; grep TEMP-GUARD = empty; characterization ZERO diffs;
full battery GREEN.

## Rollback / Checklist
git revert. - [ ] No getenv in core/models/{validation,commands,config,state} - [ ] FLAGS.md updated: all rows marked deleted with commit sha
