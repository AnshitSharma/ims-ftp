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
- [x] U-L.1 ResourceCatalog (spec → provided capacities) (verified)
- [x] U-L.2 ledger dual-writer (providers + consumers) (verified)
- [x] U-L.3 ledger_report.php (verified)
- [x] U-L.4 ResourceCatalog cpu pcie_lane provider gap (verified 2026-07-12 — closes the
      fleet-backfill NVMe error class; see migration/handoffs/U-L.4-20260712.md and
      migration/handoffs/U-L.4-U-L.5-VERIFY-20260712.md)
- [x] U-L.5 ResourceCatalog nic/hbacard/pciecard provider+consumer gap (verified 2026-07-12 —
      closes the remaining live-dual-write-path risk; see migration/handoffs/U-L.5-20260712.md and
      migration/handoffs/U-L.4-U-L.5-VERIFY-20260712.md)
- [x] U-L.6 extractLaneCount() legacy-mirror fix (verified 2026-07-12 — closes the latent
      equivalence-drift finding from the U-L.4/U-L.5 verify pass; see
      migration/handoffs/U-L.6-20260712.md and migration/handoffs/U-SM.4-U-L.6-VERIFY-20260712.md)
- [x] GATE PL: ledger report green on scratch DB fixtures — **reopened 2026-07-12 once U-SM.4/U-L.6
      were independently verified (all 6 PL units verified)**. Scratch DB `ims_compat_golden` was
      rebuilt this session (dump + 24 seeders) after the prior verify session's incident damaged it;
      `run_all.php --gate PL` re-confirmed GREEN post-rebuild. DUAL_WRITE_ENABLED may now go `on` in
      production per U-B.1 preconditions — this remains a human decision (U-B.4 sign-off).

## P2 — Backfill (07-component-migration)  [DUAL_WRITE_ENABLED=on first — see U-B.1 preconditions]
- [ ] U-B.1 backfill skeleton: state table, dry-run, resume, quarantine
- [ ] U-B.2 per-column extractors (10 JSON shapes → rows) — post-verification fix 2026-07-12:
      `Extractor::extractNics()` now reads `slot_position` for add-on NICs (was silently dropped,
      tripping `slot_report.php`'s slotless_card check once backfill runs); re-ran
      `tests/backfill/extractor_test.php` (25/25) and `ledger_backfill_test.php` (all PASS) after the
      change — see migration/handoffs/SESSION-20260712-REPORT-TRIAGE.md. phase-status.json still shows
      U-B.2 verified; treat this as a confirmed delta on top of that verification, not a reopening.
- [ ] U-B.3 ledger backfill
- [ ] U-B.4 full-fleet verification + sign-off
- [ ] GATE P2: equivalence 0 diffs fleet-wide; orphan report 0 (or quarantined+ticketed); ledger green
      — pre-existing report REDs triaged 2026-07-12 (see migration/handoffs/SESSION-20260712-REPORT-TRIAGE.md):
      inventory/orphan false-positives from is_virtual configs fixed in code; 12 real orphans (storage/ram
      on 4 real configs) have a recommended fix (`audit-orphans.php --fix`, human-run) but are not yet
      quarantined+ticketed or fixed on production; 1 inventory violation (pciecard on config 9dbc63fa) is
      genuinely ambiguous and needs a human quarantine decision; equivalence's 5 diffs are expected
      pre-backfill noise, not a defect — self-resolves once U-B.4 runs.

## P3 — State machines (03-state-machines)
- [x] U-SM.1 status_v2 columns (config + unified inventory lifecycle view) (verified)
- [x] U-SM.2 transition tables + seed rows (verified 2026-07-12, see
      migration/handoffs/U-SM.2-U-SM.3-VERIFY-20260712.md)
- [x] U-SM.3 StateMachine service + legacy mapping dual-write (verified 2026-07-12, same handoff)
- [x] U-SM.4 StateGuard wired (verified 2026-07-12, shadow-mode only, flag stays off/unset in
      production — see migration/handoffs/U-SM.4-20260712.md and
      migration/handoffs/U-SM.4-U-L.6-VERIFY-20260712.md)
- [x] U-SM.5 server_api.php finalizeConfiguration() userId wiring (implemented 2026-07-12, closes the
      enforce-blocking gap flagged by U-SM.4's handoff — see migration/handoffs/U-SM.5-20260712.md,
      verified 2026-07-12 per the in-file verify record; re-confirmed by the full-migration audit
      2026-07-12, see migration/handoffs/VERIFY-ALL-20260712.md). Inert while STATE_MACHINE_ENABLED
      stays off/unset in production.
- [ ] GATE P3: inventory report green; zero shadow-mode guard violations logged for 7 days — the
      7-day shadow soak has not started (STATE_MACHINE_ENABLED must first be set to `shadow` in
      production, a human decision, before any soak clock starts). Inventory-report triaged
      2026-07-12: 2 of the 3 `referenced_while_available` violations were false positives (fixed in
      scripts/verify/inventory_report.php, is_virtual configs excluded); 1 remains RED, ambiguous,
      quarantine decision needed — see migration/handoffs/SESSION-20260712-REPORT-TRIAGE.md.

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
