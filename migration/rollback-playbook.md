# ROLLBACK PLAYBOOK
General principle: every layer rolls back by FLAG first, CODE second, SCHEMA last.
Flags are instant and safe; schema rollbacks only when the layer above is already off.

## R-FLAG (minutes) — first response to any incident
Set the offending flag to its previous value (see 00-overview/FLAGS.md progression) in the env,
restart PHP-FPM. Order of safety: READ_FROM_ROWS → COMMAND_LAYER_ENABLED → ENGINE_MODE →
STATE_MACHINE_ENABLED → DUAL_WRITE_ENABLED. Turning DUAL_WRITE_ENABLED off pauses row freshness:
you MUST re-run the backfill (scripts/backfill/backfill.php --resume) before re-enabling anything above it.

## R-UNIT (single unit revert)
1. `git revert <unit commit(s)>` (units are 1..N commits, all prefixed `[UNIT-ID]`).
2. If the unit shipped a seeder: run `database/seeders/rollback/<name>_rollback.sql`
   ONLY IF no later seeder depends on the table (check migration-checklist order).
3. Re-run: `php scripts/verify/run_all.php --quick` + the unit's acceptance tests on the PARENT commit.
4. Set unit ⇒ `not_started` in phase-status.json; write a handoff explaining why.

## R-PHASE
Revert units in reverse order using R-UNIT. Phase gates auto-close (set gate: closed).

## R-SCHEMA specifics
- config_components / config_resources / config_events: rollback files DROP the tables. Allowed only
  while DUAL_WRITE_ENABLED=off and READ_FROM_ROWS=off. Data loss is acceptable pre-P8 because JSON
  remains authoritative until P8; post-P8 schema rollback is FORBIDDEN — roll forward instead.
- status_v2 columns: rollback drops columns; legacy int status was dual-written the whole time, so
  no data loss at any point before U-D.4.
- U-D.3 (JSON column drop) is the point of no return. Its pack requires a verified logical backup
  of server_configurations taken the same day, retained 90 days. Rollback after U-D.3 = restore
  columns from backup + replay config_events since backup timestamp (procedure in the U-D.3 pack).

## Incident classification
| Symptom | Action |
|---|---|
| New-path exception in shadow | No user impact. File bug, keep shadow on, fix in a unit. |
| Verdict divergence in enforce | R-FLAG the owning flag; capture parity report; treat as blocked unit. |
| Equivalence diffs | Freeze phase progression (INV-8). Diagnose with equivalence_report.php --config <uuid>. |
| Perf regression >20% p95 | R-FLAG COMMAND_LAYER/READ_FROM_ROWS; attach performance report to handoff. |

## R-MIXED — reverting migration work out of commit `2c8ab2f`

R-UNIT step 1 assumes one commit per unit. **`2c8ab2f "Update"` breaks that
assumption** and is the only commit that does: it carries the 2026-08-22
migration fixes together with unrelated temporary-access / rack-placement work.

It is on `origin/main`, so it is NOT to be split. Rewriting published history on
the default branch would force-push over a merged PR and break every clone, to
buy a property a path-scoped revert already gives. Use the map below instead:

```
git revert -n 2c8ab2f -- <paths>     # stage the inverse of these paths only
git commit -m "Revert migration portion of 2c8ab2f"
```

**Migration-owned, whole file:**
```
api/permission_map.php                        # debug-deadcode line ONLY (see below)
scripts/.htaccess
scripts/verify/deadcode_manifest.json
scripts/verify/deadcode_report.php
scripts/verify/deadcode_scan.php
scripts/verify/run_all.php
migration/phase-status.json
database/seeders/2026_08_22_001_normalize-sfp-slot-ref.sql
```

**Migration-owned, specific hunks (the rest of the file is NOT migration):**

| File | Migration hunks | Not migration |
|---|---|---|
| `core/models/server/ServerBuilder.php` | `@@ -1117` SFP `slot_ref` fix · `@@ -5244` ENGINE_MODE dispatch + comment block | — |
| `api/handlers/server/server_api.php` | `@@ -153` debug-deadcode dispatch · `@@ -1610` `handleImportVirtual` command-layer fix · `@@ -2088` `handleDebugDeadcode` | `@@ -1486` rack placement in `handleListConfigurations` |
| `api/permission_map.php` | `+ 'debug-deadcode' => 'server.view'` | `+ 'placement' => 'rack.view'` |

**Not migration at all** (leave untouched in any migration rollback):
`api/api.php`, `api/handlers/pipelines/pipeline-servers.php`,
`api/handlers/rack/rack_api.php`, `core/models/pipelines/PipelineManager.php`,
`core/models/tickets/TicketValidator.php`, and seeders
`2026_08_22_002` / `_003` / `_004` with their rollbacks.

Every migration commit after 2026-08-23 is single-purpose again; R-UNIT applies
to those normally.
