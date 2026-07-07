# backfill.php — operator runbook

Migrates existing `server_configurations` JSON components into
`config_components` / `config_resources`. See
`migration/07-component-migration/README.md` for the phase this belongs to
and `migration/07-component-migration/execution-packs/U-B.1.md` for the unit
that built this machinery.

**Current status (U-B.1): the Extractor is a stub.** Every legacy component
entry this script finds is quarantined with reason
`extractor-not-implemented` — nothing is written to `config_components` or
`config_resources` yet. U-B.2 replaces the stub with a real extractor; this
unit only proves the surrounding machinery (state tracking, dry-run, resume,
quarantine, rollback) is safe. Do not run `--execute` against production
expecting real migration until U-B.2 has landed.

## Precondition for a live run
`DUAL_WRITE_ENABLED=on` in production for **at least 24 hours** before any
real (non-stub) backfill execute run, so new mutations self-materialize —
backfill only owns *history*, never a moving target.

## Workflow

1. **Dry-run first, always** (this is also the default — no flag needed):
   ```
   php scripts/backfill/backfill.php
   ```
   Writes a plan to `reports/backfill-plan-<timestamp>.json` listing every
   non-virtual config and how many entries would be quarantined. Makes
   **zero** database writes — no transaction is even opened. Review the plan.

2. **Execute**, once the plan looks right:
   ```
   php scripts/backfill/backfill.php --execute
   ```
   Prints the auto-generated `run-id` — save it. Processes every non-virtual
   config: locks its row, quarantines its legacy entries (or marks it `done`
   if it has none), commits per config. A single config's failure does not
   stop the run — it's marked `error` and the run continues.

3. **Resume** an interrupted or partially-failed run:
   ```
   php scripts/backfill/backfill.php --resume --run-id <id>
   ```
   Re-attempts only configs still `pending`/`error` for that run. Configs
   already `done` are re-verified via `equivalence_report.php --config`
   instead of being reprocessed (idempotent — never re-inserts). Configs
   already `quarantined` are left alone (terminal for the current stub).

4. **Roll back** a run entirely (undo everything it did):
   ```
   php scripts/backfill/backfill.php --rollback-run <id>
   ```
   Deletes any `config_components`/`config_resources` rows the run created
   (matched via `config_events.payload.run_id`; always zero under today's
   stub), those `config_events` rows, its `backfill_quarantine` rows, and its
   `migration_backfill_state` rows. After this, the run-id no longer exists
   anywhere — start over with a fresh `--execute` if needed.

## Single-config mode
Every mode above accepts `--config <uuid>` to restrict the operation to one
config — useful for testing or re-driving a single problem config.

## Inspecting quarantine
```sql
SELECT config_uuid, reason, component_json FROM backfill_quarantine WHERE run_id = '<id>';
SELECT config_uuid, status, attempts, last_error FROM migration_backfill_state WHERE run_id = '<id>';
```

## Exit codes
`0` = clean (dry-run written, or execute/resume with zero `error` configs, or
rollback succeeded). `1` = one or more configs ended in `error` (see
`last_error` in `migration_backfill_state`), or rollback itself failed.
`2` = usage/setup error (bad `--run-id`/`--config`, no DB connection).
