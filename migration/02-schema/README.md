# 02-schema — Phase P1: Schema introduction
Objective: land the three new tables + revision column + repository + dual-write plumbing with
ZERO production behavior change (DUAL_WRITE_ENABLED defaults off; read path untouched).
Prerequisites: P0 gate open.
Affected files: database/seeders/*, core/models/config/* (new), core/models/server/ServerBuilder.php
(dual-write hook points only), scripts/verify/{schema_report,equivalence_report}.php.
Affected DB tables: NEW config_components, config_resources, config_events; ALTER server_configurations
(+revision INT NOT NULL DEFAULT 0).
Migration order: U-1.1 → U-1.2 → U-1.3 → U-1.4 → U-1.5 → U-1.6.
Rollback: R-UNIT; table rollbacks legal while flags off (see rollback-playbook R-SCHEMA).
Verification: schema report green; regression suite ZERO diffs; with flag off, `git grep -n DUAL_WRITE`
appears only in the writer class + FLAGS.md.
Expected risks: collation mismatch on uuid columns (seen before: seeder 2026_06_17_002) — every pack
pins collation by copying from server_configurations.config_uuid; FK failures against quarantined
legacy data are impossible here because no rows are written while flag is off.
Expected duration: 6 sessions.

## Session handoff (phase close)
Next Prompt: execute U-L.1 (06-resource-ledger). Files To Load: overview, invariants,
06-resource-ledger/execution-packs/U-L.1.md. Expected Context Size: ~30k tokens.
