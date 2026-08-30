# Test manifest — the migration sweep, enumerated

Written 2026-07-13 (eleventh session) in response to the tenth-session verify
finding: "38/38" was reported without an enumerated file list and could not be
reconciled. This file is now the canonical list every future session's sweep
counts must be reported against. If the list below and a session's actual
`find`/`Glob` output disagree, update this file in the same session and say so
in the handoff — don't let the two silently drift.

None of `tests/` is deployed (SFTP ignore list, per `ims-ftp/CLAUDE.md`) — this
manifest only matters for local/scratch runs.

## 1. Canonical suite — 45 discovered files, seven directories

**2026-08-24 reconciliation.** The table below was written on 2026-07-13 and had
drifted badly by today: it listed 30 files in five directories, while
`tests/run_tests.php` discovers 45 in seven. Two separate causes, both now
fixed:

1. `run_tests.php`'s `SUITE_DIRS` only listed `regression` and `unit`, so
   `tests/api/*` and `tests/backfill/*` — 4 files, including unit U-A.1's own
   stated acceptance artifact `api/add_remove_response_shape_test.php` — were
   never discovered at all. `api` and `backfill` were added to `SUITE_DIRS` on
   2026-08-24.
2. Suites added between 2026-07-13 and today (the whole of
   `unit/compatibility/`, the whole of `unit/validation/`, and six files under
   `regression/` and `unit/`) were never added to this table.

The counts below are the live `run_tests.php` discovery, not a hand-typed list.
**Regenerate this table from a real run, never from memory** — the numbers here
are only meaningful as a reconciliation target for the runner's own output.

| Directory | Count | Files |
|---|---|---|
| `tests/api/` | 2 | `add_remove_response_shape_test.php`, `new_actions_test.php` |
| `tests/backfill/` | 0 | empty since 2026-08-30 — `extractor_test.php`, `ledger_backfill_test.php` and `_ud3b_reader_parity.php` were deleted with their subjects by U-D.3c. The directory and its `SUITE_DIRS` entry stay on purpose; see `tests/backfill/README.md` |
| `tests/regression/` | 19 | `add_command_test.php`, `caddy_finalize_parity_test.php`, `component_delete_guard_test.php`, `dual_write_test.php`, `fail_closed_test.php`, `finalize_command_test.php`, `finalized_immutability_test.php`, `ledger_dual_write_test.php`, `location_aware_requests_test.php`, `nested_transaction_test.php`, `read_router_test.php`, `remove_command_test.php`, `replace_command_test.php`, `request_transition_action_test.php`, `require_paths_test.php`, `serial_less_unit_identity_test.php`, `state_guard_test.php`, `stock_missing_prerequisite_test.php`, `transaction_ownership_test.php` |
| `tests/unit/` | 11 | `base_command_test.php`, `component_entry_identity_test.php`, `config_component_repository_test.php`, `engine_shadow_test.php`, `onboard_nic_engine_test.php`, `rate_limiter_concurrency_test.php`, `resource_catalog_test.php`, `slot_namespace_test.php`, `target_state_test.php`, `verdict_shim_test.php`, `verdict_test.php` |
| `tests/unit/rules/` | 8 | `cpu_rules_test.php`, `dependency_rule_test.php`, `lane_rule_test.php`, `memory_rules_test.php`, `net_rules_test.php`, `slot_rules_test.php`, `storage_rules_test.php`, `system_rules_test.php` |
| `tests/unit/compatibility/` | 6 | `compatible_listing_engine_test.php`, `cpu_identity_matcher_test.php`, `motherboard_storage_gate_test.php`, `platform_spec_resolution_test.php`, `spec_resolver_guard_test.php`, `storage_bay_placement_test.php` |
| `tests/unit/validation/` | 4 | `finalize_trigger_coverage_test.php`, `m2_capacity_rule_test.php`, `onboard_nic_pcie_count_test.php`, `ram_type_normalization_test.php` |
| **Total discovered** | **50** | |

**2026-08-24 (part two) reconciliation.** The regression row read 15 and the
total 45; a live `run_tests.php` discovery reports 16 and 46 —
`regression/transaction_ownership_test.php` was missing from the table. Same
drift shape as the note above, one row down.

**2026-08-30 reconciliation.** The table had drifted again, in both directions
at once. The regression row listed `shadow_dry_run_error_test.php`, which P9
deleted with the shadow machinery — so that row's count of 19 was right only by
accident, counting a file that does not exist while its true membership was 18.
`unit/compatibility/` read 3 and holds 6 (`compatible_listing_engine_test.php`,
`platform_spec_resolution_test.php`, `spec_resolver_guard_test.php` were never
added). With those corrected and `regression/component_delete_guard_test.php`
added this session, the table now matches a live discovery exactly: **50**.

That is the third time this table has drifted from the directories it describes,
which is the same wrong-denominator shape `run_tests.php`'s own header was
written about. The runner is a glob and cannot drift; this file is typed by hand
and keeps doing so. Reconcile it against `php tests/run_tests.php` output rather
than by reading it.

Non-suites in these same directories, excluded by `run_tests.php` itself
(`_`-prefixed helpers plus the `NOT_A_SUITE` list) and therefore not counted
above: `tests/regression/_scratch_db.php` (shared `scratch_db_connect()`),
`tests/regression/run_serial_less_check.php` (single-purpose probe, named in
`NOT_A_SUITE`), `tests/api/_http_harness.php` (shared HTTP harness client).

`tests/api/*`'s DB+HTTP-backed criteria self-skip unless `IMS_HTTP_HARNESS_URL`
points at a running scratch-only `php -S` server. **The sharp edge found
2026-08-24 (they printed per-criterion `SKIPPED` lines, then `ALL CHECKS PASS`,
exited 0, and were therefore counted as `pass` while their acceptance criteria
had not run) is CLOSED:** both files now emit the `SKIPPED: 0 check(s) run`
marker on that branch and are reported as `RAN NOTHING (declared)`. Their offline
structural checks are kept and still fail the suite (exit 1) when broken.

## 2. Named top-level legacy scripts — 8 files

Pre-date the `*_test.php` convention; live directly under `tests/`, not in a
subdirectory. Each is independently named here because `tests/` also contains
files that are NOT part of the sweep at all (§3).

| File | Self-skips when DB unreachable? |
|---|---|
| `tests/lane_authority_unit.php` | no (DB-free) |
| `tests/memory_authority_unit.php` | no (DB-free) |
| `tests/nic_sfp_authority_unit.php` | no (DB-free) |
| `tests/slot_storage_authority_unit.php` | no (DB-free) |
| `tests/storage_bay_authority_unit.php` | no (DB-free) |
| `tests/serverstate_equivalence.php` | no (DB-free) |
| `tests/getDashboardDataShapeTest.php` | no (DB-free) |
| `tests/fixture_scenarios_real.php` | **DISABLED 2026-08-30** — exits 2 immediately. P9 deleted the three `validate*` methods it drives and U-D.3c dropped the columns its fixtures insert; a self-skip would have misreported that as "environment not available". Previously: **yes**, as of 2026-07-13 — env-gated `PROBE_DB_*` connection (default `imsbdcmsbharatda_Ims_Production` local mirror), prints `SKIPPED: ...` and exits 0 if that DB isn't reachable (mirrors `tests/regression/_scratch_db.php`'s convention). Previously hard-fataled (uncaught `PDOException`, exit 255) in any environment without that exact local DB mirror — this was the tenth-session verify finding's root cause, fixed this session. |

**45 discovered + 8 named legacy = 53 files.** (Until 2026-08-24 this line
read "30 canonical + 8 named legacy = 38" — see §1's reconciliation note for
why both halves of that arithmetic were wrong.)

The 8 legacy scripts above live directly under `tests/`, which is NOT in
`run_tests.php`'s `SUITE_DIRS` — adding `tests/` itself would sweep in
`characterize_compatibility.php` and `state_machine_unit.php`, both explicitly
excluded (§3). They are therefore still run by hand, and a `run_tests.php`
number never includes them. Report the two figures separately.

## 3. Explicitly excluded from the sweep count

- **`tests/state_machine_unit.php`** — DB-backed, but **must never be run
  against `ims_compat_golden`** (standing rule, all sessions). It targets a
  different, purpose-built state-machine fixture DB. Not part of any "N/38"
  or "N/30" figure in this or future handoffs; report it separately by name
  if it's ever run.
- **`tests/characterize_compatibility.php`** — not a pass/fail test; it's the
  golden-master capture/diff tool the sweep's "characterization" step invokes
  directly (see phase-status.json's `baseline` gate report, `lands_in` =
  this file). Reported as "byte-identical to baseline" / "N drift", never as
  exit-code pass/fail alongside the 38.
- **`scripts/verify/fleet_parity_sweep.php`** — not a test file at all; the
  offline fleet-wide engine-vs-legacy replay tool. Reported separately as
  "N replays / M configs / unexplained=K", per its own convention.
- **`scripts/verify/*_report.php`** (schema/ledger/slot/equivalence/orphan/
  inventory/performance/parity/deadcode) — gate reports, invoked via
  `scripts/verify/run_all.php`, reported as GREEN/RED per report name, never
  folded into the 38.

## Reporting convention going forward

A sweep result must read: **"N discovered / P passed / F failed / R ran
nothing"** — `run_tests.php`'s own final line, quoted verbatim — plus the legacy
count separately if those 8 were run. As of 2026-08-24 (part two) "ran nothing"
rests on TWO independent signals and needs only one: the suite printing the
`SKIPPED: 0 check(s) run` marker (reported as `RAN NOTHING (declared)`), or the
runner counting zero `PASS`/`FAIL` lines in the suite's output (reported as
`RAN NOTHING (0 checks)`). The second signal exists because the first was
opt-in: `tests/unit/rate_limiter_concurrency_test.php` printed one bare
`SKIP: pcntl not available` line, executed none of its assertions, and was
counted as a **pass** on every Windows box — the same fail-open as the
`tests/api/*` one above, in a suite nobody had audited because it is DB-free. Do not report a bare "N/M" without
stating which list M refers to, and never report "ran nothing" folded into
"passed": as of 2026-08-24 `run_tests.php` exits **3** (not 0) when any suite
exited 0 without executing a check, so `scripts/verify/run_all.php`'s
`regression` gate report reads that case as RED rather than green.

`regression` in `run_all.php`'s registry now points at `tests/run_tests.php`
(2026-08-24). Before that it was `available => false` and printed
`regression: SKIPPED` in all nine gates that list it, without affecting the
exit code — so this manifest's counts were the ONLY thing standing between the
suite and an unverified gate. They now have a runner behind them.
