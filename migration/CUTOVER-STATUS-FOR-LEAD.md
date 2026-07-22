# IMS validation-engine migration — status for review

Date: 2026-07-22. One-page honest status.

## Bottom line

The **engineering is essentially complete** — all build phases (P0–P7) are
implemented and verified in isolation. What remains is not more code; it is a
**controlled, safety-gated turn-on sequence** in production plus its cleanup. That
sequence is bounded and well-documented, but part of it is measured in calendar days
by design, and one owner-only action that starts the longest clock **has not been
performed yet.** The project is close, not stalled.

## What is done (verified)

| Phase | Scope | State |
|---|---|---|
| P0–P1 | Foundation + schema (`config_components/_resources/_events`, `status_v2`) | gate **open** |
| PL | Resource ledger dual-writer | units verified |
| P2 | Component backfill + dual-write | units verified; gate holds on one re-run (below) |
| P3 | State machines (`StateGuard`) | verified |
| P4–P5 | Validation engine + rule migration | verified |
| P6 | Command layer (add/remove/replace/transition) | verified (1 unit blocked, below) |
| P7 | API adapters | verified |

Dual-write has been firing correctly in production since ~2026-07-19 (confirmed with
real traffic and event rows). The engine has full test coverage; the fleet is fully
backfilled with zero unexplained quarantines.

## What remains — and why some of it takes wall-clock time

**P8 cutover, P9 cleanup, P10 post-cutover are not started.** They gate on soaks:

- **The 7-day `STATE_MACHINE` shadow soak has never started** — the production flag
  still reads `off`. This is the single biggest lever: every day it is not flipped is a
  wasted day on the longest clock. **Flipping it to `shadow` is the top-priority action.**
- `COMMAND_LAYER` enforce soak is **sequential after** that (cannot run in parallel).
- P8 read cutover wants 14 days of green checks at `READ_FROM_ROWS=on`.
- Migration's own floor estimate: **~3 weeks assuming zero findings** (full-soak path).

The soaks are the migration's safety mechanism, not padding: `shadow` mode is the only
place the new engine runs against real production traffic while the legacy engine stays
authoritative. This project's own history is a list of real bugs caught **only** because
of that discipline (a delete-config FK break, a stale-inventory leak, a config's
motherboard silently unassigned). Skipping straight to `enforce` makes the new engine
authoritative over live hardware-inventory records with no evidence it agrees with the
old one — the failure mode is *silent* data corruption, which a backup does not undo
once real work has landed on top of it.

## Faster path, if the deadline requires accepting risk

A **compressed-soak** variant is documented in
`migration/09-cutover/CUTOVER-RUNBOOK.md`: for each flag, flip to `shadow`, replay/run
traffic, run the full report battery, and promote to `enforce` the same session **if
green** — replacing soak-over-time with verify-at-the-moment (minutes, not days). The
risk accepted is the loss of a week of diverse real traffic. Primary rollback at every
step is `FLAG=off` (instant, lossless); the backup is the last resort. This is a
**risk decision for the project owner to sign off on**, explicitly — the runbook does
not skip any verification step.

## Open items before `enforce` (small, tracked)

- **F-5 (parent_id divergence): code side now closed on the working branch.** The
  backfill (`Extractor.php`) parented component NICs to NULL while the live path and
  seeder `2026_07_21_002` parented them to the motherboard — so cascade-removal behavior
  depended on whether a row was backfilled or freshly written. Fixed so both paths agree.
  **Owner action:** confirm seeder `2026_07_21_002` is applied to production.
- **P2 gate re-run:** run `run_all.php --gate P2` against a production dump taken *after*
  seeder `2026_07_22_004`. (The dump reviewed on 2026-07-22 predates dual-write and has
  no `config_events`/`config_components` rows — not the right dump for this check.)
- **U-C.6** remains blocked (enforce-soak dependent).

## Owner-only actions (cannot be done from the code environment)

1. Flip `STATE_MACHINE_ENABLED=shadow` in production `.env` (VS Code SFTP save). **Do this first.**
2. Confirm seeders `2026_07_21_002` and `2026_07_22_004` are applied.
3. Export a fresh post-seeder production dump for the P2 gate re-run.
4. Run the report battery against production at each gate (needs live DB access).

## Recommendation

The project is worth finishing — the hard part is built and proven. Pick the risk
posture (full soak vs compressed), then **start the state-machine shadow clock today**.
Either way the remaining work is a known checklist, not open-ended development.
