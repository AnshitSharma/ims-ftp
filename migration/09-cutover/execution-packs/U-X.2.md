# U-X.2 — Cutover runbook execution
Concept: P8 gate. Pins baseline: n/a (operational). Invariants: INV-8.

## Inputs
rollback-playbook.md; 00-overview/FLAGS.md progression; this folder's README.

## Files Created (1)
reports/cutover-signoff-<date>.md from embedded template: flag timeline (each step dated),
sample-mode divergence count (must be 0 over ≥72h), the seven reports' results at =on days 1/7/14,
perf p95 table, human sign-off.

## Procedure
1. READ_FROM_ROWS=sample, ≥72h, divergence log must stay empty (any row ⇒ fix unit, restart clock).
2. =on. Install the daily battery cron: `scripts/verify/run_all.php --quick` + weekly `--all` equivalence, archiving to reports/ (this cron is the 30-day evidence U-D.3 requires — F-4).
3. Day 14 all green ⇒ open P8 gate, fill signoff.

## Completion / Rollback / Checklist
Signoff committed; gate open. Rollback: =sample. - [ ] 72h zero-divergence evidenced - [ ] 14-day battery archived
