# U-B.4 — Fleet run + sign-off gate
Concept: P2 gate execution. Pins baseline: no. Invariants: INV-8.

## Inputs
scripts/backfill/README.md runbook; rollback-playbook.md; 11-verification/README.md.

## Files Created (1)
reports/backfill-signoff-<date>.md — filled from template embedded in this pack: run_id, configs
total/done/quarantined/error, quarantine reasons histogram, equivalence --all result, orphan/ledger/
slot/inventory report results, human sign-off line.

## Procedure (operator + AI paired; AI prepares commands, human executes on prod)
1. Confirm DUAL_WRITE_ENABLED=on ≥24h (grep env + deploy log).
2. --dry-run full fleet; review plan counts vs `SELECT COUNT(*) FROM server_configurations`.
3. --execute; monitor; --resume on interruptions (idempotent).
4. Quarantine triage: every row gets a ticket or a documented extractor fix (new unit) — quarantine
   is not a landfill.
5. Run: equivalence --all, orphan, ledger, slot, inventory. ALL GREEN ⇒ fill signoff, open P2 gate.

## Tests / Completion
run_all.php --gate P2 exit 0 on production replica; signoff file committed.

## Rollback
--rollback-run <id>; gate stays closed.

## Checklist
- [ ] 24h dual-write precondition evidenced - [ ] Zero unexplained quarantines - [ ] Signoff committed
