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
