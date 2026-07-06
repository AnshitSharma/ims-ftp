# U-V.4 — parity_report.php
Concept: diff evidence for every P4/P5 gate. Pins baseline: no. Invariants: INV-11.

## Inputs
11-verification §parity_report; parity-report-template.md; ShadowRunner JSONL shape (U-V.3).

## Files Created (1) / Modified (1: run_all registry)
scripts/verify/parity_report.php — consume shadow JSONL window; canonicalize both sides to
{blocked, error_class}; classify diffs expected (matched against a checked-in
scripts/verify/expected_diffs.json: rule_id → audit finding → matcher) vs unexplained; emit template
fields as JSON + exit per contract. --self-test: synthetic JSONL with one unexplained diff ⇒ exit 1.

## Tests
self-test exit 1; empty-window exit 0 with 'operations compared: 0' WARNING line (a zero-sample
green must be visually loud); run_all --gate P4 includes it.

## Rollback / Checklist
Delete+unregister. - [ ] Message-text differences never count as diffs - [ ] expected_diffs.json requires audit-finding id per entry
