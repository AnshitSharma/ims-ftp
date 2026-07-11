# U-L.6 — extractLaneCount() legacy-mirror fix
Concept: close a latent equivalence-drift finding from the U-L.4/U-L.5 independent verify pass.
Pins baseline: yes (zero diffs — unreachable on current ims-data, but must stay unreachable).
Invariants: INV-5 (spirit: the fail-open exception must match its cited legacy source exactly),
INV-10, INV-11.

## Finding this closes
`migration/handoffs/U-L.4-U-L.5-VERIFY-20260712.md`, Finding 1: `ResourceCatalog::extractLaneCount()`
did not mirror `PcieLaneBudgetValidator::extractLaneCount()` (core/models/compatibility/
PcieLaneBudgetValidator.php:346-358) exactly, despite the docblock claiming so. Legacy returns 0
immediately when the `interface`/`pcie_interface`/`bus_interface` candidate is absent or empty —
its numeric `pcie_lanes` fallback is reachable ONLY via a non-empty candidate string that fails
`/x(\d+)/i`. The prior implementation fell back to `pcie_lanes` even when no interface field
existed at all. Unreachable on current data (grep confirms `pcie_lanes` never appears on a
nic/hbacard/pciecard spec without also lacking any interface field in a way that would trigger
this), but a future spec combining "no interface string" + numeric `pcie_lanes` would have caused
the ledger to count lanes the legacy budget check doesn't — silent equivalence drift.

## Inputs
- `core/models/compatibility/PcieLaneBudgetValidator.php:346-358` (the source of truth being mirrored)
- `core/models/config/ResourceCatalog.php` (the `extractLaneCount()` private method, ~line 215)
- `migration/handoffs/U-L.4-U-L.5-VERIFY-20260712.md` (the finding)

## Files Modified (2)
- `core/models/config/ResourceCatalog.php` — `extractLaneCount()`: return 0 immediately when the
  candidate is absent/non-string/empty, exactly like legacy's `if (!is_string($candidate) ||
  $candidate === '') return 0;` guard, BEFORE the regex attempt (not after, and not folded into
  the regex condition as before).
- `tests/unit/resource_catalog_test.php` — the one assertion pair that exercised the (now-removed)
  incorrect fallback behavior for an absent-interface + numeric-`pcie_lanes` pciecard fixture:
  updated to expect `[]` instead of a 1-row result. A NEW fixture (non-empty interface string that
  fails the regex, e.g. `"PCIe Gen4"`, + numeric `pcie_lanes`) was added to keep the real fallback
  path — the one legacy actually exercises — covered by a passing test, not deleted along with the
  wrong one.

## Tests / Acceptance
- `tests/unit/resource_catalog_test.php` — ALL PASS, including the corrected assertion and the new
  unparseable-interface-fallback fixture.
- `tests/backfill/ledger_backfill_test.php`, `tests/regression/ledger_dual_write_test.php` — ALL
  PASS unaffected (their fixtures use either a real parseable interface string or no lane fields
  at all, neither of which touches the changed branch).
- `php tests/characterize_compatibility.php` — must still exit 0 with no new diffs (this method is
  reachable from the live dual-write path, not `PcieLaneBudgetValidator` itself, so the golden
  compatibility baseline is unaffected by construction — this fix only changes `ResourceCatalog`'s
  internal ledger bookkeeping, never a verdict `PcieLaneBudgetValidator` produces).

## Invariants touched
- INV-5 (fail-closed, spirit): the ONE deliberate fail-open exception in this file must actually
  match the legacy behavior it claims to mirror — an inexact mirror is worse than no mirror, since
  the docblock actively tells future readers not to "fix" it.
- INV-10: pin-before-change — this is a bugfix to an unreachable-on-current-data code path, not a
  verdict-producing legacy path; characterization run for completeness, zero diffs expected and
  confirmed.
- INV-11: unit box — 2 files, well under 500 LOC, 1 concept (fix a mirror to actually mirror).

## Rollback
Revert `extractLaneCount()` to the pre-fix version and revert the test assertion (git revert of
this unit's commit). No schema, no seeder, no flag involved.

## Checklist
- [x] `extractLaneCount()` returns 0 for an absent/empty candidate before attempting the regex
- [x] The `pcie_lanes` fallback remains reachable via a non-empty, regex-failing candidate string
      (proven by the new test fixture, not just asserted in a docblock)
- [x] `tests/unit/resource_catalog_test.php` ALL PASS
