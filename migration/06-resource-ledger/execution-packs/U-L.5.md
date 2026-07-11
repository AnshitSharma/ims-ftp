# U-L.5 — ResourceCatalog: close the nic/hbacard/pciecard live-dual-write gap
Concept: one owner for capacity extraction (finishes what U-L.4 explicitly deferred). Pins baseline: no.
Invariants: INV-2 (spirit), INV-5, INV-11.

## Why this unit exists
U-L.4 closed the **cpu** half of the `ResourceCatalog` provider gap but explicitly stayed out of the
**nic** (`provides('nic', ...)`) and **nic/hbacard/pciecard** (`consumes(..., ...)`) code paths. Those
still throw `CatalogException` unconditionally. `ConfigComponentWriter::writeLedgerForAdd()` — the LIVE
dual-write path (U-L.2) — calls both `provides()` and `consumes()` **unconditionally, no skip list**.
Concretely: today, with `DUAL_WRITE_ENABLED=on`, adding a NIC, HBA card, or PCIe card to any
configuration would throw and roll back the whole add-component request. This unit closes both halves
so the live path is safe for all 10 component types before `DUAL_WRITE_ENABLED` goes `on` in production.

## Purpose
- `provides('nic', $specUuid)`: one `sfp_port` capacity row sized from the NIC spec's `ports` field —
  the exact field `NICPortTracker::getPortAssignmentInfo()` (NICPortTracker.php:32-49) already reads to
  build its own port map (`$totalPorts = (int)$nicSpecs['ports']`). This unit only counts ports; it does
  not touch SFP-to-port assignment semantics (NICPortTracker's own domain, untouched).
- `consumes('nic'|'hbacard'|'pciecard', $specUuid)`: one `pcie_lane` consumption row sized the same way
  `PcieLaneBudgetValidator::extractLaneCount()` (PcieLaneBudgetValidator.php:346-358) already computes
  it: try `interface` / `pcie_interface` / `bus_interface` string field via `/x(\d+)/i`; if none parse,
  fall back to a numeric `pcie_lanes` field; if neither exists, **0 lanes** (not an error — matches
  legacy's fail-open posture for this specific field, unlike the fail-closed posture everywhere else in
  ResourceCatalog). This is a deliberate, documented exception to INV-5's usual "throw, don't guess" —
  the legacy validator itself treats an unparseable width as 0, so mirroring anything else would invent
  behavior the codebase has never had.

## Inputs (Files To Read)
- core/models/config/ResourceCatalog.php (whole file; edits `provides()`'s `case 'nic'` and
  `consumes()`'s `case 'nic'/'hbacard'/'pciecard'` branches, adds `providesNic()` + `extractLaneCount()`
  helper mirroring `consumesStorage()`'s shape)
- core/models/compatibility/NICPortTracker.php 32-49 (`ports` field read)
- core/models/compatibility/PcieLaneBudgetValidator.php 346-358 (`extractLaneCount()` — the exact
  interface-string-regex + pcie_lanes-fallback + 0-default this unit mirrors for nic/hbacard/pciecard)
- core/models/shared/DataExtractionUtilities.php (`getNICByUUID()`, `getHBACardByUUID()`,
  `getPCIeCardByUUID()` — all three already exist and are used elsewhere in this file)
- scripts/backfill/backfill.php 50-58 (`LEDGER_SKIP_PROVIDES`/`LEDGER_SKIP_CONSUMES` — remove `'nic'`
  from PROVIDES; remove `'nic'`, `'hbacard'`, `'pciecard'` from CONSUMES; `'riser'` stays, it is not a
  real component_type, see backfill.php's own physicalType mapping)
- tests/unit/resource_catalog_test.php, tests/backfill/ledger_backfill_test.php (existing fixture
  shapes to extend)

## Files Modified (2)
- `core/models/config/ResourceCatalog.php`:
  - `provides()`'s `case 'nic':` → `return $this->providesNic($specUuid);` (was: throw)
  - new private `providesNic(string $specUuid): array` — mirrors `providesCpu()`'s shape: spec lookup
    via `getNICByUUID()`, `if (!isset($spec['ports'])) return [];`, `elseif (!is_numeric(...)) throw`,
    else `return [['resource' => 'sfp_port', 'slot_ref' => null, 'capacity' => (int)$spec['ports']]]`
  - `consumes()`'s `case 'nic': case 'hbacard': case 'pciecard':` → `return
    $this->consumesPcieLanes($type, $specUuid);` (was: throw, for all three)
  - new private `consumesPcieLanes(string $type, string $specUuid): array` — spec lookup dispatched by
    `$type` (nic/hbacard/pciecard use different `getXByUUID()` accessors), then mirrors
    `PcieLaneBudgetValidator::extractLaneCount()`'s exact fallback chain; returns `[]` (0 lanes, not
    absence-of-key) when nothing parses — this is the ONE place in ResourceCatalog that returns a
    zero-value row set instead of throwing on a missing/unparseable field, and the class docblock must
    say so explicitly to prevent a future unit "fixing" it to throw.
  - Update class docblock: nic/hbacard/pciecard move from "NOT implemented" to "implemented", with the
    fail-open-on-unparseable-width caveat spelled out.
- `scripts/backfill/backfill.php`: `LEDGER_SKIP_PROVIDES = ['nic'];` → `LEDGER_SKIP_PROVIDES = [];`
  `LEDGER_SKIP_CONSUMES = ['nic', 'hbacard', 'pciecard', 'riser'];` → `LEDGER_SKIP_CONSUMES = [];`
  ('riser' was already dead in this list — `backfillLedgerForConfig()` normalizes `$physicalType` to
  `'pciecard'` for riser rows *before* the skip-list check runs, so the literal string `'riser'` could
  never match; a riser's spec naturally consumes 0 lanes via the interface-field fallback chain since
  it has no `interface`/`pcie_lanes` field, matching `PcieLaneBudgetValidator::computeLanesUsed()`'s own
  unexcluded walk of riser entries — do not add a `case 'riser'` to ResourceCatalog).

## Files To Update (test, not new)
- `tests/unit/resource_catalog_test.php`: add nic provider fixtures (ports=4 → 1 sfp_port row capacity
  4; missing `ports` → `[]`; non-numeric `ports` → throw) and nic/hbacard/pciecard consumption fixtures
  (interface `"PCIe 4.0 x8"` → 8 lanes; no interface + no pcie_lanes → `[]`, not throw; numeric
  `pcie_lanes` fallback with no parseable interface → that value).
- `tests/backfill/ledger_backfill_test.php`: add Fixture D (CPU + HBA card with a lane-bearing
  `interface` field + NIC) to prove both the pcie_lane consumption row and the sfp_port provider row now
  attach instead of throwing `CatalogException`. **Do not add the NIC to Fixture A** — discovered during
  implementation: a non-onboard NIC always gets `config_components.slot_ref = NULL` via
  `Extractor::extractNics()` (no slot-tracking path exists for add-on NICs), which trips
  `slot_report.php`'s pre-existing `slotless_card` check for `nic`/`pciecard`/`hbacard` types. This gap
  predates U-L.5 and is unrelated to `ResourceCatalog` — Fixture A asserts `slot_report GREEN` so the
  NIC proof belongs in Fixture D instead, which never calls `slot_report.php`. Flag this gap in the
  handoff as a known pre-existing limitation, not a U-L.5 regression. Re-verify Fixture A's existing
  plain-card fixture (`$cardUuid`, no `interface`/`pcie_lanes` field) still produces `errors=0` — it
  should, since a truly fieldless card now consumes 0 lanes instead of throwing, which is a **behavior
  change** for that fixture (previously it was skipped entirely via `LEDGER_SKIP_CONSUMES`, never
  exercised as a consumer at all) — call this out explicitly in the handoff.

## Tests / Acceptance
- `php -l` on both modified files.
- `tests/unit/resource_catalog_test.php`: all existing + new nic/hbacard/pciecard checks PASS.
- `tests/backfill/ledger_backfill_test.php`: ALL PASS, including the new/extended NIC + lane-bearing
  card assertions, and Fixture A's pre-existing plain-card assertion re-verified under the new
  (no-longer-skipped) consumption path.
- `grep -n "LEDGER_SKIP_PROVIDES\|LEDGER_SKIP_CONSUMES" scripts/backfill/backfill.php` — confirm
  PROVIDES is `[]` and CONSUMES is `['riser']` only.
- `php scripts/verify/run_all.php --quick` → exit 0 (schema green; pre-existing RED findings from prior
  sessions are expected, not new regressions — compare counts against the last handoff's numbers).
- `php tests/characterize_compatibility.php` → completes without a new fatal (INV-10 formality; this
  unit does not touch the legacy verdict-producing engine).

## Invariants touched
- INV-5 (fail-closed): every `providesX`/`consumesX` method in ResourceCatalog throws on a malformed
  (present but wrong-shape) field — EXCEPT `consumesPcieLanes()`'s intentional 0-lane fallback for an
  absent/unparseable interface string, which is a deliberate mirror of legacy behavior, not a new
  precedent. Documented in the class docblock so it isn't mistaken for an oversight later.
- INV-11 (unit box): 2 files modified + 2 test files extended. One concept: close the remaining
  nic/hbacard/pciecard half of the U-L.1/U-L.4 provider/consumer gap.

## Rollback
Revert both files: `case 'nic'`/`case 'hbacard'`/`case 'pciecard'` back to `throw CatalogException(...)`
in both `provides()` and `consumes()`; `LEDGER_SKIP_PROVIDES = ['nic']`, `LEDGER_SKIP_CONSUMES = ['nic',
'hbacard', 'pciecard', 'riser']`. No schema change, no seeder.

## Checklist
- [ ] `providesNic()` uses the `ports` field exactly as `NICPortTracker` does, no new field invented
- [ ] `consumesPcieLanes()` mirrors `PcieLaneBudgetValidator::extractLaneCount()`'s exact fallback
      chain (interface regex → pcie_lanes numeric → 0), not a reinvention
- [ ] The 0-lane fallback (not a throw) is called out explicitly in the class docblock as an
      intentional legacy-mirroring exception to this file's usual fail-closed posture
- [ ] `LEDGER_SKIP_PROVIDES` is empty; `LEDGER_SKIP_CONSUMES` contains only `'riser'`
- [ ] Fixture A's pre-existing plain pciecard (no interface/pcie_lanes field) is re-verified under the
      newly-active consumption path, not just left untouched on the assumption it's unaffected
- [ ] Handoff states plainly: after this unit, `ResourceCatalog::provides()`/`consumes()` no longer
      throw for any of the 10 component types under normal (even minimally-specified) real data —
      `ConfigComponentWriter::writeLedgerForAdd()`'s live dual-write path is safe for
      `DUAL_WRITE_ENABLED=on` from a ResourceCatalog-coverage standpoint (does NOT itself certify
      `DUAL_WRITE_ENABLED` should be flipped — that is still a separate, human, production decision
      gated on U-B.4 and an actual soak, per SESSION_PROTOCOL)
