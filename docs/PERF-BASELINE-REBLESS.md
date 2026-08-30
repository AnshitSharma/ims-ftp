# Performance baseline re-bless procedure

`reports/perf-baseline.json` is what every `scripts/verify/performance_report.php` run (including
the P6/P8 `performance` gate report) is graded against — a run is RED iff any operation's p95
exceeds the baseline's p95 by more than 20%. The checked-in baseline was captured with a single
pass over the R1-R10 scenarios (8 add samples, 2 finalize samples) — small enough that ordinary
machine-to-machine and run-to-run timing variance can produce a false RED with no code regression
behind it (observed live: ninth/tenth-session runs on this scratch machine, +2455%–3499% p95 vs
baseline, characterized as noise, not investigated further — see the ninth-session record in
`migration/handoffs/SESSION-20260712-FINDINGS-ABC.md`).

This file documents how to re-bless it correctly. **This is an owner-run action, not something an
implementer or verify session runs on itself** — a session that both re-blesses the baseline and
then reports the resulting performance run as GREEN would be self-certifying its own comparison
target, exactly the kind of self-certification this migration's own convention forbids.

## What `--rebless` does

```
php scripts/verify/performance_report.php --rebless --confirm
```

- Requires **both** flags. `--rebless` alone refuses (exit 2, prints this file's path, baseline file
  untouched) — this is deliberate friction, not a bug, so the flag can't fire from a copy-pasted
  command someone didn't read.
- Replays the R1-R10 scenario set enough times to reach **≥50 add samples and ≥20 finalize
  samples** (R1-R8 are "add" scenarios, R9-R10 are "finalize" — the script computes the exact pass
  count from those counts, currently 10 passes: 80 add / 20 finalize).
- Overwrites `reports/perf-baseline.json` with the new p50/p95 per operation, tagged
  `RE-BLESSED via --rebless --confirm` in the file's own `note` field, and exits 0.
- `--capture-baseline` (the older, single-pass flag) still exists unchanged — it's for an *initial*
  capture when no baseline exists yet, not for re-blessing an existing one under suspected noise.

## Quiet-machine capture procedure (do this before running `--rebless --confirm`)

Wall-clock timings are sensitive to whatever else the machine is doing. Before capturing a baseline
that every future run will be graded against:

1. **Close or pause anything with its own DB/CPU load** on this machine: no other `php -S` harness
   server running against the same scratch DB, no concurrent test suite run, no IDE re-indexing, no
   large background sync (OneDrive/Dropbox/etc. actively transferring).
2. **Confirm `mysqld` is idle** immediately before starting — no other session's long-running query
   or import in flight (`SHOW PROCESSLIST` on the scratch instance).
3. **Run the replay twice, throwaway, before the real capture** (`php scripts/verify/performance_report.php`
   against the *existing* baseline, twice, a few seconds apart) to let disk cache / DB connection
   warm-up settle — the first run after a cold `mysqld` start is reliably slower than the second and
   third in this environment (observed across multiple prior sessions' XAMPP restarts).
4. **Run `--rebless --confirm` once.** Do not run it repeatedly hoping for a better number — if the
   result still looks like an outlier, investigate *why* (a real regression from recent code, or a
   still-noisy machine) rather than re-rolling until a green number appears.
5. **Review the new `reports/perf-baseline.json` before trusting it**: sample counts should read
   `add: n=80, finalize: n=20` (or whatever the current scenario/threshold math produces — check the
   script's own `REBLESS_MIN_ADD_SAMPLES` / `REBLESS_MIN_FINALIZE_SAMPLES` constants if this doc goes
   stale), and `errors` should be 0 (any replay error means a scenario broke, not just ran slow —
   fix that before trusting the timings it produced alongside the error).
6. **Commit the new baseline file** (`reports/perf-baseline.json` is checked in, not gitignored) with
   a message noting the machine/date and *why* (first capture vs re-bless after a suspected-noise RED
   vs re-bless after an intentional performance-affecting change).

## Why this isn't run automatically by any gate or session

The whole point of a performance gate is that it compares *today's* numbers against a trusted,
independently-reviewed reference. A script that can silently redefine its own reference the moment
it doesn't like the answer isn't a gate — hence the explicit two-flag requirement, the "owner-run
only" posture, and this document existing instead of the flag just running quietly inside
`run_all.php`.
