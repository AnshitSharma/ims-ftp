# U-B.1 — Backfill skeleton (state, dry-run, resume, quarantine)
Concept: safe bulk migration machinery. Pins baseline: no. Invariants: INV-9, INV-11.

## Inputs (Files To Read)
- scripts/audit-orphans.php (bootstrap/PDO/arg pattern to copy, full file 1–~200)
- core/models/config/ConfigComponentRepository.php
- 07-component-migration/README.md

## Database Changes (1 seeder + rollback)
2026_07_08_001_create-backfill-tables.sql:
migration_backfill_state(run_id, config_uuid PK-pair, status ENUM(pending,done,quarantined,error),
attempts, last_error TEXT, updated_at) + backfill_quarantine(id, run_id, config_uuid, component_json JSON,
reason VARCHAR(191), created_at). Rollback drops both.

## Files Created (2)
scripts/backfill/backfill.php — CLI: --dry-run (default; writes NOTHING except a plan JSON to
reports/), --execute, --resume (continues pending/error), --config <uuid>, --run-id <id>,
--rollback-run <id> (deletes rows whose config_events payload run_id matches, restores state rows).
Loop: keyset-paginate configs WHERE is_virtual = 0 (virtual/test configs are sandbox data: excluded from backfill, equivalence, and all row-side machinery — assumption A-3 in PLAN_VERIFICATION_REVIEW); per config: BEGIN; SELECT ... FOR UPDATE the config row (same lock
discipline as mutations — prevents racing a live dual-write); call Extractor (U-B.2 — this unit
ships a stub that quarantines EVERYTHING with reason 'extractor-not-implemented'); write rows via
repository with event('backfill', payload run_id); mark state done; COMMIT. Idempotence: skip
configs already 'done' for this run; re-running a done config verifies rows exist and match
(via equivalence --config) instead of inserting.
scripts/backfill/README.md — operator runbook (dry-run → review plan → execute → resume semantics → rollback-run).

## Tests
```
php scripts/backfill/backfill.php --dry-run --config <fixture>     # plan JSON written, DB untouched (checksum server_configurations before/after)
php scripts/backfill/backfill.php --execute --config <fixture>     # all quarantined (stub), state rows written
php scripts/backfill/backfill.php --rollback-run <id>              # quarantine + state cleared, zero config rows remain
```

## Rollback / Checklist
Seeder rollback + git revert. - [ ] Dry-run provably write-free - [ ] Per-config transaction + row lock - [ ] rollback-run leaves equivalence able to pass (rows gone)
