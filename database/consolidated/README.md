# Consolidated seeders — the ONLY files to run for the 2026-07-13 pending DB changes

Run these **three** files, in filename order. They replace twelve individual
seeders in `../seeders/`. Do not run the originals.

## Run order

0. `2026_07_13_000_consolidated-migration-schema.sql` — **RUN FIRST**
   - creates all migration tables missing from production (`config_components`,
     `config_resources`, `config_events`, `config_status_transitions`,
     `inventory_status_transitions`, `migration_backfill_state`,
     `backfill_quarantine`) and adds the `revision` / `status_v2` columns
   - consolidates the six schema seeders (2026_07_06_001..003, 2026_07_08_001,
     2026_07_10_001..002) that were only ever applied to the scratch DB —
     discovered when 006 failed on production with
     "#1146 config_status_transitions doesn't exist"
1. `2026_07_13_005_consolidated-server-command-permissions.sql`
   - creates `server.replace`, `server.transition`, `server.finalize` permissions
   - grants them to the roles holding `server.edit` / `server.create` (admins)
   - revokes viewer's `server.edit` and blocks viewer from `server.finalize` (TRIM decision)
2. `2026_07_13_006_consolidated-finalize-transition-edges.sql`
   - adds `draft→finalized` and `building→finalized` rows to `config_status_transitions`
     (Finding 2 fix — required before `COMMAND_LAYER_ENABLED=enforce`)

## How to run

```bash
mysql -u <db_user> -p <db_name> < 2026_07_13_000_consolidated-migration-schema.sql
mysql -u <db_user> -p <db_name> < 2026_07_13_005_consolidated-server-command-permissions.sql
mysql -u <db_user> -p <db_name> < 2026_07_13_006_consolidated-finalize-transition-edges.sql
```

or via phpMyAdmin → Import (000 first, then 005, then 006).

Both are idempotent — safe to re-run if interrupted. Verification queries and
manual rollback SQL are in each file's footer comment.

## Superseded originals (do NOT run — content is fully covered above)

- `../seeders/2026_07_12_001_add-server-replace-transition-permissions.sql`
- `../seeders/2026_07_13_001_add-finding2-finalize-edges.sql`
- `../seeders/2026_07_13_002_add-server-finalize-permission.sql`
- `../seeders/2026_07_13_003_trim-viewer-server-edit-and-finalize-grants.sql`
- `../seeders/2026_07_13_004_correct-002-expected-verification-comment.sql` (doc-only no-op)
- `../seeders/2026_07_12_002_repair-bbdd2549-ddr4-to-ddr5-ram.sql` (superseded earlier —
  targets a config that doesn't exist in production)

## Also covered by 000 (schema seeders — applied to scratch DB only, NOT production)

- `../seeders/2026_07_06_001_create-config-components.sql`
- `../seeders/2026_07_06_002_create-config-resources.sql`
- `../seeders/2026_07_06_003_create-config-events-and-revision.sql`
- `../seeders/2026_07_08_001_create-backfill-tables.sql`
- `../seeders/2026_07_10_001_add-status-v2-columns.sql`
- `../seeders/2026_07_10_002_create-status-transitions.sql`

Everything from 2026_06_* and earlier in `../seeders/` predates the migration
and is already applied to production.
