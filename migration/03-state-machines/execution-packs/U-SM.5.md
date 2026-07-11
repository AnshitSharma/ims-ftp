# U-SM.5 — server_api.php userId wiring for finalizeConfiguration()
Concept: close the enforce-blocking gap flagged by the U-SM.4 handoff and confirmed by the
U-SM.4/U-L.6 independent verify pass — pass the real authenticated user id into
`finalizeConfiguration()` so its V-1 StateGuard/StateMachine gate can authorize correctly once
`STATE_MACHINE_ENABLED` ever reaches `enforce`.
Pins baseline: yes (zero verdict change — this file is never in `PcieLaneBudgetValidator`'s call
path; `STATE_MACHINE_ENABLED` stays unset/off in production, so the new `$userId` parameter is
inert today regardless of its value).
Invariants: INV-4, INV-11.

## Finding this closes
`migration/handoffs/U-SM.4-20260712.md` and `migration/handoffs/U-SM.4-U-L.6-VERIFY-20260712.md`,
Finding 1: `api/handlers/server/server_api.php`'s `handleFinalizeConfiguration()` calls
`$serverBuilder->finalizeConfiguration($configUuid, $finalNotes)` without a third argument, so the
optional `$userId = 0` parameter (added by U-SM.4) always defaults to `0`. `0` never carries the
`server.finalize` ACL permission, so if `STATE_MACHINE_ENABLED` were ever set straight to `enforce`
without this fix, `StateMachine::assertConfigTransition()` would deny every single finalize
fleet-wide with `error_type=transition_denied` — fail-closed, but a functional outage, not a
security gap. `tests/regression/state_guard_test.php`'s own "enforce: finalizeConfiguration denies
validated->finalized for unprivileged userId=0" assertion is the proof this gap is real (it exercises
`ServerBuilder::finalizeConfiguration()` directly with the same default the unfixed handler was
producing).

## Inputs
- `api/handlers/server/server_api.php:1127-1161` (`handleFinalizeConfiguration()`)
- `core/models/server/ServerBuilder.php:3625` (`finalizeConfiguration($configUuid, $notes = '', $userId = 0)`
  signature, added by U-SM.4)
- `migration/handoffs/U-SM.4-20260712.md`, `migration/handoffs/U-SM.4-U-L.6-VERIFY-20260712.md`

## Files Modified (1)
- `api/handlers/server/server_api.php` — `handleFinalizeConfiguration($serverBuilder, $user)` already
  has `$user` in scope (used two lines above for the `created_by`/`server.finalize` permission check
  at line 1149). One-line change: `$serverBuilder->finalizeConfiguration($configUuid, $finalNotes)`
  → `$serverBuilder->finalizeConfiguration($configUuid, $finalNotes, $user['id'])`.

## Why this is safe to ship today (flag stays off)
`finalizeConfiguration()`'s only use of `$userId` is inside `if (StateGuard::mode() === 'enforce')`
(`ServerBuilder.php:3659`), and `StateGuard::mode()` reads `STATE_MACHINE_ENABLED`, which remains
unset in production (defaults to `off`). With the flag off, this branch never executes — passing
the real id instead of the implicit `0` default has **zero observable effect on current production
behavior**. This is pure future-proofing for the day `enforce` is turned on, not a behavior change.

## Tests / Acceptance
- `php -l api/handlers/server/server_api.php` — clean.
- `grep -n "finalizeConfiguration(\$configUuid, \$finalNotes" api/handlers/server/server_api.php` —
  confirms the call site now passes `$user['id']` as the third argument.
- `tests/regression/state_guard_test.php` — ALL PASS, unaffected (it calls
  `ServerBuilder::finalizeConfiguration()` directly, not through this handler, and its assertion
  intentionally still exercises the `userId=0` denial path to prove the transition-legality gate
  itself works — that assertion is about `ServerBuilder`, not about whether the one API call site
  wires a real id).
- Full regression sweep (`dual_write_test.php`, `finalized_immutability_test.php`,
  `nested_transaction_test.php`, `state_guard_test.php`, `config_component_repository_test.php`,
  `ledger_dual_write_test.php`, `resource_catalog_test.php`, `ledger_backfill_test.php`,
  `state_machine_unit.php` against its own default `ims_scratch_smunit` DB) — ALL PASS, unaffected.
- `php tests/characterize_compatibility.php` — exit 0, zero diffs (this handler is never in
  `PcieLaneBudgetValidator`'s verdict-producing call path).
- No new positive-path ("valid privileged user id is now allowed through the enforce gate") test was
  added: constructing one requires seeding a real ACL role grant for a throwaway user id, which is
  outside this 1-line wiring unit's box and not exercised by any existing test fixture/helper in this
  suite. The existing `state_guard_test.php` negative-path assertion (userId=0 → denied) already
  proves `finalizeConfiguration()`'s `$userId` parameter is correctly threaded through to
  `StateMachine::assertConfigTransition()`; this unit only changes what value the ONE production call
  site provides for that parameter. A follow-up unit (or a manual smoke test at `enforce`-soak time)
  should add the positive-path proof once a test ACL fixture exists.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-4 (finalized immutable) | PASS — `finalized_immutability_test.php` unaffected; this unit changes an argument value only, not the guard logic itself |
| INV-11 (unit box) | PASS — 1 file, 1 line changed, 1 concept |

## Rollback
Revert the one-line change (drop the third argument); `finalizeConfiguration()`'s `$userId = 0`
default makes the revert behavior-identical to before this unit. No schema, no seeder, no flag
involved.

## Checklist
- [x] `handleFinalizeConfiguration()` passes `$user['id']` into `finalizeConfiguration()`
- [x] `php -l` clean
- [x] Full regression suite ALL PASS, zero characterization diffs
- [x] Confirmed inert while `STATE_MACHINE_ENABLED` stays off/unset in production
