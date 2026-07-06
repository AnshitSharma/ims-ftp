# U-P.2 — Docs + monitoring + backlog
## Files Created (3)
docs/ARCHITECTURE.md (as-built: schema, state machines, engine, commands — condensed from target
design, updated to reality); docs/OPERATIONS.md (reports, alerts, rollback-playbook pointer,
maintenance-mode swap runbook for operators); migration/BACKLOG.md — deferred items with owners:
inventory-table unification + hard FK (F-6), legacy int status demotion (U-D.4 note),
RV-1 dimm consumer links, firmware/BIOS rule family (needs ims-data schema additions —
system.firmware_matrix VF per target design §7.3), NUMA balance W rule.

## Checklist
- [ ] Backlog items each cite their origin unit/finding - [ ] Ops doc tested by a human following it cold
