# Handoff — U-SM.4 + U-L.6 independent VERIFY pass — 2026-07-12

## Current State
Both units **verified** by an independent session (Claude Fable; implementing session was Sonnet).
phase-status.json: U-SM.4 → `verified`, U-L.6 → `verified`, **PL gate reopened** (all 6 PL units
verified; `run_all.php --gate PL` exit 0 captured this session, before the incident below).
**P3 gate stays closed** — three independent reasons: (1) the 7-day STATE_MACHINE_ENABLED=shadow
soak has not started (flag unset in production), (2) inventory report has the 3 pre-existing
`referenced_while_available` violations (RED), (3) the server_api.php userId gap (below) must be
closed before enforce is ever possible.

## What was verified (executed, not just read — scratch env C:\tmp\ims-ftp-scratch)
All 5 changed files hash-confirmed byte-identical between scratch and main working copy.

- **U-SM.4 code review**: StateGuard.php matches FLAGS.md's read pattern and the off/shadow/enforce
  semantics (off = unconditional null before any evaluation; shadow = logs disagreements only,
  always returns null; enforce = authoritative). All 3 ServerBuilder sites correct: enforce skips
  (never deletes) TEMP-GUARD; off/shadow fall through to the byte-identical legacy block; both
  `TEMP-GUARD(U-0.2)` markers present. finalize gate runs entirely inside
  `if (mode()==='enforce')` — provably a no-op today.
- **U-L.6 code review**: `extractLaneCount()` now structurally identical to
  `PcieLaneBudgetValidator::extractLaneCount()` (guard → regex → fallback → 0); docblock claim now
  accurate; the corrected + new test fixtures cover both the absent-interface (→ 0) and
  unparseable-non-empty-interface (→ pcie_lanes fallback) paths.
- **Suites re-run, ALL PASS**: `state_guard_test.php` (21), `resource_catalog_test.php` (46, incl.
  U-L.6's corrected/new fixtures), `finalized_immutability_test.php` (incl. marker-count x2),
  `ledger_backfill_test.php`, `ledger_dual_write_test.php`, `dual_write_test.php`,
  `nested_transaction_test.php`, `config_component_repository_test.php`, `state_machine_unit.php`.
- `characterize_compatibility.php` — exit 0; golden baseline restored via `git checkout --` after.
- `run_all.php --gate PL` — **exit 0** (schema GREEN, ledger GREEN) — captured at 19:09, BEFORE the
  incident below; the U-L.6/U-SM.4 code was already in place for that run (hashes matched first).

## INCIDENT — scratch golden DB damaged by this verify session (not by any implemented unit)
`tests/state_machine_unit.php` defaults to its own throwaway DB (`ims_scratch_smunit`); this
session overrode `SM_TEST_DB_NAME` to `ims_compat_golden` by mistake. The test's setup
drops/recreates only the tables it needs — most other tables in `ims_compat_golden`
(config_components, config_resources, 9 of 10 inventory tables, backfill tables, …) were destroyed.
Consequences and facts:
- **No verify result is tainted**: every suite and the PL gate report above ran BEFORE the damage
  (the damage was discovered because the post-run re-check of gate reports went RED with
  `table_missing`).
- Production untouched. Main working copy untouched. Golden baseline JSON restored via git.
- **Before any future DB-backed test/verify session: rebuild `ims_compat_golden`** the same way it
  was built (production dump `imsbdcmsbharatda_Ims_Production.sql` + all 24
  `database/seeders/*.sql` in filename order). The DROP/recreate needs user approval (denied by
  permission policy in this session — correctly).
- Lesson for future sessions, recorded so it isn't repeated: NEVER point `state_machine_unit.php`
  (or any test with its own default throwaway DB) at `ims_compat_golden` — run it against its
  default `ims_scratch_smunit` and provide creds via `SM_TEST_DB_*`.

## Findings (verified)
1. **U-SM.4's server_api.php userId gap is real and enforce-blocking** (confirmed by
   state_guard_test's own denial proof): `handleFinalizeConfiguration()` never passes `$user['id']`,
   so `STATE_MACHINE_ENABLED=enforce` would deny every finalize (`transition_denied`, fail-closed).
   Must be fixed by a small wiring unit before enforce. Shadow is unaffected.
2. **maintenance-status behavior change at enforce** (intentional, user-visible): mutation in
   `maintenance` is blocked today (legacy int=3) but ALLOWED under enforce. Shadow mode logs
   exactly this divergence. Whoever flips enforce should expect it.
3. Pre-existing, unchanged: inventory report's 3 `referenced_while_available` violations;
   `user_id has no default` logging noise in dual_write/nested_transaction tests.

## Remaining Work / Next (in order)
1. **Human**: approve + run the scratch DB rebuild (dump + 24 seeders).
2. **Human**: flip `DUAL_WRITE_ENABLED=on` in production `.env` (24h soak) → then execute U-B.4's
   runbook (`reports/backfill-signoff-DRAFT.md`) → P2 gate.
3. Optional now / required before enforce: small unit passing `$user['id']` from server_api.php
   into `finalizeConfiguration()`.
4. **Human decision**: set `STATE_MACHINE_ENABLED=shadow` in production to start P3's 7-day soak.
5. P4 (U-V.1) becomes reachable once P2/P3 gates open per the execution-order rule.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-4 | PASS — finalized_immutability_test unchanged-pass in off mode; enforce rule strictly more restrictive except the deliberate maintenance case (Finding 2). |
| INV-10 | PASS — characterization exit 0, baseline git-restored. |
| INV-11 | PASS — this verify session modified NO production code; only phase-status.json + this handoff. |
| INV-12 | PASS — no new flags; StateGuard consumes STATE_MACHINE_ENABLED exactly as FLAGS.md specifies. |

## Files To Load Into Context (next session)
- migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-SM.4-20260712.md, U-L.6-20260712.md, this file
- reports/backfill-signoff-DRAFT.md (for the U-B.4 production run)

## Expected Context Size
~20k tokens
