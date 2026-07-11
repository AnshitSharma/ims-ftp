# Handoff — U-L.4 — 2026-07-12

## Current State
Implemented per `migration/06-resource-ledger/execution-packs/U-L.4.md`. Closes the **cpu** half of the
`ResourceCatalog::provides()` gap flagged in U-B.3-VERIFY-20260711.md. Status: **implemented, not
verified** — per SESSION_PROTOCOL.md, an independent session must run the verify pass.

## Completed Work
- `core/models/config/ResourceCatalog.php`: `provides()`'s `case 'cpu':` now calls new private
  `providesCpu(string $specUuid): array`, which reads the CPU spec's `pcie_lanes` field (mirrors
  `PcieLaneBudgetValidator::evaluateAssembledStorageLaneBudget()`'s field read) and returns one
  `pcie_lane` provider row (`slot_ref: null`, `capacity: (int)pcie_lanes`). Missing field → `[]` (not a
  throw, matches legacy's `isset()` guard). Non-numeric field → `CatalogException` (fail-closed, INV-5).
  Class docblock updated: `cpu -> pcie_lane` moved from "NOT implemented" to "implemented".
- `scripts/backfill/backfill.php`: `LEDGER_SKIP_PROVIDES = ['cpu', 'nic']` → `['nic']` (cpu no longer
  skipped; nic still is, pending U-L.5). Comment updated.
- Tests extended (not new files):
  - `tests/unit/resource_catalog_test.php`: 3 new cpu fixtures (pcie_lanes=64 → 1 row capacity 64; no
    pcie_lanes field → `[]`; non-numeric pcie_lanes → throw).
  - `tests/backfill/ledger_backfill_test.php`: new Fixture C — CPU (pcie_lanes=64) + NVMe storage (4
    lanes) → `--execute` → `done=1 errors=0`, one `pcie_lane` provider row (capacity 64) + one linked
    consumption row (capacity 4, `consumer_id` = the storage component's id). This is the real
    end-to-end proof the pack required, not just a unit-level `providesCpu()` check.
  - Fixture B (NVMe storage, no CPU in that config → `CatalogException`, `errors=1`) required NO
    changes — confirmed by re-reading it: that config genuinely has zero CPU rows, so `providesCpu()`
    never runs there. Still asserts the "no provider" path correctly.

## Remaining Work
- **U-L.5 (nic/hbacard/pciecard half) — see its own handoff, implemented in the same session
  immediately after this unit.** Before U-L.5, `DUAL_WRITE_ENABLED` still must not go `on` in
  production (live add-component for nic/hbacard/pciecard would still throw).
- Independent verify pass for U-L.4 (and U-L.5) — not done in this session (same session implemented
  both; SESSION_PROTOCOL requires a separate session to verify).

## Known Risks
- None new. Same local-environment gotchas as U-B.3-VERIFY-20260711.md apply (Windows `proc_open`
  `SystemRoot`, `.env` `putenv()` override, XAMPP root password) — already worked around in the scratch
  copy at `C:\tmp\ims-ftp-scratch\`.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-5 (fail-closed) | PASS — `providesCpu()` throws on non-numeric `pcie_lanes`; never swallows. |
| INV-11 (unit box) | PASS — 2 files modified (`ResourceCatalog.php`, `backfill.php`) + 2 test files extended. One concept: close the cpu provider gap. nic/hbacard/pciecard explicitly deferred to U-L.5. |

## Acceptance Test Results (scratch DB, `C:\tmp\ims-ftp-scratch\`)
- `php -l` on both modified files: no syntax errors.
- `tests/unit/resource_catalog_test.php`: ALL PASS (incl. 3 new cpu checks).
- `tests/backfill/ledger_backfill_test.php`: ALL PASS (incl. new Fixture C).
- `grep -n "'cpu'" scripts/backfill/backfill.php`: no match in `LEDGER_SKIP_PROVIDES` (confirmed).
- `php scripts/verify/run_all.php --quick`: schema GREEN; inventory/orphan/equivalence RED — same
  pre-existing counts as U-B.3-VERIFY-20260711.md (29 orphans / 3 inventory / 7 equivalence diffs), not
  a regression from this unit.
- `php tests/characterize_compatibility.php`: completes, exit 0 (formality per INV-10 — this unit does
  not touch the legacy verdict-producing engine).

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md,
migration/handoffs/U-L.4-20260712.md and migration/handoffs/U-L.5-20260712.md, then run the
independent verify pass for both units per SESSION_PROTOCOL.md (implementing session cannot verify its
own work). After both verify, U-B.4's DUAL_WRITE_ENABLED precondition can be discussed with the user."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-L.4-20260712.md (this file)
- migration/handoffs/U-L.5-20260712.md
- core/models/config/ResourceCatalog.php
- scripts/backfill/backfill.php

## Expected Context Size
~25k tokens
