# Handoff — U-L.2 — 2026-07-07

## Current State
`ConfigComponentWriter::afterLegacyAdd()`/`afterLegacyRemove()` now also maintain `config_resources`
(the ledger) in the same transaction as the component-row write, when `DUAL_WRITE_ENABLED=on`.
`ResourceCatalog` gained `consumes(type, specUuid)`. This unit only extends the existing dual-write
hook — no new `ServerBuilder.php` call sites (it already calls `afterLegacyAdd`/`afterLegacyRemove`
from U-1.5; this unit's logic runs inside those same two calls). Per the verify session's process
note in `migration/handoffs/P1-PL-VERIFY-20260707.md`, this session did **exactly one unit** and
left `phase-status.json` at `implemented`, not `verified` — that flip is for a separate session.

## Deviation from the pack: discrete slot->consumer linking deferred (RV-2)
The pack's literal instruction: "If the type CONSUMES a discrete resource and slot_ref known ...
link consumer_id on the matching provider row (UPDATE ... WHERE config_uuid=? AND slot_ref=? AND
consumer_id IS NULL)". This assumes the CONSUMING component's `slot_ref` (from the legacy slot
assignment system, e.g. `$options['slot_position']`, ultimately from
`UnifiedSlotTracker::loadMotherboardPCIeSlots()`'s scheme `"pcie_{width}_slot_{n}"`) is the SAME
STRING as the PROVIDING component's `slot_ref` already sitting in `config_resources` (from
`ResourceCatalog::provides()`, which U-L.1 designed with an unrelated scheme:
`"pcie_{n}_{width}"`, index assigned in JSON-array encounter order). These two naming schemes have
no relationship — a direct string match would essentially never link correctly (either finding
nothing, since `pcie_x16_slot_1` != `pcie_1_x16`, or occasionally colliding on the wrong slot by
coincidence). Implementing the match anyway would produce silently wrong or silently absent links —
worse than not linking at all. **Decision**: discrete-resource provider rows always keep
`consumer_id = NULL` from this unit. Documented as review item **RV-2** (alongside the pack's own
pre-existing **RV-1**, DIMM slot linking) in `ConfigComponentWriter`'s class docblock. A follow-up
unit should either reconcile the two slot_ref naming schemes (likely by changing
`ResourceCatalog::provides()` to reuse the legacy scheme) or add an explicit translation layer,
before slot-level consumer linking can be implemented correctly.

## Consequence for this unit's test scenario
The pack's acceptance scenario "add nic with slot->consumer link + lane consumption" combines a
capability this unit defers (slot linking, above) with a component type (`nic`) that currently
always throws in both `provides()` and `consumes()` (U-L.1's honest gap — no confirmed SFP-port or
PCIe-lane-consumption field). `tests/regression/ledger_dual_write_test.php` therefore tests the
**positive** scalar-consumption path with `storage` (NVMe interface, fully implemented via
`DataExtractionUtilities::extractStoragePCIeLanes()`), and uses `nic` for the **induced-failure**
scenario instead — which is exactly the correct, current behavior for that type. Since no component
type can currently succeed at PROVIDING `pcie_lane` (only `cpu` would, and `cpu`'s `provides()`
always throws), the lane-consumption provider row was seeded directly via
`ConfigComponentRepository::insert()` + a manual `config_resources` INSERT, isolating and proving
the consumption-linking mechanism itself independently of the still-open CPU gap.

## Completed Work
- `core/models/config/ResourceCatalog.php` (modified): added `consumes(string $type, string
  $specUuid): array`. Confirmed: `storage` -> `pcie_lane` amount via
  `DataExtractionUtilities::extractStoragePCIeLanes()` (mirrors the exact NVMe/PCIe interface check
  already in that method); `cpu`/`ram`/`motherboard`/`chassis`/`caddy`/`sfp` -> `[]` (confirmed to
  consume nothing). **Not implemented, throws**: `nic`/`hbacard`/`pciecard` (no confirmed
  PCIe-lane-consumption field within scope — same honest-gap pattern as U-L.1's `provides()`).
- `core/models/config/ConfigComponentWriter.php` (modified): `afterLegacyAdd()` now captures the
  new component's id from `$repo->insert()` (previously discarded) and calls a new private
  `writeLedgerForAdd()`: inserts one `config_resources` row per `provides()` entry (`consumer_id`
  NULL), and for each `consumes()` entry, finds an existing provider row for that resource
  (`consumer_id IS NULL`) and inserts a consumption row (`provider_id` = the original provider,
  `consumer_id` = the new component, `capacity` = the consumed amount) — throwing `CatalogException`
  if no provider exists for that resource in the config. `afterLegacyRemove()` now calls
  `cleanupLedgerForRemove()`: deletes rows where the tombstoned component was the consumer (its own
  consumption), and rows where it was the provider (its own advertised capacity, and any
  consumption rows attached to it as provider) — explicit because `ON DELETE CASCADE` only fires on
  a hard delete of `config_components`, never the soft tombstone `removed_at` UPDATE.
- `tests/regression/ledger_dual_write_test.php` (new, 20 checks): flag-off no-op; motherboard add
  producing 2 `pcie_slot` + 1 `riser_slot` + 1 pooled `m2_slot` provider rows, all `consumer_id`
  NULL and `provider_id` = the motherboard; motherboard remove explicitly clearing its provider
  rows; chassis add producing 1 `psu_watt` provider row; NVMe storage add consuming 4 lanes from a
  seeded CPU-provided budget (provider row untouched, new consumption row correctly linked); storage
  remove deleting only its own consumption row; an induced `nic` failure rolling back the legacy
  write, the `config_components` row, and any `config_resources` rows together.

## Remaining Work
(empty for U-L.2 itself — unit complete. RV-2 slot-linking, and U-L.1's already-flagged cpu/nic
`provides()`/`consumes()` gaps, remain real, tracked, out-of-box work for future units.)

## Known Risks
- `provides('cpu', ...)` and both `provides`/`consumes('nic', ...)` still throw unconditionally
  (U-L.1 gap, now also blocking `hbacard`/`pciecard` `consumes()`). Under `DUAL_WRITE_ENABLED=on`,
  adding a CPU or NIC will always roll back. Flag defaults off; this is inert until a human turns it
  on, which they should not do until these gaps close.
- RV-1 (DIMM, pre-existing) and RV-2 (discrete PCIe/riser slots, this unit) mean `consumer_id` is
  NEVER set on any discrete-resource provider row today — only scalar (`pcie_lane`) consumption
  rows exist. `ledger_report` (U-L.3) should not assume discrete resources ever show real
  utilization yet.
- Outstanding items #2-#4 from `P1-PL-VERIFY-20260707.md` (RAM `serial_number` legacy gap, seeders
  not yet applied to production, real-data equivalence runs pending) are unrelated to this unit and
  untouched by it.
- Same environment gap as every unit: no real `ims-data/`; `storage`'s NVMe interface fixture in
  the new test is synthetic, copied from the field-check logic in
  `DataExtractionUtilities::extractStorageInterface()`, not a real spec file.

## Invariant Check Results
| Invariant | Result |
|---|---|
| INV-5 (fail-closed) | PASS — neither new writer method catches; `CatalogException` (missing provider, or U-L.1's unresolved types) propagates through the untouched outer try/catch, rolling back the legacy write + component row + any ledger rows together (proven: induced-failure scenario). |
| INV-8 (dual-write substrate) | PASS — flag off is a true no-op for the ledger too (zero `config_resources` writes, proven); flag on keeps ledger and component rows atomically consistent. |
| INV-11 (unit box) | PASS — 2 files modified (`ConfigComponentWriter.php`, `ResourceCatalog.php`), 1 file created (`ledger_dual_write_test.php`), 0 seeders — matches the pack's box exactly. |

## Acceptance Test Results
- `php -l` on both modified files + the new test -> No syntax errors detected.
- `DUAL_WRITE_ENABLED=on php tests/regression/ledger_dual_write_test.php` -> ALL PASS (20/20).
- `php tests/characterize_compatibility.php` (flag off) -> exit 0, 0 configs/0 replays (no
  production dump); golden baseline restored via `git checkout --` immediately after.
- Full existing regression/unit suite re-run and unaffected: `dual_write_test.php` (flag on, U-1.5)
  ALL PASS, `config_component_repository_test.php` ALL PASS, `resource_catalog_test.php` (U-L.1)
  ALL PASS, `finalized_immutability_test.php` ALL PASS, `nested_transaction_test.php` ALL PASS.
- `php scripts/verify/run_all.php --quick` -> schema/inventory/orphan/equivalence all GREEN.

## Next Prompt To Use
"Continue the IMS migration. Read migration/00-overview/README.md, migration/ARCHITECTURAL_INVARIANTS.md,
then either (a) run an independent verify pass on U-L.2 (per this project's implemented/verified
split — do NOT let the same session both implement and verify), or (b) if U-L.2 is already verified,
execute unit U-L.3 using migration/06-resource-ledger/execution-packs/U-L.3.md. Read
migration/handoffs/U-L.2-20260707.md first for the RV-2 slot-linking deferral and the still-open
ResourceCatalog cpu/nic/hbacard/pciecard gaps (both provides() and consumes() now affected) — U-L.3
is the ledger_report.php unit, which should account for RV-1/RV-2 meaning discrete resources never
show real consumer linkage today. Follow migration/00-overview/SESSION_PROTOCOL.md exactly, ONE
unit only."

## Files To Load Into Context (next session)
- migration/00-overview/README.md
- migration/ARCHITECTURAL_INVARIANTS.md
- migration/handoffs/U-L.2-20260707.md (this file)
- migration/06-resource-ledger/execution-packs/U-L.3.md (if proceeding to implement)
- core/models/config/ConfigComponentWriter.php
- core/models/config/ResourceCatalog.php

## Expected Context Size
~28k tokens
