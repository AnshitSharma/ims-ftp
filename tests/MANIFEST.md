# Test manifest — the migration sweep, enumerated

Written 2026-07-13 (eleventh session) in response to the tenth-session verify
finding: "38/38" was reported without an enumerated file list and could not be
reconciled. This file is now the canonical list every future session's sweep
counts must be reported against. If the list below and a session's actual
`find`/`Glob` output disagree, update this file in the same session and say so
in the handoff — don't let the two silently drift.

None of `tests/` is deployed (SFTP ignore list, per `ims-ftp/CLAUDE.md`) — this
manifest only matters for local/scratch runs.

## 1. Canonical suite — 35 `*_test.php` files, five directories

These are the files `scripts/verify/run_all.php`'s `regression`/`baseline`
registry entries implicitly point at, and what every "N/30" sweep count in
prior handoffs refers to.

| Directory | Count | Files |
|---|---|---|
| `tests/unit/` | 11 | `base_command_test.php`, `component_entry_identity_test.php`, `config_component_repository_test.php`, `engine_shadow_test.php`, `onboard_nic_engine_test.php`, `rate_limiter_concurrency_test.php`, `resource_catalog_test.php`, `slot_namespace_test.php`, `target_state_test.php`, `verdict_shim_test.php`, `verdict_test.php` |
| `tests/unit/rules/` | 8 | `cpu_rules_test.php`, `dependency_rule_test.php`, `lane_rule_test.php`, `memory_rules_test.php`, `net_rules_test.php`, `slot_rules_test.php`, `storage_rules_test.php`, `system_rules_test.php` |
| `tests/regression/` | 12 | `add_command_test.php`, `dual_write_test.php`, `fail_closed_test.php`, `finalize_command_test.php`, `finalized_immutability_test.php`, `ledger_dual_write_test.php`, `nested_transaction_test.php`, `remove_command_test.php`, `replace_command_test.php`, `require_paths_test.php`, `serial_less_unit_identity_test.php`, `state_guard_test.php` |
| `tests/api/` | 2 | `add_remove_response_shape_test.php`, `new_actions_test.php` |
| `tests/backfill/` | 2 | `extractor_test.php`, `ledger_backfill_test.php` |
| **Total** | **35** | |

**Drift corrected 2026-07-25.** The table above previously read 8 / 10 / total
30, which no longer matched the directories. Five files existed on disk but were
unlisted, so any "38/38" sweep figure silently under-counted:

| File | Added by |
|---|---|
| `tests/unit/component_entry_identity_test.php` | first engine-audit remediation pass |
| `tests/unit/slot_namespace_test.php` | second-audit Phase 1 (the three slot-ID namespace discriminators) |
| `tests/unit/rate_limiter_concurrency_test.php` | second-audit Phase 4 (forks real processes; a single-process test passes against the broken implementation, which is why the TOCTOU survived) |
| `tests/regression/require_paths_test.php` | pre-existing, never listed |
| `tests/regression/serial_less_unit_identity_test.php` | pre-existing, never listed |

Non-test helpers in these same directories (not counted above, never run
standalone): `tests/regression/_scratch_db.php` (shared `scratch_db_connect()`
used by the regression suite), `tests/api/_http_harness.php` (shared HTTP
harness client used by the two api tests when `IMS_HTTP_HARNESS_URL` is set).

`tests/api/*`'s DB+HTTP-backed criteria self-skip (print `SKIPPED`, exit 0)
unless `IMS_HTTP_HARNESS_URL` points at a running scratch-only `php -S`
server — see the ninth/tenth-session handoff records for the harness
procedure. A "sweep without the HTTP harness" run still exits 0 for these two
files; it just means fewer criteria actually executed.

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
| `tests/fixture_scenarios_real.php` | **yes**, as of 2026-07-13 — env-gated `PROBE_DB_*` connection (default `imsbdcmsbharatda_Ims_Production` local mirror), prints `SKIPPED: ...` and exits 0 if that DB isn't reachable (mirrors `tests/regression/_scratch_db.php`'s convention). Previously hard-fataled (uncaught `PDOException`, exit 255) in any environment without that exact local DB mirror — this was the tenth-session verify finding's root cause, fixed this session. |

**35 canonical + 8 named legacy = 43 files.** (Was 30 + 8 = 38 before the
2026-07-25 drift correction above.) The "38/38" figure in the tenth session's
handoff was never enumerated; treat these tables as the enumeration, not as
confirmation of that session's working.

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

## 4. Running the DB-backed files without the production dump

`tests/golden/setup_scratch_db.sql` creates an EMPTY database and expects the
caller to load `imsbdcmsbharatda_Ims_Production.sql` into it. That dump is
production data and is deliberately not in the repo, so on a clean checkout every
DB-backed file failed with "Base table or view not found" — indistinguishable, in
a sweep summary, from a real regression.

`tests/golden/base_schema.sql` (added 2026-07-25) closes that gap. It creates
`server_configurations` + the ten `{type}inventory` tables, every column traced to
a code reference or a seeder (provenance is in its header — nothing invented).
Recipe, in order — `base_schema.sql` must precede the consolidated migration
schema, because `config_components` has a FOREIGN KEY onto
`server_configurations.config_uuid`:

```bash
mysql -u root < ims-ftp/tests/golden/setup_scratch_db.sql
mysql -u root ims_compat_golden < ims-ftp/tests/golden/base_schema.sql
mysql -u root ims_compat_golden < ims-ftp/database/consolidated/2026_07_13_000_consolidated-migration-schema.sql
```

This is enough for every file that brings its own fixture rows. It is **not**
enough for `tests/characterize_compatibility.php`: the golden master
characterises the engine against REAL production configurations, and an empty
schema yields an empty baseline, which proves nothing. Load the real dump for
that.

### The `ims-data/` catalog is a hard prerequisite, not an optional extra

Everything that resolves a hardware spec fails without the sibling `ims-data/`
directory (or `IMS_DATA_PATH`), with
`ComponentSpecPaths.php:36 — "Unable to resolve component JSON path"`. Measured
2026-07-25 on a schema-complete scratch DB with no `ims-data/` present:

| File(s) | Outcome without `ims-data/` |
|---|---|
| all 8 `tests/unit/rules/*` | fatal (7 on spec resolution; `dependency_rule_test` exits `FATAL: no real Riser Card fixture found in ims-data`) |
| `lane_authority_unit.php`, `storage_bay_authority_unit.php` | fatal on spec resolution |
| `regression/fail_closed_test.php` | fails — `scandir(ims-data)` |
| `unit/engine_shadow_test.php` | 1 failure — every spec-dependent rule throws, so the engine fails closed (correctly) and `engine.blocked` is `true` where the test expects `false` |
| `serverstate_equivalence.php` | reports OK **across 0 configs** — vacuous, not a pass |
| `characterize_compatibility.php` | cannot produce a meaningful baseline |

A sweep run in an `ims-data`-less environment must say so and name these files,
rather than reporting them as regressions. Confirm any suspected regression by
re-running the same file at the previous commit on the same database before
attributing it to a change.

## Reporting convention going forward

A sweep result must read: **"30/30 canonical + 8/8 legacy = 38/38"** (or the
actual pass counts if not full green), with any excluded/self-skipped file
named explicitly. Do not report a bare "N/M" without stating which list M
refers to — this file is that list.
