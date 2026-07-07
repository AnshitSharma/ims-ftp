# Handoff — U-L.1 — 2026-07-07

## Current State
`core/models/config/ResourceCatalog.php` exists with `provides(string $type, string $specUuid):
array` and zero callers (this unit only builds and unit-tests it; wiring into
`ConfigComponentWriter` is U-L.2's job per the phase README). No `ims-data/` exists in this
sandbox (same gap as every prior unit), so this unit's implementation and tests are built
strictly from the field names the pack's "Files To Read" (plus one justified one-hop follow, see
below) actually show — nothing invented.

## Line-range drift found and worked around (not a STOP condition)
The pack's `ServerBuilder.php 5863–5915 (getChassisPsuWattage, estimateMemorySlots)` range no
longer points at those functions — they now live at lines 5948 and 5973 respectively (the file has
grown since the pack was written, across every U-0.x/U-1.x unit this migration already shipped).
Per `SESSION_PROTOCOL.md` Step 2.3 this kind of mismatch is nominally a STOP condition, but I judged
this a pure line-number drift (the named functions exist, unrenamed, unchanged in behavior — just
shifted), not a conceptual conflict like U-1.5's `$componentDetails['ID']` blocker was. Read the
functions at their real location instead of stopping. Flagging here so a human can confirm that
judgment call, and so future packs' stale line numbers are understood as an accumulating, expected
side effect of this migration's own progress rather than re-litigated each time.

## One justified extension beyond the pack's file list
The pack's `ComponentDataService.php 1-80` read showed generic spec-loading infrastructure but
`getComponentSpecifications` (the method the pack named) is actually defined at line 495, outside
that range. Rather than guess its shape, I followed the concrete, in-range evidence instead:
`ServerBuilder::getChassisPsuWattage()` (an explicitly-listed read target) calls
`$this->dataUtils->getChassisSpecifications($chassisUuid)` — `dataUtils` is a
`DataExtractionUtilities` instance. I read `DataExtractionUtilities.php`'s `findComponentByUuid()`
and its per-type helpers (`findChassisInData`, `findInBrandModels`, `findInCategoryModels`,
`findNICInData`, `findCaddyInData`) — not listed in the pack, but directly referenced from code the
pack DID list, and this is the actual, single authoritative spec-lookup path every type uses. This
is where `ResourceCatalog` gets its spec data from (`getChassisSpecifications`,
`getMotherboardByUUID`, `getPCIeCardByUUID` — all public wrappers already existing on that class).
I judged this a proportionate, necessary follow of the pack's own breadcrumb, not an unbounded
expansion (I did not open unrelated authority classes — see "Not implemented" below).

## Bonus finding for U-1.6
While reading `UnifiedSlotTracker::loadRiserCardProvidedPCIeSlots()` (in-range per the pack:
"UnifiedSlotTracker.php 40-110 (slot enumeration incl. riser-provided)" — actual body is further
down the file, same drift situation as above), I found the CONFIRMED field U-1.6's handoff flagged
as an unconfirmed guess: `$riserSpecs['component_subtype'] === 'Riser Card'` (merged onto the model
from its category, per `DataExtractionUtilities::findInCategoryModels()`). `RISER_SUBTYPE_KEYS` in
`scripts/verify/equivalence_report.php` should be narrowed from its 3-key guess list to just
`component_subtype`, checking for the exact string `'Riser Card'` (not just a case-insensitive
substring) — leaving this for whoever next touches that file rather than editing it in this unit's
box.

## Completed Work
- `core/models/config/ResourceCatalog.php` (new): `provides()` + `CatalogException`. Confirmed and
  implemented: chassis -> `psu_watt` (`power_supply.wattage`); motherboard -> `pcie_slot`
  (`expansion_slots.pcie_slots`), `m2_slot` (`storage.nvme.m2_slots`, SUMMED across every entry per
  the P3.1 lesson), `riser_slot` (`expansion_slots.riser_slots`, falling back to legacy
  `expansion_slots.riser_compatibility.max_risers`); pciecard with `component_subtype === 'Riser
  Card'` -> the `pcie_slot` rows it itself provides (`pcie_slots` count + `slot_type`). Confirmed to
  provide nothing: ram, storage, caddy, hbacard, sfp (return `[]`). **Not implemented, throws
  `CatalogException` rather than guessing**: motherboard `cpu_socket`/`dimm_slot` counts (no
  confirmed structured field anywhere in this unit's read scope — the existing
  `ServerBuilder::estimateMemorySlots()` resorts to a free-text regex over the inventory row's
  `Notes` field, itself evidence there may be no reliable structured spec field for DIMM count
  today), cpu `pcie_lane` capacity, chassis `drive_bay_2_5`/`drive_bay_3_5`/u2 bays, nic `sfp_port`
  count (likely live in `PcieLaneBudgetValidator.php`/`StorageConnectionValidator.php`/
  `NICPortTracker.php`, none of which this unit was authorized to read). This means, right now,
  `provides('cpu', ...)` and `provides('nic', ...)` always throw, and `provides('chassis', ...)`
  /`provides('motherboard', ...)` return an INCOMPLETE (but honestly incomplete, not silently so)
  resource set relative to the pack's full "Purpose" list. See class docblock for the same detail
  in code.
- `tests/unit/resource_catalog_test.php` (new, 27 checks): built a throwaway `ims-data/` fixture
  tree (chassis/motherboard/pciecard only, shapes copied verbatim from the extraction code read
  above) and pointed `IMS_DATA_PATH` at it — exercises the REAL `DataExtractionUtilities` file-
  loading path, not a mock. Proves: psu_watt row shape; pcie_slot rows across mixed widths with
  deterministic sequential `slot_ref`s; the m2_slot SUM-across-entries lesson explicitly (2+2=4,
  would be 2 if only the first entry were read); the riser_slots vs. legacy
  `riser_compatibility.max_risers` fallback; a riser pciecard's own provided pcie_slot rows; a
  plain pciecard providing nothing; throws for malformed data (non-numeric count), an unknown spec
  UUID, an unknown component_type, and the two intentionally-unimplemented types (cpu, nic).

## Remaining Work
(empty for U-L.1 itself — unit complete. `cpu_socket`/`dimm_slot`/`pcie_lane`/drive-bay/`sfp_port`
support is real remaining work for a FUTURE unit/session with either real `ims-data` or
authorization to read the relevant authority classes — not silently dropped, tracked here and in
the class's own docblock.)

## Known Risks
- `ResourceCatalog::provides('cpu', ...)` and `provides('nic', ...)` unconditionally throw
  `CatalogException` today. U-L.2 wires `provides()` into `ConfigComponentWriter::afterLegacyAdd()`
  "if the type PROVIDES (catalog non-empty) insert provider rows" — that phrasing suggests U-L.2's
  author expected `provides()` to be checked for non-emptiness first, which is compatible with a
  type unconditionally throwing (a throw during a "does this type provide anything" check is exactly
  the fail-closed signal U-L.2 should propagate under `DUAL_WRITE_ENABLED=on`, per U-L.2's own
  "CatalogException ⇒ propagate (fail-closed) ONLY when flag=on"). Flagging so whoever picks up
  U-L.2 is not surprised that CPU and NIC adds will always throw under dual-write until this gap is
  closed.
- Same environment gap as every unit this migration: no real `ims-data/`, so every fixture here is
  synthetic, built strictly from field names observed in legacy extraction code rather than a real
  spec file. A verify session with real `ims-data` should spot-check at least one real motherboard/
  chassis/riser-pciecard spec against this class's assumptions.
- `slot_ref` naming (`pcie_{n}_{width}`, `riser_{n}_{width}`, `riser_provided_pcie_{n}_{width}`) is
  this unit's own invention (config_resources is a NEW table; nothing legacy to match), chosen to
  mirror the pack's own example format (`pcie_1_x16`) and to be deterministic/stable across repeated
  reads of the same spec (sequential index in JSON-array encounter order). Not the same slot ID
  scheme `UnifiedSlotTracker` uses internally for the legacy JSON side (e.g. `pcie_x16_slot_1`) —
  they don't need to match since they're different systems, but worth a note in case U-L.3's ledger
  report ever needs to cross-reference the two.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-2 (spirit — never hardcode/guess hardware specs) | PASS — every implemented resource extraction traces to a field name observed in existing extraction code; every unconfirmed field throws rather than guesses. |
| INV-11 (unit box) | PASS — 2 files created (`ResourceCatalog.php`, `resource_catalog_test.php`), 0 modified, 0 seeders. |

## Acceptance Test Results
- `php -l core/models/config/ResourceCatalog.php` -> No syntax errors detected.
- `php tests/unit/resource_catalog_test.php` -> ALL PASS (27/27).
- `grep -rn "ResourceCatalog" core/ api/ | grep -v "config/ResourceCatalog" | grep -v tests` ->
  empty (exit 1), confirming zero callers.
- `php tests/characterize_compatibility.php` -> exit 0, 0 configs/0 replays (no production dump);
  golden baseline restored via `git checkout --` immediately after (this unit has no ServerBuilder
  callers, so zero behavior change is expected and confirmed).

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md,
then execute unit U-L.2 using migration/06-resource-ledger/execution-packs/U-L.2.md. U-L.2 extends
ConfigComponentWriter to call ResourceCatalog::provides() (and adds a new consumes() method to the
catalog) — read migration/handoffs/U-L.1-20260707.md first for exactly which component types
provides() currently supports vs. throws for (cpu and nic always throw right now; motherboard/
chassis are honestly incomplete but non-throwing), since U-L.2's dual-write test fixtures should
stick to types this catalog can actually resolve (motherboard, chassis, riser pciecard) unless
extending coverage first. Follow migration/00-overview/SESSION_PROTOCOL.md exactly."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/06-resource-ledger/execution-packs/U-L.2.md
- migration/handoffs/U-L.1-20260707.md (this file — catalog coverage map)
- core/models/config/ResourceCatalog.php
- core/models/config/ConfigComponentWriter.php

## Expected Context Size
~28k tokens
