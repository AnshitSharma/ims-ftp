# U-L.1 — ResourceCatalog (spec → provided resources)
Concept: one owner for capacity extraction. Pins baseline: no. Invariants: INV-2(spirit), INV-11.

## Purpose
Given (component_type, spec_uuid) return the resources that component PROVIDES:
motherboard → cpu_socket×N, dimm_slot×N (slot_refs dimm_1..N), pcie_slot rows (slot_ref pcie_1..N with
width in slot_ref suffix e.g. pcie_1_x16), m2_slot×N, riser_slot×N, sfp? no; cpu → pcie_lane capacity;
chassis → drive_bay_2_5×N, drive_bay_3_5×N, u2 bays, psu_watt capacity; riser → pcie_slot rows it
provides; nic → sfp_port×N.

## Inputs (Files To Read)
- core/models/components/ComponentDataService.php 1–80 (getComponentSpecifications entry)
- core/models/server/ServerBuilder.php 6317–6414 (getSystemMemoryLimits — existing slot extraction to mirror)
- core/models/server/ServerBuilder.php 5863–5915 (getChassisPsuWattage, estimateMemorySlots)
- core/models/compatibility/UnifiedSlotTracker.php 40–110 (slot enumeration incl. riser-provided)
- ONE sample spec JSON per type from ims-data if present in repo checkout (list via `ls ../ims-data/* | head`; if absent, rely on the extraction code above — do NOT invent field names)

## Files Created (2)
core/models/config/ResourceCatalog.php — `provides(string $type, string $specUuid): array` returning
rows shaped for config_resources (resource, slot_ref, capacity). Unknown/missing spec fields ⇒ throw
CatalogException (fail-closed; NEVER return partial silently — the caller decides policy).
tests/unit/resource_catalog_test.php — fixture spec arrays (inline, copied from real shapes found in
the extraction code) → expected rows, incl. the P3.1 lesson: SUM all m2_slots entries, not first.

## Tests
php -l + unit test PASS + `grep -rn "ResourceCatalog" core/ api/ | grep -v config/ResourceCatalog\|tests` empty + characterization zero diffs.

## Rollback / Checklist
Delete files. - [ ] Throws on unknown shape - [ ] m2 slots summed across entries - [ ] slot_refs deterministic (stable ordering)

---

## PROPOSED AMENDMENT — UNAPPROVED, owner decision required (raised 2026-08-26)

### "grep ResourceCatalog ... empty" — unsatisfiable
This pack's Tests line requires
`grep -rn "ResourceCatalog" core/ api/ | grep -v config/ResourceCatalog\|tests` to be **empty**.
Run 2026-08-26 against the deployed tree it returns **11 files**, every one of which references
`ResourceCatalog` *because a later unit's design says it must*:

| File | Unit that put it there |
|---|---|
| `core/models/config/ConfigComponentWriter.php` | U-L.2 (ledger dual-writer calls `provides()`/`consumes()`) |
| `core/models/validation/TargetState.php` | U-V.2 |
| `core/models/validation/TargetStateBuilder.php` | U-V.2 (resource deltas recomputed via catalog) |
| `core/models/validation/rules/CpuSocketCountRule.php` | U-R.1 |
| `core/models/validation/rules/MemorySlotCountRule.php` | U-R.2 |
| `core/models/validation/rules/PcieLaneBudgetRule.php` | U-R.4 |
| `core/models/validation/rules/StorageBayCapacityRule.php` | U-R.5 |
| `core/models/validation/rules/StorageM2CapacityRule.php` | U-R.5 |
| `core/models/validation/rules/SystemPsuCapacityRule.php` | U-R.7 |
| `core/models/components/PlatformSpecIndex.php` | serverplatform (2026-08-25) |
| `core/models/shared/DataExtractionUtilities.php` | shared extraction |

The criterion was a **"no consumers yet"** pin, valid only on the day U-L.1 landed as an unwired
leaf. U-L.2 onward were *specified* to violate it. It can never be empty again and no amount of
work will make it so.

**Proposed replacement criterion:** *"`ResourceCatalog` is the single owner of capacity extraction:
no file outside `core/models/config/ResourceCatalog.php` re-derives provided/consumed resources
from raw spec JSON. Consumers calling `provides()`/`consumes()` are expected and unbounded in
number; a consumer reaching around the catalog into spec fields directly fails this criterion."*

### Criteria that ARE met (evidence, 2026-08-26)
- `php -l` clean.
- `tests/unit/resource_catalog_test.php` — **exit 0, ALL PASS**, DB-free, run with `IMS_DATA_PATH`
  set. Covers the m2-summing lesson, `CatalogException` on unknown type and unknown UUID, and the
  lane-consumption fallbacks.

### Criterion still UNEVALUATED
- "characterization zero diffs" — `tests/characterize_compatibility.php` was not run
  (see the 2026-08-26 session record in `phase-status.json` for why).
