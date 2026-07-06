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
