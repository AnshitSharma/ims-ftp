# Handoff — U-SM.4 — StateGuard wiring (shadow → enforce) — 2026-07-12

## Current State
Implemented per `migration/03-state-machines/execution-packs/U-SM.4.md`. Status:
**implemented, not verified** — per SESSION_PROTOCOL.md, an independent session must verify.
**STATE_MACHINE_ENABLED is unset in production (defaults to `off`) — this unit changes zero
production behavior until a human sets the flag.** Only `off` behavior was exercised as
"production-equivalent" in this session; `shadow`/`enforce` were exercised on the scratch DB only.

## Completed Work
- `core/models/state/StateGuard.php` (NEW): `mode()` (getenv → $_ENV → default 'off', whitelist
  off/shadow/enforce, exact `PcieLaneBudgetValidator::currentMode()` pattern per FLAGS.md).
  `checkMutation(PDO $pdo, array $lockedRow): ?array` — rule: `status_v2` ∈
  {draft, building, maintenance} allows mutation; any other non-null status_v2 blocks
  (`error_type=config_immutable`); NULL status_v2 falls back to the legacy int rule
  (`configuration_status === 3` blocks, `error_type=config_finalized` — this is also exactly
  TEMP-GUARD's own check, so it doubles as the "legacy verdict" shadow mode diffs against).
  `off`: no-op, returns null immediately. `shadow`: evaluates both the new rule and the legacy
  rule, logs ONLY disagreements to `reports/shadow/state-guard.jsonl`, always returns null (never
  blocks — TEMP-GUARD stays the sole enforcement). `enforce`: returns the new rule's verdict
  authoritatively.
- `core/models/server/ServerBuilder.php` (MODIFIED, 3 sites):
  - `addComponent()` and `removeComponent()`: `StateGuard::checkMutation()` called first (right
    after the row lock). `enforce` mode returns its verdict and skips the TEMP-GUARD block
    entirely (`if (StateGuard::mode() !== 'enforce') { ...TEMP-GUARD... }`) — the marker comment
    and legacy check are **skipped, not deleted**, exactly as the pack specifies (physical removal
    is U-D.4's job). `off`/`shadow` fall through to the unmodified legacy TEMP-GUARD check.
  - `finalizeConfiguration()` (audit V-1 closure): gained an optional trailing `$userId = 0`
    parameter (backward-compatible — the sole existing call site, `server_api.php`'s
    `handleFinalizeConfiguration()`, is unmodified and still compiles/works, defaulting to 0).
    When `StateGuard::mode() === 'enforce'`, **before** the pre-existing `validateConfiguration()`
    call: calls `StateMachine::assertConfigTransition($pdo, $configUuid, 'finalized', $userId)`;
    denies with `error_type=transition_denied` if the edge doesn't exist or the user lacks
    `required_permission`; if `requires_validation` is true (it is, for `validated→finalized`),
    also calls `validateConfigurationComprehensive($configUuid)` **under the same row lock** and
    denies with `error_type=config_invalid` if it fails. The pre-existing weak
    `validateConfiguration()` call **was not removed** — it still runs afterward, per the pack's
    explicit instruction (removal is U-C.5's job). `off`/`shadow` skip this whole block (no-op).

## Design decisions worth a human glance
- **`finalizeConfiguration()`'s new gate runs BEFORE the legacy `validateConfiguration()` call**,
  not after — a judgment call not specified verbatim by the pack (which only said "assertConfigTransition...
  replaces the raw status check" without stating order relative to the legacy validation call,
  since finalizeConfiguration had no raw status check to begin with). Reasoning: fail fast on
  transition-legality/permission before running the more expensive validation walk; does not
  violate the pack's "do not remove the legacy call" instruction since it still runs, just second.
- **Known gap, deliberately not closed here (would require touching `api/handlers/server/server_api.php`,
  which is outside this unit's `Files Created (1) / Modified (1)` box per the pack)**: the one
  production call site of `finalizeConfiguration()` does not pass a real `$userId` — it stays at
  the new parameter's default, `0`. User `0` has no ACL permissions, so **if `STATE_MACHINE_ENABLED`
  is ever set to `enforce` without also updating that call site, every finalize would be denied
  with `error_type=transition_denied`** (fail-closed, not a silent bypass — but still a functional
  break). This is proven directly by this unit's own test (`state_guard_test.php`'s "enforce:
  finalizeConfiguration denies validated->finalized for unprivileged userId=0" assertion — it's a
  proof of the gap, not just a happy-path check). **A follow-up unit (likely alongside U-C.5 or a
  small standalone wiring fix) must pass `$user['id']` from `server_api.php` into
  `finalizeConfiguration()` before `STATE_MACHINE_ENABLED` can ever move from `shadow` to `enforce`
  in production.** Flagging this prominently so nobody flips the flag straight to `enforce` and is
  surprised finalize breaks fleet-wide.
- **`checkMutation()`'s shadow-mode log only records disagreements**, not every call — chosen to
  keep `reports/shadow/state-guard.jsonl` a signal (things to look at before flipping to enforce),
  not noise (every single add/remove call). The pack's acceptance criteria ("shadow log populated
  on scratch runs") is satisfied — disagreement cases exist and are proven to log correctly.
- **The `maintenance` status_v2 case is the one real, concrete divergence this unit's shadow mode
  is built to catch**: `StatusMap::CONFIG_V2_TO_LEGACY['maintenance'] === 3` (from U-SM.3, same
  legacy int as finalized/deployed/retired — legacy vocabulary is smaller, so it collapses), but
  the *new* rule explicitly allows mutation in `maintenance`. Under `shadow`, this correctly blocks
  today (legacy TEMP-GUARD still wins) while logging the disagreement; under `enforce`, mutation
  in `maintenance` becomes newly ALLOWED. This is an intentional design goal, not a bug — it's the
  whole reason `maintenance→available/deployed` transitions exist in U-SM.2's table — but it is a
  real, user-visible behavior change the moment `enforce` is flipped, worth calling out explicitly
  before that decision is made.

## Verification performed (scratch env `C:\tmp\ims-ftp-scratch`, DB `ims_compat_golden`)
- `php -l` on `StateGuard.php`, modified `ServerBuilder.php`, `state_guard_test.php` — all clean.
- **NEW `tests/regression/state_guard_test.php`** (21 assertions) — **ALL PASS**:
  - `off`: `mode()` reports `off`; `checkMutation()` always null.
  - `shadow`: `maintenance` config (status_v2 allows, legacy int=3 blocks) still blocked by
    TEMP-GUARD (shadow never overrides) AND the divergence is logged correctly
    (`new_verdict_blocks=false`, `legacy_verdict_blocks=true`, `status_v2=maintenance`).
    `deployed` config (both rules agree → blocked) logs **no** divergence — proves the log only
    records disagreements.
  - `enforce`: `maintenance` now ALLOWED (new rule overrides legacy int=3) on both `addComponent`
    and (separately) `removeComponent`; `deployed` still blocked but now with
    `error_type=config_immutable` (new-rule reason, distinct from the old `config_finalized`);
    NULL status_v2 falls back to the legacy int rule correctly in both directions (int=3 blocks,
    int=0 allows).
  - `enforce`: `finalizeConfiguration()`'s V-1 gate is wired — denies `validated→finalized` for
    unprivileged `userId=0` with `error_type=transition_denied`, no `status_v2` mutation, no PDO
    transaction left open (this is also the proof of the known call-site gap above).
- `tests/regression/finalized_immutability_test.php` re-run (flag unset = `off`, i.e.
  production-equivalent today) — **ALL PASS**, byte-identical to every prior session's run,
  including the static `TEMP-GUARD(U-0.2)` marker-count check (still 2 — confirmed markers are
  skipped in enforce mode, never deleted).
- `php tests/characterize_compatibility.php` (flag unset = `off`) — exit 0, 15 configurations,
  100 add-time replays, no fatal errors (only the same pre-existing `Undefined array key "model"`
  warning noise seen in every characterization run this migration). Zero-diff by construction: the
  `off`-mode code path through both TEMP-GUARD sites and `finalizeConfiguration()` is byte-identical
  to before this unit (the new `StateGuard::checkMutation()` call executes but its `off`-mode
  return is unconditionally `null` before any evaluation, and every new finalize block is gated
  behind `if (StateGuard::mode() === 'enforce')`, never entered when unset).
- Full regression/unit suite re-run: `tests/regression/dual_write_test.php`,
  `tests/regression/nested_transaction_test.php`, `tests/unit/config_component_repository_test.php`,
  `tests/regression/ledger_dual_write_test.php`, `tests/unit/resource_catalog_test.php`,
  `tests/backfill/ledger_backfill_test.php`, `tests/state_machine_unit.php` — **ALL PASS**,
  unaffected by this unit.
- `php scripts/verify/run_all.php --gate P3` — schema GREEN, inventory RED with the same 3
  pre-existing `referenced_while_available` violations documented in every prior session (not a
  regression), regression SKIPPED (no dedicated report script; covered by the manual suite above).
- `grep -c "TEMP-GUARD(U-0.2)" core/models/server/ServerBuilder.php` → 2 (both markers present,
  confirmed not deleted).

## Known Risks / Discoveries
- **The `server_api.php` userId gap described above is the single biggest blocker to ever flipping
  `STATE_MACHINE_ENABLED` to `enforce`** — flag it clearly to whoever makes that call. Setting the
  flag to `shadow` first (the pack's mandated progression: off → shadow (soak) → enforce, never
  skip shadow) is unaffected by this gap since `finalizeConfiguration`'s new gate is a full no-op
  in `shadow` mode.
- Same local-environment gotchas as prior sessions apply; scratch environment still running and
  current through this unit's changes.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-4 (finalized immutability) | PASS — `finalized_immutability_test.php` still passes unmodified in `off` mode; `state_guard_test.php` proves the broader `enforce`-mode rule (draft/building/maintenance only) is strictly more restrictive, never less |
| INV-10 (pin before change) | PASS — characterization run to completion, exit 0, zero-diff by construction (off-mode code paths unchanged) |
| INV-11 (unit box) | PASS — 3 files changed (1 new `StateGuard.php`, 1 modified `ServerBuilder.php` at 3 sites, 1 new test), well under 500 LOC, 1 concept (mutability as a function of state) |
| INV-12 (flags) | PASS — `STATE_MACHINE_ENABLED` already listed in `FLAGS.md`/`ARCHITECTURAL_INVARIANTS.md` with exactly the values used (off/shadow/enforce); no new flag created |

## Checklist (from pack)
- [x] Shadow provably side-effect-free — `checkMutation()` in shadow mode always returns `null`
  (test-proven); its only side effect is an append-only log write, and even that only fires on
  disagreement.
- [x] V-1 comprehensive-under-lock added at enforce — `finalizeConfiguration()`'s new block calls
  `validateConfigurationComprehensive()` while still holding the row lock (`lockAndLoadConfigRow`'s
  `FOR UPDATE`, not released until commit/rollback).
- [x] TEMP-GUARD skipped, not deleted — both markers present, both legacy blocks still compile and
  run in `off`/`shadow` mode, only bypassed structurally (`if` branch) in `enforce`.

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md,
migration/ARCHITECTURAL_INVARIANTS.md, migration/handoffs/U-SM.4-20260712.md (this file), then run
the independent verify pass for U-SM.4 per SESSION_PROTOCOL.md. Before ever setting
STATE_MACHINE_ENABLED=enforce in production, first close the server_api.php userId gap documented
in this handoff's 'Known Risks' section (a small follow-up unit) — enforce mode is fully blocked
from finalizing anything fleet-wide until that's done."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-SM.3-20260709.md
- migration/handoffs/U-SM.4-20260712.md (this file)
- core/models/state/StateGuard.php
- core/models/server/ServerBuilder.php (search `StateGuard` for all 3 sites)

## Expected Context Size
~25k tokens
