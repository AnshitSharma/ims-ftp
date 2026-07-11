# Handoff — U-L.4 + U-L.5 independent VERIFY pass — 2026-07-12

## Current State
Both units **verified** by an independent session (Claude Fable; implementing session was Sonnet —
SESSION_PROTOCOL's implement/verify split respected). phase-status.json updated: U-L.4/U-L.5 →
`verified`, **PL gate → `open`** (all 5 PL units verified; `run_all.php --gate PL` exit 0:
schema GREEN, ledger GREEN, regression covered by test suites below).

## What was verified (not just re-read — actually executed on the scratch env at C:\tmp\ims-ftp-scratch\)
Scratch copies of both modified files confirmed byte-identical (Get-FileHash) to the main working copy.

- `php -l` on `ResourceCatalog.php` + `backfill.php` — clean.
- `tests/unit/resource_catalog_test.php` — 46 checks ALL PASS (incl. all U-L.4 cpu and U-L.5
  nic/hbacard/pciecard fixtures).
- `tests/backfill/ledger_backfill_test.php` — 25 checks ALL PASS (fixtures A–D, incl. Fixture A's
  re-verified 0-lane plain-card behavior change and Fixture D's hbacard/nic ledger rows).
- `tests/regression/ledger_dual_write_test.php` — 28 checks ALL PASS, including Scenarios F–I: the
  LIVE `ConfigComponentWriter::afterLegacyAdd()` path adds cpu/nic/hbacard/pciecard with no throw and
  correct `config_resources` rows. This was the original DUAL_WRITE blocker; it is closed.
- `tests/regression/dual_write_test.php`, `finalized_immutability_test.php`,
  `nested_transaction_test.php`, `tests/unit/config_component_repository_test.php` — ALL PASS.
- `php scripts/verify/run_all.php --gate PL` — exit 0 (schema GREEN, ledger GREEN).

Code review: `providesCpu()`/`providesNic()` field reads match the legacy readers they cite
(`PcieLaneBudgetValidator::evaluateAssembledStorageLaneBudget()`, `NICPortTracker`). Fail-closed
posture (INV-5) intact: spec-not-found and non-numeric fields throw; only the documented 0-lane
fallback is fail-open. Both `LEDGER_SKIP_*` constants confirmed `[]`.

## Findings (verified, none blocking)
1. **LATENT divergence — `ResourceCatalog::extractLaneCount()` does NOT mirror
   `PcieLaneBudgetValidator::extractLaneCount()` exactly**, despite both docblocks saying "exactly":
   legacy (PcieLaneBudgetValidator.php:349) returns 0 immediately when the
   `interface`/`pcie_interface`/`bus_interface` candidate is absent/empty — its numeric `pcie_lanes`
   fallback is reachable only via a NON-empty string that fails `/x(\d+)/i`. The new version falls
   back to `pcie_lanes` even when no interface field exists at all (and
   `tests/unit/resource_catalog_test.php` asserts that behavior: "no interface, numeric pcie_lanes=8
   fallback → 1 row"). **Unreachable on current data**: grep confirms `pcie_lanes` appears only in
   cpu/chassis/motherboard specs, never nic/hbacard/pciecard — so today the two functions agree on
   every real spec. If a future spec adds `pcie_lanes` to a card without an interface string, the
   ledger would count lanes the legacy budget check doesn't → equivalence drift. Fix is a 2-line
   guard + 1 test change; left to a future unit (verifier does not modify implementation). Until
   then, the ResourceCatalog docblock's "mirrors ... exactly" is slightly overstated.
2. **Pre-existing, unrelated**: `dual_write_test.php` / `nested_transaction_test.php` print
   `Error logging configuration action: SQLSTATE ... Field 'user_id' doesn't have a default value` —
   the history/audit-logging insert lacks a user_id in the test harness context. Non-fatal (tests
   pass; error is caught and logged), predates these units. Worth a look before P8.
3. `run_all.php --quick`'s inventory/orphan/equivalence RED findings (29/3/7) are pre-existing
   production-data-quality issues, unchanged by these units, and are NOT in PL's gate_reports list.

## Remaining Work / Next
- U-B.4 is now unblocked from the code side. Next: human decision on flipping `DUAL_WRITE_ENABLED=on`
  in production `.env`, ≥24h soak, then execute `reports/backfill-signoff-DRAFT.md`'s runbook.
- Open question from U-L.5 handoff still open: `slot_report.php` `slotless_card` gap for non-onboard
  NICs (pre-existing Extractor gap) — decide before P8 whether it needs a unit.
- U-SM.2/U-SM.3 remain `implemented`, awaiting their own independent verify pass.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-5 (fail-closed) | PASS — verified by code review + the unknown-UUID-throws unit checks. |
| INV-11 (unit box) | PASS for both units — 2 prod files + 3 test files total across U-L.4/U-L.5; this verify session modified NO production code. |
| INV-10 | N/A (neither pack pins baseline; characterize run recorded in implementing session). |

## Files To Load Into Context (next session)
- migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-L.4-20260712.md, U-L.5-20260712.md, this file
- reports/backfill-signoff-DRAFT.md (if proceeding to U-B.4)

## Expected Context Size
~20k tokens
