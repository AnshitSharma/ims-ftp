# Test manifest — the migration sweep, enumerated

Written 2026-07-13 (eleventh session) in response to the tenth-session verify
finding: "38/38" was reported without an enumerated file list and could not be
reconciled. This file is now the canonical list every future session's sweep
counts must be reported against. If the list below and a session's actual
`find`/`Glob` output disagree, update this file in the same session and say so
in the handoff — don't let the two silently drift.

None of `tests/` is deployed (SFTP ignore list, per `ims-ftp/CLAUDE.md`) — this
manifest only matters for local/scratch runs.

## 1. Canonical suite — 55 discovered files, eight directories

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
| `tests/` (root, non-recursive) | 5 | `getDashboardDataShapeTest.php`, `lane_authority_unit.php`, `nic_sfp_authority_unit.php`, `state_machine_unit.php`, `storage_bay_authority_unit.php` |
| **Total discovered** | **55** | |

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

**2026-08-30, later the same day: `tests/` root wired in (B-17).** `SUITE_DIRS`
never pointed at `tests/` itself, where eight real files lived — the fourth
instance of the exact drift described above, one directory up. Resolved per
file rather than deferred:

- `lane_authority_unit.php`, `nic_sfp_authority_unit.php`,
  `storage_bay_authority_unit.php` — already passing; just needed discovering.
- `state_machine_unit.php` — its minimal fixture schema had no `user_permissions`
  table, so `ACL::loadUserPermissions`'s direct-grant JOIN (added 2026-08-30)
  threw on every permission check and failed them all closed. Fixed by adding
  the table (empty; nothing here exercises a temporary grant).
- `getDashboardDataShapeTest.php` — its `FakePDO` stubbed only `prepare()`, and
  `inventoryTableExists()` (added for the risercard/serverplatform rollout)
  calls `query()` directly; fatal on load. Fixed by stubbing `query()` too, and
  by reading `$expectedTypes` from `VALID_COMPONENT_TYPES` instead of a
  hand-typed 10-item list that had silently stopped covering `risercard` and
  `serverplatform`. Its `check()` helper was also silent on success — printed
  no `PASS`/`FAIL` lines, which read as `RAN NOTHING (0 checks)` per this
  file's own §"Reporting convention" below — so it now prints one line per
  assertion like every other suite in this tree.
- `memory_authority_unit.php`, `slot_storage_authority_unit.php`,
  `serverstate_equivalence.php` — **deleted**, not repaired. Two required
  `core/models/compatibility/MemoryAuthority.php` / `SlotAuthority.php`, which
  do not exist anywhere under `core/`. The third asserted
  `ServerState::getComponents() ≡ ServerBuilder::extractComponentsFromJson()`;
  U-D.3a deleted `extractComponentsFromJson()` outright (see
  `tests/regression/read_router_test.php`'s own check that it's gone), so one
  side of the equivalence no longer exists to compare against. All three tested
  a subject that is gone by design, not by accident — the same class of defect
  `fixture_scenarios_real.php` was retired for, not repaired.
- `run_tests.php`'s `NOT_A_SUITE` gained `run_tests.php` itself (self-recursion),
  `characterize_compatibility.php` (a golden-master CAPTURE tool — running it
  would overwrite `tests/golden/compatibility_baseline.json` every sweep), and
  `fixture_scenarios_real.php` (deliberately exits 2, not pass/fail).

Net: 50 → 55 discovered, 47 → 52 passed, 3 ran nothing unchanged (all three
pre-existing and documented below), 8 named top-level files → 5 folded into
the main count + 3 deleted. §2 and §3 below are rewritten to match.

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

## 2. Named top-level scripts, pre-dating the `*_test.php` convention

**Closed out 2026-08-30 (B-17).** Until today these 8 files lived directly
under `tests/` and were run by hand, separately from `run_tests.php`'s count,
because `SUITE_DIRS` never pointed at `tests/` itself. Now: 5 are discovered as
part of §1's 55 (folded in, no longer named separately), and 3 are deleted. See
the 2026-08-30 reconciliation note in §1 for the per-file reasoning. This
section is kept only as a historical record of what used to live here.

| File | Disposition |
|---|---|
| `tests/lane_authority_unit.php` | now discovered (§1, `root`) |
| `tests/nic_sfp_authority_unit.php` | now discovered (§1, `root`) |
| `tests/storage_bay_authority_unit.php` | now discovered (§1, `root`) |
| `tests/state_machine_unit.php` | now discovered (§1, `root`) — no longer excluded; see §3 |
| `tests/getDashboardDataShapeTest.php` | now discovered (§1, `root`) |
| `tests/memory_authority_unit.php` | **deleted** — required `MemoryAuthority.php`, which does not exist under `core/` |
| `tests/slot_storage_authority_unit.php` | **deleted** — required `SlotAuthority.php`, which does not exist under `core/` |
| `tests/serverstate_equivalence.php` | **deleted** — asserted equivalence with `ServerBuilder::extractComponentsFromJson()`, which U-D.3a deleted |

`tests/fixture_scenarios_real.php` is not in this table: it was never a named
legacy script counted alongside these 8, and stays excluded from the sweep —
see §3.

## 3. Explicitly excluded from the sweep count

- **`tests/characterize_compatibility.php`** — not a pass/fail test; it's the
  golden-master capture/diff tool the sweep's "characterization" step invokes
  directly (see phase-status.json's `baseline` gate report, `lands_in` =
  this file). Reported as "byte-identical to baseline" / "N drift", never as
  exit-code pass/fail alongside the 55. Also currently unusable as a parity
  gate — see BACKLOG B-4.
- **`tests/fixture_scenarios_real.php`** — deliberately exits 2, not 0/1;
  DISABLED since both its subjects are gone (P9 deleted the `validate*`
  methods it drives, U-D.3c dropped the columns its fixtures insert). Named in
  `NOT_A_SUITE` since 2026-08-30 so it is never swept in as a false failure.
- **`scripts/verify/fleet_parity_sweep.php`** — not a test file at all; the
  offline fleet-wide engine-vs-legacy replay tool. Reported separately as
  "N replays / M configs / unexplained=K", per its own convention.
- **`scripts/verify/*_report.php`** (schema/ledger/slot/orphan/inventory/
  performance/parity/deadcode) — gate reports, invoked via
  `scripts/verify/run_all.php`, reported as GREEN/RED per report name, never
  folded into the 55. (`equivalence_report.php` and `partial_rows_report.php`
  were retired 2026-08-30 with U-D.3c — see `BACKLOG.md` B-8.)

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
