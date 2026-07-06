# 07-component-migration — Phase P2: Backfill (JSON → rows)
Objective: materialize every existing configuration's JSON components into config_components +
config_resources. Idempotent, resumable, auditable, dry-run-first.
Prerequisites: P1 + PL gates open. PRECONDITION FOR LIVE RUN: DUAL_WRITE_ENABLED=on in production
for ≥24h BEFORE the backfill live run (so new mutations self-materialize; backfill only owns history).
Affected files: scripts/backfill/* (new). Affected DB tables: writes config_components,
config_resources, config_events(event='backfill'); NEW migration_backfill_state, backfill_quarantine.
Order: U-B.1 → U-B.2 → U-B.3 → U-B.4. Rollback: rows created by backfill carry
event payload {"source":"backfill","run_id":...} — rollback = delete by run_id (procedure in U-B.1).
Verification: equivalence 0 diffs fleet-wide; orphan report clean-or-quarantined; ledger green.
Risks: legacy quirks (hbacard scalar, onboard NICs, riser subtype, serial-less entries, quantity>1
CPU/RAM entries) — each has an explicit extractor rule in U-B.2; anything unmatched goes to
quarantine, NEVER guessed. Duration: 4 sessions + fleet run time.
Handoff: next U-SM.1. Context ~30k.
