# Test manifest — the migration sweep, enumerated

Written 2026-07-13 (eleventh session) in response to the tenth-session verify
finding: "38/38" was reported without an enumerated file list and could not be
reconciled. This file is now the canonical list every future session's sweep
counts must be reported against. If the list below and a session's actual
`find`/`Glob` output disagree, update this file in the same session and say so
in the handoff — don't let the two silently drift.

None of `tests/` is deployed (SFTP ignore list, per `ims-ftp/CLAUDE.md`) — this
manifest only matters for local/scratch runs.

## 1. Canonical suite — 30 `*_test.php` files, five directories

These are the files `scripts/verify/run_all.php`'s `regression`/`baseline`
registry entries implicitly point at, and what every "N/30" sweep count in
prior handoffs refers to.

| Directory | Count | Files |
|---|---|---|
| `tests/unit/` | 8 | `base_command_test.php`, `config_component_repository_test.php`, `engine_shadow_test.php`, `onboard_nic_engine_test.php`, `resource_catalog_test.php`, `target_state_test.php`, `verdict_shim_test.php`, `verdict_test.php` |
| `tests/unit/rules/` | 8 | `cpu_rules_test.php`, `dependency_rule_test.php`, `lane_rule_test.php`, `memory_rules_test.php`, `net_rules_test.php`, `slot_rules_test.php`, `storage_rules_test.php`, `system_rules_test.php` |
| `tests/regression/` | 10 | `add_command_test.php`, `dual_write_test.php`, `fail_closed_test.php`, `finalize_command_test.php`, `finalized_immutability_test.php`, `ledger_dual_write_test.php`, `nested_transaction_test.php`, `remove_command_test.php`, `replace_command_test.php`, `state_guard_test.php` |
| `tests/api/` | 2 | `add_remove_response_shape_test.php`, `new_actions_test.php` |
| `tests/backfill/` | 2 | `extractor_test.php`, `ledger_backfill_test.php` |
| **Total** | **30** | |

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

**30 canonical + 8 named legacy = 38 files.** This is very likely what the
tenth session's uncorroborated "38/38" figure meant, though that session did
not enumerate it — treat this table as the retroactive enumeration, not
confirmation of the session's own working.

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

A sweep result must read: **"30/30 canonical + 8/8 legacy = 38/38"** (or the
actual pass counts if not full green), with any excluded/self-skipped file
named explicitly. Do not report a bare "N/M" without stating which list M
refers to — this file is that list.
