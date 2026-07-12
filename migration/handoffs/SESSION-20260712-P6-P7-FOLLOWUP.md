# Handoff — U-C.2 fix, DB-scenario authoring, fleet parity sweep, U-C.4 review, U-A.1/U-A.2/U-A.3 — 2026-07-12 (follow-up session)

Continues the same day's earlier batch (`SESSION-20260712-P5-COMPLETION.md`,
`SESSION-20260712-P6-COMMAND-LAYER.md`). Reads that session's independent
verify record (embedded at the bottom of the P6 handoff) as its starting
point and works through its two open findings plus P7 (API adapters).

## Current State

P5 stays complete/verified (unchanged this session). P6: U-C.1/U-C.3 remain
`verified`; U-C.2/U-C.4/U-C.5 remain `implemented` (Finding 1 fixed, Finding
2 deliberately NOT fixed, U-C.4 self-reviewed with no defects — none of this
promotes a unit to `verified`, per this session's own instruction). U-C.6
stays `blocked`. **P7 is no longer `not_started`** — U-A.1 (redesigned),
U-A.2, and U-A.3 all shipped this session as `implemented`. All
`COMMAND_LAYER_ENABLED`-gated behavior stays inert at `off` (production's
value); the two brand-new actions (`server-replace-component`,
`server-transition-status`) additionally require the flag to be `shadow` or
`enforce` just to be reachable at all — a deliberate posture, not in the
original packs.

## Completed Work

### 1. Finding 1 fix (U-C.2 shadow-dispatch defect)
- `api/handlers/server/server_api.php` (`handleAddComponent`): the shadow
  branch previously never called the legacy add and never set `$result`
  (undefined variable) — flipping `COMMAND_LAYER_ENABLED=shadow` would have
  broken every add in production. Now mirrors `handleRemoveComponent`
  (U-C.3): legacy `addComponent()` always runs in shadow, `$result` is set
  from its real outcome, and the command verdict is compared against
  `!$result['success']` (the real outcome) instead of a hardcoded `false`.
- `tests/regression/add_command_test.php`: added two structural assertions
  proving the fix (legacy call present in the shadow branch; comparison
  uses the real outcome, not the old hardcoded precheck-passed literal).

### 2. Real DB-backed scenario code (all four `tests/regression/*_command_test.php`)
- New shared helper `tests/regression/_scratch_db.php` (GOLDEN_DB_* connect,
  returns null on failure — never throws).
- `add_command_test.php`: enforce-mode RAM add via a real (rolled-back)
  transaction, revision-bump + no-duplicate-row assertions.
- `remove_command_test.php`: motherboard-with-children blocks without
  cascade (`dependency.blocked_removal`), the same removal with
  `cascade=true` does not.
- `replace_command_test.php`: RAM A→B `dryRun()` proves the replace
  mechanics run end-to-end against real data (not asserting non-blocking,
  since a real fleet config may carry unrelated pre-existing failures per
  the prior verify record's own live-probe finding).
- `finalize_command_test.php`: walks the REAL `config_status_transitions`
  chain (draft→building→validating→validated→finalized) via `StateMachine`
  before calling `TransitionStatusCommand::dryRun()` — deliberately does
  NOT attempt `draft→finalized` directly (that would just re-surface
  Finding 2, not test this unit); also flips one live component's
  `status_v2` to `failed` and confirms V-2 (`SystemInventoryStateRule`)
  blocks.
- **All four self-skip with an honest `SKIPPED` line this session** — see
  Known Risks below for why the DB still wasn't reachable.

### 3. Fleet-wide offline parity sweep
- New `scripts/verify/fleet_parity_sweep.php`. Forces `ENGINE_MODE=shadow`
  for the process only (never touches production `.env`), then replays
  every persisted component of every `server_configurations` row through
  `ServerBuilder::validateComponentAddition()` — identical extraction loop
  to `tests/characterize_compatibility.php`, reusing the EXISTING
  `ENGINE_MODE=shadow` hook (already wired since U-V.3) and its
  `ShadowRunner::record()` call rather than reimplementing the legacy/engine
  comparison. Reads back only the JSONL lines this run appended, classifies
  each diff against `scripts/verify/expected_diffs.json` (same
  `legacy_blocked`/`engine_blocked`/`engine_error_class` matcher as
  `parity_report.php`), and writes a timestamped triage report. **Adds NO
  expected_diffs.json entries itself** — every unmatched diff is printed for
  human triage, per the explicit instruction. Confirmed (this session) to
  fail closed with a clear stderr message and exit 1 when the DB is
  unreachable, rather than a false "0 diffs" green.

### 4. U-C.4 self-review
Read `ReplaceComponentCommand.php` in full against its pack and its own
checklist: single-TargetState RP-1 mechanics confirmed (`TargetStateBuilder
::withAdd()` always appends, so `end($replaced->components())` correctly
retrieves the just-added row's synthetic id), re-anchoring confirmed both
in-memory (`buildTarget`) and in the DB (`apply`'s `UPDATE config_components
SET parent_id`), slot-inheritance-then-fallback confirmed, no
two-transaction path exists. **No defects found.** Stays `implemented` (this
session doesn't self-certify to `verified`).

### 5. U-A.1 — redesigned flag-gated (deviation from the pack)
The pack's literal text deletes the advisory validation block and the
handler-level SFP auto-assign SQL in `handleAddComponent` UNCONDITIONALLY.
Per the owner's decision this session, redesigned instead:
- The advisory pre-check block is now skipped **only** at
  `CommandLayer::mode() === 'enforce'` (mirrors U-C.5's identical precedent
  in `handleFinalizeConfiguration`) — unchanged at `off`/`shadow`.
- The SFP auto-assign SQL is **NOT deleted** — `AddComponentCommand` has no
  equivalent logic yet; deleting it would be a real feature regression, not
  a scaffolding risk. Documented as a carried-forward gap.
- `X-IMS-Deprecation` response header added unconditionally to both
  `handleAddComponent`/`handleRemoveComponent` (informational only, per the
  pack).
- New `migration/08-api-adapters/DEPRECATION.md` documents the full
  deviation and the gap.
- New `tests/api/add_remove_response_shape_test.php` (structural; the
  pack's golden-fixture byte-equality criterion needs a DB, marked SKIPPED).

### 6. U-A.2 — new actions + serial/revision/quantity params
- `server_api.php`: new actions `server-replace-component` (→
  `handleReplaceComponent`) and `server-transition-status` (→
  `handleTransitionStatus`), both gated behind `CommandLayer::mode() !==
  'off'` (a deliberate addition beyond the pack: neither command has run
  against production data yet, so exposing them un-gated would be the first
  ungated command-layer surface this migration ships).
- `expected_revision` param + 409-with-`current_revision` on ALL FOUR
  mutation handlers (add/remove/replace/transition).
- `quantity < 1` → 400 on add. `quantity > 1` maps to N sequential
  `AddComponentCommand` dispatches inside ONE outer transaction
  (all-or-nothing), enforce mode only — each dispatch re-locks the same
  UUID's inventory rows (`ORDER BY Status ASC`), so each iteration naturally
  claims the next available physical unit.
- `serial_number` was already threaded through `handleRemoveComponent` by
  U-C.3; confirmed present, not re-added.
- New seeder `database/seeders/2026_07_12_001_add-server-replace-transition-
  permissions.sql` — `server.replace`/`server.transition` ACL rows, grants
  mirrored from `server.edit`/`server.create`. **Shown below, NOT run.**
- `api/permission_map.php`: `replace-component` → `server.replace`,
  `transition-status` → `server.transition`.
- New `tests/api/new_actions_test.php` (structural; DB-backed scenarios —
  replace happy/blocked, transition legal/illegal, 409, serial-targeted
  remove — marked SKIPPED).

### 7. U-A.3 — response shims
- New `api/handlers/server/VerdictShim.php`: `Verdict` → legacy envelope
  (`success`, `message`, `error_type`, `warnings`, `details`,
  `recommendations`). `RULE_TO_LEGACY_TYPE` is a small explicit const
  (per-row comment citing the legacy check it replaces) covering
  `socket_mismatch`, `cpu_limit_exceeded`, `duplicate_component`,
  `motherboard_required`, `no_pcie_slots_available`, the new
  `dependency_blocked`, and the two engine-exception classes mapped to
  `validation_exception`. Everything else falls back to
  `compatibility_failure` — never a 500 from an unmapped rule id. RAM
  enrichment: `memory.downclock` details surface in `recommendations[]`
  alongside the real blocking reason (downclock is a WARNING, never itself
  the blocking rule).
- Wired into all four mutation handlers' `CommandFailed` catch blocks (when
  `errorType === 'validation_blocked'` and `$e->verdict !== null`).
- New `tests/unit/verdict_shim_test.php` — 14/14 PASS, DB-free (builds
  `Verdict`/`RuleResult` objects directly).

## Remaining Work

- Every DB-backed scenario written this session (4 regression files + 2 api
  tests + the fleet parity sweep) needs an actual run against a reachable
  scratch DB.
- U-C.6 stays blocked on its own 7-day-enforce-soak precondition (unchanged).
- Finding 2 (draft/building→finalized edge-table gap) is UNFIXED, as
  instructed — it will make `finalize_command_test.php`'s DB scenario stop
  partway through the chain-walk for any real config already at
  draft/building with no further edges defined; the test documents this
  inline rather than working around it.
- SFP auto-assignment still has no command-layer equivalent (U-A.1's
  DEPRECATION.md gap) — needed before U-A.1's original unconditional-
  deletion approach, or before `COMMAND_LAYER_ENABLED=enforce` is ever
  soaked for real.
- U-R.2's phase-status.json entry already reads `verified` (a prior
  session's independent verify updated it) — not touched this session, no
  action needed.

## Known Risks

- **Local MySQL is unreachable again this session** — same symptom as the
  original implementer session (`Access denied for user 'root'@'localhost'
  (using password: NO)`), NOT the "service was just stopped" situation the
  independent verifier found and fixed last time. The owner's explicit
  instruction this session was to write seeders + code rather than chase
  DB/credential access further — followed. Every DB-backed scenario this
  session wrote is real, reviewed code that self-skips honestly; none of it
  has actually been executed against live data yet.
- `tests/unit/config_component_repository_test.php` (pre-existing, not
  touched this session) still fatally throws `PDOException` with no DB
  rather than self-skipping — confirmed NOT a regression from this session
  (it was never touched), just an older test that predates this session's
  self-skip convention.
- The two new v2-only actions (`server-replace-component`,
  `server-transition-status`) have NEVER been exercised end-to-end (no DB
  this session) — their gating logic is structurally tested only.

## Invariant Check Results

| Invariant | Result |
|---|---|
| INV-3 (commands are the only transaction owner) | PASS — no new `beginTransaction()` outside `BaseCommand`; the U-A.2 quantity>1 loop opens its OWN outer tx in the handler (API layer, not a command), joined by each command's own `execute()` |
| INV-6 (mutations bump revision + events) | PASS — unchanged this session |
| INV-8 (dual-write never forks silently) | PASS — Finding 1's fix is exactly what closes a real INV-8 violation risk |
| INV-11 (unit box) | Deliberately exceeded for U-A.1/U-A.2/U-A.3 combined (many files) — same class of documented deviation as prior sessions' PD-notes, not a new violation pattern |
| INV-12 (flags are rollout scaffolding) | PASS — `COMMAND_LAYER_ENABLED` still the only flag touched; new actions add a stricter (not looser) gate |

## Acceptance Test Results

All commands run via `/c/xampp/php/php.exe`, DB-free only (see Known Risks):

```
tests/unit/verdict_test.php                       PASS (unchanged)
tests/unit/verdict_shim_test.php                   PASS  14/14 (new)
tests/unit/resource_catalog_test.php               PASS  49/49 (unchanged)
tests/unit/base_command_test.php                   PASS  17/17 (unchanged)
tests/unit/rules/*.php (7 files)                   PASS (unchanged)
tests/regression/add_command_test.php              PASS  (2 new assertions + DB scenario written, SKIPPED)
tests/regression/remove_command_test.php           PASS  (DB scenario written, SKIPPED)
tests/regression/replace_command_test.php          PASS  (API-reachability assertions updated for U-A.2; DB scenario written, SKIPPED)
tests/regression/finalize_command_test.php         PASS  (DB scenario written, SKIPPED)
tests/api/add_remove_response_shape_test.php        PASS  (new, 6/6 structural)
tests/api/new_actions_test.php                      PASS  (new, 11/11 structural)
scripts/verify/fleet_parity_sweep.php               exit 1, fails CLOSED with a clear "cannot connect" message (not run for real — no DB)
tests/unit/config_component_repository_test.php     FATAL (pre-existing, unrelated to this session, no DB)
php -l on every touched/created file                clean
```

## Seeder shown to owner (NOT run)

`database/seeders/2026_07_12_001_add-server-replace-transition-permissions.sql`
— see the file itself; adds `server.replace`/`server.transition` permission
rows and mirrors grants from `server.edit`/`server.create` respectively.

## Next Prompt To Use

"Continue the IMS migration. Read this handoff and the two earlier same-day
handoffs (P5-COMPLETION, P6-COMMAND-LAYER) for full context. Priorities, in
order: (1) get local MySQL access resolved (or provided) so every DB-backed
scenario written across these three sessions can actually run; (2) once DB
access exists, run `scripts/verify/fleet_parity_sweep.php` for real and
triage its output; (3) U-C.6 remains blocked on its own soak precondition;
(4) Finding 2 (draft/building->finalized edge gap) still needs an owner
decision (walk intermediate transitions in-command vs. a new direct edge vs.
adopting the multi-step lifecycle in handlers) before any enforce flip."

## Files To Load Into Context (next session)

- This file + `SESSION-20260712-P6-COMMAND-LAYER.md`'s independent verify
  record (bottom section)
- `migration/phase-status.json`
- `migration/08-api-adapters/DEPRECATION.md`
- `api/handlers/server/server_api.php` (handleAddComponent, handleRemoveComponent,
  handleReplaceComponent, handleTransitionStatus)
- `api/handlers/server/VerdictShim.php`
- `scripts/verify/fleet_parity_sweep.php`

## Expected Context Size

~35k tokens (this file + phase-status.json + the touched server_api.php
sections + VerdictShim.php + the fleet sweep script).

---

## Independent verify record — 2026-07-12 (Claude Fable, separate session from implementer)

**Verdict: U-C.5, U-A.1, U-A.3 VERIFIED. U-C.2, U-C.4, U-A.2 stay `implemented` (one new
cross-command finding + open execution gaps). Finding 1's fix is CONFIRMED correct.**

**Environment, settled for good**: local MySQL was reachable all along — the service was running
(started by the prior verify session) and the known scratch credential works; the implementing
session's error (`using password: NO`) shows it connected WITHOUT passing the password. Procedure
for any future session: start XAMPP's mysqld if down, pass the scratch credential via the
`GOLDEN_DB_*` env vars the tests already read. Every DB-backed scenario written this batch was
EXECUTED this session (except two noted below).

### Executed this session (all on scratch, `ims_compat_golden`)
- U-C.2 fix confirmed by code review: shadow branch now always runs the legacy add, sets `$result`
  from it, and compares the command verdict against the real outcome with a null-verdict guard.
- DB-backed scenarios RAN GREEN with rollback proven: remove (block-without-cascade /
  pass-with-cascade), replace (A→B dryRun end-to-end), finalize (real edge-chain walk
  draft→…→finalized + V-2 failed-inventory block) — this is U-C.5's first real execution
  evidence and the basis for its promotion.
- Full sweep: all 21 unit/rule/regression suites ALL PASS (including config_component_repository
  and the two api structural tests — their earlier "failures" were this verify session's own
  file-sync gaps, not defects), verdict_shim 14/14, characterization exit 0 (baseline restored),
  gate PL GREEN.
- U-A.1 gate reviewed (advisory pre-check skipped ONLY at enforce; SFP auto-assign retained),
  U-A.2 registration reviewed (strict permission_map rows present, both new actions correctly
  403 at off), seeder reviewed (idempotent, `permissions`/`role_permissions` only), VerdictShim
  read in full. U-C.4 ReplaceComponentCommand read in full (RP-1 single-state, re-anchor both
  layers, slot inheritance) — closes the prior pass's review debt.

### NEW FINDING A — commands skip legacy's availability check (blocks U-C.2/U-C.4/U-A.2 verified)
Legacy `addComponent()` validates the LOCKED inventory row via `checkComponentAvailability()`
with an `override_used` protocol (ServerBuilder ~743-754) before claiming it. Neither
`AddComponentCommand` nor `ReplaceComponentCommand` ports this: their `lockAndCheckComponent()`
copies fetch `ORDER BY Status ASC` (failed=0 sorts FIRST) and never test Status — under
COMMAND_LAYER_ENABLED=enforce an add/replace could claim a failed or in-use physical unit with no
override. (`system.inventory_state` does not cover this: it triggers only on VALIDATE/FINALIZE.)
Inert while the flag stays off. Fix: port the availability check (+ override option) into both
commands' buildTarget, and U-A.2's quantity>1 loop inherits the fix for free.

### FINDING B — add_command_test.php's DB scenario crashes uncaught
It picks an arbitrary open config + arbitrary available RAM and calls `execute()` bare — on real
fleet data the verdict blocks (this DB: a config whose existing CPU/RAM already mismatch its
board), `CommandFailed` goes uncaught, FATAL. Needs a try/catch treating `validation_blocked` as
a legitimate outcome (assert rollback + no row) and/or a fixture pick that pre-checks
compatibility. Incidentally this crash is REAL evidence the enforce path works end-to-end
(lock→build→evaluate→block→rollback on live MySQL).

### FINDING C — fleet_parity_sweep ran for real: RED, and the dominant cause is systemic
`93 replays / 12 configs: identical=31 expected=9 unexplained=53`. Triage:
- **48 of 53: `engine.rule_exception` — synthetic onboard-NIC spec_uuids** (`onboard-{mbUuid}-{n}`,
  produced by legacy's own onboard-NIC materialization) are not real ims-data UUIDs, so every
  catalog/spec-driven rule (memory.slot_count, pcie.slot_placement, pcie.lane_budget,
  storage.m2_capacity, cpu.socket_count) throws on them and fails closed → the engine blocks on
  ANY config containing an onboard NIC. Needs a dedicated small unit: TargetState/rules must
  recognize onboard rows (e.g. skip catalog lookups for the `onboard-` prefix, or resolve via
  OnboardNICHandler's parent-board spec) BEFORE any ENGINE_MODE=shadow soak can be meaningful.
- **5 of 53: `cpu.socket_match`** — real fleet configs carrying socket-mismatched CPUs that legacy
  tolerated (order-dependent legacy checks). Human triage: bad data vs. intentional-diff entry.
- Report: `reports/fleet-parity-sweep-20260712-132245.json` (scratch copy). The sweep tool itself
  behaved exactly as specified (reuses the U-V.3 hook, adds no expected_diffs entries).

### Still not executed by anyone
The api tests' response-shape golden-fixture criterion and the two-connection concurrency
scenarios (409-after-concurrent-mutation, concurrent-finalize race); the two new v2 actions
end-to-end over HTTP (need the flag non-off in a live web context).
