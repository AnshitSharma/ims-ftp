# Handoff — U-SM.2 + U-SM.3 independent VERIFY pass — 2026-07-12

## Current State
Both units **verified** by an independent session (this session did not implement either —
respects SESSION_PROTOCOL's implement/verify split). phase-status.json updated:
U-SM.2/U-SM.3 → `verified`. **P3 gate stays `closed`** — U-SM.4 is still `not_started`/being
implemented this same session, and the gate rule requires all P3 units verified, which U-SM.4
cannot be yet (implement/verify split applies to it too).

Scratch copies of all touched files confirmed byte-identical to the main working copy (`diff -q`)
before verification began:
`database/seeders/2026_07_10_002_create-status-transitions.sql` (+rollback),
`core/models/state/StatusMap.php`, `core/models/state/StateMachine.php`,
`core/models/server/ServerBuilder.php`, `scripts/verify/inventory_report.php`,
`scripts/verify/expected_schema.json`, `tests/state_machine_unit.php`.

## What was verified (actually executed on scratch env `C:\tmp\ims-ftp-scratch`, DB `ims_compat_golden`)

**U-SM.2 (transition tables):**
- `config_status_transitions` = 12 rows, `inventory_status_transitions` = 17 rows — already
  applied to the scratch DB from a prior session; row counts match the pack exactly.
- `failed→available` absent from `inventory_status_transitions` — confirmed empty result set.
- `validated→finalized` row confirmed `required_permission='server.finalize'`,
  `requires_validation='full'`.
- Every config-lifecycle enum value (8) and every inventory-lifecycle enum value (8) appears in
  ≥1 row (from OR to) — anti-join queries both empty.

**U-SM.3 (StateMachine service + legacy sync):**
- `php -l` on `StatusMap.php`, `StateMachine.php`, `ServerBuilder.php`,
  `scripts/verify/inventory_report.php` — clean.
- `tests/state_machine_unit.php` — 13/13 assertions **PASS** (legal/illegal config transitions
  with/without permission, no-such-edge denial, revision+event bump on apply, unknown status_v2
  throws `InvalidArgumentException`, no-active-transaction throws `RuntimeException`,
  `failed→available` inventory transition denied, both apply* methods write status_v2 + mapped
  legacy column together).
- `scripts/verify/inventory_report.php` — RED, but with the **same 3 pre-existing
  `referenced_while_available` violations** documented in every prior session
  (hbacard `fe9f525f-...`, motherboard `d8e9f0a1-...`) — **zero** `status_v2_legacy_mismatch`
  violations, meaning the new Check 3 (mapping-agreement) added by U-SM.3 is clean on current
  scratch data. Not a regression; matches `migration/handoffs/U-B.3-VERIFY-20260711.md`'s known
  baseline.
- `php tests/characterize_compatibility.php` — **this is the first time this was actually run to
  completion against the golden DB for this unit** (the U-SM.3 implementing session lacked DB
  access and instead argued verdict-neutrality by construction; this session has scratch DB
  access and ran it for real): exit 0, 15 configurations captured, 100 add-time replays, no
  fatal errors (only pre-existing `Undefined array key "model"` warnings, unrelated noise seen in
  every prior characterization run in this migration). No pre-U-SM.3 baseline artifact exists to
  diff against byte-for-byte (this scratch env was built after U-SM.3's changes were already
  applied), so this is a **clean-run proof**, not a byte-diff proof — consistent with the
  implementing session's written argument that the two touched call sites
  (`finalizeConfiguration`, `updateComponentStatusAndServerUuid`) are value-identical to the old
  code, which this run does not contradict.
- `php scripts/verify/run_all.php --gate P3` — schema GREEN, inventory RED (same pre-existing
  3 violations), regression SKIPPED (no dedicated report script; covered by the manual suite
  below instead).
- Full regression/unit suite re-run: `tests/regression/dual_write_test.php`,
  `tests/regression/finalized_immutability_test.php`,
  `tests/regression/nested_transaction_test.php`,
  `tests/unit/config_component_repository_test.php`,
  `tests/regression/ledger_dual_write_test.php`, `tests/unit/resource_catalog_test.php`,
  `tests/backfill/ledger_backfill_test.php` — **ALL PASS**, unaffected by U-SM.2/U-SM.3.

Code review: `StateMachine.php` never opens a transaction or acquires a lock (grep confirmed no
`beginTransaction`/`lockForUpdate` calls); every method documents the caller-holds-lock
precondition. `StatusMap.php`'s lossy reverse maps match the pack's literal mapping
(`draft→0, building→2, validating→2, validated→1, finalized→3, deployed→3, maintenance→3,
retired→3`). `ServerBuilder.php`'s two call sites confirmed unchanged in shape since the U-SM.3
handoff's description (finalize via `applyConfigTransition`, inventory status write appends
`status_v2` into the existing dynamic UPDATE — same statement, not a second query).

## Findings (none blocking)
- Same latent `extractLaneCount()` divergence already logged in
  `migration/handoffs/U-L.4-U-L.5-VERIFY-20260712.md` — unrelated to P3, addressed as its own fix
  unit later in this session (see `migration/handoffs/U-L.6-20260712.md`).
- No new findings specific to U-SM.2/U-SM.3.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-9 (paired rollback) | PASS — U-SM.2's seeder has a rollback file, mechanically confirmed present |
| INV-11 (unit box) | PASS for both — no scope creep found on re-read of the shipped diffs |
| INV-10 (pin before change) | PASS — `characterize_compatibility.php` now actually run to completion (exit 0, 15 configs/100 replays) against golden data on scratch, closing the gap the U-SM.3 implementing session could only argue around |
| INV-2 (spirit: one validation owner) | PASS — confirmed no new business-rule logic added to `ServerBuilder.php`, only status-write delegation |

## Remaining Work / Next
- P3 gate stays `closed` until U-SM.4 exists and is itself independently verified in a future
  session.
- Everything else unchanged from the U-SM.3 handoff's "Next" section.

## Files To Load Into Context (next session)
- migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-SM.2-20260708.md, U-SM.3-20260709.md, this file
- migration/03-state-machines/execution-packs/U-SM.4.md (if continuing P3)

## Expected Context Size
~20k tokens
