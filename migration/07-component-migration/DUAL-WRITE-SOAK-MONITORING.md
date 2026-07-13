# Dual-write soak monitoring — owner runbook

Date: 2026-07-13. This is a **monitoring plan for the owner**, not a code
change. Nothing here flips `DUAL_WRITE_ENABLED`, runs a seeder, or touches
production `.env` — it only describes what to run, how often, and what a bad
result looks like, during the soak window U-B.4's own pack already requires:

> "Confirm DUAL_WRITE_ENABLED=on ≥24h (grep env + deploy log)."
> — `migration/07-component-migration/execution-packs/U-B.4.md`, checklist item 1

## Why this exists

Before this session, the soak precondition was a single checklist line with
no concrete "how do I know it's actually going okay" procedure. This gives
the owner a repeatable, read-only check to run periodically during the
window (by hand, or on a cron — nothing here assumes either).

## What to run

**Primary — production-safe, run directly:**

```
php scripts/verify/dual_write_soak_monitor.php [--since-hours N]
```

This is a NEW read-only script (`scripts/verify/dual_write_soak_monitor.php`,
this session) that:
1. Prints what `DUAL_WRITE_ENABLED` currently reads as (informational).
2. Runs the same four reports P2's own gate already requires —
   `equivalence_report.php`, `orphan_report.php`, `ledger_report.php`,
   `inventory_report.php` (see `migration/phase-status.json`'s P2
   `gate_reports`) — via the standard `core/config/app.php` bootstrap, i.e.
   against whatever DB the deployed `.env` points at. All four are
   confirmed read-only (no INSERT/UPDATE/DELETE in any of them).
3. Runs one NEW check this script owns directly: compares `config_events`
   rows created in the last `--since-hours` (default 4) against
   `config_components` rows added in the same window. If mutations are
   happening (`config_events > 0`) but zero new `config_components` rows
   landed, dual-write is not actually self-materializing despite the flag
   reading "on" — this is the one failure mode none of the four report
   scripts above would catch on their own, since they only check current
   structural integrity, not recent write activity.

Exit 0 = every check GREEN. Exit 1 = at least one RED — see "Alert
conditions" below for what each one means.

**Secondary — scratch-only, optional, NOT run against production:**

`scripts/verify/fleet_parity_sweep.php` is a scratch-DB replay tool
(`GOLDEN_DB_*` env vars, needs a full `ims-data` mirror on disk, forces
`ENGINE_MODE=shadow` for its own process only) — it was never designed to
run against a live database, production included. If the owner wants an
extra check during the soak, the safe way is: periodically refresh a
scratch mirror from a recent production dump (same procedure this session
used for the ghost-config cleanup — mysqldump backup first, never write
back to production) and re-run `fleet_parity_sweep.php` + re-capture
`tests/characterize_compatibility.php`'s baseline against it, to catch any
NEW compatibility-behavior drift introduced by whatever real mutations
landed during the soak. This is a nice-to-have trend signal, not a gate —
don't block the soak on it.

## Suggested cadence

- Every 2-4 hours during the 24h window: run the primary script.
- Once at the ~24h mark, right before U-B.4's dry-run: run it once more as
  the actual "confirm ≥24h" evidence artifact (save its output alongside the
  `reports/backfill-signoff-<date>.md` U-B.4 already produces).
- Secondary (scratch replay): once, at the end of the window, not on a tight
  cadence — it needs a fresh production dump to be worth re-running.

## Alert conditions — what to do if something's RED

| Check | RED means | What to do |
|---|---|---|
| `equivalence` | rows-path vs JSON-fallback component extraction disagrees for some config | Do NOT proceed to U-B.4's live run. Read the report's diff detail, find the specific config(s), determine if it's a genuine extraction bug or expected drift (same triage posture as `expected_diffs.json` elsewhere in this migration) before continuing the soak. |
| `orphan` | a `config_components` row's `(inventory_table, inventory_id)` doesn't resolve, or an inventory row claims `ServerUUID` with no live component row | Likely a dual-write hook bug (a mutation path not going through `ConfigComponentWriter` correctly) or a pre-existing data issue surfaced for the first time. Investigate before continuing; do not silently let orphans accumulate through the whole soak window. |
| `ledger` | `config_resources` consumption exceeds capacity for some resource, or a ledger row references a tombstoned/missing component | Same posture as `orphan` — a dual-write ledger-hook bug is the most likely cause (see `ConfigComponentWriter`'s ledger hooks, U-L.2). Investigate the specific config before continuing. |
| `inventory` | an inventory row's `status_v2`/legacy `Status` pairing is invalid, or a status transition happened outside `inventory_status_transitions`' legal edges | Could indicate a mutation path bypassing `StateMachine::applyInventoryTransition()`. Investigate; this is a correctness signal, not noise. |
| staleness (`config_events > 0`, `config_components` added `= 0`) | dual-write flag reads "on" but nothing is landing | **Stop the soak clock.** This means the ≥24h precondition isn't actually being satisfied no matter how long you wait — find out why `ConfigComponentWriter` isn't firing (flag read correctly? hook actually wired into the mutation path that's seeing traffic?) before restarting the window. |
| `DUAL_WRITE_ENABLED` reads anything other than `on` | the flag isn't actually set the way you think it is in the environment this script ran in | Re-check the deploy — this script reports what IT sees, which is only meaningful if it ran in the same process context as production traffic. |

## What this does NOT cover

- It does not itself confirm the flag has been "on" continuously for 24h —
  only that it's on RIGHT NOW when the script runs, plus recent write
  activity. The owner (or a deploy-log grep, per U-B.4's own checklist
  wording) still needs to confirm continuity across the window.
- It does not run or suggest running any seeder, migration, or the U-B.4
  backfill itself. That remains a separate, later, human-executed step per
  U-B.4's own pack.
- It does not touch `ENGINE_MODE`, `STATE_MACHINE_ENABLED`,
  `COMMAND_LAYER_ENABLED`, or `READ_FROM_ROWS` — this soak is about
  `DUAL_WRITE_ENABLED` only, per U-B.4's own precondition.
