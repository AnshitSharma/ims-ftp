# Consolidated seeders — the ONLY files to run for the 2026-07-13 pending DB changes

These two files replace six individual seeders in `../seeders/` (marked
SUPERSEDED in their headers). Run **these two**, not the originals.

## Run order

1. `2026_07_13_005_consolidated-server-command-permissions.sql`
   - creates `server.replace`, `server.transition`, `server.finalize` permissions
   - grants them to the roles holding `server.edit` / `server.create` (admins)
   - revokes viewer's `server.edit` and blocks viewer from `server.finalize` (TRIM decision)
2. `2026_07_13_006_consolidated-finalize-transition-edges.sql`
   - adds `draft→finalized` and `building→finalized` rows to `config_status_transitions`
     (Finding 2 fix — required before `COMMAND_LAYER_ENABLED=enforce`)

## How to run

```bash
mysql -u <db_user> -p <db_name> < 2026_07_13_005_consolidated-server-command-permissions.sql
mysql -u <db_user> -p <db_name> < 2026_07_13_006_consolidated-finalize-transition-edges.sql
```

or via phpMyAdmin → Import (005 first, then 006).

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

Everything older than these in `../seeders/` is schema history from the
migration's earlier phases and has already been applied.
