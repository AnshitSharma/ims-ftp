# IMS validation-engine migration — status for review

Date: 2026-07-25 (supersedes the 2026-07-22 revision). One-page honest status.

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

**P8 cutover, P9 cleanup, P10 post-cutover are not started.**

**Correction to the 2026-07-22 revision: the shadow soak HAS started.** Live probe
2026-07-25T10:06Z (`server-debug-migration-flags`) reads `STATE_MACHINE_ENABLED=shadow`,
`ENGINE_MODE=shadow`, `COMMAND_LAYER_ENABLED=shadow`, `DUAL_WRITE=on`,
`READ_FROM_ROWS=sample`, `.env` mtime `2026-07-22T15:40Z` and holding. The previous
revision's "still reads `off`, has never started" is stale. Probe before repeating it.

**The calendar clock is not the real constraint here — traffic volume is.** The soak has
accumulated 69 engine rows total, *all* from a single 70-minute burst on 07-22 23:38 →
07-23 00:24, and 34 of those 69 are byte-identical duplicate records (finding F-8). On a
fleet this idle, waiting out the remaining days of a 7-day soak buys very little
additional evidence. The compressed-soak variant is the sensible posture here, and the
honest reason is *this*, not schedule pressure.

What the soak did buy was real: it surfaced 9 genuine divergences, 8 of which trace to
one rule being harsher than the legacy code that actually runs (see below). That is the
argument for the discipline — not the number of days. Skipping verification entirely
would still make the new engine authoritative over live inventory with no evidence it
agrees with the old one, and the failure mode is *silent* wrong data, which a backup does
not undo once real work has landed on top of it. Downtime tolerance does not help with
that failure mode; rollback is already instant and lossless (`FLAG=off`).

## Faster path, if the deadline requires accepting risk

A **compressed-soak** variant is documented in
`migration/09-cutover/CUTOVER-RUNBOOK.md`: for each flag, flip to `shadow`, replay/run
traffic, run the full report battery, and promote to `enforce` the same session **if
green** — replacing soak-over-time with verify-at-the-moment (minutes, not days). The
risk accepted is the loss of a week of diverse real traffic. Primary rollback at every
step is `FLAG=off` (instant, lossless); the backup is the last resort. This is a
**risk decision for the project owner to sign off on**, explicitly — the runbook does
not skip any verification step.

## Shadow-parity findings (2026-07-25)

First real parity run scored **RED, 9 unexplained**. Root cause found for 8 of 9:

- **7 rows — `storage.bay_capacity` was harsher than legacy.** The rule implemented
  "strict 2.5/3.5, no adapter" on the authority of a *comment*
  (`ComponentCompatibility.php:3193-3200`), but the legacy code that actually executes —
  `ComponentValidator::validateChassisBayStorage():1024-1029` — accepts a 2.5" drive in a
  3.5" bay via a caddy and treats it as a warning. Legacy's count/overflow branch is
  documented dead code, so legacy never blocks on capacity either. **Flipping `enforce`
  would have started rejecting 2.5" drives into 3.5" chassis that succeed today.** Fixed:
  2.5" now falls back to 3.5" bays, blocking only when no eligible bay exists; overflow is
  reported non-blocking for the post-cutover tightening pass.
- **1 row** — engine allowed a 2.5"-into-no-bays add that legacy blocked.
  `storage.caddy_pairing` is `VALIDATION_FAILURE`, which by design does not block at ADD
  ([Verdict.php:44-48]) and re-blocks at finalize. Expected to resolve with the above.
- **1 row still unexplained** (`a84cc492`, 07-23 00:19:42): legacy blocked
  "Incompatible with existing components", engine passed all 16 rules. **Undiagnosable**
  — `ShadowRunner::record()` never logged *which component* the operation was about. Fixed
  going forward (optional `subject` field); needs fresh traffic to identify.

**F-8, affects gate validity:** the engine shadow log contains adjacent byte-identical
duplicate rows (39 lines → 18 distinct; 30 → 17). `parity_report.php` counts them as
separate operations, so `operations_compared` is inflated ~2x. Not yet traced.

**COMMAND_LAYER's shadow soak is entirely unverified** — `command-*.jsonl` has no gating
report consuming it at all, despite being the log that *does* record component type.

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

1. ~~Flip `STATE_MACHINE_ENABLED=shadow`~~ — **done 2026-07-22, verified holding.**
2. **Pull fresh `reports/shadow/engine-*.jsonl` + `command-*.jsonl` from production**
   *after* the 2026-07-25 rule fix deploys. This is now the top-priority action: without
   traffic that exercises the corrected rule, parity cannot go green. Do not rename the
   files on download — `(1)`-suffixed duplicates get double-counted on a default run.
3. Confirm seeders `2026_07_21_002` and `2026_07_22_004` are applied.
4. Export a fresh post-seeder production dump for the P2 gate re-run.
5. Run the report battery against production at each gate (needs live DB access).

## Recommendation

The project is worth finishing — the hard part is built and proven, and the shadow clock
is already running. Take the **compressed-soak** path: on this fleet's traffic volume the
extra calendar days add little evidence, so the honest trade is small.

The gating work is no longer waiting — it is: get fresh shadow logs after the 07-25 rule
fix, confirm the 7 bay-capacity rows go green, identify the last unexplained row, and
build the missing command-layer parity report. That is a known checklist measured in
sessions, not weeks.
