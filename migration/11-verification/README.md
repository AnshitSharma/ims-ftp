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
Replays `tests/fixture_scenarios_real.php` scenarios against scratch DB, records wall-time p50/p95
per operation, compares to `reports/perf-baseline.json` (captured at U-0.4). Green iff p95 delta
≤ +20% (threshold overridable only in the pack that changes it).

### equivalence_report.php (created U-1.6) — INV-8 owner
For each config (or --config <uuid>): extract components via legacy
`ServerBuilder::extractComponentsFromJson()` AND via row reads; canonicalize both to
`[type, spec_uuid, serial, slot_ref]` sorted tuples; diff. Green iff zero diffs fleet-wide.
--all iterates with keyset pagination (1000/batch) so it runs on large fleets.

### deadcode_report.php (created U-D.1)
For each symbol scheduled for deletion: `grep -rn` zero call sites outside tests + the symbol's
own file; PHP lint of full tree after deletion; characterization suite green.

## run_all.php contract
`php scripts/verify/run_all.php [--quick] [--gate P<N>]`
--quick: schema+inventory+orphan+equivalence only. --gate: exactly the reports listed for that
gate in phase-status.json. Exit 0 iff all selected reports green. Prints one line per report:
`<name>: GREEN|RED reports/<file>.json`.
