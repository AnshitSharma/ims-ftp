# U-L.3 — slot_report.php + ledger_report.php
Concept: ledger observability. Pins baseline: no. Invariants: INV-11.

## Inputs
11-verification/README.md §4 §5 (contracts); U-1.2 semantics; PcieLaneBudgetValidator.php 66–140
(the lane math to recompute against — read-only reference).

## Files Created (2) / Modified (1: run_all registry ×2 flips)
scripts/verify/slot_report.php, scripts/verify/ledger_report.php exactly per contract; each with
--self-test seeded-defect mode (duplicate slot_ref via raw SQL bypassing uniques? uniques block it —
self-test instead seeds a consumer link to a missing provider and an over-consumed scalar).

## Tests
Both reports GREEN on dual-written scratch fixtures; both self-tests exit 1; run_all --gate PL exit 0.

## Rollback / Checklist
Delete + unregister. - [ ] Lane recomputation matches PcieLaneBudgetValidator on fixtures (documents future single-model target U-R.4)
