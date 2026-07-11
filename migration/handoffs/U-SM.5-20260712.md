# Handoff — U-SM.5 — server_api.php finalizeConfiguration() userId wiring — 2026-07-12

## Current State
Implemented per `migration/03-state-machines/execution-packs/U-SM.5.md` (pack written this same
session, since this is a fix unit discovered by the U-SM.4 handoff and confirmed by the U-SM.4/U-L.6
independent verify pass, not a pre-planned unit). Status: **VERIFIED 2026-07-12** — see the
"Independent verify record" section appended at the bottom of this file (recorded in-file rather
than as a separate handoff, per the user's fewer-md-files instruction).

## Independent verify record — 2026-07-12 (Claude Fable, separate session from implementer)
- server_api.php:1161 confirmed: `finalizeConfiguration($configUuid, $finalNotes, $user['id'])`
  matches ServerBuilder.php:3625's `($configUuid, $notes = '', $userId = 0)` signature; `$user['id']`
  already in scope (used pre-existing at line 1166), so no new failure mode.
- Scratch copy hash-identical to main; `php -l` clean.
- `state_guard_test.php` (21) ALL PASS; `finalized_immutability_test.php` ALL PASS (off-mode
  production-equivalent behavior unchanged).
- `run_all.php --gate PL` exit 0 (schema GREEN, ledger GREEN) — also confirms the rebuilt
  `ims_compat_golden` DB is healthy post-incident.
- phase-status.json: U-SM.5 → `verified`. P3 gate stays closed for its remaining reasons only:
  the 7-day STATE_MACHINE_ENABLED=shadow soak (not started) and the pre-existing inventory-report
  RED (3 `referenced_while_available`). The enforce-blocking userId gap is now closed.

## What shipped
Closes the enforce-blocking gap documented in `migration/handoffs/U-SM.4-20260712.md` and confirmed
in `migration/handoffs/U-SM.4-U-L.6-VERIFY-20260712.md` Finding 1:
`api/handlers/server/server_api.php`'s `handleFinalizeConfiguration()` now passes `$user['id']` into
`ServerBuilder::finalizeConfiguration()` instead of relying on its `$userId = 0` default.

- `api/handlers/server/server_api.php:1161`: `$serverBuilder->finalizeConfiguration($configUuid,
  $finalNotes)` → `$serverBuilder->finalizeConfiguration($configUuid, $finalNotes, $user['id'])`.
  `$user` was already in scope in this function (used at line 1149 for the
  `created_by`/`server.finalize` permission check just above).

## Why this mattered (and why it's inert today)
`finalizeConfiguration()`'s `$userId` parameter (added by U-SM.4) is only read inside
`if (StateGuard::mode() === 'enforce')` — the V-1 gate that calls
`StateMachine::assertConfigTransition($pdo, $configUuid, 'finalized', $userId)`. With
`STATE_MACHINE_ENABLED` unset (defaults to `off`) in production, that branch never executes, so this
change has **zero observable effect on current production behavior**. It matters only for the day
`STATE_MACHINE_ENABLED` is ever set to `enforce`: without this fix, every finalize call would have
been evaluated with `userId=0`, which carries no ACL permissions, so
`StateMachine::assertConfigTransition()` would have denied every finalize fleet-wide
(`error_type=transition_denied`) — fail-closed per INV-5, but a functional outage. This fix removes
that prerequisite blocker.

## Verification performed (scratch env `C:\tmp\ims-ftp-scratch`, DB `ims_compat_golden`, rebuilt this
session — see the DB-rebuild note below)
- `php -l api/handlers/server/server_api.php` — clean.
- `grep -n "finalizeConfiguration(\$configUuid, \$finalNotes"` confirms the call site now passes
  `$user['id']`.
- Full regression sweep — **ALL PASS**, unaffected: `dual_write_test.php`,
  `finalized_immutability_test.php`, `nested_transaction_test.php`, `state_guard_test.php`,
  `config_component_repository_test.php`, `ledger_dual_write_test.php`, `resource_catalog_test.php`,
  `ledger_backfill_test.php`. `state_guard_test.php`'s userId=0 denial assertion still passes
  unaffected — it exercises `ServerBuilder::finalizeConfiguration()` directly, not through this
  handler, so it correctly continues to prove the transition-legality gate itself works regardless of
  what any one caller passes.
- `state_machine_unit.php` — run against its own default throwaway DB `ims_scratch_smunit` (NOT
  `ims_compat_golden` — see the incident note in the prior verify handoff), 13/13 PASS.
- `php tests/characterize_compatibility.php` — exit 0, 12 configurations, 93 add-time replays, zero
  diffs (golden baseline file restored via `git checkout --` after, per this migration's standing
  practice — this harness always rewrites the baseline file on every run by design, so it must be
  restored unless a baseline change is intentional and reviewed).

## Scratch DB rebuild performed this session (prerequisite, requested explicitly)
`ims_compat_golden` had been damaged by the prior verify session's `state_machine_unit.php` incident
(documented in `migration/handoffs/U-SM.4-U-L.6-VERIFY-20260712.md`). Rebuilt this session, local
only, production untouched:
1. `DROP DATABASE IF EXISTS ims_compat_golden; CREATE DATABASE ims_compat_golden CHARACTER SET
   utf8mb4 COLLATE utf8mb4_unicode_ci;`
2. Loaded `imsbdcmsbharatda_Ims_Production.sql` — clean, exit 0.
3. Applied all 24 `database/seeders/*.sql` files in filename order — all exit 0 (a few seeders print
   informational `SELECT`/`ROW_COUNT()` diagnostic rows to stdout; none are errors).
4. `php scripts/verify/run_all.php --gate PL` — **GREEN** (schema GREEN, ledger GREEN), run from the
   scratch copy `C:\tmp\ims-ftp-scratch` (not the main working copy — its local `.env` points at an
   unrelated local database and must never be used for verify runs; confirmed no production
   credentials or hosts were touched, `DB_HOST=localhost` there is itself a local-only reference
   copy).
5. Confirmed 42 tables present in the rebuilt `ims_compat_golden`.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-4 (finalized immutable) | PASS — `finalized_immutability_test.php` unaffected; this unit only changes an argument value, not guard logic |
| INV-11 (unit box) | PASS — 1 file, 1 line, 1 concept |

## Known Risks / Discoveries
No new positive-path test (a privileged real user id proceeding past the enforce gate) was added —
constructing one requires seeding a real ACL role grant for a throwaway test user id, which needs
more setup than this 1-line wiring unit's box allows and no existing fixture/helper in this test
suite does it. The existing negative-path assertion in `state_guard_test.php` (userId=0 → denied)
already proves the parameter threads through correctly; this unit only changes what value the one
production call site supplies. Recommended before `STATE_MACHINE_ENABLED` is ever flipped to
`enforce`: either add that positive-path fixture, or treat the first `enforce` flip itself as the de
facto live test (shadow-mode soak should surface any wiring problem before enforce is even
considered, per P3's gate note).

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md,
migration/ARCHITECTURAL_INVARIANTS.md, migration/handoffs/U-SM.5-20260712.md (this file), then run
the independent verify pass for U-SM.5 per SESSION_PROTOCOL.md."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-SM.4-20260712.md, U-SM.4-U-L.6-VERIFY-20260712.md
- migration/handoffs/U-SM.5-20260712.md (this file)
- api/handlers/server/server_api.php:1127-1161
- core/models/server/ServerBuilder.php:3625-3680

## Expected Context Size
~15k tokens
