# U-X.2 — Cutover runbook execution
Concept: P8 gate. Pins baseline: n/a (operational). Invariants: INV-8.

## Inputs
rollback-playbook.md; 00-overview/FLAGS.md progression; this folder's README.

## Files Created (1)
reports/cutover-signoff-<date>.md from embedded template: flag timeline (each step dated),
sample-mode divergence count AND comparison count (0 divergences over ≥72h, comparisons > 0),
the seven reports' results at =on days 1/7/14, perf p95 table, human sign-off.

## Procedure
1. READ_FROM_ROWS=sample, ≥72h, `php scripts/verify/read_report.php` GREEN
   (any divergence ⇒ fix unit, restart clock).

   **AMENDED 2026-07-29 (F-27).** This item used to read "divergence log must stay
   empty". A router that never executes satisfies that perfectly, and for the whole
   soak to date that is exactly what happened: `ConfigReadRouter` logged divergences
   ONLY, so "every read agreed" and "no read ever reached the router" produced the
   identical artifact — an absent file. The criterion could not fail. Same shape as
   F-10 (reports exiting 0 having run nothing) and F-8/F-23 (a ratio with no
   denominator).

   The router now records every outcome with a `kind`, and `read_report.php` is
   GREEN only when all four hold: 0 divergences, 0 unrecognised kinds, **> 0
   production comparisons**, and an observed window ≥ 72h. Emptiness now fails.
   Note also that `sapi=cli` rows are excluded — the production copy of
   `read-20260728.jsonl` held 6 rows that were all local-harness output uploaded by
   SFTP, and under the old wording those alone would have restarted the clock.
2. =on. Install the daily battery cron: `scripts/verify/run_all.php --quick` + weekly `--all` equivalence, archiving to reports/ (this cron is the 30-day evidence U-D.3 requires — F-4).
3. Day 14 all green ⇒ open P8 gate, fill signoff.

## Completion / Rollback / Checklist
Signoff committed; gate open. Rollback: =sample. - [ ] 72h zero-divergence evidenced - [ ] 14-day battery archived
