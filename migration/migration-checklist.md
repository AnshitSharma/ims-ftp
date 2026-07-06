# MIGRATION MASTER CHECKLIST
Tick strictly top-to-bottom. A phase's boxes may not be ticked until the previous phase's GATE row is ticked.
"Reports green" = the named reports in phase-status.json gate_reports exited 0 on the current commit.

## P0 — Foundation (01-foundation)
- [ ] U-0.1 fail-closed validation (add path + API)
- [ ] U-0.2 finalized-immutability guard (add/remove, in-lock)
- [ ] U-0.3 nestable transactions (removeComponent, deleteConfiguration)
- [ ] U-0.4 verification harness + baseline capture (scripts/verify/run_all.php)
- [ ] GATE P0: baseline captured; orphan report run recorded; regression suite green

## P1 — Schema (02-schema)  [zero behavior change; all flags off]
- [ ] U-1.1 config_components table (+rollback)
- [ ] U-1.2 config_resources table (+rollback)
- [ ] U-1.3 config_events table + revision column (+rollback)
- [ ] U-1.4 ConfigComponentRepository (no callers)
- [ ] U-1.5 dual-write hooks behind DUAL_WRITE_ENABLED=off
- [ ] U-1.6 equivalence_report.php
- [ ] GATE P1: schema report green; regression green; prod behavior diff = none (flag off)

## PL — Resource ledger (06-resource-ledger)
- [ ] U-L.1 ResourceCatalog (spec → provided capacities)
- [ ] U-L.2 ledger dual-writer (providers + consumers)
- [ ] U-L.3 ledger_report.php
- [ ] GATE PL: ledger report green on scratch DB fixtures

## P2 — Backfill (07-component-migration)  [DUAL_WRITE_ENABLED=on first — see U-B.1 preconditions]
- [ ] U-B.1 backfill skeleton: state table, dry-run, resume, quarantine
- [ ] U-B.2 per-column extractors (10 JSON shapes → rows)
- [ ] U-B.3 ledger backfill
- [ ] U-B.4 full-fleet verification + sign-off
- [ ] GATE P2: equivalence 0 diffs fleet-wide; orphan report 0 (or quarantined+ticketed); ledger green

## P3 — State machines (03-state-machines)
- [ ] U-SM.1 status_v2 columns (config + unified inventory lifecycle view)
- [ ] U-SM.2 transition tables + seed rows
- [ ] U-SM.3 StateMachine service + legacy mapping dual-write
- [ ] U-SM.4 StateGuard wired (STATE_MACHINE_ENABLED=shadow → enforce after 7-day soak)
- [ ] GATE P3: inventory report green; zero shadow-mode guard violations logged for 7 days

## P4 — Validation engine skeleton (04-validation-engine, U-V.*)
- [ ] U-V.1 value objects (Severity, RuleResult, Verdict, Trigger, Rule interface)
- [ ] U-V.2 TargetStateBuilder (rows primary, JSON fallback)
- [ ] U-V.3 Engine + registry + shadow runner (ENGINE_MODE=shadow)
- [ ] U-V.4 parity diff report generator
- [ ] GATE P4: engine runs in shadow on every add/remove with zero exceptions for 3 days

## P5 — Rule migration (04-validation-engine, U-R.*)  [one family per unit; parity after each]
- [ ] U-R.1 CPU rules   - [ ] U-R.2 memory rules   - [ ] U-R.3 slot placement rules
- [ ] U-R.4 lane budget (single model)   - [ ] U-R.5 storage rules
- [ ] U-R.6 network rules   - [ ] U-R.7 system/config rules   - [ ] U-R.8 dependency resolver rule
- [ ] GATE P5: parity report: explained-diff-only (each diff maps to an audit finding ID); 7-day soak

## P6 — Command layer (05-command-layer)
- [ ] U-C.1 BaseCommand   - [ ] U-C.2 AddComponentCommand (shadow→enforce)
- [ ] U-C.3 RemoveComponentCommand (+cascade)   - [ ] U-C.4 ReplaceComponentCommand
- [ ] U-C.5 TransitionStatus/Finalize command   - [ ] U-C.6 legacy tx removal from ServerBuilder paths
- [ ] GATE P6: COMMAND_LAYER_ENABLED=enforce 7 days; performance report Δp95 ≤ +20%; equivalence green

## P7 — API adapters (08-api-adapters)
- [ ] U-A.1 add/remove routed through commands + deprecation headers
- [ ] U-A.2 new actions: replace-component, transition-status; serial_number on remove; qty≥1 reject
- [ ] U-A.3 response shims (legacy JSON shape from Verdict)
- [ ] GATE P7: golden API-response fixtures byte-compatible where documented; new actions tested

## P8 — Read cutover (09-cutover)
- [ ] U-X.1 ConfigReadRouter (READ_FROM_ROWS off→sample→on)
- [ ] U-X.2 cutover runbook executed + full verification battery
- [ ] GATE P8: all seven reports green with READ_FROM_ROWS=on for 14 days

## P9 — Cleanup (10-cleanup)
- [ ] U-D.1 delete duplicate validation (API advisory + Phase 1.5 pairwise)
- [ ] U-D.2 delete legacy validators trio + getConfigurationWarnings
- [ ] U-D.3 drop JSON columns (after 30-day zero-diff proof)
- [ ] U-D.4 delete all flags + Phase 0 temporary guards superseded by state machine
- [ ] GATE P9: deadcode report green; final equivalence run archived

## P10 — Post-cutover (12-post-cutover)
- [ ] U-P.1 CI invariant checks wired
- [ ] U-P.2 monitoring + runbooks + docs
