# U-L.4 — ResourceCatalog: close the cpu pcie_lane provider gap
Concept: one owner for capacity extraction (finishing U-L.1's deferred scope). Pins baseline: no.
Invariants: INV-2 (spirit), INV-5, INV-11.

## Why this unit exists
U-L.1 explicitly deferred `ResourceCatalog::provides('cpu', ...)` — no confirmed field was found
within that unit's permitted read scope, so it throws `CatalogException` unconditionally instead of
guessing (see ResourceCatalog.php's class docblock and `provides()`'s `case 'cpu'`). Two consumers of
`ResourceCatalog` are affected right now:
- `ConfigComponentWriter::writeLedgerForAdd()` (live dual-write path, U-L.2) calls
  `$catalog->provides($type, $specUuid)` **unconditionally, with no skip list** — U-L.2's own handoff
  flags this: "Under DUAL_WRITE_ENABLED=on, adding a CPU or NIC will always roll back. Flag defaults
  off; this is inert until a human turns it on, which they should not do until these gaps close."
  This unit closes the **cpu** half of that gap. (The **nic** half — `provides('nic', ...)` and
  `consumes('nic'|'hbacard'|'pciecard', ...)` — is explicitly OUT of this unit's box; a follow-up unit
  must close it before DUAL_WRITE_ENABLED is safe to enable in production. Do not touch nic/hbacard/
  pciecard code paths in this unit.)
- `scripts/backfill/backfill.php`'s `LEDGER_SKIP_PROVIDES = ['cpu', 'nic']` skip list means the
  backfill's ledger pass never even attempts a cpu provider row — which is *why* every NVMe/PCIe
  storage config lands in `error` on a fleet backfill (no `pcie_lane` provider ever exists anywhere,
  since `consumesStorage()` already works but nothing ever provides that resource). This unit removes
  `'cpu'` from that list once the underlying gap is closed, unblocking U-B.4's fleet run.

## Purpose
Implement `ResourceCatalog::provides('cpu', $specUuid)`: return one `pcie_lane` capacity row sized
from the CPU spec's `pcie_lanes` field — the exact field and read pattern legacy
`PcieLaneBudgetValidator::evaluateAssembledStorageLaneBudget()` already uses for the live budget
check, so this unit does not invent a new field, it mirrors a proven one.

## Inputs (Files To Read)
- core/models/config/ResourceCatalog.php (whole file — small, ~309 lines; this unit edits `provides()`'s
  `case 'cpu'` branch and adds one private method, mirroring `providesChassis()`'s shape/error pattern)
- core/models/compatibility/PcieLaneBudgetValidator.php 156–171 (the exact `pcie_lanes` field read +
  quantity handling this unit mirrors for the provider side — quantity does NOT apply here: one
  physical row = one provider row per INV-1, unlike the legacy quantity-summing budget check)
- core/models/shared/DataExtractionUtilities.php 632–634 (`getCPUByUUID()` — the accessor
  `ResourceCatalog`'s constructor already holds as `$this->dataUtils`; use this, not a new lookup path)
- tests/unit/resource_catalog_test.php (existing fixture shapes to extend, not replace)
- scripts/backfill/backfill.php 50–58 (`LEDGER_SKIP_PROVIDES` — remove `'cpu'` only; `'nic'` stays)

## Files Modified (2)
- `core/models/config/ResourceCatalog.php`:
  - `provides()`'s `case 'cpu':` → `return $this->providesCpu($specUuid);` (was: throw CatalogException)
  - new private `providesCpu(string $specUuid): array` — mirrors `providesChassis()`'s shape:
    `$spec = $this->dataUtils->getCPUByUUID($specUuid); if (!is_array($spec)) throw CatalogException(...);`
    then `if (!isset($spec['pcie_lanes'])) return [];` (a CPU with no lane field provides nothing —
    NOT an error; some CPU specs may legitimately omit it, matching legacy's `isset()` guard which
    silently contributes 0 rather than throwing) `elseif (!is_numeric($spec['pcie_lanes'])) throw
    CatalogException(...)` else `return [['resource' => 'pcie_lane', 'slot_ref' => null, 'capacity' =>
    (int)$spec['pcie_lanes']]];`
  - Update the class docblock's "NOT implemented" list: remove the `cpu pcie_lane capacity` line: this
    is now implemented; keep `chassis drive_bay_*/u2 bays, nic sfp_port count` as still-open.
- `scripts/backfill/backfill.php`: `LEDGER_SKIP_PROVIDES = ['cpu', 'nic'];` → `LEDGER_SKIP_PROVIDES =
  ['nic'];` (one line). Update the comment above the constant to say cpu is now handled, nic is not.

## Files To Update (test, not new — extends existing fixtures)
- `tests/unit/resource_catalog_test.php`: add cpu fixtures — (a) a cpu spec with `pcie_lanes: 64` →
  expect exactly one `{resource: 'pcie_lane', slot_ref: null, capacity: 64}` row; (b) a cpu spec with
  no `pcie_lanes` key → expect `[]` (not a throw); (c) a cpu spec with a non-numeric `pcie_lanes` value
  → expect `CatalogException`.
- `tests/backfill/ledger_backfill_test.php` Fixture B currently asserts NVMe storage with NO cpu in
  the config produces a `CatalogException` (`errors=1`). That assertion is still correct as written —
  Fixture B's config has no CPU row at all, so `providesCpu()` never runs — but note in your handoff
  whether this pack's change requires touching that test (it should not, if Fixture B truly has zero
  CPU rows; confirm by re-reading the fixture before assuming no changes needed).

## Tests / Acceptance
- `php -l` on both modified files: no syntax errors.
- `tests/unit/resource_catalog_test.php`: all existing checks PASS + the 3 new cpu checks PASS.
- `tests/backfill/ledger_backfill_test.php`: still ALL PASS (see note above — verify, don't assume).
- New scratch-DB check (extend or add alongside Fixture A/B in `ledger_backfill_test.php`, or a new
  Fixture C if that keeps the box within INV-11): a config with a CPU (`pcie_lanes: 64` fixture spec)
  + NVMe storage (4 lanes) → backfill `--execute` → `done=1 errors=0`, one `pcie_lane` provider row
  (capacity 64, consumer_id NULL until linked) + one `pcie_lane` consumption row (amount 4, consumer_id
  = the storage component's id) in `config_resources`. This is the acceptance proof that the gap
  described in this pack's "Why" section is actually closed, not just that old tests still pass.
- `grep -n "'cpu'" scripts/backfill/backfill.php` — confirm `'cpu'` no longer appears in
  `LEDGER_SKIP_PROVIDES` (still fine if it appears elsewhere, e.g. `LEDGER_SKIP_CONSUMES` unaffected —
  cpu was never in that list).
- `php scripts/verify/run_all.php --quick` → exit 0.
- `php tests/characterize_compatibility.php` → must still pass before touching anything (INV-10; this
  unit does not touch a legacy verdict-producing path, so this should be a formality, but run it).

## Invariants touched
- INV-5 (fail-closed): `providesCpu()` must never catch/swallow — a non-numeric `pcie_lanes` throws,
  same as every other `providesX()` method in the file.
- INV-11 (unit box): 2 files modified + 2 test files extended, well under 500 LOC (this is a ~20-line
  method + a 1-line constant edit + fixture additions). One concept: close the cpu provider gap. Do
  NOT also attempt nic/hbacard/pciecard in this unit even though they share the same root cause — that
  is out of box and belongs in a follow-up unit (see "Why this unit exists" above).

## Rollback
Revert both files to `case 'cpu': throw CatalogException(...)` and `LEDGER_SKIP_PROVIDES = ['cpu',
'nic']`. No schema change, no seeder, nothing else to unwind.

## Checklist
- [ ] `providesCpu()` mirrors `PcieLaneBudgetValidator`'s field name exactly (`pcie_lanes`), no new
      field invented
- [ ] Missing field ⇒ `[]`, not throw (matches legacy's `isset()` guard semantics)
- [ ] Non-numeric field ⇒ throw (fail-closed, matches every other `providesX()` method)
- [ ] `LEDGER_SKIP_PROVIDES` no longer contains `'cpu'`; still contains `'nic'`
- [ ] New/extended tests prove a real end-to-end NVMe-behind-CPU config backfills clean (not just
      that `providesCpu()` in isolation returns the right array)
- [ ] Handoff explicitly states: the nic/hbacard/pciecard half of this same gap is UNFIXED and
      DUAL_WRITE_ENABLED must not go to production `on` until a follow-up unit closes it too
      (ConfigComponentWriter::writeLedgerForAdd() has no skip list on the live path — unlike
      backfill.php — so this is a live production-request-failure risk, not just a backfill nicety)
