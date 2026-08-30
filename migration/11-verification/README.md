# 11 — Verification (continuous; used by every phase gate)

Objective: seven deterministic reports, each a PHP CLI script in `scripts/verify/`, each exiting
0 (green) or 1 (red) and writing JSON to `reports/`. run_all.php orchestrates them.
Prerequisites: U-0.4 creates the harness; individual reports are created in the unit that creates
what they verify (listed below). Affected DB tables: read-only over everything.
Migration order: harness first (U-0.4); each report lands with its subject.
Rollback: scripts are additive; delete file. Risks: report bugs masking real defects — every report
ships a self-test fixture that must FAIL (proving the report can detect its defect class).
Duration: bundled inside owning units.

## Report specifications

### 1. schema_report.php (created U-1.1, extended U-1.2/U-1.3/U-SM.1)
Green iff: all expected tables/columns/indexes/uniques/FKs exist with expected definitions
(information_schema comparison against a checked-in `scripts/verify/expected_schema.json`);
collations of all new uuid columns equal `server_configurations.config_uuid`'s collation
(bug class already seen: seeder 2026_06_17_002).

### 2. inventory_report.php (created U-0.4, extended U-SM.3)
Green iff, per inventory table: no row with Status=2/installed and NULL ServerUUID; no row
referenced by any config while Status=1/available; after P3: legacy int status and status_v2
agree per the mapping table; no illegal status_v2 value.

### 3. orphan_report.php (created U-0.4 — wraps existing scripts/audit-orphans.php)
Green iff audit-orphans.php dry-run exit 0 AND (post-P1) every config_components row's
inventory FK target exists with non-retired status.

### 4. slot_report.php (created U-L.3)
Green iff: no duplicate (config_uuid, slot_ref) [DB enforces; report double-checks];
every consumer's slot_ref exists as a provider ledger row; no card row with NULL slot_ref
of types nic/pciecard/hbacard after P2 (the "slotless card" class, audit A-8).

### 5. ledger_report.php (created U-L.3)
Green iff per config: Σ consumed ≤ Σ capacity per resource; no consumer row without living consumer
component; no provider rows from components not in config_components; lane totals match the
single lane model recomputation.

### 6. parity_report.php (created U-V.4)
Compares shadow-engine verdicts vs legacy verdicts from the shadow log
(`reports/shadow/*.jsonl`). Output = parity-report-template.md fields as JSON.
Green iff unexplained diffs = 0 AND engine exceptions = 0.

Supports an opt-in `--since YYYY-MM-DD` flag that drops shadow-log rows dated before the cutoff
(the log is append-only and never rotated, so stale pre-fix rows would otherwise trip the gate
forever). **Owner-adopted (2026-07-13): `run_all.php` always invokes this report with
`--since PARITY_SINCE_DEFAULT`** (a constant in `run_all.php`, overridable via the
`PARITY_SINCE_CUTOFF` env var) — this is the gate's standing invocation from here on. Running
`parity_report.php` directly with no arguments is unaffected: it still scans every row, unfiltered,
exactly as before this flag existed. `scripts/verify/prune_shadow_log.php` (dry-run by default,
`--execute` to actually rewrite the log files) is the alternative/complementary fix — pruning
removes the need for `--since` going forward but is a one-way file rewrite, so it stays owner-run
only.

### 7. performance_report.php (created U-0.4)
Replays the R1-R10 real-component scenarios against a scratch DB, records wall-time p50/p95 per
operation, compares to `reports/perf-baseline.json`. Green iff p95 delta ≤ +20% (threshold
overridable only in the pack that changes it).

It no longer replays `tests/fixture_scenarios_real.php`, which is disabled — it carries its own
copy of the scenarios. Two corrections landed 2026-08-30 with U-D.3c: the starting state is
built as inventory units plus `config_components` rows rather than JSON columns, and the ADD
path now hands `TargetStateBuilder::withAdd()` a row-shaped candidate. It had been passing the
API's key names (`component_uuid` / `parent_uuid` / `port_index`), which `withAdd()` merges over
a null template — so `spec_uuid` stayed null and every add threw inside `pcie.lane_budget`
instead of being timed.

**`reports/perf-baseline.json` is from 2026-07-06 and is not comparable**: it timed the three
legacy validate* methods P9 has since deleted. The report runs clean (0 errors, 10/10 scenarios)
and reads RED purely against that stale baseline. Re-bless before trusting a verdict.

### equivalence_report.php (created U-1.6) — INV-8 owner — **DELETED 2026-08-30 (U-D.3c)**
It compared each config's components as extracted from the legacy JSON columns against the same
config's `config_components` rows, canonicalized to `[type, spec_uuid, serial, slot_ref]` sorted
tuples, and was green iff there were zero diffs fleet-wide.

Both the report and INV-8 are closed: U-D.3c dropped the nine JSON columns, so there is no
second store for a dual-write window to fork from. The invariant now holds structurally, which
is stronger than a nightly run — a run can be skipped, a column that does not exist cannot
diverge.

The rows-vs-inventory half of its job (the pack's "retire JSON side → becomes rows-vs-inventory
consistency check") lives on in `inventory_report.php`'s Check 2, in the file that already owned
that question. It was repointed at `config_components` and mutation-probed BEFORE this file was
deleted, and it is now exact rather than a sampling heuristic: every reference resolves through
`config_components.inventory_id` to one physical unit.

### deadcode_report.php (created U-D.1)
For each symbol scheduled for deletion: `grep -rn` zero call sites outside tests + the symbol's
own file; PHP lint of full tree after deletion; characterization suite green.

## run_all.php contract
`php scripts/verify/run_all.php [--quick] [--gate P<N>]`
--quick: schema+inventory+orphan only (`equivalence` left the set 2026-08-30 with U-D.3c). --gate: exactly the reports listed for that
gate in phase-status.json. Exit 0 iff all selected reports green. Prints one line per report:
`<name>: GREEN|RED reports/<file>.json`.
